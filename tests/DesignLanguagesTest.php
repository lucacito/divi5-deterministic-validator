<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
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

    public function testThereAreSixLanguagesAndTheMoodsFollow(): void
    {
        $this->assertSame(['editorial', 'glass-dark', 'organic-warm', 'tech-mono', 'playful-pastel', 'corporate-trust'], DesignLanguages::ids());
        $this->assertSame(['any', ...DesignLanguages::ids()], SectionRecipes::moods());
        foreach (['organic-warm', 'tech-mono', 'playful-pastel', 'corporate-trust'] as $id) {
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
}
