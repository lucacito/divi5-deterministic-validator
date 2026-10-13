# Pro `create_product` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the Pro tool `create_product`: create up to 25 simple WooCommerce products as drafts in one undoable batch (MCP + REST only).

**Architecture:** A pure `ProductValuePolicy` validates and normalises each product; a `ProductWriter` interface is the only door to WooCommerce (real `WooProductWriter`, a fake in tests); `CreateProduct` (shaped like `SetCustomFields`) orchestrates all-or-nothing validation, creation, read-back verification, rollback and one batch; `ProductCreatedKind` / `ProductTrashedKind` (mirroring `PageCreatedKind` / `PageTrashedKind`) make the batch undoable.

**Tech Stack:** PHP 8.1, PHPUnit (`make test-pro`), WooCommerce's CRUD classes (`WC_Product_Simple`), the add-on's batch/undo machinery.

**Spec:** `docs/superpowers/specs/2026-10-13-pro-create-product-design.md`

## Global Constraints

- Everything is in `pro-addon/` (plus `scripts/`, `Makefile`, `CLAUDE.md`, the spec/plan docs). The free plugin (`wp-plugin/`, `src/`) is NOT changed; `Compat::MIN_FREE` stays `4.8.0`.
- Namespace `AiEditorDivi5\Pro\...`; each new PHP file starts `<?php`, `declare(strict_types=1);`, the `ABSPATH` guard (`if ( ! defined( 'ABSPATH' ) ) { exit; }`) and uses WordPress-style spacing inside parentheses, as the neighbouring Pro files do. PHP 8.1 only: no standalone `null` return types, no readonly classes.
- Products are ALWAYS created as drafts; nothing is published; no existing product is ever modified; the only deletion is of a product the same call created seconds earlier (rollback).
- No URL arguments anywhere; an image is an existing Media Library attachment id the user may read. No custom code in any text (reuse `FieldValuePolicy::refusal()` plus the "`wp_kses_post()` must not change it" rule, with `&amp;` normalised as in `SetCustomFields`).
- Unknown keys in a product object are refused by name; a wrong argument type is refused, never cast (`Args::*`, strict `is_bool` / `is_int`).
- Limits: 1 to 25 products per call; name 1-200 chars; description 10,000; short description 1,000; SKU 1-100 printable ASCII; categories 10; tags 20; gallery images 10; stock quantity 0 to 1,000,000; price format `^\d{1,10}(\.\d{1,6})?$`.
- The refusal message is cut at 6,000 characters with a trailing `...` (as `SetCustomFields` does).
- Registry capability is `edit_posts`; inside the tool: WooCommerce active, then `edit_products`, plus `assign_product_terms` when categories or tags are given.
- The add-on has two source-scanning tripwires you must not trip: `tests/NoPriceLiteralsTest.php` fails if `pro-addon/src/**.php`, `readme.txt` or `README.md` contain the standalone numbers `49`, `99`, `149` or `449` (use other example values; the limits above are fine); `tests/ProSafetyTest.php` bans `eval`, the exec family, `unserialize`, user writes, plugin management, Divi preset storage, remote HTTP calls and any `http(s)://` URL literal inside a PHP string in `src/` (build links with `admin_url()`).
- `tests/ReadmeTest.php` requires every tool in `Tools::all()` to be listed in `pro-addon/readme.txt`.
- The tool is MCP + REST only: it joins `ProOpenApi::MCP_REST_ONLY` (the ChatGPT importer's 30 operations are used up); a test pins that list on purpose, so the pin changes with it.
- Pro version becomes **0.10.0** (plugin header, `AIED_PRO_VERSION`, readme `Stable tag`, the test bootstrap constant, changelog).

## Review Focus

- Partial creation: a refused product, a failing save (Nth product) or a read-back mismatch must leave NO product behind and NO batch recorded; a rollback that cannot delete a product must name it (Task 3 tests).
- Stock/price combinations a reasonable owner types: `stock_quantity` without `manage_stock`, `stock_status` with `manage_stock: true`, sale price equal to or above the regular price, `"0"` price, `"10.50"` vs `"10.5"` comparisons, a price like `"1,000"` or `"$5"` (Task 1 tests).
- A duplicate SKU in the same call, a SKU that already exists, SKU case differences (Task 3 tests).
- Unknown category/tag slug, a non-image or unreadable attachment id, and a user missing `assign_product_terms` (Task 3 tests).
- Undo after the owner edited, published or trashed a created product: skipped and reported, never trashed behind their back; `EMPTY_TRASH_DAYS` 0 never deletes a changed product (Task 2 tests).
- WooCommerce inactive: a clear refusal, never a PHP fatal; an exception message from WooCommerce must not leak a server path (Task 3 tests).

---

## File Structure

- Create `pro-addon/src/Products/ProductValuePolicy.php` (pure rules), `ProductWriter.php` (interface), `WooProductWriter.php` (the only WooCommerce-facing class).
- Create `pro-addon/src/Kinds/ProductCreatedKind.php`, `ProductTrashedKind.php`.
- Create `pro-addon/src/Tools/CreateProduct.php`.
- Modify `pro-addon/src/autoload.php`, `src/Tools.php`, `src/Batch/Batches.php`, `src/Rest/ProOpenApi.php`, `src/Compat.php` is NOT modified.
- Modify `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`, `readme.txt`, `README.md`, `tests/bootstrap.php`, `tests/ToolsTest.php`, `tests/ProOpenApiTest.php` (and any other existing Pro test the suite shows pinning the tool list or MCP-only list).
- Create tests: `tests/ProductValuePolicyTest.php`, `tests/FakeProductWriter.php`, `tests/ProductKindsTest.php`, `tests/CreateProductTest.php`.
- Create `scripts/verify-pro-create-product.php`; modify `Makefile`, `CLAUDE.md`.

---

### Task 1: `ProductValuePolicy` (pure rules)

**Files:**
- Create: `pro-addon/src/Products/ProductValuePolicy.php`
- Test: `pro-addon/tests/ProductValuePolicyTest.php`

**Interfaces:**
- Consumes: `AiEditorDivi5\Pro\Fields\FieldValuePolicy::refusal( string ): ?string` (exists).
- Produces: `ProductValuePolicy::check( array $product, \Closure $kses, \Closure $stripTags ): array{values: ?array<string,mixed>, reasons: list<string>}` and `ProductValuePolicy::compareDecimals( string $a, string $b ): int` (-1, 0, 1), plus constants `KEYS`, `MAX_NAME`, etc. On success `values` has exactly these keys: `name, description, short_description, sku, regular_price, sale_price, manage_stock, stock_quantity, stock_status, backorders, category_slugs, tag_slugs, image_id, gallery_image_ids, weight, length, width, height, tax_status, virtual, sold_individually` (strings are `''` when absent, `stock_quantity` is `?int`, `image_id` is `0` when absent, lists are `[]` when absent).

- [ ] **Step 1: Write the failing test**

Create `pro-addon/tests/ProductValuePolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Products\ProductValuePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class ProductValuePolicyTest extends TestCase
{
    private \Closure $kses;
    private \Closure $strip;

    protected function setUp(): void
    {
        // A kses that drops <script> and rewrites a bare ampersand like core, and a strip-tags like core's.
        $this->kses  = static fn ( string $s ): string => (string) preg_replace( '/&(?!#?\w+;)/', '&amp;', (string) preg_replace( '#<\s*script\b.*?>.*?<\s*/\s*script\s*>#is', '', $s ) );
        $this->strip = static fn ( string $s ): string => strip_tags( $s );
    }

    /** @return array{values: ?array<string,mixed>, reasons: list<string>} */
    private function check( array $p ): array
    {
        return ProductValuePolicy::check( $p, $this->kses, $this->strip );
    }

    private function ok( array $p ): array
    {
        $r = $this->check( $p );
        $this->assertSame( [], $r['reasons'], json_encode( $r['reasons'] ) );
        $this->assertIsArray( $r['values'] );

        return $r['values'];
    }

    private function refusedWith( array $p, string $needle ): void
    {
        $r = $this->check( $p );
        $this->assertNull( $r['values'] );
        $this->assertStringContainsString( $needle, implode( ' | ', $r['reasons'] ) );
    }

    public function testAMinimalProductGetsDefaultsForEverythingElse(): void
    {
        $v = $this->ok( [ 'name' => 'Blue Hat' ] );
        $this->assertSame( [
            'name' => 'Blue Hat', 'description' => '', 'short_description' => '', 'sku' => '', 'regular_price' => '', 'sale_price' => '',
            'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock', 'backorders' => 'no',
            'category_slugs' => [], 'tag_slugs' => [], 'image_id' => 0, 'gallery_image_ids' => [],
            'weight' => '', 'length' => '', 'width' => '', 'height' => '', 'tax_status' => 'taxable', 'virtual' => false, 'sold_individually' => false,
        ], $v );
    }

    public function testAFullProductIsAccepted(): void
    {
        $v = $this->ok( [
            'name' => 'Blue Hat', 'description' => '<p>Warm &amp; soft</p>', 'short_description' => 'Warm', 'sku' => 'HAT-1',
            'regular_price' => '20.50', 'sale_price' => '15', 'manage_stock' => true, 'stock_quantity' => 12, 'backorders' => 'notify',
            'categories' => [ 'shirts' ], 'tags' => [ 'sale' ], 'image_id' => 90, 'gallery_image_ids' => [ 91, 92 ],
            'weight' => '0.4', 'dimensions' => [ 'length' => '10', 'width' => '5', 'height' => '2.5' ], 'tax_status' => 'none', 'virtual' => true, 'sold_individually' => true,
        ] );
        $this->assertSame( 12, $v['stock_quantity'] );
        $this->assertSame( 'notify', $v['backorders'] );
        $this->assertSame( [ 'shirts' ], $v['category_slugs'] );
        $this->assertSame( '2.5', $v['height'] );
        $this->assertSame( [ 91, 92 ], $v['gallery_image_ids'] );
    }

    public static function badProducts(): array
    {
        return [
            'no name'               => [ [], '"name" is required' ],
            'empty name'            => [ [ 'name' => '  ' ], '"name" is required' ],
            'name not a string'     => [ [ 'name' => 5 ], '"name"' ],
            'name with tags'        => [ [ 'name' => 'Hat <b>x</b>' ], 'plain text' ],
            'name control char'     => [ [ 'name' => "Hat\x07" ], 'control characters' ],
            'name too long'         => [ [ 'name' => str_repeat( 'a', 201 ) ], '200 characters' ],
            'unknown key'           => [ [ 'name' => 'x', 'slug' => 'x' ], '"slug" is not a supported field' ],
            'status key'            => [ [ 'name' => 'x', 'status' => 'publish' ], '"status" is not a supported field' ],
            'script description'    => [ [ 'name' => 'x', 'description' => '<script>alert(1)</script>' ], 'description' ],
            'js url description'    => [ [ 'name' => 'x', 'description' => '<a href="javascript:alert(1)">x</a>' ], 'description' ],
            'kses would change'     => [ [ 'name' => 'x', 'description' => 'ok <script>x</script>' ], 'description' ],
            'short too long'        => [ [ 'name' => 'x', 'short_description' => str_repeat( 'a', 1001 ) ], 'short_description' ],
            'description too long'  => [ [ 'name' => 'x', 'description' => str_repeat( 'a', 10001 ) ], 'description' ],
            'sku too long'          => [ [ 'name' => 'x', 'sku' => str_repeat( 'a', 101 ) ], '"sku"' ],
            'sku non ascii'         => [ [ 'name' => 'x', 'sku' => "h\u{e9}" ], '"sku"' ],
            'sku padded'            => [ [ 'name' => 'x', 'sku' => ' A1' ], '"sku"' ],
            'price symbol'          => [ [ 'name' => 'x', 'regular_price' => '$5' ], '"regular_price"' ],
            'price comma'           => [ [ 'name' => 'x', 'regular_price' => '1,000' ], '"regular_price"' ],
            'price number'          => [ [ 'name' => 'x', 'regular_price' => 5 ], '"regular_price"' ],
            'price negative'        => [ [ 'name' => 'x', 'regular_price' => '-5' ], '"regular_price"' ],
            'price too many digits' => [ [ 'name' => 'x', 'regular_price' => '12345678901' ], '"regular_price"' ],
            'sale without regular'  => [ [ 'name' => 'x', 'sale_price' => '5' ], 'regular_price' ],
            'sale equals regular'   => [ [ 'name' => 'x', 'regular_price' => '10', 'sale_price' => '10.0' ], 'lower' ],
            'sale above regular'    => [ [ 'name' => 'x', 'regular_price' => '10', 'sale_price' => '11' ], 'lower' ],
            'qty without manage'    => [ [ 'name' => 'x', 'stock_quantity' => 5 ], 'manage_stock' ],
            'manage without qty'    => [ [ 'name' => 'x', 'manage_stock' => true ], '"stock_quantity"' ],
            'qty float'             => [ [ 'name' => 'x', 'manage_stock' => true, 'stock_quantity' => 1.5 ], '"stock_quantity"' ],
            'qty negative'          => [ [ 'name' => 'x', 'manage_stock' => true, 'stock_quantity' => -1 ], '"stock_quantity"' ],
            'qty too big'           => [ [ 'name' => 'x', 'manage_stock' => true, 'stock_quantity' => 1000001 ], '"stock_quantity"' ],
            'manage as string'      => [ [ 'name' => 'x', 'manage_stock' => 'yes' ], '"manage_stock"' ],
            'status with manage'    => [ [ 'name' => 'x', 'manage_stock' => true, 'stock_quantity' => 1, 'stock_status' => 'instock' ], '"stock_status"' ],
            'bad status'            => [ [ 'name' => 'x', 'stock_status' => 'maybe' ], '"stock_status"' ],
            'backorders no manage'  => [ [ 'name' => 'x', 'backorders' => 'yes' ], '"backorders"' ],
            'bad backorders'        => [ [ 'name' => 'x', 'manage_stock' => true, 'stock_quantity' => 1, 'backorders' => 'sometimes' ], '"backorders"' ],
            'categories not list'   => [ [ 'name' => 'x', 'categories' => 'shirts' ], '"categories"' ],
            'category bad slug'     => [ [ 'name' => 'x', 'categories' => [ 'a b' ] ], '"categories"' ],
            'category duplicate'    => [ [ 'name' => 'x', 'categories' => [ 'a', 'a' ] ], '"categories"' ],
            'too many categories'   => [ [ 'name' => 'x', 'categories' => array_map( fn ( $i ) => "c{$i}", range( 1, 11 ) ) ], '"categories"' ],
            'too many tags'         => [ [ 'name' => 'x', 'tags' => array_map( fn ( $i ) => "t{$i}", range( 1, 21 ) ) ], '"tags"' ],
            'image as url'          => [ [ 'name' => 'x', 'image_id' => 'https://example.test/a.png' ], '"image_id"' ],
            'image zero'            => [ [ 'name' => 'x', 'image_id' => 0 ], '"image_id"' ],
            'gallery not ints'      => [ [ 'name' => 'x', 'gallery_image_ids' => [ 'a' ] ], '"gallery_image_ids"' ],
            'gallery too many'      => [ [ 'name' => 'x', 'gallery_image_ids' => range( 1, 11 ) ], '"gallery_image_ids"' ],
            'dimensions list'       => [ [ 'name' => 'x', 'dimensions' => [ '1', '2' ] ], '"dimensions"' ],
            'dimensions bad key'    => [ [ 'name' => 'x', 'dimensions' => [ 'depth' => '1' ] ], '"dimensions"' ],
            'dimension not decimal' => [ [ 'name' => 'x', 'dimensions' => [ 'length' => 'long' ] ], '"dimensions"' ],
            'weight bad'            => [ [ 'name' => 'x', 'weight' => '2kg' ], '"weight"' ],
            'tax status bad'        => [ [ 'name' => 'x', 'tax_status' => 'free' ], '"tax_status"' ],
            'virtual string'        => [ [ 'name' => 'x', 'virtual' => 'true' ], '"virtual"' ],
            'sold individually int' => [ [ 'name' => 'x', 'sold_individually' => 1 ], '"sold_individually"' ],
        ];
    }

    #[DataProvider( 'badProducts' )]
    public function testBadInputIsRefusedWithAReason( array $product, string $needle ): void
    {
        $this->refusedWith( $product, $needle );
    }

    public function testEveryReasonIsReportedNotJustTheFirst(): void
    {
        $r = $this->check( [ 'name' => '', 'regular_price' => 'x', 'sku' => str_repeat( 'a', 200 ), 'bogus' => 1 ] );
        $this->assertNull( $r['values'] );
        $this->assertGreaterThanOrEqual( 4, count( $r['reasons'] ) );
    }

    public function testNullMeansNotGiven(): void
    {
        $v = $this->ok( [ 'name' => 'x', 'sku' => null, 'regular_price' => null, 'manage_stock' => null, 'categories' => null ] );
        $this->assertSame( '', $v['sku'] );
        $this->assertFalse( $v['manage_stock'] );
    }

    public function testZeroPriceAndASaleBelowARegularPriceAreFine(): void
    {
        $this->assertSame( '0', $this->ok( [ 'name' => 'Free', 'regular_price' => '0' ] )['regular_price'] );
        $this->assertSame( '9.999999', $this->ok( [ 'name' => 'x', 'regular_price' => '10', 'sale_price' => '9.999999' ] )['sale_price'] );
    }

    public static function decimalPairs(): array
    {
        return [ [ '10', '10.0', 0 ], [ '10.50', '10.5', 0 ], [ '9', '10', -1 ], [ '10', '9', 1 ], [ '0.1', '0.01', 1 ], [ '0099', '99', 0 ], [ '1000000', '999999.999999', 1 ], [ '0', '0.000001', -1 ] ];
    }

    #[DataProvider( 'decimalPairs' )]
    public function testCompareDecimalsIsExactNotFloat( string $a, string $b, int $expected ): void
    {
        $this->assertSame( $expected, ProductValuePolicy::compareDecimals( $a, $b ) );
        $this->assertSame( -$expected, ProductValuePolicy::compareDecimals( $b, $a ) );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `make test-pro 2>&1 | grep -B1 -A5 "ProductValuePolicy" | head -20`
Expected: error `Class "AiEditorDivi5\Pro\Products\ProductValuePolicy" not found` (the test file requires the autoload, which does not list the new file yet).

- [ ] **Step 3: Implement the policy and register it in the autoloader**

Create `pro-addon/src/Products/ProductValuePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

use AiEditorDivi5\Pro\Fields\FieldValuePolicy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * What one new simple product may contain. Pure: no WordPress or WooCommerce call (the strip-tags and kses checks are passed in as
 * closures). It returns the normalised values or EVERY reason the product is refused. Checks that need the site (a taken SKU,
 * whether a category exists, whether an image may be used, capabilities) belong to CreateProduct.
 */
final class ProductValuePolicy
{
    public const KEYS = [
        'name', 'description', 'short_description', 'sku', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status',
        'backorders', 'categories', 'tags', 'image_id', 'gallery_image_ids', 'weight', 'dimensions', 'tax_status', 'virtual', 'sold_individually',
    ];

    public const MAX_NAME        = 200;
    public const MAX_DESCRIPTION = 10000;
    public const MAX_SHORT       = 1000;
    public const MAX_CATEGORIES  = 10;
    public const MAX_TAGS        = 20;
    public const MAX_GALLERY     = 10;
    public const MAX_STOCK       = 1000000;

    private const DECIMAL = '/^\d{1,10}(\.\d{1,6})?$/';
    private const SLUG    = '/^[A-Za-z0-9%_-]{1,200}$/';
    private const STOCK_STATUSES = [ 'instock', 'outofstock', 'onbackorder' ];
    private const BACKORDERS     = [ 'no', 'notify', 'yes' ];
    private const TAX_STATUSES   = [ 'taxable', 'shipping', 'none' ];

    /**
     * @param array<string,mixed> $product
     * @param \Closure(string):string $kses wp_kses_post
     * @param \Closure(string):string $stripTags wp_strip_all_tags
     * @return array{values: ?array<string,mixed>, reasons: list<string>}
     */
    public static function check( array $product, \Closure $kses, \Closure $stripTags ): array
    {
        $r = [];
        $v = [];

        foreach ( array_keys( $product ) as $key ) {
            if ( ! is_string( $key ) || ! in_array( $key, self::KEYS, true ) ) {
                $r[] = '"' . self::shown( (string) $key ) . '" is not a supported field';
            }
        }

        // name
        $name = $product['name'] ?? null;
        $v['name'] = '';
        if ( ! is_string( $name ) || '' === trim( $name ) ) {
            $r[] = '"name" is required and must be a non-empty string';
        } elseif ( 1 !== preg_match( '//u', $name ) ) {
            $r[] = '"name" is not valid UTF-8';
        } elseif ( mb_strlen( $name ) > self::MAX_NAME ) {
            $r[] = '"name" is longer than 200 characters';
        } elseif ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
            $r[] = '"name" must not contain control characters';
        } elseif ( $stripTags( $name ) !== $name ) {
            $r[] = '"name" must be plain text (no HTML tags)';
        } else {
            $v['name'] = $name;
        }

        $v['description']       = self::text( $product, 'description', self::MAX_DESCRIPTION, $kses, $r );
        $v['short_description'] = self::text( $product, 'short_description', self::MAX_SHORT, $kses, $r );

        // sku
        $v['sku'] = '';
        if ( self::given( $product, 'sku' ) ) {
            $sku = $product['sku'];
            if ( ! is_string( $sku ) || 1 !== preg_match( '/^[\x20-\x7E]{1,100}$/', $sku ) || trim( $sku ) !== $sku ) {
                $r[] = '"sku" must be 1 to 100 printable ASCII characters without leading or trailing spaces';
            } else {
                $v['sku'] = $sku;
            }
        }

        $v['regular_price'] = self::decimal( $product, 'regular_price', $r );
        $v['sale_price']    = self::decimal( $product, 'sale_price', $r );
        if ( '' !== $v['sale_price'] ) {
            if ( '' === $v['regular_price'] ) {
                $r[] = '"sale_price" needs a "regular_price"';
            } elseif ( self::compareDecimals( $v['sale_price'], $v['regular_price'] ) >= 0 ) {
                $r[] = '"sale_price" must be lower than "regular_price"';
            }
        }

        // stock
        $manage = false;
        if ( self::given( $product, 'manage_stock' ) ) {
            if ( is_bool( $product['manage_stock'] ) ) {
                $manage = $product['manage_stock'];
            } else {
                $r[] = '"manage_stock" must be true or false';
            }
        }
        $v['manage_stock']   = $manage;
        $v['stock_quantity'] = null;
        $v['stock_status']   = 'instock';
        $v['backorders']     = 'no';
        if ( $manage ) {
            $qty = $product['stock_quantity'] ?? null;
            if ( ! is_int( $qty ) || $qty < 0 || $qty > self::MAX_STOCK ) {
                $r[] = '"stock_quantity" is required when manage_stock is true: a whole number from 0 to 1,000,000';
            } else {
                $v['stock_quantity'] = $qty;
            }
            if ( self::given( $product, 'stock_status' ) ) {
                $r[] = '"stock_status" cannot be set when manage_stock is true (WooCommerce works it out from the quantity)';
            }
            $v['backorders'] = self::enum( $product, 'backorders', self::BACKORDERS, 'no', $r );
        } else {
            if ( self::given( $product, 'stock_quantity' ) ) {
                $r[] = '"stock_quantity" needs "manage_stock": true';
            }
            if ( self::given( $product, 'backorders' ) ) {
                $r[] = '"backorders" needs "manage_stock": true';
            }
            $v['stock_status'] = self::enum( $product, 'stock_status', self::STOCK_STATUSES, 'instock', $r );
        }

        $v['category_slugs'] = self::slugs( $product, 'categories', self::MAX_CATEGORIES, $r );
        $v['tag_slugs']      = self::slugs( $product, 'tags', self::MAX_TAGS, $r );

        // images
        $v['image_id'] = 0;
        if ( self::given( $product, 'image_id' ) ) {
            if ( is_int( $product['image_id'] ) && $product['image_id'] >= 1 ) {
                $v['image_id'] = $product['image_id'];
            } else {
                $r[] = '"image_id" must be the id (a whole number from 1) of an image in the Media Library';
            }
        }
        $v['gallery_image_ids'] = [];
        if ( self::given( $product, 'gallery_image_ids' ) ) {
            $g = $product['gallery_image_ids'];
            if ( ! is_array( $g ) || ! array_is_list( $g ) || count( $g ) > self::MAX_GALLERY || count( $g ) !== count( array_unique( $g, SORT_REGULAR ) ) ) {
                $r[] = '"gallery_image_ids" must be a list of at most 10 different image ids';
            } else {
                foreach ( $g as $id ) {
                    if ( ! is_int( $id ) || $id < 1 ) {
                        $r[] = '"gallery_image_ids" must contain only whole image ids from 1';
                        break;
                    }
                    $v['gallery_image_ids'][] = $id;
                }
            }
        }

        $v['weight'] = self::decimal( $product, 'weight', $r );
        $v['length'] = '';
        $v['width']  = '';
        $v['height'] = '';
        if ( self::given( $product, 'dimensions' ) ) {
            $d = $product['dimensions'];
            if ( ! is_array( $d ) || array_is_list( $d ) || [] !== array_diff( array_keys( $d ), [ 'length', 'width', 'height' ] ) ) {
                $r[] = '"dimensions" must be an object with only "length", "width" and "height"';
            } else {
                foreach ( [ 'length', 'width', 'height' ] as $axis ) {
                    if ( ! array_key_exists( $axis, $d ) || null === $d[ $axis ] ) {
                        continue;
                    }
                    if ( is_string( $d[ $axis ] ) && 1 === preg_match( self::DECIMAL, $d[ $axis ] ) ) {
                        $v[ $axis ] = $d[ $axis ];
                    } else {
                        $r[] = "\"dimensions\" {$axis} must be a decimal string such as \"2.5\"";
                    }
                }
            }
        }

        $v['tax_status'] = self::enum( $product, 'tax_status', self::TAX_STATUSES, 'taxable', $r );
        $v['virtual']           = self::flag( $product, 'virtual', $r );
        $v['sold_individually'] = self::flag( $product, 'sold_individually', $r );

        return [ 'values' => [] === $r ? $v : null, 'reasons' => $r ];
    }

    /** Exact comparison of two decimal strings that match DECIMAL (no float rounding). @return int -1, 0 or 1 */
    public static function compareDecimals( string $a, string $b ): int
    {
        [ $ai, $af ] = array_pad( explode( '.', $a, 2 ), 2, '' );
        [ $bi, $bf ] = array_pad( explode( '.', $b, 2 ), 2, '' );
        $ai = ltrim( $ai, '0' );
        $bi = ltrim( $bi, '0' );
        if ( strlen( $ai ) !== strlen( $bi ) ) {
            return strlen( $ai ) <=> strlen( $bi );
        }
        if ( $ai !== $bi ) {
            return strcmp( $ai, $bi ) <=> 0;
        }
        $len = max( strlen( $af ), strlen( $bf ) );

        return strcmp( str_pad( $af, $len, '0' ), str_pad( $bf, $len, '0' ) ) <=> 0;
    }

    /** @param array<string,mixed> $p */
    private static function given( array $p, string $key ): bool
    {
        return array_key_exists( $key, $p ) && null !== $p[ $key ];
    }

    /** @param array<string,mixed> $p @param list<string> $r */
    private static function text( array $p, string $key, int $max, \Closure $kses, array &$r ): string
    {
        if ( ! self::given( $p, $key ) ) {
            return '';
        }
        $value = $p[ $key ];
        if ( ! is_string( $value ) ) {
            $r[] = "\"{$key}\" must be a string";

            return '';
        }
        if ( mb_strlen( $value ) > $max ) {
            $r[] = "\"{$key}\" is longer than " . number_format( $max ) . ' characters';

            return '';
        }
        $why = FieldValuePolicy::refusal( $value );
        if ( null !== $why ) {
            $r[] = "\"{$key}\" {$why}";

            return '';
        }
        // Core's wp_kses_post() rewrites a bare "&" to "&amp;"; that is not unsafe markup, so both sides are compared with it undone.
        $norm = static fn ( string $s ): string => str_replace( '&amp;', '&', $s );
        if ( $norm( $kses( $value ) ) !== $norm( $value ) ) {
            $r[] = "\"{$key}\" contains markup WordPress does not allow in post content";

            return '';
        }

        return $value;
    }

    /** @param array<string,mixed> $p @param list<string> $r */
    private static function decimal( array $p, string $key, array &$r ): string
    {
        if ( ! self::given( $p, $key ) ) {
            return '';
        }
        if ( is_string( $p[ $key ] ) && 1 === preg_match( self::DECIMAL, $p[ $key ] ) ) {
            return $p[ $key ];
        }
        $r[] = "\"{$key}\" must be a decimal string such as \"20\" or \"20.50\" (no currency symbol or thousands separator)";

        return '';
    }

    /** @param array<string,mixed> $p @param list<string> $allowed @param list<string> $r */
    private static function enum( array $p, string $key, array $allowed, string $default, array &$r ): string
    {
        if ( ! self::given( $p, $key ) ) {
            return $default;
        }
        if ( is_string( $p[ $key ] ) && in_array( $p[ $key ], $allowed, true ) ) {
            return $p[ $key ];
        }
        $r[] = "\"{$key}\" must be one of: " . implode( ', ', $allowed );

        return $default;
    }

    /** @param array<string,mixed> $p @param list<string> $r */
    private static function flag( array $p, string $key, array &$r ): bool
    {
        if ( ! self::given( $p, $key ) ) {
            return false;
        }
        if ( is_bool( $p[ $key ] ) ) {
            return $p[ $key ];
        }
        $r[] = "\"{$key}\" must be true or false";

        return false;
    }

    /**
     * @param array<string,mixed> $p @param list<string> $r
     * @return list<string>
     */
    private static function slugs( array $p, string $key, int $max, array &$r ): array
    {
        if ( ! self::given( $p, $key ) ) {
            return [];
        }
        $list = $p[ $key ];
        if ( ! is_array( $list ) || ! array_is_list( $list ) || count( $list ) > $max || count( $list ) !== count( array_unique( $list, SORT_REGULAR ) ) ) {
            $r[] = "\"{$key}\" must be a list of at most {$max} different slugs";

            return [];
        }
        foreach ( $list as $slug ) {
            if ( ! is_string( $slug ) || 1 !== preg_match( self::SLUG, $slug ) ) {
                $r[] = "\"{$key}\" must contain only slugs (letters, digits, \"-\", \"_\" or \"%\")";

                return [];
            }
        }

        return array_values( $list );
    }

    private static function shown( string $key ): string
    {
        return mb_strlen( $key ) > 80 ? mb_substr( $key, 0, 80 ) . '...' : $key;
    }
}
```

In `pro-addon/src/autoload.php`, directly after the line `require_once __DIR__ . '/Fields/FieldValuePolicy.php';` (find it with `grep -n "FieldValuePolicy" pro-addon/src/autoload.php`) add:

```php
require_once __DIR__ . '/Products/ProductValuePolicy.php';
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `make test-pro 2>&1 | tail -4`
Expected: `OK` (the whole Pro suite, including `ProductValuePolicyTest` and `NoPriceLiteralsTest`: the policy file contains no standalone 49/99/149/449).

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src/Products/ProductValuePolicy.php pro-addon/src/autoload.php pro-addon/tests/ProductValuePolicyTest.php
git commit -m "feat(pro): ProductValuePolicy, the pure rules for a new simple product

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `ProductWriter`, `WooProductWriter` and the two batch kinds

