<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The "What's new" panel at the top of the plugin's OWN Dashboard tab. Never an admin notice, never on another admin screen.
 *
 * The announcement lives in data/whats-new.php (version, title, bullets, link). A user dismisses it once; their dismissal is the
 * announcement's version, stored in user meta, so it comes back only when the file carries a newer version. Only a user who can
 * edit pages sees it. There is nothing to buy in it: it describes what the free plugin does.
 */
final class WhatsNew
{
    public const META   = 'aied_whats_new_dismissed';
    public const ACTION = 'ai_editor_divi5_dismiss_whats_new';
    public const FILE   = __DIR__ . '/../data/whats-new.php';

    public const MIN_BULLETS = 3;
    public const MAX_BULLETS = 5;

    /**
     * The announcement, or null when the file is missing or malformed (then nothing is shown).
     *
     * @return ?array{version:string, title:string, bullets:list<string>, link:array{label:string, url:string}}
     */
    public static function content( ?string $file = null ): ?array
    {
        $file = $file ?? self::FILE;
        if ( ! is_readable( $file ) ) {
            return null;
        }
        $data = include $file;

        return self::valid( $data ) ? $data : null;
    }

    /** True when the data has every field the panel needs, in the allowed sizes, with an https link. */
    public static function valid( mixed $data ): bool
    {
        if ( ! is_array( $data ) || ! is_string( $data['version'] ?? null ) || 1 !== preg_match( '/^\d+(?:\.\d+)*$/', $data['version'] ) ) {
            return false;
        }
        if ( ! is_string( $data['title'] ?? null ) || '' === trim( $data['title'] ) ) {
            return false;
        }
        $bullets = $data['bullets'] ?? null;
        if ( ! is_array( $bullets ) || count( $bullets ) < self::MIN_BULLETS || count( $bullets ) > self::MAX_BULLETS ) {
            return false;
        }
        foreach ( $bullets as $b ) {
            if ( ! is_string( $b ) || '' === trim( $b ) ) {
                return false;
            }
        }
        $link = $data['link'] ?? null;

        return is_array( $link ) && is_string( $link['label'] ?? null ) && '' !== trim( $link['label'] )
            && is_string( $link['url'] ?? null ) && str_starts_with( $link['url'], 'https://' );
    }

    /**
     * Whether the panel is shown: the user can edit pages and has not dismissed THIS version.
     *
     * @param mixed $dismissedVersion the stored user meta value
     */
    public static function shouldShow( ?array $content, mixed $dismissedVersion, bool $canEditPages ): bool
    {
        if ( null === $content || ! $canEditPages ) {
            return false;
        }

        return ! ( is_string( $dismissedVersion ) && '' !== $dismissedVersion && version_compare( $dismissedVersion, $content['version'], '>=' ) );
    }

    /** Remembers that this user has seen this version. */
    public static function dismiss( int $userId, string $version ): void
    {
        update_user_meta( $userId, self::META, $version );
    }
}
