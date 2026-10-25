<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

/**
 * Dev/test-side audit of the headings and labels in the section recipes (type adapter, plan 2026-10-24, Task 4): which
 * blocks are a display headline, a title or an eyebrow label. Never shipped, and it loads no plugin class, so the
 * migration report script can use it without a site.
 *
 * A block is found by what it IS or by what it SAYS, so the table is the same before and after the migration (Task 5):
 *
 *   unmigrated   found by its level and font (the classification rule below)
 *   migrated     found by its `"aied":{"type":"display|title|eyebrow"}` marker (the weight, tracking and capitals are gone)
 *
 * Which blocks are listed: every `divi/heading`, every `divi/text` whose desktop body font says `uppercase` and every block
 * that carries a type marker (`$allTexts` adds every other `divi/text`). `index` is the block's order among ALL blocks of
 * its NAME in the recipe (CardReport numbering, the numbering of RecipeLibraryTest::markerBands).
 *
 * The classification rule (measured at 77abc24, plan header "Classification rule"):
 *
 *   divi/heading   h1 or h2 + the display font token = display; h3 + display = title; h4 + the eyebrow font token = eyebrow
 *   divi/text      uppercase + the eyebrow font token = eyebrow
 *
 * Anything else has no role. A heading or an uppercase text the rule cannot place is either one of the named EXEMPTIONS
 * (it stays literal, with a reason) or unclassified (`role` null, `exempt` null), which the tests fail on. A marked block
 * says its role itself; a marker that is not exactly one `type` key naming a role this block takes throws (fail closed).
 */
final class TypeAudit
{
    /**
     * recipe|block|index => why this block stays literal although it is a heading or an uppercase label.
     *
     * @var array<string,string>
     */
    public const EXEMPTIONS = [
        'shop-grid|divi/heading|0'          => 'no headingLevel: Divi\'s own default level, which no probe or export of this adapter covers; the shop grid\'s own title',
        'proof-press-quotes|divi/text|0'    => 'a publication name set in the display face as a masthead mark, not an eyebrow label',
        'proof-press-quotes|divi/text|3'    => 'a publication name set in the display face as a masthead mark, not an eyebrow label',
        'proof-press-quotes|divi/text|6'    => 'a publication name set in the display face as a masthead mark, not an eyebrow label',
    ];

    /** The exemptions only shrink: a new one is a conscious decision. */
    public const EXEMPTIONS_MAX = 4;

    /** The type roles, and the block names a marker with each role may sit on. */
    public const ROLES = [ 'display', 'title', 'eyebrow' ];

    private const BLOCK_REGEX = '/<!--\s+(\/?)wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+(\/?)-->/s';

