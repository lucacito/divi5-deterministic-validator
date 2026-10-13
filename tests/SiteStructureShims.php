<?php

declare(strict_types=1);

/**
 * WordPress and ACF read functions driven by $GLOBALS['__ss_*'], so the REAL readers in SiteStructure
 * can be exercised without WordPress. Required only by the tests that need them. Defining the acf_*
 * functions makes ACF look "active" for the rest of the process; tests that need "ACF absent" pass
 * null readers to SiteStructure::catalogWith() instead.
 */

if ( ! function_exists( 'get_post_types' ) ) {
    function get_post_types( $args = [], $output = 'names' ) { return $GLOBALS['__ss_post_types'] ?? []; }
}
if ( ! function_exists( 'get_taxonomies' ) ) {
    function get_taxonomies( $args = [], $output = 'names' ) { return $GLOBALS['__ss_taxonomies'] ?? []; }
}
if ( ! function_exists( 'get_object_taxonomies' ) ) {
    function get_object_taxonomies( $type ) { return $GLOBALS['__ss_object_taxonomies'][ $type ] ?? []; }
}
if ( ! function_exists( 'get_all_post_type_supports' ) ) {
    function get_all_post_type_supports( $type ) { return $GLOBALS['__ss_supports'][ $type ] ?? []; }
}
if ( ! function_exists( 'wp_get_registered_image_subsizes' ) ) {
    function wp_get_registered_image_subsizes() { return $GLOBALS['__ss_image_sizes'] ?? []; }
}
if ( ! function_exists( 'wp_count_posts' ) ) {
    // Falls back to __wc_counts because this file is required before tests/WooShims.php, so this definition wins there.
    function wp_count_posts( $type = 'post' ) { return (object) ( $GLOBALS['__ss_counts'][ $type ] ?? $GLOBALS['__wc_counts'] ?? [] ); }
}
if ( ! function_exists( 'acf_get_field_groups' ) ) {
    function acf_get_field_groups() { return $GLOBALS['__ss_acf_groups'] ?? []; }
}
if ( ! function_exists( 'acf_get_fields' ) ) {
    function acf_get_fields( $key ) { return $GLOBALS['__ss_acf_fields'][ $key ] ?? false; } // ACF returns false when a group has no fields
}
