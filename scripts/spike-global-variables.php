<?php
/**
 * ONE-OFF evidence spike for the Pro manage_global_variables tool (not a gate, not kept in the Makefile).
 * Reads Divi 5.14's real variable storage and does a self-restoring write round trip with two throwaway variables.
 *
 * Run: docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/spike-global-variables.php --user=1
 * Dev sites only (scripts/dev-guard.php). The stored option is snapshotted first and put back byte for byte at the end.
 * The page is fetched through the Docker network (http://wordpress, Host header = the site's host); set AIED_LOOPBACK_BASE to change that.
 */

use ET\Builder\Packages\GlobalData\GlobalData;

if ( ! class_exists( GlobalData::class ) || ! function_exists( 'et_get_option' ) ) {
    WP_CLI::error( "Divi 5's GlobalData class is not loaded: activate the Divi theme on this site first." );
}
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$raw = static function () {
    return maybe_unserialize( et_get_option( 'global_variables', [], '', true, false, '', '', true ) );
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
/** The home page HTML plus every same-site stylesheet it links. */
$render = static function () use ( $fetch ): string {
    $html = $fetch( home_url( '/' ) );
    $all  = $html;
    if ( preg_match_all( '/<link[^>]+href=[\'"]([^\'"]+\.css[^\'"]*)[\'"]/i', $html, $m ) ) {
        $site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        foreach ( array_slice( array_unique( $m[1] ), 0, 25 ) as $href ) {
            if ( $site === (string) wp_parse_url( $href, PHP_URL_HOST ) ) {
                $all .= "\n" . $fetch( $href );
            }
        }
    }

    return $all;
};
$context = static function ( string $haystack, string $needle ): array {
    $out = [];
    $off = 0;
    while ( count( $out ) < 3 && false !== ( $pos = strpos( $haystack, $needle, $off ) ) ) {
        $out[] = substr( $haystack, max( 0, $pos - 120 ), strlen( $needle ) + 240 );
        $off   = $pos + strlen( $needle );
    }

    return $out;
};
$clearCaches = static function (): void {
    if ( class_exists( 'ET_Core_PageResource' ) ) {
        ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
    }
};

$snapshot = $raw();
$out      = [ 'divi_user_can_edit_theme_options' => current_user_can( 'edit_theme_options' ), 'variables_manager_allowed' => function_exists( 'et_pb_is_allowed' ) ? (bool) et_pb_is_allowed( 'variables_manager' ) : null ];

// 1. What is stored, and what Divi's own read adds.
$out['raw_is_array'] = is_array( $snapshot );
$out['raw_groups']   = [];
foreach ( (array) $snapshot as $type => $group ) {
    $out['raw_groups'][ $type ] = is_array( $group ) ? count( $group ) : gettype( $group );
}
$out['raw_sample_per_group'] = [];
foreach ( (array) $snapshot as $type => $group ) {
    if ( is_array( $group ) && [] !== $group ) {
        $id                                   = (string) array_key_first( $group );
        $entry                                = $group[ $id ];
        $out['raw_sample_per_group'][ $type ] = [ 'id' => $id, 'entry' => $entry, 'field_types' => array_map( 'gettype', (array) $entry ) ];
    }
}
$read = GlobalData::get_global_variables();
$out['read_groups']             = array_map( static fn ( $g ): int => count( (array) $g ), (array) $read );
$out['read_fonts_ids']          = array_keys( (array) ( ( (array) $read )['fonts'] ?? [] ) );
$out['customizer_fonts_ids']    = array_keys( (array) GlobalData::$customizer_fonts );
$firstNumber                    = (array) ( ( (array) $read )['numbers'] ?? [] );
$out['read_sample_has_allowed'] = [] === $firstNumber ? null : isset( ( (array) reset( $firstNumber ) )['allowedActions'] );

$restored = false;
try {
    // 2. A write of exactly what is stored (six groups only): does Divi leave the option unchanged?
    $six = [];
    foreach ( [ 'numbers', 'strings', 'images', 'links', 'fonts', 'gradients' ] as $t ) {
        if ( ! empty( $snapshot[ $t ] ) && is_array( $snapshot[ $t ] ) ) {
            $six[ $t ] = $snapshot[ $t ];
        }
    }
    GlobalData::set_global_variables( $six );
    $out['noop_roundtrip_identical'] = serialize( $raw() ) === serialize( $snapshot );
    if ( ! $out['noop_roundtrip_identical'] ) {
        $out['noop_roundtrip_after'] = $raw();
    }

    // 3. Two throwaway variables: how Divi stores them.
    $numId  = 'gvid-aiedspike-0000-4000-8000-000000000001';
    $strId  = 'gvid-aiedspike-0000-4000-8000-000000000002';
    $marker = 'AIEDSPIKEMARKER Hello world';
    $with   = $six;
    $with['numbers'][ $numId ] = [ 'label' => 'AIED spike number', 'value' => 'clamp(1rem, 2vw + 1rem, 3rem)', 'order' => 99, 'status' => 'active' ];
    $with['strings'][ $strId ] = [ 'label' => 'AIED spike string', 'value' => $marker, 'order' => 99, 'status' => 'active' ];
    GlobalData::set_global_variables( $with );
    $after                       = $raw();
    $out['stored_number']        = $after['numbers'][ $numId ] ?? null;
    $out['stored_string']        = $after['strings'][ $strId ] ?? null;
    $out['stored_order_type']    = gettype( $after['numbers'][ $numId ]['order'] ?? null );
    $out['untouched_unchanged']  = array_diff_key( (array) $after['numbers'], [ $numId => 1 ] ) === (array) ( $six['numbers'] ?? [] );

    // 4. Does Divi emit a variable's value into the page or its CSS, without anything citing it?
    $clearCaches();
    $css                               = $render();
    $out['emit_page_bytes']            = strlen( $css );
    $out['emit_number_id_contexts']    = $context( $css, $numId );
    $out['emit_string_id_contexts']    = $context( $css, $strId );
    $out['emit_string_marker_contexts'] = $context( $css, $marker );
    $out['emit_any_gvid_definitions']  = preg_match_all( '/--gvid-[a-z0-9-]+\s*:/i', $css );

    // 5. Archived: still emitted?
    $arch                        = $with;
    $arch['numbers'][ $numId ]['status'] = 'archived';
    GlobalData::set_global_variables( $arch );
    $clearCaches();
    $css2                              = $render();
    $out['archived_number_emitted']    = false !== strpos( $css2, $numId );
    $out['archived_stored_status']     = $raw()['numbers'][ $numId ]['status'] ?? null;
} finally {
    et_update_option( 'global_variables', $snapshot, false, '', '', true );
    $clearCaches();
    $restored = serialize( $raw() ) === serialize( $snapshot );
}
$out['restored_byte_identical'] = $restored;

WP_CLI::log( (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
if ( ! $restored ) {
    WP_CLI::error( 'The stored variables were NOT restored byte for byte: fix this by hand before anything else (dev site).' );
}
WP_CLI::success( 'Spike finished; the stored variables are exactly as they were.' );
