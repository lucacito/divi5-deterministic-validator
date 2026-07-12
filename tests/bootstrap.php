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

foreach ( [ 'MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400 ] as $c => $v ) {
    if ( ! defined( $c ) ) define( $c, $v );
}
if ( ! defined( 'AI_EDITOR_DIVI5_VERSION' ) )  define( 'AI_EDITOR_DIVI5_VERSION', '3.0.0' );
if ( ! defined( 'AI_EDITOR_DIVI5_PRODUCT' ) )  define( 'AI_EDITOR_DIVI5_PRODUCT', 'ai-editor-divi5-pro' );
if ( ! defined( 'AI_EDITOR_DIVI5_FILE' ) )     define( 'AI_EDITOR_DIVI5_FILE', __DIR__ . '/../wp-plugin/ai-editor-divi5.php' );

$GLOBALS['__wp_transients'] = [];
// Scripted HTTP: tests push [ 'code' => int, 'body' => array ] entries or the
// string 'network_error'; each wp_remote_post/get shifts the next one.
$GLOBALS['__wp_http_queue'] = [];
$GLOBALS['__wp_http_log']   = [];

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) { return $GLOBALS['__wp_transients'][ $key ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $ttl = 0 ): bool { $GLOBALS['__wp_transients'][ $key ] = $value; return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $key ): bool { unset( $GLOBALS['__wp_transients'][ $key ] ); return true; }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error { public function __construct( public string $code = 'http_request_failed' ) {} }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; }
}
if ( ! function_exists( '__wp_http_next' ) ) {
    function __wp_http_next( string $url, $payload ) {
        $GLOBALS['__wp_http_log'][] = [ 'url' => $url, 'payload' => $payload ];
        $next = array_shift( $GLOBALS['__wp_http_queue'] );
        if ( $next === null || $next === 'network_error' ) return new WP_Error();
        return [ 'response' => [ 'code' => $next['code'] ], 'body' => json_encode( $next['body'] ) ];
    }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = [] ) { return __wp_http_next( $url, $args['body'] ?? null ); }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( $url, $args = [] ) { return __wp_http_next( $url, null ); }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $res ) { return is_wp_error( $res ) ? 0 : ( $res['response']['code'] ?? 0 ); }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $res ) { return is_wp_error( $res ) ? '' : ( $res['body'] ?? '' ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ) { return json_encode( $data ); }
}
if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( $key ) { return $key === 'version' ? '6.5' : ''; }
}
