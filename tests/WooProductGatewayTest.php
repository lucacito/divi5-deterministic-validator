<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\WooProductGateway;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WooShims.php';
require_once __DIR__ . '/../wp-plugin/src/ProductReader.php';
require_once __DIR__ . '/../wp-plugin/src/ProductGateway.php';
require_once __DIR__ . '/../wp-plugin/src/WooProductGateway.php';

class WooProductGatewayTest extends TestCase
{
    private function product( int $id, array $over = [] ): \FakeWcProduct
    {
        return new \FakeWcProduct( $over + [
            'id' => $id, 'name' => "Product {$id}", 'type' => 'simple', 'status' => 'publish', 'sku' => "SKU{$id}",
            'price' => (string) ( $id * 10 ), 'regular_price' => (string) ( $id * 10 ), 'sale_price' => '', 'on_sale' => false,
            'stock_status' => 'instock', 'manage_stock' => false, 'stock_quantity' => null, 'category_ids' => [ 3 ], 'tag_ids' => [],
            'image_id' => 0, 'gallery_image_ids' => [], 'attributes' => [], 'children' => [], 'upsell_ids' => [], 'cross_sell_ids' => [],
            'weight' => '', 'shipping_class' => '', 'virtual' => false, 'downloadable' => false, 'catalog_visibility' => 'visible',
            'average_rating' => '0', 'review_count' => 0, 'tax_status' => 'taxable', 'tax_class' => '', 'sold_individually' => false,
            'backorders' => 'no', 'low_stock_amount' => null, 'description' => 'Desc', 'short_description' => 'Short', 'featured' => false,
            'post_password' => '', 'date_created' => null, 'date_modified' => null,
            // private getters the gateway must NEVER call:
            'downloads' => [ [ 'file' => 'https://shop.test/SECRET-DOWNLOAD.zip' ] ], 'purchase_note' => 'SECRET-PURCHASE-NOTE', 'meta_data' => [ 'SECRET-META' ],
        ] );
    }

    protected function setUp(): void
    {
        $GLOBALS['__wc_terms']       = [ 'product_cat' => [ 3 => [ 'name' => 'Clothing', 'slug' => 'clothing' ], 4 => [ 'name' => 'Music', 'slug' => 'music' ] ], 'product_type' => [ 1 => [ 'name' => 'simple', 'slug' => 'simple', 'count' => 3 ], 2 => [ 'name' => 'variable', 'slug' => 'variable', 'count' => 1 ] ], 'product_tag' => [] ];
        $GLOBALS['__wc_counts']      = [ 'publish' => 5, 'draft' => 1, 'trash' => 9, 'auto-draft' => 2 ];
        $GLOBALS['__wc_attachments'] = [ 90 => 'https://shop.test/a.jpg', 91 => 'https://shop.test/g1.jpg' ];
        $GLOBALS['__wc_products']    = [
            1 => $this->product( 1, [ 'name' => 'Alpha', 'price' => '10', 'on_sale' => true, 'sale_price' => '10', 'regular_price' => '15', 'manage_stock' => true, 'stock_quantity' => 4, 'image_id' => 90, 'gallery_image_ids' => [ 91 ] ] ),
            2 => $this->product( 2, [ 'name' => 'Beta', 'price' => '20', 'category_ids' => [ 4 ] ] ),
            3 => $this->product( 3, [ 'name' => 'Gamma', 'price' => '5', 'on_sale' => true, 'status' => 'draft', 'stock_status' => 'outofstock' ] ),
            4 => $this->product( 4, [ 'name' => 'Shirt', 'type' => 'variable', 'children' => [ 41, 42 ],
                'attributes' => [ new \FakeWcAttribute( [ 'name' => 'pa_color', 'taxonomy' => true, 'terms' => [ 'Red', 'Blue' ], 'variation' => true ] ), new \FakeWcAttribute( [ 'name' => 'Fit', 'options' => [ 'Slim', 'Wide' ] ] ) ] ] ),
            41 => $this->product( 41, [ 'name' => 'Shirt red', 'type' => 'variation', 'post_type' => 'product_variation', 'attributes' => [ 'pa_color' => 'red' ], 'image_id' => 91 ] ),
            42 => $this->product( 42, [ 'name' => 'Shirt blue', 'type' => 'variation', 'post_type' => 'product_variation', 'attributes' => [ 'pa_color' => 'blue' ] ] ),
            5 => $this->product( 5, [ 'name' => 'Link', 'type' => 'external', 'product_url' => 'https://other.test/p', 'button_text' => 'Buy there' ] ),
            6 => $this->product( 6, [ 'name' => 'Set', 'type' => 'grouped', 'children' => [ 1, 2 ] ] ),
            7 => $this->product( 7, [ 'name' => 'Locked', 'post_password' => 'pw', 'description' => 'SECRET-DESC' ] ),
        ];
    }

