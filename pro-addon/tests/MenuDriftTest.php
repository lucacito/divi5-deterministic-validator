<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\MegaMenu\Blocks;
use AiEditorDivi5\Pro\MegaMenu\MenuDrift;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

final class MenuDriftTest extends TestCase
{
    private function wrapper( string $inner ): string
    {
        return ( new Blocks() )->group( [ 'display' => 'flex' ], $inner, [], Blocks::ADMIN_LABEL );
    }

    private function nav(): string
    {
        $b     = new Blocks();
        $panel = fn ( int $id, string $inner ): string => $b->dropdown( [ 'showOn' => 'hover' ], $inner, [], 'click', Blocks::panelLabel( $id, 'columns-dark' ) );

        return $this->wrapper(
            $b->link( 'Home', 'https://example.test/', [], null )
            . $b->link( 'Services', 'https://example.test/s/', [], null, $panel( 2, $b->text( '<p>Group</p>' ) . $b->link( 'Design', 'https://example.test/d/', [] ) . $b->link( 'Build', 'https://example.test/b/', [] ) ) )
        );
    }

    public function testEveryLinkAndLabelIsAttributedToItsPanel(): void
    {
        $sig = MenuDrift::signature( $this->nav() );
        $this->assertSame( [ "link\tHome\thttps://example.test/", "link\tServices\thttps://example.test/s/" ], $sig[ MenuDrift::TOP ] );
        $this->assertSame( [ "text\tGroup\t", "link\tDesign\thttps://example.test/d/", "link\tBuild\thttps://example.test/b/" ], $sig[2] );
    }

    public function testPostCardsAreNotPartOfTheMenu(): void
    {
        $b   = new Blocks();
        $nav = $this->wrapper(
            $b->link( 'Blog', 'https://example.test/blog/', [], null, $b->dropdown( [ 'showOn' => 'hover' ], $b->link( 'News', 'https://example.test/n/', [] ) . $b->group( [ 'display' => 'flex' ], $b->link( 'A post', 'https://example.test/p/', [] ), [], Blocks::POST_LABEL ), [], 'click', Blocks::panelLabel( 5, 'latest-cards' ) ) )
        );
        $this->assertSame( [ "link\tNews\thttps://example.test/n/" ], MenuDrift::signature( $nav )[5] );
    }

    public function testTextIsReadWithoutMarkupOrEntities(): void
    {
        $b   = new Blocks();
        $nav = $this->wrapper( $b->link( 'Tom &amp; Jerry', 'https://example.test/t/', [] ) . $b->text( '<p>One <b>two</b>&nbsp;&amp; three</p>' ) );
        $this->assertSame( "link\tTom & Jerry\thttps://example.test/t/", MenuDrift::signature( $nav )[ MenuDrift::TOP ][0] );
        $this->assertSame( "text\tOne two & three\t", MenuDrift::signature( $nav )[ MenuDrift::TOP ][1] );
    }

    public function testNothingDiffersFromItself(): void
    {
        $sig = MenuDrift::signature( $this->nav() );
        $this->assertSame( [], MenuDrift::compare( $sig, $sig ) );
    }

    public function testMissingExtraAndReorderedAreTold(): void
    {
        $have = [ 0 => [ "link\tA\tu1", "link\tB\tu2" ], 2 => [ "link\tX\tx", "link\tY\ty" ], 3 => [ "link\tP\tp", "link\tQ\tq" ] ];
        $want = [ 0 => [ "link\tA\tu1", "link\tB\tu2", "link\tC\tu3" ], 2 => [ "link\tX\tx" ], 3 => [ "link\tQ\tq", "link\tP\tp" ] ];
        $diff = MenuDrift::compare( $have, $want );
        $this->assertSame( [ 'C' ], $diff[0]['missing'] );
        $this->assertSame( [ 'Y' ], $diff[2]['extra'] );
        $this->assertTrue( $diff[3]['reordered'] );
        $this->assertSame( [], $diff[3]['missing'] );
    }

    public function testARepeatedLinkCountsAsOftenAsItRepeats(): void
    {
        $diff = MenuDrift::compare( [ 0 => [ "link\tA\tu" ] ], [ 0 => [ "link\tA\tu", "link\tA\tu" ] ] );
        $this->assertSame( [ 'A' ], $diff[0]['missing'] );
    }

    public function testASnapshotPanelOnlyCountsWhatTheMenuIsMissing(): void
    {
        $have = [ 5 => [ "link\tNews\tn", "link\tOld post\to" ] ];
        $want = [ 5 => [ "link\tNews\tn", "link\tGuides\tg" ] ];
        $this->assertSame( [], MenuDrift::compare( $have, [ 5 => [ "link\tNews\tn" ] ], [ 5 ] ), 'extras of a snapshot panel are ignored' );
        $this->assertSame( [ 'Guides' ], MenuDrift::compare( $have, $want, [ 5 ] )[5]['missing'] );
    }

    public function testAHeaderWithoutAWrapperHasOnlyAnEmptyBar(): void
    {
        $this->assertSame( [ MenuDrift::TOP => [] ], MenuDrift::signature( '' ) );
    }
}
