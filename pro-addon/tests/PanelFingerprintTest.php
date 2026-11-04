<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\MegaMenu\HeaderEdit;
use AiEditorDivi5\Pro\MegaMenu\Layouts;
use AiEditorDivi5\Pro\MegaMenu\PanelFingerprint;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/MegaMenuFixture.php';

final class PanelFingerprintTest extends TestCase
{
    private function panels( string $layoutId = 'columns-footer' ): array
    {
        $wrapper = MegaMenuFixture::wrapper( Layouts::find( $layoutId ), MegaMenuFixture::menus()['grouped'] );

        return HeaderEdit::panelBlocks( '<!-- wp:divi/section {} -->' . $wrapper . '<!-- /wp:divi/section -->' );
    }

    /** The same markup written the way a builder might: keys in another order, other spacing, another builder version. */
    private static function rewritten( string $markup ): string
    {
        return (string) preg_replace_callback( '/(<!--\s+wp:divi\/[a-z0-9-]+\s+)(\{.*?\})(\s*\/?-->)/s', static function ( array $m ): string {
            $a = json_decode( $m[2], true );
            $rev = static function ( $v ) use ( &$rev ) {
                if ( ! is_array( $v ) ) {
                    return $v;
                }
                $v = array_map( $rev, $v );

                return array_is_list( $v ) ? $v : array_reverse( $v, true );
            };
            $a = $rev( $a );
            $a['builderVersion'] = '5.99.0';

            return $m[1] . json_encode( $a, JSON_UNESCAPED_SLASHES ) . '  ' . $m[3];
        }, $markup );
    }

    public function testPanelBlocksAreTheDropdownsWithTheirItemIds(): void
    {
        $panels = $this->panels();
        $this->assertSame( [ 2 ], array_keys( $panels ), 'only Services has sub-items' );
        foreach ( $panels as $id => $markup ) {
            $this->assertStringStartsWith( '<!-- wp:divi/dropdown {', $markup );
            $this->assertStringEndsWith( '<!-- /wp:divi/dropdown -->', $markup );
            $this->assertStringContainsString( 'JHMG panel ' . $id . ' columns-footer', $markup );
            $this->assertSame( substr_count( $markup, '<!-- wp:divi/dropdown ' ), substr_count( $markup, '<!-- /wp:divi/dropdown -->' ) );
        }
        $this->assertSame( [], HeaderEdit::panelBlocks( '<!-- wp:divi/section {} --><!-- /wp:divi/section -->' ) );
    }

    public function testTheSamePanelWrittenAnotherWayHasTheSameFingerprint(): void
    {
        foreach ( $this->panels() as $markup ) {
            $this->assertSame( PanelFingerprint::of( $markup ), PanelFingerprint::of( self::rewritten( $markup ) ) );
        }
    }

    public function testAChangedTextModuleOrAttributeChangesIt(): void
    {
        foreach ( $this->panels() as $markup ) {
            $fp = PanelFingerprint::of( $markup );
            $this->assertNotSame( $fp, PanelFingerprint::of( str_replace( '"text":"Care plans"', '"text":"Care plan"', $markup ) ) );
            $this->assertNotSame( $fp, PanelFingerprint::of( str_replace( '"color":"#1b2130"', '"color":"#ff0000"', $markup ) ) );
            $this->assertNotSame( $fp, PanelFingerprint::of( preg_replace( '/(<!-- \/wp:divi\/dropdown -->)$/', '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>x<\/p>"}}}} /-->$1', $markup ) ) );
            break;
        }
    }

    public function testDifferentLayoutsHaveDifferentFingerprints(): void
    {
        $a = PanelFingerprint::of( $this->panels( 'columns-footer' )[2] );
        $b = PanelFingerprint::of( $this->panels( 'showcase-tiles' )[2] );
        $this->assertNotSame( $a, $b );
    }

    public function testEveryPanelCarriesItsOwnFingerprintInItsLabel(): void
    {
        foreach ( $this->panels() as $id => $markup ) {
            $stamp = PanelFingerprint::stamped( $markup );
            $this->assertNotNull( $stamp );
            $this->assertMatchesRegularExpression( '/^[0-9a-f]{10}$/', $stamp );
            $this->assertSame( PanelFingerprint::short( $markup ), $stamp, 'the label holds the panel\'s own fingerprint' );
            $this->assertFalse( PanelFingerprint::edited( $markup ) );
            $this->assertStringContainsString( 'JHMG panel ' . $id . ' columns-footer ' . $stamp, $markup );
        }
    }

    public function testTheLabelItselfIsNotPartOfTheFingerprint(): void
    {
        foreach ( $this->panels() as $markup ) {
            $renamed = (string) preg_replace( '/"value":"JHMG panel (\d+) ([a-z0-9-]+) [0-9a-f]{10}"/', '"value":"My own name for it"', $markup, 1 );
            $this->assertNotSame( $markup, $renamed );
            $this->assertSame( PanelFingerprint::of( $markup ), PanelFingerprint::of( $renamed ), 'renaming the layer in the builder is not an edit of the panel' );
        }
    }

    public function testAChangedPanelIsEditedAndAnOldPanelWithoutAStampNeverIs(): void
    {
        foreach ( $this->panels() as $markup ) {
            $changed = str_replace( '"text":"Care plans"', '"text":"Care plan"', $markup );
            $this->assertTrue( PanelFingerprint::edited( $changed ) );
            $this->assertSame( PanelFingerprint::stamped( $markup ), PanelFingerprint::stamped( $changed ), 'the stamp is still the old one' );
            $old = (string) preg_replace( '/(JHMG panel \d+ [a-z0-9-]+) [0-9a-f]{10}/', '$1', $changed );
            $this->assertNull( PanelFingerprint::stamped( $old ) );
            $this->assertFalse( PanelFingerprint::edited( $old ), 'no stamp: written by an older version, never called edited' );
        }
    }

    public function testAnUnrelatedHashShapedWordInALabelIsNotAStamp(): void
    {
        $this->assertNull( PanelFingerprint::stamped( '<!-- wp:divi/dropdown {"module":{"meta":{"adminLabel":{"desktop":{"value":"My panel abcdef0123"}}}}} -->' ) );
    }

    public function testSetMenuChangesOnlyTheMenuOption(): void
    {
        $header = MegaMenuFixture::header();
        $new    = HeaderEdit::setMenu( $header, 77 );
        $this->assertSame( $header, str_replace( '"menuId":{"desktop":{"value":"77"}}', '"menuId":{"desktop":{"value":"35"}}', $new ) );
        $this->assertSame( '77', HeaderEdit::inspect( $new )['menu']['menu']['advanced']['menuId']['desktop']['value'] );
        $this->assertSame( $new, HeaderEdit::setMenu( $new, 77 ), 'the same menu again is no change' );
    }

    public function testSetMenuCreatesTheOptionWhenTheModuleHasNone(): void
    {
        $bare = '<!-- wp:divi/menu {"menu":{"decoration":{}},"builderVersion":"5.14.0"} /-->';
        $new  = HeaderEdit::setMenu( $bare, 5 );
        $this->assertStringContainsString( '"menuId":{"desktop":{"value":"5"}}', $new );
        $this->assertStringContainsString( '"decoration":{}', $new );
    }

    public function testSetMenuRefusesAHeaderWithoutAMenuModule(): void
    {
        $this->expectException( \InvalidArgumentException::class );
        HeaderEdit::setMenu( '<!-- wp:divi/section {} --><!-- /wp:divi/section -->', 5 );
    }
}