**Files:**
- Create: `pro-addon/src/Products/ProductWriter.php`, `pro-addon/src/Products/WooProductWriter.php`, `pro-addon/src/Kinds/ProductCreatedKind.php`, `pro-addon/src/Kinds/ProductTrashedKind.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Batch/Batches.php`, `pro-addon/tests/ToolsTest.php` (the registered-kinds list)
- Create: `pro-addon/tests/FakeProductWriter.php`
- Test: `pro-addon/tests/ProductKindsTest.php`

**Interfaces:**
- Consumes: `Item::make`, `Canon::hash`, `ItemKind`, `BatchUndo`, `PageCreatedKind::trashEnabled()` (static, public: reused so there is ONE trash-setting rule and one test seam).
- Produces:
  - `interface ProductWriter { isActive(): bool; skuTaken( string $sku ): bool; termIds( string $taxonomy, array $slugs ): array{ids: list<int>, missing: list<string>}; imageUsable( int $id ): bool; create( array $values ): int; snapshot( int $id ): ?array; delete( int $id ): bool; editUrl( int $id ): string; }`. `create()` takes the normalised policy values PLUS `category_ids` and `tag_ids` (lists of int) and returns the new product id, or throws. `snapshot()` returns `null` unless the id is a SIMPLE product, else an array with keys `status` (post status) and `name, description, short_description, sku, regular_price, sale_price, manage_stock (bool), stock_quantity (?int), stock_status, backorders, category_ids (sorted ints), tag_ids (sorted ints), image_id (int), gallery_image_ids (list<int>), weight, length, width, height, tax_status, virtual (bool), sold_individually (bool)`.
  - `ProductCreatedKind` (name `product_created`): `FIELDS` constant, `hashed( array $snapshot ): array`, `itemFor( int $id, array $snapshot, string $name ): array`, constructor `( ?ProductWriter $writer = null )`.
  - `ProductTrashedKind` (name `product_trashed`), constructor `( ?ProductWriter $writer = null )`.
  - `FakeProductWriter` (test helper, namespace `AiEditorDivi5\Pro\Tests`): public props `active`, `skus` (taken SKUs), `terms` (`taxonomy => slug => id`), `images` (`id => bool`), `products` (`id => snapshot`), `next`, `failOnCreate` (1-based call number), `afterCreate` (`?\Closure( array &$snapshot, int $call )`), `deleted` (list of ids), `deleteFails`, `createCalls`, `created` (list of the `$values` passed to `create`).

