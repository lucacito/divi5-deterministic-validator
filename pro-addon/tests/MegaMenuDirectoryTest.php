<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\MegaMenu\Blocks;
use AiEditorDivi5\Pro\MegaMenu\Layouts;
use AiEditorDivi5\Pro\MegaMenu\MenuDrift;
use AiEditorDivi5\Pro\MegaMenu\NavBuilder;
use AiEditorDivi5\Pro\MegaMenu\PanelBuilder;
use AiEditorDivi5\Pro\MegaMenu\Palette;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MegaMenuFixture.php';

/**
 * The "Grouped list with counts" layout: groups of icon rows with a post count, linked cards along the bottom.
 */
final class MegaMenuDirectoryTest extends TestCase
{
    private function layout(): array
    {
        $layout = Layouts::find( 'directory-counts' );
        $this->assertNotNull( $layout );

        return $layout;
    }

    /** @param array<int,array<string,mixed>> $content the owner's content per menu item id (the panel's own icon, and one per link) */
    private function build( array $tree, array $content = [] ): string
    {
        return ( new NavBuilder( new Blocks( '5.14.0' ) ) )->build(
            $this->layout(),
            $tree,
            [ 'look' => MegaMenuFixture::look(), 'posts' => [], 'featureImage' => '', 'content' => $content ]
        );
    }

    public function testEveryLinkOfAGroupIsARowAndOnlyItemsWithACountGetAPill(): void
    {
        $tree   = MegaMenuFixture::menus()['directory'];
        $markup = $this->build( $tree );
        foreach ( [ 'Hero sections', 'Pricing tables', 'About us', 'SaaS', 'Agencies', 'Minimal', 'Bold' ] as $title ) {
            $this->assertSame( 1, substr_count( $markup, '"text":"' . $title . '"' ), $title . ' is one Link' );
        }
        // Six items know their count (a category with 0 posts shows 0); "About us" is a custom link and shows none.
        $this->assertSame( 6, substr_count( $markup, '"adminLabel":{"desktop":{"value":"' . Blocks::COUNT_LABEL . '"}}' ) );
        foreach ( [ '63', '39', '62', '0', '92', '116' ] as $n ) {
            $this->assertStringContainsString( '"innerContent":{"desktop":{"value":"\u003cp\u003e' . $n . '\u003c/p\u003e"}}', $markup, 'count ' . $n );
        }
    }

    public function testTheSecondLevelGroupsAreCapitalisedLabelsNotLinks(): void
    {
        $markup = $this->build( MegaMenuFixture::menus()['directory'] );
        $this->assertSame( 3, substr_count( $markup, '"capitalization":"uppercase"' ) );
        $this->assertSame( 3, substr_count( $markup, '"letterSpacing":"1px"' ) );
        foreach ( [ 'By type', 'By industry', 'By style' ] as $heading ) {
            $this->assertStringContainsString( '\u003cp\u003e' . $heading . '\u003c/p\u003e', $markup );
            $this->assertStringNotContainsString( '"text":"' . $heading . '"', $markup, $heading . ' must not be a link' );
        }
    }

    public function testSecondLevelLinksWithNothingUnderThemBecomeCardsWithTheirDescription(): void
    {
        $markup = $this->build( MegaMenuFixture::menus()['directory'] );
        foreach ( [ 'Browse all layouts', 'Theme packs' ] as $card ) {
            $this->assertSame( 1, substr_count( $markup, '"text":"' . $card . '"' ), $card );
        }
        $this->assertStringContainsString( 'The full validated catalog.', $markup );
        $this->assertStringContainsString( 'Complete multi-page websites, one price.', $markup );
        // Two cards in one row of two.
        $this->assertSame( 1, substr_count( $markup, '"gridColumnCount":"2"' ) );
        $this->assertSame( 1, substr_count( $markup, '"gridColumnCount":"3"' ), 'three groups, three columns' );
    }

    public function testEveryRowTakesTheHoverTintAndNothingElseDoes(): void
    {
        $t      = Palette::tone( 'light' );
        $markup = $this->build( MegaMenuFixture::menus()['directory'] );
        $this->assertSame( 7, substr_count( $markup, '"background":{"desktop":{"hover":{"color":"' . $t['hover'] . '"}}}' ), 'seven rows' );
    }

    public function testAMenuWithNoGroupsGivesPlainRowsInColumnsAndNoCards(): void
    {
        $tree   = MegaMenuFixture::menus()['flat'];
        $markup = $this->build( $tree );
        foreach ( [ 'News', 'Guides', 'Case studies', 'Podcast', 'Events' ] as $title ) {
            $this->assertSame( 1, substr_count( $markup, '"text":"' . $title . '"' ), $title );
        }
        $this->assertStringNotContainsString( '"capitalization":"uppercase"', $markup, 'no groups, no headings' );
        $this->assertStringNotContainsString( 'View all', $markup );
        $this->assertSame( 5, substr_count( $markup, '"hover":{"color":"#eef2ff"}' ) );
    }

    public function testTheIconsAreTheOwnersPerRowThenThePanelsThenTheArrow(): void
    {
        $tree   = MegaMenuFixture::menus()['directory'];
        $markup = $this->build( $tree, [ 1 => [ 'icon' => '&#xf135;' ], 4 => [ 'icon' => '&#xf015;' ] ] );
        $this->assertSame( 1, substr_count( $markup, 'u0026#xf015;' ), 'Pricing tables got the house' );
        $this->assertSame( 6, substr_count( $markup, 'u0026#xf135;' ), 'the other six rows got the panel icon' );
        // The cards keep their arrow.
        $this->assertSame( 2, substr_count( $markup, 'u0026#xf061;' ) );
    }

    public function testTheRowsTheOwnerChoosesIconsForAreThoseOfThePanel(): void
    {
        $tree = MegaMenuFixture::menus()['directory'];
        $this->assertSame( [ 3, 4, 5, 7, 8, 10, 11 ], array_map( static fn ( array $r ): int => $r['id'], PanelBuilder::directoryRows( $tree[0] ) ) );
        $flat = MegaMenuFixture::menus()['flat'];
        $this->assertSame( [ 3, 4, 5, 6, 7 ], array_map( static fn ( array $r ): int => $r['id'], PanelBuilder::directoryRows( $flat[1] ) ) );
    }

    public function testACountThatChangesIsNotDriftButARenamedLinkIs(): void
    {
        $a = MegaMenuFixture::menus()['directory'];
        $b = $a;
        $b[0]['children'][0]['children'][0]['count'] = 64;
        $same = MenuDrift::compare( MenuDrift::signature( $this->build( $a ) ), MenuDrift::signature( $this->build( $b ) ) );
        $this->assertSame( [], $same, 'a post count is a snapshot, not part of the menu' );
        $c = $a;
        $c[0]['children'][0]['children'][0]['title'] = 'Hero blocks';
        $this->assertNotSame( [], MenuDrift::compare( MenuDrift::signature( $this->build( $a ) ), MenuDrift::signature( $this->build( $c ) ) ) );
    }

    public function testTheTintKeepsEveryTextReadableOnBothTones(): void
    {
        foreach ( [ 'light', 'dark' ] as $tone ) {
            $t = Palette::tone( $tone );
            foreach ( [ 'text', 'muted', 'accent' ] as $ink ) {
                $this->assertGreaterThanOrEqual( 4.5, Palette::contrast( $t[ $ink ], $t['hover'] ), $tone . ' ' . $ink . ' on the hover tint' );
            }
        }
    }
}
