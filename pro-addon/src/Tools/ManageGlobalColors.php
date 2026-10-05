<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\GlobalColorKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Settings\GlobalColorPlanner;
use AiEditorDivi5\Pro\Settings\GlobalColors;
use AiEditorDivi5\Pro\Settings\GlobalColorsLock;
use AiEditorDivi5\Pro\Site\PreviewToken;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create, recolor, rename or deactivate the site's Divi global colors. It never deletes one (a page that cites a missing
 * color silently loses it). Every real run needs the token from a dry run of the same arguments; it writes Divi's
 * COMPLETE color map once, reads back what Divi really stored and records THAT as one undoable batch. The five theme
 * colors change only with allow_theme_colors, and only their color. MCP and REST only: the ChatGPT spec has no room.
 *
 * Two safeguards against losing colors, because Divi's save replaces the whole map: a real run holds the site-wide
 * GlobalColorsLock from before its first read until the write is read back (two Pro changes, or a Pro change and an undo,
 * cannot interleave; Divi builder saves are NOT covered), and a pre-flight refuses any map Divi would silently delete or
 * flatten entries from, already in the dry run.
 */
final class ManageGlobalColors implements ProTool
{
    public const NAME = 'manage_global_colors';

    private const UNAVAILABLE = "Divi's global color functions are not available on this site (is the Divi 5 theme active?), so nothing was changed.";

    private \Closure $clock;
    private \Closure $newId;
    private GlobalColorsLock $lock;

