<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/CodePolicy.php';

/**
 * Tells the AI about two things a layout does with Divi 5.15 Components that look fine but do nothing, so it can correct itself.
 * It is an advisory only: it never changes a verdict, blocks a save or alters content (same contract as StyleReferences).
 *
 *  - `unknown_components`: an instance cites a component id that is not a Divi Library component on this site. Divi still shows
 *    the copy stored in the page (live proof, 2026-10-16: scripts/spike-component-edit.php), so the page works but is not
 *    linked to anything.
 *  - `component_owned_edits`: a save changed an attribute of a block that belongs to a component (a block with a `syncNodeId`
 *    inside an instance, or the instance's own `localAttrs`) although the component does not mark that attribute as unsynced
 *    (its `localAttrsMap`). Divi shows the component's own value in every instance, so the edit never appears (same spike: the
 *    changed heading and body text, and a changed module `localAttrs.title`, all rendered as the component's own text).
 *    Only attributes the component owns are reported; a Slot's content is local and is never reported.
 */
final class ComponentAdvisory
{
    public const MAX_ROWS = 20;

    public const MESSAGE = "A page that uses a component shows the component's own content in every instance, so a change to a block that belongs to the component does not appear (unless the component marks that setting as unsynced in the builder's Edit Component). Change the component itself in the Divi builder, or put your own modules in one of its Slots. An instance whose component id is not in this site's Divi Library still shows the copy stored in the page, linked to nothing. Call get_site_structure with include=components for this site's components and their Slots.";

    /** Keys that describe how Divi stores a block, never the block's own settings. */
    private const BOOKKEEPING = [ 'builderVersion', 'syncNodeId', 'localAttrOverridePaths', 'localAttrsMap', 'localAttrs', 'globalModule', 'blockName' ];

    /**
     * @param ?string                  $previous the stored content a save replaces; null = nothing to compare (create, validate)
     * @param callable(string):?bool   $exists   whether an id is a global component here; null = cannot tell
     * @param callable(string):?string $template a component's saved blocks; null = not readable
     * @return ?array{unknown_components: list<array{id:string,count:int}>, component_owned_edits: list<array<string,mixed>>, message: string}
     */
    public static function advise( string $content, ?string $previous, callable $exists, callable $template ): ?array
    {
        if ( ! str_contains( $content, 'wp:divi/global-layout' ) ) {
            return null;
        }
        if ( CodePolicy::tooLarge( $content ) || CodePolicy::tooDeep( $content ) ) {
            return null;
        }
        $new = ( new BlockParser() )->parse( $content );
        if ( ! $new->isOk() ) {
            return null;
        }
        $newInstances = self::instances( $new->root() );

        $unknown = [];
        foreach ( $newInstances as $inst ) {
            if ( false === $exists( $inst['id'] ) ) {
                $unknown[ $inst['id'] ] = ( $unknown[ $inst['id'] ] ?? 0 ) + 1;
            }
        }

        $edits = [];
        if ( null !== $previous && str_contains( $previous, 'wp:divi/global-layout' ) && ! CodePolicy::tooLarge( $previous ) && ! CodePolicy::tooDeep( $previous ) ) {
            $old = ( new BlockParser() )->parse( $previous );
            if ( $old->isOk() ) {
                $edits = self::ownedEdits( $newInstances, self::instances( $old->root() ), $template );
            }
        }

        if ( [] === $unknown && [] === $edits ) {
            return null;
        }
        $rows = [];
        foreach ( array_slice( $unknown, 0, self::MAX_ROWS, true ) as $id => $count ) {
            $rows[] = [ 'id' => self::shown( (string) $id ), 'count' => $count ];
        }

        return [ 'unknown_components' => $rows, 'component_owned_edits' => array_slice( $edits, 0, self::MAX_ROWS ), 'message' => self::MESSAGE ];
    }

    /** @return list<array{id:string,block:Block}> every instance in document order */
    private static function instances( Block $root ): array
    {
        $out = [];
        $root->walk( static function ( Block $b ) use ( &$out ): void {
            $id = $b->attr( 'globalModule' );
            if ( 'divi/global-layout' === $b->name() && is_string( $id ) && '' !== $id ) {
                $out[] = [ 'id' => $id, 'block' => $b ];
            }
        } );

        return $out;
    }

