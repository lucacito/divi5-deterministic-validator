<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The owner's switch for the OAuth sign-in (Claude on the web and other apps that sign in). Off by default:
 * while it is off the plugin behaves exactly as before. Switching it on creates the table; switching it off
 * forgets every app, grant and token and drops the table.
 */
final class OAuthSettings
{
    public const OPTION = 'ai_editor_divi5_oauth_enabled';

    public static function enabled(): bool
    {
        return '1' === (string) get_option( self::OPTION, '0' );
    }

    /** False (and nothing switched on) when the table could not be created. */
    public static function enable(): bool
    {
        if ( ! OAuthTable::ensureReady( true ) ) {
            return false;
        }
        update_option( self::OPTION, '1' );

        return true;
    }

    public static function disable(): void
    {
        delete_option( self::OPTION );
        if ( OAuthTable::exists() ) {
            ( new OAuthStore() )->revokeAll();
        }
        OAuthTable::drop();
    }
}
