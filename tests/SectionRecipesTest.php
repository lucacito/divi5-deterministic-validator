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

    /** @return array<string,string> recipe name => raw markup (tokens unresolved) */
    private function rawRecipes(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/../wp-plugin/data/section-recipes.json'), true);
        $out  = [];
        foreach ($data as $r) {
            $out[$r['name']] = $r['markup'];
        }
        return $out;
    }

    public function testNoPhoneScrubberArtefactsInRecipesGuidesOrToolDescriptions(): void
    {
        $blob = json_encode(json_decode((string) file_get_contents(__DIR__ . '/../wp-plugin/data/section-recipes.json'), true));
        foreach (['McpHandler.php', 'OpenApiSpec.php', 'StyleGuide.php', 'SiteGuide.php', 'LandingGuide.php', 'ImageGuide.php', 'SectionRecipes.php'] as $f) {
            $blob .= (string) file_get_contents(__DIR__ . '/../wp-plugin/src/' . $f);
        }
        foreach (['(555)', '+1 (', '010-1000'] as $bad) {
            $this->assertStringNotContainsString($bad, $blob, "scrubber artefact {$bad}");
        }
    }

    public function testGlobalColourIdsAndUuidsAreWellFormed(): void
    {
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        foreach ($this->rawRecipes() as $name => $m) {
            preg_match_all('/gcid-([^\\\\"\s);,]*)/', $m, $g); // also used as a CSS var: var(--gcid-primary-color);
            foreach ($g[1] as $id) {
                // Real shapes: a UUID (Divi exports), a 10-char random id (Site B export), or a named one (gcid-body-color).
                $this->assertMatchesRegularExpression('/^(?:' . $uuid . '|[a-z0-9]{10}|[a-z]+(?:-[a-z]+)+)$/', $id, "{$name}: malformed gcid-{$id}");
            }
            // Any uuid-like value (8 hex + dash) must be a complete UUID.
            preg_match_all('/(?<![0-9a-z-])[0-9a-f]{8}-[^"\\\\]*/', $m, $u);
            foreach ($u[0] as $id) {
                $this->assertMatchesRegularExpression('/^' . $uuid . '$/', $id, "{$name}: malformed uuid {$id}");
            }
            foreach (['"id":"', '"uniqueId":{"desktop":{"value":"', '"modulePreset":["'] as $prefix) {
                preg_match_all('/' . preg_quote($prefix, '/') . '([^"]+)"/', $m, $v);
                foreach ($v[1] as $id) {
                    if ($id !== 'default') {
                        // Real shapes: UUID, 13-hex uniqid (fixtures/valid/*.json) or 10-char random id (Site B export).
                        $this->assertMatchesRegularExpression('/^(?:' . $uuid . '|[0-9a-f]{13}|[a-z0-9]{10})$/', $id, "{$name}: malformed id {$id} after {$prefix}");
                    }
                }
            }
        }
    }

    public function testUniqueIdsAndCssIdsDoNotCollide(): void
    {
        $cssIds = [];
        foreach ($this->rawRecipes() as $name => $m) {
            preg_match_all('/"uniqueId":\{"desktop":\{"value":"([^"]+)"/', $m, $u);
            $this->assertSame(count($u[1]), count(array_unique($u[1])), "{$name}: duplicate uniqueId");
            preg_match_all('/"name":"id","value":"([^"]+)"/', $m, $c);
            foreach ($c[1] as $id) {
                $this->assertArrayNotHasKey($id, $cssIds, "CSS id #{$id} is used by both {$name} and " . ($cssIds[$id] ?? ''));
                $cssIds[$id] = $name;
            }
        }
    }

    public function testNoSourceSiteLeftovers(): void
    {
        $blob = implode("\n", $this->rawRecipes());
        foreach (['sitehackedfix', 'Fashion Stylist', 'Interior Design Planner', 'Book A Seat', 'Barbers', 'Ayoka Stewart', '"value":"pricing"', '\\u003eDivi\\u003c', 'Hacking Audit', 'hacked', 'malicious code', 'defacement', 'Smith + Howard', 'Support My Website', 'MembersFirst'] as $bad) {
            $this->assertStringNotContainsString($bad, $blob, "source-site leftover: {$bad}");
        }
    }

    public function testNoRecipeEmbedsABlobAndSizesStayReasonable(): void
    {
        foreach ($this->rawRecipes() as $name => $markup) {
            $this->assertStringNotContainsString('data:image', $markup, "$name: no inline image blobs (use an image token)");
            $this->assertStringNotContainsString('base64,', $markup, "$name: no base64 payloads");
            $this->assertLessThan(60 * 1024, strlen($markup), "$name: recipe markup stays under 60 KB");
        }
        $this->assertLessThan(200 * 1024, filesize(__DIR__ . '/../wp-plugin/data/section-recipes.json'), 'the whole recipe file stays small');
    }

    public function testTestimonialPortraitUsesTheBundledAvatarWithAlt(): void
    {
        $raw = $this->rawRecipes()['testimonial'];
        $this->assertStringContainsString('{{aied:image:avatar-1}}', $raw);
        $this->assertSame(1, preg_match('/"portrait":\{"innerContent":\{"desktop":\{"value":(\{"url":"[^"]*"(?:,"alt":"[^"]*")?\})\}\}\}/', $raw, $m));
        $value = json_decode($m[1], true);
        $this->assertSame('{{aied:image:avatar-1}}', $value['url'] ?? null);
        $this->assertNotSame('', trim((string) ($value['alt'] ?? '')), 'the portrait has meaningful alt text');
        $this->assertStringContainsString('/assets/images/avatar-1.svg', (string) SectionRecipes::recipe('testimonial'), 'the token resolves to the local pack');
    }
}
