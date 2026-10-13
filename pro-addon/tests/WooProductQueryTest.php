<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Products\WooProductWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/**
 * The pure parts of WooProductWriter::findProducts (the query arguments and the name match). They call neither WooCommerce nor WordPress,
 * so they need no stub class; the rest of the writer is proven by the live gate.
 */
class WooProductQueryTest extends TestCase
{
    public function testDefaults(): void
    {
        $q = WooProductWriter::findQuery( [], 10 );
        $this->assertSame(
            [
                'return'   => 'ids',
                'status'   => [ 'publish' ],
                'type'     => [ 'simple', 'variable', 'external' ],
                'orderby'  => 'ID',
                'order'    => 'ASC',
                'limit'    => 11,
                'paginate' => false,
            ],
            $q
        );
    }

    public function testStatusAndTypesAreKept(): void
    {
        $q = WooProductWriter::findQuery( [ 'status' => [ 'publish', 'draft' ], 'types' => [ 'simple' ] ], 5 );
        $this->assertSame( [ 'publish', 'draft' ], $q['status'] );
        $this->assertSame( [ 'simple' ], $q['type'] );
    }

    public function testCategoriesTagsAndStockStatusArePassed(): void
    {
        $q = WooProductWriter::findQuery( [ 'categories' => [ 'shoes' ], 'tags' => [ 'sale', 'new' ], 'stock_status' => 'instock' ], 10 );
        $this->assertSame( [ 'shoes' ], $q['category'] );
        $this->assertSame( [ 'sale', 'new' ], $q['tag'] );
        $this->assertSame( 'instock', $q['stock_status'] );
    }

    public function testEmptyListsAndNullsSendNothing(): void
    {
        $q = WooProductWriter::findQuery( [ 'categories' => [], 'tags' => [], 'stock_status' => null, 'ids' => [], 'exclude_ids' => [], 'search' => null, 'on_sale' => null ], 10 );
        foreach ( [ 'category', 'tag', 'stock_status', 'include', 'exclude', 's' ] as $key ) {
            $this->assertArrayNotHasKey( $key, $q );
        }
    }

    public function testIdsAndExcludeIdsAreCombinedIntoOneInclude(): void
    {
        $q = WooProductWriter::findQuery( [ 'ids' => [ 5, 6, 7, 8 ], 'exclude_ids' => [ 6, 8, 99 ] ], 10 );
        $this->assertSame( [ 5, 7 ], $q['include'] );
        $this->assertArrayNotHasKey( 'exclude', $q, 'WP_Query ignores post__not_in when post__in is set, so it must never be sent with include' );
    }

    public function testIdsThatAreAllExcludedMatchNothing(): void
    {
        $this->assertNull( WooProductWriter::findQuery( [ 'ids' => [ 5, 6 ], 'exclude_ids' => [ 6, 5, 9 ] ], 10 ) );
    }

    public function testExcludeAloneIsSentAsExclude(): void
    {
        $q = WooProductWriter::findQuery( [ 'exclude_ids' => [ 3, 4 ] ], 10 );
        $this->assertSame( [ 3, 4 ], $q['exclude'] );
        $this->assertArrayNotHasKey( 'include', $q );
    }

    public function testPlainSearchIsSentToNarrowTheQuery(): void
    {
        $this->assertSame( 'Blue Shirt 2', WooProductWriter::findQuery( [ 'search' => 'Blue Shirt 2' ], 10 )['s'] );
        $this->assertSame( 'café été', WooProductWriter::findQuery( [ 'search' => 'café été' ], 10 )['s'] );
    }

    /** @return array<string,array{string}> */
    public static function unsafeSearches(): array
    {
        return [
            'leading minus (means NOT)' => [ '-50' ],
            'minus in a phrase'         => [ 'shirt -blue' ],
            'double quote'              => [ 'the "big" one' ],
            'apostrophe'                => [ "men's shirt" ],
            'punctuation'               => [ 'shirt, blue' ],
        ];
    }

    #[DataProvider( 'unsafeSearches' )]
    public function testSearchWithOtherCharactersIsNeverSent( string $search ): void
    {
        $q = WooProductWriter::findQuery( [ 'search' => $search ], 10 );
        $this->assertArrayNotHasKey( 's', $q );
        $this->assertSame( 501, $q['limit'], 'the name is decided in PHP, so the scan cap applies' );
    }

    public function testLimitIsOnePlusTheLimitWithoutAScan(): void
    {
        $this->assertSame( 26, WooProductWriter::findQuery( [], 25 )['limit'] );
        $this->assertSame( 2, WooProductWriter::findQuery( [], 1 )['limit'] );
    }

    public function testLimitIsTheScanCapForOnSaleOrSearch(): void
    {
        $this->assertSame( 501, WooProductWriter::findQuery( [ 'on_sale' => true ], 25 )['limit'] );
        $this->assertSame( 501, WooProductWriter::findQuery( [ 'on_sale' => false ], 25 )['limit'] );
        $this->assertSame( 501, WooProductWriter::findQuery( [ 'search' => 'shirt' ], 25 )['limit'] );
        $this->assertSame( 501, WooProductWriter::findQuery( [ 'on_sale' => true, 'search' => 'shirt' ], 25 )['limit'] );
    }

    public function testNameMatchesIgnoresCase(): void
    {
        $this->assertTrue( WooProductWriter::nameMatches( 'Blue SHIRT', 'blue shirt' ) );
        $this->assertTrue( WooProductWriter::nameMatches( 'blue shirt', 'SHIRT' ) );
    }

    public function testNameMatchesUnicode(): void
    {
        $this->assertTrue( WooProductWriter::nameMatches( 'Été Café', 'été café' ) );
        $this->assertTrue( WooProductWriter::nameMatches( 'ÉTÉ', 'été' ) );
        $this->assertFalse( WooProductWriter::nameMatches( 'Cafe', 'café' ) );
    }

    public function testNameMatchesThePhraseNotTheWords(): void
    {
        $this->assertTrue( WooProductWriter::nameMatches( 'Blue cotton shirt', 'cotton shirt' ) );
        $this->assertFalse( WooProductWriter::nameMatches( 'Blue cotton shirt', 'shirt blue' ) );
        $this->assertFalse( WooProductWriter::nameMatches( 'Blue shirt', 'blue cotton shirt' ) );
    }

    public function testNameMatchesAMinusLiterally(): void
    {
        $this->assertTrue( WooProductWriter::nameMatches( 'Bundle -50 pack', '-50' ) );
        $this->assertFalse( WooProductWriter::nameMatches( 'Bundle 50 pack', '-50' ) );
    }
}
