<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ContrastCheck;
use AiEditorDivi5\WP\DesignCheck;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/ContrastCheck.php';
require_once __DIR__ . '/../wp-plugin/src/DesignCheck.php';

/**
 * The conservative contrast check: it may report only a pair that definitely cannot be read, and must say "not checked" for
 * everything it cannot compute. Each case below that expects silence is a false positive that must never happen.
 */
class ContrastCheckTest extends TestCase
{
    /** A section with an optional background, holding one text module with an optional colour and size. */
    private function page( ?array $sectionBg, ?string $textColor, ?string $size = null, array $extraSection = [], array $extraText = [] ): string
    {
        $section = $extraSection;
        if ( null !== $sectionBg ) {
            $section['module']['decoration']['background']['desktop']['value'] = $sectionBg;
        }
        $font = [];
        if ( null !== $textColor ) {
            $font['color'] = $textColor;
        }
        if ( null !== $size ) {
            $font['size'] = $size;
        }
        $text = $extraText + [ 'builderVersion' => '5.14.0' ];
        if ( [] !== $font ) {
            $text['content']['decoration']['bodyFont']['body']['font']['desktop']['value'] = $font;
        }

        return '<!-- wp:divi/placeholder --><!-- wp:divi/section ' . json_encode( $section + [ 'builderVersion' => '5.14.0' ] ) . ' --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/text ' . json_encode( $text ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    public function testTheRatioMatchesTheWcagReferenceValues(): void
    {
        $this->assertEqualsWithDelta( 21.0, ContrastCheck::ratio( [ 0, 0, 0 ], [ 255, 255, 255 ] ), 0.001 );
        $this->assertEqualsWithDelta( 1.0, ContrastCheck::ratio( [ 120, 120, 120 ], [ 120, 120, 120 ] ), 0.001 );
        $this->assertEqualsWithDelta( 4.54, ContrastCheck::ratio( [ 0x76, 0x76, 0x76 ], [ 255, 255, 255 ] ), 0.01, '#767676 on white is the well-known 4.54:1' );
    }

    public function testNearlyInvisibleTextIsReportedWithBothColours(): void
    {
        $r = ContrastCheck::check( $this->page( [ 'color' => '#ffffff' ], '#f5f5f5' ) );
        $this->assertSame( 'problems', $r['status'] );
        $p = $r['problems'][0];
        $this->assertSame( 'LOW_CONTRAST', $p['code'] );
        $this->assertSame( '#f5f5f5', $p['text_color'] );
        $this->assertSame( '#ffffff', $p['background'] );
        $this->assertLessThan( 3.0, $p['ratio'] );
        $this->assertStringContainsString( 'divi/text', $p['block'] );
        $this->assertStringContainsString( 'section', $p['on'] );
    }

    public function testReadableTextIsOkAndCounted(): void
    {
        $r = ContrastCheck::check( $this->page( [ 'color' => '#101820' ], '#ffffff' ) );
        $this->assertSame( 'ok', $r['status'] );
        $this->assertSame( 1, $r['checked'] );
        $this->assertSame( [], $r['problems'] );
    }

    public function testBetweenThreeAndFourPointFiveIsOnlyAFailureWhenASmallPxSizeIsWritten(): void
    {
        // #888888 on white is 3.54:1: fine for large text, a failure for small text.
        $this->assertSame( 'ok', ContrastCheck::check( $this->page( [ 'color' => '#ffffff' ], '#888888' ) )['status'], 'size unknown: not judged' );
        $this->assertSame( 'ok', ContrastCheck::check( $this->page( [ 'color' => '#ffffff' ], '#888888', '32px' ) )['status'] );
        $this->assertSame( 'ok', ContrastCheck::check( $this->page( [ 'color' => '#ffffff' ], '#888888', '2rem' ) )['status'], 'rem and em are never guessed' );
        $small = ContrastCheck::check( $this->page( [ 'color' => '#ffffff' ], '#888888', '14px' ) );
        $this->assertSame( 'problems', $small['status'] );
        $this->assertSame( '14px', $small['problems'][0]['font_size'] );
        $this->assertSame( 4.5, $small['problems'][0]['needs'] );
    }

    /** @return array<string,array{array<string,mixed>|null,?string,string}> sectionBg, text colour, reason it must stay silent */
    public static function silentCases(): array
    {
        return [
            'a global colour id'            => [ [ 'color' => '#ffffff' ], 'gcid-4f1ca2', 'color_is_not_a_plain_value' ],
            'a variable'                    => [ [ 'color' => '#ffffff' ], '$variable({"type":"color","value":{"name":"gvid-x"}})$', 'color_is_not_a_plain_value' ],
            'a colour name'                 => [ [ 'color' => '#ffffff' ], 'white', 'color_is_not_a_plain_value' ],
            'translucent text'              => [ [ 'color' => '#ffffff' ], 'rgba(255,255,255,0.4)', 'transparent_color' ],
            'a translucent background'      => [ [ 'color' => 'rgba(255,255,255,0.1)' ], '#fefefe', 'transparent_color' ],
            'no background anywhere'        => [ null, '#ffffff', 'no_background_above_the_text' ],
            'a gradient background'         => [ [ 'color' => '#ffffff', 'gradient' => [ 'enabled' => 'on', 'stops' => [] ] ], '#fafafa', 'background_is_an_image_gradient_or_video' ],
            'a background image'            => [ [ 'color' => '#ffffff', 'image' => [ 'url' => 'https://x.test/a.jpg' ] ], '#fafafa', 'background_is_an_image_gradient_or_video' ],
            'a background video'            => [ [ 'color' => '#ffffff', 'video' => [ 'mp4' => 'https://x.test/a.mp4' ] ], '#fafafa', 'background_is_an_image_gradient_or_video' ],
        ];
    }

    /** @param array<string,mixed>|null $bg */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'silentCases' )]
    public function testAnythingItCannotComputeIsNeverAProblem( ?array $bg, ?string $text, string $reason ): void
    {
        $r = ContrastCheck::check( $this->page( $bg, $text ) );
        $this->assertSame( [], $r['problems'], 'no false positive' );
        $this->assertSame( 'not_checked', $r['status'], 'and it does not claim a pass either' );
        $this->assertArrayHasKey( $reason, $r['not_checked'] );
    }

