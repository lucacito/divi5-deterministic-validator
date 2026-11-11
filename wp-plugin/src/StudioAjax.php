<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The admin-ajax handlers of the Lab screen (internal name Studio). Logged-in users only (never the logged-out variant of the hook).
 *
 * - Every action needs the nonce NONCE and the capability manage_options; preview, create and rebuild also need
 *   edit_pages (each of them inserts or updates a page: the preview its user's preview draft).
 * - The user is ALWAYS get_current_user_id(): no user id is ever read from the request, and the hand-off names the site
 *   with home_url(), never an address from the request.
 * - Input is read strictly: a value that is not a string where a string is required is HTTP 400, malformed JSON is 400,
 *   a brief over StudioStore::MAX_BYTES is 413 (checked before decoding), an invalid brief or one that does not compile
 *   is 422, a brief id that is missing or belongs to another user is the same 404, and a failure inside is 500.
 * - Answers carry data only (ids, plain words, the report); no HTML is built from anything a person typed. The one
 *   field that holds markup, preview.html, is already escaped inert text and is meant ONLY for an iframe srcdoc, never
 *   for innerHTML. Error messages never repeat brief text; the problem list's paths and messages have angle brackets
 *   removed as a second guard for a screen that might ever show them as HTML.
 * - A save or create of a stored brief may carry base_hash, the brief_hash the screen last saw (64 lowercase hex, else
 *   400). If the stored brief has changed since (an assistant saved), nothing is written and the answer is 409 CONFLICT
 *   with the CURRENT brief and hash, so the screen can merge the words and try once more. Without base_hash the old
 *   behaviour holds (a new brief has no hash yet).
 * - The stale preview sweep runs at most once an hour (a per-site transient), on the preview action only; a successful
 *   create removes the user's own preview draft.
 */
final class StudioAjax
{
    public const NONCE = 'ai_editor_divi5_studio';

    /** The per-site guard that lets the stale preview sweep run once an hour. */
    public const SWEEP_TRANSIENT = 'aied_studio_stale_sweep';

    private const DEPTH   = 32;
    private const DENSITY = [ 'airy', 'balanced', 'compact', 'dense' ];

    private const STATUS = [
        StudioService::NOT_FOUND      => 404,
        StudioService::INVALID_BRIEF  => 422,
        StudioService::LAYOUT_INVALID => 422,
        StudioService::RECIPE_REMOVED => 422,
        StudioService::STORE_FAILED   => 500,
        StudioService::SAVE_FAILED    => 500,
        StudioService::INTERNAL_ERROR => 500,
        StudioStore::TOO_LARGE        => 413,
        StudioStore::CONFLICT         => 409,
    ];

    public static function register(): void
    {
        foreach ( [ 'stack', 'preview', 'save', 'create', 'rebuild', 'handoff', 'brief' ] as $action ) {
            add_action( 'wp_ajax_aied_studio_' . $action, [ self::class, 'handle' . ucfirst( $action ) ] );
        }
    }

    /** goal (+ optional density) -> the suggested stack, each step with the recipes it can be swapped for. */
    public static function handleStack(): void
    {
        if ( ! self::gate( false ) ) {
            return;
        }
        $goal = self::text( 'goal', true );
        if ( null === $goal ) {
            return;
        }
        if ( ! in_array( $goal, StudioBrief::GOALS, true ) ) {
            self::fail( 400, 'BAD_REQUEST', __( 'That goal is not one Lab knows.', 'jhmg-ai-editor-for-divi-5' ) );

            return;
        }
        $density = self::text( 'density', false );
        if ( null === $density && self::present( 'density' ) ) {
            return; // not a string: already answered
        }
        $density ??= 'balanced';
        if ( ! in_array( $density, self::DENSITY, true ) ) {
            self::fail( 400, 'BAD_REQUEST', __( 'That density is not one Lab knows.', 'jhmg-ai-editor-for-divi-5' ) );

            return;
        }
        $steps = [];
        foreach ( StudioStack::forGoal( $goal, $density ) as $recipe ) {
            $tags    = SectionRecipes::tags( $recipe ) ?? [];
            $steps[] = [
                'recipe'   => $recipe,
                'role'     => (string) ( $tags['role'] ?? '' ),
                'layout'   => (string) ( $tags['layout'] ?? '' ),
                'items'    => StudioStack::itemCount( $recipe ),
                'variants' => StudioStack::variants( $recipe ),
            ];
        }
        wp_send_json_success( [ 'goal' => $goal, 'density' => $density, 'stack' => $steps ] );
    }

    /** brief -> the report and where to see the preview. Writes no brief and no page except the user's preview draft. */
    public static function handlePreview(): void
    {
        if ( ! self::gate( true ) ) {
            return;
        }
        $brief = self::brief();
        if ( null === $brief ) {
            return;
        }
        $user = get_current_user_id();
        $made = StudioService::preview( $user, $brief );
        if ( ! $made['ok'] ) {
            self::serviceFailure( $made );

            return;
        }
        self::sweepStaleOncePerHour();
        // preview.html (approximation mode) is escaped inert text for an iframe srcdoc only, never for innerHTML.
        $preview = StudioPreview::url( $user, (string) $made['markup'] );
        wp_send_json_success( [
            'message' => (string) $made['message'],
            'report'  => self::cleanReport( (array) $made['report'] ),
            'preview' => [
                'mode' => (string) $preview['mode'],
                'url'  => $preview['url'],
                'html' => $preview['html'],
            ],
        ] );
    }

    /** brief (+ optional brief id to update) -> the id it was stored under, for the signed-in user. */
    public static function handleSave(): void
    {
        if ( ! self::gate( false ) ) {
            return;
        }
        $brief = self::brief();
        if ( null === $brief ) {
            return;
        }
        $id = self::briefId( false );
        if ( false === $id ) {
            return;
        }
        $base = self::baseHash();
        if ( false === $base ) {
            return;
        }
        $checked = StudioBrief::check( $brief );
        if ( ! $checked['ok'] ) {
            self::fail( 422, StudioService::INVALID_BRIEF, __( 'The brief has problems. Fix them and try again.', 'jhmg-ai-editor-for-divi-5' ), [ 'problems' => self::cleanProblems( $checked['problems'] ) ] );

            return;
        }
        $user  = get_current_user_id();
        $saved = StudioStore::save( $user, (array) $checked['brief'], $id, $base );
        if ( ! $saved['ok'] ) {
            if ( StudioStore::CONFLICT === $saved['code'] && null !== $id ) {
                self::conflict( $user, $id );

                return;
            }
            self::fail( self::STATUS[ (string) $saved['code'] ] ?? 500, (string) $saved['code'], self::storeMessage( (string) $saved['code'] ) );

            return;
        }
        wp_send_json_success( [
            'id'      => (int) $saved['id'],
            'hash'    => self::hashOfStored( $user, (int) $saved['id'] ),
            'message' => __( 'Brief saved.', 'jhmg-ai-editor-for-divi-5' ),
        ] );
    }

    /** brief (+ optional stored brief id) -> a new DRAFT page. Also needs edit_pages. */
    public static function handleCreate(): void
    {
        if ( ! self::gate( true ) ) {
            return;
        }
        $brief = self::brief();
        if ( null === $brief ) {
            return;
        }
        $id = self::briefId( false );
        if ( false === $id ) {
            return;
        }
        $base = self::baseHash();
        if ( false === $base ) {
            return;
        }
        $user = get_current_user_id();
        if ( null !== $id && null !== $base && self::staleBase( $user, $id, $base, $brief ) ) {
            self::conflict( $user, $id );

            return;
        }
        $made = StudioService::create( $user, $brief, $id );
        if ( ! $made['ok'] ) {
            self::serviceFailure( $made );

            return;
        }
        StudioPreview::cleanup( $user );
        wp_send_json_success( [
            'message'   => (string) $made['message'],
            'page_id'   => $made['page_id'],
            'brief_id'  => $made['brief_id'],
            'hash'      => null === $made['brief_id'] ? null : self::hashOfStored( $user, (int) $made['brief_id'] ),
            'edit_url'  => $made['edit_url'],
            'duplicate' => (bool) $made['duplicate'],
            'report'    => self::cleanReport( (array) $made['report'] ),
        ] );
    }

    /** stored brief id -> the page built again (or a new draft, with the reason). Also needs edit_pages. */
    public static function handleRebuild(): void
    {
        if ( ! self::gate( true ) ) {
            return;
        }
        $id = self::briefId( true );
        if ( false === $id || null === $id ) {
            return;
        }
        $made = StudioService::rebuild( get_current_user_id(), $id );
        if ( ! $made['ok'] ) {
            self::serviceFailure( $made );

            return;
        }
        wp_send_json_success( [
            'message'  => (string) $made['message'],
            'mode'     => $made['mode'],
            'page_id'  => $made['page_id'],
            'reason'   => $made['reason'],
            'brief_id' => $made['brief_id'],
            'history'  => $made['history'],
            'edit_url' => $made['edit_url'],
            'report'   => self::cleanReport( (array) $made['report'] ),
        ] );
    }

    /** stored brief id -> the instruction to give an assistant. The site is this site's address, never from the request. */
    public static function handleHandoff(): void
    {
        if ( ! self::gate( false ) ) {
            return;
        }
        $id = self::briefId( true );
        if ( false === $id || null === $id ) {
            return;
        }
        $made = StudioService::handoff( get_current_user_id(), $id, home_url() );
        if ( ! $made['ok'] ) {
            self::serviceFailure( $made );

            return;
        }
        wp_send_json_success( [ 'message' => (string) $made['message'], 'instruction' => (string) $made['instruction'] ] );
    }

    /** stored brief id -> the stored brief, for the screen's refresh and poll. Another user's id is a plain 404. */
    public static function handleBrief(): void
    {
        if ( ! self::gate( false ) ) {
            return;
        }
        $id = self::briefId( true );
        if ( false === $id || null === $id ) {
            return;
        }
        $row = StudioStore::get( get_current_user_id(), $id );
        if ( null === $row ) {
            self::fail( 404, StudioService::NOT_FOUND, __( 'That saved brief was not found.', 'jhmg-ai-editor-for-divi-5' ) );

            return;
        }
        wp_send_json_success( [
            'id'         => $row['id'],
            'brief'      => $row['brief'], // data for the screen to show with textContent
            'hash'       => $row['brief_hash'],
            'page_id'    => $row['page_id'],
            'updated_at' => $row['updated_at'],
        ] );
    }

    // ---------------------------------------------------------------
    // Gate and input
    // ---------------------------------------------------------------

    /** manage_options, the nonce and, when asked, edit_pages. Sends the refusal and returns false when one is missing. */
    private static function gate( bool $needsEditPages ): bool
    {
        if ( ! current_user_can( 'manage_options' ) || ( $needsEditPages && ! current_user_can( 'edit_pages' ) ) ) {
            self::fail( 403, 'FORBIDDEN', __( 'You are not allowed to do this.', 'jhmg-ai-editor-for-divi-5' ) );

            return false;
        }
        if ( false === check_ajax_referer( self::NONCE, 'nonce', false ) ) {
            self::fail( 403, 'EXPIRED', __( 'This page has expired. Reload it and try again.', 'jhmg-ai-editor-for-divi-5' ) );

            return false;
        }

        return true;
    }

    private static function present( string $key ): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified in gate() before any input is read.
        return isset( $_POST[ $key ] );
    }

    /**
     * A request value that must be a string. Null (after answering 400) when it is not a string, or when it is missing
     * and $required; null without an answer when it is optional and missing.
     */
    private static function text( string $key, bool $required ): ?string
    {
        if ( ! self::present( $key ) ) {
            if ( $required ) {
                self::fail( 400, 'BAD_REQUEST', __( 'A required value is missing.', 'jhmg-ai-editor-for-divi-5' ) );
            }

            return null;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in gate(); the value is only compared with fixed lists, decoded as JSON or matched as digits.
        $value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
        if ( ! is_string( $value ) ) {
            self::fail( 400, 'BAD_REQUEST', __( 'A value was not text.', 'jhmg-ai-editor-for-divi-5' ) );

            return null;
        }

        return $value;
    }

    /**
     * The brief JSON of the request as an array. Null after answering: 400 (missing, not text, not JSON, not an object or
     * list, nested deeper than DEPTH) or 413 (over StudioStore::MAX_BYTES, before it is decoded).
     *
     * @return ?array<mixed>
     */
    private static function brief(): ?array
    {
        $raw = self::text( 'brief', true );
        if ( null === $raw ) {
            return null;
        }
        if ( strlen( $raw ) > StudioStore::MAX_BYTES ) {
            self::fail( 413, 'TOO_LARGE', __( 'The brief is too large.', 'jhmg-ai-editor-for-divi-5' ) );

            return null;
        }
        $decoded = json_decode( $raw, true, self::DEPTH );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            self::fail( 400, 'BAD_REQUEST', __( 'The brief is not valid JSON.', 'jhmg-ai-editor-for-divi-5' ) );

            return null;
        }

        return $decoded;
    }

    /**
     * A stored brief id: digits only, as text. int when given; null when optional and missing; false after answering 400.
     */
    private static function briefId( bool $required ): int|false|null
    {
        $raw = self::text( 'id', $required );
        if ( null === $raw ) {
            return self::present( 'id' ) || $required ? false : null;
        }
        if ( 1 !== preg_match( '/^[1-9][0-9]{0,9}$/', $raw ) ) {
            self::fail( 400, 'BAD_REQUEST', __( 'The brief id is not valid.', 'jhmg-ai-editor-for-divi-5' ) );

            return false;
        }

        return (int) $raw;
    }

    /**
     * The optional base_hash: the brief_hash the screen last saw, 64 lowercase hex characters. string when given; null when
     * missing; false after answering 400.
     */
    private static function baseHash(): string|false|null
    {
        $raw = self::text( 'base_hash', false );
        if ( null === $raw ) {
            return self::present( 'base_hash' ) ? false : null;
        }
        if ( 1 !== preg_match( '/^[0-9a-f]{64}\z/', $raw ) ) {
            self::fail( 400, 'BAD_REQUEST', __( 'The brief hash is not valid.', 'jhmg-ai-editor-for-divi-5' ) );

            return false;
        }

        return $raw;
    }

    /**
     * True when the stored brief has changed since $base AND is not already the brief about to be written (the same
     * rule StudioStore::save applies). The create path checks here, before the service saves.
     *
     * @param array<mixed> $brief
     */
    private static function staleBase( int $user, int $id, string $base, array $brief ): bool
    {
        $row = StudioStore::get( $user, $id );
        if ( null === $row || hash_equals( (string) $row['brief_hash'], $base ) ) {
            return false;
        }
        $checked = StudioBrief::check( $brief );
        $hash    = $checked['ok'] ? StudioStore::hashOf( (array) $checked['brief'] ) : null;

        return null === $hash || ! hash_equals( (string) $row['brief_hash'], $hash );
    }

    private static function hashOfStored( int $user, int $id ): ?string
    {
        $row = StudioStore::get( $user, $id );

        return null === $row ? null : (string) $row['brief_hash'];
    }

    // ---------------------------------------------------------------
    // Answers
    // ---------------------------------------------------------------

    /** 409: the stored brief changed since the screen last saw it. Carries the CURRENT brief and hash (data for the screen to merge). */
    private static function conflict( int $user, int $id ): void
    {
        $row = StudioStore::get( $user, $id );
        if ( null === $row ) {
            self::fail( 404, StudioService::NOT_FOUND, __( 'That saved brief was not found.', 'jhmg-ai-editor-for-divi-5' ) );

            return;
        }
        self::fail(
            409,
            StudioStore::CONFLICT,
            __( 'The brief was changed while you were editing.', 'jhmg-ai-editor-for-divi-5' ),
            [ 'brief' => $row['brief'], 'hash' => $row['brief_hash'] ]
        );
    }

    /** @param array<string,mixed> $extra */
    private static function fail( int $status, string $code, string $message, array $extra = [] ): void
    {
        wp_send_json_error( [ 'code' => $code, 'message' => $message ] + $extra, $status );
    }

    /** @param array<string,mixed> $made a StudioService result with ok false */
    private static function serviceFailure( array $made ): void
    {
        $code = (string) ( $made['code'] ?? StudioService::INTERNAL_ERROR );
        $data = [ 'code' => $code, 'message' => (string) ( $made['message'] ?? '' ) ];
        if ( isset( $made['report'] ) && is_array( $made['report'] ) ) {
            $data['report'] = self::cleanReport( $made['report'] );
        }
        wp_send_json_error( $data, self::STATUS[ $code ] ?? 422 );
    }

    private static function storeMessage( string $code ): string
    {
        return match ( $code ) {
            StudioStore::NOT_FOUND => __( 'That saved brief was not found.', 'jhmg-ai-editor-for-divi-5' ),
            StudioStore::TOO_LARGE => __( 'The brief is too large to save.', 'jhmg-ai-editor-for-divi-5' ),
            StudioStore::CONFLICT  => __( 'The brief was changed while you were editing.', 'jhmg-ai-editor-for-divi-5' ),
            default                => __( 'The brief could not be saved. Try again.', 'jhmg-ai-editor-for-divi-5' ),
        };
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private static function cleanReport( array $report ): array
    {
        if ( isset( $report['problems'] ) && is_array( $report['problems'] ) ) {
            $report['problems'] = self::cleanProblems( $report['problems'] );
        }

        return $report;
    }

    /**
     * Angle brackets removed from the paths and messages of a problem list (a path can be a key the sender chose).
     *
     * @param array<mixed> $problems
     * @return list<array<string,mixed>>
     */
    private static function cleanProblems( array $problems ): array
    {
        $out = [];
        foreach ( $problems as $problem ) {
            if ( ! is_array( $problem ) ) {
                continue;
            }
            foreach ( [ 'path', 'message' ] as $key ) {
                if ( isset( $problem[ $key ] ) && is_string( $problem[ $key ] ) ) {
                    $problem[ $key ] = str_replace( [ '<', '>' ], '', $problem[ $key ] );
                }
            }
            $out[] = $problem;
        }

        return $out;
    }

    /** Deletes preview drafts older than a day, for any user, but no more than once an hour on this site. */
    private static function sweepStaleOncePerHour(): void
    {
        if ( false !== get_transient( self::SWEEP_TRANSIENT ) ) {
            return;
        }
        set_transient( self::SWEEP_TRANSIENT, 1, HOUR_IN_SECONDS );
        StudioPreview::cleanupStale();
    }
}
