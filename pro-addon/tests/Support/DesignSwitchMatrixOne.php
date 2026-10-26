<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests\Support;

use AiEditorDivi5\Pro\Design\Design;
use AiEditorDivi5\Pro\Design\DesignSwitch;
use AiEditorDivi5\Pro\Design\PageScan;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LanguageResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;

require_once __DIR__ . '/DesignDiffClassifier.php';

/**
 * Matrix 1 of the design switch proofs (plan 2026-10-25-pro-design-switch.md, Task 6): every recipe built under each
 * of the ten (language, own kit) designs and switched to each of the ten, 71 x 10 x 10 = 7,100 switches, plus the
 * properties that ride on the same outputs (identity through the engine path, switching twice, the round trip, the
 * gate, leftovers, byte preservation). Computed ONCE per PHPUnit process and shared by `DesignSwitchMatrixTest` and
 * `DesignSwitchPropertiesTest`, so the suite pays for the 7,100 switches (and their 6,390 round trips) once. Only
 * summaries are kept (classes and counts, never the pages), so memory stays flat.
 */
final class DesignSwitchMatrixOne
{
    /** Every n-th switched output (a != b, in loop order, from the first) is gated and byte-preservation checked. */
    public const GATE_EVERY = 7;

    /** @var array<string,mixed>|null */
    private static ?array $result = null;

    /** The page as the free plugin serves the recipe under a design (image tokens resolved as outside WordPress). */
    public static function build( string $recipe, Design $d ): string
    {
        return (string) SectionRecipes::resolveMarkup( ImageTokens::resolve( (string) SectionRecipes::rawMarkup( $recipe ), '' ), $d->language, $d->kit );
    }

    /** @return array<string,Design> the ten languages under their own kits, in `DesignLanguages::ids()` order */
    public static function defaultDesigns(): array
    {
        $out = [];
        foreach ( DesignLanguages::ids() as $l ) {
            $out[ $l ] = Design::make( $l, (string) DesignLanguages::get( $l )['kit'] );
        }

        return $out;
    }

