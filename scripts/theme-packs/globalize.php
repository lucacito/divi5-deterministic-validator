<?php
declare(strict_types=1);

/**
 * DEV-ONLY. Usage: php scripts/theme-packs/globalize.php <pack> [outdir] [indir]
 * Turns the literal palette colours of a built pack (dist/theme-packs/<pack>/<role>.json) into references to Divi
 * global colors and writes <outdir>/<role>.json (still {post_title, post_content}) plus <outdir>/colors.json, the
 * list the importer needs: [ [ 'gcid-<pack>-<role>', { color, label, status } ], ... ] (Divi's own export shape).
 *
 * Only a whole value that equals a palette colour is replaced, written the way the builder writes it
 * ($variable({"type":"color","value":{"name":"gcid-...","settings":{}}})$, quotes written as backslash-u0022 inside the block comment).
 * rgba() values, gradients and anything else stay literal. The five Customizer colors are never touched.
 */
$root = dirname( __DIR__, 2 );
$pack = $argv[1] ?? '';
if ( '' === $pack ) { fwrite( STDERR, "usage: globalize.php <pack> [outdir]\n" ); exit( 2 ); }
$spec = require __DIR__ . '/' . $pack . '.php';
$in   = $argv[3] ?? "$root/dist/theme-packs/$pack"; // indir: a folder of <role>.json, e.g. the layouts as the store holds them
$out  = $argv[2] ?? "$root/dist/theme-packs-global/$pack";
if ( ! is_dir( $out ) ) { mkdir( $out, 0775, true ); }

$labels = [ 'bg' => 'Background', 'surface' => 'Surface', 'text' => 'Text', 'muted' => 'Muted text', 'accent' => 'Accent', 'accent_text' => 'On accent', 'border' => 'Border', 'inverse' => 'On dark', 'overlay' => 'Overlay' ];
$slug   = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', explode( ' ', $spec['name'] )[0] ) );
$byHex  = []; // hex => [ id, label, role ]
foreach ( $spec['palette'] ?? [] as $role => $hex ) {
    if ( ! preg_match( '/^#[0-9a-f]{6}$/i', $hex ) || isset( $byHex[ strtolower( $hex ) ] ) ) { continue; }
    $byHex[ strtolower( $hex ) ] = [ "gcid-$slug-" . str_replace( '_', '-', $role ), ( $spec['name'] . ' ' . ( $labels[ $role ] ?? $role ) ), $role ];
}
$Q = chr( 92 ) . 'u0022'; // the block comment writes a quote inside a string value as backslash-u0022
$ref = static fn ( string $id ): string => '$variable({' . $Q . 'type' . $Q . ':' . $Q . 'color' . $Q . ',' . $Q . 'value' . $Q . ':{' . $Q . 'name' . $Q . ':' . $Q . '' . $id . $Q . ',' . $Q . 'settings' . $Q . ':{}}})$';

$used = [];
$count = 0;
foreach ( glob( "$in/*.json" ) as $file ) {
    if ( in_array( basename( $file ), [ "colors.json", "presets.json" ], true ) ) { continue; }
    $j = json_decode( (string) file_get_contents( $file ), true );
    $content = $j['post_content'];
    foreach ( $byHex as $hex => [ $id, $label, $role ] ) {
        $content = preg_replace_callback( '/"' . preg_quote( $hex, '/' ) . '"/i', static function () use ( $ref, $id, &$used, &$count ): string { $used[ $id ] = true; ++$count; return '"' . $ref( $id ) . '"'; }, $content );
    }
    file_put_contents( "$out/" . basename( $file ), json_encode( [ 'post_title' => $j['post_title'], 'post_content' => $content ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}
$colors = [];
foreach ( $byHex as $hex => [ $id, $label ] ) { if ( isset( $used[ $id ] ) ) { $colors[] = [ $id, [ 'color' => $hex, 'label' => $label, 'status' => 'active' ] ]; } }
file_put_contents( "$out/colors.json", json_encode( $colors, JSON_UNESCAPED_SLASHES ) );
printf( "%s: %d references, %d global colors -> %s\n", $pack, $count, count( $colors ), $out );
