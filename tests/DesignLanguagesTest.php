<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';

/**
 * Design languages are art-direction data the AI is told to pick before choosing
 * recipes. Every palette must be readable (WCAG AA), and the recipe mood vocabulary
 * must follow the languages that exist.
 */
class DesignLanguagesTest extends TestCase
{
    private const COLOR_KEYS = [ 'bg', 'surface', 'text', 'muted', 'accent', 'accent_text', 'border' ];
    private const FONT_KEYS  = [ 'display', 'body', 'eyebrow' ];

    public function testLanguagesLoadAndHaveEveryField(): void
    {
        $ids = DesignLanguages::ids();
        $this->assertContains('editorial', $ids);
        $this->assertContains('glass-dark', $ids);

        foreach ($ids as $id) {
            $lang = DesignLanguages::get($id);
            $this->assertIsArray($lang, $id);
            $this->assertSame($id, $lang['id']);
            foreach (['title', 'summary', 'radius', 'section_padding', 'shadow', 'rhythm', 'hero', 'cta'] as $key) {
                $this->assertIsString($lang[$key] ?? null, "$id.$key");
                $this->assertNotSame('', trim($lang[$key]), "$id.$key");
            }
            foreach (self::COLOR_KEYS as $key) {
                $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', (string) ($lang['colors'][$key] ?? ''), "$id.colors.$key");
            }
            foreach (self::FONT_KEYS as $key) {
                $this->assertNotSame('', trim((string) ($lang['fonts'][$key] ?? '')), "$id.fonts.$key");
            }
            $this->assertContains($lang['motion'], ['none', 'subtle', 'lively'], $id);
            foreach (['favors', 'avoids'] as $key) {
                $this->assertNotEmpty($lang[$key], "$id.$key");
                foreach ($lang[$key] as $layout) {
                    $this->assertContains($layout, SectionRecipes::LAYOUTS, "$id.$key layout vocabulary");
                }
            }
            $this->assertSame([], array_intersect($lang['favors'], $lang['avoids']), "$id favors and avoids overlap");
        }
    }

    public function testEveryLanguagePaletteMeetsWcagAa(): void
    {
        foreach (DesignLanguages::ids() as $id) {
            $c = DesignLanguages::get($id)['colors'];
            $this->assertGreaterThanOrEqual(4.5, DesignLanguages::contrast($c['text'], $c['bg']), "$id text/bg");
            $this->assertGreaterThanOrEqual(4.5, DesignLanguages::contrast($c['text'], $c['surface']), "$id text/surface");
            $this->assertGreaterThanOrEqual(4.5, DesignLanguages::contrast($c['accent_text'], $c['accent']), "$id accent_text/accent");
            $this->assertGreaterThanOrEqual(3.0, DesignLanguages::contrast($c['muted'], $c['bg']), "$id muted/bg");
            if (isset($c['surface_glass'])) {
                $card = $this->composite($c['surface_glass'], $c['bg']);
                $this->assertGreaterThanOrEqual(4.5, DesignLanguages::contrast($c['text'], $card), "$id text/surface_glass over bg ($card)");
            }
        }
    }

    /** Alpha-blend an rgba() string over a #rrggbb background; returns #rrggbb. */
    private function composite(string $rgba, string $bgHex): string
    {
        $this->assertSame(1, preg_match('/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*([0-9.]+)\s*\)$/', $rgba, $m), $rgba);
        $alpha = (float) $m[4];
        $bg = [hexdec(substr($bgHex, 1, 2)), hexdec(substr($bgHex, 3, 2)), hexdec(substr($bgHex, 5, 2))];
        $out = '#';
        foreach ([1, 2, 3] as $i) {
            $out .= sprintf('%02x', (int) round($alpha * (int) $m[$i] + (1 - $alpha) * $bg[$i - 1]));
        }
        return $out;
    }

    public function testSurfaceGlassIsOptionalAndWellFormed(): void
    {
        $this->assertArrayNotHasKey('surface_glass', DesignLanguages::get('editorial')['colors']);
        $this->assertNotSame('', DesignLanguages::markdown('editorial'));
        $glassCount = 0;
        foreach (DesignLanguages::ids() as $id) {
            $c = DesignLanguages::get($id)['colors'];
            if (!isset($c['surface_glass'])) {
                continue;
            }
            $glassCount++;
            $this->assertSame(1, preg_match('/^rgba\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(0?\.\d+)\s*\)$/', $c['surface_glass'], $m), "$id surface_glass shape");
            foreach ([1, 2, 3] as $i) {
                $this->assertLessThanOrEqual(255, (int) $m[$i], "$id surface_glass channel");
            }
            $this->assertGreaterThan(0.0, (float) $m[4]);
            $this->assertLessThan(1.0, (float) $m[4]);
        }
        $this->assertGreaterThanOrEqual(1, $glassCount);
    }

    public function testGlassLanguageMarkdownPresentsTranslucentCardColour(): void
    {
        $lang = DesignLanguages::get('glass-dark');
        $glass = $lang['colors']['surface_glass'];
        $md = DesignLanguages::markdown('glass-dark');
        $this->assertStringContainsString($glass, $md);
        $this->assertStringNotContainsString('surface (cards)', $md);
        $this->assertStringContainsString('opaque equivalent', $md);
        $this->assertStringContainsString('fallback', $md);
        // The opaque surface is never the line that names the card colour.
        foreach (explode("\n", $md) as $line) {
            if (str_contains($line, 'card colour')) {
                $this->assertStringContainsString($glass, $line);
                $this->assertStringNotContainsString($lang['colors']['surface'], $line);
            }
        }
        // A language without surface_glass is printed as before.
        $this->assertStringContainsString('surface (cards) `#ffffff`', DesignLanguages::markdown('editorial'));
    }

    public function testThereAreTenLanguagesAndTheMoodsFollow(): void
    {
        $this->assertSame(
            ['editorial', 'glass-dark', 'organic-warm', 'tech-mono', 'playful-pastel', 'corporate-trust', 'soft-saas', 'bold-vibrant', 'brutalist-flat', 'luxe'],
            DesignLanguages::ids()
        );
        $this->assertSame(['any', ...DesignLanguages::ids()], SectionRecipes::moods());
        foreach (['organic-warm', 'tech-mono', 'playful-pastel', 'corporate-trust', 'soft-saas', 'bold-vibrant', 'brutalist-flat', 'luxe'] as $id) {
            $this->assertContains($id, SectionRecipes::moods());
        }
    }

    public function testEveryLanguageHasInverseAndOverlayRoles(): void
    {
        foreach (DesignLanguages::ids() as $id) {
            $c = DesignLanguages::get($id)['colors'];
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', (string) ($c['inverse'] ?? ''), "$id.colors.inverse");
            $this->assertSame(1, preg_match('/^rgba\((\d{1,3}),(\d{1,3}),(\d{1,3}),(0?\.\d+)\)$/', (string) ($c['overlay'] ?? ''), $m), "$id.colors.overlay");
            $this->assertGreaterThanOrEqual(0.35, (float) $m[4], "$id overlay alpha");
            $this->assertLessThanOrEqual(0.8, (float) $m[4], "$id overlay alpha");
        }
    }

    /** Light text on a photo: the scrim over the worst possible photo (pure white, pure black) must still read. */
    public function testInverseTextReadsOverTheOverlayOverBothWhiteAndBlack(): void
    {
        foreach (DesignLanguages::ids() as $id) {
            $c = DesignLanguages::get($id)['colors'];
            foreach (['#ffffff', '#000000'] as $photo) {
                $scrim = $this->composite($c['overlay'], $photo);
                $this->assertGreaterThanOrEqual(4.5, DesignLanguages::contrast($c['inverse'], $scrim), "$id inverse over overlay over $photo ($scrim)");
            }
        }
    }

    public function testLanguagesAreVisiblyDifferentFromEachOther(): void
    {
        $favors = [];
        $texts  = ['hero' => [], 'rhythm' => [], 'summary' => [], 'cta' => []];
        $accents = [];
        $bgs     = [];
        $displays = [];
        $bodies   = [];
        foreach (DesignLanguages::ids() as $id) {
            $lang = DesignLanguages::get($id);
            $set = $lang['favors'];
            sort($set);
            $key = implode(',', $set);
            $this->assertArrayNotHasKey($key, $favors, "$id favors the same layouts as " . ($favors[$key] ?? ''));
            $favors[$key] = $id;
            foreach ($texts as $field => $_) {
                $this->assertNotContains($lang[$field], $texts[$field], "$id $field repeats another language's");
                $texts[$field][] = $lang[$field];
            }
            $this->assertNotContains(strtolower($lang['colors']['accent']), $accents, "$id accent repeats");
            $accents[] = strtolower($lang['colors']['accent']);
            $this->assertNotContains(strtolower($lang['colors']['bg']), $bgs, "$id background repeats");
            $bgs[] = strtolower($lang['colors']['bg']);
            $this->assertNotContains($lang['fonts']['display'], $displays, "$id display font repeats");
            $displays[] = $lang['fonts']['display'];
            $this->assertNotContains($lang['fonts']['body'], $bodies, "$id body font repeats");
            $bodies[] = $lang['fonts']['body'];
        }
    }

    /**
     * The seven things that make two pages look different at a glance. Two languages must differ in at least
     * MIN_FEATURE_DIFFERENCES of them AND their `favors` sets must differ by at least MIN_FAVORS_DISTANCE layouts
     * (the symmetric difference). The shipped ten meet both; a language copied from another does not.
     */
    private const MIN_FEATURE_DIFFERENCES = 4;
    private const MIN_FAVORS_DISTANCE     = 3;

    /** @return array<string,mixed> */
    private function features(array $lang): array
    {
        $favors = $lang['favors'];
        sort($favors);
        return [
            'motion'  => $lang['motion'],
            'radius'  => $lang['radius'],
            'display' => $lang['fonts']['display'],
            'body'    => $lang['fonts']['body'],
            'bg'      => strtolower($lang['colors']['bg']),
            'accent'  => strtolower($lang['colors']['accent']),
            'favors'  => implode(',', $favors),
        ];
    }

