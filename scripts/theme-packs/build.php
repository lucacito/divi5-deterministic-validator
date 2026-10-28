<?php
declare(strict_types=1);

/**
 * DEV-ONLY. Usage: php scripts/theme-packs/build.php <pack> [outdir]
 * Builds every page of scripts/theme-packs/<pack>.php from the plugin's recipes (design language + style kit applied by
 * the real pipeline), puts the author's copy into the text slots, wraps the sections in the root placeholder,
 * runs the real LayoutGate on each page and writes <outdir>/<role>.json ({post_title, post_content}).
 */
require __DIR__ . '/lib.php';

use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\SectionRecipes;

$pack   = $argv[1] ?? 'tidewell';
$outDir = $argv[2] ?? dirname( __DIR__, 2 ) . '/dist/theme-packs/' . $pack;
$spec   = require __DIR__ . '/' . $pack . '.php';
if ( ! is_dir( $outDir ) ) { mkdir( $outDir, 0775, true ); }

$failed = 0;
$warn = tp_palette_report( $spec['language'], $spec['palette'] ?? [] );
if ( $warn ) { echo 'PALETTE WARNING: ' . implode( '; ', $warn ) . "\n"; }
foreach ( $spec['pages'] as $role => $page ) {
    $parts = [];
    foreach ( $page['sections'] as [ $recipe, $copy ] ) {
        $md = SectionRecipes::recipe( $recipe, $spec['language'], $spec['kit'] );
        if ( null === $md ) { fwrite( STDERR, "unknown recipe $recipe\n" ); exit( 2 ); }
        $md = preg_replace( '#^\s*<!-- wp:divi/placeholder -->|<!-- /wp:divi/placeholder -->\s*$#', '', trim( $md ) );
        $parts[] = trim( tp_restyle( tp_apply( $md, $copy ), $spec['language'], $spec['palette'] ?? [], $spec['fonts'] ?? [] ) );
    }
    $content = '<!-- wp:divi/placeholder -->' . implode( "\n", $parts ) . '<!-- /wp:divi/placeholder -->';
    $result  = LayoutGate::check( $content );
    $codes   = [];
    foreach ( $result->violations() as $v ) { $codes[] = $v->code() . ' ' . $v->message(); }
    printf( "%-12s %6d B  %s%s\n", $role, strlen( $content ), $result->isValid() ? 'VALID' : 'INVALID', $codes ? '  ' . implode( '; ', array_slice( $codes, 0, 6 ) ) : '' );
    if ( ! $result->isValid() ) { ++$failed; }
    file_put_contents( "$outDir/$role.json", json_encode( [ 'post_title' => $spec['name'] . ' ' . $page['title'], 'post_content' => $content ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}
exit( $failed ? 1 : 0 );
