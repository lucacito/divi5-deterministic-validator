<?php
/**
 * Dev-only: lists every attribute path the recipes in wp-plugin/data/section-recipes.json use that the shape registry
 * (fixtures/shapes/verified-shapes.json) does not cover as a registered leaf, with the recipes that use each, and compares the
 * list with the frozen fixtures/shapes/ungrounded-recipe-paths.txt.
 *
 *   php scripts/list-ungrounded-recipe-paths.php
 *
 * Output: "path -> recipe, recipe" per line, then a summary and any difference from the frozen file. Pure PHP, read-only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Block.php';
require_once __DIR__ . '/../src/BlockParser.php';
require_once __DIR__ . '/../src/ParseResult.php';
require_once __DIR__ . '/../tests/Support/ShapePaths.php';
require_once __DIR__ . '/../tests/Support/ShapeRegistry.php';

use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;

$root     = dirname( __DIR__ );
$recipes  = json_decode( (string) file_get_contents( $root . '/wp-plugin/data/section-recipes.json' ), true );
$registry = ShapeRegistry::load();
$unreg    = [];
foreach ( (array) $recipes as $r ) {
    foreach ( ShapePaths::fromMarkup( (string) $r['markup'] ) as $path ) {
        if ( ! $registry->coversLeaf( $path ) ) {
            $unreg[ $path ][] = (string) $r['name'];
        }
    }
}
ksort( $unreg, SORT_STRING );
foreach ( $unreg as $path => $names ) {
    echo $path . ' -> ' . implode( ', ', $names ) . "\n";
}
$frozen = array_values( array_filter( array_map( 'trim', file( $root . '/fixtures/shapes/ungrounded-recipe-paths.txt', FILE_IGNORE_NEW_LINES ) ?: [] ), static fn ( string $l ): bool => $l !== '' ) );
echo "\n" . count( $unreg ) . " recipe path(s) are not registered; the frozen list has " . count( $frozen ) . ".\n";
foreach ( array_diff( array_keys( $unreg ), $frozen ) as $p ) {
    echo "NOT FROZEN (a recipe uses it and nothing grounds it): $p\n";
}
foreach ( array_diff( $frozen, array_keys( $unreg ) ) as $p ) {
    echo "STALE in the frozen list: $p\n";
}
