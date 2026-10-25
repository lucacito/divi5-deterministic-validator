<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\RecipeTokens;
use Divi5Validator\Tests\RecipeLibraryTest;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/' ); // the plugin classes refuse to load outside WordPress without it
}
require_once __DIR__ . '/../../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/Colour.php';

/**
 * Dev/test-side audit of the thin things between modules in the section recipes (rules and chips adapter, plan
 * 2026-10-21, Task 2): which blocks are RULES (a `divi/divider` with a line colour, or a `divi/group` / `divi/column`
 * whose only border is a top edge and which paints no background) and which are CHIPS (an icon with a background and a
 * radius, an outline tag, or anything marked `chip`). Never shipped.
 *
 * A block is found by what it IS or by what it SAYS, so the lists are the same before and after the migration:
 *
 *   unmigrated   found by its decoration (the shapes above)
 *   migrated     found by its `"aied":{"kit":"rule|rule-strong|chip"}` marker (the decoration is gone)
 *
 * `index` is the block's order among ALL blocks of its name in the recipe (CardReport numbering, the numbering of
 * RecipeLibraryTest::markerBands), `on` is that shared band walk (`other:text` is a `panel`). A rule's role is decided
 * by its colour (Spec issue 6): `border` or any alpha token is a quiet `rule`, every other colour is `rule-strong`;
 * a migrated rule says its role itself and carries no colour (`colour` null).
 *
 * SHAPE_EXEMPTIONS are blocks that look like a rule or chip but are a shape or brand decision; they are never listed
 * by rules() / chips() (rules() can include the library's exempt rules for the floor measurement).
 *
 * The quiet-rule floor (Ruling 5). Measured by `php scripts/rule-chip-audit.php --floor` over EVERY rule the library
 * shipped at 155ff75 (59: the two accent dividers that Ruling 8 later exempted are part of what the library shipped, so
 * they are measured), each in the 10 languages, the rule colour (alpha included) composited over its band and judged
 * against the band. The lowest cell of every colour on every band (fixtures/shapes/rule-floor.md):
 *
 *   border (quiet)           light    1.184  soft-saas        <- the overall minimum
 *   border (quiet)           surface  1.275  soft-saas
 *   accent-text:40 (quiet)   accent   1.928  playful-pastel
 *   accent                   light    2.304  playful-pastel
 *   bg                       panel   11.978  organic-warm
 *   inverse                  dark     9.543  playful-pastel
 *   text                     light   11.978 / surface 13.274  organic-warm
 *
 * The minimum, 1.184, rounded DOWN to one decimal is the floor: QUIET_RULE_FLOOR = 1.1. It is written once and never
 * lowered; a kit x language x band cell that cannot meet it by a role change fails, it is not excused.
 */
final class RuleChipAudit
{
    /** The weakest quiet rule any kit may draw: the library's measured minimum (1.184) rounded down. Never lowered. */
    public const QUIET_RULE_FLOOR = 1.1;

    /**
     * The weakest `rule-accent` (an accent line on a `light` or `surface` band) any kit may draw. Carriers adapter,
     * Ruling 1: the accent-vs-band minimum is the `accent | light | 2.304 | playful-pastel` row of
     * fixtures/shapes/rule-floor.md (the two accent dividers the library shipped), rounded DOWN to one decimal. No band is
     * 3:1 in all ten languages (light 2.304, surface 2.448, accent 1.000, dark 1.401, panel 1.103), so the accent rule is a
     * decorative brand mark judged at this measured floor. Written once, never lowered.
     */
    public const ACCENT_RULE_FLOOR = 2.3;

    /** The script's photo model: the language overlay over mid grey (KitAdaptersTest::PHOTO_GREY). */
    public const PHOTO_GREY = '#808080';

