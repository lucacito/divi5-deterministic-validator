<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

use AiEditorDivi5\Pro\Licensing\LicenseClient;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Premium gate: an adapter over the divi5lab licence-server client.
 *
 * Enforcement: the first successful activation sets a persistent unlock; only an explicit server
 * verdict of `revoked` or `invalid` (invalid_key) re-locks. A lapse (expired/canceled/past_due) and
 * transient failures (offline/429/5xx) NEVER re-lock: lapsed customers keep what they activated and
 * only lose updates and support.
 */
final class Licensing
{
    private const UNLOCKED_OPTION = 'aied_pro_premium_unlocked';
    private const LOCKING         = [ 'revoked', 'invalid' ];

    public const UPGRADE_URL = 'https://divi5lab.com/plugins/divi-5-ai-editor';

    private static ?LicenseClient $client = null;

    public static function client(): LicenseClient
    {
        if ( null === self::$client ) {
            self::$client = new LicenseClient(
                AIED_PRO_PRODUCT,
                AIED_PRO_VERSION,
                defined( 'AIED_PRO_API_BASE' ) ? (string) AIED_PRO_API_BASE : 'https://divi5lab.com',
                plugin_basename( AIED_PRO_FILE ),
                'ai-editor-divi5',
                self::UPGRADE_URL,
                'aied_pro'
            );
        }

        return self::$client;
    }

    /** @internal test isolation only */
    public static function resetForTests(): void
    {
        self::$client = null;
    }

    public static function isPremium(): bool
    {
        if ( ! get_option( self::UNLOCKED_OPTION ) ) {
            return false;
        }
        $state = self::client()->get_state();

        return ! in_array( $state['status'] ?? '', self::LOCKING, true );
    }

    /** @return array{ok: bool, error: ?string} */
    public static function activate( string $key ): array
    {
        $res = self::client()->activate( trim( $key ) );
        if ( $res['ok'] ) {
            update_option( self::UNLOCKED_OPTION, 1, false );
        }

        return [ 'ok' => (bool) $res['ok'], 'error' => $res['error'] ];
    }

    public static function deactivate(): void
    {
        self::client()->deactivate();
        delete_option( self::UNLOCKED_OPTION );
    }

    public static function refresh( bool $force = false ): void
    {
        self::client()->refresh( $force );
    }

    /**
     * @return array{valid: bool, status: ?string, expires: ?int, reason: string}
     */
    public static function status(): array
    {
        if ( null === self::client()->get_key() ) {
            return [ 'valid' => false, 'status' => null, 'expires' => null, 'reason' => 'no_key' ];
        }
        $state   = self::client()->get_state();
        $status  = (string) ( $state['status'] ?? 'unknown' );
        $expires = null;
        if ( ! empty( $state['expires'] ) ) {
            $ts      = strtotime( (string) $state['expires'] );
            $expires = false !== $ts ? $ts : null;
        }

        return [ 'valid' => self::isPremium(), 'status' => $status, 'expires' => $expires, 'reason' => $status ];
    }

    /** Local-only wipe (uninstall). Never performs HTTP. */
    public static function clear(): void
    {
        foreach ( [ self::UNLOCKED_OPTION, 'aied_pro_license_key', 'aied_pro_license_state', 'aied_pro_update_blocked' ] as $option ) {
            delete_option( $option );
        }
    }
}
