<?php
/**
 * Dev-only: lists every attribute path the recipes in wp-plugin/data/section-recipes.json use that the shape registry
 * (fixtures/shapes/verified-shapes.json) does not cover as a registered leaf, with the recipes that use each, and compares the
 * list with the frozen fixtures/shapes/ungrounded-recipe-paths.txt.
 *
 *   php scripts/list-ungrounded-recipe-paths.php
 *
 * Recipes are read as SERVED: every style kit's markers resolved (a marker is not a Divi attribute), so the paths each
 * kit's adapter writes are listed too.
 *
 * Output: "path -> recipe, recipe" per line, then a summary and any difference from the frozen file. Pure PHP, read-only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Block.php';
require_once __DIR__ . '/../src/BlockParser.php';
require_once __DIR__ . '/../src/ParseResult.php';
require_once __DIR__ . '/../tests/Support/ShapePaths.php';
require_once __DIR__ . '/../tests/Support/ShapeRegistry.php';
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' ); // the plugin classes refuse to load outside WordPress without it
}
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';

use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\StyleKits;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;

$root     = dirname( __DIR__ );
$recipes  = json_decode( (string) file_get_contents( $root . '/wp-plugin/data/section-recipes.json' ), true );
$registry = ShapeRegistry::load();
$unreg    = [];
$kits     = StyleKits::ids();
if ( [] === $kits ) {
    fwrite( STDERR, "No style kits could be read (wp-plugin/data/style-kits.json).\n" );
    exit( 1 );
}
foreach ( (array) $recipes as $r ) {
    $paths = [];
    foreach ( $kits as $kit ) {
        foreach ( ShapePaths::fromMarkup( KitResolver::resolve( (string) $r['markup'], (array) StyleKits::get( $kit ) ) ) as $path ) {
            $paths[ $path ] = true;
        }
    }
    foreach ( array_keys( $paths ) as $path ) {
        if ( ! $registry->coversLeaf( $path ) ) {
            $unreg[ $path ][] = (string) $r['name'];
        }
    }
}
ksort( $unreg, SORT_STRING );
foreach ( $unreg as $path => $names ) {
    echo $path . ' -> ' . implode( ', ', $names ) . "\n";
}
$frozen = array_values( array_filter( array_map( 'trim', file( $root . '/fixtures/shapes/ungrounded-recipe-paths.txt', FILE_IGNORE_NEW_LINES ) ?: [] ), static fn ( string $l ): bool => $l !== '' && '#' !== $l[0] ) );
echo "\n" . count( $unreg ) . " recipe path(s) are not registered; the frozen list has " . count( $frozen ) . ".\n";
foreach ( array_diff( array_keys( $unreg ), $frozen ) as $p ) {
    echo "NOT FROZEN (a recipe uses it and nothing grounds it): $p\n";
}
foreach ( array_diff( $frozen, array_keys( $unreg ) ) as $p ) {
    echo "STALE in the frozen list: $p\n";
}
