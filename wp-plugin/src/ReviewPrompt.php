<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The rules for the one review reminder on the plugin's Dashboard (pure: no WordPress calls, so they are testable).
 *
 * It is asked only when the plugin has really done work (at least MIN_SAVED saved changes), once per user, never on
 * another screen, and it is easy to say no: "Maybe later" hides it for SNOOZE_DAYS and "Don't ask again" hides it for
 * good. The state is one user meta value per user (removed on uninstall). Nothing is sent anywhere: the review link is
 * a plain link to this plugin's own page on WordPress.org that the user chooses to open.
 */
final class ReviewPrompt
{
    public const META         = 'aied_review_prompt';
    public const URL          = 'https://wordpress.org/support/plugin/jhmg-ai-editor-for-divi-5/reviews/#new-post';
    public const MIN_SAVED    = 5;
    public const SNOOZE_DAYS  = 30;
    public const STATUS_LATER = 'later';
    public const STATUS_DONE  = 'done';

    /**
     * Whether to show the reminder.
     *
     * @param int   $saved Saved changes so far (UsageTracker summary).
     * @param mixed $state The stored user meta value (anything; an unknown shape counts as "never asked").
     * @param int   $now   The current time (a Unix timestamp).
     */
    public static function shouldShow( int $saved, $state, int $now ): bool
    {
        if ( $saved < self::MIN_SAVED ) {
            return false;
        }
        $state = self::normalise( $state );
        if ( self::STATUS_DONE === $state['status'] ) {
            return false;
        }
        if ( self::STATUS_LATER === $state['status'] && $state['until'] > $now ) {
            return false;
        }

        return true;
    }

    /** The value to store for "Maybe later". @return array{status:string,until:int} */
    public static function later( int $now ): array
    {
        return [ 'status' => self::STATUS_LATER, 'until' => $now + self::SNOOZE_DAYS * 86400 ];
    }

    /** The value to store for "Don't ask again". @return array{status:string,until:int} */
    public static function done(): array
    {
        return [ 'status' => self::STATUS_DONE, 'until' => 0 ];
    }

    /**
     * @param mixed $raw
     * @return array{status:string,until:int}
     */
    public static function normalise( $raw ): array
    {
        if ( is_array( $raw ) && isset( $raw['status'] ) && in_array( $raw['status'], [ self::STATUS_LATER, self::STATUS_DONE ], true ) ) {
            return [ 'status' => (string) $raw['status'], 'until' => is_int( $raw['until'] ?? null ) ? max( 0, $raw['until'] ) : 0 ];
        }

        return [ 'status' => '', 'until' => 0 ];
    }
}
