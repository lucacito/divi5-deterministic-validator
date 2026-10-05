<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Settings\GlobalColors;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One Divi global color. The item stores the previous state (null = the color did not exist, so undo removes it);
 * undo writes the complete map back through GlobalColors, puts back only the fields the batch changed, and only while
 * the color still looks the way the batch left it. A color the batch created is never removed once it has changed since,
 * not even with force.
 */
final class GlobalColorKind implements ItemKind
{
    public const NAME = 'global_color';

    /** The only fields a change ever touches. */
    private const FIELDS = [ 'color', 'label', 'status' ];

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string,string>|null $before GlobalColors::state() before the change, or null when the color did not exist
     * @param array<string,string>|null $after GlobalColors::state() as Divi stores it now, or null when it no longer exists
     * @return array<string,mixed>
     */
    public static function itemFor( string $id, ?array $before, ?array $after ): array
    {
        $fields = [];
        foreach ( self::FIELDS as $field ) {
            if ( ( $before[ $field ] ?? null ) !== ( $after[ $field ] ?? null ) ) {
                $fields[] = $field;
            }
        }
        $name  = (string) ( $after['label'] ?? $before['label'] ?? '' );
        $label = 'Global color' . ( '' !== $name ? " \"{$name}\"" : '' ) . " ({$id})";

        return Item::make( self::NAME, $id, $label, null === $before ? null : $before + [ '_fields' => $fields ], $after );
    }

    public function current( array $item ): mixed
    {
        $all = GlobalColors::read();
        if ( null === $all ) {
            throw new \RuntimeException( "Divi's global colors cannot be read." );
        }

        return GlobalColors::state( $all[ (string) $item['ref'] ] ?? null );
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'edit_theme_options' ) && GlobalColors::available();
    }

    public function restore( array $item ): array
    {
        $id     = (string) $item['ref'];
        $before = is_array( $item['before'] ?? null ) ? $item['before'] : null;
        $all    = GlobalColors::read();
        if ( null === $all ) {
            return [ 'ok' => false, 'reason' => 'divi_unavailable' ];
        }
        $current  = $all[ $id ] ?? null;
        $nowState = GlobalColors::state( $current );
        $map      = GlobalColors::customMap( $all );
        $stamp    = GlobalColors::stamp( time() );

        if ( null === $before ) {
            if ( null === $current ) {
                return [ 'ok' => false, 'reason' => 'not_found' ];
            }
            if ( Canon::hash( $nowState ) !== (string) ( $item['after_hash'] ?? '' ) ) {
                return [ 'ok' => false, 'reason' => 'changed_since' ]; // even when forced: never remove a color someone changed after us
            }
            unset( $map[ $id ] );
            $expected = null;
        } else {
            $fields = is_array( $before['_fields'] ?? null ) ? $before['_fields'] : self::FIELDS;
            if ( GlobalColors::isThemeId( $id ) ) {
                if ( null === $nowState ) {
                    return [ 'ok' => false, 'reason' => 'not_found' ];
                }
                $color      = in_array( 'color', $fields, true ) ? (string) $before['color'] : $nowState['color'];
                $map[ $id ] = GlobalColors::themeEntry( $color, $nowState['label'], $stamp );
                $expected   = [ 'color' => $color ] + $nowState;
            } else {
                $entry = $current ?? [ 'color' => (string) $before['color'], 'folder' => (string) ( $before['folder'] ?? '' ), 'label' => (string) $before['label'], 'status' => (string) $before['status'], 'lastUpdated' => $stamp, 'usedInPosts' => [] ];
                foreach ( $fields as $field ) {
                    if ( in_array( $field, self::FIELDS, true ) && array_key_exists( $field, $before ) ) {
                        $entry[ $field ] = $before[ $field ];
                    }
                }
                $entry['lastUpdated'] = $stamp;
                $map[ $id ]           = $entry;
                $expected             = GlobalColors::state( $entry );
            }
        }

        $res = GlobalColors::write( $map );
        if ( null === $res['after'] ) {
            return [ 'ok' => false, 'reason' => 'divi_refused' ];
        }
        $stored = GlobalColors::state( $res['after'][ $id ] ?? null );
        if ( $stored !== $expected ) {
            return [ 'ok' => false, 'reason' => 'divi_refused' ];
        }

        return [ 'ok' => true, 'inverse' => self::itemFor( $id, $nowState, $stored ) ];
    }
}
