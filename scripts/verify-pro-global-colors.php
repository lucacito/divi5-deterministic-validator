<?php
/**
 * Real-site check of the Pro manage_global_colors tool on Divi's own global colors: the evidence gate for that tool.
 * Through the real ToolRegistry it proves: a dry run changes nothing; create / recolor / deactivate change exactly one
 * entry each and every other stored color (including usedInPosts and lastUpdated) is untouched; a throwaway PUBLISHED page
 * that cites the new color gets that color's value in Divi's generated CSS, and the new value after the recolor; undo
 * walks it all back (the stored colors and the five theme options are identical to the start); a theme color is refused
 * without allow_theme_colors and its preview with the flag changes nothing; the token is required and single use; a color
 * changed since a batch is not removed by undoing the create.
 *
 * Run: make verify-pro-global-colors   (needs `make up`, the free plugin and the Pro add-on active on the dev site)
 * The page is fetched through the Docker network (http://wordpress, Host header = the site's host); set AIED_LOOPBACK_BASE to
 * change that base. Dev sites only. WARNING: it records about 6 batches and deletes them at the end.
 */

use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Settings\GlobalColors;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\WP\HistoryTable;
use ET\Builder\Packages\GlobalData\GlobalData;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( HistoryTable::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro on this site first.' );
}
if ( ! class_exists( GlobalData::class ) || ! function_exists( 'et_get_option' ) ) {
    WP_CLI::error( "Divi 5's GlobalData class is not loaded: activate the Divi theme on this site first." );
}

global $wpdb;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$batchIds = [];
$registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
/**
 * Divi marks its CSS cache stale at most ONCE per PHP request (PageResource::$_global_timestamp_updated) and compares file
 * mtimes with whole-second resolution. Every real tool call is its own request, so each call here starts a fresh one.
 */
$newRequest = static function (): void {
    if ( class_exists( 'ET_Core_PageResource' ) && property_exists( 'ET_Core_PageResource', '_global_timestamp_updated' ) ) {
        $prop = new ReflectionProperty( 'ET_Core_PageResource', '_global_timestamp_updated' );
        $prop->setAccessible( true );
        $prop->setValue( null, false );
    }
};
$call     = static function ( string $name, array $args ) use ( $registry, &$batchIds, $newRequest ): array {
    $newRequest();
    $res = $registry->claim( null, $name, $args, 1 );
    if ( ! $res instanceof WP_REST_Response ) {
        return [ 'error' => [ 'code' => 0, 'message' => 'not claimed' ], 'result' => null ];
    }
    $data = (array) $res->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => $data['error'], 'result' => null ];
    }
    $result = (array) json_decode( (string) ( $data['result']['content'][0]['text'] ?? '' ), true );
    foreach ( [ 'batch', 'undo_batch' ] as $k ) {
        if ( isset( $result[ $k ]['id'] ) ) {
            $batchIds[] = (int) $result[ $k ]['id'];
        }
    }

    return [ 'error' => null, 'result' => $result ];
};
$errHas = static fn ( array $r, string $needle ): bool => null !== $r['error'] && false !== stripos( (string) $r['error']['message'], $needle );

