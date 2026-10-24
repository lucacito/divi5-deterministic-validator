<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

/**
 * Dev/test-side audit of the section padding in the section recipes (rhythm adapter, plan 2026-10-24, Task 4): which
 * `divi/section` blocks are an ordinary section, an open band or a tight band, and which stay literal with a named
 * reason. Never shipped, and it loads no plugin class, so the migration report script can use it without a site.
 *
 * A section is found by its own padding or by what it SAYS, so the table is the same before and after the migration
 * (Task 5):
 *
 *   unmigrated   found by its padding (the classification rule below)
 *   migrated     found by its `"aied":{"rhythm":"section|open|tight"}` marker (its own padding is gone)
 *
 * The classification rule (measured at 2b40d59, plan header "Classification rule"). A section is a rhythm candidate only
 * when `module.decoration.spacing` holds exactly `desktop`, `tablet` and `phone`, each with a `value` and nothing else (no
 * hover or sticky state), whose `padding` has `top` equal to `bottom`, both `Npx` with N > 0 (an optional `syncVertical`
 * is allowed; a left, right, margin or any other key is not), at ALL THREE breakpoints. Its role is the one whose factor
 * (section 1, open 1.25, tight 0.6) is nearest, in ratio (|ln((desktop / BASE) / factor)|), to its desktop value over
 * BASE = 112 px, the library's own section value. A desktop value outside BAND = [0.45, 1.6] x BASE (51 to 179 px) is
 * near no role, so it is a candidate for nothing. Everything else is a named EXEMPTION, one reason per recipe, and a
 * section that is neither classified nor exempt has `role` null and `exempt` null, which the tests fail on.
 */
final class RhythmAudit
{
    /** The library's own section value in px: the ratio base of the role rule. */
    public const BASE = 112;

    /** [ lowest, highest ] desktop ratio to BASE that any role can claim. */
    public const BAND = [ 0.45, 1.6 ];

    /** The role factors of spec section 1 (Ruling 8). */
    public const FACTORS = [ 'section' => 1.0, 'open' => 1.25, 'tight' => 0.6 ];

    /** The rhythm roles a marker may name. */
    public const ROLES = [ 'section', 'open', 'tight' ];

    /**
     * recipe => `<class>: why this section stays literal`. Every recipe has exactly one section (index 0). The class is
     * the text before the first colon (reasonClass()).
     *
     * @var array<string,string>
     */
    public const EXEMPTIONS = [
        // Ruling 10: a percentage or viewport unit is not a px rhythm.
        'hero-cta'                 => 'percent or vw: 8% on desktop only; a relative unit the px rhythm cannot replace',
        'card-grid-4'              => 'percent or vw: 8vw on desktop only; a viewport unit the px rhythm cannot replace',
        // Ruling 10, anti-goal 4: a 0px edge is a design decision, not a rhythm value.
        'hero-image-scrim'         => '0px edge: 0 top and bottom, the photo fills the section',
        'image-gallery'            => '0px edge: 0 top and 100 bottom, flush with the section above',
        'transition-overlap-panel' => '0px edge: 0 top, the panel is pulled over the band above',
        // Asymmetric: top and bottom differ on purpose.
        'section-intro'            => 'asymmetric: 16px top only',
        'hero-editorial-minimal'   => 'asymmetric: 180 top, 140 bottom',
        'hero-product-glow'        => 'asymmetric: 120 top, 96 bottom',
        'header-title-band'        => 'asymmetric: 72 top, 64 bottom',
        'header-divider-intro'     => 'asymmetric: 112 top, 168 bottom, the room under it holds a 100px curve divider',
        'header-image-band'        => 'asymmetric: 120 top, 104 bottom',
        // The old alpha shape: no desktop padding, 0px on phones.
        'blurb-grid'               => 'old alpha shape: no desktop padding, 0px on phones',
        'icon-features'            => 'old alpha shape: no desktop padding, 0px on phones',
        'testimonial'              => 'old alpha shape: no desktop padding, 0px on phones',
        // No padding: Divi\'s default (56px at every width); a marker would change a recipe that never chose a rhythm.
        'card-grid-3'              => 'no padding: Divi\'s own default, which the recipe never chose to replace',
        'split-image-text'         => 'no padding: Divi\'s own default, which the recipe never chose to replace',
        'contact-form'             => 'no padding: Divi\'s own default, which the recipe never chose to replace',
        'slider'                   => 'no padding: Divi\'s own default, which the recipe never chose to replace',
        'image-carousel'           => 'no padding: Divi\'s own default, which the recipe never chose to replace',
        'newsletter-social'        => 'no padding: Divi\'s own default, which the recipe never chose to replace',
        'shop-grid'                => 'no padding: Divi\'s own default, which the recipe never chose to replace',
        // Desktop only: Divi cascades the value to tablet and phone.
        'stats-counter'            => 'desktop only: 40px, cascaded to tablet and phone',
        'blog-feed'                => 'desktop only: 100px, cascaded to tablet and phone',
        'icon-values'              => 'desktop only: 100px, cascaded to tablet and phone',
        // Out of band.
        'proof-rating-strip'       => 'out of band: 32/28/28px, a hairline strip thinner than any tight value a language gives (56/32/28 at least)',
    ];

