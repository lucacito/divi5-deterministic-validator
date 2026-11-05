<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DefaultFallbacks;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/DefaultFallbacks.php';

/**
 * Divi's stock look showing through. The defaults are the ones measured on the real Divi (fixtures/shapes/divi-defaults-5.15.1.json)
 * and the placeholder the builder itself writes (a real export); nothing here comes from memory.
 */
class DefaultFallbacksTest extends TestCase
{
    private function wrap( string $inner ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"builderVersion":"5.14.0"} -->' . $inner . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    /** The data URI the builder wrote into the real export. */
    private function realPlaceholder(): string
    {
        $export = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/valid/page-17-newpage.json' ), true );
        $this->assertSame( 1, preg_match( '#data:image/svg\+xml;base64,[A-Za-z0-9+/=]+#', $export['post_content'], $m ) );

        return $m[0];
    }

    private function evidence(): array
    {
        return json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/shapes/divi-defaults-5.15.1.json' ), true );
    }

    public function testTheCommittedEvidenceBacksEveryClaimTheCheckMakes(): void
    {
        $m = $this->evidence()['measured'];
        $this->assertSame( '5.15.1', $this->evidence()['divi'] );
        $this->assertSame( 'rgba(0, 0, 0, 0)', $m['button']['background-color'], 'the stock button has no fill' );
        $this->assertSame( 'rgb(255, 255, 255)', $m['button']['color'], 'with a white label' );
        $this->assertSame( 'rgb(255, 255, 255)', $m['button']['border-top-color'] );
        $this->assertSame( '2px', $m['button']['border-top-width'] );
        $this->assertSame( 'rgb(244, 244, 244)', $m['tabsBar']['background-color'], 'the stock tab bar is #f4f4f4' );
        $this->assertSame( 'rgb(102, 102, 102)', $m['tabControl']['color'], 'with #666666 text' );
        $this->assertStringContainsString( '2px solid white', DefaultFallbacks::MEASURED['button'] );
        $this->assertStringContainsString( '#f4f4f4', DefaultFallbacks::MEASURED['tabs'] );
    }

    public function testTheBuildersOwnPlaceholderIsFoundInAnyAttribute(): void
    {
        $uri = $this->realPlaceholder();
        $this->assertTrue( DefaultFallbacks::isPlaceholder( $uri ) );
        $img = '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"' . $uri . '","alt":"x"}}}},"builderVersion":"5.14.0"} /-->';
        $r = DefaultFallbacks::check( $this->wrap( $img ) );
        $this->assertSame( 'problems', $r['status'] );
        $this->assertSame( 'GREY_PLACEHOLDER_IMAGE', $r['findings'][0]['code'] );
        $this->assertSame( 'certain', $r['findings'][0]['confidence'] );
        $this->assertSame( 'image.innerContent.desktop.value.src', $r['findings'][0]['at'] );

        $blurb = '<!-- wp:divi/blurb {"imageIcon":{"innerContent":{"desktop":{"value":{"src":"' . $uri . '"}}}},"builderVersion":"5.14.0"} /-->';
        $this->assertSame( 'GREY_PLACEHOLDER_IMAGE', DefaultFallbacks::check( $this->wrap( $blurb ) )['findings'][0]['code'] );
    }

    public function testARealPictureOrAnotherDataUriIsNotAPlaceholder(): void
    {
        $other = 'data:image/svg+xml;base64,' . base64_encode( '<svg width="10" height="10" viewBox="0 0 10 10" xmlns="http://www.w3.org/2000/svg"><path fill="#EBEBEB" d="M0 0h10v10H0z"/></svg>' );
        $this->assertFalse( DefaultFallbacks::isPlaceholder( $other ), 'same colour, not the builder\'s placeholder' );
        $this->assertFalse( DefaultFallbacks::isPlaceholder( 'data:image/svg+xml;base64,!!!notbase64' ) );
        $img = '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"https://example.com/photo.jpg","alt":"x"}}}},"builderVersion":"5.14.0"} /-->';
        $this->assertSame( 'ok', DefaultFallbacks::check( $this->wrap( $img ) )['status'] );
    }

    public function testAnUnstyledButtonIsFlaggedAsPossibleAndAStyledOneIsNot(): void
    {
        $bare = '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go"}}}},"builderVersion":"5.14.0"} /-->';
        $r = DefaultFallbacks::check( $this->wrap( $bare ) );
        $this->assertSame( 'STOCK_BUTTON_LOOK', $r['findings'][0]['code'] );
        $this->assertSame( 'possible', $r['findings'][0]['confidence'], 'a theme option could restyle it, so it is never "certain"' );

        foreach ( [
            '"button":{"decoration":{"background":{"desktop":{"value":{"color":"#101820"}}}},"innerContent":{"desktop":{"value":{"text":"Go"}}}}',
            '"button":{"decoration":{"font":{"font":{"desktop":{"value":{"color":"#101820"}}}}},"innerContent":{"desktop":{"value":{"text":"Go"}}}}',
            '"modulePreset":["abc123"],"button":{"innerContent":{"desktop":{"value":{"text":"Go"}}}}',
        ] as $attrs ) {
            $styled = '<!-- wp:divi/button {' . $attrs . ',"builderVersion":"5.14.0"} /-->';
            $this->assertSame( 'ok', DefaultFallbacks::check( $this->wrap( $styled ) )['status'], $attrs );
        }
    }

    public function testASitePresetForTheModuleSilencesThePossibleFindings(): void
    {
        $bare = '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go"}}}},"builderVersion":"5.14.0"} /--><!-- wp:divi/tabs {"builderVersion":"5.14.0"} -->' . '<!-- /wp:divi/tabs -->';
        $this->assertCount( 2, DefaultFallbacks::check( $this->wrap( $bare ) )['findings'] );
        $this->assertCount( 2, DefaultFallbacks::check( $this->wrap( $bare ), static fn ( string $m ): ?bool => null )['findings'], 'presets unreadable: cannot rule the stock look out' );
        $this->assertCount( 2, DefaultFallbacks::check( $this->wrap( $bare ), static fn ( string $m ): ?bool => false )['findings'] );
        $this->assertSame( 'ok', DefaultFallbacks::check( $this->wrap( $bare ), static fn ( string $m ): ?bool => true )['status'] );
    }

    public function testTabsWithTheirOwnFillAreNotFlagged(): void
    {
        $tabs = '<!-- wp:divi/tabs {"tab":{"decoration":{"background":{"desktop":{"value":{"color":"#101820"}}}}},"builderVersion":"5.14.0"} --><!-- /wp:divi/tabs -->';
        $this->assertSame( 'ok', DefaultFallbacks::check( $this->wrap( $tabs ) )['status'] );
    }

    public function testBadInputIsNotCheckedNeverAnError(): void
    {
        foreach ( [ '', 'plain', '<!-- wp:divi/section {broken', str_repeat( 'x', DefaultFallbacks::MAX_BYTES + 1 ) ] as $in ) {
            $r = DefaultFallbacks::check( $in );
            $this->assertSame( 'not_checked', $r['status'] );
            $this->assertSame( [], $r['findings'] );
        }
    }
}
