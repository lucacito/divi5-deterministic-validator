<?php

declare(strict_types=1);

/**
 * The few WordPress functions the Divi 5 Lab screen and AdminPage::enqueueAssets() call. Each one records what it was
 * given in $GLOBALS['__enqueued'] so a test can see which assets a tab loads. Guarded so they never clash with another shim.
 */

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = false, $media = 'all' ) { $GLOBALS['__enqueued']['style'][ $handle ] = (string) $src; }
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = false, $args = [] ) { $GLOBALS['__enqueued']['script'][ $handle ] = [ 'src' => (string) $src, 'deps' => (array) $deps ]; }
}
if ( ! function_exists( 'wp_localize_script' ) ) {
    function wp_localize_script( $handle, $name, $data ) { $GLOBALS['__enqueued']['localize'][ $handle ][ $name ] = $data; return true; }
}
if ( ! function_exists( 'wp_enqueue_media' ) ) {
    function wp_enqueue_media( $args = [] ) { $GLOBALS['__enqueued']['media'] = true; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) { return 'https://acme.example/wp-content/plugins/' . basename( dirname( $file ) ) . '/'; }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = -1 ) { return 'nonce-for-' . $action; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
}
if ( ! function_exists( 'get_site_url' ) ) {
    function get_site_url( $blog_id = null, $path = '' ) { return 'https://acme.example' . $path; }
}
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $args, $url = '' ) { return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . http_build_query( (array) $args ); }
}
if ( ! function_exists( 'esc_attr_e' ) ) {
    function esc_attr_e( $s, $d = null ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
