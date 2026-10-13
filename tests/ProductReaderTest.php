<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ProductReader;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ProductReader.php';

class ProductReaderTest extends TestCase
{
    private function raw( array $over = [] ): array
    {
        return $over + [
            'id' => 12, 'name' => 'Logo T-Shirt', 'type' => 'simple', 'status' => 'publish', 'sku' => 'TS-1',
            'price' => '18', 'regular_price' => '20', 'sale_price' => '18', 'on_sale' => true,
            'stock_status' => 'instock', 'manage_stock' => true, 'stock_quantity' => 7,
            'categories' => [ [ 'id' => 3, 'name' => 'Clothing', 'slug' => 'clothing' ] ],
            'url' => 'https://shop.test/product/logo-t-shirt/',
        ];
    }

    private function detailRaw( array $over = [] ): array
    {
        return $this->raw( $over ) + [
            'description' => '<p>Long text</p>', 'short_description' => 'Short', 'password_protected' => false,
            'tax_status' => 'taxable', 'tax_class' => '', 'weight' => '0.4', 'dimensions' => [ 'length' => '1', 'width' => '2', 'height' => '3' ],
            'shipping_class' => '', 'sold_individually' => false, 'backorders' => 'no', 'low_stock_amount' => null,
            'virtual' => false, 'downloadable' => false, 'featured' => false, 'catalog_visibility' => 'visible',
            'attributes' => [], 'images' => [ 'featured' => 'https://shop.test/a.jpg', 'gallery' => [] ], 'tags' => [],
            'upsell_ids' => [], 'cross_sell_ids' => [], 'date_created' => '2019-01-16T13:01:54+00:00', 'date_modified' => null,
            'average_rating' => '4.50', 'review_count' => 3,
        ];
    }

    public function testARowHasTheContractedKeysAndKeepsPricesAsStrings(): void
    {
        $row = ProductReader::row( $this->raw() );
        $this->assertSame( [
            'id', 'name', 'type', 'status', 'sku', 'price', 'regular_price', 'sale_price', 'on_sale',
            'stock_status', 'manage_stock', 'stock_quantity', 'categories', 'url',
        ], array_keys( $row ) );
        $this->assertSame( '18', $row['price'] );
        $this->assertSame( 7, $row['stock_quantity'] );
        $this->assertSame( [ [ 'id' => 3, 'name' => 'Clothing', 'slug' => 'clothing' ] ], $row['categories'] );
    }

    public function testStockQuantityIsNullWhenStockIsNotManaged(): void
    {
        $row = ProductReader::row( $this->raw( [ 'manage_stock' => false, 'stock_quantity' => 5 ] ) );
        $this->assertNull( $row['stock_quantity'] );
    }

    public function testOnlyAVariableProductCarriesVariationsCount(): void
    {
        $this->assertArrayNotHasKey( 'variations_count', ProductReader::row( $this->raw() ) );
        $this->assertSame( 4, ProductReader::row( $this->raw( [ 'type' => 'variable', 'variations_count' => 4 ] ) )['variations_count'] );
    }

    public function testLongNamesAreCutWithAMarker(): void
    {
        $row = ProductReader::row( $this->raw( [ 'name' => str_repeat( 'a', 250 ) ] ) );
        $this->assertSame( ProductReader::MAX_NAME + 1, mb_strlen( $row['name'] ) );
        $this->assertStringEndsWith( '…', $row['name'] );
        $this->assertSame( 'short', ProductReader::cut( 'short', 200 ) );
    }

    public function testAtMostTwentyCategories(): void
    {
        $cats = [];
        for ( $i = 1; $i <= 30; $i++ ) {
            $cats[] = [ 'id' => $i, 'name' => "C{$i}", 'slug' => "c{$i}" ];
        }
        $this->assertCount( 20, ProductReader::row( $this->raw( [ 'categories' => $cats ] ) )['categories'] );
    }

    public function testDetailCutsDescriptionsAndAddsTheContractedFields(): void
    {
        $d = ProductReader::detail( $this->detailRaw( [ 'description' => str_repeat( 'x', 6000 ), 'short_description' => str_repeat( 'y', 1500 ) ] ) );
        $this->assertSame( 5001, mb_strlen( $d['description'] ) );
        $this->assertSame( 1001, mb_strlen( $d['short_description'] ) );
        foreach ( [ 'tax_status', 'weight', 'dimensions', 'images', 'tags', 'upsell_ids', 'cross_sell_ids', 'date_created', 'average_rating', 'review_count', 'password_protected', 'downloadable' ] as $key ) {
            $this->assertArrayHasKey( $key, $d );
        }
        $this->assertFalse( $d['password_protected'] );
        $this->assertFalse( $d['downloadable'] );
    }

    public function testAPasswordProtectedProductNeverLeaksItsText(): void
    {
        $d = ProductReader::detail( $this->detailRaw( [ 'password_protected' => true, 'description' => 'SECRET-DESC', 'short_description' => 'SECRET-SHORT' ] ) );
        $this->assertTrue( $d['password_protected'] );
        $this->assertTrue( $d['description_hidden'] );
        $this->assertNull( $d['description'] );
        $this->assertNull( $d['short_description'] );
        $this->assertStringNotContainsString( 'SECRET', json_encode( $d ) );
    }