- [ ] **Step 1: Write the failing kind test and the fake writer**

Create `pro-addon/tests/FakeProductWriter.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Products\ProductWriter;

/** A scripted store: no WooCommerce at all. Post status is read from $GLOBALS['__wp_posts'] when the post is there (so wp_trash_post shows). */
final class FakeProductWriter implements ProductWriter
{
    public bool $active = true;
    /** @var array<string,bool> */
    public array $skus = [];
    /** @var array<string,array<string,int>> */
    public array $terms = [ 'product_cat' => [ 'shirts' => 3, 'hats' => 4 ], 'product_tag' => [ 'sale' => 7 ] ];
    /** @var array<int,bool> */
    public array $images = [ 90 => true, 91 => true ];
    /** @var array<int,array<string,mixed>> */
    public array $products = [];
    public int $next = 100;
    public ?int $failOnCreate = null;
    public ?\Closure $afterCreate = null;
    /** @var list<int> */
    public array $deleted = [];
    public bool $deleteFails = false;
    public int $createCalls = 0;
    /** @var list<array<string,mixed>> */
    public array $created = [];

    public function isActive(): bool { return $this->active; }
    public function skuTaken( string $sku ): bool { return isset( $this->skus[ $sku ] ); }

    public function termIds( string $taxonomy, array $slugs ): array
    {
        $ids = [];
        $missing = [];
        foreach ( $slugs as $slug ) {
            if ( isset( $this->terms[ $taxonomy ][ $slug ] ) ) {
                $ids[] = $this->terms[ $taxonomy ][ $slug ];
            } else {
                $missing[] = $slug;
            }
        }

        return [ 'ids' => $ids, 'missing' => $missing ];
    }

    public function imageUsable( int $id ): bool { return $this->images[ $id ] ?? false; }

    public function create( array $values ): int
    {
        ++$this->createCalls;
        if ( $this->failOnCreate === $this->createCalls ) {
            throw new \Exception( 'boom: could not write /var/www/html/wp-content/secret.php' );
        }
        $id   = $this->next++;
        $snap = [
            'status' => 'draft', 'name' => $values['name'], 'description' => $values['description'], 'short_description' => $values['short_description'],
            'sku' => $values['sku'], 'regular_price' => $values['regular_price'], 'sale_price' => $values['sale_price'],
            'manage_stock' => $values['manage_stock'], 'stock_quantity' => $values['stock_quantity'], 'stock_status' => $values['manage_stock'] ? ( ( $values['stock_quantity'] ?? 0 ) > 0 ? 'instock' : 'outofstock' ) : $values['stock_status'],
            'backorders' => $values['backorders'], 'category_ids' => $this->sorted( $values['category_ids'] ), 'tag_ids' => $this->sorted( $values['tag_ids'] ),
            'image_id' => $values['image_id'], 'gallery_image_ids' => $values['gallery_image_ids'], 'weight' => $values['weight'],
            'length' => $values['length'], 'width' => $values['width'], 'height' => $values['height'], 'tax_status' => $values['tax_status'],
            'virtual' => $values['virtual'], 'sold_individually' => $values['sold_individually'],
        ];
        if ( null !== $this->afterCreate ) {
            ( $this->afterCreate )( $snap, $this->createCalls );
        }
        $this->products[ $id ] = $snap;
        $this->created[]       = $values;

        return $id;
    }

    public function snapshot( int $id ): ?array
    {
        $s = $this->products[ $id ] ?? null;
        if ( null === $s ) {
            return null;
        }
        if ( isset( $GLOBALS['__wp_posts'][ $id ] ) ) {
            $s['status'] = $GLOBALS['__wp_posts'][ $id ]->post_status;
        }

        return $s;
    }

    public function delete( int $id ): bool
    {
        if ( $this->deleteFails ) {
            return false;
        }
        $this->deleted[] = $id;
        unset( $this->products[ $id ] );

        return true;
    }

    public function editUrl( int $id ): string { return 'EDIT-' . $id; }

    /** @param list<int> $ids @return list<int> */
    private function sorted( array $ids ): array
    {
        sort( $ids );

        return array_values( $ids );
    }
}
```

