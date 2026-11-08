<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

/**
 * The "the migration shrinks the recipe file" proofs compare the file with its size before that migration. The four Loop Builder
 * recipes (loop-posts-cards, loop-products-grid, loop-posts-paged, loop-categories-grid) were added after all of those migrations, so the proofs measure the file
 * WITHOUT them: the size the file would have had, byte for byte, in the same pretty-printed form. The 700 KB bound is still
 * measured on the real file.
 */
final class RecipeFileSize
{
    public const LOOP_RECIPES = [ 'loop-posts-cards', 'loop-products-grid', 'loop-posts-paged', 'loop-categories-grid' ];

    public const FILE = __DIR__ . '/../../wp-plugin/data/section-recipes.json';

    public static function withoutLoopRecipes(): int
    {
        $all  = json_decode( (string) file_get_contents( self::FILE ), true, 512, JSON_THROW_ON_ERROR );
        $kept = array_values( array_filter( $all, static fn ( array $r ): bool => ! in_array( $r['name'], self::LOOP_RECIPES, true ) ) );

        return strlen( (string) json_encode( $kept, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
    }
}
