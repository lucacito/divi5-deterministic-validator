<?php
declare(strict_types=1);

/**
 * DEV-ONLY. Usage: php scripts/theme-packs/presetize.php <pack> [outdir] [indir]
 * Second step after globalize.php. Reads dist/theme-packs-global/<pack>/ and moves each module's typography and
 * button look into Divi module presets, so a buyer changes the pack's heading, body or button style in one place
 * (Divi's Presets panel) and every page follows. Writes <outdir>/<role>.json (still {post_title, post_content}), the
 * pack's colors.json, and presets.json in Divi's own export shape: { module: { 'divi/heading': { default, items } }, group: [] }.
 *
 * What moves (the block keeps everything else, so sizes and spacing stay per section):
 *   divi/heading  family, weight, color, letter spacing, capitalization (desktop)   one preset per distinct set
 *   divi/text     family, color (desktop)                                          one preset per distinct set
 *   divi/button   the whole button.decoration subtree (font, fill, border, hover)  one preset per distinct look
 * A button with an icon, and any block whose attributes contain an empty JSON object, are left as they are.
 * Preset ids are derived from the pack, module and name, so a re-built pack keeps its ids and a re-import is a duplicate to Divi.
 */
$root = dirname( __DIR__, 2 );
require_once $root . '/tests/bootstrap.php';
require_once $root . '/tests/FakeWpdb.php';
require_once $root . '/tests/WpRestShims.php';
require_once $root . '/wp-plugin/src/autoload.php';

use AiEditorDivi5\WP\LayoutGate;

$pack = $argv[1] ?? '';
if ( '' === $pack ) { fwrite( STDERR, "usage: presetize.php <pack> [outdir]\n" ); exit( 2 ); }
$spec = require __DIR__ . '/' . $pack . '.php';
$in   = $argv[3] ?? "$root/dist/theme-packs-global/$pack"; // indir: globalize.php's output folder (with its colors.json)
$out  = $argv[2] ?? "$root/dist/theme-packs-preset/$pack";
if ( ! is_file( "$in/colors.json" ) ) { fwrite( STDERR, "run globalize.php $pack first\n" ); exit( 2 ); }
if ( ! is_dir( $out ) ) { mkdir( $out, 0775, true ); }

const PRESET_VERSION = '5.8.0';
const PRESET_TIME    = 1790000000;
const BLOCK_RE       = '/<!-- wp:(divi\/[\w-]+) (\{.*?\}) (\/?-->)/s';

$brand   = explode( ' ', $spec['name'] )[0];
$labelOf = [];
foreach ( json_decode( (string) file_get_contents( "$in/colors.json" ), true ) as [ $id, $c ] ) {
    $labelOf[ $id ] = trim( preg_replace( '/^' . preg_quote( $spec['name'], '/' ) . '\s*/', '', $c['label'] ) );
}

function pz_get( array $a, array $path ) { foreach ( $path as $k ) { if ( ! is_array( $a ) || ! array_key_exists( $k, $a ) ) { return null; } $a = $a[ $k ]; } return $a; }
function pz_set( array &$a, array $path, $v ): void { $n = &$a; foreach ( $path as $k ) { if ( ! isset( $n[ $k ] ) || ! is_array( $n[ $k ] ) ) { $n[ $k ] = []; } $n = &$n[ $k ]; } $n = $v; }
/** Remove the path and every parent left empty. */
function pz_unset( array &$a, array $path ): void {
    if ( ! $path ) { return; }
    $k = array_shift( $path );
    if ( ! array_key_exists( $k, $a ) ) { return; }
    if ( $path && is_array( $a[ $k ] ) ) { pz_unset( $a[ $k ], $path ); if ( [] === $a[ $k ] ) { unset( $a[ $k ] ); } return; }
    if ( ! $path ) { unset( $a[ $k ] ); }
}
function pz_colour_label( $v, array $labelOf ): string {
    if ( is_string( $v ) && preg_match( '/"name":"(gcid-[^"]+)"|name\\\\u0022:\\\\u0022(gcid-[^\\\\]+)/', $v, $m ) ) { $id = $m[1] ?: $m[2]; return $labelOf[ $id ] ?? $id; }
    if ( is_string( $v ) && preg_match( '/^rgba\(.*,\s*0\)$/', $v ) ) { return 'Transparent'; }
    return is_string( $v ) ? $v : 'custom';
}
function pz_uuid( string $seed ): string {
    $h = md5( $seed );
    return sprintf( '%s-%s-4%s-%s%s-%s', substr( $h, 0, 8 ), substr( $h, 8, 4 ), substr( $h, 13, 3 ), dechex( 8 | ( hexdec( $h[16] ) & 3 ) ), substr( $h, 17, 3 ), substr( $h, 20, 12 ) );
}
function pz_encode( array $attrs ): string { return str_replace( '\\"', '\\u0022', json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); }

