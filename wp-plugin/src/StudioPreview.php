<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The live preview of Divi 5 Lab (internal name Studio).
 *
 * Mode "draft" (the decision of docs/studio-preview-evidence.md): ONE private draft page per user, titled exactly
 * "Divi 5 Lab preview (temporary) <user id>", is created once and then updated with each new compiled layout; its
 * preview address is shown in an iframe. The draft is NEVER published and never given a slug. Divi renders a draft's
 * preview with inline CSS, so nothing needs clearing after an update.
 *
 * Mode "approximation" is the fallback: a minimal, labelled wrapper that shows the compiled markup as inert text. url()
 * returns it when the draft cannot be made (the insert or the update fails, the markup is empty, the user is not the
 * signed-in user) or when asked for it, and it never throws.
 *
 * - The update runs inside HistoryCapture::suspend() so a preview leaves no undo-history rows, and with core's revisions
 *   switched off for that one save so a preview leaves no revisions either.
 * - Every deletion is scoped by the exact title AND the author AND post_type=page AND post_status=draft, checked again in
 *   PHP on every row a query returns (a query is only ever a way to narrow the list, never the proof). Never by search or
 *   title prefix alone. Deleting is permanent (wp_delete_post with force), which also removes the page's history.
 * - The caller (the Studio admin and REST handlers) is responsible for the edit_pages capability and for passing the
 *   signed-in user: this class does not check capabilities. It does not run LayoutGate either: the compiler already did.
 */
final class StudioPreview
{
    public const MODE_DRAFT         = 'draft';
    public const MODE_APPROXIMATION = 'approximation';

    /** The mode in use. Changing it is a code change, not a filter. */
    public const MODE = self::MODE_DRAFT;

    /** The preview draft's title is this prefix followed by the numeric user id and nothing else. */
    public const TITLE_PREFIX = 'Divi 5 Lab preview (temporary) ';

    /** A preview draft last modified before this many seconds ago is stale. */
    public const STALE_SECONDS = 86400;

    /** The approximation shows at most this many characters of the markup. */
    public const APPROXIMATION_LIMIT = 20000;

    private const BATCH    = 100;
    private const MAX_SCAN = 5000; // draft pages looked at in one sweep; the rest are left for the next one

    /**
     * Puts $markup on the user's preview draft and returns where to see it.
     *
     * @return array{mode:string,url:?string,html:?string}
     */
    public static function url( int $user, string $markup, string $mode = self::MODE ): array
    {
        try {
            if ( '' === trim( $markup ) ) {
                return self::approximation( '', 'Nothing to preview yet.' );
            }
            if ( self::MODE_DRAFT !== $mode || $user <= 0 || get_current_user_id() !== $user ) {
                // The draft's author is set by core to the signed-in user, so a draft for anyone else could never be found again.
                return self::approximation( $markup );
            }
            $id = self::saveDraft( $user, $markup );
            if ( null === $id ) {
                return self::approximation( $markup );
            }
            $url = get_preview_post_link( $id );
            if ( ! is_string( $url ) || '' === $url ) {
                return self::approximation( $markup );
            }

            return [ 'mode' => self::MODE_DRAFT, 'url' => $url, 'html' => null ];
        } catch ( \Throwable $e ) {
            return self::approximation( $markup );
        }
    }

    /** Permanently deletes the user's preview draft (exact title, that author, a draft page). Never throws. */
    public static function cleanup( int $user ): void
    {
        try {
            if ( $user <= 0 ) {
                return;
            }
            foreach ( self::findFor( $user ) as $post ) {
                wp_delete_post( (int) $post->ID, true );
            }
        } catch ( \Throwable $e ) {
            return;
        }
    }

