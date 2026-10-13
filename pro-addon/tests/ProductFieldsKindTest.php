<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\ProductFieldsKind;
use AiEditorDivi5\Pro\Products\ProductFields;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/FakeProductWriter.php';

/** What update_products changed on a product, and how an undo puts the previous values back (and how that undo is itself undone). */
class ProductFieldsKindTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private FakeProductWriter $writer;

    protected function setUp(): void
    {
        $this->repo   = new InMemoryBatchRepository();
        $this->log    = new BatchLog( $this->repo );
        $this->writer = new FakeProductWriter();
        $this->writer->products[50] = $this->simple();
        $this->writer->products[60] = $this->variable();
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_caps']  = [ 'edit_post' => true, 'assign_product_terms' => true ];
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'] );
    }

    /** @return array<string,mixed> */
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
    private function variable(): array
    {
        return $this->simple( [
            'type' => 'variable', 'name' => 'Tee', 'sku' => 'TEE', 'regular_price' => '', 'manage_stock' => false, 'stock_quantity' => null,
            'variations' => [
                61 => [ 'regular_price' => '10', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => true, 'stock_quantity' => 5, 'stock_status' => 'instock', 'backorders' => 'no' ],
                62 => [ 'regular_price' => '12', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock', 'backorders' => 'no' ],
            ],
        ] );
    }

    private function kind(): ProductFieldsKind
    {
        return new ProductFieldsKind( $this->writer );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ 'product_fields' => $this->kind() ], $this->log );
    }

    /**
     * Pretend update_products ran: apply $changes through the fake store and record the item exactly as the tool does.
     *
     * @param array<string,mixed> $changes
     * @return array<string,mixed> the item
     */
    private function ran( int $id, array $changes ): array
    {
        $tracked = ProductFields::tracked( $changes );
        $before  = ProductFields::pick( $this->writer->product( $id ), $tracked );
        $this->writer->apply( $id, $changes );
        $after = ProductFields::pick( $this->writer->product( $id ), $tracked );
        $this->writer->applyCalls = 0;
        $this->writer->applied    = [];

        return ProductFieldsKind::itemFor( $id, (string) $this->writer->products[ $id ]['name'], 'test', $before, $after );
    }

    private function record( array $item ): int
    {
        return (int) $this->log->record( 'update_products', 'Updated 1 product(s)', [ $item ] )['id'];
    }

    // ---- the item -------------------------------------------------------------------------------

    public function testTheItemRecordsThePreviousValuesAndAHashOfTheFieldsAsReadBack(): void
    {
        $item = $this->ran( 50, [ 'regular_price' => '25' ] );
        $this->assertSame( [ 'product_fields', '50', 'applied' ], [ $item['kind'], $item['ref'], $item['state'] ] );
        $this->assertSame( [ 'fields' => [ 'regular_price' => '20' ], 'variations' => [] ], $item['before'] );
        $this->assertSame( Canon::hash( [ 'fields' => [ 'regular_price' => '25' ], 'variations' => [] ] ), $item['after_hash'] );
        $this->assertSame( [ 'available' => true ], $item['undo'] );
        $this->assertStringContainsString( 'Blue Hat', $item['label'] );
        $this->assertStringContainsString( '#50', $item['label'] );
        $this->assertSame( $item['after_hash'], Canon::hash( $this->kind()->current( $item ) ) );
    }

    public function testCurrentIsNullForAProductThatIsGoneOrNotEditableAnyMore(): void
    {
        $item = $this->ran( 50, [ 'regular_price' => '25' ] );
        unset( $this->writer->products[50] );
        $this->assertNull( $this->kind()->current( $item ) );
        $this->writer->products[50] = $this->simple();
        $this->writer->types[50]    = 'grouped';
        $this->assertNull( $this->kind()->current( $item ), 'a product turned grouped is not one this tool edits' );
    }

    // ---- undo -----------------------------------------------------------------------------------

    public function testUndoPutsThePreviousValuesBack(): void
    {
        $id = $this->record( $this->ran( 50, [ 'regular_price' => '25', 'name' => 'Red Hat' ] ) );
        $r  = $this->undo()->undo( $id );
        $this->assertSame( 1, $r['restored'] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( 'restored', $r['items'][0]['result'] );
        $this->assertSame( [ '20', 'Blue Hat' ], [ $this->writer->products[50]['regular_price'], $this->writer->products[50]['name'] ] );
    }

    public function testAnUndoCanBeUndone(): void
    {
        $id   = $this->record( $this->ran( 50, [ 'regular_price' => '25', 'stock_quantity' => 3 ] ) );
        $undo = $this->undo()->undo( $id );
        $this->assertSame( '20', $this->writer->products[50]['regular_price'] );
        $again = $this->undo()->undo( (int) $undo['undo_batch']['id'] );
        $this->assertSame( 1, $again['restored'], 'the inverse item restores the values the undo replaced' );
        $this->assertSame( [ '25', 3 ], [ $this->writer->products[50]['regular_price'], $this->writer->products[50]['stock_quantity'] ] );
        // and once more, back to the original
        $third = $this->undo()->undo( (int) $again['undo_batch']['id'] );
        $this->assertSame( 1, $third['restored'] );
        $this->assertSame( [ '20', 12 ], [ $this->writer->products[50]['regular_price'], $this->writer->products[50]['stock_quantity'] ] );
    }

    public function testAFieldTheOwnerChangedSinceIsNeverOverwrittenUnlessForced(): void
    {
        $id = $this->record( $this->ran( 50, [ 'regular_price' => '25' ] ) );
        $this->writer->products[50]['regular_price'] = '26'; // the owner
        $r = $this->undo()->undo( $id );
        $this->assertSame( 0, $r['restored'] );
        $this->assertSame( 'changed_since', $r['items'][0]['result'] );
        $this->assertSame( '26', $this->writer->products[50]['regular_price'] );
        $this->assertFalse( $r['fully_undone'] );

        $forced = $this->undo()->undo( $id, true );
        $this->assertSame( 1, $forced['restored'] );
        $this->assertSame( '20', $this->writer->products[50]['regular_price'] );
    }

    public function testAFieldTheRunDidNotTouchDoesNotBlockTheUndo(): void
    {
        $id = $this->record( $this->ran( 50, [ 'regular_price' => '25' ] ) );
        $this->writer->products[50]['description'] = '<p>Edited by the owner</p>';
        $this->writer->products[50]['weight']      = '2';
        $r = $this->undo()->undo( $id );
        $this->assertSame( 1, $r['restored'] );
        $this->assertSame( [ '20', '<p>Edited by the owner</p>', '2' ], [ $this->writer->products[50]['regular_price'], $this->writer->products[50]['description'], $this->writer->products[50]['weight'] ] );
    }

    public function testVariationRowsAreComparedAndRestoredPerRow(): void
    {
        $item = $this->ran( 60, [ 'variations' => [ 61 => [ 'regular_price' => '11' ], 62 => [ 'sale_price' => '9' ] ] ] );
        $this->assertSame( [ 61 => [ 'regular_price' => '10' ], 62 => [ 'sale_price' => '' ] ], $item['before']['variations'] );
        $id = $this->record( json_decode( (string) json_encode( $item ), true ) ); // as stored: int keys become strings and back

        $this->writer->products[60]['variations'][62]['stock_status'] = 'outofstock'; // a field of row 62 the run did not touch
        $ok = $this->undo()->undo( $id, false, true );
        $this->assertSame( 1, $ok['would_restore'] );

        $this->writer->products[60]['variations'][61]['regular_price'] = '13'; // the owner edits a tracked field of row 61
        $r = $this->undo()->undo( $id );
        $this->assertSame( 'changed_since', $r['items'][0]['result'], 'one changed row keeps the whole product item back' );
        $this->assertSame( '9', $this->writer->products[60]['variations'][62]['sale_price'], 'a product item is restored whole or not at all: row 62 stays as it was' );

        $forced = $this->undo()->undo( $id, true );
        $this->assertSame( 1, $forced['restored'] );
        $this->assertSame( [ '10', '' ], [ $this->writer->products[60]['variations'][61]['regular_price'], $this->writer->products[60]['variations'][62]['sale_price'] ] );
    }

    public function testSwitchingManagedStockOffAndBackRestoresTheDerivedValuesToo(): void
    {
        $this->writer->products[50]['backorders'] = 'notify';
        $item = $this->ran( 50, [ 'manage_stock' => false, 'stock_quantity' => null, 'backorders' => 'no' ] );
        $this->assertEqualsCanonicalizing( [ 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount' ], array_keys( $item['before']['fields'] ), 'the whole stock group is recorded' );
        $this->assertSame( [ true, 12, 'instock', 'notify' ], [ $item['before']['fields']['manage_stock'], $item['before']['fields']['stock_quantity'], $item['before']['fields']['stock_status'], $item['before']['fields']['backorders'] ] );
        $r = $this->undo()->undo( $this->record( $item ) );
        $this->assertSame( 1, $r['restored'] );
        $p = $this->writer->products[50];
        $this->assertSame( [ true, 12, 'instock', 'notify' ], [ $p['manage_stock'], $p['stock_quantity'], $p['stock_status'], $p['backorders'] ] );
    }

    public function testTermsAreRestoredByIdAndAnEmptyCategoryListAcceptsTheStoresDefault(): void
    {
        $id = $this->record( $this->ran( 50, [ 'category_ids' => [ 4 ], 'tag_ids' => [ 7 ] ] ) );
        $this->assertSame( 1, $this->undo()->undo( $id )['restored'] );
        $this->assertSame( [ [ 3 ], [] ], [ $this->writer->products[50]['category_ids'], $this->writer->products[50]['tag_ids'] ] );
    }

    // ---- failures -------------------------------------------------------------------------------

    public function testAMissingProductOrVariationIsReportedAndNothingElseIsTouched(): void
    {
        $item = $this->ran( 60, [ 'variations' => [ 61 => [ 'regular_price' => '11' ] ] ] );
        unset( $this->writer->products[60]['variations'][61] );
        $r = $this->kind()->restore( $item );
        $this->assertSame( [ 'ok' => false, 'reason' => 'variation_missing' ], $r );

        $gone = $this->ran( 50, [ 'regular_price' => '25' ] );
        unset( $this->writer->products[50] );
        $this->assertSame( [ 'ok' => false, 'reason' => 'missing' ], $this->kind()->restore( $gone ) );
        $this->assertSame( 0, $this->writer->applyCalls );
    }

    public function testAFailedSaveIsReportedWithoutLeakingAndTheProductKeepsItsValues(): void
    {
        $item = $this->ran( 50, [ 'regular_price' => '25' ] );
        $this->writer->failApplyCalls = [ 1 ];
        $r = $this->kind()->restore( $item );
        $this->assertSame( [ 'ok' => false, 'reason' => 'save_failed' ], $r );
        $this->assertSame( '25', $this->writer->products[50]['regular_price'] );
    }

    public function testAPartlyWrittenFailedRestoreIsPutBack(): void
    {
        $item = $this->ran( 50, [ 'regular_price' => '25', 'name' => 'Red Hat' ] );
        $this->assertSame( [ '25', 'Red Hat' ], [ $this->writer->products[50]['regular_price'], $this->writer->products[50]['name'] ] );
        $this->writer->applyCalls       = 0;
        $this->writer->partialFailCalls = [ 1 ];
        $r = $this->kind()->restore( $item );
        $this->assertSame( [ 'ok' => false, 'reason' => 'save_failed' ], $r );
        $this->assertSame( 2, $this->writer->applyCalls, 'the restore attempt and the put-back' );
        $this->assertSame( [ '25', 'Red Hat' ], [ $this->writer->products[50]['regular_price'], $this->writer->products[50]['name'] ], 'the half-written previous values were put back by putBack()' );
    }

    public function testARestoreThatReadsBackDifferentlyIsPutBackAndReported(): void
    {
        $item = $this->ran( 50, [ 'regular_price' => '25', 'name' => 'Red Hat' ] );
        $this->writer->afterApply = function ( int $id, array &$stored, int $call ): void {
            if ( 1 === $call ) {
                $stored['regular_price'] = '99'; // a store that saved something else
            }
        };
        $r = $this->kind()->restore( $item );
        $this->assertSame( [ 'ok' => false, 'reason' => 'restore_differs' ], $r );
        $this->assertSame( [ '25', 'Red Hat' ], [ $this->writer->products[50]['regular_price'], $this->writer->products[50]['name'] ], 'the values it held before the restore are back' );
    }

    public function testARestoreOfALoneBackordersChangeIsVerifiedAgainstTheManagedState(): void
    {
        $item = $this->ran( 50, [ 'backorders' => 'notify' ] );
        $this->writer->afterApply = function ( int $id, array &$stored, int $call ): void {
            if ( 1 === $call ) {
                $stored['backorders'] = 'yes'; // the store kept something else
            }
        };
        $r = $this->kind()->restore( $item );
        $this->assertSame( [ 'ok' => false, 'reason' => 'restore_differs' ], $r );
        $this->assertSame( 'notify', $this->writer->products[50]['backorders'], 'the value it held before the restore is back' );
    }

    // ---- permissions ----------------------------------------------------------------------------

    public function testItNeedsEditPostAndTermsNeedTheTermPermission(): void
    {
        $price = $this->ran( 50, [ 'regular_price' => '25' ] );
        $terms = $this->ran( 50, [ 'category_ids' => [ 4 ] ] );
        $this->assertTrue( $this->kind()->allowed( $price ) );
        $this->assertTrue( $this->kind()->allowed( $terms ) );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true ];
        $this->assertTrue( $this->kind()->allowed( $price ) );
        $this->assertFalse( $this->kind()->allowed( $terms ) );
        $GLOBALS['__wp_caps'] = [];
        $this->assertFalse( $this->kind()->allowed( $price ) );
    }

    public function testAnUndoWithoutPermissionRestoresNothing(): void
    {
        $id = $this->record( $this->ran( 50, [ 'regular_price' => '25' ] ) );
        $GLOBALS['__wp_caps'] = [];
        $r = $this->undo()->undo( $id, true );
        $this->assertSame( 'forbidden', $r['items'][0]['result'], 'force never skips a permission check' );
        $this->assertSame( '25', $this->writer->products[50]['regular_price'] );
    }
}
