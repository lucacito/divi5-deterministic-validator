<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only code that saves a Divi 5 Lab page (internal name Studio): preview a brief, create a draft from it, rebuild
 * the page a stored brief made, and write the hand-off instruction for an assistant.
 *
 * - Every page it makes is a DRAFT (DraftPage::insert) and every layout passed LayoutGate (StudioCompiler::compile).
 * - A rebuild overwrites a page only when the page is still a draft AND its stored content is exactly what this service
 *   last saved there (sha256 of the stored post content, re-read after the save so core's filtering is included); the
 *   old content is snapshotted first through HistoryService, so the overwrite is undoable. Otherwise a new draft is
 *   made and the reason given. Nothing is overwritten silently.
 * - It checks that a stored brief belongs to the acting user (StudioStore: another user's brief is NOT_FOUND).
 *   Capability checks belong to the callers (the admin and REST handlers).
 * - Unfilled slots never block: they are listed in report.unfilled.
 *
 * Every method returns an array with ok, code and message and never throws. Messages never repeat brief text other than
 * a recipe id.
 */
final class StudioService
{
    /** The assistant tool the hand-off instruction names (added by plan 3). */
    public const TOOL = 'lab_build';

    /** The history row's tool column for a rebuild's snapshot (a tool name, not a HistorySources type). */
    public const HISTORY_TOOL = 'studio';

    /** A second identical create by the same user within this many seconds returns the first result. */
    public const DUPLICATE_SECONDS = 10;

    public const INVALID_BRIEF  = 'INVALID_BRIEF';
    public const LAYOUT_INVALID = 'LAYOUT_INVALID';
    public const NOT_FOUND      = 'NOT_FOUND';
    public const RECIPE_REMOVED = 'RECIPE_REMOVED';
    public const STORE_FAILED   = 'STORE_FAILED';
    public const SAVE_FAILED    = 'SAVE_FAILED';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    public const MODE_OVERWRITTEN = 'overwritten';
    public const MODE_NEW_DRAFT   = 'new_draft';

    public const REASON_EDITED    = 'edited';
    public const REASON_PUBLISHED = 'published';
    public const REASON_TRASHED   = 'trashed';
    public const REASON_MISSING   = 'missing';

    private const INSTRUCTION = 'Use the %1$s tool%2$s to open Divi 5 Lab brief #%3$d. It lists the empty slots of every section. '
        . 'Write words for those slots and send them back with the same tool; you can fill some now and the rest later. '
        . 'Do not invent facts, prices, names or numbers; leave a slot empty if unknown. '
        . 'The page stays a draft until the site owner publishes it.';

    /**
     * Compiles a brief and returns the markup and the report. Writes nothing.
     *
     * @return array{ok:bool,code:?string,message:string,markup:?string,report:array<string,mixed>}
     */
    public static function preview( int $user, array $brief ): array
    {
        try {
            $compiled = self::compile( $brief );
            if ( ! $compiled['ok'] ) {
                return self::refusal( $compiled ) + [ 'markup' => null, 'report' => self::report( $compiled ) ];
            }

            return [ 'ok' => true, 'code' => null, 'message' => self::previewMessage( $compiled ), 'markup' => (string) $compiled['markup'], 'report' => self::report( $compiled ) ];
        } catch ( \Throwable $e ) {
            return self::internal() + [ 'markup' => null, 'report' => self::report( [] ) ];
        }
    }

    /**
     * Compiles a brief, saves it (as $storedId when given, which must be the user's), inserts a draft page titled by the
     * brief and links the two with the hash of the content as stored.
     *
     * @return array{ok:bool,code:?string,message:string,page_id:?int,brief_id:?int,edit_url:?string,duplicate:bool,report:array<string,mixed>}
     */
    public static function create( int $user, array $brief, ?int $storedId = null ): array
    {
        $empty = [ 'page_id' => null, 'brief_id' => null, 'edit_url' => null, 'duplicate' => false ];
        try {
            $compiled = self::compile( $brief );
            if ( ! $compiled['ok'] ) {
                return self::refusal( $compiled ) + $empty + [ 'report' => self::report( $compiled ) ];
            }
            $report = self::report( $compiled );
            $clean  = (array) StudioBrief::check( $brief )['brief'];
            if ( null !== $storedId && null === StudioStore::get( $user, $storedId ) ) {
                return self::fail( self::NOT_FOUND, 'That saved brief was not found.' ) + $empty + [ 'report' => $report ];
            }

            $hash  = StudioStore::hashOf( $clean );
            $first = null === $hash ? null : StudioStore::recentlyLinked( $user, $hash, self::DUPLICATE_SECONDS );
            if ( null !== $first && ( null === $storedId || $storedId === $first['id'] ) && null !== $first['page_id'] && self::isLivePage( $first['page_id'] ) ) {
                return [
                    'ok'        => true,
                    'code'      => null,
                    'message'   => 'This brief was just turned into a draft page; that page is shown instead of making a second one.',
                    'page_id'   => $first['page_id'],
                    'brief_id'  => $first['id'],
                    'edit_url'  => self::editUrl( $first['page_id'] ),
                    'duplicate' => true,
                    'report'    => $report,
                ];
            }

            $saved = StudioStore::save( $user, $clean, $storedId );
            if ( ! $saved['ok'] ) {
                return self::storeFailure( (string) $saved['code'] ) + $empty + [ 'report' => $report ];
            }
            $briefId = (int) $saved['id'];
            $made    = self::insertDraft( $user, $briefId, (string) $clean['title'], (string) $compiled['markup'] );
            if ( ! $made['ok'] ) {
                return $made + [ 'page_id' => null, 'brief_id' => $briefId, 'edit_url' => null, 'duplicate' => false, 'report' => $report ];
            }

            return [
                'ok'        => true,
                'code'      => null,
                'message'   => 'A draft page was created.' . self::placeholderNote( $compiled ),
                'page_id'   => $made['page_id'],
                'brief_id'  => $briefId,
                'edit_url'  => self::editUrl( $made['page_id'] ),
                'duplicate' => false,
                'report'    => $report,
            ];
        } catch ( \Throwable $e ) {
            return self::internal() + $empty + [ 'report' => self::report( [] ) ];
        }
    }

    /**
     * Builds the page of a stored brief again (spec rules 1 and 6).
     *
     * @return array{ok:bool,code:?string,message:string,mode:?string,page_id:?int,reason:?string,brief_id:?int,section?:int,history:?array,edit_url:?string,report:array<string,mixed>}
     */
    public static function rebuild( int $user, int $briefId ): array
    {
        $empty = [ 'mode' => null, 'page_id' => null, 'reason' => null, 'brief_id' => null, 'history' => null, 'edit_url' => null ];
        try {
            $stored = StudioStore::get( $user, $briefId );
            if ( null === $stored ) {
                return self::fail( self::NOT_FOUND, 'That saved brief was not found.' ) + $empty + [ 'report' => self::report( [] ) ];
            }
            $empty['brief_id'] = $briefId;

            // Rule 6: a recipe that left the library stops the rebuild; the stored brief (every section's words) is not touched.
            foreach ( (array) ( $stored['brief']['sections'] ?? [] ) as $i => $section ) {
                $recipe = is_array( $section ) ? ( $section['recipe'] ?? null ) : null;
                if ( ! is_string( $recipe ) || null === StudioBrief::resolveRecipe( $recipe ) ) {
                    $name = is_string( $recipe ) ? self::cut( $recipe ) : '(none)';

                    return self::fail(
                        self::RECIPE_REMOVED,
                        sprintf( 'The layout "%s" used by section %d is no longer in the library. Pick another layout for that section; the words of the other sections are kept.', $name, (int) $i + 1 )
                    ) + $empty + [ 'section' => (int) $i, 'report' => self::report( [] ) ];
                }
            }

            $compiled = self::compile( $stored['brief'] );
            if ( ! $compiled['ok'] ) {
                return self::refusal( $compiled ) + $empty + [ 'report' => self::report( $compiled ) ];
            }
            $report = self::report( $compiled );
            $markup = (string) $compiled['markup'];
            $title  = (string) ( $stored['brief']['title'] ?? '' );
            $reason = self::whyNotOverwrite( $stored['page_id'], $stored['content_hash'] );

            if ( null === $reason && null !== $stored['page_id'] ) {
                $pageId  = $stored['page_id'];
                $written = HistoryService::write( $pageId, $markup, self::HISTORY_TOOL );
                if ( ! $written['ok'] ) {
                    return self::fail( self::SAVE_FAILED, 'The page could not be saved.' ) + $empty + [ 'history' => $written['snapshot'] ?? null, 'report' => $report ];
                }
                $hash = self::storedHash( $pageId );
                if ( null === $hash || ! StudioStore::link( $user, $briefId, $pageId, $hash ) ) {
                    return self::fail( self::STORE_FAILED, 'The page was rebuilt, but the brief could not be linked to it, so the next rebuild will make a new draft.' )
                        + $empty + [ 'mode' => self::MODE_OVERWRITTEN, 'page_id' => $pageId, 'history' => $written['snapshot'], 'report' => $report ];
                }

                return [
                    'ok'       => true,
                    'code'     => null,
                    'message'  => 'The draft was rebuilt.' . ( $written['snapshot']['stored'] || 'duplicate' === $written['snapshot']['reason'] ? ' Its earlier content can be restored from the page history.' : ' No undo point could be kept for its earlier content.' ) . self::placeholderNote( $compiled ),
                    'mode'     => self::MODE_OVERWRITTEN,
                    'page_id'  => $pageId,
                    'reason'   => null,
                    'brief_id' => $briefId,
                    'history'  => $written['snapshot'],
                    'edit_url' => self::editUrl( $pageId ),
                    'report'   => $report,
                ];
            }

            $made = self::insertDraft( $user, $briefId, $title, $markup );
            if ( ! $made['ok'] ) {
                return $made + $empty + [ 'reason' => $reason, 'report' => $report ];
            }

            return [
                'ok'       => true,
                'code'     => null,
                'message'  => self::newDraftMessage( (string) $reason ) . self::placeholderNote( $compiled ),
                'mode'     => self::MODE_NEW_DRAFT,
                'page_id'  => $made['page_id'],
                'reason'   => $reason,
                'brief_id' => $briefId,
                'history'  => null,
                'edit_url' => self::editUrl( $made['page_id'] ),
                'report'   => $report,
            ];
        } catch ( \Throwable $e ) {
            return self::internal() + $empty + [ 'report' => self::report( [] ) ];
        }
    }

    /**
     * The ready-made instruction a person pastes to their assistant. A fixed template: only the brief id (an integer)
     * and, when it is a plain web address, the site are put in.
     *
     * @return array{ok:bool,code:?string,message:string,instruction:string}
     */
    public static function handoff( int $user, int $briefId, string $site ): array
    {
        try {
            if ( null === StudioStore::get( $user, $briefId ) ) {
                return self::fail( self::NOT_FOUND, 'That saved brief was not found.' ) + [ 'instruction' => '' ];
            }
            $site = self::plainSite( $site );

            return [
                'ok'          => true,
                'code'        => null,
                'message'     => 'Copy this instruction to your assistant.',
                'instruction' => sprintf( self::INSTRUCTION, self::TOOL, null === $site ? '' : ' on ' . $site, $briefId ),
            ];
        } catch ( \Throwable $e ) {
            return self::internal() + [ 'instruction' => '' ];
        }
    }

    /**
     * Why a rebuild must not overwrite the linked page, or null when it may (a draft page whose stored content still has
     * the hash this service recorded).
     */
    private static function whyNotOverwrite( ?int $pageId, ?string $contentHash ): ?string
    {
        $post = null === $pageId ? null : get_post( $pageId );
        if ( ! $post || 'page' !== (string) ( $post->post_type ?? '' ) ) {
            return self::REASON_MISSING;
        }
        $status = (string) ( $post->post_status ?? '' );
        if ( 'trash' === $status ) {
            return self::REASON_TRASHED;
        }
        if ( 'draft' !== $status ) {
            return self::REASON_PUBLISHED;
        }
        if ( null === $contentHash || ! hash_equals( $contentHash, hash( 'sha256', (string) $post->post_content ) ) ) {
            return self::REASON_EDITED;
        }

        return null;
    }

    private static function newDraftMessage( string $reason ): string
    {
        $why = [
            self::REASON_EDITED    => 'the page was edited after it was built',
            self::REASON_PUBLISHED => 'the page is no longer a draft (it is published, scheduled, private or pending)',
            self::REASON_TRASHED   => 'the page is in the trash',
            self::REASON_MISSING   => 'the page it built no longer exists',
        ][ $reason ] ?? 'the page could not be overwritten';

        return 'A new draft page was created, because ' . $why . '. The earlier page was left as it is.';
    }

    /**
     * Inserts a draft and links the brief to it with the hash of the content as stored.
     *
     * @return array{ok:bool,code:?string,message:string,page_id?:int}
     */
    private static function insertDraft( int $user, int $briefId, string $title, string $markup ): array
    {
        $pageId = DraftPage::insert( $title, $markup, '' );
        if ( is_wp_error( $pageId ) || ! is_int( $pageId ) || $pageId <= 0 ) {
            return self::fail( self::SAVE_FAILED, 'The draft page could not be created.' );
        }
        $hash = self::storedHash( $pageId );
        if ( null === $hash || ! StudioStore::link( $user, $briefId, $pageId, $hash ) ) {
            return self::fail( self::STORE_FAILED, 'The draft page was created, but the brief could not be linked to it.' ) + [ 'page_id' => $pageId ];
        }

        return [ 'ok' => true, 'code' => null, 'message' => '', 'page_id' => $pageId ];
    }

    /** sha256 of the page content as stored now (core may have filtered what was submitted). */
    private static function storedHash( int $pageId ): ?string
    {
        $post = get_post( $pageId );

        return $post ? hash( 'sha256', (string) $post->post_content ) : null;
    }

    private static function isLivePage( int $pageId ): bool
    {
        $post = get_post( $pageId );

        return (bool) $post && 'trash' !== (string) ( $post->post_status ?? '' );
    }

    private static function editUrl( int $pageId ): ?string
    {
        if ( ! function_exists( 'get_edit_post_link' ) ) {
            return null;
        }
        $url = get_edit_post_link( $pageId, 'raw' );

        return is_string( $url ) && '' !== $url ? $url : null;
    }

    /** @return array<string,mixed> the compiler's result for this site */
    private static function compile( array $brief ): array
    {
        $exists = function_exists( 'url_to_postid' ) && function_exists( 'home_url' )
            ? static fn ( string $path ): bool => url_to_postid( home_url( $path ) ) > 0
            : null;

        return StudioCompiler::compile( $brief, $exists, function_exists( 'home_url' ) ? (string) home_url() : '' );
    }

    /**
     * @param array<string,mixed> $compiled
     * @return array{problems:array,violations:array,unfilled:array,refused:array,warnings:array,advice:array}
     */
    private static function report( array $compiled ): array
    {
        $out = [];
        foreach ( [ 'problems', 'violations', 'unfilled', 'refused', 'warnings', 'advice' ] as $key ) {
            $out[ $key ] = is_array( $compiled[ $key ] ?? null ) ? $compiled[ $key ] : [];
        }

        return $out;
    }

    /** @param array<string,mixed> $compiled @return array{ok:false,code:string,message:string} */
    private static function refusal( array $compiled ): array
    {
        if ( [] !== ( $compiled['problems'] ?? [] ) ) {
            return self::fail( self::INVALID_BRIEF, 'The brief has problems; nothing was saved. See report.problems.' );
        }

        return self::fail( self::LAYOUT_INVALID, 'The built layout did not pass the layout check; nothing was saved. See report.violations.' );
    }

    /** @param array<string,mixed> $compiled */
    private static function previewMessage( array $compiled ): string
    {
        return 'The page was built for preview; nothing was saved.' . self::placeholderNote( $compiled );
    }

    /** @param array<string,mixed> $compiled */
    private static function placeholderNote( array $compiled ): string
    {
        $n = count( (array) ( $compiled['unfilled'] ?? [] ) );

        return 0 === $n ? '' : sprintf( ' %d slot%s still show%s sample copy (see report.unfilled).', $n, 1 === $n ? '' : 's', 1 === $n ? 's' : '' );
    }

    /** @return array{ok:false,code:string,message:string} */
    private static function storeFailure( string $code ): array
    {
        if ( StudioStore::NOT_FOUND === $code ) {
            return self::fail( self::NOT_FOUND, 'That saved brief was not found.' );
        }
        if ( StudioStore::TOO_LARGE === $code ) {
            return self::fail( self::STORE_FAILED, 'The brief is too large to save.' );
        }

        return self::fail( self::STORE_FAILED, 'The brief could not be saved.' );
    }

    /** A plain http(s) address made of safe characters, or null (the value is then left out of the text). */
    private static function plainSite( string $site ): ?string
    {
        $site = trim( $site );
        if ( strlen( $site ) > 200 || 1 !== preg_match( '#^https?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~/-]*)?$#', $site ) ) {
            return null;
        }

        return rtrim( $site, '/' );
    }

    /** A recipe id cut short for a message; the id was a library name when the brief was saved. */
    private static function cut( string $s ): string
    {
        $s = (string) preg_replace( '/[^A-Za-z0-9_.-]/', '', $s );

        return strlen( $s ) > 60 ? substr( $s, 0, 60 ) : $s;
    }

    /** @return array{ok:false,code:string,message:string} */
    private static function fail( string $code, string $message ): array
    {
        return [ 'ok' => false, 'code' => $code, 'message' => $message ];
    }

    /** @return array{ok:false,code:string,message:string} */
    private static function internal(): array
    {
        return self::fail( self::INTERNAL_ERROR, 'Something went wrong while building the page. Check the page list before trying again.' );
    }
}
