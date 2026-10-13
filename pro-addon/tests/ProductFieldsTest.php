<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Products\ProductDiff;
use AiEditorDivi5\Pro\Products\ProductFields;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/** The shapes a change set is turned into (what the batch item stores and hashes) and the one equality the tool and its undo share. */
class ProductFieldsTest extends TestCase
{
    /** @return array<string,mixed> */
    private function state(): array
    {
        return [
            'name' => 'Hat', 'regular_price' => '20', 'sale_price' => '', 'manage_stock' => true, 'stock_quantity' => 5, 'stock_status' => 'instock', 'backorders' => 'no',
            'category_ids' => [ 3 ], 'tag_ids' => [], 'sale_from' => '', 'sale_to' => '',
            'variations' => [
                11 => [ 'regular_price' => '10', 'sale_price' => '', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'outofstock', 'backorders' => 'no' ],
                12 => [ 'regular_price' => '12', 'sale_price' => '9', 'manage_stock' => true, 'stock_quantity' => 2, 'stock_status' => 'instock', 'backorders' => 'no' ],
            ],
        ];
    }

    public function testAChangeSetSplitsIntoAShapeAndBack(): void
    {
        $changes = [ 'regular_price' => '25', 'variations' => [ 11 => [ 'regular_price' => '11' ] ] ];
        $shape   = ProductFields::asShape( $changes );
        $this->assertSame( [ 'fields' => [ 'regular_price' => '25' ], 'variations' => [ 11 => [ 'regular_price' => '11' ] ] ], $shape );
        $this->assertSame( $changes, ProductFields::asChanges( $shape ) );
        $this->assertSame( [ 'regular_price' => '25' ], ProductFields::asChanges( ProductFields::asShape( [ 'regular_price' => '25' ] ) ), 'no variations key when there are none' );
    }