    /**
     * @param list<array{id:string,block:Block}> $new
     * @param list<array{id:string,block:Block}> $old
     * @param callable(string):?string           $template
     * @return list<array<string,mixed>>
     */
    private static function ownedEdits( array $new, array $old, callable $template ): array
    {
        $oldByNode  = [];
        $oldByOrder = [];
        foreach ( $old as $inst ) {
            $oldByOrder[ $inst['id'] ][] = $inst['block'];
            foreach ( self::owned( $inst['block'] ) as $b ) {
                $oldByNode[ (string) $b->attr( 'syncNodeId' ) ] = $b;
            }
        }
        $seen   = [];
        $locals = [];
        $rows   = [];
        foreach ( $new as $inst ) {
            $id  = $inst['id'];
            $nth = $seen[ $id ] = ( $seen[ $id ] ?? -1 ) + 1;
            if ( ! array_key_exists( $id, $locals ) ) {
                $tpl          = $template( $id );
                $locals[ $id ] = null === $tpl ? null : self::localNames( $tpl );
            }
            if ( null === $locals[ $id ] ) {
                continue; // the component cannot be read: say nothing rather than guess
            }
            $wrapper = $inst['block'];
            $before  = $oldByOrder[ $id ][ $nth ] ?? null;
            if ( null !== $before ) {
                $changed = self::changedKeys( (array) $before->attr( 'localAttrs' ), (array) $wrapper->attr( 'localAttrs' ), [ 'builderVersion' ] );
                $bad     = array_values( array_diff( $changed, $locals[ $id ]['__root__'] ?? [] ) );
                if ( [] !== $bad ) {
                    $rows[] = [ 'component' => $id, 'block' => (string) ( $wrapper->attr( 'blockName' ) ?? $wrapper->name() ), 'sync_node_id' => null, 'attributes' => $bad ];
                }
            }
            foreach ( self::owned( $wrapper ) as $b ) {
                $node = (string) $b->attr( 'syncNodeId' );
                $prev = $oldByNode[ $node ] ?? null;
                if ( null === $prev ) {
                    continue;
                }
                $changed = self::changedKeys( $prev->attrs(), $b->attrs(), self::BOOKKEEPING );
                $bad     = array_values( array_diff( $changed, $locals[ $id ][ $node ] ?? [] ) );
                if ( [] !== $bad ) {
                    $rows[] = [ 'component' => $id, 'block' => $b->name(), 'sync_node_id' => $node, 'attributes' => $bad ];
                }
            }
        }

        return $rows;
    }

    /**
     * The blocks inside an instance that the component owns: those with a `syncNodeId`, not looking inside a Slot (its content is
     * the instance's own) nor inside a nested instance (that one is its own component).
     *
     * @return list<Block>
     */
    private static function owned( Block $instance ): array
    {
        $out  = [];
        $walk = static function ( Block $b ) use ( &$out, &$walk ): void {
            foreach ( $b->children() as $c ) {
                if ( 'divi/global-slot' === $c->name() ) {
                    continue;
                }
                if ( is_string( $c->attr( 'syncNodeId' ) ) && '' !== $c->attr( 'syncNodeId' ) ) {
                    $out[] = $c;
                }
                if ( 'divi/global-layout' !== $c->name() ) {
                    $walk( $c );
                }
            }
        };
        $walk( $instance );

        return $out;
    }

    /**
     * For each synced block of a component, the first segment of every attribute it marks as unsynced (`localAttrsMap`); the
     * component's own first block is '__root__'.
     *
     * @return array<string,list<string>>
     */
    private static function localNames( string $templateContent ): array
    {
        $parsed = ( new BlockParser() )->parse( $templateContent );
        if ( ! $parsed->isOk() ) {
            return [];
        }
        $out   = [];
        $first = true;
        $parsed->root()->walk( static function ( Block $b, string $path ) use ( &$out, &$first ): void {
            if ( '__root__' === $b->name() || 'divi/placeholder' === $b->name() ) {
                return;
            }
            $node = $b->attr( 'syncNodeId' );
            $key  = is_string( $node ) && '' !== $node ? $node : ( $first ? '__root__' : null );
            $first = false;
            if ( null === $key ) {
                return;
            }
            $names = [];
            foreach ( (array) $b->attr( 'localAttrsMap' ) as $entry ) {
                if ( is_array( $entry ) && is_string( $entry['attrName'] ?? null ) && '' !== $entry['attrName'] ) {
                    $names[] = explode( '.', $entry['attrName'] )[0];
                }
            }
            $out[ $key ] = array_values( array_unique( $names ) );
        } );

        return $out;
    }

    /** @param array<mixed> $a @param array<mixed> $b @param list<string> $ignore @return list<string> */
    private static function changedKeys( array $a, array $b, array $ignore ): array
    {
        $changed = [];
        foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $k ) {
            $k = (string) $k;
            if ( in_array( $k, $ignore, true ) ) {
                continue;
            }
            if ( wp_json_encode( $a[ $k ] ?? null ) !== wp_json_encode( $b[ $k ] ?? null ) ) {
                $changed[] = $k;
            }
        }

        return $changed;
    }

    private static function shown( string $id ): string
    {
        return 1 === preg_match( '/^.{0,100}/su', $id, $m ) ? $m[0] : '[invalid id]';
    }
}