    public function testEveryPairOfLanguagesIsFarApart(): void
    {
        $ids = DesignLanguages::ids();
        $this->assertCount(10, $ids);
        $min = PHP_INT_MAX;
        $minFavors = PHP_INT_MAX;
        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $a = DesignLanguages::get($ids[$i]);
                $b = DesignLanguages::get($ids[$j]);
                $fa = $this->features($a);
                $fb = $this->features($b);
                $differ = array_keys(array_filter($fa, static fn($v, $k): bool => $v !== $fb[$k], ARRAY_FILTER_USE_BOTH));
                $min = min($min, count($differ));
                $this->assertGreaterThanOrEqual(self::MIN_FEATURE_DIFFERENCES, count($differ), "{$ids[$i]} vs {$ids[$j]} differ only in: " . implode(', ', $differ));
                $distance = count(array_merge(array_diff($a['favors'], $b['favors']), array_diff($b['favors'], $a['favors'])));
                $minFavors = min($minFavors, $distance);
                $this->assertGreaterThanOrEqual(self::MIN_FAVORS_DISTANCE, $distance, "{$ids[$i]} vs {$ids[$j]} favor almost the same layouts");
            }
        }
        $this->assertGreaterThanOrEqual(self::MIN_FEATURE_DIFFERENCES, $min);
        $this->assertGreaterThanOrEqual(self::MIN_FAVORS_DISTANCE, $minFavors);
    }

    public function testBoldVibrantAndLuxeDoNotOpenWithTheSameHero(): void
    {
        $bold = DesignLanguages::get('bold-vibrant');
        $luxe = DesignLanguages::get('luxe');
        $this->assertNotSame($bold['hero'], $luxe['hero']);
        $this->assertMatchesRegularExpression('/\bstacked\b/i', $bold['hero']);
        $this->assertMatchesRegularExpression('/\bcentered\b/i', $luxe['hero']);
        $this->assertStringNotContainsStringIgnoringCase('centered', $bold['hero']);
        $this->assertStringNotContainsStringIgnoringCase('stacked', $luxe['hero']);
    }

    /** @return list<string> */
    private static function newLanguageIds(): array
    {
        return ['soft-saas', 'bold-vibrant', 'brutalist-flat', 'luxe'];
    }

    /** Things the guides do not prove (or prove dead). The four new languages never name them, not even to forbid them. */
    public function testNewLanguagesNameNothingTheGuidesDoNotProve(): void
    {
        $banned = ['backdrop', 'frosted', 'glass', 'blurred', 'blurry', 'bounce', 'sticker', 'video', 'cursor', 'marquee', 'ticker', 'noise', 'grain', 'scroll-snap',
            'snap', 'inner shadow', 'inset', 'gradient text', 'highlight', 'squiggle', 'floating shape', 'hand-', 'real-looking', 'parallax scroll'];
        foreach (self::newLanguageIds() as $id) {
            $lang = DesignLanguages::get($id);
            $this->assertIsArray($lang, $id);
            foreach (['summary', 'radius', 'section_padding', 'shadow', 'rhythm', 'hero', 'cta'] as $field) {
                foreach ($banned as $phrase) {
                    $this->assertStringNotContainsStringIgnoringCase($phrase, $lang[$field], "$id.$field names \"$phrase\"");
                }
                // Upper case is `capitalization`; a `style` list holding "uppercase" is dead.
                $this->assertDoesNotMatchRegularExpression('/style[^.]*uppercase/i', $lang[$field], "$id.$field");
                // Divi's divider styles are the 26 proven ones; "curve" and "wave" and "slant" are among them, "zigzag"/"torn" are not.
                $this->assertStringNotContainsStringIgnoringCase('torn', $lang[$field], "$id.$field");
            }
        }
    }

    /** A hex named in the prose is paired with the text or the inverse colour: it must be readable with one of them. */
    public function testEveryHexNamedInTheNewProseReadsWithItsPairedTextColour(): void
    {
        foreach (self::newLanguageIds() as $id) {
            $lang = DesignLanguages::get($id);
            $c = $lang['colors'];
            foreach (['summary', 'radius', 'section_padding', 'shadow', 'rhythm', 'hero', 'cta'] as $field) {
                preg_match_all('/#[0-9a-f]{6}\b/i', $lang[$field], $m);
                foreach ($m[0] as $hex) {
                    $best = max(DesignLanguages::contrast($hex, $c['text']), DesignLanguages::contrast($hex, $c['inverse']));
                    $this->assertGreaterThanOrEqual(4.5, $best, "$id.$field names $hex, which reads with neither the text nor the inverse colour");
                }
            }
        }
    }

    public function testNewLanguagesCallPlaceholdersPlaceholdersAndNeverAskForInventedFacts(): void
    {
        foreach (self::newLanguageIds() as $id) {
            $lang = DesignLanguages::get($id);
            foreach (['rhythm', 'hero', 'cta'] as $field) {
                if (1 === preg_match('/logo|testimonial|review|rating|award|statistic|client|customer name|price|quote/i', $lang[$field])) {
                    $this->assertStringContainsStringIgnoringCase('placeholder', $lang[$field], "$id.$field mentions proof or prices without calling them placeholders");
                }
            }
        }
        // Where logos or testimonials appear at all, the owner is told to replace them and the assistant not to invent them.
        $text = strtolower(DesignLanguages::get('soft-saas')['hero']);
        $this->assertStringContainsString('placeholder', $text);
        $this->assertStringContainsString('never invent', $text);
    }

    /**
     * An instruction that invites a reassurance, a guarantee or a term the assistant cannot know ("no credit card",
     * "cancel anytime") must hand the fact to the owner: say it is a placeholder or supplied by the owner, and never invent.
     */
    public function testNoLanguageInvitesInventedGuaranteesOrTerms(): void
    {
        $risky = '/reassur|guarantee|credit card|cancel|free trial|trial length|no commitment|refund|money-back|sign(?:ing)? ?up|what happens (?:after|next)/i';
        foreach (DesignLanguages::ids() as $id) {
            $lang = DesignLanguages::get($id);
            foreach (['rhythm', 'hero', 'cta'] as $field) {
                if (1 === preg_match($risky, $lang[$field])) {
                    $this->assertMatchesRegularExpression('/placeholder|owner supplied|owner has not said/i', $lang[$field], "$id.$field invites a fact the assistant cannot know without saying the owner supplies it");
                    $this->assertStringContainsStringIgnoringCase('never invent', $lang[$field], "$id.$field");
                }
            }
        }
        $cta = DesignLanguages::get('soft-saas')['cta'];
        $this->assertStringContainsString('only if the owner supplied it', $cta);
        $this->assertStringNotContainsString('confirms is true', $cta);
    }

    /**
     * Every language, every instruction field: a mention of a fact the assistant cannot know (a promise, a certificate, a logo,
     * a figure, years/clients/results, a phone number, a price, a trial ...) must come with an owner-supplied / placeholder /
     * never-invent qualifier in the same field. "never invent guarantees" is itself the qualifier, so it passes.
     */
    public function testEveryLanguageHandsInventableFactsToTheOwner(): void
    {
        $facts = '/promise|guarantee|certificate|testimonial|logo|figure|\byears\b|\bclients\b|\bresults\b|phone number|award|pric(?:e|ing)|trial|reassur|credit card|cancel|no commitment|refund|money-back|sign(?:ing)? ?up|what happens (?:after|next)|rating|statistic|customer name|\bquote/i';
        $qualifier = '/placeholder|owner supplied|supplied by the owner|owner gave|never invent/i';
        $this->assertSame(1, preg_match($qualifier, 'never invent guarantees'));
        $this->assertSame(1, preg_match($facts, 'a one-line promise'));
        $checked = 0;
        foreach (DesignLanguages::ids() as $id) {
            $lang = DesignLanguages::get($id);
            foreach (['rhythm', 'hero', 'cta'] as $field) {
                if (1 === preg_match($facts, $lang[$field])) {
                    $checked++;
                    $this->assertMatchesRegularExpression($qualifier, $lang[$field], "$id.$field mentions a fact the assistant cannot know without handing it to the owner");
                }
            }
        }
        $this->assertGreaterThanOrEqual(6, $checked, 'the check actually found fields to test');
        // The generic check is per field, so one qualifier can mask another unqualified fact: pin the three that were fixed.
        $this->assertStringContainsString('never invent promises, guarantees or terms', DesignLanguages::get('playful-pastel')['cta']);
        $this->assertStringContainsString('only if the owner supplied one', DesignLanguages::get('playful-pastel')['cta']);
        $corp = DesignLanguages::get('corporate-trust');
        $this->assertStringContainsString('never invent names, figures, certificates or quotes', $corp['rhythm']);
        $this->assertStringNotContainsString('Back every claim', $corp['rhythm']);
        $this->assertStringContainsString("owner's real numbers", $corp['rhythm']);
        $this->assertStringContainsString('never invent contact details', $corp['cta']);
    }

    /** A divider crosses its section's content unless that edge has padding of at least the divider height, on every device. */
    public function testBoldVibrantDividerIsNoTallerThanItsPaddingOnEveryDevice(): void
    {
        $bold = DesignLanguages::get('bold-vibrant');
        $this->assertSame(1, preg_match('/slant divider (\d+)px high on desktop, (\d+)px on tablet and (\d+)px on phones \(each no taller than that block\'s bottom padding of (\d+)px, (\d+)px and (\d+)px\)/', $bold['rhythm'], $d), 'divider sentence');
        $this->assertSame(1, preg_match('/(\d+)px top and bottom on desktop, (\d+)px on tablet, (\d+)px on phone/', $bold['section_padding'], $p), 'padding sentence');
        for ($i = 1; $i <= 3; $i++) {
            $this->assertSame((int) $p[$i], (int) $d[$i + 3], "the stated bottom padding matches section_padding on device $i");
            $this->assertLessThanOrEqual((int) $d[$i + 3], (int) $d[$i], "divider height on device $i fits the padding");
        }
    }

    /** Small gold or accent text over a photo and its scrim is not guaranteed readable; only the inverse colour is. */
    public function testNoAccentColouredTextIsPlacedOverAPhoto(): void
    {
        foreach (DesignLanguages::ids() as $id) {
            $lang = DesignLanguages::get($id);
            foreach (['hero', 'cta'] as $field) {
                if (1 === preg_match('/photo|scrim|overlay/i', $lang[$field])) {
                    $this->assertDoesNotMatchRegularExpression('/\b(?:gold|accent)(?:-| )?(?:coloured )?(?:eyebrow|label|headline|line)\b|\bgold text\b|\baccent-coloured text\b/i', $lang[$field], "$id.$field puts accent text over a photo");
                }
            }
        }
        $luxe = DesignLanguages::get('luxe');
        $this->assertMatchesRegularExpression('/eyebrow[^,.;:]*inverse text colour/', $luxe['hero']);
        $this->assertStringContainsString('never for text over a photo', $luxe['shadow']);
    }

    public function testBoldVibrantHasNeitherADividerAndAPullUpOnTheHeroNorAnUnscaledWideHeadline(): void
    {
        $bold = DesignLanguages::get('bold-vibrant');
        $this->assertStringContainsString('negative top margin', $bold['hero']);
        $this->assertStringContainsString('The hero has no divider', $bold['rhythm']);
        $this->assertDoesNotMatchRegularExpression('/(?:close|end|finish)s? the hero with a [a-z ]*divider/i', $bold['rhythm']);
        $this->assertMatchesRegularExpression('/slant divider 60px high[^.]*cobalt|cobalt[^.]*slant divider 60px high/', $bold['rhythm']);
        $this->assertMatchesRegularExpression('/size per device[^)]*smaller on phones/', $bold['hero']);
    }

    public function testLuxeNamesTheDividerModuleAndDoesNotCombineParallaxWithTheScrim(): void
    {
        $luxe = DesignLanguages::get('luxe');
        $this->assertStringContainsString('divider module', $luxe['cta']);
        $this->assertStringNotContainsStringIgnoringCase('rule above', $luxe['cta']);
        $this->assertStringNotContainsStringIgnoringCase('parallax', $luxe['rhythm'] . $luxe['hero'] . $luxe['cta'] . $luxe['shadow']);
        $this->assertStringContainsString('gradient scrim', $luxe['rhythm']);
    }

    /** Low-contrast accents (the bright ones) are never text colour; the brief says so where the accent is not readable on the page background. */
    public function testNewLanguagesWithUnreadableAccentTextSayNeverUseItAsTextColour(): void
    {
        foreach (self::newLanguageIds() as $id) {
            $c = DesignLanguages::get($id)['colors'];
            if (DesignLanguages::contrast($c['accent'], $c['bg']) < 4.5 || DesignLanguages::contrast($c['accent'], $c['surface']) < 4.5) {
                $prose = strtolower(DesignLanguages::get($id)['shadow'] . DesignLanguages::get($id)['rhythm']);
                $this->assertMatchesRegularExpression('/never[^.]*(as|for)[^.]*text/', $prose, "$id accent is not readable as text on its own background and the brief must say so");
            }
        }
    }

    public function testMarkdownPrintsTheTwoNewRolesWithTheirPurpose(): void
    {
        foreach (DesignLanguages::ids() as $id) {
            $lang = DesignLanguages::get($id);
            $md   = DesignLanguages::markdown($id);
            $this->assertStringContainsString($lang['colors']['inverse'], $md, $id);
            $this->assertStringContainsString($lang['colors']['overlay'], $md, $id);
            $this->assertStringContainsString('inverse: text on photos and dark bands', $md, $id);
            $this->assertStringContainsString('overlay: the scrim over photos', $md, $id);
            $this->assertStringNotContainsString('{{', $md, $id);
            $this->assertStringNotContainsString('<', $md, $id);
            $this->assertLessThan(5000, strlen($md), "$id brief is far below the 10,000-byte topic ceiling");
        }
    }

    public function testProseDoesNotPromiseWhatTheGuidesDoNotProve(): void
    {
        $playful = DesignLanguages::get('playful-pastel');
        $this->assertStringNotContainsString('highlighted', $playful['hero']);
        $this->assertStringNotContainsString('bounce', $playful['rhythm']);
        $this->assertStringContainsString('placeholder', DesignLanguages::get('corporate-trust')['hero']);
        $this->assertStringContainsString('never invent client names', DesignLanguages::get('corporate-trust')['hero']);
        $this->assertStringContainsString('never use the pink as a text or link colour', strtolower($playful['shadow']));

        $dead = ['highlighted', 'bounce', 'hand-feel', 'sticker', 'squiggle', 'floating shape', 'no blur', 'real-looking'];
        foreach (DesignLanguages::ids() as $id) {
            $lang = DesignLanguages::get($id);
            foreach (['rhythm', 'hero', 'shadow'] as $field) {
                foreach ($dead as $phrase) {
                    $this->assertStringNotContainsStringIgnoringCase($phrase, $lang[$field], "$id.$field promises \"$phrase\"");
                }
            }
        }
        // tech-mono names ONE rule for the neon accent, in both places.
        $tech = DesignLanguages::get('tech-mono');
        $this->assertStringContainsString('primary button and one small marker per section', $tech['shadow']);
        $this->assertStringContainsString('primary button and one small marker per section', $tech['rhythm']);
        $this->assertStringNotContainsString('one thing per screen', $tech['rhythm']);
    }

    public function testBodyFontsAreReadableFamilies(): void
    {
        $display = ['Pacifico', 'Lobster', 'Dancing Script', 'Great Vibes', 'Satisfy', 'Caveat', 'Permanent Marker', 'Kaushan Script', 'Sacramento', 'Allura'];
        foreach (DesignLanguages::ids() as $id) {
            $body = DesignLanguages::get($id)['fonts']['body'];
            $this->assertNotContains($body, $display, "$id body font is a script face");
        }
    }

    // ---- valid(): strict anchors, overlay rule, font length cap ----

    public function testValidRejectsATrailingNewlineEverywhereAnAnchorMatters(): void
    {
        $known = $this->knownFonts();
        $lang  = $this->okLanguage();
        $this->assertSame([], DesignLanguages::valid($lang, $known));

        $l = $lang; $l['colors']['bg'] = $lang['colors']['bg'] . "\n";
        $this->assertStringContainsString('colors.bg', implode("\n", DesignLanguages::valid($l, $known)));

        $l = $lang; $l['id'] = "glass-dark\n";
        $this->assertStringContainsString('id', implode("\n", DesignLanguages::valid($l, $known)));

        $l = $lang; $l['colors']['surface_glass'] = "rgba(255,255,255,0.06)\n";
        $this->assertStringContainsString('colors.surface_glass', implode("\n", DesignLanguages::valid($l, $known)));

        $l = $lang; $l['colors']['overlay'] = "rgba(0,0,0,0.6)\n";
        $this->assertStringContainsString('colors.overlay', implode("\n", DesignLanguages::valid($l, $known)));

        $l = $lang; $l['colors']['inverse'] = "#ffffff\n";
        $this->assertStringContainsString('colors.inverse', implode("\n", DesignLanguages::valid($l, $known)));

        $l = $lang; $l['fonts']['body'] = "Inter\n";
        $this->assertSame([], DesignLanguages::valid($l, $known), 'a stack member is trimmed, so a trailing newline around a family is harmless');
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function overlayValues(): array
    {
        return [
            'alpha at the low end'  => ['rgba(10,10,10,0.35)', true],
            'alpha at the high end' => ['rgba(10,10,10,0.8)', true],
            'alpha below range'     => ['rgba(10,10,10,0.34)', false],
            'alpha above range'     => ['rgba(10,10,10,0.81)', false],
            'alpha 0.5 no zero'     => ['rgba(10,10,10,.5)', true],
            'hex is not a scrim'    => ['#000000', false],
            'channel over 255'      => ['rgba(300,10,10,0.5)', false],
            'alpha one'             => ['rgba(10,10,10,1)', false],
            'not a string'          => ['', false],
        ];
    }

    #[DataProvider('overlayValues')]
    public function testValidEnforcesTheOverlayRange(string $value, bool $ok): void
    {
        $lang = $this->okLanguage();
        $lang['colors']['overlay'] = $value;
        $problems = implode("\n", DesignLanguages::valid($lang, $this->knownFonts()));
        if ($ok) {
            $this->assertSame('', $problems);
        } else {
            $this->assertStringContainsString('colors.overlay', $problems);
        }
    }

    public function testValidRequiresInverseAndOverlay(): void
    {
        foreach (['inverse', 'overlay'] as $key) {
            $lang = $this->okLanguage();
            unset($lang['colors'][$key]);
            $this->assertStringContainsString('colors.' . $key, implode("\n", DesignLanguages::valid($lang, $this->knownFonts())));
        }
    }

    public function testValidCapsAFontFamilyAtOneHundredCharacters(): void
    {
        $lang = $this->okLanguage();
        $lang['fonts']['body'] = str_repeat('a', 100);
        $this->assertSame([], DesignLanguages::valid($lang));
        $lang['fonts']['body'] = str_repeat('a', 101);
        $this->assertStringContainsString('fonts.body', implode("\n", DesignLanguages::valid($lang)));
    }

    public function testParseIsMarkedInternal(): void
    {
        $doc = (new \ReflectionMethod(DesignLanguages::class, 'parse'))->getDocComment();
        $this->assertIsString($doc);
        $this->assertStringContainsString('@internal', $doc);
    }

    public function testContrastKnownValues(): void
    {
        $this->assertEqualsWithDelta(21.0, DesignLanguages::contrast('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(21.0, DesignLanguages::contrast('#fff', '#000'), 0.01);
        $this->assertEqualsWithDelta(1.0, DesignLanguages::contrast('#336699', '#336699'), 0.001);
        $this->assertLessThan(4.5, DesignLanguages::contrast('#777777', '#ffffff'));
    }

    public function testUnknownIdReturnsNull(): void
    {
        $this->assertNull(DesignLanguages::get('no-such-language'));
        $this->assertNull(DesignLanguages::get(''));
    }

    public function testMarkdownIndexListsEveryId(): void
    {
        $index = DesignLanguages::markdown();
        foreach (DesignLanguages::ids() as $id) {
            $this->assertStringContainsString($id, $index);
        }
        $full = DesignLanguages::markdown('editorial');
        $this->assertStringContainsString('#faf7f2', strtolower($full));
        $this->assertStringContainsString('Playfair Display', $full);
        $this->assertStringContainsString('Unknown', DesignLanguages::markdown('nope'));
    }

    public function testMoodsMatchRecipeVocabulary(): void
    {
        $this->assertSame(['any', ...DesignLanguages::ids()], SectionRecipes::moods());
    }

    public function testTheLanguagesAreReadOnceAndClearCacheReadsThemAgain(): void
    {
        DesignLanguages::clearCache();
        $ids = DesignLanguages::ids();
        $this->assertNotEmpty( $ids );

        $cache = new \ReflectionProperty( DesignLanguages::class, 'cache' );
        $this->assertIsArray( $cache->getValue(), 'the first call memoises' );

        // Plant a sentinel: a second call that re-read the file would not see it.
        $cache->setValue( null, [ [ 'id' => 'memo-sentinel' ] ] );
        try {
            $this->assertSame( [ 'memo-sentinel' ], DesignLanguages::ids(), 'the second call serves the memo without reading the file' );
            DesignLanguages::clearCache();
            $this->assertNull( $cache->getValue() );
            $this->assertSame( $ids, DesignLanguages::ids(), 'clearCache() restores the file data' );
        } finally {
            DesignLanguages::clearCache();
        }
    }

    // ---- valid(): the validity check every shipped (and every future) language must pass ----

    /** @return list<string> the Divi 5.15.1 Google font family names (fixtures/shapes/google-fonts-names.txt) */
    private function knownFonts(): array
    {
        $lines = file(__DIR__ . '/../fixtures/shapes/google-fonts-names.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertGreaterThan(1000, count($lines));
        return $lines;
    }

    public function testTheTypographyFixtureFamiliesAreInTheExtractedList(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/shapes/fonts-5.15.1.json'), true);
        $this->assertIsArray($fixture);
        $known = $this->knownFonts();
        foreach (array_keys($fixture['families']) as $family) {
            $this->assertContains($family, $known, $family);
        }
    }

    /** @return array<string,mixed> */
    private function okLanguage(): array
    {
        $lang = DesignLanguages::get('glass-dark');
        $this->assertIsArray($lang);
        return $lang;
    }

    public function testEveryShippedLanguageIsValidAndUsesRealFonts(): void
    {
        $known = $this->knownFonts();
        $seen  = [];
        foreach (DesignLanguages::ids() as $id) {
            $lang = DesignLanguages::get($id);
            $this->assertSame([], DesignLanguages::valid($lang, $known), $id . ' (with the font list)');
            $this->assertSame([], DesignLanguages::valid($lang), $id . ' (without the font list)');
            $this->assertArrayNotHasKey($id, $seen, 'ids are unique');
            $seen[$id] = true;
            foreach ($lang['fonts'] as $role => $font) {
                foreach (explode(',', $font) as $family) {
                    $family = trim($family);
                    $this->assertTrue(in_array($family, $known, true) || in_array($family, DesignLanguages::WEB_SAFE_FONTS, true), "$id.fonts.$role family $family");
                }
            }
        }
    }

    /** @return array<string,array{0:callable(array<string,mixed>):array<string,mixed>,1:string}> */
    public static function brokenLanguages(): array
    {
        return [
            'bad hex'            => [static function (array $l): array { $l['colors']['accent'] = '#12345'; return $l; }, 'colors.accent'],
            'hex without hash'   => [static function (array $l): array { $l['colors']['bg'] = '0b1020'; return $l; }, 'colors.bg'],
            'named colour'       => [static function (array $l): array { $l['colors']['text'] = 'red'; return $l; }, 'colors.text'],
            'alpha one'          => [static function (array $l): array { $l['colors']['surface_glass'] = 'rgba(255,255,255,1)'; return $l; }, 'colors.surface_glass'],
            'alpha zero'         => [static function (array $l): array { $l['colors']['surface_glass'] = 'rgba(255,255,255,0)'; return $l; }, 'colors.surface_glass'],
            'alpha over one'     => [static function (array $l): array { $l['colors']['surface_glass'] = 'rgba(255,255,255,1.5)'; return $l; }, 'colors.surface_glass'],
            'channel over 255'   => [static function (array $l): array { $l['colors']['surface_glass'] = 'rgba(256,255,255,0.5)'; return $l; }, 'colors.surface_glass'],
            'rgba on bg'         => [static function (array $l): array { $l['colors']['bg'] = 'rgba(0,0,0,0.5)'; return $l; }, 'colors.bg'],
            'missing colour'     => [static function (array $l): array { unset($l['colors']['border']); return $l; }, 'colors.border'],
            'unknown font'       => [static function (array $l): array { $l['fonts']['display'] = 'Definitely Not A Font'; return $l; }, 'fonts.display'],
            'unknown in a stack' => [static function (array $l): array { $l['fonts']['body'] = 'Inter, Nope Sans, sans-serif'; return $l; }, 'fonts.body'],
            'quote in font'      => [static function (array $l): array { $l['fonts']['body'] = 'Evil" Font'; return $l; }, 'fonts.body'],
            'backslash in font'  => [static function (array $l): array { $l['fonts']['eyebrow'] = 'Evil\\Font'; return $l; }, 'fonts.eyebrow'],
            'empty stack member' => [static function (array $l): array { $l['fonts']['body'] = 'Inter,,serif'; return $l; }, 'fonts.body'],
            'missing font'       => [static function (array $l): array { unset($l['fonts']['eyebrow']); return $l; }, 'fonts.eyebrow'],
            'missing field'      => [static function (array $l): array { unset($l['hero']); return $l; }, 'hero'],
            'blank field'        => [static function (array $l): array { $l['cta'] = '   '; return $l; }, 'cta'],
            'non-string field'   => [static function (array $l): array { $l['summary'] = ['x']; return $l; }, 'summary'],
            'no colors at all'   => [static function (array $l): array { unset($l['colors']); return $l; }, 'colors'],
            'missing kit'        => [static function (array $l): array { unset($l['kit']); return $l; }, 'kit'],
            'unknown kit'        => [static function (array $l): array { $l['kit'] = 'no-such-kit'; return $l; }, 'kit'],
            'non-string kit'     => [static function (array $l): array { $l['kit'] = ['outline-2']; return $l; }, 'kit'],
            'bad motion'         => [static function (array $l): array { $l['motion'] = 'wild'; return $l; }, 'motion'],
            'id not kebab'       => [static function (array $l): array { $l['id'] = 'Glass_Dark'; return $l; }, 'id'],
            'id missing'         => [static function (array $l): array { unset($l['id']); return $l; }, 'id'],
            'unknown layout'     => [static function (array $l): array { $l['favors'][] = 'masonry'; return $l; }, 'favors'],
            'avoids unknown'     => [static function (array $l): array { $l['avoids'] = ['masonry']; return $l; }, 'avoids'],
            'empty favors'       => [static function (array $l): array { $l['favors'] = []; return $l; }, 'favors'],
            'favors and avoids'  => [static function (array $l): array { $l['avoids'][] = $l['favors'][0]; return $l; }, 'favors'],
            'template in prose'  => [static function (array $l): array { $l['summary'] .= ' {{aied:color:bg}}'; return $l; }, 'summary'],
            'angle in prose'     => [static function (array $l): array { $l['hero'] .= ' <b>x</b>'; return $l; }, 'hero'],
            'backtick in prose'  => [static function (array $l): array { $l['rhythm'] .= ' `code`'; return $l; }, 'rhythm'],
            'angle in a colour'  => [static function (array $l): array { $l['colors']['muted'] = '#9aa4c0<'; return $l; }, 'colors.muted'],
        ];
    }

    #[DataProvider('brokenLanguages')]
    /**
     * @param callable(array<string,mixed>):array<string,mixed> $break
     */
    public function testValidRejectsEachBrokenLanguageAndNamesTheField(callable $break, string $field): void
    {
        $this->assertSame([], DesignLanguages::valid($this->okLanguage(), $this->knownFonts()), 'the base fixture is valid');
        $problems = DesignLanguages::valid($break($this->okLanguage()), $this->knownFonts());
        $this->assertNotSame([], $problems);
        $this->assertStringContainsString($field, implode("\n", $problems));
    }

    public function testFontStacksAreCheckedFamilyByFamilyAgainstTheListOrTheWebSafeSet(): void
    {
        $known = $this->knownFonts();
        $lang  = $this->okLanguage();
        foreach (['Georgia, serif', 'Inter, system-ui, sans-serif', 'Times New Roman', 'Playfair Display, Georgia, serif'] as $ok) {
            $lang['fonts']['body'] = $ok;
            $this->assertSame([], DesignLanguages::valid($lang, $known), $ok);
        }
        $lang['fonts']['body'] = 'Courier New, Mystery Mono';
        $this->assertNotSame([], DesignLanguages::valid($lang, $known));
        // Without a font list the existence check is skipped, but the character set still applies.
        $lang['fonts']['body'] = 'Mystery Mono';
        $this->assertSame([], DesignLanguages::valid($lang));
        $lang['fonts']['body'] = 'Mystery" Mono';
        $this->assertNotSame([], DesignLanguages::valid($lang));
    }

    public function testACorruptLanguagesFileYieldsAnEmptyListNeverAFatal(): void
    {
        foreach (['', 'not json {', '"a string"', '123', 'null', '{"id":"x"}x', "\xff\xfe"] as $garbage) {
            $this->assertSame([], DesignLanguages::parse($garbage), 'garbage: ' . bin2hex($garbage));
        }
        $this->assertSame([], DesignLanguages::parse('[1,"a",null,{"title":"no id"},{"id":7}]'), 'entries without a string id are dropped');
        $this->assertSame([['id' => 'ok']], DesignLanguages::parse('[1,{"id":"ok"},{"id":7}]'));

        // End to end through the memo: an empty load means no languages, not an error.
        $cache = new \ReflectionProperty(DesignLanguages::class, 'cache');
        $cache->setValue(null, []);
        try {
            $this->assertSame([], DesignLanguages::ids());
            $this->assertNull(DesignLanguages::get('editorial'));
            $this->assertStringContainsString('Unknown', DesignLanguages::markdown('editorial'));
        } finally {
            DesignLanguages::clearCache();
        }
    }

    public function testEveryLanguageNamesAShippedStyleKit(): void
    {
        $this->assertNotEmpty(StyleKits::ids());
        foreach (DesignLanguages::ids() as $id) {
            $kit = DesignLanguages::get($id)['kit'] ?? null;
            $this->assertIsString($kit, "$id.kit");
            $this->assertNotNull(StyleKits::get($kit), "$id.kit names a shipped kit");
            $this->assertSame([], StyleKits::problems(StyleKits::get($kit)), "$id.kit is a valid kit");
        }
    }

    public function testEveryLanguageHasItsOwnKitAndTheTwoGeneralKitsStay(): void
    {
        $kits = array_map(static fn (string $id): string => (string) DesignLanguages::get($id)['kit'], DesignLanguages::ids());
        $this->assertSame($kits, array_values(array_unique($kits)), 'each design language points at its own kit');
        $this->assertNotContains('soft-solid', $kits, 'soft-solid stays a general kit, not a language default');
        $this->assertNotContains('outline-2', $kits, 'outline-2 stays a general kit, not a language default');
        $this->assertContains('soft-solid', StyleKits::ids());
        $this->assertContains('outline-2', StyleKits::ids());
        foreach (DesignLanguages::ids() as $id) {
            $kit = (array) StyleKits::get((string) DesignLanguages::get($id)['kit']);
            // Every language's hero and cta prose describes an accent-filled primary button (editorial's "primary button",
            // glass-dark's "solid accent", luxe's "gold button" and so on): its own kit is a solid accent kit.
            $this->assertSame(['solid', 'accent'], [$kit['fill'], $kit['color']], "$id: an accent-filled primary");
        }
    }

    /**
     * The radius family of a px value: square 0-4px, soft 6-16px, round 20-48px, pill 999px; null outside them
     * (5px or 17-19px belongs to no family and is a contradiction by construction).
     */
    private static function radiusFamily(int $px): ?string
    {
        if (999 === $px) {
            return 'pill';
        }
        if ($px >= 0 && $px <= 4) {
            return 'square';
        }
        if ($px >= 6 && $px <= 16) {
            return 'soft';
        }
        if ($px >= 20 && $px <= 48) {
            return 'round';
        }
        return null;
    }

    /**
     * The button and card radius a language's `radius` prose states, read clause by clause: the prose is cut at `;`,
     * `.` and at every comma that is followed by a px value ("20px on cards, 12px on buttons"), and each clause gives its
     * FIRST px value to the buttons and cards it names (or to both when it says "everywhere").
     *
     * @return array{button:?int,card:?int}
     */
    private static function proseRadius(string $prose): array
    {
        $out = ['button' => null, 'card' => null];
        foreach ((array) preg_split('/[;.]\s*|,\s*(?=\d+px)/', $prose) as $clause) {
            $clause = (string) $clause;
            if (1 !== preg_match('/(\d+)px/', $clause, $m)) {
                continue;
            }
            $everywhere = str_contains($clause, 'everywhere');
            foreach (['button' => '/\bbuttons?\b/', 'card' => '/\bcards?\b/'] as $what => $noun) {
                if (null === $out[$what] && ($everywhere || 1 === preg_match($noun, $clause))) {
                    $out[$what] = (int) $m[1];
                }
            }
        }
        return $out;
    }

    /**
     * What is wrong between a language's radius prose and a kit ([] = they agree).
     *
     * @param array<string,mixed> $kit
     * @return list<string>
     */
    private static function radiusContradictions(string $prose, array $kit): array
    {
        $out    = [];
        $stated = self::proseRadius($prose);
        foreach (['button' => 'control', 'card' => 'card'] as $what => $key) {
            if (null === $stated[$what]) {
                $out[] = "the prose states no $what radius the check can read";
                continue;
            }
            $want = self::radiusFamily($stated[$what]);
            $have = self::radiusFamily((int) $kit['radius'][$key]);
            if (null === $want || $want !== $have) {
                $out[] = sprintf('%s: prose %dpx (%s), kit radius.%s %s (%s)', $what, $stated[$what], $want ?? 'no family', $key, $kit['radius'][$key], $have ?? 'no family');
            }
        }
        return $out;
    }

    public function testEachLanguagesKitAgreesWithItsRadiusProse(): void
    {
        $problems = [];
        foreach (DesignLanguages::ids() as $id) {
            $lang = (array) DesignLanguages::get($id);
            $kit  = (array) StyleKits::get((string) $lang['kit']);
            foreach (self::radiusContradictions((string) $lang['radius'], $kit) as $p) {
                $problems[] = "$id ({$lang['kit']}): $p";
            }
        }
        $this->assertSame([], $problems);
    }

    public function testTheRadiusCheckReadsTheProseAndCatchesAContradiction(): void
    {
        $this->assertSame(['button' => 12, 'card' => 20], self::proseRadius('20px on cards, 12px on buttons and images. Keep corners equal.'));
        $this->assertSame(['button' => 999, 'card' => 28], self::proseRadius('Generous: 28px on cards and panels, 999px on buttons and tags, images 32px.'));
        $this->assertSame(['button' => 6, 'card' => 6], self::proseRadius('Small: 6px on cards, buttons and inputs; images square or 6px.'));
        $this->assertSame(['button' => 0, 'card' => 0], self::proseRadius('0px everywhere: cards, panels, images, buttons and inputs.'));
        $this->assertSame(['button' => 8, 'card' => 32], self::proseRadius('Sharp: images square; cards and panels 32px on two corners and 0px on the others; buttons 8px.'));
        $kit = ['radius' => ['control' => '999px', 'card' => '28px', 'media' => '0px']];
        $this->assertSame([], self::radiusContradictions('28px on cards, 999px on buttons.', $kit));
        $this->assertCount(1, self::radiusContradictions('28px on cards, 4px on buttons.', $kit), 'a pill kit for square-button prose');
        $this->assertCount(2, self::radiusContradictions('2px on buttons and cards.', $kit));
        $this->assertCount(1, self::radiusContradictions('28px on cards, 5px on buttons.', $kit), '5px is in no family');
        $this->assertCount(2, self::radiusContradictions('No numbers at all.', $kit), 'unreadable prose fails loudly');
        $this->assertSame('square', self::radiusFamily(4));
        $this->assertSame('soft', self::radiusFamily(6));
        $this->assertSame('soft', self::radiusFamily(16));
        $this->assertSame('round', self::radiusFamily(20));
        $this->assertSame('pill', self::radiusFamily(999));
        $this->assertNull(self::radiusFamily(18));
    }

    /**
     * Why a language's kit may use an effect: the passage of its own prose (shadow, rhythm, hero and cta) that asks for it,
     * or null when the prose does not ask (or forbids it). soft shadow: the prose asks for a soft shadow and does not say
     * "no shadows"; hard shadow: it asks for an offset shadow; lift: a button that lifts; underline: an underlined button;
     * a dashed, dotted or double border: hairline or ruled structure, or the style itself.
     *
     * @param array<string,mixed> $lang
     */
    private static function effectAsk(array $lang, string $effect): ?string
    {
        $prose  = strtolower($lang['shadow'] . ' ' . $lang['rhythm'] . ' ' . $lang['hero'] . ' ' . $lang['cta']);
        $forbid = 1 === preg_match('/\bno (drop )?shadows\b|buttons get no shadow/', $prose);
        $asks   = match ($effect) {
            'soft'   => !$forbid && 1 === preg_match('/\bsoft and (warm|cool)\b|\bsoft shadow\b/', $prose),
            'hard'   => !$forbid && str_contains($prose, 'offset shadow'),
            'lift'   => 1 === preg_match('/\bbuttons? (lifts?|rises?)\b/', $prose),
            'bottom' => 1 === preg_match('/\bunderlined? buttons?\b/', $prose),
            'dashed', 'dotted', 'double' => 1 === preg_match('/\bhairline\b|\bruled\b|\b' . $effect . '\b/', $prose),
            default  => false,
        };
        return $asks ? $effect : null;
    }

    public function testEachLanguagesKitUsesAnEffectOnlyWhereItsProseAsksForIt(): void
    {
        $problems = [];
        foreach (DesignLanguages::ids() as $id) {
            $lang = (array) DesignLanguages::get($id);
            $kit  = (array) StyleKits::get((string) $lang['kit']);
            $used = array_filter([
                'none' !== $kit['shadow']['rest'] ? $kit['shadow']['rest'] : null,
                'none' !== $kit['shadow']['hover'] ? $kit['shadow']['hover'] : null,
                'lift' === $kit['hover'] ? 'lift' : null,
                'bottom' === ($kit['border']['sides'] ?? 'all') ? 'bottom' : null,
                'solid' !== $kit['border']['style'] ? $kit['border']['style'] : null,
            ]);
            foreach (array_unique($used) as $effect) {
                if (null === self::effectAsk($lang, $effect)) {
                    $problems[] = "$id ({$lang['kit']}) uses $effect but its prose does not ask for it";
                }
            }
        }
        $this->assertSame([], $problems);
        // The check reads what it should.
        $this->assertNull(self::effectAsk((array) DesignLanguages::get('brutalist-flat'), 'hard'), 'brutalist-flat says no shadows at all');
        $this->assertNull(self::effectAsk((array) DesignLanguages::get('glass-dark'), 'soft'), 'glass-dark says no drop shadows');
        $this->assertNull(self::effectAsk((array) DesignLanguages::get('soft-saas'), 'soft'), 'soft-saas buttons get no shadow of their own');
        $this->assertSame('hard', self::effectAsk((array) DesignLanguages::get('playful-pastel'), 'hard'));
        $this->assertSame('soft', self::effectAsk((array) DesignLanguages::get('organic-warm'), 'soft'));
        $this->assertSame('dashed', self::effectAsk((array) DesignLanguages::get('tech-mono'), 'dashed'));
    }

    /**
     * What a kit's buttons look like at a glance on a light section, as one string: corner family (sharp 0-2px, small
     * 4-8px, round 12-48px, pill), the primary's visible frame (width and colour, or none when the border is 0px or the
     * fill colour), the secondary (text-only, or its outline width and style), underline, shadow pair and lift. Capital
     * letters, weight, letter spacing and hover colour changes are left out: they change the label or the hover colours,
     * not the button's shape, so two kits that differ only there count as one look.
     *
     * @param array<string,mixed> $kit
     */
    private static function look(array $kit): string
    {
        $r      = (int) $kit['radius']['control'];
        $corner = 999 === $r ? 'pill' : ($r <= 2 ? 'sharp' : ($r <= 8 ? 'small' : 'round'));
        $b      = $kit['border'];
        $frame  = 'solid' === $kit['fill'] && ('0px' === $b['width'] || 'same' === $b['color']) ? 'none' : $b['width'] . ' ' . $b['color'];
        $second = 'solid' === $kit['fill'] && '0px' === $b['width'] ? 'text-only' : $b['width'] . ' ' . $b['style'];
        return implode('|', [$corner, $kit['fill'], $frame, $second, $b['sides'] ?? 'all', $kit['shadow']['rest'] . '/' . $kit['shadow']['hover'], 'lift' === $kit['hover'] ? 'lift' : '-']);
    }

    public function testTheLanguagesKitsShowAtLeastEightDistinctButtonLooks(): void
    {
        $looks = [];
        foreach (DesignLanguages::ids() as $id) {
            $looks[self::look((array) StyleKits::get((string) DesignLanguages::get($id)['kit']))][] = $id;
        }
        $this->assertGreaterThanOrEqual(8, count($looks), "distinct looks:\n" . json_encode($looks, JSON_PRETTY_PRINT));
    }

    public function testMarkdownNamesTheLanguagesStyleKit(): void
    {
        foreach (DesignLanguages::ids() as $id) {
            $lines = explode("\n", DesignLanguages::markdown($id));
            $this->assertContains('- Style kit: ' . DesignLanguages::get($id)['kit'], $lines, $id);
        }
    }

    // ---- Type adapter (plan 2026-10-24-type-adapter.md, Task 2): language-owned headline type ----

    /** Languages whose own prose asks for capital headlines. Shrink-only (Ruling 6); the cap is asserted. */
    private const UPPERCASE_HEADLINE_LANGUAGES = [ 'brutalist-flat' ];

    /**
     * Every sentence of the prose that names a weight, capital letters or a letter spacing, with the data it claims.
     * [ language, field, exact phrase, role, key, expected value ]
     */
    private const PROSE_CLAIMS = [
        [ 'editorial', 'hero', 'small uppercase eyebrow', 'eyebrow', 'capitalization', 'uppercase' ],
        [ 'glass-dark', 'hero', 'a bold headline', 'display', 'weight', '700' ],
        [ 'bold-vibrant', 'hero', 'weight 800', 'display', 'weight', '800' ],
        [ 'brutalist-flat', 'hero', 'weight 900, in capital letters', 'display', 'weight', '900' ],
        [ 'brutalist-flat', 'hero', 'weight 900, in capital letters', 'display', 'capitalization', 'uppercase' ],
        [ 'brutalist-flat', 'cta', 'a headline in capital letters', 'display', 'capitalization', 'uppercase' ],
        [ 'brutalist-flat', 'rhythm', 'mono face in capital letters for labels', 'eyebrow', 'capitalization', 'uppercase' ],
        [ 'luxe', 'rhythm', 'serif at weight 300', 'display', 'weight', '300' ],
        [ 'luxe', 'rhythm', 'Eyebrow labels are in capital letters with 3px letter spacing', 'eyebrow', 'capitalization', 'uppercase' ],
        [ 'luxe', 'rhythm', 'Eyebrow labels are in capital letters with 3px letter spacing', 'eyebrow', 'letterSpacing', '3px' ],
        [ 'luxe', 'hero', 'a small eyebrow in capital letters', 'eyebrow', 'capitalization', 'uppercase' ],
    ];

    /** @return array<string,mixed> Divi's own font facts for the families the languages use (fixtures/shapes/font-weights.json) */
    private function fontWeights(): array
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/shapes/font-weights.json'), true);
        $this->assertIsArray($fixture);
        $this->assertIsArray($fixture['families'] ?? null);
        return $fixture;
    }

    /** @return list<string> the family names of every font role of every shipped language, sorted */
    private function languageFamilies(): array
    {
        $out = [];
        foreach (DesignLanguages::ids() as $id) {
            foreach ((array) DesignLanguages::get($id)['fonts'] as $stack) {
                foreach (explode(',', (string) $stack) as $family) {
                    $out[trim($family)] = true;
                }
            }
        }
        $names = array_keys($out);
        sort($names, SORT_STRING);
        return $names;
    }

    public function testTheFontWeightsFixtureIsDivisOwnFileAndCoversEveryLanguageFamily(): void
    {
        $fixture = $this->fontWeights();
        $this->assertSame('5.15.1', $fixture['divi']);
        $this->assertStringContainsString('Divi/core/json-data/google-fonts.json', (string) $fixture['source']);
        $this->assertSame($this->languageFamilies(), array_keys($fixture['families']), 'exactly the families the languages use, sorted');
        $known = $this->knownFonts();
        foreach ($fixture['families'] as $family => $entry) {
            $this->assertContains($family, $known, $family);
            $this->assertIsArray($entry['variants'] ?? null, $family);
            if (isset($entry['axes'])) {
                $this->assertCount(2, $entry['axes']['wght'], $family);
                $this->assertLessThan($entry['axes']['wght'][1], $entry['axes']['wght'][0], $family);
            }
        }
        $this->assertArrayNotHasKey('axes', $fixture['families']['Space Mono'], 'the one static family');
        $this->assertSame([400, 900], $fixture['families']['Playfair Display']['axes']['wght']);
    }

    public function testEveryTypeWeightExistsInItsRolesFont(): void
    {
        $fixture = $this->fontWeights();
        foreach (DesignLanguages::ids() as $id) {
            $lang = (array) DesignLanguages::get($id);
            $this->assertSame([], DesignLanguages::typeProblems($lang, $fixture['families']), $id);
            $this->assertSame([], DesignLanguages::valid($lang, $this->knownFonts(), $fixture['families']), $id . ' via valid()');
        }
        // Planted failures: each names the weight it refuses.
        $lang = $this->okLanguage();

        $mono = $lang;
        $mono['fonts']['eyebrow'] = 'Space Mono';
        $mono['type']['eyebrow']['weight'] = '600';
        $this->assertStringContainsString('type.eyebrow.weight', implode("\n", DesignLanguages::typeProblems($mono, $fixture['families'])));
        $mono['type']['eyebrow']['weight'] = '700';
        $this->assertSame([], DesignLanguages::typeProblems($mono, $fixture['families']), 'Space Mono has a 700');
        $mono['type']['eyebrow']['weight'] = '400';
        $this->assertSame([], DesignLanguages::typeProblems($mono, $fixture['families']), 'regular is 400');

        $heavy = $lang;
        $heavy['fonts']['display'] = 'Cormorant Garamond';
        $heavy['type']['display']['weight'] = '800';
        $this->assertStringContainsString('type.display.weight', implode("\n", DesignLanguages::typeProblems($heavy, $fixture['families'])));

        $light = $lang;
        $light['fonts']['eyebrow'] = 'Fredoka';
        $light['type']['eyebrow']['weight'] = '200';
        $this->assertStringContainsString('type.eyebrow.weight', implode("\n", DesignLanguages::typeProblems($light, $fixture['families'])));

        $unknown = $lang;
        $unknown['fonts']['display'] = 'Mystery Sans';
        $this->assertStringContainsString('type.display.weight', implode("\n", DesignLanguages::typeProblems($unknown, $fixture['families'])));
        $this->assertSame([], DesignLanguages::typeProblems($unknown), 'without the fixture the font facts are not checked');
    }

    public function testTheTypeBoundsHold(): void
    {
        foreach (DesignLanguages::ids() as $id) {
            $type = (array) DesignLanguages::get($id)['type'];
            $this->assertSame(DesignLanguages::TYPE_KEYS, array_keys($type), $id);
            foreach (DesignLanguages::TYPE_KEYS as $role) {
                $this->assertSame(DesignLanguages::TYPE_ROLE_KEYS, array_keys((array) $type[$role]), "$id.$role");
                $this->assertContains($type[$role]['weight'], DesignLanguages::TYPE_WEIGHTS, "$id.$role weight");
                $this->assertSame(1, preg_match('/^(0|-?[1-9][0-9]?)px\z/', $type[$role]['letterSpacing']), "$id.$role tracking");
                [$lo, $hi] = DesignLanguages::TYPE_TRACKING_BOUNDS[$role];
                $this->assertGreaterThanOrEqual($lo, (int) $type[$role]['letterSpacing'], "$id.$role tracking lower bound");
                $this->assertLessThanOrEqual($hi, (int) $type[$role]['letterSpacing'], "$id.$role tracking upper bound");
                $this->assertContains($type[$role]['capitalization'], ['none', 'uppercase'], "$id.$role");
            }
            if ('uppercase' === $type['eyebrow']['capitalization']) {
                $this->assertGreaterThanOrEqual(0, (int) $type['eyebrow']['letterSpacing'], "$id capital eyebrows are never tightened");
            }
        }
    }

    /** @return array<string,array{0:callable(array<string,mixed>):array<string,mixed>,1:string}> */
    public static function brokenTypes(): array
    {
        return [
            'missing type'        => [static function (array $l): array { unset($l['type']); return $l; }, 'type'],
            'type not an object'  => [static function (array $l): array { $l['type'] = 'bold'; return $l; }, 'type'],
            'extra role'          => [static function (array $l): array { $l['type']['caption'] = $l['type']['title']; return $l; }, 'type.caption'],
            'missing role'        => [static function (array $l): array { unset($l['type']['title']); return $l; }, 'type.title'],
            'missing key'         => [static function (array $l): array { unset($l['type']['display']['weight']); return $l; }, 'type.display.weight'],
            'extra key'           => [static function (array $l): array { $l['type']['display']['size'] = '44px'; return $l; }, 'type.display.size'],
            'weight 650'          => [static function (array $l): array { $l['type']['display']['weight'] = '650'; return $l; }, 'type.display.weight'],
            'weight as int'       => [static function (array $l): array { $l['type']['display']['weight'] = 600; return $l; }, 'type.display.weight'],
            'half pixel'          => [static function (array $l): array { $l['type']['display']['letterSpacing'] = '-0.5px'; return $l; }, 'type.display.letterSpacing'],
            'negative zero'       => [static function (array $l): array { $l['type']['display']['letterSpacing'] = '-0px'; return $l; }, 'type.display.letterSpacing'],
            'em spacing'          => [static function (array $l): array { $l['type']['title']['letterSpacing'] = '1em'; return $l; }, 'type.title.letterSpacing'],
            'display tracked out' => [static function (array $l): array { $l['type']['display']['letterSpacing'] = '1px'; return $l; }, 'type.display.letterSpacing'],
            'display too tight'   => [static function (array $l): array { $l['type']['display']['letterSpacing'] = '-4px'; return $l; }, 'type.display.letterSpacing'],
            'title too wide'      => [static function (array $l): array { $l['type']['title']['letterSpacing'] = '2px'; return $l; }, 'type.title.letterSpacing'],
            'eyebrow tightened'   => [static function (array $l): array { $l['type']['eyebrow']['letterSpacing'] = '-1px'; return $l; }, 'type.eyebrow.letterSpacing'],
            'eyebrow too wide'    => [static function (array $l): array { $l['type']['eyebrow']['letterSpacing'] = '5px'; return $l; }, 'type.eyebrow.letterSpacing'],
            'lowercase'           => [static function (array $l): array { $l['type']['eyebrow']['capitalization'] = 'lowercase'; return $l; }, 'type.eyebrow.capitalization'],
            'capitalised word'    => [static function (array $l): array { $l['type']['eyebrow']['capitalization'] = 'None'; return $l; }, 'type.eyebrow.capitalization'],
        ];
    }

    #[DataProvider('brokenTypes')]
    /**
     * @param callable(array<string,mixed>):array<string,mixed> $break
     */
    public function testTypeProblemsCatchesEachBrokenShape(callable $break, string $field): void
    {
        $fixture = $this->fontWeights();
        $this->assertSame([], DesignLanguages::typeProblems($this->okLanguage(), $fixture['families']), 'the base fixture is valid');
        $broken = $break($this->okLanguage());
        $lines  = DesignLanguages::typeProblems($broken, $fixture['families']);
        $this->assertNotSame([], $lines);
        $this->assertStringContainsString($field, implode("\n", $lines));
        // valid() reports the same lines, so a language with a broken type cannot be accepted anywhere.
        $this->assertStringContainsString($field, implode("\n", DesignLanguages::valid($broken, $this->knownFonts(), $fixture['families'])));
    }

    public function testUppercaseHeadlinesOnlyWhereTheProseAsksForCapitals(): void
    {
        $this->assertCount(1, self::UPPERCASE_HEADLINE_LANGUAGES, 'shrink-only list, cap 1');
        foreach (DesignLanguages::ids() as $id) {
            $type = (array) DesignLanguages::get($id)['type'];
            $caps = 'uppercase' === $type['display']['capitalization'] || 'uppercase' === $type['title']['capitalization'];
            $this->assertSame($caps, in_array($id, self::UPPERCASE_HEADLINE_LANGUAGES, true), "$id uppercase headlines");
        }
        foreach (self::UPPERCASE_HEADLINE_LANGUAGES as $id) {
            $lang = (array) DesignLanguages::get($id);
            $this->assertTrue(str_contains((string) $lang['hero'], 'capital letters') || str_contains((string) $lang['cta'], 'capital letters'), $id);
        }
    }

    public function testTheTypeDataMatchesEveryProseClaim(): void
    {
        foreach (self::PROSE_CLAIMS as [$id, $field, $phrase, $role, $key, $expected]) {
            $lang = DesignLanguages::get($id);
            $this->assertIsArray($lang, $id);
            $this->assertStringContainsString($phrase, (string) $lang[$field], "$id.$field says: $phrase");
            $this->assertSame($expected, $lang['type'][$role][$key], "$id.$field claims $role $key = $expected");
        }
    }

    /**
     * The places in a language's prose that name a weight, capital letters or a letter spacing and are not inside a
     * phrase PROSE_CLAIMS checks.
     *
     * @param array<string,mixed> $lang
     * @return list<string>
     */
    private function uncoveredProseClaims(array $lang): array
    {
        $out = [];
        foreach (['title', 'summary', 'radius', 'section_padding', 'shadow', 'rhythm', 'hero', 'cta'] as $field) {
            $text = (string) ($lang[$field] ?? '');
            if (!preg_match_all('/weight \d+|capital letters|uppercase|upper-case|all caps|letter spacing|letter-spacing/i', $text, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($m[0] as [$hit, $at]) {
                $covered = false;
                foreach (self::PROSE_CLAIMS as [$id, $claimField, $phrase]) {
                    if ($id !== $lang['id'] || $claimField !== $field) {
                        continue;
                    }
                    $from = strpos($text, $phrase);
                    if (false !== $from && $at >= $from && $at + strlen($hit) <= $from + strlen($phrase)) {
                        $covered = true;
                    }
                }
                if (!$covered) {
                    $out[] = $lang['id'] . '.' . $field . ': ' . $hit;
                }
            }
        }
        return $out;
    }

    public function testEveryNamedWeightInTheProseIsAClaimTheTestChecks(): void
    {
        foreach (DesignLanguages::ids() as $id) {
            $this->assertSame([], $this->uncoveredProseClaims((array) DesignLanguages::get($id)), $id);
        }
        $lang = $this->okLanguage();
        $lang['hero'] .= ' Set the headline at weight 700 and in capital letters.';
        $this->assertCount(2, $this->uncoveredProseClaims($lang), 'a new claim without a check is found');
    }

    /**
     * @param list<array<string,mixed>> $languages
     * @return array<string,list<string>> type triples more than one language has
     */
    private static function sharedTriples(array $languages): array
    {
        $triples = [];
        foreach ($languages as $lang) {
            $triples[json_encode([ $lang['type']['display'], $lang['type']['title'], $lang['type']['eyebrow'] ])][] = $lang['id'];
        }
        return array_filter($triples, static fn(array $ids): bool => count($ids) > 1);
    }

    public function testNoTwoLanguagesShareATypeTriple(): void
    {
        $all = array_map(static fn(string $id): array => (array) DesignLanguages::get($id), DesignLanguages::ids());
        $this->assertSame([], self::sharedTriples($all), 'cap 0, no exemption file');
        // Planted: a copy of one language is found.
        $copy       = $all[0];
        $copy['id'] = 'copy';
        $this->assertCount(1, self::sharedTriples([ ...$all, $copy ]));
    }

    public function testTheTypeLineIsGeneratedFromTheData(): void
    {
        foreach (DesignLanguages::ids() as $id) {
            $lang  = (array) DesignLanguages::get($id);
            $line  = DesignLanguages::typeLine($lang);
            $md    = DesignLanguages::markdown($id);
            $this->assertSame(1, substr_count($md, $line), "$id Type line appears once");
            $lines = explode("\n", $md);
            $at    = array_search($line, $lines, true);
            $this->assertIsInt($at, $id);
            $this->assertStringStartsWith('- headings: ', $lines[$at - 1], "$id: right after the fonts line");
            $this->assertStringStartsWith('- Type: ', $line);
            $caps = 0;
            foreach (DesignLanguages::TYPE_KEYS as $role) {
                $this->assertStringContainsString('weight ' . $lang['type'][$role]['weight'], $line, "$id.$role");
                $this->assertStringContainsString('letter spacing ' . $lang['type'][$role]['letterSpacing'], $line, "$id.$role");
                $caps += 'uppercase' === $lang['type'][$role]['capitalization'] ? 1 : 0;
            }
            $this->assertSame($caps, substr_count($line, 'capital letters'), "$id: capital letters named once per uppercase role");
            $this->assertStringContainsString('topic=typography', $line);
            $this->assertLessThanOrEqual(DesignLanguages::GENERATED_LINE_MAX, strlen($line), $id);
            foreach (['{{', '<', '`'] as $bad) {
                $this->assertStringNotContainsString($bad, $line, $id);
            }
        }
        // Capitals are named only for an uppercase role.
        $lang = $this->okLanguage();
        foreach (DesignLanguages::TYPE_KEYS as $role) {
            $lang['type'][$role]['capitalization'] = 'none';
        }
        $this->assertStringNotContainsString('capital letters', DesignLanguages::typeLine($lang));
    }

    public function testEveryGeneratedLineFitsTheCap(): void
    {
        $this->assertSame(260, DesignLanguages::GENERATED_LINE_MAX);
        foreach (DesignLanguages::ids() as $id) {
            $lines = DesignLanguages::generatedLines((array) DesignLanguages::get($id));
            $this->assertSame(['type', 'section_space'], array_keys($lines), $id);
            foreach ($lines as $name => $line) {
                $this->assertLessThanOrEqual(DesignLanguages::GENERATED_LINE_MAX, strlen($line), "$id.$name");
            }
        }
    }

    /** Ruling 8 of the rhythm adapter, typed once: language => role => [desktop, tablet, phone]. */
    private const RHYTHM_TABLE = [
        'editorial'       => [ 'section' => [120, 80, 56], 'open' => [152, 104, 72], 'tight' => [72, 48, 32] ],
        'glass-dark'      => [ 'section' => [100, 72, 48], 'open' => [128, 88, 64],  'tight' => [64, 40, 32] ],
        'organic-warm'    => [ 'section' => [96, 64, 44],  'open' => [120, 80, 56],  'tight' => [56, 40, 28] ],
        'tech-mono'       => [ 'section' => [88, 64, 40],  'open' => [112, 80, 48],  'tight' => [56, 40, 28] ],
        'playful-pastel'  => [ 'section' => [88, 60, 40],  'open' => [112, 72, 48],  'tight' => [56, 40, 28] ],
        'corporate-trust' => [ 'section' => [96, 68, 44],  'open' => [120, 88, 56],  'tight' => [56, 40, 28] ],
        'soft-saas'       => [ 'section' => [104, 72, 48], 'open' => [128, 88, 64],  'tight' => [64, 40, 32] ],
        'bold-vibrant'    => [ 'section' => [80, 56, 36],  'open' => [104, 72, 48],  'tight' => [56, 32, 28] ],
        'brutalist-flat'  => [ 'section' => [64, 48, 32],  'open' => [80, 64, 40],   'tight' => [56, 32, 28] ],
        'luxe'            => [ 'section' => [160, 112, 72], 'open' => [200, 144, 88], 'tight' => [96, 64, 40] ],
    ];

    /** @return list<array<string,mixed>> */
    private function allLanguages(): array
    {
        return array_map(static fn(string $id): array => (array) DesignLanguages::get($id), DesignLanguages::ids());
    }

    /**
     * The prose-vs-data comparison: null when `section_space` is the one sentence in `section_padding`, else what differs.
     *
     * @param array<string,mixed> $lang
     */
    private static function sectionSpaceProseMismatch(array $lang): ?string
    {
        $id = (string) $lang['id'];
        if (1 !== preg_match_all('/(\d+)px top and bottom on desktop, (\d+)px on tablet, (\d+)px on phone/', (string) $lang['section_padding'], $m)) {
            return "$id: the prose does not state the padding exactly once";
        }
        $prose = [ (int) $m[1][0], (int) $m[2][0], (int) $m[3][0] ];
        $data  = [ $lang['section_space']['desktop'] ?? null, $lang['section_space']['tablet'] ?? null, $lang['section_space']['phone'] ?? null ];
        return $prose === $data ? null : "$id: section_space " . json_encode($data) . ' is not the sentence ' . json_encode($prose);
    }

    public function testEverySectionSpaceIsItsProseSentence(): void
    {
        foreach ($this->allLanguages() as $lang) {
            $this->assertNull(self::sectionSpaceProseMismatch($lang), "$lang[id]: section_space is the sentence");
        }
        // Planted: the same helper reports a mismatch, and a prose without the sentence.
        $lang                           = $this->okLanguage();
        $this->assertNull(self::sectionSpaceProseMismatch($lang), 'the base fixture agrees with its own prose');
        $planted                        = $lang;
        $planted['section_space']['phone'] = $lang['section_space']['phone'] + 4;
        $this->assertNotNull(self::sectionSpaceProseMismatch($planted), 'a planted data mismatch is reported');
        $noSentence                     = $lang;
        $noSentence['section_padding']  = 'Roomy sections.';
        $this->assertNotNull(self::sectionSpaceProseMismatch($noSentence), 'a prose without the sentence is reported');
    }

    public function testTheSectionSpaceBoundsHold(): void
    {
        foreach ($this->allLanguages() as $lang) {
            $this->assertSame(DesignLanguages::SECTION_SPACE_KEYS, array_keys($lang['section_space']), $lang['id']);
            $this->assertSame([], DesignLanguages::sectionSpaceProblems($lang), $lang['id']);
            $this->assertGreaterThanOrEqual(56, $lang['section_space']['desktop']);
            $this->assertLessThanOrEqual(200, $lang['section_space']['desktop']);
            $this->assertGreaterThanOrEqual(28, $lang['section_space']['phone']);
            $this->assertLessThanOrEqual(120, $lang['section_space']['phone']);
        }
    }

    /** @return array<string,array{0:callable(array<string,mixed>):array<string,mixed>,1:string}> */
    public static function brokenSectionSpaces(): array
    {
        return [
            'missing'            => [static function (array $l): array { unset($l['section_space']); return $l; }, 'section_space'],
            'not an object'      => [static function (array $l): array { $l['section_space'] = '120/80/56'; return $l; }, 'section_space'],
            'extra key'          => [static function (array $l): array { $l['section_space']['wide'] = 200; return $l; }, 'section_space.wide'],
            'missing tablet'     => [static function (array $l): array { unset($l['section_space']['tablet']); return $l; }, 'section_space.tablet'],
            'string number'      => [static function (array $l): array { $l['section_space']['desktop'] = '120'; return $l; }, 'section_space.desktop'],
            'float number'       => [static function (array $l): array { $l['section_space']['desktop'] = 120.0; return $l; }, 'section_space.desktop'],
            'bool'               => [static function (array $l): array { $l['section_space']['tablet'] = true; return $l; }, 'section_space.tablet'],
            'desktop 54'         => [static function (array $l): array { $l['section_space']['desktop'] = 54; return $l; }, 'section_space.desktop'],
            'desktop 208'        => [static function (array $l): array { $l['section_space']['desktop'] = 208; return $l; }, 'section_space.desktop'],
            'phone 24'           => [static function (array $l): array { $l['section_space']['phone'] = 24; return $l; }, 'section_space.phone'],
            'phone 128'          => [static function (array $l): array { $l['section_space']['phone'] = 128; return $l; }, 'section_space.phone'],
            'tablet above desktop' => [static function (array $l): array { $l['section_space']['tablet'] = 108; return $l; }, 'section_space.tablet'],
            'phone above tablet' => [static function (array $l): array { $l['section_space']['phone'] = 76; return $l; }, 'section_space.phone'],
        ];
    }

    #[DataProvider('brokenSectionSpaces')]
    /**
     * @param callable(array<string,mixed>):array<string,mixed> $break
     */
    public function testSectionSpaceProblemsCatchesEachBrokenShape(callable $break, string $field): void
    {
        $this->assertSame([], DesignLanguages::sectionSpaceProblems($this->okLanguage()), 'the base fixture is valid');
        $broken = $break($this->okLanguage());
        $lines  = DesignLanguages::sectionSpaceProblems($broken);
        $this->assertNotSame([], $lines);
        foreach ($lines as $line) {
            $this->assertStringStartsWith('section_space', $line);
        }
        $this->assertStringContainsString($field, implode("\n", $lines));
        // valid() reports the same lines, and a broken language yields no roles and no line.
        $this->assertStringContainsString($field, implode("\n", DesignLanguages::valid($broken, $this->knownFonts())));
        foreach (DesignLanguages::RHYTHM_ROLES as $role) {
            $this->assertNull(DesignLanguages::sectionSpace($broken, $role), $role);
        }
        $this->assertSame('', DesignLanguages::sectionSpaceLine($broken));
    }

    /** @param list<array<string,mixed>> $languages @return array<string,list<string>> */
    private static function sharedSectionSpaces(array $languages): array
    {
        $triples = [];
        foreach ($languages as $lang) {
            $triples[json_encode($lang['section_space'])][] = $lang['id'];
        }
        return array_filter($triples, static fn(array $ids): bool => count($ids) > 1);
    }

    public function testNoTwoLanguagesShareASectionSpaceTriple(): void
    {
        $all = $this->allLanguages();
        $this->assertSame([], self::sharedSectionSpaces($all), 'cap 0, no exemption file');
        $copy       = $all[0];
        $copy['id'] = 'copy';
        $this->assertCount(1, self::sharedSectionSpaces([ ...$all, $copy ]));
    }

    public function testTheDerivedRolesAreTheRuling8Table(): void
    {
        $this->assertSame(array_keys(self::RHYTHM_TABLE), DesignLanguages::ids());
        $this->assertSame([ 'section', 'open', 'tight' ], DesignLanguages::RHYTHM_ROLES);
        foreach (self::RHYTHM_TABLE as $id => $roles) {
            $lang = (array) DesignLanguages::get($id);
            foreach ($roles as $role => [ $d, $t, $p ]) {
                $this->assertSame([ 'desktop' => $d, 'tablet' => $t, 'phone' => $p ], DesignLanguages::sectionSpace($lang, $role), "$id.$role");
            }
            // `section` is the data itself, never rounded.
            $this->assertSame($lang['section_space'], DesignLanguages::sectionSpace($lang, 'section'), $id);
        }
        $this->assertSame(44, DesignLanguages::sectionSpace((array) DesignLanguages::get('organic-warm'), 'section')['phone'], '44 px stays 44 px');
        $this->assertNull(DesignLanguages::sectionSpace($this->okLanguage(), 'huge'));
        $this->assertNull(DesignLanguages::sectionSpace($this->okLanguage(), ''));
    }

    public function testTheDerivedRolesHoldForEveryTripleInsideTheBounds(): void
    {
        $bounds = [ 'desktop' => [ 56, 200 ], 'phone' => [ 28, 120 ] ];
        $count  = 0;
        for ($desktop = 56; $desktop <= 200; $desktop += 4) {
            for ($phone = 28; $phone <= min(120, $desktop); $phone += 4) {
                for ($tablet = $phone; $tablet <= $desktop; $tablet += 4) {
                    $lang   = $this->okLanguage();
                    $lang['section_space'] = [ 'desktop' => $desktop, 'tablet' => $tablet, 'phone' => $phone ];
                    $base   = DesignLanguages::sectionSpace($lang, 'section');
                    foreach ([ 'open', 'tight' ] as $role) {
                        $got = DesignLanguages::sectionSpace($lang, $role);
                        $this->assertIsArray($got);
                        foreach ($got as $key => $value) {
                            $this->assertIsInt($value);
                            // A whole multiple of 8, or the clamp bound of that key (desktop 56/200, phone 28/120),
                            // or, for tablet only, the phone or desktop result it was clamped to.
                            $ok = 0 === $value % 8 || in_array($value, $bounds[$key] ?? [], true) || ('tablet' === $key && ($value === $got['phone'] || $value === $got['desktop']));
                            $this->assertTrue($ok, "$role $desktop/$tablet/$phone $key=$value is not a multiple of 8 or a clamp");
                        }
                        $this->assertGreaterThanOrEqual(56, $got['desktop']);
                        $this->assertLessThanOrEqual(200, $got['desktop']);
                        $this->assertGreaterThanOrEqual(28, $got['phone']);
                        $this->assertLessThanOrEqual(120, $got['phone']);
                        $this->assertGreaterThanOrEqual($got['tablet'], $got['desktop'], "$role $desktop/$tablet/$phone");
                        $this->assertGreaterThanOrEqual($got['phone'], $got['tablet'], "$role $desktop/$tablet/$phone");
                    }
                    $open  = DesignLanguages::sectionSpace($lang, 'open');
                    $tight = DesignLanguages::sectionSpace($lang, 'tight');
                    foreach (DesignLanguages::SECTION_SPACE_KEYS as $key) {
                        $this->assertGreaterThanOrEqual($base[$key], $open[$key], "open >= section $desktop/$tablet/$phone $key");
                        $this->assertLessThanOrEqual($base[$key], $tight[$key], "tight <= section $desktop/$tablet/$phone $key");
                    }
                    ++$count;
                }
            }
        }
        $this->assertGreaterThan(10000, $count, 'the sweep covered the whole grid');
        // The half case rounds up; a result below the phone floor is raised to it.
        $lang = $this->okLanguage();
        $lang['section_space'] = [ 'desktop' => 100, 'tablet' => 48, 'phone' => 40 ];
        $this->assertSame(64, DesignLanguages::sectionSpace($lang, 'open')['tablet'], '48 x 5/4 = 60 rounds to 64');
        $this->assertSame(28, DesignLanguages::sectionSpace($lang, 'tight')['phone'], '40 x 3/5 = 24 is raised to the phone floor 28');
    }

    public function testTheShippedRolesAreStrictlyOrdered(): void
    {
        foreach ($this->allLanguages() as $lang) {
            $base  = DesignLanguages::sectionSpace($lang, 'section');
            $open  = DesignLanguages::sectionSpace($lang, 'open');
            $tight = DesignLanguages::sectionSpace($lang, 'tight');
            foreach (DesignLanguages::SECTION_SPACE_KEYS as $key) {
                $this->assertGreaterThan($base[$key], $open[$key], $lang['id'] . " open > section at $key");
                $this->assertGreaterThan($tight[$key], $base[$key], $lang['id'] . " section > tight at $key");
            }
        }
        // Planted: a language at the floor cannot get a tighter role, and the check sees it.
        $lang                  = $this->okLanguage();
        $lang['section_space'] = [ 'desktop' => 56, 'tablet' => 40, 'phone' => 28 ];
        $this->assertSame(56, DesignLanguages::sectionSpace($lang, 'tight')['desktop']);
        $this->assertSame(
            DesignLanguages::sectionSpace($lang, 'section')['desktop'],
            DesignLanguages::sectionSpace($lang, 'tight')['desktop'],
            'tight is not below section here, so the strict-order check would fail for such a language'
        );
    }

    public function testNoProsePromisesOnePaddingForEverySection(): void
    {
        $bad = static function (array $node) use (&$bad): bool {
            foreach ($node as $value) {
                if (is_array($value) ? $bad($value) : (is_string($value) && 1 === preg_match('/same padding on every section/i', $value))) {
                    return true;
                }
            }
            return false;
        };
        foreach ($this->allLanguages() as $lang) {
            $this->assertFalse($bad($lang), $lang['id'] . ': a language with open and tight roles cannot say every section has the same padding');
        }
        $planted                    = $this->okLanguage();
        $planted['section_padding'] .= ' The same padding on every section.';
        $this->assertTrue($bad($planted), 'a planted sentence is found');
    }

    public function testTheSectionSpacingLineIsGeneratedFromTheData(): void
    {
        $this->assertSame('module.decoration.spacing', DesignLanguages::SECTION_SPACE_PATH);
        foreach (DesignLanguages::ids() as $id) {
            $lang  = (array) DesignLanguages::get($id);
            $line  = DesignLanguages::sectionSpaceLine($lang);
            $md    = DesignLanguages::markdown($id);
            $this->assertSame(1, substr_count($md, $line), "$id Section spacing line appears once");
            $lines = explode("\n", $md);
            $at    = array_search($line, $lines, true);
            $this->assertIsInt($at, $id);
            $this->assertStringStartsWith('- Section padding: ', $lines[$at - 1], "$id: right after the Section padding line");
            $this->assertStringStartsWith('- Section spacing: ', $line);
            foreach (DesignLanguages::RHYTHM_ROLES as $role) {
                $s = DesignLanguages::sectionSpace($lang, $role);
                $this->assertStringContainsString($s['desktop'] . '/' . $s['tablet'] . '/' . $s['phone'] . 'px', $line, "$id.$role");
            }
            $this->assertStringContainsString(DesignLanguages::SECTION_SPACE_PATH, $line);
            $this->assertStringContainsString('never left or right', $line);
            $this->assertLessThanOrEqual(DesignLanguages::GENERATED_LINE_MAX, strlen($line), $id);
            foreach (['{{', '<', '`'] as $bad) {
                $this->assertStringNotContainsString($bad, $line, $id);
            }
            // The Type line stays where it was: right after the fonts line.
            $this->assertStringStartsWith('- headings: ', $lines[array_search(DesignLanguages::typeLine($lang), $lines, true) - 1]);
        }
    }
}
