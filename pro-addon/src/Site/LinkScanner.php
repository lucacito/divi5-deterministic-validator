<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Site;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Finds the INTERNAL links in a Divi 5 layout, as root-relative paths, without ever making a request. Link strings
 * are read from the two places real Divi 5 content keeps them (verified against the real exports in fixtures/valid/):
 * block attributes whose key ends in `url`, `Url`, `link` or `href` (e.g. `"linkUrl":"/about/"`, `"url":"https://..."`),
 * and `href=` attributes inside the escaped HTML of text modules (`href="/about/"`). External links, anchors,
 * mailto/tel/javascript/data links, dynamic `$variable(...)` values, the home page and WordPress's own paths
 * (uploads, admin, REST) are not internal links to a page and are left out.
 */
final class LinkScanner
{
    public const MAX_LINKS = 200;

    private const ATTRIBUTE = '/"[A-Za-z]*(?:[Uu]rl|[Ll]ink|[Hh]ref)"\s*:\s*"((?:[^"\\\\]++|\\\\.)*+)"/';
    private const HTML_HREF = '/href=\\\\u0022(.*?)\\\\u0022/s';
    // WordPress's own paths anywhere in the path (a subdirectory install keeps them under its home path); `feed` only as the last segment.
    private const SKIPPED   = '#/(?:wp-content|wp-includes|wp-admin|wp-json|wp-login\.php)(?:/|$|\?)|/feed/?(?:\?|$)#i';
    // A file, not a page: the last segment (before any query string) ends in a 1-5 character extension (no trailing slash).
    private const FILE      = '#/[^/]*\.[A-Za-z0-9]{1,5}$#';

    /** @return list<string> root-relative paths (with their query string), unique, at most MAX_LINKS */
    public static function internalPaths( string $content, string $siteHost ): array
    {
        $values = [];
        $m = [];
        if ( false !== preg_match_all( self::ATTRIBUTE, $content, $m ) ) {
            foreach ( $m[1] as $raw ) {
                $values[] = self::decode( $raw );
            }
        }
        $m = [];
        if ( false !== preg_match_all( self::HTML_HREF, $content, $m ) ) {
            foreach ( $m[1] as $raw ) {
                $values[] = self::decode( $raw );
            }
        }
        $paths = [];
        foreach ( $values as $value ) {
            $path = self::toInternalPath( trim( $value ), strtolower( preg_replace( '/^www\./i', '', $siteHost ) ?? $siteHost ) );
            if ( null !== $path ) {
                $paths[ $path ] = true;
                if ( count( $paths ) >= self::MAX_LINKS ) {
                    break;
                }
            }
        }

        return array_keys( $paths );
    }

    private static function decode( string $raw ): string
    {
        $decoded = json_decode( '"' . $raw . '"' );

        return is_string( $decoded ) ? $decoded : $raw;
    }

    /** The path without its query string, for the file-extension check (a trailing slash means a page, not a file). */
    private static function pathOnly( string $value ): string
    {
        return (string) strtok( $value, '?' );
    }

    private static function toInternalPath( string $value, string $host ): ?string
    {
        if ( '' === $value || '#' === $value[0] || str_starts_with( $value, '$variable(' ) || 1 === preg_match( '/^(?:mailto|tel|sms|javascript|data):/i', $value ) ) {
            return null;
        }
        if ( 1 === preg_match( '#^https?://#i', $value ) ) {
            $parts = parse_url( $value );
            if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || strtolower( preg_replace( '/^www\./i', '', (string) $parts['host'] ) ?? '' ) !== $host ) {
                return null;
            }
            $value = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
        }
        if ( '/' !== $value[0] || str_starts_with( $value, '//' ) ) {
            return null;
        }
        $value = (string) preg_replace( '/#.*$/', '', $value );
        if ( '/' === $value || '' === $value || 1 === preg_match( self::SKIPPED, $value ) || 1 === preg_match( self::FILE, self::pathOnly( $value ) ) ) {
            return null;
        }
        // A query-only root link (`/?utm_source=x`, `/?lang=fr`) is the home page; only `/?p=ID` and `/?page_id=ID` name a post.
        if ( str_starts_with( $value, '/?' ) ) {
            parse_str( substr( $value, 2 ), $rootParams );
            if ( ! array_key_exists( 'p', $rootParams ) && ! array_key_exists( 'page_id', $rootParams ) ) {
                return null;
            }
        }
        // A search link (`/?s=x`, `/blog/?q=1&s=x`) is a query, not a page.
        $query = (string) parse_url( $value, PHP_URL_QUERY );
        if ( '' !== $query ) {
            parse_str( $query, $params );
            if ( array_key_exists( 's', $params ) ) {
                return null;
            }
        }

        return $value;
    }
}
