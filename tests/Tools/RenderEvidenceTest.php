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

    // FINDING 1: HTML-formatted PHP diagnostics detection
    public function testHtmlFormattedWarningDiagnosticIsAFail(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_video_slider_0"><b>Warning</b>:  Undefined index</div>', '', 'divi/video-slider');
        $this->assertSame('fail', $r['status']);
    }

    public function testHtmlFormattedNoticeDiagnosticIsAFail(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_video_slider_0"><b>Notice</b>:</div>', '', 'divi/video-slider');
        $this->assertSame('fail', $r['status']);
    }

    public function testHtmlFormattedDeprecatedDiagnosticIsAFail(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_video_slider_0"><b>Deprecated</b>:</div>', '', 'divi/video-slider');
        $this->assertSame('fail', $r['status']);
    }

    public function testCliErrorPrefixInNoiseIsAFail(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_video_slider_0"></div>', 'Error: something went wrong', 'divi/video-slider');
        $this->assertSame('fail', $r['status']);
    }

    // FINDING 2: Boundary-aware marker matching
    public function testPrefixCollisionTabVsTabsReturnsNeedsRealExport(): void
    {
        // et_pb_tab is substring of et_pb_tabs; classifying divi/tab should not match et_pb_tabs_0
        $r = RenderEvidence::classify('<div class="et_pb_tabs_0">content</div>', '', 'divi/tab');
        $this->assertSame('needs-real-export', $r['status']);
    }

    public function testPrefixCollisionSlideVsSliderReturnsNeedsRealExport(): void
    {
        // et_pb_slide is substring of et_pb_slider
        $r = RenderEvidence::classify('<div class="et_pb_slider_0">content</div>', '', 'divi/slide');
        $this->assertSame('needs-real-export', $r['status']);
    }

    public function testPrefixCollisionVideoVsVideoSliderReturnsNeedsRealExport(): void
    {
        // et_pb_video is substring of et_pb_video_slider
        $r = RenderEvidence::classify('<div class="et_pb_video_slider_0">content</div>', '', 'divi/video');
        $this->assertSame('needs-real-export', $r['status']);
    }

    public function testBoundaryMatchAllowsExactPrefixWithNumericSuffix(): void
    {
        // et_pb_video_slider with _0 suffix should match divi/video-slider
        $r = RenderEvidence::classify('<div class="et_pb_video_slider_0">content</div>', '', 'divi/video-slider');
        $this->assertSame('pass', $r['status']);
    }

    public function testWrapperTypeSectionReturnsNeedsRealExport(): void
    {
        // Structural wrapper: cannot be proven by a probe page that is itself wrapped in it
        $r = RenderEvidence::classify('<div class="et_pb_section_0">content</div>', '', 'divi/section');
        $this->assertSame('needs-real-export', $r['status']);
    }

    public function testWrapperTypeRowReturnsNeedsRealExport(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_row_0">content</div>', '', 'divi/row');
        $this->assertSame('needs-real-export', $r['status']);
    }

    public function testWrapperTypeColumnReturnsNeedsRealExport(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_column_0">content</div>', '', 'divi/column');
        $this->assertSame('needs-real-export', $r['status']);
    }

    public function testWrapperTypeRowInnerReturnsNeedsRealExport(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_row_inner_0">content</div>', '', 'divi/row-inner');
        $this->assertSame('needs-real-export', $r['status']);
    }

    public function testWrapperTypeColumnInnerReturnsNeedsRealExport(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_column_inner_0">content</div>', '', 'divi/column-inner');
        $this->assertSame('needs-real-export', $r['status']);
    }

    public function testWrapperTypePlaceholderReturnsNeedsRealExport(): void
    {
        $r = RenderEvidence::classify('<div class="et_pb_placeholder_0">content</div>', '', 'divi/placeholder');
        $this->assertSame('needs-real-export', $r['status']);
    }
}
