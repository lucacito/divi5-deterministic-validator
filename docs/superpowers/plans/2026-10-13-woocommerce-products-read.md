# Read WooCommerce products (`list_products`, `get_product`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the free plugin two read-only MCP + REST tools that list, count and read WooCommerce products, plus a `count` per content type in `get_site_structure`.

**Architecture:** A pure formatter (`ProductReader`) and a pure argument parser (`ProductQuery`) sit behind an orchestrating `ProductService` that talks to WooCommerce only through a `ProductGateway` interface (real implementation `WooProductGateway`, the only class that calls WooCommerce). `McpHandler` and `RestController` are thin adapters over `ProductService`, exactly like `get_custom_fields`.

**Tech Stack:** PHP 8.1, PHPUnit (`make test`), WooCommerce's own public read functions, the plugin's light WordPress shims in `tests/`.

**Spec:** `docs/superpowers/specs/2026-10-13-woocommerce-products-read-design.md`

## Global Constraints

- Free plugin, namespace `AiEditorDivi5\WP`, files in `wp-plugin/src/`, each starting `<?php`, `declare(strict_types=1);`, the `ABSPATH` guard, and WordPress-style spacing inside parentheses (as `CustomFieldReader.php`).
- READ-ONLY. No WooCommerce write call anywhere in `wp-plugin/` (Task 6 adds a test that enforces it). No licence gate, no remote call, no new host, no Pro mention.
- Both tools are MCP + REST only. `OpenApiSpec.php` is NOT changed; a test pins that the tools are absent from the ChatGPT spec (30-operation importer budget is used up).
- A wrong argument type is rejected 400 / -32602, never cast. REST query strings are the one exception: digit strings for integers and `true`/`false` for the boolean are accepted on REST only.
- `per_page` 1 to 50 (default 25); above 50 is refused, not clamped.
- A product the user may not edit, a missing id, a variation id and a non-product id all answer the same NOT_FOUND (404 / -32602, message `Product {id} not found.`).
- Never returned: downloadable file names or URLs, the purchase note, customer or order data, product meta, any cost field, and the text of a password-protected product.
- `list_products` requires `edit_products` AND `edit_others_products` (plus the existing `PageAccess::CAP` = `edit_pages`); each row is also re-checked with `edit_post`.
- WooCommerce inactive is a normal success: `woocommerce: { active: false }`, no products, one message. A `Throwable` from WooCommerce becomes one 500 / -32603 `WooCommerce could not be read.`.
- Free plugin version becomes **4.9.0** (header, `AI_EDITOR_DIVI5_VERSION`, readme `Stable tag`, changelog, `WpOrgNamingTest` pin). The free plugin goes from 18 to 20 tools.
- Live findings (WooCommerce 11.1.2, dev site, 2026-10-13) the code must respect: `wc_get_products` has NO real `on_sale` argument (it is ignored) so the filter is done in PHP with a 500-product scan cap; taxonomy attribute options come back as term ids and must be resolved to names; `wc_get_products(['paginate' => true])` returns an object with `products`, `total`, `max_num_pages`.

## Review Focus

- WooCommerce inactive (or its functions missing): a normal answer, not an error (Task 3 `ProductServiceTest`, Task 4 handlers).
- A password-protected product must not leak its description or short description; a downloadable product must not leak its file URL; the purchase note must not appear (Task 1 `ProductReaderTest`, Task 5 live gate).
- A variation id (or a post of another type) passed to `get_product` answers exactly like a missing product (Task 3).
- `on_sale` filtering with paging: the total and the page slice must agree, and a scan over 500 products must say it was cut (Task 2 gateway test, Task 3 service note).
- Out-of-range input: `per_page=0`, `per_page=51`, `page=abc`, `on_sale=yes`, `status=trash` are all 400, never clamped; a `page` beyond the last page is a normal empty list with the totals (Task 2 `ProductQueryTest`, Task 4 lockstep).
- A user without `edit_others_products` (a plain editor) gets a clear 403 / -32602 naming the permission, and `get_product` for a product they cannot edit is NOT_FOUND (Task 3, Task 4).

---

## File Structure

- Create `wp-plugin/src/ProductReader.php`: pure shaping, caps and privacy rules (one responsibility: raw array in, response array out).
- Create `wp-plugin/src/ProductQuery.php`: pure argument parsing and validation for `list_products`.
- Create `wp-plugin/src/ProductGateway.php`: the interface (what `ProductService` needs from WooCommerce).
- Create `wp-plugin/src/WooProductGateway.php`: the only class that calls WooCommerce.
- Create `wp-plugin/src/ProductService.php`: orchestration, capability checks, error kinds; returns `['ok' => bool, 'kind' => ?string, 'message' => ?string, 'data' => ?array]`.
- Modify `wp-plugin/src/autoload.php`, `McpHandler.php`, `RestController.php`, `SiteStructure.php`, `StyleGuide.php`, `AdminPage.php`.
- Modify `wp-plugin/readme.txt`, `wp-plugin/jhmg-ai-editor-for-divi-5.php`.
- Create tests: `tests/ProductReaderTest.php`, `tests/ProductQueryTest.php`, `tests/WooShims.php`, `tests/WooProductGatewayTest.php`, `tests/ProductServiceTest.php`, `tests/ProductsLockstepTest.php`.
- Modify tests: `tests/SiteStructureTest.php`, `tests/SiteStructureLockstepTest.php`, `tests/WpOrgComplianceTest.php`, `tests/WpOrgNamingTest.php`, `tests/StyleGuideTest.php`.
- Create `scripts/verify-products.php`, `scripts/verify-products-inactive.php`; modify `Makefile`, `CLAUDE.md`.

---

### Task 1: `ProductReader` (pure shaping, caps, privacy)

**Files:**
- Create: `wp-plugin/src/ProductReader.php`
- Test: `tests/ProductReaderTest.php`

**Interfaces:**
- Consumes: nothing (pure PHP).
- Produces: `ProductReader::row( array $raw ): array`, `ProductReader::detail( array $raw ): array`, `ProductReader::totals( array $byStatus, array $publishedByType ): array`, `ProductReader::cut( string $text, int $max ): string`, and the `MAX_*` constants. The `$raw` array shape (keys) is the contract `WooProductGateway` (Task 2) fills: `id, name, type, status, sku, price, regular_price, sale_price, on_sale, stock_status, manage_stock, stock_quantity, categories[{id,name,slug}], url, variations_count` for a row; a detail adds `description, short_description, password_protected, tax_status, tax_class, weight, dimensions{length,width,height}, shipping_class, sold_individually, backorders, low_stock_amount, virtual, downloadable, featured, catalog_visibility, attributes[{name,options,variation,visible,taxonomy}], images{featured,gallery[]}, tags[{id,name,slug}], upsell_ids, cross_sell_ids, date_created, date_modified, average_rating, review_count, external{product_url,button_text}, children[], variations[{id,sku,status,price,regular_price,sale_price,on_sale,stock_status,manage_stock,stock_quantity,attributes{name=>value},image}], variations_total`.

- [ ] **Step 1: Write the failing test**

Create `tests/ProductReaderTest.php`:

```php
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
```

- [ ] **Step 2: Run it to verify it fails**

Run: `make test 2>&1 | grep -A6 "ProductReaderTest" | head -20`
Expected: FAIL / error `Class "AiEditorDivi5\WP\ProductReader" not found`.

- [ ] **Step 3: Write the implementation**

