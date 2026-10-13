<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ProductQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ProductQuery.php';

class ProductQueryTest extends TestCase
{
    public function testDefaults(): void
    {
        $p = ProductQuery::parse( [], false );
        $this->assertNull( $p['error'] );
        $this->assertSame( [
            'status' => 'any', 'type' => null, 'category' => null, 'stock_status' => null, 'on_sale' => null,
            'search' => null, 'orderby' => 'title', 'order' => 'asc', 'page' => 1, 'per_page' => 25,
        ], $p['args'] );
    }

    public function testAValidMcpCall(): void
    {
        $p = ProductQuery::parse( [ 'status' => 'draft', 'type' => 'variable', 'category' => 'music', 'stock_status' => 'outofstock', 'on_sale' => true, 'search' => ' hat ', 'orderby' => 'price', 'order' => 'desc', 'page' => 2, 'per_page' => 50 ], false );
        $this->assertNull( $p['error'] );
        $this->assertSame( [ 'draft', 'variable', 'music', 'outofstock', true, 'hat', 'price', 'desc', 2, 50 ], array_values( $p['args'] ) );
    }

    public function testRestAcceptsDigitStringsAndTrueFalseStrings(): void
    {
        $p = ProductQuery::parse( [ 'page' => '3', 'per_page' => '10', 'on_sale' => 'true', 'search' => '', 'status' => '' ], true );
        $this->assertNull( $p['error'] );
        $this->assertSame( 3, $p['args']['page'] );
        $this->assertSame( 10, $p['args']['per_page'] );
        $this->assertTrue( $p['args']['on_sale'] );
        $this->assertNull( $p['args']['search'] );
        $this->assertSame( 'any', $p['args']['status'] );
        $this->assertFalse( ProductQuery::parse( [ 'on_sale' => 'false' ], true )['args']['on_sale'] );
    }

    public static function badInputs(): array
    {
        return [
            'status trash'         => [ [ 'status' => 'trash' ], false ],
            'status number'        => [ [ 'status' => 5 ], false ],
            'type unknown'         => [ [ 'type' => 'bundle' ], false ],
            'stock unknown'        => [ [ 'stock_status' => 'maybe' ], false ],
            'orderby unknown'      => [ [ 'orderby' => 'rand' ], false ],
            'order unknown'        => [ [ 'order' => 'up' ], false ],
            'category with space'  => [ [ 'category' => 'a b' ], false ],
            'category too long'    => [ [ 'category' => str_repeat( 'a', 201 ) ], false ],
            'category array'       => [ [ 'category' => [ 'a' ] ], false ],
            'search too long'      => [ [ 'search' => str_repeat( 'a', 101 ) ], false ],
            'search number'        => [ [ 'search' => 7 ], false ],
            'on_sale string mcp'   => [ [ 'on_sale' => 'true' ], false ],
            'on_sale yes rest'     => [ [ 'on_sale' => 'yes' ], true ],
            'on_sale int'          => [ [ 'on_sale' => 1 ], false ],
            'page zero'            => [ [ 'page' => 0 ], false ],
            'page negative'        => [ [ 'page' => -1 ], false ],
            'page float'           => [ [ 'page' => 1.5 ], false ],
            'page string mcp'      => [ [ 'page' => '2' ], false ],
            'page letters rest'    => [ [ 'page' => 'abc' ], true ],
            'per_page zero'        => [ [ 'per_page' => 0 ], false ],
            'per_page 51'          => [ [ 'per_page' => 51 ], false ],
            'per_page 51 rest'     => [ [ 'per_page' => '51' ], true ],
            'per_page decimal rest' => [ [ 'per_page' => '2.5' ], true ],
        ];
    }

    #[DataProvider( 'badInputs' )]
    public function testBadInputIsRefusedNeverCastOrClamped( array $in, bool $rest ): void
    {
        $p = ProductQuery::parse( $in, $rest );
        $this->assertNull( $p['args'] );
        $this->assertNotEmpty( $p['error'] );
    }

    public function testNullMeansNotGivenAndUnknownKeysAreIgnored(): void
    {
        $p = ProductQuery::parse( [ 'status' => null, 'per_page' => null, 'id' => 5, 'whatever' => 'x' ], false );
        $this->assertNull( $p['error'] );
        $this->assertSame( 25, $p['args']['per_page'] );
    }

    public function testPercentEncodedSlugsAreAllowed(): void
    {
        $this->assertSame( '%d0%bc%d1%83%d0%b7', ProductQuery::parse( [ 'category' => '%d0%bc%d1%83%d0%b7' ], false )['args']['category'] );
    }
}
