<?php
/**
 * Real-site check for dry_run. The unit tests cover the diff and the wiring; this drives the REAL
 * REST and MCP handlers on the Docker site and proves a preview leaves the page, its post_modified
 * and its history rows untouched, that mistyped flags are rejected, and that a real save still works.
 *
 * Run: make verify-dry-run   (needs `make up` and the plugin active on the dev site)
 * It creates ONE throwaway draft page, exercises it, and deletes it again.
 */

use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\UsageTracker;
use Divi5Validator\Validator;

if ( ! class_exists( HistoryStore::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 on this site first.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$layout = static function ( string $title ): string {
    return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $title . '"}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};
$rows = static function ( int $pageId ): int {
    return count( HistoryStore::summaries( $pageId ) );
};
$state = static function ( int $pageId ): array {
    clean_post_cache( $pageId );
    $p = get_post( $pageId );

    return [ 'content' => $p->post_content, 'modified' => $p->post_modified_gmt, 'history' => count( HistoryStore::summaries( $pageId ) ) ];
};
$rest = static function ( string $method, string $route, array $body ): array {
    $req = new WP_REST_Request( $method, '/ai-editor-divi5/v1' . $route );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( $body ) );
    $res = rest_do_request( $req );

    return [ 'status' => $res->get_status(), 'data' => (array) $res->get_data() ];
};
$mcp = static function ( string $tool, array $args ): array {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] ) );
    // The MCP endpoint accepts only the plugin API key (Bearer), never a plain logged-in user.
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    $res                           = rest_do_request( $req );
    unset( $_SERVER['HTTP_AUTHORIZATION'] );
    wp_set_current_user( 1 ); // authenticateRequest() switched to the key owner; back to --user=1 for the REST calls
    $data = (array) $res->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => $data['error'], 'isError' => true, 'result' => null ];
    }
    $text = $data['result']['content'][0]['text'] ?? '';

    return [ 'error' => null, 'isError' => (bool) ( $data['result']['isError'] ?? false ), 'result' => json_decode( (string) $text, true ) ];
};

HistoryTable::ensureReady( true );
$check( ( new Validator() )->validateContent( $layout( 'x' ) )->isValid(), 'the fixture layout used by this script passes the checker' );

$pageId = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied dry-run check', 'post_content' => $layout( 'Original' ) ], true );
if ( is_wp_error( $pageId ) ) {
    WP_CLI::error( $pageId->get_error_message() );
}