    public function testActiveInfoAndProductCheck(): void
    {
        $gw = new WooProductGateway();
        $this->assertTrue( $gw->isActive() );
        $this->assertSame( [ 'version' => '11.1.2', 'currency' => 'USD' ], $gw->info() );
        $this->assertTrue( $gw->isProduct( 1 ) );
        $this->assertFalse( $gw->isProduct( 41 ), 'a variation is not a product for get_product' );
        $this->assertFalse( $gw->isProduct( 999 ) );
    }

    public function testCountsComeFromWordPressAndLeaveTrashOut(): void
    {
        $gw = new WooProductGateway();
        $this->assertSame( [ 'publish' => 5, 'draft' => 1, 'pending' => 0, 'private' => 0, 'future' => 0 ], $gw->statusCounts() );
        $this->assertSame( [ 'simple' => 3, 'variable' => 1 ], $gw->publishedTypeCounts() );
    }

    public function testQueryFiltersOrdersAndPaginates(): void
    {
        $gw   = new WooProductGateway();
        $base = [ 'status' => 'any', 'type' => null, 'category' => null, 'stock_status' => null, 'on_sale' => null, 'search' => null, 'orderby' => 'title', 'order' => 'asc', 'page' => 1, 'per_page' => 3 ];
        $r    = $gw->query( $base );
        $this->assertSame( [ 1, 2, 3 ], $r['ids'], 'Alpha, Beta, Gamma' );
        $this->assertSame( 7, $r['total'], 'seven products; the two variations are not products' );
        $this->assertFalse( $r['truncated'] );
        $this->assertSame( [ 5, 7, 6 ], $gw->query( [ 'page' => 2 ] + $base )['ids'], 'Link, Locked, Set' );
        $this->assertSame( [ 4 ], $gw->query( [ 'page' => 3 ] + $base )['ids'], 'Shirt' );
        $this->assertSame( [ 2 ], $gw->query( [ 'category' => 'music' ] + $base )['ids'] );
        $this->assertSame( [ 3 ], $gw->query( [ 'stock_status' => 'outofstock' ] + $base )['ids'] );
        $this->assertSame( [ 3 ], $gw->query( [ 'status' => 'draft' ] + $base )['ids'] );
        $this->assertSame( [ 4 ], $gw->query( [ 'type' => 'variable' ] + $base )['ids'] );
        $this->assertSame( [ 1 ], $gw->query( [ 'search' => 'alp' ] + $base )['ids'] );
        $this->assertSame( [ 3, 1, 2 ], $gw->query( [ 'orderby' => 'price' ] + $base )['ids'] );
    }

    public function testOnSaleIsFilteredInPhpWithTheRightTotalAndPage(): void
    {
        $gw   = new WooProductGateway();
        $base = [ 'status' => 'any', 'type' => null, 'category' => null, 'stock_status' => null, 'on_sale' => true, 'search' => null, 'orderby' => 'title', 'order' => 'asc', 'page' => 1, 'per_page' => 1 ];
        $r    = $gw->query( $base );
        $this->assertSame( [ 1 ], $r['ids'] );
        $this->assertSame( 2, $r['total'], 'total is the count of ALL on-sale matches, not of the page' );
        $this->assertSame( [ 3 ], $gw->query( [ 'page' => 2 ] + $base )['ids'] );
        $this->assertSame( [], $gw->query( [ 'page' => 3 ] + $base )['ids'] );
        $off = $gw->query( [ 'on_sale' => false, 'per_page' => 50 ] + $base );
        $this->assertNotContains( 1, $off['ids'] );
        $this->assertSame( 5, $off['total'] );
    }

