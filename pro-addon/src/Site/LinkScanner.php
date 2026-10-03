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

    private const ATTRIBUTE = '/"[A-Za-z]*(?:[Uu]rl|[Ll]ink|[Hh]ref)"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/';
    private const HTML_HREF = '/href=\\\\u0022(.*?)\\\\u0022/s';
    private const SKIPPED   = '#^/(?:wp-content|wp-includes|wp-admin|wp-json|wp-login\.php|feed)(?:/|$|\?)#i';

    /** @return list<string> root-relative paths (with their query string), unique, at most MAX_LINKS */
    public static function internalPaths( string $content, string $siteHost ): array
    {
        $values = [];
        if ( preg_match_all( self::ATTRIBUTE, $content, $m ) ) {
            foreach ( $m[1] as $raw ) {
                $values[] = self::decode( $raw );
            }
        }
        if ( preg_match_all( self::HTML_HREF, $content, $m ) ) {
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
        if ( '/' === $value || '' === $value || 1 === preg_match( self::SKIPPED, $value ) ) {
            return null;
        }

        return $value;
    }
}
