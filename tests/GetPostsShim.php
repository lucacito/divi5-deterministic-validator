<?php

declare(strict_types=1);

/**
 * Global stand-in for get_posts() as CustomFields::postHasMeta() calls it: a post "has" a meta key when a test lists it in
 * $GLOBALS['__wp_meta_keys']. No namespace, like the other shims. The Pro suite has its own richer get_posts() shim.
 */
if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args = [] ) {
        return ! empty( $GLOBALS['__wp_meta_keys'][ $args['meta_key'] ?? '' ] ) ? [ 1 ] : [];
    }
}
