<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for the Pro add-on: the main suite's shared WordPress shims, plus the few the
 * add-on's admin code needs. Every definition is guarded so it never clashes with another shim.
 */

require_once __DIR__ . '/../../tests/bootstrap.php';
require_once __DIR__ . '/../../tests/WpRestShims.php';

if ( ! defined( 'AIED_PRO_VERSION' ) ) {
    define( 'AIED_PRO_VERSION', '0.2.0' );
}
if ( ! defined( 'AIED_PRO_FILE' ) ) {
    define( 'AIED_PRO_FILE', (string) realpath( __DIR__ . '/..' ) . '/jhmg-ai-editor-for-divi-5-pro.php' );
}
if ( ! defined( 'AIED_PRO_PRODUCT' ) ) {
    define( 'AIED_PRO_PRODUCT', 'ai-editor-divi5-pro' );
}

if ( ! function_exists( 'is_admin' ) ) {
    function is_admin(): bool { return (bool) ( $GLOBALS['__wp_is_admin'] ?? false ); }
}
if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) { return 'https://example.com/wp-admin/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $args, $url = '' ) { return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . http_build_query( (array) $args ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( $action = -1, $name = '_wpnonce' ) { echo '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '">'; }
}
if ( ! function_exists( 'check_admin_referer' ) ) {
    function check_admin_referer( $action = -1 ) {
        if ( ( $_REQUEST['_wpnonce'] ?? '' ) !== 'nonce-for-' . $action ) {
            throw new \RuntimeException( 'check_admin_referer failed' );
        }

        return 1;
    }
}
if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( $message = '' ) { throw new \RuntimeException( 'wp_die: ' . $message ); }
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
    function wp_safe_redirect( $url ) { $GLOBALS['__wp_redirects'][] = (string) $url; return true; }
}
if ( ! function_exists( 'remove_filter' ) ) {
    function remove_filter( $tag, $fn, $priority = 10 ) {
        foreach ( $GLOBALS['__wp_filters'][ $tag ] ?? [] as $i => $f ) {
            if ( $f === $fn ) {
                unset( $GLOBALS['__wp_filters'][ $tag ][ $i ] );

                return true;
            }
        }

        return false;
    }
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
    function wp_doing_ajax(): bool { return (bool) ( $GLOBALS['__wp_doing_ajax'] ?? false ); }
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
    function wp_doing_cron(): bool { return (bool) ( $GLOBALS['__wp_doing_cron'] ?? false ); }
}
if ( ! function_exists( 'wp_get_environment_type' ) ) {
    function wp_get_environment_type(): string { return (string) ( $GLOBALS['__wp_env_type'] ?? 'production' ); }
}