    /**
     * Permanently deletes every preview draft last modified more than 24 hours ago, for any user, and returns how many
     * went. A page whose title names user N but whose author is not N is left alone. Never throws.
     */
    public static function cleanupStale(): int
    {
        try {
            $cutoff  = gmdate( 'Y-m-d H:i:s', time() - self::STALE_SECONDS );
            $removed = 0;
            foreach ( self::sweep( $cutoff ) as $post ) {
                if ( false !== wp_delete_post( (int) $post->ID, true ) ) {
                    ++$removed;
                }
            }

            return $removed;
        } catch ( \Throwable $e ) {
            return 0;
        }
    }

    /** Uninstall: permanently deletes every user's preview draft, by the same strict match. Never throws. */
    public static function cleanupAll(): void
    {
        try {
            foreach ( self::sweep( null ) as $post ) {
                wp_delete_post( (int) $post->ID, true );
            }
        } catch ( \Throwable $e ) {
            return;
        }
    }

    // ---- draft mode ----------------------------------------------------------------------

    private static function title( int $user ): string
    {
        return self::TITLE_PREFIX . $user;
    }

    /** The user id a preview title names, or null when the title is not exactly prefix + digits. */
    private static function userOfTitle( string $title ): ?int
    {
        if ( 1 !== preg_match( '/^' . preg_quote( self::TITLE_PREFIX, '/' ) . '([1-9][0-9]{0,18})$/D', $title, $m ) ) {
            return null;
        }
        $id = (int) $m[1];

        return $id > 0 && (string) $id === $m[1] ? $id : null;
    }

    /** True only for a draft page titled exactly for $user and written by $user. */
    private static function isPreviewOf( mixed $post, int $user ): bool
    {
        return is_object( $post )
            && (int) ( $post->ID ?? 0 ) > 0
            && 'page' === (string) ( $post->post_type ?? '' )
            && 'draft' === (string) ( $post->post_status ?? '' )
            && (int) ( $post->post_author ?? 0 ) === $user
            && self::title( $user ) === (string) ( $post->post_title ?? '' );
    }

    /** @return list<object> the user's preview drafts, each checked again, oldest first */
    private static function findFor( int $user ): array
    {
        $rows = get_posts( [
            'post_type'        => 'page',
            'post_status'      => 'draft',
            'author'           => $user,
            'title'            => self::title( $user ),
            'numberposts'      => 20,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ] );
        $found = [];
        foreach ( is_array( $rows ) ? $rows : [] as $post ) {
            if ( self::isPreviewOf( $post, $user ) ) {
                $found[] = $post;
            }
        }

        return $found;
    }

    /**
     * Every preview draft of any user (older than $cutoff when given), each checked again in PHP: the title names a user
     * and that user is the author. The rows are collected first and deleted by the caller, so paging is not disturbed.
     *
     * @return list<object>
     */
    private static function sweep( ?string $cutoff ): array
    {
        $found  = [];
        $offset = 0;
        while ( $offset < self::MAX_SCAN ) {
            $args = [
                'post_type'        => 'page',
                'post_status'      => 'draft',
                's'                => rtrim( self::TITLE_PREFIX ), // only narrows the list; every row is checked below
                'numberposts'      => self::BATCH,
                'offset'           => $offset,
                'orderby'          => 'ID',
                'order'            => 'ASC',
                'no_found_rows'    => true,
                'suppress_filters' => true,
            ];
            if ( null !== $cutoff ) {
                $args['date_query'] = [ [ 'column' => 'post_modified_gmt', 'before' => $cutoff, 'inclusive' => false ] ];
            }
            $rows = get_posts( $args );
            if ( ! is_array( $rows ) || [] === $rows ) {
                break;
            }
            foreach ( $rows as $post ) {
                if ( ! is_object( $post ) ) {
                    continue;
                }
                $named = self::userOfTitle( (string) ( $post->post_title ?? '' ) );
                if ( null === $named || ! self::isPreviewOf( $post, $named ) ) {
                    continue;
                }
                if ( null !== $cutoff ) {
                    $modified = self::modifiedGmt( $post );
                    if ( '' === $modified || ! ( $modified < $cutoff ) ) {
                        continue;
                    }
                }
                $found[] = $post;
            }
            if ( count( $rows ) < self::BATCH ) {
                break;
            }
            $offset += self::BATCH;
        }

        return $found;
    }