try {
    $before = $state( $pageId );
    $check( 0 === $before['history'], 'the throwaway page starts with no history' );
    sleep( 1 ); // post_modified has one-second resolution: a real write would be visible

    // ---- 1. the four dry runs --------------------------------------------------------------
    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $layout( 'Changed' ), 'dry_run' => true ] );
    $check( 200 === $r['status'] && false === $r['data']['saved'] && true === $r['data']['dry_run'] && true === $r['data']['valid'], 'REST PUT dry_run: 200, saved false, dry_run true, valid true' );
    $check( 1 === ( $r['data']['diff']['summary']['changed'] ?? null ), 'REST PUT dry_run: the diff reports exactly one changed block' );
    $check( 'title.innerContent.desktop.value' === ( $r['data']['diff']['changes'][0]['attrs'][0]['path'] ?? null ) && 'Original' === ( $r['data']['diff']['changes'][0]['attrs'][0]['before'] ?? null ), 'REST PUT dry_run: the diff names the attribute and the old value' );

    $r = $rest( 'POST', "/pages/{$pageId}/edit", [ 'find' => 'Original', 'replace' => 'Edited', 'dry_run' => true ] );
    $check( 200 === $r['status'] && false === $r['data']['saved'] && 1 === ( $r['data']['replaced'] ?? null ) && 1 === ( $r['data']['diff']['summary']['changed'] ?? null ), 'REST edit dry_run: 200, saved false, replaced 1, one changed block' );

    $m = $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => $layout( 'Changed' ), 'dry_run' => true ] );
    $check( ! $m['isError'] && false === ( $m['result']['saved'] ?? null ) && true === ( $m['result']['dry_run'] ?? null ) && 1 === ( $m['result']['diff']['summary']['changed'] ?? null ), 'MCP update_page_layout dry_run: not an error, saved false, one changed block' );

    $m = $mcp( 'edit_page_content', [ 'page_id' => $pageId, 'find' => 'Original', 'replace' => 'Edited', 'dry_run' => true ] );
    $check( ! $m['isError'] && false === ( $m['result']['saved'] ?? null ) && 1 === ( $m['result']['replaced'] ?? null ), 'MCP edit_page_content dry_run: not an error, saved false, replaced 1' );

    // ---- 2. an invalid layout is a normal preview, never a save ------------------------------
    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => '<!-- wp:divi/placeholder --><!-- wp:divi/not-a-module /--><!-- /wp:divi/placeholder -->', 'dry_run' => true ] );
    $check( 200 === $r['status'] && false === $r['data']['valid'] && [] !== $r['data']['violations'] && false === $r['data']['saved'], 'REST dry_run of an invalid layout: 200, valid false, violations listed, saved false' );
    $m = $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => '<!-- wp:divi/placeholder --><!-- wp:divi/not-a-module /--><!-- /wp:divi/placeholder -->', 'dry_run' => true ] );
    $check( ! $m['isError'] && false === ( $m['result']['valid'] ?? null ), 'MCP dry_run of an invalid layout: isError false, valid false' );

    // ---- 3. failures of a real edit stay failures ---------------------------------------------
    $r = $rest( 'POST', "/pages/{$pageId}/edit", [ 'find' => 'does not exist anywhere', 'replace' => 'x', 'dry_run' => true ] );
    $check( 422 === $r['status'] && false === $r['data']['saved'], 'REST edit dry_run with a find that does not match: the same 422 as a real edit' );

    // ---- 4. mistyped dry_run is rejected, never read as false ----------------------------------
    foreach ( [ 'false', 'yes', 0, 1, [] ] as $bad ) {
        $label = json_encode( $bad );
        $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $layout( 'Changed' ), 'dry_run' => $bad ] );
        $check( 400 === $r['status'], "REST PUT with dry_run={$label} is rejected (400)" );
        $m = $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => $layout( 'Changed' ), 'dry_run' => $bad ] );
        $check( true === $m['isError'] && -32602 === ( $m['error']['code'] ?? null ), "MCP update_page_layout with dry_run={$label} is rejected (-32602)" );
    }

    // ---- 5. none of that touched the page --------------------------------------------------------
    $after = $state( $pageId );
    $check( $after['content'] === $before['content'], 'post_content is byte-identical after every dry run and rejected call' );
    $check( $after['modified'] === $before['modified'], 'post_modified is unchanged' );
    $check( $after['history'] === $before['history'], 'no history rows were written' );

    // ---- 6. previews are logged as dry_run / read --------------------------------------------------
    global $wpdb;
    $usage = UsageTracker::tableName();
    $n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$usage} WHERE endpoint = %s AND result = %s AND page_id = %d", 'dry_run', 'read', $pageId ) ); // phpcs:ignore
    $check( $n >= 6, "previews appear in the usage log as dry_run/read ({$n} of the 6 expected)" );

    // ---- 7. a real save afterwards still works and snapshots -----------------------------------------
    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $layout( 'Changed' ) ] );
    $check( 200 === $r['status'] && true === $r['data']['saved'] && true === ( $r['data']['history']['stored'] ?? null ), 'a real save without dry_run still saves and snapshots' );
    $check( 1 === $rows( $pageId ) && get_post( $pageId )->post_content === $layout( 'Changed' ), 'the real save wrote the content and exactly one history row' );
} catch ( \Throwable $e ) {
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    if ( null !== get_post( $pageId ) ) {
        wp_delete_post( $pageId, true );
    }
}

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'dry_run verified on the real REST and MCP handlers.' );
