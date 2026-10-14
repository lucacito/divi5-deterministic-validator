<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Which redirect URIs a client may register, and when a requested one counts as registered.
 *
 * Claude's hosted apps use one fixed callback on claude.ai (or claude.com); native clients (Claude Code and the
 * other desktop tools) use a loopback address on a port that changes every session (RFC 8252). Nothing else is
 * accepted unless the site owner's code adds a host through the `jhmg_aied_oauth_redirect_hosts` filter.
 */
final class OAuthRedirectPolicy
{
    /** host => the exact callback path allowed on it. */
    public const HOSTED = [
        'claude.ai'  => '/api/mcp/auth_callback',
        'claude.com' => '/api/mcp/auth_callback',
    ];

    private const LOOPBACK_HOSTS = [ 'localhost', '127.0.0.1', '[::1]' ];

    /**
     * @param list<string> $extraHosts https hosts added by the site owner (any path on them is allowed)
     */
    public static function isAllowedForRegistration( string $uri, array $extraHosts = [] ): bool
    {
        $p = self::parts( $uri );
        if ( null === $p ) {
            return false;
        }
        if ( 'http' === $p['scheme'] ) {
            return self::isLoopbackHost( $p['host'] );
        }
        if ( isset( self::HOSTED[ $p['host'] ] ) ) {
            return 443 === $p['port'] && self::HOSTED[ $p['host'] ] === $p['path'];
        }

        return in_array( $p['host'], array_map( 'strtolower', $extraHosts ), true );
    }

    /**
     * What the `jhmg_aied_oauth_redirect_hosts` filter handed back, reduced to plain lower-case host names.
     * Anything that is not a bare host name (a URL, a path, a wildcard, a loopback or the built-in hosts) is dropped.
     *
     * @return list<string>
     */
    public static function cleanHosts( mixed $filtered ): array
    {
        if ( ! is_array( $filtered ) ) {
            return [];
        }
        $out = [];
        foreach ( $filtered as $host ) {
            if ( ! is_string( $host ) ) {
                continue;
            }
            $host = strtolower( trim( $host ) );
            if ( 1 === preg_match( '/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host ) && ! isset( self::HOSTED[ $host ] ) ) {
                $out[] = $host;
            }
        }

        return array_values( array_unique( $out ) );
    }

    /** Whether the URI points at this computer (a native app waiting on a local port). */
    public static function isLoopback( string $uri ): bool
    {
        $p = self::parts( $uri );

        return null !== $p && 'http' === $p['scheme'] && self::isLoopbackHost( $p['host'] );
    }

    /** The host to show the person on the consent screen. */
    public static function displayHost( string $uri ): string
    {
        $p = self::parts( $uri );

        return null === $p ? '' : $p['host'];
    }

    /**
     * Whether $requested is one of the registered URIs. Exact string match; the only leniency is RFC 8252's:
     * for a loopback URI the port is ignored (the same scheme, host and path must still match).
     *
     * @param list<string> $registered
     */
    public static function matches( array $registered, string $requested ): bool
    {
        foreach ( $registered as $uri ) {
            if ( hash_equals( $uri, $requested ) ) {
                return true;
            }
        }
        $req = self::parts( $requested );
        if ( null === $req || 'http' !== $req['scheme'] || ! self::isLoopbackHost( $req['host'] ) ) {
            return false;
        }
        foreach ( $registered as $uri ) {
            $reg = self::parts( $uri );
            if ( null !== $reg && 'http' === $reg['scheme'] && self::isLoopbackHost( $reg['host'] )
                && $reg['host'] === $req['host'] && $reg['path'] === $req['path'] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return ?array{scheme:string,host:string,port:int,path:string}
     */
    private static function parts( string $uri ): ?array
    {
        if ( '' === $uri || strlen( $uri ) > 2000 || 1 === preg_match( '/[\x00-\x20\x7f\\\\]/', $uri ) ) {
            return null;
        }
        $p = parse_url( $uri );
        if ( ! is_array( $p ) || isset( $p['user'] ) || isset( $p['pass'] ) || str_contains( $uri, '#' ) || str_contains( $uri, '?' ) ) {
            return null;
        }
        $scheme = strtolower( (string) ( $p['scheme'] ?? '' ) );
        $host   = strtolower( (string) ( $p['host'] ?? '' ) );
        if ( ( 'https' !== $scheme && 'http' !== $scheme ) || '' === $host || '' === (string) ( $p['path'] ?? '' ) ) {
            return null;
        }
        $path = (string) $p['path'];
        if ( 1 === preg_match( '#(^|/)\.\.?(/|$)#', $path ) || str_contains( $path, '//' ) ) {
            return null;
        }
        $port = isset( $p['port'] ) ? (int) $p['port'] : ( 'https' === $scheme ? 443 : 80 );

        return [ 'scheme' => $scheme, 'host' => $host, 'port' => $port, 'path' => $path ];
    }

    private static function isLoopbackHost( string $host ): bool
    {
        return in_array( $host, self::LOOPBACK_HOSTS, true );
    }
}
