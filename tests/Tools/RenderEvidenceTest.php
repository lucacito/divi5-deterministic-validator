<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Tools;

use Divi5Validator\Tools\RenderEvidence;
use PHPUnit\Framework\TestCase;

class RenderEvidenceTest extends TestCase
{
    public function testRecognisedMarkupIsAPass(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_module et_pb_video_slider_0">x</div>', '', 'divi/video-slider');
        $this->assertSame('pass', $r['status']);
    }

    public function testPhpNoticeInOutputIsAFailNotAPass(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_video_slider_0">Notice: Undefined index</div>', '', 'divi/video-slider');
        $this->assertSame('fail', $r['status']);
    }

    public function testPhpErrorCapturedOutsideTheHtmlIsAFail(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_video_slider_0"></div>', 'PHP Fatal error: boom', 'divi/video-slider');
        $this->assertSame('fail', $r['status']);
    }

    public function testEmptyOutputNeedsARealExport(): void
    {
        $this->assertSame('needs-real-export', RenderEvidence::classify('', '', 'divi/gravity-forms')['status']);
    }

    public function testOutputWithoutTheModuleMarkerNeedsARealExport(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_section">nothing from our module</div>', '', 'divi/gravity-forms');
        $this->assertSame('needs-real-export', $r['status']);
    }

    public function testMarkersAreModuleSpecific(): void
    {
        $m = RenderEvidence::markers('divi/video-slider');
        $this->assertContains('et_pb_video_slider', $m);
        foreach ($m as $marker) {
            $this->assertStringContainsString('video_slider', str_replace('-', '_', $marker), 'every marker must contain the full module name');
        }
    }

    public function testGenericWordsInOutputDoNotProveAModule(): void
    {
        // "link" appears in almost any page output; it must not prove divi/link rendered.
        $r = RenderEvidence::classify('<a class="link" href="#">x</a><link rel="stylesheet">', '', 'divi/link');
        $this->assertSame('needs-real-export', $r['status']);
    }
}
