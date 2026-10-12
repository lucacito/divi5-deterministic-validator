<?php

declare(strict_types=1);

/**
 * A recording stand-in for core's clean_post_cache() for the Pro unit tests (no namespace: the code under test calls the global
 * function). Each call appends the post id to $GLOBALS['__clean_post_cache'];
 * with $GLOBALS['__clean_post_cache_throws'] set it then throws, like a misbehaving clean_post_cache hook.
 */
if ( ! function_exists( 'clean_post_cache' ) ) {
    function clean_post_cache( $postId ): void
    {
        $GLOBALS['__clean_post_cache'][] = (int) $postId;
        if ( ! empty( $GLOBALS['__clean_post_cache_throws'] ) ) {
            throw new \RuntimeException( 'a clean_post_cache hook failed' );
        }
    }
}