Create `wp-plugin/src/ProductReader.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shapes ONE WooCommerce product for the AI: pure, no WooCommerce and no WordPress calls, so the caps and the privacy rules
 * are unit-testable. It receives a plain array (filled by WooProductGateway) and returns only the contracted keys: anything
 * else in the array (downloads, purchase note, meta, cost) is dropped by construction because it is never copied.
 */
final class ProductReader
{
    public const MAX_NAME        = 200;
    public const MAX_DESCRIPTION = 5000;
    public const MAX_SHORT       = 1000;
    public const MAX_CATEGORIES  = 20;
    public const MAX_TAGS        = 20;
    public const MAX_ATTRIBUTES  = 50;
    public const MAX_OPTIONS     = 50;
    public const MAX_IMAGES      = 20;
    public const MAX_IDS         = 50;
    public const MAX_CHILDREN    = 100;
    public const MAX_VARIATIONS  = 100;

    private const LIST_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];
    private const CORE_TYPES    = [ 'simple', 'variable', 'grouped', 'external' ];

    /** Cuts a text to $max characters and marks the cut with an ellipsis (so a cut text is $max + 1 long). */
    public static function cut( string $text, int $max ): string
    {
        return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . '…' : $text;
    }

    /**
     * One list row.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function row( array $raw ): array
    {
        $type   = self::str( $raw['type'] ?? '' );
        $manage = (bool) ( $raw['manage_stock'] ?? false );
        $qty    = $raw['stock_quantity'] ?? null;
        $row    = [
            'id'             => (int) ( $raw['id'] ?? 0 ),
            'name'           => self::cut( self::str( $raw['name'] ?? '' ), self::MAX_NAME ),
            'type'           => $type,
            'status'         => self::str( $raw['status'] ?? '' ),
            'sku'            => self::str( $raw['sku'] ?? '' ),
            'price'          => self::str( $raw['price'] ?? '' ),
            'regular_price'  => self::str( $raw['regular_price'] ?? '' ),
            'sale_price'     => self::str( $raw['sale_price'] ?? '' ),
            'on_sale'        => (bool) ( $raw['on_sale'] ?? false ),
            'stock_status'   => self::str( $raw['stock_status'] ?? '' ),
            'manage_stock'   => $manage,
            'stock_quantity' => $manage && null !== $qty ? (int) $qty : null,
            'categories'     => self::terms( $raw['categories'] ?? [], self::MAX_CATEGORIES ),
            'url'            => self::str( $raw['url'] ?? '' ),
        ];
        if ( 'variable' === $type ) {
            $row['variations_count'] = (int) ( $raw['variations_count'] ?? 0 );
        }

        return $row;
    }

    /**
     * Everything the AI may know about one product.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function detail( array $raw ): array
    {
        $out       = self::row( $raw );
        $protected = (bool) ( $raw['password_protected'] ?? false );
        $dim       = is_array( $raw['dimensions'] ?? null ) ? $raw['dimensions'] : [];
        $images    = is_array( $raw['images'] ?? null ) ? $raw['images'] : [];
        $low       = $raw['low_stock_amount'] ?? null;

        $out['password_protected'] = $protected;
        if ( $protected ) {
            $out['description_hidden'] = true;
            $out['description']        = null;
            $out['short_description']  = null;
        } else {
            $out['description']       = self::cut( self::str( $raw['description'] ?? '' ), self::MAX_DESCRIPTION );
            $out['short_description'] = self::cut( self::str( $raw['short_description'] ?? '' ), self::MAX_SHORT );
        }
        $out['tax_status']         = self::str( $raw['tax_status'] ?? '' );
        $out['tax_class']          = self::str( $raw['tax_class'] ?? '' );
        $out['weight']             = self::str( $raw['weight'] ?? '' );
        $out['dimensions']         = [
            'length' => self::str( $dim['length'] ?? '' ),
            'width'  => self::str( $dim['width'] ?? '' ),
            'height' => self::str( $dim['height'] ?? '' ),
        ];
        $out['shipping_class']     = self::str( $raw['shipping_class'] ?? '' );
        $out['sold_individually']  = (bool) ( $raw['sold_individually'] ?? false );
        $out['backorders']         = self::str( $raw['backorders'] ?? '' );
        $out['low_stock_amount']   = null === $low || '' === $low ? null : (int) $low;
        $out['virtual']            = (bool) ( $raw['virtual'] ?? false );
        $out['downloadable']       = (bool) ( $raw['downloadable'] ?? false ); // a flag only: never the files
        $out['featured']           = (bool) ( $raw['featured'] ?? false );
        $out['catalog_visibility'] = self::str( $raw['catalog_visibility'] ?? '' );
        $out['attributes']         = self::attributes( $raw['attributes'] ?? [] );
        $out['images']             = [
            'featured' => isset( $images['featured'] ) && is_string( $images['featured'] ) && '' !== $images['featured'] ? $images['featured'] : null,
            'gallery'  => array_slice( self::strings( $images['gallery'] ?? [] ), 0, self::MAX_IMAGES ),
        ];
        $out['tags']               = self::terms( $raw['tags'] ?? [], self::MAX_TAGS );
        $out['upsell_ids']         = array_slice( self::ints( $raw['upsell_ids'] ?? [] ), 0, self::MAX_IDS );
        $out['cross_sell_ids']     = array_slice( self::ints( $raw['cross_sell_ids'] ?? [] ), 0, self::MAX_IDS );
        $out['date_created']       = isset( $raw['date_created'] ) && is_string( $raw['date_created'] ) ? $raw['date_created'] : null;
        $out['date_modified']      = isset( $raw['date_modified'] ) && is_string( $raw['date_modified'] ) ? $raw['date_modified'] : null;
        $out['average_rating']     = self::str( $raw['average_rating'] ?? '' );
        $out['review_count']       = (int) ( $raw['review_count'] ?? 0 );

        $type = $out['type'];
        if ( 'external' === $type && is_array( $raw['external'] ?? null ) ) {
            $out['external'] = [
                'product_url' => self::str( $raw['external']['product_url'] ?? '' ),
                'button_text' => self::cut( self::str( $raw['external']['button_text'] ?? '' ), self::MAX_NAME ),
            ];
        }
        if ( 'grouped' === $type ) {
            $out['children'] = array_slice( self::ints( $raw['children'] ?? [] ), 0, self::MAX_CHILDREN );
        }
        if ( 'variable' === $type ) {
            $vars                         = is_array( $raw['variations'] ?? null ) ? array_slice( array_values( $raw['variations'] ), 0, self::MAX_VARIATIONS ) : [];
            $total                        = (int) ( $raw['variations_total'] ?? count( $vars ) );
            $out['variations']            = array_map( [ self::class, 'variation' ], $vars );
            $out['variations_total']      = $total;
            $out['variations_truncated']  = $total > count( $out['variations'] );
        }

        return $out;
    }

    /**
     * The totals block: always the same keys, so the AI can rely on them.
     *
     * @param array<string,mixed> $byStatus          post status => count (wp_count_posts)
     * @param array<string,mixed> $publishedByType   product type slug => published count
     * @return array{all:int, by_status:array<string,int>, published_by_type:array<string,int>}
     */
    public static function totals( array $byStatus, array $publishedByType ): array
    {
        $status = [];
        $all    = 0;
        foreach ( self::LIST_STATUSES as $s ) {
            $n          = max( 0, (int) ( $byStatus[ $s ] ?? 0 ) );
            $status[ $s ] = $n;
            $all       += $n;
        }
        $types = [];
        foreach ( self::CORE_TYPES as $t ) {
            $types[ $t ] = max( 0, (int) ( $publishedByType[ $t ] ?? 0 ) );
        }
        foreach ( $publishedByType as $t => $n ) { // product types added by other plugins
            if ( is_string( $t ) && ! isset( $types[ $t ] ) && 1 === preg_match( '/^[a-z0-9_-]{1,40}$/', $t ) ) {
                $types[ $t ] = max( 0, (int) $n );
            }
        }

        return [ 'all' => $all, 'by_status' => $status, 'published_by_type' => $types ];
    }

    /** @param array<string,mixed> $v @return array<string,mixed> */
    private static function variation( array $v ): array
    {
        $manage = (bool) ( $v['manage_stock'] ?? false );
        $qty    = $v['stock_quantity'] ?? null;
        $attrs  = [];
        foreach ( is_array( $v['attributes'] ?? null ) ? $v['attributes'] : [] as $name => $value ) {
            if ( count( $attrs ) >= self::MAX_ATTRIBUTES ) {
                break;
            }
            $attrs[ self::cut( self::str( $name ), self::MAX_NAME ) ] = self::cut( self::str( $value ), self::MAX_NAME );
        }

        return [
            'id'             => (int) ( $v['id'] ?? 0 ),
            'sku'            => self::str( $v['sku'] ?? '' ),
            'status'         => self::str( $v['status'] ?? '' ),
            'price'          => self::str( $v['price'] ?? '' ),
            'regular_price'  => self::str( $v['regular_price'] ?? '' ),
            'sale_price'     => self::str( $v['sale_price'] ?? '' ),
            'on_sale'        => (bool) ( $v['on_sale'] ?? false ),
            'stock_status'   => self::str( $v['stock_status'] ?? '' ),
            'manage_stock'   => $manage,
            'stock_quantity' => $manage && null !== $qty ? (int) $qty : null,
            'attributes'     => $attrs,
            'image'          => isset( $v['image'] ) && is_string( $v['image'] ) && '' !== $v['image'] ? $v['image'] : null,
        ];
    }

    /** @param mixed $list @return list<array{name:string,options:list<string>,variation:bool,visible:bool,taxonomy:bool}> */
    private static function attributes( mixed $list ): array
    {
        $out = [];
        foreach ( is_array( $list ) ? $list : [] as $a ) {
            if ( ! is_array( $a ) || count( $out ) >= self::MAX_ATTRIBUTES ) {
                continue;
            }
            $out[] = [
                'name'      => self::cut( self::str( $a['name'] ?? '' ), self::MAX_NAME ),
                'options'   => array_map( fn ( string $o ): string => self::cut( $o, self::MAX_NAME ), array_slice( self::strings( $a['options'] ?? [] ), 0, self::MAX_OPTIONS ) ),
                'variation' => (bool) ( $a['variation'] ?? false ),
                'visible'   => (bool) ( $a['visible'] ?? false ),
                'taxonomy'  => (bool) ( $a['taxonomy'] ?? false ),
            ];
        }

        return $out;
    }

    /** @param mixed $list @return list<array{id:int,name:string,slug:string}> */
    private static function terms( mixed $list, int $max ): array
    {
        $out = [];
        foreach ( is_array( $list ) ? $list : [] as $t ) {
            if ( ! is_array( $t ) || count( $out ) >= $max ) {
                continue;
            }
            $out[] = [
                'id'   => (int) ( $t['id'] ?? 0 ),
                'name' => self::cut( self::str( $t['name'] ?? '' ), self::MAX_NAME ),
                'slug' => self::cut( self::str( $t['slug'] ?? '' ), self::MAX_NAME ),
            ];
        }

        return $out;
    }

    private static function str( mixed $v ): string
    {
        return is_scalar( $v ) ? (string) $v : '';
    }

    /** @param mixed $list @return list<string> */
    private static function strings( mixed $list ): array
    {
        $out = [];
        foreach ( is_array( $list ) ? $list : [] as $v ) {
            if ( is_scalar( $v ) && '' !== (string) $v ) {
                $out[] = (string) $v;
            }
        }

        return $out;
    }

    /** @param mixed $list @return list<int> */
    private static function ints( mixed $list ): array
    {
        $out = [];
        foreach ( is_array( $list ) ? $list : [] as $v ) {
            if ( is_numeric( $v ) ) {
                $out[] = (int) $v;
            }
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `make test 2>&1 | tail -4`
Expected: `OK` (the new `ProductReaderTest` tests included; `ValidatorSyncTest` etc. unaffected because `wp-plugin/src/` is not mirrored).

- [ ] **Step 5: Commit**

```bash
git add wp-plugin/src/ProductReader.php tests/ProductReaderTest.php
git commit -m "feat: ProductReader, the pure shaping, caps and privacy rules for WooCommerce products

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `ProductQuery` (argument parsing) and `WooProductGateway` (the WooCommerce glue)

**Files:**
- Create: `wp-plugin/src/ProductQuery.php`, `wp-plugin/src/ProductGateway.php`, `wp-plugin/src/WooProductGateway.php`
- Create: `tests/ProductQueryTest.php`, `tests/WooShims.php`, `tests/WooProductGatewayTest.php`

**Interfaces:**
- Consumes: `ProductReader::MAX_VARIATIONS`, `MAX_IMAGES` (Task 1).
- Produces:
  - `ProductQuery::parse( array $in, bool $rest ): array{args: ?array<string,mixed>, error: ?string}`. On success `args` always has the keys `status, type, category, stock_status, on_sale, search, orderby, order, page, per_page` (null for unset filters).
  - `interface ProductGateway { isActive(): bool; info(): array{version:string,currency:string}; isProduct( int $id ): bool; query( array $args ): array{ids: list<int>, total: int, truncated: bool}; statusCounts(): array<string,int>; publishedTypeCounts(): array<string,int>; row( int $id ): ?array; detail( int $id ): ?array; }`
  - `WooProductGateway implements ProductGateway`; the scan cap `ProductGateway::MAX_SCAN = 500` is a constant of the interface (so `ProductService` and its test never load the WooCommerce class).
  - `tests/WooShims.php`: `FakeWcProduct`, global WooCommerce/WordPress function fakes driven by `$GLOBALS['__wc_products']` (id => `FakeWcProduct`).

- [ ] **Step 1: Write the failing `ProductQueryTest`**

Create `tests/ProductQueryTest.php`:

```php
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
```

- [ ] **Step 2: Run it to verify it fails**

Run: `make test 2>&1 | grep -B1 -A4 "ProductQuery" | head`
Expected: error `Class "AiEditorDivi5\WP\ProductQuery" not found`.

- [ ] **Step 3: Implement `ProductQuery`**

Create `wp-plugin/src/ProductQuery.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validates the arguments of list_products for MCP (typed) and REST (query strings). A wrong value is refused, never cast
 * or clamped. The one REST allowance: digit strings for integers and "true"/"false" for the boolean (a query string has no types).
 */
final class ProductQuery
{
    public const STATUSES        = [ 'any', 'publish', 'draft', 'pending', 'private', 'future' ];
    public const TYPES           = [ 'simple', 'variable', 'grouped', 'external' ];
    public const STOCK_STATUSES  = [ 'instock', 'outofstock', 'onbackorder' ];
    public const ORDERBY         = [ 'title', 'date', 'price' ];
    public const ORDER           = [ 'asc', 'desc' ];
    public const MAX_PER_PAGE    = 50;
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PAGE        = 100000;

    /**
     * @param array<string,mixed> $in
     * @return array{args: ?array<string,mixed>, error: ?string}
     */
    public static function parse( array $in, bool $rest ): array
    {
        $out = [
            'status' => 'any', 'type' => null, 'category' => null, 'stock_status' => null, 'on_sale' => null,
            'search' => null, 'orderby' => 'title', 'order' => 'asc', 'page' => 1, 'per_page' => self::DEFAULT_PER_PAGE,
        ];

        $enums = [ 'status' => self::STATUSES, 'type' => self::TYPES, 'stock_status' => self::STOCK_STATUSES, 'orderby' => self::ORDERBY, 'order' => self::ORDER ];
        foreach ( $enums as $key => $allowed ) {
            $v = $in[ $key ] ?? null;
            if ( null === $v || ( $rest && '' === $v ) ) {
                continue;
            }
            if ( ! is_string( $v ) || ! in_array( $v, $allowed, true ) ) {
                return self::fail( "\"{$key}\" must be one of: " . implode( ', ', $allowed ) . '.' );
            }
            $out[ $key ] = $v;
        }

        $category = $in['category'] ?? null;
        if ( null !== $category && ! ( $rest && '' === $category ) ) {
            if ( ! is_string( $category ) || 1 !== preg_match( '/^[A-Za-z0-9%_-]{1,200}$/', $category ) ) {
                return self::fail( '"category" must be a category slug (letters, digits, "-", "_" or "%", at most 200 characters).' );
            }
            $out['category'] = $category;
        }

        $search = $in['search'] ?? null;
        if ( null !== $search ) {
            if ( ! is_string( $search ) || mb_strlen( trim( $search ) ) > 100 ) {
                return self::fail( '"search" must be a string of at most 100 characters.' );
            }
            $search = trim( $search );
            if ( '' !== $search ) {
                $out['search'] = $search;
            }
        }

        $onSale = $in['on_sale'] ?? null;
        if ( null !== $onSale && ! ( $rest && '' === $onSale ) ) {
            if ( is_bool( $onSale ) ) {
                $out['on_sale'] = $onSale;
            } elseif ( $rest && 'true' === $onSale ) {
                $out['on_sale'] = true;
            } elseif ( $rest && 'false' === $onSale ) {
                $out['on_sale'] = false;
            } else {
                return self::fail( '"on_sale" must be true or false.' );
            }
        }

        foreach ( [ 'page' => [ 1, self::MAX_PAGE ], 'per_page' => [ 1, self::MAX_PER_PAGE ] ] as $key => [ $min, $max ] ) {
            $v = $in[ $key ] ?? null;
            if ( null === $v || ( $rest && '' === $v ) ) {
                continue;
            }
            if ( $rest && is_string( $v ) && 1 === preg_match( '/^\d{1,6}$/', $v ) ) {
                $v = (int) $v;
            }
            if ( ! is_int( $v ) || $v < $min || $v > $max ) {
                return self::fail( "\"{$key}\" must be a whole number from {$min} to {$max}." );
            }
            $out[ $key ] = $v;
        }

        return [ 'args' => $out, 'error' => null ];
    }

    /** @return array{args: null, error: string} */
    private static function fail( string $message ): array
    {
        return [ 'args' => null, 'error' => $message ];
    }
}
```

