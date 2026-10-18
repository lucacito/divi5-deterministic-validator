<?php

declare(strict_types=1);

/**
 * Seeds fixtures/shapes/verified-shapes.json from the real builder exports in fixtures/valid/*.json.
 *
 * One entry per distinct normalised attribute path, "how":"export", evidence = the first fixture file (sorted by
 * name) that contains it. Byte-stable: running it twice changes nothing. Nothing is registered by hand: a path that
 * no export contains cannot get into the registry through this script.
 *
 *   php scripts/seed-shape-registry.php            writes verified-shapes.json (and legacy-guide-paths.txt if absent)
 *   php scripts/seed-shape-registry.php --legacy   also rewrites legacy-guide-paths.txt (do this ONCE; it is a frozen list)
 */

use Divi5Validator\Tests\Support\ShapePaths;

if ( PHP_SAPI !== 'cli' ) {
    exit( 1 );
}

define( 'ABSPATH', sys_get_temp_dir() . '/' );
$root = dirname( __DIR__ );
require_once $root . '/vendor/autoload.php';
require_once $root . '/tests/Support/ShapePaths.php';
require_once $root . '/wp-plugin/src/StyleGuide.php';

const SEED_DIVI_VERSION = '5.15.1';

$files = glob( $root . '/fixtures/valid/*.json' ) ?: [];
sort( $files, SORT_STRING );

/** @var array<string,string> $evidence path => first fixture */
$evidence = [];
foreach ( $files as $file ) {
    $data = json_decode( (string) file_get_contents( $file ), true );
    $content = is_array( $data ) ? ( $data['post_content'] ?? null ) : null;
    if ( ! is_string( $content ) || $content === '' ) {
        fwrite( STDERR, 'skipped (no post_content): ' . basename( $file ) . "\n" );
        continue;
    }
    foreach ( ShapePaths::fromMarkup( $content ) as $path ) {
        $evidence[ $path ] ??= 'fixtures/valid/' . basename( $file );
    }
}
ksort( $evidence, SORT_STRING );

$shapes = [];
foreach ( $evidence as $path => $file ) {
    $shapes[] = [ 'path' => (string) $path, 'how' => 'export', 'evidence' => $file ];
}
$json = json_encode( [ 'divi' => SEED_DIVI_VERSION, 'shapes' => $shapes ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
if ( ! is_string( $json ) ) {
    fwrite( STDERR, "json_encode failed\n" );
    exit( 1 );
}
@mkdir( $root . '/fixtures/shapes', 0777, true );
file_put_contents( $root . '/fixtures/shapes/verified-shapes.json', $json . "\n" );
echo count( $shapes ) . " shapes from " . count( $files ) . " fixtures\n";

$legacy = $root . '/fixtures/shapes/legacy-guide-paths.txt';
if ( in_array( '--legacy', $argv, true ) || ! file_exists( $legacy ) ) {
    $paths = ShapePaths::fromGuideText( AiEditorDivi5\WP\StyleGuide::markdown() );
    file_put_contents( $legacy, implode( "\n", $paths ) . "\n" );
    echo 'legacy guide paths frozen: ' . count( $paths ) . "\n";
}