    /**
     * recipe|block|index => why this block stays literal although it looks like a rule or a chip.
     *
     * Carriers adapter (plan 2026-10-24, Task 5, Ruling 11): 15 -> 9. The four counters (`rule-strong` on accent) and the
     * two accent dividers (`rule-accent` on light) are kit-driven now and pinned in CarrierMigrationTest.
     *
     * @var array<string,string>
     */
    public const SHAPE_EXEMPTIONS = [
        'steps-horizontal|divi/group|5'  => 'step badge: a 48px circle, a shape decision, Ruling 6',
        'steps-horizontal|divi/group|9'  => 'step badge: a 48px circle, a shape decision, Ruling 6',
        'steps-horizontal|divi/group|13' => 'step badge: a 48px circle, a shape decision, Ruling 6',
        'steps-horizontal|divi/group|17' => 'step badge: a 48px circle, a shape decision, Ruling 6',
        'timeline-vertical|divi/group|3'  => 'timeline dot: a 14px square, a shape decision, Ruling 6',
        'timeline-vertical|divi/group|8'  => 'timeline dot: a 14px square, a shape decision, Ruling 6',
        'timeline-vertical|divi/group|13' => 'timeline dot: a 14px square, a shape decision, Ruling 6',
        'timeline-vertical|divi/group|18' => 'timeline dot: a 14px square, a shape decision, Ruling 6',
        'pricing-three-tier|divi/group|10' => 'the filled [Most popular] badge on the accent ground: a filled pill, not an outline tag; Spec issue 3',
    ];

    /** The blocks the carriers adapter added (plan 2026-10-24): their markers are CarrierAudit's and CarrierMigrationTest's. */
    private const CARRIER_BLOCKS = [ 'divi/number-counter', 'divi/blurb', 'divi/testimonial' ];

    /** The block names whose top-edge-only border reads as a rule when raw rows are scanned (a counter is exempt, never listed). */
    private const RULE_CARRIERS = [ 'divi/divider', 'divi/group', 'divi/column' ];

    /** The block names a rule marker or a decoration rule is reported for. */
    private const SCANNED_RULE_BLOCKS = [ 'divi/divider', 'divi/group', 'divi/column', 'divi/number-counter' ];

    /**
     * Every rule of the recipes, in recipe and document order.
     *
     * @param list<array{name:string,markup:string}> $recipes        the rows of section-recipes.json
     * @param bool                                   $includeExempt  true adds the exempt rule-like blocks of RULE_CARRIERS
     *                                                               (the floor measurement covers what the library shipped)
     *
     * @return list<array{recipe:string,block:string,index:int,role:string,on:string,colour:?string,marked:bool}>
     */
    public static function rules( array $recipes, bool $includeExempt = false ): array
    {
        $out = [];
        foreach ( self::scan( $recipes ) as $row ) {
            if ( 'rule' !== $row['kind'] || ! in_array( $row['block'], self::RULE_CARRIERS, true ) ) {
                continue;
            }
            if ( ! $includeExempt && isset( self::SHAPE_EXEMPTIONS[ self::key( $row ) ] ) ) {
                continue;
            }
            $out[] = [
                'recipe' => $row['recipe'],
                'block'  => $row['block'],
                'index'  => $row['index'],
                'role'   => $row['role'],
                'on'     => $row['on'],
                'colour' => $row['colour'],
                'marked' => $row['marked'],
            ];
        }

        return $out;
    }

    /**
     * Every chip of the recipes, in recipe and document order.
     *
     * @param list<array{name:string,markup:string}> $recipes
     *
     * @return list<array{recipe:string,block:string,index:int,on:string,marked:bool}>
     */
    public static function chips( array $recipes ): array
    {
        $out = [];
        foreach ( self::scan( $recipes ) as $row ) {
            if ( 'chip' !== $row['kind'] || isset( self::SHAPE_EXEMPTIONS[ self::key( $row ) ] ) ) {
                continue;
            }
            $out[] = [ 'recipe' => $row['recipe'], 'block' => $row['block'], 'index' => $row['index'], 'on' => $row['on'], 'marked' => $row['marked'] ];
        }

        return $out;
    }

