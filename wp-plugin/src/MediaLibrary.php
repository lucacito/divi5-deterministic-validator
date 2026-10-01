<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Pure helpers for the read-only Media Library tool. No WordPress calls. */
final class MediaLibrary
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE     = 50;
    public const SCAN_LIMIT       = 200;

    public static function perPage( mixed $v ): int
    {
        if ( ! is_numeric( $v ) ) {
            return self::DEFAULT_PER_PAGE;
        }

        return max( 1, min( self::MAX_PER_PAGE, (int) $v ) );
    }

    public static function page( mixed $v ): int
    {
        return is_numeric( $v ) ? max( 1, (int) $v ) : 1;
    }

    public static function normalizeOrientation( mixed $v ): ?string
    {
        $o = is_string( $v ) ? strtolower( trim( $v ) ) : '';

        return in_array( $o, [ 'landscape', 'portrait', 'square' ], true ) ? $o : null;
    }

    public static function orientation( int $w, int $h ): string
    {
        $max = max( $w, $h );
        if ( $max <= 0 || abs( $w - $h ) / $max <= 0.05 ) {
            return 'square';
        }

        return $w > $h ? 'landscape' : 'portrait';
    }

    public static function matchesOrientation( string $actual, ?string $wanted ): bool
    {
        return null === $wanted || $actual === $wanted;
    }

    public static function isImageMime( string $mime ): bool
    {
        return str_starts_with( $mime, 'image/' );
    }

    /**
     * @param array<string,mixed> $a raw attachment data
     * @return array<string,mixed>
     */
    public static function formatItem( array $a ): array
    {
        // Pure class (no WordPress calls, unit-tested without WP), so plain strip_tags() rather than wp_strip_all_tags().
        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
        $clean = static fn ( mixed $v ): string => trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $v ) ) );
        $w     = (int) ( $a['width'] ?? 0 );
        $h     = (int) ( $a['height'] ?? 0 );

        return [
            'id'            => (int) ( $a['id'] ?? 0 ),
            'title'         => $clean( $a['title'] ?? '' ),
            'alt'           => $clean( $a['alt'] ?? '' ),
            'caption'       => $clean( $a['caption'] ?? '' ),
            'url'           => (string) ( $a['url'] ?? '' ),
            'thumbnail_url' => (string) ( $a['thumbnail_url'] ?? '' ),
            'width'         => $w,
            'height'        => $h,
            'orientation'   => self::orientation( $w, $h ),
            'mime'          => (string) ( $a['mime'] ?? '' ),
            'filename'      => (string) ( $a['filename'] ?? '' ),
        ];
    }
}