Create `pro-addon/tests/ProductKindsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\PageCreatedKind;
use AiEditorDivi5\Pro\Kinds\ProductCreatedKind;
use AiEditorDivi5\Pro\Kinds\ProductTrashedKind;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';
require_once __DIR__ . '/FakeProductWriter.php';

/** A product create_product made, and how an undo takes it away again (and brings it back). */
class ProductKindsTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private FakeProductWriter $writer;

    protected function setUp(): void
    {
        $this->repo   = new InMemoryBatchRepository();
        $this->log    = new BatchLog( $this->repo );
        $this->writer = new FakeProductWriter();
        $this->writer->products[50] = $this->snapshot();
        $GLOBALS['__wp_posts'] = [ 50 => (object) [ 'ID' => 50, 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Blue Hat', 'post_content' => '', 'post_name' => 'blue-hat' ] ];
        $GLOBALS['__wp_caps']  = [ 'edit_post' => true, 'delete_post' => true ];
        $GLOBALS['__wp_trash_fail']   = false;
        $GLOBALS['__wp_untrash_fail'] = false;
        $GLOBALS['__wp_untrash_vanish'] = false;
        PageCreatedKind::$trashEnabled = null;
    }

    protected function tearDown(): void
    {
        PageCreatedKind::$trashEnabled = null;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_trash_fail'], $GLOBALS['__wp_untrash_fail'], $GLOBALS['__wp_untrash_vanish'] );
    }

    /** @return array<string,mixed> */
    private function snapshot( array $over = [] ): array
    {
        return $over + [
            'status' => 'draft', 'name' => 'Blue Hat', 'description' => '<p>Warm</p>', 'short_description' => 'Warm', 'sku' => 'HAT-1',
            'regular_price' => '20', 'sale_price' => '', 'manage_stock' => true, 'stock_quantity' => 12, 'stock_status' => 'instock', 'backorders' => 'no',
            'category_ids' => [ 3 ], 'tag_ids' => [], 'image_id' => 0, 'gallery_image_ids' => [], 'weight' => '', 'length' => '', 'width' => '', 'height' => '',
            'tax_status' => 'taxable', 'virtual' => false, 'sold_individually' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function item(): array
    {
        return ProductCreatedKind::itemFor( 50, $this->snapshot(), 'Blue Hat' );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ 'product_created' => new ProductCreatedKind( $this->writer ), 'product_trashed' => new ProductTrashedKind( $this->writer ) ], $this->log );
    }

    public function testTheItemRecordsTheProductAndAHashOfWhatMattersForUndo(): void
    {
        $i = $this->item();
        $this->assertSame( [ 'product_created', '50', null, 'applied' ], [ $i['kind'], $i['ref'], $i['before'], $i['state'] ] );
        $this->assertStringContainsString( 'Blue Hat', $i['label'] );
        $this->assertStringContainsString( '#50', $i['label'] );
        $this->assertSame( [ 'available' => true ], $i['undo'] );
        $this->assertSame( Canon::hash( ProductCreatedKind::hashed( $this->snapshot() ) ), $i['after_hash'] );
        $this->assertSame( ProductCreatedKind::FIELDS, array_keys( ProductCreatedKind::hashed( $this->snapshot() ) ) );
    }

    public function testCurrentIsTheHashedSnapshotOfADraftAndNullOtherwise(): void
    {
        $kind = new ProductCreatedKind( $this->writer );
        $item = $this->item();
        $this->assertSame( $item['after_hash'], Canon::hash( $kind->current( $item ) ) );

        $this->writer->products[50]['regular_price'] = '25'; // the owner changed the price
        $this->assertNotSame( $item['after_hash'], Canon::hash( $kind->current( $item ) ) );

        $GLOBALS['__wp_posts'][50]->post_status = 'publish';
        $this->assertNull( $kind->current( $item ), 'a published product is not the draft the call left' );
        $GLOBALS['__wp_posts'][50]->post_status = 'draft';
        unset( $this->writer->products[50] );
        $this->assertNull( $kind->current( $item ), 'a gone (or not simple) product' );
    }

    public function testUndoTrashesAnUnchangedDraftAndTheInverseBringsItBackAsADraft(): void
    {
        $batch = $this->log->record( 'create_product', 'Created 1', [ $this->item() ] );
        $r     = $this->undo()->undo( (int) $batch['id'], false );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][50]->post_status );
        $this->assertSame( 1, $r['restored'] ?? $r['undone'] ?? 1 );

        $items = $this->repo->rows[ (int) $batch['id'] ]['items'] ?? [];
        $this->assertSame( 'product_trashed', $items[0]['kind'] ?? 'product_trashed' );
    }

    public function testAnEditedProductIsSkippedNotTrashed(): void
    {
        $batch = $this->log->record( 'create_product', 'Created 1', [ $this->item() ] );
        $this->writer->products[50]['name'] = 'Renamed by the owner';
        $this->undo()->undo( (int) $batch['id'], false );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][50]->post_status, 'changed since: not trashed' );
    }

    public function testAPublishedProductIsNeverTrashedNotEvenForced(): void
    {
        $batch = $this->log->record( 'create_product', 'Created 1', [ $this->item() ] );
        $GLOBALS['__wp_posts'][50]->post_status = 'publish';
        $this->undo()->undo( (int) $batch['id'], true );
        $this->assertSame( 'publish', $GLOBALS['__wp_posts'][50]->post_status );
    }

    public function testRestoreReasonsAreExplicit(): void
    {
        $kind = new ProductCreatedKind( $this->writer );
        $item = $this->item();

        $GLOBALS['__wp_posts'][50]->post_status = 'pending';
        $this->assertSame( [ 'ok' => false, 'reason' => 'not_draft' ], $kind->restore( $item ) );
        $GLOBALS['__wp_posts'][50]->post_status = 'draft';

        $GLOBALS['__wp_trash_fail'] = true;
        $this->assertSame( [ 'ok' => false, 'reason' => 'trash_failed' ], $kind->restore( $item ) );
        $GLOBALS['__wp_trash_fail'] = false;

        unset( $this->writer->products[50] );
        $this->assertSame( [ 'ok' => false, 'reason' => 'missing' ], $kind->restore( $item ) );
    }

    public function testWithTheTrashDisabledAChangedProductIsNeverDeletedPermanently(): void
    {
        PageCreatedKind::$trashEnabled = static fn (): bool => false;
        $kind = new ProductCreatedKind( $this->writer );
        $item = $this->item();
        $this->writer->products[50]['description'] = 'changed';
        $this->assertSame( [ 'ok' => false, 'reason' => 'would_delete_permanently' ], $kind->restore( $item ) );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][50]->post_status );

        $this->writer->products[50] = $this->snapshot(); // unchanged again: allowed, but the inverse has no undo point
        $r = $kind->restore( $item );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [ 'available' => false, 'reason' => 'trash_disabled' ], $r['inverse']['undo'] );
    }

    public function testThePerItemCapabilityIsCheckedAtUndoTime(): void
    {
        $kind = new ProductCreatedKind( $this->writer );
        $this->assertTrue( $kind->allowed( $this->item() ) );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'delete_post' => false ];
        $this->assertFalse( $kind->allowed( $this->item() ) );
        $this->assertFalse( ( new ProductTrashedKind( $this->writer ) )->allowed( $this->item() ) );
    }

    public function testTheTrashedKindUntrashesAsADraftAndReturnsAProductCreatedItem(): void
    {
        $created = new ProductCreatedKind( $this->writer );
        $r       = $created->restore( $this->item() );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'product_trashed', $r['inverse']['kind'] );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][50]->post_status );

        $trashed = new ProductTrashedKind( $this->writer );
        $this->assertSame( 'trash', $trashed->current( $r['inverse'] ) );
        $back = $trashed->restore( $r['inverse'] );
        $this->assertTrue( $back['ok'] );
        $this->assertSame( 'product_created', $back['inverse']['kind'] );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][50]->post_status );
        $this->assertSame( Canon::hash( ProductCreatedKind::hashed( $this->snapshot() ) ), $back['inverse']['after_hash'] );

        $GLOBALS['__wp_untrash_fail'] = true;
        $GLOBALS['__wp_posts'][50]->post_status = 'trash';
        $this->assertSame( [ 'ok' => false, 'reason' => 'untrash_failed' ], $trashed->restore( $r['inverse'] ) );
        unset( $this->writer->products[50] );
        $this->assertSame( [ 'ok' => false, 'reason' => 'missing' ], $trashed->restore( $r['inverse'] ) );
    }
}
```

