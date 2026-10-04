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
    private const STATE_OPTION    = 'aied_pro_license_state';
    private const EXTRAS_OPTION   = 'aied_pro_license_extras';
    private const SITE_LIMIT_OPTION = 'aied_pro_site_limit';
    /** Answers that are a decision about this licence. Anything else (an HTML error page, an empty or non-JSON body, `invalid_request`) is a fault. */
    private const VERDICT_ERRORS  = [ 'invalid_key', 'product_mismatch', 'license_not_usable' ];

    private const DEFAULT_API_BASE = 'https://divi5lab.com';

    public const UPGRADE_URL = 'https://divi5lab.com/plugins/divi-5-ai-editor';

    public const REFRESH_BACKOFF_TRANSIENT = 'aied_pro_refresh_backoff';
    public const REFRESH_BACKOFF_SECONDS   = 900;

    private static ?LicenseClient $client = null;

    /**
     * The licence key is sent to this host, so an override is honoured only on a local or development site
     * (`wp_get_environment_type()`); on any other site the constant is ignored and divi5lab.com is used.
     */
    public static function apiBaseFor( ?string $override, string $envType ): string
    {
        if ( null !== $override && '' !== $override && in_array( $envType, [ 'local', 'development' ], true ) ) {
            return rtrim( $override, '/' );
        }

        return self::DEFAULT_API_BASE;
    }

    public static function client(): LicenseClient
    {
        if ( null === self::$client ) {
            self::$client = new LicenseClient(
                AIED_PRO_PRODUCT,
                AIED_PRO_VERSION,
                // Dev/test override only. Never define it in production: the licence key is sent to this host.
                self::apiBaseFor( defined( 'AIED_PRO_API_BASE' ) ? (string) AIED_PRO_API_BASE : null, wp_get_environment_type() ),
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
        $seen    = null;
        $capture = static function ( $response, $args = [], $url = '' ) use ( &$seen ) {
            if ( is_array( $response ) && is_string( $url ) && str_contains( $url, '/api/license/' ) ) {
                $seen = [ 'code' => (int) wp_remote_retrieve_response_code( $response ), 'body' => (string) wp_remote_retrieve_body( $response ) ];
            }

            return $response;
        };
        add_filter( 'http_response', $capture, 10, 3 );
        try {
            $res = self::client()->activate( trim( $key ) );
        } finally {
            remove_filter( 'http_response', $capture, 10 );
        }

        if ( $res['ok'] ) {
            update_option( self::UNLOCKED_OPTION, 1, false );
            // Store extras and site limit status from the activation response
            self::storeExtrasAndSiteLimit( $seen );
        }

        return [ 'ok' => (bool) $res['ok'], 'error' => $res['error'] ];
    }

    public static function deactivate(): void
    {
        self::client()->deactivate();
        delete_option( self::UNLOCKED_OPTION );
        delete_option( self::EXTRAS_OPTION );
        delete_option( self::SITE_LIMIT_OPTION );
    }

    public static function refresh( bool $force = false ): void
    {
        $client = self::client();
        if ( null === $client->get_key() ) {
            return;
        }
        $before = $client->get_state();
        if ( ! $force && ! self::refreshDue( $before ) ) {
            return;
        }
        $seen    = null;
        $capture = static function ( $response, $args = [], $url = '' ) use ( &$seen ) {
            if ( is_array( $response ) && is_string( $url ) && str_contains( $url, '/api/license/' ) ) {
                $seen = [ 'code' => (int) wp_remote_retrieve_response_code( $response ), 'body' => (string) wp_remote_retrieve_body( $response ) ];
            }

            return $response;
        };
        add_filter( 'http_response', $capture, 10, 3 );
        try {
            $client->refresh( $force );
        } finally {
            remove_filter( 'http_response', $capture, 10 );
        }

        // Store extras and site limit status from the response
        self::storeExtrasAndSiteLimit( $seen );

        // The generated client files any definitive-looking non-200 answer as `invalid`, and a 200 with an empty or
        // non-JSON body (a proxy or captive-portal page) as `unknown`, losing `expires`. Only an answer that really
        // is a decision about this licence may change the stored state; after a fault the previous state comes back.
        $after = $client->get_state();
        if ( $after !== $before && ! self::isVerdict( $seen ) && ! self::isStatusAnswer( $seen ) ) {
            if ( null === $before ) {
                delete_option( self::STATE_OPTION );
            } else {
                update_option( self::STATE_OPTION, $before, false );
            }
        }

        // A refresh that stored nothing new (network error, 429, 5xx, or a reverted fault) must not be retried on
        // every request while the licence server is down.
        $now = $client->get_state();
        if ( (int) ( $now['checked_at'] ?? 0 ) !== (int) ( $before['checked_at'] ?? 0 ) ) {
            delete_transient( self::REFRESH_BACKOFF_TRANSIENT );
        } else {
            set_transient( self::REFRESH_BACKOFF_TRANSIENT, 1, self::REFRESH_BACKOFF_SECONDS );
        }
    }

    /** Not on ajax or cron requests, not during a backoff, and only once the one-day cache window has passed. */
    private static function refreshDue( ?array $state ): bool
    {
        if ( wp_doing_ajax() || wp_doing_cron() ) {
            return false;
        }
        if ( false !== get_transient( self::REFRESH_BACKOFF_TRANSIENT ) ) {
            return false;
        }

        return time() - (int) ( $state['checked_at'] ?? 0 ) >= DAY_IN_SECONDS;
    }

    /** A 200 whose JSON body carries a non-empty string `status`: a real answer about this licence (active, expired...). */
    private static function isStatusAnswer( ?array $seen ): bool
    {
        if ( null === $seen || 200 !== $seen['code'] ) {
            return false;
        }
        $body = json_decode( $seen['body'], true );

        return is_array( $body ) && is_string( $body['status'] ?? null ) && '' !== $body['status'];
    }

    /** @param array{code:int, body:string}|null $seen the raw validate answer */
    private static function isVerdict( ?array $seen ): bool
    {
        if ( null === $seen ) {
            return false;
        }
        $body = json_decode( $seen['body'], true );
        if ( ! is_array( $body ) ) {
            return false;
        }
        if ( 200 === $seen['code'] ) {
            return is_string( $body['status'] ?? null ) && in_array( $body['status'], self::LOCKING, true );
        }
        if ( ! in_array( $seen['code'], [ 403, 404 ], true ) ) {
            return false;
        }

        return is_string( $body['error'] ?? null ) && in_array( $body['error'], self::VERDICT_ERRORS, true );
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
        foreach ( [ self::UNLOCKED_OPTION, 'aied_pro_license_key', 'aied_pro_license_state', 'aied_pro_update_blocked', self::EXTRAS_OPTION, self::SITE_LIMIT_OPTION ] as $option ) {
            delete_option( $option );
        }
        delete_transient( self::REFRESH_BACKOFF_TRANSIENT );
    }

    /** @return array{tier:?string,tier_label:?string,sites_used:?int,sites_allowed:?int,founding:bool,lifetime:bool,upgrade_url:?string,manage_url:?string} */
    public static function extras(): array
    {
        return (array) get_option( self::EXTRAS_OPTION, Licensing\LicenseExtras::empty() );
    }

    /** Check if the site limit was hit on the last activation/refresh. */
    public static function siteLimitHit(): bool
    {
        return (bool) get_option( self::SITE_LIMIT_OPTION, false );
    }

    /** Capture extras and site limit from an HTTP response. */
    private static function storeExtrasAndSiteLimit( ?array $seen ): void
    {
        if ( null === $seen ) {
            return;
        }

        $code = $seen['code'] ?? 0;
        $body = json_decode( $seen['body'] ?? '', true );

        if ( ! is_array( $body ) ) {
            return;
        }

        $apiBase = self::apiBaseFor( defined( 'AIED_PRO_API_BASE' ) ? (string) AIED_PRO_API_BASE : null, wp_get_environment_type() );

        if ( 200 === $code && isset( $body['status'] ) && is_string( $body['status'] ) ) {
            // 200 response: store extras and clear site limit flag
            $extras = Licensing\LicenseExtras::fromBody( $body, $apiBase );
            update_option( self::EXTRAS_OPTION, $extras, false );
            delete_option( self::SITE_LIMIT_OPTION );
        } elseif ( 403 === $code && ( $body['error'] ?? null ) === 'site_limit_reached' ) {
            // 403 site_limit_reached: store extras from the error body and set the flag
            $extras = Licensing\LicenseExtras::fromBody( $body, $apiBase );
            update_option( self::EXTRAS_OPTION, $extras, false );
            update_option( self::SITE_LIMIT_OPTION, 1, false );
        }
    }
}
