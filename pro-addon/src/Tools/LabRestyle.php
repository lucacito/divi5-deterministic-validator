<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\LabBriefKind;
use AiEditorDivi5\Pro\Kinds\PostContentKind;
use AiEditorDivi5\Pro\Lab\LabCompile;
use AiEditorDivi5\Pro\Lab\LabLook;
use AiEditorDivi5\Pro\Lab\LabSupport;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Site\PreviewToken;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\EditTarget;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\LayoutDiff;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\PageHistory;
use AiEditorDivi5\WP\StudioBrief;
use AiEditorDivi5\WP\StudioStore;
use AiEditorDivi5\WP\StyleKits;
use AiEditorDivi5\WP\StyleReferences;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Rebuilds pages made with Divi 5 Lab in another design language and/or style kit, exactly as Lab would build them now
 * (spec 2026-11-10-pro-lab-design.md section A). Only a Lab page that is untouched since Lab built it qualifies: the
 * page's content must still hash to the `content_hash` Lab recorded. Anything else is skipped with a reason and is for
 * `switch_design`. The words, links and pictures of the brief are never touched; only its `language` / `kit`.
 *
 * The same pattern as `SwitchDesign`: strict arguments, a per-tool free-plugin guard (`LabSupport`), a per-page plan
 * (`EditTarget` first, nothing else is revealed about a page the user may not edit), a preview with a single-use
 * `PreviewToken` over `id => "stored16:new16:brief16"` (so a page, its new markup or its brief changing between the
 * preview and the run is skipped), and the apply: per page the page write through the free `HistoryService` (snapshotted),
 * then the brief by compare-and-set and its link to the content as stored, recorded as ONE undoable batch with the items
 * `[lab_brief, post_content]` per page (spec Findings 3 and 5).
 */
final class LabRestyle implements ProTool
{
    public const NAME      = 'lab_restyle';
    public const MAX_PAGES = 25;

    private \Closure $clock;
    private ?\Closure $classExists;
    private ?\Closure $methodExists;

    /**
     * @param (\Closure(int,string,string):array<string,mixed>)|null $write fn(postId, content, tool): HistoryService::write()'s result (used by the apply)
     * @param (\Closure():int)|null $clock fn(): unix time
     * @param (\Closure(string):bool)|null $classExists seam for the guard: whether a class is installed
     * @param (\Closure(string,string):bool)|null $methodExists seam for the guard: whether a class has a method
     */
    public function __construct(
        private BatchLog $log,
        private ?\Closure $write = null,
        ?\Closure $clock = null,
        ?\Closure $classExists = null,
        ?\Closure $methodExists = null
    ) {
        $this->clock        = $clock ?? static fn (): int => time();
        $this->classExists  = $classExists;
        $this->methodExists = $methodExists;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_pages';
    }