    /**
     * @return array{
     *   switches:int, identical:int, firsts:list<?string>, combos:array<string,int>, recipes:array<string,list<string>>,
     *   identity_shortcut:int, identity_engine:int, identity_engine_failures:list<string>,
     *   pairs:int, twice:int, twice_failures:list<string>, round_trip:int, round_trip_firsts:list<?string>,
     *   round_trip_recipes:array<string,int>, gated:int, gate_valid:int, preserved:int, leftovers:int, unclassified:list<string>
     * }
     */
    public static function result(): array
    {
        if ( null !== self::$result ) {
            return self::$result;
        }
        $designs = self::defaultDesigns();
        $r = [
            'switches' => 0, 'identical' => 0, 'firsts' => [], 'combos' => [], 'recipes' => [],
            'identity_shortcut' => 0, 'identity_engine' => 0, 'identity_engine_failures' => [],
            'pairs' => 0, 'twice' => 0, 'twice_failures' => [], 'round_trip' => 0, 'round_trip_firsts' => [],
            'round_trip_recipes' => [], 'gated' => 0, 'gate_valid' => 0, 'preserved' => 0, 'leftovers' => 0, 'unclassified' => [],
        ];
        $k = 0;
        foreach ( SectionRecipes::names() as $recipe ) {
            $built = [];
            foreach ( $designs as $id => $d ) {
                $built[ $id ] = self::build( $recipe, $d );
            }
            foreach ( $designs as $a => $from ) {
                foreach ( $designs as $b => $to ) {
                    ++$r['switches'];
                    $out     = DesignSwitch::run( $built[ $a ], $from, $to )->content;
                    $classes = DesignDiffClassifier::classify( $out, $built[ $b ] );
                    $r['firsts'][] = $classes[0] ?? null;
                    if ( [] === $classes ) {
                        ++$r['identical'];
                    } else {
                        $combo = implode( '+', $classes );
                        $r['combos'][ $combo ] = ( $r['combos'][ $combo ] ?? 0 ) + 1;
                        foreach ( $classes as $c ) {
                            $r['recipes'][ $c ][ $recipe ] = true;
                        }
                        self::noteUnclassified( $r, $classes, "$recipe $a>$b" );
                    }
                    if ( $a === $b ) {
                        if ( $out === $built[ $a ] ) {
                            ++$r['identity_shortcut'];
                        }
                        // The engine path the shortcut skips: unresolve under the own design, resolve under it again.
                        $again = (string) SectionRecipes::resolveMarkup( DesignSwitch::unresolve( $built[ $a ], $from, $to ), $to->language, $to->kit );
                        if ( $again === $built[ $a ] ) {
                            ++$r['identity_engine'];
                        } else {
                            $r['identity_engine_failures'][] = "$recipe $a " . implode( '+', DesignDiffClassifier::classify( $again, $built[ $a ] ) );
                        }
                        continue;
                    }
                    ++$r['pairs'];
                    // Switching twice to the same target, through the engine path (from == to would be the shortcut).
                    $twice = (string) SectionRecipes::resolveMarkup( DesignSwitch::unresolve( $out, $to, $to ), $to->language, $to->kit );
                    if ( $twice === $out ) {
                        ++$r['twice'];
                    } else {
                        $tc = DesignDiffClassifier::classify( $twice, $out );
                        $r['twice_failures'][] = "$recipe $a>$b " . implode( '+', $tc );
                        self::noteUnclassified( $r, $tc, "twice $recipe $a>$b" );
                    }
                    $back = DesignSwitch::run( $out, $to, $from )->content;
                    if ( $back === $built[ $a ] ) {
                        ++$r['round_trip'];
                    } else {
                        $bc = DesignDiffClassifier::classify( $back, $built[ $a ] );
                        $r['round_trip_firsts'][] = $bc[0] ?? null;
                        $r['round_trip_recipes'][ $recipe ] = ( $r['round_trip_recipes'][ $recipe ] ?? 0 ) + 1;
                        self::noteUnclassified( $r, $bc, "round trip $recipe $a>$b>$a" );
                    }
                    if ( null !== RecipeTokens::advise( $out ) || [] !== KitResolver::leftovers( $out ) || [] !== LanguageResolver::leftovers( $out ) ) {
                        ++$r['leftovers'];
                    }
                    if ( 0 === ( $k++ ) % self::GATE_EVERY ) {
                        ++$r['gated'];
                        if ( LayoutGate::check( $out, $built[ $a ] )->isValid() ) {
                            ++$r['gate_valid'];
                        }
                        if ( self::preserves( $built[ $a ], DesignSwitch::unresolve( $built[ $a ], $from, $to ), $out ) ) {
                            ++$r['preserved'];
                        }
                    }
                }
            }
        }
        foreach ( $r['recipes'] as $c => $set ) {
            $names = array_keys( $set );
            sort( $names, SORT_STRING );
            $r['recipes'][ $c ] = $names;
        }
        ksort( $r['recipes'], SORT_STRING );
        ksort( $r['combos'], SORT_STRING );
        ksort( $r['round_trip_recipes'], SORT_STRING );

        return self::$result = $r;
    }

    /**
     * Byte preservation outside the leaves the switch decided to change: every byte outside the attribute JSON is equal
     * between input and output, and every block whose attributes the unresolve step left byte-identical (no marker, token
     * or role recovered) has byte-identical attributes in the output.
     */
    public static function preserves( string $in, string $unresolved, string $out ): bool
    {
        $a = PageScan::scan( $in, [] );
        $u = PageScan::scan( $unresolved, [] );
        $o = PageScan::scan( $out, [] );
        if ( count( $a ) !== count( $u ) || count( $a ) !== count( $o ) ) {
            return false;
        }
        $atIn  = 0;
        $atOut = 0;
        foreach ( $a as $i => $x ) {
            if ( null === $x['json'] ) {
                continue;
            }
            if ( substr( $in, $atIn, $x['offset'] - $atIn ) !== substr( $out, $atOut, $o[ $i ]['offset'] - $atOut ) ) {
                return false;
            }
            if ( $x['json'] === $u[ $i ]['json'] && $x['json'] !== $o[ $i ]['json'] ) {
                return false;
            }
            $atIn  = $x['offset'] + $x['length'];
            $atOut = $o[ $i ]['offset'] + $o[ $i ]['length'];
        }

        return substr( $in, $atIn ) === substr( $out, $atOut );
    }

    /** @param list<string> $classes */
    private static function noteUnclassified( array &$r, array $classes, string $where ): void
    {
        if ( in_array( DesignDiffClassifier::UNCLASSIFIED, $classes, true ) || in_array( DesignDiffClassifier::STRUCTURE, $classes, true ) ) {
            $r['unclassified'][] = $where;
        }
    }
}