- [ ] **Step 4: Run to verify `ProductQueryTest` passes**

Run: `make test 2>&1 | tail -3`
Expected: `OK`.

- [ ] **Step 5: Write the gateway shims and the failing `WooProductGatewayTest`**

Create `tests/WooShims.php` (a tiny WooCommerce + WordPress fake; every function is guarded so a richer shim elsewhere wins):

```php
<?php

declare(strict_types=1);

/**
 * A minimal fake of the WooCommerce and WordPress read functions WooProductGateway calls, driven by $GLOBALS:
 *   __wc_products : id => FakeWcProduct
 *   __wc_terms    : taxonomy => id => ['name' => ..., 'slug' => ..., 'count' => ...]
 *   __wc_counts   : post status => count (wp_count_posts)
 *   __wc_attachments : id => url
 * Required only by the WooCommerce tests. WC_VERSION is defined here.
 */

if ( ! defined( 'WC_VERSION' ) ) {
    define( 'WC_VERSION', '11.1.2' );
}

if ( ! class_exists( 'FakeWcProduct' ) ) {
    /** Getter bag: get_x() / is_x() read the key x. Deliberately also answers the PRIVATE getters (downloads, purchase note, meta) so tests can prove the gateway never calls them. */
    class FakeWcProduct
    {
        /** @param array<string,mixed> $d */
        public function __construct( public array $d ) {}

        public function __call( string $name, array $args )
        {
            if ( 'is_type' === $name ) {
                return ( $this->d['type'] ?? '' ) === $args[0];
            }
            $key = preg_replace( '/^(get|is)_/', '', $name );
            if ( 'get_dimensions' === $name ) {
                return $this->d['dimensions'] ?? [ 'length' => '', 'width' => '', 'height' => '' ];
            }

            return $this->d[ $key ] ?? null;
        }
    }

    class FakeWcAttribute
    {
        /** @param array<string,mixed> $d */
        public function __construct( private array $d ) {}
        public function get_name() { return $this->d['name']; }
        public function is_taxonomy() { return (bool) ( $this->d['taxonomy'] ?? false ); }
        public function get_options() { return $this->d['options'] ?? []; }
        public function get_terms() { return array_map( fn ( $n ) => (object) [ 'name' => $n ], $this->d['terms'] ?? [] ); }
        public function get_variation() { return (bool) ( $this->d['variation'] ?? false ); }
        public function get_visible() { return (bool) ( $this->d['visible'] ?? true ); }
    }
}

if ( ! function_exists( 'wc_get_product' ) ) {
    function wc_get_product( $id ) { return $GLOBALS['__wc_products'][ (int) $id ] ?? false; }
}
if ( ! function_exists( 'wc_get_products' ) ) {
    function wc_get_products( array $args ) {
        $all = array_values( array_filter( $GLOBALS['__wc_products'] ?? [], fn ( $p ) => 'product_variation' !== ( $p->d['post_type'] ?? 'product' ) ) );
        $status = (array) ( $args['status'] ?? [ 'publish' ] );
        $all = array_values( array_filter( $all, fn ( $p ) => in_array( $p->d['status'], $status, true ) ) );
        if ( isset( $args['type'] ) ) {
            $all = array_values( array_filter( $all, fn ( $p ) => $p->d['type'] === $args['type'] ) );
        }
        if ( isset( $args['stock_status'] ) ) {
            $all = array_values( array_filter( $all, fn ( $p ) => $p->d['stock_status'] === $args['stock_status'] ) );
        }
        if ( isset( $args['category'] ) ) {
            $slugs = (array) $args['category'];
            $all   = array_values( array_filter( $all, function ( $p ) use ( $slugs ) {
                foreach ( (array) ( $p->d['category_ids'] ?? [] ) as $cid ) {
                    if ( in_array( $GLOBALS['__wc_terms']['product_cat'][ $cid ]['slug'] ?? '', $slugs, true ) ) {
                        return true;
                    }
                }
                return false;
            } ) );
        }
        if ( isset( $args['s'] ) ) {
            $all = array_values( array_filter( $all, fn ( $p ) => false !== stripos( (string) $p->d['name'], (string) $args['s'] ) ) );
        }
        $by = $args['orderby'] ?? 'title';
        usort( $all, fn ( $a, $b ) => 'price' === $by ? (float) $a->d['price'] <=> (float) $b->d['price'] : ( 'date' === $by ? strcmp( (string) ( $a->d['date_created'] ?? '' ), (string) ( $b->d['date_created'] ?? '' ) ) : strcasecmp( (string) $a->d['name'], (string) $b->d['name'] ) ) );
        if ( 'DESC' === ( $args['order'] ?? 'ASC' ) ) {
            $all = array_reverse( $all );
        }
        $limit = (int) ( $args['limit'] ?? -1 );
        $paged = ! empty( $args['paginate'] );
        $total = count( $all );
        if ( $limit > 0 ) {
            $page = max( 1, (int) ( $args['page'] ?? 1 ) );
            $all  = array_slice( $all, ( $page - 1 ) * $limit, $limit );
        }
        $ids = array_map( fn ( $p ) => (int) $p->d['id'], $all );
        $out = 'ids' === ( $args['return'] ?? 'objects' ) ? $ids : $all;
        if ( $paged ) {
            return (object) [ 'products' => $out, 'total' => $total, 'max_num_pages' => $limit > 0 ? (int) ceil( $total / $limit ) : 1 ];
        }

        return $out;
    }
}
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
    function get_woocommerce_currency() { return 'USD'; }
}
if ( ! function_exists( 'wc_attribute_label' ) ) {
    function wc_attribute_label( $name ) { return ucfirst( preg_replace( '/^pa_/', '', (string) $name ) ); }
}
if ( ! function_exists( 'get_terms' ) ) {
    function get_terms( $args ) {
        $out = [];
        foreach ( $GLOBALS['__wc_terms'][ $args['taxonomy'] ] ?? [] as $id => $t ) {
            if ( isset( $args['include'] ) && ! in_array( $id, (array) $args['include'], true ) ) {
                continue;
            }
            $out[] = (object) [ 'term_id' => $id, 'name' => $t['name'], 'slug' => $t['slug'], 'count' => $t['count'] ?? 0 ];
        }

        return $out;
    }
}
if ( ! function_exists( 'wp_count_posts' ) ) {
    function wp_count_posts( $type = 'post' ) { return (object) ( $GLOBALS['__wc_counts'] ?? [] ); }
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    function wp_get_attachment_url( $id ) { return $GLOBALS['__wc_attachments'][ (int) $id ] ?? false; }
}
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $id ) { return 'https://shop.test/?p=' . (int) $id; }
}
if ( ! function_exists( 'get_post_type' ) ) {
    function get_post_type( $id ) {
        $p = $GLOBALS['__wc_products'][ (int) $id ] ?? null;
        if ( $p ) {
            return $p->d['post_type'] ?? 'product';
        }

        return $GLOBALS['__wp_posts'][ (int) $id ]->post_type ?? false;
    }
}
```

Create `tests/WooProductGatewayTest.php`:

```php
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
    }
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `make test 2>&1 | grep -B1 -A4 "WooProductGateway" | head`
Expected: error `Class "AiEditorDivi5\WP\WooProductGateway" not found` (and `ProductGateway` interface not found).

- [ ] **Step 7: Implement the interface and the gateway**

Create `wp-plugin/src/ProductGateway.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** What ProductService needs from a store. The real implementation is WooProductGateway; tests use a fake. */
interface ProductGateway
{
    /** WooCommerce has no on-sale filter, so on_sale is decided in PHP over at most this many matches. */
    public const MAX_SCAN = 500;

    public function isActive(): bool;

    /** @return array{version: string, currency: string} */
    public function info(): array;

    /** True only for a post of the exact type `product` (a variation or any other id is false). */
    public function isProduct( int $id ): bool;

    /**
     * @param array<string,mixed> $args the parsed ProductQuery args
     * @return array{ids: list<int>, total: int, truncated: bool} the ids of the requested page, the number of ALL matches
     */
    public function query( array $args ): array;

    /** @return array<string,int> post status => number of products */
    public function statusCounts(): array;

    /** @return array<string,int> product type => number of PUBLISHED products */
    public function publishedTypeCounts(): array;

    /** @return ?array<string,mixed> the raw list-row array for ProductReader::row(), or null when there is no such product */
    public function row( int $id ): ?array;

    /** @return ?array<string,mixed> the raw array for ProductReader::detail(), or null when there is no such product */
    public function detail( int $id ): ?array;
}
```

Create `wp-plugin/src/WooProductGateway.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ONLY class that calls WooCommerce, and only its public READ functions. It copies an explicit list of fields into a plain
 * array; it never calls a getter for downloads, the purchase note or meta, so none of them can reach the AI.
 */
final class WooProductGateway implements ProductGateway
{
    private const LIST_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

    public function isActive(): bool
    {
        return function_exists( 'wc_get_products' ) && function_exists( 'wc_get_product' );
    }

    public function info(): array
    {
        return [
            'version'  => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
            'currency' => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
        ];
    }

    public function isProduct( int $id ): bool
    {
        return $id > 0 && 'product' === get_post_type( $id );
    }

    public function query( array $args ): array
    {
        $q = [
            'return'  => 'ids',
            'status'  => 'any' === $args['status'] ? self::LIST_STATUSES : $args['status'],
            'orderby' => $args['orderby'],
            'order'   => strtoupper( (string) $args['order'] ),
        ];
        foreach ( [ 'type', 'stock_status' ] as $key ) {
            if ( null !== $args[ $key ] ) {
                $q[ $key ] = $args[ $key ];
            }
        }
        if ( null !== $args['category'] ) {
            $q['category'] = [ $args['category'] ];
        }
        if ( null !== $args['search'] ) {
            $q['s'] = $args['search'];
        }
        $perPage = (int) $args['per_page'];
        $page    = (int) $args['page'];

        if ( null === $args['on_sale'] ) {
            $r = wc_get_products( $q + [ 'limit' => $perPage, 'page' => $page, 'paginate' => true ] );

            return [ 'ids' => array_map( 'intval', (array) $r->products ), 'total' => (int) $r->total, 'truncated' => false ];
        }

        // wc_get_products() silently ignores an `on_sale` argument (checked on WooCommerce 11.1.2), so decide it here.
        $ids       = array_map( 'intval', (array) wc_get_products( $q + [ 'limit' => self::MAX_SCAN + 1, 'paginate' => false ] ) );
        $truncated = count( $ids ) > self::MAX_SCAN;
        $hits      = [];
        foreach ( array_slice( $ids, 0, self::MAX_SCAN ) as $id ) {
            $p = wc_get_product( $id );
            if ( $p && (bool) $p->is_on_sale() === $args['on_sale'] ) {
                $hits[] = $id;
            }
        }

        return [ 'ids' => array_slice( $hits, ( $page - 1 ) * $perPage, $perPage ), 'total' => count( $hits ), 'truncated' => $truncated ];
    }

    public function statusCounts(): array
    {
        $counts = (array) wp_count_posts( 'product' );
        $out    = [];
        foreach ( self::LIST_STATUSES as $status ) {
            $out[ $status ] = (int) ( $counts[ $status ] ?? 0 );
        }

        return $out;
    }

    public function publishedTypeCounts(): array
    {
        $terms = get_terms( [ 'taxonomy' => 'product_type', 'hide_empty' => false ] );
        if ( ! is_array( $terms ) ) {
            return [];
        }
        $out = [];
        foreach ( $terms as $term ) {
            $out[ (string) $term->slug ] = (int) $term->count;
        }

        return $out;
    }

    public function row( int $id ): ?array
    {
        $p = wc_get_product( $id );

        return $p ? $this->base( $p ) : null;
    }

