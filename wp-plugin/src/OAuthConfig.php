<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The fixed numbers and the canonical addresses of the plugin's OAuth sign-in, as one value object.
 * Built from explicit strings so tests never depend on WordPress; fromWordPress() builds the real one.
 *
 * The addresses are the exact strings the Settings screen shows to people, because claude.ai requires the
 * protected-resource `resource` to equal the connector URL as typed.
 */
final class OAuthConfig
{
    /** The one scope. A token acts as the signed-in user, limited by what that user may do in WordPress. */
    public const SCOPE = 'aied:edit';

    public const CODE_TTL    = 600;
    public const ACCESS_TTL  = 3600;
    public const REFRESH_TTL = 2592000;
    /** A registered client lives this long (about 13 months); one with a live grant is never pruned. */
    public const CLIENT_TTL  = 34560000;

    public const CODE_PREFIX    = 'aied_ac_';
    public const ACCESS_PREFIX  = 'aied_at_';
    public const REFRESH_PREFIX = 'aied_rt_';

    public const MAX_CLIENTS             = 100;
    public const REGISTRATIONS_PER_HOUR  = 20;
    public const MAX_REDIRECT_URIS       = 5;

    public function __construct(
        public readonly string $issuer,
        public readonly string $resource,
        public readonly string $authorizeUrl,
        public readonly string $tokenUrl,
        public readonly string $registerUrl,
        public readonly string $resourceMetadataUrl,
        /** Path of the site address ('' for a root install, '/blog' for a subdirectory install). */
        public readonly string $homePath
    ) {
    }

    public static function fromWordPress(): self
    {
        $site = rtrim( (string) get_site_url(), '/' );
        $rest = $site . '/wp-json/ai-editor-divi5/v1';
        $path = (string) wp_parse_url( $site, PHP_URL_PATH );

        return new self(
            $site,
            $rest . '/mcp',
            admin_url( 'admin-post.php' ) . '?action=aied_oauth_authorize',
            $rest . '/oauth/token',
            $rest . '/oauth/register',
            $rest . '/oauth/resource-metadata',
            rtrim( $path, '/' )
        );
    }

    /** Whether a `resource` value sent by a client names this site's MCP endpoint (a trailing slash is ignored). */
    public function isOurResource( string $resource ): bool
    {
        return rtrim( $resource, '/' ) === rtrim( $this->resource, '/' );
    }
}
