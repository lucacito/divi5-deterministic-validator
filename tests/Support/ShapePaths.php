<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;

/**
 * Turns Divi 5 block attributes into normalised attribute paths (dev/test-side only, not shipped).
 *
 * Normalisation: breakpoint keys become {bp}, numeric keys become {n}, descent stops at "innerContent"
 * (the path ends there) and the bookkeeping keys in EXEMPT are not attribute shapes at all.
 */
final class ShapePaths
{
    public const BREAKPOINTS = [ 'desktop', 'tablet', 'phone', 'tabletWide', 'phoneWide' ];

    public const EXEMPT = [
        'builderVersion', 'modulePreset', 'syncNodeId', 'slotId', 'globalModule', 'blockName', 'localAttrs', 'localAttrOverridePaths',
    ];

    /**
     * @param array<mixed> $attrs
     * @return list<string> unique, sorted
     */
    public static function fromBlockAttrs( array $attrs ): array
    {
        $out = [];
        self::walk( $attrs, [], $out );
        $paths = array_keys( $out );
        sort( $paths, SORT_STRING );
        return $paths;
    }

    /** @return list<string> every block's paths, unique, sorted */
    public static function fromMarkup( string $markup ): array
    {
        if ( strpos( $markup, '<!-- wp:divi/placeholder' ) === false ) {
            $markup = '<!-- wp:divi/placeholder -->' . $markup . '<!-- /wp:divi/placeholder -->';
        }
        $parsed = ( new BlockParser() )->parse( $markup );
        $root   = $parsed->root();
        if ( $root === null ) {
            return [];
        }
        $all = [];
        $root->walk( static function ( Block $b ) use ( &$all ): void {
            foreach ( ShapePaths::fromBlockAttrs( $b->attrs() ) as $p ) {
                $all[ $p ] = true;
            }
        } );
        $paths = array_keys( $all );
        sort( $paths, SORT_STRING );
        return $paths;
    }

    /**
     * Dotted paths of four or more segments written in Markdown (backticks stripped), normalised.
     *
     * @return list<string> unique, sorted
     */
    public static function fromGuideText( string $md ): array
    {
        $md = str_replace( '`', '', $md );
        $found = [];
        if ( preg_match_all( '/(?<![A-Za-z0-9_.{}\/-])[A-Za-z_][A-Za-z0-9_]*(?:\.(?:[A-Za-z0-9_]+|\{bp\}|\{n\})){3,}(?![A-Za-z0-9_{}\/-])/', $md, $m ) ) {
            foreach ( $m[0] as $raw ) {
                $segments = array_map( static function ( string $s ): string {
                    if ( in_array( $s, self::BREAKPOINTS, true ) ) {
                        return '{bp}';
                    }
                    return ctype_digit( $s ) ? '{n}' : $s;
                }, explode( '.', $raw ) );
                $found[ implode( '.', $segments ) ] = true;
            }
        }
        $paths = array_keys( $found );
        sort( $paths, SORT_STRING );
        return $paths;
    }

    /**
     * @param array<mixed>        $node
     * @param list<string>        $prefix
     * @param array<string,true>  $out
     */
    private static function walk( array $node, array $prefix, array &$out ): void
    {
        foreach ( $node as $key => $value ) {
            $key = (string) $key;
            if ( in_array( $key, self::EXEMPT, true ) ) {
                continue;
            }
            if ( in_array( $key, self::BREAKPOINTS, true ) ) {
                $seg = '{bp}';
            } elseif ( ctype_digit( $key ) ) {
                $seg = '{n}';
            } else {
                $seg = $key;
            }
            $path = array_merge( $prefix, [ $seg ] );
            if ( $seg === 'innerContent' || ! is_array( $value ) || $value === [] ) {
                $out[ implode( '.', $path ) ] = true;
                continue;
            }
            self::walk( $value, $path, $out );
        }
    }
}
