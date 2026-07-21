<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AdminPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/AdminPage.php';

/**
 * The AI Editor admin cross-promotes JHMG's two converter plugins. This locks
 * the promo data shape and the rendered output for both placements (compact
 * Dashboard strip + full Upgrade section), including the not-yet-buyable
 * Divi→Elementor being shown as a waitlist, never a purchase.
 */
class ConverterPromoTest extends TestCase
{
    private function render(bool $compact): string
    {
        ob_start();
        ( new AdminPage() )->converterPromoSection($compact);
        return (string) ob_get_clean();
    }

    public function testPromoDataHasTwoConvertersWithAllKeys(): void
    {
        $promos = AdminPage::converterPromos();
        $this->assertCount(2, $promos);
        foreach ($promos as $p) {
            foreach (['name', 'blurb', 'chip', 'cta', 'url'] as $k) {
                $this->assertArrayHasKey($k, $p, "promo missing '$k'");
                $this->assertNotSame('', (string) $p[$k], "promo '$k' is empty");
            }
            $this->assertStringStartsWith('https://divi5lab.com/plugins/', (string) $p['url']);
        }
    }

    public function testFullSectionListsBothConvertersWithLinksAndPrice(): void
    {
        $html = $this->render(false);
        $this->assertStringContainsString('Elementor → Divi 5 Converter', $html);
        $this->assertStringContainsString('Divi → Elementor Converter', $html);
        $this->assertStringContainsString('divi5lab.com/plugins/elementor-to-divi-5', $html);
        $this->assertStringContainsString('divi5lab.com/plugins/divi-to-elementor', $html);
        $this->assertStringContainsString('$25/yr', $html);
    }

    public function testDiviToElementorShownAsWaitlistNotBuyable(): void
    {
        $html = strtolower($this->render(false));
        $this->assertStringContainsString('coming soon', $html);
        $this->assertStringContainsString('waitlist', $html);
    }

    public function testExternalLinksOpenSafely(): void
    {
        $html = $this->render(false);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener', $html);
    }

    public function testBothPlacementsRenderBothProducts(): void
    {
        foreach ([true, false] as $compact) {
            $html = $this->render($compact);
            $this->assertStringContainsString('elementor-to-divi-5', $html, 'compact=' . var_export($compact, true));
            $this->assertStringContainsString('divi-to-elementor', $html, 'compact=' . var_export($compact, true));
            $this->assertStringContainsString('More Divi tools from JHMG', $html);
        }
    }
}
