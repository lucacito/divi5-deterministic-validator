<?php
/**
 * Real-site check for changing an existing page's title (update_page_layout / PUT /pages/{id} with `title`): the REAL
 * REST and MCP handlers on the Docker site. Title-only and combined saves, undo behaviour (a title is NOT restorable),
 * strict inputs, dry run, approval mode, permissions, what core's own filtering stores for a user without
 * unfiltered_html, that the slug, status and layout are untouched, and what a dynamic divi/post-title module renders
 * before and after a rename (evidence for the spec's "Verified facts").
 *
 * Run: make verify-page-title   (needs `make up` and the plugin active on the dev site)
 * Creates throwaway posts and a throwaway user; always deletes them and restores the approval-mode setting and the API
 * key's user. Dev sites only (scripts/dev-guard.php). The front-end fetch (check 13) uses wp_remote_get, which the
 * plugin itself never does: this is a dev-only script and the site is local.
 */

use AiEditorDivi5\WP\ApprovalMode;
use AiEditorDivi5\WP\EditTarget;
use AiEditorDivi5\WP\HistoryStore;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

if ( ! class_exists( EditTarget::class ) || ! class_exists( \AiEditorDivi5\WP\PageTitle::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (with page title support) on this site first.' );
}

const AIED_PREFIX = 'aied title verify';
const AIED_USER   = 'aied_title_verify_author';

$failures = [];
$passes   = 0;
$skips    = 0;
$check    = static function ( bool $ok, string $label ) use ( &$failures, &$passes ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( $ok ) {
        ++$passes;
    } else {
        $failures[] = $label;
    }
};
$skip = static function ( string $label, string $why ) use ( &$skips ): void {
    ++$skips;
    WP_CLI::log( 'SKIP  ' . $label . ' (' . $why . ')' );
};
$fact = static function ( string $line ): void {
    WP_CLI::log( 'FACT  ' . $line );
};
$rest = static function ( string $method, string $route, array $body = [], array $params = [] ): array {
    $req = new WP_REST_Request( $method, '/ai-editor-divi5/v1' . $route );
    $req->set_header( 'content-type', 'application/json' );
    if ( [] !== $body ) {
        $req->set_body( wp_json_encode( $body ) );
    }
    foreach ( $params as $k => $v ) {
        $req->set_param( $k, $v );
    }
    $res = rest_do_request( $req );

    return [ 'status' => $res->get_status(), 'data' => (array) $res->get_data() ];
};

$keyUserOption   = 'ai_editor_divi5_api_user_id';
$keyUserOriginal = get_option( $keyUserOption, null );
// The MCP endpoint accepts only the plugin API key; the key's user is who the call runs as. $asUser swaps it for one call.
$mcp = static function ( string $tool, array $args, int $asUser = 0 ) use ( $keyUserOption, $keyUserOriginal ): array {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => (object) $args ] ] ) );
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    if ( $asUser > 0 ) {
        update_option( $keyUserOption, $asUser, false );
    }
    try {
        $res = rest_do_request( $req );
    } finally {
        unset( $_SERVER['HTTP_AUTHORIZATION'] );
        if ( $asUser > 0 ) {
            if ( null === $keyUserOriginal ) {
                delete_option( $keyUserOption );
            } else {
                update_option( $keyUserOption, $keyUserOriginal, false );
            }
        }
        wp_set_current_user( 1 );
    }
    $data = (array) $res->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => $data['error'], 'isError' => true, 'result' => null ];
    }
    $text = $data['result']['content'][0]['text'] ?? '';

    return [ 'error' => null, 'isError' => (bool) ( $data['result']['isError'] ?? false ), 'result' => (array) json_decode( (string) $text, true ) ];
};
$layout = static function ( string $headline ): string {
    $attrs = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $headline ] ] ], 'builderVersion' => '5.14.0' ];

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading ' . serialize_block_attributes( $attrs ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};
// A fresh read of the columns this feature must or must not change.
$state = static function ( int $id ): array {
    clean_post_cache( $id );
    $p = get_post( $id );

    return [
        'title'    => (string) $p->post_title,
        'content'  => (string) $p->post_content,
        'name'     => (string) $p->post_name,
        'status'   => (string) $p->post_status,
        'modified' => (string) $p->post_modified_gmt,
        'history'  => count( HistoryStore::summaries( $id ) ),
    ];
};
$storedTitle = static function ( int $id ): string {
    clean_post_cache( $id );

    return (string) get_post_field( 'post_title', $id, 'raw' );
};
$cleanupLeftovers = static function (): int {
    global $wpdb;
    $ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s", $wpdb->esc_like( AIED_PREFIX ) . '%' ) ); // phpcs:ignore
    foreach ( $ids as $id ) {
        wp_delete_post( (int) $id, true );
    }
    $user = get_user_by( 'login', AIED_USER );
    if ( $user ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $user->ID );
    }

    return count( $ids ) + ( $user ? 1 : 0 );
};
// Fetch a front-end URL. The dev site's own address is not reachable from inside the wpcli container (only from the
// host), so it falls back to the web container by its service name with the site's Host header.
$fetch = static function ( string $url ) use ( $fact ): ?string {
    $attempts = [ [ $url, [] ] ];
    $home     = wp_parse_url( home_url() );
    $path     = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '/' );
    $query    = wp_parse_url( $url, PHP_URL_QUERY );
    $hostHdr  = (string) $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
    $attempts[] = [ 'http://wordpress' . $path . ( $query ? '?' . $query : '' ), [ 'Host' => $hostHdr ] ];
    foreach ( $attempts as [ $u, $headers ] ) {
        $res = wp_remote_get( $u, [ 'timeout' => 60, 'redirection' => 0, 'headers' => $headers ] );
        if ( is_wp_error( $res ) ) {
            $fact( 'front-end fetch ' . $u . ( [] !== $headers ? ' (Host: ' . $hostHdr . ')' : '' ) . ' failed: ' . $res->get_error_message() );
            continue;
        }
        $code = (int) wp_remote_retrieve_response_code( $res );
        $fact( 'front-end fetch ' . $u . ( [] !== $headers ? ' (Host: ' . $hostHdr . ')' : '' ) . ' answered HTTP ' . $code );
        if ( 200 === $code ) {
            return (string) wp_remote_retrieve_body( $res );
        }
    }

    return null;
};
// Where a text appears in rendered HTML: the element that directly contains it, and the <title> tag.
$whereInHtml = static function ( string $html, string $needle ): string {
    $count = substr_count( $html, $needle );
    preg_match_all( '/<([a-z0-9]+)([^>]*)>[^<]*' . preg_quote( $needle, '/' ) . '[^<]*</i', $html, $m, PREG_SET_ORDER );
    $els = [];
    foreach ( $m as $one ) {
        $cls   = preg_match( '/class="([^"]*)"/', $one[2], $c ) ? '.' . str_replace( ' ', '.', trim( $c[1] ) ) : '';
        $els[] = '<' . strtolower( $one[1] ) . $cls . '>';
    }
    $inHeadTitle = 1 === preg_match( '/<title>[^<]*' . preg_quote( $needle, '/' ) . '[^<]*<\/title>/i', $html );

    return sprintf( '"%s": %d occurrence(s) in the HTML; directly inside element(s): %s; inside <title>: %s', $needle, $count, [] === $els ? '(none)' : implode( ', ', $els ), $inHeadTitle ? 'yes' : 'no' );
};