    /** The exemptions only shrink: a new one is a conscious decision. */
    public const EXEMPTIONS_MAX = 25;

    private const BREAKPOINTS = [ 'desktop', 'tablet', 'phone' ];

    private const BLOCK_REGEX = '/<!--\s+(\/?)wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+(\/?)-->/s';

    /**
     * Every section of the recipes, in recipe and document order.
     *
     * `desktop`, `tablet` and `phone` are the px values of a candidate (null for any other section, a marked one included
     * once its own padding is gone). `raw` is what each breakpoint's padding says as text (for the report): the value when
     * top equals bottom, `top~bottom` when they differ, `-` for none.
     *
     * @param list<array{name:string,markup:string}> $recipes the rows of section-recipes.json
     *
     * @return list<array{recipe:string,index:int,desktop:?int,tablet:?int,phone:?int,role:?string,marked:bool,exempt:?string,raw:array{desktop:string,tablet:string,phone:string}}>
     */
    public static function sections( array $recipes ): array
    {
        $rows = [];
        foreach ( $recipes as $recipe ) {
            $name  = (string) $recipe['name'];
            $index = -1;
            preg_match_all( self::BLOCK_REGEX, (string) $recipe['markup'], $found, PREG_SET_ORDER );
            foreach ( $found as $b ) {
                if ( '/' === $b[1] || 'divi/section' !== $b[2] ) {
                    continue; // a closing comment, or another block
                }
                ++$index;
                $attrs = null === ( $b[3] ?? null ) || '' === $b[3] ? [] : json_decode( $b[3], true );
                if ( ! is_array( $attrs ) ) {
                    throw new \RuntimeException( "$name: the attributes of a divi/section block are not valid JSON." );
                }
                $rows[] = self::row( $name, $index, $attrs );
            }
        }

        return $rows;
    }

    /** The key of a row in EXEMPTIONS terms: recipe|divi/section|index. */
    public static function key( array $row ): string
    {
        return $row['recipe'] . '|divi/section|' . $row['index'];
    }

    /** The class of an exemption reason: the text before the first colon. */
    public static function reasonClass( string $reason ): string
    {
        $pos = strpos( $reason, ':' );

        return false === $pos ? $reason : substr( $reason, 0, $pos );
    }

    /**
     * The role whose factor is nearest, in ratio, to a desktop padding over BASE, or null when the value is not inside
     * BAND (or is not positive). A tie cannot happen between the three factors at an integer value.
     */
    public static function role( int $desktop ): ?string
    {
        if ( $desktop <= 0 ) {
            return null;
        }
        $ratio = $desktop / self::BASE;
        if ( $ratio < self::BAND[0] || $ratio > self::BAND[1] ) {
            return null;
        }
        $best      = null;
        $bestError = INF;
        foreach ( self::FACTORS as $role => $factor ) {
            $error = abs( log( $ratio / $factor ) );
            if ( $error < $bestError ) {
                $bestError = $error;
                $best      = $role;
            }
        }

        return $best;
    }

