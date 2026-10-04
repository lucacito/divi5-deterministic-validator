<?php

declare(strict_types=1);

/**
 * Just enough of wp_insert_post() for the create_page handlers: stores the post in $GLOBALS['__wp_posts']
 * and returns its id. Guarded like every other shim.
 */
if ( ! function_exists( 'wp_insert_post' ) ) {
    function wp_insert_post( $arr, $wp_error = false ) {
        if ( ! empty( $GLOBALS['__wp_insert_fail'] ) ) {
            return new WP_Error( 'db_insert_error', 'simulated failure' );
        }
        $arr          = wp_unslash( $arr );
        $id           = max( array_merge( [ 100 ], array_keys( $GLOBALS['__wp_posts'] ?? [] ) ) ) + 1;
        $p            = new WP_Post();
        $p->ID        = $id;
        $p->post_type = (string) ( $arr['post_type'] ?? 'page' );
        $p->post_status  = (string) ( $arr['post_status'] ?? 'draft' );
        $p->post_title   = (string) ( $arr['post_title'] ?? '' );
        $p->post_content = (string) ( $arr['post_content'] ?? '' );
        $p->post_name    = (string) ( $arr['post_name'] ?? '' );
        foreach ( (array) ( $arr['meta_input'] ?? [] ) as $k => $v ) {
            $GLOBALS['__wp_postmeta'][ $id ][ $k ] = $v;
        }
        $GLOBALS['__wp_posts'][ $id ] = $p;
        $GLOBALS['__wp_inserts'][]    = $id;

        return $id;
    }
}
if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $s ), '-' ) ); }
}
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $id = 0 ) { return 'https://example.com/?page_id=' . (int) ( is_object( $id ) ? $id->ID : $id ); }
}
