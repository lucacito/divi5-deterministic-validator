<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Companion;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Two deterministic, advisory consistency checks across the pages of one review:
 *  - SPACING_VARIES: sections whose desktop top padding is a px value; more than MAX_DISTINCT different values across the
 *    pages reviewed is reported with how often each occurs (a site normally uses a few).
 *  - USE_GLOBAL_COLOR: a colour written as a plain hex value that is exactly the value of one of the site's global colors, where
 *    the global color should be used so a later change to it reaches the page.
 * Both read only what is written in the layouts. Neither changes anything.
 */
final class SiteConsistency
{
    public const MAX_DISTINCT = 4;

    /** @param array<int,string> $contents page id => layout @return array{distinct:array<string,int>, pages:array<int,int>} */
    public static function sectionPaddings( array $contents ): array
    {
        $counts = [];
        $pages  = [];
        foreach ( $contents as $id => $content ) {
            foreach ( self::blocks( $content ) as $block ) {
                if ( 'divi/section' !== $block->name() ) {
                    continue;
                }
                $top = $block->attr( 'module.decoration.spacing.desktop.value.padding.top' );
                if ( is_string( $top ) && 1 === preg_match( '/^\s*(\d+(?:\.\d+)?)px\s*$/', $top, $m ) ) {
                    $key            = rtrim( rtrim( number_format( (float) $m[1], 2, '.', '' ), '0' ), '.' ) . 'px';
                    $counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
                    $pages[ $id ]   = ( $pages[ $id ] ?? 0 ) + 1;
                }
            }
        }
        ksort( $counts, SORT_NATURAL );

        return [ 'distinct' => $counts, 'pages' => $pages ];
    }

    /** @param array<int,string> $contents @return list<array{code:string, message:string, detail:array<string,int>}> */
    public static function spacingFindings( array $contents ): array
    {
        $s = self::sectionPaddings( $contents );
        if ( count( $s['distinct'] ) <= self::MAX_DISTINCT ) {
            return [];
        }

        return [ [
            'code'    => 'SPACING_VARIES',
            'message' => 'Sections use ' . count( $s['distinct'] ) . ' different desktop top paddings across these pages. Pick the few the design language uses (see get-design-languages) and use them everywhere.',
            'detail'  => $s['distinct'],
        ] ];
    }

    /**
     * Plain hex colours in one layout that equal a global color of the site.
     *
     * @param array<string,string> $globals global color id => value as the site stores it
     * @return list<array{code:string, color:string, use:string, count:int}>
     */
    public static function literalGlobalColors( string $content, array $globals ): array
    {
        $byHex = [];
        foreach ( $globals as $id => $value ) {
            $hex = self::hex( $value );
            if ( null !== $hex && ! isset( $byHex[ $hex ] ) ) {
                $byHex[ $hex ] = $id;
            }
        }
        if ( [] === $byHex ) {
            return [];
        }
        $found = [];
        foreach ( self::blocks( $content ) as $block ) {
            self::collect( $block->attrs(), $byHex, $found );
        }
        $out = [];
        foreach ( $found as $hex => $count ) {
            $out[] = [ 'code' => 'USE_GLOBAL_COLOR', 'color' => $hex, 'use' => $byHex[ $hex ], 'count' => $count ];
        }

        return $out;
    }

    /** @param array<mixed> $node @param array<string,string> $byHex @param array<string,int> $found */
    private static function collect( array $node, array $byHex, array &$found ): void
    {
        foreach ( $node as $key => $value ) {
            if ( is_array( $value ) ) {
                self::collect( $value, $byHex, $found );
            } elseif ( 'color' === $key && is_string( $value ) ) {
                $hex = self::hex( $value );
                if ( null !== $hex && isset( $byHex[ $hex ] ) ) {
                    $found[ $hex ] = ( $found[ $hex ] ?? 0 ) + 1;
                }
            }
        }
    }

    /** A plain #rgb or #rrggbb colour normalised to lower-case #rrggbb, else null. */
    public static function hex( mixed $value ): ?string
    {
        if ( ! is_string( $value ) ) {
            return null;
        }
        $v = strtolower( trim( $value ) );
        if ( 1 === preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $v, $m ) ) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }

        return 1 === preg_match( '/^#[0-9a-f]{6}$/', $v ) ? $v : null;
    }

    /** @return list<Block> every block of a layout, depth first; empty when it cannot be parsed */
    private static function blocks( string $content ): array
    {
        if ( '' === trim( $content ) || strlen( $content ) > 524288 ) {
            return [];
        }
        $parsed = ( new BlockParser() )->parse( $content );
        if ( ! $parsed->isOk() || null === $parsed->root() ) {
            return [];
        }
        $out = [];
        $parsed->root()->walk( static function ( Block $b ) use ( &$out ): void {
            $out[] = $b;
        } );

        return $out;
    }
}