const HEAD_PATH = [ 'title', 'decoration', 'font', 'font', 'desktop', 'value' ];
const TEXT_PATH = [ 'content', 'decoration', 'bodyFont', 'body', 'font', 'desktop', 'value' ];
const BTN_PATH  = [ 'button', 'decoration' ];
const HEAD_KEYS = [ 'family', 'weight', 'letterSpacing', 'capitalization' ]; // colour stays in the block, as a global color
const TEXT_KEYS = [ 'family' ];

/** What a block would give to a preset: [ module, key, preset attrs, paths to remove from the block ] or null. */
function pz_plan( string $module, array $a, array $labelOf ): ?array {
    if ( 'divi/heading' === $module ) {
        $v = pz_get( $a, HEAD_PATH );
        if ( ! is_array( $v ) || ! isset( $v['family'] ) ) { return null; }
        $moved = []; foreach ( HEAD_KEYS as $k ) { if ( isset( $v[ $k ] ) ) { $moved[ $k ] = $v[ $k ]; } }
        $level = $v['headingLevel'] ?? 'h2';
        $attrs = []; pz_set( $attrs, HEAD_PATH, $moved );
        $base = ( 'h4' === $level && 'uppercase' === ( $moved['capitalization'] ?? '' ) ) ? 'Eyebrow' : 'Heading ' . substr( $level, 1 );
        return [ 'divi/heading', $level . '|' . json_encode( $moved ), $attrs, array_map( static fn ( $k ) => [ ...HEAD_PATH, $k ], array_keys( $moved ) ), $base, '' ];
    }
    if ( 'divi/text' === $module ) {
        $v = pz_get( $a, TEXT_PATH );
        if ( ! is_array( $v ) || ! isset( $v['family'] ) ) { return null; }
        $moved = []; foreach ( TEXT_KEYS as $k ) { if ( isset( $v[ $k ] ) ) { $moved[ $k ] = $v[ $k ]; } }
        $attrs = []; pz_set( $attrs, TEXT_PATH, $moved );
        return [ 'divi/text', json_encode( $moved ), $attrs, array_map( static fn ( $k ) => [ ...TEXT_PATH, $k ], array_keys( $moved ) ), 'Body', '' ];
    }
    if ( 'divi/button' === $module ) {
        $d = pz_get( $a, BTN_PATH );
        if ( ! is_array( $d ) || isset( $d['button'] ) || ! isset( $d['font'] ) ) { return null; }
        $bg   = pz_colour_label( pz_get( $d, [ 'background', 'desktop', 'value', 'color' ] ), $labelOf );
        $ink  = pz_colour_label( pz_get( $d, [ 'font', 'font', 'desktop', 'value', 'color' ] ), $labelOf );
        $attrs = []; pz_set( $attrs, BTN_PATH, $d );
        return [ 'divi/button', json_encode( $d ), $attrs, [ BTN_PATH ], 'Button', 'Transparent' === $bg ? "Ghost, $ink text" : "$bg fill" ];
    }
    return null;
}