    public function detail( int $id ): ?array
    {
        $p = wc_get_product( $id );
        if ( ! $p ) {
            return null;
        }
        $raw  = $this->base( $p );
        $type = (string) $p->get_type();
        $img  = (int) $p->get_image_id();

        $raw['description']        = (string) $p->get_description();
        $raw['short_description']  = (string) $p->get_short_description();
        $raw['password_protected'] = '' !== (string) $p->get_post_password();
        $raw['tax_status']         = (string) $p->get_tax_status();
        $raw['tax_class']          = (string) $p->get_tax_class();
        $raw['weight']             = (string) $p->get_weight();
        $raw['dimensions']         = (array) $p->get_dimensions( false );
        $raw['shipping_class']     = (string) $p->get_shipping_class();
        $raw['sold_individually']  = (bool) $p->get_sold_individually();
        $raw['backorders']         = (string) $p->get_backorders();
        $raw['low_stock_amount']   = $p->get_low_stock_amount();
        $raw['virtual']            = (bool) $p->get_virtual();
        $raw['downloadable']       = (bool) $p->get_downloadable(); // the flag only: never get_downloads()
        $raw['featured']           = (bool) $p->get_featured();
        $raw['catalog_visibility'] = (string) $p->get_catalog_visibility();
        $raw['attributes']         = $this->attributes( (array) $p->get_attributes() );
        $raw['images']             = [
            'featured' => $img > 0 ? $this->url( $img ) : null,
            'gallery'  => array_values( array_filter( array_map( [ $this, 'url' ], array_slice( array_map( 'intval', (array) $p->get_gallery_image_ids() ), 0, ProductReader::MAX_IMAGES ) ) ) ),
        ];
        $raw['tags']               = $this->terms( 'product_tag', (array) $p->get_tag_ids() );
        $raw['upsell_ids']         = array_map( 'intval', (array) $p->get_upsell_ids() );
        $raw['cross_sell_ids']     = array_map( 'intval', (array) $p->get_cross_sell_ids() );
        $created                   = $p->get_date_created();
        $modified                  = $p->get_date_modified();
        $raw['date_created']       = $created ? (string) $created->date( 'c' ) : null;
        $raw['date_modified']      = $modified ? (string) $modified->date( 'c' ) : null;
        $raw['average_rating']     = (string) $p->get_average_rating();
        $raw['review_count']       = (int) $p->get_review_count();

        if ( 'external' === $type ) {
            $raw['external'] = [ 'product_url' => (string) $p->get_product_url(), 'button_text' => (string) $p->get_button_text() ];
        }
        if ( 'grouped' === $type ) {
            $raw['children'] = array_map( 'intval', (array) $p->get_children() );
        }
        if ( 'variable' === $type ) {
            $children                 = array_map( 'intval', (array) $p->get_children() );
            $raw['variations_total']  = count( $children );
            $raw['variations']        = [];
            foreach ( array_slice( $children, 0, ProductReader::MAX_VARIATIONS ) as $vid ) {
                $v = wc_get_product( $vid );
                if ( ! $v ) {
                    continue;
                }
                $attrs = [];
                foreach ( (array) $v->get_attributes() as $name => $value ) {
                    $attrs[ (string) wc_attribute_label( (string) $name ) ] = (string) $value;
                }
                $vimg                = (int) $v->get_image_id();
                $raw['variations'][] = [
                    'id' => (int) $v->get_id(), 'sku' => (string) $v->get_sku(), 'status' => (string) $v->get_status(),
                    'price' => (string) $v->get_price(), 'regular_price' => (string) $v->get_regular_price(), 'sale_price' => (string) $v->get_sale_price(),
                    'on_sale' => (bool) $v->is_on_sale(), 'stock_status' => (string) $v->get_stock_status(),
                    'manage_stock' => (bool) $v->get_manage_stock(), 'stock_quantity' => $v->get_stock_quantity(),
                    'attributes' => $attrs, 'image' => $vimg > 0 ? $this->url( $vimg ) : null,
                ];
            }
        }

        return $raw;
    }

    /** @return array<string,mixed> */
    private function base( object $p ): array
    {
        $id     = (int) $p->get_id();
        $manage = (bool) $p->get_manage_stock();
        $type   = (string) $p->get_type();
        $row    = [
            'id' => $id, 'name' => (string) $p->get_name(), 'type' => $type, 'status' => (string) $p->get_status(),
            'sku' => (string) $p->get_sku(), 'price' => (string) $p->get_price(), 'regular_price' => (string) $p->get_regular_price(),
            'sale_price' => (string) $p->get_sale_price(), 'on_sale' => (bool) $p->is_on_sale(),
            'stock_status' => (string) $p->get_stock_status(), 'manage_stock' => $manage, 'stock_quantity' => $manage ? $p->get_stock_quantity() : null,
            'categories' => $this->terms( 'product_cat', (array) $p->get_category_ids() ),
            'url' => (string) get_permalink( $id ),
        ];
        if ( 'variable' === $type ) {
            $row['variations_count'] = count( (array) $p->get_children() );
        }

        return $row;
    }

    /** @param list<mixed> $ids @return list<array{id:int,name:string,slug:string}> */
    private function terms( string $taxonomy, array $ids ): array
    {
        if ( [] === $ids ) {
            return [];
        }
        $terms = get_terms( [ 'taxonomy' => $taxonomy, 'include' => array_map( 'intval', $ids ), 'hide_empty' => false ] );
        if ( ! is_array( $terms ) ) {
            return [];
        }
        $out = [];
        foreach ( $terms as $t ) {
            $out[] = [ 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug ];
        }

        return $out;
    }

    /** @param array<mixed> $attributes @return list<array<string,mixed>> */
    private function attributes( array $attributes ): array
    {
        $out = [];
        foreach ( $attributes as $a ) {
            if ( ! is_object( $a ) ) {
                continue;
            }
            $taxonomy = (bool) $a->is_taxonomy();
            $options  = [];
            if ( $taxonomy ) { // WooCommerce stores term ids; the AI needs the names
                foreach ( (array) $a->get_terms() as $t ) {
                    if ( is_object( $t ) ) {
                        $options[] = (string) $t->name;
                    }
                }
            } else {
                foreach ( (array) $a->get_options() as $o ) {
                    $options[] = (string) $o;
                }
            }
            $out[] = [
                'name' => (string) wc_attribute_label( (string) $a->get_name() ), 'options' => $options,
                'variation' => (bool) $a->get_variation(), 'visible' => (bool) $a->get_visible(), 'taxonomy' => $taxonomy,
            ];
        }

        return $out;
    }

    private function url( int $attachmentId ): ?string
    {
        $url = wp_get_attachment_url( $attachmentId );

        return is_string( $url ) && '' !== $url ? $url : null;
    }
}
```

- [ ] **Step 8: Run to verify the gateway tests pass**

Run: `make test 2>&1 | tail -4`
Expected: `OK`. If a shim detail trips a test (for example the date ordering), fix the SHIM in `tests/WooShims.php`, not the gateway, unless the failure shows a real gateway bug.

- [ ] **Step 9: Commit**

```bash
git add wp-plugin/src/ProductQuery.php wp-plugin/src/ProductGateway.php wp-plugin/src/WooProductGateway.php tests/ProductQueryTest.php tests/WooShims.php tests/WooProductGatewayTest.php
git commit -m "feat: ProductQuery and WooProductGateway, the argument rules and the only WooCommerce-facing class

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `ProductService` (orchestration, permissions, error kinds)

**Files:**
- Create: `wp-plugin/src/ProductService.php`
- Test: `tests/ProductServiceTest.php`

**Interfaces:**
- Consumes: `ProductGateway`, `ProductQuery::parse`, `ProductReader::row/detail/totals` (Tasks 1-2).
- Produces: `ProductService::list( array $input, bool $rest, ProductGateway $gw, callable $can ): array` and `ProductService::get( int $id, ProductGateway $gw, callable $can ): array`. Both return `['ok' => true, 'data' => array]` or `['ok' => false, 'kind' => 'forbidden'|'invalid'|'not_found'|'server', 'message' => string]`. `$can` has the signature of `current_user_can` (`$can( string $cap, ...$args ): bool`). Constant `ProductService::MSG_INACTIVE`.

- [ ] **Step 1: Write the failing test**

