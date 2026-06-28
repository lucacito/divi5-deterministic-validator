<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\StyleGuide;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';

/**
 * The style guide is the AI's authoring contract, so it must (a) cover the key
 * topics and (b) ship a worked example that actually validates — otherwise we'd
 * be teaching the AI to produce invalid layouts.
 */
class StyleGuideTest extends TestCase
{
    public function testGuideCoversKeyTopics(): void
    {
        $md = StyleGuide::markdown();
        $this->assertNotEmpty($md);
        foreach ([
            'builderVersion', 'divi/placeholder', 'divi/section', 'divi/column-inner',
            'background', 'gradient', 'boxShadow', 'transform', 'animation',
            'picsum.photos', 'Worked example',
            // The column-nesting rule the AI keeps re-deriving: must be taught explicitly.
            'can NEVER directly contain another', 'divi/group',
        ] as $needle) {
            $this->assertStringContainsString($needle, $md, "guide should mention $needle");
        }
    }

    public function testWorkedExampleValidates(): void
    {
        $md = StyleGuide::markdown();

        // Extract the section fragment from the worked example and wrap it in the
        // required placeholder root, exactly as the guide instructs the AI to.
        $this->assertSame(1, preg_match('/(<!-- wp:divi\/section.*<!-- \/wp:divi\/section -->)/s', $md, $m),
            'guide must contain a worked section example');
        $postContent = '<!-- wp:divi/placeholder -->' . $m[1] . '<!-- /wp:divi/placeholder -->';

        $result = (new Validator())->validateContent($postContent);
        $messages = implode('; ', array_map(fn($v) => $v->toArray()['code'] . ' ' . $v->toArray()['path'], $result->violations()));
        $this->assertTrue($result->isValid(), "worked example must validate, got: $messages");
    }
}
