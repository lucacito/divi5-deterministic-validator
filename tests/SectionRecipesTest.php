<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\SectionRecipes;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';

/**
 * The recipe library is content the AI copies verbatim, so EVERY recipe must
 * validate when placed in the root wrapper — otherwise we'd ship the AI a
 * broken pattern. Also guards the catalog/lookup API.
 */
class SectionRecipesTest extends TestCase
{
    public function testCatalogListsRecipes(): void
    {
        $catalog = SectionRecipes::catalog();
        $this->assertStringContainsString('Section Recipes', $catalog);
        $this->assertNotEmpty(SectionRecipes::names());
        foreach (SectionRecipes::names() as $name) {
            $this->assertStringContainsString($name, $catalog, "catalog should list $name");
        }
    }

    public function testEveryRecipeValidates(): void
    {
        $names = SectionRecipes::names();
        $this->assertGreaterThanOrEqual(5, count($names), 'expected a meaningful recipe library');

        foreach ($names as $name) {
            $markup = SectionRecipes::recipe($name);
            $this->assertNotNull($markup, "recipe $name should resolve");

            $postContent = '<!-- wp:divi/placeholder -->' . $markup . '<!-- /wp:divi/placeholder -->';
            $result = (new Validator())->validateContent($postContent);
            $codes = implode(', ', array_map(fn($v) => $v->toArray()['code'], $result->violations()));
            $this->assertTrue($result->isValid(), "recipe '$name' must validate, got: $codes");
        }
    }

    public function testUnknownRecipeReturnsNull(): void
    {
        $this->assertNull(SectionRecipes::recipe('does-not-exist'));
    }
}
