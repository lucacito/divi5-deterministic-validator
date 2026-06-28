<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\SiteGuide;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/SiteGuide.php';

/**
 * The full-site blueprint is the AI's contract for multi-page generation; it must
 * cover the workflow steps: page set, one design system, per-page slug, cross-link,
 * and the wiring tools.
 */
class SiteGuideTest extends TestCase
{
    public function testBlueprintCoversTheWorkflow(): void
    {
        $md = SiteGuide::markdown();
        $this->assertNotEmpty($md);
        foreach ([
            'page set', 'design system', 'create_page', 'slug', 'Cross-link',
            'set_front_page', 'set_primary_menu',
        ] as $needle) {
            $this->assertStringContainsString($needle, $md, "site guide should mention $needle");
        }
    }
}
