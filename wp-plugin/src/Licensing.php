<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Self-hosted premium licensing — offline, no license server.
 *
 * A license key is an Ed25519-signed token the vendor mints with a private key
 * that never ships. The plugin embeds only the matching PUBLIC key and verifies
 * signatures locally, so premium checks work with zero network calls and keys
 * cannot be forged.
 *
 * Key format:  base64url(payloadJson) "." base64url(signature)
 * Payload:     { "email": string, "plan": "premium",
 *                "domain"?: string,   // optional — bind license to one site host
 *                "exp"?: int }        // optional unix ts; 0/absent = perpetual
 *
 * Mint keys with scripts/make-license.php (vendor side).
 */
final class Licensing
{
    private const OPTION_KEY = 'ai_editor_divi5_license';

    /** Ed25519 public key (base64). Safe to ship — only verifies, never signs. */
    private const PUBLIC_KEY = 'LeK3CYS3EzY4h0BKL/3a65JfpqyF8eICgCjyoT+Je10=';

    public const UPGRADE_URL = 'https://jhmediagroup.com/plugin/ai-editor-divi5#pricing';

    // ---------------------------------------------------------------
    // Stored license key
    // ---------------------------------------------------------------

    public static function getKey(): string
    {
        return trim( (string) get_option( self::OPTION_KEY, '' ) );
    }

    public static function setKey(string $key): void
    {
        update_option( self::OPTION_KEY, trim( $key ), false );
    }

    public static function clear(): void
    {
        delete_option( self::OPTION_KEY );
    }

    // ---------------------------------------------------------------
    // Premium gate
    // ---------------------------------------------------------------

    /** True only when a valid, unexpired, domain-matching premium key is stored. */
    public static function isPremium(): bool
    {
        return self::status()['valid'];
    }

    /**
     * Full license status for display and gating.
     *
     * @return array{valid: bool, plan: ?string, email: ?string, expires: ?int, reason: string}
     */
    public static function status(): array
    {
        $empty = ['valid' => false, 'plan' => null, 'email' => null, 'expires' => null];

        $key = self::getKey();
        if ( $key === '' ) {
            return $empty + ['reason' => 'no_key'];
        }

        $payload = self::verify( $key );
        if ( $payload === null ) {
            return $empty + ['reason' => 'invalid_signature'];
        }

        $plan    = isset( $payload['plan'] )  ? (string) $payload['plan']  : '';
        $email   = isset( $payload['email'] ) ? (string) $payload['email'] : null;
        $exp     = isset( $payload['exp'] )   ? (int) $payload['exp']      : 0;
        $domain  = isset( $payload['domain'] ) ? (string) $payload['domain'] : '';
        $details = ['plan' => $plan ?: null, 'email' => $email, 'expires' => $exp ?: null];

        if ( $plan !== 'premium' ) {
            return ['valid' => false] + $details + ['reason' => 'wrong_plan'];
        }
        if ( $exp > 0 && time() > $exp ) {
            return ['valid' => false] + $details + ['reason' => 'expired'];
        }
        if ( $domain !== '' && ! self::hostMatches( $domain ) ) {
            return ['valid' => false] + $details + ['reason' => 'domain_mismatch'];
        }

        return ['valid' => true] + $details + ['reason' => 'ok'];
    }

    // ---------------------------------------------------------------
    // Crypto verification (pure — no WordPress, unit-testable)
    // ---------------------------------------------------------------

    /**
     * Verify a license key's signature and return its decoded payload, or null
     * if the key is malformed or the signature does not match the public key.
     *
     * @return array<string, mixed>|null
     */
    public static function verify(string $key): ?array
    {
        $parts = explode( '.', trim( $key ) );
        if ( count( $parts ) !== 2 ) {
            return null;
        }

        $payloadRaw = self::b64urlDecode( $parts[0] );
        $signature  = self::b64urlDecode( $parts[1] );
        if ( $payloadRaw === null || $signature === null ) {
            return null;
        }
        if ( strlen( $signature ) !== SODIUM_CRYPTO_SIGN_BYTES ) {
            return null;
        }

        $publicKey = base64_decode( self::PUBLIC_KEY, true );
        if ( $publicKey === false || strlen( $publicKey ) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ) {
            return null;
        }

        if ( ! sodium_crypto_sign_verify_detached( $signature, $payloadRaw, $publicKey ) ) {
            return null;
        }

        $payload = json_decode( $payloadRaw, true );
        return is_array( $payload ) ? $payload : null;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** Compares a license domain claim against this site's host, ignoring scheme and a leading "www.". */
    private static function hostMatches(string $domain): bool
    {
        return self::normalizeHost( $domain ) === self::normalizeHost( (string) home_url() );
    }

    private static function normalizeHost(string $value): string
    {
        $host = wp_parse_url( $value, PHP_URL_HOST );
        if ( ! is_string( $host ) || $host === '' ) {
            // Bare host like "example.com" with no scheme.
            $host = preg_replace( '#^.*?//#', '', $value );
            $host = explode( '/', (string) $host )[0];
        }
        $host = strtolower( $host );
        return preg_replace( '#^www\.#', '', $host );
    }

    private static function b64urlDecode(string $value): ?string
    {
        $b64     = strtr( $value, '-_', '+/' );
        $padded  = str_pad( $b64, intdiv( strlen( $b64 ) + 3, 4 ) * 4, '=' );
        $decoded = base64_decode( $padded, true );
        return $decoded === false ? null : $decoded;
    }
}
