<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Companion;

use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Site\SiteItems;
use AiEditorDivi5\Pro\ToolRunner;
use AiEditorDivi5\Pro\Tools\AuditSite;
use AiEditorDivi5\Pro\Tools\FindReplaceSite;
use AiEditorDivi5\Pro\Tools\ListBatches;
use AiEditorDivi5\Pro\Tools\UndoBatch;
use AiEditorDivi5\WP\Abilities;
use AiEditorDivi5\WP\DesignCheck;
use AiEditorDivi5\WP\GlobalStyles;
use AiEditorDivi5\WP\StyleReferences;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The Pro add-on's abilities for the WordPress Abilities API (WordPress 6.9 and later), in their own category, so any agent that
 * works with Divi's MCP can ask for site-wide design QA, audits, batch undo and launch control.
 *
 * Every ability needs an unlocked licence, the same capability the matching tool needs, and is registered nowhere if the free
 * plugin is too old to register abilities. The 22 existing Pro tools, their REST routes and the OpenAPI spec are untouched: these
 * abilities call the same tool classes (audit_site, find_replace_site, list_batches, undo_batch) or the new Companion classes.
 */
final class ProAbilities
{
    public const CATEGORY  = 'jhmg-ai-editor-pro';
    public const NAMESPACE = 'jhmg-ai-editor-pro';

    /** The first free-plugin version with everything the companion features call (REQUIRED_FREE and its methods). */
    public const MIN_FREE = '4.17.0';

    /**
     * Free-plugin classes only the companion features (these abilities, strict validation) need. They are NOT in
     * Compat::REQUIRED_CLASSES: that would switch every Pro tool off on a free plugin that predates them (they arrived in 4.17.0).
     * The companion features are simply not registered there.
     */
    public const REQUIRED_FREE = [
        'AiEditorDivi5\\WP\\Abilities',
        'AiEditorDivi5\\WP\\DesignCheck',
        'AiEditorDivi5\\WP\\HistoryCapture',
        'AiEditorDivi5\\WP\\HistorySources',
        'AiEditorDivi5\\WP\\HistoryStore',
    ];

    private const READ  = [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ];
    private const WRITE = [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ];

    public function __construct( private \Closure $unlocked ) {}

    /** Does the installed free plugin have everything the companion features call? */
    public static function freeSupportsCompanion(): bool
    {
        foreach ( self::REQUIRED_FREE as $class ) {
            if ( ! class_exists( $class ) ) {
                return false;
            }
        }

        return method_exists( Abilities::class, 'args' ) && method_exists( Abilities::class, 'permit' )
            && method_exists( \AiEditorDivi5\WP\HistoryStore::class, 'rowsSince' )
            && method_exists( \AiEditorDivi5\WP\HistoryCapture::class, 'currentSource' )
            && method_exists( \AiEditorDivi5\WP\HistoryCapture::class, 'isSuspended' );
    }

    public function register(): void
    {
        if ( ! self::freeSupportsCompanion() ) {
            return; // a free plugin older than 4.17.0: the other Pro tools keep working
        }
        add_action( 'wp_abilities_api_categories_init', [ $this, 'registerCategory' ] );
        add_action( 'wp_abilities_api_init', [ $this, 'registerAll' ] );
    }

    public function registerCategory(): void
    {
        if ( function_exists( 'wp_register_ability_category' ) ) {
            wp_register_ability_category( self::CATEGORY, [
                'label'       => 'JHMG AI Editor for Divi 5 Pro',
                'description' => 'The design and safety layer for Divi\'s upcoming MCP: whole-site design QA, site-wide audits, batch undo and launch control.',
            ] );
        }
    }

    public function registerAll(): void
    {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        foreach ( self::definitions() as $def ) {
            wp_register_ability(
                self::NAMESPACE . '/' . $def['name'],
                Abilities::args(
                    $def,
                    self::CATEGORY,
                    fn ( $input = null ) => ( $this->unlocked )() && Abilities::permit( $def, $input, $def['capability'] ),
                    fn ( $input = null ) => ( $this->unlocked )() ? $this->run( $def['name'], is_array( $input ) ? $input : [] ) : new \WP_Error( 'jhmg_pro_locked', 'A Pro licence is needed.', [ 'status' => 403 ] )
                )
            );
        }
    }