    public function definition(): array
    {
        $id = [ 'type' => 'integer', 'minimum' => 1 ];

        return [
            'name'        => self::NAME,
            'description' => 'Rebuild pages made with Divi 5 Lab in another design language and/or style kit, exactly as Lab would build them now. Only unedited Lab pages; edited ones are skipped (use switch_design). Always dry_run=true first, then repeat with the token. Undoable.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'page_id'           => $id + [ 'description' => 'One Lab page id (or use page_ids or all_mine)' ],
                    'page_ids'          => [ 'type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => self::MAX_PAGES, 'description' => 'Up to 25 Lab page ids, restyled as one batch (or use page_id or all_mine)' ],
                    'all_mine'          => [ 'type' => 'boolean', 'description' => 'true: every page you built with Lab (at most 25; use page_ids when you have more)' ],
                    'to_language'       => [ 'type' => 'string', 'description' => 'Target design language id (get_style_guide topic design-languages); omitted keeps each page\'s own' ],
                    'to_kit'            => [ 'type' => 'string', 'description' => 'Target style kit id, or "default" for the language\'s own kit; omitted keeps each page\'s own' ],
                    'include_published' => [ 'type' => 'boolean', 'description' => 'Also restyle Lab pages that are already published (default false: only drafts)' ],
                    'dry_run'           => [ 'type' => 'boolean', 'description' => 'Preview only; returns the report and the token a real run needs' ],
                    'token'             => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                ],
            ],
        ];
    }

    /** The refusal message when the installed free plugin lacks what the tool needs, or null. Public for tests. */
    public function unsupported(): ?string
    {
        return LabSupport::unsupported( self::NAME, $this->classExists, $this->methodExists );
    }

    public function run( array $arguments ): array
    {
        $args = $this->arguments( $arguments );
        $why  = $this->unsupported();
        if ( null !== $why ) {
            throw new \InvalidArgumentException( $why );
        }
        $this->validateIds( $args );
        $user = (int) get_current_user_id();
        $ids  = $this->pageIds( $args, $user );

        $argsHash = Canon::hash( [
            'tool'              => self::NAME,
            'ids'               => $args['all_mine'] ? null : $ids,
            'all_mine'          => $args['all_mine'],
            'to_language'       => $args['to_language'],
            'to_kit'            => self::normalisedToKit( $args ),
            'include_published' => $args['include_published'],
        ] );

        $plan = [];
        foreach ( $ids as $id ) {
            $plan[] = $this->plan( $id, $args, $user );
        }

        if ( $args['dry_run'] ) {
            return $this->preview( $plan, $argsHash, $user );
        }

        $map = null === $args['token'] ? null : PreviewToken::open( $args['token'], $argsHash, ( $this->clock )(), $user );
        if ( null === $map ) {
            throw new \InvalidArgumentException( 'A real run needs the token from a dry run of the same arguments, made by you in the last 30 minutes and not used before. Call again with dry_run=true.' );
        }
        PreviewToken::spend( (string) $args['token'] );

        return $this->apply( $plan, $map, $user );
    }

    /**
     * Strict argument parsing (types and shapes only; ids are checked against the installed lists after the guard).
     *
     * @param array<string,mixed> $a
     * @return array{ids:list<int>, all_mine:bool, to_language:?string, to_kit:?string, include_published:bool, dry_run:bool, token:?string}
     */
    private function arguments( array $a ): array
    {
        $hasOne  = array_key_exists( 'page_id', $a ) && null !== $a['page_id'];
        $hasMany = array_key_exists( 'page_ids', $a ) && null !== $a['page_ids'];
        $hasAll  = array_key_exists( 'all_mine', $a ) && null !== $a['all_mine'];
        if ( $hasAll && true !== $a['all_mine'] ) {
            throw new \InvalidArgumentException( '"all_mine" must be true (or left out).' );
        }
        if ( 1 !== (int) $hasOne + (int) $hasMany + (int) $hasAll ) {
            throw new \InvalidArgumentException( 'Pass exactly one of "page_id" (one id), "page_ids" (a list of 1 to ' . self::MAX_PAGES . ' ids) or "all_mine": true.' );
        }
        $ids = [];
        if ( $hasOne ) {
            $ids = [ (int) Args::int( $a, 'page_id' ) ];
        } elseif ( $hasMany ) {
            foreach ( Args::listOf( $a, 'page_ids', self::MAX_PAGES ) as $id ) {
                if ( ! is_int( $id ) || $id < 1 ) {
                    throw new \InvalidArgumentException( '"page_ids" must be a list of positive integers.' );
                }
                $ids[] = $id;
            }
            $ids = array_values( array_unique( $ids ) );
            sort( $ids );
        }
        $out = [ 'ids' => $ids, 'all_mine' => $hasAll ];
        foreach ( [ 'to_language', 'to_kit' ] as $key ) {
            $out[ $key ] = Args::string( $a, $key, false, 64 );
            if ( null !== $out[ $key ] && '' === trim( $out[ $key ] ) ) {
                throw new \InvalidArgumentException( "\"{$key}\" must not be empty." );
            }
        }
        if ( null === $out['to_language'] && null === $out['to_kit'] ) {
            throw new \InvalidArgumentException( 'Pass "to_language", "to_kit" or both: the look to rebuild the pages in.' );
        }
        $out['include_published'] = Args::bool( $a, 'include_published' );
        $out['dry_run']           = Args::bool( $a, 'dry_run' );
        $out['token']             = Args::string( $a, 'token', false, 8000 );

        return $out;
    }

    /** @param array<string,mixed> $args */
    private function validateIds( array $args ): void
    {
        $languages = DesignLanguages::ids();
        $kits      = StyleKits::ids();
        if ( null !== $args['to_language'] && ! in_array( $args['to_language'], $languages, true ) ) {
            throw new \InvalidArgumentException( sprintf( 'Unknown design language "%s" in "to_language". Valid ids: %s.', $args['to_language'], implode( ', ', $languages ) ) );
        }
        if ( null !== $args['to_kit'] && 'default' !== $args['to_kit'] && ! in_array( $args['to_kit'], $kits, true ) ) {
            throw new \InvalidArgumentException( sprintf( 'Unknown style kit "%s" in "to_kit". Valid ids: default, %s.', $args['to_kit'], implode( ', ', $kits ) ) );
        }
    }

    /**
     * The page ids to plan, sorted: the arguments' own, or for `all_mine` the pages the user's briefs built (never silently
     * a subset: more than the limit is refused).
     *
     * @param array<string,mixed> $args
     * @return list<int>
     */
    private function pageIds( array $args, int $user ): array
    {
        if ( ! $args['all_mine'] ) {
            return $args['ids'];
        }
        $ids = [];
        foreach ( StudioStore::linked( $user, StudioStore::MAX_PER_USER ) as $row ) {
            $ids[] = $row['page_id'];
        }
        $ids = array_values( array_unique( $ids ) );
        sort( $ids );
        if ( count( $ids ) > self::MAX_PAGES ) {
            throw new \InvalidArgumentException( sprintf( 'You have %d pages built with Lab; "all_mine" handles at most %d at a time. Pass the ones you want in "page_ids" (up to %d).', count( $ids ), self::MAX_PAGES, self::MAX_PAGES ) );
        }

        return $ids;
    }

    /** The `to_kit` the arguments hash carries: "default" resolved to the language's own kit when the language is given. @param array<string,mixed> $args */
    private static function normalisedToKit( array $args ): ?string
    {
        if ( 'default' === $args['to_kit'] && null !== $args['to_language'] ) {
            $language = DesignLanguages::get( (string) $args['to_language'] );

            return is_array( $language ) ? (string) ( $language['kit'] ?? '' ) : '';
        }

        return $args['to_kit'];
    }

    /**
     * One page's plan: what the restyle would write and whether it may. The first matching reason wins.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function plan( int $id, array $args, int $user ): array
    {
        $target = EditTarget::resolve( $id );
        if ( null !== $target['error'] || null === $target['post'] ) {
            // Only the id and the reason: the user may not be allowed to know anything else about this post.
            return [ 'id' => $id, 'skip' => EditTarget::FORBIDDEN === $target['error'] ? 'forbidden' : 'not_found' ];
        }
        $post   = $target['post'];
        $stored = (string) $post->post_content;
        $row    = [ 'id' => $id, 'title' => (string) $post->post_title, 'kind' => (string) $target['kind'] ];

        $lab = 'page' === $target['kind'] ? StudioStore::forPage( $user, $id ) : null;
        if ( null === $lab ) {
            return $row + [ 'skip' => 'not_lab_page', 'detail' => 'This page was not built with Divi 5 Lab by you, so it cannot be rebuilt from a brief. switch_design can restyle it in place.' ];
        }
        $old = $lab['brief'];
        $row += [ 'brief_id' => $lab['id'], 'from' => LabLook::of( $old ) ];

        $status = (string) ( $post->post_status ?? '' );
        if ( 'trash' === $status ) {
            return $row + [ 'skip' => 'trashed', 'detail' => 'The page is in the trash.' ];
        }
        if ( 'draft' !== $status && ! $args['include_published'] ) {
            return $row + [ 'skip' => 'published', 'detail' => 'The page is not a draft. Pass include_published=true to restyle a published Lab page.' ];
        }
        if ( null === $lab['content_hash'] || ! hash_equals( $lab['content_hash'], hash( 'sha256', $stored ) ) ) {
            return $row + [ 'skip' => 'edited', 'detail' => 'The page was changed after Lab built it, so a rebuild would lose those changes. Use switch_design to restyle it in place.' ];
        }
        foreach ( (array) ( $old['sections'] ?? [] ) as $i => $section ) {
            $recipe = is_array( $section ) ? ( $section['recipe'] ?? null ) : null;
            if ( ! is_string( $recipe ) || null === StudioBrief::resolveRecipe( $recipe ) ) {
                $name = is_string( $recipe ) ? substr( $recipe, 0, 60 ) : '(none)';

                return $row + [ 'skip' => 'recipe_removed', 'detail' => sprintf( 'The layout "%s" used by section %d is no longer in the library. Change that section in the Lab screen first.', $name, (int) $i + 1 ) ];
            }
        }

        $new = LabLook::with( $old, $args['to_language'], $args['to_kit'] );
        $to  = LabLook::of( $new );
        $row['to'] = $to;
        if ( LabLook::same( $old, $new ) ) {
            return $row + [ 'skip' => 'same_design', 'detail' => 'The page already has this design; nothing to change.' ];
        }

        $compiled = LabCompile::compile( $new );
        if ( ! $compiled['ok'] ) {
            return $row + [ 'skip' => 'invalid_result', 'violations' => self::codes( $compiled ) ];
        }
        $markup = (string) $compiled['markup'];
        if ( strlen( $stored ) > PageHistory::MAX_BYTES ) {
            return $row + [ 'skip' => 'too_large_for_undo', 'detail' => 'The page is over ' . PageHistory::MAX_BYTES . ' bytes, so no undo point could be kept.' ];
        }
        if ( strlen( $markup ) > PageHistory::MAX_BYTES ) {
            return $row + [ 'skip' => 'too_large_for_undo', 'detail' => 'The rebuilt page would be over ' . PageHistory::MAX_BYTES . ' bytes, so no undo point could be kept.' ];
        }
        $gate = LayoutGate::check( $markup, $stored );
        if ( ! $gate->isValid() ) {
            $codes = [];
            foreach ( $gate->violations() as $v ) {
                $codes[ $v->toArray()['code'] ] = true;
            }

            return $row + [ 'skip' => 'invalid_result', 'violations' => array_slice( array_keys( $codes ), 0, 10 ) ];
        }

        return $row + [
            'stored'         => $stored,
            'new'            => $markup,
            'old_brief'      => $lab,
            'new_brief'      => $new,
            'unfilled_count' => count( $compiled['unfilled'] ),
            'hash'           => substr( hash( 'sha256', $stored ), 0, 16 ) . ':' . substr( hash( 'sha256', $markup ), 0, 16 ) . ':' . substr( $lab['brief_hash'], 0, 16 ),
        ];
    }

    /**
     * The problem and violation codes of a refused compile, at most 10, each once.
     *
     * @param array<string,mixed> $compiled
     * @return list<string>
     */
    private static function codes( array $compiled ): array
    {
        $codes = [];
        foreach ( [ 'problems', 'violations' ] as $key ) {
            foreach ( (array) ( $compiled[ $key ] ?? [] ) as $item ) {
                if ( is_array( $item ) && isset( $item['code'] ) && is_string( $item['code'] ) ) {
                    $codes[ $item['code'] ] = true;
                }
            }
        }

        return array_slice( array_keys( $codes ), 0, 10 );
    }

    /**
     * @param list<array<string,mixed>> $plan
     * @return array<string,mixed>
     */
    private function preview( array $plan, string $argsHash, int $user ): array
    {
        $pages = [];
        $map   = [];
        foreach ( $plan as $p ) {
            $row = self::row( $p );
            if ( isset( $p['new'] ) ) {
                $map[ $p['id'] ] = $p['hash'];
                $diff            = LayoutDiff::compare( $p['stored'], $p['new'] );
                $row            += [ 'valid' => true, 'violations' => [], 'diff' => $diff['diff']['summary'] ?? null ];
                $row             = StyleReferences::attach( $row, $p['new'], $p['stored'] );
            }
            $pages[] = $row;
        }
        $out = [
            'changed'      => false,
            'dry_run'      => true,
            'would_change' => count( $map ),
            'would_skip'   => count( $plan ) - count( $map ),
            'pages'        => $pages,
            'token'        => null,
        ];
        if ( [] === $map ) {
            return $out + [ 'message' => 'Preview only. No page would change, so there is no token.' ];
        }
        $out['token'] = PreviewToken::make( $argsHash, $map, ( $this->clock )(), $user );

        return $out + [ 'message' => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to apply it.' ];
    }

    /**
     * The real run (the token was opened and spent by `run()`). For each planned page, in this order:
     * 1. skip it unless the preview saw exactly this plan (`not_in_preview`, `changed_since_preview`: the page, the new
     *    markup or the brief moved since);
     * 2. write the page through the free `HistoryService` (snapshotted); a failure is `save_failed` and nothing else is
     *    touched;
     * 3. save the restyled brief by compare-and-set on the brief hash the plan read; then link it to the sha256 of the
     *    content RE-READ after the save (as stored, Finding 5). When either fails the page stays restyled, the brief is
     *    left as it is (Lab then reads the page as edited) and the page is reported `brief_not_updated` with only its
     *    `post_content` item;
     * 4. record the items `[lab_brief, post_content]` (Finding 3: undo walks them last to first, the brief never reads
     *    the page, so both directions restore).
     * Everything is ONE batch.
     *
     * @param list<array<string,mixed>> $plan
     * @param array<int,string> $map id => "stored16:new16:brief16" the preview saw
     * @return array<string,mixed>
     */
    private function apply( array $plan, array $map, int $user ): array
    {
        $write   = $this->write ?? static fn ( int $id, string $content, string $tool ): array => HistoryService::write( $id, $content, $tool );
        $items   = [];
        $done    = [];
        $skipped = [];
        $targets = [];
        foreach ( $plan as $p ) {
            if ( ! isset( $p['new'] ) ) {
                $skipped[] = self::row( $p );
                continue;
            }
            $base = [ 'id' => $p['id'], 'title' => $p['title'], 'kind' => $p['kind'] ];
            if ( ! isset( $map[ $p['id'] ] ) ) {
                $skipped[] = $base + [ 'reason' => 'not_in_preview' ];
                continue;
            }
            if ( ! hash_equals( (string) $map[ $p['id'] ], (string) $p['hash'] ) ) {
                $skipped[] = $base + [ 'reason' => 'changed_since_preview' ];
                continue;
            }

            $result = $write( $p['id'], $p['new'], self::NAME );
            if ( empty( $result['ok'] ) ) {
                $skipped[] = $base + [ 'reason' => 'save_failed' ];
                continue;
            }
            $snapshot = is_array( $result['snapshot'] ?? null ) ? $result['snapshot'] : [];
            $now      = get_post( $p['id'] );
            $stored   = $now ? (string) $now->post_content : $p['new'];

            $old      = $p['old_brief'];
            $briefId  = (int) $old['id'];
            $briefRow = $this->updateBrief( $user, $briefId, $p['id'], $old, $p['new_brief'], $stored );

            $page = [ 'id' => $p['id'], 'title' => $p['title'], 'from' => $p['from'], 'to' => $p['to'], 'brief_id' => $briefId ];
            if ( null !== $briefRow ) {
                $items[] = LabBriefKind::itemFor( $user, $old, $briefRow );
            } else {
                $page += [
                    'warning' => 'brief_not_updated',
                    'detail'  => 'The page was restyled, but its Lab brief changed in the meantime and was left as it is. Lab now treats the page as edited; undo this batch to go back.',
                ];
            }
            $items[] = PostContentKind::itemFor( $p['id'], $p['title'], $stored, $snapshot );
            $done[]  = $page;
            $targets[ $p['to']['language'] . ' + ' . $p['to']['kit'] ] = true;
        }
        $restyled = count( $done );
        if ( [] === $items ) {
            return [ 'changed' => false, 'dry_run' => false, 'restyled' => 0, 'pages' => [], 'skipped' => $skipped, 'message' => 'Nothing was saved.' ];
        }
        $to    = 1 === count( $targets ) ? (string) array_key_first( $targets ) : 'their new looks';
        $batch = $this->log->record( self::NAME, 'Restyled ' . $restyled . ' Lab page(s) to ' . $to, $items );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'restyled' => $restyled, 'pages' => $done, 'skipped' => $skipped ], $batch );
    }

    /**
     * Saves the restyled brief (compare-and-set on the hash the plan read) and links it to the content as stored. Returns
     * the brief row after both, or null when the brief could not be updated; then the row is left as the other writer
     * left it (a brief this call wrote but could not link is put back, again only by compare-and-set).
     *
     * @param array<string,mixed> $old the plan's `StudioStore::forPage()` row
     * @param array<string,mixed> $newBrief
     * @return array<string,mixed>|null
     */
    private function updateBrief( int $user, int $briefId, int $pageId, array $old, array $newBrief, string $stored ): ?array
    {
        $saved = StudioStore::save( $user, $newBrief, $briefId, (string) $old['brief_hash'] );
        if ( empty( $saved['ok'] ) ) {
            return null;
        }
        if ( ! StudioStore::link( $user, $briefId, $pageId, hash( 'sha256', $stored ) ) ) {
            $written = StudioStore::get( $user, $briefId );
            if ( null !== $written && is_array( $old['brief'] ?? null ) ) {
                StudioStore::save( $user, $old['brief'], $briefId, (string) $written['brief_hash'] );
            }

            return null;
        }

        return StudioStore::get( $user, $briefId );
    }

    /**
     * What the client sees of a planned page (never the content or the brief).
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function row( array $p ): array
    {
        $row = [ 'id' => $p['id'] ];
        foreach ( [ 'title', 'kind', 'brief_id', 'from', 'to', 'unfilled_count' ] as $k ) {
            if ( array_key_exists( $k, $p ) ) {
                $row[ $k ] = $p[ $k ];
            }
        }
        if ( isset( $p['skip'] ) ) {
            $row['reason'] = $p['skip'];
            foreach ( [ 'detail', 'violations' ] as $k ) {
                if ( isset( $p[ $k ] ) ) {
                    $row[ $k ] = $p[ $k ];
                }
            }
            if ( 'invalid_result' === $p['skip'] ) {
                $row['valid'] = false;
            }
        }

        return $row;
    }
}