    public function testNothingPrivateSurvivesEvenIfTheRawArrayCarriesIt(): void
    {
        $d = ProductReader::detail( $this->detailRaw( [
            'downloads' => [ [ 'file' => 'https://shop.test/uploads/SECRET-DOWNLOAD.zip' ] ],
            'purchase_note' => 'SECRET-PURCHASE-NOTE', 'meta_data' => [ '_cost' => 'SECRET-COST' ], 'cost' => 'SECRET-COST',
        ] ) );
        $json = json_encode( $d );
        foreach ( [ 'SECRET-DOWNLOAD', 'SECRET-PURCHASE-NOTE', 'SECRET-COST', 'downloads', 'purchase_note', 'meta_data' ] as $needle ) {
            $this->assertStringNotContainsString( $needle, $json );
        }
    }

    public function testAttributesImagesTagsAndIdsAreCapped(): void
    {
        $attrs = [];
        for ( $i = 0; $i < 60; $i++ ) {
            $attrs[] = [ 'name' => "A{$i}", 'options' => array_map( 'strval', range( 1, 70 ) ), 'variation' => false, 'visible' => true, 'taxonomy' => false ];
        }
        $d = ProductReader::detail( $this->detailRaw( [
            'attributes' => $attrs,
            'images' => [ 'featured' => 'https://shop.test/a.jpg', 'gallery' => array_map( fn ( $i ) => "https://shop.test/g{$i}.jpg", range( 1, 30 ) ) ],
            'tags' => array_map( fn ( $i ) => [ 'id' => $i, 'name' => "T{$i}", 'slug' => "t{$i}" ], range( 1, 30 ) ),
            'upsell_ids' => range( 1, 80 ), 'cross_sell_ids' => range( 1, 80 ),
        ] ) );
        $this->assertCount( 50, $d['attributes'] );
        $this->assertCount( 50, $d['attributes'][0]['options'] );
        $this->assertCount( 20, $d['images']['gallery'] );
        $this->assertCount( 20, $d['tags'] );
        $this->assertCount( 50, $d['upsell_ids'] );
        $this->assertCount( 50, $d['cross_sell_ids'] );
    }

    public function testVariationsAreCappedAndFlaggedWhenTruncated(): void
    {
        $vars = [];
        for ( $i = 1; $i <= 100; $i++ ) {
            $vars[] = [ 'id' => $i, 'sku' => '', 'status' => 'publish', 'price' => '5', 'regular_price' => '5', 'sale_price' => '', 'on_sale' => false, 'stock_status' => 'instock', 'manage_stock' => false, 'stock_quantity' => null, 'attributes' => [ 'Color' => 'red' ], 'image' => null ];
        }
        $d = ProductReader::detail( $this->detailRaw( [ 'type' => 'variable', 'variations' => $vars, 'variations_total' => 130, 'variations_count' => 130 ] ) );
        $this->assertCount( 100, $d['variations'] );
        $this->assertSame( 130, $d['variations_total'] );
        $this->assertTrue( $d['variations_truncated'] );
        $this->assertSame( [ 'Color' => 'red' ], $d['variations'][0]['attributes'] );
        $ok = ProductReader::detail( $this->detailRaw( [ 'type' => 'variable', 'variations' => array_slice( $vars, 0, 3 ), 'variations_total' => 3 ] ) );
        $this->assertFalse( $ok['variations_truncated'] );
    }

    public function testExternalAndGroupedExtrasAppearOnlyForTheirType(): void
    {
        $ext = ProductReader::detail( $this->detailRaw( [ 'type' => 'external', 'external' => [ 'product_url' => 'https://other.test/x', 'button_text' => 'Buy' ] ] ) );
        $this->assertSame( [ 'product_url' => 'https://other.test/x', 'button_text' => 'Buy' ], $ext['external'] );
        $this->assertArrayNotHasKey( 'children', $ext );
        $grp = ProductReader::detail( $this->detailRaw( [ 'type' => 'grouped', 'children' => array_merge( range( 1, 120 ) ) ] ) );
        $this->assertCount( 100, $grp['children'] );
        $this->assertArrayNotHasKey( 'external', $grp );
        $simple = ProductReader::detail( $this->detailRaw() );
        foreach ( [ 'external', 'children', 'variations' ] as $k ) {
            $this->assertArrayNotHasKey( $k, $simple );
        }
    }

    public function testTotalsAlwaysHaveTheSameShape(): void
    {
        $t = ProductReader::totals( [ 'publish' => '18', 'draft' => 2 ], [ 'simple' => '14', 'variable' => 2, 'bundle' => 1 ] );
        $this->assertSame( 20, $t['all'] );
        $this->assertSame( [ 'publish' => 18, 'draft' => 2, 'pending' => 0, 'private' => 0, 'future' => 0 ], $t['by_status'] );
        $this->assertSame( [ 'simple' => 14, 'variable' => 2, 'grouped' => 0, 'external' => 0, 'bundle' => 1 ], $t['published_by_type'] );
        $this->assertSame( 0, ProductReader::totals( [], [] )['all'] );
    }
}
