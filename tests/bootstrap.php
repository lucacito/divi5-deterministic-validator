<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * Loads the Composer autoloader for the pure validator (Divi5Validator\*) and
 * provides just enough WordPress shims for the plugin's WP-namespaced classes
 * (AiEditorDivi5\WP\*) to be unit-tested in isolation, without a WP install.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// In-memory option store + configurable site URL, controllable from tests.
$GLOBALS['__wp_options']  = [];
$GLOBALS['__wp_home_url'] = 'https://example.com';

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = false ) {
        return $GLOBALS['__wp_options'][ $key ] ?? $default;
    }
}
if ( ! function_exists( 'update_option' ) ) {
    function update_option( $key, $value, $autoload = null ): bool {
        $GLOBALS['__wp_options'][ $key ] = $value;
        return true;
    }
}
if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $key ): bool {
        unset( $GLOBALS['__wp_options'][ $key ] );
        return true;
    }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return $GLOBALS['__wp_home_url'];
    }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}
