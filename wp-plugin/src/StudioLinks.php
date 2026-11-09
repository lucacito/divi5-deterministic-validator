<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only link forms a Studio brief may carry (a button's link): https addresses, one mailto address, a phone number,
 * an anchor on the same page and a path on the site itself. Everything else is refused with a stable code, so a brief
 * from a person or an assistant can never put a script, a data address or a Divi token into a link. Pure.
 */
final class StudioLinks
{
    public const MAX_LENGTH = 2000;

    /** Null when $url is an allowed link, else LINK_TOO_LONG, LINK_MALFORMED or LINK_SCHEME_NOT_ALLOWED. */
    public static function problem( string $url ): ?string
    {
        if ( '' === $url ) {
            return 'LINK_MALFORMED';
        }
        if ( strlen( $url ) > self::MAX_LENGTH ) {
            return 'LINK_TOO_LONG';
        }
        // Control characters, tokens, and the characters no address may carry unencoded (they would break a quoted
        // attribute or a CSS url()): " < > ` { } | ^ and the backslash.
        if ( 1 === preg_match( '/[\x00-\x1F\x7F"<>`{}|^\\\\]/', $url ) || str_contains( $url, '{{' ) || str_contains( $url, '$variable(' ) ) {
            return 'LINK_MALFORMED';
        }
        if ( '#' === $url[0] ) {
            return 1 === preg_match( '/^#[A-Za-z][A-Za-z0-9_-]*$/', $url ) ? null : 'LINK_MALFORMED';
        }
        if ( '/' === $url[0] ) {
            // "//host" is a protocol-relative address and "/\host" is read as one by browsers; spaces never belong in a path.
            return ( '/' === ( $url[1] ?? '' ) || '\\' === ( $url[1] ?? '' ) || 1 === preg_match( '/[\s\\\\]/', $url ) ) ? 'LINK_MALFORMED' : null;
        }
        if ( 1 === preg_match( '/^https:\/\//i', $url ) ) {
            return self::httpsProblem( $url );
        }
        if ( 1 === preg_match( '/^mailto:/i', $url ) ) {
            return 1 === preg_match( '/^mailto:[^\s@<>"\',;?]+@[^\s@<>"\',;?]+\.[^\s@<>"\',;?]+$/i', $url ) ? null : 'LINK_MALFORMED';
        }
        if ( 1 === preg_match( '/^tel:/i', $url ) ) {
            return 1 === preg_match( '/^tel:\+?[0-9 ().-]{3,30}$/i', $url ) ? null : 'LINK_MALFORMED';
        }

        return 'LINK_SCHEME_NOT_ALLOWED';
    }

    /**
     * Null when $url is an allowed picture address: an https address or a site path, with only the characters a plain
     * image address needs (letters, digits and - . _ ~ : / ? # @ ! & * + , = %). Stricter than a link because the
     * address is written into a CSS url() for backgrounds. Non-ASCII, quotes, brackets and spaces must be percent-encoded.
     */
    public static function pictureProblem( string $url ): ?string
    {
        if ( 1 !== preg_match( '~^(https://|/)~i', $url ) ) {
            return 'PICTURE_INVALID';
        }
        $code = self::problem( $url );
        if ( null !== $code ) {
            return $code;
        }

        return 1 === preg_match( '/^[A-Za-z0-9\-._~:\/?#@!&*+,=%]+$/', $url ) ? null : 'PICTURE_INVALID';
    }

    /**
     * The path of $url when it points at the site itself (a site path, or an https address on $homeUrl's host), else
     * null. The query and fragment are dropped. Used only to warn about a page that no longer exists.
     */
    public static function sitePath( string $url, string $homeUrl ): ?string
    {
        if ( '' === $url ) {
            return null;
        }
        if ( '/' === $url[0] ) {
            if ( '/' === ( $url[1] ?? '' ) ) {
                return null;
            }

            return self::pathOnly( $url );
        }
        if ( 1 !== preg_match( '/^https:\/\//i', $url ) ) {
            return null;
        }
        $host     = parse_url( $url, PHP_URL_HOST );
        $homeHost = parse_url( $homeUrl, PHP_URL_HOST );
        if ( ! is_string( $host ) || ! is_string( $homeHost ) || 0 !== strcasecmp( $host, $homeHost ) ) {
            return null;
        }
        $path     = parse_url( $url, PHP_URL_PATH );
        $path     = is_string( $path ) && '' !== $path ? $path : '/';
        $homePath = rtrim( (string) parse_url( $homeUrl, PHP_URL_PATH ), '/' );
        // A site in a subdirectory: the path is read from the install's own root (a longer name such as /blogger is not inside /blog).
        if ( '' !== $homePath && ( $path === $homePath || str_starts_with( $path, $homePath . '/' ) ) ) {
            $path = substr( $path, strlen( $homePath ) );
        }

        return '' === $path ? '/' : $path;
    }

    private static function pathOnly( string $url ): string
    {
        $cut = strcspn( $url, '?#' );

        return substr( $url, 0, $cut );
    }

    private static function httpsProblem( string $url ): ?string
    {
        if ( 1 === preg_match( '/\s/', $url ) ) {
            return 'LINK_MALFORMED';
        }
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return 'LINK_MALFORMED';
        }
        $host = $parts['host'] ?? '';
        if ( ! is_string( $host ) || str_contains( $host, '..' ) || 1 !== preg_match( '/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/', $host ) ) {
            return 'LINK_MALFORMED';
        }

        return null;
    }
}