    /**
     * Every block of the recipes named by SHAPE_EXEMPTIONS: key => [block exists, what the raw scan makes of it].
     * `looks` is `rule` (a raw rule shape or marker), `chip`, `filled` (a block that paints a background: a badge, a dot) or `none`.
     *
     * @param list<array{name:string,markup:string}> $recipes
     *
     * @return array<string,array{exists:bool,looks:string}>
     */
    public static function exemptionsSeen( array $recipes ): array
    {
        $seen = [];
        foreach ( self::SHAPE_EXEMPTIONS as $key => $_ ) {
            $seen[ $key ] = [ 'exists' => false, 'looks' => 'none' ];
        }
        foreach ( $recipes as $recipe ) {
            foreach ( self::blocks( (string) $recipe['name'], (string) $recipe['markup'] ) as $b ) {
                $key = self::key( $b );
                if ( ! isset( self::SHAPE_EXEMPTIONS[ $key ] ) ) {
                    continue;
                }
                $kind = self::kindOf( $b['block'], $b['attrs'] );
                $seen[ $key ] = [ 'exists' => true, 'looks' => null !== $kind ? $kind['kind'] : ( self::fills( $b['attrs'] ) ? 'filled' : 'none' ) ];
            }
        }

        return $seen;
    }

    /** The key of a row in SHAPE_EXEMPTIONS: recipe|block|index. */
    public static function key( array $row ): string
    {
        return $row['recipe'] . '|' . $row['block'] . '|' . $row['index'];
    }

    /**
     * The floor measurement cells: one per (rule colour, band), the lowest contrast of the rule's colour composited over
     * its band against that band over the 10 design languages, with the language, the rule that gives it and how many
     * rules share the cell. Covers every rule the library shipped (the exempt dividers included); a marked rule has no
     * colour and cannot be measured (the measurement is made at the revision before the migration).
     *
     * @param list<array{name:string,markup:string}> $recipes
     *
     * @return list<array{colour:string,band:string,ratio:float,language:string,rule:string,count:int}>
     */
    public static function floorCells( array $recipes ): array
    {
        $cells = [];
        foreach ( self::rules( $recipes, true ) as $rule ) {
            if ( null === $rule['colour'] ) {
                throw new \RuntimeException( self::key( $rule ) . ' is marked: the floor is measured on the unmigrated library.' );
            }
            $cell = $rule['colour'] . '|' . $rule['on'];
            $cells[ $cell ] ??= [ 'colour' => $rule['colour'], 'band' => $rule['on'], 'ratio' => INF, 'language' => '', 'rule' => '', 'count' => 0 ];
            ++$cells[ $cell ]['count'];
            foreach ( DesignLanguages::ids() as $id ) {
                $lang = DesignLanguages::get( $id );
                if ( ! is_array( $lang ) ) {
                    throw new \RuntimeException( "design language $id is unreadable" );
                }
                $ratio = self::ratio( $rule['colour'], $rule['on'], $lang );
                if ( $ratio < $cells[ $cell ]['ratio'] ) {
                    $cells[ $cell ]['ratio']    = $ratio;
                    $cells[ $cell ]['language'] = $id;
                    $cells[ $cell ]['rule']     = self::key( $rule );
                }
            }
        }

        return array_values( $cells );
    }

    /**
     * Contrast of a rule colour token (`border`, `accent-text:40`) drawn on a band, in one design language.
     *
     * @param array<string,mixed> $lang a design language as DesignLanguages::get() returns it
     */
    public static function ratio( string $colourRole, string $band, array $lang ): float
    {
        $colors = (array) $lang['colors'];
        $under  = match ( $band ) {
            'light'   => Colour::opaque( (string) $colors['bg'], '#ffffff' ),
            'surface' => Colour::opaque( (string) $colors['surface'], (string) $colors['bg'] ),
            'accent'  => Colour::opaque( (string) $colors['accent'], (string) $colors['bg'] ),
            'panel'   => Colour::opaque( (string) $colors['text'], (string) $colors['bg'] ),
            'dark'    => Colour::opaque( (string) $colors['overlay'], self::PHOTO_GREY ),
            default   => throw new \RuntimeException( "no band colour for \"$band\"" ),
        };
        $line = RecipeTokens::resolve( '{{aied:color:' . $colourRole . '}}', $lang );
        $ink  = null === $under ? null : Colour::opaque( $line, $under );
        if ( null === $under || null === $ink ) {
            throw new \RuntimeException( "cannot composite $colourRole on $band in {$lang['id']}" );
        }

        return Colour::contrast( $ink, $under );
    }