    /**
     * @param array<string,mixed> $attrs
     *
     * @return array{recipe:string,index:int,desktop:?int,tablet:?int,phone:?int,role:?string,marked:bool,exempt:?string,raw:array{desktop:string,tablet:string,phone:string}}
     */
    private static function row( string $recipe, int $index, array $attrs ): array
    {
        $marker  = self::marker( "$recipe divi/section#$index", $attrs );
        $spacing = $attrs['module']['decoration']['spacing'] ?? null;
        $spacing = is_array( $spacing ) ? $spacing : [];
        $raw     = [];
        foreach ( self::BREAKPOINTS as $bp ) {
            $raw[ $bp ] = self::rawPadding( $spacing[ $bp ] ?? null );
        }
        $px = self::candidate( $spacing );
        $row = [
            'recipe'  => $recipe,
            'index'   => $index,
            'desktop' => $px['desktop'] ?? null,
            'tablet'  => $px['tablet'] ?? null,
            'phone'   => $px['phone'] ?? null,
            'role'    => $marker ?? ( null === $px ? null : self::role( $px['desktop'] ) ),
            'marked'  => null !== $marker,
            'exempt'  => null,
            'raw'     => $raw,
        ];
        if ( null === $row['role'] && 0 === $index && isset( self::EXEMPTIONS[ $recipe ] ) ) {
            $row['exempt'] = self::EXEMPTIONS[ $recipe ];
        }

        return $row;
    }

    /**
     * The px values of a candidate's three breakpoints, or null when the spacing is not a candidate shape (the rule in the
     * class docblock).
     *
     * @param array<string,mixed> $spacing
     *
     * @return array{desktop:int,tablet:int,phone:int}|null
     */
    private static function candidate( array $spacing ): ?array
    {
        if ( [ 'desktop', 'phone', 'tablet' ] !== self::sortedKeys( $spacing ) ) {
            return null;
        }
        $px = [];
        foreach ( self::BREAKPOINTS as $bp ) {
            $one = $spacing[ $bp ];
            if ( ! is_array( $one ) || [ 'value' ] !== array_keys( $one ) || ! is_array( $one['value'] ) || [ 'padding' ] !== array_keys( $one['value'] ) ) {
                return null; // a state, a margin, or anything but the padding
            }
            $padding = $one['value']['padding'];
            if ( ! is_array( $padding ) || ! isset( $padding['top'], $padding['bottom'] ) ) {
                return null;
            }
            foreach ( array_keys( $padding ) as $key ) {
                if ( ! in_array( $key, [ 'top', 'bottom', 'syncVertical' ], true ) ) {
                    return null; // left, right, syncHorizontal ...
                }
            }
            if ( ! is_string( $padding['top'] ) || ! is_string( $padding['bottom'] ) || $padding['top'] !== $padding['bottom'] || 1 !== preg_match( '/^([1-9][0-9]*)px$/', $padding['top'], $m ) ) {
                return null;
            }
            $px[ $bp ] = (int) $m[1];
        }

        return $px;
    }

    /** @param array<mixed> $a @return list<string> */
    private static function sortedKeys( array $a ): array
    {
        $keys = array_map( 'strval', array_keys( $a ) );
        sort( $keys );

        return $keys;
    }

    /** What one breakpoint's padding says, as text: the value when top = bottom, `top~bottom` otherwise, `-` for none. */
    private static function rawPadding( mixed $one ): string
    {
        $padding = is_array( $one ) ? ( $one['value']['padding'] ?? null ) : null;
        if ( ! is_array( $padding ) ) {
            return '-';
        }
        $top    = is_string( $padding['top'] ?? null ) && '' !== $padding['top'] ? $padding['top'] : '-';
        $bottom = is_string( $padding['bottom'] ?? null ) && '' !== $padding['bottom'] ? $padding['bottom'] : '-';

        return $top === $bottom ? $top : "$top~$bottom";
    }

    /**
     * The role of a section's rhythm marker, null when it has none (a marker of another kind is not a rhythm marker). A
     * marker that names `rhythm` with any other key, or a value that is not one of ROLES, throws (fail closed).
     *
     * @param array<string,mixed> $attrs
     */
    private static function marker( string $where, array $attrs ): ?string
    {
        $aied = $attrs['aied'] ?? null;
        if ( ! is_array( $aied ) || ! array_key_exists( 'rhythm', $aied ) ) {
            return null;
        }
        $role = $aied['rhythm'];
        if ( 1 !== count( $aied ) || ! is_string( $role ) || ! in_array( $role, self::ROLES, true ) ) {
            throw new \RuntimeException( "$where: a rhythm marker must be exactly {\"rhythm\":\"section|open|tight\"}." );
        }

        return $role;
    }
}
