<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\MegaMenu\Blocks;
use AiEditorDivi5\Pro\MegaMenu\ColumnPlan;
use AiEditorDivi5\Pro\MegaMenu\HeaderEdit;
use AiEditorDivi5\Pro\MegaMenu\Layouts;
use AiEditorDivi5\Pro\MegaMenu\Look;
use AiEditorDivi5\Pro\MegaMenu\MenuTree;
use AiEditorDivi5\Pro\MegaMenu\Palette;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MegaMenuFixture.php';

class MegaMenuPartsTest extends TestCase
{
    // ---- catalog ---------------------------------------------------------------------------------------------------

    public function testThereAreTwentyLayoutsInFiveFamiliesOfFour(): void
    {
        $all = Layouts::all();
        $this->assertCount( 20, $all );
        $this->assertSame( 20, count( array_unique( Layouts::ids() ) ), 'ids are unique' );
        $perFamily = array_count_values( array_map( static fn ( array $l ): string => $l['family'], $all ) );
        $this->assertSame( array_keys( Layouts::FAMILIES ), array_keys( $perFamily ) );
        foreach ( $perFamily as $family => $n ) {
            $this->assertSame( 4, $n, $family );
        }
    }

    public function testEveryLayoutIsWellFormedAndNamesAreDistinct(): void
    {
        $names = [];
        foreach ( Layouts::all() as $l ) {
            $this->assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $l['id'] );
            $this->assertContains( $l['tone'], [ 'light', 'dark' ] );
            $this->assertContains( $l['mode'], [ 'auto', 'row', 'viewport' ] );
            $this->assertContains( $l['feature'], [ 'none', 'right', 'left', 'top', 'cta' ] );
            $this->assertContains( $l['rows'], [ 'plain', 'desc', 'icons', 'cards' ] );
            $this->assertGreaterThanOrEqual( 200, $l['width'] );
            $this->assertLessThanOrEqual( 1200, $l['width'] );
            $this->assertGreaterThanOrEqual( 1, $l['columns'] );
            $this->assertLessThanOrEqual( 4, $l['columns'] );
            $this->assertNotSame( '', $l['name'] );
            $this->assertNotSame( '', $l['blurb'] );
            $names[] = $l['name'];
        }
        $this->assertSame( $names, array_values( array_unique( $names ) ) );
    }

    public function testTheTwoDivi5labPanelsAreInTheCatalog(): void
    {
        $this->assertSame( 'cta', Layouts::find( 'feature-cta' )['feature'], 'three icon-link columns and a call-to-action bar' );
        $this->assertSame( 'cards', Layouts::find( 'icons-cards' )['rows'], 'a grid of icon cards with a footer link' );
        $this->assertNull( Layouts::find( 'nope' ) );
    }

    public function testEveryPanelTextColourIsReadable(): void
    {
        foreach ( [ 'light', 'dark' ] as $tone ) {
            $t = Palette::tone( $tone );
            foreach ( Palette::TEXT_PAIRS as [ $fg, $bg ] ) {
                $this->assertGreaterThanOrEqual( 4.5, Palette::contrast( $t[ $fg ], $t[ $bg ] ), "$tone: $fg on $bg" );
            }
        }
    }

    // ---- column plan -----------------------------------------------------------------------------------------------

    public function testTheColumnPlanNeverDropsALink(): void
    {
        foreach ( MegaMenuFixture::menus() as $name => $tree ) {
            foreach ( $tree as $item ) {
                if ( [] === $item['children'] ) {
                    continue;
                }
                $expected = array_map( static fn ( array $i ): int => $i['id'], ColumnPlan::flatten( $item['children'] ) );
                foreach ( [ 1, 2, 3, 4 ] as $max ) {
                    $columns = ColumnPlan::from( $item, $max );
                    $this->assertLessThanOrEqual( $max, count( $columns ), "$name/{$item['title']}/$max" );
                    $got = [];
                    foreach ( $columns as $sections ) {
                        foreach ( $sections as $s ) {
                            if ( null !== $s['heading'] ) {
                                $got[] = $s['heading']['id'];
                            }
                            foreach ( $s['links'] as $l ) {
                                $got[] = $l['id'];
                            }
                        }
                    }
                    sort( $got );
                    $expectedSorted = $expected;
                    sort( $expectedSorted );
                    $this->assertSame( $expectedSorted, $got, "$name/{$item['title']}/$max columns keep every item exactly once" );
                }
            }
        }
    }

    public function testLooseLinksAreCutEvenlyOverTheColumns(): void
    {
        $blog = MegaMenuFixture::menus()['flat'][1]; // five loose links
        $cols = ColumnPlan::from( $blog, 3 );
        $this->assertSame( [ 2, 2, 1 ], array_map( static fn ( array $c ): int => count( $c[0]['links'] ), $cols ) );
        $this->assertSame( [], ColumnPlan::from( [ 'children' => [] ], 3 ) );
    }

    public function testExtraSectionsAreStackedUnderTheLastColumn(): void
    {
        $services = MegaMenuFixture::menus()['grouped'][1]; // loose "Care plans" + three groups = four sections
        $cols     = ColumnPlan::from( $services, 2 );
        $this->assertCount( 2, $cols );
        $this->assertCount( 1, $cols[0] );
        $this->assertCount( 3, $cols[1], 'the other three sections share the last column' );
    }

    // ---- menu tree and look ----------------------------------------------------------------------------------------

    public function testTheMenuTreeNestsOrdersAndSurvivesABrokenParent(): void
    {
        $flat = [
            [ 'id' => 3, 'parent' => 2, 'order' => 2, 'title' => 'B' ],
            [ 'id' => 2, 'parent' => 0, 'order' => 1, 'title' => 'Top' ],
            [ 'id' => 4, 'parent' => 2, 'order' => 1, 'title' => 'A' ],
            [ 'id' => 9, 'parent' => 77, 'order' => 5, 'title' => 'Orphan' ], // its parent is not in the menu
        ];
        $tree = MenuTree::build( $flat );
        $this->assertSame( [ 'Top', 'Orphan' ], array_column( $tree, 'title' ) );
        $this->assertSame( [ 'A', 'B' ], array_column( $tree[0]['children'], 'title' ) );
        $this->assertSame( 1, MenuTree::panelCount( $tree ) );
        $loop = MenuTree::build( [ [ 'id' => 1, 'parent' => 2, 'order' => 1, 'title' => 'x' ], [ 'id' => 2, 'parent' => 1, 'order' => 1, 'title' => 'y' ] ] );
        $this->assertSame( [], $loop, 'a parent loop yields nothing instead of recursing forever' );
    }

    public function testLookReadsFontLogoAndMenuId(): void
    {
        $attrs = json_decode( (string) preg_replace( '/^<!-- wp:divi\/menu (.*) \/-->$/s', '$1', MegaMenuFixture::menuBlock() ), true );
        $look  = Look::fromMenuAttrs( $attrs );
        $this->assertSame( '1rem', $look['font']['size'] );
        $this->assertSame( '#0b6e4f', $look['hoverColor'] );
        $this->assertSame( 'https://example.test/logo.png', $look['logo']['src'] );
        $this->assertSame( '230px', $look['logoWidth'] );
        $this->assertSame( '35', $look['menuId'] );
        $bare = Look::fromMenuAttrs( [] );
        $this->assertSame( Look::DEFAULT_FONT, $bare['font'] );
        $this->assertNull( $bare['logo'] );
    }

    // ---- header edit -----------------------------------------------------------------------------------------------

    private function wrapper( string $layout = 'columns-3' ): string
    {
        return MegaMenuFixture::wrapper( Layouts::find( $layout ), MegaMenuFixture::menus()['grouped'] );
    }

    public function testApplyHidesTheMenuOnDesktopOnlyAndPlacesTheWrapperRightAfterIt(): void
    {
        $header = MegaMenuFixture::header();
        $new    = HeaderEdit::apply( $header, $this->wrapper() );
        $info   = HeaderEdit::inspect( $new );
        $this->assertTrue( $info['hasMega'] );
        $this->assertSame( [ 'desktop' => [ 'value' => 'on' ], 'tablet' => [ 'value' => 'off' ], 'phone' => [ 'value' => 'off' ] ], $info['menu']['module']['decoration']['disabledOn'] );
        $this->assertMatchesRegularExpression( '#/-->' . preg_quote( '<!-- wp:divi/group', '#' ) . '#', $new, 'the wrapper follows the Menu module' );
        $this->assertStringContainsString( '"menuDropdown":{}', $new, 'an empty object in the Menu module stays an empty object' );
        $this->assertSame( 1, substr_count( $new, '<!-- wp:divi/search' ), 'the rest of the header is untouched' );
    }

    public function testApplyTwiceReplacesTheWrapperAndIsIdempotent(): void
    {
        $header = MegaMenuFixture::header();
        $once   = HeaderEdit::apply( $header, $this->wrapper( 'columns-3' ) );
        $twice  = HeaderEdit::apply( $once, $this->wrapper( 'columns-3' ) );
        $this->assertSame( $once, $twice );
        $other = HeaderEdit::apply( $once, $this->wrapper( 'icons-dark' ) );
        $this->assertSame( 1, substr_count( $other, Blocks::ADMIN_LABEL ), 'one wrapper only' );
        $this->assertStringContainsString( '#12161f', $other );
        $this->assertStringNotContainsString( 'Three columns', $other );
    }

    public function testRemoveRestoresTheHeaderByteForByte(): void
    {
        $header = MegaMenuFixture::header();
        $new    = HeaderEdit::apply( $header, $this->wrapper() );
        $this->assertNotSame( $header, $new );
        $back = HeaderEdit::remove( $new );
        $this->assertFalse( HeaderEdit::inspect( $back )['hasMega'] );
        $this->assertSame( $header, $back, 'remove() puts every byte back (the Menu module block is re-encoded the way WordPress encodes it)' );
        $this->assertSame( HeaderEdit::remove( $back ), $back, 'removing again changes nothing' );
    }

    public function testRemoveLeavesAMenuModuleAloneThatWeDidNotHide(): void
    {
        $custom = '<!-- wp:divi/menu {"module":{"decoration":{"disabledOn":{"phone":{"value":"on"}}}},"builderVersion":"5.14.0"} /-->';
        $header = MegaMenuFixture::header( $custom );
        $this->assertSame( $header, HeaderEdit::remove( $header ) );
    }

    public function testAHeaderWithoutAMenuModuleIsRefused(): void
    {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( HeaderEdit::NO_MENU );
        HeaderEdit::apply( '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->', $this->wrapper() );
    }

    public function testOnlyTheFirstMenuModuleIsTouched(): void
    {
        $two = MegaMenuFixture::header( MegaMenuFixture::menuBlock() . MegaMenuFixture::menuBlock() );
        $new = HeaderEdit::apply( $two, $this->wrapper() );
        $this->assertSame( 1, substr_count( $new, '"disabledOn":{"desktop":{"value":"on"}' ) );
    }
}
