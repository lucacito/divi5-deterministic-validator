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
    public function testImageGuidancePointsAtTheImageGuideNotARemoteHost(): void
    {
        $md = StyleGuide::markdown();
        $this->assertStringContainsString('get_image_guide', $md);
        $this->assertStringContainsString('list_media_images', $md);
        foreach (['picsum', 'pravatar', 'randomuser', 'placehold.co', 'loremflickr', 'unsplash'] as $host) {
            $this->assertStringNotContainsStringIgnoringCase($host, $md, "StyleGuide must not mention $host");
        }
    }

    public function testGuideCoversKeyTopics(): void
    {
        $md = StyleGuide::markdown();
        $this->assertNotEmpty($md);
        foreach ([
            'builderVersion', 'divi/placeholder', 'divi/section', 'divi/column-inner',
            'background', 'gradient', 'boxShadow', 'transform', 'animation',
            'Worked example',
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

    public function testDoesNotReferenceRemovedOrPaidTools(): void
    {
        $md = StyleGuide::markdown();
        foreach (['set_front_page', 'set_primary_menu', 'set_custom_css', 'propose_php_snippet', 'find_image', 'premium', 'upgrade', 'license'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $md, "StyleGuide must not mention $needle");
        }
    }

    public function testPreviewSectionTeachesDryRunAndThatAnInvalidPreviewStillReturns(): void
    {
        $md = StyleGuide::markdown();
        $this->assertStringContainsString('dry_run', $md);
        $this->assertStringContainsString('valid: false', $md);
        $this->assertStringContainsString('counts as one removed/added block', $md);
    }

    public function testTeachesTheSiteStylesToolWithTheColorShapeSeenInRealExports(): void
    {
        $md = StyleGuide::markdown();
        $this->assertStringContainsString('## Match the site (colors and presets)', $md);
        $this->assertStringContainsString('list_global_styles', $md);
        $this->assertStringContainsString('advisories.style_references', $md);

        // The one reference shape the guide teaches must appear verbatim in a real export.
        $q     = '\\' . 'u0022'; // a quote as written inside a block comment: backslash, u, 0, 0, 2, 2
        $shape = '$variable({' . $q . 'type' . $q . ':' . $q . 'color' . $q . ',' . $q . 'value' . $q . ':{' . $q . 'name' . $q . ':' . $q . 'gcid-';
        $this->assertStringContainsString($shape, $md);
        $found = false;
        foreach (glob(__DIR__ . '/../fixtures/valid/page-*.json') ?: [] as $file) {
            $export = json_decode((string) file_get_contents($file), true);
            if (is_array($export) && str_contains((string) ($export['post_content'] ?? ''), $shape)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'no real export in fixtures/valid contains the color-variable shape the guide teaches');
    }

    public function testSiteStylesSectionDoesNotTeachUngroundedVariableTypes(): void
    {
        $md = StyleGuide::markdown();
        $this->assertStringContainsString('Variables other than colors are listed for information', $md);
        $q = '\\' . 'u0022';
        $this->assertStringNotContainsString($q . 'type' . $q . ':' . $q . 'number', $md);
        $this->assertStringNotContainsString('gvid-XXXX', $md);
    }
}
