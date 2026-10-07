<?php

declare(strict_types=1);

/**
 * WordPress read functions driven by $GLOBALS, so the REAL CustomFieldReader / FieldTarget wrappers run without WordPress.
 * Required only by the custom-field tests. Each is guarded, so a richer shim from another test file wins.
 *   __cf_protected : list<string> keys is_protected_meta() answers true for (besides the leading-underscore rule WordPress has)
 *   __cf_types     : array<string,bool> post type => public?
 *   __wp_postmeta  : post id => key => value (from tests/bootstrap.php)
 */

if ( ! function_exists( 'is_protected_meta' ) ) {
    function is_protected_meta( $key, $type = '' ) { return in_array( $key, $GLOBALS['__cf_protected'] ?? [], true ); }
}
if ( ! function_exists( 'get_post_custom' ) ) {
    // Like core: key => list of values.
    function get_post_custom( $id = 0 ) { return array_map( static fn ( $v ) => [ $v ], $GLOBALS['__wp_postmeta'][ (int) $id ] ?? [] ); }
}
if ( ! function_exists( 'get_post_type_object' ) ) {
    function get_post_type_object( $type ) {
        $types = $GLOBALS['__cf_types'] ?? [];

        return isset( $types[ $type ] ) ? (object) [ 'public' => (bool) $types[ $type ] ] : null;
    }
}