    /** @return list<array<string,mixed>> */
    public static function definitions(): array
    {
        $object = static fn ( array $props, array $required = [] ): array => [ 'type' => 'object', 'properties' => $props ] + ( [] === $required ? [ 'default' => [] ] : [ 'required' => $required ] );
        $scope  = [ 'type' => 'string', 'enum' => SiteItems::SCOPES, 'description' => 'pages, layouts or all (default all)' ];
        $cursor = [ 'type' => 'integer', 'description' => 'Where to continue: the next_cursor of the previous call (default 0 = from the start)' ];
        $find   = (array) self::tool( FindReplaceSite::class )->definition()['inputSchema'];

        return [
            [ 'name' => 'audit-site', 'label' => 'Audit the whole site', 'capability' => 'edit_pages', 'annotations' => self::READ, 'page_arg' => null,
              'description' => 'Call to audit Divi 5 pages (and the live header and footer with scope layouts) for layout violations, unknown ids, broken internal links, and design problems: text that cannot be read, Divi\'s stock placeholders, repeated sections with recipes to try. 25 items per call; pass next_cursor to continue. It works on pages built by any tool and changes nothing.',
              'input' => $object( [ 'scope' => $scope, 'cursor' => $cursor, 'include_design' => [ 'type' => 'boolean', 'description' => 'Also run the design check on each page (default true)' ] ] ) ],
            [ 'name' => 'design-review-site', 'label' => 'Review the site\'s design', 'capability' => 'edit_pages', 'annotations' => self::READ, 'page_arg' => null,
              'description' => 'Call after building or changing pages with any Divi tool to get one prioritised fix list for 25 pages at a time: unreadable text and broken links first, then Divi\'s stock look and unknown ids, then repetition, uneven spacing and colors that should use a global color. Any agent can then fix each item with any tool. Pass next_cursor to continue. Read-only.',
              'input' => $object( [ 'scope' => $scope, 'cursor' => $cursor ] ) ],
            [ 'name' => 'find-replace-site', 'label' => 'Find and replace text across the site', 'capability' => 'edit_pages', 'annotations' => self::WRITE, 'page_arg' => null,
              'description' => 'Call to replace an exact piece of text across many pages (and optionally the header and footer). Do a dry_run first: it returns a one-time token valid for 30 minutes that a real run needs. Every result is validated, a page changed since the preview is skipped, and the whole run is one undoable batch.',
              'input' => $find ],
            [ 'name' => 'list-batches', 'label' => 'List recent batches of changes', 'capability' => 'edit_pages', 'annotations' => self::READ, 'page_arg' => null,
              'description' => 'Call to see what could be undone: the Pro tools\' recorded batches, plus the changes any agent made in the last 3 days, grouped by who made them and when (or by start-batch and end-batch), whatever tool they used.',
              'input' => $object( [ 'limit' => [ 'type' => 'integer', 'description' => 'How many recorded batches (1-20, default 10)' ] ] ) ],
            [ 'name' => 'start-batch', 'label' => 'Start a batch of changes', 'capability' => 'edit_pages', 'annotations' => self::WRITE, 'page_arg' => null,
              'description' => 'Call before a series of edits with any tool, so they can be undone together with undo-batch. Call end-batch when done. Nothing else is needed: without it, changes by the same source close together in time are grouped automatically.',
              'input' => $object( [ 'label' => [ 'type' => 'string', 'description' => 'A short name for what you are about to do' ] ] ) ],
            [ 'name' => 'end-batch', 'label' => 'End the current batch', 'capability' => 'edit_pages', 'annotations' => self::WRITE, 'page_arg' => null,
              'description' => 'Call when a start-batch series of edits is finished.',
              'input' => $object( [] ) ],
            [ 'name' => 'undo-batch', 'label' => 'Undo a batch of changes', 'capability' => 'edit_pages', 'annotations' => self::WRITE, 'page_arg' => null,
              'description' => 'Call to put every page of a batch back as it was before, whichever tool changed it (use the id from list-batches). A page changed after the batch is skipped with the reason and a newer change is never overwritten. No page is deleted, and the undo can itself be undone. Use dry_run to preview.',
              'input' => $object( [ 'batch_id' => [ 'type' => [ 'integer', 'string' ], 'description' => 'The id from list-batches' ], 'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview only' ], 'force' => [ 'type' => 'boolean', 'description' => 'Recorded batches only: restore items even if they changed since' ] ], [ 'batch_id' ] ) ],
        ];
    }

    /** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
    public function run( string $name, array $args ): array|\WP_Error
    {
        try {
            return match ( $name ) {
                'audit-site'         => $this->auditSite( $args ),
                'design-review-site' => $this->designReview( $args ),
                'find-replace-site'  => $this->viaTool( self::tool( FindReplaceSite::class ), $args ),
                'list-batches'       => $this->listBatches( $args ),
                'start-batch'        => $this->startBatch( $args ),
                'end-batch'          => $this->endBatch(),
                'undo-batch'         => $this->undoBatch( $args ),
                default              => new \WP_Error( 'jhmg_unknown_ability', 'Unknown ability.', [ 'status' => 404 ] ),
            };
        } catch ( \InvalidArgumentException $e ) {
            return new \WP_Error( 'jhmg_invalid_params', $e->getMessage(), [ 'status' => 400 ] );
        }
    }

    /** The constructor arguments FindReplaceSite needs, as the Pro tool list passes them. @return list<mixed> */
    private static function findReplaceArgs(): array
    {
        foreach ( \AiEditorDivi5\Pro\Tools::all() as $tool ) {
            if ( $tool instanceof FindReplaceSite ) {
                return [];
            }
        }

        return [];
    }

    /** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
    private function viaTool( ProTool $tool, array $args ): array|\WP_Error
    {
        $r = ToolRunner::run( $tool, $args );

        return $r['ok'] ? $r['payload'] : new \WP_Error( 'jhmg_' . $r['code'], $r['message'], [ 'status' => $r['status'] ] );
    }

    /** @var ?list<ProTool> */
    private static ?array $tools = null;

    /** The Pro tool of this class, as the tool list builds it (it carries the batch log it records into). */
    private static function tool( string $class ): ProTool
    {
        foreach ( self::$tools ??= \AiEditorDivi5\Pro\Tools::all() as $tool ) {
            if ( $tool instanceof $class ) {
                return $tool;
            }
        }
        throw new \RuntimeException( "Pro tool {$class} is not registered." );
    }

    /** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
    private function auditSite( array $args ): array|\WP_Error
    {
        $audit = $this->viaTool( new AuditSite(), array_intersect_key( $args, [ 'scope' => 1, 'cursor' => 1 ] ) );
        if ( $audit instanceof \WP_Error || false === ( $args['include_design'] ?? true ) ) {
            return $audit;
        }
        $slice = $this->slice( (string) ( $args['scope'] ?? 'all' ), (int) ( $args['cursor'] ?? 0 ) );
        $pages = [];
        foreach ( $slice as $item ) {
            $check = DesignCheck::run( $item['content'], StyleReferences::knownIds(), self::presetLookup() );
            $codes = [];
            foreach ( [ 'contrast' => 'problems', 'default_fallbacks' => 'findings' ] as $key => $field ) {
                foreach ( (array) ( $check['checks'][ $key ][ $field ] ?? [] ) as $p ) {
                    $code           = (string) ( $p['code'] ?? 'LOW_CONTRAST' );
                    $codes[ $code ] = ( $codes[ $code ] ?? 0 ) + 1;
                }
            }
            $try = [];
            foreach ( (array) ( $check['checks']['variety']['details']['findings'] ?? [] ) as $v ) {
                $codes[ (string) $v['code'] ] = ( $codes[ (string) $v['code'] ] ?? 0 ) + 1;
                $try                          = array_merge( $try, (array) ( $v['try'] ?? [] ) );
            }
            if ( [] !== $codes ) {
                $pages[] = [ 'kind' => $item['kind'], 'id' => $item['id'], 'title' => $item['title'], 'design_problems' => $codes, 'suggested_recipes' => array_values( array_unique( $try ) ), 'not_checked' => $check['not_checked'] ];
            }
        }
        $audit['design'] = [ 'pages_with_problems' => $pages, 'pages_checked' => count( $slice ), 'note' => 'Design problems are advice, found by the same checks as check-design. Use design-review-site for a prioritised fix list.' ];

        return $audit;
    }

    /** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
    private function designReview( array $args ): array|\WP_Error
    {
        $scope  = (string) ( $args['scope'] ?? 'all' );
        $cursor = (int) ( $args['cursor'] ?? 0 );
        $audit  = $this->viaTool( new AuditSite(), [ 'scope' => $scope, 'cursor' => $cursor ] );
        if ( $audit instanceof \WP_Error ) {
            return $audit;
        }
        $slice   = $this->slice( $scope, $cursor );
        $globals = [];
        foreach ( (array) ( GlobalStyles::catalog()['colors'] ?? [] ) as $c ) {
            if ( isset( $c['id'], $c['color'] ) ) {
                $globals[ (string) $c['id'] ] = (string) $c['color'];
            }
        }
        $known  = StyleReferences::knownIds();
        $result = DesignReview::build( $slice, (array) $audit['findings'], static fn ( string $content ): array => DesignCheck::run( $content, $known, self::presetLookup() ), $globals );

        return $result + [ 'scope' => $scope, 'next_cursor' => $audit['next_cursor'], 'total_items' => $audit['total_items'], 'note' => 'This is advice for any agent to act on with any tool; nothing was changed. Spacing and color findings cover only the pages of this call.' ];
    }

    /**
     * The same 25 items AuditSite reads for this scope and cursor, with their stored content.
     *
     * @return list<array{kind:string, id:int, title:string, content:string}>
     */
    private function slice( string $scope, int $cursor ): array
    {
        $set   = SiteItems::collect( $scope, SiteItems::AUDIT_STATUSES, null );
        $items = $set['items'];
        usort( $items, static fn ( array $a, array $b ): int => $a['id'] <=> $b['id'] );
        $items = array_slice( array_values( array_filter( $items, static fn ( array $i ): bool => $i['id'] > $cursor ) ), 0, AuditSite::PAGE_SIZE );

        return array_map( static fn ( array $i ): array => [ 'kind' => (string) $i['kind'], 'id' => (int) $i['id'], 'title' => (string) $i['title'], 'content' => SiteItems::content( (int) $i['id'] ) ], $items );
    }

    private static function presetLookup(): \Closure
    {
        return static function ( string $module ): ?bool {
            $catalog = GlobalStyles::catalog( $module );
            if ( in_array( "Divi's preset data is not available on this site.", $catalog['notes'], true ) ) {
                return null;
            }

            return [] !== $catalog['presets'];
        };
    }

    /** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
    private function listBatches( array $args ): array|\WP_Error
    {
        $recorded = $this->viaTool( self::tool( ListBatches::class ), array_intersect_key( $args, [ 'limit' => 1 ] ) );

        return [
            'recorded_batches' => $recorded instanceof \WP_Error ? [ 'error' => $recorded->get_error_message() ] : $recorded,
            'agent_batches'    => BatchService::listBatches(),
            'note'             => 'agent_batches are grouped from page history (or by start-batch and end-batch). Undo one with undo-batch and its id.',
        ];
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function startBatch( array $args ): array
    {
        $session = AgentSessions::start( (int) get_current_user_id(), (string) ( $args['label'] ?? '' ) );

        return $session + [ 'batch_id' => 'session-' . $session['token'], 'message' => 'Batch started. Make your changes with any tool, then call end-batch.' ];
    }

    /** @return array<string,mixed> */
    private function endBatch(): array
    {
        $ended = AgentSessions::end( (int) get_current_user_id() );

        return null === $ended
            ? [ 'closed' => false, 'message' => 'There was no open batch.' ]
            : [ 'closed' => true, 'batch_id' => 'session-' . $ended['token'], 'label' => $ended['label'], 'started' => $ended['started'], 'ended' => $ended['ended'], 'message' => 'Batch ended. Undo it with undo-batch and this batch_id.' ];
    }

    /** @param array<string,mixed> $args @return array<string,mixed>|\WP_Error */
    private function undoBatch( array $args ): array|\WP_Error
    {
        $id = $args['batch_id'] ?? null;
        if ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) {
            $call = [ 'batch_id' => (int) $id ] + array_intersect_key( $args, [ 'force' => 1, 'dry_run' => 1 ] );

            return $this->viaTool( self::tool( UndoBatch::class ), $call );
        }
        if ( ! is_string( $id ) || 1 !== preg_match( '/^(?:inferred|session)-[A-Za-z0-9-]{1,40}$/', $id ) ) {
            return new \WP_Error( 'jhmg_invalid_params', 'batch_id must be an id from list-batches.', [ 'status' => 400 ] );
        }

        return BatchService::undo( $id, true === ( $args['dry_run'] ?? false ) );
    }
}