$removed = $cleanupLeftovers();
if ( $removed > 0 ) {
    WP_CLI::log( "NOTE  removed {$removed} leftover throwaway item(s) from an earlier aborted run" );
}

$approvalOriginal = get_option( ApprovalMode::OPTION, null );
$posts            = [];
$authorId         = 0;
try {
    wp_set_current_user( 1 );
    ApprovalMode::set( false );
    $meta = [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ];
    $make = static function ( array $args ) use ( &$posts, $meta ): int {
        $id = wp_insert_post( $args + [ 'post_content' => '', 'meta_input' => $meta ], true );
        if ( is_wp_error( $id ) ) {
            throw new RuntimeException( $id->get_error_message() );
        }
        $posts[] = (int) $id;

        return (int) $id;
    };

    // ---- 1. fixtures --------------------------------------------------------------------------------------------
    $L0 = $layout( 'Headline zero aied' );
    $A  = $make( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => AIED_PREFIX . ' A', 'post_name' => 'aied-title-verify-a', 'post_content' => wp_slash( $L0 ) ] );
    $B  = $make( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => AIED_PREFIX . ' B blog', 'post_content' => wp_slash( $L0 ) ] );
    $P  = 0;
    if ( post_type_exists( 'project' ) ) {
        $P = $make( [ 'post_type' => 'project', 'post_status' => 'draft', 'post_title' => AIED_PREFIX . ' P project', 'post_content' => wp_slash( $L0 ) ] );
    }
    $permalink0 = get_permalink( $A );
    $check( 'publish' === get_post_status( $A ) && 'aied-title-verify-a' === get_post_field( 'post_name', $A ) && AIED_PREFIX . ' A' === get_post_field( 'post_title', $A ), '1. throwaway published page A (slug aied-title-verify-a), draft blog post B' . ( $P ? ' and draft Project P exist' : ' exist' ) );
    $fact( 'permalink of A before any rename: ' . $permalink0 );
    if ( ! $P ) {
        $skip( '1. Divi Project P', 'the "project" post type does not exist on this site' );
    }

    // ---- 2. REST title-only ----------------------------------------------------------------------------------------
    sleep( 1 ); // post_modified has one-second resolution
    $s0 = $state( $A );
    $r  = $rest( 'PUT', "/pages/{$A}", [ 'title' => AIED_PREFIX . ' A rest-1' ] );
    $s1 = $state( $A );
    $check( 200 === $r['status'] && true === ( $r['data']['saved'] ?? null ), '2. REST title-only PUT /pages/{A}: 200, saved true' );
    $check( AIED_PREFIX . ' A rest-1' === $s1['title'] && $s0['title'] !== $s1['title'], '2. REST: post_title changed in the database' );
    $check( $s1['content'] === $s0['content'] && $s1['name'] === $s0['name'] && $s1['status'] === $s0['status'], '2. REST: content byte-identical, post_name and post_status unchanged' );
    $check( 'title_only' === ( $r['data']['history']['reason'] ?? null ) && false === ( $r['data']['history']['stored'] ?? null ) && false === ( $r['data']['layout_changed'] ?? null ), '2. REST: history.reason is title_only, stored false, layout_changed false' );
    $check( $s1['history'] === $s0['history'], '2. REST: no history row was added (' . $s0['history'] . ' -> ' . $s1['history'] . ')' );
    $check( true === ( $r['data']['title']['changed'] ?? null ) && $s0['title'] === ( $r['data']['title']['before'] ?? null ) && $s1['title'] === ( $r['data']['title']['after'] ?? null ) && false === ( $r['data']['title']['restorable'] ?? null ), '2. REST: title reports changed, the old title, the stored new title and restorable false' );

    // ---- 3. MCP title-only ------------------------------------------------------------------------------------------
    $s0 = $state( $A );
    $m  = $mcp( 'update_page_layout', [ 'page_id' => $A, 'title' => AIED_PREFIX . ' A mcp-1' ] );
    $s1 = $state( $A );
    $check( null === $m['error'] && ! $m['isError'] && true === ( $m['result']['saved'] ?? null ), '3. MCP title-only update_page_layout: not an error, saved true' );
    $check( AIED_PREFIX . ' A mcp-1' === $s1['title'] && $s1['content'] === $s0['content'] && $s1['name'] === $s0['name'] && $s1['status'] === $s0['status'], '3. MCP: post_title changed; content, post_name and post_status unchanged' );
    $check( 'title_only' === ( $m['result']['history']['reason'] ?? null ) && false === ( $m['result']['layout_changed'] ?? null ) && $s1['history'] === $s0['history'], '3. MCP: history.reason title_only, layout_changed false, no history row added' );
    $check( true === ( $m['result']['title']['changed'] ?? null ) && $s0['title'] === ( $m['result']['title']['before'] ?? null ) && $s1['title'] === ( $m['result']['title']['after'] ?? null ) && false === ( $m['result']['title']['restorable'] ?? null ), '3. MCP: title reports changed, old title, stored new title, restorable false' );
    if ( $P ) {
        $pr = $rest( 'PUT', "/pages/{$P}", [ 'title' => AIED_PREFIX . ' P renamed' ] );
        $check( 200 === $pr['status'] && AIED_PREFIX . ' P renamed' === $storedTitle( $P ) && 'draft' === $state( $P )['status'] && $state( $P )['content'] === $L0, '3. a Divi Project is renamed too (REST), status and layout untouched' );
    }

    // ---- 4. combined save and restore ---------------------------------------------------------------------------------
    $L1 = $layout( 'Headline one aied' );
    $r  = $rest( 'PUT', "/pages/{$A}", [ 'post_content' => $L1, 'title' => AIED_PREFIX . ' A rest-2' ] );
    $vid = (int) ( $r['data']['history']['version_id'] ?? 0 );
    $check( 200 === $r['status'] && true === ( $r['data']['saved'] ?? null ) && $state( $A )['content'] === $L1 && AIED_PREFIX . ' A rest-2' === $state( $A )['title'], '4. REST combined save: layout and title both saved' );
    $check( true === ( $r['data']['history']['stored'] ?? null ) && $vid > 0 && ( HistoryStore::entry( $A, $vid )['content'] ?? null ) === $L0, '4. REST combined: the previous layout was snapshotted (version ' . $vid . ' holds the old content)' );
    $rr = $rest( 'POST', "/pages/{$A}/restore", [ 'version_id' => $vid ] );
    $check( 200 === $rr['status'] && true === ( $rr['data']['restored'] ?? null ) && $state( $A )['content'] === $L0, '4. REST restore brings the previous layout back' );
    $check( false === ( $rr['data']['title_restored'] ?? null ) && str_contains( (string) ( $rr['data']['title_note'] ?? '' ), AIED_PREFIX . ' A rest-2' ) && AIED_PREFIX . ' A rest-2' === $state( $A )['title'], '4. REST restore: title_restored false, title_note names the current title, the title stayed as it was' );

    $L2  = $layout( 'Headline two aied' );
    $m   = $mcp( 'update_page_layout', [ 'page_id' => $A, 'post_content' => $L2, 'title' => AIED_PREFIX . ' A mcp-2' ] );
    $vid = (int) ( $m['result']['history']['version_id'] ?? 0 );
    $check( null === $m['error'] && ! $m['isError'] && $state( $A )['content'] === $L2 && AIED_PREFIX . ' A mcp-2' === $state( $A )['title'] && true === ( $m['result']['history']['stored'] ?? null ) && ( HistoryStore::entry( $A, $vid )['content'] ?? null ) === $L0, '4. MCP combined save: layout and title saved, previous layout snapshotted (version ' . $vid . ' holds the old content)' );
    $mr = $mcp( 'restore_page_version', [ 'page_id' => $A, 'version_id' => $vid ] );
    $check( null === $mr['error'] && $state( $A )['content'] === $L0 && false === ( $mr['result']['title_restored'] ?? null ) && str_contains( (string) ( $mr['result']['title_note'] ?? '' ), AIED_PREFIX . ' A mcp-2' ) && AIED_PREFIX . ' A mcp-2' === $state( $A )['title'], '4. MCP restore: layout back, title_restored false with a note, title stayed' );

    // ---- 5. round trip of awkward characters ---------------------------------------------------------------------------
    $awkward = [
        'rest' => [ AIED_PREFIX . ' A "quoted" back\\slash & amp', AIED_PREFIX . ' A é ñ ü 😀 emoji' ],
        'mcp'  => [ AIED_PREFIX . ' A \'single\' "double" \\\\ & more', AIED_PREFIX . ' A ß ç 🎉 ✓' ],
    ];
    foreach ( $awkward['rest'] as $t ) {
        $r      = $rest( 'PUT', "/pages/{$A}", [ 'title' => $t ] );
        $stored = $storedTitle( $A );
        $check( 200 === $r['status'] && $stored === ( $r['data']['title']['after'] ?? null ) && $stored === $t, '5. REST round trip: stored == title.after == submitted for ' . wp_json_encode( $t, JSON_UNESCAPED_UNICODE ) );
    }
    foreach ( $awkward['mcp'] as $t ) {
        $m      = $mcp( 'update_page_layout', [ 'page_id' => $A, 'title' => $t ] );
        $stored = $storedTitle( $A );
        $check( null === $m['error'] && $stored === ( $m['result']['title']['after'] ?? null ) && $stored === $t, '5. MCP round trip: stored == title.after == submitted for ' . wp_json_encode( $t, JSON_UNESCAPED_UNICODE ) );
    }

    // ---- 6. strict inputs --------------------------------------------------------------------------------------------------
    $bads = [
        'a number'       => 123,
        'an array'       => [ 'a' ],
        'an empty string' => '',
        'whitespace'     => "  \u{00A0} ",
        'HTML'           => 'Hello <b>bold</b>',
        '201 characters' => str_repeat( 'x', 201 ),
    ];
    $s0 = $state( $A );
    foreach ( $bads as $name => $bad ) {
        $r = $rest( 'PUT', "/pages/{$A}", [ 'title' => $bad ] );
        $m = $mcp( 'update_page_layout', [ 'page_id' => $A, 'title' => $bad ] );
        $check( 400 === $r['status'], "6. REST refuses a title that is {$name} (400)" );
        $check( -32602 === ( $m['error']['code'] ?? null ), "6. MCP refuses a title that is {$name} (-32602)" );
    }
    $r = $rest( 'PUT', "/pages/{$A}", [ 'post_content' => '', 'title' => AIED_PREFIX . ' A never' ] );
    $m = $mcp( 'update_page_layout', [ 'page_id' => $A, 'post_content' => '', 'title' => AIED_PREFIX . ' A never' ] );
    $check( 400 === $r['status'] && -32602 === ( $m['error']['code'] ?? null ), '6. an explicit empty post_content plus a title is refused (REST 400, MCP -32602)' );
    $r = $rest( 'PUT', "/pages/{$A}", [ 'dry_run' => false ] );
    $m = $mcp( 'update_page_layout', [ 'page_id' => $A ] );
    $check( 400 === $r['status'] && -32602 === ( $m['error']['code'] ?? null ), '6. neither post_content nor title is refused (REST 400, MCP -32602)' );
    $s1 = $state( $A );
    $check( $s1['title'] === $s0['title'] && $s1['content'] === $s0['content'] && $s1['modified'] === $s0['modified'] && $s1['history'] === $s0['history'], '6. none of the refused calls wrote anything (title, content, post_modified, history identical)' );

    // ---- 7. dry run ----------------------------------------------------------------------------------------------------------
    sleep( 1 );
    $s0 = $state( $A );
    $r  = $rest( 'PUT', "/pages/{$A}", [ 'title' => AIED_PREFIX . ' A dry-1', 'dry_run' => true ] );
    $check( 200 === $r['status'] && true === ( $r['data']['dry_run'] ?? null ) && false === ( $r['data']['saved'] ?? null ) && $s0['title'] === ( $r['data']['title']['before'] ?? null ) && AIED_PREFIX . ' A dry-1' === ( $r['data']['title']['after'] ?? null ) && false === ( $r['data']['layout_changed'] ?? null ), '7. REST title-only dry_run: preview shows the old and the new title, layout_changed false, saved false' );
    $m  = $mcp( 'update_page_layout', [ 'page_id' => $A, 'title' => AIED_PREFIX . ' A dry-2', 'dry_run' => true ] );
    $check( null === $m['error'] && ! $m['isError'] && true === ( $m['result']['dry_run'] ?? null ) && $s0['title'] === ( $m['result']['title']['before'] ?? null ) && AIED_PREFIX . ' A dry-2' === ( $m['result']['title']['after'] ?? null ), '7. MCP title-only dry_run: preview shows the old and the new title' );
    $r  = $rest( 'PUT', "/pages/{$A}", [ 'post_content' => $layout( 'Headline dry aied' ), 'title' => AIED_PREFIX . ' A dry-3', 'dry_run' => true ] );
    $check( 200 === $r['status'] && true === ( $r['data']['valid'] ?? null ) && $s0['title'] === ( $r['data']['title']['before'] ?? null ) && AIED_PREFIX . ' A dry-3' === ( $r['data']['title']['after'] ?? null ) && 1 === ( $r['data']['diff']['summary']['changed'] ?? null ), '7. REST combined dry_run: layout diff (1 changed block) and both titles' );
    $m  = $mcp( 'update_page_layout', [ 'page_id' => $A, 'post_content' => $layout( 'Headline dry aied' ), 'title' => AIED_PREFIX . ' A dry-4', 'dry_run' => true ] );
    $check( null === $m['error'] && ! $m['isError'] && true === ( $m['result']['valid'] ?? null ) && AIED_PREFIX . ' A dry-4' === ( $m['result']['title']['after'] ?? null ) && $s0['title'] === ( $m['result']['title']['before'] ?? null ), '7. MCP combined dry_run: valid, both titles shown' );
    $s1 = $state( $A );
    $check( $s1['title'] === $s0['title'] && $s1['content'] === $s0['content'] && $s1['modified'] === $s0['modified'] && $s1['history'] === $s0['history'], '7. four dry runs left title, content, post_modified and history untouched' );

    // ---- 8. approval mode -----------------------------------------------------------------------------------------------------
    ApprovalMode::set( true );
    $base = $storedTitle( $A );
    $s0   = $state( $A );
    $r    = $rest( 'PUT', "/pages/{$A}", [ 'title' => AIED_PREFIX . ' A appr-x' ] );
    $check( 428 === $r['status'] && ApprovalMode::CODE === ( $r['data']['code'] ?? null ) && $storedTitle( $A ) === $base, '8. approval mode on: a title save with no preview is refused (428 PREVIEW_REQUIRED), nothing written' );
    $m    = $mcp( 'update_page_layout', [ 'page_id' => $A, 'title' => AIED_PREFIX . ' A appr-x' ] );
    $check( true === $m['isError'] && ApprovalMode::CODE === ( $m['result']['code'] ?? null ) && $storedTitle( $A ) === $base, '8. MCP: the same refusal (isError, PREVIEW_REQUIRED)' );
    $rest( 'PUT', "/pages/{$A}", [ 'title' => AIED_PREFIX . ' A appr-a', 'dry_run' => true ] );
    $r = $rest( 'PUT', "/pages/{$A}", [ 'title' => AIED_PREFIX . ' A appr-b' ] );
    $check( 428 === $r['status'] && $storedTitle( $A ) === $base, '8. a preview of title A does not authorise saving title B' );
    $r = $rest( 'PUT', "/pages/{$A}", [ 'title' => AIED_PREFIX . ' A appr-a' ] );
    $check( 200 === $r['status'] && true === ( $r['data']['saved'] ?? null ) && AIED_PREFIX . ' A appr-a' === $storedTitle( $A ), '8. the previewed title A is saved' );
    // single use: put the title back outside the plugin, then repeat the identical save without a new preview
    wp_update_post( [ 'ID' => $A, 'post_title' => $base ] );
    $r = $rest( 'PUT', "/pages/{$A}", [ 'title' => AIED_PREFIX . ' A appr-a' ] );
    $check( 428 === $r['status'] && $storedTitle( $A ) === $base, '8. the preview was used up: the identical save repeated is refused again' );
    // a layout-only preview does not authorise a layout-plus-title save
    $LA = $layout( 'Headline approval aied' );
    $rest( 'PUT', "/pages/{$A}", [ 'post_content' => $LA, 'dry_run' => true ] );
    $r = $rest( 'PUT', "/pages/{$A}", [ 'post_content' => $LA, 'title' => AIED_PREFIX . ' A appr-c' ] );
    $check( 428 === $r['status'] && $state( $A )['content'] === $L0 && $storedTitle( $A ) === $base, '8. a layout-only preview does not authorise a layout-plus-title save' );
    // combined preview then combined save works (MCP)
    $mcp( 'update_page_layout', [ 'page_id' => $A, 'post_content' => $LA, 'title' => AIED_PREFIX . ' A appr-c', 'dry_run' => true ] );
    $m = $mcp( 'update_page_layout', [ 'page_id' => $A, 'post_content' => $LA, 'title' => AIED_PREFIX . ' A appr-c' ] );
    $check( null === $m['error'] && ! $m['isError'] && true === ( $m['result']['saved'] ?? null ) && $state( $A )['content'] === $LA && AIED_PREFIX . ' A appr-c' === $storedTitle( $A ), '8. MCP: a combined preview then the same combined save works' );
    // a page whose title changed after the preview voids it
    $rest( 'PUT', "/pages/{$A}", [ 'title' => AIED_PREFIX . ' A appr-d', 'dry_run' => true ] );
    wp_update_post( [ 'ID' => $A, 'post_title' => AIED_PREFIX . ' A appr-changed-by-hand' ] );
    $r = $rest( 'PUT', "/pages/{$A}", [ 'title' => AIED_PREFIX . ' A appr-d' ] );
    $check( 428 === $r['status'] && AIED_PREFIX . ' A appr-changed-by-hand' === $storedTitle( $A ), '8. a title changed by hand after the preview voids the preview' );
    // restore A to the baseline layout for later steps (approval mode is switched off below)
    ApprovalMode::set( false );
    if ( null === $approvalOriginal ) {
        delete_option( ApprovalMode::OPTION );
    } else {
        update_option( ApprovalMode::OPTION, $approvalOriginal );
    }
    wp_update_post( [ 'ID' => $A, 'post_title' => AIED_PREFIX . ' A', 'post_content' => wp_slash( $L0 ) ] );

    // ---- 9. permissions: a user who can edit pages but only their own --------------------------------------------------------------
    $authorId = wp_insert_user( [ 'user_login' => AIED_USER, 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'aied-title-verify@example.test', 'role' => 'author' ] );
    if ( is_wp_error( $authorId ) ) {
        throw new RuntimeException( $authorId->get_error_message() );
    }
    $authorId = (int) $authorId;
    // The Author role cannot edit pages at all; edit_pages (without edit_others_pages) lets this user edit only pages they own.
    ( new WP_User( $authorId ) )->add_cap( 'edit_pages' );
    $D = $make( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => AIED_PREFIX . ' D author-owned', 'post_content' => wp_slash( $L0 ), 'post_author' => $authorId ] );
    $check( user_can( $authorId, 'edit_post', $D ) && ! user_can( $authorId, 'edit_post', $A ) && ! user_can( $authorId, 'unfiltered_html' ), '9. setup: the Author can edit their own page D, cannot edit A (owned by user 1), and has no unfiltered_html' );
    $s0 = $state( $A );
    wp_set_current_user( $authorId );
    $r = $rest( 'PUT', "/pages/{$A}", [ 'title' => AIED_PREFIX . ' A hacked' ] );
    wp_set_current_user( 1 );
    $m = $mcp( 'update_page_layout', [ 'page_id' => $A, 'title' => AIED_PREFIX . ' A hacked' ], $authorId );
    $check( 403 === $r['status'], '9. REST: the Author renaming a page they do not own is refused (403)' );
    $check( -32602 === ( $m['error']['code'] ?? null ) && str_contains( (string) ( $m['error']['message'] ?? '' ), 'permission' ), '9. MCP: the same refusal (-32602, "permission")' );
    $s1 = $state( $A );
    $check( $s1['title'] === $s0['title'] && $s1['content'] === $s0['content'] && $s1['modified'] === $s0['modified'], '9. the refused calls changed nothing on A' );
    wp_set_current_user( $authorId );
    $r = $rest( 'PUT', "/pages/{$D}", [ 'title' => AIED_PREFIX . ' D renamed-rest' ] );
    wp_set_current_user( 1 );
    $check( 200 === $r['status'] && AIED_PREFIX . ' D renamed-rest' === $storedTitle( $D ), '9. REST: the Author renames their own draft page D (200)' );
    $m = $mcp( 'update_page_layout', [ 'page_id' => $D, 'title' => AIED_PREFIX . ' D renamed-mcp' ], $authorId );
    $check( null === $m['error'] && ! $m['isError'] && AIED_PREFIX . ' D renamed-mcp' === $storedTitle( $D ), '9. MCP: the Author renames their own draft page D' );

    // ---- 10. core's own filtering for a user without unfiltered_html -----------------------------------------------------------------
    $raw = AIED_PREFIX . ' D Tom & Jerry';
    wp_set_current_user( $authorId );
    $r = $rest( 'PUT', "/pages/{$D}", [ 'title' => $raw ] );
    wp_set_current_user( 1 );
    $stored = $storedTitle( $D );
    $fact( 'no unfiltered_html, REST: submitted ' . wp_json_encode( $raw ) . ' -> core stored ' . wp_json_encode( $stored ) . '; result title.after = ' . wp_json_encode( $r['data']['title']['after'] ?? null ) );
    $check( 200 === $r['status'] && $stored === ( $r['data']['title']['after'] ?? null ), '10. REST, user without unfiltered_html: title.after equals the value core stored' );
    $raw2 = AIED_PREFIX . ' D Fish & Chips "quoted"';
    $m    = $mcp( 'update_page_layout', [ 'page_id' => $D, 'title' => $raw2 ], $authorId );
    $stored2 = $storedTitle( $D );
    $fact( 'no unfiltered_html, MCP: submitted ' . wp_json_encode( $raw2 ) . ' -> core stored ' . wp_json_encode( $stored2 ) . '; result title.after = ' . wp_json_encode( $m['result']['title']['after'] ?? null ) );
    $check( null === $m['error'] && $stored2 === ( $m['result']['title']['after'] ?? null ), '10. MCP, user without unfiltered_html: title.after equals the value core stored' );
    $rest( 'PUT', "/pages/{$D}", [ 'title' => $raw . ' (admin)' ] );
    $fact( 'for comparison, user 1 (has unfiltered_html) submitting ' . wp_json_encode( $raw . ' (admin)' ) . ' -> core stored ' . wp_json_encode( $storedTitle( $D ) ) );

    // ---- 11. a blog post is refused -----------------------------------------------------------------------------------------------------
    $sb = $state( $B );
    $r  = $rest( 'PUT', "/pages/{$B}", [ 'title' => AIED_PREFIX . ' B hacked' ] );
    $m  = $mcp( 'update_page_layout', [ 'page_id' => $B, 'title' => AIED_PREFIX . ' B hacked' ] );
    $check( 404 === $r['status'] && -32602 === ( $m['error']['code'] ?? null ) && $state( $B )['title'] === $sb['title'], '11. a blog post cannot be renamed (REST 404, MCP -32602), its title is unchanged' );

    // ---- 13. dynamic divi/post-title render ----------------------------------------------------------------------------------------------------
    $fixtureFile = __DIR__ . '/../fixtures/valid/verified-post-title-column.json';
    $fixture     = is_file( $fixtureFile ) ? json_decode( (string) file_get_contents( $fixtureFile ), true ) : null;
    if ( ! is_array( $fixture ) || ! isset( $fixture['post_content'] ) || ! str_contains( (string) $fixture['post_content'], 'wp:divi/post-title' ) ) {
        $skip( '13. dynamic divi/post-title render', 'fixtures/valid/verified-post-title-column.json is missing or has no divi/post-title block' );
    } else {
        $old = AIED_PREFIX . ' C oldname';
        $new = AIED_PREFIX . ' C newname';
        $C   = $make( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $old, 'post_name' => 'aied-title-verify-c', 'post_content' => wp_slash( (string) $fixture['post_content'] ) ] );
        $url = add_query_arg( 'aied_nocache', (string) wp_rand(), get_permalink( $C ) );
        $before = $fetch( $url );
        if ( null === $before ) {
            $skip( '13. dynamic divi/post-title render', 'the front end could not be fetched from inside the container (see the FACT lines above)' );
        } else {
            $r = $rest( 'PUT', "/pages/{$C}", [ 'title' => $new ] );
            clean_post_cache( $C );
            $after = $fetch( add_query_arg( 'aied_nocache', (string) wp_rand(), get_permalink( $C ) ) );
            if ( null === $after || 200 !== $r['status'] ) {
                $skip( '13. dynamic divi/post-title render', 'the rename (HTTP ' . $r['status'] . ') or the second fetch failed' );
            } else {
                $fact( 'divi/post-title page C, BEFORE the rename, ' . $whereInHtml( $before, $old ) );
                $fact( 'divi/post-title page C, BEFORE the rename, ' . $whereInHtml( $before, $new ) );
                $fact( 'divi/post-title page C, AFTER the rename,  ' . $whereInHtml( $after, $old ) );
                $fact( 'divi/post-title page C, AFTER the rename,  ' . $whereInHtml( $after, $new ) );
                $check( str_contains( $before, $old ) && ! str_contains( $before, $new ), '13. before the rename the rendered page shows the old title and not the new one' );
                $check( str_contains( $after, $new ) && ! str_contains( $after, $old ), '13. after the rename the rendered page shows the new title and no longer the old one (the dynamic module follows the WordPress title)' );
            }
        }
    }

    // ---- 12. slug and permalink never moved ------------------------------------------------------------------------------------------------------------
    $fin = $state( $A );
    $check( 'aied-title-verify-a' === $fin['name'] && 'publish' === $fin['status'] && get_permalink( $A ) === $permalink0, '12. after every rename A keeps post_name aied-title-verify-a, status publish and the permalink ' . $permalink0 );
} catch ( Throwable $e ) {
    $check( false, 'unexpected error: ' . get_class( $e ) . ': ' . $e->getMessage() . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
    wp_set_current_user( 1 );
    // Always restore the settings first.
    if ( null === $approvalOriginal ) {
        delete_option( ApprovalMode::OPTION );
    } else {
        update_option( ApprovalMode::OPTION, $approvalOriginal );
    }
    if ( null === $keyUserOriginal ) {
        delete_option( $keyUserOption );
    } else {
        update_option( $keyUserOption, $keyUserOriginal, false );
    }
    // Only ever delete what this run created.
    foreach ( $posts as $id ) {
        $made = get_post( $id );
        if ( $made && str_starts_with( $made->post_title, AIED_PREFIX ) ) {
            wp_delete_post( $id, true );
        }
    }
    if ( $authorId > 0 ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $authorId );
    }
}

