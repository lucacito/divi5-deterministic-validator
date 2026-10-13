<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\ProductCreatedKind;
use AiEditorDivi5\Pro\Tools\CreateProduct;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpKsesShim.php';
require_once __DIR__ . '/WpBuildShims.php';
require_once __DIR__ . '/FakeProductWriter.php';

class CreateProductTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private FakeProductWriter $writer;
    private CreateProduct $tool;

    protected function setUp(): void
    {
        $this->repo   = new InMemoryBatchRepository();
        $this->log    = new BatchLog( $this->repo );
        $this->writer = new FakeProductWriter();
        $this->tool   = new CreateProduct( $this->log, $this->writer );
        $GLOBALS['__wp_caps'] = [ 'edit_products' => true, 'assign_product_terms' => true ];
        $GLOBALS['__wp_posts'] = [];
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'] );
    }

    private function go( array $products, bool $dry = false ): array
    {
        return $this->tool->run( [ 'products' => $products, 'dry_run' => $dry ] );
    }

    private function refused( array $args, string $message ): void
    {
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( 0, $this->writer->createCalls, 'nothing was created' );
        $this->assertSame( [], $this->repo->rows, 'no batch was recorded' );
    }

    private function hat( array $over = [] ): array
    {
        return $over + [ 'name' => 'Blue Hat', 'sku' => 'HAT-1', 'regular_price' => '20', 'manage_stock' => true, 'stock_quantity' => 12, 'categories' => [ 'hats' ] ];
    }

    // ---- definition -----------------------------------------------------------------------------

    public function testTheDefinition(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'create_product', $d['name'] );
        $this->assertSame( 'edit_posts', $this->tool->capability() );
        $this->assertLessThanOrEqual( 400, strlen( $d['description'] ) );
        $this->assertSame( [ 'products' ], $d['inputSchema']['required'] );
        $this->assertSame( 'array', $d['inputSchema']['properties']['products']['type'] );
        $this->assertSame( 'boolean', $d['inputSchema']['properties']['dry_run']['type'] );
        $this->assertSame( 25, $d['inputSchema']['properties']['products']['maxItems'] );
    }

    // ---- a real run -----------------------------------------------------------------------------

    public function testItCreatesDraftsRecordsOneBatchAndReturnsEachProduct(): void
    {
        $r = $this->go( [ $this->hat(), $this->hat( [ 'name' => 'Red Scarf', 'sku' => 'SC-1', 'categories' => [ 'shirts' ], 'tags' => [ 'sale' ] ] ) ] );
        $this->assertTrue( $r['changed'] );
        $this->assertFalse( $r['dry_run'] );
        $this->assertSame( [ 100, 101 ], array_column( $r['products'], 'id' ) );
        $this->assertSame( [ 'Blue Hat', 'Red Scarf' ], array_column( $r['products'], 'name' ) );
        $this->assertSame( 'draft', $r['products'][0]['status'] );
        $this->assertSame( 'EDIT-100', $r['products'][0]['edit_url'] );
        $this->assertCount( 1, $this->repo->rows, 'one batch for the whole call' );
        $batch = array_values( $this->repo->rows )[0];
        $this->assertSame( 'create_product', $batch['tool'] );
        $this->assertCount( 2, $batch['items'] );
        $this->assertSame( 'product_created', $batch['items'][0]['kind'] );
        $this->assertSame( [ 4 ], $this->writer->created[0]['category_ids'], 'slugs were resolved to term ids' );
        $this->assertSame( [ 3 ], $this->writer->created[1]['category_ids'] );
        $this->assertSame( [ 7 ], $this->writer->created[1]['tag_ids'] );
    }

    // ---- dry run --------------------------------------------------------------------------------

    public function testDryRunWritesNothingAndShowsTheNormalisedProducts(): void
    {
        $r = $this->go( [ $this->hat() ], true );
        $this->assertFalse( $r['changed'] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertSame( 'Blue Hat', $r['products'][0]['name'] );
        $this->assertSame( [ 'hats' ], $r['products'][0]['categories'] );
        $this->assertSame( 0, $this->writer->createCalls );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testDryRunStillRunsEveryCheck(): void
    {
        $this->refused( [ 'products' => [ $this->hat( [ 'sale_price' => '30' ] ) ], 'dry_run' => true ], 'lower' );
    }

    // ---- all or nothing -------------------------------------------------------------------------

    public function testOneRefusedProductCreatesNothingAndEveryReasonIsReportedWithItsPosition(): void
    {
        $this->refused( [ 'products' => [ $this->hat(), $this->hat( [ 'name' => 'Bad', 'sku' => 'X', 'sale_price' => '1', 'categories' => [ 'nope' ] ] ), $this->hat( [ 'name' => '' ] ) ] ], 'Product 2 "Bad"' );
        try {
            $this->tool->run( [ 'products' => [ $this->hat(), $this->hat( [ 'name' => 'Bad', 'sku' => 'X', 'sale_price' => '1', 'categories' => [ 'nope' ] ] ), $this->hat( [ 'name' => '' ] ) ] ] );
        } catch ( \InvalidArgumentException $e ) {
            $m = $e->getMessage();
            $this->assertStringContainsString( 'Nothing was created', $m );
            $this->assertStringContainsString( 'Product 2 "Bad"', $m );
            $this->assertStringContainsString( 'Product 3', $m );
            $this->assertStringContainsString( 'category "nope" does not exist', $m );
            $this->assertStringNotContainsString( 'Product 1', $m );
        }
    }

    public function testASkuAlreadyOnTheSiteAndADuplicateInsideTheCallAreRefused(): void
    {
        $this->writer->skus['TAKEN'] = true;
        $this->refused( [ 'products' => [ $this->hat( [ 'sku' => 'TAKEN' ] ) ] ], 'already used' );
        $this->refused( [ 'products' => [ $this->hat(), $this->hat( [ 'name' => 'Other' ] ) ] ], 'more than once' );
        $this->refused( [ 'products' => [ $this->hat(), $this->hat( [ 'name' => 'Other', 'sku' => 'hat-1' ] ) ] ], 'more than once' );
    }

    public function testUnknownCategoryAndTagSlugsAreRefusedByName(): void
    {
        $this->refused( [ 'products' => [ $this->hat( [ 'categories' => [ 'hats', 'ghost' ] ] ) ] ], 'category "ghost" does not exist' );
        $this->refused( [ 'products' => [ $this->hat( [ 'tags' => [ 'phantom' ] ] ) ] ], 'tag "phantom" does not exist' );
    }

    public function testImagesMustBeUsableMediaLibraryImages(): void
    {
        $this->refused( [ 'products' => [ $this->hat( [ 'image_id' => 555 ] ) ] ], 'image_id 555' );
        $this->refused( [ 'products' => [ $this->hat( [ 'image_id' => 90, 'gallery_image_ids' => [ 91, 556 ] ] ) ] ], 'gallery_image_ids 556' );
        $r = $this->go( [ $this->hat( [ 'image_id' => 90, 'gallery_image_ids' => [ 91 ] ] ) ] );
        $this->assertSame( 90, $this->writer->created[0]['image_id'] );
        $this->assertTrue( $r['changed'] );
    }

    public function testPermissionsAndWooCommerceStateAreCheckedFirst(): void
    {
        $this->writer->active = false;
        $this->refused( [ 'products' => [ $this->hat() ] ], 'WooCommerce is not active' );
        $this->writer->active = true;

        $GLOBALS['__wp_caps'] = [ 'edit_products' => false ];
        $this->refused( [ 'products' => [ $this->hat() ] ], 'edit_products' );

        $GLOBALS['__wp_caps'] = [ 'edit_products' => true, 'assign_product_terms' => false ];
        $this->refused( [ 'products' => [ $this->hat() ] ], 'assign_product_terms' );
        $r = $this->go( [ $this->hat( [ 'categories' => null ] ) ] ); // no terms asked: the term permission is not needed
        $this->assertTrue( $r['changed'] );
    }

    public function testArgumentsAreTypeCheckedNeverCast(): void
    {
        foreach ( [ [ 'products' => 'x' ], [ 'products' => [] ], [ 'products' => [ 'a' => 1 ] ], [ 'products' => [ 'string' ] ], [ 'products' => array_fill( 0, 26, [ 'name' => 'x' ] ) ], [ 'products' => [ [ 'name' => 'x' ] ], 'dry_run' => 'yes' ], [] ] as $bad ) {
            try {
                $this->tool->run( $bad );
                $this->fail( 'expected refusal for ' . json_encode( $bad ) );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertNotSame( '', $e->getMessage() );
            }
        }
        $this->assertSame( 0, $this->writer->createCalls );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testALongReasonListIsCutAtSixThousandCharacters(): void
    {
        $products = array_fill( 0, 25, [ 'name' => '', 'sku' => str_repeat( 'a', 200 ), 'description' => str_repeat( 'x', 11000 ), 'regular_price' => 'bad', 'bogus1' => 1, 'bogus2' => 2, 'bogus3' => 3 ] );
        try {
            $this->tool->run( [ 'products' => $products ] );
            $this->fail( 'expected refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertLessThanOrEqual( 6000, mb_strlen( $e->getMessage() ) );
            $this->assertStringEndsWith( '...', $e->getMessage() );
        }
    }

    // ---- failure while creating: rollback -------------------------------------------------------

    public function testAFailingSaveRollsBackWhatThisCallCreatedAndRecordsNoBatch(): void
    {
        $this->writer->failOnCreate = 3;
        try {
            $this->go( [ $this->hat(), $this->hat( [ 'name' => 'B', 'sku' => 'B' ] ), $this->hat( [ 'name' => 'C', 'sku' => 'C' ] ) ] );
            $this->fail( 'expected failure' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Product 3 "C"', $e->getMessage() );
            $this->assertStringContainsString( 'Nothing was created', $e->getMessage() );
            $this->assertStringNotContainsString( '/var/www', $e->getMessage(), 'an exception message from the store never reaches the client' );
        }
        $this->assertSame( [ 101, 100 ], $this->writer->deleted, 'the two created products were deleted, newest first' );
        $this->assertSame( [], $this->writer->products );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testAReadBackThatDiffersRollsEverythingBack(): void
    {
        $this->writer->afterCreate = static function ( array &$snap, int $call ): void {
            if ( 2 === $call ) {
                $snap['regular_price'] = '99999'; // the store saved something else
            }
        };
        try {
            $this->go( [ $this->hat(), $this->hat( [ 'name' => 'B', 'sku' => 'B' ] ) ] );
            $this->fail( 'expected failure' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Product 2 "B"', $e->getMessage() );
            $this->assertStringContainsString( 'regular_price', $e->getMessage() );
        }
        $this->assertSame( [ 101, 100 ], $this->writer->deleted );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testAProductThatComesBackPublishedIsAFailure(): void
    {
        $this->writer->afterCreate = static function ( array &$snap ): void {
            $snap['status'] = 'publish';
        };
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'status' );
        $this->go( [ $this->hat() ] );
    }

    public function testARollbackThatCannotDeleteNamesTheProduct(): void
    {
        $this->writer->failOnCreate = 2;
        $this->writer->deleteFails  = true;
        try {
            $this->go( [ $this->hat(), $this->hat( [ 'name' => 'B', 'sku' => 'B' ] ) ] );
            $this->fail( 'expected failure' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( '#100', $e->getMessage() );
            $this->assertStringContainsString( 'check', strtolower( $e->getMessage() ) );
        }
        $this->assertSame( [], $this->repo->rows );
    }

    public function testPriceFormattingDifferencesThatMeanTheSameNumberAreNotFailures(): void
    {
        $this->writer->afterCreate = static function ( array &$snap ): void {
            $snap['regular_price'] = '20.00'; // asked "20": the store may add decimals
        };
        $this->assertTrue( $this->go( [ $this->hat() ] )['changed'] );
    }

    // ---- categories: the store may add its default category ------------------------------------

    public function testADefaultCategoryAddedByTheStoreIsNotAFailureAndTheBatchItemUsesTheReadBack(): void
    {
        $this->writer->afterCreate = static function ( array &$snap ): void {
            $snap['category_ids'] = [ 15 ]; // WooCommerce's default category ("Uncategorized"), asked for by nobody
        };
        $r = $this->go( [ $this->hat( [ 'categories' => null ] ) ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [], $this->writer->deleted, 'nothing was rolled back' );
        $item     = array_values( $this->repo->rows )[0]['items'][0];
        $readBack = $this->writer->products[100];
        $this->assertSame( [ 15 ], $readBack['category_ids'] );
        $this->assertSame( Canon::hash( ProductCreatedKind::hashed( $readBack ) ), $item['after_hash'], 'the undo hash is built from the read-back' );
        $asked = $readBack;
        $asked['category_ids'] = [];
        $this->assertNotSame( Canon::hash( ProductCreatedKind::hashed( $asked ) ), $item['after_hash'], 'not from the input' );
    }

    public function testRequestedCategoriesMayComeBackWithExtraOnes(): void
    {
        $this->writer->afterCreate = static function ( array &$snap ): void {
            $snap['category_ids'] = [ 4, 15 ];
        };
        $this->assertTrue( $this->go( [ $this->hat() ] )['changed'] );
    }

    public function testARequestedCategoryMissingFromTheReadBackRollsEverythingBack(): void
    {
        $this->writer->afterCreate = static function ( array &$snap, int $call ): void {
            if ( 2 === $call ) {
                $snap['category_ids'] = [ 15 ]; // asked for hats (4), got only the default
            }
        };
        try {
            $this->go( [ $this->hat(), $this->hat( [ 'name' => 'B', 'sku' => 'B' ] ) ] );
            $this->fail( 'expected failure' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Product 2 "B"', $e->getMessage() );
            $this->assertStringContainsString( 'categories', $e->getMessage() );
        }
        $this->assertSame( [ 101, 100 ], $this->writer->deleted );
        $this->assertSame( [], $this->repo->rows );
    }
}
