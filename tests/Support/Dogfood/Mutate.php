<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support\Dogfood;

use AiEditorDivi5\WP\RecipeTokens;

/**
 * Test-only helpers for the scorer's mutation tests (dogfood plan 2026-10-22, Task 2): change ONE attribute of ONE block of a
 * page and leave every other byte alone. Only the JSON of the addressed block comment is re-encoded (with
 * RecipeTokens::encodeJson, as the plugin writes block attributes); the block is addressed as the scorer names it,
 * "<name>#<index among blocks of that name>", counted over every block comment of the page in document order. Never shipped.
 */
final class Mutate
{
    /**
     * $page with the attribute at the dot path set to $value (missing steps are created).
     *
     * @throws \InvalidArgumentException when the block does not exist or has no attribute JSON
     */
    public static function set( string $page, string $blockKey, string $dotPath, mixed $value ): string
    {
        // The value goes through JSON so an array becomes an object tree like the rest of the attributes.
        $value = json_decode( (string) json_encode( $value ), false );

        return self::edit( $page, $blockKey, static function ( object $attrs ) use ( $dotPath, $value ): void {
            $parts = explode( '.', $dotPath );
            $last  = array_pop( $parts );
            $node  = $attrs;
            foreach ( $parts as $part ) {
                if ( ! isset( $node->$part ) || ! is_object( $node->$part ) ) {
                    $node->$part = new \stdClass();
                }
                $node = $node->$part;
            }
            $node->$last = $value;
        } );
    }

    /**
     * $page without the attribute at the dot path.
     *
     * @throws \InvalidArgumentException when the block does not exist, has no attribute JSON, or has no such attribute
     */
    public static function remove( string $page, string $blockKey, string $dotPath ): string
    {
        return self::edit( $page, $blockKey, static function ( object $attrs ) use ( $dotPath, $blockKey ): void {
            $parts = explode( '.', $dotPath );
            $last  = array_pop( $parts );
            $node  = $attrs;
            foreach ( $parts as $part ) {
                if ( ! isset( $node->$part ) || ! is_object( $node->$part ) ) {
                    throw new \InvalidArgumentException( "$blockKey has no attribute $dotPath." );
                }
                $node = $node->$part;
            }
            if ( ! property_exists( $node, $last ) ) {
                throw new \InvalidArgumentException( "$blockKey has no attribute $dotPath." );
            }
            unset( $node->$last );
        } );
    }

    /**
     * The attribute at the dot path of the addressed block (null when missing), read from the page text.
     */
    public static function get( string $page, string $blockKey, string $dotPath ): mixed
    {
        $found = self::locate( $page, $blockKey );
        $node  = json_decode( $found['json'], true );
        foreach ( explode( '.', $dotPath ) as $part ) {
            if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
                return null;
            }
            $node = $node[ $part ];
        }

        return $node;
    }

    /**
     * @param callable(object):void $change
     */
    private static function edit( string $page, string $blockKey, callable $change ): string
    {
        $found = self::locate( $page, $blockKey );
        $attrs = json_decode( $found['json'], false );
        if ( ! is_object( $attrs ) ) {
            throw new \InvalidArgumentException( "$blockKey has no attribute JSON." );
        }
        $change( $attrs );
        $json = RecipeTokens::encodeJson( $attrs );
        if ( '' === $json ) {
            throw new \InvalidArgumentException( "$blockKey: the mutated attributes cannot be encoded." );
        }

        return substr( $page, 0, $found['start'] ) . $json . substr( $page, $found['start'] + $found['length'] );
    }

    /**
     * Where the attribute JSON of a block comment sits in the page, found the way the validator's BlockParser reads comments.
     *
     * @return array{start:int,length:int,json:string}
     */
    private static function locate( string $page, string $blockKey ): array
    {
        $hash = strrpos( $blockKey, '#' );
        if ( false === $hash ) {
            throw new \InvalidArgumentException( "Not a block key: $blockKey." );
        }
        $want  = substr( $blockKey, 0, $hash );
        $index = (int) substr( $blockKey, $hash + 1 );
        $seen  = -1;
        $off   = 0;
        $len   = strlen( $page );
        while ( $off < $len ) {
            $open = strpos( $page, '<!--', $off );
            if ( false === $open ) {
                break;
            }
            $close = strpos( $page, '-->', $open );
            if ( false === $close ) {
                break;
            }
            $inner = substr( $page, $open + 4, $close - $open - 4 );
            $off   = $close + 3;
            if ( 1 !== preg_match( '/^(\s*wp:([a-z0-9\/_-]+))(\s+)(\{.*\})(\s*\/?\s*)$/is', $inner, $m ) && 1 !== preg_match( '/^(\s*wp:([a-z0-9\/_-]+))()()(\s*\/?\s*)$/is', $inner, $m ) ) {
                continue;
            }
            if ( $m[2] !== $want ) {
                continue;
            }
            if ( ++$seen !== $index ) {
                continue;
            }
            if ( '' === $m[4] ) {
                throw new \InvalidArgumentException( "$blockKey has no attribute JSON." );
            }

            return [ 'start' => $open + 4 + strlen( $m[1] ) + strlen( $m[3] ), 'length' => strlen( $m[4] ), 'json' => $m[4] ];
        }

        throw new \InvalidArgumentException( "The page has no block $blockKey." );
    }
}
