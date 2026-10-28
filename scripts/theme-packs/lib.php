<?php
declare(strict_types=1);

/**
 * DEV-ONLY (not in the plugin zip). Builds a themed multi-page layout from the plugin's own section recipes:
 * a recipe is served through the real pipeline (design language + style kit + tokens), its text slots are
 * listed in document order, and the pack author supplies the copy. Structure, styling and kit decisions
 * stay the recipe's; only text and images change. Every page is run through the real LayoutGate.
 */

$root = dirname( __DIR__, 2 );
require_once $root . '/tests/bootstrap.php';
require_once $root . '/tests/FakeWpdb.php';
require_once $root . '/tests/WpRestShims.php';
require_once $root . '/wp-plugin/src/autoload.php';

use AiEditorDivi5\WP\SectionRecipes;

const TP_BLOCK = '/<!-- wp:(divi\/[\w-]+) (\{.*?\}) (\/?-->)/s';

/** Walk a decoded attrs tree and call $fn( path, &value ) for every text slot. */
function tp_walk( array &$node, array $path, callable $fn ): void {
    foreach ( $node as $k => &$v ) {
        $p = [ ...$path, (string) $k ];
        if ( is_array( $v ) ) { tp_walk( $v, $p, $fn ); continue; }
        if ( is_string( $v ) && tp_is_slot( $p, $v ) ) { $fn( $p, $v, $node ); }
    }
}

function tp_is_slot( array $path, string $v ): bool {
    $last = end( $path );
    if ( in_array( 'innerContent', $path, true ) ) {
        // text content only: skip bare style-ish values (colours, sizes) that can live under innerContent
        return ! preg_match( '/^(#[0-9a-f]{3,8}|[\d.]+(px|em|rem|%|vw)?|on|off|none)$/i', $v ) && '' !== trim( $v );
    }
    if ( in_array( 'attributes', $path, true ) || 'type' === $last ) { return false; }
    if ( 'url' === $last ) { return in_array( 'background', $path, true ) && (bool) preg_match( '#^https?://#', $v ); }
    if ( 'src' === $last ) { return in_array( 'image', $path, true ); }
    return in_array( $last, [ 'alt', 'title', 'text', 'name', 'label', 'number', 'percent', 'placeholder', 'unicode' ], true );
}

/** @return list<array{block:string,path:string,value:string}> */
function tp_slots( string $markup ): array {
    $out = [];
    preg_replace_callback( TP_BLOCK, static function ( array $m ) use ( &$out ): string {
        $attrs = json_decode( $m[2], true );
        if ( is_array( $attrs ) ) {
            tp_walk( $attrs, [], static function ( array $p, string &$v, array &$parent ) use ( &$out, $m ): void {
                $out[] = [ 'block' => $m[1], 'path' => implode( '.', $p ), 'value' => $v ];
            } );
        }
        return $m[0];
    }, $markup );
    return $out;
}

/** Replace slot N (document order) with $copy[N] (null keeps it). */
function tp_apply( string $markup, array $copy ): string {
    $n = 0;
    $res = preg_replace_callback( TP_BLOCK, static function ( array $m ) use ( &$n, $copy ): string {
        $attrs = json_decode( $m[2], true );
        if ( ! is_array( $attrs ) ) { return $m[0]; }
        $changed = false;
        tp_walk( $attrs, [], static function ( array $p, string &$v, array &$parent ) use ( &$n, $copy, &$changed ): void {
            if ( array_key_exists( $n, $copy ) && null !== $copy[ $n ] ) {
                $new = (string) $copy[ $n ];
                if ( 'unicode' === end( $p ) && preg_match( '/^(fa|divi):([0-9a-f]{3,4})$/', $new, $g ) ) {
                    // "fa:f06c" sets the glyph, its font type and its weight together (fa solid = 900, divi = 400)
                    $parent['type'] = $g[1]; $parent['weight'] = 'fa' === $g[1] ? '900' : '400';
                    $new = '&#x' . $g[2] . ';';
                }
                $v = tp_value( $new ); $changed = true;
            }
            ++$n;
        } );
        if ( $changed && 'divi/image' === $m[1] ) { tp_sync_image_title( $attrs ); }
        return $changed ? '<!-- wp:' . $m[1] . ' ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' ' . $m[3] : $m[0];
    }, $markup );
    if ( $copy && max( array_keys( $copy ) ) >= $n ) { throw new RuntimeException( "copy index " . max( array_keys( $copy ) ) . " is beyond the recipe's $n slots" ); }
    return (string) $res;
}

