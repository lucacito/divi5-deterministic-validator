<?php
/**
 * Real-site check for list_global_styles and the style-reference advisory. The unit tests cover the
 * logic against shims; this drives the REAL REST and MCP handlers on the Docker site with Divi's real
 * preset, color and variable data, and proves the plugin only READS them: the stored presets, global
 * colors and variables are byte-identical before and after.
 *
 * Run: make verify-global-styles   (needs `make up` and the plugin active on the dev site)
 * It creates ONE throwaway draft page, exercises it, and deletes it again.
 */

use AiEditorDivi5\WP\GlobalStyles;
use AiEditorDivi5\WP\UsageTracker;
use Divi5Validator\Validator;
use ET\Builder\Packages\GlobalData\GlobalData;
use ET\Builder\Packages\GlobalData\GlobalPreset;

if ( ! class_exists( GlobalStyles::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (4.4.0 or later) on this site first.' );
}
if ( ! class_exists( GlobalPreset::class ) || ! class_exists( GlobalData::class ) ) {
    WP_CLI::error( "Divi 5's GlobalData classes are not loaded: activate the Divi theme on this site first." );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$layout = static function ( string $title, array $extra = [] ): string {
    $attrs = $extra + [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $title ] ] ], 'builderVersion' => '5.14.0' ];

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
$mcp = static function ( string $tool, array $args ): array {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => (object) $args ] ] ) );
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
/** A fingerprint of everything Divi stores about presets, global colors and variables. */
$fingerprint = static function (): string {
    $divi = (array) get_option( 'et_divi', [] );

    return md5( serialize( [
        get_option( 'et_divi_builder_global_presets_d5', null ),
        get_option( 'et_divi_global_variables', null ),
        $divi['et_global_data'] ?? null,
    ] ) );
};

$fpBefore = $fingerprint();

// ---- 1. the catalog matches Divi's own read API -------------------------------------------------
$presetData = GlobalPreset::get_data();
$expected   = 0;
$defaults   = [];
foreach ( (array) ( $presetData['module'] ?? [] ) as $name => $entry ) {
    if ( ! is_string( $name ) || ! is_array( $entry ) || ! is_array( $entry['items'] ?? null ) ) {
        continue;
    }
    foreach ( $entry['items'] as $item ) {
        if ( is_array( $item ) && is_string( $item['id'] ?? null ) && '' !== $item['id'] && is_string( $item['name'] ?? null ) ) {
            ++$expected;
        }
    }
    if ( is_string( $entry['default'] ?? null ) && isset( $entry['items'][ $entry['default'] ] ) ) {
        $defaults[ $name ] = $entry['default'];
    }
}
$activeColors = array_filter( GlobalData::get_global_colors(), static fn ( $c ) => is_array( $c ) && 'active' === ( $c['status'] ?? 'active' ) );

$r = $rest( 'GET', '/global-styles' );
$d = $r['data'];
$check( 200 === $r['status'], 'REST GET /global-styles: 200' );
$check( $expected === count( $d['presets'] ?? [] ) && $expected === ( $d['counts']['presets'] ?? -1 ), "the catalog lists every preset Divi reports ({$expected})" );
$check( count( $activeColors ) === count( $d['colors'] ?? [] ), 'the catalog lists every active global color (' . count( $activeColors ) . ')' );
$themeIds = array_column( array_filter( $d['colors'] ?? [], static fn ( $c ) => 'theme' === $c['source'] ), 'id' );
sort( $themeIds );
$check( [ 'gcid-body-color', 'gcid-heading-color', 'gcid-link-color', 'gcid-primary-color', 'gcid-secondary-color' ] === $themeIds, 'the five theme colors are listed with source "theme"' );
$flagged = [];
foreach ( $d['presets'] ?? [] as $p ) {
    if ( $p['default'] ) {
        $flagged[ $p['module'] ] = $p['id'];
    }
}
ksort( $defaults );
ksort( $flagged );
$check( $defaults === $flagged, 'exactly the default preset of each module is flagged default' );
$check( 0 === count( array_diff_key( $d, array_flip( [ 'presets', 'colors', 'variables', 'counts', 'truncated', 'notes' ] ) ) ), 'the response has exactly the documented keys' );
$check( false !== wp_json_encode( $d ), 'the response encodes as JSON' );