    /**
     * Every heading and label of the recipes, in recipe and document order.
     *
     * @param list<array{name:string,markup:string}> $recipes   the rows of section-recipes.json
     * @param bool                                   $allTexts  true also lists every `divi/text` that is not an uppercase label
     *
     * @return list<array{recipe:string,block:string,index:int,level:?string,font:?string,role:?string,marked:bool,exempt:?string}>
     */
    public static function blocks( array $recipes, bool $allTexts = false ): array
    {
        $rows = [];
        foreach ( $recipes as $recipe ) {
            $name = (string) $recipe['name'];
            preg_match_all( self::BLOCK_REGEX, (string) $recipe['markup'], $found, PREG_SET_ORDER );
            $seen = [];
            foreach ( $found as $b ) {
                if ( '/' === $b[1] ) {
                    continue; // a closing comment
                }
                $block        = $b[2];
                $seen[ $block ] = ( $seen[ $block ] ?? -1 ) + 1;
                if ( 'divi/heading' !== $block && 'divi/text' !== $block ) {
                    continue;
                }
                $attrs = null === ( $b[3] ?? null ) || '' === $b[3] ? [] : json_decode( $b[3], true );
                if ( ! is_array( $attrs ) ) {
                    throw new \RuntimeException( "$name: the attributes of a $block block are not valid JSON." );
                }
                $row = self::row( $name, $block, $seen[ $block ], $attrs );
                if ( 'divi/text' === $block && ! $allTexts && ! $row['marked'] && 'uppercase' !== $row['caps'] ) {
                    continue;
                }
                unset( $row['caps'] );
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** The key of a row in EXEMPTIONS: recipe|block|index. */
    public static function key( array $row ): string
    {
        return $row['recipe'] . '|' . $row['block'] . '|' . $row['index'];
    }

    /**
     * @param array<string,mixed> $attrs
     *
     * @return array{recipe:string,block:string,index:int,level:?string,font:?string,role:?string,marked:bool,exempt:?string,caps:?string}
     */
    private static function row( string $recipe, string $block, int $index, array $attrs ): array
    {
        $isHeading = 'divi/heading' === $block;
        $value     = $isHeading
            ? ( $attrs['title']['decoration']['font']['font']['desktop']['value'] ?? [] )
            : ( $attrs['content']['decoration']['bodyFont']['body']['font']['desktop']['value'] ?? [] );
        $value  = is_array( $value ) ? $value : [];
        $level  = $isHeading && is_string( $value['headingLevel'] ?? null ) ? $value['headingLevel'] : null;
        $font   = self::fontOf( $value['family'] ?? null );
        $caps   = is_string( $value['capitalization'] ?? null ) ? $value['capitalization'] : null;
        $marker = self::marker( "$recipe $block#$index", $block, $attrs );
        $role   = $marker;
        if ( null === $role ) {
            $role = $isHeading ? self::headingRole( $level, $font ) : ( 'uppercase' === $caps && 'eyebrow' === $font ? 'eyebrow' : null );
        }
        $row = [
            'recipe' => $recipe,
            'block'  => $block,
            'index'  => $index,
            'level'  => $level,
            'font'   => $font,
            'role'   => $role,
            'marked' => null !== $marker,
            'exempt' => null,
            'caps'   => $caps,
        ];
        if ( null === $role && isset( self::EXEMPTIONS[ self::key( $row ) ] ) ) {
            $row['exempt'] = self::EXEMPTIONS[ self::key( $row ) ];
        }

        return $row;
    }

    /** The role a heading's level and font place it in, or null. */
    private static function headingRole( ?string $level, ?string $font ): ?string
    {
        return match ( true ) {
            in_array( $level, [ 'h1', 'h2' ], true ) && 'display' === $font => 'display',
            'h3' === $level && 'display' === $font                         => 'title',
            'h4' === $level && 'eyebrow' === $font                         => 'eyebrow',
            default                                                        => null,
        };
    }

    /** The font role of a `{{aied:font:<role>}}` token, the literal family otherwise, null when there is none. */
    private static function fontOf( mixed $family ): ?string
    {
        if ( ! is_string( $family ) || '' === $family ) {
            return null;
        }

        return 1 === preg_match( '/^\{\{aied:font:([a-z-]+)\}\}$/', $family, $m ) ? $m[1] : $family;
    }

    /**
     * The role of a block's type marker, null when it has none (a kit marker is not a type marker). A marker with any key
     * besides `type`, a role that is not one of ROLES, or a role this block does not take throws.
     *
     * @param array<string,mixed> $attrs
     */
    private static function marker( string $where, string $block, array $attrs ): ?string
    {
        $aied = $attrs['aied'] ?? null;
        if ( ! is_array( $aied ) || ! array_key_exists( 'type', $aied ) ) {
            return null;
        }
        $role = $aied['type'];
        if ( 1 !== count( $aied ) || ! is_string( $role ) || ! in_array( $role, self::ROLES, true ) ) {
            throw new \RuntimeException( "$where: a type marker must be exactly {\"type\":\"display|title|eyebrow\"}." );
        }
        if ( 'divi/text' === $block && 'eyebrow' !== $role ) {
            throw new \RuntimeException( "$where: a divi/text takes the eyebrow role only, not \"$role\"." );
        }

        return $role;
    }
}
