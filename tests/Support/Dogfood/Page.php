<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support\Dogfood;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;
use Divi5Validator\Tests\Support\Colour;

require_once __DIR__ . '/../Colour.php';

/**
 * The dogfood scorer's view of a page (plan 2026-10-22, Task 2): the block tree of a Divi 5 page in document order, with the
 * band every block sits on. Pure and deterministic; reads nothing but the markup and the language it is given. Dev tooling:
 * never shipped in `wp-plugin/`.
 *
 * Bands (plan D3). A section, row, column or group that paints a background makes a band for everything inside it, and the
 * NEAREST painting ancestor wins. The band a background paints is found by a reverse lookup of its colour in the language's
 * palette, with the fixed priority `bg, surface, surface-glass, accent, text, overlay, inverse` (the first role whose
 * resolved value equals the colour): `bg` is `light`, `surface` and `surface-glass` are `surface`, `accent` is `accent`,
 * `text` is `panel`, `overlay` and `inverse` are `dark`. A background image, or a gradient with an `overlay` stop, is `dark`;
 * any other colour or gradient is `unknown`. A block under no painting ancestor sits on `light` (the page's own ground).
 */
final class Page
{
    /** The reverse-lookup order of D3: palette role => [the language colour key, the band it paints]. */
    private const PALETTE = [
        'bg'            => [ 'bg', 'light' ],
        'surface'       => [ 'surface', 'surface' ],
        'surface-glass' => [ 'surface_glass', 'surface' ],
        'accent'        => [ 'accent', 'accent' ],
        'text'          => [ 'text', 'panel' ],
        'overlay'       => [ 'overlay', 'dark' ],
        'inverse'       => [ 'inverse', 'dark' ],
    ];

    /** The band of a block under no painting ancestor. */
    public const GROUND = 'light';

    /**
     * Document order, the top-level placeholder root left out.
     *
     * @var list<array{key:string,name:string,attrs:array<string,mixed>,parent:?int,desc:array<string,true>}>
     */
    private array $nodes = [];

    /** key => index in $nodes. */
    private array $index = [];

    private function __construct( Block $root )
    {
        $seen = [];
        foreach ( $root->children() as $top ) {
            $this->collect( $top, null, true, $seen );
        }
    }

    /**
     * Parses with \Divi5Validator\BlockParser; wraps in a placeholder root unless the trimmed page already starts with one.
     *
     * @throws \InvalidArgumentException when the parse is not ok
     */
    public static function parse( string $markup ): self
    {
        $page = ltrim( $markup );
        if ( ! str_starts_with( $page, '<!-- wp:divi/placeholder' ) ) {
            $page = '<!-- wp:divi/placeholder -->' . $page . "\n<!-- /wp:divi/placeholder -->";
        }
        $result = ( new BlockParser() )->parse( $page );
        $root   = $result->root();
        if ( ! $result->isOk() || null === $root ) {
            throw new \InvalidArgumentException( 'The page does not parse as Divi blocks: ' . implode( ' ', array_slice( $result->errors(), 0, 3 ) ) );
        }

        return new self( $root );
    }

    /**
     * Every block in document order. key = "<name>#<index among blocks of that name>" (the top-level placeholder is not listed);
     * band = the band the block sits on (the nearest painting ancestor's, never the block's own paint); parentBand = the band
     * of the ground the block's nearest painting ancestor-or-self lies on. A block's own paint never changes the band it sits
     * on, so this equals `band` for every block: it is kept because the top-rule check names it, and it reads only values of
     * the rule roles that do not depend on the band; hasContent = a divi/heading or divi/button descendant.
     *
     * @param array<string,mixed> $language a design language as DesignLanguages::get() returns it
     *
     * @return list<array{key:string,name:string,attrs:array<string,mixed>,band:string,parentBand:string,hasContent:bool}>
     */
    public function blocks( array $language ): array
    {
        $palette = self::palette( $language );
        $out     = [];
        $ctx     = [];
        foreach ( $this->nodes as $i => $node ) {
            $in      = null === $node['parent'] ? self::GROUND : $ctx[ $node['parent'] ];
            $ctx[ $i ] = self::bandIn( $node['attrs'], $palette ) ?? $in;
            $out[]   = [
                'key'        => $node['key'],
                'name'       => $node['name'],
                'attrs'      => $node['attrs'],
                'band'       => $in,
                'parentBand' => $in,
                'hasContent' => isset( $node['desc']['divi/heading'] ) || isset( $node['desc']['divi/button'] ),
            ];
        }

        return $out;
    }

    /**
     * The names of the blocks inside the block with this key (any depth).
     *
     * @return list<string>
     */
    public function descendants( string $key ): array
    {
        $i = $this->index[ $key ] ?? null;

        return null === $i ? [] : array_keys( $this->nodes[ $i ]['desc'] );
    }

    /**
     * The band a background attribute paints (D3), or null when it paints nothing.
     *
     * @param array<string,mixed> $attrs
     * @param array<string,mixed> $language
     */
    public static function bandOf( array $attrs, array $language ): ?string
    {
        return self::bandIn( $attrs, self::palette( $language ) );
    }

