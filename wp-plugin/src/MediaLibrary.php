<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Pure helpers for the read-only Media Library tool. Only core string helper used: wp_strip_all_tags(). */
final class MediaLibrary
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE     = 50;
    public const SCAN_LIMIT       = 200;

    public const MAX_SEARCH_LENGTH = 200;

    public static function perPage( mixed $v ): int
    {
        $n = self::finite( $v );
        if ( null === $n ) {
            return self::DEFAULT_PER_PAGE;
        }

        return (int) max( 1, min( self::MAX_PER_PAGE, $n ) );
    }

    /** More than SCAN_LIMIT pages can never exist (one item per page at minimum), so the page is clamped to it. */
    public static function page( mixed $v ): int
    {
        $n = self::finite( $v );

        return null === $n ? 1 : (int) max( 1, min( self::SCAN_LIMIT, $n ) );
    }

    /** Zero-based slice offset from an already-clamped page and per-page (cannot overflow). */
    public static function offset( int $page, int $perPage ): int
    {
        return ( max( 1, min( self::SCAN_LIMIT, $page ) ) - 1 ) * max( 1, min( self::MAX_PER_PAGE, $perPage ) );
    }

    /** True when a bounded query returned as many rows as the scan limit (more may exist). */
    public static function isTruncated( int $queryCount, int $scanLimit = self::SCAN_LIMIT ): bool
    {
        return $queryCount >= $scanLimit;
    }

    /** Trimmed search text capped at MAX_SEARCH_LENGTH bytes without splitting a multibyte character. */
    public static function searchTerm( mixed $v ): string
    {
        $s = is_string( $v ) ? trim( $v ) : '';
        if ( strlen( $s ) > self::MAX_SEARCH_LENGTH ) {
            $s = substr( $s, 0, self::MAX_SEARCH_LENGTH );
            // Drop a trailing partial UTF-8 sequence (at most 3 bytes) left by the byte cut.
            for ( $i = 0; $i < 3 && 1 !== preg_match( '//u', $s ); $i++ ) {
                $s = substr( $s, 0, -1 );
            }
            $s = trim( $s );
        }

        return $s;
    }

    private static function finite( mixed $v ): ?float
    {
        if ( ! is_numeric( $v ) ) {
            return null;
        }
        $n = (float) $v;

        return is_finite( $n ) ? $n : null;
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
        $clean = static fn ( mixed $v ): string => trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $v ) ) );
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