NOTE for the implementer: `PageCreatedKindTest` is the model for how `BatchUndo` is driven (`undo( int $batchId, bool $force )`) and what its result array contains; the loose assertions in `testUndoTrashesAnUnchangedDraft...` (`$r['restored'] ?? ...`) must be replaced by the exact keys `BatchUndo::undo()` really returns (read `pro-addon/src/Batch/BatchUndo.php` and `tests/PageCreatedKindTest.php` and copy their assertions), keeping the same intent: the product ends up in the trash, the stored batch then holds a `product_trashed` inverse item.

- [ ] **Step 2: Run to verify it fails**

Run: `make test-pro 2>&1 | grep -B1 -A4 "ProductKinds" | head -20`
Expected: `Class "AiEditorDivi5\Pro\Kinds\ProductCreatedKind" not found` (and the missing interface).

- [ ] **Step 3: Implement the interface, the real writer and the kinds**

Create `pro-addon/src/Products/ProductWriter.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** What create_product and the product batch kinds need from a store. The real one is WooProductWriter; tests use a fake. */
interface ProductWriter
{
    public function isActive(): bool;

    public function skuTaken( string $sku ): bool;

    /**
     * @param list<string> $slugs
     * @return array{ids: list<int>, missing: list<string>}
     */
    public function termIds( string $taxonomy, array $slugs ): array;

    /** An existing Media Library image the current user may use. */
    public function imageUsable( int $id ): bool;

    /**
     * Create ONE simple product as a DRAFT and return its id; throw on any failure.
     *
     * @param array<string,mixed> $values the ProductValuePolicy values plus `category_ids` and `tag_ids` (lists of int)
     */
    public function create( array $values ): int;

    /**
     * @return ?array<string,mixed> null unless the id is a simple product; otherwise `status` (the post status) plus name, description,
     *         short_description, sku, regular_price, sale_price, manage_stock, stock_quantity, stock_status, backorders, category_ids (sorted),
     *         tag_ids (sorted), image_id, gallery_image_ids, weight, length, width, height, tax_status, virtual, sold_individually
     */
    public function snapshot( int $id ): ?array;

    /** Permanently delete (used only to roll back a product this same call just created). */
    public function delete( int $id ): bool;

    public function editUrl( int $id ): string;
}
```

Create `pro-addon/src/Products/WooProductWriter.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only class that calls WooCommerce, through its own CRUD classes and public functions. It creates drafts only and never touches
 * an existing product. Proven on the real site by `make verify-pro-create-product` (the unit tests use FakeProductWriter).
 */
final class WooProductWriter implements ProductWriter
{
    public function isActive(): bool
    {
        return class_exists( '\WC_Product_Simple' ) && function_exists( 'wc_get_product' ) && function_exists( 'wc_get_product_id_by_sku' );
    }

    public function skuTaken( string $sku ): bool
    {
        return (int) wc_get_product_id_by_sku( $sku ) > 0;
    }

    public function termIds( string $taxonomy, array $slugs ): array
    {
        $ids     = [];
        $missing = [];
        foreach ( $slugs as $slug ) {
            $term = get_term_by( 'slug', $slug, $taxonomy );
            if ( is_object( $term ) && isset( $term->term_id ) ) {
                $ids[] = (int) $term->term_id;
            } else {
                $missing[] = $slug;
            }
        }

        return [ 'ids' => $ids, 'missing' => $missing ];
    }

    public function imageUsable( int $id ): bool
    {
        $post = get_post( $id );

        return is_object( $post )
            && 'attachment' === $post->post_type
            && str_starts_with( (string) get_post_mime_type( $id ), 'image/' )
            && current_user_can( 'upload_files' )
            && current_user_can( 'read_post', $id );
    }

    public function create( array $values ): int
    {
        $p = new \WC_Product_Simple();
        $p->set_name( (string) $values['name'] );
        $p->set_status( 'draft' );
        $p->set_description( (string) $values['description'] );
        $p->set_short_description( (string) $values['short_description'] );
        if ( '' !== $values['sku'] ) {
            $p->set_sku( (string) $values['sku'] );
        }
        $p->set_regular_price( (string) $values['regular_price'] );
        $p->set_sale_price( (string) $values['sale_price'] );
        $p->set_manage_stock( (bool) $values['manage_stock'] );
        if ( $values['manage_stock'] ) {
            $p->set_stock_quantity( (int) $values['stock_quantity'] );
            $p->set_backorders( (string) $values['backorders'] );
        } else {
            $p->set_stock_status( (string) $values['stock_status'] );
        }
        $p->set_category_ids( array_map( 'intval', $values['category_ids'] ) );
        $p->set_tag_ids( array_map( 'intval', $values['tag_ids'] ) );
        if ( (int) $values['image_id'] > 0 ) {
            $p->set_image_id( (int) $values['image_id'] );
        }
        $p->set_gallery_image_ids( array_map( 'intval', $values['gallery_image_ids'] ) );
        $p->set_weight( (string) $values['weight'] );
        $p->set_length( (string) $values['length'] );
        $p->set_width( (string) $values['width'] );
        $p->set_height( (string) $values['height'] );
        $p->set_tax_status( (string) $values['tax_status'] );
        $p->set_virtual( (bool) $values['virtual'] );
        $p->set_sold_individually( (bool) $values['sold_individually'] );

        return (int) $p->save();
    }

    public function snapshot( int $id ): ?array
    {
        $p = wc_get_product( $id );
        if ( ! $p || 'simple' !== $p->get_type() || 'product' !== get_post_type( $id ) ) {
            return null;
        }
        $manage = (bool) $p->get_manage_stock();
        $dims   = (array) $p->get_dimensions( false );
        $cats   = array_map( 'intval', (array) $p->get_category_ids() );
        $tags   = array_map( 'intval', (array) $p->get_tag_ids() );
        sort( $cats );
        sort( $tags );
        $qty = $p->get_stock_quantity();

        return [
            'status'            => (string) $p->get_status(),
            'name'              => (string) $p->get_name(),
            'description'       => (string) $p->get_description(),
            'short_description' => (string) $p->get_short_description(),
            'sku'               => (string) $p->get_sku(),
            'regular_price'     => (string) $p->get_regular_price(),
            'sale_price'        => (string) $p->get_sale_price(),
            'manage_stock'      => $manage,
            'stock_quantity'    => $manage && null !== $qty ? (int) $qty : null,
            'stock_status'      => (string) $p->get_stock_status(),
            'backorders'        => (string) $p->get_backorders(),
            'category_ids'      => $cats,
            'tag_ids'           => $tags,
            'image_id'          => (int) $p->get_image_id(),
            'gallery_image_ids' => array_map( 'intval', (array) $p->get_gallery_image_ids() ),
            'weight'            => (string) $p->get_weight(),
            'length'            => (string) ( $dims['length'] ?? '' ),
            'width'             => (string) ( $dims['width'] ?? '' ),
            'height'            => (string) ( $dims['height'] ?? '' ),
            'tax_status'        => (string) $p->get_tax_status(),
            'virtual'           => (bool) $p->get_virtual(),
            'sold_individually' => (bool) $p->get_sold_individually(),
        ];
    }

    public function delete( int $id ): bool
    {
        return (bool) wp_delete_post( $id, true );
    }

    public function editUrl( int $id ): string
    {
        return (string) admin_url( 'post.php?post=' . $id . '&action=edit' );
    }
}
```

Create `pro-addon/src/Kinds/ProductCreatedKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A product create_product made. `ref` is the product id, `before` is null, `after_hash` is a hash of the FIELDS read back after the
 * save. Undo trashes the product, but only while it is still a DRAFT and still matches that hash (the changed-since rule), so a product
 * the owner edited, published or made private is skipped and reported, never trashed behind their back. With the trash disabled
 * (EMPTY_TRASH_DAYS 0) a trash is a permanent delete, so a changed product is never removed, forced or not (the same guard as
 * PageCreatedKind, whose trashEnabled() rule is reused). The inverse is a `product_trashed` item.
 */
final class ProductCreatedKind implements ItemKind
{
    public const NAME = 'product_created';

    /** What the undo compares: the values a call sets that an owner is likely to change. */
    public const FIELDS = [ 'name', 'description', 'short_description', 'sku', 'regular_price', 'sale_price', 'manage_stock', 'stock_quantity', 'stock_status', 'category_ids' ];

    public function __construct( private ?ProductWriter $writer = null )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function hashed( array $snapshot ): array
    {
        $out = [];
        foreach ( self::FIELDS as $field ) {
            $out[ $field ] = $snapshot[ $field ] ?? null;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public static function itemFor( int $id, array $snapshot, string $name ): array
    {
        return Item::make( self::NAME, (string) $id, "Created product \"{$name}\" (#{$id})", null, self::hashed( $snapshot ) );
    }

    public function current( array $item ): mixed
    {
        $s = $this->writer()->snapshot( (int) $item['ref'] );

        return null !== $s && 'draft' === $s['status'] ? self::hashed( $s ) : null;
    }

    public function allowed( array $item ): bool
    {
        $id = (int) $item['ref'];

        return current_user_can( 'edit_post', $id ) && current_user_can( 'delete_post', $id );
    }

    public function restore( array $item ): array
    {
        $id = (int) $item['ref'];
        $s  = $this->writer()->snapshot( $id );
        if ( null === $s ) {
            return [ 'ok' => false, 'reason' => 'missing' ];
        }
        // `force` skips the changed-since rule, never this one: a product that is live (or private, or pending) is not the draft the call left.
        if ( 'draft' !== $s['status'] ) {
            return [ 'ok' => false, 'reason' => 'not_draft' ];
        }
        if ( ! PageCreatedKind::trashEnabled() && Canon::hash( self::hashed( $s ) ) !== (string) ( $item['after_hash'] ?? '' ) ) {
            return [ 'ok' => false, 'reason' => 'would_delete_permanently' ];
        }
        if ( ! wp_trash_post( $id ) ) {
            return [ 'ok' => false, 'reason' => 'trash_failed' ];
        }
        $undo = PageCreatedKind::trashEnabled() ? null : [ 'available' => false, 'reason' => 'trash_disabled' ];

        return [ 'ok' => true, 'inverse' => Item::make( ProductTrashedKind::NAME, (string) $id, (string) $item['label'], null, 'trash', $undo ) ];
    }

    private function writer(): ProductWriter
    {
        return $this->writer ??= new WooProductWriter();
    }
}
```

