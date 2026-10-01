<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\SectionRecipes;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
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

    public function testCatalogMapsEveryRecipeToAPersuasionStage(): void
    {
        $catalog = SectionRecipes::catalog();
        // The catalog tells the AI which conversion-flow stage each recipe serves.
        $this->assertStringContainsString('_Stage:_', $catalog, 'catalog should surface the persuasion stage');
        $this->assertStringContainsString('Hero', $catalog);
        $this->assertStringContainsString('Social proof', $catalog);
    }

    public function testUnknownRecipeReturnsNull(): void
    {
        $this->assertNull(SectionRecipes::recipe('does-not-exist'));
    }
    public function testNoRecipeContainsARemoteImageHostOrAnUnresolvedToken(): void
    {
        foreach (SectionRecipes::names() as $name) {
            $md = (string) SectionRecipes::recipe($name);
            $this->assertFalse(ImageTokens::hasUnresolved($md), "$name leaks an unresolved token");
            $this->assertStringNotContainsString('{{aied:', $md, "$name leaks an unresolved token");
            $this->assertDoesNotMatchRegularExpression('#https?://(?!example\.com|[a-z0-9.-]*\.example(/|"))[^"\s]*\.(jpe?g|png|webp|gif|svg)#i', $md, "$name hotlinks a remote image");
            $this->assertStringNotContainsString('srcset', $md, "$name keeps a stale srcset from the original export");
            $this->assertStringNotContainsString('/wp-content/uploads/', $md, "$name references the exported site's uploads");
            foreach (['picsum.photos', 'pravatar', 'randomuser', 'placehold.co', 'loremflickr', 'unsplash'] as $host) {
                $this->assertStringNotContainsString($host, $md, "$name mentions $host");
            }
        }
    }

    public function testRecipeImagesPointAtTheBundledPack(): void
    {
        $withImages = 0;
        foreach (SectionRecipes::names() as $name) {
            $md = (string) SectionRecipes::recipe($name);
            if (str_contains($md, '/assets/images/')) {
                $withImages++;
                $this->assertMatchesRegularExpression('#https://example\.com/wp-content/plugins/jhmg-ai-editor-for-divi-5/assets/images/[a-z0-9-]+\.svg#', $md);
            }
        }
        $this->assertGreaterThanOrEqual(4, $withImages, 'image-bearing recipes should use the bundled pack');
    }

    public function testRecipesWithSeveralImagesUseDistinctOnes(): void
    {
        foreach (['image-gallery', 'image-carousel', 'card-grid-3'] as $name) {
            preg_match_all('#/assets/images/([a-z0-9-]+)\.svg#', (string) SectionRecipes::recipe($name), $m);
            $this->assertGreaterThan(1, count($m[1]), "$name should show several images");
            $this->assertSame(count($m[1]), count(array_unique($m[1])), "$name should use a different image per slot");
        }
    }

    public function testImageTokenFilterCanOverrideARecipeImage(): void
    {
        add_filter('jhmg_aied_image_token', static fn (?string $url, string $token): ?string => 'https://cdn.addon.example/' . $token . '.jpg', 10, 2);
        try {
            $md = (string) SectionRecipes::recipe('image-gallery');
            $this->assertStringContainsString('https://cdn.addon.example/', $md);
            $this->assertStringNotContainsString('/assets/images/', $md);
        } finally {
            remove_all_filters('jhmg_aied_image_token');
        }
    }

    public function testCatalogPointsAtTheMediaLibraryTool(): void
    {
        $this->assertStringContainsString('list_media_images', SectionRecipes::catalog());
    }

    public function testEveryRecipeImageHasMeaningfulAltText(): void
    {
        $slots = 0;
        foreach (SectionRecipes::names() as $name) {
            $md = (string) SectionRecipes::recipe($name);
            if (!str_contains($md, '<!-- wp:divi/image ')) {
                continue;
            }
            preg_match_all('#<!-- wp:divi/image (\{.*?\}) /-->#s', $md, $blocks);
            $this->assertNotEmpty($blocks[1] ?? [], "$name: image blocks should be parseable");
            $this->assertSame(substr_count($md, '<!-- wp:divi/image '), count($blocks[1]), "$name: every image block must be parsed");
            foreach ($blocks[1] as $json) {
                $attrs = json_decode($json, true);
                $this->assertIsArray($attrs, "$name: image block JSON must decode");
                $slots++;
                $value = $attrs['image']['innerContent']['desktop']['value'] ?? [];
                $alt = (string) ($value['alt'] ?? '');
                $this->assertNotSame('', trim($alt), "$name: image {$value['src']} has no alt");
                $this->assertNotSame('image', strtolower(trim($alt)), "$name: image alt must not be the literal 'Image'");
                // Leftover export-style names (file names, @2x) must not survive as title/alt attributes.
                $htmlAttrs = $attrs['module']['decoration']['attributes']['desktop']['value']['attributes'] ?? [];
                foreach ($htmlAttrs as $a) {
                    if (in_array($a['name'] ?? '', ['alt', 'title'], true)) {
                        $this->assertSame($alt, $a['value'], "$name: image {$a['name']} attribute must match the alt text");
                    }
                }
            }
        }
        $this->assertGreaterThanOrEqual(11, $slots, 'expected to inspect every image slot');
    }

    public function testImageCarouselIsALogoStrip(): void
    {
        $md = (string) SectionRecipes::recipe('image-carousel');
        preg_match_all('#/assets/images/([a-z0-9-]+)\.svg#', $md, $m);
        $this->assertGreaterThan(1, count($m[1]));
        foreach ($m[1] as $file) {
            $this->assertStringStartsWith('logo-', $file, 'carousel recipe is a logo strip');
        }
    }
}
