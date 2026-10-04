<?php

declare(strict_types=1);

/**
 * Trash, untrash, add_option and the page lookups the site-build code needs, over the in-memory `$GLOBALS['__wp_posts']`
 * store (plain objects with post_name). Like core: trashing keeps the content, renames the slug with `__trashed` and
 * remembers the status; untrashing restores the slug and brings the page back as a DRAFT. Guarded like every shim.
 */
if ( ! function_exists( 'wp_trash_post' ) ) {
    function wp_trash_post( $id ) {
        $p = $GLOBALS['__wp_posts'][ (int) $id ] ?? null;
        if ( ! $p || 'trash' === $p->post_status || ! empty( $GLOBALS['__wp_trash_fail'] ) ) {
            return false;
        }
        $p->_wp_trash_meta_status = $p->post_status;
        $p->post_status           = 'trash';
        $p->post_name             = ( $p->post_name ?? '' ) . '__trashed';
        $GLOBALS['__wp_trash_calls'][] = (int) $id;

        return $p;
    }
}
if ( ! function_exists( 'wp_untrash_post' ) ) {
    function wp_untrash_post( $id ) {
        $p = $GLOBALS['__wp_posts'][ (int) $id ] ?? null;
        if ( ! $p || 'trash' !== $p->post_status || ! empty( $GLOBALS['__wp_untrash_fail'] ) ) {
            return false;
        }
        $p->post_status = 'draft';
        $p->post_name   = (string) preg_replace( '/__trashed$/', '', (string) ( $p->post_name ?? '' ) );

        return $p;
    }
}
if ( ! function_exists( 'add_option' ) ) {
    /** Like core: false when the option already exists. */
    function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
        if ( array_key_exists( $key, $GLOBALS['__wp_options'] ?? [] ) ) {
            return false;
        }
        $GLOBALS['__wp_options'][ $key ] = $value;

        return true;
    }
}
