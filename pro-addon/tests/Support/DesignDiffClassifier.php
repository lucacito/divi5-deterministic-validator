<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests\Support;

use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\Expectations;
use AiEditorDivi5\Pro\Design\Leaves;
use AiEditorDivi5\Pro\Design\MarkerSpace;
use AiEditorDivi5\Pro\Design\PageScan;
use AiEditorDivi5\WP\BlockAttrs;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\StyleKits;

/**
 * The ONE classifier of every difference between a switched page and the direct build of the same recipes under the
 * target design (plan 2026-10-25-pro-design-switch.md, controller Ruling 6; spec section 6, the D1-D5 table). Dev
 * tooling for the proofs only: never shipped.
 *
 * Blocks are paired in document order (`PageScan`). A different block count, a different block name at the same
 * position, a different text between block comments, or attributes on one side only is `STRUCTURE` (always a failure).
 * For each pair whose attribute bytes differ, both sides are decoded and flattened into leaves (`Leaves::of`: a list and
 * an empty object are one leaf). JSON-equal attributes are `D5` (key order only). Otherwise every leaf present on one
 * side only, or with another value, is classified by the spec's rules, one `if` each, in this order:
 *
 * - `D1`: a font family leaf (key `family`): an untyped text or slider font whose source family two roles shared;
 * - `D2`: a leaf under `image.decoration.border` of a `divi/image`: a flush image read as a kit image;
 * - `D3`: a background `color` leaf of a `divi/group` or `divi/column` (a fill whose value two roles share, left);
 * - `D4`: a leaf at a path some kit candidate patch of the block's name writes (`KITS_FOR_PATHS`): an unmarked block
 *   whose literals equal what the source kit writes, switched as kit-driven ("indistinguishable is switched");
 * - anything else: `UNCLASSIFIED` (a failure: a difference the spec does not name).
 *
 * Pure and deterministic. The kit path set is read from the free pipeline (`Expectations`), never spelled here.
 */
final class DesignDiffClassifier
{
    public const CLASSES = [ 'D1', 'D2', 'D3', 'D4', 'D5' ];

    public const STRUCTURE    = 'STRUCTURE';
    public const UNCLASSIFIED = 'UNCLASSIFIED';

    /** The order a switch is counted in ("by first class"). */
    public const ORDER = [ 'D1', 'D2', 'D3', 'D4', 'D5', self::STRUCTURE, self::UNCLASSIFIED ];

    /** @var array<string,array<string,true>>|null block name => kit patch leaf paths (segments joined by '.') */
    private static ?array $kitPaths = null;

    /**
     * The classes of every difference, unique, in ORDER (`[]` = byte-identical).
     *
     * @return list<string>
     */
    public static function classify( string $switched, string $direct ): array
    {
        $seen = [];
        foreach ( self::details( $switched, $direct ) as $d ) {
            $seen[ $d['class'] ] = true;
        }

        return array_values( array_filter( self::ORDER, static fn ( string $c ): bool => isset( $seen[ $c ] ) ) );
    }

    /** The first class of a switch's differences in ORDER, or null when byte-identical. */
    public static function first( string $switched, string $direct ): ?string
    {
        return self::classify( $switched, $direct )[0] ?? null;
    }

    /**
     * Every difference: the block's index, its scorer key (`name#n`, n counting blocks of that name from 0, a top-level
     * `divi/placeholder` not counted, as `tests/Support/Dogfood/Page.php` keys blocks), the leaf path ('' for a whole block)
     * and the class.
     *
     * @return list<array{block:int, key:string, name:string, path:string, class:string}>
     */
    public static function details( string $switched, string $direct ): array
    {
        if ( $switched === $direct ) {
            return [];
        }
        $a = PageScan::scan( $switched, [] );
        $b = PageScan::scan( $direct, [] );
        if ( count( $a ) !== count( $b ) ) {
            return [ [ 'block' => -1, 'key' => '', 'name' => '', 'path' => 'block count ' . count( $a ) . ' vs ' . count( $b ), 'class' => self::STRUCTURE ] ];
        }
        $out    = [];
        $atA    = 0;
        $atB    = 0;
        $counts = [];
        foreach ( $a as $i => $x ) {
            $y    = $b[ $i ];
            $name = $x['name'];
            $top  = null === $x['parent'] && 'divi/placeholder' === $name;
            $key  = '';
            if ( ! $top ) {
                $counts[ $name ] = ( $counts[ $name ] ?? -1 ) + 1;
                $key             = $name . '#' . $counts[ $name ];
            }
            if ( $name !== $y['name'] || ( null === $x['json'] ) !== ( null === $y['json'] ) ) {
                $out[] = [ 'block' => $i, 'key' => $key, 'name' => $name, 'path' => 'block ' . $name . ' vs ' . $y['name'], 'class' => self::STRUCTURE ];
                continue;
            }
            if ( null === $x['json'] ) {
                continue;
            }
            if ( substr( $switched, $atA, $x['offset'] - $atA ) !== substr( $direct, $atB, $y['offset'] - $atB ) ) {
                $out[] = [ 'block' => $i, 'key' => $key, 'name' => $name, 'path' => 'text before the attributes', 'class' => self::STRUCTURE ];
            }
            $atA = $x['offset'] + $x['length'];
            $atB = $y['offset'] + $y['length'];
            if ( $x['json'] === $y['json'] ) {
                continue;
            }
            foreach ( self::blockDiff( $name, $x['json'], $y['json'] ) as [ $path, $class ] ) {
                $out[] = [ 'block' => $i, 'key' => $key, 'name' => $name, 'path' => $path, 'class' => $class ];
            }
        }
        if ( substr( $switched, $atA ) !== substr( $direct, $atB ) ) {
            $out[] = [ 'block' => -1, 'key' => '', 'name' => '', 'path' => 'text after the last attributes', 'class' => self::STRUCTURE ];
        }

        return $out;
    }