Create `tests/ProductServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ProductGateway;
use AiEditorDivi5\WP\ProductService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ProductReader.php';
require_once __DIR__ . '/../wp-plugin/src/ProductQuery.php';
require_once __DIR__ . '/../wp-plugin/src/ProductGateway.php';
require_once __DIR__ . '/../wp-plugin/src/ProductService.php';

/** A scripted store: no WooCommerce at all. */
final class FakeGateway implements ProductGateway
{
    public bool $active = true;
    public bool $throw  = false;
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    public bool $truncated = false;
    /** @var list<array<string,mixed>> */
    public array $queries = [];

    public function isActive(): bool { return $this->active; }
    public function info(): array { return [ 'version' => '9.9.9', 'currency' => 'EUR' ]; }
    public function isProduct( int $id ): bool { return isset( $this->rows[ $id ] ) && 'product' === ( $this->rows[ $id ]['post_type'] ?? 'product' ); }
    public function query( array $args ): array
    {
        $this->guard();
        $this->queries[] = $args;
        $ids = array_keys( array_filter( $this->rows, fn ( $r ) => 'product' === ( $r['post_type'] ?? 'product' ) ) );

        return [ 'ids' => array_slice( $ids, ( $args['page'] - 1 ) * $args['per_page'], $args['per_page'] ), 'total' => count( $ids ), 'truncated' => $this->truncated ];
    }
    public function statusCounts(): array { $this->guard(); return [ 'publish' => 3, 'draft' => 1, 'pending' => 0, 'private' => 0, 'future' => 0 ]; }
    public function publishedTypeCounts(): array { return [ 'simple' => 3 ]; }
    public function row( int $id ): ?array { $this->guard(); return $this->rows[ $id ] ?? null; }
    public function detail( int $id ): ?array { $this->guard(); return $this->rows[ $id ] ?? null; }
    private function guard(): void { if ( $this->throw ) { throw new \RuntimeException( 'internal /var/www/secret path' ); } }
}

class ProductServiceTest extends TestCase
{
    private FakeGateway $gw;

    protected function setUp(): void
    {
        $this->gw = new FakeGateway();
        foreach ( [ 1, 2, 3, 4 ] as $id ) {
            $this->gw->rows[ $id ] = [ 'id' => $id, 'name' => "P{$id}", 'type' => 'simple', 'status' => 'publish', 'price' => '5', 'url' => "https://s.test/{$id}" ];
        }
        $this->gw->rows[9] = [ 'id' => 9, 'name' => 'Variation', 'type' => 'variation', 'post_type' => 'product_variation' ];
    }

    private function can( array $deny = [] ): callable
    {
        return static function ( string $cap, ...$args ) use ( $deny ): bool {
            return ! in_array( $cap . ( isset( $args[0] ) ? ':' . $args[0] : '' ), $deny, true ) && ! in_array( $cap, $deny, true );
        };
    }

    public function testListReturnsTotalsRowsAndPaging(): void
    {
        $r = ProductService::list( [ 'per_page' => 3 ], false, $this->gw, $this->can() );
        $this->assertTrue( $r['ok'] );
        $d = $r['data'];
        $this->assertSame( [ 'active' => true, 'version' => '9.9.9', 'currency' => 'EUR' ], $d['woocommerce'] );
        $this->assertSame( 4, $d['totals']['all'] );
        $this->assertCount( 3, $d['products'] );
        $this->assertSame( 'P1', $d['products'][0]['name'] );
        $this->assertSame( [ 1, 3, 4, 2 ], [ $d['page'], $d['per_page'], $d['total_matching'], $d['total_pages'] ] );
        $this->assertArrayNotHasKey( 'notes', $d );
    }

    public function testAPageBeyondTheLastIsANormalEmptyListWithTheTotals(): void
    {
        $r = ProductService::list( [ 'page' => 9 ], false, $this->gw, $this->can() );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [], $r['data']['products'] );
        $this->assertSame( 4, $r['data']['total_matching'] );
        $this->assertSame( 4, $r['data']['totals']['all'] );
    }

    public function testNoMatchesGivesZeroPages(): void
    {
        $this->gw->rows = [];
        $r = ProductService::list( [], false, $this->gw, $this->can() );
        $this->assertSame( 0, $r['data']['total_matching'] );
        $this->assertSame( 0, $r['data']['total_pages'] );
    }

    public function testBadArgumentsAreInvalid(): void
    {
        $r = ProductService::list( [ 'per_page' => 51 ], false, $this->gw, $this->can() );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'invalid', $r['kind'] );
        $this->assertSame( [], $this->gw->queries, 'nothing is queried for bad input' );
    }

    public function testAUserWithoutTheProductPermissionsIsForbiddenAndToldWhich(): void
    {
        foreach ( [ 'edit_products', 'edit_others_products' ] as $missing ) {
            $r = ProductService::list( [], false, $this->gw, $this->can( [ $missing ] ) );
            $this->assertFalse( $r['ok'] );
            $this->assertSame( 'forbidden', $r['kind'] );
            $this->assertStringContainsString( 'edit_products', $r['message'] );
            $this->assertStringContainsString( 'edit_others_products', $r['message'] );
        }
    }

    public function testRowsTheUserCannotEditAreLeftOut(): void
    {
        $r = ProductService::list( [], false, $this->gw, $this->can( [ 'edit_post:2' ] ) );
        $this->assertSame( [ 1, 3, 4 ], array_column( $r['data']['products'], 'id' ) );
    }

    public function testAnInactiveStoreIsANormalAnswerNotAnError(): void
    {
        $this->gw->active = false;
        $r = ProductService::list( [], false, $this->gw, $this->can() );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [ 'active' => false ], $r['data']['woocommerce'] );
        $this->assertSame( [], $r['data']['products'] );
        $this->assertSame( ProductService::MSG_INACTIVE, $r['data']['message'] );
        $g = ProductService::get( 1, $this->gw, $this->can() );
        $this->assertTrue( $g['ok'] );
        $this->assertNull( $g['data']['product'] );
        $this->assertSame( [ 'active' => false ], $g['data']['woocommerce'] );
    }

    public function testAThrowingStoreAnswersCleanlyWithoutLeakingTheMessage(): void
    {
        $this->gw->throw = true;
        foreach ( [ ProductService::list( [], false, $this->gw, $this->can() ), ProductService::get( 1, $this->gw, $this->can() ) ] as $r ) {
            $this->assertFalse( $r['ok'] );
            $this->assertSame( 'server', $r['kind'] );
            $this->assertSame( 'WooCommerce could not be read.', $r['message'] );
            $this->assertStringNotContainsString( '/var/www', $r['message'] );
        }
    }

    public function testATruncatedOnSaleScanSaysSo(): void
    {
        $this->gw->truncated = true;
        $r = ProductService::list( [ 'on_sale' => true ], false, $this->gw, $this->can() );
        $this->assertStringContainsString( '500', $r['data']['notes'][0] );
    }

    public function testGetReturnsTheDetail(): void
    {
        $r = ProductService::get( 2, $this->gw, $this->can() );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'P2', $r['data']['product']['name'] );
        $this->assertSame( 'EUR', $r['data']['woocommerce']['currency'] );
        $this->assertArrayHasKey( 'description', $r['data']['product'] );
    }

    public function testMissingVariationAndNotEditableAllAnswerTheSameNotFound(): void
    {
        $messages = [];
        foreach ( [ [ 999, [] ], [ 9, [] ], [ 2, [ 'edit_post:2' ] ], [ 0, [] ], [ -4, [] ] ] as [ $id, $deny ] ) {
            $r = ProductService::get( $id, $this->gw, $this->can( $deny ) );
            $this->assertFalse( $r['ok'], "id {$id}" );
            $this->assertSame( 'not_found', $r['kind'], "id {$id}" );
            $messages[] = str_replace( (string) $id, 'N', $r['message'] );
        }
        $this->assertCount( 1, array_unique( $messages ) );
        $this->assertSame( 'Product 999 not found.', ProductService::get( 999, $this->gw, $this->can() )['message'] );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `make test 2>&1 | grep -B1 -A4 "ProductService" | head`
Expected: error `Class "AiEditorDivi5\WP\ProductService" not found`.

- [ ] **Step 3: Implement `ProductService`**

Create `wp-plugin/src/ProductService.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * list_products and get_product, once: McpHandler and RestController are thin adapters that map the result to JSON-RPC or WP_Error.
 * It never touches WooCommerce itself (ProductGateway does) and never logs (the adapters do).
 *
 * Result: ['ok' => true, 'data' => array] or ['ok' => false, 'kind' => forbidden|invalid|not_found|server, 'message' => string].
 */
final class ProductService
{
    public const MSG_INACTIVE = 'WooCommerce is not active on this site.';
    public const MSG_SERVER   = 'WooCommerce could not be read.';

    /**
     * @param array<string,mixed> $input
     * @param callable(string, mixed...): bool $can current_user_can
     * @return array<string,mixed>
     */
    public static function list( array $input, bool $rest, ProductGateway $gw, callable $can ): array
    {
        if ( ! $can( 'edit_products' ) || ! $can( 'edit_others_products' ) ) {
            return self::fail( 'forbidden', 'Listing products needs the edit_products and edit_others_products permissions (shop managers and administrators have them).' );
        }
        $parsed = ProductQuery::parse( $input, $rest );
        if ( null === $parsed['args'] ) {
            return self::fail( 'invalid', (string) $parsed['error'] );
        }
        if ( ! $gw->isActive() ) {
            return self::ok( [ 'woocommerce' => [ 'active' => false ], 'products' => [], 'message' => self::MSG_INACTIVE ] );
        }

        try {
            $args  = $parsed['args'];
            $found = $gw->query( $args );
            $rows  = [];
            foreach ( $found['ids'] as $id ) {
                if ( ! $can( 'edit_post', $id ) ) {
                    continue;
                }
                $raw = $gw->row( (int) $id );
                if ( null !== $raw ) {
                    $rows[] = ProductReader::row( $raw );
                }
            }
            $perPage = (int) $args['per_page'];
            $total   = (int) $found['total'];
            $data    = [
                'woocommerce'    => [ 'active' => true ] + $gw->info(),
                'totals'         => ProductReader::totals( $gw->statusCounts(), $gw->publishedTypeCounts() ),
                'products'       => $rows,
                'page'           => (int) $args['page'],
                'per_page'       => $perPage,
                'total_matching' => $total,
                'total_pages'    => $total > 0 ? (int) ceil( $total / $perPage ) : 0,
            ];
            if ( ! empty( $found['truncated'] ) ) {
                $data['notes'] = [ 'The on_sale filter looked at the first ' . ProductGateway::MAX_SCAN . ' matching products only; narrow the search (category, type, search) to see the rest.' ];
            }

            return self::ok( $data );
        } catch ( \Throwable ) {
            return self::fail( 'server', self::MSG_SERVER );
        }
    }

    /**
     * @param callable(string, mixed...): bool $can current_user_can
     * @return array<string,mixed>
     */
    public static function get( int $id, ProductGateway $gw, callable $can ): array
    {
        if ( ! $gw->isActive() ) {
            return self::ok( [ 'woocommerce' => [ 'active' => false ], 'product' => null, 'message' => self::MSG_INACTIVE ] );
        }

        try {
            // A missing id, a variation, a post of another type and a product the user cannot edit all answer the same.
            if ( $id < 1 || ! $gw->isProduct( $id ) || ! $can( 'edit_post', $id ) ) {
                return self::fail( 'not_found', "Product {$id} not found." );
            }
            $raw = $gw->detail( $id );
            if ( null === $raw ) {
                return self::fail( 'not_found', "Product {$id} not found." );
            }

            return self::ok( [ 'woocommerce' => [ 'active' => true ] + $gw->info(), 'product' => ProductReader::detail( $raw ) ] );
        } catch ( \Throwable ) {
            return self::fail( 'server', self::MSG_SERVER );
        }
    }

    /** @param array<string,mixed> $data @return array{ok: true, data: array<string,mixed>} */
    private static function ok( array $data ): array
    {
        return [ 'ok' => true, 'data' => $data ];
    }

    /** @return array{ok: false, kind: string, message: string} */
    private static function fail( string $kind, string $message ): array
    {
        return [ 'ok' => false, 'kind' => $kind, 'message' => $message ];
    }
}
```

- [ ] **Step 4: Run to verify the tests pass**

Run: `make test 2>&1 | tail -4`
Expected: `OK`.

- [ ] **Step 5: Commit**

```bash
git add wp-plugin/src/ProductService.php tests/ProductServiceTest.php
git commit -m "feat: ProductService, list_products and get_product orchestration with permissions and error kinds

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: MCP tools and REST routes (thin adapters) + lockstep test

**Files:**
- Modify: `wp-plugin/src/autoload.php`, `wp-plugin/src/McpHandler.php`, `wp-plugin/src/RestController.php`
- Create: `tests/ProductsLockstepTest.php`

**Interfaces:**
- Consumes: `ProductService::list/get` (Task 3), `WooProductGateway` (Task 2), `PageAccess::CAP`, `UsageTracker::log`, the handlers' existing `rpcError` / `rpcResult`.
- Produces: MCP tools `list_products` and `get_product`; REST `GET /products` (callback `list_products`) and `GET /products/{id}` (callback `get_product`); usage endpoints `list_products` and `get_product`.

- [ ] **Step 1: Write the failing lockstep test**

