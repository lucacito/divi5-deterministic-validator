<?php
/**
 * Real-site check for the Settings screen's "Test connection": real requests go through the real MCP handler
 * (rest_do_request, so the real permission callbacks run), the observed facts are shaped exactly as the browser
 * script shapes them, and ConnectionDiagnosis must name each situation. The browser side itself is checked by
 * scripts/verify-settings-ui.cjs.
 *
 * Run: make verify-test-connection   (dev site only; the key owner's role and every filter are restored)
 */

use AiEditorDivi5\WP\ApiKey;
use AiEditorDivi5\WP\ConnectionDiagnosis;
use AiEditorDivi5\WP\PageAccess;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

if ( ! class_exists( ConnectionDiagnosis::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (this branch) on this site first.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

/** One probe, the way admin.js sees the reply: status, the WordPress error code, JSON-RPC shape, tool count. */
$probe = static function ( string $method, ?string $key ): array {
    unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
    if ( null !== $key ) {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
    }
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method ] ) );
    $res  = rest_do_request( $req );
    // What the browser sees is the JSON on the wire: round-trip so nested objects become arrays.
    $data = json_decode( (string) wp_json_encode( $res->get_data() ), true );
    $data = is_array( $data ) ? $data : [];
    unset( $_SERVER['HTTP_AUTHORIZATION'] );

    return [
        'status'       => (int) $res->get_status(),
        'contentType'  => 'application/json',
        'jsonCode'     => is_string( $data['code'] ?? null ) ? $data['code'] : '',
        'jsonRpc'      => '2.0' === ( $data['jsonrpc'] ?? null ),
        'toolCount'    => is_array( $data['result']['tools'] ?? null ) ? count( $data['result']['tools'] ) : null,
        'headerHint'   => false,
        'redirected'   => false,
        'hasChallenge' => false,
        'docIssuer'    => '',
        'docResource'  => '',
    ];
};

$run = static function ( ?string $key ) use ( $probe ): array {
    return ConnectionDiagnosis::sanitizeProbes( [
        'unauth' => $probe( 'ping', null ),
        'auth'   => $probe( 'ping', $key ),
        'tools'  => $probe( 'tools/list', $key ),
    ] );
};

$site = static function ( array $over = [] ): array {
    return array_merge( [
        'permalinks_plain' => '' === (string) get_option( 'permalink_structure' ),
        'is_local'         => true,
        'scheme'           => 'http',
        'key_owner_ok'     => PageAccess::keyOwnerCanUse( ApiKey::getUserId(), 'user_can' ),
        'divi'             => \AiEditorDivi5\WP\DiviCompat::installedVersion(),
    ], $over );
};

$codes = static fn( array $findings ): array => array_column( $findings, 'code' );
$key   = ApiKey::get();
$check( strlen( $key ) > 20, 'the dev site has an API key (read only, never changed)' );

// 1. healthy
$probes   = $run( $key );
$findings = ConnectionDiagnosis::explain( $probes, $site() );
$check( 401 === $probes['unauth']['status'] && 'unauthorized' === $probes['unauth']['jsonCode'], 'healthy: the unauthenticated probe is the plugin\'s own 401' );
$check( 200 === $probes['auth']['status'] && $probes['auth']['jsonRpc'], 'healthy: the keyed probe is a JSON-RPC 200' );
$check( ( $probes['tools']['toolCount'] ?? 0 ) >= 20, 'healthy: the tool list has ' . ( $probes['tools']['toolCount'] ?? 0 ) . ' tools' );
$check( in_array( 'ALL_OK', $codes( $findings ), true ) && ! in_array( 'error', array_column( $findings, 'level' ), true ), 'healthy: ALL_OK and no error (' . implode( ',', $codes( $findings ) ) . ')' );

// 2. wrong key
$findings = ConnectionDiagnosis::explain( $run( 'not-the-key' ), $site() );
$check( [ 'KEY_REJECTED' ] === $codes( $findings ), 'wrong key: KEY_REJECTED only (' . implode( ',', $codes( $findings ) ) . ')' );

