<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Plugin API key — a single Bearer token that authenticates all plugin endpoints.
 * Generated on first use, stored in wp_options, never exposed in source code.
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

    public static function verify(string $candidate): bool
    {
        return hash_equals(self::get(), $candidate);
    }

    public static function getUserId(): int
    {
        return (int) get_option(self::OPTION_USER, 1);
    }

    /** Sets the current WP user from the API key owner. Returns false if key is invalid. */
    public static function authenticateRequest(): bool
    {
        // Apache internal rewrites can prefix the env var with REDIRECT_.
        // wp_unslash() + sanitize_text_field() required by PHPCS; hex bearer tokens are unaffected.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $raw    = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $header = sanitize_text_field( wp_unslash( $raw ) );
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return false;
        }
        if (!self::verify(trim($m[1]))) {
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