    public function testTextWithNoColourOfItsOwnIsSkippedNotJudged(): void
    {
        $r = ContrastCheck::check( $this->page( [ 'color' => '#000000' ], null ) );
        $this->assertSame( 'not_checked', $r['status'] );
        $this->assertSame( [], $r['problems'] );
    }

    public function testAPositionedOrOverlappingModuleIsNotJudged(): void
    {
        $r = ContrastCheck::check( $this->page( [ 'color' => '#ffffff' ], '#f8f8f8', null, [], [ 'module' => [ 'decoration' => [ 'position' => [ 'desktop' => [ 'value' => [ 'type' => 'absolute' ] ] ] ] ] ] ) );
        $this->assertSame( [], $r['problems'] );
        $this->assertArrayHasKey( 'positioned_or_overlapping', $r['not_checked'] );

        $r = ContrastCheck::check( $this->page( [ 'color' => '#ffffff' ], '#f8f8f8', null, [], [ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'margin' => [ 'top' => '-80px' ] ] ] ] ] ] ] ) );
        $this->assertSame( [], $r['problems'], 'a negative margin pulls a module over its neighbour' );
    }

    public function testTheNearestOpaqueBackgroundWinsAndAHiddenImageStopsTheSearch(): void
    {
        // Dark text on a white card inside a dark section: judged against the card, and fine.
        $card = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#101820"}}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"}}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/text {"content":{"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"#222222"}}}}}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
        $r = ContrastCheck::check( $card );
        $this->assertSame( 'ok', $r['status'] );
        $this->assertSame( 1, $r['checked'] );

        // The same text on a column with an image over a white section: unknown, so silent.
        $image = str_replace( '"color":"#ffffff"}}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/text', '"image":{"url":"https://x.test/a.jpg"}}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/text', $card );
        $this->assertNotSame( $card, $image );
        $this->assertSame( [], ContrastCheck::check( $image )['problems'] );
    }

    public function testAButtonIsJudgedAgainstItsOwnFill(): void
    {
        $button = static fn ( string $label, string $fill ): string => '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"builderVersion":"5.14.0"} --><!-- wp:divi/button {"button":{"decoration":{"font":{"font":{"desktop":{"value":{"color":"' . $label . '"}}}},"background":{"desktop":{"value":{"color":"' . $fill . '"}}}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
        $this->assertSame( 'problems', ContrastCheck::check( $button( '#2ea3f2', '#3aa8f5' ) )['status'] );
        $this->assertSame( 'ok', ContrastCheck::check( $button( '#ffffff', '#101820' ) )['status'] );
    }

    public function testParseAcceptsOnlyPlainOpaqueColours(): void
    {
        $this->assertSame( [ 255, 0, 0 ], ContrastCheck::parse( '#f00' ) );
        $this->assertSame( [ 18, 52, 86 ], ContrastCheck::parse( '#123456' ) );
        $this->assertSame( [ 18, 52, 86 ], ContrastCheck::parse( ' #123456FF ' ) );
        $this->assertSame( [ 1, 2, 3 ], ContrastCheck::parse( 'rgb(1, 2, 3)' ) );
        $this->assertSame( [ 1, 2, 3 ], ContrastCheck::parse( 'rgba(1,2,3,1)' ) );
        foreach ( [ '#12345678', 'rgba(1,2,3,0.5)', 'rgb(300,0,0)', 'red', 'transparent', 'var(--x)', 'hsl(0,0%,0%)', '', 12, null ] as $bad ) {
            $this->assertNull( ContrastCheck::parse( $bad ), var_export( $bad, true ) );
        }
    }

    public function testBadOrHugeInputNeverThrows(): void
    {
        foreach ( [ '', 'plain text', '<!-- wp:divi/section {broken', str_repeat( 'x', ContrastCheck::MAX_BYTES + 1 ) ] as $in ) {
            $r = ContrastCheck::check( $in );
            $this->assertSame( 'not_checked', $r['status'] );
            $this->assertSame( [], $r['problems'] );
        }
    }

    public function testEveryReportListsWhatIsNeverJudgedSoACleanReportIsNotAFullPass(): void
    {
        $clean = DesignCheck::run( $this->page( [ 'color' => '#101820' ], '#ffffff' ), null );
        $this->assertSame( 0, $clean['problem_count'] );
        $items = array_column( $clean['not_checked_items'], null, 'item' );
        foreach ( [ 'global_colors_and_variables', 'images_gradients_video', 'hover_states', 'tablet_and_phone', 'semi_transparent_colors', 'text_without_own_color', 'other_accessibility' ] as $must ) {
            $this->assertArrayHasKey( $must, $items, $must );
            $this->assertNotSame( '', $items[ $must ]['why'] );
        }
        $this->assertStringContainsString( 'not a full pass', $clean['message'] );

        // The counts are what this very layout hit: a global color id and an image background.
        $page = $this->page( [ 'color' => '#ffffff', 'image' => [ 'url' => 'https://x.test/a.jpg' ] ], 'gcid-4f1ca2' ) . $this->page( [ 'color' => '#ffffff', 'image' => [ 'url' => 'https://x.test/a.jpg' ] ], '#fafafa' );
        $r = DesignCheck::run( $page, null );
        $items = array_column( $r['not_checked_items'], 'seen', 'item' );
        $this->assertSame( 1, $items['global_colors_and_variables'] );
        $this->assertSame( 1, $items['images_gradients_video'] );
        $this->assertNull( $items['hover_states'], 'a thing that is never judged at all is not counted' );
    }

    public function testDesignCheckCombinesTheChecksAndSaysWhatItDidNotCheck(): void
    {
        $r = DesignCheck::run( $this->page( [ 'color' => '#ffffff' ], '#f5f5f5' ), null );
        $this->assertSame( [ 'contrast', 'style_references', 'variety', 'default_fallbacks' ], array_keys( $r['checks'] ) );
        $this->assertSame( 'problems', $r['checks']['contrast']['status'] );
        $this->assertSame( 1, $r['problem_count'] );
        $this->assertContains( 'style_references', $r['not_checked'], 'no site data: said, not passed' );
        $this->assertStringContainsString( 'advice only', $r['message'] );
        $this->assertStringContainsString( 'Could not check', $r['message'] );
    }
}