    public function testTrackedAddsTheWholeStockGroupWhereverManageStockChanges(): void
    {
        $t = ProductFields::tracked( [ 'manage_stock' => false, 'name' => 'x', 'variations' => [ 11 => [ 'manage_stock' => true, 'stock_quantity' => 3 ], 12 => [ 'regular_price' => '1' ] ] ] );
        $this->assertEqualsCanonicalizing( [ 'manage_stock', 'name', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount' ], array_keys( $t['fields'] ) );
        $this->assertEqualsCanonicalizing( [ 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount' ], array_keys( $t['variations'][11] ) );
        $this->assertSame( [ 'regular_price' ], array_keys( $t['variations'][12] ), 'a row that does not touch manage_stock tracks only what it changes' );
        $this->assertSame( [ 'stock_quantity' ], array_keys( ProductFields::tracked( [ 'stock_quantity' => 9 ] )['fields'] ), 'a quantity alone is not a flip' );
    }

    public function testPickReadsTheNamedFieldsAndLeavesOutAMissingRow(): void
    {
        $picked = ProductFields::pick( $this->state(), [ 'fields' => [ 'regular_price' => true, 'nope' => true ], 'variations' => [ 12 => [ 'sale_price' => true ], 99 => [ 'sale_price' => true ] ] ] );
        $this->assertSame( [ 'regular_price' => '20', 'nope' => null ], $picked['fields'] );
        $this->assertSame( [ 12 => [ 'sale_price' => '9' ] ], $picked['variations'] );
    }

    public function testPickAcceptsAValueShapeAsItsKeys(): void
    {
        $values = [ 'fields' => [ 'name' => 'old' ], 'variations' => [ 11 => [ 'regular_price' => '1' ] ] ];
        $this->assertSame( [ 'fields' => [ 'name' => 'Hat' ], 'variations' => [ 11 => [ 'regular_price' => '10' ] ] ], ProductFields::pick( $this->state(), $values ) );
    }

    // ---- ProductDiff ----------------------------------------------------------------------------

    private function same( array $expected, array $actual ): void
    {
        $this->assertSame( [], ProductDiff::compare( $expected, $actual ) );
    }

    /** @return list<string> */
    private function diff( array $expected, array $actual ): array
    {
        return ProductDiff::compare( $expected, $actual );
    }

    public function testTextsAreEqualOnceACoreAmpersandRewriteIsUndone(): void
    {
        $this->same( [ 'fields' => [ 'name' => 'Salt & Pepper' ] ], [ 'fields' => [ 'name' => 'Salt &amp; Pepper' ] ] );
        $this->assertSame( [ 'description' ], $this->diff( [ 'fields' => [ 'description' => '<p>a</p>' ] ], [ 'fields' => [ 'description' => '<p>b</p>' ] ] ) );
    }

    public function testDecimalsAreComparedAsNumbersNotStrings(): void
    {
        $this->same( [ 'fields' => [ 'regular_price' => '20', 'weight' => '1.50' ] ], [ 'fields' => [ 'regular_price' => '20.00', 'weight' => '1.5' ] ] );
        $this->assertSame( [ 'regular_price' ], $this->diff( [ 'fields' => [ 'regular_price' => '20' ] ], [ 'fields' => [ 'regular_price' => '21' ] ] ) );
        $this->assertSame( [ 'sale_price' ], $this->diff( [ 'fields' => [ 'sale_price' => '' ] ], [ 'fields' => [ 'sale_price' => '5' ] ] ), 'empty is not zero' );
        $this->assertSame( [ 'sale_price' ], $this->diff( [ 'fields' => [ 'sale_price' => '5' ] ], [ 'fields' => [ 'sale_price' => null ] ] ) );
    }

    public function testDatesAndPlainScalarsAreExact(): void
    {
        $this->assertSame( [ 'sale_to', 'tax_status', 'image_id' ], $this->diff(
            [ 'fields' => [ 'sale_to' => '2026-12-31', 'tax_status' => 'none', 'image_id' => 5 ] ],
            [ 'fields' => [ 'sale_to' => '2026-12-30', 'tax_status' => 'taxable', 'image_id' => 6 ] ]
        ) );
        $this->assertSame( [ 'gallery_image_ids' ], $this->diff( [ 'fields' => [ 'gallery_image_ids' => [ 1, 2 ] ] ], [ 'fields' => [ 'gallery_image_ids' => [ 2, 1 ] ] ] ), 'gallery order matters' );
    }

    public function testCategoriesAnEmptyRequestAcceptsTheStoresDefaultButARealRequestMustMatch(): void
    {
        $this->same( [ 'fields' => [ 'category_ids' => [] ] ], [ 'fields' => [ 'category_ids' => [ 15 ] ] ] );
        $this->same( [ 'fields' => [ 'category_ids' => [ 4, 3 ] ] ], [ 'fields' => [ 'category_ids' => [ 3, 4 ] ] ] );
        $this->assertSame( [ 'category_ids' ], $this->diff( [ 'fields' => [ 'category_ids' => [ 3 ] ] ], [ 'fields' => [ 'category_ids' => [ 3, 15 ] ] ] ), 'an extra category is a difference when categories were asked for' );
        $this->assertSame( [ 'tag_ids' ], $this->diff( [ 'fields' => [ 'tag_ids' => [] ] ], [ 'fields' => [ 'tag_ids' => [ 7 ] ] ] ), 'tags have no default: an empty request must come back empty' );
    }

    public function testDerivedStockValuesAreNotCompared(): void
    {
        // managed: the status is WooCommerce's to work out
        $this->same( [ 'fields' => [ 'manage_stock' => true, 'stock_quantity' => 5, 'stock_status' => 'outofstock' ] ], [ 'fields' => [ 'manage_stock' => true, 'stock_quantity' => 5, 'stock_status' => 'instock' ] ] );
        // not managed: backorders are cleared by WooCommerce
        $this->same( [ 'fields' => [ 'manage_stock' => false, 'backorders' => 'yes' ] ], [ 'fields' => [ 'manage_stock' => false, 'backorders' => 'no' ] ] );
        // not managed: the status IS compared; managed: backorders ARE compared
        $this->assertSame( [ 'stock_status' ], $this->diff( [ 'fields' => [ 'manage_stock' => false, 'stock_status' => 'outofstock' ] ], [ 'fields' => [ 'manage_stock' => false, 'stock_status' => 'instock' ] ] ) );
        $this->assertSame( [ 'backorders' ], $this->diff( [ 'fields' => [ 'manage_stock' => true, 'backorders' => 'notify' ] ], [ 'fields' => [ 'manage_stock' => true, 'backorders' => 'no' ] ] ) );
        // a lone stock_status expectation is always compared, even when the actual state is managed (an undo must not claim a status it could not restore)
        $this->assertSame( [ 'stock_status' ], $this->diff( [ 'fields' => [ 'stock_status' => 'outofstock' ] ], [ 'fields' => [ 'stock_status' => 'instock', 'manage_stock' => true ] ] ) );
        // backorders still take the managed flag from the actual state when the expectation does not name it
        $this->same( [ 'fields' => [ 'backorders' => 'yes' ] ], [ 'fields' => [ 'backorders' => 'no', 'manage_stock' => false ] ] );
    }

    public function testVariationRowsAreComparedByIdAndNamedInTheAnswer(): void
    {
        $e = [ 'fields' => [], 'variations' => [ 11 => [ 'regular_price' => '11', 'sale_price' => '' ], 12 => [ 'regular_price' => '1' ] ] ];
        $this->same( $e, [ 'fields' => [], 'variations' => [ 11 => [ 'regular_price' => '11.00', 'sale_price' => '' ], 12 => [ 'regular_price' => '1' ] ] ] );
        $this->assertSame( [ 'variation 11 regular_price', 'variation 12' ], $this->diff( $e, [ 'fields' => [], 'variations' => [ 11 => [ 'regular_price' => '12', 'sale_price' => '' ] ] ] ) );
    }
}