$m = $mcp( 'list_global_styles', [] );
$check( ! $m['isError'] && $m['result'] === json_decode( wp_json_encode( $d ), true ), 'MCP list_global_styles returns the same catalog as REST' );

$firstModule = $d['presets'][0]['module'] ?? '';
if ( '' !== $firstModule ) {
    $r = $rest( 'GET', '/global-styles', [], [ 'module' => $firstModule ] );
    $check( 200 === $r['status'] && [] !== $r['data']['presets'] && [ $firstModule ] === array_values( array_unique( array_column( $r['data']['presets'], 'module' ) ) ), "?module={$firstModule} lists only that module's presets" );
}
$r = $rest( 'GET', '/global-styles', [], [ 'module' => [ 'divi/text' ] ] );
$check( 400 === $r['status'], 'REST: a non-string module is rejected (400)' );
$m = $mcp( 'list_global_styles', [ 'module' => [ 'divi/text' ] ] );
$check( true === $m['isError'] && -32602 === ( $m['error']['code'] ?? null ), 'MCP: a non-string module is rejected (-32602)' );

// ---- 2. the advisory on the real handlers ---------------------------------------------------------
$knownPreset = '';
foreach ( $d['presets'] ?? [] as $p ) {
    if ( ! $p['default'] ) {
        $knownPreset = $p['id'];
        break;
    }
}
$knownColor   = $d['colors'][0]['id'] ?? 'gcid-primary-color';
$unknownP     = '11111111-2222-4333-8444-555555555555';
$unknownC     = 'gcid-00000000-0000-4000-8000-000000000000';
$colorAttr    = static fn ( string $id ): array => [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Probe' ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => '$variable(' . wp_json_encode( [ 'type' => 'color', 'value' => [ 'name' => $id, 'settings' => new stdClass() ] ] ) . ')$' ] ] ] ] ] ] ];
$clean        = $layout( 'Probe', [ 'modulePreset' => [ $knownPreset ?: 'default' ] ] + $colorAttr( $knownColor ) );
$dirty        = $layout( 'Probe', [ 'modulePreset' => [ $unknownP ] ] + $colorAttr( $unknownC ) );
$check( ( new Validator() )->validateContent( $dirty )->isValid(), 'the probe layout with unknown ids passes the checker (the advisory is not a violation)' );

$varAttr = static fn ( string $id ): array => [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Probe' ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '$variable(' . wp_json_encode( [ 'type' => 'content', 'value' => [ 'name' => $id, 'settings' => new stdClass() ] ] ) . ')$' ] ] ] ] ] ] ];
$knownVar = '';
foreach ( [ 'numbers', 'strings' ] as $kind ) {
    if ( [] !== ( $d['variables'][ $kind ] ?? [] ) ) {
        $knownVar = (string) $d['variables'][ $kind ][0]['id'];
        break;
    }
}
$unknownV = 'gvid-00000000-0000-4000-8000-000000000000';
$dirtyVar = $layout( 'Probe', $varAttr( $unknownV ) );
$cleanVar = '' === $knownVar ? '' : $layout( 'Probe', $varAttr( $knownVar ) );
$check( ( new Validator() )->validateContent( $dirtyVar )->isValid(), 'a layout citing a variable the site does not have passes the checker (the advisory is not a violation)' );
if ( '' === $knownVar ) {
    WP_CLI::warning( 'this site has no numbers or strings variable: the "known variable gets no advisory" check was skipped' );
}

$pageId = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied global-styles check', 'post_content' => $clean ], true );
if ( is_wp_error( $pageId ) ) {
    WP_CLI::error( $pageId->get_error_message() );
}

try {
    $before = [ get_post( $pageId )->post_content, get_post( $pageId )->post_modified_gmt ];

    $r = $rest( 'POST', '/validate', [ 'post_content' => $clean ] );
    $check( 200 === $r['status'] && true === $r['data']['valid'] && ! array_key_exists( 'advisories', $r['data'] ), 'REST /validate of a layout whose ids all exist: no advisories key' );
    $r = $rest( 'POST', '/validate', [ 'post_content' => $dirty ] );
    $adv = $r['data']['advisories']['style_references'] ?? [];
    $check( 200 === $r['status'] && true === $r['data']['valid'], 'REST /validate of the unknown-id layout: still 200 and valid' );
    $check( [ $unknownP ] === array_column( $adv['unknown_presets'] ?? [], 'id' ), 'the advisory names exactly the unknown preset id' );
    $check( [ $unknownC ] === array_column( $adv['unknown_colors'] ?? [], 'id' ), 'the advisory names exactly the unknown color id' );

    $m = $mcp( 'validate_layout', [ 'post_content' => $dirty ] );
    $check( ! $m['isError'] && [ $unknownP ] === array_column( $m['result']['advisories']['style_references']['unknown_presets'] ?? [], 'id' ), 'MCP validate_layout reports the same unknown preset' );

    $r = $rest( 'POST', '/validate', [ 'post_content' => $dirtyVar ] );
    $check( 200 === $r['status'] && true === $r['data']['valid'] && [ $unknownV ] === array_column( $r['data']['advisories']['style_references']['unknown_variables'] ?? [], 'id' ), 'REST /validate of a layout citing a missing variable: still valid, and the advisory names exactly that id' );
    $m = $mcp( 'validate_layout', [ 'post_content' => $dirtyVar ] );
    $check( ! $m['isError'] && true === $m['result']['valid'] && [ $unknownV ] === array_column( $m['result']['advisories']['style_references']['unknown_variables'] ?? [], 'id' ), 'MCP validate_layout reports the same unknown variable' );
    if ( '' !== $knownVar ) {
        $r = $rest( 'POST', '/validate', [ 'post_content' => $cleanVar ] );
        $check( 200 === $r['status'] && true === $r['data']['valid'] && ! array_key_exists( 'advisories', $r['data'] ), "REST /validate of a layout citing the real variable {$knownVar}: no advisories key" );
    }

    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $dirty, 'dry_run' => true ] );
    $check( 200 === $r['status'] && false === $r['data']['saved'] && true === $r['data']['dry_run'] && isset( $r['data']['advisories']['style_references'] ), 'REST PUT dry_run carries the advisory and saves nothing' );
    $m = $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => $dirty, 'dry_run' => true ] );
    $check( ! $m['isError'] && isset( $m['result']['advisories']['style_references'] ), 'MCP update_page_layout dry_run carries the advisory' );

    $post = get_post( $pageId );
    $check( [ $post->post_content, $post->post_modified_gmt ] === $before, 'the page is byte-identical after the previews' );

    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $dirty ] );
    $check( 200 === $r['status'] && true === $r['data']['saved'] && isset( $r['data']['advisories']['style_references'] ), 'a real save with unknown ids still saves, and carries the advisory' );
    $check( get_post( $pageId )->post_content === $dirty, 'the saved content is exactly what was sent (the advisory never rewrites content)' );

    // ---- 3. usage log ------------------------------------------------------------------------------
    global $wpdb;
    $usage = UsageTracker::tableName();
    $n     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$usage} WHERE endpoint = %s AND result = %s", 'list_global_styles', 'read' ) ); // phpcs:ignore
    $check( $n >= 2, "list_global_styles reads appear in the usage log ({$n})" );
} catch ( \Throwable $e ) {
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    if ( null !== get_post( $pageId ) ) {
        wp_delete_post( $pageId, true );
    }
}

// ---- 4. read-only proof ---------------------------------------------------------------------------------
$check( $fingerprint() === $fpBefore, 'presets, global colors and variables are byte-identical after everything above (read-only)' );
$check( null === get_post( $pageId ), 'the throwaway page is gone' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'list_global_styles and the style-reference advisory verified on the real REST and MCP handlers.' );
