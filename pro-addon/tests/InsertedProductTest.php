<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Products\InsertedProduct;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/**
 * The pure rule WooProductWriter uses to decide which post a failed save may delete. The writer itself is proven live by
 * `make verify-pro-create-product` (a foreign product inserted by another hook during our failing save must survive).
 */
class InsertedProductTest extends TestCase
{
    private static function post( array $fields ): object
    {
        return (object) ( $fields + [ 'ID' => 42, 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Blue mug' ] );
    }

    public function testOurDraftMatches(): void
    {
        $this->assertTrue( InsertedProduct::matches( self::post( [] ), 'Blue mug' ) );
    }

    public function testATitleCoreKsesEncodedStillMatches(): void
    {
        // A user without unfiltered_html: core's title_save_pre turns "&" into "&amp;".
        $this->assertTrue( InsertedProduct::matches( self::post( [ 'post_title' => 'Salt &amp; pepper' ] ), 'Salt & pepper' ) );
    }

    /** @return array<string,array{mixed}> */
    public static function notOurs(): array
    {
        return [
            'a foreign product (other title)' => [ self::post( [ 'post_title' => 'Foreign' ] ) ],
            'a title differing by case'       => [ self::post( [ 'post_title' => 'blue mug' ] ) ],
            'a title with extra spaces'       => [ self::post( [ 'post_title' => 'Blue mug ' ] ) ],
            'a published product'             => [ self::post( [ 'post_status' => 'publish' ] ) ],
            'a trashed product'               => [ self::post( [ 'post_status' => 'trash' ] ) ],
            'a page'                          => [ self::post( [ 'post_type' => 'page' ] ) ],
            'a variation'                     => [ self::post( [ 'post_type' => 'product_variation' ] ) ],
            'id 0'                            => [ self::post( [ 'ID' => 0 ] ) ],
            'no title'                        => [ (object) [ 'ID' => 42, 'post_type' => 'product', 'post_status' => 'draft' ] ],
            'null (deleted)'                  => [ null ],
            'not an object'                   => [ [ 'ID' => 42 ] ],
        ];
    }

    #[DataProvider( 'notOurs' )]
    public function testAnythingElseDoesNotMatch( mixed $post ): void
    {
        $this->assertFalse( InsertedProduct::matches( $post, 'Blue mug' ) );
    }

    public function testAnEmptyNameNeverMatches(): void
    {
        $this->assertFalse( InsertedProduct::matches( self::post( [ 'post_title' => '' ] ), '' ) );
    }

    public function testTheObjectIdWinsOverTheRecordedOnes(): void
    {
        $this->assertSame( 7, InsertedProduct::candidate( 7, [ 9 ] ) );
        $this->assertSame( 7, InsertedProduct::candidate( 7, [ 3, 9 ] ), 'WooCommerce set it on the object: certain' );
        $this->assertFalse( InsertedProduct::ambiguous( 7, [ 3, 9 ] ) );
    }

    public function testOneRecordedIdIsUsedOnlyWhenTheObjectHasNone(): void
    {
        $this->assertSame( 9, InsertedProduct::candidate( 0, [ 9 ] ) );
        $this->assertSame( 9, InsertedProduct::candidate( 0, [ 9, 9 ] ), 'the same post seen twice is one post' );
        $this->assertSame( 0, InsertedProduct::candidate( 0, [] ) );
        $this->assertSame( 0, InsertedProduct::candidate( -1, [ -5, 0 ] ) );
        $this->assertFalse( InsertedProduct::ambiguous( 0, [ 9 ] ) );
        $this->assertFalse( InsertedProduct::ambiguous( 0, [] ) );
    }

    public function testTwoMatchingPostsAreAmbiguousAndNothingIsDeleted(): void
    {
        $this->assertTrue( InsertedProduct::ambiguous( 0, [ 9, 10 ] ) );
        $this->assertSame( 0, InsertedProduct::candidate( 0, [ 9, 10 ] ) );
    }
}