// 3. the key belongs to a user who cannot edit pages: a throwaway subscriber becomes the key owner for a moment
$ownerId  = ApiKey::getUserId();
$tempId   = wp_insert_user( [ 'user_login' => 'aied_tc_subscriber', 'user_pass' => wp_generate_password(), 'user_email' => 'aied-tc@example.test', 'role' => 'subscriber' ] );
$check( is_int( $tempId ), 'a throwaway subscriber was created' );
try {
    if ( is_int( $tempId ) ) {
        update_option( 'ai_editor_divi5_api_user_id', $tempId );
        $findings = ConnectionDiagnosis::explain( $run( $key ), $site( [ 'key_owner_ok' => PageAccess::keyOwnerCanUse( ApiKey::getUserId(), 'user_can' ) ] ) );
        $check( [ 'KEY_OWNER_CANNOT_EDIT' ] === $codes( $findings ), 'key owner cannot edit pages: KEY_OWNER_CANNOT_EDIT only (' . implode( ',', $codes( $findings ) ) . ')' );
    }
} finally {
    update_option( 'ai_editor_divi5_api_user_id', $ownerId );
    wp_set_current_user( $ownerId );
    if ( is_int( $tempId ) ) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $tempId );
    }
}
$check( ApiKey::getUserId() === $ownerId && PageAccess::keyOwnerCanUse( $ownerId, 'user_can' ), 'the key owner is restored and can edit pages' );
$check( false === get_user_by( 'login', 'aied_tc_subscriber' ), 'the throwaway user is gone' );

// 4. a plugin closes the REST API for visitors who are not logged in. rest_do_request() skips core's
//    authentication gate, so ask core's own gate (WP_REST_Server::check_authentication) and convert its
//    error exactly as core does before sending it.
$block = static fn( $result ) => new WP_Error( 'rest_login_required', 'Only authenticated users can access the REST API.', [ 'status' => 401 ] );
add_filter( 'rest_authentication_errors', $block, 99 );
$gate = rest_get_server()->check_authentication();
$closed = is_wp_error( $gate ) ? rest_convert_error_to_response( $gate ) : null;
remove_filter( 'rest_authentication_errors', $block, 99 );
$check( null !== $closed, 'REST closed: core\'s own gate refuses the request' );
if ( null !== $closed ) {
    $data  = json_decode( (string) wp_json_encode( $closed->get_data() ), true );
    $fact  = [ 'status' => $closed->get_status(), 'jsonCode' => (string) ( $data['code'] ?? '' ), 'contentType' => 'application/json' ];
    $probes = ConnectionDiagnosis::sanitizeProbes( [ 'unauth' => $fact, 'auth' => $fact, 'tools' => $fact ] );
    $findings = ConnectionDiagnosis::explain( $probes, $site() );
    $check( 'rest_login_required' === $probes['unauth']['jsonCode'] && 401 === $probes['unauth']['status'], 'REST closed: the real reply is 401 rest_login_required' );
    $check( [ 'REST_BLOCKED_JSON' ] === $codes( $findings ), 'REST closed: REST_BLOCKED_JSON only (' . implode( ',', $codes( $findings ) ) . ')' );
}

// 5. the filter is gone again
$findings = ConnectionDiagnosis::explain( $run( $key ), $site() );
$check( in_array( 'ALL_OK', $codes( $findings ), true ), 'after removing the filter the site is healthy again' );

// 6. plain permalinks (a real 404 cannot be provoked in-process, so this uses the real site fact)
$findings = ConnectionDiagnosis::explain(
    ConnectionDiagnosis::sanitizeProbes( [ 'unauth' => [ 'status' => 404 ], 'auth' => [ 'status' => 404 ], 'tools' => [ 'status' => 404 ] ] ),
    $site( [ 'permalinks_plain' => true ] )
);
$check( [ 'PERMALINKS_PLAIN' ] === $codes( $findings ), 'plain permalinks plus a 404: PERMALINKS_PLAIN' );
$check( '' !== (string) get_option( 'permalink_structure' ), 'this dev site really uses pretty permalinks (nothing was changed)' );

if ( $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Test connection names every situation correctly on the real handlers.' );
