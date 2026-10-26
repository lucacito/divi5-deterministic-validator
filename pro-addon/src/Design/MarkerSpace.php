<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Design;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AiEditorDivi5\WP\LanguageAdapters;

/**
 * The space of markers the free plugin's resolvers accept, as the design switch enumerates it.
 *
 * The five constants are COPIES of the private constants of the free `KitResolver` (same names, same values, same
 * order). They are private there and the free plugin must not change for the add-on (the Pro Contract), so the copy is
 * pinned equal by `MarkerSpaceTest::testTheCopiedConstantsEqualTheFreeOnes`, which reads the free values with
 * `ReflectionClassConstant`. A role the free plugin adds later is then simply not recovered (left and reported, the
 * safe failure) until the copy is refreshed and the pin passes again.
 *
 * Pure and deterministic: no site access. The candidates are only the SPACE of markers; what each one writes is
 * always the free pipeline's output, never computed here.
 */
final class MarkerSpace
{
    /** Marker role => the blocks it may sit on (copy of KitResolver::ROLE_BLOCKS). */
    public const ROLE_BLOCKS = [
        'control'           => [ 'divi/button' ],
        'control-secondary' => [ 'divi/button' ],
        'card'              => [ 'divi/group', 'divi/column', 'divi/testimonial' ],
        'panel'             => [ 'divi/group', 'divi/column', 'divi/testimonial' ],
        'rule'              => [ 'divi/group', 'divi/column', 'divi/divider', 'divi/number-counter' ],
        'rule-strong'       => [ 'divi/group', 'divi/column', 'divi/divider', 'divi/number-counter' ],
        'chip'              => [ 'divi/group', 'divi/icon', 'divi/blurb' ],
        'item'              => [ 'divi/accordion', 'divi/toggle' ],
        'item-rule'         => [ 'divi/accordion', 'divi/toggle' ],
        'form'              => [ 'divi/contact-form' ],
        'rule-accent'       => [ 'divi/divider' ],
    ];

    /** Marker role => the surfaces (bands) it may name (copy of KitResolver::ROLE_SURFACES). */
    public const ROLE_SURFACES = [
        'control'           => [ 'light', 'accent', 'dark', 'panel' ],
        'control-secondary' => [ 'light', 'accent', 'dark', 'panel' ],
        'card'              => [ 'light', 'surface', 'accent', 'dark' ],
        'panel'             => [ 'light', 'surface', 'accent', 'dark' ],
        'item'              => [ 'light', 'surface', 'accent', 'dark' ],
        'item-rule'         => [ 'light', 'surface' ],
        'form'              => [ 'light', 'surface', 'accent', 'dark' ],
        'rule'              => [ 'light', 'surface', 'accent', 'dark', 'panel' ],
        'rule-strong'       => [ 'light', 'surface', 'accent', 'dark', 'panel' ],
        'chip'              => [ 'light', 'surface', 'accent', 'dark' ],
        'rule-accent'       => [ 'light', 'surface' ],
    ];

    /** Marker role => the grounds it may name (copy of KitResolver::ROLE_GROUNDS). */
    public const ROLE_GROUNDS = [
        'card'  => [ 'inverted', 'accent' ],
        'panel' => [ 'inverted', 'accent' ],
    ];

    /** The surfaces a ground may sit on (copy of KitResolver::GROUND_SURFACES). */
    public const GROUND_SURFACES = [ 'light', 'surface' ];

    /** The blocks a ground may sit on (copy of KitResolver::GROUND_BLOCKS). */
    public const GROUND_BLOCKS = [ 'divi/group', 'divi/column' ];

    /**
     * Every kit marker `KitResolver::marker()` accepts on $block, in ROLE_BLOCKS order, `on` in ROLE_SURFACES order, a
     * ground candidate right after its plain one. Every candidate names its `on` explicitly.
     *
     * @return list<array{kit:string,on:string,ground?:string}>
     */
    public static function kitCandidates( string $block ): array
    {
        $out = [];
        foreach ( self::ROLE_BLOCKS as $role => $blocks ) {
            if ( ! in_array( $block, $blocks, true ) ) {
                continue;
            }
            foreach ( self::ROLE_SURFACES[ $role ] ?? [] as $on ) {
                $out[] = [ 'kit' => $role, 'on' => $on ];
                if ( ! in_array( $on, self::GROUND_SURFACES, true ) || ! in_array( $block, self::GROUND_BLOCKS, true ) ) {
                    continue;
                }
                foreach ( self::ROLE_GROUNDS[ $role ] ?? [] as $ground ) {
                    $out[] = [ 'kit' => $role, 'on' => $on, 'ground' => $ground ];
                }
            }
        }

        return $out;
    }

    /**
     * Every language marker (`LanguageAdapters::APPLIED`) on $block: `[ 'type' => 'display' ]`, `[ 'rhythm' => 'section' ]`...
     *
     * @return list<array<string,string>>
     */
    public static function languageCandidates( string $block ): array
    {
        $out = [];
        foreach ( LanguageAdapters::APPLIED as $kind => $blocks ) {
            foreach ( $blocks[ $block ] ?? [] as $role ) {
                $out[] = [ $kind => $role ];
            }
        }

        return $out;
    }
}
