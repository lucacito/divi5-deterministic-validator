<?php

declare(strict_types=1);

/**
 * Records `register_rest_route()` calls in `$GLOBALS['__wp_rest_routes']` (list of [namespace, route, args]) and
 * answers `is_user_logged_in()` from `$GLOBALS['__wp_logged_in']`. Guarded like every shim.
 */
$GLOBALS['__wp_rest_routes'] = [];
$GLOBALS['__wp_logged_in']   = false;

if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( $namespace, $route, $args = [] ) {
        $GLOBALS['__wp_rest_routes'][] = [ (string) $namespace, (string) $route, $args ];

        return true;
    }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
    function is_user_logged_in() {
        return (bool) $GLOBALS['__wp_logged_in'];
    }
}