    /**
     * @param (\Closure():int)|null $clock the time in seconds (the tests move it)
     * @param (\Closure():string)|null $newId makes the id of a created color
     */
    public function __construct( private BatchLog $log, ?\Closure $clock = null, ?\Closure $newId = null, ?GlobalColorsLock $lock = null )
    {
        $this->lock  = $lock ?? new GlobalColorsLock();
        $this->clock = $clock ?? static fn (): int => time();
        $this->newId = $newId ?? static fn (): string => 'gcid-' . wp_generate_uuid4();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_theme_options';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Create, recolor, rename or deactivate the site\'s Divi global colors (never deletes). The five theme colors need allow_theme_colors=true. Affects every page using a color: call with dry_run=true first, then repeat with the preview_token. Undoable.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'colors'             => [
                        'type'        => 'array',
                        'minItems'    => 1,
                        'maxItems'    => GlobalColorPlanner::MAX_ENTRIES,
                        'description' => 'The changes. No id = create a color (label and color needed). With an id (from list_global_styles) = change that color: any of label, color, status.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'id'     => [ 'type' => 'string', 'description' => 'gcid-... of an existing color; leave out to create one' ],
                                'label'  => [ 'type' => 'string', 'description' => 'Name, 1 to 60 characters' ],
                                'color'  => [ 'type' => 'string', 'description' => 'Hex (#rgb, #rgba, #rrggbb, #rrggbbaa) or rgb()/rgba()/hsl()/hsla() with plain numbers' ],
                                'status' => [ 'type' => 'string', 'enum' => [ 'active', 'inactive' ], 'description' => 'inactive retires a color without deleting it' ],
                            ],
                        ],
                    ],
                    'dry_run'            => [ 'type' => 'boolean', 'description' => 'Preview only; returns the preview_token a real run needs' ],
                    'preview_token'      => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                    'allow_theme_colors' => [ 'type' => 'boolean', 'description' => 'Needed to change one of the five theme colors (primary, secondary, heading, body, link): that restyles the whole theme' ],
                ],
                'required'   => [ 'colors' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $entries = GlobalColorPlanner::normalize( Args::listOf( $arguments, 'colors', GlobalColorPlanner::MAX_ENTRIES ) );
        $dry     = Args::bool( $arguments, 'dry_run' );
        $token   = Args::string( $arguments, 'preview_token', false, 8000 );
        $allow   = Args::bool( $arguments, 'allow_theme_colors' );

        if ( $dry ) {
            return $this->execute( $entries, true, $token, $allow ); // a preview writes nothing, so it needs no lock
        }
        // Before the request's first read: Divi caches that read for the rest of the request, so a lock taken after it
        // would protect a snapshot another request may already have outdated.
        if ( ! $this->lock->acquire() ) {
            throw new \InvalidArgumentException( 'Another color change is in progress on this site; nothing was changed. Try again in a moment.' );
        }
        try {
            return $this->execute( $entries, false, $token, $allow );
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return array<string,mixed>
     */
    private function execute( array $entries, bool $dry, ?string $token, bool $allow ): array
    {
        $before = GlobalColors::read();
        if ( null === $before ) {
            throw new \InvalidArgumentException( self::UNAVAILABLE );
        }
        $now  = ( $this->clock )();
        $plan = GlobalColorPlanner::plan( $before, $entries, $allow, GlobalColors::stamp( $now ), $this->newId );
        if ( [] === $plan['changes'] ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'unchanged' => $plan['unchanged'], 'message' => 'Every color already is exactly like that; nothing changed.' ];
        }

        $argsHash = Canon::hash( [ 'colors' => $entries, 'allow_theme_colors' => $allow ] );
        $userId   = (int) get_current_user_id();
        if ( $dry ) {
            $all = GlobalColors::customMap( $before );
            foreach ( $plan['changes'] as $c ) {
                $all[ $c['id'] ] = $c['write'];
            }
            self::refuseUnsafe( $all );
            $items = [];
            foreach ( $plan['changes'] as $c ) {
                $items[ $c['index'] ] = Canon::hash( $c['before'] );
            }

            return [
                'changed'              => false,
                'dry_run'              => true,
                'changes'              => array_map( static fn ( array $c ): array => [ 'index' => $c['index'], 'id' => 'create' === $c['action'] ? null : $c['id'], 'action' => $c['action'], 'before' => $c['before'], 'after' => $c['after'] ], $plan['changes'] ),
                'theme_colors_touched' => $plan['theme_touched'],
                'preview_token'        => PreviewToken::make( $argsHash, $items, $now, $userId ),
                'expires_in'           => PreviewToken::TTL,
                'message'              => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this preview_token to apply it.' . ( $plan['theme_touched'] ? ' This changes a theme color, which restyles the whole theme.' : '' ),
            ];
        }

        $map = null === $token ? null : PreviewToken::open( $token, $argsHash, $now, $userId );
        if ( null === $map ) {
            throw new \InvalidArgumentException( 'A real run needs the preview_token from a dry run of the same arguments (including allow_theme_colors), made by you in the last 30 minutes and not used before. Call again with dry_run=true.' );
        }
        PreviewToken::spend( (string) $token );

        $kept    = [];
        $skipped = [];
        foreach ( $plan['changes'] as $c ) {
            if ( isset( $map[ $c['index'] ] ) && hash_equals( $map[ $c['index'] ], Canon::hash( $c['before'] ) ) ) {
                $kept[] = $c;
            } else {
                $skipped[] = [ 'index' => $c['index'], 'id' => 'create' === $c['action'] ? null : $c['id'], 'reason' => isset( $map[ $c['index'] ] ) ? 'changed_since_preview' : 'not_in_preview' ];
            }
        }
        if ( [] === $kept ) {
            return [ 'changed' => false, 'dry_run' => false, 'skipped' => $skipped, 'message' => 'Every color changed since the preview, so nothing was written. Call again with dry_run=true.' ];
        }

        // Divi caches its read for the rest of the request, so within one request this can only notice a change made by code
        // that resets that cache; protection against another REQUEST is the lock taken in run(), which Divi builder saves ignore.
        $fresh = GlobalColors::read();
        if ( null === $fresh || self::fingerprint( $fresh ) !== self::fingerprint( $before ) ) {
            throw new \InvalidArgumentException( "The site's global colors changed while this call was running; nothing was written. Call again with dry_run=true." );
        }

        $send = GlobalColors::customMap( $before );
        foreach ( $kept as $c ) {
            $send[ $c['id'] ] = $c['write'];
        }
        self::refuseUnsafe( $send );
        $res   = GlobalColors::write( $send );
        $after = $res['after'];
        if ( null === $after ) {
            throw new \RuntimeException( "Divi's global colors could not be read back after the write, so nothing is recorded. Check list_global_styles for their current state." );
        }

        $recorded   = [];
        $applied    = [];
        $notStored  = [];
        $notChanged = [];
        $differs    = [];
        foreach ( $kept as $c ) {
            $stored = GlobalColors::state( $after[ $c['id'] ] ?? null );
            if ( 'create' === $c['action'] && null === $stored ) {
                $notStored[] = [ 'index' => $c['index'], 'label' => $c['after']['label'] ];
                continue;
            }
            if ( 'create' !== $c['action'] && $stored === $c['before'] ) {
                $notChanged[] = $c['id'];
                continue;
            }
            if ( $stored !== $c['after'] ) {
                $differs[] = [ 'id' => $c['id'], 'requested' => $c['after'], 'stored' => $stored ];
            }
            $recorded[] = GlobalColorKind::itemFor( $c['id'], $c['before'], $stored );
            $applied[]  = [ 'id' => $c['id'], 'action' => $c['action'], 'before' => $c['before'], 'after' => $stored ];
        }
        $changedIds = array_column( $kept, 'id' );
        $unexpected = [];
        foreach ( $before as $id => $entry ) {
            if ( in_array( $id, $changedIds, true ) ) {
                continue;
            }
            $now_ = $after[ $id ] ?? null;
            if ( null === $now_ ) {
                $unexpected[] = [ 'id' => $id, 'reason' => 'removed_by_divi' ];
            } elseif ( GlobalColors::state( $now_ ) !== GlobalColors::state( $entry ) ) {
                $unexpected[] = [ 'id' => $id, 'reason' => 'changed' ];
            }
        }

        if ( [] === $recorded ) {
            if ( $res['threw'] ) {
                throw new \RuntimeException( 'Divi did not save the colors, so nothing is recorded. Check list_global_styles for their current state.' );
            }

            return [ 'changed' => false, 'dry_run' => false, 'skipped' => $skipped, 'not_stored' => $notStored, 'not_changed' => $notChanged, 'unexpected_changes' => $unexpected, 'message' => 'Divi accepted the write, but none of the requested changes is stored; nothing is recorded.' ];
        }

        $batch  = $this->log->record( self::NAME, 'Global colors: ' . count( $recorded ) . ' change(s)', $recorded );
        $result = [
            'changed'              => true,
            'dry_run'              => false,
            'changes'              => $applied,
            'skipped'              => $skipped,
            'theme_colors_touched' => [] !== array_filter( $kept, static fn ( array $c ): bool => $c['theme'] ),
            'note'                 => "A color only shows on pages that cite its gcid id in a layout (list_global_styles shows the ids, the style guide the reference shape). Divi's caches were cleared.",
        ];
        foreach ( [ 'differs_from_request' => $differs, 'not_stored' => $notStored, 'not_changed' => $notChanged, 'unexpected_changes' => $unexpected ] as $key => $list ) {
            if ( [] !== $list ) {
                $result[ $key ] = $list;
            }
        }
        if ( $res['threw'] ) {
            $result['warning'] = 'Divi reported an error while saving; what is stored now is what was recorded, and undo_batch can put the previous state back.';
        }

        return BatchLog::attach( $result, $batch );
    }

    /** @param array<string,array<string,mixed>> $map the exact map that would be sent to Divi */
    private static function refuseUnsafe( array $map ): void
    {
        $unsafe = GlobalColors::unsafeEntries( $map );
        if ( [] === $unsafe ) {
            return;
        }
        $list = implode( ', ', array_map( static fn ( array $u ): string => $u['id'] . ' (' . $u['reason'] . ')', $unsafe ) );
        throw new \InvalidArgumentException( "Divi would delete or damage these existing colors when it saves the list, and the change could not bring them back: {$list}. Nothing was changed. Fix or remove them in Divi's builder (Global Colors) first." );
    }

    /** @param array<string,array<string,mixed>> $all */
    private static function fingerprint( array $all ): string
    {
        return Canon::hash( array_map( [ GlobalColors::class, 'state' ], $all ) );
    }
}
