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
    /** @param array<string,mixed> $args search, per_page, page, orientation */
    public static function list( array $args ): array
    {
        $perPage     = MediaLibrary::perPage( $args['per_page'] ?? null );
        $page        = MediaLibrary::page( $args['page'] ?? null );
        $orientation = MediaLibrary::normalizeOrientation( $args['orientation'] ?? null );
        $search      = isset( $args['search'] ) && is_string( $args['search'] ) ? trim( $args['search'] ) : '';

        $ids = self::ids( $search );
        $out = [];
        foreach ( $ids as $id ) {
            if ( ! current_user_can( 'read_post', $id ) ) {
                continue;
            }
            $item = self::item( (int) $id );
            if ( null === $item || ! MediaLibrary::isImageMime( $item['mime'] ) || ! MediaLibrary::matchesOrientation( $item['orientation'], $orientation ) ) {
                continue;
            }
            $out[] = $item;
        }

        $total = count( $out );

        return [
            'items'    => array_slice( $out, ( $page - 1 ) * $perPage, $perPage ),
            'total'    => $total,
            'pages'    => (int) max( 1, (int) ceil( $total / $perPage ) ),
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /** Newest-first attachment ids (images only), bounded by MediaLibrary::SCAN_LIMIT; search matches title/caption/description, alt text and filename. @return list<int> */
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
            return array_map( 'intval', get_posts( $base ) );
        }

        $byText = get_posts( $base + [ 's' => $search ] );
        $byAlt  = get_posts( $base + [
            'meta_query' => [ [ 'key' => '_wp_attachment_image_alt', 'value' => $search, 'compare' => 'LIKE' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        ] );
        $byFile = get_posts( $base + [
            'meta_query' => [ [ 'key' => '_wp_attached_file', 'value' => $search, 'compare' => 'LIKE' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        ] );
        $merged = array_values( array_unique( array_merge( array_map( 'intval', $byText ), array_map( 'intval', $byAlt ), array_map( 'intval', $byFile ) ) ) );
        // Keep newest-first across both result sets.
        rsort( $merged );

        return array_slice( $merged, 0, MediaLibrary::SCAN_LIMIT );
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
