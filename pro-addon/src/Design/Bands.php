<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Design;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The band a block's OWN background paints, read by a reverse lookup in a design language's palette (spec
 * 2026-10-25-pro-design-switch-design.md section 5.1, the dogfood scorer's rule D3). Pure and deterministic.
 *
 * This is a PORT, not shared code: the dev-only scorer keeps its own copy and `BandsTest` pins the two equal on every
 * block of the recipe library and the dogfood corpus. Sources ported (main 6d8ae9a):
 * - `tests/Support/Dogfood/Page.php` `PALETTE` (lines 28-36), `palette()` (lines 207-223), `bandIn()` (lines 229-272)
 *   and `colourKey()` / `isClear()` (lines 138-170);
 * - `tests/Support/Colour.php` `Colour::rgba` (lines 18-32), which `colourKey()` calls first (here `channels`).
 *
 * The rule: a block's background value (module, decoration, background, desktop, value) with an image url paints
 * `dark`; an enabled gradient paints `dark` when one stop is the language's overlay colour, else `unknown`; a colour
 * that paints nothing (empty, transparent, alpha 0) is no paint (null); any other colour is looked up in the palette with
 * the fixed priority bg, surface, surface-glass, accent, text, overlay, inverse (`light`, `surface`, `surface`, `accent`,
 * `panel`, `dark`, `dark`); a colour in no role, or one that cannot be read, is `unknown`.
 */
final class Bands
{
    /** The band of a block under no painting ancestor (the page's own ground). */
    public const GROUND = 'light';

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

    /**
     * The band a block's own background paints, or null when it paints nothing.
     *
     * @param array<string,mixed> $attrs    the block's decoded attributes (associative arrays)
     * @param array<string,mixed> $language a design language as DesignLanguages::get() returns it
     */
    public static function of( array $attrs, array $language ): ?string
    {
        return self::paint( $attrs, self::palette( $language ) );
    }

    /**
     * The palette of D3 in its priority order, for repeated `paint()` calls on one page.
     *
     * @param array<string,mixed> $language
     *
     * @return list<array{0:string,1:string,2:string}> [colour key, band, role]
     */
    public static function palette( array $language ): array
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
     * `of()` with a palette from `palette()`.
     *
     * @param array<string,mixed>                     $attrs
     * @param list<array{0:string,1:string,2:string}> $palette
     */
    public static function paint( array $attrs, array $palette ): ?string
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

    /**
     * A colour as the key "r,g,b,a" (alpha rounded to 2 decimals, so a three-digit hex, its six-digit form and the same
     * rgb() function are one colour), or null when it cannot be read. `transparent` is "0,0,0,0". Reads three-, four-,
     * six- and eight-digit hex and the comma and space forms of the rgb/rgba functions; a channel above 255 is unreadable.
     */
    public static function colourKey( string $colour ): ?string
    {
        $c = strtolower( trim( $colour ) );
        if ( 'transparent' === $c ) {
            return '0,0,0,0';
        }
        $rgba = self::channels( $c );
        if ( null === $rgba ) {
            if ( 1 === preg_match( '/^#([0-9a-f]{8}|[0-9a-f]{4})$/', $c, $m ) ) {
                $h    = 4 === strlen( $m[1] ) ? $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] . $m[1][3] . $m[1][3] : $m[1];
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

    /** Does this colour paint nothing (not a string, empty, `transparent` or alpha 0)? An unreadable colour is assumed to paint. */
    public static function isClear( mixed $colour ): bool
    {
        if ( ! is_string( $colour ) || '' === trim( $colour ) ) {
            return true;
        }
        $key = self::colourKey( $colour );

        return null !== $key && str_ends_with( $key, ',0' );
    }

    /**
     * A three- or six-digit hex or a comma-form rgb/rgba function as [r, g, b, a], or null (the scorer's `Colour::rgba`).
     *
     * @return array{0:int|float,1:int|float,2:int|float,3:float}|null
     */
    private static function channels( string $c ): ?array
    {
        $c = trim( $c );
        if ( 1 === preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $c, $m ) ) {
            $h = $m[1];
            if ( 3 === strlen( $h ) ) {
                $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            }

            return [ hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ), 1.0 ];
        }
        if ( 1 === preg_match( '/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([0-9.]+)\s*)?\)$/i', $c, $m ) ) {
            return [ (int) $m[1], (int) $m[2], (int) $m[3], isset( $m[4] ) ? (float) $m[4] : 1.0 ];
        }

        return null;
    }
}
