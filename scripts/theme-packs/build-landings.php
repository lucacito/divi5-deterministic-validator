<?php
declare(strict_types=1);

/**
 * DEV-ONLY. Usage: php scripts/theme-packs/build-landings.php [slug-fragment] [outdir]
 * Builds every scripts/theme-packs/landing-*.php (one free full-landing page per file: language, kit, palette, fonts, sections
 * of [recipe, copy]) from the plugin's recipes, runs the real LayoutGate, and writes <outdir>/<slug>.json ({post_title,
 * post_content}) plus <outdir>/meta.json (slug, title, niche, style, color) for the store's create-layouts.ts (LAYOUT_TYPE=full_landing).
 */
require __DIR__ . '/lib.php';

use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\SectionRecipes;

$only   = $argv[1] ?? '';
$outDir = $argv[2] ?? dirname( __DIR__, 2 ) . '/dist/landings';
if ( ! is_dir( $outDir ) ) { mkdir( $outDir, 0775, true ); }
$failed = 0; $meta = [];
foreach ( glob( __DIR__ . '/landing-*.php' ) as $file ) {
    $s = require $file;
    if ( '' !== $only && ! str_contains( $s['slug'], $only ) && ! str_contains( basename( $file ), $only ) ) { continue; }
    $warn = tp_palette_report( $s['language'], $s['palette'] ?? [] );
    if ( $warn ) { echo "PALETTE WARNING {$s['slug']}: " . implode( '; ', $warn ) . "\n"; }
    $parts = []; $used = [];
    foreach ( $s['sections'] as [ $recipe, $copy ] ) {
        $md = SectionRecipes::recipe( $recipe, $s['language'], $s['kit'] ?? null );
        if ( null === $md ) { fwrite( STDERR, "{$s['slug']}: unknown recipe $recipe\n" ); ++$failed; continue 2; }
        $md = preg_replace( '#^\s*<!-- wp:divi/placeholder -->|<!-- /wp:divi/placeholder -->\s*$#', '', trim( $md ) );
        try { $parts[] = trim( tp_restyle( tp_apply( $md, $copy ), $s['language'], $s['palette'] ?? [], $s['fonts'] ?? [] ) ); }
        catch ( Throwable $e ) { fwrite( STDERR, "{$s['slug']} / $recipe: " . $e->getMessage() . "\n" ); ++$failed; continue 2; }
        $used[] = $recipe;
    }
    $content = '<!-- wp:divi/placeholder -->' . implode( "\n", $parts ) . '<!-- /wp:divi/placeholder -->';
    $result  = LayoutGate::check( $content );
    $codes   = [];
    foreach ( $result->violations() as $v ) { $codes[] = $v->code() . ' ' . $v->message(); }
    $left = preg_match_all( '/\[[A-Za-z0-9 ]+\]|Placeholder|example\.com|loremflickr|[Ll]orem ipsum/', preg_replace( '#wp:divi/placeholder#', '', $content ) );
    printf( "%-70s %7d B  %s%s%s\n   %s\n", $s['slug'], strlen( $content ), $result->isValid() ? 'VALID' : 'INVALID', $left ? "  LEFTOVER($left)" : '', $codes ? '  ' . implode( '; ', array_slice( $codes, 0, 5 ) ) : '', implode( ' > ', $used ) );
    $dash = substr_count( $content, "\u{2014}" ) + substr_count( $content, '\u2014' );
    if ( $dash ) { echo "   EM DASH x$dash: the catalogue copy never uses em dashes, use a comma, colon or full stop\n"; }
    if ( ! $result->isValid() || $left || $dash ) { ++$failed; }
    file_put_contents( "$outDir/{$s['slug']}.json", json_encode( [ 'post_title' => $s['title'], 'post_content' => $content ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    $meta[ $s['slug'] ] = [ 'slug' => $s['slug'], 'title' => $s['title'], 'niche' => $s['niche'], 'style' => $s['style'], 'color' => $s['color'] ];
}
if ( '' === $only ) { file_put_contents( "$outDir/meta.json", json_encode( array_values( $meta ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) ); }
exit( $failed ? 1 : 0 );
