<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Builds the real OAuth objects (table store, WordPress users, the hourly registration limit) once per request. */
final class OAuthRuntime
{
    private static ?OAuthConfig $config = null;

    private static ?OAuthStore $store = null;

    public static function config(): OAuthConfig
    {
        return self::$config ??= OAuthConfig::fromWordPress();
    }

    public static function store(): OAuthStore
    {
        return self::$store ??= new OAuthStore();
    }

    public static function tokens(): OAuthTokens
    {
        return new OAuthTokens(
            self::store(),
            self::config(),
            null,
            static fn( int $id ): bool => false !== get_userdata( $id )
        );
    }

    public static function clients(): OAuthClients
    {
        return new OAuthClients(
            self::store(),
            null,
            static function (): bool {
                $key = 'aied_oauth_reg_' . gmdate( 'YmdH' );
                $n   = (int) get_transient( $key );
                if ( $n >= OAuthConfig::REGISTRATIONS_PER_HOUR ) {
                    return false;
                }
                set_transient( $key, $n + 1, HOUR_IN_SECONDS );

                return true;
            },
            OAuthRedirectPolicy::cleanHosts( apply_filters( 'jhmg_aied_oauth_redirect_hosts', [] ) )
        );
    }

    public static function metadata(): OAuthMetadata
    {
        return new OAuthMetadata( self::config() );
    }
}