Create `tests/ProductsLockstepTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/SiteStructureShims.php';
require_once __DIR__ . '/CustomFieldsReadShims.php';
require_once __DIR__ . '/WooShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/FieldTarget.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFieldReader.php';
require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/SiteStructure.php';
require_once __DIR__ . '/../wp-plugin/src/ProductReader.php';
require_once __DIR__ . '/../wp-plugin/src/ProductQuery.php';
require_once __DIR__ . '/../wp-plugin/src/ProductGateway.php';
require_once __DIR__ . '/../wp-plugin/src/WooProductGateway.php';
require_once __DIR__ . '/../wp-plugin/src/ProductService.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** list_products / get_product: same meaning in MCP and REST, read-only, and deliberately NOT in the ChatGPT OpenAPI spec. */
class ProductsLockstepTest extends TestCase
{
    private function product( int $id, array $over = [] ): \FakeWcProduct
    {
        return new \FakeWcProduct( $over + [
            'id' => $id, 'name' => "Product {$id}", 'type' => 'simple', 'status' => 'publish', 'sku' => "SKU{$id}", 'price' => (string) ( $id * 10 ),
            'regular_price' => (string) ( $id * 10 ), 'sale_price' => '', 'on_sale' => false, 'stock_status' => 'instock', 'manage_stock' => false,
            'stock_quantity' => null, 'category_ids' => [ 3 ], 'tag_ids' => [], 'image_id' => 0, 'gallery_image_ids' => [], 'attributes' => [],
            'children' => [], 'upsell_ids' => [], 'cross_sell_ids' => [], 'weight' => '', 'shipping_class' => '', 'virtual' => false,
            'downloadable' => false, 'catalog_visibility' => 'visible', 'average_rating' => '0', 'review_count' => 0, 'tax_status' => 'taxable',
            'tax_class' => '', 'sold_individually' => false, 'backorders' => 'no', 'low_stock_amount' => null, 'description' => 'Desc', 'short_description' => 'Short',
            'featured' => false, 'post_password' => '', 'date_created' => null, 'date_modified' => null,
            'downloads' => [ [ 'file' => 'https://shop.test/SECRET-DOWNLOAD.zip' ] ], 'purchase_note' => 'SECRET-PURCHASE-NOTE',
        ] );
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wc_terms']    = [ 'product_cat' => [ 3 => [ 'name' => 'Clothing', 'slug' => 'clothing' ] ], 'product_type' => [ 1 => [ 'name' => 'simple', 'slug' => 'simple', 'count' => 3 ] ], 'product_tag' => [] ];
        $GLOBALS['__wc_counts']   = [ 'publish' => 3, 'draft' => 1 ];
        $GLOBALS['__wc_products'] = [ 1 => $this->product( 1 ), 2 => $this->product( 2, [ 'name' => 'Locked', 'post_password' => 'pw', 'description' => 'SECRET-DESC' ] ), 3 => $this->product( 3 ), 4 => $this->product( 4, [ 'status' => 'draft' ] ) ];
        $GLOBALS['__wp_caps']     = [ 'edit_pages' => true, 'edit_products' => true, 'edit_others_products' => true, 'edit_post' => true ];
        $GLOBALS['__wp_posts']    = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wc_products'], $GLOBALS['__wc_terms'], $GLOBALS['__wc_counts'] );
        $GLOBALS['__wp_can'] = true;
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    private function mcpCall( string $tool, array $args ): array
    {
        $req = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ?: new \stdClass() ] ] );

        return (array) ( new McpHandler() )->handle( $req )->get_data();
    }

    private function restList( array $params )
    {
        return ( new RestController() )->list_products( new \WP_REST_Request( [], $params ) );
    }

    private function restGet( array $params )
    {
        return ( new RestController() )->get_product( new \WP_REST_Request( [], $params ) );
    }

    public function testMcpDeclaresAndDispatchesBothTools(): void
    {
        $mcp = $this->src( 'McpHandler.php' );
        foreach ( [ 'list_products' => 'toolListProducts', 'get_product' => 'toolGetProduct' ] as $tool => $method ) {
            $this->assertStringContainsString( "'name'        => '{$tool}'", $mcp );
            $this->assertMatchesRegularExpression( "/'{$tool}'\s*=>\s*\\\$this->{$method}/", $mcp );
        }
    }

    public function testRestRegistersBothRoutesAsReadOnly(): void
    {
        $rest = $this->src( 'RestController.php' );
        $this->assertMatchesRegularExpression( "#'/products',\s*\[\s*'methods'\s*=>\s*WP_REST_Server::READABLE#", $rest );
        $this->assertMatchesRegularExpression( '#/products/\(\?P<id>\\\\d\+\)\',\s*\[\s*\'methods\'\s*=>\s*WP_REST_Server::READABLE#', $rest );
    }

    public function testNeitherToolIsInTheChatGptSpecOnPurpose(): void
    {
        // The importer allows 30 operations and the free (16) plus Pro (14) tools already use all 30 (owner decision 2026-10-04).
        $json = json_encode( OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' ) );
        foreach ( [ 'list_products', 'get_product', '/products' ] as $needle ) {
            $this->assertStringNotContainsString( $needle, $json );
        }
    }

    public function testRestAndMcpReturnTheSameListAndTheSameProduct(): void
    {
        $rest = $this->restList( [ 'per_page' => '2' ] );
        $this->assertSame( 200, $rest->get_status() );
        $mcp = $this->mcpCall( 'list_products', [ 'per_page' => 2 ] );
        $this->assertArrayNotHasKey( 'error', $mcp );
        $this->assertSame( (array) $rest->get_data(), json_decode( $mcp['result']['content'][0]['text'], true ) );
        $d = (array) $rest->get_data();
        $this->assertSame( 2, count( $d['products'] ) );
        $this->assertSame( 4, $d['total_matching'] );
        $this->assertSame( 2, $d['total_pages'] );
        $this->assertSame( 4, $d['totals']['all'] );

        $restOne = $this->restGet( [ 'id' => '1' ] );
        $this->assertSame( 200, $restOne->get_status() );
        $mcpOne = $this->mcpCall( 'get_product', [ 'id' => 1 ] );
        $this->assertSame( (array) $restOne->get_data(), json_decode( $mcpOne['result']['content'][0]['text'], true ) );
    }

    public function testNothingPrivateReachesEitherSurface(): void
    {
        $all = json_encode( [
            $this->restList( [ 'per_page' => '50' ] )->get_data(), $this->restGet( [ 'id' => '1' ] )->get_data(), $this->restGet( [ 'id' => '2' ] )->get_data(),
            $this->mcpCall( 'get_product', [ 'id' => 2 ] ),
        ] );
        foreach ( [ 'SECRET-DOWNLOAD', 'SECRET-PURCHASE-NOTE', 'SECRET-DESC' ] as $needle ) {
            $this->assertStringNotContainsString( $needle, $all );
        }
    }

    public function testBadInputIsRejectedNeverCastOrClamped(): void
    {
        foreach ( [ [ 'per_page' => '0' ], [ 'per_page' => '51' ], [ 'page' => 'abc' ], [ 'on_sale' => 'yes' ], [ 'status' => 'trash' ] ] as $bad ) {
            $r = $this->restList( $bad );
            $this->assertInstanceOf( \WP_Error::class, $r );
            $this->assertSame( 400, $r->data['status'], json_encode( $bad ) );
        }
        foreach ( [ [ 'per_page' => 51 ], [ 'on_sale' => 'true' ], [ 'page' => '2' ], [ 'status' => 'trash' ] ] as $bad ) {
            $e = $this->mcpCall( 'list_products', $bad );
            $this->assertSame( -32602, $e['error']['code'], json_encode( $bad ) );
        }
        foreach ( [ '5', 5.5, true, [ 5 ], 0, -1, null ] as $badId ) {
            $e = $this->mcpCall( 'get_product', [ 'id' => $badId ] );
            $this->assertSame( -32602, $e['error']['code'], 'id ' . json_encode( $badId ) );
        }
    }

    public function testAnEditorWithoutProductPermissionsGetsAClearRefusal(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'edit_post' => true, 'edit_products' => false, 'edit_others_products' => false ];
        $r = $this->restList( [] );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 403, $r->data['status'] );
        $this->assertStringContainsString( 'edit_others_products', $r->get_error_message() );
        $e = $this->mcpCall( 'list_products', [] );
        $this->assertSame( -32602, $e['error']['code'] );
        $this->assertStringContainsString( 'edit_others_products', $e['error']['message'] );
    }

    public function testMissingVariationAndNotEditableAllAnswerAsNotFound(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'edit_products' => true, 'edit_others_products' => true, 'edit_post' => true, 'edit_post:3' => false ];
        $GLOBALS['__wc_products'][9] = $this->product( 9, [ 'post_type' => 'product_variation', 'type' => 'variation' ] );
        $messages = [];
        foreach ( [ '999', '9', '3' ] as $id ) {
            $r = $this->restGet( [ 'id' => $id ] );
            $this->assertInstanceOf( \WP_Error::class, $r );
            $this->assertSame( 404, $r->data['status'], "product {$id}" );
            $e = $this->mcpCall( 'get_product', [ 'id' => (int) $id ] );
            $this->assertSame( -32602, $e['error']['code'] );
            $this->assertSame( "Product {$id} not found.", $e['error']['message'] );
            $messages[] = str_replace( $id, 'N', $r->get_error_message() );
        }
        $this->assertCount( 1, array_unique( $messages ) );
    }

    public function testKeyUserWithoutEditPagesIsRefusedBeforeAnythingElse(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => false, 'edit_products' => true, 'edit_others_products' => true ];
        $this->assertSame( 403, $this->restList( [] )->data['status'] );
        $this->assertSame( 403, $this->restGet( [ 'id' => '1' ] )->data['status'] );
        $this->assertSame( -32602, $this->mcpCall( 'list_products', [] )['error']['code'] );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `make test 2>&1 | grep -B1 -A5 "ProductsLockstep" | head -20`
Expected: failures such as `Call to undefined method ... list_products()` and the tool declaration assertions.

- [ ] **Step 3: Register the classes in the autoloader**

In `wp-plugin/src/autoload.php`, after the line `require_once __DIR__ . '/FieldTarget.php';` add:

```php
require_once __DIR__ . '/ProductReader.php';
require_once __DIR__ . '/ProductQuery.php';
require_once __DIR__ . '/ProductGateway.php';
require_once __DIR__ . '/WooProductGateway.php';
require_once __DIR__ . '/ProductService.php';
```

- [ ] **Step 4: Add the two MCP tool definitions and the dispatch lines**

In `wp-plugin/src/McpHandler.php`, directly after the closing `],` of the `get_custom_fields` tool definition (the one ending with `'required'   => ['id'],` followed by `],`), insert:

```php
            [
                'name'        => 'list_products',
                'description' => 'List and count the WooCommerce products of this site (read-only): totals by status and by type, and one light row per product (id, name, type, status, SKU, current, regular and sale price, on-sale flag, stock status and quantity, categories, link). Use it to answer store questions ("how many products do I have?") and before writing a shop page or product copy, so names, prices and categories are real. Filters: status, type, category (slug), stock_status, on_sale, search, orderby (title, date, price), order, page, per_page (1 to 50, default 25). Needs the edit_products and edit_others_products permissions. Never returns downloadable files, purchase notes, customer or order data, or custom fields. If WooCommerce is not active it says so. Use get_product for one product in full.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'       => ['type' => 'string', 'enum' => ['any', 'publish', 'draft', 'pending', 'private', 'future'], 'description' => 'Optional. Default any (never trash).'],
                        'type'         => ['type' => 'string', 'enum' => ['simple', 'variable', 'grouped', 'external'], 'description' => 'Optional. Product type.'],
                        'category'     => ['type' => 'string', 'description' => 'Optional. Product category slug.'],
                        'stock_status' => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder'], 'description' => 'Optional.'],
                        'on_sale'      => ['type' => 'boolean', 'description' => 'Optional. true = only products on sale, false = only products not on sale.'],
                        'search'       => ['type' => 'string', 'description' => 'Optional. Words in the product name or text (at most 100 characters).'],
                        'orderby'      => ['type' => 'string', 'enum' => ['title', 'date', 'price'], 'description' => 'Optional. Default title.'],
                        'order'        => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'Optional. Default asc.'],
                        'page'         => ['type' => 'integer', 'description' => 'Optional. Default 1.'],
                        'per_page'     => ['type' => 'integer', 'description' => 'Optional. 1 to 50, default 25.'],
                    ],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_product',
                'description' => 'Read ONE WooCommerce product in full (read-only): its text, price, stock, categories, tags, attributes, images, dimensions, tax and shipping settings and, for a variable product, every variation with its own price, stock and attributes (grouped products list their child ids, external products their link). Never returns downloadable files, the purchase note, custom fields, or the text of a password-protected product. Get ids from list_products. Needs permission to edit that product.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'WooCommerce product ID (not a variation id)']],
                    'required'   => ['id'],
                ],
            ],
```

In the same file, in the `match ($name)` dispatch, after the line `'get_custom_fields'  => $this->toolGetCustomFields($id, $arguments),` add:

```php
            'list_products'      => $this->toolListProducts($id, $arguments),
            'get_product'        => $this->toolGetProduct($id, $arguments),
```

- [ ] **Step 5: Add the MCP handler methods**

In `McpHandler.php`, directly after the `toolGetCustomFields` method, add:

```php
    private function toolListProducts(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('list_products', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read products.');
        }

        return $this->productResult($id, 'list_products', null, ProductService::list($args, false, new WooProductGateway(), 'current_user_can'));
    }

    private function toolGetProduct(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('get_product', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read products.');
        }
        $productId = $args['id'] ?? null;
        if (!is_int($productId) || $productId < 1) {
            return $this->rpcError($id, -32602, 'id must be a positive integer.');
        }

        return $this->productResult($id, 'get_product', $productId, ProductService::get($productId, new WooProductGateway(), 'current_user_can'));
    }

    /** @param array<string,mixed> $r a ProductService result */
    private function productResult(mixed $id, string $endpoint, ?int $postId, array $r): WP_REST_Response
    {
        UsageTracker::log($endpoint, $postId, $r['ok'] ? UsageTracker::RESULT_READ : UsageTracker::RESULT_ERROR);
        if (!$r['ok']) {
            return $this->rpcError($id, 'server' === $r['kind'] ? -32603 : -32602, (string) $r['message']);
        }

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($r['data'])]],
        ]);
    }
