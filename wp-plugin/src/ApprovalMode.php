<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Optional "Require a preview before every save" setting. When it is on, the free plugin's page-saving
 * entry points refuse a save unless the exact resulting content was previewed (a valid dry run) by the
 * same user for the same page, as it stood then, in the last 30 minutes. A preview is single use. State is one transient
 * per preview; if it is evicted the only effect is that the AI must preview again.
 */
final class ApprovalMode
{
    public const OPTION  = 'ai_editor_divi5_require_preview';
    public const TTL     = 1800;
    public const CODE    = 'PREVIEW_REQUIRED';
    public const MESSAGE = 'Approval mode is on: this change was not previewed. Call again with dry_run set to true first, show the owner the result, then repeat the same call without dry_run to save it. Nothing was saved.';

    public static function enabled(): bool
    {
        return '1' === get_option( self::OPTION, '0' );
    }

    public static function set( bool $on ): void
    {
        update_option( self::OPTION, $on ? '1' : '0', false );
    }

    /** $base is the page's stored content at preview time: a page that changed since voids the preview. */
    public static function key( int $userId, int $pageId, string $content, string $base ): string
    {
        return 'aied_prev_' . substr( hash( 'sha256', $userId . '|' . $pageId . '|' . hash( 'sha256', $content ) . '|' . hash( 'sha256', $base ) ), 0, 40 );
    }

    /** Remember a successful, valid preview (only while the mode is on). */
    public static function recordPreview( int $pageId, string $content, bool $valid, string $base ): void
    {
        if ( ! $valid || ! self::enabled() ) {
            return;
        }
        set_transient( self::key( get_current_user_id(), $pageId, $content, $base ), 1, self::TTL );
    }

    /** True when a save may go ahead: the mode is off, or a matching preview existed (it is used up now). */
    public static function consume( int $pageId, string $content, string $base ): bool
    {
        if ( ! self::enabled() ) {
            return true;
        }
        $key = self::key( get_current_user_id(), $pageId, $content, $base );
        if ( false === get_transient( $key ) ) {
            return false;
        }
        delete_transient( $key );

        return true;
    }

    /** @return array{saved:false, code:string, message:string} */
    public static function refusal(): array
    {
        return [ 'saved' => false, 'code' => self::CODE, 'message' => self::MESSAGE ];
    }
}
