<?php

declare(strict_types=1);

/**
 * Global stand-in for get_posts(), shared by two callers (no namespace, like the other shims):
 *  - CustomFields::postHasMeta() passes `meta_key`: a post "has" it when a test lists it in $GLOBALS['__wp_meta_keys'];
 *  - CustomFieldReader::discover() passes `post_type`: the newest post ids of that type come from $GLOBALS['__cfd_posts'][type].
 * The Pro suite has its own richer get_posts() shim.
 */
if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args = [] ) {
        if ( isset( $args['meta_key'] ) ) {
            return ! empty( $GLOBALS['__wp_meta_keys'][ $args['meta_key'] ] ) ? [ 1 ] : [];
        }

        return array_slice( $GLOBALS['__cfd_posts'][ $args['post_type'] ?? '' ] ?? [], 0, (int) ( $args['posts_per_page'] ?? 5 ) );
    }
}
