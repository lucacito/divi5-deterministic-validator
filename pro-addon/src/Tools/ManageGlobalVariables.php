<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\GlobalVariableKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Settings\GlobalVariablePlanner;
use AiEditorDivi5\Pro\Settings\GlobalVariables;
use AiEditorDivi5\Pro\Settings\GlobalVariablesLock;
use AiEditorDivi5\Pro\Site\PreviewToken;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create, edit or archive the site's Divi global variables of type numbers and strings. It never deletes one. Every real run
 * needs the token from a dry run of the same arguments; it writes Divi's COMPLETE variable map once, reads back what Divi
 * really stored and records THAT as one undoable batch. MCP and REST only: the ChatGPT spec has no room.
 *
 * Safeguards, because Divi's save replaces the whole map: a real run holds the site-wide GlobalVariablesLock from before its
 * first read until the write is read back (Divi builder saves are NOT covered); a pre-flight refuses any map Divi would
 * silently delete or flatten entries from, already in the dry run; and Divi's own silent no-op for a user without the
 * Variables Manager permission is checked up front and again by the read-back.
 */
final class ManageGlobalVariables implements ProTool
{
    public const NAME = 'manage_global_variables';

    private const UNAVAILABLE = "Divi's global variable functions are not available on this site (is the Divi 5 theme active?), so nothing was changed.";

    private \Closure $clock;
    private \Closure $newId;
    private GlobalVariablesLock $lock;

