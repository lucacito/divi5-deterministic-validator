<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

use Divi5Validator\Tests\RecipeLibraryTest;

/**
 * Dev/test-side audit of the carriers adapter (plan 2026-10-24, Task 2): which blocks of the section recipes are the
 * four carriers the adapter takes, and which modules stay unadapted and why. Never shipped.
 *
 * A block is found by what it IS or by what it SAYS, so the list is the same before and after the migration (Task 5):
 *
 *   unmigrated   found by its decoration (the shapes below)
 *   migrated     found by its `"aied":{"kit":"<role>"}` marker (the decoration is gone)
 *
 * The four carriers (spec "What the audit found", 11 blocks in 5 recipes):
 *
 *   divi/divider         a line coloured with the `accent` token                          role rule-accent (Ruling 2)
 *   divi/testimonial     a module background colour                                       role panel       (Ruling 3)
 *   divi/blurb           an `imageIcon` background colour                                 role chip
 *   divi/number-counter  `border.styles.all` 0px and `styles.top` wider than 0, no fill   role rule / rule-strong by the
 *                                                                                         rule's colour (Ruling 4: a full
 *                                                                                         ink such as `accent-text` is strong)
 *
 * `index` is the block's order among ALL blocks of its name in the recipe (the numbering of
 * RecipeLibraryTest::markerBands, the walk the rule and chip audits use) and `on` is that walk's band (`other:text` is a
 * `panel`). A marked block says its role itself.
 */
final class CarrierAudit
{
    /** The module-by-module scope: which marker roles each carrier answers to. */
    private const CARRIER_ROLES = [
        'divi/divider'        => [ 'rule-accent' ],
        'divi/testimonial'    => [ 'card', 'panel' ],
        'divi/blurb'          => [ 'chip' ],
        'divi/number-counter' => [ 'rule', 'rule-strong' ],
    ];

    /**
     * module => why it stays unadapted (spec "Stays UNADAPTED, by ruling"). The reasons are the spec's, shortened.
     *
     * @var array<string,string>
     */
    public const UNADAPTED = [
        'divi/circle-counter'     => 'the ring and track are colour roles of the language; a kit has no ring field',
        'divi/slider'             => 'each slide repeats the surface fill, two blocks coupled which one marker per block cannot express; arrows and dot nav were never probed',
        'divi/slide'              => 'a slide repeats its slider\'s surface fill (enableColor): coupled to the slider, which is not adapted',
        'divi/countdown-timer'    => 'the digit boxes have no kit field',
        'divi/social-media-follow' => 'network brand colours, not kit colours',
        'divi/before-after-image' => 'the handle and divider are the module\'s own control; image corners are a shape (the image-radius pass)',
        'divi/blog'               => 'Divi builds one item per post, module.decoration frames the whole feed and the per-item paths were never probed',
        'divi/shop'               => 'Divi builds one item per product, module.decoration frames the whole feed and the per-item paths were never probed',
        'divi/group-carousel'     => 'the carousel is a track; its children are already ordinary groups and icons',
        'divi/signup'             => 'DECLINED: no library block to migrate or prove, so a carrier would add a probe, a golden and a render slot for no shipped page',
    ];

    /**
     * module => [blocks, recipes] measured in section-recipes.json (the modules are untouched by the migration).
     *
     * @var array<string,array{0:int,1:int}>
     */
    public const UNADAPTED_COUNTS = [
        'divi/circle-counter'      => [ 4, 1 ],
        'divi/slider'              => [ 2, 2 ],
        'divi/slide'               => [ 6, 2 ],
        'divi/countdown-timer'     => [ 1, 1 ],
        'divi/social-media-follow' => [ 1, 1 ],
        'divi/before-after-image'  => [ 1, 1 ],
        'divi/blog'                => [ 1, 1 ],
        'divi/shop'                => [ 1, 1 ],
        'divi/group-carousel'      => [ 2, 2 ],
        'divi/signup'              => [ 0, 0 ],
    ];

    /**
     * Every carrier block of the recipes, in recipe and document order.
     *
     * @param list<array{name:string,markup:string}> $recipes the rows of section-recipes.json
     *
     * @return list<array{recipe:string,block:string,index:int,role:string,on:string,marked:bool}>
     */
    public static function blocks( array $recipes ): array
    {
        $out = [];
        foreach ( $recipes as $recipe ) {
            // The placeholder root and blocks without attributes have nothing to audit.
            $found = RecipeLibraryTest::markerBands(
                (string) $recipe['markup'],
                static fn ( array $attrs ): ?array => [] === $attrs ? null : [ $attrs ]
            );
            foreach ( $found as $key => [ $attrs, $band ] ) {
                $hash  = (int) strrpos( $key, '#' );
                $block = substr( $key, 0, $hash );
                $kind  = self::roleOf( $block, $attrs[0] );
                if ( null === $kind ) {
                    continue;
                }
                $out[] = [
                    'recipe' => (string) $recipe['name'],
                    'block'  => $block,
                    'index'  => (int) substr( $key, $hash + 1 ),
                    'role'   => $kind['role'],
                    'on'     => 'other:text' === $band ? 'panel' : $band,
                    'marked' => $kind['marked'],
                ];
            }
        }

        return $out;
    }