```

- [ ] **Step 6: Add the REST routes and handlers**

In `wp-plugin/src/RestController.php`, directly after the `/posts/(?P<id>\d+)/custom-fields` route registration, add:

```php
        // GET /products — read-only list and counts of WooCommerce products
        register_rest_route(self::NS, '/products', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'list_products'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // GET /products/{id} — read-only detail of one WooCommerce product
        register_rest_route(self::NS, '/products/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_product'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);
```

and directly after the `custom_fields` method add:

```php
    public function list_products(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('list_products', null, 'error');
            return new WP_Error('forbidden', 'You do not have permission to read products.', ['status' => 403]);
        }
        $input = [];
        foreach (['status', 'type', 'category', 'stock_status', 'on_sale', 'search', 'orderby', 'order', 'page', 'per_page'] as $key) {
            $value = $request->get_param($key);
            if ($value !== null) {
                $input[$key] = $value;
            }
        }

        return $this->productResponse('list_products', null, ProductService::list($input, true, new WooProductGateway(), 'current_user_can'));
    }

    public function get_product(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('get_product', null, 'error');
            return new WP_Error('forbidden', 'You do not have permission to read products.', ['status' => 403]);
        }
        $productId = (int) $request->get_param('id');

        return $this->productResponse('get_product', $productId ?: null, ProductService::get($productId, new WooProductGateway(), 'current_user_can'));
    }

    /** @param array<string,mixed> $r a ProductService result */
    private function productResponse(string $endpoint, ?int $postId, array $r): WP_REST_Response|WP_Error
    {
        UsageTracker::log($endpoint, $postId, $r['ok'] ? UsageTracker::RESULT_READ : UsageTracker::RESULT_ERROR);
        if ($r['ok']) {
            return new WP_REST_Response($r['data'], 200);
        }
        $map = ['forbidden' => [403, 'forbidden'], 'invalid' => [400, 'invalid_field'], 'not_found' => [404, 'not_found'], 'server' => [500, 'server_error']];
        [$status, $code] = $map[$r['kind']] ?? [500, 'server_error'];

        return new WP_Error($code, (string) $r['message'], ['status' => $status]);
    }
```

- [ ] **Step 7: Run to verify everything passes**

Run: `make test 2>&1 | tail -6`
Expected: `OK`. If `WpOrgComplianceTest` now fails on the tool list or `(18 tools)` that is expected and is fixed in Task 6; to keep this commit green, temporarily skip nothing: instead make the Task 6 compliance edits for the tool list now if that test is the only failure, or finish Task 6 step 3 first. (The expected failures are exactly `testToolList...` and the readme `(18 tools)` assertion.)

- [ ] **Step 8: Commit**

```bash
git add wp-plugin/src/autoload.php wp-plugin/src/McpHandler.php wp-plugin/src/RestController.php tests/ProductsLockstepTest.php
git commit -m "feat: list_products and get_product over MCP and REST (read-only, not in the ChatGPT spec)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `count` per content type in `get_site_structure`

**Files:**
- Modify: `wp-plugin/src/SiteStructure.php` (`readPostTypes()` and the `post_types` mapping in `catalogWith()`)
- Modify tests: `tests/SiteStructureTest.php:28,29,82,268,278`, `tests/SiteStructureLockstepTest.php`

**Interfaces:**
- Consumes: WordPress `wp_count_posts()` (guarded by `function_exists`).
- Produces: every `post_types[]` entry gains `count` (int): published items (`inherit` for the `attachment` type).

- [ ] **Step 1: Write the failing test changes**

In `tests/SiteStructureTest.php` change the two exact-shape assertions to expect `count`. Line 82 becomes:

```php
        $this->assertSame( [ 'name' => 'book', 'label' => 'Books', 'supports' => [ 'title' ], 'taxonomies' => [ 'genre' ], 'has_archive' => true, 'hierarchical' => false, 'count' => 12 ], $d['post_types'][1] );
```

and in the reader fixture on lines 28 and 29 add a `'count'` key (`'page' => [ ..., 'hierarchical' => true, 'count' => 5 ]` and `'book' => [ ..., 'hierarchical' => false, 'count' => 12 ]`). Add this new test to the same class:

```php
    public function testEveryPostTypeCarriesAnIntegerCountAndAMissingOneIsZero(): void
    {
        $d = SiteStructure::catalogWith( [ 'post_types' => static fn (): array => [
            'book' => [ 'label' => 'Books', 'count' => '12' ],
            'note' => [ 'label' => 'Notes' ],
        ] ], [ 'post_types' ] );
        $this->assertSame( 12, $d['post_types'][0]['count'] );
        $this->assertSame( 0, $d['post_types'][1]['count'] );
    }
```

For the real-reader test at line 268-278, extend the shims: in the test that sets `$GLOBALS['__ss_post_types']`, also define `wp_count_posts` through a global (add to `tests/SiteStructureShims.php`, guarded):

```php
if ( ! function_exists( 'wp_count_posts' ) ) {
    function wp_count_posts( $type = 'post' ) { return (object) ( $GLOBALS['__ss_counts'][ $type ] ?? [] ); }
}
```

and set `$GLOBALS['__ss_counts'] = [ 'book' => [ 'publish' => 7, 'draft' => 2 ], 'attachment' => [ 'inherit' => 30 ] ];` in that test, then assert line 278's entry now ends with `'hierarchical' => false, 'count' => 7 ]`, plus a new assertion that an `attachment` post type with `'inherit' => 30` reports `count` 30 (add an `attachment` entry to `__ss_post_types`).

If `tests/WooShims.php` and `tests/SiteStructureShims.php` are both loaded in one run, the first definition of `wp_count_posts` wins; both read different globals. To avoid the clash make `WooShims.php`'s version fall back: change its body to `return (object) ( $GLOBALS['__ss_counts'][ $type ] ?? $GLOBALS['__wc_counts'] ?? [] );`.

- [ ] **Step 2: Run to verify it fails**

Run: `make test 2>&1 | grep -A8 "SiteStructure" | head -30`
Expected: assertion failures about the missing `count` key.

- [ ] **Step 3: Implement**

In `wp-plugin/src/SiteStructure.php`, in `readPostTypes()` replace the inner array build with:

```php
            $counts       = function_exists( 'wp_count_posts' ) ? (array) wp_count_posts( $name ) : [];
            $statusKey    = 'attachment' === $name ? 'inherit' : 'publish';
            $out[ $name ] = [
                'label'        => (string) ( $type->label ?? $name ),
                'supports'     => function_exists( 'get_all_post_type_supports' ) ? array_keys( array_filter( (array) get_all_post_type_supports( $name ) ) ) : [],
                'taxonomies'   => function_exists( 'get_object_taxonomies' ) ? array_values( (array) get_object_taxonomies( $name ) ) : [],
                'has_archive'  => (bool) ( $type->has_archive ?? false ),
                'hierarchical' => (bool) ( $type->hierarchical ?? false ),
                'count'        => (int) ( $counts[ $statusKey ] ?? 0 ),
            ];
```

and in `catalogWith()`, in the `post_types` mapping closure add after the `'hierarchical'` line:

```php
                'count'        => max( 0, (int) ( $d['count'] ?? 0 ) ),
```

Also update the tool description sentence in `McpHandler.php` for `get_site_structure`: after "public post types," insert "each with how many items it has (published),". (No other description change.)

- [ ] **Step 4: Run to verify everything passes**

Run: `make test 2>&1 | tail -4`
Expected: `OK` except the two known Task 6 compliance failures if Task 6 is not done yet (the SiteStructure tests themselves must pass). If `tests/SiteStructureLockstepTest.php` asserts an exact entry, add `'count'` the same way.

- [ ] **Step 5: Commit**

```bash
git add wp-plugin/src/SiteStructure.php wp-plugin/src/McpHandler.php tests/SiteStructureTest.php tests/SiteStructureShims.php tests/SiteStructureLockstepTest.php tests/WooShims.php
git commit -m "feat: get_site_structure reports how many items each content type has

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Compliance, guide, admin, readme and version 4.9.0

**Files:**
- Modify: `tests/WpOrgComplianceTest.php`, `tests/WpOrgNamingTest.php`, `tests/StyleGuideTest.php`
- Modify: `wp-plugin/src/StyleGuide.php`, `wp-plugin/src/AdminPage.php`, `wp-plugin/readme.txt`, `wp-plugin/jhmg-ai-editor-for-divi-5.php`
- Modify: `docs/superpowers/specs/2026-10-13-woocommerce-products-read-design.md` (record the on_sale finding)

**Interfaces:**
- Consumes: the two tool names (Task 4).
- Produces: a 20-tool compliance list, a write-call ban for WooCommerce, version 4.9.0.

- [ ] **Step 1: Update `WpOrgComplianceTest` (tool list, count, readme claim) and add the write ban**

In `tests/WpOrgComplianceTest.php` replace the sorted tool list assertion (lines ~377-382) with the 20 tools and update the count and the comment:

```php
        $this->assertSame([
            'create_page', 'edit_page_content', 'get_custom_fields', 'get_image_guide', 'get_landing_guide', 'get_page_history_entry',
            'get_page_layout', 'get_product', 'get_section_recipes', 'get_site_guide', 'get_site_structure', 'get_style_guide', 'list_divi_pages',
            'list_global_styles', 'list_media_images', 'list_page_history', 'list_products', 'restore_page_version', 'update_page_layout', 'validate_layout',
        ], $tools);
        // 20 tools: 4.9.0 adds the READ-ONLY list_products and get_product (WooCommerce catalogue). Nothing is gated and nothing writes; no rule of this test is loosened.
        $this->assertCount(20, $tools);
```

Change the readme assertion near line 578 to `'(20 tools)'`. Add this new test method to the class:

```php
    public function testTheFreePluginNeverWritesWooCommerceData(): void
    {
        $banned = '/\b(wc_update_product(_stock|_lookup_tables)?|wc_create_product|wc_delete_product|wc_update_product_stock|set_(regular_price|sale_price|price|stock_quantity|stock_status|manage_stock|backorders|sku|short_description)\s*\(|new\s+WC_Product(_[A-Za-z]+)?\s*\()/';
        $hits   = [];
        foreach ($this->files() as $rel => $src) {
            if (str_starts_with($rel, 'validator/')) {
                continue;
            }
            if (preg_match($banned, $src, $m)) {
                $hits[] = "$rel ({$m[0]})";
            }
        }
        $this->assertSame([], $hits, 'the free plugin only READS WooCommerce: writing a price or stock level belongs to the Pro add-on');
    }
```

(`$this->files()` is the helper the sibling tests already use; it returns relative path => source for the plugin tree.)

- [ ] **Step 2: Update the version pins**

In `tests/WpOrgNamingTest.php` change `4\.8\.0` to `4\.9\.0` on the `Version:` regex (line ~44), the `AI_EDITOR_DIVI5_VERSION` string (line ~45), and the `Stable tag` regex (line ~76). In `wp-plugin/jhmg-ai-editor-for-divi-5.php` set ` * Version:           4.9.0` and `define('AI_EDITOR_DIVI5_VERSION', '4.9.0');`. In `wp-plugin/readme.txt` set `Stable tag:        4.9.0`.

- [ ] **Step 3: Write the failing guide test, then the guide paragraph**

Add to `tests/StyleGuideTest.php`:

```php
    public function testTheGuideTellsTheAiToReadTheRealCatalogueBeforeWritingShopCopy(): void
    {
        $g = StyleGuide::markdown();
        $this->assertStringContainsString( '`list_products`', $g );
        $this->assertStringContainsString( '`get_product`', $g );
        $this->assertStringContainsString( 'never invent a product', $g );
    }
```

(Use the same class import and call style as the neighbouring tests in that file.) Run it, see it fail, then in `wp-plugin/src/StyleGuide.php` add, immediately after the paragraph that mentions `get_custom_fields` (search for the line containing `` `get_custom_fields` `` near line 308), a new paragraph:

```
If the site sells products with WooCommerce, call `list_products` before you write a shop page or product copy: it returns the
real product names, prices, stock and categories (and how many products there are), and `get_product` gives one product in
full, including its variations. Use those values; never invent a product, a price or a category. These tools only read: you
cannot change a product from here.
```

- [ ] **Step 4: Add the Features card and fix the comment in `AdminPage.php`**

In `wp-plugin/src/AdminPage.php` change the comment `the 18 tools` to `the 20 tools` and add this entry to `$features`, right after the `Fit your site (post types, image sizes, ACF)` entry:

```php
            [ __( 'Know your WooCommerce products', 'jhmg-ai-editor-for-divi-5' ), __( 'Your AI can list and count your WooCommerce products with their prices, stock and categories (read-only), so shop pages and product copy use real data. It never changes a product.', 'jhmg-ai-editor-for-divi-5' ), 'list_products, get_product' ],