Create `pro-addon/src/Kinds/ProductTrashedKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The inverse of an undone `product_created`: the product is in the trash. Undoing it takes the product out of the trash (core brings
 * it back as a DRAFT) and records a `product_created` item, so an undo can always be undone.
 */
final class ProductTrashedKind implements ItemKind
{
    public const NAME = 'product_trashed';

    public function __construct( private ?ProductWriter $writer = null )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function current( array $item ): mixed
    {
        $s = $this->writer()->snapshot( (int) $item['ref'] );

        return null !== $s ? (string) $s['status'] : null;
    }

    public function allowed( array $item ): bool
    {
        $id = (int) $item['ref'];

        return current_user_can( 'edit_post', $id ) && current_user_can( 'delete_post', $id );
    }

    public function restore( array $item ): array
    {
        $id = (int) $item['ref'];
        if ( null === $this->writer()->snapshot( $id ) ) {
            return [ 'ok' => false, 'reason' => 'missing' ];
        }
        if ( ! wp_untrash_post( $id ) ) {
            return [ 'ok' => false, 'reason' => 'untrash_failed' ];
        }
        $s = $this->writer()->snapshot( $id );
        if ( null === $s ) {
            // Untrashed, but gone again before it could be read: nothing to take away a second time.
            return [ 'ok' => true, 'inverse' => Item::make( ProductCreatedKind::NAME, (string) $id, (string) $item['label'], null, null, [ 'available' => false, 'reason' => 'missing' ] ) ];
        }

        return [ 'ok' => true, 'inverse' => ProductCreatedKind::itemFor( $id, $s, (string) $s['name'] ) ];
    }

    private function writer(): ProductWriter
    {
        return $this->writer ??= new WooProductWriter();
    }
}
```

In `pro-addon/src/autoload.php`, directly after the `Products/ProductValuePolicy.php` line added in Task 1, add:

```php
require_once __DIR__ . '/Products/ProductWriter.php';
require_once __DIR__ . '/Products/WooProductWriter.php';
```

and next to the other `Kinds/` requires (find `PageTrashedKind.php`) add:

```php
require_once __DIR__ . '/Kinds/ProductCreatedKind.php';
require_once __DIR__ . '/Kinds/ProductTrashedKind.php';
```

In `pro-addon/src/Batch/Batches.php` add `use AiEditorDivi5\Pro\Kinds\ProductCreatedKind;` and `use AiEditorDivi5\Pro\Kinds\ProductTrashedKind;` (alphabetical among the `use` lines) and, in `kinds()`, append after `new PostMetaKind(),`:

```php
            new ProductCreatedKind(),
            new ProductTrashedKind(),
```

In `pro-addon/tests/ToolsTest.php` (`testEveryKindARecordedItemUsesIsRegisteredForUndo`) add `'product_created', 'product_trashed'` to the expected kind list.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `make test-pro 2>&1 | tail -4`
Expected: `OK`. (`ProSafetyTest` also scans the new files: they contain no banned construct and no `http(s)://` literal.)

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src/Products pro-addon/src/Kinds/ProductCreatedKind.php pro-addon/src/Kinds/ProductTrashedKind.php pro-addon/src/autoload.php pro-addon/src/Batch/Batches.php pro-addon/tests/FakeProductWriter.php pro-addon/tests/ProductKindsTest.php pro-addon/tests/ToolsTest.php
git commit -m "feat(pro): ProductWriter, WooProductWriter and the product_created / product_trashed batch kinds

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The `CreateProduct` tool

**Files:**
- Create: `pro-addon/src/Tools/CreateProduct.php`
- Modify: `pro-addon/src/autoload.php`
- Test: `pro-addon/tests/CreateProductTest.php`

**Interfaces:**
- Consumes: `Args::bool`, `Args::listOf`, `BatchLog::record`, `BatchLog::attach`, `ProductValuePolicy::check` / `compareDecimals`, `ProductWriter`, `ProductCreatedKind::itemFor`, `ProTool`; `FakeProductWriter` (Task 2).
- Produces: `CreateProduct implements ProTool` (`NAME = 'create_product'`, `MAX_PRODUCTS = 25`), constructor `( BatchLog $log, ?ProductWriter $writer = null )`; `run()` returns, for a real run, `[ 'changed' => true, 'dry_run' => false, 'products' => list<{id, name, sku, status, edit_url}> ] + batch block (BatchLog::attach)`; for `dry_run`, `[ 'changed' => false, 'dry_run' => true, 'products' => list<normalised preview> ]`; refusals and failures throw `\InvalidArgumentException` with a client-safe message.

- [ ] **Step 1: Write the failing test**

Create `pro-addon/tests/CreateProductTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
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

    private function run( array $products, bool $dry = false ): array
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
        $r = $this->run( [ $this->hat(), $this->hat( [ 'name' => 'Red Scarf', 'sku' => 'SC-1', 'categories' => [ 'shirts' ], 'tags' => [ 'sale' ] ] ) ] );
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
        $r = $this->run( [ $this->hat() ], true );
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
        $r = $this->run( [ $this->hat( [ 'image_id' => 90, 'gallery_image_ids' => [ 91 ] ] ) ] );
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
        $r = $this->run( [ $this->hat( [ 'categories' => null ] ) ] ); // no terms asked: the term permission is not needed
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
            $this->run( [ $this->hat(), $this->hat( [ 'name' => 'B', 'sku' => 'B' ] ), $this->hat( [ 'name' => 'C', 'sku' => 'C' ] ) ] );
            $this->fail( 'expected failure' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Product 3 "C"', $e->getMessage() );
            $this->assertStringContainsString( 'nothing was created', $e->getMessage() );
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
            $this->run( [ $this->hat(), $this->hat( [ 'name' => 'B', 'sku' => 'B' ] ) ] );
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
        $this->run( [ $this->hat() ] );
    }

    public function testARollbackThatCannotDeleteNamesTheProduct(): void
    {
        $this->writer->failOnCreate = 2;
        $this->writer->deleteFails  = true;
        try {
            $this->run( [ $this->hat(), $this->hat( [ 'name' => 'B', 'sku' => 'B' ] ) ] );
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
        $this->assertTrue( $this->run( [ $this->hat() ] )['changed'] );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `make test-pro 2>&1 | grep -B1 -A4 "CreateProduct" | head -20`
Expected: `Class "AiEditorDivi5\Pro\Tools\CreateProduct" not found`.

- [ ] **Step 3: Implement the tool and register it in the autoloader**

Create `pro-addon/src/Tools/CreateProduct.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Kinds\ProductCreatedKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Products\ProductValuePolicy;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create new SIMPLE WooCommerce products as drafts. Every product is checked first; if any is refused nothing is created. Each saved
 * product is read back and compared with what was asked; a failed save or a difference deletes every product this call created.
 * One undoable batch per call. Never publishes and never touches an existing product.
 */
final class CreateProduct implements ProTool
{
    public const NAME         = 'create_product';
    public const MAX_PRODUCTS = 25;
    private const MAX_MESSAGE = 6000;

