<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Plugin API key — a single Bearer token that authenticates all plugin endpoints.
 * Generated on activation or when an administrator opens the Settings screen (get()),
 * stored in wp_options, never exposed in source code. verify() never creates one.
 */
final class ApiKey
{
    private const OPTION_KEY  = 'ai_editor_divi5_api_key';
    private const OPTION_USER = 'ai_editor_divi5_api_user_id';

    public static function get(): string
    {
        $key = (string) get_option(self::OPTION_KEY, '');
        if ($key === '') {
            $key = self::generate();
        }
        return $key;
    }

    public static function generate(): string
    {
        $key = bin2hex(random_bytes(32));
        update_option(self::OPTION_KEY,  $key,                   false);
        update_option(self::OPTION_USER, get_current_user_id(),  false);
        return $key;
    }

    /** Read-only: never creates a key (an unauthenticated request must not have side effects). */
    public static function verify(string $candidate): bool
    {
        $key = (string) get_option(self::OPTION_KEY, '');
        if ($key === '' || $candidate === '') {
            return false;
        }
        return hash_equals($key, $candidate);
    }

    public static function getUserId(): int
    {
        return (int) get_option(self::OPTION_USER, 0);
    }

    /** The Bearer credential the request carries, or '' when there is none. */
    public static function bearer(): string
    {
        // Apache internal rewrites can prefix the env var with REDIRECT_.
        // wp_unslash() + sanitize_text_field() required by PHPCS; hex bearer tokens are unaffected.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $raw    = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $header = sanitize_text_field( wp_unslash( $raw ) );

        return 1 === preg_match('/^Bearer\s+(.+)$/i', $header, $m) ? trim($m[1]) : '';
    }

    /** Whether the request carries something shaped like one of the OAuth sign-in's tokens (valid or not). */
    public static function presentsOAuthToken(): bool
    {
        return 1 === preg_match('/^aied_(?:at|rt|ac)_/', self::bearer());
    }

    /** Sets the current WP user from the API key owner (or the OAuth token's user). Returns false if the credential is invalid. */
    public static function authenticateRequest(): bool
    {
        $token = self::bearer();
        if ($token === '') {
            return false;
        }

        // An OAuth access token (Claude on the web and other apps that sign in): only while the owner has that
        // switched on. The user it belongs to still has to pass the same capability checks as the API key's user.
        if (str_starts_with($token, OAuthConfig::ACCESS_PREFIX)) {
            if (!OAuthSettings::enabled()) {
                return false;
            }
            $userId = OAuthRuntime::tokens()->authenticate($token);
            if (null === $userId) {
                return false;
            }
            wp_set_current_user($userId);
            return true;
        }

        if (!self::verify($token)) {
            return false;
        }
        wp_set_current_user(self::getUserId());
        return true;
    }

    public static function delete(): void
    {
        delete_option(self::OPTION_KEY);
        delete_option(self::OPTION_USER);
    }
}