```

If `tests/AdminMarkupTest.php` (or similar) pins the number of feature cards or the tool count, update that number in the same step; run `make test` to find out.

- [ ] **Step 5: Readme: tool list, FAQ, changelog**

In `wp-plugin/readme.txt`: change `= What your AI assistant can do (18 tools) =` to `(20 tools)`; after the `get_custom_fields` bullet add:

```
* `list_products`: list and count your WooCommerce products with their prices, stock status and categories (read-only; needs the permission to edit products; available over MCP and the REST API, not in the ChatGPT Action).
* `get_product`: read one WooCommerce product in full, including its variations (read-only; never downloadable files, purchase notes or custom fields).
```

In the FAQ, add a question `= Does it work with WooCommerce? =` with the answer: `Yes, for reading. Your assistant can list and count your products and read one product in full (name, description, price, stock, categories, variations), so it can answer store questions and write shop pages that match your catalogue. It never changes a product: prices and stock are not touched by this plugin.` Add a new top changelog entry:

```
= 4.9.0 =
* New: your AI assistant can now see your WooCommerce products. `list_products` lists and counts them with their prices, stock status and categories, and `get_product` reads one product in full, including its variations. Both are read-only: they never show downloadable files, purchase notes, customer or order data, or the text of a password-protected product, and they need the permission to edit products (shop managers and administrators have it). Available over MCP and the REST API, not in the ChatGPT Action.
* Improved: `get_site_structure` now also says how many items each content type has, so your assistant can answer "how many products (or pages, or posts) do I have?".
```

- [ ] **Step 6: Record the on_sale finding in the spec**

In `docs/superpowers/specs/2026-10-13-woocommerce-products-read-design.md`, under "Open points", add: `- WooCommerce's wc_get_products() ignores an on_sale argument (checked on 11.1.2 with the dev site's 18 products: 7 are on sale, the argument returned all 18), so the on_sale filter scans at most 500 matching products in PHP and says so in notes when it was cut.` and under section 2 "Totals" no change.

- [ ] **Step 7: Run everything**

Run: `make test 2>&1 | tail -6`
Expected: `OK` (the full free suite, including `WpOrgComplianceTest` with 20 tools, the new write-ban test, `WpOrgNamingTest`, `StyleGuideTest`). Run `bash scripts/build-plugin-zip.sh` and confirm `unzip -l jhmg-ai-editor-for-divi-5.zip | grep -c "Product"` is 5 (the five new source files).

- [ ] **Step 8: Commit**

```bash
git add tests/WpOrgComplianceTest.php tests/WpOrgNamingTest.php tests/StyleGuideTest.php wp-plugin/src/StyleGuide.php wp-plugin/src/AdminPage.php wp-plugin/readme.txt wp-plugin/jhmg-ai-editor-for-divi-5.php docs/superpowers/specs/2026-10-13-woocommerce-products-read-design.md
git commit -m "release prep: 20 tools, WooCommerce write ban, guide, Features card, readme and 4.9.0

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Real-site gate `make verify-products` and the final build

**Files:**
- Create: `scripts/verify-products.php`, `scripts/verify-products-inactive.php`
- Modify: `Makefile`, `CLAUDE.md`, the tracked zip (`jhmg-ai-editor-for-divi-5.zip`)

**Interfaces:**
- Consumes: the real REST routes and MCP handler on the Docker dev site (WooCommerce 11.1.2, 18 products), `scripts/dev-guard.php` (`aied_require_dev_site()`).
- Produces: a self-cleaning real-site check; the rebuilt 4.9.0 zip.

- [ ] **Step 1: Write `scripts/verify-products.php`**

Model it on `scripts/verify-custom-fields.php` (same `$check`, `$rest`, `$mcp` helpers; copy those helper closures verbatim from that file's lines 26-52). The script must:

1. `require_once __DIR__ . '/dev-guard.php'; aied_require_dev_site();` and stop with `WP_CLI::error` unless `function_exists( 'wc_get_products' )` and `class_exists( \AiEditorDivi5\WP\ProductService::class )`.
2. Remove a stale throwaway user `aied_prod_editor` and any product whose SKU starts with `AIED-VERIFY-` (force delete).
3. Take a SNAPSHOT of every existing product (id, name, status, regular, sale, stock status, stock quantity, `post_modified_gmt`, `post_password`) as `md5( serialize( ... ) )`.
4. Create three throwaways with the WooCommerce CRUD classes (dev script, not plugin code): a draft simple product `AIED-VERIFY-DRAFT`; a published product `AIED-VERIFY-LOCKED` with `post_password` `pw` and description `AIED-SECRET-DESC`; a published downloadable product `AIED-VERIFY-DOWNLOAD` with a download file `https://example.test/AIED-SECRET-DOWNLOAD.zip` and purchase note `AIED-SECRET-PURCHASE-NOTE`.
5. Through the REAL routes (`$rest( 'GET', '/products' )`) as user 1, `$check` that: status 200; `totals.all` equals `wp_count_posts('product')` publish+draft+pending+private+future; `totals.by_status.publish` equals the direct count; `totals.published_by_type` equals a direct `wc_get_products` count per type (the open point: the two must agree); `per_page=50` returns every product the site has (compare id sets); filters: `status=draft` returns exactly the draft throwaway, `type=variable` returns only variable ids, `category=<slug of a real category>` matches `wc_get_products(['category'=>...])`, `search=AIED-VERIFY` returns the three throwaways, `on_sale=true` returns exactly the ids whose `is_on_sale()` is true (compare with a direct loop), `orderby=price&order=desc` is non-increasing in `price`; `per_page=51` is 400, `page=abc` is 400, `status=trash` is 400, a `page` past the end is 200 with an empty list.
6. `get_product` through REST for: a simple product (fields equal `wc_get_product`), a VARIABLE product (every child id appears in `variations`, taxonomy attribute options are names not numeric ids), the GROUPED product (`children` equals `get_children()`), the EXTERNAL product (`external.product_url` equals WooCommerce's), the LOCKED throwaway (`password_protected` true, `description` null, no `AIED-SECRET-DESC` anywhere), a VARIATION id (404 identical to a missing id 99999999), and a blog post id (404).
7. Privacy sweep: `json_encode` of EVERY response above (list with `per_page=50`, every `get_product`) must not contain `AIED-SECRET-DOWNLOAD`, `AIED-SECRET-PURCHASE-NOTE`, `AIED-SECRET-DESC`, `purchase_note` or `_downloadable_files`.
8. MCP parity: for one list call and one `get_product` call the decoded MCP text equals the REST data.
9. Permissions: create user `aied_prod_editor` with role `editor` (has `edit_pages`, not `edit_products`); with that user active, REST list is 403 naming `edit_others_products`, and `get_product` of a real product is 404. Switch back to user 1.
10. `get_site_structure` through REST: `post_types` entry `product` has `count` equal to the published product count and `page` has a `count` equal to `wp_count_posts('page')->publish`.
11. In a `finally`-style cleanup that always runs: force-delete the three throwaways, delete the throwaway user, then recompute the SNAPSHOT of the original products and `$check` it is byte-identical (proof nothing was written), and `$check` the throwaways are gone.
12. End with `WP_CLI::error( 'products NOT verified: ' . implode( '; ', $failures ) )` when any failure, else `WP_CLI::success( 'list_products and get_product verified on the real site.' )`.

- [ ] **Step 2: Write `scripts/verify-products-inactive.php`**

```php
<?php
/**
 * Runs in a SECOND wp-cli process while WooCommerce is deactivated (see the Makefile target): the free plugin must answer
 * "WooCommerce is not active" as a normal success, over REST and MCP, and must not fatal.
 */

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

if ( function_exists( 'wc_get_products' ) ) {
    WP_CLI::error( 'WooCommerce is still active in this process; the Makefile target deactivates it first.' );
}
wp_set_current_user( 1 );
$req = new WP_REST_Request( 'GET', '/ai-editor-divi5/v1/products' );
$res = rest_do_request( $req );
$d   = (array) $res->get_data();
$ok  = 200 === $res->get_status() && false === ( $d['woocommerce']['active'] ?? null ) && [] === ( $d['products'] ?? null ) && ! empty( $d['message'] );
WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . 'list_products answers "not active" as a normal success' );
$req = new WP_REST_Request( 'GET', '/ai-editor-divi5/v1/products/1' );
$res = rest_do_request( $req );
$d2  = (array) $res->get_data();
$ok2 = 200 === $res->get_status() && false === ( $d2['woocommerce']['active'] ?? null ) && null === ( $d2['product'] ?? 'x' );
WP_CLI::log( ( $ok2 ? 'PASS  ' : 'FAIL  ' ) . 'get_product answers "not active" as a normal success' );
if ( ! $ok || ! $ok2 ) {
    WP_CLI::error( 'inactive-WooCommerce behaviour NOT verified.' );
}
WP_CLI::success( 'The plugin is quiet and correct without WooCommerce.' );
```

- [ ] **Step 3: Add the Makefile target**

Append `verify-products` to the `.PHONY` line and add (tab-indented recipe lines):

```make
# ---------------------------------------------------------------
# verify-products — real-site check of list_products / get_product on the dev site's WooCommerce products (throwaway products and a user, always deleted; the existing products must be byte-identical afterwards), then the same tools with WooCommerce deactivated (always reactivated)
# ---------------------------------------------------------------
verify-products:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-products.php --user=1
	@set -e; \
	trap 'docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin activate woocommerce >/dev/null 2>&1' EXIT; \
	docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin deactivate woocommerce >/dev/null 2>&1; \
	docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-products-inactive.php --user=1
```

- [ ] **Step 4: Run the gate until it passes**

Run: `make verify-products 2>&1 | tail -60`
Expected: every line `PASS`, ending with both `Success:` lines; then `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin list --format=table | grep woocommerce` shows `active`. Any `FAIL` is a real finding: fix the code (a failing set-comparison between `published_by_type` and the direct count means switching `publishedTypeCounts()` to a counted query, as the spec's open point says), add a unit test that reproduces it, and rerun. Do not edit the gate to hide a failure.

- [ ] **Step 5: Final build, docs and commit**

Run `make test` (expect `OK`), `bash scripts/build-plugin-zip.sh`, and confirm `unzip -p jhmg-ai-editor-for-divi-5.zip jhmg-ai-editor-for-divi-5/readme.txt | grep "Stable tag"` shows 4.9.0. In `CLAUDE.md`: add `make verify-products` to the entry-points table, change the tool count text (`18 MCP tools` to `20 MCP tools`, naming `list_products` and `get_product`), and add one bullet under "Current state": `Unreleased (branch feat/woocommerce-products-read): free 4.9.0 read-only WooCommerce products (list_products, get_product, counts in get_site_structure); spec/plan docs/superpowers/specs|plans/2026-10-13-woocommerce-products-read*; Pro writes (update_products, bulk_adjust_prices, create_product) are sub-project B, not started.`

```bash
git add scripts/verify-products.php scripts/verify-products-inactive.php Makefile CLAUDE.md jhmg-ai-editor-for-divi-5.zip
git commit -m "test: make verify-products, a real-site gate for list_products and get_product; CLAUDE.md and the 4.9.0 zip

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

The WordPress.org upload and the divi5lab.com copy are release steps for the owner's go-ahead (same routine as 4.8.0), not part of this plan.

---

## Self-Review (done against the spec)

- **Spec coverage:** tools and inputs (Tasks 2, 3, 4); output shapes and caps (Task 1); privacy list (Tasks 1, 2, 4, 7); permissions (Task 3, 4); inactive and throwing WooCommerce (Task 3, 7 inactive script); totals incl. `published_by_type` honesty (Tasks 1, 2, 7); `get_site_structure` count (Task 5); guide, readme, admin card, version (Task 6); compliance 20 tools + write ban (Task 6); live gate incl. byte-identical proof (Task 7); not in OpenAPI (Task 4 test). The `on_sale` finding is recorded in the spec (Task 6 step 6).
- **Placeholders:** none; the one "choose" in Task 2 step 5 (the date-order smoke assertion) states both options and the exact replacement line.
- **Type consistency:** `ProductGateway` method names and the `$raw` keys match between `WooProductGateway` (Task 2), `ProductReader` (Task 1) and `FakeGateway` (Task 3); `ProductService::list/get` result shape (`ok`, `kind`, `message`, `data`) is consumed unchanged by `productResult` (MCP) and `productResponse` (REST); `ProductQuery::parse` returns `args`/`error` as `ProductService` reads them; `WooProductGateway::MAX_SCAN` is read by `ProductService` for the note.
- **Review Focus coverage:** inactive/throwing (Task 3 tests, Task 7 inactive); password and download leaks (Task 1, 2, 4, 7); variation id NOT_FOUND (Task 3, 4, 7); on_sale paging and cap (Task 2, 3); out-of-range input (Task 2, 4, 7); editor without permissions (Task 3, 4, 7).