/** Every stored color (usage compared as strings: Divi's sanitiser stores them as strings) plus the five theme options. */
$fingerprint = static function (): string {
    $norm = [];
    foreach ( (array) GlobalColors::read() as $id => $e ) {
        $norm[ $id ] = [ (string) ( $e['color'] ?? '' ), (string) ( $e['folder'] ?? '' ), (string) ( $e['label'] ?? '' ), GlobalColors::isThemeId( $id ) ? '' : (string) ( $e['lastUpdated'] ?? '' ), (string) ( $e['status'] ?? '' ), array_map( 'strval', (array) ( $e['usedInPosts'] ?? [] ) ) ];
    }
    $opts = [];
    foreach ( GlobalData::$customizer_colors as $def ) {
        $opts[ $def['option_name'] ] = et_get_option( $def['option_name'], $def['default'] );
    }

    return md5( serialize( [ $norm, $opts ] ) );
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
/** The page HTML plus every same-site stylesheet it links (Divi may put the variables in a static file). */
$render = static function ( int $pageId ) use ( $fetch ): string {
    $html = $fetch( add_query_arg( 'page_id', $pageId, home_url( '/' ) ) );
    $all  = $html;
    if ( preg_match_all( '/<link[^>]+href=[\'"]([^\'"]+\.css[^\'"]*)[\'"]/i', $html, $m ) ) {
        $site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        foreach ( array_slice( array_unique( $m[1] ), 0, 20 ) as $href ) {
            if ( $site === (string) wp_parse_url( $href, PHP_URL_HOST ) ) {
                $all .= "\n" . $fetch( $href );
            }
        }
    }

    return $all;
};
$defines = static fn ( string $css, string $id, string $value ): bool => 1 === preg_match( '/--' . preg_quote( $id, '/' ) . '\s*:\s*' . preg_quote( $value, '/' ) . '/i', $css );
$layout  = static function ( string $id ): string {
    $color = '$variable(' . wp_json_encode( [ 'type' => 'color', 'value' => [ 'name' => $id, 'settings' => new stdClass() ] ] ) . ')$';
    $attrs = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'AIED colour probe' ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => $color ] ] ] ] ] ], 'builderVersion' => '5.14.0' ];

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading ' . serialize_block_attributes( $attrs ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};

HistoryTable::ensureReady( true );
BatchTable::ensureReady( true );