    /** The key of a block in a pinned table: recipe|block|index. */
    public static function key( array $row ): string
    {
        return $row['recipe'] . '|' . $row['block'] . '|' . $row['index'];
    }

    /**
     * How many blocks of each UNADAPTED module the recipes hold, and in how many recipes.
     *
     * @param list<array{name:string,markup:string}> $recipes
     *
     * @return array<string,array{0:int,1:int}> module => [blocks, recipes]
     */
    public static function unadaptedSeen( array $recipes ): array
    {
        $seen = [];
        foreach ( array_keys( self::UNADAPTED ) as $module ) {
            $seen[ $module ] = [ 0, 0 ];
            $pattern         = '/<!-- wp:' . preg_quote( $module, '/' ) . '(?: |-->|\/-->)/';
            foreach ( $recipes as $recipe ) {
                $n = (int) preg_match_all( $pattern, (string) $recipe['markup'] );
                if ( $n > 0 ) {
                    $seen[ $module ][0] += $n;
                    ++$seen[ $module ][1];
                }
            }
        }

        return $seen;
    }

    /**
     * What one block is to this adapter: a carrier with its role (marked or by its decoration), or null.
     *
     * @param array<string,mixed> $attrs
     *
     * @return ?array{role:string,marked:bool}
     */
    private static function roleOf( string $block, array $attrs ): ?array
    {
        if ( ! isset( self::CARRIER_ROLES[ $block ] ) ) {
            return null;
        }
        $kit = is_array( $attrs['aied'] ?? null ) ? ( $attrs['aied']['kit'] ?? null ) : null;
        if ( is_string( $kit ) && in_array( $kit, self::CARRIER_ROLES[ $block ], true ) ) {
            return [ 'role' => $kit, 'marked' => true ];
        }
        $dec = $attrs['module']['decoration'] ?? [];
        $dec = is_array( $dec ) ? $dec : [];

        switch ( $block ) {
            case 'divi/divider':
                $line = $attrs['divider']['advanced']['line']['desktop']['value'] ?? null;
                if ( ! is_array( $line ) || 'off' === ( $line['show'] ?? null ) ) {
                    return null;
                }
                return 'accent' === self::colourRole( $line['color'] ?? null ) ? [ 'role' => 'rule-accent', 'marked' => false ] : null;

            case 'divi/testimonial':
                return self::fillsColour( $dec ) ? [ 'role' => 'panel', 'marked' => false ] : null;

            case 'divi/blurb':
                $bg = $attrs['imageIcon']['decoration']['background']['desktop']['value']['color'] ?? null;
                return is_string( $bg ) && '' !== $bg ? [ 'role' => 'chip', 'marked' => false ] : null;

            default: // divi/number-counter
                $styles = $dec['border']['desktop']['value']['styles'] ?? null;
                $px     = static fn ( $v ): float => is_string( $v ) || is_numeric( $v ) ? (float) $v : 0.0;
                if ( ! is_array( $styles ) || ! isset( $styles['all'], $styles['top'] )
                    || 0.0 !== $px( $styles['all']['width'] ?? null ) || $px( $styles['top']['width'] ?? 0 ) <= 0 || self::fillsColour( $dec ) ) {
                    return null;
                }
                $colour = self::colourRole( $styles['top']['color'] ?? $styles['all']['color'] ?? null );
                if ( null === $colour ) {
                    throw new \RuntimeException( 'a top rule on a counter with a colour that is not a colour token' );
                }
                // Ruling 4 / Spec issue 6: `border` or any alpha token is a quiet rule, every other colour is strong.
                return [ 'role' => 'border' === $colour || str_contains( $colour, ':' ) ? 'rule' : 'rule-strong', 'marked' => false ];
        }
    }

    /** The role part of a `{{aied:color:<role>[:NN]}}` token (`accent-text:40`), or null for anything else. */
    private static function colourRole( mixed $value ): ?string
    {
        return is_string( $value ) && 1 === preg_match( '/^\{\{aied:color:([a-z-]+(?::[1-9][0-9]?)?)\}\}$/', $value, $m ) ? $m[1] : null;
    }

    /**
     * Does `module.decoration.background` paint a colour?
     *
     * @param array<string,mixed> $dec
     */
    private static function fillsColour( array $dec ): bool
    {
        $c = $dec['background']['desktop']['value']['color'] ?? null;

        return is_string( $c ) && '' !== $c;
    }
}
