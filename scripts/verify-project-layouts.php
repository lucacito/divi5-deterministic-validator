<?php
/**
 * Real-site check for editing Divi Project layouts: the REAL REST and MCP handlers on the Docker site. A throwaway draft Project is
 * listed (post_type=project), read, previewed (dry run), saved, snapshotted and restored; an invalid layout is refused; a
 * throwaway draft blog post is refused by every id-taking tool and route.
 *
 * Run: make verify-project-layouts   (needs `make up` and the plugin active on the dev site; the site needs the Divi "project" type)
 * Creates throwaway posts and always deletes them. Dev sites only (scripts/dev-guard.php).
 */

use AiEditorDivi5\WP\EditTarget;
use AiEditorDivi5\WP\HistoryService;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

if ( ! class_exists( EditTarget::class ) || ! defined( EditTarget::class . '::ALLOWED_TYPES' ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (with Project layout support) on this site first.' );
}
if ( ! post_type_exists( 'project' ) ) {
    WP_CLI::error( 'This site has no "project" post type (Divi Projects are disabled); enable them in Divi > Theme Options first.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
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
$mcp = static function ( string $tool, array $args ): array {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => (object) $args ] ] ) );
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    $res                           = rest_do_request( $req );
    unset( $_SERVER['HTTP_AUTHORIZATION'] );
    wp_set_current_user( 1 );
    $data = (array) $res->get_data();
    $text = $data['result']['content'][0]['text'] ?? '';

    return [ 'error' => $data['error'] ?? null, 'result' => (array) json_decode( (string) $text, true ) ];
};
$layout = static function ( string $title ): string {
    $attrs = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $title ] ] ], 'builderVersion' => '5.14.0' ];

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading ' . serialize_block_attributes( $attrs ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};