// ---- 14. cleanup is complete --------------------------------------------------------------------------------------------------------------------------------
global $wpdb;
$left      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE %s", $wpdb->esc_like( AIED_PREFIX ) . '%' ) ); // phpcs:ignore
$historyLeft = 0;
foreach ( $posts as $id ) {
    $historyLeft += count( HistoryStore::summaries( $id ) );
}
$check( 0 === $left && false === get_user_by( 'login', AIED_USER ), '14. cleanup: no throwaway post (title prefix "' . AIED_PREFIX . '") and no throwaway user remain' );
$check( 0 === $historyLeft, '14. cleanup: no history rows remain for the deleted throwaway posts' );
$check( ( null === $approvalOriginal ? null : (string) $approvalOriginal ) === ( false === get_option( ApprovalMode::OPTION, false ) ? null : (string) get_option( ApprovalMode::OPTION ) ), '14. cleanup: the approval-mode setting is back to its original value (' . var_export( $approvalOriginal, true ) . ')' );
$keyNow = get_option( $keyUserOption, null );
$check( $keyUserOriginal === $keyNow, '14. cleanup: the API key user is back to its original value (' . var_export( $keyUserOriginal, true ) . ')' );

// ---- 15. summary ---------------------------------------------------------------------------------------------------------------------------------------------
WP_CLI::log( sprintf( '%d PASS, %d FAIL, %d SKIP', $passes, count( $failures ), $skips ) );
if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Changing a page title verified on the real site.' );