/** "img:1200x900:yoga-studio-light" becomes the store's stock-photo placeholder URL (resolved to a Pexels photo at ingest). */
function tp_value( string $v ): string {
    if ( preg_match( '/^img:(\d+)x(\d+):([a-z0-9,-]+)$/', $v, $m ) ) { return "https://loremflickr.com/{$m[1]}/{$m[2]}/{$m[3]}"; }
    // "px:39828916" or "px:39828916:1920" pins one specific Pexels photograph (curated by eye), width defaults to 1600
    if ( preg_match( '/^px:(\d+)(?::(\d+))?$/', $v, $m ) ) { return "https://images.pexels.com/photos/{$m[1]}/pexels-photo-{$m[1]}.jpeg?auto=compress&cs=tinysrgb&w=" . ( $m[2] ?? '1600' ); }
    return $v;
}

/** An image's hidden HTML "title" attribute (module.decoration.attributes) carries the recipe's alt text: keep it equal to the new alt. */
function tp_sync_image_title( array &$attrs ): void {
    $alt = $attrs['image']['innerContent']['desktop']['value']['alt'] ?? null;
    if ( ! is_string( $alt ) || ! isset( $attrs['module']['decoration']['attributes']['desktop']['value']['attributes'] ) ) { return; }
    foreach ( $attrs['module']['decoration']['attributes']['desktop']['value']['attributes'] as &$a ) {
        if ( is_array( $a ) && in_array( $a['name'] ?? '', [ 'title', 'alt' ], true ) ) { $a['value'] = $alt; }
    }
}

/**
 * Re-colour and re-font a served recipe. $palette maps design-language colour roles to new hex values (roles you leave out
 * keep the language's colour); every literal of the old colour, as a hex or as the rgb of an rgba(), becomes the new one, and
 * $fonts maps old Google font families to new ones. Roles that share one hex in the language must get the same new value.
 */
function tp_restyle( string $markup, string $language, array $palette, array $fonts ): string {
    if ( ! $palette && ! $fonts ) { return $markup; }
    $lang = AiEditorDivi5\WP\DesignLanguages::get( $language );
    $byHex = [];
    foreach ( $palette as $role => $new ) {
        $old = $lang['colors'][ $role ] ?? null;
        if ( null === $old ) { throw new RuntimeException( "unknown colour role $role" ); }
        if ( preg_match( '/^#([0-9a-f]{6})$/i', $old ) ) {
            $k = strtolower( $old );
            if ( isset( $byHex[ $k ] ) && strtolower( $byHex[ $k ] ) !== strtolower( $new ) ) { throw new RuntimeException( "roles sharing $old need the same new colour" ); }
            $byHex[ $k ] = $new;
        } elseif ( preg_match( '/^rgba\((\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\)$/', $old, $m ) ) {
            $byHex[ "rgb:$m[1],$m[2],$m[3]" ] = $new;
        }
    }
    foreach ( $byHex as $old => $new ) {
        if ( str_starts_with( $old, 'rgb:' ) ) {
            [ $r, $g, $b ] = explode( ',', substr( $old, 4 ) );
        } else {
            $h = ltrim( $old, '#' ); [ $r, $g, $b ] = [ hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) ];
            $markup = preg_replace( '/' . preg_quote( $old, '/' ) . '(?![0-9a-fA-F])/i', $new, $markup );
        }
        $n = ltrim( $new, '#' ); [ $nr, $ng, $nb ] = [ hexdec( substr( $n, 0, 2 ) ), hexdec( substr( $n, 2, 2 ) ), hexdec( substr( $n, 4, 2 ) ) ];
        $markup = preg_replace( '/rgba\(\s*' . (int) $r . ',\s*' . (int) $g . ',\s*' . (int) $b . ',/', "rgba($nr,$ng,$nb,", $markup );
    }
    foreach ( $fonts as $old => $new ) { $markup = str_replace( '"family":"' . $old . '"', '"family":"' . $new . '"', $markup ); }
    return $markup;
}

/** Contrast warnings for a pack's palette (language colours overridden by $palette): the text pairs the recipes use. */
function tp_palette_report( string $language, array $palette ): array {
    $c = array_merge( AiEditorDivi5\WP\DesignLanguages::get( $language )['colors'], $palette );
    $out = [];
    foreach ( [ [ 'text', 'bg', 4.5 ], [ 'muted', 'bg', 4.5 ], [ 'text', 'surface', 4.5 ], [ 'muted', 'surface', 4.5 ], [ 'accent_text', 'accent', 4.5 ], [ 'bg', 'text', 4.5 ], [ 'accent', 'bg', 3.0 ] ] as [ $f, $b, $min ] ) {
        if ( ! preg_match( '/^#[0-9a-f]{6}$/i', $c[ $f ] ) || ! preg_match( '/^#[0-9a-f]{6}$/i', $c[ $b ] ) ) { continue; }
        $r = AiEditorDivi5\WP\DesignLanguages::contrast( $c[ $f ], $c[ $b ] );
        if ( $r < $min ) { $out[] = sprintf( '%s on %s %.2f < %.1f', $f, $b, $r, $min ); }
    }
    return $out;
}