    /**
     * @param (\Closure():int)|null $clock the time in seconds (the tests move it)
     * @param (\Closure():string)|null $newId makes the id of a created variable
     */
    public function __construct( private BatchLog $log, ?\Closure $clock = null, ?\Closure $newId = null, ?GlobalVariablesLock $lock = null )
    {
        $this->lock  = $lock ?? new GlobalVariablesLock();
        $this->clock = $clock ?? static fn (): int => time();
        $this->newId = $newId ?? static fn (): string => 'gvid-' . wp_generate_uuid4();
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
            'description' => 'Create, edit or archive the site\'s Divi global variables of type numbers (12px, clamp()) and strings (never deletes). Affects every page using a variable: call with dry_run=true first, then repeat with the preview_token. Undoable.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'variables'     => [
                        'type'        => 'array',
                        'minItems'    => 1,
                        'maxItems'    => GlobalVariablePlanner::MAX_ENTRIES,
                        'description' => 'The changes. No id = create a variable (type, label and value needed). With an id (from list_global_styles) = change that numbers or strings variable: any of label, value, status.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'id'     => [ 'type' => 'string', 'description' => 'gvid-... of an existing variable; leave out to create one' ],
                                'type'   => [ 'type' => 'string', 'enum' => [ 'numbers', 'strings' ], 'description' => 'Needed to create; never changes afterwards' ],
                                'label'  => [ 'type' => 'string', 'description' => 'Name, 1 to 60 characters' ],
                                'value'  => [ 'type' => 'string', 'description' => 'numbers: a number with an optional unit or calc()/clamp()/min()/max() of those. strings: plain text up to 200 characters (balanced brackets, no braces, semicolons, quotes, comment markers or code)' ],
                                'status' => [ 'type' => 'string', 'enum' => [ 'active', 'archived' ], 'description' => 'archived retires a variable without deleting it' ],
                            ],
                        ],
                    ],
                    'dry_run'       => [ 'type' => 'boolean', 'description' => 'Preview only; returns the preview_token a real run needs' ],
                    'preview_token' => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                ],
                'required'   => [ 'variables' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $entries = GlobalVariablePlanner::normalize( Args::listOf( $arguments, 'variables', GlobalVariablePlanner::MAX_ENTRIES ) );
        $dry     = Args::bool( $arguments, 'dry_run' );
        $token   = Args::string( $arguments, 'preview_token', false, 8000 );

        // set_global_variables() silently writes nothing without these, so say so before promising anything (even a preview).
        if ( ! GlobalVariables::permitted() ) {
            throw new \InvalidArgumentException( "Divi does not let your user manage global variables (it needs edit_theme_options and the Variables Manager permission in Divi's Role Editor), so nothing was changed." );
        }
        if ( $dry ) {
            return $this->execute( $entries, true, $token ); // a preview writes nothing, so it needs no lock
        }
        // Before the request's first read: Divi caches that read for the rest of the request, so a lock taken after it
        // would protect a snapshot another request may already have outdated.
        if ( ! $this->lock->acquire() ) {
            throw new \InvalidArgumentException( 'Another variable change is in progress on this site; nothing was changed. Try again in a moment.' );
        }
        try {
            return $this->execute( $entries, false, $token );
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return array<string,mixed>
     */
    private function execute( array $entries, bool $dry, ?string $token ): array
    {
        $before = GlobalVariables::read();
        if ( null === $before ) {
            throw new \InvalidArgumentException( self::UNAVAILABLE );
        }
        $plan = GlobalVariablePlanner::plan( $before, $entries, $this->newId );
        if ( [] === $plan['changes'] ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'unchanged' => $plan['unchanged'], 'message' => 'Every variable already is exactly like that; nothing changed.' ];
        }

        $now      = ( $this->clock )();
        $argsHash = Canon::hash( [ 'variables' => $entries ] );
        $userId   = (int) get_current_user_id();
        if ( $dry ) {
            self::refuseUnsafe( GlobalVariables::sendMap( self::withChanges( $before, $plan['changes'] ) ) );
            $items = [];
            foreach ( $plan['changes'] as $c ) {
                $items[ $c['index'] ] = Canon::hash( $c['before'] );
            }

            return [
                'changed'       => false,
                'dry_run'       => true,
                'changes'       => array_map( static fn ( array $c ): array => [ 'index' => $c['index'], 'id' => 'create' === $c['action'] ? null : $c['id'], 'type' => $c['type'], 'action' => $c['action'], 'before' => $c['before'], 'after' => $c['after'] ], $plan['changes'] ),
                'preview_token' => PreviewToken::make( $argsHash, $items, $now, $userId ),
                'expires_in'    => PreviewToken::TTL,
                'message'       => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this preview_token to apply it.',
            ];
        }

        $map = null === $token ? null : PreviewToken::open( $token, $argsHash, $now, $userId );
        if ( null === $map ) {
            throw new \InvalidArgumentException( 'A real run needs the preview_token from a dry run of the same arguments, made by you in the last 30 minutes and not used before. Call again with dry_run=true.' );
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
            return [ 'changed' => false, 'dry_run' => false, 'skipped' => $skipped, 'message' => 'Every variable changed since the preview, so nothing was written. Call again with dry_run=true.' ];
        }

        // Divi caches its read for the rest of the request, so within one request this can only notice a change made by code
        // that resets that cache; protection against another REQUEST is the lock taken in run(), which Divi builder saves ignore.
        $fresh = GlobalVariables::read();
        if ( null === $fresh || self::fingerprint( $fresh ) !== self::fingerprint( $before ) ) {
            throw new \InvalidArgumentException( "The site's global variables changed while this call was running; nothing was written. Call again with dry_run=true." );
        }

        $send = GlobalVariables::sendMap( self::withChanges( $before, $kept ) );
        self::refuseUnsafe( $send );
        $res   = GlobalVariables::write( $send );
        $after = $res['after'];
        if ( null === $after ) {
            throw new \RuntimeException( "Divi's global variables could not be read back after the write, so nothing is recorded. Check list_global_styles for their current state." );
        }

        $recorded   = [];
        $applied    = [];
        $notStored  = [];
        $notChanged = [];
        $differs    = [];
        foreach ( $kept as $c ) {
            $stored = GlobalVariables::state( $after[ $c['type'] ][ $c['id'] ] ?? null );
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
            $recorded[] = GlobalVariableKind::itemFor( $c['id'], $c['type'], $c['before'], $stored );
            $applied[]  = [ 'id' => $c['id'], 'type' => $c['type'], 'action' => $c['action'], 'before' => $c['before'], 'after' => $stored ];
        }
        $changedIds = array_column( $kept, 'id' );
        $unexpected = [];
        foreach ( $before as $type => $group ) {
            foreach ( $group as $id => $entry ) {
                if ( in_array( $id, $changedIds, true ) ) {
                    continue;
                }
                $now_ = $after[ $type ][ $id ] ?? null;
                if ( null === $now_ ) {
                    $unexpected[] = [ 'id' => $id, 'reason' => 'removed_by_divi' ];
                } elseif ( GlobalVariables::state( $now_ ) !== GlobalVariables::state( $entry ) ) {
                    $unexpected[] = [ 'id' => $id, 'reason' => 'changed' ];
                }
            }
        }

        if ( [] === $recorded ) {
            if ( $res['threw'] ) {
                throw new \RuntimeException( 'Divi did not save the variables, so nothing is recorded. Check list_global_styles for their current state.' );
            }

            return [ 'changed' => false, 'dry_run' => false, 'skipped' => $skipped, 'not_stored' => $notStored, 'not_changed' => $notChanged, 'unexpected_changes' => $unexpected, 'message' => 'Divi accepted the call, but none of the requested changes is stored (Divi silently ignores a save from a user without the Variables Manager permission); nothing is recorded.' ];
        }

        $batch  = $this->log->record( self::NAME, 'Global variables: ' . count( $recorded ) . ' change(s)', $recorded );
        $result = [
            'changed'  => true,
            'dry_run'  => false,
            'changes'  => $applied,
            'skipped'  => $skipped,
            'note'     => "A variable only shows on pages that cite its gvid id in a layout (list_global_styles shows the ids). Divi's caches were cleared.",
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

    /**
     * @param array<string,array<string,array<string,mixed>>> $all
     * @param list<array<string,mixed>> $changes
     * @return array<string,array<string,array<string,mixed>>>
     */
    private static function withChanges( array $all, array $changes ): array
    {
        foreach ( $changes as $c ) {
            $all[ $c['type'] ][ $c['id'] ] = $c['write'];
        }

        return $all;
    }

    /** @param array<string,mixed> $map the exact map that would be sent to Divi */
    private static function refuseUnsafe( array $map ): void
    {
        $unsafe = GlobalVariables::unsafeEntries( $map );
        if ( [] === $unsafe ) {
            return;
        }
        $list = implode( ', ', array_map( static fn ( array $u ): string => $u['id'] . ' (' . $u['reason'] . ')', $unsafe ) );
        throw new \InvalidArgumentException( "Divi would delete or damage these existing variables when it saves the list, and the change could not bring them back: {$list}. Nothing was changed. Fix or remove them in Divi's builder (Variables Manager) first." );
    }

    /** @param array<string,array<string,array<string,mixed>>> $all */
    private static function fingerprint( array $all ): string
    {
        $norm = [];
        foreach ( $all as $type => $group ) {
            foreach ( $group as $id => $entry ) {
                $norm[ $type ][ $id ] = GlobalVariables::state( $entry );
            }
        }

        return Canon::hash( $norm );
    }
}
