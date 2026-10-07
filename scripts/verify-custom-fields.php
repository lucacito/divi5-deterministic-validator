<?php
/**
 * Real-site check for get_custom_fields and custom field discovery: the REAL REST and MCP handlers on the Docker site, a plain
 * (non-ACF) post meta key, a protected key, a secret-looking key, a Divi Project when the site has the type, a contributor
 * who is refused, and a front-end render of a module bound to the plain key (the evidence the guide needs).
 *
 * Run: make verify-custom-fields   (needs `make up` and the plugin active on the dev site; ACF is NOT needed)
 * Creates throwaway pages / a draft Project / a contributor user and always deletes them. Dev sites only (scripts/dev-guard.php).
 * Set AIED_LOOPBACK_BASE if http://wordpress is not reachable from the wpcli container.
 */

use AiEditorDivi5\WP\CustomFieldReader;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();
if ( ! class_exists( CustomFieldReader::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (with get_custom_fields) on this site first.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
// A previous run that was killed may have left its throwaway contributor behind: remove only that exact login.
$stale = get_user_by( 'login', 'aied_cf_contrib' );
if ( $stale && $stale->ID > 1 ) {
    wp_delete_user( $stale->ID );
}
WP_CLI::log( function_exists( 'acf_import_field_group' ) ? 'NOTE  ACF is active on this site (the evidence below is not an "ACF absent" run)' : 'NOTE  ACF is not installed on this site (the render evidence is for a plain post meta key)' );

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$rest = static function ( string $method, string $route, array $params = [] ): array {
    $req = new WP_REST_Request( $method, '/ai-editor-divi5/v1' . $route );
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

    return [ 'error' => $data['error'] ?? null, 'text' => (string) $text, 'result' => (array) json_decode( (string) $text, true ) ];
};
$fetch = static function ( string $url ) use ( &$fetch ): string {
    $p    = wp_parse_url( $url );
    $host = ( $p['host'] ?? 'localhost' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
    $path = ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
    $base = rtrim( (string) ( getenv( 'AIED_LOOPBACK_BASE' ) ?: 'http://wordpress' ), '/' );
    $res  = wp_remote_get( $base . $path, [ 'headers' => [ 'Host' => $host ], 'timeout' => 30, 'redirection' => 0, 'sslverify' => false ] );
    if ( is_wp_error( $res ) ) {
        return '';
    }
    $code = (int) wp_remote_retrieve_response_code( $res );
    if ( in_array( $code, [ 301, 302 ], true ) && '' !== (string) wp_remote_retrieve_header( $res, 'location' ) ) {
        return $fetch( (string) wp_remote_retrieve_header( $res, 'location' ) );
    }

    return (string) wp_remote_retrieve_body( $res );
};
// The legacy binding form (what a real builder export holds and what the style guide teaches).
$bind   = static fn ( string $field ): string => '$variable(' . wp_json_encode( [ 'type' => 'content', 'value' => [ 'name' => 'post_meta_key', 'settings' => [ 'before' => '', 'after' => '', 'select_meta_key' => 'custom_meta_' . $field, 'meta_key' => '', 'date_format' => 'default', 'custom_date_format' => '', 'enable_html' => 'off' ] ] ] ) . ')$';
$layout = static fn ( string $field ): string => '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/text ' . serialize_block_attributes( [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => $bind( $field ) ] ] ], 'builderVersion' => '5.14.0' ] ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

$VALUE  = 'Plain value 7f3a';
$posts  = [];
$userId = 0;
try {
    $pid = wp_insert_post( [
        'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'aied custom fields verify',
        'post_content' => wp_slash( $layout( 'aied_plain_meta' ) ),
        'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on', 'aied_plain_meta' => $VALUE, 'aied_api_token' => 'SECRET-7f3a', '_aied_hidden' => 'HIDDEN-7f3a', 'aied_list' => [ 1, 2, 3 ] ],
    ], true );
    if ( is_wp_error( $pid ) ) {
        throw new RuntimeException( $pid->get_error_message() );
    }
    $posts[] = (int) $pid;
    $check( true, 'a throwaway published page exists' );
    $before = md5( serialize( get_post_meta( $pid ) ) );

    // 1. REST read
    $r = $rest( 'GET', "/posts/{$pid}/custom-fields" );
    $keys = array_column( (array) ( $r['data']['fields'] ?? [] ), 'value', 'key' );
    $check( 200 === $r['status'] && ( $keys['aied_plain_meta'] ?? null ) === $VALUE, 'REST: the plain (non-ACF) key and its value are returned' );
    $check( ( $keys['aied_list'] ?? null ) === '[1,2,3]', 'REST: an array value comes back as JSON' );
    $check( ! isset( $keys['aied_api_token'] ) && ! isset( $keys['_aied_hidden'] ) && ! isset( $keys['_et_pb_use_builder'] ), 'REST: the secret-looking, underscore and Divi protected keys are not returned' );
    $check( (int) ( $r['data']['withheld'] ?? 0 ) >= 1, 'REST: the secret-looking key is counted in withheld' );
    $check( ! str_contains( wp_json_encode( $r['data'] ), 'SECRET-7f3a' ) && ! str_contains( wp_json_encode( $r['data'] ), 'HIDDEN-7f3a' ), 'REST: neither hidden value appears anywhere in the answer' );

    // 2. MCP read, keys filter, bad input
    $m = $mcp( 'get_custom_fields', [ 'id' => $pid ] );
    $check( null === $m['error'] && $m['result']['fields'] === $r['data']['fields'], 'MCP: the same fields as REST' );
    $f = $mcp( 'get_custom_fields', [ 'id' => $pid, 'keys' => [ 'aied_plain_meta', 'aied_api_token' ] ] );
    $check( [ 'aied_plain_meta' ] === array_column( (array) ( $f['result']['fields'] ?? [] ), 'key' ), 'MCP: the keys filter returns only the requested shown key, even if a secret one is requested' );
    $check( -32602 === ( $mcp( 'get_custom_fields', [ 'id' => (string) $pid ] )['error']['code'] ?? null ), 'MCP: a non-integer id is rejected' );
    $check( 404 === $rest( 'GET', '/posts/99999999/custom-fields' )['status'], 'REST: a missing post is 404' );

    // 3. a non-public type looks missing; a Divi Project (when the type exists) is readable
    $hdr = wp_insert_post( [ 'post_type' => 'et_header_layout', 'post_status' => 'draft', 'post_title' => 'aied hdr', 'meta_input' => [ 'aied_x' => '1' ] ], true );
    if ( ! is_wp_error( $hdr ) ) {
        $posts[] = (int) $hdr;
        $check( 404 === $rest( 'GET', "/posts/{$hdr}/custom-fields" )['status'], 'REST: a Theme Builder layout (non-public type) looks missing' );
    }
    if ( post_type_exists( 'project' ) ) {
        $prj = wp_insert_post( [ 'post_type' => 'project', 'post_status' => 'draft', 'post_title' => 'aied project', 'meta_input' => [ 'aied_project_meta' => 'proj-7f3a' ] ], true );
        if ( ! is_wp_error( $prj ) ) {
            $posts[] = (int) $prj;
            $pr = $rest( 'GET', "/posts/{$prj}/custom-fields" );
            $check( 200 === $pr['status'] && 'project' === ( $pr['data']['post_type'] ?? '' ) && 'proj-7f3a' === ( array_column( (array) $pr['data']['fields'], 'value', 'key' )['aied_project_meta'] ?? null ), 'REST: a Divi Project\'s custom field is readable' );
        }
    } else {
        WP_CLI::log( 'SKIP  this site has no "project" post type; the Project read is covered by the unit tests' );
    }

    // 4. a contributor cannot use it
    $inserted = wp_insert_user( [ 'user_login' => 'aied_cf_contrib', 'user_pass' => wp_generate_password(), 'role' => 'contributor' ] );
    if ( is_wp_error( $inserted ) ) {
        throw new RuntimeException( 'could not create the throwaway contributor: ' . $inserted->get_error_message() );
    }
    $userId = (int) $inserted;
    wp_set_current_user( $userId );
    $denied = $rest( 'GET', "/posts/{$pid}/custom-fields" );
    wp_set_current_user( 1 );
    $check( in_array( $denied['status'], [ 401, 403 ], true ), 'REST: a contributor is refused (' . $denied['status'] . ')' );

    // 5. discovery
    $s  = $mcp( 'get_site_structure', [ 'include' => [ 'custom_fields' ] ] );
    $pg = null;
    foreach ( (array) ( $s['result']['custom_fields']['post_types'] ?? [] ) as $t ) {
        if ( 'page' === ( $t['post_type'] ?? '' ) ) {
            $pg = $t;
        }
    }
    $names = array_column( (array) ( $pg['keys'] ?? [] ), 'key' );
    $check( in_array( 'aied_plain_meta', $names, true ), 'get_site_structure custom_fields: the plain key is discovered on pages' );
    $check( ! in_array( 'aied_api_token', $names, true ) && ! in_array( '_aied_hidden', $names, true ), 'discovery: secret-looking and underscore keys are not listed' );
    $check( ! str_contains( $s['text'], $VALUE ), 'discovery: values are never listed' );

    // 6. nothing was written by any of the reads
    $check( md5( serialize( get_post_meta( $pid ) ) ) === $before, 'the reads left the post meta byte-identical' );

    // 7. EVIDENCE: Divi renders a module bound to a plain (non-ACF) meta key
    $html = $fetch( get_permalink( $pid ) );
    $check( '' !== $html && str_contains( $html, $VALUE ), 'RENDER: the front end shows the plain meta value through the custom_meta_ binding (Divi ' . wp_get_theme()->get( 'Version' ) . ')' );
    $check( ! str_contains( $html, '$variable(' ), 'RENDER: no $variable( token leaks into the HTML' );
} catch ( Throwable $e ) {
    $check( false, 'unexpected error: ' . $e->getMessage() );
} finally {
    foreach ( $posts as $id ) {
        wp_delete_post( $id, true );
    }
    // Only ever delete the throwaway user: never id 1 or any other account.
    if ( $userId > 1 ) {
        $made = get_userdata( $userId );
        if ( $made && 'aied_cf_contrib' === $made->user_login ) {
            wp_delete_user( $userId );
        }
    }
    wp_set_current_user( 1 );
}

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed.' );
}
WP_CLI::success( 'get_custom_fields verified on the real site.' );