    public function __construct( private BatchLog $log, private ?ProductWriter $writer = null )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_posts';
    }

    public function definition(): array
    {
        $string = static fn ( string $d ): array => [ 'type' => 'string', 'description' => $d ];
        $list   = static fn ( string $d, string $item ): array => [ 'type' => 'array', 'description' => $d, 'items' => [ 'type' => $item ] ];

        return [
            'name'        => self::NAME,
            'description' => 'Create up to 25 new simple WooCommerce products as DRAFTS (never published): name, texts, SKU, prices, stock, existing categories, tags and Media Library images. All or nothing; one undoable batch. dry_run previews. Use list_products to see the catalogue first.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'products' => [
                        'type'        => 'array',
                        'description' => 'The products to create (1 to 25)',
                        'maxItems'    => self::MAX_PRODUCTS,
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'name'              => $string( 'Product name (plain text, at most 200 characters)' ),
                                'description'       => $string( 'Long description (HTML allowed, no scripts)' ),
                                'short_description' => $string( 'Short description (HTML allowed, no scripts)' ),
                                'sku'               => $string( 'Unique SKU' ),
                                'regular_price'     => $string( 'Decimal string, for example "20" or "20.50"' ),
                                'sale_price'        => $string( 'Decimal string lower than regular_price' ),
                                'manage_stock'      => [ 'type' => 'boolean', 'description' => 'Track stock quantity' ],
                                'stock_quantity'    => [ 'type' => 'integer', 'description' => 'Required when manage_stock is true' ],
                                'stock_status'      => $string( 'instock, outofstock or onbackorder (only when manage_stock is false)' ),
                                'backorders'        => $string( 'no, notify or yes (only when manage_stock is true)' ),
                                'categories'        => $list( 'Existing category slugs', 'string' ),
                                'tags'              => $list( 'Existing tag slugs', 'string' ),
                                'image_id'          => [ 'type' => 'integer', 'description' => 'Media Library image id' ],
                                'gallery_image_ids' => $list( 'Media Library image ids', 'integer' ),
                                'weight'            => $string( 'Decimal string' ),
                                'dimensions'        => [ 'type' => 'object', 'description' => 'length, width and height as decimal strings' ],
                                'tax_status'        => $string( 'taxable, shipping or none' ),
                                'virtual'           => [ 'type' => 'boolean', 'description' => 'A product that is not shipped' ],
                                'sold_individually' => [ 'type' => 'boolean', 'description' => 'Limit purchases to one per order' ],
                            ],
                            'required'   => [ 'name' ],
                        ],
                    ],
                    'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is created' ],
                ],
                'required'   => [ 'products' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $dry    = Args::bool( $arguments, 'dry_run' );
        $items  = Args::listOf( $arguments, 'products', self::MAX_PRODUCTS );
        $writer = $this->writer();

        if ( ! $writer->isActive() ) {
            throw new \InvalidArgumentException( 'WooCommerce is not active on this site.' );
        }
        if ( ! current_user_can( 'edit_products' ) ) {
            throw new \InvalidArgumentException( 'You need the edit_products permission to create products.' );
        }

        $kses  = static fn ( string $s ): string => (string) wp_kses_post( $s );
        $strip = static fn ( string $s ): string => (string) wp_strip_all_tags( $s );

        $plans    = [];
        $refusals = [];
        $skus     = [];
        foreach ( $items as $i => $raw ) {
            $n = $i + 1;
            if ( ! is_array( $raw ) || array_is_list( $raw ) ) {
                $refusals[] = "Product {$n}: must be an object with at least a \"name\"";
                continue;
            }
            $label   = self::label( $n, $raw );
            $checked = ProductValuePolicy::check( $raw, $kses, $strip );
            $reasons = $checked['reasons'];
            $values  = $checked['values'];
            if ( null !== $values ) {
                $reasons = $this->siteChecks( $values, $skus, $writer );
            }
            if ( [] !== $reasons ) {
                foreach ( $reasons as $reason ) {
                    $refusals[] = "{$label}: {$reason}";
                }
                continue;
            }
            $plans[] = [ 'n' => $n, 'label' => $label, 'values' => $values ];
        }
        if ( [] !== $refusals ) {
            $message = 'Nothing was created. ' . count( $refusals ) . ' problem(s): ' . implode( '; ', $refusals ) . '.';
            if ( mb_strlen( $message ) > self::MAX_MESSAGE ) {
                $message = mb_substr( $message, 0, self::MAX_MESSAGE - 3 ) . '...';
            }
            throw new \InvalidArgumentException( $message );
        }

        if ( $dry ) {
            return [ 'changed' => false, 'dry_run' => true, 'products' => array_map( static fn ( array $p ): array => self::preview( $p['values'] ), $plans ) ];
        }

        $created = [];
        $current = $plans[0] ?? [ 'label' => 'Product 1' ];
        try {
            foreach ( $plans as $plan ) {
                $current = $plan;
                $id        = $writer->create( $plan['values'] );
                $created[] = [ 'id' => $id, 'plan' => $plan, 'snapshot' => null ];
                $snap      = $writer->snapshot( $id );
                if ( null === $snap ) {
                    throw new \RuntimeException( 'the saved product could not be read back' );
                }
                $created[ count( $created ) - 1 ]['snapshot'] = $snap;
                $differences = self::differences( $plan['values'], $snap );
                if ( [] !== $differences ) {
                    throw new \RuntimeException( 'it was saved differently than asked (' . implode( ', ', $differences ) . ')' );
                }
            }
        } catch ( \Throwable $e ) {
            $notRemoved = $this->rollback( $created, $writer );
            // Only our own messages and WooCommerce's data exceptions are safe to show; anything else could carry a path.
            $reason = ( $e instanceof \RuntimeException || is_a( $e, 'WC_Data_Exception' ) ) ? $e->getMessage() : 'WooCommerce could not save it';
            $text   = "Could not create {$current['label']}: {$reason}.";
            if ( [] !== $notRemoved ) {
                throw new \InvalidArgumentException( $text . ' These products may still exist as drafts and should be checked or trashed: #' . implode( ', #', $notRemoved ) . '.' );
            }
            throw new \InvalidArgumentException( $text . ' Nothing was created.' );
        }

        $products = [];
        $records  = [];
        foreach ( $created as $c ) {
            $name       = (string) $c['snapshot']['name'];
            $products[] = [ 'id' => $c['id'], 'name' => $name, 'sku' => (string) $c['snapshot']['sku'], 'status' => (string) $c['snapshot']['status'], 'edit_url' => $writer->editUrl( $c['id'] ) ];
            $records[]  = ProductCreatedKind::itemFor( $c['id'], $c['snapshot'], $name );
        }
        $count = count( $records );
        $batch = $this->log->record( self::NAME, "Created {$count} draft product(s)", $records );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'products' => $products ], $batch );
    }

    /**
     * Checks that need the site: SKUs, term slugs, images, term permission. Fills `category_ids` / `tag_ids` into $values.
     *
     * @param array<string,mixed> $values
     * @param array<string,bool> $skus lower-cased SKUs already used in this call
     * @return list<string> reasons
     */
    private function siteChecks( array &$values, array &$skus, ProductWriter $writer ): array
    {
        $r = [];
        if ( '' !== $values['sku'] ) {
            $key = strtolower( (string) $values['sku'] );
            if ( isset( $skus[ $key ] ) ) {
                $r[] = "sku \"{$values['sku']}\" appears more than once in this call";
            } elseif ( $writer->skuTaken( (string) $values['sku'] ) ) {
                $r[] = "sku \"{$values['sku']}\" is already used by another product";
            }
            $skus[ $key ] = true;
        }

        $values['category_ids'] = [];
        $values['tag_ids']      = [];
        if ( [] !== $values['category_slugs'] || [] !== $values['tag_slugs'] ) {
            if ( ! current_user_can( 'assign_product_terms' ) ) {
                $r[] = 'you need the assign_product_terms permission to use categories or tags';
            } else {
                foreach ( [ 'category' => [ 'product_cat', 'category_slugs', 'category_ids' ], 'tag' => [ 'product_tag', 'tag_slugs', 'tag_ids' ] ] as $word => [ $taxonomy, $slugKey, $idKey ] ) {
                    if ( [] === $values[ $slugKey ] ) {
                        continue;
                    }
                    $found = $writer->termIds( $taxonomy, $values[ $slugKey ] );
                    foreach ( $found['missing'] as $slug ) {
                        $r[] = "{$word} \"{$slug}\" does not exist (create it in WooCommerce first)";
                    }
                    $values[ $idKey ] = $found['ids'];
                }
            }
        }

        if ( (int) $values['image_id'] > 0 && ! $writer->imageUsable( (int) $values['image_id'] ) ) {
            $r[] = "image_id {$values['image_id']} is not an image in the Media Library that you can use";
        }
        foreach ( $values['gallery_image_ids'] as $id ) {
            if ( ! $writer->imageUsable( (int) $id ) ) {
                $r[] = "gallery_image_ids {$id} is not an image in the Media Library that you can use";
            }
        }

        return $r;
    }

    /**
     * @param array<string,mixed> $values
     * @param array<string,mixed> $snap
     * @return list<string> the names of the fields that came back different
     */
    private static function differences( array $values, array $snap ): array
    {
        $bad  = [];
        $same = static function ( string $a, string $b ): bool {
            return '' === $a || '' === $b ? $a === $b : 0 === ProductValuePolicy::compareDecimals( $a, $b );
        };
        $norm = static fn ( string $s ): string => str_replace( '&amp;', '&', $s );

        if ( 'draft' !== ( $snap['status'] ?? '' ) ) {
            $bad[] = 'status';
        }
        foreach ( [ 'name', 'sku', 'tax_status' ] as $f ) {
            if ( (string) $values[ $f ] !== (string) ( $snap[ $f ] ?? '' ) ) {
                $bad[] = $f;
            }
        }
        foreach ( [ 'description', 'short_description' ] as $f ) {
            if ( $norm( (string) $values[ $f ] ) !== $norm( (string) ( $snap[ $f ] ?? '' ) ) ) {
                $bad[] = $f;
            }
        }
        foreach ( [ 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' ] as $f ) {
            if ( ! $same( (string) $values[ $f ], (string) ( $snap[ $f ] ?? '' ) ) ) {
                $bad[] = $f;
            }
        }
        if ( (bool) $values['manage_stock'] !== (bool) ( $snap['manage_stock'] ?? false ) ) {
            $bad[] = 'manage_stock';
        } elseif ( $values['manage_stock'] ) {
            if ( $values['stock_quantity'] !== ( $snap['stock_quantity'] ?? null ) ) {
                $bad[] = 'stock_quantity';
            }
        } elseif ( (string) $values['stock_status'] !== (string) ( $snap['stock_status'] ?? '' ) ) {
            $bad[] = 'stock_status';
        }
        $want = $values['category_ids'];
        sort( $want );
        if ( array_values( $want ) !== array_values( (array) ( $snap['category_ids'] ?? [] ) ) ) {
            $bad[] = 'categories';
        }

        return $bad;
    }

    /**
     * Deletes the products this call created, newest first. Returns the ids that could not be removed.
     *
     * @param list<array{id:int, plan:array<string,mixed>, snapshot:mixed}> $created
     * @return list<int>
     */
    private function rollback( array $created, ProductWriter $writer ): array
    {
        $left = [];
        foreach ( array_reverse( $created ) as $c ) {
            try {
                $ok = $writer->delete( $c['id'] );
            } catch ( \Throwable $e ) {
                $ok = false;
            }
            if ( ! $ok ) {
                $left[] = $c['id'];
            }
        }

        return $left;
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    private static function preview( array $values ): array
    {
        return [
            'name'           => $values['name'],
            'sku'            => $values['sku'],
            'regular_price'  => $values['regular_price'],
            'sale_price'     => $values['sale_price'],
            'manage_stock'   => $values['manage_stock'],
            'stock_quantity' => $values['stock_quantity'],
            'stock_status'   => $values['manage_stock'] ? null : $values['stock_status'],
            'categories'     => $values['category_slugs'],
            'tags'           => $values['tag_slugs'],
            'image_id'       => $values['image_id'],
            'status'         => 'draft',
        ];
    }

    /** @param array<string,mixed> $raw */
    private static function label( int $n, array $raw ): string
    {
        $name = $raw['name'] ?? null;
        if ( is_string( $name ) && '' !== trim( $name ) ) {
            $name = mb_strlen( $name ) > 60 ? mb_substr( $name, 0, 60 ) . '...' : $name;

            return "Product {$n} \"{$name}\"";
        }

        return "Product {$n}";
    }

    private function writer(): ProductWriter
    {
        return $this->writer ??= new WooProductWriter();
    }
}
```

In `pro-addon/src/autoload.php`, next to `require_once __DIR__ . '/Tools/SetCustomFields.php';` add:

```php
require_once __DIR__ . '/Tools/CreateProduct.php';
```

- [ ] **Step 4: Run to verify the tests pass**

Run: `make test-pro 2>&1 | tail -4`
Expected: `OK`. If a test trips on a shim detail (for example `current_user_can` per-capability handling in `tests/WpRestShims.php`), read that shim and fix the TEST setup (the `__wp_caps` array), not the production code, unless the failure shows a real bug.

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src/Tools/CreateProduct.php pro-addon/src/autoload.php pro-addon/tests/CreateProductTest.php
git commit -m "feat(pro): create_product, simple WooCommerce products as drafts, all or nothing, undoable

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Registry, REST/OpenAPI parity, docs and version 0.10.0

**Files:**
- Modify: `pro-addon/src/Tools.php`, `pro-addon/src/Rest/ProOpenApi.php`
- Modify: `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`, `pro-addon/readme.txt`, `pro-addon/README.md`, `pro-addon/tests/bootstrap.php`
- Modify tests: `pro-addon/tests/ToolsTest.php`, `pro-addon/tests/ProOpenApiTest.php` (and any other existing Pro test the suite shows pinning the tool list, the MCP-only list or the version)

**Interfaces:**
- Consumes: `CreateProduct` (Task 3).
- Produces: the registered 19th Pro tool, version 0.10.0.

- [ ] **Step 1: Update the pinned tests first (they must fail)**

In `pro-addon/tests/ToolsTest.php`:
- `testThisReleaseShipsExactlyTheseTools`: append `, 'create_product'` after `'set_custom_fields'` in the expected list.
- `testTheChatGptOperationBudgetIsRespected`: update the comment to say the 15th to 19th Pro tools (`manage_global_colors`, `manage_global_variables`, `manage_global_presets`, `set_custom_fields` and `create_product`) are MCP + REST only, and change the pinned list assertion to `[ 'manage_global_colors', 'manage_global_variables', 'manage_global_presets', 'set_custom_fields', 'create_product' ]`.

In `pro-addon/tests/ProOpenApiTest.php` next to the `'/pro/set_custom_fields'` absence assertion add:

```php
        $this->assertArrayNotHasKey( '/pro/create_product', $paths, 'MCP + REST only: no room in the 30-operation ChatGPT spec' );
```

In `pro-addon/tests/bootstrap.php` change `define( 'AIED_PRO_VERSION', '0.9.0' );` to `'0.10.0'`.

Run: `make test-pro 2>&1 | tail -12` — Expected: failures in `ToolsTest` / `ProOpenApiTest` / `ProParityTest` / `ReadmeTest` (the tool is not registered, `MCP_REST_ONLY` lacks it, the readme lacks it, the version differs). Read each failure so Step 2 fixes exactly those.

- [ ] **Step 2: Register the tool and add it to the MCP-only list**

In `pro-addon/src/Tools.php` add `use AiEditorDivi5\Pro\Tools\CreateProduct;` (alphabetical among the `use` lines) and append `new CreateProduct( $log ),` after `new SetCustomFields( $log ),`.

In `pro-addon/src/Rest/ProOpenApi.php` change the constant to:

```php
    public const MCP_REST_ONLY = [ 'manage_global_colors', 'manage_global_variables', 'manage_global_presets', 'set_custom_fields', 'create_product' ];
```

and update the comment above it (line ~15) so it names `create_product` too if it lists the tools by name.

- [ ] **Step 3: Version, readme, README**

- `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`: ` * Version:           0.10.0` and `define( 'AIED_PRO_VERSION', '0.10.0' );`.
- `pro-addon/readme.txt`: `Stable tag:        0.10.0`; add the tool to the `= Tools =` list after `set_custom_fields` (read how `set_custom_fields` is described and match the style; the bullet must name `create_product`, say it creates up to 25 simple products as drafts and never publishes, lists what it sets, says unknown categories/tags/images are refused, that `dry_run` previews, that one `undo_batch` trashes the unchanged drafts, and that it is MCP and REST only); update the sentence listing the MCP-and-REST-only tools (`manage_global_colors`, `manage_global_variables`, `manage_global_presets` and `set_custom_fields`) to include `create_product`; add above `= 0.9.0 =`:

```
= 0.10.0 =
* New: `create_product` creates new simple WooCommerce products as drafts: name, descriptions, SKU, prices, stock, existing categories and tags and Media Library images, weight, dimensions and tax status. Up to 25 products per call, checked before anything is saved: if one is refused none is created and every reason is listed. A product is never published, an existing product is never changed, and every saved product is read back and compared (a difference removes what the call created). Add `dry_run` to preview. One `undo_batch` moves the created drafts to the trash, unless you edited, published or trashed one since.
* Over MCP and REST only: this is the 19th Pro tool, and the ChatGPT importer's 30 operations are used up.
```

(Do not use the standalone numbers 49, 99, 149 or 449 anywhere in the readme or README; `NoPriceLiteralsTest` scans them.)
- `pro-addon/README.md`: add a "Create products (`create_product`)" section after the custom-fields section: the argument table (fields and limits), all-or-nothing, read-back and rollback, undo rules (changed-since, `EMPTY_TRASH_DAYS` 0), what is out of scope (variable/grouped/external/downloadable products, publishing, changing existing products), MCP + REST only, the spec path and `make verify-pro-create-product`.

- [ ] **Step 4: Run everything**

Run: `make test-pro 2>&1 | tail -4` and `make test 2>&1 | tail -3`
Expected: both `OK` (Pro suite now includes the registry, REST parity, readme and no-price-literal checks; the main suite is unchanged). Then `make build-pro 2>&1 | tail -3` must write `dist/jhmg-ai-editor-for-divi-5-pro.zip` and `unzip -p dist/jhmg-ai-editor-for-divi-5-pro.zip jhmg-ai-editor-for-divi-5-pro/jhmg-ai-editor-for-divi-5-pro.php | grep -m1 "Version:"` must show 0.10.0.

- [ ] **Step 5: Commit**

```bash
git add pro-addon
git commit -m "feat(pro): register create_product (MCP + REST only), docs and version 0.10.0

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Real-site gate `make verify-pro-create-product`, CLAUDE.md

**Files:**
- Create: `scripts/verify-pro-create-product.php`
- Modify: `Makefile`, `CLAUDE.md`

**Interfaces:**
- Consumes: the real tool (`new CreateProduct( Batches::log() )` with the real `WooProductWriter`), `Batches::undo()`, the free plugin's `ProductService::get()` (read-back through the real read path), `scripts/dev-guard.php`, WooCommerce 11.1.2 on the Docker dev site (18 existing products).
- Produces: a self-cleaning real-site check; the Makefile target.

- [ ] **Step 1: Write `scripts/verify-pro-create-product.php`**

Model it on `scripts/verify-pro-custom-fields.php` (copy its header comment style, the `$check`/`$skip` closures, `BatchTable::ensureReady( true )`, `$track` / `$trackUndo` batch-id bookkeeping, the contributor-user helper pattern with a fixed login `aied_pro_cp_contrib`, and the cleanup in `finally`). The script must:

1. `require_once __DIR__ . '/dev-guard.php'; aied_require_dev_site();` and `WP_CLI::error` unless the Pro plugin class and WooCommerce (`class_exists( 'WC_Product_Simple' )`) are active. Remove a stale throwaway contributor and any product whose SKU starts with `AIED-CP-` or any category/tag with slug starting `aied-cp-` (force delete).
2. Snapshot every existing product AND variation (id, name, status, regular and sale price, stock status and quantity, `post_modified_gmt`, `post_password`) as one hash before and after.
3. Create throwaway fixtures: a product category `aied-cp-cat`, a product tag `aied-cp-tag` (`wp_insert_term`), and a throwaway Media Library image (`wp_upload_bits` with a base64 1x1 PNG, then `wp_insert_attachment`).
4. Through the REAL tool, as user 1, `$check` that:
   - `dry_run` with two valid products returns `changed: false` and creates nothing (`wc_get_products( [ 'sku' => ... ] )` empty, no batch row);
   - a real run of three products (a simple one with price and sale price and managed stock; one with the category, the tag and the image; one minimal) returns three ids, each `status: draft`, and for each id the free plugin's `\AiEditorDivi5\WP\ProductService::get( $id, new \AiEditorDivi5\WP\WooProductGateway(), 'current_user_can' )` returns the same name, SKU, price, sale price, stock quantity, category ids and (for the second) the attachment id and tag id (this is the proof that what the AI asked is what WooCommerce stored, read through a different code path);
   - none of the three is published; `wp_count_posts( 'product' )->publish` is unchanged;
   - refused with nothing created (assert the SKU set is unchanged and no new batch row): a duplicate SKU against a created product, a duplicate SKU inside the call, an unknown category, an unknown tag, a price like `$5`, a sale price above the regular price, a script in a description, an `image_id` of a non-image attachment or a missing id, an unknown key (`status`), 26 products, `manage_stock: true` without `stock_quantity`;
   - the description `a & b <p>x</p>` is accepted and stored as sent (real `wp_kses_post()` runs here, unlike the unit tests);
   - one `Batches::undo()->undo( $batchId, false )` trashes all three created products (`get_post_status` is `trash`); products created by that call and edited afterwards (change one name with `wp_update_post` or the WooCommerce CRUD, publish another) are SKIPPED and reported by a second batch, and neither is trashed; the undo of the undo (restoring the trashed ones) brings them back as drafts;
   - a `WC_Data_Exception` path: force a failure by creating a product through the tool whose SKU another throwaway product (created by the script directly with the CRUD classes, SKU `AIED-CP-RACE`) already owns AFTER validation is not possible; so instead prove the rollback path with a deliberately failing writer: construct `new CreateProduct( $log, $failingWriter )` where `$failingWriter` is an anonymous class that delegates to `WooProductWriter` but throws on its second `create()`; assert the first product created is gone (deleted, not in the trash: `get_post( $id )` is null), no batch was recorded and the message does not contain a server path;
   - a plain editor (role `editor`, which has `edit_pages` but not `edit_products`) is refused with the `edit_products` message; a shop manager can create (create one throwaway user per role, delete both in cleanup).
5. Cleanup in a `finally` that always runs: force-delete every product the script created (including trashed ones and the rollback leftovers), the category, tag, attachment, the throwaway users and the batch rows it recorded; then `$check` that the snapshot of the 18 original products and their variations is byte-identical, that no `AIED-CP-` SKU remains, and that the totals equal the original counts again.
6. End with `WP_CLI::error( 'create_product NOT verified: ' . implode( '; ', $failures ) )` when any check failed, else `WP_CLI::success( 'create_product verified on the real site.' )`. A `FAIL` is a real finding: fix the cause in production code with a reproducing unit test, rerun; never edit the gate to hide it.

- [ ] **Step 2: Add the Makefile target**

Append `verify-pro-create-product` to the `.PHONY` line and add (tab-indented recipe line):

```make
# ---------------------------------------------------------------
# verify-pro-create-product — real-site check of the Pro tool create_product (self-cleaning; dev site only; free + Pro plugins and WooCommerce active; it records several batches, so on a site with batches it evicts the oldest real ones)
# ---------------------------------------------------------------
verify-pro-create-product:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-create-product.php --user=1
```

- [ ] **Step 3: Run the gate until it passes**

Run: `make verify-pro-create-product 2>&1 | tail -70`
Expected: every line `PASS` and the final `Success:` line; then `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin list --format=table | grep -E "woocommerce|pro"` still shows both active. Fix real findings in production code (with a reproducing unit test in the matching Pro test file) and rerun.

- [ ] **Step 4: Final build, CLAUDE.md, commit**

Run `make test-pro` and `make test` (both `OK`) and `make build-pro` (writes `dist/jhmg-ai-editor-for-divi-5-pro.zip`, not committed: `dist/` is git-ignored). In `CLAUDE.md`: add `make verify-pro-create-product` to the entry-points table; in the "Pro add-on" overview change the tool count to 19 (`create_product` after `set_custom_fields`) and add one "Current state" bullet: `**Unreleased (branch feat/pro-create-product): Pro 0.10.0 create_product** (sub-project B, first tool): up to 25 simple WooCommerce products per call as drafts, all or nothing, read-back verified, undoable (product_created / product_trashed kinds); MCP + REST only (ProOpenApi::MCP_REST_ONLY); spec/plan docs/superpowers/specs|plans/2026-10-13-pro-create-product*; live gate make verify-pro-create-product. update_products and bulk_adjust_prices are next. Release (store upload, divi5lab.com copy 19 Pro tools) is the owner's step.`

```bash
git add scripts/verify-pro-create-product.php Makefile CLAUDE.md
git commit -m "test: make verify-pro-create-product, a real-site gate for create_product; CLAUDE.md

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

The store release (`scripts/release-aied-pro.sh` in layoutlab) and the divi5lab.com copy are release steps for the owner's go-ahead, not part of this plan.

---

## Self-Review (done against the spec)

- **Spec coverage:** contract and every field rule (Task 1 table-driven tests); unknown keys refused by name (Task 1); site checks: SKU, terms, images, capabilities, WooCommerce active (Task 3); all-or-nothing with positions and the 6,000 cut (Task 3); `dry_run` (Task 3); read-back verification and rollback incl. an undeletable product (Task 3); undo, changed-since, `EMPTY_TRASH_DAYS` 0, inverse, per-item capability (Task 2); wiring, MCP-only list, REST/OpenAPI parity, version, readmes (Task 4); live gate incl. editor refusal, byte-identical originals, cleanup (Task 5). `Compat::MIN_FREE` is not touched (stated in Global Constraints).
- **Placeholders:** none; the one open instruction (Task 2 note on exact `BatchUndo::undo()` result keys) says exactly which files to read and what to preserve; Task 5 step 1 is a prose-specified script (a dev gate, modelled on a named sibling).
- **Type consistency:** `ProductWriter` method names/signatures are identical in the interface (Task 2), `WooProductWriter` (Task 2), `FakeProductWriter` (Task 2) and the calls in `CreateProduct` (Task 3); the `values` keys from `ProductValuePolicy` (Task 1) are exactly those read by `WooProductWriter::create`, `CreateProduct::siteChecks/differences/preview` and `FakeProductWriter::create`; `ProductCreatedKind::hashed/itemFor/FIELDS` are used with the same signatures in Tasks 2 and 3; snapshot keys match between the interface docblock, `WooProductWriter::snapshot` and `FakeProductWriter::create`.
- **Review Focus coverage:** partial creation/rollback (Task 3 tests), stock/price combinations (Task 1), SKU duplicates (Task 3), unknown terms/images/permissions (Task 3), undo after edit/publish/trash and `EMPTY_TRASH_DAYS` 0 (Task 2), WooCommerce inactive and exception message leak (Task 3).
