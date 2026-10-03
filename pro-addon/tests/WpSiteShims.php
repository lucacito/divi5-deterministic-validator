<?php

declare(strict_types=1);

/**
 * `get_posts()` and `url_to_postid()` over the in-memory `$GLOBALS['__wp_posts']` / `__wp_postmeta` store, for the
 * site-wide tools. Supports exactly what SiteItems and the audit use: post_type, post_status (string or list),
 * fields=ids, post__in, orderby ID, posts_per_page and a single meta_query equality. Guarded like every shim.
 */
if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args = [] ) {
        $types    = (array) ( $args['post_type'] ?? 'post' );
        $statuses = (array) ( $args['post_status'] ?? 'publish' );
        $in       = isset( $args['post__in'] ) ? array_map( 'intval', (array) $args['post__in'] ) : null;
        $meta     = $args['meta_query'][0] ?? null;
        $out      = [];
        $posts    = $GLOBALS['__wp_posts'] ?? [];
        ksort( $posts );
        foreach ( $posts as $id => $p ) {
            if ( ! in_array( $p->post_type, $types, true ) ) {
                continue;
            }
            if ( ! in_array( 'any', $statuses, true ) && ! in_array( $p->post_status, $statuses, true ) ) {
                continue;
            }
            if ( null !== $in && ! in_array( (int) $id, $in, true ) ) {
                continue;
            }
            if ( null !== $meta && (string) ( $GLOBALS['__wp_postmeta'][ $id ][ $meta['key'] ] ?? '' ) !== (string) $meta['value'] ) {
                continue;
            }
            $out[] = $id;
        }
        $limit = (int) ( $args['posts_per_page'] ?? -1 );
        if ( $limit > 0 ) {
            $out = array_slice( $out, 0, $limit );
        }

        return 'ids' === ( $args['fields'] ?? '' ) ? $out : array_map( static fn ( $id ) => $GLOBALS['__wp_posts'][ $id ], $out );
    }
}
if ( ! function_exists( 'url_to_postid' ) ) {
    /** Resolves `?p=ID`, `?page_id=ID` and `/slug/` against the stored posts' `post_name`; 0 when nothing matches. */
    function url_to_postid( $url ) {
        $parts = parse_url( (string) $url );
        parse_str( (string) ( $parts['query'] ?? '' ), $q );
        foreach ( [ 'p', 'page_id' ] as $k ) {
            if ( isset( $q[ $k ] ) && isset( $GLOBALS['__wp_posts'][ (int) $q[ $k ] ] ) ) {
                return (int) $q[ $k ];
            }
        }
        $path = (string) ( $parts['path'] ?? '' );
        $home = rtrim( (string) ( parse_url( function_exists( 'home_url' ) ? (string) home_url() : '', PHP_URL_PATH ) ?? '' ), '/' );
        if ( '' !== $home && ( $path === $home || str_starts_with( $path, $home . '/' ) ) ) {
            $path = substr( $path, strlen( $home ) );
        }
        $slug = trim( $path, '/' );
        foreach ( $GLOBALS['__wp_posts'] ?? [] as $id => $p ) {
            if ( '' !== $slug && ( $p->post_name ?? '' ) === $slug ) {
                return (int) $id;
            }
        }

        return 0;
    }
}
if ( ! function_exists( 'get_post_status' ) ) {
    function get_post_status( $id = 0 ) {
        $p = $GLOBALS['__wp_posts'][ (int) $id ] ?? null;

        return $p ? $p->post_status : false;
    }
}
