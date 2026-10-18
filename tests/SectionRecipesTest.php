<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignVariety;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\SectionRecipes;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/DesignVariety.php';

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


    /**
     * Byte length of the default catalog before it became compact and filterable
     * (measured on 2026-10-17 from the pre-change SectionRecipes::catalog()).
     */
    private const BASELINE = 3483;

    public function testEveryRecipeHasValidTags(): void
    {
        $keys = ['role', 'layout', 'mood', 'density', 'background', 'needs_images'];
        foreach (SectionRecipes::names() as $name) {
            $tags = SectionRecipes::tags($name);
            $this->assertIsArray($tags, "$name has tags");
            foreach ($keys as $k) {
                $this->assertArrayHasKey($k, $tags, "$name tag $k");
            }
            $this->assertContains($tags['role'], SectionRecipes::ROLES, "$name role");
            $this->assertContains($tags['layout'], SectionRecipes::LAYOUTS, "$name layout");
            $this->assertIsArray($tags['mood'], "$name mood is a list");
            $this->assertNotEmpty($tags['mood'], "$name mood");
            foreach ($tags['mood'] as $m) {
                $this->assertContains($m, SectionRecipes::moods(), "$name mood $m");
            }
            $this->assertContains($tags['density'], ['airy', 'balanced', 'dense'], "$name density");
            $this->assertContains($tags['background'], ['solid', 'gradient', 'image', 'none'], "$name background");
            $this->assertIsBool($tags['needs_images'], "$name needs_images");
        }
        $this->assertNull(SectionRecipes::tags('does-not-exist'));
    }

    public function testTagsAreCoherentWithTheMarkup(): void
    {
        // Collection modules render their own grid of posts, products or items.
        $collections = ['divi/blog', 'divi/shop', 'divi/gallery', 'divi/portfolio', 'divi/fullwidth-portfolio', 'divi/filterable-portfolio'];
        foreach (SectionRecipes::names() as $name) {
            $raw  = (string) SectionRecipes::rawMarkup($name);
            $tags = (array) SectionRecipes::tags($name);
            $hasImage = str_contains($raw, '{{aied:image:') || str_contains($raw, '<!-- wp:divi/image ');
            $this->assertSame($hasImage, $tags['needs_images'], "$name needs_images must match its markup");

            // The invariants below are measured on the parsed markup.
            $parsed = (new \Divi5Validator\BlockParser())->parse('<!-- wp:divi/placeholder -->' . (string) SectionRecipes::recipe($name) . '<!-- /wp:divi/placeholder -->');
            $this->assertTrue($parsed->isOk(), $name);
            $rows = [];
            $collection = false;
            $parsed->root()->walk(static function (\Divi5Validator\Block $b) use (&$rows, &$collection, $collections): void {
                if (in_array($b->name(), ['divi/row', 'divi/row-inner'], true)) {
                    $rows[] = count(array_filter($b->children(), static fn ($c): bool => in_array($c->name(), ['divi/column', 'divi/column-inner'], true)));
                }
                $collection = $collection || in_array($b->name(), $collections, true);
            });
            $fp = DesignVariety::fingerprints('<!-- wp:divi/placeholder -->' . (string) SectionRecipes::recipe($name) . '<!-- /wp:divi/placeholder -->');
            $this->assertCount(1, $fp, $name);

            // grid: a row of 3 or more columns, or a matrix (at least two rows of 2 or more columns), or a collection module.
            if ('grid' === $tags['layout']) {
                $wide   = count(array_filter($rows, static fn (int $n): bool => $n >= 3)) > 0;
                $matrix = count(array_filter($rows, static fn (int $n): bool => $n >= 2)) >= 2;
                $this->assertTrue($wide || $matrix || $collection, "$name is tagged grid but its markup has no 3-column row, no matrix of rows and no collection module");
            }
            // split: some row has exactly two columns.
            if ('split' === $tags['layout']) {
                $this->assertContains(2, $rows, "$name is tagged split but has no two-column row");
            }
            // a recipe that fingerprints as a card grid must be tagged grid.
            if ($fp[0]['cards']) {
                $this->assertSame('grid', $tags['layout'], "$name measures as a card grid, so it must be tagged grid");
            }
            // the background tag is what the fingerprint measures.
            $this->assertSame($fp[0]['background'], $tags['background'], "$name background tag must match what its markup measures");
        }
    }

    public function testRetaggedRecipesDescribeTheirRealStructure(): void
    {
        $this->assertSame('split', ((array) SectionRecipes::tags('card-grid-3'))['layout']);
        $this->assertSame('centered', ((array) SectionRecipes::tags('card-grid-4'))['layout']);
        $this->assertSame('gradient', ((array) SectionRecipes::tags('card-grid-4'))['background']);
        $catalog = SectionRecipes::catalog('features');
        foreach (['card-grid-3', 'card-grid-4'] as $name) {
            preg_match('/\*\*' . $name . '\*\* — ([^\n]*)/u', SectionRecipes::catalog(null, ((array) SectionRecipes::tags($name))['layout']), $m);
            $this->assertNotEmpty($m, $name);
            $this->assertStringNotContainsStringIgnoringCase('cards', $m[1], "$name prose must not promise cards");
        }
    }

    public function testCatalogFiltersByRole(): void
    {
        $hero = SectionRecipes::catalog('hero');
        $this->assertStringContainsString('hero-cta', $hero);
        $this->assertStringNotContainsString('contact-form', $hero);
        // Filtered form carries the full one-line description of a match.
        $this->assertStringContainsString('Centered headline + button', $hero);
    }

    public function testCatalogFiltersByLayoutAndMood(): void
    {
        $this->assertStringContainsString('split-image-text', SectionRecipes::catalog(null, 'split'));
        $this->assertStringNotContainsString('hero-cta', SectionRecipes::catalog(null, 'split'));
        $both = SectionRecipes::catalog('features', 'grid');
        $this->assertStringContainsString('blurb-grid', $both);
        $this->assertStringNotContainsString('split-image-text', $both);
        $this->assertStringNotContainsString('card-grid-3', $both, 'card-grid-3 is tagged split, not grid');
        $this->assertStringContainsString('hero-cta', SectionRecipes::catalog(null, null, 'any'));
    }

    public function testUnknownFilterValueListsValidOnes(): void
    {
        $role = SectionRecipes::catalog('nonsense');
        $this->assertStringContainsString('nonsense', $role);
        foreach (SectionRecipes::ROLES as $r) {
            $this->assertStringContainsString($r, $role);
        }
        $layout = SectionRecipes::catalog(null, 'nonsense');
        foreach (SectionRecipes::LAYOUTS as $l) {
            $this->assertStringContainsString($l, $layout);
        }
        $mood = SectionRecipes::catalog(null, null, 'nonsense');
        foreach (SectionRecipes::moods() as $m) {
            $this->assertStringContainsString($m, $mood);
        }
        // A valid value with no match also lists the valid values instead of an empty answer.
        $none = SectionRecipes::catalog('team');
        $this->assertStringContainsString('No recipe', $none);
        $this->assertStringContainsString('hero', $none);
    }

    public function testDefaultCatalogIsCompact(): void
    {
        $catalog = SectionRecipes::catalog();
        $this->assertLessThanOrEqual(2 * self::BASELINE, strlen($catalog));
        $this->assertLessThan(self::BASELINE, strlen($catalog), 'the compact form is smaller than the old full form');
        foreach (SectionRecipes::names() as $name) {
            $this->assertStringContainsString($name, $catalog);
        }
        $this->assertStringContainsString('get_section_recipes', $catalog);
    }

    public function testFilteredCatalogMapsMatchesToAPersuasionStage(): void
    {
        $full = SectionRecipes::catalog('testimonials');
        $this->assertStringContainsString('_Stage:_', $full);
        $this->assertStringContainsString('Social proof', $full);
    }

    public function testSuggestSkipsAvoidedLayouts(): void
    {
        $out = SectionRecipes::suggest(['grid'], ['features']);
        $this->assertNotEmpty($out);
        $this->assertLessThanOrEqual(3, count($out));
        foreach ($out as $name) {
            $t = (array) SectionRecipes::tags($name);
            $this->assertNotSame('grid', $t['layout']);
            $this->assertNotSame('features', $t['role']);
        }
        $this->assertSame($out, SectionRecipes::suggest(['grid'], ['features']), 'deterministic');
        $this->assertCount(1, SectionRecipes::suggest([], [], 1));
        $this->assertSame([], SectionRecipes::suggest(SectionRecipes::LAYOUTS, []));
    }

    public function testSuggestCanBeRestrictedToRolesAndBackgrounds(): void
    {
        $hero = SectionRecipes::suggest([], [], 10, ['hero']);
        $this->assertNotEmpty($hero);
        foreach ($hero as $name) {
            $this->assertSame('hero', ((array) SectionRecipes::tags($name))['role']);
        }
        $bg = SectionRecipes::suggest([], [], 50, [], ['gradient', 'image']);
        $this->assertNotEmpty($bg);
        foreach ($bg as $name) {
            $this->assertContains(((array) SectionRecipes::tags($name))['background'], ['gradient', 'image']);
        }
        // Restrictions combine with the avoid lists and the limit; empty arrays restrict nothing.
        $this->assertSame(SectionRecipes::suggest(['grid'], [], 3), SectionRecipes::suggest(['grid'], [], 3, [], []));
        foreach (SectionRecipes::suggest(['grid'], [], 50, [], ['gradient']) as $name) {
            $t = (array) SectionRecipes::tags($name);
            $this->assertNotSame('grid', $t['layout']);
            $this->assertSame('gradient', $t['background']);
        }
        $this->assertCount(1, SectionRecipes::suggest([], [], 1, [], ['gradient', 'image']));
        $this->assertSame([], SectionRecipes::suggest([], [], 3, ['no-such-role']));
        $this->assertSame($bg, SectionRecipes::suggest([], [], 50, [], ['gradient', 'image']), 'deterministic');
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
        // The purpose of this test is the blob guard: no embedded file (a data: URI, a base64 image) may sneak into the
        // recipe library. The size bounds below are only a backstop for that.
        $data = json_decode((string) file_get_contents(__DIR__ . '/../wp-plugin/data/section-recipes.json'), true);
        foreach ($data as $r) {
            $name = (string) $r['name'];
            foreach (['markup', 'description'] as $field) {
                $value = (string) ($r[$field] ?? '');
                $this->assertDoesNotMatchRegularExpression('/data:[a-z]+\/[a-z0-9.+-]+/i', $value, "$name $field: no data: URI (use an image token)");
                $this->assertStringNotContainsString('base64,', $value, "$name $field: no base64 payloads");
                $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9+\/=]{200,}/', $value, "$name $field: no base64-looking run of 200+ characters");
            }
            // Per recipe: the largest recipe is about 11 KB; one above 24 KB is a smell and should be split.
            $this->assertLessThanOrEqual(24000, strlen((string) $r['markup']), "$name: recipe markup stays at most 24,000 bytes");
        }
        // The checker itself.
        $this->assertMatchesRegularExpression('/[A-Za-z0-9+\/=]{200,}/', str_repeat('QUJD', 60));
        $this->assertMatchesRegularExpression('/data:[a-z]+\/[a-z0-9.+-]+/i', '"src":"data:image/svg+xml,..."');
        // Whole file: 700 KB (Ruling 22, plan 2 Task 12). The old 200 KB bound was set for 17 recipes; 60+ recipes at about
        // 6-8 KB each with JSON escaping (every quote in a block comment is written \") need several hundred KB. The file is
        // read once per process and memoised (SectionRecipes::all(), on tool calls only), and the plugin zip stays far below
        // WordPress.org's limits.
        $this->assertLessThan(700 * 1024, filesize(__DIR__ . '/../wp-plugin/data/section-recipes.json'), 'the whole recipe file stays reasonable');
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

    public function testCatalogWarnsThatRecipeIdsComeFromADemoSite(): void
    {
        $catalog = SectionRecipes::catalog();
        $this->assertStringContainsString('come from a demo site and may not exist', $catalog);
        $this->assertStringContainsString('list_global_styles', $catalog);
    }

    public function testTheRecipesAreReadOnceAndClearCacheReadsThemAgain(): void
    {
        SectionRecipes::clearCache();
        $names = SectionRecipes::names();
        $this->assertNotEmpty($names);

        $cache = new \ReflectionProperty(SectionRecipes::class, 'cache');
        $this->assertIsArray($cache->getValue(), 'the first call memoises');

        // Plant a sentinel: a second call that re-read the file would not see it.
        $cache->setValue(null, [['name' => 'memo-sentinel', 'title' => 't', 'description' => 'd', 'when' => 'w', 'stage' => 's', 'markup' => '']]);
        try {
            $this->assertSame(['memo-sentinel'], SectionRecipes::names(), 'the second call serves the memo without reading the file');
            SectionRecipes::clearCache();
            $this->assertNull($cache->getValue());
            $this->assertSame($names, SectionRecipes::names(), 'clearCache() restores the file data');
        } finally {
            SectionRecipes::clearCache();
        }
    }
}