    /**
     * Count a list of switches by first class.
     *
     * @param list<?string> $firsts
     *
     * @return array<string,int> class => switches, in ORDER, zero classes included
     */
    public static function tally( array $firsts ): array
    {
        $t = array_fill_keys( self::ORDER, 0 );
        foreach ( $firsts as $f ) {
            if ( null !== $f ) {
                ++$t[ $f ];
            }
        }

        return $t;
    }

    /** @return list<array{0:string,1:string}> [path, class] */
    private static function blockDiff( string $name, string $jsonA, string $jsonB ): array
    {
        $da = BlockAttrs::decode( $jsonA );
        $db = BlockAttrs::decode( $jsonB );
        if ( null === $da || null === $db ) {
            return [ [ 'attributes do not decode', self::STRUCTURE ] ];
        }
        $la = self::flat( $da );
        $lb = self::flat( $db );
        $paths = [];
        foreach ( $la + $lb as $path => $_ ) {
            if ( ( $la[ $path ] ?? null ) !== ( $lb[ $path ] ?? null ) ) {
                $paths[] = $path;
            }
        }
        if ( [] === $paths ) {
            return [ [ '', 'D5' ] ];
        }
        $out = [];
        foreach ( $paths as $path ) {
            $out[] = [ $path, self::leafClass( $name, $path ) ];
        }

        return $out;
    }

    /** The spec section 6 rules, one `if` each. */
    private static function leafClass( string $name, string $path ): string
    {
        $segments = explode( '.', $path );
        $leaf     = end( $segments );
        if ( 'family' === $leaf ) {
            return 'D1';
        }
        if ( 'divi/image' === $name && str_starts_with( $path, 'image.decoration.border.' ) ) {
            return 'D2';
        }
        if ( in_array( $name, [ 'divi/group', 'divi/column' ], true ) && 'color' === $leaf && in_array( 'background', $segments, true ) ) {
            return 'D3';
        }
        if ( isset( self::kitPaths()[ $name ][ $path ] ) ) {
            return 'D4';
        }

        return self::UNCLASSIFIED;
    }

    /** @return array<string,string> leaf path => encoded value */
    private static function flat( \stdClass $attrs ): array
    {
        $out = [];
        foreach ( Leaves::of( $attrs ) as [ $path, $value ] ) {
            $out[ implode( '.', $path ) ] = Leaves::encode( $value );
        }

        return $out;
    }

    /**
     * Block name => every leaf path a kit candidate patch of that name writes, over every kit under editorial and the
     * ten languages under their own kits (the designs the proofs switch between), read from the free pipeline.
     *
     * @return array<string,array<string,true>>
     */
    public static function kitPaths(): array
    {
        if ( null !== self::$kitPaths ) {
            return self::$kitPaths;
        }
        $designs = [];
        foreach ( StyleKits::ids() as $kit ) {
            $designs[] = Design::make( 'editorial', $kit );
        }
        foreach ( DesignLanguages::ids() as $l ) {
            $designs[] = Design::make( $l, (string) DesignLanguages::get( $l )['kit'] );
        }
        $names = [];
        foreach ( MarkerSpace::ROLE_BLOCKS as $blocks ) {
            foreach ( $blocks as $n ) {
                $names[ $n ] = true;
            }
        }
        $paths = [];
        foreach ( $designs as $d ) {
            $e = new Expectations( $d );
            foreach ( array_keys( $names ) as $n ) {
                foreach ( MarkerSpace::kitCandidates( $n ) as $c ) {
                    foreach ( array_keys( $e->prepared( $n, $c ) ?? [] ) as $p ) {
                        $paths[ $n ][ str_replace( "\0", '.', (string) $p ) ] = true;
                    }
                }
            }
        }
        ksort( $paths, SORT_STRING );

        return self::$kitPaths = $paths;
    }
}
