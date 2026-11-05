<?php

declare(strict_types=1);

// Global-namespace user meta shims for the tests of per-user panels (the free and Pro suites load this one file).
namespace {
    if ( ! function_exists( 'update_user_meta' ) ) {
        function update_user_meta( $user, $key, $value )
        {
            $GLOBALS['__user_meta'][ (int) $user ][ $key ] = $value;

            return true;
        }
    }
    if ( ! function_exists( 'get_user_meta' ) ) {
        function get_user_meta( $user, $key = '', $single = false )
        {
            return $GLOBALS['__user_meta'][ (int) $user ][ $key ] ?? '';
        }
    }
}