    /**
     * Every block of the recipes the audit looks at, classified: kind rule|chip, role, band, colour, marker.
     *
     * @param list<array{name:string,markup:string}> $recipes
     *
     * @return list<array{recipe:string,block:string,index:int,kind:string,role:string,on:string,colour:?string,marked:bool}>
     */
    private static function scan( array $recipes ): array
    {
        $rows = [];
        foreach ( $recipes as $recipe ) {
            foreach ( self::blocks( (string) $recipe['name'], (string) $recipe['markup'] ) as $b ) {
                $kind = self::kindOf( $b['block'], $b['attrs'] );
                if ( null === $kind ) {
                    continue;
                }
                if ( 'rule' === $kind['kind'] && ! in_array( $b['block'], self::SCANNED_RULE_BLOCKS, true ) ) {
                    continue;
                }
                $rows[] = [
                    'recipe' => $b['recipe'],
                    'block'  => $b['block'],
                    'index'  => $b['index'],
                    'kind'   => $kind['kind'],
                    'role'   => $kind['role'],
                    'on'     => $b['on'],
                    'colour' => $kind['colour'],
                    'marked' => $kind['marked'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Every block of one markup with its attributes and band, in document order of recipe-wide numbering.
     *
     * @return list<array{recipe:string,block:string,index:int,attrs:array<string,mixed>,on:string}>
     */
    private static function blocks( string $recipe, string $markup ): array
    {
        $out = [];
        // The placeholder root and blocks without attributes have nothing to audit (and the shared walk cannot key the root).
        foreach ( RecipeLibraryTest::markerBands( $markup, static fn ( array $attrs ): ?array => [] === $attrs ? null : [ $attrs ] ) as $key => [ $found, $band ] ) {
            $hash  = strrpos( $key, '#' );
            $out[] = [
                'recipe' => $recipe,
                'block'  => substr( $key, 0, (int) $hash ),
                'index'  => (int) substr( $key, (int) $hash + 1 ),
                'attrs'  => $found[0],
                'on'     => 'other:text' === $band ? 'panel' : $band,
            ];
        }

        return $out;
    }

    /**
     * What one block is: a rule (marked or by its decoration), a chip (marked or by its decoration) or nothing.
     *
     * @param array<string,mixed> $attrs
     *
     * @return ?array{kind:string,role:string,colour:?string,marked:bool}
     */
    private static function kindOf( string $block, array $attrs ): ?array
    {
        $kit = is_array( $attrs['aied'] ?? null ) ? ( $attrs['aied']['kit'] ?? null ) : null;
        // Carriers adapter (plan 2026-10-24, Task 5, Ruling 11): a `rule-accent` marker, and any marker on the carriers
        // that adapter added (a counter's rule, a blurb's icon chip, a testimonial's card or panel), are
        // CarrierMigrationTest's, not this audit's.
        if ( null !== $kit && ( 'rule-accent' === $kit || in_array( $block, self::CARRIER_BLOCKS, true ) ) ) {
            return null;
        }
        if ( 'rule' === $kit || 'rule-strong' === $kit ) {
            return [ 'kind' => 'rule', 'role' => $kit, 'colour' => null, 'marked' => true ];
        }
        if ( 'chip' === $kit ) {
            return [ 'kind' => 'chip', 'role' => 'chip', 'colour' => null, 'marked' => true ];
        }
        $dec = $attrs['module']['decoration'] ?? [];
        $dec = is_array( $dec ) ? $dec : [];
        if ( 'divi/divider' === $block ) {
            $line = $attrs['divider']['advanced']['line']['desktop']['value'] ?? [];
            if ( ! is_array( $line ) || 'off' === ( $line['show'] ?? null ) ) {
                return null;
            }
            $colour = self::role( $line['color'] ?? null );
            return null === $colour ? null : [ 'kind' => 'rule', 'role' => self::roleOf( $colour ), 'colour' => $colour, 'marked' => false ];
        }
        $border = $dec['border']['desktop']['value'] ?? [];
        $border = is_array( $border ) ? $border : [];
        $styles = is_array( $border['styles'] ?? null ) ? $border['styles'] : [];
        $px     = static fn ( $v ): float => is_string( $v ) || is_numeric( $v ) ? (float) $v : 0.0;
        $radius = 0.0;
        foreach ( [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ] as $corner ) {
            $radius = max( $radius, $px( $border['radius'][ $corner ] ?? 0 ) );
        }
        if ( in_array( $block, [ 'divi/group', 'divi/column', 'divi/number-counter' ], true ) && isset( $styles['all'], $styles['top'] )
            && 0.0 === $px( $styles['all']['width'] ?? null ) && $px( $styles['top']['width'] ?? 0 ) > 0 && ! self::fills( $attrs ) ) {
            $colour = self::role( $styles['top']['color'] ?? $styles['all']['color'] ?? null );
            if ( null === $colour ) {
                throw new \RuntimeException( "a top rule on $block with a colour that is not a colour token" );
            }
            return [ 'kind' => 'rule', 'role' => self::roleOf( $colour ), 'colour' => $colour, 'marked' => false ];
        }
        if ( 'divi/icon' === $block && $radius > 0 && self::backgroundColour( $attrs ) ) {
            return [ 'kind' => 'chip', 'role' => 'chip', 'colour' => null, 'marked' => false ];
        }
        if ( 'divi/group' === $block && $radius > 0 && ! self::fills( $attrs ) && 1.0 === $px( $styles['all']['width'] ?? 0 ) ) {
            return [ 'kind' => 'chip', 'role' => 'chip', 'colour' => null, 'marked' => false ];
        }

        return null;
    }

    /** `border` or any alpha token is a quiet rule; every other colour is strong (Spec issue 6). */
    private static function roleOf( string $colour ): string
    {
        return 'border' === $colour || str_contains( $colour, ':' ) ? 'rule' : 'rule-strong';
    }

    /** The role part of a `{{aied:color:<role>[:NN]}}` token (`accent-text:40`), or null for anything else. */
    private static function role( mixed $value ): ?string
    {
        return is_string( $value ) && 1 === preg_match( '/^\{\{aied:color:([a-z-]+(?::[1-9][0-9]?)?)\}\}$/', $value, $m ) ? $m[1] : null;
    }

    /** Does the block paint a ground of its own (a colour, an enabled gradient or an image)? */
    private static function fills( array $attrs ): bool
    {
        $v = $attrs['module']['decoration']['background']['desktop']['value'] ?? null;
        if ( ! is_array( $v ) ) {
            return false;
        }
        if ( is_string( $v['color'] ?? null ) && '' !== $v['color'] ) {
            return true;
        }
        if ( is_array( $v['gradient'] ?? null ) && 'on' === ( $v['gradient']['enabled'] ?? null ) ) {
            return true;
        }

        return isset( $v['image'] ) && is_array( $v['image'] ) && '' !== (string) ( $v['image']['url'] ?? '' );
    }

    /** A module background COLOUR (the icon chip's fill). */
    private static function backgroundColour( array $attrs ): bool
    {
        $c = $attrs['module']['decoration']['background']['desktop']['value']['color'] ?? null;

        return is_string( $c ) && '' !== $c;
    }
}
