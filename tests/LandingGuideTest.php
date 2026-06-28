<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\LandingGuide;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/LandingGuide.php';

/**
 * The landing-page blueprint is the AI's contract for conversion-focused single
 * pages. It must teach the full persuasion flow, business-type adaptation, the
 * copywriting rules, and CTA placement — and stay in sync with the companion
 * guides it tells the AI to call.
 */
class LandingGuideTest extends TestCase
{
    public function testBlueprintCoversThePersuasionFlow(): void
    {
        $md = LandingGuide::markdown();
        $this->assertNotEmpty($md);

        // The full conversion flow, in the right vocabulary.
        foreach ([
            'Hero', 'Problem', 'Solution', 'Benefits', 'social proof',
            'How it works', 'Feature', 'FAQ', 'Final conversion',
        ] as $stage) {
            $this->assertStringContainsString($stage, $md, "landing guide should cover the '$stage' stage");
        }
    }

    public function testTeachesTemplateIntelligenceAndCopyRules(): void
    {
        $md = LandingGuide::markdown();

        // Step 0: decide business type, audience, conversion goal before building.
        foreach (['Business type', 'audience', 'Conversion goal'] as $needle) {
            $this->assertStringContainsString($needle, $md, "landing guide should require deciding $needle");
        }

        // Copywriting guardrails: ban the generic, prefer outcomes.
        $this->assertStringContainsString('Welcome to our website', $md, 'should name the banned generic headline');
        $this->assertStringContainsString('Lorem ipsum', $md, 'should ban lorem ipsum');
        $this->assertStringContainsString('without [main frustration]', $md, 'should give the benefit-headline formula');
    }

    public function testPointsToCompanionGuidesAndTools(): void
    {
        $md = LandingGuide::markdown();
        foreach (['get_style_guide', 'get_section_recipes', 'create_page', 'update_page_layout'] as $tool) {
            $this->assertStringContainsString($tool, $md, "landing guide should reference $tool");
        }
    }
}
