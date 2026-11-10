<?php

declare(strict_types=1);

/**
 * Stand-ins for the few post functions the Studio live preview needs beyond the bootstrap's: wp_delete_post (fires
 * `delete_post` like core, so the history cleanup hook can be tested), get_preview_post_link, and a get_posts() handler
 * that honours post_type, post_status, author, title, s (contains), offset/numberposts and a post_modified_gmt `before` date query over
 * $GLOBALS['__wp_posts']. $GLOBALS['__get_posts_loose'] = true makes it ignore every filter and return all posts, to prove
 * the caller checks each row again itself. Guarded like every other shim.
 */
if ( ! function_exists( 'wp_delete_post' ) ) {
    function wp_delete_post( $id = 0, $force = false ) {
        $id = (int) $id;
        if ( ! isset( $GLOBALS['__wp_posts'][ $id ] ) ) {
            return false;
        }
        $post = $GLOBALS['__wp_posts'][ $id ];
        $GLOBALS['__wp_deleted'][] = [ $id, (bool) $force ];
        do_action( 'delete_post', $id );
        unset( $GLOBALS['__wp_posts'][ $id ], $GLOBALS['__wp_postmeta'][ $id ] );

        return $post;
    }
}
if ( ! function_exists( 'get_preview_post_link' ) ) {
    function get_preview_post_link( $post = null, $args = [], $url = '' ) {
        return 'https://example.com/?page_id=' . (int) ( is_object( $post ) ? $post->ID : $post ) . '&preview=true';
    }
}
if ( ! function_exists( 'aied_preview_shim_get_posts' ) ) {
    function aied_preview_shim_get_posts( $args ) {
        $rows = array_values( $GLOBALS['__wp_posts'] ?? [] );
        usort( $rows, static fn ( $a, $b ) => $a->ID <=> $b->ID );
        if ( empty( $GLOBALS['__get_posts_loose'] ) ) {
            $rows = array_values( array_filter( $rows, static function ( $p ) use ( $args ): bool {
                if ( isset( $args['post_type'] ) && $p->post_type !== $args['post_type'] ) {
                    return false;
                }
                if ( isset( $args['post_status'] ) && $p->post_status !== $args['post_status'] ) {
                    return false;
                }
                if ( isset( $args['author'] ) && (int) $p->post_author !== (int) $args['author'] ) {
                    return false;
                }
                if ( isset( $args['title'] ) && $p->post_title !== $args['title'] ) {
                    return false;
                }
                if ( isset( $args['s'] ) && ! str_contains( $p->post_title . ' ' . $p->post_content, (string) $args['s'] ) ) {
                    return false; // a search narrows by words in title or content
                }
                foreach ( (array) ( $args['date_query'] ?? [] ) as $q ) {
                    if ( is_array( $q ) && isset( $q['before'] ) && ! ( $p->post_modified_gmt < (string) $q['before'] ) ) {
                        return false;
                    }
                }

                return true;
            } ) );
        }
        $GLOBALS['__get_posts_calls'][] = $args;

        return array_slice( $rows, (int) ( $args['offset'] ?? 0 ), (int) ( $args['numberposts'] ?? $args['posts_per_page'] ?? 5 ) );
    }
}
