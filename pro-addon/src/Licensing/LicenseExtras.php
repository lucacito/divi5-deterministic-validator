<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parse and sanitize server response fields related to licence tiers and limits.
 * Fields that fail type checks or validation are set to null/false; URLs must be https
 * and belong to the API base host.
 */
final class LicenseExtras
{
    /** @return array{tier:?string,tier_label:?string,sites_used:?int,sites_allowed:?int,founding:bool,lifetime:bool,upgrade_url:?string,manage_url:?string} */
    public static function empty(): array
    {
        return [
            'tier'          => null,
            'tier_label'    => null,
            'sites_used'    => null,
            'sites_allowed' => null,
            'founding'      => false,
            'lifetime'      => false,
            'upgrade_url'   => null,
            'manage_url'    => null,
        ];
    }

    /**
     * Parse and sanitize a decoded HTTP response body.
     *
     * @param mixed $decoded the JSON-decoded body
     * @param string $apiBase the licence server's base URL (e.g. "https://divi5lab.com")
     * @return array{tier:?string,tier_label:?string,sites_used:?int,sites_allowed:?int,founding:bool,lifetime:bool,upgrade_url:?string,manage_url:?string}
     */
    public static function fromBody( mixed $decoded, string $apiBase ): array
    {
        $extras = self::empty();

        if ( ! is_array( $decoded ) ) {
            return $extras;
        }

        // tier: string or null
        if ( isset( $decoded['tier'] ) && is_string( $decoded['tier'] ) ) {
            $extras['tier'] = $decoded['tier'];
        }

        // tier_label: string or null
        if ( isset( $decoded['tier_label'] ) && is_string( $decoded['tier_label'] ) && '' !== $decoded['tier_label'] && strlen( $decoded['tier_label'] ) <= 40 ) {
            $extras['tier_label'] = $decoded['tier_label'];
        }

        // sites_used: integer or null
        if ( isset( $decoded['sites_used'] ) && is_int( $decoded['sites_used'] ) && $decoded['sites_used'] >= 0 ) {
            $extras['sites_used'] = $decoded['sites_used'];
        }

        // sites_allowed: integer or null (can be null for unlimited)
        if ( isset( $decoded['sites_allowed'] ) ) {
            if ( is_int( $decoded['sites_allowed'] ) && $decoded['sites_allowed'] >= 1 ) {
                $extras['sites_allowed'] = $decoded['sites_allowed'];
            } elseif ( null === $decoded['sites_allowed'] ) {
                $extras['sites_allowed'] = null;
            }
        }

        // founding: boolean (defaults to false if missing)
        if ( isset( $decoded['founding'] ) && is_bool( $decoded['founding'] ) ) {
            $extras['founding'] = $decoded['founding'];
        }

        // lifetime: boolean (defaults to false if missing)
        if ( isset( $decoded['lifetime'] ) && is_bool( $decoded['lifetime'] ) ) {
            $extras['lifetime'] = $decoded['lifetime'];
        }

        // upgrade_url: https URL on the API base host or null
        if ( isset( $decoded['upgrade_url'] ) && is_string( $decoded['upgrade_url'] ) ) {
            $extras['upgrade_url'] = self::sanitizeUrl( $decoded['upgrade_url'], $apiBase );
        }

        // manage_url: https URL on the API base host or null
        if ( isset( $decoded['manage_url'] ) && is_string( $decoded['manage_url'] ) ) {
            $extras['manage_url'] = self::sanitizeUrl( $decoded['manage_url'], $apiBase );
        }

        return $extras;
    }

    /**
     * Validate and sanitize a URL: must be https and belong to the API base host.
     */
    private static function sanitizeUrl( string $url, string $apiBase ): ?string
    {
        // Must start with https:// and carry no backslash or userinfo (those are how a URL says one host and means another).
        if ( ! str_starts_with( $url, 'https://' ) || str_contains( $url, '\\' ) ) {
            return null;
        }

        // Parse the API base to get the host
        $apiParsed = wp_parse_url( $apiBase );
        $urlParsed = wp_parse_url( $url );

        $apiHost = $apiParsed['host'] ?? '';
        $urlHost = $urlParsed['host'] ?? '';

        if ( isset( $urlParsed['user'] ) || isset( $urlParsed['pass'] ) ) {
            return null;
        }

        // Hosts must match (a leading "www." on either side does not make it another host)
        $bare = static fn ( string $h ): string => str_starts_with( strtolower( $h ), 'www.' ) ? substr( strtolower( $h ), 4 ) : strtolower( $h );
        if ( $bare( (string) $apiHost ) !== $bare( (string) $urlHost ) ) {
            return null;
        }

        // Also check the port if present in the API base
        $apiPort = $apiParsed['port'] ?? ( str_starts_with( $apiBase, 'https' ) ? 443 : 80 );
        $urlPort = $urlParsed['port'] ?? ( str_starts_with( $url, 'https' ) ? 443 : 80 );

        if ( $apiPort !== $urlPort ) {
            return null;
        }

        return $url;
    }
}
