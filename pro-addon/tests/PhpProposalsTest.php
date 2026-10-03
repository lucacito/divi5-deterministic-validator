<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\PhpProposals;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class PhpProposalsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = [];
    }

    public function testAddStoresTheCodeVerbatimAndReturnsAnId(): void
    {
        $code = "<?php\n// \$x = '\\n'; </code></pre><script>alert(1)</script>";
        $id   = PhpProposals::add( 'My CPT', 'Registers a post type', $code, 7 );
        $this->assertMatchesRegularExpression( '/^php_[a-f0-9]{12}$/', $id );
        $all = PhpProposals::all();
        $this->assertSame( 1, PhpProposals::count() );
        $this->assertSame( [ 'id' => $id, 'title' => 'My CPT', 'description' => 'Registers a post type', 'code' => $code, 'user_id' => 7 ], array_diff_key( $all[0], [ 'created' => 1 ] ) );
        $this->assertIsInt( $all[0]['created'] );
    }

    public function testNewestFirstAndAnEmptyTitleFallsBack(): void
    {
        PhpProposals::add( 'First', '', 'a', 1 );
        PhpProposals::add( '', '', 'b', 1 );
        $this->assertSame( [ 'Untitled snippet', 'First' ], array_column( PhpProposals::all(), 'title' ) );
    }

    public function testDeleteRemovesExactlyOne(): void
    {
        $a = PhpProposals::add( 'A', '', 'a', 1 );
        PhpProposals::add( 'B', '', 'b', 1 );
        PhpProposals::delete( $a );
        $this->assertSame( [ 'B' ], array_column( PhpProposals::all(), 'title' ) );
        PhpProposals::delete( 'php_unknown' );
        $this->assertSame( 1, PhpProposals::count() );
    }

    public function testOnlyTheNewest50AreKept(): void
    {
        for ( $i = 1; $i <= 52; $i++ ) {
            PhpProposals::add( "P{$i}", '', 'c', 1 );
        }
        $this->assertSame( 50, PhpProposals::count() );
        $this->assertSame( 'P52', PhpProposals::all()[0]['title'] );
        $this->assertSame( 'P3', PhpProposals::all()[49]['title'] );
    }

    public function testClearAndACorruptOptionAreSafe(): void
    {
        PhpProposals::add( 'A', '', 'a', 1 );
        PhpProposals::clear();
        $this->assertSame( 0, PhpProposals::count() );
        $GLOBALS['__wp_options'][ PhpProposals::OPTION ] = 'garbage';
        $this->assertSame( [], PhpProposals::all() );
    }
}
