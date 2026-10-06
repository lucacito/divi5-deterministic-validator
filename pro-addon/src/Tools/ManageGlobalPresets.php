<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\GlobalPresetKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Settings\GlobalPresetPlanner;
use AiEditorDivi5\Pro\Settings\GlobalPresets;
use AiEditorDivi5\Pro\Settings\GlobalPresetsLock;
use AiEditorDivi5\Pro\Site\PreviewToken;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create or update the site's Divi module presets (never deletes). A preset restyles every module that cites it
 * (`modulePreset: ["<id>"]`), and a module's default preset restyles every module of that type without a preset of its own.
 * Every real run needs the token from a dry run of the same arguments. Creates go through Divi's additive import; updates save
 * Divi's COMPLETE preset data once. The result is read back and THAT is recorded as one undoable batch. Custom code in the
 * attributes is refused. A real run holds the site-wide GlobalPresetsLock from before its first read until the read-back.
 * MCP and REST only: the ChatGPT spec has no room.
 */
final class ManageGlobalPresets implements ProTool
{
    public const NAME = 'manage_global_presets';

    private const UNAVAILABLE = "Divi's preset functions are not available on this site (is the Divi 5 theme active?), so nothing was changed.";

    private const TOKEN_REFUSAL = 'A real run needs the preview_token from a dry run of the same arguments (including allow_default_presets), made by you in the last 30 minutes and not used before. Call again with dry_run=true.';

    private \Closure $clock;
    private \Closure $newId;
    private GlobalPresetsLock $lock;

