<?php
declare(strict_types=1);

/** DEV-ONLY. Usage: php scripts/theme-packs/build-heroes.php [outdir]  -> <outdir>/<slug>.json ({post_title, post_content}) per hero, each through the real LayoutGate. */
require __DIR__ . '/lib.php';

use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\SectionRecipes;

$outDir = $argv[1] ?? dirname( __DIR__, 2 ) . '/dist/heroes';
if ( ! is_dir( $outDir ) ) { mkdir( $outDir, 0775, true ); }
$failed = 0;
foreach ( require __DIR__ . '/heroes.php' as $h ) {
    $md = SectionRecipes::recipe( $h['recipe'], $h['language'], $h['kit'] );
    if ( null === $md ) { fwrite( STDERR, "unknown recipe {$h['recipe']} / kit {$h['kit']}\n" ); ++$failed; continue; }
    $md = preg_replace( '#^\s*<!-- wp:divi/placeholder -->|<!-- /wp:divi/placeholder -->\s*$#', '', trim( $md ) );
    try { $section = trim( tp_apply( $md, $h['copy'] ) ); } catch ( Throwable $e ) { fwrite( STDERR, "{$h['slug']}: " . $e->getMessage() . "\n" ); ++$failed; continue; }
    $content = '<!-- wp:divi/placeholder -->' . $section . '<!-- /wp:divi/placeholder -->';
    $result  = LayoutGate::check( $content );
    $codes   = [];
    foreach ( $result->violations() as $v ) { $codes[] = $v->code() . ' ' . $v->message(); }
    $left = preg_match_all( '/\[[A-Za-z0-9 ]+\]|Placeholder|example\.com|loremflickr/', $content );
    printf( "%-12s %-24s %-8s %6d B  %s%s%s\n", $h['recipe'], $h['language'], $h['niche'], strlen( $content ), $result->isValid() ? 'VALID' : 'INVALID', $left ? "  LEFTOVER($left)" : '', $codes ? '  ' . implode( '; ', array_slice( $codes, 0, 4 ) ) : '' );
    if ( ! $result->isValid() || $left ) { ++$failed; }
    file_put_contents( "$outDir/{$h['slug']}.json", json_encode( [ 'post_title' => $h['title'], 'post_content' => $content ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}
exit( $failed ? 1 : 0 );