$ORIGINAL = $layout( 'Project original aied' );
$NEW      = $layout( 'Project changed aied' );
$BAD      = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/not-a-module {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
$posts    = [];
try {
    $meta = [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ];
    // Record each id right after its own insert, so a failure between the two inserts cannot leak the first.
    $prj = wp_insert_post( [ 'post_type' => 'project', 'post_status' => 'draft', 'post_title' => 'aied project verify', 'post_content' => wp_slash( $ORIGINAL ), 'meta_input' => $meta ], true );
    if ( is_wp_error( $prj ) ) {
        throw new RuntimeException( $prj->get_error_message() );
    }
    $posts[] = (int) $prj;
    $blog = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'aied blog verify', 'post_content' => wp_slash( $ORIGINAL ), 'meta_input' => $meta ], true );
    if ( is_wp_error( $blog ) ) {
        throw new RuntimeException( $blog->get_error_message() );
    }
    $posts[] = (int) $blog;
    $check( true, 'a throwaway draft Project and a throwaway draft blog post exist' );

    // 1. discovery
    $l = $rest( 'GET', '/pages', [], [ 'post_type' => 'project' ] );
    $lPages = (array) ( $l['data']['pages'] ?? [] );
    $check( 200 === $l['status'] && in_array( (int) $prj, array_map( 'intval', array_column( $lPages, 'id' ) ), true ), 'REST GET /pages?post_type=project lists the Project' );
    $check( 'project' === ( array_column( $lPages, 'post_type', 'id' )[ $prj ] ?? '' ), 'the listed item reports post_type project' );
    $lp = $rest( 'GET', '/pages' );
    $check( 200 === $lp['status'] && isset( $lp['data']['pages'] ) && ! in_array( (int) $prj, array_map( 'intval', array_column( (array) $lp['data']['pages'], 'id' ) ), true ), 'the default listing (pages) answers 200 and does not include the Project' );
    $check( 400 === $rest( 'GET', '/pages', [], [ 'post_type' => 'post' ] )['status'], 'REST rejects post_type=post (400)' );
    $m = $mcp( 'list_divi_pages', [ 'post_type' => 'project' ] );
    $check( null === $m['error'] && in_array( (int) $prj, array_map( 'intval', array_column( (array) ( $m['result']['pages'] ?? [] ), 'id' ) ), true ), 'MCP list_divi_pages post_type=project lists the Project' );
    $check( -32602 === ( $mcp( 'list_divi_pages', [ 'post_type' => 'post' ] )['error']['code'] ?? null ), 'MCP rejects post_type=post (-32602)' );

    // 2. read
    $g = $rest( 'GET', "/pages/{$prj}" );
    $check( 200 === $g['status'] && 'project' === ( $g['data']['post_type'] ?? '' ), 'REST reads the Project (post_type project)' );
    $gm = $mcp( 'get_page_layout', [ 'page_id' => (int) $prj ] );
    $check( null === $gm['error'] && 'project' === ( $gm['result']['post_type'] ?? '' ), 'MCP get_page_layout reads the Project' );

    // 3. dry run, invalid, save
    $histBefore = count( HistoryService::listFor( (int) $prj ) );
    $d = $rest( 'PUT', "/pages/{$prj}", [ 'post_content' => $NEW, 'dry_run' => true ] );
    $check( 200 === $d['status'] && true === ( $d['data']['valid'] ?? null ), 'dry run on the Project returns a valid preview' );
    $check( get_post_field( 'post_content', $prj ) === $ORIGINAL, 'dry run left the Project untouched' );
    $check( count( HistoryService::listFor( (int) $prj ) ) === $histBefore, 'dry run left the Project history untouched' );
    $bad = $rest( 'PUT', "/pages/{$prj}", [ 'post_content' => $BAD ] );
    $check( 422 === $bad['status'] && get_post_field( 'post_content', $prj ) === $ORIGINAL, 'an invalid layout is refused (422) and nothing is saved' );
    $s = $rest( 'PUT', "/pages/{$prj}", [ 'post_content' => $NEW ] );
    $check( 200 === $s['status'] && true === ( $s['data']['saved'] ?? null ), 'a valid layout is saved to the Project' );
    $check( true === ( $s['data']['history']['stored'] ?? null ), 'the previous content was snapshotted first' );
    $check( get_post_field( 'post_content', $prj ) === $NEW, 'the Project now holds the new layout' );

    // 4. undo
    $versionId = (int) ( $s['data']['history']['version_id'] ?? 0 );
    $hl        = $rest( 'GET', "/pages/{$prj}/history" );
    $check( 200 === $hl['status'] && (int) ( $hl['data']['count'] ?? 0 ) >= 1, 'the history lists the snapshot' );
    $he = $rest( 'GET', "/pages/{$prj}/history/{$versionId}" );
    $check( 200 === $he['status'] && ( $he['data']['post_content'] ?? null ) === $ORIGINAL, 'the history entry holds the pre-save (original) content' );
    $r = $rest( 'POST', "/pages/{$prj}/restore", [ 'version_id' => $versionId ] );
    $check( 200 === $r['status'] && true === ( $r['data']['restored'] ?? null ) && get_post_field( 'post_content', $prj ) === $ORIGINAL, 'restore brings the original layout back' );

    // 4b. surgical edit through both surfaces
    $e = $rest( 'POST', "/pages/{$prj}/edit", [ 'find' => 'Project original aied', 'replace' => 'Project edited aied' ] );
    $check( 200 === $e['status'] && true === ( $e['data']['saved'] ?? null ) && str_contains( get_post_field( 'post_content', $prj ), 'Project edited aied' ), 'REST edit (find and replace) saves to the Project' );
    $em = $mcp( 'edit_page_content', [ 'page_id' => (int) $prj, 'find' => 'Project edited aied', 'replace' => 'Project original aied' ] );
    $check( null === $em['error'] && get_post_field( 'post_content', $prj ) === $ORIGINAL, 'MCP edit_page_content saves to the Project' );
    $lm = $mcp( 'list_page_history', [ 'page_id' => (int) $prj ] );
    $check( null === $lm['error'] && (int) ( $lm['result']['count'] ?? 0 ) >= 2, 'MCP list_page_history lists the Project snapshots' );

    // 5. a blog post is refused by every id-taking tool and route
    // Seed a REAL snapshot row for the blog post through the production service (not through the handlers under test),
    // so a missing version can never be the reason a history-entry or restore probe is refused.
    $seed = HistoryService::write( (int) $blog, $NEW, 'seed' );
    $seedId = (int) ( $seed['snapshot']['version_id'] ?? 0 );
    $check( true === ( $seed['ok'] ?? false ) && true === ( $seed['snapshot']['stored'] ?? false ) && $seedId > 0 && null !== HistoryService::entry( (int) $blog, $seedId ), 'seeded a real history snapshot for the blog post (version ' . $seedId . ')' );
    $before     = get_post_field( 'post_content', $blog );
    $histBlog   = count( HistoryService::listFor( (int) $blog ) );
    $refused = [
        $rest( 'GET', "/pages/{$blog}" )['status'],
        $rest( 'PUT', "/pages/{$blog}", [ 'post_content' => $NEW ] )['status'],
        $rest( 'POST', "/pages/{$blog}/edit", [ 'find' => 'aied', 'replace' => 'x' ] )['status'],
        $rest( 'GET', "/pages/{$blog}/history" )['status'],
        $rest( 'GET', "/pages/{$blog}/history/{$seedId}" )['status'],
        $rest( 'POST', "/pages/{$blog}/restore", [ 'version_id' => $seedId ] )['status'],
    ];
    $check( [ 404, 404, 404, 404, 404, 404 ] === $refused, 'REST: a blog post is 404 on get, update, edit, history, history entry (real version) and restore (real version)' );
    $codes = [
        $mcp( 'get_page_layout', [ 'page_id' => (int) $blog ] )['error']['code'] ?? null,
        $mcp( 'update_page_layout', [ 'page_id' => (int) $blog, 'post_content' => $NEW ] )['error']['code'] ?? null,
        $mcp( 'edit_page_content', [ 'page_id' => (int) $blog, 'find' => 'aied', 'replace' => 'x' ] )['error']['code'] ?? null,
        $mcp( 'list_page_history', [ 'page_id' => (int) $blog ] )['error']['code'] ?? null,
        $mcp( 'get_page_history_entry', [ 'page_id' => (int) $blog, 'version_id' => $seedId ] )['error']['code'] ?? null,
        $mcp( 'restore_page_version', [ 'page_id' => (int) $blog, 'version_id' => $seedId ] )['error']['code'] ?? null,
    ];
    $check( [ -32602, -32602, -32602, -32602, -32602, -32602 ] === $codes, 'MCP: a blog post is refused on get, update, edit, history, history entry (real version) and restore (real version)' );
    $check( get_post_field( 'post_content', $blog ) === $before, 'the blog post was never touched' );
    $check( count( HistoryService::listFor( (int) $blog ) ) === $histBlog, 'the blog post history did not grow' );
} catch ( Throwable $e ) {
    $check( false, 'unexpected error: ' . $e->getMessage() );
} finally {
    // Only ever delete the throwaway posts this run created (a Project and a blog post titled "aied ...").
    foreach ( $posts as $id ) {
        $made = get_post( $id );
        if ( $made && in_array( $made->post_type, [ 'project', 'post' ], true ) && str_starts_with( $made->post_title, 'aied ' ) ) {
            wp_delete_post( $id, true );
        }
    }
    wp_set_current_user( 1 );
}

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed.' );
}
WP_CLI::success( 'Project layouts verified on the real site.' );
