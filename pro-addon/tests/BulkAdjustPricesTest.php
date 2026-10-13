<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\ProductFieldsKind;
use AiEditorDivi5\Pro\Tools\BulkAdjustPrices;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';
require_once __DIR__ . '/FakeProductWriter.php';
require_once __DIR__ . '/WcDataExceptionShim.php';

class BulkAdjustPricesTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private FakeProductWriter $writer;
    private BulkAdjustPrices $tool;
    private int $now;

    protected function setUp(): void
    {
        $this->now    = (int) strtotime( '2026-10-13 12:00:00 UTC' );
        $this->repo   = new InMemoryBatchRepository();
        $this->log    = new BatchLog( $this->repo );
        $this->writer = new FakeProductWriter();
        $this->tool   = new BulkAdjustPrices( $this->log, $this->writer, fn (): int => $this->now );
        $w = $this->writer;
        $w->products[50] = $this->simple();
        $w->products[51] = $this->simple( [ 'name' => 'Mug', 'sku' => 'MUG', 'regular_price' => '10', 'sale_price' => '8' ] );
        $w->products[52] = $this->simple( [ 'name' => 'Draft Scarf', 'sku' => 'SCARF', 'regular_price' => '30', 'status' => 'draft' ] );
        $w->products[53] = $this->simple( [ 'name' => 'Free Gift', 'sku' => 'GIFT', 'regular_price' => '' ] );
        $w->products[54] = $this->simple( [ 'name' => 'Link Item', 'sku' => 'LINK', 'type' => 'external', 'regular_price' => '5' ] );
        $w->products[60] = $this->variable();
        $w->products[70] = $this->simple( [ 'name' => 'Bundle', 'sku' => 'BUNDLE' ] );
        $w->types[70]    = 'grouped';
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_posts']           = [];
        $GLOBALS['__wp_current_user_id'] = 7;
        $GLOBALS['__wp_caps']            = [ 'edit_products' => true, 'edit_post' => true ];
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'] );
    }

    /** @return array<string,mixed> a published simple product in the "shirts" category */
    private function simple( array $over = [] ): array
    {
        return $over + [
            'type' => 'simple', 'status' => 'publish', 'name' => 'Blue Hat', 'description' => '<p>Warm</p>', 'short_description' => 'Warm', 'sku' => 'HAT-1',
            'regular_price' => '20', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => true, 'stock_quantity' => 12, 'stock_status' => 'instock',
            'backorders' => 'no', 'category_ids' => [ 3 ], 'tag_ids' => [], 'image_id' => 0, 'gallery_image_ids' => [], 'weight' => '', 'length' => '', 'width' => '',
            'height' => '', 'tax_status' => 'taxable', 'variations' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function variable( int $rows = 2, int $id = 60 ): array
    {
        $variations = [
            61 => [ 'regular_price' => '10', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock', 'backorders' => 'no' ],
            62 => [ 'regular_price' => '12', 'sale_price' => '9', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock', 'backorders' => 'no' ],
        ];
        for ( $i = 3; $i <= $rows; $i++ ) {
            $variations[ $id * 1000 + $i ] = $variations[61];
        }

        return $this->simple( [ 'type' => 'variable', 'name' => 'Tee', 'sku' => 'TEE', 'regular_price' => '', 'manage_stock' => false, 'stock_quantity' => null, 'variations' => $variations ] );
    }

    /** @return array<string,mixed> */
    private function args( array $over = [] ): array
    {
        return $over + [ 'filter' => [ 'categories' => [ 'shirts' ] ], 'change' => [ 'mode' => 'percent', 'value' => '10' ] ];
    }

    /** @return array<string,mixed> */
    private function dry( array $over = [] ): array
    {
        return $this->tool->run( $this->args( $over ) + [ 'dry_run' => true ] );
    }

    /** Preview, then run with the token. @return array<string,mixed> */
    private function go( array $over = [] ): array
    {
        $p = $this->dry( $over );
        $this->assertArrayHasKey( 'token', $p, 'the preview made a token' );

        return $this->tool->run( $this->args( $over ) + [ 'token' => $p['token'] ] );
    }

    private function refused( array $args, string $message, bool $noWrites = true ): void
    {
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        if ( $noWrites ) {
            $this->assertSame( 0, $this->writer->applyCalls, 'nothing was written' );
            $this->assertSame( [], $this->repo->rows, 'no batch was recorded' );
        }
    }

    /** @return list<int> */
    private function ids( array $rows ): array
    {
        return array_map( 'intval', array_column( $rows, 'id' ) );
    }

    // ---- definition -----------------------------------------------------------------------------

    public function testTheDefinition(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'bulk_adjust_prices', $d['name'] );
        $this->assertSame( 'edit_posts', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'filter', 'change' ], $d['inputSchema']['required'] );
        foreach ( [ 'filter', 'change', 'round_to', 'dry_run', 'token' ] as $key ) {
            $this->assertArrayHasKey( $key, $d['inputSchema']['properties'], $key );
        }
        $this->assertSame( [ 'mode', 'value' ], $d['inputSchema']['properties']['change']['required'] );
        $this->assertSame( 'string', $d['inputSchema']['properties']['change']['properties']['value']['type'], 'the value is a string, never a number' );
    }

    // ---- the preview ----------------------------------------------------------------------------

    public function testADryRunListsEveryMatchedProductAndMakesOneToken(): void
    {
        $r = $this->dry();
        $this->assertFalse( $r['changed'] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertSame( [ 'matched' => 5, 'would_change' => 4, 'skipped' => 1, 'unchanged' => 0 ], $r['summary'], '50, 51, 53, 54 and 60 match; the draft 52 and the grouped 70 do not' );
        $this->assertSame( [ 50, 51, 54, 60 ], $this->ids( $r['products'] ) );
        $this->assertSame( [ 'regular_price' => [ 'before' => '20', 'after' => '22.00' ] ], $r['products'][0]['changes'] );
        $this->assertSame( 'external', $r['products'][2]['type'] );
        $this->assertSame( [ 61, 62 ], $this->ids( $r['products'][3]['variations'] ) );
        $this->assertSame( [ 'regular_price' => [ 'before' => '12', 'after' => '13.20' ] ], $r['products'][3]['variations'][1]['changes'] );
        $this->assertSame( [ 53 ], $this->ids( $r['skipped'] ) );
        $this->assertSame( 'no_regular_price', $r['skipped'][0]['reason'] );
        $this->assertSame( 'Free Gift', $r['skipped'][0]['name'] );
        $this->assertIsString( $r['token'] );
        $this->assertSame( 0, $this->writer->applyCalls );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( '20', $this->writer->products[50]['regular_price'] );
    }

    public function testNothingToAdjustMakesNoToken(): void
    {
        $r = $this->dry( [ 'filter' => [ 'ids' => [ 53 ] ] ] );
        $this->assertArrayNotHasKey( 'token', $r );
        $this->assertSame( 1, $r['summary']['skipped'] );
        $u = $this->dry( [ 'filter' => [ 'ids' => [ 50 ] ], 'change' => [ 'mode' => 'amount', 'value' => '0.001' ] ] );
        $this->assertSame( [ 50 ], $u['unchanged'], 'a change that rounds to the same price is no change' );
        $this->assertArrayNotHasKey( 'token', $u );
    }

    // ---- a real run -----------------------------------------------------------------------------

    public function testARealRunAppliesExactlyThePreviewedPricesAndRecordsOneBatch(): void
    {
        $r = $this->go();
        $this->assertTrue( $r['changed'] );
        $this->assertFalse( $r['dry_run'] );
        $this->assertSame( [ 50, 51, 54, 60 ], $this->ids( $r['updated'] ) );
        $this->assertSame( [ 53 ], $this->ids( $r['skipped'] ) );
        $this->assertSame( 0, $r['not_in_preview'] );
        $w = $this->writer->products;
        $this->assertSame( [ '22.00', '11.00', '5.50' ], [ $w[50]['regular_price'], $w[51]['regular_price'], $w[54]['regular_price'] ] );
        $this->assertSame( [ '11.00', '13.20' ], [ $w[60]['variations'][61]['regular_price'], $w[60]['variations'][62]['regular_price'] ] );
        $this->assertSame( '8', $w[51]['sale_price'], 'a sale price is never touched' );
        $this->assertSame( '30', $w[52]['regular_price'], 'the draft is untouched' );
        $this->assertCount( 1, $this->repo->rows );
        $batch = array_values( $this->repo->rows )[0];
        $this->assertSame( 'bulk_adjust_prices', $batch['tool'] );
        $this->assertSame( 'Adjusted prices of 4 product(s)', $batch['summary'] );
        $this->assertSame( [ 'product_fields', 'product_fields', 'product_fields', 'product_fields' ], array_column( $batch['items'], 'kind' ) );
        $this->assertSame( [ 'fields' => [ 'regular_price' => '20' ], 'variations' => [] ], $batch['items'][0]['before'] );
        $this->assertSame( [ 'fields' => [], 'variations' => [ 61 => [ 'regular_price' => '10' ], 62 => [ 'regular_price' => '12' ] ] ], $batch['items'][3]['before'] );
    }

    public function testOnlyTheRegularPriceIsWritten(): void
    {
        $this->go( [ 'filter' => [ 'ids' => [ 50, 60 ] ] ] );
        $this->assertSame( [ [ 50, [ 'regular_price' => '22.00' ] ], [ 60, [ 'variations' => [ 61 => [ 'regular_price' => '11.00' ], 62 => [ 'regular_price' => '13.20' ] ] ] ] ], $this->writer->applied );
    }

    public function testRoundToAndTheShopsDecimals(): void
    {
        $r = $this->go( [ 'filter' => [ 'ids' => [ 50 ] ], 'round_to' => '0.95' ] );
        $this->assertSame( '21.95', $this->writer->products[50]['regular_price'] );
        $this->assertSame( '21.95', $r['updated'][0]['changes']['regular_price']['after'] );

        $this->writer->decimals = 0;
        $this->go( [ 'filter' => [ 'ids' => [ 51 ] ] ] );
        $this->assertSame( '11', $this->writer->products[51]['regular_price'] );
    }

    // ---- skipped products -----------------------------------------------------------------------

    public function testEverySkipReasonIsReportedWithTheProductThatTriggeredIt(): void
    {
        $this->writer->products[55] = $this->simple( [ 'name' => 'Huge', 'sku' => 'HUGE', 'regular_price' => '9999999999' ] );
        $r = $this->dry( [ 'filter' => [ 'ids' => [ 50, 51, 53, 55, 60 ] ], 'change' => [ 'mode' => 'amount', 'value' => '-9' ] ] );
        $by = array_column( $r['skipped'], 'reason', 'id' );
        $this->assertSame( 'no_regular_price', $by[53] );
        $this->assertSame( 'not_above_sale', $by[51], '10 - 9 = 1 is below the sale 8' );
        $this->assertSame( 'variation_blocked', $by[60], 'row 61 would be 1 (fine) but row 62 would be 3, below its sale 9' );
        $this->assertSame( [ 'id' => 62, 'reason' => 'not_above_sale', 'detail' => 'the new regular price would not stay above the active sale price' ], $r['skipped'][2]['rows'][0] );
        $this->assertSame( [ 50, 55 ], $this->ids( $r['products'] ), 'the rest proceed' );

        $r = $this->dry( [ 'filter' => [ 'ids' => [ 50, 55 ] ], 'change' => [ 'mode' => 'amount', 'value' => '-20' ] ] );
        $this->assertSame( 'not_positive', array_column( $r['skipped'], 'reason', 'id' )[50] );
        $r = $this->dry( [ 'filter' => [ 'ids' => [ 55 ] ], 'change' => [ 'mode' => 'percent', 'value' => '10' ] ] );
        $this->assertSame( 'too_large', $r['skipped'][0]['reason'] );
        $r = $this->dry( [ 'filter' => [ 'ids' => [ 50 ] ], 'change' => [ 'mode' => 'percent', 'value' => '1' ], 'round_to' => '0.95' ] );
        $this->assertSame( 'rounding_reversed', $r['skipped'][0]['reason'] );
    }

    public function testAVariableProductIsAdjustedAsAWholeOrNotAtAll(): void
    {
        $r = $this->dry( [ 'filter' => [ 'ids' => [ 60 ] ], 'change' => [ 'mode' => 'amount', 'value' => '-5' ] ] );
        $this->assertSame( [], $r['products'], 'row 61 alone would have worked (10 - 5 = 5), but row 62 would end at 7, below its sale 9: nothing of this product is adjusted' );
        $this->assertSame( 'variation_blocked', $r['skipped'][0]['reason'] );
        $this->assertSame( [ 62 ], $this->ids( $r['skipped'][0]['rows'] ) );
        $this->assertArrayNotHasKey( 'token', $r );
    }

    public function testAVariableProductWithNoVariationsIsSkipped(): void
    {
        $this->writer->products[60]['variations'] = [];
        $r = $this->dry( [ 'filter' => [ 'ids' => [ 60 ] ] ] );
        $this->assertSame( 'no_variations', $r['skipped'][0]['reason'] );
    }

    // ---- unreadable stored prices (controller ruling: a rogue stored price never takes a preview down) -----

    public function testAStoredPriceThatIsNotAPlainDecimalSkipsTheProductAndNeverThrows(): void
    {
        $w = $this->writer;
        $w->products[81] = $this->simple( [ 'name' => 'Comma', 'sku' => 'C81', 'regular_price' => '1,5' ] );
        $w->products[82] = $this->simple( [ 'name' => 'Digits', 'sku' => 'D82', 'regular_price' => '99999999999999' ] );
        $w->products[83] = $this->simple( [ 'name' => 'Sale', 'sku' => 'S83', 'regular_price' => '20', 'sale_price' => 'abc' ] );
        $r  = $this->dry( [ 'filter' => [ 'ids' => [ 50, 81, 82, 83 ] ] ] );
        $by = array_column( $r['skipped'], 'reason', 'id' );
        $this->assertSame( [ 81 => 'unreadable_price', 82 => 'unreadable_price', 83 => 'unreadable_price' ], $by );
        $this->assertSame( 'a stored price could not be read as a plain decimal number', $r['skipped'][0]['detail'] );
        $this->assertSame( [ 50 ], $this->ids( $r['products'] ), 'the product around them is still adjusted' );
        $this->assertArrayHasKey( 'token', $r );
    }

    public function testAnUnreadableRowSkipsAVariableProductAsAWholeAndOtherRowsAreUntouched(): void
    {
        $this->writer->products[60]['variations'][62]['regular_price'] = '1e3';
        $r = $this->dry( [ 'filter' => [ 'ids' => [ 50, 60 ] ] ] );
        $this->assertSame( [ 50 ], $this->ids( $r['products'] ) );
        $this->assertSame( 'variation_blocked', $r['skipped'][0]['reason'] );
        $this->assertSame( [ [ 'id' => 62, 'reason' => 'unreadable_price', 'detail' => 'a stored price could not be read as a plain decimal number' ] ], $r['skipped'][0]['rows'] );
        $go = $this->tool->run( $this->args( [ 'filter' => [ 'ids' => [ 50, 60 ] ] ] ) + [ 'token' => $r['token'] ] );
        $this->assertSame( [ 50 ], $this->ids( $go['updated'] ) );
        $this->assertSame( [ '10', '1e3' ], [ $this->writer->products[60]['variations'][61]['regular_price'], $this->writer->products[60]['variations'][62]['regular_price'] ] );
    }

    // ---- the filter -----------------------------------------------------------------------------

    public function testTheFilterKeys(): void
    {
        $ids = fn ( array $filter ): array => $this->ids( $this->dry( [ 'filter' => $filter ] )['products'] );
        $this->assertSame( [ 50, 51, 54, 60 ], $ids( [ 'all' => true ] ), 'all published, adjustable products' );
        $this->assertSame( [ 52 ], $ids( [ 'all' => true, 'status' => [ 'draft' ] ] ), 'status asks for drafts' );
        $this->assertSame( [ 50, 51, 52, 54, 60 ], $ids( [ 'all' => true, 'status' => [ 'publish', 'draft' ] ] ) );
        $this->assertSame( [ 51 ], $ids( [ 'on_sale' => true ] ) );
        $this->assertSame( [ 50, 54, 60 ], $ids( [ 'on_sale' => false ] ), '53 is skipped, so it is not a product that would change' );
        $this->assertSame( [ 54 ], $ids( [ 'types' => [ 'external' ] ] ) );
        $this->assertSame( [ 60 ], $ids( [ 'types' => [ 'variable' ] ] ) );
        $this->assertSame( [ 51 ], $ids( [ 'search' => 'mug' ] ) );
        $this->assertSame( [ 50, 51, 54, 60 ], $ids( [ 'stock_status' => 'instock' ] ) );
        $this->assertSame( [], $ids( [ 'stock_status' => 'outofstock' ] ) );
        $this->assertSame( [ 50, 60 ], $ids( [ 'ids' => [ 50, 60, 52 ] ] ) );
        $this->assertSame( [ 50, 54, 60 ], $ids( [ 'categories' => [ 'shirts' ], 'exclude_ids' => [ 51 ] ] ) );
        $this->writer->products[50]['tag_ids'] = [ 7 ];
        $this->assertSame( [ 50 ], $ids( [ 'tags' => [ 'sale' ] ] ) );
        $this->assertSame( [ 50 ], $ids( [ 'tags' => [ 'sale' ], 'categories' => [ 'shirts' ] ] ), 'different keys combine with AND' );
        $this->assertSame( [], $ids( [ 'tags' => [ 'sale' ], 'categories' => [ 'hats' ] ] ) );
    }

    public function testUnknownSlugsAndBadArgumentsAreRefusedByName(): void
    {
        $this->refused( $this->args( [ 'filter' => [ 'categories' => [ 'nope' ] ] ] ) + [ 'dry_run' => true ], 'category "nope" does not exist' );
        $this->refused( $this->args( [ 'filter' => [ 'tags' => [ 'nope' ] ] ] ) + [ 'dry_run' => true ], 'tag "nope" does not exist' );
        $this->refused( [ 'filter' => [ 'categories' => [ 'shirts' ] ], 'change' => [ 'mode' => 'percent', 'value' => 10 ], 'dry_run' => true ], 'Nothing was changed. 1 problem(s): "change.value" must be a signed decimal string' );
        $this->refused( [ 'filter' => [ 'colour' => 'red' ], 'change' => [ 'mode' => 'percent', 'value' => '1' ], 'dry_run' => true ], '"filter.colour" is not a supported filter' );
        $this->refused( [ 'filter' => [ 'status' => [ 'draft' ] ], 'change' => [ 'mode' => 'percent', 'value' => '1' ], 'dry_run' => true ], 'must narrow the products' );
        $this->refused( $this->args() + [ 'dry_run' => 'yes' ], 'must be true or false' );
        $this->refused( $this->args() + [ 'token' => 5 ], 'must be a string' );
    }

    public function testMoreThanOneHundredMatchesIsRefusedNotTruncated(): void
    {
        for ( $i = 0; $i < 101; $i++ ) {
            $this->writer->products[ 1000 + $i ] = $this->simple( [ 'name' => "P{$i}", 'sku' => "S{$i}" ] );
        }
        $this->refused( $this->args() + [ 'dry_run' => true ], 'More than 100 products match this filter' );
        $this->writer->findTruncated = true;
        $this->refused( $this->args( [ 'filter' => [ 'ids' => [ 50 ] ] ] ) + [ 'dry_run' => true ], 'More than 100 products match this filter' );
    }

    public function testMoreThanFourHundredPricesIsRefused(): void
    {
        $this->writer->products[80] = $this->variable( 401, 80 );
        $this->refused( $this->args( [ 'filter' => [ 'ids' => [ 80 ] ] ] ) + [ 'dry_run' => true ], 'would change 401 prices; the limit is 400' );
        $this->writer->products[80] = $this->variable( 400, 80 );
        $this->assertSame( 1, $this->dry( [ 'filter' => [ 'ids' => [ 80 ] ] ] )['summary']['would_change'] );
    }

    // ---- permissions ----------------------------------------------------------------------------

    public function testWooCommerceMustBeActiveAndTheUserMayEditProducts(): void
    {
        $this->writer->active = false;
        $this->refused( $this->args() + [ 'dry_run' => true ], 'WooCommerce is not active' );
        $this->writer->active = true;
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true ];
        $this->refused( $this->args() + [ 'dry_run' => true ], 'edit_products permission' );
    }

    public function testProductsTheUserMayNotEditAreNotMatched(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_products' => true ];
        $r = $this->dry();
        $this->assertSame( 0, $r['summary']['matched'] );
        $this->assertSame( [], $r['products'] );
    }

    // ---- the token ------------------------------------------------------------------------------

    public function testARealRunNeedsAGenuineUnusedTokenForTheSameArgumentsByTheSameUserInTime(): void
    {
        $needs = 'A real run needs the token';
        $this->refused( $this->args(), $needs );
        $this->refused( $this->args() + [ 'token' => 'garbage' ], $needs );
        $token = $this->dry()['token'];
        $this->refused( $this->args( [ 'change' => [ 'mode' => 'percent', 'value' => '11' ] ] ) + [ 'token' => $token ], $needs );
        $GLOBALS['__wp_current_user_id'] = 8;
        $this->refused( $this->args() + [ 'token' => $token ], $needs );
        $GLOBALS['__wp_current_user_id'] = 7;
        $this->now += 1801;
        $this->refused( $this->args() + [ 'token' => $token ], $needs );
        $this->now -= 1801;
        $this->assertTrue( $this->tool->run( $this->args() + [ 'token' => $token ] )['changed'] );
        $this->refused( $this->args() + [ 'token' => $token ], $needs, false );
    }

    public function testTheSameRequestWrittenAnotherWayOpensTheToken(): void
    {
        $token = $this->dry( [ 'filter' => [ 'categories' => [ 'shirts' ], 'ids' => [ 60, 50 ] ], 'change' => [ 'mode' => 'percent', 'value' => '10.0' ], 'round_to' => '0.50' ] )['token'];
        $r = $this->tool->run( [ 'token' => $token, 'round_to' => '0.5', 'change' => [ 'value' => '10', 'mode' => 'percent' ], 'filter' => [ 'ids' => [ 50, 60 ], 'categories' => [ 'shirts' ] ] ] );
        $this->assertTrue( $r['changed'] );
    }

    public function testARefusedRunKeepsItsToken(): void
    {
        $token = $this->dry()['token'];
        $terms = $this->writer->terms['product_cat'];
        $this->writer->terms['product_cat'] = []; // the category was removed after the preview
        $this->refused( $this->args() + [ 'token' => $token ], 'category "shirts" does not exist' );
        $this->writer->terms['product_cat'] = $terms;
        $this->assertTrue( $this->tool->run( $this->args() + [ 'token' => $token ] )['changed'], 'the same token still works' );
    }

    // ---- between the preview and the run ----------------------------------------------------------

    public function testAProductChangedSinceThePreviewIsSkippedAndTheOthersProceed(): void
    {
        $token = $this->dry()['token'];
        $this->writer->products[50]['regular_price'] = '25';
        $r = $this->tool->run( $this->args() + [ 'token' => $token ] );
        $this->assertSame( [ 51, 54, 60 ], $this->ids( $r['updated'] ) );
        $this->assertContains( [ 'id' => 50, 'reason' => 'changed_since_preview' ], $r['skipped'] );
        $this->assertSame( '25', $this->writer->products[50]['regular_price'], 'never overwritten' );
    }

    public function testTheTokenBindsThePlannedPriceSoAChangeOfTheShopsDecimalsSkipsTheProduct(): void
    {
        $args  = $this->args( [ 'filter' => [ 'ids' => [ 50 ] ], 'change' => [ 'mode' => 'percent', 'value' => '7.5' ] ] );
        $p     = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertSame( '21.50', $p['products'][0]['changes']['regular_price']['after'] );
        $this->writer->decimals = 0; // the shop's setting (or a currency plugin's filter) changed after the preview
        $r = $this->tool->run( $args + [ 'token' => $p['token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ [ 'id' => 50, 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
        $this->assertSame( 0, $this->writer->applyCalls );
        $this->assertSame( '20', $this->writer->products[50]['regular_price'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testAProductTheUserMayNoLongerEditIsSkippedAsChangedAndNotWritten(): void
    {
        $token = $this->dry()['token'];
        $GLOBALS['__wp_caps']['edit_post:50'] = false; // per-id denial after the preview
        $r = $this->tool->run( $this->args() + [ 'token' => $token ] );
        $this->assertContains( [ 'id' => 50, 'reason' => 'changed_since_preview' ], $r['skipped'] );
        $this->assertSame( [ 51, 54, 60 ], $this->ids( $r['updated'] ) );
        $this->assertSame( '20', $this->writer->products[50]['regular_price'], 'not written' );
    }

    public function testAnyOtherChangeToTheProductAlsoCountsAndSoDoesATrashedProduct(): void
    {
        $token = $this->dry()['token'];
        $this->writer->products[50]['description'] = '<p>Edited</p>';
        $this->writer->products[60]['variations'][62]['sale_price'] = '7';
        $GLOBALS['__wp_posts'][54] = (object) [ 'ID' => 54, 'post_status' => 'trash' ];
        $r = $this->tool->run( $this->args() + [ 'token' => $token ] );
        $this->assertSame( [ 51 ], $this->ids( $r['updated'] ) );
        $this->assertEqualsCanonicalizing( [ 50, 54, 60 ], $this->ids( array_filter( $r['skipped'], static fn ( array $s ): bool => 'changed_since_preview' === $s['reason'] ) ) );
    }

    public function testAProductThatMatchesNowButWasNotPreviewedIsNeverTouched(): void
    {
        $token = $this->dry()['token'];
        $this->writer->products[56] = $this->simple( [ 'name' => 'New Hat', 'sku' => 'NEW', 'regular_price' => '40' ] );
        $r = $this->tool->run( $this->args() + [ 'token' => $token ] );
        $this->assertSame( 1, $r['not_in_preview'] );
        $this->assertSame( '40', $this->writer->products[56]['regular_price'] );
        $this->assertSame( [ 50, 51, 54, 60 ], $this->ids( $r['updated'] ) );
    }

    public function testARealRunWhoseEveryProductWasSkippedReportsItAndSpendsTheToken(): void
    {
        $token = $this->dry( [ 'filter' => [ 'ids' => [ 50 ] ] ] )['token'];
        $this->writer->products[50]['regular_price'] = '25';
        $r = $this->tool->run( $this->args( [ 'filter' => [ 'ids' => [ 50 ] ] ] ) + [ 'token' => $token ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ [ 'id' => 50, 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
        $this->assertSame( 0, $this->writer->applyCalls );
        $this->refused( $this->args( [ 'filter' => [ 'ids' => [ 50 ] ] ] ) + [ 'token' => $token ], 'A real run needs the token', false );
    }

    // ---- failure paths --------------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function stored(): array
    {
        return $this->writer->products;
    }

    public function testAFailedSaveRestoresEveryProductThisRunChangedAndRecordsNoBatch(): void
    {
        $before = $this->stored();
        $p      = $this->dry();
        $this->writer->failApplyCalls = [ 3 ];
        try {
            $this->tool->run( $this->args() + [ 'token' => $p['token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'WooCommerce could not save it.', $e->getMessage() );
            $this->assertStringContainsString( 'nothing was changed', $e->getMessage() );
            $this->assertStringNotContainsString( '/var/www', $e->getMessage() );
        }
        $this->assertEquals( $before, $this->stored() );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testASaveThatReadsBackDifferentlyIsRolledBack(): void
    {
        $before = $this->stored();
        $p      = $this->dry();
        $this->writer->afterApply = function ( int $id, array &$stored, int $call ): void {
            if ( 2 === $call ) {
                $stored['regular_price'] = '1.00';
            }
        };
        try {
            $this->tool->run( $this->args() + [ 'token' => $p['token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'it was saved differently than asked (regular_price)', $e->getMessage() );
        }
        $this->assertEquals( $before, $this->stored() );
        $this->assertSame( [], $this->repo->rows );
    }

    // ---- undo -----------------------------------------------------------------------------------

    public function testOneUndoPutsEveryPriceBackAndAPriceEditedSinceIsSkipped(): void
    {
        $before = $this->stored();
        $r      = $this->go();
        $undo   = new BatchUndo( [ 'product_fields' => new ProductFieldsKind( $this->writer ) ], $this->log );
        $this->writer->products[54]['regular_price'] = '6';
        $u = $undo->undo( (int) $r['batch']['id'] );
        $this->assertSame( 3, $u['restored'] );
        $this->assertSame( 'changed_since', $u['items'][2]['result'] );
        $this->assertSame( '20', $this->writer->products[50]['regular_price'] );
        $this->assertSame( '10', $this->writer->products[60]['variations'][61]['regular_price'] );
        $this->assertSame( '6', $this->writer->products[54]['regular_price'] );
        $this->writer->products[54]['regular_price'] = $before[54]['regular_price'];
    }
}