    public function testAScanOverTheCapSaysItWasCut(): void
    {
        $GLOBALS['__wc_products'] = [];
        for ( $i = 1; $i <= WooProductGateway::MAX_SCAN + 5; $i++ ) {
            $GLOBALS['__wc_products'][ $i ] = $this->product( $i, [ 'on_sale' => true, 'name' => sprintf( 'P%04d', $i ) ] );
        }
        $r = ( new WooProductGateway() )->query( [ 'status' => 'any', 'type' => null, 'category' => null, 'stock_status' => null, 'on_sale' => true, 'search' => null, 'orderby' => 'title', 'order' => 'asc', 'page' => 1, 'per_page' => 50 ] );
        $this->assertTrue( $r['truncated'] );
        $this->assertSame( WooProductGateway::MAX_SCAN, $r['total'] );
        $this->assertCount( 50, $r['ids'] );
    }

    public function testRowResolvesCategoriesAndKeepsStockHonest(): void
    {
        $row = ( new WooProductGateway() )->row( 1 );
        $this->assertSame( 'Alpha', $row['name'] );
        $this->assertSame( [ [ 'id' => 3, 'name' => 'Clothing', 'slug' => 'clothing' ] ], $row['categories'] );
        $this->assertSame( 4, $row['stock_quantity'] );
        $this->assertSame( 2, ( new WooProductGateway() )->row( 4 )['variations_count'] );
        $this->assertNull( ( new WooProductGateway() )->row( 999 ) );
    }

    public function testDetailForAVariableProductResolvesAttributesAndVariations(): void
    {
        $d = ( new WooProductGateway() )->detail( 4 );
        $this->assertSame( [ [ 'name' => 'Color', 'options' => [ 'Red', 'Blue' ], 'variation' => true, 'visible' => true, 'taxonomy' => true ], [ 'name' => 'Fit', 'options' => [ 'Slim', 'Wide' ], 'variation' => false, 'visible' => true, 'taxonomy' => false ] ], $d['attributes'] );
        $this->assertSame( 2, $d['variations_total'] );
        $this->assertSame( [ 'Color' => 'red' ], $d['variations'][0]['attributes'] );
        $this->assertSame( 'https://shop.test/g1.jpg', $d['variations'][0]['image'] );
        $this->assertNull( $d['variations'][1]['image'] );
    }

    public function testDetailForExternalGroupedAndImages(): void
    {
        $gw = new WooProductGateway();
        $this->assertSame( [ 'product_url' => 'https://other.test/p', 'button_text' => 'Buy there' ], $gw->detail( 5 )['external'] );
        $this->assertSame( [ 1, 2 ], $gw->detail( 6 )['children'] );
        $img = $gw->detail( 1 )['images'];
        $this->assertSame( [ 'featured' => 'https://shop.test/a.jpg', 'gallery' => [ 'https://shop.test/g1.jpg' ] ], $img );
    }

    public function testTheGatewayNeverReadsPrivateGetters(): void
    {
        $gw   = new WooProductGateway();
        $json = json_encode( [ $gw->detail( 1 ), $gw->detail( 4 ), $gw->row( 1 ) ] );
        foreach ( [ 'SECRET-DOWNLOAD', 'SECRET-PURCHASE-NOTE', 'SECRET-META' ] as $needle ) {
            $this->assertStringNotContainsString( $needle, $json );
        }
        $this->assertTrue( $gw->detail( 7 )['password_protected'] );
        $plain = $gw->detail( 1 );
        $this->assertArrayHasKey( 'password_protected', $plain, 'ProductReader fails open without this key, so detail() must always set it' );
        $this->assertFalse( $plain['password_protected'] );
    }
}