/** Pass 1: collect distinct looks per module in document order, then name them. */
$files = array_values( array_filter( glob( "$in/*.json" ), static fn ( $f ) => ! in_array( basename( $f ), [ "colors.json", "presets.json" ], true ) ) );
$looks = []; // module => key => [ attrs, base, tag ]
$skipped = 0;
foreach ( $files as $f ) {
    $c = json_decode( (string) file_get_contents( $f ), true )['post_content'];
    preg_match_all( BLOCK_RE, $c, $ms, PREG_SET_ORDER );
    foreach ( $ms as $m ) {
        $a = json_decode( $m[2], true ); if ( ! is_array( $a ) ) { continue; }
        $p = pz_plan( $m[1], $a, $labelOf ); if ( ! $p ) { continue; }
        if ( preg_match( '/\{\}/', preg_replace( '/\$variable\(.*?\)\$/s', '', $m[2] ) ) ) { ++$skipped; continue; }
        $looks[ $p[0] ][ $p[1] ] ??= [ 'attrs' => $p[2], 'base' => $p[4], 'tag' => $p[5], 'uses' => 0 ];
        ++$looks[ $p[0] ][ $p[1] ]['uses'];
    }
}
$names = []; $presets = [];
foreach ( $looks as $module => $byKey ) {
    if ( 'divi/text' === $module ) { // the family most texts use is the body; a text set in another family is display text
        $fam = [];
        foreach ( $byKey as $key => $l ) { $f = json_decode( $key, true )['family']; $fam[ $f ] = ( $fam[ $f ] ?? 0 ) + $l['uses']; }
        arsort( $fam ); $bodyFamily = (string) array_key_first( $fam );
        foreach ( $byKey as $key => $l ) { if ( json_decode( $key, true )['family'] !== $bodyFamily ) { $byKey[ $key ]['base'] = 'Display text'; } }
    }
    $byBase = [];
    foreach ( $byKey as $key => $l ) { $byBase[ $l['base'] ][] = $key; }
    foreach ( $byBase as $base => $keys ) {
        $seen = [];
        foreach ( $keys as $key ) {
            $name = "$brand $base" . ( count( $keys ) > 1 && '' !== $byKey[ $key ]['tag'] ? ' - ' . $byKey[ $key ]['tag'] : '' );
            $seen[ $name ] = ( $seen[ $name ] ?? 0 ) + 1;
            if ( $seen[ $name ] > 1 ) { $name .= ' ' . chr( 64 + $seen[ $name ] ); }
            $id = pz_uuid( "jhmg-aied-preset|$pack|$module|$name" );
            $names[ $module ][ $key ] = [ $id, $name ];
            $presets[ $module ]['items'][ $id ] = [ 'id' => $id, 'moduleName' => $module, 'name' => $name, 'attrs' => $byKey[ $key ]['attrs'], 'styleAttrs' => $byKey[ $key ]['attrs'], 'version' => PRESET_VERSION, 'type' => 'module', 'created' => PRESET_TIME, 'updated' => PRESET_TIME ];
        }
    }
    $presets[ $module ]['default'] = '';
}

/** Pass 2: rewrite the blocks. */
$used = [];
foreach ( $files as $f ) {
    $j = json_decode( (string) file_get_contents( $f ), true );
    if ( ! isset( $j['post_content'] ) ) { continue; }
    $content = preg_replace_callback( BLOCK_RE, static function ( array $m ) use ( $labelOf, $names, &$used ): string {
        $a = json_decode( $m[2], true ); if ( ! is_array( $a ) ) { return $m[0]; }
        $p = pz_plan( $m[1], $a, $labelOf ); if ( ! $p || ! isset( $names[ $p[0] ][ $p[1] ] ) ) { return $m[0]; }
        foreach ( $p[3] as $path ) { pz_unset( $a, $path ); }
        $a['modulePreset'] = [ $names[ $p[0] ][ $p[1] ][0] ];
        $used[ $p[0] ][ $names[ $p[0] ][ $p[1] ][1] ] = ( $used[ $p[0] ][ $names[ $p[0] ][ $p[1] ][1] ] ?? 0 ) + 1;
        return '<!-- wp:' . $m[1] . ' ' . pz_encode( $a ) . ' ' . $m[3];
    }, $j['post_content'] );
    $res = LayoutGate::check( $content );
    printf( "%-12s %s%s\n", basename( $f, '.json' ), $res->isValid() ? 'VALID' : 'INVALID', $res->isValid() ? '' : ' ' . $res->violations()[0]->code() . ' ' . $res->violations()[0]->message() );
    file_put_contents( "$out/" . basename( $f ), json_encode( [ 'post_title' => $j['post_title'], 'post_content' => $content ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}
copy( "$in/colors.json", "$out/colors.json" );
file_put_contents( "$out/presets.json", json_encode( [ 'module' => $presets, 'group' => [] ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
foreach ( $used as $module => $byName ) { foreach ( $byName as $name => $n ) { printf( "  %-13s %-34s %3d blocks\n", $module, $name, $n ); } }
printf( "%s: %d presets, %d blocks rewritten, %d skipped (empty object) -> %s\n", $pack, array_sum( array_map( static fn ( $m ) => count( $m['items'] ), $presets ) ), array_sum( array_map( 'array_sum', $used ) ), $skipped, $out );
