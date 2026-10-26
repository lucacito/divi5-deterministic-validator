<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Design;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One pass over the block comments of a Divi 5 page (spec 2026-10-25-pro-design-switch-design.md section 5.1): every
 * OPENING block comment in document order, with the byte span of its attribute JSON, its parent and the band it sits on.
 * Pure and deterministic: reads nothing but the markup and the language it is given; never throws.
 *
 * Block comments are read the way the block grammar writes them: `<!--`, whitespace, an optional `/` (a closer),
 * `wp:` and a block name, whitespace, an optional attribute object followed by whitespace, an optional `/` (self-closing)
 * and `-->`. The attribute object is delimited by a real JSON tokenizer (`JsonLeaves::objectEnd`), so a string holding
 * `-->` or `}` does not end it; text that is not a valid object falls back to the free scanner's rule
 * (`BlockAttrs::BLOCK`: the first `}` followed by the comment's end), so its span is the one the free resolvers would
 * see, and its attributes do not decode. Any other comment is not a block and is skipped.
 *
 * Bands (the dogfood scorer's rule D3, `tests/Support/Dogfood/Page.php::blocks()`): a block sits on the band of its
 * nearest painting ancestor (`Bands::paint` of that ancestor's own attributes, in the given language's palette), and on
 * `Bands::GROUND` under none. A block's own paint never changes the band it sits on. Closing comments pop the stack; a
 * closer whose name is not the innermost open block's is ignored (as the validator's BlockParser does), and a closer with
 * nothing open is ignored: such a page is scanned to the end and `balanced()` says false.
 */
final class PageScan
{
    /** `<!--` + whitespace + optional closer slash + `wp:` + a block name (with or without a namespace). */
    private const HEAD = '/\G<!--\s+(\/)?wp:((?:[a-z][a-z0-9_-]*\/)?[a-z][a-z0-9_-]*)/';

    /** The free scanner's attribute rule, for text the JSON tokenizer refuses (`BlockAttrs::BLOCK`, its JSON group). */
    private const LOOSE = '/\G\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}(?=\s+\/?-->)/s';

    /**
     * @param array<string,mixed> $language a design language as DesignLanguages::get() returns it (its palette reads bands)
     *
     * @return list<array{name:string, json:?string, offset:int, length:int, band:string, parent:?int}> `offset`/`length` are
     *         the byte span of the attribute JSON in `$page` (`-1`/`0` when the comment has none); `parent` is the index in
     *         this list of the nearest open ancestor, or null at the top
     */
    public static function scan( string $page, array $language ): array
    {
        return self::walk( $page, Bands::palette( $language ) )[0];
    }

    /** Does every closing comment close the innermost open block, and is every opened block closed? */
    public static function balanced( string $page ): bool
    {
        return self::walk( $page, [] )[1];
    }

    /**
     * @param list<array{0:string,1:string,2:string}> $palette
     *
     * @return array{0:list<array{name:string, json:?string, offset:int, length:int, band:string, parent:?int}>, 1:bool}
     */
    private static function walk( string $page, array $palette ): array
    {
        $out      = [];
        $stack    = []; // open blocks: [index in $out, name, the band inside it]
        $balanced = true;
        $n        = strlen( $page );
        $at       = 0;
        while ( $at < $n && false !== ( $start = strpos( $page, '<!--', $at ) ) ) {
            $c = self::comment( $page, $start );
            if ( null === $c ) {
                $at = $start + 4;
                continue;
            }
            $at = $c['end'];
            if ( $c['close'] ) {
                $top = end( $stack );
                if ( false !== $top && $top[1] === $c['name'] ) {
                    array_pop( $stack );
                } else {
                    $balanced = false;
                }
                continue;
            }
            $top    = end( $stack );
            $band   = false === $top ? Bands::GROUND : $top[2];
            $index  = count( $out );
            $out[]  = [
                'name'   => $c['name'],
                'json'   => $c['json'],
                'offset' => $c['offset'],
                'length' => $c['length'],
                'band'   => $band,
                'parent' => false === $top ? null : $top[0],
            ];
            if ( ! $c['void'] ) {
                $attrs   = null === $c['json'] ? null : json_decode( $c['json'], true );
                $own     = is_array( $attrs ) && [] !== $palette ? Bands::paint( $attrs, $palette ) : null;
                $stack[] = [ $index, $c['name'], $own ?? $band ];
            }
        }

        return [ $out, $balanced && [] === $stack ];
    }

    /**
     * The block comment starting at `$start`, or null when the comment there is not one.
     *
     * @return array{close:bool, name:string, json:?string, offset:int, length:int, void:bool, end:int}|null
     */
    private static function comment( string $page, int $start ): ?array
    {
        if ( 1 !== preg_match( self::HEAD, $page, $m, 0, $start ) ) {
            return null;
        }
        $close = '' !== $m[1];
        $name  = $m[2];
        $i     = $start + strlen( $m[0] );
        $ws    = strspn( $page, " \t\n\r\f\v", $i );
        if ( 0 === $ws ) {
            return null; // the name runs into something else
        }
        $i += $ws;
        if ( $close ) {
            return '-->' === substr( $page, $i, 3 ) ? [ 'close' => true, 'name' => $name, 'json' => null, 'offset' => -1, 'length' => 0, 'void' => false, 'end' => $i + 3 ] : null;
        }
        $json   = null;
        $offset = -1;
        if ( '{' === ( $page[ $i ] ?? '' ) ) {
            $end = JsonLeaves::objectEnd( $page, $i );
            $gap = $end > 0 ? strspn( $page, " \t\n\r\f\v", $end ) : 0;
            if ( $end < 0 || 0 === $gap || ! self::endsAt( $page, $end + $gap ) ) {
                if ( 1 !== preg_match( self::LOOSE, $page, $l, 0, $i ) ) {
                    return null;
                }
                $end = $i + strlen( $l[0] );
                $gap = strspn( $page, " \t\n\r\f\v", $end );
            }
            $json   = substr( $page, $i, $end - $i );
            $offset = $i;
            $i      = $end + $gap;
        }
        if ( ! self::endsAt( $page, $i ) ) {
            return null;
        }
        $void = '/' === $page[ $i ];

        return [ 'close' => false, 'name' => $name, 'json' => $json, 'offset' => $offset, 'length' => null === $json ? 0 : strlen( $json ), 'void' => $void, 'end' => $i + ( $void ? 4 : 3 ) ];
    }

    /** Does a comment end (`-->` or `/-->`) at `$i`? */
    private static function endsAt( string $page, int $i ): bool
    {
        return '-->' === substr( $page, $i, 3 ) || '/-->' === substr( $page, $i, 4 );
    }
}
