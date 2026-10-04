<?php
/**
 * Real-site check for get_site_structure, approval mode and the MCP protocol fixes. The unit tests cover
 * the logic against shims; this drives the REAL REST and MCP handlers on the Docker site.
 *
 * Run: make verify-site-structure   (needs `make up` and the plugin active on the dev site)
 * It creates throwaway draft pages, restores the approval-mode setting, and deletes the pages again.
 */

use AiEditorDivi5\WP\ApprovalMode;
use AiEditorDivi5\WP\SiteStructure;

if ( ! class_exists( SiteStructure::class ) || ! class_exists( ApprovalMode::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (4.6.0 or later) on this site first.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$layout = static function ( string $title ): string {
    $attrs = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $title ] ] ], 'builderVersion' => '5.14.0' ];

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading ' . serialize_block_attributes( $attrs ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
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
/** One JSON-RPC call to the MCP endpoint with the plugin's API key; returns the raw response object. */
$rpc = static function ( string $method, array $params = [] ): WP_REST_Response {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params ] ) );
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    $res                           = rest_do_request( $req );
    unset( $_SERVER['HTTP_AUTHORIZATION'] );
    wp_set_current_user( 1 ); // authenticateRequest() switched to the key owner; back to --user=1 for the REST calls

    return $res;
};
$mcp = static function ( string $tool, array $args ) use ( $rpc ): array {
    $data = (array) $rpc( 'tools/call', [ 'name' => $tool, 'arguments' => (object) $args ] )->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => $data['error'], 'isError' => true, 'result' => null ];
    }
    $text = $data['result']['content'][0]['text'] ?? '';

    return [ 'error' => null, 'isError' => (bool) ( $data['result']['isError'] ?? false ), 'result' => json_decode( (string) $text, true ) ];
};

$settingBefore = get_option( ApprovalMode::OPTION, null );
$pageId        = 0;

try {
    // ---- 1. MCP protocol fixes ---------------------------------------------------------------------
    $ping = $rpc( 'ping' );
    $check( 200 === $ping->get_status() && is_object( ( (array) $ping->get_data() )['result'] ?? null ), 'ping answers with an empty object, not a list' );
    $note = $rpc( 'notifications/initialized' );
    $check( 202 === $note->get_status(), 'a notification is accepted with 202' );

    // ---- 2. get_site_structure ----------------------------------------------------------------------
    $r = $rest( 'GET', '/site-structure' );
    $check( 200 === $r['status'], 'REST GET /site-structure answers 200' );
    $check( in_array( 'page', array_column( $r['data']['post_types'] ?? [], 'name' ), true ), 'the page post type is listed' );
    $check( isset( $r['data']['image_sizes'][0]['width'] ), 'image sizes are listed with a width' );
    $check( isset( $r['data']['acf']['active'] ), 'the ACF section reports whether ACF is active (' . ( ! empty( $r['data']['acf']['active'] ) ? 'active' : 'not active' ) . ')' );
    $m = $mcp( 'get_site_structure', [] );
    $check( null === $m['error'] && ! $m['isError'] && ( $m['result']['post_types'] ?? null ) === $r['data']['post_types'], 'MCP returns the same post types as REST' );
    $only = $mcp( 'get_site_structure', [ 'include' => [ 'image_sizes' ] ] );
    $check( isset( $only['result']['image_sizes'] ) && ! isset( $only['result']['post_types'] ), 'include limits the sections' );
    $bad = $mcp( 'get_site_structure', [ 'include' => 'nope' ] );
    $check( null !== $bad['error'] && -32602 === $bad['error']['code'], 'a bad include is rejected with -32602' );

    // ---- 3. approval mode ----------------------------------------------------------------------------
    $pageId = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'AIED verify (temporary)', 'post_content' => wp_slash( $layout( 'One' ) ) ] );
    $check( $pageId > 0, 'a throwaway draft page exists' );

    ApprovalMode::set( false );
    $two = $layout( 'Two' );
    $r   = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $two ] );
    $check( 200 === $r['status'] && true === ( $r['data']['saved'] ?? null ), 'off: a save needs no preview' );

    ApprovalMode::set( true );
    $three = $layout( 'Three' );
    $r     = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $three ] );
    $check( 428 === $r['status'] && 'PREVIEW_REQUIRED' === ( $r['data']['code'] ?? null ) && get_post( $pageId )->post_content === $two, 'on: a save without a preview is refused (428) and the page is unchanged' );
    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $three, 'dry_run' => true ] );
    $check( 200 === $r['status'] && true === ( $r['data']['valid'] ?? null ) && false === ( $r['data']['saved'] ?? null ), 'a preview works and saves nothing' );
    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $three ] );
    $check( 200 === $r['status'] && true === ( $r['data']['saved'] ?? null ) && get_post( $pageId )->post_content === $three, 'after the preview the same save goes through' );
    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $three ] );
    $check( 428 === $r['status'], 'the preview was single use: repeating the save is refused again' );

    $four = $layout( 'Four' );
    $m    = $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => $four ] );
    $check( $m['isError'] && 'PREVIEW_REQUIRED' === ( $m['result']['code'] ?? null ), 'MCP update: refused without a preview' );
    $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => $four, 'dry_run' => true ] );
    $m = $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => $four ] );
    $check( ! $m['isError'] && true === ( $m['result']['saved'] ?? null ), 'MCP update: saved after the preview' );

    $edit = [ 'page_id' => $pageId, 'find' => 'Four', 'replace' => 'Five' ];
    $m    = $mcp( 'edit_page_content', $edit );
    $check( $m['isError'] && 'PREVIEW_REQUIRED' === ( $m['result']['code'] ?? null ), 'MCP edit: refused without a preview' );
    $mcp( 'edit_page_content', $edit + [ 'dry_run' => true ] );
    $m = $mcp( 'edit_page_content', $edit );
    $check( ! $m['isError'] && true === ( $m['result']['saved'] ?? null ) && str_contains( get_post( $pageId )->post_content, 'Five' ), 'MCP edit: saved after the preview' );

    $c = $rest( 'POST', '/pages', [ 'title' => 'AIED verify created (temporary)', 'post_content' => $layout( 'Created' ) ] );
    $check( 201 === $c['status'], 'create_page is exempt: a new draft needs no preview' );

    // ---- 4. usage log ----------------------------------------------------------------------------------
    global $wpdb;
    $usage = \AiEditorDivi5\WP\UsageTracker::tableName();
    $n     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$usage} WHERE endpoint = %s AND result = %s", 'site_structure', 'read' ) ); // phpcs:ignore
    $check( $n >= 3, "site_structure reads appear in the usage log ({$n})" );
} catch ( \Throwable $e ) {
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    if ( null === $settingBefore ) {
        delete_option( ApprovalMode::OPTION );
    } else {
        update_option( ApprovalMode::OPTION, $settingBefore, false );
    }
    $temp = get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => 'AIED verify' ] );
    foreach ( $temp as $id ) {
        wp_delete_post( (int) $id, true );
    }
}

$check( get_option( ApprovalMode::OPTION, null ) === $settingBefore, 'the approval-mode setting is back to what it was' );
$check( [] === get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => 'AIED verify' ] ), 'the throwaway pages are gone' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'get_site_structure, approval mode and the protocol fixes verified on the real REST and MCP handlers.' );