    /**
     * A colour as the key "r,g,b,a" (alpha rounded to 2 decimals, so `#FFF`, `#ffffff` and `rgb(255,255,255)` are one colour),
     * or null when it cannot be read. `transparent` is "0,0,0,0".
     */
    public static function colourKey( string $colour ): ?string
    {
        $c = strtolower( trim( $colour ) );
        if ( 'transparent' === $c ) {
            return '0,0,0,0';
        }
        $rgba = Colour::rgba( $c );
        if ( null === $rgba ) {
            if ( 1 === preg_match( '/^#([0-9a-f]{8}|[0-9a-f]{4})$/', $c, $m ) ) {
                $h = 4 === strlen( $m[1] ) ? $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] . $m[1][3] . $m[1][3] : $m[1];
                $rgba = [ hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ), hexdec( substr( $h, 6, 2 ) ) / 255 ];
            } elseif ( 1 === preg_match( '/^rgba?\(\s*(\d+)\s+(\d+)\s+(\d+)\s*(?:\/\s*([0-9.]+)\s*)?\)$/', $c, $m ) ) {
                $rgba = [ (int) $m[1], (int) $m[2], (int) $m[3], isset( $m[4] ) && '' !== $m[4] ? (float) $m[4] : 1.0 ];
            }
        }
        if ( null === $rgba || max( $rgba[0], $rgba[1], $rgba[2] ) > 255 ) {
            return null;
        }
        $alpha = round( min( 1.0, max( 0.0, (float) $rgba[3] ) ), 2 );

        return sprintf( '%d,%d,%d,%s', $rgba[0], $rgba[1], $rgba[2], rtrim( rtrim( sprintf( '%.2f', $alpha ), '0' ), '.' ) );
    }

    /** Does this colour string paint nothing (empty, `transparent` or alpha 0)? An unreadable colour is assumed to paint. */
    public static function isClear( mixed $colour ): bool
    {
        if ( ! is_string( $colour ) || '' === trim( $colour ) ) {
            return true;
        }
        $key = self::colourKey( $colour );

        return null !== $key && str_ends_with( $key, ',0' );
    }

    /**
     * @param array<string,int> $seen the count of blocks seen so far, by name
     *
     * @return array<string,true> the names of this block's subtree, itself included
     */
    private function collect( Block $b, ?int $parent, bool $top, array &$seen ): array
    {
        $name = $b->name();
        if ( $top && 'divi/placeholder' === $name ) {
            $subtree = [];
            foreach ( $b->children() as $child ) {
                $subtree += $this->collect( $child, null, false, $seen );
            }

            return $subtree;
        }
        $seen[ $name ] = ( $seen[ $name ] ?? -1 ) + 1;
        $i             = count( $this->nodes );
        $key           = $name . '#' . $seen[ $name ];
        $this->nodes[ $i ] = [ 'key' => $key, 'name' => $name, 'attrs' => $b->attrs(), 'parent' => $parent, 'desc' => [] ];
        $this->index[ $key ] = $i;
        $desc = [];
        foreach ( $b->children() as $child ) {
            $desc += $this->collect( $child, $i, false, $seen );
        }
        $this->nodes[ $i ]['desc'] = $desc;

        return $desc + [ $name => true ];
    }

    /**
     * @param array<string,mixed> $language
     *
     * @return list<array{0:string,1:string,2:string}> [colour key, band, role] in the D3 priority
     */
    private static function palette( array $language ): array
    {
        $colors = isset( $language['colors'] ) && is_array( $language['colors'] ) ? $language['colors'] : [];
        $out    = [];
        foreach ( self::PALETTE as $role => [ $key, $band ] ) {
            $value = $colors[ $key ] ?? null;
            if ( ( ! is_string( $value ) || '' === $value ) && 'surface_glass' === $key ) {
                $value = $colors['surface'] ?? null; // RecipeTokens' own fallback
            }
            $ck = is_string( $value ) ? self::colourKey( $value ) : null;
            if ( null !== $ck ) {
                $out[] = [ $ck, $band, $role ];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed>      $attrs
     * @param list<array{0:string,1:string,2:string}> $palette
     */
    private static function bandIn( array $attrs, array $palette ): ?string
    {
        $v = $attrs['module']['decoration']['background']['desktop']['value'] ?? null;
        if ( ! is_array( $v ) ) {
            return null;
        }
        $image = $v['image']['url'] ?? '';
        if ( is_string( $image ) && '' !== $image ) {
            return 'dark';
        }
        $grad = $v['gradient'] ?? null;
        if ( is_array( $grad ) && 'on' === ( $grad['enabled'] ?? null ) ) {
            $overlay = null;
            foreach ( $palette as [ $ck, , $role ] ) {
                if ( 'overlay' === $role ) {
                    $overlay = $ck;
                }
            }
            foreach ( (array) ( $grad['stops'] ?? [] ) as $stop ) {
                $colour = is_array( $stop ) ? ( $stop['color'] ?? null ) : null;
                $ck     = is_string( $colour ) ? self::colourKey( $colour ) : null;
                if ( null !== $ck && $ck === $overlay ) {
                    return 'dark';
                }
            }

            return 'unknown';
        }
        $colour = $v['color'] ?? null;
        if ( self::isClear( $colour ) ) {
            return null;
        }
        $ck = self::colourKey( (string) $colour );
        if ( null === $ck ) {
            return 'unknown';
        }
        foreach ( $palette as [ $pk, $band ] ) {
            if ( $pk === $ck ) {
                return $band;
            }
        }

        return 'unknown';
    }
}
