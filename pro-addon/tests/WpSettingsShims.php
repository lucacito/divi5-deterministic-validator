<?php

declare(strict_types=1);

/**
 * In-memory WordPress settings shims for the Pro tools: Additional CSS now, menus and post status/permalink
 * helpers in later tasks. Every definition is guarded so it never clashes with another shim.
 */

$GLOBALS['__wp_custom_css']      = '';
$GLOBALS['__wp_custom_css_fail'] = false;

if ( ! function_exists( 'wp_get_custom_css' ) ) {
    function wp_get_custom_css( $stylesheet = '' ) { return (string) $GLOBALS['__wp_custom_css']; }
}
if ( ! function_exists( 'wp_update_custom_css_post' ) ) {
    function wp_update_custom_css_post( $css, $args = [] ) {
        if ( $GLOBALS['__wp_custom_css_fail'] ) {
            return new WP_Error( 'css_failed', 'simulated css failure' );
        }
        $GLOBALS['__wp_custom_css'] = (string) $css;

        return (object) [ 'ID' => 900, 'post_content' => (string) $css ];
    }
}
