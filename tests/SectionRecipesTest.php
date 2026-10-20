<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\DesignVariety;
use AiEditorDivi5\WP\ImageTokens;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
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

    public function testCountIsTheNumberOfRecipesInTheDataFile(): void
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/../wp-plugin/data/section-recipes.json'), true);
        $this->assertIsArray($data);
        $this->assertSame(count($data), SectionRecipes::count());
        $this->assertSame(count(SectionRecipes::names()), SectionRecipes::count());
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

    /** Plan 2 Task 12: the hero and page-header batch, each with its role/layout, found by the filter, exactly one h1. */
    public function testHeroAndPageHeaderBatchIsTaggedFilterableAndHasOneH1(): void
    {
        $batch = [
            'hero-pattern-centered'   => ['hero', 'centered'],
            'hero-split-right'        => ['hero', 'split'],
            'hero-split-left-overlap' => ['hero', 'overlap'],
            'hero-image-scrim'        => ['hero', 'stacked'],
            'hero-gradient-stats'     => ['hero', 'overlap'],
            'hero-editorial-minimal'  => ['hero', 'list'],
            'hero-bordered-cells'     => ['hero', 'stacked'],
            'hero-spec-card'          => ['hero', 'split'],
            'hero-product-glow'       => ['hero', 'centered'],
            'header-title-band'       => ['header', 'band'],
            'header-divider-intro'    => ['header', 'centered'],
            'header-image-band'       => ['header', 'band'],
        ];
        foreach ($batch as $name => [$role, $layout]) {
            $tags = (array) SectionRecipes::tags($name);
            $this->assertSame($role, $tags['role'] ?? null, "$name role");
            $this->assertSame($layout, $tags['layout'] ?? null, "$name layout");
            $this->assertStringContainsString($name, SectionRecipes::catalog($role, $layout), "$name is listed by the role + layout filter");
            $markup = (string) SectionRecipes::rawMarkup($name);
            $this->assertSame(1, preg_match_all('/"headingLevel":"h1"/', $markup), "$name has exactly one explicit h1");
            // Every heading module states its level (a Divi heading without one is an h1).
            $this->assertSame(substr_count($markup, '<!-- wp:divi/heading '), preg_match_all('/"headingLevel":"h[1-4]"/', $markup), "$name: every heading names its level, h1 to h4");
        }
    }

    /** Plan 2 Task 13: the features and process batch, each with its role/layout, found by the filter, no h1, at most two card grids. */
    public function testFeaturesAndProcessBatchIsTaggedFilterableAndHasNoH1(): void
    {
        $batch = [
            'features-bento'           => ['features', 'bento'],
            'features-zigzag'          => ['features', 'zigzag'],
            'steps-numbered'           => ['process', 'list'],
            'steps-horizontal'         => ['process', 'band'],
            'timeline-vertical'        => ['process', 'list'],
            'list-icon-dividers'       => ['features', 'list'],
            'features-accordion-image' => ['features', 'split'],
            'checklist-two-column'     => ['features', 'split'],
            'compare-two-panels'       => ['features', 'split'],
            'features-carousel-strip'  => ['features', 'carousel'],
            'stats-big-numbers'        => ['stats', 'band'],
        ];
        $cards = 0;
        foreach ($batch as $name => [$role, $layout]) {
            $tags = (array) SectionRecipes::tags($name);
            $this->assertSame($role, $tags['role'] ?? null, "$name role");
            $this->assertSame($layout, $tags['layout'] ?? null, "$name layout");
            $this->assertStringContainsString($name, SectionRecipes::catalog($role, $layout), "$name is listed by the role + layout filter");
            $markup = (string) SectionRecipes::rawMarkup($name);
            $this->assertSame(0, preg_match_all('/"headingLevel":"h1"/', $markup), "$name is not a hero: no h1");
            // Every heading module states its level (a Divi heading without one is an h1).
            $this->assertSame(substr_count($markup, '<!-- wp:divi/heading '), preg_match_all('/<!-- wp:divi\/heading [^\n]*"headingLevel":"h[2-4]"/', $markup), "$name: every heading names its level, h2 to h4");
            $fp = DesignVariety::fingerprints('<!-- wp:divi/placeholder -->' . (string) SectionRecipes::recipe($name) . '<!-- /wp:divi/placeholder -->');
            $cards += $fp[0]['cards'] ? 1 : 0;
        }
        $this->assertLessThanOrEqual(2, $cards, 'not more than two of the batch measure as card grids');
    }

    /** Plan 2 Task 14: the proof, stats and testimonial batch, each with its role/layout, found by the filter, no h1, placeholders only. */
    public function testProofStatsAndTestimonialBatchIsTaggedFilterableAndInventsNothing(): void
    {
        $batch = [
            'proof-logo-wall'           => ['proof', 'band'],
            'proof-press-quotes'        => ['proof', 'grid'],
            'proof-rating-strip'        => ['proof', 'band'],
            'stats-counter-band'        => ['stats', 'split'],
            'stats-circle-counters'     => ['stats', 'grid'],
            'stats-big-captions'        => ['stats', 'band'],
            'quote-single-large'        => ['testimonials', 'centered'],
            'testimonials-slider-quote' => ['testimonials', 'carousel'],
            'testimonials-avatar-grid'  => ['testimonials', 'grid'],
            'quote-over-image'          => ['testimonials', 'band'],
        ];
        $meta = [];
        foreach ((array) json_decode((string) file_get_contents(__DIR__ . '/../wp-plugin/data/section-recipes.json'), true) as $r) {
            $meta[(string) $r['name']] = $r;
        }
        $cards = 0;
        foreach ($batch as $name => [$role, $layout]) {
            $tags = (array) SectionRecipes::tags($name);
            $this->assertSame($role, $tags['role'] ?? null, "$name role");
            $this->assertSame($layout, $tags['layout'] ?? null, "$name layout");
            $this->assertStringContainsString($name, SectionRecipes::catalog($role, $layout), "$name is listed by the role + layout filter");
            $this->assertStringStartsWith('Social proof', (string) ($meta[$name]['stage'] ?? ''), "$name is a social-proof section");
            $markup = (string) SectionRecipes::rawMarkup($name);
            $this->assertSame(0, preg_match_all('/"headingLevel":"h1"/', $markup), "$name is not a hero: no h1");
            $this->assertSame(substr_count($markup, '<!-- wp:divi/heading '), preg_match_all('/<!-- wp:divi\/heading [^\n]*"headingLevel":"h[2-4]"/', $markup), "$name: every heading names its level, h2 to h4");
            // No invented proof: every visible text value (and every image alt) is a bracketed placeholder, says Placeholder, is a
            // demo number its own Placeholder label names, or is generic structural copy from the short allow-list.
            $this->assertSame([], self::inventedTexts($markup), "$name: text values that are neither placeholders nor structural copy");
            $this->assertMatchesRegularExpression('/placeholder|demo value/i', (string) ($meta[$name]['when'] ?? ''), "$name: the when text tells the owner to replace the placeholders");
            $fp = DesignVariety::fingerprints('<!-- wp:divi/placeholder -->' . (string) SectionRecipes::recipe($name) . '<!-- /wp:divi/placeholder -->');
            $cards += $fp[0]['cards'] ? 1 : 0;
        }
        $this->assertLessThanOrEqual(2, $cards, 'not more than two of the batch measure as card grids');
        // Images come from the pack only: avatars and logos as tokens.
        $this->assertStringContainsString('{{aied:image:logo-1}}', (string) SectionRecipes::rawMarkup('proof-logo-wall'));
        $this->assertStringContainsString('{{aied:image:avatar-', (string) SectionRecipes::rawMarkup('testimonials-avatar-grid'));
    }

    /** Plan 2 Task 15: the pricing, team, FAQ and story batch, each with its role/layout, found by the filter, no h1, placeholders only. */
    public function testPricingTeamFaqAndStoryBatchIsTaggedFilterableAndInventsNothing(): void
    {
        $batch = [
            'pricing-three-tier'         => ['pricing', 'grid'],
            'pricing-two-plan-highlight' => ['pricing', 'split'],
            'pricing-comparison-table'   => ['pricing', 'list'],
            'team-grid'                  => ['team', 'grid'],
            'founder-note'               => ['team', 'split'],
            'values-list'                => ['content', 'stacked'],
            'faq-accordion'              => ['faq', 'centered'],
            'faq-two-column'             => ['faq', 'split'],
            'faq-toggle-list'            => ['faq', 'list'],
            'story-timeline'             => ['content', 'band'],
        ];
        $meta = [];
        foreach ((array) json_decode((string) file_get_contents(__DIR__ . '/../wp-plugin/data/section-recipes.json'), true) as $r) {
            $meta[(string) $r['name']] = $r;
        }
        $cards = 0;
        foreach ($batch as $name => [$role, $layout]) {
            $tags = (array) SectionRecipes::tags($name);
            $this->assertSame($role, $tags['role'] ?? null, "$name role");
            $this->assertSame($layout, $tags['layout'] ?? null, "$name layout");
            $this->assertStringContainsString($name, SectionRecipes::catalog($role, $layout), "$name is listed by the role + layout filter");
            $markup = (string) SectionRecipes::rawMarkup($name);
            $this->assertSame(0, preg_match_all('/"headingLevel":"h1"/', $markup), "$name is not a hero: no h1");
            $this->assertSame(substr_count($markup, '<!-- wp:divi/heading '), preg_match_all('/<!-- wp:divi\/heading [^\n]*"headingLevel":"h[2-4]"/', $markup), "$name: every heading names its level, h2 to h4");
            // No invented prices, plans, people, dates or answers: every visible text value and alt is a placeholder or structural copy.
            $this->assertSame([], self::inventedTexts($markup), "$name: text values that are neither placeholders nor structural copy");
            $this->assertMatchesRegularExpression('/placeholder/i', (string) ($meta[$name]['when'] ?? ''), "$name: the when text tells the owner to replace the placeholders");
            $fp = DesignVariety::fingerprints('<!-- wp:divi/placeholder -->' . (string) SectionRecipes::recipe($name) . '<!-- /wp:divi/placeholder -->');
            $cards += $fp[0]['cards'] ? 1 : 0;
        }
        $this->assertLessThanOrEqual(2, $cards, 'not more than two of the batch measure as card grids');
        // A highlighted plan never relies on colour alone: it carries a label.
        $this->assertStringContainsString('[Most popular]', (string) SectionRecipes::rawMarkup('pricing-three-tier'));
        $this->assertStringContainsString('[Most popular]', (string) SectionRecipes::rawMarkup('pricing-comparison-table'));
        $this->assertStringContainsString('[Recommended]', (string) SectionRecipes::rawMarkup('pricing-two-plan-highlight'));
        // People come from the pack only, each portrait with its own placeholder alt.
        $team = (string) SectionRecipes::rawMarkup('team-grid');
        for ($n = 1; $n <= 4; $n++) {
            $this->assertStringContainsString('[Team member ' . $n . ' name] portrait', $team);
        }
        $this->assertStringContainsString('{{aied:image:avatar-', $team);
        $this->assertStringContainsString('{{aied:image:avatar-', (string) SectionRecipes::rawMarkup('founder-note'));
        // The FAQ recipes use the accordion, the stand-alone toggle and plain text, so they differ in kind, not only in look.
        $this->assertStringContainsString('<!-- wp:divi/accordion ', (string) SectionRecipes::rawMarkup('faq-accordion'));
        $this->assertStringContainsString('<!-- wp:divi/toggle ', (string) SectionRecipes::rawMarkup('faq-toggle-list'));
        $this->assertStringNotContainsString('wp:divi/toggle', (string) SectionRecipes::rawMarkup('faq-two-column'));
        $this->assertStringNotContainsString('wp:divi/accordion', (string) SectionRecipes::rawMarkup('faq-two-column'));
        // No pricing-table, team-member or tabs module: their default colours cannot all be set from the registered shapes.
        foreach (array_keys($batch) as $name) {
            $this->assertDoesNotMatchRegularExpression('#wp:divi/(pricing-tables?|team-member|tabs?) #', (string) SectionRecipes::rawMarkup($name), $name);
        }
    }

    /** Plan 2 Task 16: the calls to action, gallery, contact and transition batch, each with its role/layout, found by the filter, no h1, placeholders only. */
    public function testCtaGalleryContactAndTransitionBatchIsTaggedFilterableAndInventsNothing(): void
    {
        $batch = [
            'cta-band'                 => ['cta', 'band'],
            'cta-split-form'           => ['cta', 'split'],
            'cta-card-over-image'      => ['cta', 'band'],
            'cta-countdown'            => ['cta', 'centered'],
            'gallery-masonry-style'    => ['gallery', 'grid'],
            'portfolio-featured-grid'  => ['gallery', 'bento'],
            'before-after-compare'     => ['gallery', 'split'],
            'contact-location-form'    => ['contact', 'split'],
            'contact-details-form'     => ['contact', 'form'],
            'transition-wave-band'     => ['transition', 'band'],
            'transition-overlap-panel' => ['transition', 'overlap'],
        ];
        $meta = [];
        foreach ((array) json_decode((string) file_get_contents(__DIR__ . '/../wp-plugin/data/section-recipes.json'), true) as $r) {
            $meta[(string) $r['name']] = $r;
        }
        $cards = 0;
        foreach ($batch as $name => [$role, $layout]) {
            $tags = (array) SectionRecipes::tags($name);
            $this->assertSame($role, $tags['role'] ?? null, "$name role");
            $this->assertSame($layout, $tags['layout'] ?? null, "$name layout");
            $this->assertStringContainsString($name, SectionRecipes::catalog($role, $layout), "$name is listed by the role + layout filter");
            $markup = (string) SectionRecipes::rawMarkup($name);
            $this->assertSame(0, preg_match_all('/"headingLevel":"h1"/', $markup), "$name is not a hero: no h1");
            $this->assertSame(substr_count($markup, '<!-- wp:divi/heading '), preg_match_all('/<!-- wp:divi\/heading [^\n]*"headingLevel":"h[2-4]"/', $markup), "$name: every heading names its level, h2 to h4");
            $this->assertSame([], self::inventedTexts($markup), "$name: text values that are neither placeholders nor structural copy");
            $this->assertMatchesRegularExpression('/placeholder/i', (string) ($meta[$name]['when'] ?? ''), "$name: the when text tells the owner to replace the placeholders");
            // No map module (it needs a Google API key and loads a third-party script) and no filterable portfolio (its filter bar is not themed).
            $this->assertDoesNotMatchRegularExpression('#wp:divi/(map|fullwidth-map|filterable-portfolio|signup) #', $markup, $name);
            $fp = DesignVariety::fingerprints('<!-- wp:divi/placeholder -->' . (string) SectionRecipes::recipe($name) . '<!-- /wp:divi/placeholder -->');
            $cards += $fp[0]['cards'] ? 1 : 0;
        }
        $this->assertLessThanOrEqual(2, $cards, 'not more than two of the batch measure as card grids');
        // Every image in a recipe has its own alt (a screen reader must not hear the same text twice).
        foreach (array_keys($batch) as $name) {
            preg_match_all('/"alt":"((?:[^"\\\\]|\\\\.)*)"/', (string) SectionRecipes::rawMarkup($name), $m);
            $this->assertSame(count($m[1]), count(array_unique($m[1])), "$name: every image alt is distinct");
        }
        // Placeholders are the only field labels (Divi keeps labels off): they get the muted colour, not Chrome's #757575.
        // Since the forms adapter (Task 4) the kit writes the placeholder colour and the field fill, so the check reads the SERVED form.
        foreach (['contact-form', 'cta-split-form', 'contact-location-form', 'contact-details-form'] as $name) {
            $served = (string) SectionRecipes::recipe($name);
            $this->assertSame(1, preg_match('/<!-- wp:divi\/contact-form (\{[^\n]*?\}) -->/', $served, $m), $name);
            $form = (array) json_decode($m[1], true);
            $this->assertMatchesRegularExpression('/^(#|rgb)/', (string) ($form['field']['decoration']['placeholderFont']['font']['desktop']['value']['color'] ?? ''), "$name: the placeholder has a colour of its own");
            $this->assertMatchesRegularExpression('/^(#|rgb)/', (string) ($form['field']['decoration']['background']['desktop']['value']['color'] ?? ''), "$name: the field has a fill of its own");
        }
        // Every form keeps Divi's default receiver (the site admin email): no address is invented, none is written.
        foreach (['cta-split-form', 'contact-location-form', 'contact-details-form'] as $name) {
            $markup = (string) SectionRecipes::rawMarkup($name);
            $this->assertStringContainsString('<!-- wp:divi/contact-form ', $markup, $name);
            $this->assertStringNotContainsString('receiver', $markup, "$name writes no receiver");
            $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $markup, "$name holds no email address");
            $this->assertStringContainsString('"aied":{"kit":"form","on":', $markup, "$name takes the kit's field colours (Divi's default grey fields otherwise)");
        }
        // The countdown's end date is the labelled far-future placeholder, never a date that reads as a real deadline.
        $this->assertStringContainsString('"dateTime":{"desktop":{"value":"2099-12-31 23:59"}}', (string) SectionRecipes::rawMarkup('cta-countdown'));
        $this->assertStringContainsString('Placeholder: set the real end date', (string) SectionRecipes::rawMarkup('cta-countdown'));
        // Transitions are what their names say: divider edges filled with the neighbours' colour, and a card pulled over a band's edge.
        $wave = (string) SectionRecipes::rawMarkup('transition-wave-band');
        $this->assertStringContainsString('"dividers":{"top":', $wave);
        $this->assertStringContainsString('"bottom":{"desktop":{"value":{"style":"wave"', $wave);
        $this->assertStringContainsString('"margin":{"top":"-160px"}', (string) SectionRecipes::rawMarkup('transition-overlap-panel'));
        $this->assertStringContainsString('<!-- wp:divi/before-after-image ', (string) SectionRecipes::rawMarkup('before-after-compare'));
    }

    /**
     * Generic words left beside a placeholder once it is stripped: units of a bracketed figure ("[0.0] out of 5", "[0]x") and the
     * kind of image in an alt ("[Logo 1 name] logo", "[Client name] portrait"). Not claims.
     */
    private const PLACEHOLDER_UNITS = ['out of 5', 'x', 'logo', 'portrait'];

    /** Generic headings and eyebrows of the Task 14 and Task 15 batches: structure, not claims. */
    private const STRUCTURAL_COPY = [
        'In the press', 'What others have written', 'By the numbers', 'Figures that back up the promise', 'Results',
        'Four results, at a glance', 'In figures', 'The work, counted', 'In their words', 'What clients say', 'Clients',
        'Three clients, three kinds of result',
        // Task 15: pricing, team, FAQ and story.
        'Pricing', 'Pick the plan that fits', 'Two ways to work with us', 'Compare plans', 'Every plan, side by side', 'Features',
        'The team', 'The people you will work with', 'A note from the founder', 'What we stand for', 'Our values', 'FAQ',
        'Questions, answered', 'Good to know before you start', 'What people ask us', 'Our story', 'How we got here',
        // Task 16: eyebrows and the labels of a contact list and a before/after pair (what a row is, not what it says).
        'Get started', 'Next step', 'Gallery', 'Selected work', 'Before and after', 'Before', 'After', 'Visit or write', 'Address',
        'Opening hours', 'Contact', 'Email', 'Phone', 'Hours',
    ];

    /**
     * The visible text values (headings, text, buttons, slides, counter titles and numbers) and image alts of a recipe that are NOT
     * placeholders: a value passes when it holds a bracketed [placeholder], starts with "Placeholder", is only punctuation (a quote
     * mark), is in STRUCTURAL_COPY, is a two-digit list ordinal (01 to 09: a numbered list, not a figure), or is a counter number
     * that the counter's own "Placeholder" title names.
     *
     * @return list<string>
     */
    private static function inventedTexts(string $markup): array
    {
        $parsed = (new \Divi5Validator\BlockParser())->parse('<!-- wp:divi/placeholder -->' . $markup . '<!-- /wp:divi/placeholder -->');
        $bad = [];
        $ok = static function (string $v): bool {
            $v = trim(html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ('' === $v || in_array($v, self::STRUCTURAL_COPY, true) || 1 === preg_match('/^0[1-9]$/', $v)) {
                return true;
            }
            if (str_starts_with($v, 'Placeholder')) {
                // An instruction to the owner: no figure and no currency in it, except the counter pattern "replace N".
                $rest = (string) preg_replace('/\breplace \d+%?/', '', (string) preg_replace('/\[[^\]]*\]/', '', $v));
                return 0 === preg_match('/[\p{N}\p{Sc}]/u', $rest);
            }
            // Strip every [placeholder] group: what is left must hold no letter or digit (separators, punctuation, a % sign), or be
            // one of the few generic words a placeholder needs (PLACEHOLDER_UNITS).
            $rest = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/\[[^\]]*\]/', ' ', $v)));
            return 0 === preg_match('/[\p{L}\p{N}\p{Sc}]/u', $rest) || in_array($rest, self::PLACEHOLDER_UNITS, true);
        };
        $parsed->root()->walk(static function (\Divi5Validator\Block $b) use (&$bad, $ok): void {
            $name = preg_replace('#^divi/#', '', $b->name());
            $values = [];
            foreach (['title.innerContent.desktop.value', 'content.innerContent.desktop.value', 'button.innerContent.desktop.value.text', 'image.innerContent.desktop.value.alt', 'beforeImage.innerContent.desktop.value.alt', 'afterImage.innerContent.desktop.value.alt'] as $path) {
                $v = $b->attr($path);
                if (is_string($v)) {
                    $values[] = $v;
                }
            }
            if (in_array($name, ['number-counter', 'circle-counter'], true)) {
                $num   = (string) $b->attr('number.innerContent.desktop.value');
                $title = (string) $b->attr('title.innerContent.desktop.value');
                // A counter needs digits: the number is a demo value only when its own title is a Placeholder that names it.
                if (!(str_starts_with($title, 'Placeholder') && 1 === preg_match('/\b' . preg_quote($num, '/') . '\b/', $title))) {
                    $bad[] = "$name number $num";
                }
            }
            foreach ($values as $v) {
                if (!$ok($v)) {
                    $bad[] = "$name: $v";
                }
            }
        });
        return $bad;
    }

    public function testTheInventedTextCheckCatchesInventedProof(): void
    {
        $h = static fn (string $t): string => '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":' . json_encode($t) . '}}}} /-->';
        $c = static fn (string $n, string $t): string => '<!-- wp:divi/circle-counter {"title":{"innerContent":{"desktop":{"value":' . json_encode($t) . '}}},"number":{"innerContent":{"desktop":{"value":"' . $n . '"}}}} /-->';
        $this->assertSame([], self::inventedTexts($h('[Client name]') . $h('Placeholder: what this counts') . $h('What clients say') . $h('&ldquo;') . $c('80', 'Placeholder: replace 80% with a figure you can prove')));
        $this->assertSame(['heading: Trusted by 10,000 customers'], self::inventedTexts($h('Trusted by 10,000 customers')), 'an invented claim is caught');
        $this->assertSame(['circle-counter number 97', 'circle-counter: Happy clients'], self::inventedTexts($c('97', 'Happy clients')), 'an invented figure and label are caught');
        $this->assertSame(['circle-counter number 97'], self::inventedTexts($c('97', 'Placeholder: replace 80% with a figure you can prove')), 'a number its label does not name is caught');
        $this->assertSame(['image: Acme Corp logo'], self::inventedTexts('<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"x","alt":"Acme Corp logo"}}}}} /-->'), 'an invented brand in an alt text is caught');
        // Task 15: invented prices, plan names, people, dates and answers are caught; ordinals and placeholders are not.
        $t = static fn (string $v): string => '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":' . json_encode('<p>' . $v . '</p>') . '}}}} /-->';
        $g = static fn (string $q, string $a): string => '<!-- wp:divi/toggle {"title":{"innerContent":{"desktop":{"value":' . json_encode($q) . '}}},"content":{"innerContent":{"desktop":{"value":' . json_encode('<p>' . $a . '</p>') . '}}}} /-->';
        $this->assertSame([], self::inventedTexts($t('01') . $t('[Price]') . $t('/ [period]') . $h('Our values') . $g('[Question 1]', '[The answer.]')));
        $this->assertSame(['text: <p>$29</p>', 'heading: Pro', 'text: <p>Jane Doe, founder</p>', 'text: <p>1998</p>', 'text: <p>100</p>'], self::inventedTexts($t('$29') . $h('Pro') . $t('Jane Doe, founder') . $t('1998') . $t('100')), 'invented prices, plan names, people and years are caught (only 01 to 09 pass as ordinals)');
        $this->assertSame(['toggle: Is there a refund?', 'toggle: <p>Yes, within 30 days.</p>'], self::inventedTexts($g('Is there a refund?', 'Yes, within 30 days.')), 'an invented question and answer are caught');
        // A bracket elsewhere in the value no longer excuses invented text beside it.
        $this->assertSame(['text: <p>Pro plan $29 [period]</p>', 'text: <p>Jane Doe [Role]</p>', 'text: <p>[Price] per month, billed 12 times</p>', 'text: <p>Placeholder: Pro plan at $29</p>'],
            self::inventedTexts($t('Pro plan $29 [period]') . $t('Jane Doe [Role]') . $t('[Price] per month, billed 12 times') . $t('Placeholder: Pro plan at $29')),
            'invented words, prices and figures beside a placeholder are caught');
        // Task 16: invented contact details and an offer deadline are caught; the generic row labels pass.
        $this->assertSame(['text: <p>hello@acme.com</p>', 'text: <p>+44 20 7946 0000</p>', 'text: <p>12 High Street, [Postcode and city]</p>', 'heading: Offer ends Friday'],
            self::inventedTexts($t('hello@acme.com') . $t('+44 20 7946 0000') . $t('12 High Street, [Postcode and city]') . $h('Offer ends Friday')),
            'invented contact details and deadlines are caught');
        $ba = static fn (string $b, string $a): string => '<!-- wp:divi/before-after-image {"beforeImage":{"innerContent":{"desktop":{"value":{"src":"x","alt":' . json_encode($b) . '}}}},"afterImage":{"innerContent":{"desktop":{"value":{"src":"y","alt":' . json_encode($a) . '}}}}} /-->';
        $this->assertSame(['before-after-image: Smith kitchen, 2019', 'before-after-image: Smith kitchen after our refit'], self::inventedTexts($ba('Smith kitchen, 2019', 'Smith kitchen after our refit')), 'invented before/after alts are caught');
        $this->assertSame([], self::inventedTexts($ba('[Before: the view at the start]', '[After: the same view finished]')));
        $this->assertSame([], self::inventedTexts($t('Email') . $t('[Email address]') . $h('Opening hours') . $t('Before')));
        $this->assertSame([], self::inventedTexts($t('[0.0] out of 5') . $t('[00]%') . $t('[Client name] / [Role, company]') . $t('&mdash; [Founder first name]') . $t('Placeholder: replace 80% with a figure you can prove')));
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

    /** A mood that leaves nothing, while the same role/layout has recipes under other moods, says so (it is not a wrong value). */
    public function testAMoodThatEmptiesAFilterPointsAtTheOtherMoods(): void
    {
        $this->assertSame([], SectionRecipes::matchingNames('cta', null, 'bold-vibrant'), 'precondition: no cta recipe lists bold-vibrant');
        $n = count(SectionRecipes::matchingNames('cta'));
        $this->assertGreaterThan(0, $n);
        $answer = SectionRecipes::catalog('cta', null, 'bold-vibrant');
        $this->assertStringContainsString($n . ' recipes have role=cta under other moods', $answer);
        $this->assertStringContainsString('call again without mood', $answer);
        $this->assertStringContainsString('design_language=bold-vibrant', $answer);
        $this->assertStringContainsString('AA in all ten', $answer);
        $this->assertStringNotContainsString('Unknown', $answer);
        // A genuinely unknown value still gets the invalid-value answer, not the hint.
        $unknown = SectionRecipes::catalog('cta', null, 'neon');
        $this->assertStringContainsString('Unknown mood "neon"', $unknown);
        $this->assertStringNotContainsString('under other moods', $unknown);
        // A role/layout pair that has no recipe under any mood keeps the plain "No recipe" answer.
        foreach (SectionRecipes::ROLES as $r) {
            foreach (SectionRecipes::LAYOUTS as $l) {
                if ([] === SectionRecipes::matchingNames($r, $l)) {
                    $plain = SectionRecipes::catalog($r, $l, 'editorial');
                    $this->assertStringContainsString('No recipe matches', $plain);
                    $this->assertStringNotContainsString('under other moods', $plain);
                    break 2;
                }
            }
        }
        // A normal non-empty answer is the listing.
        $this->assertStringStartsWith('# Divi 5 Section Recipes (role=cta, mood=glass-dark)', SectionRecipes::catalog('cta', null, 'glass-dark'));
    }

    /** The mood example names a design language id, and the catalog says what mood values are and what `any` means. */
    public function testTheCatalogExplainsMoodValues(): void
    {
        $catalog = SectionRecipes::catalog();
        $this->assertStringNotContainsString('{"mood":"any"}', $catalog);
        $this->assertStringContainsString('{"mood":"editorial"}', $catalog);
        $this->assertStringContainsString(SectionRecipes::MOOD_HELP, preg_replace('/\s+/', ' ', $catalog));
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
        // A valid value with no match also lists the valid values instead of an empty answer. The empty combination is found at
        // test time (a valid role and a valid layout that no recipe has together), so adding recipes cannot break this test.
        $none = null;
        foreach (SectionRecipes::ROLES as $r) {
            foreach (SectionRecipes::LAYOUTS as $l) {
                $answer = SectionRecipes::catalog($r, $l);
                if (null === $none && str_contains($answer, 'No recipe')) {
                    $none = $answer;
                }
            }
        }
        $this->assertNotNull($none, 'some valid role + layout combination has no recipe');
        $this->assertStringContainsString('No recipe', $none);
        foreach (SectionRecipes::ROLES as $r) {
            $this->assertStringContainsString($r, $none);
        }
        foreach (SectionRecipes::LAYOUTS as $l) {
            $this->assertStringContainsString($l, $none);
        }
    }

    public function testDefaultCatalogIsCompact(): void
    {
        $catalog = SectionRecipes::catalog();
        $this->assertLessThanOrEqual(2 * self::BASELINE, strlen($catalog));
        // Controller Ruling 23: the old "smaller than the 17-recipe full form" bound measured the wrong thing once the library grew;
        // what matters is the AI's budget: a short line per recipe and a compact whole.
        $count = count(SectionRecipes::names());
        $this->assertGreaterThan(0, $count);
        $this->assertLessThanOrEqual(130, strlen($catalog) / $count, 'the compact catalog averages at most 130 bytes per recipe');
        $this->assertLessThanOrEqual(8000, strlen($catalog), 'the compact catalog stays at most 8,000 bytes in total');
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

    // ---- style kits: the recipe a language (or an explicit kit) serves ----

    /** A one-button recipe carrying a kit marker (no shipped recipe is migrated yet), planted through the memo. */
    private function plantMarkedRecipe(string $marker = '"aied":{"kit":"control"}'): void
    {
        $markup = '<!-- wp:divi/section {"builderVersion":"5.15.1"} --><!-- wp:divi/row {"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1"} -->'
            . '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Start here"}}},'
            . '"decoration":{"font":{"font":{"desktop":{"value":{"family":"{{aied:font:body}}"}}}}}},'
            . '"builderVersion":"5.15.1",' . $marker . '} /-->'
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
        $cache = new \ReflectionProperty(SectionRecipes::class, 'cache');
        $cache->setValue(null, [['name' => 'kit-fixture', 'title' => 't', 'description' => 'd', 'when' => 'w', 'stage' => 's', 'markup' => $markup]]);
    }

    /** @return array<string,mixed> the button's attributes in served markup */
    private function buttonAttrs(string $markup): array
    {
        $this->assertSame(1, preg_match('/<!-- wp:divi\/button (\{.*\}) \/-->/s', $markup, $m), 'one button');
        $attrs = json_decode($m[1], true);
        $this->assertIsArray($attrs);
        return $attrs;
    }

    public function testEachLanguageServesItsOwnKitAndAnExplicitKitWins(): void
    {
        $this->plantMarkedRecipe();
        try {
            // Each language serves its own kit: editorial's quiet solid button and brutalist-flat's thick square frame differ in
            // border, radius and lettering, read from the kit data (the fixture asserts the served markup follows it).
            $ek = (array) StyleKits::get((string) DesignLanguages::get('editorial')['kit']);
            $bk = (array) StyleKits::get((string) DesignLanguages::get('brutalist-flat')['kit']);
            $this->assertNotSame($ek['id'], $bk['id']);
            $this->assertSame('4px', $ek['radius']['control'], 'editorial: 4px buttons (its radius prose)');
            $this->assertSame('0px', $bk['radius']['control'], 'brutalist-flat: square buttons (its radius prose)');
            $this->assertSame('4px', $bk['border']['width'], 'brutalist-flat: a 4px border (its shadow prose)');
            $this->assertSame('uppercase', $bk['text']['capitalization'], 'brutalist-flat: capital letters');
            $this->assertSame('solid', $ek['fill'], 'editorial: an accent-filled primary, not a transparent outline');

            $editorial = SectionRecipes::recipe('kit-fixture', 'editorial');
            $brutal    = SectionRecipes::recipe('kit-fixture', 'brutalist-flat');
            $glass     = SectionRecipes::recipe('kit-fixture', 'glass-dark');
            $this->assertNotNull($editorial);
            $this->assertNotNull($brutal);
            $this->assertNotNull($glass);
            $this->assertNotSame($editorial, $brutal);
            $e  = $this->buttonAttrs($editorial)['button']['decoration'];
            $br = $this->buttonAttrs($brutal)['button']['decoration'];
            $this->assertSame($ek['border']['width'], $e['border']['desktop']['value']['styles']['all']['width']);
            $this->assertSame($bk['border']['width'], $br['border']['desktop']['value']['styles']['all']['width']);
            $this->assertSame('4px', $e['border']['desktop']['value']['radius']['topLeft']);
            $this->assertSame('0px', $br['border']['desktop']['value']['radius']['topLeft']);
            $this->assertNotSame($e['border']['desktop']['value']['styles']['all']['width'], $br['border']['desktop']['value']['styles']['all']['width']);
            $this->assertSame('none', $e['font']['font']['desktop']['value']['capitalization']);
            $this->assertSame('uppercase', $br['font']['font']['desktop']['value']['capitalization']);
            $this->assertSame(DesignLanguages::get('editorial')['colors']['accent'], $e['background']['desktop']['value']['color'], 'editorial fills its primary with the accent');

            // No language = the default language (editorial) and its kit.
            $this->assertSame($editorial, SectionRecipes::recipe('kit-fixture'));

            // An explicit kit wins over the language's.
            $override = SectionRecipes::recipe('kit-fixture', 'glass-dark', 'outline-2');
            $gk       = (string) DesignLanguages::get('glass-dark')['kit'];
            $this->assertNotNull($override);
            $this->assertNotSame($glass, $override);
            $this->assertSame('2px', $this->buttonAttrs($override)['button']['decoration']['border']['desktop']['value']['styles']['all']['width']);
            $this->assertStringContainsString(DesignLanguages::get('glass-dark')['colors']['text'], $override, 'the language still sets the colours');
            $this->assertStringNotContainsString(DesignLanguages::get('editorial')['colors']['text'], $override);
            $this->assertSame($glass, SectionRecipes::recipe('kit-fixture', 'glass-dark', $gk), 'naming the language\'s own kit changes nothing');
            $this->assertNotSame($glass, SectionRecipes::recipe('kit-fixture', 'glass-dark', 'soft-solid'));
            $this->assertSame(SectionRecipes::recipe('kit-fixture', 'editorial', 'soft-solid'), SectionRecipes::recipe('kit-fixture', null, 'soft-solid'));

            foreach ([$editorial, $brutal, $glass, $override] as $served) {
                $this->assertStringNotContainsString('{{aied:', $served);
                $this->assertStringNotContainsString('"aied"', $served);
                $this->assertSame([], KitResolver::leftovers($served));
            }
        } finally {
            SectionRecipes::clearCache();
        }
    }

    public function testAnUnknownKitOrLanguageServesNothing(): void
    {
        $this->assertNull(SectionRecipes::recipe('hero-cta', null, 'nope'));
        $this->assertNull(SectionRecipes::recipe('hero-cta', 'editorial', 'nope'));
        $this->assertNull(SectionRecipes::recipe('hero-cta', 'nope', 'outline-2'));
        $this->assertNotNull(SectionRecipes::recipe('hero-cta', null, 'outline-2'));
    }

    public function testAMarkerTheResolverCannotResolveServesNothing(): void
    {
        $this->plantMarkedRecipe('"aied":{"kit":"card"}');
        try {
            foreach ([null, 'editorial', 'glass-dark'] as $language) {
                $this->assertNull(SectionRecipes::recipe('kit-fixture', $language), 'never a recipe with a marker left in it');
            }
        } finally {
            SectionRecipes::clearCache();
        }
    }

    public function testUnreadableKitDataServesNothing(): void
    {
        $this->assertNotNull(SectionRecipes::recipe('hero-cta'));
        $cache = new \ReflectionProperty(StyleKits::class, 'cache');
        foreach ([StyleKits::parse('garbage'), StyleKits::parse('[]'), [['id' => 'outline-2']]] as $broken) {
            $cache->setValue(null, $broken);
            try {
                $this->assertNull(SectionRecipes::recipe('hero-cta'), 'no kit data: no recipe');
                $this->assertNull(SectionRecipes::recipe('hero-cta', 'glass-dark'));
                $this->assertNull(SectionRecipes::recipe('hero-cta', null, 'outline-2'));
            } finally {
                StyleKits::clearCache();
            }
        }
        $this->assertNotNull(SectionRecipes::recipe('hero-cta'));
    }

    public function testNoServedRecipeContainsAMarkerOrKitToken(): void
    {
        $languages = [null, ...DesignLanguages::ids()];
        $kits      = [null, ...StyleKits::ids()];
        $this->assertContains('soft-solid', $kits);
        $this->assertContains('outline-2', $kits);
        foreach (SectionRecipes::names() as $name) {
            foreach ($languages as $language) {
                foreach ($kits as $kit) {
                    $label  = $name . ' / ' . ($language ?? 'default') . ' / ' . ($kit ?? 'language kit');
                    $served = SectionRecipes::recipe($name, $language, $kit);
                    $this->assertNotNull($served, $label);
                    $this->assertSame([], KitResolver::leftovers($served), $label);
                    $this->assertStringNotContainsString('{{aied:', $served, $label);
                }
            }
        }
    }
}