    /**
     * @param (\Closure():int)|null $clock the time in seconds (the tests move it)
     * @param (\Closure():string)|null $newId makes the id of a created preset
     */
    public function __construct( private BatchLog $log, ?\Closure $clock = null, ?\Closure $newId = null, ?GlobalPresetsLock $lock = null )
    {
        $this->lock  = $lock ?? new GlobalPresetsLock();
        $this->clock = $clock ?? static fn (): int => time();
        $this->newId = $newId ?? static fn (): string => wp_generate_uuid4();
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
            'description' => 'Create or update the site\'s Divi module presets (never deletes). Pages use one with modulePreset ["<id>"], so a change restyles every module citing it. Call with dry_run=true first, then repeat with the preview_token. Undoable.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'presets'              => [
                        'type'        => 'array',
                        'minItems'    => 1,
                        'maxItems'    => GlobalPresetPlanner::MAX_ENTRIES,
                        'description' => 'The changes. No id = create a preset (module, name and attrs needed). With an id (from list_global_styles) = change that preset: name and/or attrs (attrs REPLACES its whole style tree).',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'id'     => [ 'type' => 'string', 'description' => 'Id of an existing preset; leave out to create one' ],
                                'module' => [ 'type' => 'string', 'description' => 'Needed to create, e.g. divi/heading; never changes afterwards' ],
                                'name'   => [ 'type' => 'string', 'description' => 'Name, 1 to 60 characters' ],
                                'attrs'  => [ 'type' => 'object', 'description' => 'The style attributes, in the same shape a layout block uses (for example title.decoration.font.font.desktop.value.size). No custom CSS or code.' ],
                            ],
                        ],
                    ],
                    'dry_run'              => [ 'type' => 'boolean', 'description' => 'Preview only; returns the preview_token a real run needs' ],
                    'preview_token'        => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                    'allow_default_presets' => [ 'type' => 'boolean', 'description' => 'Needed to change a module\'s DEFAULT preset: that restyles every module of that type without a preset of its own' ],
                ],
                'required'   => [ 'presets' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $rows  = GlobalPresetPlanner::normalize( Args::listOf( $arguments, 'presets', GlobalPresetPlanner::MAX_ENTRIES ) );
        $dry   = Args::bool( $arguments, 'dry_run' );
        $token = Args::string( $arguments, 'preview_token', false, 8000 );
        $allow = Args::bool( $arguments, 'allow_default_presets' );

        // Divi's own preset functions check no capability, so this is the bar (even for a preview).
        if ( ! GlobalPresets::permitted() ) {
            throw new \InvalidArgumentException( 'Changing presets needs the edit_theme_options capability, which your user does not have, so nothing was changed.' );
        }
        if ( $dry ) {
            return $this->execute( $rows, true, $token, $allow ); // a preview writes nothing, so it needs no lock
        }
        if ( ! $this->lock->acquire() ) {
            throw new \InvalidArgumentException( 'Another preset change is in progress on this site; nothing was changed. Try again in a moment.' );
        }
        try {
            return $this->execute( $rows, false, $token, $allow );
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function execute( array $rows, bool $dry, ?string $token, bool $allow ): array
    {
        $before = GlobalPresets::read();
        if ( null === $before || ! GlobalPresets::available() ) {
            throw new \InvalidArgumentException( self::UNAVAILABLE );
        }
        self::refuseUnsafe( $before );
        $now  = ( $this->clock )();
        $plan = GlobalPresetPlanner::plan( $before, $rows, $allow, $now, $this->newId );
        $argsHash = Canon::hash( [ 'presets' => $rows, 'allow_default_presets' => $allow ] );
        $userId   = (int) get_current_user_id();
        if ( [] === $plan['changes'] ) {
            // A real run always needs a genuine, unspent token, even when there turns out to be nothing to write (it is not spent here).
            if ( ! $dry && ( null === $token || null === PreviewToken::open( $token, $argsHash, $now, $userId ) ) ) {
                throw new \InvalidArgumentException( self::TOKEN_REFUSAL );
            }

            return [ 'changed' => false, 'dry_run' => $dry, 'unchanged' => $plan['unchanged'], 'message' => 'Every preset already is exactly like that; nothing changed.' ];
        }

        if ( $dry ) {
            $items   = [];
            $default = false;
            foreach ( $plan['changes'] as $c ) {
                $items[ $c['index'] ] = Canon::hash( $c['before'] );
                $default              = $default || $c['default_created'];
            }

            return [
                'changed'       => false,
                'dry_run'       => true,
                'changes'       => array_map( static fn ( array $c ): array => [ 'index' => $c['index'], 'id' => 'create' === $c['action'] ? null : $c['id'], 'module' => $c['module'], 'action' => $c['action'], 'before' => $c['before'], 'after' => $c['after'], 'is_default' => $c['is_default'], 'default_preset_created' => $c['default_created'] ], $plan['changes'] ),
                'preview_token' => PreviewToken::make( $argsHash, $items, $now, $userId ),
                'expires_in'    => PreviewToken::TTL,
                'message'       => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this preview_token to apply it. Every module that cites a changed preset is restyled.' . ( $default ? ' For a module with no presets yet Divi also adds a default preset, which undo_batch removes again.' : '' ),
            ];
        }

        $map = null === $token ? null : PreviewToken::open( $token, $argsHash, $now, $userId );
        if ( null === $map ) {
            throw new \InvalidArgumentException( self::TOKEN_REFUSAL );
        }

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
            PreviewToken::spend( (string) $token );

            return [ 'changed' => false, 'dry_run' => false, 'skipped' => $skipped, 'message' => 'Every preset changed since the preview, so nothing was written. Call again with dry_run=true.' ];
        }

        PreviewToken::spend( (string) $token ); // everything that can still refuse has run; the first write follows

        $touched = [];  // id => true, presets this call changed or created
        $recorded = [];
        $applied  = [];
        $differs  = [];
        $notStored = [];
        $threw    = false;

        // 1. Updates: ONE replace-all save of the complete current data.
        $updates = array_values( array_filter( $kept, static fn ( array $c ): bool => 'update' === $c['action'] ) );
        if ( [] !== $updates ) {
            $data = $before;
            foreach ( $updates as $c ) {
                $data['module'][ $c['module'] ]['items'][ $c['id'] ] = $c['item'];
            }
            $res   = GlobalPresets::save( $data );
            $threw = $threw || $res['threw'];
            if ( null === $res['after'] ) {
                throw new \RuntimeException( "Divi's presets could not be read back after the write, so nothing is recorded. Check list_global_styles for their current state." );
            }
            foreach ( $updates as $c ) {
                $touched[ $c['id'] ] = true;
                $stored = GlobalPresets::state( GlobalPresets::find( $res['after'], $c['id'] )['item'] ?? null );
                if ( $stored === $c['before'] ) {
                    $notStored[] = [ 'index' => $c['index'], 'id' => $c['id'] ];
                    continue;
                }
                if ( $stored !== $c['after'] ) {
                    $differs[] = [ 'id' => $c['id'], 'requested' => $c['after'], 'stored' => $stored ];
                }
                $prevStyle  = $before['module'][ $c['module'] ]['items'][ $c['id'] ]['styleAttrs'] ?? null;
                $recorded[] = GlobalPresetKind::itemForUpdate( $c['id'], $c['module'], (array) $c['before'], (array) $stored, is_array( $prevStyle ) ? $prevStyle : null );
                $applied[]  = [ 'id' => $c['id'], 'module' => $c['module'], 'action' => 'update', 'before' => $c['before'], 'after' => $stored, 'default_preset_created' => false ];
            }
        }

        // 2. Creates: Divi's additive import, one preset at a time.
        foreach ( $kept as $c ) {
            if ( 'create' !== $c['action'] ) {
                continue;
            }
            $current = GlobalPresets::read();
            if ( null === $current ) {
                throw new \RuntimeException( "Divi's presets could not be read, so the remaining creates were not attempted. Check list_global_styles for their current state." );
            }
            $idsBefore = GlobalPresets::itemIds( $current, $c['module'] );
            $res       = GlobalPresets::import( $c['module'], $c['item'], GlobalPresets::defaultId( $current, $c['module'] ) );
            $threw     = $threw || $res['threw'];
            if ( null === $res['after'] ) {
                throw new \RuntimeException( "Divi's presets could not be read back after the write, so nothing further is recorded. Check list_global_styles for their current state." );
            }
            $newIds   = array_values( array_diff( GlobalPresets::itemIds( $res['after'], $c['module'] ), $idsBefore ) );
            $created  = null;
            $auto     = null;
            foreach ( $newIds as $nid ) {
                $nItem = $res['after']['module'][ $c['module'] ]['items'][ $nid ] ?? null;
                $nSt   = GlobalPresets::state( is_array( $nItem ) ? $nItem : null );
                if ( null === $created && null !== $nSt && $nSt['name'] === $c['after']['name'] && Canon::hash( $nSt['attrs'] ) === Canon::hash( $c['after']['attrs'] ) ) {
                    $created = [ 'id' => (string) $nid, 'state' => $nSt ];
                } else {
                    $auto = [ 'id' => (string) $nid, 'hash' => Canon::hash( $nSt ) ];
                }
            }
            if ( null === $created ) {
                $notStored[] = [ 'index' => $c['index'], 'name' => $c['after']['name'] ];
                continue;
            }
            $touched[ $created['id'] ] = true;
            if ( null !== $auto ) {
                $touched[ $auto['id'] ] = true;
            }
            $recorded[] = GlobalPresetKind::itemForCreate( $created['id'], $c['module'], $created['state'], $c['module_existed'], $auto );
            $applied[]  = [ 'id' => $created['id'], 'module' => $c['module'], 'action' => 'create', 'before' => null, 'after' => $created['state'], 'default_preset_created' => null !== $auto ];
        }

        // 3. Read back: every preset this call did not touch must be exactly as it was.
        $final      = GlobalPresets::read();
        $unexpected = [];
        if ( null !== $final ) {
            foreach ( (array) ( $before['module'] ?? [] ) as $module => $entry ) {
                foreach ( (array) ( is_array( $entry ) ? ( $entry['items'] ?? [] ) : [] ) as $id => $oldItem ) {
                    if ( isset( $touched[ (string) $id ] ) || ! is_array( $oldItem ) ) {
                        continue;
                    }
                    $newItem = $final['module'][ $module ]['items'][ $id ] ?? null;
                    if ( null === $newItem ) {
                        $unexpected[] = [ 'id' => (string) $id, 'reason' => 'removed_by_divi' ];
                    } elseif ( GlobalPresets::state( $newItem ) !== GlobalPresets::state( $oldItem ) ) {
                        $unexpected[] = [ 'id' => (string) $id, 'reason' => 'changed' ];
                    }
                }
            }
        }

        if ( [] === $recorded ) {
            if ( $threw ) {
                throw new \RuntimeException( 'Divi did not save the presets, so nothing is recorded. Check list_global_styles for their current state.' );
            }

            return [ 'changed' => false, 'dry_run' => false, 'skipped' => $skipped, 'not_stored' => $notStored, 'unexpected_changes' => $unexpected, 'message' => 'Divi accepted the call, but none of the requested changes is stored; nothing is recorded.' ];
        }

        $batch  = $this->log->record( self::NAME, 'Global presets: ' . count( $recorded ) . ' change(s)', $recorded );
        $result = [
            'changed' => true,
            'dry_run' => false,
            'changes' => $applied,
            'skipped' => $skipped,
            'note'    => "A preset only takes effect on modules that cite it: set \"modulePreset\":[\"<id>\"] on a module in a layout (list_global_styles shows the ids). Divi's caches were cleared.",
        ];
        foreach ( [ 'differs_from_request' => $differs, 'not_stored' => $notStored, 'unexpected_changes' => $unexpected ] as $key => $list ) {
            if ( [] !== $list ) {
                $result[ $key ] = $list;
            }
        }
        if ( $threw ) {
            $result['warning'] = 'Divi reported an error while saving; what is stored now is what was recorded, and undo_batch can put the previous state back.';
        }

        return BatchLog::attach( $result, $batch );
    }

    /** @param array<string,mixed> $data */
    private static function refuseUnsafe( array $data ): void
    {
        $unsafe = GlobalPresets::unsafe( $data );
        if ( [] === $unsafe ) {
            return;
        }
        throw new \InvalidArgumentException( 'Divi stores its presets in a shape this tool cannot save back faithfully (' . implode( '; ', $unsafe ) . '), so something would be lost and the change could not bring it back. Nothing was changed.' );
    }
}
