<?php
/**
 * Real-site check of the Pro REST and ChatGPT (OpenAPI) faces on the real WordPress REST server: the routes exist
 * for every Pro tool, authenticate with the plugin API key (and refuse an anonymous call), map errors to 400/403/401,
 * run the tool through the same capability gate as MCP, and the live /openapi.json carries every Pro operation inside
 * the ChatGPT importer's limits. Read-only: it calls only previews and read tools and one invalid-argument write, and
 * it creates and changes nothing.
 *
 * Run: make verify-pro-rest   (needs `make up`, the free plugin and the Pro add-on active on the dev site; no licence
 * is needed: this script registers the Pro REST/OpenAPI hooks itself with the unlock forced and rebuilds the REST
 * server, exactly as the add-on wires them when licensed)
 */

use AiEditorDivi5\Pro\Rest\ProOpenApi;
use AiEditorDivi5\Pro\Rest\ProRest;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( \AiEditorDivi5\WP\RestController::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro on this site first.' );
}

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

// The add-on only wires these while licensed; force the unlock here, BEFORE the REST server is first built.
$registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
( new ProRest( $registry ) )->register();
( new ProOpenApi( $registry ) )->register();
// Under WP-CLI the REST server was already built during bootstrap, before these hooks existed: build it again so
// the routes are registered (on a real request the server is built after plugins load, so this is not needed).
$GLOBALS['wp_rest_server'] = null;

$ns   = '/ai-editor-divi5/v1';
$send = static function ( string $route, array $body = [], ?string $rawBody = null, string $method = 'POST' ) use ( $ns ): array {
    $req = new WP_REST_Request( $method, $ns . $route );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( $rawBody ?? wp_json_encode( $body ) );
    $res  = rest_do_request( $req );
    $data = (array) $res->get_data();

    return [ 'status' => $res->get_status(), 'data' => $data ];
};
$asKey = static function ( callable $fn ) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    wp_set_current_user( 0 );
    try {
        return $fn();
    } finally {
        unset( $_SERVER['HTTP_AUTHORIZATION'] );
        wp_set_current_user( 1 );
    }
};

$names = array_map( static fn ( $t ): string => $t->name(), Tools::all() );

// ---- the live OpenAPI spec -----------------------------------------------------------------------------------------
$spec = $send( '/openapi.json', [], '', 'GET' );
$paths = (array) ( $spec['data']['paths'] ?? [] );
$ops   = 0;
$long  = 0;
$ids   = [];
foreach ( $paths as $methods ) {
    foreach ( (array) $methods as $op ) {
        $ops++;
        $long += strlen( (string) ( $op['description'] ?? '' ) ) > 300 ? 1 : 0;
        $ids[] = $op['operationId'] ?? '';
    }
}
$check( 200 === $spec['status'], 'GET /openapi.json answers 200' );
$proPaths = array_filter( array_keys( $paths ), static fn ( string $p ): bool => str_starts_with( $p, '/pro/' ) );
$check( count( $proPaths ) === count( $names ) && [] === array_diff( array_map( static fn ( string $n ): string => '/pro/' . $n, $names ), array_keys( $paths ) ), 'the live spec has one /pro/ operation for each of the ' . count( $names ) . ' Pro tools' );
$check( $ops <= 30 && 0 === $long && count( $ids ) === count( array_unique( $ids ) ), "the live spec has {$ops} operations (limit 30), every description at most 300 characters, unique operationIds" );
$check( isset( $spec['data']['components']['schemas']['ProResult'], $spec['data']['components']['schemas']['ProError'] ), 'the ProResult and ProError schemas are in the spec' );

// ---- authentication -------------------------------------------------------------------------------------------------
wp_set_current_user( 0 );
$anon = $send( '/pro/list_batches', [] );
wp_set_current_user( 1 );
$check( 401 === $anon['status'], 'an anonymous call is 401' );
$keyed = $asKey( static fn () => $send( '/pro/list_batches', [] ) );
$check( 200 === $keyed['status'] && isset( $keyed['data']['batches'] ), 'the plugin API key (Bearer) authenticates: list_batches answers 200' );
$logged = $send( '/pro/list_batches', [] );
$check( 200 === $logged['status'], 'a logged-in administrator is accepted too' );

// ---- tools over REST ------------------------------------------------------------------------------------------------
$a = $send( '/pro/audit_site', [ 'scope' => 'pages' ] );
$check( 200 === $a['status'] && isset( $a['data']['total_items'] ) && array_key_exists( 'next_cursor', $a['data'] ), 'audit_site over REST: 200 with total_items and next_cursor' );
$f = $send( '/pro/find_replace_site', [ 'find' => 'AIED-REST-NOWHERE-' . wp_generate_password( 12, false ), 'replace' => 'x', 'dry_run' => true ] );
$check( 200 === $f['status'] && 0 === ( $f['data']['matched'] ?? -1 ), 'find_replace_site dry run over REST: 200, matched 0' );
$l = $send( '/pro/list_theme_layouts', [] );
$check( 200 === $l['status'] && isset( $l['data']['templates'] ), 'list_theme_layouts over REST: 200 with templates' );

// ---- error mapping --------------------------------------------------------------------------------------------------
$bad = $send( '/pro/set_front_page', [ 'page_id' => 'not-a-number' ] );
$check( 400 === $bad['status'] && 'invalid_argument' === ( $bad['data']['code'] ?? null ) && false !== strpos( (string) ( $bad['data']['message'] ?? '' ), 'page_id' ), 'an invalid argument is 400 with the tool\'s own message (nothing was changed)' );
$nf = $send( '/pro/undo_batch', [ 'batch_id' => 987654321 ] );
$check( 400 === $nf['status'] && false !== strpos( (string) ( $nf['data']['message'] ?? '' ), 'not found' ), 'undo_batch of a batch that does not exist is 400 "not found"' );
$arr = $send( '/pro/list_batches', [], '[1,2,3]' );
$check( 400 === $arr['status'], 'a JSON body that is a list, not an object, is 400' );

// ---- the tool's own capability, same gate as MCP ----------------------------------------------------------------------
$strip = static fn ( $caps ) => array_merge( (array) $caps, [ 'edit_theme_options' => false ] );
add_filter( 'user_has_cap', $strip );
try {
    $no = $send( '/pro/list_theme_layouts', [] );
    $check( 403 === $no['status'] && 'forbidden' === ( $no['data']['code'] ?? null ), 'without edit_theme_options, list_theme_layouts is 403 over REST' );
    $ok = $send( '/pro/list_batches', [] );
    $check( 200 === $ok['status'], 'a tool that needs a different capability still works' );
} finally {
    remove_filter( 'user_has_cap', $strip );
}

// ---- a locked licence --------------------------------------------------------------------------------------------------
$locked = new ProRest( new ToolRegistry( Tools::all(), static fn (): bool => false ) );
$r      = $locked->handle( 'list_batches', new WP_REST_Request( 'POST', $ns . '/pro/list_batches' ) );
$check( $r instanceof WP_Error && 403 === ( $r->get_error_data()['status'] ?? 0 ), 'with the licence locked a call is refused (403 pro_locked)' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Pro REST and OpenAPI faces verified on the real REST server.' );
