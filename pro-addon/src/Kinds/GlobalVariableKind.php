<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\SiteLockingKind;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Settings\GlobalColors;
use AiEditorDivi5\Pro\Settings\GlobalVariables;
use AiEditorDivi5\Pro\Settings\GlobalVariablesLock;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One Divi global variable. The item stores the previous state (null = the variable did not exist, so undo ARCHIVES it: Divi's own
 * delete is archive, and Divi 5.15.1 refuses any save that omits an existing id) and its type; undo writes the complete map back
 * through GlobalVariables, puts back only the fields the batch changed, and only while the variable still looks the way the batch
 * left it. A variable the batch created is never archived by undo once it has changed since, not even with force. restore() runs under the site lock BatchUndo holds for the whole batch (see SiteLockingKind).
 */
final class GlobalVariableKind implements SiteLockingKind
{
    public const NAME = 'global_variable';

    /** The only fields a change ever touches. */
    private const FIELDS = [ 'label', 'value', 'status' ];

    private GlobalVariablesLock $lock;

    public function __construct( ?GlobalVariablesLock $lock = null )
    {
        $this->lock = $lock ?? new GlobalVariablesLock();
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string,string>|null $before GlobalVariables::state() before the change, or null when the variable did not exist
     * @param array<string,string>|null $after GlobalVariables::state() as Divi stores it now, or null when it no longer exists
     * @param string|null $order the order to put back for a variable that no longer exists (a forced undo of an edit recreates it there)
     * @return array<string,mixed>
     */
    public static function itemFor( string $id, string $type, ?array $before, ?array $after, ?string $order = null ): array
    {
        $fields = [];
        foreach ( self::FIELDS as $field ) {
            if ( ( $before[ $field ] ?? null ) !== ( $after[ $field ] ?? null ) ) {
                $fields[] = $field;
            }
        }
        $name  = (string) ( $after['label'] ?? $before['label'] ?? '' );
        $label = 'Global variable' . ( '' !== $name ? " \"{$name}\"" : '' ) . " ({$type}, {$id})";
        $keep  = [ '_fields' => $fields, '_type' => $type ] + ( null === $order ? [] : [ '_order' => $order ] );

        return Item::make( self::NAME, $id, $label, null === $before ? null : $before + $keep, $after );
    }

    public function current( array $item ): mixed
    {
        $all = GlobalVariables::read();
        if ( null === $all ) {
            throw new \RuntimeException( "Divi's global variables cannot be read." );
        }
        $found = GlobalVariables::find( $all, (string) $item['ref'] );

        return GlobalVariables::state( $found['entry'] ?? null );
    }

    public function allowed( array $item ): bool
    {
        return GlobalVariables::permitted() && GlobalVariables::available();
    }

    public function lockSite(): bool
    {
        return $this->lock->acquire();
    }

    public function unlockSite(): void
    {
        $this->lock->release();
    }

    /** Runs under the site lock BatchUndo holds: Divi's save replaces the whole map, so two writers must not interleave. */
    public function restore( array $item ): array
    {
        $id     = (string) $item['ref'];
        $before = is_array( $item['before'] ?? null ) ? $item['before'] : null;
        $all    = GlobalVariables::read();
        if ( null === $all ) {
            return [ 'ok' => false, 'reason' => 'divi_unavailable' ];
        }
        $found    = GlobalVariables::find( $all, $id );
        $nowState = GlobalVariables::state( $found['entry'] ?? null );

        if ( null === $before ) {
            if ( null === $found ) {
                return [ 'ok' => false, 'reason' => 'not_found' ];
            }
            if ( Canon::hash( $nowState ) !== (string) ( $item['after_hash'] ?? '' ) ) {
                return [ 'ok' => false, 'reason' => 'changed_since' ]; // even when forced: never remove a variable someone changed after us
            }
            // Archive, never remove: the entry keeps its label, value and place; undoing this undo makes it active again.
            $type                = $found['type'];
            $order               = null;
            $entry               = GlobalVariables::builderShape( array_merge( $found['entry'], [ 'status' => 'archived' ] ), $id, $type, GlobalColors::stamp( time() ) );
            $all[ $type ][ $id ] = $entry;
            $expected            = GlobalVariables::state( $entry );
        } else {
            $type = (string) ( $found['type'] ?? ( $before['_type'] ?? '' ) );
            if ( ! in_array( $type, GlobalVariables::MANAGED, true ) ) {
                return [ 'ok' => false, 'reason' => 'not_found' ];
            }
            $order  = null;
            $fields = is_array( $before['_fields'] ?? null ) ? $before['_fields'] : self::FIELDS;
            $stamp  = GlobalColors::stamp( time() );
            $entry  = $found['entry'] ?? [ 'id' => $id, 'label' => (string) $before['label'], 'value' => (string) $before['value'], 'order' => (string) ( $before['_order'] ?? GlobalVariables::nextOrder( $all[ $type ] ?? [] ) ), 'status' => (string) $before['status'], 'lastUpdated' => $stamp, 'variableType' => $type ];
            foreach ( $fields as $field ) {
                if ( in_array( $field, self::FIELDS, true ) && array_key_exists( $field, $before ) ) {
                    $entry[ $field ] = $before[ $field ];
                }
            }
            // Same stored form as the builder's (Divi ignores a variable without id/variableType); an older write is repaired here.
            $entry               = GlobalVariables::builderShape( $entry, $id, $type, $stamp );
            $all[ $type ][ $id ] = $entry;
            $expected            = GlobalVariables::state( $entry );
        }

        $map = GlobalVariables::sendMap( $all );
        if ( [] !== GlobalVariables::unsafeEntries( $map ) ) {
            return [ 'ok' => false, 'reason' => 'unsafe_to_save' ]; // Divi would delete or damage an entry of the map we must send back
        }

        $res = GlobalVariables::write( $map );
        if ( null === $res['after'] ) {
            return [ 'ok' => false, 'reason' => 'divi_refused' ] + ( null === $res['error'] ? [] : [ 'detail' => $res['error']['message'] ] );
        }
        $stored = GlobalVariables::state( $res['after'][ $type ][ $id ] ?? null );
        if ( $stored !== $expected ) {
            return [ 'ok' => false, 'reason' => 'divi_refused' ] + ( null === $res['error'] ? [] : [ 'detail' => $res['error']['message'] ] );
        }

        return [ 'ok' => true, 'inverse' => self::itemFor( $id, $type, $nowState, $stored, $order ) ];
    }
}
