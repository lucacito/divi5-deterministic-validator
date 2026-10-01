<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read-only query of the site's Media Library images. Never uploads, sideloads or
 * modifies media. The CALLER must have checked current_user_can('upload_files').
 */
final class MediaService
{
    /**
     * @param array<string,mixed> $args search, per_page, page, orientation
     * @return array{items:list<array<string,mixed>>,total:int,pages:int,page:int,per_page:int,truncated:bool}
     */
    public static function list( array $args ): array
    {
        $perPage     = MediaLibrary::perPage( $args['per_page'] ?? null );
        $page        = MediaLibrary::page( $args['page'] ?? null );
        $orientation = MediaLibrary::normalizeOrientation( $args['orientation'] ?? null );
        $search      = isset( $args['search'] ) && is_string( $args['search'] ) ? MediaLibrary::searchTerm( sanitize_text_field( $args['search'] ) ) : '';

        $found     = self::ids( $search );
        $truncated = $found['truncated'];
        self::prime( $found['ids'] );

        // Per-attachment permission first (needed for an honest total), cheap and cache-backed.
        $allowed = [];
        foreach ( $found['ids'] as $id ) {
            if ( ! current_user_can( 'read_post', $id ) ) {
                continue;
            }
            // Without an orientation filter nothing else is needed before slicing; with one, classify by dimensions.
            if ( null !== $orientation && ! MediaLibrary::matchesOrientation( self::orientationOf( $id ), $orientation ) ) {
                continue;
            }
            $allowed[] = $id;
        }

        $total = count( $allowed );
        $items = [];
        // Build the full item (url, metadata, thumbnail) only for the requested page slice.
        foreach ( array_slice( $allowed, MediaLibrary::offset( $page, $perPage ), $perPage ) as $id ) {
            $item = self::item( $id );
            if ( null !== $item && MediaLibrary::isImageMime( $item['mime'] ) ) {
                $items[] = $item;
            }
        }

        return [
            'items'     => $items,
            'total'     => $total,
            'pages'     => max( 1, (int) ceil( $total / $perPage ) ),
            'page'      => $page,
            'per_page'  => $perPage,
            'truncated' => $truncated,
        ];
    }

    /**
     * Newest-first attachment ids (images only), bounded by MediaLibrary::SCAN_LIMIT per query; search matches
     * title/caption/description, alt text and filename. "truncated" is true when any contributing query hit the
     * limit (or the merged set exceeded it), i.e. older matches exist that were not scanned.
     *
     * @return array{ids:list<int>,truncated:bool}
     */
    private static function ids( string $search ): array
    {
        $base = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'posts_per_page' => MediaLibrary::SCAN_LIMIT,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ];
        if ( '' === $search ) {
            $ids = array_map( 'intval', get_posts( $base ) );

            return [ 'ids' => $ids, 'truncated' => MediaLibrary::isTruncated( count( $ids ) ) ];
        }

        $byText = array_map( 'intval', get_posts( $base + [ 's' => $search ] ) );
        $byAlt  = array_map( 'intval', get_posts( $base + [
            'meta_query' => [ [ 'key' => '_wp_attachment_image_alt', 'value' => $search, 'compare' => 'LIKE' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        ] ) );
        $byFile = array_map( 'intval', get_posts( $base + [
            'meta_query' => [ [ 'key' => '_wp_attached_file', 'value' => $search, 'compare' => 'LIKE' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        ] ) );

        $truncated = MediaLibrary::isTruncated( count( $byText ) ) || MediaLibrary::isTruncated( count( $byAlt ) ) || MediaLibrary::isTruncated( count( $byFile ) );
        $merged    = array_values( array_unique( array_merge( $byText, $byAlt, $byFile ) ) );
        if ( ! $merged ) {
            return [ 'ids' => [], 'truncated' => false ];
        }
        if ( count( $merged ) > MediaLibrary::SCAN_LIMIT ) {
            $truncated = true;
        }

        // The three result sets are merged by id, so re-sort the union newest-first by date in one bounded query.
        $ordered = get_posts( [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post__in'       => $merged,
            'posts_per_page' => MediaLibrary::SCAN_LIMIT,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ] );

        return [ 'ids' => array_map( 'intval', $ordered ), 'truncated' => $truncated ];
    }

    /** Warm the post and meta caches (and the parents' posts, used by the read_post check) in bulk. @param list<int> $ids */
    private static function prime( array $ids ): void
    {
        if ( ! $ids || ! function_exists( '_prime_post_caches' ) ) {
            return;
        }
        _prime_post_caches( $ids, false, true );
        $parents = [];
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( $post && (int) $post->post_parent > 0 ) {
                $parents[ (int) $post->post_parent ] = true;
            }
        }
        if ( $parents ) {
            _prime_post_caches( array_keys( $parents ), false, false );
        }
    }

    private static function orientationOf( int $id ): string
    {
        $meta = wp_get_attachment_metadata( $id );

        return MediaLibrary::orientation(
            is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0,
            is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0
        );
    }

    /** @return array<string,mixed>|null */
    private static function item( int $id ): ?array
    {
        $post = get_post( $id );
        $url  = wp_get_attachment_url( $id );
        if ( ! $post || ! $url ) {
            return null;
        }
        $meta = wp_get_attachment_metadata( $id );
        $file = get_attached_file( $id );

        return MediaLibrary::formatItem( [
            'id'            => $id,
            'title'         => (string) $post->post_title,
            'alt'           => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'caption'       => (string) $post->post_excerpt,
            'url'           => (string) $url,
            'thumbnail_url' => (string) ( wp_get_attachment_image_url( $id, 'medium' ) ?: $url ),
            'width'         => is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0,
            'height'        => is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0,
            'mime'          => (string) get_post_mime_type( $id ),
            'filename'      => $file ? basename( (string) $file ) : '',
        ] );
    }
}
