<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/BlockAttrs.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageResolver.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';

/**
 * Studio compiler, plan 1 task 1: the serve pipeline is reachable for markup that is not a stored recipe (a recipe
 * with its slots filled), and `recipe()` is exactly a lookup plus that call.
 */
class StudioPipelineTest extends TestCase
{
    public function testResolveRawEqualsRecipeForEveryRecipeAndEveryLanguage(): void
    {
        $this->assertGreaterThan( 0, count( SectionRecipes::names() ) );
        foreach ( SectionRecipes::names() as $name ) {
            $raw = SectionRecipes::rawMarkup( $name );
            $this->assertNotNull( $raw, $name );
            foreach ( DesignLanguages::ids() as $language ) {
                $served = SectionRecipes::recipe( $name, $language );
                $this->assertNotNull( $served, "$name under $language" );
                $this->assertSame( $served, SectionRecipes::resolveRaw( $raw, $language ), "$name under $language" );
            }
        }
    }

    public function testResolveRawNullForUnknownLanguageOrKit(): void
    {
        $raw = (string) SectionRecipes::rawMarkup( 'hero-cta' );
        $this->assertNull( SectionRecipes::resolveRaw( $raw, 'no-such-language' ) );
        $this->assertNull( SectionRecipes::resolveRaw( $raw, 'editorial', 'no-such-kit' ) );
        $this->assertNotNull( SectionRecipes::resolveRaw( $raw, 'editorial', 'quiet-solid' ) );
    }
}