    /**
     * When the post was last modified, in GMT, or '' when that is not known. WordPress keeps post_modified_gmt at
     * 0000-00-00 00:00:00 for a draft that was inserted and never updated (a draft has no GMT date yet; found by the live
     * gate), so such a row is judged by its local post_modified, converted to GMT.
     */
    private static function modifiedGmt( object $post ): string
    {
        $gmt = (string) ( $post->post_modified_gmt ?? '' );
        if ( '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) {
            return $gmt;
        }
        $local = (string) ( $post->post_modified ?? '' );
        if ( '' === $local || '0000-00-00 00:00:00' === $local ) {
            return '';
        }

        return function_exists( 'get_gmt_from_date' ) ? (string) get_gmt_from_date( $local ) : $local;
    }

    /** The id of the user's preview draft after putting $markup on it (made first if needed), or null on any failure. */
    private static function saveDraft( int $user, string $markup ): ?int
    {
        $existing = self::findFor( $user );
        if ( [] === $existing ) {
            $id = DraftPage::insert( self::title( $user ), $markup, '' );

            return is_int( $id ) && $id > 0 ? $id : null;
        }

        $keep = array_shift( $existing );
        foreach ( $existing as $extra ) {
            wp_delete_post( (int) $extra->ID, true ); // a second tab raced the first: one draft per user
        }
        $id = (int) $keep->ID;
        if ( $markup === (string) ( $keep->post_content ?? '' ) ) {
            return $id;
        }

        // No revision for a preview (core saves none when it keeps 0), and no undo row: this draft is not the owner's work.
        $noRevisions = static fn (): int => 0;
        add_filter( 'wp_revisions_to_keep', $noRevisions, PHP_INT_MAX );
        try {
            $updated = HistoryCapture::suspend( static fn () => wp_update_post( wp_slash( [ 'ID' => $id, 'post_content' => $markup ] ), true ) );
        } finally {
            remove_filter( 'wp_revisions_to_keep', $noRevisions, PHP_INT_MAX );
        }

        return is_wp_error( $updated ) || ! $updated ? null : $id;
    }

    // ---- approximation -------------------------------------------------------------------

    /**
     * A labelled wrapper that shows the markup as text only. Nothing in $markup can become an element: it is escaped, cut
     * to APPROXIMATION_LIMIT characters, and placed inside one pre block.
     *
     * @return array{mode:string,url:?string,html:?string}
     */
    private static function approximation( string $markup, string $note = '' ): array
    {
        $shown = $markup;
        $cut   = false;
        if ( strlen( $shown ) > self::APPROXIMATION_LIMIT ) {
            $shown = substr( $shown, 0, self::APPROXIMATION_LIMIT );
            $cut   = true;
        }
        $html  = '<div class="aied-studio-approximation" style="font:13px/1.5 sans-serif;border:1px dashed #888;padding:12px">';
        $html .= '<p style="margin:0 0 8px"><strong>' . esc_html( 'Approximate preview' ) . '</strong> ';
        $html .= esc_html( '' !== $note ? $note : 'The real preview could not be made, so this is the layout the assistant would save, shown as plain text rather than as a page.' ) . '</p>';
        if ( '' !== trim( $markup ) ) {
            $html .= '<pre style="margin:0;white-space:pre-wrap;word-break:break-word">' . esc_html( $shown ) . '</pre>';
            if ( $cut ) {
                $html .= '<p style="margin:8px 0 0"><em>' . esc_html( 'The text above was cut short.' ) . '</em></p>';
            }
        }
        $html .= '</div>';

        return [ 'mode' => self::MODE_APPROXIMATION, 'url' => null, 'html' => $html ];
    }
}
