<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
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
}
