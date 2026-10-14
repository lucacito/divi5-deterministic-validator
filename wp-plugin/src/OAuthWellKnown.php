<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Which discovery document, if any, a request path asks for. Pure: the hook that answers lives in OAuthRoutes.
 *
 * Clients look for the authorization server metadata at the host root, with the issuer's path inserted
 * (RFC 8414), and for an issuer with a path also at "<issuer>/.well-known/openid-configuration". Only exact,
 * known paths match: nothing else is ever answered.
 */
final class OAuthWellKnown
{
    public const AUTH_SERVER        = 'authorization_server';
    public const PROTECTED_RESOURCE = 'protected_resource';

    private const MCP_PATH = '/wp-json/ai-editor-divi5/v1/mcp';

    /**
     * @param string $path     the request path without the query string
     * @param string $homePath '' for a root install, '/blog' when the site lives in a subdirectory
     */
    public static function match( string $path, string $homePath ): ?string
    {
        $path     = self::normalise( $path );
        $homePath = self::normalise( $homePath );
        if ( '' === $path || ! str_contains( $path, '/.well-known/' ) ) {
            return null;
        }

        $as = [
            '/.well-known/oauth-authorization-server',
            '/.well-known/openid-configuration',
        ];
        $pr = [
            '/.well-known/oauth-protected-resource',
            '/.well-known/oauth-protected-resource' . self::MCP_PATH,
        ];
        if ( '' !== $homePath ) {
            // Path-inserted forms for an issuer that has a path (these reach WordPress only if the host routes them here).
            $as[] = '/.well-known/oauth-authorization-server' . $homePath;
            $as[] = '/.well-known/openid-configuration' . $homePath;
            $pr[] = '/.well-known/oauth-protected-resource' . $homePath . self::MCP_PATH;
        }

        // The same documents under the site's own path, which a subdirectory install can always serve.
        $relative = $path;
        if ( '' !== $homePath && str_starts_with( $path, $homePath . '/' ) ) {
            $relative = substr( $path, strlen( $homePath ) );
        }

        foreach ( array_unique( [ $path, $relative ] ) as $candidate ) {
            if ( in_array( $candidate, $as, true ) ) {
                return self::AUTH_SERVER;
            }
            if ( in_array( $candidate, $pr, true ) ) {
                return self::PROTECTED_RESOURCE;
            }
        }

        return null;
    }

    private static function normalise( string $path ): string
    {
        if ( '' === $path ) {
            return '';
        }
        $path = '/' . trim( $path, '/' );

        return '/' === $path ? '' : $path;
    }
}
