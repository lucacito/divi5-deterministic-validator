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
        }
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
}