// ---- 1. baseline --------------------------------------------------------------------------------------------------
$fpBefore   = $fingerprint();
$countStart = count( (array) GlobalColors::read() );
$themeOpts  = [];
foreach ( GlobalData::$customizer_colors as $def ) {
    $themeOpts[ $def['option_name'] ] = et_get_option( $def['option_name'], $def['default'] );
}
WP_CLI::log( "baseline: {$countStart} color(s) (five of them theme colors)" );
$pageId = 0;
$newId  = '';
try {
    // ---- 2. a dry run changes nothing -------------------------------------------------------------------------------
    $new = [ [ 'label' => 'AIED verify sky', 'color' => '#00aaff' ] ];
    $d   = $call( 'manage_global_colors', [ 'colors' => $new, 'dry_run' => true ] );
    $check( null === $d['error'] && true === $d['result']['dry_run'] && false === $d['result']['changed'] && is_string( $d['result']['preview_token'] ?? null ) && null === $d['result']['changes'][0]['id'], 'dry run: a preview, a token, no id yet, changed=false' );
    $check( $fingerprint() === $fpBefore, 'the dry run changed nothing' );
    $r = $call( 'manage_global_colors', [ 'colors' => $new ] );
    $check( $errHas( $r, 'preview_token' ), 'a real run without the token is refused' );
    $check( $fingerprint() === $fpBefore, 'the refused run changed nothing' );

    // ---- 3. create ----------------------------------------------------------------------------------------------------
    $c = $call( 'manage_global_colors', [ 'colors' => $new, 'preview_token' => $d['result']['preview_token'] ] );
    $check( null === $c['error'] && true === $c['result']['changed'] && 'create' === $c['result']['changes'][0]['action'] && true === $c['result']['batch']['undoable'], 'create: applied and undoable' );
    $newId   = (string) ( $c['result']['changes'][0]['id'] ?? '' );
    $createB = (int) ( $c['result']['batch']['id'] ?? 0 );
    $after   = (array) GlobalColors::read();
    $check( str_starts_with( $newId, 'gcid-' ) && '#00aaff' === ( $after[ $newId ]['color'] ?? '' ) && 'AIED verify sky' === ( $after[ $newId ]['label'] ?? '' ) && 'active' === ( $after[ $newId ]['status'] ?? '' ), 'the color is stored through Divi: ' . $newId );
    $check( count( $after ) === $countStart + 1, 'exactly one entry was added' );
    $reuse = $call( 'manage_global_colors', [ 'colors' => $new, 'preview_token' => $d['result']['preview_token'] ] );
    $check( $errHas( $reuse, 'preview_token' ), 'the token works once' );

    // ---- 4. a real page that cites it gets the color in Divi's CSS ----------------------------------------------------
    $pageId = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'aied verify gc probe', 'post_content' => wp_slash( $layout( $newId ) ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ], true );
    if ( is_wp_error( $pageId ) ) {
        throw new \RuntimeException( $pageId->get_error_message() );
    }
    $css = $render( (int) $pageId );
    $check( '' !== $css, 'the probe page rendered (if this FAILS: set AIED_LOOPBACK_BASE to a URL the wpcli container can reach)' );
    $check( $defines( $css, $newId, '#00aaff' ), "Divi's generated CSS defines --{$newId} as #00aaff on the page that cites it" );

    // ---- 5. recolor, then the page shows the new value ----------------------------------------------------------------
    sleep( 2 ); // Divi treats a CSS file written in the same second as a cache clear as fresh; a real page is older than that
    $recolor = [ [ 'id' => $newId, 'color' => '#ff8800' ] ];
    $d2      = $call( 'manage_global_colors', [ 'colors' => $recolor, 'dry_run' => true ] );
    $c2      = $call( 'manage_global_colors', [ 'colors' => $recolor, 'preview_token' => $d2['result']['preview_token'] ?? '' ] );
    $recB    = (int) ( $c2['result']['batch']['id'] ?? 0 );
    $check( true === ( $c2['result']['changed'] ?? false ) && '#ff8800' === ( GlobalColors::read()[ $newId ]['color'] ?? '' ), 'recolor: stored' );
    $css2 = $render( (int) $pageId );
    $check( $defines( $css2, $newId, '#ff8800' ) && ! $defines( $css2, $newId, '#00aaff' ), 'the page now gets the new value (Divi cleared its caches)' );

    // ---- 6. deactivate ------------------------------------------------------------------------------------------------
    $off  = [ [ 'id' => $newId, 'status' => 'inactive' ] ];
    $d3   = $call( 'manage_global_colors', [ 'colors' => $off, 'dry_run' => true ] );
    $c3   = $call( 'manage_global_colors', [ 'colors' => $off, 'preview_token' => $d3['result']['preview_token'] ?? '' ] );
    $offB = (int) ( $c3['result']['batch']['id'] ?? 0 );
    $check( 'deactivate' === ( $c3['result']['changes'][0]['action'] ?? '' ) && 'inactive' === ( GlobalColors::read()[ $newId ]['status'] ?? '' ), 'deactivate: stored, nothing deleted' );

    // ---- 7. the token is bound to the arguments ----------------------------------------------------------------------------
    $d4 = $call( 'manage_global_colors', [ 'colors' => [ [ 'id' => $newId, 'color' => '#123456' ] ], 'dry_run' => true ] );
    $x  = $call( 'manage_global_colors', [ 'colors' => [ [ 'id' => $newId, 'color' => '#654321' ] ], 'preview_token' => $d4['result']['preview_token'] ?? '' ] );
    $check( $errHas( $x, 'preview_token' ) && 'inactive' === ( GlobalColors::read()[ $newId ]['status'] ?? '' ) && '#ff8800' === GlobalColors::read()[ $newId ]['color'], 'a token for other arguments does not open a run' );

    // ---- 8. refusals ------------------------------------------------------------------------------------------------------
    foreach ( [ 'red', 'var(--x)', '#fff; }', 'url(x)', "#ff\nf", '#fff;', 'rgb(1,2,3);color:red' ] as $bad ) {
        $r = $call( 'manage_global_colors', [ 'colors' => [ [ 'label' => 'x', 'color' => $bad ] ], 'dry_run' => true ] );
        $check( $errHas( $r, '"color" must be a hex value' ), 'refused color: ' . json_encode( $bad ) );
    }
    $r = $call( 'manage_global_colors', [ 'colors' => [ [ 'label' => 'x', 'color' => "  #fff\n" ] ], 'dry_run' => true ] );
    $check( null === $r['error'] && '#fff' === ( $r['result']['changes'][0]['after']['color'] ?? '' ), 'surrounding whitespace is trimmed (stored as #fff), never stored raw' );
    $r = $call( 'manage_global_colors', [ 'colors' => [ [ 'id' => 'gcid-00000000-0000-4000-8000-000000000000', 'color' => '#fff' ] ], 'dry_run' => true ] );
    $check( $errHas( $r, 'no color with the id' ), 'an unknown id is refused' );
    $r = $call( 'manage_global_colors', [ 'colors' => [ [ 'label' => 'ok', 'color' => '#fff' ], [ 'id' => 'gcid-00000000-0000-4000-8000-000000000000', 'color' => '#fff' ] ], 'dry_run' => true ] );
    $check( $errHas( $r, 'Nothing was changed' ), 'one bad entry refuses the whole palette' );

    // ---- 9. theme colors ------------------------------------------------------------------------------------------------------
    $theme = [ [ 'id' => 'gcid-primary-color', 'color' => '#ff0000' ] ];
    $r     = $call( 'manage_global_colors', [ 'colors' => $theme, 'dry_run' => true ] );
    $check( $errHas( $r, 'allow_theme_colors' ), 'a theme color is refused without allow_theme_colors' );
    $fpMid = $fingerprint();
    $r     = $call( 'manage_global_colors', [ 'colors' => $theme, 'allow_theme_colors' => true, 'dry_run' => true ] );
    $check( null === $r['error'] && true === $r['result']['theme_colors_touched'] && $fingerprint() === $fpMid, 'a theme color preview with the flag reports it and changes nothing' );
    $themeNow = [];
    foreach ( GlobalData::$customizer_colors as $def ) {
        $themeNow[ $def['option_name'] ] = et_get_option( $def['option_name'], $def['default'] );
    }
    $check( $themeOpts === $themeNow, 'the five theme options are untouched' );

    // ---- 10. undo walks it all back; undo of a create refuses once the color changed since ------------------------
    $u = $call( 'undo_batch', [ 'batch_id' => $createB ] );
    $check( null === $u['error'] && false === ( $u['result']['fully_undone'] ?? true ) && isset( GlobalColors::read()[ $newId ] ), 'undoing the create while later batches changed the color does not remove it' );
    foreach ( [ $offB, $recB ] as $b ) {
        $u = $call( 'undo_batch', [ 'batch_id' => $b ] );
        $check( true === ( $u['result']['fully_undone'] ?? false ), "undo of batch {$b}" );
    }
    $check( '#00aaff' === ( GlobalColors::read()[ $newId ]['color'] ?? '' ) && 'active' === ( GlobalColors::read()[ $newId ]['status'] ?? '' ), 'the color is exactly as the create left it' );
    $u = $call( 'undo_batch', [ 'batch_id' => $createB ] );
    $check( true === ( $u['result']['fully_undone'] ?? false ) && ! isset( GlobalColors::read()[ $newId ] ), 'now the undo of the create removes the color' );
} catch ( \Throwable $e ) {
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    if ( $pageId > 0 && null !== get_post( (int) $pageId ) ) {
        wp_delete_post( (int) $pageId, true );
    }
    if ( '' !== $newId && isset( ( (array) GlobalColors::read() )[ $newId ] ) ) { // cleanup after an abort: remove the throwaway color directly
        GlobalColors::write( GlobalColors::customMap( array_diff_key( (array) GlobalColors::read(), [ $newId => 1 ] ) ) );
    }
}

// ---- 11. the site is exactly as it was ---------------------------------------------------------------------------------------
$leftover = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'aied verify gc%'" ); // phpcs:ignore
$check( $fingerprint() === $fpBefore, 'every stored color (including usedInPosts and lastUpdated) and the five theme options are identical to the start' );
$check( count( (array) GlobalColors::read() ) === $countStart, 'the color count equals the baseline' );
$check( 0 === $leftover, "no throwaway page remains ({$leftover})" );
if ( [] !== $batchIds ) {
    $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
}
$left = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
$check( 0 === $left, "none of the batch rows this run created remain ({$left} left)" );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Pro manage_global_colors verified on the real Divi global colors.' );
