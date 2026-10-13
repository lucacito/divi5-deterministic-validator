# Pro `update_products` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the 20th Pro tool, `update_products` (Pro add-on 0.11.0): change existing simple, external and variable WooCommerce products (prices and sale schedule, stock, texts, SKU, terms, images, weight and size, and the price and stock of variation rows) behind a before/after preview and a single-use token, read back, rolled back on any failure, and undoable.

**Architecture:** `ProductValuePolicy::checkPartial()` (pure) judges the given fields against the product's current state and returns only what changes. `UpdateProducts` (the tool, shaped like `find_replace_site`) loads each product through the `ProductWriter` interface, hashes its whole current state into the existing `PreviewToken`, applies product by product, reads each back through `ProductDiff` and puts everything back on any failure. One batch of `product_fields` items (`ProductFieldsKind`) stores the previous values of only the changed fields. `WooProductWriter` stays the only class that calls WooCommerce.

**Tech Stack:** PHP 8.1+ (no 8.2-only syntax), PHPUnit 11 (`./vendor/bin/phpunit -c pro-addon/phpunit.xml`), WooCommerce CRUD classes, the add-on's `Batch`/`Site\PreviewToken` machinery, WP-CLI live gate in Docker.

**Spec:** `docs/superpowers/specs/2026-10-13-pro-update-products-design.md` (approved by the owner; it is the binding authority - read it before any task). Model plans: `docs/superpowers/plans/2026-10-13-pro-create-product.md` and `...-woocommerce-products-read.md`.

## How this plan was made (read once)

Every code block below was first written and run in a scratch copy of the repository: the Pro suite was green there (1870 existing tests plus the new ones = 1982), and the mutation checks listed under each task were run and fail as stated. The code blocks are therefore the intended final content of each file. If a command in a task still does not give the stated result, a **test or command in this plan is wrong - fix it in your brief and say so in your report; never silently weaken a test**. A FAIL in the live gate is a finding about the tool or the writer, never a reason to edit the gate.

## Global Constraints

- The free plugin (`wp-plugin/`, `src/`) is NOT changed by this feature. WordPress.org compliance rules in `CLAUDE.md` stay; `tests/WpOrgComplianceTest.php` is never weakened.
- `WooProductWriter` is the ONLY class that calls WooCommerce, through its CRUD classes and public functions; it has NO unit test by design (a stub `WC_Product_Simple` would flip `isActive()` for the whole PHPUnit process). Prove writer changes with the live gate and by reading; unit-test the pure and fake-driven layers.
- Never changes a product's `status`, `type` or `slug`; never creates, deletes, publishes or unpublishes a product (spec: Design 1).
- MCP + REST only: `update_products` joins `ProOpenApi::MCP_REST_ONLY` (the ChatGPT importer's 30 operations are used up). Registry capability `edit_posts`; inside the tool: WooCommerce active, `edit_products`, `edit_post` per product, `assign_product_terms` when categories or tags are given.
- At most 25 products per call, at most 100 variation rows per product and 200 per call; a text whose current value is over 512 KB (or not valid UTF-8) is not changed; the previous texts of one call may not exceed 1 MB in total; a refusal message is cut at 6,000 characters with `...`.
- Every real run needs the single-use `PreviewToken` (30 minutes, bound to the user, the exact arguments and a hash of each product's whole current state).
- Pro tripwires you must not trip: `tests/NoPriceLiteralsTest.php` (no standalone 49/99/149/449 in `pro-addon/src`, `readme.txt`, `README.md`); `tests/ProSafetyTest.php` (no `eval`/`exec`/`unserialize`, no `http(s)://` literal in a `src` string); `tests/ReadmeTest.php` (every tool in `Tools::all()` listed in `pro-addon/readme.txt`; Stable tag equals `AIED_PRO_VERSION` in `tests/bootstrap.php`); `ToolsTest` pins the exact tool list, the MCP-only list, the registered kinds and a 300-character description cap; `ExpiryKeepsAllToolsTest` pins the tool count; `DevGuardTest` pins the number of `verify-pro-*` scripts; keep the first sentence of the tool description clean (no comma-cut).
- Test helpers must not be named `run()` (clashes with `TestCase::run()`). Pro tests use `tests/WpRestShims.php`: `current_user_can` honours `$GLOBALS['__wp_caps']` and a capability NOT listed is DENIED.
- Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Do not push, upload or publish anything.
- Working in the main checkout on branch `feat/pro-update-products` is intentional (the Docker dev site mounts this path).

## Rulings made while planning (the owner can overrule; each says what it costs if wrong)

1. **Two extra methods on `ProductWriter`: `typeOf()` and `skuOwner()`.** The spec names `product()` and `apply()`. The spec also wants a grouped product to get its own refusal reason and a product to keep its own SKU; `product()` returns null for grouped products, so the tool needs `typeOf()`, and "unique except for the product itself" needs the SKU's owner. Cost if wrong: one interface method to remove.
2. **The skipped reason code is `changed_since_preview`** (the code `find_replace_site` uses), not the spaced phrase in the spec. Cost: a string.
3. **Relative rules are judged only for the group an update touches.** A legacy product whose sale price is already above its regular price (an old import) can still be renamed; changing any price field is judged against the RESULTING prices. Cost if wrong: an unrelated edit could be blocked by an old inconsistency (the opposite choice).
4. **When `manage_stock` changes, the whole stock group (quantity, status, backorders) is recorded for undo**, so the values WooCommerce derives come back too. Switching managed stock off also lists `stock_quantity` null and `backorders` "no" in the preview. Cost if wrong: an undo could leave a derived stock status behind.
5. **Small field choices the spec left open:** `image_id: 0` removes the image; `dimensions` changes only the axes given; `categories`/`tags` need `assign_product_terms` whenever they are given; `[]` clears a list; `stock_quantity` and a status are never both allowed while stock is managed.
6. **The token is spent after validation, right before the first write.** A run that is refused keeps its token (the person can fix the cause and repeat within 30 minutes). Cost if wrong: none for safety; a spent-token replay window is unchanged.
7. **A product that is in the call but absent from the preview's token map** (it had nothing to change at preview time) is skipped as `changed_since_preview` if it now needs a change; it is reported `unchanged` if it still needs none.
8. **The sale window follows WooCommerce's own product screen:** a sale starts at 00:00:00 of `sale_from` and ends at 23:59:59 of `sale_to`, in the site's time zone. The live gate proves the read-back; what WooCommerce does with a sale that already ended is recorded as a NOTE, not asserted (the design lists it as open).
9. **Tool description is 289 characters** (the importer cap pinned by `ToolsTest` is 300).

## Review Focus (inputs the spec implies; each has a test in the task that owns the code)

1. The same categories asked for in a different order, or by slug when the product has them by id, are "unchanged", not a rewrite (Task 5: `testTheSameCategoriesInAnotherOrderAreNoChange`).
2. An entry that carries only an `id` changes nothing and makes no token (Task 5: `testAnEntryWithOnlyAnIdChangesNothing`).
3. The same arguments sent with their keys in another order still open the token (Task 5: `testTheTokenDoesNotCareInWhatOrderTheKeysAreSent`).
4. A long multi-byte product name never produces broken UTF-8 in a label or a message (Task 5: `testALongMultiByteNameNeverBreaksTheUtf8OfAMessage`).
5. A quantity sent as `5.0` or `"5"`, a price sent as a number, and a clear (`""`) sent to a field that cannot be cleared all get a precise refusal, not a cast (Task 3: `testManagedStock`, `testPrices`, `testNameRulesAreTheOnesCreateUses`).
6. A variable product with more rows than one call may edit (101+ rows present, 100 edited) works; 300 rows across three products is refused as a whole (Task 3: `testAtMostOneHundredVariationRowsPerProduct`; Task 5: `testAtMostTwoHundredVariationRowsPerCall`).

## Execution notes for the controller

- Per task: implementer = sonnet; task reviewer = sonnet, except **Task 2 (the real writer, no unit test), Task 5 (core logic and every failure path) and Task 7 (the live gate) get an opus reviewer.** Tasks 1-4 and 6 are sonnet/sonnet.
- Reviewers must repeat the **RED proofs** listed in each task (apply the mutation, watch the named test fail, revert). Reviewers found real bugs on failure paths in the last two features: be strict there.
- Task order is fixed and serial: 1 -> 2 -> 3 -> 4 -> 5 -> 6 -> 7 (each depends on the earlier ones; Tasks 1, 4 and 5 all edit `autoload.php`).
- After Task 7: a final whole-branch review (opus), at most ONE fix wave, then a scoped re-review, then `superpowers:finishing-a-development-branch` (present the 3 options and wait for the owner).

## File structure

| File | Responsibility | Task |
|---|---|---|
| `pro-addon/src/Products/ProductFields.php` (new) | field vocabulary; change set <-> shape; `tracked()`, `pick()`; pure | 1 |
| `pro-addon/src/Products/ProductDiff.php` (new) | the one equality rule set for "asked vs read back"; pure | 1 |
| `pro-addon/src/Products/ProductWriter.php` | interface: + `skuOwner`, `typeOf`, `product`, `apply` | 2 |
| `pro-addon/src/Products/WooProductWriter.php` | the real implementation (only WooCommerce-facing class) | 2 |
| `pro-addon/tests/FakeProductWriter.php` | scripted store: the matching methods | 2 |
| `pro-addon/src/Products/ProductValuePolicy.php` | `checkPartial()`; `validName`/`validSku`/`galleryList` shared with `check()` | 3 |
| `pro-addon/src/Kinds/ProductFieldsKind.php` (new) | `product_fields` batch item kind | 4 |
| `pro-addon/src/Tools/UpdateProducts.php` (new) | the tool | 5 |
| wiring, docs, version | `Tools.php`, `ProOpenApi.php`, pinned tests, `readme.txt`, `README.md`, `CLAUDE.md`, plugin header | 6 |
| `scripts/verify-pro-update-products.php` (new), `Makefile`, `DevGuardTest` | the live gate | 7 |

---

### Task 1: The field vocabulary and the read-back comparison (pure)

**Files:**
- Create: `pro-addon/src/Products/ProductFields.php`
- Create: `pro-addon/src/Products/ProductDiff.php`
- Create: `pro-addon/tests/ProductFieldsTest.php`
- Modify: `pro-addon/src/autoload.php` (two `require_once` lines after `Products/ProductValuePolicy.php`)

**Interfaces:**
- Consumes: `ProductValuePolicy::sameText( string, string ): bool` and `ProductValuePolicy::compareDecimals( string, string ): int` (existing).
- Produces (later tasks rely on these exact names):
  - `ProductFields::STOCK`, `::ROW`, `::TEXTS` (constants, `list<string>`).
  - `ProductFields::asShape( array $changes ): array{fields, variations}` and `::asChanges( array $shape ): array` (a change set is the flat map `apply()` takes plus optional `variations` = id => row fields; a shape splits those two).
  - `ProductFields::tracked( array $changes ): array` - a KEY shape (`field => true`) of what a change set touches, plus the whole stock group wherever `manage_stock` is a key.
  - `ProductFields::pick( array $state, array $shape ): array` - a VALUE shape read from a `ProductWriter::product()` state using only the shape's keys; a field the state lacks is null, a variation row it lacks is left out.
  - `ProductDiff::compare( array $expectedShape, array $actualShape ): list<string>` - names of fields that differ (`"sale_price"`, `"variation 12 sale_price"`, `"variation 12"` for a missing row).

- [ ] **Step 1: Write the tests**

Create `pro-addon/tests/ProductFieldsTest.php`:

```php
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
        $this->assertEqualsCanonicalizing( [ 'manage_stock', 'name', 'stock_quantity', 'stock_status', 'backorders' ], array_keys( $t['fields'] ) );
        $this->assertEqualsCanonicalizing( [ 'manage_stock', 'stock_quantity', 'stock_status', 'backorders' ], array_keys( $t['variations'][11] ) );
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
        // the managed flag is taken from the actual state when the expectation does not name it
        $this->same( [ 'fields' => [ 'stock_status' => 'outofstock' ] ], [ 'fields' => [ 'stock_status' => 'instock', 'manage_stock' => true ] ] );
    }

    public function testVariationRowsAreComparedByIdAndNamedInTheAnswer(): void
    {
        $e = [ 'fields' => [], 'variations' => [ 11 => [ 'regular_price' => '11', 'sale_price' => '' ], 12 => [ 'regular_price' => '1' ] ] ];
        $this->same( $e, [ 'fields' => [], 'variations' => [ 11 => [ 'regular_price' => '11.00', 'sale_price' => '' ], 12 => [ 'regular_price' => '1' ] ] ] );
        $this->assertSame( [ 'variation 11 regular_price', 'variation 12' ], $this->diff( $e, [ 'fields' => [], 'variations' => [ 11 => [ 'regular_price' => '12', 'sale_price' => '' ] ] ] ) );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter ProductFieldsTest`
Expected: errors such as `Class "AiEditorDivi5\Pro\Products\ProductFields" not found`.

- [ ] **Step 3: Create `ProductFields`**

Create `pro-addon/src/Products/ProductFields.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The vocabulary of update_products, and how a change set becomes the "shape" the batch kind stores and compares. Pure.
 *
 * A change set (what ProductWriter::apply() takes) is a flat map of product fields plus, for a variable product, `variations`
 * (variation id => the row fields that change). A SHAPE is the same data split in two: `['fields' => [...], 'variations' => [id => [...]]]`.
 * A shape holding VALUES is what the batch item stores as `before` and hashes as `after`; a shape holding `true` for every
 * field is a key shape that says which fields to read back (`tracked()`, `pick()`).
 */
final class ProductFields
{
    /** The stock group. When manage_stock changes, all four are recorded so an undo also puts back the values WooCommerce derives. */
    public const STOCK = [ 'manage_stock', 'stock_quantity', 'stock_status', 'backorders' ];

    /** What one variation row (and the price and stock part of a product) holds. */
    public const ROW = [ 'regular_price', 'sale_price', 'sale_from', 'sale_to', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders' ];

    /** The text fields whose previous value is stored for undo (size and encoding are checked). */
    public const TEXTS = [ 'name', 'description', 'short_description' ];

    /**
     * @param array<string,mixed> $changes
     * @return array{fields: array<string,mixed>, variations: array<int,array<string,mixed>>}
     */
    public static function asShape( array $changes ): array
    {
        $variations = [];
        foreach ( (array) ( $changes['variations'] ?? [] ) as $vid => $row ) {
            $variations[ (int) $vid ] = (array) $row;
        }
        unset( $changes['variations'] );

        return [ 'fields' => $changes, 'variations' => $variations ];
    }

    /**
     * @param array<string,mixed> $shape
     * @return array<string,mixed>
     */
    public static function asChanges( array $shape ): array
    {
        $changes = (array) ( $shape['fields'] ?? [] );
        $rows    = (array) ( $shape['variations'] ?? [] );
        if ( [] !== $rows ) {
            $changes['variations'] = $rows;
        }

        return $changes;
    }

    /**
     * The fields a change set touches: the changed ones, plus the whole stock group wherever manage_stock changes.
     *
     * @param array<string,mixed> $changes
     * @return array{fields: array<string,true>, variations: array<int,array<string,true>>}
     */
    public static function tracked( array $changes ): array
    {
        $shape = self::asShape( $changes );
        $out   = [ 'fields' => self::keys( array_keys( $shape['fields'] ) ), 'variations' => [] ];
        foreach ( $shape['variations'] as $vid => $row ) {
            $out['variations'][ $vid ] = self::keys( array_keys( $row ) );
        }

        return $out;
    }

    /**
     * The values of the fields a shape names, read from a ProductWriter::product() state. A field the state does not have is null;
     * a variation row the state does not have is left out (so a comparison reports it).
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $shape any shape: only its keys are used
     * @return array{fields: array<string,mixed>, variations: array<int,array<string,mixed>>}
     */
    public static function pick( array $state, array $shape ): array
    {
        $out = [ 'fields' => [], 'variations' => [] ];
        foreach ( array_keys( (array) ( $shape['fields'] ?? [] ) ) as $field ) {
            $out['fields'][ $field ] = $state[ $field ] ?? null;
        }
        foreach ( (array) ( $shape['variations'] ?? [] ) as $vid => $row ) {
            $have = $state['variations'][ (int) $vid ] ?? null;
            if ( ! is_array( $have ) ) {
                continue;
            }
            foreach ( array_keys( (array) $row ) as $field ) {
                $out['variations'][ (int) $vid ][ $field ] = $have[ $field ] ?? null;
            }
        }

        return $out;
    }

    /**
     * @param list<string|int> $fields
     * @return array<string,true>
     */
    private static function keys( array $fields ): array
    {
        $out = [];
        foreach ( $fields as $field ) {
            $out[ (string) $field ] = true;
        }
        if ( isset( $out['manage_stock'] ) ) {
            foreach ( self::STOCK as $f ) {
                $out[ $f ] = true;
            }
        }

        return $out;
    }
}
```

- [ ] **Step 4: Create `ProductDiff`**

Create `pro-addon/src/Products/ProductDiff.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Compares what was asked for with what a product reads back as, field by field, with the one set of equality rules update_products
 * and its undo share. Pure. Both sides are value shapes (see ProductFields): only the fields in `$expected` are compared.
 *
 * Rules: texts through ProductValuePolicy::sameText() (core stores a bare "&" as "&amp;" for a user without unfiltered_html); prices,
 * weight and dimensions as exact decimals ("20" equals "20.00"); an expected EMPTY category list accepts anything (WooCommerce files a
 * product without categories under its default one); stock_status is not compared while stock is managed and backorders are not compared
 * while it is not (WooCommerce works them out).
 */
final class ProductDiff
{
    private const TEXTS    = [ 'name', 'description', 'short_description' ];
    private const DECIMALS = [ 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' ];

    /**
     * @param array<string,mixed> $expected
     * @param array<string,mixed> $actual
     * @return list<string> the fields that differ ("sale_price", "variation 12 sale_price")
     */
    public static function compare( array $expected, array $actual ): array
    {
        $bad = self::fields( (array) ( $expected['fields'] ?? [] ), (array) ( $actual['fields'] ?? [] ), '' );
        foreach ( (array) ( $expected['variations'] ?? [] ) as $vid => $row ) {
            $have = $actual['variations'][ $vid ] ?? null;
            if ( ! is_array( $have ) ) {
                $bad[] = "variation {$vid}";
                continue;
            }
            $bad = array_merge( $bad, self::fields( (array) $row, $have, "variation {$vid} " ) );
        }

        return $bad;
    }

    /**
     * @param array<string,mixed> $e
     * @param array<string,mixed> $a
     * @return list<string>
     */
    private static function fields( array $e, array $a, string $prefix ): array
    {
        $bad    = [];
        $manage = array_key_exists( 'manage_stock', $e ) ? (bool) $e['manage_stock'] : (bool) ( $a['manage_stock'] ?? false );
        foreach ( $e as $field => $want ) {
            $got = $a[ $field ] ?? null;
            if ( in_array( $field, self::TEXTS, true ) ) {
                $same = is_string( $got ) && ProductValuePolicy::sameText( (string) $want, $got );
            } elseif ( in_array( $field, self::DECIMALS, true ) ) {
                $same = is_string( $got ) && self::sameDecimal( (string) $want, $got );
            } elseif ( 'stock_status' === $field && $manage ) {
                $same = true;
            } elseif ( 'backorders' === $field && ! $manage ) {
                $same = true;
            } elseif ( 'category_ids' === $field ) {
                $same = [] === $want || self::sorted( $want ) === self::sorted( (array) $got );
            } elseif ( 'tag_ids' === $field ) {
                $same = self::sorted( (array) $want ) === self::sorted( (array) $got );
            } else {
                $same = $want === $got;
            }
            if ( ! $same ) {
                $bad[] = $prefix . $field;
            }
        }

        return $bad;
    }

    private static function sameDecimal( string $a, string $b ): bool
    {
        return '' === $a || '' === $b ? $a === $b : 0 === ProductValuePolicy::compareDecimals( $a, $b );
    }

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    private static function sorted( array $ids ): array
    {
        $ids = array_map( 'intval', array_values( $ids ) );
        sort( $ids );

        return $ids;
    }
}
```

- [ ] **Step 5: Register both files in `autoload.php`**

In `pro-addon/src/autoload.php`, replace this exact text:

```php
require_once __DIR__ . '/Products/ProductValuePolicy.php';

```

with:

```php
require_once __DIR__ . '/Products/ProductValuePolicy.php';
require_once __DIR__ . '/Products/ProductFields.php';
require_once __DIR__ . '/Products/ProductDiff.php';

```

- [ ] **Step 6: Run the new tests, then the whole Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter ProductFieldsTest` - Expected: `OK (10 tests, 29 assertions)`.
Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK` (no failures; `ProSafetyTest`/`NoPriceLiteralsTest` scan the two new files and must stay green).

- [ ] **Step 7: RED proof (do it, then revert)**

In `ProductDiff::fields()` change `} elseif ( 'stock_status' === $field && $manage ) {` to `} elseif ( false ) {`. Expected: `testDerivedStockValuesAreNotCompared` fails. Revert.

- [ ] **Step 8: Commit**

```bash
git add pro-addon/src/Products/ProductFields.php pro-addon/src/Products/ProductDiff.php pro-addon/tests/ProductFieldsTest.php pro-addon/src/autoload.php
git commit -m "$(cat <<'EOF'
feat(pro): ProductFields and ProductDiff for update_products (shapes, tracked fields, read-back comparison)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: The writer contract (interface, fake, real WooCommerce writer)

**Files:**
- Modify: `pro-addon/src/Products/ProductWriter.php`
- Modify: `pro-addon/src/Products/WooProductWriter.php`
- Replace: `pro-addon/tests/FakeProductWriter.php` (whole file)
- Modify: `scripts/verify-pro-create-product.php` (its anonymous `ProductWriter` decorator must implement the new methods, or `make verify-pro-create-product` dies)

**Interfaces:**
- Consumes: nothing new.
- Produces (exact signatures):
  - `ProductWriter::skuOwner( string $sku ): int` - id of the product or variation that owns the SKU, 0 for none.
  - `ProductWriter::typeOf( int $id ): ?string` - WooCommerce type of a non-trashed `product` post; null for missing, trashed, a variation, any other post type.
  - `ProductWriter::product( int $id ): ?array` - the whole editable state of a simple, external or variable product (keys in the docblock below); null for anything else (grouped included).
  - `ProductWriter::apply( int $id, array $changes ): void` - writes ONLY the given fields (flat map + optional `variations` id => row fields); throws on failure; never touches status, type or slug.
  - `FakeProductWriter` public members used by later tests: `$products`, `$types`, `$terms`, `$images`, `$skus`, `$active`, `$filteredTitle`, `$applyCalls`, `$failApplyCalls` (list of 1-based `apply()` call numbers that throw, restores included), `$failApplyException` (closure returning the throwable), `$applied`, `$afterApply` (closure `fn(int $id, array &$stored, int $call)`).

There is no new unit test in this task on purpose: the interface and the fake are test infrastructure, driven by the tests of Tasks 4 and 5; `WooProductWriter` is proven by Task 7's live gate and by reading. The existing suite must stay green (it proves the fake still serves `create_product`).

- [ ] **Step 1: Replace the interface file**

Replace the whole content of `pro-addon/src/Products/ProductWriter.php` with:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** What create_product, update_products and the product batch kinds need from a store. The real one is WooProductWriter; tests use a fake. */
interface ProductWriter
{
    public function isActive(): bool;

    public function skuTaken( string $sku ): bool;

    /** The id of the product or variation that owns this SKU, 0 when no one does (update_products allows a product to keep its own SKU). */
    public function skuOwner( string $sku ): int;

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

    /** The WooCommerce type ('simple', 'variable', 'grouped', ...) of a product post that is not in the trash; null for anything else (missing, trashed, a variation, another post type). */
    public function typeOf( int $id ): ?string;

    /**
     * The whole editable state of a simple, external or variable product (null for any other id, grouped products included):
     * type, status, name, description, short_description, sku, regular_price, sale_price, sale_from, sale_to (YYYY-MM-DD or ''),
     * manage_stock, stock_quantity (null unless managed), stock_status, backorders, category_ids and tag_ids (sorted), category_slugs and
     * tag_slugs (display only, same order), image_id, gallery_image_ids, weight, length, width, height, tax_status, and `variations`:
     * variation id => row (regular_price, sale_price, sale_from, sale_to, manage_stock, stock_quantity, stock_status, backorders), sorted by id,
     * empty unless the product is variable.
     *
     * @return ?array<string,mixed>
     */
    public function product( int $id ): ?array;

    /**
     * Writes ONLY the given fields of an existing product through WooCommerce's CRUD and throws on any failure. Never touches status, type or
     * slug. Keys: the scalar fields of product(), `category_ids` and `tag_ids` (lists of int), and `variations` (variation id => the row
     * fields to write).
     *
     * @param array<string,mixed> $changes
     */
    public function apply( int $id, array $changes ): void;

    /** Permanently delete (used only to roll back a product this same call just created). */
    public function delete( int $id ): bool;

    public function editUrl( int $id ): string;
}
```

- [ ] **Step 2: Replace the fake**

Replace the whole content of `pro-addon/tests/FakeProductWriter.php` with:

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
    /** When set, the failing create() call (see failOnCreate) throws what this returns instead of the default \Exception. */
    public ?\Closure $failException = null;
    /** @var list<int> */
    public array $deleted = [];
    public bool $deleteFails = false;
    public int $createCalls = 0;
    /** Store the name and texts as core does for a user without unfiltered_html: a bare "&" becomes "&amp;". */
    public bool $filteredTitle = false;
    /** @var list<array<string,mixed>> */
    public array $created = [];

    // ---- update_products ---------------------------------------------------------------------------------------------------------
    public int $applyCalls = 0;
    /** @var list<int> the apply() calls (1-based, the restores of a rollback included) that throw */
    public array $failApplyCalls = [];
    /** When set, a failing apply() throws what this returns instead of the default \Exception. */
    public ?\Closure $failApplyException = null;
    /** @var list<array{int,array<string,mixed>}> every successful apply(): [id, changes] */
    public array $applied = [];
    /** Runs after a successful apply(): fn(int $id, array &$stored, int $call): void, to simulate a store that saved something else. */
    public ?\Closure $afterApply = null;
    /** @var array<int,string> id => type, to make a stored product 'grouped', 'subscription' and so on (or to give an id with no stored fields a type) */
    public array $types = [];

    public function isActive(): bool { return $this->active; }
    public function skuTaken( string $sku ): bool { return isset( $this->skus[ $sku ] ); }

    public function skuOwner( string $sku ): int
    {
        foreach ( $this->products as $id => $p ) {
            if ( '' !== (string) ( $p['sku'] ?? '' ) && strtolower( (string) $p['sku'] ) === strtolower( $sku ) ) {
                return (int) $id;
            }
        }

        return isset( $this->skus[ $sku ] ) ? 1 : 0;
    }

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
            throw null !== $this->failException ? ( $this->failException )() : new \Exception( 'boom: could not write /var/www/html/wp-content/secret.php' );
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
        if ( $this->filteredTitle ) {
            foreach ( [ 'name', 'description', 'short_description' ] as $f ) {
                $snap[ $f ] = (string) preg_replace( '/&(?!#?\w+;)/', '&amp;', (string) $snap[ $f ] );
            }
        }
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

    public function typeOf( int $id ): ?string
    {
        $post = $GLOBALS['__wp_posts'][ $id ] ?? null;
        if ( is_object( $post ) && in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
            return null;
        }
        if ( isset( $this->products[ $id ] ) || isset( $this->types[ $id ] ) ) {
            return $this->types[ $id ] ?? (string) ( $this->products[ $id ]['type'] ?? 'simple' );
        }

        return null;
    }

    public function product( int $id ): ?array
    {
        $type = $this->typeOf( $id );
        if ( ! in_array( $type, [ 'simple', 'external', 'variable' ], true ) || ! isset( $this->products[ $id ] ) ) {
            return null;
        }
        $s = $this->products[ $id ] + [ 'sale_from' => '', 'sale_to' => '', 'variations' => [], 'category_ids' => [], 'tag_ids' => [] ];
        $s['type'] = $type;
        if ( isset( $GLOBALS['__wp_posts'][ $id ] ) ) {
            $s['status'] = $GLOBALS['__wp_posts'][ $id ]->post_status;
        }
        $s['category_slugs'] = $this->slugsFor( 'product_cat', $s['category_ids'] );
        $s['tag_slugs']      = $this->slugsFor( 'product_tag', $s['tag_ids'] );
        ksort( $s['variations'] );

        return $s;
    }

    public function apply( int $id, array $changes ): void
    {
        ++$this->applyCalls;
        if ( in_array( $this->applyCalls, $this->failApplyCalls, true ) ) {
            throw null !== $this->failApplyException ? ( $this->failApplyException )() : new \Exception( 'boom: could not write /var/www/html/wp-content/secret.php' );
        }
        if ( ! isset( $this->products[ $id ] ) ) {
            throw new \Exception( 'no such product' );
        }
        $rows = (array) ( $changes['variations'] ?? [] );
        unset( $changes['variations'] );
        $stored = $this->products[ $id ];
        foreach ( $rows as $vid => $row ) {
            if ( ! isset( $stored['variations'][ $vid ] ) ) {
                throw new \Exception( 'no such variation' );
            }
            $stored['variations'][ $vid ] = $this->merge( $stored['variations'][ $vid ], $row );
        }
        $stored = $this->merge( $stored, $changes );
        if ( $this->filteredTitle ) {
            foreach ( [ 'name', 'description', 'short_description' ] as $f ) {
                if ( array_key_exists( $f, $changes ) ) {
                    $stored[ $f ] = (string) preg_replace( '/&(?!#?\w+;)/', '&amp;', (string) $stored[ $f ] );
                }
            }
        }
        $this->products[ $id ] = $stored;
        $this->applied[]       = [ $id, $changes + ( [] === $rows ? [] : [ 'variations' => $rows ] ) ];
        if ( null !== $this->afterApply ) {
            ( $this->afterApply )( $id, $this->products[ $id ], $this->applyCalls );
        }
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

    /** @param list<int> $ids @return list<string> */
    private function slugsFor( string $taxonomy, array $ids ): array
    {
        $out = [];
        foreach ( $ids as $id ) {
            $slug = array_search( $id, $this->terms[ $taxonomy ] ?? [], true );
            $out[] = false === $slug ? 'term-' . $id : (string) $slug;
        }

        return $out;
    }

    /**
     * Like WooCommerce on save: an unmanaged product has no quantity and no backorders; a managed one works its stock status out
     * (a stock_status that comes with managed stock is ignored); term id lists are kept sorted.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private function merge( array $state, array $c ): array
    {
        foreach ( $c as $k => $v ) {
            $state[ $k ] = $v;
        }
        foreach ( [ 'category_ids', 'tag_ids' ] as $k ) {
            if ( isset( $state[ $k ] ) ) {
                $state[ $k ] = $this->sorted( $state[ $k ] );
            }
        }
        if ( array_key_exists( 'manage_stock', $state ) ) {
            if ( $state['manage_stock'] ) {
                $qty = (int) ( $state['stock_quantity'] ?? 0 );
                $state['stock_status'] = $qty > 0 ? 'instock' : ( 'no' === ( $state['backorders'] ?? 'no' ) ? 'outofstock' : 'onbackorder' );
            } else {
                $state['stock_quantity'] = null;
                $state['backorders']     = 'no';
            }
        }

        return $state;
    }
}
```

- [ ] **Step 3: Implement the methods in the real writer**

**Edit 1 of 3 in `pro-addon/src/Products/WooProductWriter.php`** - replace this exact text:

```php
}

/**
 * The only class that calls WooCommerce, through its own CRUD classes and public functions. It creates drafts only and never touches
 * an existing product. Proven on the real site by `make verify-pro-create-product` (the unit tests use FakeProductWriter).
 */
final class WooProductWriter implements ProductWriter
{
```

with:

```php
}

/**
 * The only class that calls WooCommerce, through its own CRUD classes and public functions. create() makes drafts only; apply() changes
 * the fields it is given on an existing product and never its status, type or slug. Proven on the real site by
 * `make verify-pro-create-product` and `make verify-pro-update-products` (the unit tests use FakeProductWriter).
 */
final class WooProductWriter implements ProductWriter
{
```

**Edit 2 of 3 in `pro-addon/src/Products/WooProductWriter.php`** - replace this exact text:

```php
    public function skuTaken( string $sku ): bool
    {
        return (int) wc_get_product_id_by_sku( $sku ) > 0;
    }

    public function termIds( string $taxonomy, array $slugs ): array
```

with:

```php
    public function skuTaken( string $sku ): bool
    {
        return (int) wc_get_product_id_by_sku( $sku ) > 0;
    }

    public function skuOwner( string $sku ): int
    {
        return (int) wc_get_product_id_by_sku( $sku );
    }

    public function termIds( string $taxonomy, array $slugs ): array
```

**Edit 3 of 3 in `pro-addon/src/Products/WooProductWriter.php`** - replace this exact text:

```php
        ];
    }

    public function delete( int $id ): bool
    {
        return (bool) wp_delete_post( $id, true );
```

with:

```php
        ];
    }

    public function typeOf( int $id ): ?string
    {
        $post = get_post( $id );
        if ( ! is_object( $post ) || 'product' !== $post->post_type || in_array( $post->post_status, [ 'trash', 'auto-draft' ], true ) ) {
            return null;
        }
        $p = wc_get_product( $id );

        return $p ? (string) $p->get_type() : null;
    }

    public function product( int $id ): ?array
    {
        $type = $this->typeOf( $id );
        if ( ! in_array( $type, [ 'simple', 'external', 'variable' ], true ) ) {
            return null;
        }
        $p = wc_get_product( $id );
        if ( ! $p ) {
            return null;
        }
        $cats = array_map( 'intval', (array) $p->get_category_ids() );
        $tags = array_map( 'intval', (array) $p->get_tag_ids() );
        sort( $cats );
        sort( $tags );
        $dims       = (array) $p->get_dimensions( false );
        $variations = [];
        if ( 'variable' === $type ) {
            foreach ( array_map( 'intval', (array) $p->get_children() ) as $vid ) {
                $v = wc_get_product( $vid );
                if ( $v && 'variation' === $v->get_type() ) {
                    $variations[ $vid ] = self::row( $v );
                }
            }
            ksort( $variations );
        }

        return [
            'type'              => $type,
            'status'            => (string) $p->get_status(),
            'name'              => (string) $p->get_name( 'edit' ),
            'description'       => (string) $p->get_description( 'edit' ),
            'short_description' => (string) $p->get_short_description( 'edit' ),
            'sku'               => (string) $p->get_sku( 'edit' ),
            'category_ids'      => $cats,
            'category_slugs'    => self::slugs( $cats, 'product_cat' ),
            'tag_ids'           => $tags,
            'tag_slugs'         => self::slugs( $tags, 'product_tag' ),
            'image_id'          => (int) $p->get_image_id( 'edit' ),
            'gallery_image_ids' => array_map( 'intval', (array) $p->get_gallery_image_ids( 'edit' ) ),
            'weight'            => (string) $p->get_weight( 'edit' ),
            'length'            => (string) ( $dims['length'] ?? '' ),
            'width'             => (string) ( $dims['width'] ?? '' ),
            'height'            => (string) ( $dims['height'] ?? '' ),
            'tax_status'        => (string) $p->get_tax_status( 'edit' ),
            'variations'        => $variations,
        ] + self::row( $p );
    }

    /**
     * Writes only the fields given. The sale window follows WooCommerce's own product screen: a sale starts at 00:00:00 of its first day and
     * ends at 23:59:59 of its last, in the site's time zone. The parent of a variable product is re-synced after its variations changed.
     *
     * @throws ProductSaveFailed with a constant message (a product or variation that cannot be loaded)
     * @throws \WC_Data_Exception from a setter or save (its own text is meant to be shown: a taken SKU, for example)
     */
    public function apply( int $id, array $changes ): void
    {
        $p = wc_get_product( $id );
        if ( ! $p || 'product' !== get_post_type( $id ) ) {
            throw new ProductSaveFailed( 'the product could not be loaded' );
        }
        $variations = (array) ( $changes['variations'] ?? [] );
        unset( $changes['variations'] );
        if ( [] !== $changes ) {
            self::fill( $p, $changes );
            if ( array_key_exists( 'category_ids', $changes ) ) {
                $p->set_category_ids( array_map( 'intval', (array) $changes['category_ids'] ) );
            }
            if ( array_key_exists( 'tag_ids', $changes ) ) {
                $p->set_tag_ids( array_map( 'intval', (array) $changes['tag_ids'] ) );
            }
            if ( array_key_exists( 'image_id', $changes ) ) {
                $p->set_image_id( (int) $changes['image_id'] > 0 ? (int) $changes['image_id'] : '' );
            }
            if ( array_key_exists( 'gallery_image_ids', $changes ) ) {
                $p->set_gallery_image_ids( array_map( 'intval', (array) $changes['gallery_image_ids'] ) );
            }
            $p->save();
        }
        if ( [] === $variations ) {
            return;
        }
        foreach ( $variations as $vid => $row ) {
            $v = wc_get_product( (int) $vid );
            if ( ! $v || 'variation' !== $v->get_type() || (int) $v->get_parent_id() !== $id ) {
                throw new ProductSaveFailed( 'a variation does not belong to this product' );
            }
            self::fill( $v, (array) $row );
            $v->save();
        }
        if ( class_exists( '\WC_Product_Variable' ) ) {
            \WC_Product_Variable::sync( $id );
            if ( is_callable( [ 'WC_Product_Variable', 'sync_stock_status' ] ) ) {
                \WC_Product_Variable::sync_stock_status( $id );
            }
        }
        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients( $id );
        }
    }

    /** The price, sale window and stock part, read the same way for a product and for a variation (edit context: no inheritance from a parent). */
    private static function row( object $p ): array
    {
        $manage = (bool) $p->get_manage_stock( 'edit' );
        $qty    = $p->get_stock_quantity( 'edit' );
        $from   = $p->get_date_on_sale_from( 'edit' );
        $to     = $p->get_date_on_sale_to( 'edit' );

        return [
            'regular_price'  => (string) $p->get_regular_price( 'edit' ),
            'sale_price'     => (string) $p->get_sale_price( 'edit' ),
            'sale_from'      => $from ? (string) $from->date( 'Y-m-d' ) : '',
            'sale_to'        => $to ? (string) $to->date( 'Y-m-d' ) : '',
            'manage_stock'   => $manage,
            'stock_quantity' => $manage && null !== $qty ? (int) $qty : null,
            'stock_status'   => (string) $p->get_stock_status( 'edit' ),
            'backorders'     => (string) $p->get_backorders( 'edit' ),
        ];
    }

    /** The setters both a product and a variation have, for the keys present. @param array<string,mixed> $c */
    private static function fill( object $p, array $c ): void
    {
        if ( array_key_exists( 'name', $c ) ) {
            $p->set_name( (string) $c['name'] );
        }
        if ( array_key_exists( 'description', $c ) ) {
            $p->set_description( (string) $c['description'] );
        }
        if ( array_key_exists( 'short_description', $c ) ) {
            $p->set_short_description( (string) $c['short_description'] );
        }
        if ( array_key_exists( 'sku', $c ) ) {
            $p->set_sku( (string) $c['sku'] );
        }
        if ( array_key_exists( 'regular_price', $c ) ) {
            $p->set_regular_price( (string) $c['regular_price'] );
        }
        if ( array_key_exists( 'sale_price', $c ) ) {
            $p->set_sale_price( (string) $c['sale_price'] );
        }
        if ( array_key_exists( 'sale_from', $c ) ) {
            $p->set_date_on_sale_from( '' === $c['sale_from'] ? null : $c['sale_from'] . ' 00:00:00' );
        }
        if ( array_key_exists( 'sale_to', $c ) ) {
            $p->set_date_on_sale_to( '' === $c['sale_to'] ? null : $c['sale_to'] . ' 23:59:59' );
        }
        if ( array_key_exists( 'weight', $c ) ) {
            $p->set_weight( (string) $c['weight'] );
        }
        if ( array_key_exists( 'length', $c ) ) {
            $p->set_length( (string) $c['length'] );
        }
        if ( array_key_exists( 'width', $c ) ) {
            $p->set_width( (string) $c['width'] );
        }
        if ( array_key_exists( 'height', $c ) ) {
            $p->set_height( (string) $c['height'] );
        }
        if ( array_key_exists( 'tax_status', $c ) ) {
            $p->set_tax_status( (string) $c['tax_status'] );
        }
        // Stock: the order matters. WooCommerce works the stock status out itself while stock is managed, and clears the quantity and the
        // backorders when it is not, so a quantity of null and a status for a managed product are not written.
        if ( array_key_exists( 'manage_stock', $c ) ) {
            $p->set_manage_stock( (bool) $c['manage_stock'] );
        }
        if ( array_key_exists( 'stock_quantity', $c ) && null !== $c['stock_quantity'] ) {
            $p->set_stock_quantity( (int) $c['stock_quantity'] );
        }
        if ( array_key_exists( 'backorders', $c ) ) {
            $p->set_backorders( (string) $c['backorders'] );
        }
        if ( array_key_exists( 'stock_status', $c ) && ! $p->get_manage_stock( 'edit' ) ) {
            $p->set_stock_status( (string) $c['stock_status'] );
        }
    }

    /** @param list<int> $ids @return list<string> */
    private static function slugs( array $ids, string $taxonomy ): array
    {
        $out = [];
        foreach ( $ids as $id ) {
            $term  = get_term( $id, $taxonomy );
            $out[] = is_object( $term ) && isset( $term->slug ) ? (string) $term->slug : 'term-' . $id;
        }

        return $out;
    }

    public function delete( int $id ): bool
    {
        return (bool) wp_delete_post( $id, true );
```

Notes for the reader and the reviewer (read the code against WooCommerce's behaviour; nothing here runs in PHPUnit):
- `product()` reads in the `'edit'` context everywhere so a variation never reports a value inherited from its parent (`get_manage_stock('edit')` is a bool; `'view'` can be `'parent'`).
- `apply()` writes through the CRUD objects and `save()`; it never calls a function that touches `post_status`, the product type or the slug. A variation's parent is re-synced (`WC_Product_Variable::sync`, `sync_stock_status`) and the product transients are cleared after variation rows are written.
- The stock setters are ordered on purpose (see the comment in `fill()`): a quantity of null and a stock status for a managed product are not written; WooCommerce derives them.
- Exceptions: a setter or `save()` may throw `WC_Data_Exception` (a taken SKU); the tool shows that text. Anything else is hidden by the tool.

- [ ] **Step 4: Keep the create_product live gate valid**

The gate script has an anonymous class implementing `ProductWriter`; it must delegate the four new methods:

**Edit 1 of 1 in `scripts/verify-pro-create-product.php`** - replace this exact text:

```php
            return $this->real->skuTaken( $sku );
        }

        public function termIds( string $taxonomy, array $slugs ): array
        {
            return $this->real->termIds( $taxonomy, $slugs );
```

with:

```php
            return $this->real->skuTaken( $sku );
        }

        public function skuOwner( string $sku ): int
        {
            return $this->real->skuOwner( $sku );
        }

        public function typeOf( int $id ): ?string
        {
            return $this->real->typeOf( $id );
        }

        public function product( int $id ): ?array
        {
            return $this->real->product( $id );
        }

        public function apply( int $id, array $changes ): void
        {
            $this->real->apply( $id, $changes );
        }

        public function termIds( string $taxonomy, array $slugs ): array
        {
            return $this->real->termIds( $taxonomy, $slugs );
```

- [ ] **Step 5: Syntax and suite**

Run: `php -l pro-addon/src/Products/ProductWriter.php && php -l pro-addon/src/Products/WooProductWriter.php && php -l pro-addon/tests/FakeProductWriter.php && php -l scripts/verify-pro-create-product.php` - Expected: `No syntax errors detected` four times.
Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK` (every existing test, `CreateProductTest` and `ProductKindsTest` included, still passes with the new fake).
Run: `grep -rn "implements ProductWriter" --include='*.php' pro-addon scripts tests` - Expected: exactly `WooProductWriter`, `FakeProductWriter`, and the anonymous class in `scripts/verify-pro-create-product.php`.

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src/Products/ProductWriter.php pro-addon/src/Products/WooProductWriter.php pro-addon/tests/FakeProductWriter.php scripts/verify-pro-create-product.php
git commit -m "$(cat <<'EOF'
feat(pro): ProductWriter reads and writes existing products (typeOf, product, apply, skuOwner) for update_products

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `ProductValuePolicy::checkPartial()` (update mode)

**Files:**
- Modify: `pro-addon/src/Products/ProductValuePolicy.php`
- Create: `pro-addon/tests/ProductValuePolicyPartialTest.php`

**Interfaces:**
- Consumes: `ProductFields::STOCK`, `ProductFields::ROW` (Task 1); the current state shape of `ProductWriter::product()` (Task 2).
- Produces: `ProductValuePolicy::UPDATE_KEYS`, `::MAX_VARIATIONS` (100) and

```php
ProductValuePolicy::checkPartial( array $update, array $current, \Closure $kses, \Closure $stripTags ): array{changes: ?array<string,mixed>, reasons: list<string>}
```

`$update` is one entry WITHOUT its `id`. `changes` is null when any reason exists, otherwise ONLY what differs from `$current`, under the keys of `product()` (scalars, `length`/`width`/`height`, `variations` = variation id => changed row fields), plus `category_slugs` / `tag_slugs` when given (the tool resolves them to ids and drops them when they match). Switching managed stock off adds `stock_quantity` null and `backorders` "no" when they differ. Existing `check()` keeps its behaviour; its name, SKU and gallery rules are moved into private helpers `validName`, `validSku`, `galleryList` that `checkPartial()` reuses.

- [ ] **Step 1: Write the tests**

Create `pro-addon/tests/ProductValuePolicyPartialTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Products\ProductValuePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/** The update mode of the product policy: only the given fields, judged against the product's current state. */
class ProductValuePolicyPartialTest extends TestCase
{
    private \Closure $kses;
    private \Closure $strip;

    protected function setUp(): void
    {
        $this->kses  = static fn ( string $s ): string => (string) preg_replace( '/&(?!#?\w+;)/', '&amp;', (string) preg_replace( '#<\s*(?:script|form)\b.*?>.*?<\s*/\s*(?:script|form)\s*>#is', '', $s ) );
        $this->strip = static fn ( string $s ): string => strip_tags( $s );
    }

    /** @return array<string,mixed> a published simple product with managed stock */
    private function current( array $over = [] ): array
    {
        return $over + [
            'type' => 'simple', 'status' => 'publish', 'name' => 'Blue Hat', 'description' => '<p>Warm</p>', 'short_description' => 'Warm', 'sku' => 'HAT-1',
            'regular_price' => '20', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => true, 'stock_quantity' => 12, 'stock_status' => 'instock',
            'backorders' => 'no', 'category_ids' => [ 3 ], 'category_slugs' => [ 'shirts' ], 'tag_ids' => [], 'tag_slugs' => [], 'image_id' => 0, 'gallery_image_ids' => [],
            'weight' => '', 'length' => '', 'width' => '', 'height' => '', 'tax_status' => 'taxable', 'variations' => [],
        ];
    }

    /** @return array<string,mixed> a variable product with two variation rows (11 managed, 12 not) */
    private function variable(): array
    {
        return $this->current( [
            'type' => 'variable', 'regular_price' => '', 'manage_stock' => false, 'stock_quantity' => null,
            'variations' => [
                11 => [ 'regular_price' => '10', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => true, 'stock_quantity' => 5, 'stock_status' => 'instock', 'backorders' => 'no' ],
                12 => [ 'regular_price' => '12', 'sale_price' => '9', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'outofstock', 'backorders' => 'no' ],
            ],
        ] );
    }

    /** @return array{changes: ?array<string,mixed>, reasons: list<string>} */
    private function check( array $update, ?array $current = null ): array
    {
        return ProductValuePolicy::checkPartial( $update, $current ?? $this->current(), $this->kses, $this->strip );
    }

    /** @return array<string,mixed> */
    private function ok( array $update, ?array $current = null ): array
    {
        $r = $this->check( $update, $current );
        $this->assertSame( [], $r['reasons'], json_encode( $r['reasons'] ) );
        $this->assertIsArray( $r['changes'] );

        return $r['changes'];
    }

    private function refused( array $update, string $needle, ?array $current = null ): void
    {
        $r = $this->check( $update, $current );
        $this->assertNull( $r['changes'], 'a refused update has no changes' );
        $this->assertNotSame( [], $r['reasons'] );
        $this->assertStringContainsString( $needle, implode( ' | ', $r['reasons'] ) );
    }

    // ---- keys and unchanged ---------------------------------------------------------------------

    public function testAnEmptyUpdateAndAnUpdateThatChangesNothingHaveNoChanges(): void
    {
        $this->assertSame( [], $this->ok( [] ) );
        $same = [ 'name' => 'Blue Hat', 'description' => '<p>Warm</p>', 'sku' => 'HAT-1', 'regular_price' => '20.00', 'manage_stock' => true, 'stock_quantity' => 12, 'tax_status' => 'taxable', 'image_id' => 0, 'gallery_image_ids' => [], 'weight' => '' ];
        $this->assertSame( [], $this->ok( $same ), 'every value equals the current one ("20.00" equals "20")' );
        $this->assertSame( [], $this->ok( [ 'name' => null, 'regular_price' => null ] ), 'null means omitted' );
    }

    public function testOnlyTheFieldsThatDifferAreReturned(): void
    {
        $this->assertSame( [ 'name' => 'Red Hat', 'regular_price' => '25' ], $this->ok( [ 'name' => 'Red Hat', 'regular_price' => '25', 'sku' => 'HAT-1' ] ) );
    }

    public function testUnknownKeysAreRefusedByNameAndStatusTypeSlugSayWhy(): void
    {
        $this->refused( [ 'colour' => 'red' ], '"colour" is not a supported field' );
        $this->refused( [ 'status' => 'publish' ], 'never changes a product\'s status, type or slug' );
        $this->refused( [ 'type' => 'variable' ], '"type" is not a supported field' );
        $this->refused( [ 'slug' => 'x' ], '"slug" is not a supported field' );
        $this->refused( [ 'virtual' => true ], '"virtual" is not a supported field' );
        $this->refused( [ 'id' => 5 ], '"id" is not a supported field' ); // the tool takes the id out before it asks the policy
        $this->refused( [ 0 => 'x' ], '"0" is not a supported field' );
    }

    // ---- name, texts, sku -----------------------------------------------------------------------

    /** @return array<string,array{mixed,string}> */
    public static function badNames(): array
    {
        return [
            'empty'       => [ '', '"name" must be a non-empty string' ],
            'blank'       => [ '   ', '"name" must be a non-empty string' ],
            'not string'  => [ 5, '"name" must be a non-empty string' ],
            'angle'       => [ 'A <b>hat</b>', '< or >' ],
            'backslash'   => [ 'A\\B', 'backslash' ],
            'padded'      => [ ' Hat', 'spaces' ],
            'control'     => [ "A\nB", 'control characters' ],
            'too long'    => [ str_repeat( 'x', 201 ), '200 characters' ],
        ];
    }

    #[DataProvider( 'badNames' )]
    public function testNameRulesAreTheOnesCreateUses( mixed $name, string $needle ): void
    {
        $this->refused( [ 'name' => $name ], $needle );
    }

    public function testAnAmpersandInTheNameIsTheSameNameAsTheStoredEncodedOne(): void
    {
        $this->assertSame( [], $this->ok( [ 'name' => 'Salt & Pepper' ], $this->current( [ 'name' => 'Salt &amp; Pepper' ] ) ) );
        $this->assertSame( [ 'name' => 'Salt & Pepper' ], $this->ok( [ 'name' => 'Salt & Pepper' ] ) );
    }

    public function testTextsCanBeClearedAndAreCheckedLikeCreate(): void
    {
        $this->assertSame( [ 'description' => '', 'short_description' => '' ], $this->ok( [ 'description' => '', 'short_description' => '' ] ) );
        $this->refused( [ 'description' => '<script>x</script>' ], 'script' );
        $this->refused( [ 'description' => '<form>x</form>' ], 'markup WordPress does not allow' );
        $this->refused( [ 'description' => 'a\\b' ], 'backslash' );
        $this->refused( [ 'short_description' => str_repeat( 'x', 1001 ) ], '1,000 characters' );
        $this->refused( [ 'description' => 5 ], '"description" must be a string' );
        $this->assertSame( [], $this->ok( [ 'description' => '<p>Warm</p>' ] ), 'the same text is no change' );
        $this->assertSame( [], $this->ok( [ 'description' => 'Fish & chips' ], $this->current( [ 'description' => 'Fish &amp; chips' ] ) ) );
    }

    public function testTheSku(): void
    {
        $this->assertSame( [ 'sku' => '' ], $this->ok( [ 'sku' => '' ] ), '"" clears it' );
        $this->assertSame( [ 'sku' => 'HAT-2' ], $this->ok( [ 'sku' => 'HAT-2' ] ) );
        $this->assertSame( [], $this->ok( [ 'sku' => 'HAT-1' ] ) );
        $this->assertSame( [ 'sku' => 'hat-1' ], $this->ok( [ 'sku' => 'hat-1' ] ), 'a change of case is a change (the tool allows it: the product owns the SKU)' );
        $this->refused( [ 'sku' => 'A<b' ], '< or >' );
        $this->refused( [ 'sku' => ' A' ], 'printable ASCII' );
        $this->refused( [ 'sku' => str_repeat( 'A', 101 ) ], 'printable ASCII' );
        $this->refused( [ 'sku' => 12 ], 'printable ASCII' );
    }

    // ---- prices and the sale window --------------------------------------------------------------

    public function testPrices(): void
    {
        $this->assertSame( [ 'regular_price' => '25.50' ], $this->ok( [ 'regular_price' => '25.50' ] ) );
        $this->assertSame( [ 'regular_price' => '', 'sale_price' => '' ], $this->ok( [ 'regular_price' => '', 'sale_price' => '' ], $this->current( [ 'sale_price' => '15' ] ) ), 'both cleared together' );
        $this->refused( [ 'regular_price' => '$20' ], 'decimal string' );
        $this->refused( [ 'regular_price' => 20 ], 'decimal string' );
        $this->refused( [ 'regular_price' => '1,000' ], 'decimal string' );
    }

    public function testASalePriceIsJudgedAgainstTheResultingRegularPrice(): void
    {
        $this->assertSame( [ 'sale_price' => '15' ], $this->ok( [ 'sale_price' => '15' ] ) );
        $this->refused( [ 'sale_price' => '20' ], 'lower than the "regular_price"' );
        $this->refused( [ 'sale_price' => '25' ], 'lower than the "regular_price"' );
        $this->refused( [ 'regular_price' => '10' ], 'lower than the "regular_price"', $this->current( [ 'sale_price' => '15' ] ) ); // lowering the regular price under an existing sale
        $this->assertSame( [ 'regular_price' => '30', 'sale_price' => '25' ], $this->ok( [ 'regular_price' => '30', 'sale_price' => '25' ], $this->current( [ 'sale_price' => '15' ] ) ) );
        $this->refused( [ 'sale_price' => '5' ], 'needs a "regular_price"', $this->current( [ 'regular_price' => '' ] ) );
        $this->refused( [ 'regular_price' => '' ], 'needs a "regular_price"', $this->current( [ 'sale_price' => '15' ] ) );
    }

    public function testAnUntouchedInconsistentPriceStateNeverBlocksAnUnrelatedChange(): void
    {
        $legacy = $this->current( [ 'regular_price' => '10', 'sale_price' => '15', 'sale_from' => '2026-01-01' ] ); // a sale above the regular price, from an old import
        $this->assertSame( [ 'name' => 'New' ], $this->ok( [ 'name' => 'New' ], $legacy ) );
        $this->assertSame( [ 'weight' => '2' ], $this->ok( [ 'weight' => '2' ], $legacy ) );
        $dates = $this->current( [ 'sale_from' => '2026-01-01', 'sale_to' => '2025-01-01' ] ); // sale dates without a sale price, the end before the start
        $this->assertSame( [ 'regular_price' => '25' ], $this->ok( [ 'regular_price' => '25' ], $dates ), 'a price change does not judge the sale window it did not touch' );
        $this->assertSame( [ 'manage_stock' => false, 'stock_quantity' => null ], $this->ok( [ 'manage_stock' => false ], $dates ) );
    }

    public function testTheSaleWindow(): void
    {
        $sale = $this->current( [ 'sale_price' => '15' ] );
        $this->assertSame( [ 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-30' ], $this->ok( [ 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-30' ], $sale ) );
        $this->assertSame( [ 'sale_price' => '15', 'sale_from' => '2026-11-01' ], $this->ok( [ 'sale_price' => '15', 'sale_from' => '2026-11-01' ] ) );
        $this->assertSame( [], $this->ok( [ 'sale_from' => '' ], $sale ), 'clearing an empty date is no change' );
        $this->assertSame( [ 'sale_from' => '' ], $this->ok( [ 'sale_from' => '' ], $this->current( [ 'sale_price' => '15', 'sale_from' => '2026-11-01' ] ) ) );
        $this->refused( [ 'sale_from' => '2026-11-01' ], 'sale dates need a "sale_price"' );
        $this->refused( [ 'sale_price' => '' ], 'sale dates need a "sale_price"', $this->current( [ 'sale_price' => '15', 'sale_to' => '2026-12-31' ] ) );
        $this->assertSame( [ 'sale_price' => '', 'sale_from' => '', 'sale_to' => '' ], $this->ok( [ 'sale_price' => '', 'sale_from' => '', 'sale_to' => '' ], $this->current( [ 'sale_price' => '15', 'sale_from' => '2026-11-01', 'sale_to' => '2026-12-31' ] ) ), 'a sale ends by clearing all three' );
        $this->refused( [ 'sale_from' => '2026-12-01', 'sale_to' => '2026-11-01' ], '"sale_to" must not be before "sale_from"', $sale );
        $this->refused( [ 'sale_to' => '2026-10-01' ], '"sale_to" must not be before "sale_from"', $this->current( [ 'sale_price' => '15', 'sale_from' => '2026-11-01' ] ) );
        $this->assertSame( [ 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-01' ], $this->ok( [ 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-01' ], $sale ), 'a one-day sale' );
    }

    #[DataProvider( 'badDates' )]
    public function testBadDatesAreRefused( mixed $date ): void
    {
        $this->refused( [ 'sale_from' => $date ], 'YYYY-MM-DD', $this->current( [ 'sale_price' => '15' ] ) );
    }

    /** @return array<string,array{mixed}> */
    public static function badDates(): array
    {
        return [ 'not a date' => [ 'soon' ], 'month 13' => [ '2026-13-01' ], 'feb 30' => [ '2026-02-30' ], 'with time' => [ '2026-11-01 10:00' ], 'a number' => [ 20261101 ], 'slashes' => [ '2026/11/01' ] ];
    }

    public function testAVariableProductHasNoPriceOfItsOwn(): void
    {
        foreach ( [ 'regular_price' => '5', 'sale_price' => '4', 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-02' ] as $key => $value ) {
            $this->refused( [ $key => $value ], "\"{$key}\" is not available on a variable product", $this->variable() );
        }
        $this->assertSame( [ 'name' => 'X' ], $this->ok( [ 'name' => 'X' ], $this->variable() ) );
    }

    // ---- stock ----------------------------------------------------------------------------------

    public function testManagedStock(): void
    {
        $this->assertSame( [ 'stock_quantity' => 3 ], $this->ok( [ 'stock_quantity' => 3 ] ) );
        $this->assertSame( [], $this->ok( [ 'stock_quantity' => 12 ] ) );
        $this->assertSame( [ 'stock_quantity' => 0 ], $this->ok( [ 'stock_quantity' => 0 ] ) );
        $this->assertSame( [ 'backorders' => 'notify' ], $this->ok( [ 'backorders' => 'notify' ] ) );
        $this->refused( [ 'stock_quantity' => -1 ], 'whole number from 0 to 1,000,000' );
        $this->refused( [ 'stock_quantity' => 1000001 ], 'whole number from 0 to 1,000,000' );
        $this->refused( [ 'stock_quantity' => '5' ], 'whole number from 0 to 1,000,000' );
        $this->refused( [ 'stock_quantity' => 2.5 ], 'whole number from 0 to 1,000,000' );
        $this->refused( [ 'stock_status' => 'outofstock' ], 'cannot be set while stock is managed' );
        $this->refused( [ 'backorders' => 'maybe' ], 'must be one of: no, notify, yes' );
        $this->refused( [ 'manage_stock' => 'yes' ], '"manage_stock" must be true or false' );
    }

    public function testUnmanagedStock(): void
    {
        $plain = $this->current( [ 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock' ] );
        $this->assertSame( [ 'stock_status' => 'outofstock' ], $this->ok( [ 'stock_status' => 'outofstock' ], $plain ) );
        $this->assertSame( [], $this->ok( [ 'stock_status' => 'instock', 'manage_stock' => false ], $plain ) );
        $this->refused( [ 'stock_quantity' => 3 ], 'needs managed stock', $plain );
        $this->refused( [ 'backorders' => 'yes' ], 'needs managed stock', $plain );
        $this->refused( [ 'stock_status' => 'sold' ], 'must be one of: instock, outofstock, onbackorder', $plain );
    }

    public function testSwitchingManagedStockOnNeedsAQuantityAndOffListsWhatWooCommerceClears(): void
    {
        $plain = $this->current( [ 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'outofstock' ] );
        $this->assertSame( [ 'manage_stock' => true, 'stock_quantity' => 4 ], $this->ok( [ 'manage_stock' => true, 'stock_quantity' => 4 ], $plain ) );
        $this->refused( [ 'manage_stock' => true ], 'managed stock needs a "stock_quantity"', $plain );

        $managed = $this->current( [ 'backorders' => 'notify' ] );
        $this->assertSame( [ 'manage_stock' => false, 'stock_quantity' => null, 'backorders' => 'no' ], $this->ok( [ 'manage_stock' => false ], $managed ) );
        $this->assertSame( [ 'manage_stock' => false, 'stock_quantity' => null, 'backorders' => 'no', 'stock_status' => 'outofstock' ], $this->ok( [ 'manage_stock' => false, 'stock_status' => 'outofstock' ], $managed ) );
        $this->assertSame( [ 'manage_stock' => false, 'stock_quantity' => null ], $this->ok( [ 'manage_stock' => false ], $this->current() ), 'backorders already "no": not listed' );
    }

    public function testExternalProductsHaveNoStockFields(): void
    {
        $external = $this->current( [ 'type' => 'external', 'manage_stock' => false, 'stock_quantity' => null ] );
        $this->refused( [ 'stock_status' => 'outofstock' ], '"stock_status" is not available on an external product', $external );
        $this->refused( [ 'manage_stock' => true, 'stock_quantity' => 1 ], 'not available on an external product', $external );
        $this->assertSame( [ 'regular_price' => '5' ], $this->ok( [ 'regular_price' => '5' ], $external ), 'prices are fine' );
    }

    // ---- catalogue data -------------------------------------------------------------------------

    public function testWeightAndDimensionsMergeAxisByAxis(): void
    {
        $boxed = $this->current( [ 'weight' => '2', 'length' => '10', 'width' => '5', 'height' => '2.5' ] );
        $this->assertSame( [ 'weight' => '' ], $this->ok( [ 'weight' => '' ], $boxed ) );
        $this->assertSame( [ 'length' => '12' ], $this->ok( [ 'dimensions' => [ 'length' => '12', 'width' => '5.00' ] ], $boxed ), 'only the axis that differs; "5.00" equals "5"' );
        $this->assertSame( [ 'height' => '' ], $this->ok( [ 'dimensions' => [ 'height' => '' ] ], $boxed ) );
        $this->refused( [ 'dimensions' => [ 'depth' => '1' ] ], 'only "length", "width" and "height"' );
        $this->refused( [ 'dimensions' => [ 1, 2 ] ], 'only "length", "width" and "height"' );
        $this->refused( [ 'dimensions' => [ 'length' => 'wide' ] ], 'decimal string' );
        $this->refused( [ 'weight' => 'heavy' ], 'decimal string' );
    }

    public function testTaxStatusAndImages(): void
    {
        $this->assertSame( [ 'tax_status' => 'none' ], $this->ok( [ 'tax_status' => 'none' ] ) );
        $this->refused( [ 'tax_status' => 'free' ], 'must be one of: taxable, shipping, none' );
        $this->assertSame( [ 'image_id' => 90, 'gallery_image_ids' => [ 91, 92 ] ], $this->ok( [ 'image_id' => 90, 'gallery_image_ids' => [ 91, 92 ] ] ) );
        $this->assertSame( [ 'image_id' => 0 ], $this->ok( [ 'image_id' => 0 ], $this->current( [ 'image_id' => 90 ] ) ), '0 removes the image' );
        $this->assertSame( [], $this->ok( [ 'image_id' => 90, 'gallery_image_ids' => [ 91 ] ], $this->current( [ 'image_id' => 90, 'gallery_image_ids' => [ 91 ] ] ) ) );
        $this->assertSame( [ 'gallery_image_ids' => [] ], $this->ok( [ 'gallery_image_ids' => [] ], $this->current( [ 'gallery_image_ids' => [ 91 ] ] ) ) );
        $this->refused( [ 'image_id' => -1 ], 'id of an image in the Media Library' );
        $this->refused( [ 'image_id' => '90' ], 'id of an image in the Media Library' );
        $this->refused( [ 'gallery_image_ids' => [ 1, 1 ] ], 'at most 10 different image ids' );
        $this->refused( [ 'gallery_image_ids' => [ 0 ] ], 'whole image ids from 1' );
        $this->refused( [ 'gallery_image_ids' => range( 1, 11 ) ], 'at most 10 different image ids' );
    }

    public function testCategoryAndTagSlugsArePassedOnForTheToolToResolve(): void
    {
        $this->assertSame( [ 'category_slugs' => [ 'hats', 'shirts' ], 'tag_slugs' => [ 'sale' ] ], $this->ok( [ 'categories' => [ 'hats', 'shirts' ], 'tags' => [ 'sale' ] ] ) );
        $this->assertSame( [ 'category_slugs' => [] ], $this->ok( [ 'categories' => [] ] ), 'an empty list is a request to clear (the tool compares ids)' );
        $this->refused( [ 'categories' => 'hats' ], 'list of at most 10 different slugs' );
        $this->refused( [ 'tags' => [ 'a b' ] ], 'only slugs' );
        $this->refused( [ 'categories' => [ 'a', 'a' ] ], 'different slugs' );
        $this->refused( [ 'tags' => array_map( static fn ( int $i ): string => "t{$i}", range( 1, 21 ) ) ], 'at most 20 different slugs' );
    }

    public function testEveryReasonIsReportedAtOnce(): void
    {
        $r = $this->check( [ 'name' => '', 'sale_price' => '99', 'stock_status' => 'outofstock', 'colour' => 1 ] );
        $this->assertNull( $r['changes'] );
        $this->assertGreaterThanOrEqual( 4, count( $r['reasons'] ) );
    }

    // ---- variation rows -------------------------------------------------------------------------

    public function testVariationRowsReturnOnlyWhatChanges(): void
    {
        $changes = $this->ok( [ 'variations' => [
            [ 'id' => 11, 'regular_price' => '11', 'stock_quantity' => 5 ],
            [ 'id' => 12, 'sale_price' => '8', 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-30' ],
        ] ], $this->variable() );
        $this->assertSame( [ 11 => [ 'regular_price' => '11' ], 12 => [ 'sale_price' => '8', 'sale_from' => '2026-11-01', 'sale_to' => '2026-11-30' ] ], $changes['variations'] );
    }

    public function testAVariationRowThatChangesNothingIsDroppedAndSoIsTheKey(): void
    {
        $this->assertSame( [], $this->ok( [ 'variations' => [ [ 'id' => 11, 'regular_price' => '10.00' ] ] ], $this->variable() ) );
    }

    public function testVariationRowsFollowTheSameRelativeRulesPerRowAndNameTheRow(): void
    {
        $this->refused( [ 'variations' => [ [ 'id' => 11, 'sale_price' => '10' ] ] ], 'variation 11: the resulting "sale_price" must be lower than the "regular_price"', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => 12, 'stock_quantity' => 3 ] ] ], 'variation 12: "stock_quantity" needs managed stock', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => 11, 'stock_status' => 'outofstock' ] ] ], 'variation 11: "stock_status" cannot be set while stock is managed', $this->variable() );
        $this->assertSame( [ 12 => [ 'manage_stock' => true, 'stock_quantity' => 9 ] ], $this->ok( [ 'variations' => [ [ 'id' => 12, 'manage_stock' => true, 'stock_quantity' => 9 ] ] ], $this->variable() )['variations'] );
        $this->assertSame( [ 11 => [ 'manage_stock' => false, 'stock_quantity' => null ] ], $this->ok( [ 'variations' => [ [ 'id' => 11, 'manage_stock' => false ] ] ], $this->variable() )['variations'] );
    }

    public function testVariationRowsMustBelongToTheProductAndBeWellFormed(): void
    {
        $this->refused( [ 'variations' => [ [ 'id' => 99, 'regular_price' => '1' ] ] ], 'variation 99 does not belong to this product', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => 11, 'regular_price' => '1' ], [ 'id' => 11, 'regular_price' => '2' ] ] ], 'variation 11 appears more than once', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'regular_price' => '1' ] ] ], '"id" must be a whole variation id', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => '11' ] ] ], '"id" must be a whole variation id', $this->variable() );
        $this->refused( [ 'variations' => [ 5 ] ], 'must be an object', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => 11, 'sku' => 'X' ] ] ], 'variation 11: "sku" is not a supported field', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => 11, 'status' => 'private' ] ] ], 'variation 11: "status" is not a supported field', $this->variable() );
        $this->refused( [ 'variations' => [] ], '1 to 100 objects', $this->variable() );
        $this->refused( [ 'variations' => [ 'id' => 11 ] ], '1 to 100 objects', $this->variable() );
        $this->refused( [ 'variations' => [ [ 'id' => 11, 'regular_price' => '1' ] ] ], 'only for variable products' );
    }

    public function testAtMostOneHundredVariationRowsPerProduct(): void
    {
        $many = $this->variable();
        for ( $i = 100; $i < 201; $i++ ) {
            $many['variations'][ $i ] = $many['variations'][11];
        }
        $rows = [];
        for ( $i = 100; $i < 201; $i++ ) {
            $rows[] = [ 'id' => $i, 'regular_price' => '11' ];
        }
        $this->refused( [ 'variations' => $rows ], '1 to 100 objects', $many );
        $this->assertCount( 100, $this->ok( [ 'variations' => array_slice( $rows, 0, 100 ) ], $many )['variations'] );
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter ProductValuePolicyPartialTest`
Expected: errors `Call to undefined method ...ProductValuePolicy::checkPartial()`.

- [ ] **Step 3: Implement**

The five edits below add the constants, move three existing rules into helpers (messages unchanged) and add `checkPartial()` with its private helpers. Apply them in order; each "replace this exact text" occurs exactly once in the current file.

**Edit 1 of 5 in `pro-addon/src/Products/ProductValuePolicy.php`** - replace this exact text:

```php
        'backorders', 'categories', 'tags', 'image_id', 'gallery_image_ids', 'weight', 'dimensions', 'tax_status', 'virtual', 'sold_individually',
    ];

    public const MAX_NAME        = 200;
    public const MAX_DESCRIPTION = 10000;
    public const MAX_SHORT       = 1000;
```

with:

```php
        'backorders', 'categories', 'tags', 'image_id', 'gallery_image_ids', 'weight', 'dimensions', 'tax_status', 'virtual', 'sold_individually',
    ];

    /** The fields an update_products entry may carry besides the product `id`. */
    public const UPDATE_KEYS = [
        'name', 'description', 'short_description', 'sku', 'regular_price', 'sale_price', 'sale_from', 'sale_to', 'manage_stock', 'stock_quantity',
        'stock_status', 'backorders', 'categories', 'tags', 'image_id', 'gallery_image_ids', 'weight', 'dimensions', 'tax_status', 'variations',
    ];

    public const MAX_VARIATIONS  = 100;
    public const MAX_NAME        = 200;
    public const MAX_DESCRIPTION = 10000;
    public const MAX_SHORT       = 1000;
```

**Edit 2 of 5 in `pro-addon/src/Products/ProductValuePolicy.php`** - replace this exact text:

```php
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
        } elseif ( trim( $name ) !== $name ) {
            $r[] = '"name" must not start or end with spaces';
        } elseif ( str_contains( $name, '<' ) || str_contains( $name, '>' ) ) {
            // Core's title filter (kses, for a user without unfiltered_html) entity-encodes a lone ">" and cuts the name at a "<".
            $r[] = '"name" must not contain < or >';
        } elseif ( str_contains( $name, '\\' ) ) {
            $r[] = '"name" ' . self::BACKSLASH;
        } elseif ( $stripTags( $name ) !== $name ) {
            $r[] = '"name" must be plain text (no HTML tags)';
        } else {
            $v['name'] = $name;
        }

        $v['description']       = self::text( $product, 'description', self::MAX_DESCRIPTION, $kses, $r );
        $v['short_description'] = self::text( $product, 'short_description', self::MAX_SHORT, $kses, $r );
```

with:

```php
        }

        // name
        $v['name'] = self::validName( $product['name'] ?? null, $stripTags, '"name" is required and must be a non-empty string', $r ) ?? '';

        $v['description']       = self::text( $product, 'description', self::MAX_DESCRIPTION, $kses, $r );
        $v['short_description'] = self::text( $product, 'short_description', self::MAX_SHORT, $kses, $r );
```

**Edit 3 of 5 in `pro-addon/src/Products/ProductValuePolicy.php`** - replace this exact text:

```php
        // sku
        $v['sku'] = '';
        if ( self::given( $product, 'sku' ) ) {
            $sku = $product['sku'];
            if ( ! is_string( $sku ) || 1 !== preg_match( '/^[\x20-\x7E]{1,100}$/D', $sku ) || trim( $sku ) !== $sku ) {
                $r[] = '"sku" must be 1 to 100 printable ASCII characters without leading or trailing spaces';
            } elseif ( str_contains( $sku, '<' ) || str_contains( $sku, '>' ) ) {
                // WooCommerce's own SKU input runs wc_clean(), and its product page prints the SKU unescaped: never store markup there.
                $r[] = '"sku" must not contain < or >';
            } else {
                $v['sku'] = $sku;
            }
        }

        $v['regular_price'] = self::decimal( $product, 'regular_price', $r );
```

with:

```php
        // sku
        $v['sku'] = '';
        if ( self::given( $product, 'sku' ) ) {
            $v['sku'] = self::validSku( $product['sku'], $r ) ?? '';
        }

        $v['regular_price'] = self::decimal( $product, 'regular_price', $r );
```

**Edit 4 of 5 in `pro-addon/src/Products/ProductValuePolicy.php`** - replace this exact text:

```php
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
```

with:

```php
        }
        $v['gallery_image_ids'] = [];
        if ( self::given( $product, 'gallery_image_ids' ) ) {
            $v['gallery_image_ids'] = self::galleryList( $product['gallery_image_ids'], $r ) ?? [];
        }

        $v['weight'] = self::decimal( $product, 'weight', $r );
```

**Edit 5 of 5 in `pro-addon/src/Products/ProductValuePolicy.php`** - replace this exact text:

```php
        $v['sold_individually'] = self::flag( $product, 'sold_individually', $r );

        return [ 'values' => [] === $r ? $v : null, 'reasons' => $r ];
    }

    /**
```

with:

```php
        $v['sold_individually'] = self::flag( $product, 'sold_individually', $r );

        return [ 'values' => [] === $r ? $v : null, 'reasons' => $r ];
    }

    /**
     * The update-mode twin of check(): the given fields of ONE existing product, judged against its current state (ProductWriter::product()).
     * Pure, like check(). An omitted field (or null) is left alone; `""` clears a text, the SKU, a price, a sale date or the weight.
     * Relative rules (a sale price below the regular price, sale dates need a sale price, a quantity needs managed stock) are checked
     * against the RESULTING state, and only for the group the update touches, so an untouched legacy inconsistency never blocks an
     * unrelated change.
     *
     * `changes` holds ONLY what differs from the current state, under the keys of product(): scalar fields, `length`/`width`/`height`,
     * `variations` (variation id => changed row fields), and `category_slugs` / `tag_slugs` when given (the tool resolves them to ids
     * and drops them when they match). Switching managed stock off also lists the values WooCommerce derives (`stock_quantity` null,
     * `backorders` "no") so that the preview and the undo show them.
     *
     * @param array<string,mixed> $update the entry without its `id`
     * @param array<string,mixed> $current ProductWriter::product()
     * @param \Closure(string):string $kses wp_kses_post
     * @param \Closure(string):string $stripTags wp_strip_all_tags
     * @return array{changes: ?array<string,mixed>, reasons: list<string>}
     */
    public static function checkPartial( array $update, array $current, \Closure $kses, \Closure $stripTags ): array
    {
        $r    = [];
        $c    = [];
        $type = (string) ( $current['type'] ?? '' );

        foreach ( array_keys( $update ) as $key ) {
            if ( ! is_string( $key ) || ! in_array( $key, self::UPDATE_KEYS, true ) ) {
                $note = in_array( $key, [ 'status', 'type', 'slug' ], true ) ? " (this tool never changes a product's status, type or slug)" : '';
                $r[]  = '"' . self::shown( (string) $key ) . '" is not a supported field' . $note;
            }
        }

        if ( self::given( $update, 'name' ) ) {
            $name = self::validName( $update['name'], $stripTags, '"name" must be a non-empty string', $r );
            if ( null !== $name && ! self::sameText( $name, (string) ( $current['name'] ?? '' ) ) ) {
                $c['name'] = $name;
            }
        }
        foreach ( [ 'description' => self::MAX_DESCRIPTION, 'short_description' => self::MAX_SHORT ] as $key => $max ) {
            if ( self::given( $update, $key ) ) {
                $before = count( $r );
                $text   = self::text( $update, $key, $max, $kses, $r );
                if ( count( $r ) === $before && ! self::sameText( $text, (string) ( $current[ $key ] ?? '' ) ) ) {
                    $c[ $key ] = $text;
                }
            }
        }
        if ( self::given( $update, 'sku' ) ) {
            $sku = '' === $update['sku'] ? '' : self::validSku( $update['sku'], $r );
            if ( null !== $sku && $sku !== (string) ( $current['sku'] ?? '' ) ) {
                $c['sku'] = $sku;
            }
        }

        // Prices, the sale window and stock. A variable product keeps its prices in its variation rows.
        $own = $update;
        if ( 'variable' === $type ) {
            foreach ( [ 'regular_price', 'sale_price', 'sale_from', 'sale_to' ] as $key ) {
                if ( self::given( $update, $key ) ) {
                    $r[] = "\"{$key}\" is not available on a variable product (change the prices of its variations instead)";
                }
                unset( $own[ $key ] );
            }
        }
        $c += self::rowChanges( $own, $current, 'external' !== $type, '', $r );

        // Texts and catalogue data.
        if ( self::given( $update, 'weight' ) ) {
            $weight = self::decimalOrClear( $update['weight'], '"weight"', $r );
            if ( null !== $weight && ! self::sameValue( 'weight', $weight, (string) ( $current['weight'] ?? '' ) ) ) {
                $c['weight'] = $weight;
            }
        }
        if ( self::given( $update, 'dimensions' ) ) {
            $d = $update['dimensions'];
            if ( ! is_array( $d ) || array_is_list( $d ) || [] !== array_diff( array_keys( $d ), [ 'length', 'width', 'height' ] ) ) {
                $r[] = '"dimensions" must be an object with only "length", "width" and "height"';
            } else {
                foreach ( [ 'length', 'width', 'height' ] as $axis ) {
                    if ( ! array_key_exists( $axis, $d ) || null === $d[ $axis ] ) {
                        continue;
                    }
                    $value = self::decimalOrClear( $d[ $axis ], "\"dimensions\" {$axis}", $r );
                    if ( null !== $value && ! self::sameValue( $axis, $value, (string) ( $current[ $axis ] ?? '' ) ) ) {
                        $c[ $axis ] = $value;
                    }
                }
            }
        }
        if ( self::given( $update, 'tax_status' ) ) {
            $tax = self::enum( $update, 'tax_status', self::TAX_STATUSES, (string) ( $current['tax_status'] ?? 'taxable' ), $r );
            if ( $tax !== (string) ( $current['tax_status'] ?? 'taxable' ) ) {
                $c['tax_status'] = $tax;
            }
        }
        if ( self::given( $update, 'image_id' ) ) {
            if ( is_int( $update['image_id'] ) && $update['image_id'] >= 0 ) {
                if ( $update['image_id'] !== (int) ( $current['image_id'] ?? 0 ) ) {
                    $c['image_id'] = $update['image_id'];
                }
            } else {
                $r[] = '"image_id" must be the id of an image in the Media Library, or 0 to remove the image';
            }
        }
        if ( self::given( $update, 'gallery_image_ids' ) ) {
            $gallery = self::galleryList( $update['gallery_image_ids'], $r );
            if ( null !== $gallery && $gallery !== array_map( 'intval', array_values( (array) ( $current['gallery_image_ids'] ?? [] ) ) ) ) {
                $c['gallery_image_ids'] = $gallery;
            }
        }
        if ( self::given( $update, 'categories' ) ) {
            $c['category_slugs'] = self::slugs( $update, 'categories', self::MAX_CATEGORIES, $r );
        }
        if ( self::given( $update, 'tags' ) ) {
            $c['tag_slugs'] = self::slugs( $update, 'tags', self::MAX_TAGS, $r );
        }

        // Variation rows (variable products only).
        if ( self::given( $update, 'variations' ) ) {
            $rows = [];
            self::variationChanges( $update['variations'], $current, $type, $rows, $r );
            if ( [] !== $rows ) {
                $c['variations'] = $rows;
            }
        }

        return [ 'changes' => [] === $r ? $c : null, 'reasons' => $r ];
    }

    /**
     * @param array<string,mixed> $current
     * @param array<int,array<string,mixed>> $out
     * @param list<string> $r
     */
    private static function variationChanges( mixed $list, array $current, string $type, array &$out, array &$r ): void
    {
        if ( 'variable' !== $type ) {
            $r[] = '"variations" is only for variable products';

            return;
        }
        if ( ! is_array( $list ) || ! array_is_list( $list ) || [] === $list || count( $list ) > self::MAX_VARIATIONS ) {
            $r[] = '"variations" must be a list of 1 to ' . self::MAX_VARIATIONS . ' objects';

            return;
        }
        $seen = [];
        foreach ( $list as $i => $row ) {
            $n = $i + 1;
            if ( ! is_array( $row ) || array_is_list( $row ) ) {
                $r[] = "variations row {$n} must be an object with the variation \"id\"";
                continue;
            }
            $vid = $row['id'] ?? null;
            if ( ! is_int( $vid ) || $vid < 1 ) {
                $r[] = "variations row {$n}: \"id\" must be a whole variation id";
                continue;
            }
            if ( ! isset( $current['variations'][ $vid ] ) ) {
                $r[] = "variation {$vid} does not belong to this product";
                continue;
            }
            if ( isset( $seen[ $vid ] ) ) {
                $r[] = "variation {$vid} appears more than once";
                continue;
            }
            $seen[ $vid ] = true;
            foreach ( array_keys( $row ) as $key ) {
                if ( 'id' !== $key && ! in_array( $key, ProductFields::ROW, true ) ) {
                    $r[] = "variation {$vid}: \"" . self::shown( (string) $key ) . '" is not a supported field';
                }
            }
            $changes = self::rowChanges( $row, $current['variations'][ $vid ], true, "variation {$vid}: ", $r );
            if ( [] !== $changes ) {
                $out[ $vid ] = $changes;
            }
        }
    }

    /**
     * The price, sale window and stock part, shared by a product and a variation row: returns the fields of ProductFields::ROW that
     * differ from `$cur`. Reasons are prefixed with `$at` (for example "variation 12: ").
     *
     * @param array<string,mixed> $in
     * @param array<string,mixed> $cur
     * @param list<string> $r
     * @return array<string,mixed>
     */
    private static function rowChanges( array $in, array $cur, bool $stockAllowed, string $at, array &$r ): array
    {
        $rr  = [];
        $c   = [];
        $new = [];
        foreach ( [ 'regular_price', 'sale_price' ] as $key ) {
            if ( self::given( $in, $key ) ) {
                $value = self::decimalOrClear( $in[ $key ], "\"{$key}\"", $rr );
                if ( null !== $value ) {
                    $new[ $key ] = $value;
                }
            }
        }
        foreach ( [ 'sale_from', 'sale_to' ] as $key ) {
            if ( self::given( $in, $key ) ) {
                $value = self::dateOrClear( $in[ $key ], "\"{$key}\"", $rr );
                if ( null !== $value ) {
                    $new[ $key ] = $value;
                }
            }
        }
        if ( [] !== $new ) {
            $res = $new + [
                'regular_price' => (string) ( $cur['regular_price'] ?? '' ), 'sale_price' => (string) ( $cur['sale_price'] ?? '' ),
                'sale_from'     => (string) ( $cur['sale_from'] ?? '' ), 'sale_to' => (string) ( $cur['sale_to'] ?? '' ),
            ];
            if ( ( isset( $new['regular_price'] ) || isset( $new['sale_price'] ) ) && '' !== $res['sale_price'] ) {
                if ( '' === $res['regular_price'] ) {
                    $rr[] = 'the resulting "sale_price" needs a "regular_price"';
                } elseif ( self::compareDecimals( $res['sale_price'], $res['regular_price'] ) >= 0 ) {
                    $rr[] = 'the resulting "sale_price" must be lower than the "regular_price"';
                }
            }
            if ( isset( $new['sale_price'] ) || isset( $new['sale_from'] ) || isset( $new['sale_to'] ) ) {
                if ( ( '' !== $res['sale_from'] || '' !== $res['sale_to'] ) && '' === $res['sale_price'] ) {
                    $rr[] = 'sale dates need a "sale_price" (to end a sale, clear "sale_price", "sale_from" and "sale_to" together)';
                }
                if ( '' !== $res['sale_from'] && '' !== $res['sale_to'] && $res['sale_to'] < $res['sale_from'] ) {
                    $rr[] = '"sale_to" must not be before "sale_from"';
                }
            }
            foreach ( $new as $key => $value ) {
                if ( ! self::sameValue( $key, $value, (string) ( $cur[ $key ] ?? '' ) ) ) {
                    $c[ $key ] = $value;
                }
            }
        }

        $given = array_values( array_filter( ProductFields::STOCK, static fn ( string $key ): bool => self::given( $in, $key ) ) );
        if ( [] !== $given && ! $stockAllowed ) {
            foreach ( $given as $key ) {
                $rr[] = "\"{$key}\" is not available on an external product";
            }
        } elseif ( [] !== $given ) {
            $curManage = (bool) ( $cur['manage_stock'] ?? false );
            $manage    = $curManage;
            if ( self::given( $in, 'manage_stock' ) ) {
                if ( is_bool( $in['manage_stock'] ) ) {
                    $manage = $in['manage_stock'];
                } else {
                    $rr[] = '"manage_stock" must be true or false';
                }
            }
            if ( $manage ) {
                $qty = $curManage ? ( $cur['stock_quantity'] ?? null ) : null;
                if ( self::given( $in, 'stock_quantity' ) ) {
                    if ( is_int( $in['stock_quantity'] ) && $in['stock_quantity'] >= 0 && $in['stock_quantity'] <= self::MAX_STOCK ) {
                        $qty = $in['stock_quantity'];
                    } else {
                        $rr[] = '"stock_quantity" must be a whole number from 0 to 1,000,000';
                    }
                } elseif ( null === $qty ) {
                    $rr[] = 'managed stock needs a "stock_quantity"';
                }
                if ( self::given( $in, 'stock_status' ) ) {
                    $rr[] = '"stock_status" cannot be set while stock is managed (WooCommerce works it out from the quantity)';
                }
                $backorders = self::enum( $in, 'backorders', self::BACKORDERS, (string) ( $cur['backorders'] ?? 'no' ), $rr );
                if ( $manage !== $curManage ) {
                    $c['manage_stock'] = $manage;
                }
                if ( null !== $qty && ( ! $curManage || $qty !== ( $cur['stock_quantity'] ?? null ) ) ) {
                    $c['stock_quantity'] = $qty;
                }
                if ( $backorders !== (string) ( $cur['backorders'] ?? 'no' ) ) {
                    $c['backorders'] = $backorders;
                }
            } else {
                foreach ( [ 'stock_quantity', 'backorders' ] as $key ) {
                    if ( self::given( $in, $key ) ) {
                        $rr[] = "\"{$key}\" needs managed stock (\"manage_stock\": true)";
                    }
                }
                $status = self::enum( $in, 'stock_status', self::STOCK_STATUSES, (string) ( $cur['stock_status'] ?? 'instock' ), $rr );
                if ( $curManage ) {
                    // Switching managed stock off: list what WooCommerce clears so the preview and the undo show it.
                    $c['manage_stock'] = false;
                    if ( null !== ( $cur['stock_quantity'] ?? null ) ) {
                        $c['stock_quantity'] = null;
                    }
                    if ( 'no' !== (string) ( $cur['backorders'] ?? 'no' ) ) {
                        $c['backorders'] = 'no';
                    }
                }
                if ( $status !== (string) ( $cur['stock_status'] ?? 'instock' ) ) {
                    $c['stock_status'] = $status;
                }
            }
        }

        foreach ( $rr as $reason ) {
            $r[] = $at . $reason;
        }

        return $c;
    }

    /** Decimal strings compare as numbers ("20" equals "20.00"), everything else exactly. */
    private static function sameValue( string $key, string $new, string $old ): bool
    {
        $decimal = in_array( $key, [ 'regular_price', 'sale_price', 'weight', 'length', 'width', 'height' ], true );

        return $decimal && '' !== $new && '' !== $old ? 0 === self::compareDecimals( $new, $old ) : $new === $old;
    }

    /** @param list<string> $r @return ?string the decimal, "" to clear it, or null (with a reason) when it is neither */
    private static function decimalOrClear( mixed $value, string $label, array &$r ): ?string
    {
        if ( '' === $value ) {
            return '';
        }
        if ( is_string( $value ) && 1 === preg_match( self::DECIMAL, $value ) ) {
            return $value;
        }
        $r[] = "{$label} must be a decimal string such as \"20\" or \"20.50\" (no currency symbol or thousands separator), or \"\" to clear it";

        return null;
    }

    /** @param list<string> $r @return ?string a real calendar date (YYYY-MM-DD), "" to clear it, or null (with a reason) */
    private static function dateOrClear( mixed $value, string $label, array &$r ): ?string
    {
        if ( '' === $value ) {
            return '';
        }
        if ( is_string( $value ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
            return $value;
        }
        $r[] = "{$label} must be a date such as \"2026-12-31\" (YYYY-MM-DD), or \"\" to clear it";

        return null;
    }

    /** @param list<string> $r @return ?string the name when it is allowed, otherwise null and a reason */
    private static function validName( mixed $name, \Closure $stripTags, string $emptyMessage, array &$r ): ?string
    {
        if ( ! is_string( $name ) || '' === trim( $name ) ) {
            $r[] = $emptyMessage;
        } elseif ( 1 !== preg_match( '//u', $name ) ) {
            $r[] = '"name" is not valid UTF-8';
        } elseif ( mb_strlen( $name ) > self::MAX_NAME ) {
            $r[] = '"name" is longer than 200 characters';
        } elseif ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
            $r[] = '"name" must not contain control characters';
        } elseif ( trim( $name ) !== $name ) {
            $r[] = '"name" must not start or end with spaces';
        } elseif ( str_contains( $name, '<' ) || str_contains( $name, '>' ) ) {
            // Core's title filter (kses, for a user without unfiltered_html) entity-encodes a lone ">" and cuts the name at a "<".
            $r[] = '"name" must not contain < or >';
        } elseif ( str_contains( $name, '\\' ) ) {
            $r[] = '"name" ' . self::BACKSLASH;
        } elseif ( $stripTags( $name ) !== $name ) {
            $r[] = '"name" must be plain text (no HTML tags)';
        } else {
            return $name;
        }

        return null;
    }

    /** @param list<string> $r @return ?string the SKU when it is allowed, otherwise null and a reason */
    private static function validSku( mixed $sku, array &$r ): ?string
    {
        if ( ! is_string( $sku ) || 1 !== preg_match( '/^[\x20-\x7E]{1,100}$/D', $sku ) || trim( $sku ) !== $sku ) {
            $r[] = '"sku" must be 1 to 100 printable ASCII characters without leading or trailing spaces';
        } elseif ( str_contains( $sku, '<' ) || str_contains( $sku, '>' ) ) {
            // WooCommerce's own SKU input runs wc_clean(), and its product page prints the SKU unescaped: never store markup there.
            $r[] = '"sku" must not contain < or >';
        } else {
            return $sku;
        }

        return null;
    }

    /** @param list<string> $r @return ?list<int> */
    private static function galleryList( mixed $g, array &$r ): ?array
    {
        if ( ! is_array( $g ) || ! array_is_list( $g ) || count( $g ) > self::MAX_GALLERY || count( $g ) !== count( array_unique( $g, SORT_REGULAR ) ) ) {
            $r[] = '"gallery_image_ids" must be a list of at most 10 different image ids';

            return null;
        }
        foreach ( $g as $id ) {
            if ( ! is_int( $id ) || $id < 1 ) {
                $r[] = '"gallery_image_ids" must contain only whole image ids from 1';

                return null;
            }
        }

        return array_values( $g );
    }

    /**
```

- [ ] **Step 4: Run the new tests and the create-mode tests**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter 'ProductValuePolicy'` - Expected: `OK (...)` for `ProductValuePolicyTest` (create mode, unchanged) and `ProductValuePolicyPartialTest` (38 tests).
Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK`.

- [ ] **Step 5: RED proof (do it, then revert)**

In `rowChanges()` replace `if ( isset( $new['sale_price'] ) || isset( $new['sale_from'] ) || isset( $new['sale_to'] ) ) {` with `if ( true ) {`. Expected: `testAnUntouchedInconsistentPriceStateNeverBlocksAnUnrelatedChange` fails. Revert.

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src/Products/ProductValuePolicy.php pro-addon/tests/ProductValuePolicyPartialTest.php
git commit -m "$(cat <<'EOF'
feat(pro): ProductValuePolicy::checkPartial for update_products (only what changes, relative rules on the resulting state)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: `ProductFieldsKind` (the `product_fields` batch item)

**Files:**
- Create: `pro-addon/src/Kinds/ProductFieldsKind.php`
- Create: `pro-addon/tests/ProductFieldsKindTest.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Batch/Batches.php`, `pro-addon/tests/ToolsTest.php` (the pinned list of registered kinds)

**Interfaces:**
- Consumes: `ProductFields::pick/asChanges`, `ProductDiff::compare` (Task 1); `ProductWriter::product/apply` and `FakeProductWriter` (Task 2); `Item::make`, `ItemKind` (existing).
- Produces: `ProductFieldsKind::NAME` (`'product_fields'`), `ProductFieldsKind::itemFor( int $id, string $name, string $what, array $before, array $after ): array` (`$before`/`$after` are VALUE shapes; the item's `before` is `$before`, its `after_hash` is `Canon::hash( $after )`), and the `ItemKind` methods (`current`, `allowed`, `restore`). Restore failure reasons: `missing`, `variation_missing`, `save_failed`, `restore_differs`.

- [ ] **Step 1: Write the tests**

Create `pro-addon/tests/ProductFieldsKindTest.php`:

```php
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
        $id = $this->record( $item );

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
        $this->assertEqualsCanonicalizing( [ 'manage_stock', 'stock_quantity', 'stock_status', 'backorders' ], array_keys( $item['before']['fields'] ), 'the whole stock group is recorded' );
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
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter ProductFieldsKindTest`
Expected: errors `Class "AiEditorDivi5\Pro\Kinds\ProductFieldsKind" not found`.

- [ ] **Step 3: Create the kind**

Create `pro-addon/src/Kinds/ProductFieldsKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\Products\ProductDiff;
use AiEditorDivi5\Pro\Products\ProductFields;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * What update_products changed on one product. `ref` is the product id. `before` is a shape (see ProductFields) holding the PREVIOUS values
 * of only the fields the run changed (plus the whole stock group where manage_stock changed, and per variation row only the fields that
 * row changed); `after_hash` is the hash of those same fields as read back after the save. Undo puts the previous values back, but only
 * while those fields still hold what the run wrote (the changed-since rule); `force` skips that rule, never the permission or the
 * existence checks. After a restore the fields are read back and compared; the inverse item records the values the restore replaced, so
 * the undo can itself be undone.
 */
final class ProductFieldsKind implements ItemKind
{
    public const NAME = 'product_fields';

    public function __construct( private ?ProductWriter $writer = null )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string,mixed> $before value shape: the previous values
     * @param array<string,mixed> $after value shape: the same fields as read back after the save
     * @return array<string,mixed>
     */
    public static function itemFor( int $id, string $name, string $what, array $before, array $after ): array
    {
        return Item::make( self::NAME, (string) $id, "Updated product \"{$name}\" (#{$id}): {$what}", $before, $after );
    }

    public function current( array $item ): mixed
    {
        $state = $this->writer()->product( (int) $item['ref'] );

        return null === $state ? null : ProductFields::pick( $state, self::shape( $item ) );
    }

    public function allowed( array $item ): bool
    {
        $id = (int) $item['ref'];
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return false;
        }
        $fields = array_keys( self::shape( $item )['fields'] );

        return [] === array_intersect( $fields, [ 'category_ids', 'tag_ids' ] ) || current_user_can( 'assign_product_terms' );
    }

    public function restore( array $item ): array
    {
        $id     = (int) $item['ref'];
        $writer = $this->writer();
        $before = self::shape( $item );
        $state  = $writer->product( $id );
        if ( null === $state ) {
            return [ 'ok' => false, 'reason' => 'missing' ];
        }
        foreach ( array_keys( $before['variations'] ) as $vid ) {
            if ( ! isset( $state['variations'][ $vid ] ) ) {
                return [ 'ok' => false, 'reason' => 'variation_missing' ];
            }
        }
        $now = ProductFields::pick( $state, $before );
        try {
            $writer->apply( $id, ProductFields::asChanges( $before ) );
            $back = $writer->product( $id );
        } catch ( \Throwable $e ) {
            $this->putBack( $id, $now );

            return [ 'ok' => false, 'reason' => 'save_failed' ];
        }
        $after = null === $back ? null : ProductFields::pick( $back, $before );
        if ( null === $after || [] !== ProductDiff::compare( $before, $after ) ) {
            $this->putBack( $id, $now );

            return [ 'ok' => false, 'reason' => 'restore_differs' ];
        }

        return [ 'ok' => true, 'inverse' => Item::make( self::NAME, (string) $id, (string) $item['label'], $now, $after ) ];
    }

    /** Best effort after a failed restore: the values the product held just before it. */
    private function putBack( int $id, array $now ): void
    {
        try {
            $this->writer()->apply( $id, ProductFields::asChanges( $now ) );
        } catch ( \Throwable $e ) {
            // nothing more can be done; the undo reports the item as failed
        }
    }

    /**
     * @param array<string,mixed> $item
     * @return array{fields: array<string,mixed>, variations: array<int,array<string,mixed>>}
     */
    private static function shape( array $item ): array
    {
        $before = is_array( $item['before'] ?? null ) ? $item['before'] : [];
        $rows   = [];
        foreach ( (array) ( $before['variations'] ?? [] ) as $vid => $row ) {
            $rows[ (int) $vid ] = (array) $row;
        }

        return [ 'fields' => (array) ( $before['fields'] ?? [] ), 'variations' => $rows ];
    }

    private function writer(): ProductWriter
    {
        return $this->writer ??= new WooProductWriter();
    }
}
```

- [ ] **Step 4: Register it**

In `pro-addon/src/autoload.php`, replace this exact text:

```php
require_once __DIR__ . '/Kinds/ProductTrashedKind.php';

```

with:

```php
require_once __DIR__ . '/Kinds/ProductTrashedKind.php';
require_once __DIR__ . '/Kinds/ProductFieldsKind.php';

```

In `pro-addon/src/Batch/Batches.php` (the use line), replace this exact text:

```php
use AiEditorDivi5\Pro\Kinds\ProductCreatedKind;

```

with:

```php
use AiEditorDivi5\Pro\Kinds\ProductCreatedKind;
use AiEditorDivi5\Pro\Kinds\ProductFieldsKind;

```

In `pro-addon/src/Batch/Batches.php` (the kinds list), replace this exact text:

```php
            new ProductTrashedKind(),

```

with:

```php
            new ProductTrashedKind(),
            new ProductFieldsKind(),

```

In `pro-addon/tests/ToolsTest.php` (the pinned kinds list in testEveryKindARecordedItemUsesIsRegisteredForUndo), replace this exact text:

```php
'product_created', 'product_trashed' ],
```

with:

```php
'product_created', 'product_trashed', 'product_fields' ],
```

- [ ] **Step 5: Run the kind tests and the suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter ProductFieldsKindTest` - Expected: `OK (14 tests, 52 assertions)`.
Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK`.

- [ ] **Step 6: RED proofs (do each, then revert)**

1. In `restore()`, replace `if ( null === $after || [] !== ProductDiff::compare( $before, $after ) ) {` with `if ( false ) {`. Expected: `testARestoreThatReadsBackDifferentlyIsPutBackAndReported` fails.
2. In the same branch delete the line `$this->putBack( $id, $now );` (keep the `return`). Expected: the same test fails on its last assertion (the values held before the restore must be back).

- [ ] **Step 7: Commit**

```bash
git add pro-addon/src/Kinds/ProductFieldsKind.php pro-addon/tests/ProductFieldsKindTest.php pro-addon/src/autoload.php pro-addon/src/Batch/Batches.php pro-addon/tests/ToolsTest.php
git commit -m "$(cat <<'EOF'
feat(pro): ProductFieldsKind, the undo for update_products (changed-since per field and per variation row, undo of the undo)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: The `UpdateProducts` tool

**Files:**
- Create: `pro-addon/src/Tools/UpdateProducts.php`
- Create: `pro-addon/tests/UpdateProductsTest.php`
- Modify: `pro-addon/src/autoload.php` (one line after `Tools/CreateProduct.php`)

**Interfaces:**
- Consumes: everything from Tasks 1-4; `Site\PreviewToken::make/open/spend` (existing; the token carries `id => 32-character state hash`), `Canon::hash`, `Args::bool/listOf/string`, `BatchLog::record/attach`, `ProductSaveFailed` (existing).
- Produces: `new UpdateProducts( BatchLog $log, ?ProductWriter $writer = null, ?\Closure $clock = null )` implementing `ProTool` (`name()` = `update_products`, `capability()` = `edit_posts`). `run( array $arguments ): array` with arguments `updates` (1-25 entries `{id, ...fields}`), `dry_run` (strict boolean), `token` (string). Results:
  - dry run: `{ changed:false, dry_run:true, would_change:int, products:[{id,name,type,changes:{field:{before,after}},variations?:[{id,changes}],notes?:[string]}], unchanged:[ids], token, message }` (no `token` when nothing would change);
  - real run: `{ changed, dry_run:false, updated:[{id,name,changes,variations?}], skipped:[{id,reason:'changed_since_preview'}], unchanged:[ids], batch }` or, when nothing was saved, `{ changed:false, dry_run:false, updated:[], skipped, unchanged, message:'Nothing was changed.' }`.
  - every refusal and failure is an `\InvalidArgumentException` whose message is safe to show.

Behaviour in one paragraph (spec Design 1-3): arguments and permissions are checked, then the token (real runs). Each entry is shape-checked, loaded (`typeOf`, `edit_post`, grouped/other types refused), its whole state hashed, the policy run, then the site checks (SKU owner, term slugs -> ids, usable images, text size/encoding for the previous values). On a real run a product whose hash differs from the token's is skipped before anything else; one absent from the token that now needs a change is skipped too. Any reason (dry run, or a real run's non-skipped products) refuses the whole call with every reason, each prefixed `Product N (#id "name")`. A dry run returns the preview and a token over `id => hash` of the products that would change. A real run spends the token, applies product by product, reads each back through `ProductDiff` and, on any exception or difference, restores every product it started to change (newest first), records NO batch and throws a safe message (only `ProductSaveFailed` and `WC_Data_Exception` text is shown); a restore that cannot finish names the product ids.

- [ ] **Step 1: Write the tests**

Create `pro-addon/tests/UpdateProductsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\ProductFieldsKind;
use AiEditorDivi5\Pro\Products\ProductSaveFailed;
use AiEditorDivi5\Pro\Tools\UpdateProducts;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpKsesShim.php';
require_once __DIR__ . '/WpBuildShims.php';
require_once __DIR__ . '/FakeProductWriter.php';
require_once __DIR__ . '/WcDataExceptionShim.php';

class UpdateProductsTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private FakeProductWriter $writer;
    private UpdateProducts $tool;
    private int $now;

    protected function setUp(): void
    {
        $this->now    = (int) strtotime( '2026-10-13 12:00:00 UTC' );
        $this->repo   = new InMemoryBatchRepository();
        $this->log    = new BatchLog( $this->repo );
        $this->writer = new FakeProductWriter();
        $this->tool   = new UpdateProducts( $this->log, $this->writer, fn (): int => $this->now );
        $this->writer->products[50] = $this->simple();
        $this->writer->products[51] = $this->simple( [ 'name' => 'Plain Mug', 'sku' => 'MUG-1', 'regular_price' => '', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'outofstock', 'category_ids' => [] ] );
        $this->writer->products[60] = $this->variable();
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_posts']           = [];
        $GLOBALS['__wp_current_user_id'] = 7;
        $GLOBALS['__wp_caps']            = [ 'edit_products' => true, 'edit_post' => true, 'assign_product_terms' => true ];
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'] );
    }

    /** @return array<string,mixed> a published simple product with managed stock */
    private function simple( array $over = [] ): array
    {
        return $over + [
            'type' => 'simple', 'status' => 'publish', 'name' => 'Blue Hat', 'description' => '<p>Warm</p>', 'short_description' => 'Warm', 'sku' => 'HAT-1',
            'regular_price' => '20', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => true, 'stock_quantity' => 12, 'stock_status' => 'instock',
            'backorders' => 'no', 'category_ids' => [ 3 ], 'tag_ids' => [], 'image_id' => 0, 'gallery_image_ids' => [], 'weight' => '', 'length' => '', 'width' => '',
            'height' => '', 'tax_status' => 'taxable', 'variations' => [],
        ];
    }

    /** @return array<string,mixed> a variable product with three variation rows */
    private function variable( int $rows = 3, string $name = 'Tee', string $sku = 'TEE' ): array
    {
        $variations = [
            61 => [ 'regular_price' => '10', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => true, 'stock_quantity' => 5, 'stock_status' => 'instock', 'backorders' => 'no' ],
            62 => [ 'regular_price' => '12', 'sale_price' => '9', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => false, 'stock_quantity' => null, 'stock_status' => 'instock', 'backorders' => 'no' ],
            63 => [ 'regular_price' => '15', 'sale_price' => '', 'sale_from' => '', 'sale_to' => '', 'manage_stock' => true, 'stock_quantity' => 0, 'stock_status' => 'outofstock', 'backorders' => 'no' ],
        ];
        for ( $i = 4; $i <= $rows; $i++ ) {
            $variations[ 1000 + $i ] = $variations[61];
        }

        return $this->simple( [ 'type' => 'variable', 'name' => $name, 'sku' => $sku, 'regular_price' => '', 'manage_stock' => false, 'stock_quantity' => null, 'variations' => $variations ] );
    }

    /** @return array<string,mixed> */
    private function dry( array $updates ): array
    {
        return $this->tool->run( [ 'updates' => $updates, 'dry_run' => true ] );
    }

    /** Preview, then run with the token. @return array<string,mixed> */
    private function go( array $updates ): array
    {
        $p = $this->dry( $updates );
        $this->assertArrayHasKey( 'token', $p, 'the preview made a token' );

        return $this->tool->run( [ 'updates' => $updates, 'token' => $p['token'] ] );
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

    // ---- definition -----------------------------------------------------------------------------

    public function testTheDefinition(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'update_products', $d['name'] );
        $this->assertSame( 'edit_posts', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'updates' ], $d['inputSchema']['required'] );
        $this->assertSame( 25, $d['inputSchema']['properties']['updates']['maxItems'] );
        $this->assertSame( 'boolean', $d['inputSchema']['properties']['dry_run']['type'] );
        $this->assertSame( 'string', $d['inputSchema']['properties']['token']['type'] );
        $item = $d['inputSchema']['properties']['updates']['items'];
        $this->assertSame( [ 'id' ], $item['required'] );
        $this->assertSame( [ 'id' ], $item['properties']['variations']['items']['required'] );
        foreach ( [ 'regular_price', 'sale_price', 'sale_from', 'sale_to', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'name', 'description', 'short_description', 'sku', 'categories', 'tags', 'image_id', 'gallery_image_ids', 'weight', 'dimensions', 'tax_status', 'variations' ] as $field ) {
            $this->assertArrayHasKey( $field, $item['properties'], $field );
        }
        foreach ( [ 'status', 'type', 'slug', 'virtual' ] as $never ) {
            $this->assertArrayNotHasKey( $never, $item['properties'], "{$never} is not offered" );
        }
    }

    // ---- the preview ----------------------------------------------------------------------------

    public function testADryRunShowsBeforeAndAfterOfOnlyTheChangedFieldsMakesOneTokenAndWritesNothing(): void
    {
        $r = $this->dry( [ [ 'id' => 50, 'regular_price' => '25', 'name' => 'Blue Hat', 'stock_quantity' => 12 ], [ 'id' => 51, 'stock_status' => 'instock' ] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertSame( 2, $r['would_change'] );
        $this->assertSame( [ 50, 51 ], array_column( $r['products'], 'id' ) );
        $this->assertSame( [ 'Blue Hat', 'Plain Mug' ], array_column( $r['products'], 'name' ) );
        $this->assertSame( 'simple', $r['products'][0]['type'] );
        $this->assertSame( [ 'regular_price' => [ 'before' => '20', 'after' => '25' ] ], $r['products'][0]['changes'] );
        $this->assertSame( [ 'stock_status' => [ 'before' => 'outofstock', 'after' => 'instock' ] ], $r['products'][1]['changes'] );
        $this->assertIsString( $r['token'] );
        $this->assertNotSame( '', $r['token'] );
        $this->assertSame( [], $r['unchanged'] );
        $this->assertSame( 0, $this->writer->applyCalls );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( '20', $this->writer->products[50]['regular_price'] );
    }

    public function testAProductWithNothingToChangeIsListedAsUnchangedAndAPreviewOfNothingHasNoToken(): void
    {
        $r = $this->dry( [ [ 'id' => 50, 'regular_price' => '20.00', 'name' => 'Blue Hat' ], [ 'id' => 51, 'stock_status' => 'instock' ] ] );
        $this->assertSame( [ 50 ], $r['unchanged'] );
        $this->assertSame( [ 51 ], array_column( $r['products'], 'id' ) );
        $this->assertArrayHasKey( 'token', $r );

        $none = $this->dry( [ [ 'id' => 50, 'regular_price' => '20' ] ] );
        $this->assertSame( [ 'changed' => false, 'dry_run' => true, 'would_change' => 0, 'products' => [], 'unchanged' => [ 50 ] ], array_diff_key( $none, [ 'message' => 1 ] ) );
        $this->assertArrayNotHasKey( 'token', $none );
    }

    public function testAFutureSaleCarriesANoteAndAPastOneDoesNot(): void
    {
        $future = $this->dry( [ [ 'id' => 50, 'sale_price' => '15', 'sale_from' => '2026-11-01' ] ] );
        $this->assertStringContainsString( '2026-11-01', $future['products'][0]['notes'][0] );
        $this->assertStringContainsString( 'regular price', $future['products'][0]['notes'][0] );
        $past = $this->dry( [ [ 'id' => 50, 'sale_price' => '15', 'sale_from' => '2026-10-01' ] ] );
        $this->assertArrayNotHasKey( 'notes', $past['products'][0] );
        $viaRow = $this->dry( [ [ 'id' => 60, 'variations' => [ [ 'id' => 61, 'sale_price' => '8', 'sale_from' => '2027-01-01' ] ] ] ] );
        $this->assertStringContainsString( '2027-01-01', $viaRow['products'][0]['notes'][0] );
    }

    public function testADryRunStillRunsEveryCheckAndReportsEveryReasonWithItsPosition(): void
    {
        $args = [ 'updates' => [
            [ 'id' => 50, 'sale_price' => '30' ],
            [ 'id' => 999, 'name' => 'x' ],
            [ 'id' => 70, 'name' => 'x' ],
            [ 'id' => 60, 'regular_price' => '5' ],
            [ 'id' => 50, 'name' => 'dup' ],
            [ 'id' => 51, 'status' => 'publish' ],
        ], 'dry_run' => true ];
        $this->writer->products[70] = $this->simple();
        $this->writer->types[70]    = 'grouped';
        try {
            $this->tool->run( $args );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $m = $e->getMessage();
            $this->assertStringStartsWith( 'Nothing was changed. 6 problem(s): ', $m );
            $this->assertStringContainsString( 'Product 1 (#50 "Blue Hat"): the resulting "sale_price" must be lower than the "regular_price"', $m );
            $this->assertStringContainsString( 'Product 2 (#999): product not found', $m );
            $this->assertStringContainsString( 'Product 3 (#70): grouped products are not supported', $m );
            $this->assertStringContainsString( 'Product 4 (#60 "Tee"): "regular_price" is not available on a variable product', $m );
            $this->assertStringContainsString( 'Product 5 (#50): the product appears more than once', $m );
            $this->assertStringContainsString( 'Product 6 (#51 "Plain Mug"): "status" is not a supported field (this tool never changes', $m );
        }
        $this->assertSame( 0, $this->writer->applyCalls );
    }

    public function testALongRefusalIsCutAtSixThousandCharacters(): void
    {
        $updates = [];
        for ( $i = 0; $i < 25; $i++ ) {
            $this->writer->products[ 100 + $i ] = $this->simple( [ 'name' => "P{$i}", 'sku' => "S{$i}" ] );
            $updates[] = [ 'id' => 100 + $i, str_repeat( 'k', 80 ) . '1' => 1, str_repeat( 'k', 80 ) . '2' => 1, str_repeat( 'k', 80 ) . '3' => 1, str_repeat( 'k', 80 ) . '4' => 1 ];
        }
        try {
            $this->tool->run( [ 'updates' => $updates, 'dry_run' => true ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( 6000, mb_strlen( $e->getMessage() ) );
            $this->assertStringEndsWith( '...', $e->getMessage() );
        }
    }

    // ---- arguments and permissions --------------------------------------------------------------

    public function testTheArgumentsAreCheckedStrictly(): void
    {
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'name' => 'x' ] ], 'dry_run' => 'yes' ], 'must be true or false' );
        $this->refused( [ 'dry_run' => true ], '"updates" is required' );
        $this->refused( [ 'updates' => [], 'dry_run' => true ], 'must not be empty' );
        $this->refused( [ 'updates' => array_fill( 0, 26, [ 'id' => 50 ] ), 'dry_run' => true ], 'at most 25 items' );
        $this->refused( [ 'updates' => [ [ 'id' => 50 ] ], 'token' => 5 ], 'must be a string' );
        $this->refused( [ 'updates' => [ 5 ], 'dry_run' => true ], 'Product 1: must be an object' );
        $this->refused( [ 'updates' => [ [ 'name' => 'x' ] ], 'dry_run' => true ], 'Product 1: "id" must be a whole product id' );
        $this->refused( [ 'updates' => [ [ 'id' => '50', 'name' => 'x' ] ], 'dry_run' => true ], '"id" must be a whole product id' );
        $this->refused( [ 'updates' => [ [ 'id' => 0 ] ], 'dry_run' => true ], '"id" must be a whole product id' );
    }

    public function testWooCommerceMustBeActiveAndTheUserMayEditProducts(): void
    {
        $this->writer->active = false;
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'name' => 'x' ] ], 'dry_run' => true ], 'WooCommerce is not active' );
        $this->writer->active = true;
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true ];
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'name' => 'x' ] ], 'dry_run' => true ], 'edit_products permission' );
    }

    public function testProductsThatAreMissingTrashedVariationsOtherTypesOrNotEditableAllAnswerAlike(): void
    {
        $this->writer->products[80]    = $this->simple();
        $GLOBALS['__wp_posts'][80]     = (object) [ 'ID' => 80, 'post_status' => 'trash' ];
        $this->writer->products[72]    = $this->simple();
        $this->writer->types[72]       = 'subscription';
        foreach ( [ 999, 80, 61 ] as $id ) { // a missing id, a trashed product, a variation id
            $this->refused( [ 'updates' => [ [ 'id' => $id, 'name' => 'x' ] ], 'dry_run' => true ], "Product 1 (#{$id}): product not found" );
        }
        $this->refused( [ 'updates' => [ [ 'id' => 72, 'name' => 'x' ] ], 'dry_run' => true ], 'subscription products are not supported' );
        $GLOBALS['__wp_caps'] = [ 'edit_products' => true ];
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'name' => 'x' ] ], 'dry_run' => true ], 'Product 1 (#50): product not found' );
    }

    public function testTermsNeedTheTermPermissionWheneverTheyAreGiven(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_products' => true, 'edit_post' => true ];
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'categories' => [ 'hats' ] ] ], 'dry_run' => true ], 'assign_product_terms permission' );
    }

    // ---- the token ------------------------------------------------------------------------------

    public function testARealRunNeedsAGenuineUnusedTokenForTheSameArgumentsByTheSameUserInTime(): void
    {
        $updates = [ [ 'id' => 50, 'regular_price' => '25' ] ];
        $needs   = 'A real run needs the token';
        $this->refused( [ 'updates' => $updates ], $needs );
        $this->refused( [ 'updates' => $updates, 'token' => 'garbage' ], $needs );
        $this->refused( [ 'updates' => $updates, 'token' => '' ], $needs );

        $token = $this->dry( $updates )['token'];
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'regular_price' => '26' ] ], 'token' => $token ], $needs ); // other arguments
        $GLOBALS['__wp_current_user_id'] = 8;
        $this->refused( [ 'updates' => $updates, 'token' => $token ], $needs ); // another user
        $GLOBALS['__wp_current_user_id'] = 7;
        $this->now += 1801;
        $this->refused( [ 'updates' => $updates, 'token' => $token ], $needs ); // expired
        $this->now -= 1801;

        $this->assertTrue( $this->tool->run( [ 'updates' => $updates, 'token' => $token ] )['changed'] );
        $this->refused( [ 'updates' => $updates, 'token' => $token ], $needs, false ); // spent
        $this->assertSame( 1, $this->writer->applyCalls, 'only the one good run wrote' );
    }

    public function testATokenSurvivesARefusedRunAndIsSpentOnlyWhenTheRunStarts(): void
    {
        $updates = [ [ 'id' => 50, 'sku' => 'NEW-SKU' ] ];
        $token   = $this->dry( $updates )['token'];
        $this->writer->skus['NEW-SKU'] = true; // someone took the SKU after the preview
        $this->refused( [ 'updates' => $updates, 'token' => $token ], 'already used by another product' );
        unset( $this->writer->skus['NEW-SKU'] );
        $this->assertTrue( $this->tool->run( [ 'updates' => $updates, 'token' => $token ] )['changed'], 'the same token still works' );
    }

    // ---- a real run -----------------------------------------------------------------------------

    public function testARealRunAppliesExactlyWhatWasPreviewedAndRecordsOneBatch(): void
    {
        $r = $this->go( [ [ 'id' => 50, 'regular_price' => '25', 'sale_price' => '18', 'stock_quantity' => 7, 'name' => 'Red Hat' ], [ 'id' => 51, 'stock_status' => 'instock', 'weight' => '2' ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertFalse( $r['dry_run'] );
        $this->assertSame( [ 50, 51 ], array_column( $r['updated'], 'id' ) );
        $this->assertSame( [ 'Red Hat', 'Plain Mug' ], array_column( $r['updated'], 'name' ) );
        $this->assertSame( [ 'name', 'regular_price', 'sale_price', 'stock_quantity' ], $this->sortedKeys( $r['updated'][0]['changes'] ) );
        $this->assertSame( [], $r['skipped'] );
        $this->assertSame( [], $r['unchanged'] );
        $this->assertTrue( $r['batch']['undoable'] );
        $p = $this->writer->products[50];
        $this->assertSame( [ '25', '18', 7, 'Red Hat' ], [ $p['regular_price'], $p['sale_price'], $p['stock_quantity'], $p['name'] ] );
        $this->assertSame( [ 'instock', '2' ], [ $this->writer->products[51]['stock_status'], $this->writer->products[51]['weight'] ] );

        $this->assertCount( 1, $this->repo->rows, 'one batch for the whole call' );
        $batch = array_values( $this->repo->rows )[0];
        $this->assertSame( 'update_products', $batch['tool'] );
        $this->assertSame( [ 'product_fields', 'product_fields' ], array_column( $batch['items'], 'kind' ) );
        $this->assertSame( [ '50', '51' ], array_column( $batch['items'], 'ref' ) );
        $this->assertSame( [ 'fields' => [ 'name' => 'Blue Hat', 'regular_price' => '20', 'sale_price' => '', 'stock_quantity' => 12 ], 'variations' => [] ], $batch['items'][0]['before'] );
    }

    /** @param array<string,mixed> $a @return list<string> */
    private function sortedKeys( array $a ): array
    {
        $k = array_keys( $a );
        sort( $k );

        return $k;
    }

    public function testOnlyTheChangedFieldsAreWritten(): void
    {
        $this->go( [ [ 'id' => 50, 'regular_price' => '25', 'name' => 'Blue Hat', 'description' => '<p>Warm</p>' ] ] );
        $this->assertSame( [ [ 50, [ 'regular_price' => '25' ] ] ], $this->writer->applied );
    }

    public function testSwitchingManagedStockOffWritesTheDerivedValuesAndTheUndoPutsThemBack(): void
    {
        $this->writer->products[50]['backorders'] = 'notify';
        $r = $this->go( [ [ 'id' => 50, 'manage_stock' => false ] ] );
        $this->assertSame( [ 'backorders', 'manage_stock', 'stock_quantity' ], $this->sortedKeys( $r['updated'][0]['changes'] ) );
        $this->assertSame( [ false, null, 'no' ], [ $this->writer->products[50]['manage_stock'], $this->writer->products[50]['stock_quantity'], $this->writer->products[50]['backorders'] ] );
        $this->undoBatch( (int) $r['batch']['id'] );
        $p = $this->writer->products[50];
        $this->assertSame( [ true, 12, 'instock', 'notify' ], [ $p['manage_stock'], $p['stock_quantity'], $p['stock_status'], $p['backorders'] ] );
    }

    public function testTextsAreComparedTheWayCoreStoresThemForAUserWithoutUnfilteredHtml(): void
    {
        $this->writer->filteredTitle = true; // core rewrites a bare "&" to "&amp;"
        $r = $this->go( [ [ 'id' => 50, 'name' => 'Salt & Pepper', 'description' => 'Fish & chips' ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 'Salt &amp; Pepper', $this->writer->products[50]['name'] );
        $again = $this->dry( [ [ 'id' => 50, 'name' => 'Salt & Pepper' ] ] );
        $this->assertSame( [ 50 ], $again['unchanged'], 'the stored "&amp;" is the same name' );
    }

    public function testTheSkuMayBeKeptChangedInCaseButNeverTakenFromAnotherProduct(): void
    {
        $this->writer->products[52] = $this->simple( [ 'name' => 'Other', 'sku' => 'TAKEN' ] );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'sku' => 'TAKEN' ] ], 'dry_run' => true ], 'sku "TAKEN" is already used by another product' );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'sku' => 'taken' ] ], 'dry_run' => true ], 'already used by another product' );
        $this->assertTrue( $this->go( [ [ 'id' => 50, 'sku' => 'hat-1' ] ] )['changed'], 'the product owns its SKU: only the case changes' );
        $this->assertTrue( $this->go( [ [ 'id' => 51, 'sku' => '' ] ] )['changed'], '"" clears the SKU' );
        $this->assertSame( '', $this->writer->products[51]['sku'] );
    }

    public function testCategoriesTagsAndImages(): void
    {
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'categories' => [ 'nope' ] ] ], 'dry_run' => true ], 'category "nope" does not exist' );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'tags' => [ 'nope' ] ] ], 'dry_run' => true ], 'tag "nope" does not exist' );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'image_id' => 5 ] ], 'dry_run' => true ], 'image_id 5 is not an image in the Media Library' );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'gallery_image_ids' => [ 90, 5 ] ] ], 'dry_run' => true ], 'gallery_image_ids 5 is not an image' );

        $same = $this->dry( [ [ 'id' => 50, 'categories' => [ 'shirts' ] ] ] );
        $this->assertSame( [ 50 ], $same['unchanged'], 'the same category (by id) is no change' );

        $r = $this->go( [ [ 'id' => 50, 'categories' => [ 'hats' ], 'tags' => [ 'sale' ], 'image_id' => 90, 'gallery_image_ids' => [ 91, 90 ] ] ] );
        $changes = $r['updated'][0]['changes'];
        ksort( $changes );
        $this->assertSame( [ 'categories' => [ 'before' => [ 'shirts' ], 'after' => [ 'hats' ] ], 'gallery_image_ids' => [ 'before' => [], 'after' => [ 91, 90 ] ], 'image_id' => [ 'before' => 0, 'after' => 90 ], 'tags' => [ 'before' => [], 'after' => [ 'sale' ] ] ], $changes );
        $this->assertSame( [ [ 4 ], [ 7 ], 90, [ 91, 90 ] ], [ $this->writer->products[50]['category_ids'], $this->writer->products[50]['tag_ids'], $this->writer->products[50]['image_id'], $this->writer->products[50]['gallery_image_ids'] ] );

        // images already on the product need no check (the user may lack upload_files); an empty category list clears
        $this->writer->images = [];
        $kept = $this->go( [ [ 'id' => 50, 'gallery_image_ids' => [ 90, 91 ], 'categories' => [] ] ] );
        $this->assertTrue( $kept['changed'] );
        $this->assertSame( [ [], [ 90, 91 ] ], [ $this->writer->products[50]['category_ids'], $this->writer->products[50]['gallery_image_ids'] ] );
    }

    // ---- inputs a person is likely to send ------------------------------------------------------

    public function testTheSameCategoriesInAnotherOrderAreNoChange(): void
    {
        $this->writer->products[50]['category_ids'] = [ 3, 4 ];
        $r = $this->dry( [ [ 'id' => 50, 'categories' => [ 'hats', 'shirts' ] ] ] );
        $this->assertSame( [ 50 ], $r['unchanged'] );
        $this->assertSame( [], $r['products'] );
    }

    public function testAnEntryWithOnlyAnIdChangesNothing(): void
    {
        $r = $this->dry( [ [ 'id' => 50 ] ] );
        $this->assertSame( [ 50 ], $r['unchanged'] );
        $this->assertArrayNotHasKey( 'token', $r );
    }

    public function testTheTokenDoesNotCareInWhatOrderTheKeysAreSent(): void
    {
        $token = $this->dry( [ [ 'id' => 50, 'regular_price' => '25', 'name' => 'Red Hat' ] ] )['token'];
        $r     = $this->tool->run( [ 'token' => $token, 'updates' => [ [ 'name' => 'Red Hat', 'regular_price' => '25', 'id' => 50 ] ] ] );
        $this->assertTrue( $r['changed'] );
    }

    public function testALongMultiByteNameNeverBreaksTheUtf8OfAMessage(): void
    {
        $name = str_repeat( 'é', 59 ) . '🙂🙂🙂';
        $this->writer->products[53] = $this->simple( [ 'name' => $name, 'sku' => 'U53' ] );
        try {
            $this->tool->run( [ 'updates' => [ [ 'id' => 53, 'sale_price' => '99' ] ], 'dry_run' => true ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( 1, preg_match( '//u', $e->getMessage() ), 'the message is valid UTF-8' );
            $this->assertStringContainsString( str_repeat( 'é', 59 ) . '🙂...', $e->getMessage() );
        }
    }

    // ---- skipped products -----------------------------------------------------------------------

    public function testAProductChangedSinceThePreviewIsSkippedAndTheOthersProceed(): void
    {
        $updates = [ [ 'id' => 50, 'regular_price' => '25' ], [ 'id' => 51, 'stock_status' => 'instock' ] ];
        $token   = $this->dry( $updates )['token'];
        $this->writer->products[50]['regular_price'] = '22'; // the owner
        $r = $this->tool->run( [ 'updates' => $updates, 'token' => $token ] );
        $this->assertSame( [ 51 ], array_column( $r['updated'], 'id' ) );
        $this->assertSame( [ [ 'id' => 50, 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
        $this->assertSame( '22', $this->writer->products[50]['regular_price'], 'never overwritten' );
        $this->assertCount( 1, array_values( $this->repo->rows )[0]['items'] );
    }

    public function testAChangeToAnyOtherFieldOrAVariationRowCountsAsChangedSinceThePreview(): void
    {
        $updates = [ [ 'id' => 50, 'regular_price' => '25' ], [ 'id' => 60, 'variations' => [ [ 'id' => 61, 'regular_price' => '11' ] ] ] ];
        $token   = $this->dry( $updates )['token'];
        $this->writer->products[50]['description'] = '<p>Edited</p>';
        $this->writer->products[60]['variations'][62]['sale_price'] = '7'; // a row the update does not name
        $r = $this->tool->run( [ 'updates' => $updates, 'token' => $token ] );
        $this->assertSame( [], $r['updated'] );
        $this->assertSame( [ 50, 60 ], array_column( $r['skipped'], 'id' ) );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 0, $this->writer->applyCalls );
        $this->assertSame( [], $this->repo->rows, 'nothing changed, nothing recorded' );
    }

    public function testAProductTrashedSinceThePreviewIsSkipped(): void
    {
        $updates = [ [ 'id' => 50, 'regular_price' => '25' ], [ 'id' => 51, 'stock_status' => 'instock' ] ];
        $token   = $this->dry( $updates )['token'];
        $GLOBALS['__wp_posts'][51] = (object) [ 'ID' => 51, 'post_status' => 'trash' ];
        $r = $this->tool->run( [ 'updates' => $updates, 'token' => $token ] );
        $this->assertSame( [ 50 ], array_column( $r['updated'], 'id' ) );
        $this->assertSame( [ [ 'id' => 51, 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
    }

    public function testAProductTheOwnerChangedSoThatItNowNeedsAChangeIsSkippedNotChanged(): void
    {
        $updates = [ [ 'id' => 50, 'name' => 'Blue Hat' ], [ 'id' => 51, 'stock_status' => 'instock' ] ];
        $preview = $this->dry( $updates );
        $this->assertSame( [ 50 ], $preview['unchanged'] );
        $this->writer->products[50]['name'] = 'Renamed by the owner'; // the update would now rename it back
        $r = $this->tool->run( [ 'updates' => $updates, 'token' => $preview['token'] ] );
        $this->assertSame( [ [ 'id' => 50, 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
        $this->assertSame( 'Renamed by the owner', $this->writer->products[50]['name'] );

        $again = [ [ 'id' => 50, 'name' => 'Renamed by the owner' ], [ 'id' => 51, 'weight' => '3' ] ];
        $p2    = $this->dry( $again );
        $r2    = $this->tool->run( [ 'updates' => $again, 'token' => $p2['token'] ] );
        $this->assertSame( [ 50 ], $r2['unchanged'] );
        $this->assertSame( [], $r2['skipped'] );
    }

    public function testARealRunWhoseEveryProductWasSkippedReportsItAndSpendsTheToken(): void
    {
        $updates = [ [ 'id' => 50, 'regular_price' => '25' ] ];
        $token   = $this->dry( $updates )['token'];
        $this->writer->products[50]['regular_price'] = '22';
        $r = $this->tool->run( [ 'updates' => $updates, 'token' => $token ] );
        $this->assertSame( [ 'changed' => false, 'dry_run' => false, 'updated' => [], 'skipped' => [ [ 'id' => 50, 'reason' => 'changed_since_preview' ] ], 'unchanged' => [], 'message' => 'Nothing was changed.' ], $r );
        $this->refused( [ 'updates' => $updates, 'token' => $token ], 'A real run needs the token', false );
    }

    // ---- variation rows -------------------------------------------------------------------------

    public function testVariationRowsAreChangedAndRecordedPerRow(): void
    {
        $r = $this->go( [ [ 'id' => 60, 'variations' => [ [ 'id' => 61, 'regular_price' => '11', 'stock_quantity' => 9 ], [ 'id' => 62, 'sale_price' => '8' ], [ 'id' => 63, 'stock_quantity' => 0 ] ] ] ] );
        $this->assertSame( [], $r['updated'][0]['changes'] );
        $this->assertSame( [ 61, 62 ], array_column( $r['updated'][0]['variations'], 'id' ), 'row 63 already has quantity 0: unchanged' );
        $this->assertSame( [ 'regular_price' => [ 'before' => '10', 'after' => '11' ], 'stock_quantity' => [ 'before' => 5, 'after' => 9 ] ], $r['updated'][0]['variations'][0]['changes'] );
        $v = $this->writer->products[60]['variations'];
        $this->assertSame( [ '11', 9, '8' ], [ $v[61]['regular_price'], $v[61]['stock_quantity'], $v[62]['sale_price'] ] );
        $item = array_values( $this->repo->rows )[0]['items'][0];
        $this->assertSame( [ 61 => [ 'regular_price' => '10', 'stock_quantity' => 5 ], 62 => [ 'sale_price' => '9' ] ], $item['before']['variations'] );
    }

    public function testVariationProblemsAreRefusedByRow(): void
    {
        $this->refused( [ 'updates' => [ [ 'id' => 60, 'variations' => [ [ 'id' => 999, 'regular_price' => '1' ] ] ] ], 'dry_run' => true ], 'variation 999 does not belong to this product' );
        $this->refused( [ 'updates' => [ [ 'id' => 60, 'variations' => [ [ 'id' => 61, 'sale_price' => '99' ] ] ] ], 'dry_run' => true ], 'variation 61: the resulting "sale_price" must be lower' );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'variations' => [ [ 'id' => 61, 'regular_price' => '1' ] ] ] ], 'dry_run' => true ], '"variations" is only for variable products' );
    }

    public function testAtMostTwoHundredVariationRowsPerCall(): void
    {
        foreach ( [ 90, 91, 92 ] as $id ) {
            $this->writer->products[ $id ] = $this->variable( 100, "Big {$id}", "BIG-{$id}" );
        }
        $rows = static function ( array $product ): array {
            $out = [];
            foreach ( array_keys( $product['variations'] ) as $vid ) {
                $out[] = [ 'id' => $vid, 'regular_price' => '99.5' ];
            }

            return $out;
        };
        $two   = [ [ 'id' => 90, 'variations' => $rows( $this->writer->products[90] ) ], [ 'id' => 91, 'variations' => $rows( $this->writer->products[91] ) ] ];
        $this->assertSame( 2, $this->dry( $two )['would_change'], '100 + 100 rows is allowed' );
        $three = array_merge( $two, [ [ 'id' => 92, 'variations' => $rows( $this->writer->products[92] ) ] ] );
        $this->refused( [ 'updates' => $three, 'dry_run' => true ], 'change 300 variation rows; the limit is 200 per call' );
    }

    // ---- what is stored for undo ----------------------------------------------------------------

    public function testATextTooLargeOrNotValidUtf8ToKeepForUndoIsNotChanged(): void
    {
        $this->writer->products[50]['description'] = str_repeat( 'x', 524289 );
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'description' => 'short' ] ], 'dry_run' => true ], 'larger than 512 KB' );
        $this->assertSame( 1, $this->dry( [ [ 'id' => 50, 'regular_price' => '25' ] ] )['would_change'], 'a text the update does not touch is not kept, so its size does not matter' );

        $this->writer->products[50]['description'] = "caf\xE9 broken";
        $this->refused( [ 'updates' => [ [ 'id' => 50, 'description' => 'fixed' ] ], 'dry_run' => true ], 'not valid UTF-8' );
    }

    public function testThePreviousTextsOfOneCallMayNotExceedOneMegabyte(): void
    {
        foreach ( [ 50, 51, 52 ] as $id ) {
            $this->writer->products[ $id ] = $this->simple( [ 'name' => "P{$id}", 'sku' => "S{$id}", 'description' => str_repeat( 'y', 400000 ) ] );
        }
        $updates = [ [ 'id' => 50, 'description' => 'a' ], [ 'id' => 51, 'description' => 'b' ], [ 'id' => 52, 'description' => 'c' ] ];
        $this->refused( [ 'updates' => $updates, 'dry_run' => true ], 'more than 1 MB; split the call' );
        $this->assertSame( 2, $this->dry( array_slice( $updates, 0, 2 ) )['would_change'] );
    }

    // ---- failure paths --------------------------------------------------------------------------

    private function threeUpdates(): array
    {
        return [ [ 'id' => 50, 'regular_price' => '25' ], [ 'id' => 51, 'stock_status' => 'instock', 'weight' => '2' ], [ 'id' => 60, 'variations' => [ [ 'id' => 61, 'regular_price' => '11' ] ] ] ];
    }

    /** @return array<int,array<string,mixed>> every stored product, to compare before and after */
    private function stored(): array
    {
        return $this->writer->products;
    }

    public function testAFailedSaveRestoresEveryProductThisRunChangedAndRecordsNoBatch(): void
    {
        $before = $this->stored();
        $this->writer->failApplyCalls = [ 3 ]; // the third product fails; 50 and 51 were already changed
        try {
            $p = $this->dry( $this->threeUpdates() );
            $this->tool->run( [ 'updates' => $this->threeUpdates(), 'token' => $p['token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $m = $e->getMessage();
            $this->assertStringContainsString( 'Could not update Product 3 (#60 "Tee"): WooCommerce could not save it.', $m );
            $this->assertStringContainsString( 'nothing was changed', $m );
            $this->assertStringNotContainsString( 'boom', $m );
            $this->assertStringNotContainsString( '/var/www', $m );
        }
        $this->assertEquals( $before, $this->stored(), 'every product is back exactly as it was' );
        $this->assertSame( [], $this->repo->rows, 'no batch' );
        $this->assertSame( 6, $this->writer->applyCalls, 'three saves (the third fails), then the three products are put back, newest first' );
    }

    public function testARestoreThatCannotFinishNamesTheProduct(): void
    {
        $this->writer->failApplyCalls = [ 2, 3 ]; // the second save fails, and the restore of the second (call 3) fails too
        $p = $this->dry( $this->threeUpdates() );
        try {
            $this->tool->run( [ 'updates' => $this->threeUpdates(), 'token' => $p['token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Could not update Product 2 (#51 "Plain Mug")', $e->getMessage() );
            $this->assertStringContainsString( 'may not be fully restored and should be checked: #51.', $e->getMessage() );
        }
        $this->assertSame( '20', $this->writer->products[50]['regular_price'], 'the first product was still put back' );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testASaveThatReadsBackDifferentlyIsRolledBack(): void
    {
        $before = $this->stored();
        $this->writer->afterApply = function ( int $id, array &$stored, int $call ): void {
            if ( 2 === $call ) {
                $stored['weight'] = '99'; // the store saved something else
            }
        };
        $p = $this->dry( $this->threeUpdates() );
        try {
            $this->tool->run( [ 'updates' => $this->threeUpdates(), 'token' => $p['token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Could not update Product 2 (#51 "Plain Mug"): it was saved differently than asked (weight).', $e->getMessage() );
        }
        $this->assertEquals( $before, $this->stored() );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testAProductThatCannotBeReadBackIsReportedAndTheRunIsRolledBack(): void
    {
        $this->writer->afterApply = function ( int $id, array &$stored, int $call ): void {
            if ( 1 === $call ) {
                $this->writer->types[ $id ] = 'grouped'; // gone from the tool's point of view
            }
        };
        $p = $this->dry( [ [ 'id' => 50, 'regular_price' => '25' ] ] );
        try {
            $this->tool->run( [ 'updates' => [ [ 'id' => 50, 'regular_price' => '25' ] ], 'token' => $p['token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'could not be read back', $e->getMessage() );
            $this->assertStringContainsString( 'should be checked: #50', $e->getMessage(), 'the restore cannot be verified either' );
        }
        $this->assertSame( [], $this->repo->rows );
    }

    public function testOnlyOurOwnMessagesAndWooCommerceDataExceptionsReachTheClient(): void
    {
        $cases = [
            'data exception' => [ static fn () => new \WC_Data_Exception( 'product_invalid_sku', 'Invalid or duplicated SKU.' ), 'Invalid or duplicated SKU.' ],
            'our own'        => [ static fn () => new ProductSaveFailed( 'the saved product could not be read back' ), 'the saved product could not be read back' ],
            'foreign'        => [ static fn () => new \RuntimeException( 'SQL error in /var/www/html/wp-includes/x.php' ), 'WooCommerce could not save it' ],
        ];
        foreach ( $cases as $label => [ $make, $expected ] ) {
            $this->setUp();
            $this->writer->failApplyCalls     = [ 1 ];
            $this->writer->failApplyException = $make;
            $p = $this->dry( [ [ 'id' => 50, 'regular_price' => '25' ] ] );
            try {
                $this->tool->run( [ 'updates' => [ [ 'id' => 50, 'regular_price' => '25' ] ], 'token' => $p['token'] ] );
                $this->fail( "{$label}: expected a refusal" );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( $expected, $e->getMessage(), $label );
                $this->assertStringNotContainsString( '/var/www', $e->getMessage(), $label );
            }
        }
    }

    // ---- undo, end to end -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function undoBatch( int $id, bool $force = false ): array
    {
        return ( new BatchUndo( [ 'product_fields' => new ProductFieldsKind( $this->writer ) ], $this->log ) )->undo( $id, $force );
    }

    public function testOneUndoPutsEveryPreviousValueBackAndAFieldEditedSinceIsSkipped(): void
    {
        $before = $this->stored();
        $r      = $this->go( $this->threeUpdates() );
        $this->assertNotEquals( $before, $this->stored() );
        $this->writer->products[51]['weight'] = '5'; // the owner edits a field the run changed
        $u = $this->undoBatch( (int) $r['batch']['id'] );
        $this->assertSame( 2, $u['restored'] );
        $this->assertSame( [ 'restored', 'changed_since', 'restored' ], array_column( $u['items'], 'result' ) );
        $this->assertSame( '20', $this->writer->products[50]['regular_price'] );
        $this->assertSame( '10', $this->writer->products[60]['variations'][61]['regular_price'] );
        $this->assertSame( '5', $this->writer->products[51]['weight'], 'the owner\'s value stays' );
        $this->assertFalse( $u['fully_undone'] );

        $forced = $this->undoBatch( (int) $r['batch']['id'], true );
        $this->assertSame( 1, $forced['restored'] );
        $this->assertSame( '', $this->writer->products[51]['weight'] );
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter UpdateProductsTest`
Expected: errors `Class "AiEditorDivi5\Pro\Tools\UpdateProducts" not found`.

- [ ] **Step 3: Create the tool**

Create `pro-addon/src/Tools/UpdateProducts.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\ProductFieldsKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Products\ProductDiff;
use AiEditorDivi5\Pro\Products\ProductFields;
use AiEditorDivi5\Pro\Products\ProductSaveFailed;
use AiEditorDivi5\Pro\Products\ProductValuePolicy;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;
use AiEditorDivi5\Pro\Site\PreviewToken;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Change existing simple, external and variable WooCommerce products (and the price and stock of a variable product's variation rows).
 * It changes live products, so a real run needs the single-use token of a dry run of the same arguments; a product that changed since
 * that preview is skipped and reported, never overwritten. Every product is checked first (all or nothing), applied one at a time, read
 * back and compared; a failed save or a difference puts every product this run touched back and records nothing. One undoable batch per
 * run. It never changes a product's status, type or slug and never creates or deletes anything.
 */
final class UpdateProducts implements ProTool
{
    public const NAME            = 'update_products';
    public const MAX_PRODUCTS    = 25;
    public const MAX_ROWS        = 200;
    public const MAX_TEXT_BYTES  = 524288;
    public const MAX_PREVIOUS    = 1048576;
    private const MAX_MESSAGE    = 6000;
    private const NEEDS_TOKEN    = 'A real run needs the token from a dry run of the same arguments, made by you in the last 30 minutes and not used before. Call again with dry_run=true.';
    private const CHANGED        = 'changed_since_preview';

    private \Closure $clock;

    /** @param (\Closure():int)|null $clock fn(): unix time */
    public function __construct( private BatchLog $log, private ?ProductWriter $writer = null, ?\Closure $clock = null )
    {
        $this->clock = $clock ?? static fn (): int => time();
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
        $row    = [
            'id'             => [ 'type' => 'integer', 'description' => 'The variation id (it must belong to this product)' ],
            'regular_price'  => $string( 'Decimal string, or "" to clear' ),
            'sale_price'     => $string( 'Decimal string lower than regular_price, or "" to clear' ),
            'sale_from'      => $string( 'YYYY-MM-DD, or "" to clear' ),
            'sale_to'        => $string( 'YYYY-MM-DD, or "" to clear' ),
            'manage_stock'   => [ 'type' => 'boolean', 'description' => 'Track stock quantity' ],
            'stock_quantity' => [ 'type' => 'integer', 'description' => 'Needs manage_stock' ],
            'stock_status'   => $string( 'instock, outofstock or onbackorder (only when stock is not managed)' ),
            'backorders'     => $string( 'no, notify or yes (only when stock is managed)' ),
        ];

        return [
            'name'        => self::NAME,
            'description' => 'Change existing WooCommerce products: prices and sale dates, stock, texts, SKU, categories, tags, images, size and variation rows. Never publishes, creates or deletes. Call with dry_run=true first, then repeat with its token. One undoable batch. Read current values with get_product first.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'updates' => [
                        'type'        => 'array',
                        'description' => 'The products to change (1 to 25); omit a field to leave it alone',
                        'maxItems'    => self::MAX_PRODUCTS,
                        'items'       => [
                            'type'       => 'object',
                            'properties' => $row + [
                                'name'              => $string( 'Product name (plain text, at most 200 characters)' ),
                                'description'       => $string( 'Long description (HTML allowed, no scripts), or "" to clear' ),
                                'short_description' => $string( 'Short description (HTML allowed, no scripts), or "" to clear' ),
                                'sku'               => $string( 'Unique SKU, or "" to clear' ),
                                'categories'        => $list( 'Existing category slugs; the list replaces the current categories', 'string' ),
                                'tags'              => $list( 'Existing tag slugs; the list replaces the current tags', 'string' ),
                                'image_id'          => [ 'type' => 'integer', 'description' => 'Media Library image id, or 0 to remove the image' ],
                                'gallery_image_ids' => $list( 'Media Library image ids; the list replaces the gallery', 'integer' ),
                                'weight'            => $string( 'Decimal string, or "" to clear' ),
                                'dimensions'        => [ 'type' => 'object', 'description' => 'length, width and height as decimal strings; only the axes given change' ],
                                'tax_status'        => $string( 'taxable, shipping or none' ),
                                'variations'        => [
                                    'type'        => 'array',
                                    'description' => 'Variable products only: up to 100 variation rows to change',
                                    'items'       => [ 'type' => 'object', 'properties' => $row, 'required' => [ 'id' ] ],
                                ],
                            ],
                            'required'   => [ 'id' ],
                        ],
                    ],
                    'dry_run' => [ 'type' => 'boolean', 'description' => 'Preview only; returns the token a real run needs' ],
                    'token'   => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                ],
                'required'   => [ 'updates' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $dry    = Args::bool( $arguments, 'dry_run' );
        $items  = Args::listOf( $arguments, 'updates', self::MAX_PRODUCTS );
        $token  = Args::string( $arguments, 'token', false, 8000 );
        $writer = $this->writer();

        if ( ! $writer->isActive() ) {
            throw new \InvalidArgumentException( 'WooCommerce is not active on this site.' );
        }
        if ( ! current_user_can( 'edit_products' ) ) {
            throw new \InvalidArgumentException( 'You need the edit_products permission to change products.' );
        }

        $now      = ( $this->clock )();
        $userId   = (int) get_current_user_id();
        $argsHash = Canon::hash( $items );
        $map      = null;
        if ( ! $dry ) {
            $map = null === $token ? null : PreviewToken::open( $token, $argsHash, $now, $userId );
            if ( null === $map ) {
                throw new \InvalidArgumentException( self::NEEDS_TOKEN );
            }
        }

        $kses  = static fn ( string $s ): string => (string) wp_kses_post( $s );
        $strip = static fn ( string $s ): string => (string) wp_strip_all_tags( $s );

        $plans     = [];
        $skipped   = [];
        $unchanged = [];
        $refusals  = [];
        $seen      = [];
        $rows      = 0;
        $bytes     = 0;
        foreach ( $items as $i => $raw ) {
            $n = $i + 1;
            if ( ! is_array( $raw ) || array_is_list( $raw ) ) {
                $refusals[] = "Product {$n}: must be an object with an \"id\" and the fields to change";
                continue;
            }
            $id = $raw['id'] ?? null;
            if ( ! is_int( $id ) || $id < 1 ) {
                $refusals[] = "Product {$n}: \"id\" must be a whole product id";
                continue;
            }
            if ( isset( $seen[ $id ] ) ) {
                $refusals[] = "Product {$n} (#{$id}): the product appears more than once in this call";
                continue;
            }
            $seen[ $id ] = true;
            unset( $raw['id'] );

            $loaded = $this->load( $id, $writer );
            if ( null === $loaded['current'] ) {
                if ( $dry ) {
                    $refusals[] = "Product {$n} (#{$id}): {$loaded['why']}";
                } else {
                    $skipped[] = [ 'id' => $id, 'reason' => self::CHANGED ];
                }
                continue;
            }
            $current = $loaded['current'];
            $label   = self::label( $n, $id, (string) $current['name'] );
            $hash    = substr( Canon::hash( $current ), 0, 32 );
            $inMap   = null !== $map && isset( $map[ $id ] );
            if ( $inMap && $map[ $id ] !== $hash ) {
                $skipped[] = [ 'id' => $id, 'reason' => self::CHANGED ];
                continue;
            }

            $plan = $this->plan( $id, $raw, $current, $writer, $kses, $strip );
            if ( ! $dry && ! $inMap ) {
                // The preview saw nothing to change on this product. It stays out of the skipped list only if that is still true.
                if ( [] === $plan['reasons'] && [] === $plan['changes'] ) {
                    $unchanged[] = $id;
                } else {
                    $skipped[] = [ 'id' => $id, 'reason' => self::CHANGED ];
                }
                continue;
            }
            if ( [] !== $plan['reasons'] ) {
                foreach ( $plan['reasons'] as $reason ) {
                    $refusals[] = "{$label}: {$reason}";
                }
                continue;
            }
            if ( [] === $plan['changes'] ) {
                $unchanged[] = $id;
                continue;
            }
            $rows    += $plan['rows'];
            $bytes   += $plan['bytes'];
            $plans[] = $plan + [ 'id' => $id, 'label' => $label, 'hash' => $hash, 'name' => (string) $current['name'] ];
        }
        if ( $rows > self::MAX_ROWS ) {
            $refusals[] = "These updates change {$rows} variation rows; the limit is " . self::MAX_ROWS . ' per call, so split the call';
        }
        if ( $bytes > self::MAX_PREVIOUS ) {
            $refusals[] = 'The previous texts of these products, kept so the change can be undone, add up to more than 1 MB; split the call into smaller ones';
        }
        if ( [] !== $refusals ) {
            $message = 'Nothing was changed. ' . count( $refusals ) . ' problem(s): ' . implode( '; ', $refusals ) . '.';
            if ( mb_strlen( $message ) > self::MAX_MESSAGE ) {
                $message = mb_substr( $message, 0, self::MAX_MESSAGE - 3 ) . '...';
            }
            throw new \InvalidArgumentException( $message );
        }

        if ( $dry ) {
            return $this->preview( $plans, $unchanged, $argsHash, $now, $userId );
        }
        PreviewToken::spend( (string) $token );
        if ( [] === $plans ) {
            return [ 'changed' => false, 'dry_run' => false, 'updated' => [], 'skipped' => $skipped, 'unchanged' => $unchanged, 'message' => 'Nothing was changed.' ];
        }

        return $this->apply( $plans, $skipped, $unchanged );
    }

    /**
     * @return array{current: ?array<string,mixed>, why: string}
     */
    private function load( int $id, ProductWriter $writer ): array
    {
        $type = $writer->typeOf( $id );
        // A missing id, a trashed product, a variation, another post type and a product the user may not edit all answer alike.
        if ( null === $type || ! current_user_can( 'edit_post', $id ) ) {
            return [ 'current' => null, 'why' => 'product not found' ];
        }
        if ( 'grouped' === $type ) {
            return [ 'current' => null, 'why' => 'grouped products are not supported (their price comes from their children)' ];
        }
        $current = $writer->product( $id );
        if ( null === $current ) {
            return [ 'current' => null, 'why' => "{$type} products are not supported" ];
        }

        return [ 'current' => $current, 'why' => '' ];
    }

    /**
     * One product: the policy, then the checks that need the site (SKU owner, terms, images, size of the texts kept for undo).
     *
     * @param array<string,mixed> $raw the update without its id
     * @param array<string,mixed> $current
     * @return array{reasons: list<string>, changes: array<string,mixed>, previous: array<string,mixed>, display: array<string,mixed>, current: array<string,mixed>, rows: int, bytes: int}
     */
    private function plan( int $id, array $raw, array $current, ProductWriter $writer, \Closure $kses, \Closure $strip ): array
    {
        $none = [ 'reasons' => [], 'changes' => [], 'previous' => [], 'display' => [ 'fields' => [], 'variations' => [] ], 'current' => $current, 'rows' => 0, 'bytes' => 0 ];
        $checked = ProductValuePolicy::checkPartial( $raw, $current, $kses, $strip );
        if ( null === $checked['changes'] ) {
            return [ 'reasons' => $checked['reasons'] ] + $none;
        }
        $changes  = $checked['changes'];
        $r        = [];
        $slugsFor = [];

        if ( isset( $changes['sku'] ) && '' !== $changes['sku'] ) {
            $owner = $writer->skuOwner( (string) $changes['sku'] );
            if ( $owner > 0 && $owner !== $id ) {
                $r[] = "sku \"{$changes['sku']}\" is already used by another product";
            }
        }
        foreach ( [ 'category' => [ 'product_cat', 'category_slugs', 'category_ids' ], 'tag' => [ 'product_tag', 'tag_slugs', 'tag_ids' ] ] as $word => [ $taxonomy, $slugKey, $idKey ] ) {
            if ( ! array_key_exists( $slugKey, $changes ) ) {
                continue;
            }
            $slugs = $changes[ $slugKey ];
            unset( $changes[ $slugKey ] );
            if ( ! current_user_can( 'assign_product_terms' ) ) {
                $r[] = 'you need the assign_product_terms permission to use categories or tags';
                continue;
            }
            $found = [] === $slugs ? [ 'ids' => [], 'missing' => [] ] : $writer->termIds( $taxonomy, $slugs );
            foreach ( $found['missing'] as $slug ) {
                $r[] = "{$word} \"{$slug}\" does not exist (create it in WooCommerce first)";
            }
            $ids = array_map( 'intval', $found['ids'] );
            sort( $ids );
            if ( $ids !== array_map( 'intval', (array) $current[ $idKey ] ) ) {
                $changes[ $idKey ]  = $ids;
                $slugsFor[ $idKey ] = $slugs;
            }
        }
        if ( isset( $changes['image_id'] ) && (int) $changes['image_id'] > 0 && ! $writer->imageUsable( (int) $changes['image_id'] ) ) {
            $r[] = "image_id {$changes['image_id']} is not an image in the Media Library that you can use";
        }
        foreach ( (array) ( $changes['gallery_image_ids'] ?? [] ) as $gid ) {
            if ( ! in_array( $gid, (array) $current['gallery_image_ids'], true ) && ! $writer->imageUsable( (int) $gid ) ) {
                $r[] = "gallery_image_ids {$gid} is not an image in the Media Library that you can use";
            }
        }

        // The previous values are stored for undo: a text that cannot be stored safely is not changed.
        $previous = ProductFields::pick( $current, ProductFields::tracked( $changes ) );
        $bytes    = 0;
        foreach ( ProductFields::TEXTS as $field ) {
            if ( ! isset( $previous['fields'][ $field ] ) ) {
                continue;
            }
            $old = (string) $previous['fields'][ $field ];
            if ( 1 !== preg_match( '//u', $old ) ) {
                $r[] = "its current \"{$field}\" is not valid UTF-8, so the change could not be undone safely";
            } elseif ( strlen( $old ) > self::MAX_TEXT_BYTES ) {
                $r[] = "its current \"{$field}\" is larger than 512 KB, too large to keep for undo, so this tool will not change it";
            }
            $bytes += strlen( $old );
        }
        if ( [] !== $r ) {
            return [ 'reasons' => $r ] + $none;
        }

        return [
            'reasons'  => [],
            'changes'  => $changes,
            'previous' => $previous,
            'display'  => self::display( $changes, $current, $slugsFor ),
            'current'  => $current,
            'rows'     => count( (array) ( $changes['variations'] ?? [] ) ),
            'bytes'    => $bytes,
        ];
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @param list<int> $unchanged
     * @return array<string,mixed>
     */
    private function preview( array $plans, array $unchanged, string $argsHash, int $now, int $userId ): array
    {
        if ( [] === $plans ) {
            return [ 'changed' => false, 'dry_run' => true, 'would_change' => 0, 'products' => [], 'unchanged' => $unchanged, 'message' => 'Nothing to change: every product already has the requested values, so there is nothing to apply.' ];
        }
        $today    = function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d', $now ) : gmdate( 'Y-m-d', $now );
        $map      = [];
        $products = [];
        foreach ( $plans as $plan ) {
            $map[ $plan['id'] ] = $plan['hash'];
            $row                = [ 'id' => $plan['id'], 'name' => $plan['name'], 'type' => (string) $plan['current']['type'], 'changes' => $plan['display']['fields'] ];
            if ( [] !== $plan['display']['variations'] ) {
                $row['variations'] = $plan['display']['variations'];
            }
            $notes = self::notes( $plan['changes'], $today );
            if ( [] !== $notes ) {
                $row['notes'] = $notes;
            }
            $products[] = $row;
        }

        return [
            'changed'      => false,
            'dry_run'      => true,
            'would_change' => count( $plans ),
            'products'     => $products,
            'unchanged'    => $unchanged,
            'token'        => PreviewToken::make( $argsHash, $map, $now, $userId ),
            'message'      => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to apply it (it works once, within 30 minutes).',
        ];
    }

    /**
     * Apply product by product, read each back and compare; any failure puts every product this run touched back and records nothing.
     *
     * @param list<array<string,mixed>> $plans
     * @param list<array{id:int, reason:string}> $skipped
     * @param list<int> $unchanged
     * @return array<string,mixed>
     */
    private function apply( array $plans, array $skipped, array $unchanged ): array
    {
        $writer  = $this->writer();
        $done    = [];
        $failing = $plans[0];
        try {
            foreach ( $plans as $k => $plan ) {
                $failing = $plan;
                $done[]  = $plan;
                $writer->apply( $plan['id'], $plan['changes'] );
                $back = $writer->product( $plan['id'] );
                if ( null === $back ) {
                    throw new ProductSaveFailed( 'the saved product could not be read back' );
                }
                $asked = ProductFields::asShape( $plan['changes'] );
                $diff  = ProductDiff::compare( $asked, ProductFields::pick( $back, $asked ) );
                if ( [] !== $diff ) {
                    throw new ProductSaveFailed( 'it was saved differently than asked (' . implode( ', ', $diff ) . ')' );
                }
                $plans[ $k ]['after']      = ProductFields::pick( $back, $plan['previous'] );
                $plans[ $k ]['name_after'] = (string) $back['name'];
            }
        } catch ( \Throwable $e ) {
            $notRestored = $this->rollback( $done, $writer );
            // Only our own constant messages (ProductSaveFailed) and WooCommerce's data exceptions are safe to show; any other message
            // (a foreign RuntimeException, a hook, a database error) could carry a server path.
            $safe   = $e instanceof ProductSaveFailed || is_a( $e, 'WC_Data_Exception' );
            $reason = $safe ? $e->getMessage() : 'WooCommerce could not save it';
            $text   = "Could not update {$failing['label']}: {$reason}.";
            if ( [] !== $notRestored ) {
                throw new \InvalidArgumentException( $text . ' These products may not be fully restored and should be checked: #' . implode( ', #', $notRestored ) . '.' );
            }
            throw new \InvalidArgumentException( $text . ' Every product this call had touched was put back; nothing was changed.' );
        }

        $updated = [];
        $records = [];
        foreach ( $plans as $plan ) {
            $row = [ 'id' => $plan['id'], 'name' => $plan['name_after'], 'changes' => $plan['display']['fields'] ];
            if ( [] !== $plan['display']['variations'] ) {
                $row['variations'] = $plan['display']['variations'];
            }
            $updated[] = $row;
            $records[] = ProductFieldsKind::itemFor( $plan['id'], self::cut( $plan['name_after'] ), self::what( $plan['changes'] ), $plan['previous'], $plan['after'] );
        }
        $count = count( $records );
        $batch = $this->log->record( self::NAME, "Updated {$count} product(s)", $records );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'updated' => $updated, 'skipped' => $skipped, 'unchanged' => $unchanged ], $batch );
    }

    /**
     * Puts the previous values back on every product the run started to change, newest first, and checks each read-back.
     *
     * @param list<array<string,mixed>> $done
     * @return list<int> the ids that could not be put back
     */
    private function rollback( array $done, ProductWriter $writer ): array
    {
        $left = [];
        foreach ( array_reverse( $done ) as $plan ) {
            try {
                $writer->apply( $plan['id'], ProductFields::asChanges( $plan['previous'] ) );
                $back = $writer->product( $plan['id'] );
                if ( null === $back || [] !== ProductDiff::compare( $plan['previous'], ProductFields::pick( $back, $plan['previous'] ) ) ) {
                    $left[] = $plan['id'];
                }
            } catch ( \Throwable $e ) {
                $left[] = $plan['id'];
            }
        }

        return $left;
    }

    /**
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $current
     * @param array<string,list<string>> $slugsFor category_ids / tag_ids => the slugs that were asked for
     * @return array{fields: array<string,mixed>, variations: list<array<string,mixed>>}
     */
    private static function display( array $changes, array $current, array $slugsFor ): array
    {
        $fields = [];
        foreach ( $changes as $field => $value ) {
            if ( 'variations' === $field ) {
                continue;
            }
            if ( 'category_ids' === $field || 'tag_ids' === $field ) {
                $key            = 'category_ids' === $field ? 'categories' : 'tags';
                $before         = 'category_ids' === $field ? $current['category_slugs'] : $current['tag_slugs'];
                $fields[ $key ] = [ 'before' => $before, 'after' => $slugsFor[ $field ] ?? $value ];
                continue;
            }
            $fields[ $field ] = [ 'before' => self::shown( $current[ $field ] ?? null ), 'after' => self::shown( $value ) ];
        }
        $rows = [];
        foreach ( (array) ( $changes['variations'] ?? [] ) as $vid => $row ) {
            $diff = [];
            foreach ( $row as $field => $value ) {
                $diff[ $field ] = [ 'before' => self::shown( $current['variations'][ $vid ][ $field ] ?? null ), 'after' => self::shown( $value ) ];
            }
            $rows[] = [ 'id' => (int) $vid, 'changes' => $diff ];
        }

        return [ 'fields' => $fields, 'variations' => $rows ];
    }

    /** A sale that starts in the future leaves the price at the regular price until WooCommerce's own scheduler starts it. @param array<string,mixed> $changes @return list<string> */
    private static function notes( array $changes, string $today ): array
    {
        $dates = isset( $changes['sale_from'] ) ? [ (string) $changes['sale_from'] ] : [];
        foreach ( (array) ( $changes['variations'] ?? [] ) as $row ) {
            if ( isset( $row['sale_from'] ) ) {
                $dates[] = (string) $row['sale_from'];
            }
        }
        foreach ( $dates as $date ) {
            if ( '' !== $date && $date > $today ) {
                return [ "A sale that starts on {$date} leaves the price at the regular price until WooCommerce's own scheduler starts it." ];
            }
        }

        return [];
    }

    /** @param array<string,mixed> $changes */
    private static function what( array $changes ): string
    {
        $names = [];
        foreach ( array_keys( $changes ) as $field ) {
            $names[] = 'variations' === $field ? count( (array) $changes['variations'] ) . ' variation row(s)' : (string) $field;
        }
        $what = implode( ', ', $names );

        return mb_strlen( $what ) > 120 ? mb_substr( $what, 0, 117 ) . '...' : $what;
    }

    private static function shown( mixed $value ): mixed
    {
        return is_string( $value ) && mb_strlen( $value ) > 200 ? mb_substr( $value, 0, 200 ) . '...' : $value;
    }

    private static function label( int $n, int $id, string $name ): string
    {
        return "Product {$n} (#{$id} \"" . self::cut( $name ) . '")';
    }

    private static function cut( string $name ): string
    {
        $name = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $name );

        return mb_strlen( $name ) > 60 ? mb_substr( $name, 0, 60 ) . '...' : $name;
    }

    private function writer(): ProductWriter
    {
        return $this->writer ??= new WooProductWriter();
    }
}
```

- [ ] **Step 4: Register the file**

In `pro-addon/src/autoload.php`, replace this exact text:

```php
require_once __DIR__ . '/Tools/CreateProduct.php';

```

with:

```php
require_once __DIR__ . '/Tools/CreateProduct.php';
require_once __DIR__ . '/Tools/UpdateProducts.php';

```

- [ ] **Step 5: Run the tool tests and the suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter UpdateProductsTest` - Expected: `OK (38 tests, 287 assertions)`.
Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK`.

- [ ] **Step 6: RED proofs (opus reviewer: do every one, then revert)**

Each mutation is in `UpdateProducts.php`; the named tests must FAIL (or error) with it:

| Mutation | Must fail |
|---|---|
| `if ( $inMap && $map[ $id ] !== $hash ) {` -> `if ( false ) {` | `testAProductChangedSinceThePreviewIsSkippedAndTheOthersProceed` and 2 more |
| delete `PreviewToken::spend( (string) $token );` | `testARealRunNeedsAGenuineUnusedTokenForTheSameArgumentsByTheSameUserInTime` and 1 more |
| `$notRestored = $this->rollback( $done, $writer );` -> `$notRestored = [];` | `testAFailedSaveRestoresEveryProductThisRunChangedAndRecordsNoBatch` and 3 more |
| `if ( [] !== $diff ) {` (in `apply()`) -> `if ( false ) {` | `testASaveThatReadsBackDifferentlyIsRolledBack` |
| `$owner > 0 && $owner !== $id` -> `$owner > 0` | `testTheSkuMayBeKeptChangedInCaseButNeverTakenFromAnotherProduct` |
| `if ( $bytes > self::MAX_PREVIOUS ) {` -> `if ( false ) {` | `testThePreviousTextsOfOneCallMayNotExceedOneMegabyte` |

- [ ] **Step 7: Commit**

```bash
git add pro-addon/src/Tools/UpdateProducts.php pro-addon/tests/UpdateProductsTest.php pro-addon/src/autoload.php
git commit -m "$(cat <<'EOF'
feat(pro): update_products tool (preview + token, skip changed products, read-back, rollback, one undoable batch)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Wiring, version 0.11.0 and docs

**Files:**
- Modify: `pro-addon/src/Tools.php`, `pro-addon/src/Rest/ProOpenApi.php`
- Modify (pinned tests): `pro-addon/tests/ToolsTest.php`, `pro-addon/tests/ProOpenApiTest.php`, `pro-addon/tests/ExpiryKeepsAllToolsTest.php`, `pro-addon/tests/bootstrap.php`
- Modify (version/docs): `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`, `pro-addon/readme.txt`, `pro-addon/README.md`, `CLAUDE.md`

**Interfaces:**
- Consumes: `UpdateProducts` (Task 5).
- Produces: the registry exposes 20 Pro tools; `ProOpenApi::MCP_REST_ONLY` has six entries ending in `update_products`; `AIED_PRO_VERSION` is `0.11.0`.

The pinned tests are changed FIRST (they describe the new world), so Step 2 shows them failing.

- [ ] **Step 1: Update the pinned tests**

In `pro-addon/tests/ToolsTest.php` (testThisReleaseShipsExactlyTheseTools), replace this exact text:

```php
'set_custom_fields', 'create_product' ],
            array_map
```

with:

```php
'set_custom_fields', 'create_product', 'update_products' ],
            array_map
```

In `pro-addon/tests/ToolsTest.php` (the comment in testTheChatGptOperationBudgetIsRespected), replace this exact text:

```php
The 15th to 19th Pro tools, manage_global_colors, manage_global_variables, manage_global_presets, set_custom_fields and create_product, are
```

with:

```php
The 15th to 20th Pro tools, manage_global_colors, manage_global_variables, manage_global_presets, set_custom_fields, create_product and update_products, are
```

In `pro-addon/tests/ToolsTest.php` (the pinned MCP-only list), replace this exact text:

```php
[ 'manage_global_colors', 'manage_global_variables', 'manage_global_presets', 'set_custom_fields', 'create_product' ], ProOpenApi::MCP_REST_ONLY
```

with:

```php
[ 'manage_global_colors', 'manage_global_variables', 'manage_global_presets', 'set_custom_fields', 'create_product', 'update_products' ], ProOpenApi::MCP_REST_ONLY
```

In `pro-addon/tests/ProOpenApiTest.php`, replace this exact text:

```php
        $this->assertArrayNotHasKey( '/pro/create_product', $paths, 'MCP + REST only: no room in the 30-operation ChatGPT spec' );

```

with:

```php
        $this->assertArrayNotHasKey( '/pro/create_product', $paths, 'MCP + REST only: no room in the 30-operation ChatGPT spec' );
        $this->assertArrayNotHasKey( '/pro/update_products', $paths, 'MCP + REST only: no room in the 30-operation ChatGPT spec' );

```

In `pro-addon/tests/ExpiryKeepsAllToolsTest.php` change the three places that say 19 to 20: the docblock line `Verify that all 19 Pro tools are callable in lapsed states` (-> `all 20 Pro tools`), `$this->assertCount( 19, $names );` (-> `20`) and the message `'all 19 Pro tools are advertised'` (-> `'all 20 Pro tools are advertised'`).

In `pro-addon/tests/bootstrap.php` change `define( 'AIED_PRO_VERSION', '0.10.0' );` to `'0.11.0'`.

- [ ] **Step 2: Run the suite and see the expected failures**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected failures (and only these): five tests of `ExpiryKeepsAllToolsTest` (the count is now 20), `ReadmeTest::testTheStableTagIsTheCurrentVersion` (the bootstrap says 0.11.0, the readme still 0.10.0), `ToolsTest::testThisReleaseShipsExactlyTheseTools` and `ToolsTest::testTheChatGptOperationBudgetIsRespected`.

- [ ] **Step 3: Wire the tool**

In `pro-addon/src/Tools.php` (the use line, after UndoBatch), replace this exact text:

```php
use AiEditorDivi5\Pro\Tools\UndoBatch;

```

with:

```php
use AiEditorDivi5\Pro\Tools\UndoBatch;
use AiEditorDivi5\Pro\Tools\UpdateProducts;

```

In `pro-addon/src/Tools.php` (the list, after CreateProduct), replace this exact text:

```php
            new CreateProduct( $log ),

```

with:

```php
            new CreateProduct( $log ),
            new UpdateProducts( $log ),

```

In `pro-addon/src/Rest/ProOpenApi.php`, replace this exact text:

```php
'set_custom_fields', 'create_product' ];
```

with:

```php
'set_custom_fields', 'create_product', 'update_products' ];
```

- [ ] **Step 4: Version 0.11.0 and the add-on's readme**

In `pro-addon/jhmg-ai-editor-for-divi-5-pro.php` change the header line ` * Version:           0.10.0` to `0.11.0` and `define( 'AIED_PRO_VERSION', '0.10.0' );` to `'0.11.0'`.

**Edit 1 of 3 in `pro-addon/readme.txt`** - replace this exact text:

```text
Contributors:      lucaslopvet
Requires at least: 6.0
Requires PHP:      8.1
Stable tag:        0.10.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

```

with:

```text
Contributors:      lucaslopvet
Requires at least: 6.0
Requires PHP:      8.1
Stable tag:        0.11.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

```

**Edit 2 of 3 in `pro-addon/readme.txt`** - replace this exact text:

```text
* `manage_global_colors` - create, recolor, rename or deactivate your site's Divi global colors (never deletes one; the five theme colors only with `allow_theme_colors`). It affects every page that uses a color, so every real run needs the token from a preview (`dry_run`); undo puts it all back.
* `set_custom_fields` - set custom field values on one page, Divi Project or other post you can edit (only keys your site already uses; never protected or secret keys, code, or ACF lists and images; all or nothing; `dry_run` previews; `undo_batch` puts the values back as long as nothing changed them afterwards).
* `create_product` - create up to 25 new simple WooCommerce products per call as drafts (never published): name, descriptions, SKU, regular and sale price, stock, backorders, existing categories and tags, a main image and gallery from your Media Library, weight, dimensions, tax status, virtual and sold individually. Needs WooCommerce. Everything is checked first and nothing is created unless every product passes (unknown categories, tags or images are refused, never created); each saved product is read back and compared, and a difference removes what the call created. `dry_run` previews; one `undo_batch` moves the created drafts to the trash unless you edited, published or trashed one since. It never changes an existing product.
* `list_batches` - list recent Pro changes.
* `undo_batch` - undo one batch.

Every tool is also available over REST (`POST /wp-json/ai-editor-divi5/v1/pro/<tool>`) and, except `manage_global_colors`, `manage_global_variables`, `manage_global_presets`, `set_custom_fields` and `create_product` (MCP and REST only), in the free plugin's ChatGPT OpenAPI spec, with the same permission checks. The Pro tools stay off, over every transport, until the licence is unlocked.

Each tool checks the permission it really needs (for example `manage_options` to set the front page), not only that the request is authenticated.

```

with:

```text
* `manage_global_colors` - create, recolor, rename or deactivate your site's Divi global colors (never deletes one; the five theme colors only with `allow_theme_colors`). It affects every page that uses a color, so every real run needs the token from a preview (`dry_run`); undo puts it all back.
* `set_custom_fields` - set custom field values on one page, Divi Project or other post you can edit (only keys your site already uses; never protected or secret keys, code, or ACF lists and images; all or nothing; `dry_run` previews; `undo_batch` puts the values back as long as nothing changed them afterwards).
* `create_product` - create up to 25 new simple WooCommerce products per call as drafts (never published): name, descriptions, SKU, regular and sale price, stock, backorders, existing categories and tags, a main image and gallery from your Media Library, weight, dimensions, tax status, virtual and sold individually. Needs WooCommerce. Everything is checked first and nothing is created unless every product passes (unknown categories, tags or images are refused, never created); each saved product is read back and compared, and a difference removes what the call created. `dry_run` previews; one `undo_batch` moves the created drafts to the trash unless you edited, published or trashed one since. It never changes an existing product.
* `update_products` - change existing WooCommerce products (simple, external and variable): regular and sale price with a sale schedule, stock, name, descriptions, SKU, existing categories and tags, images, weight, dimensions, tax status, and the price and stock of a variable product's variation rows. Needs WooCommerce. It changes live products, so every real run needs the token from a preview (`dry_run`), which shows each change as before and after; a product that changed since the preview is skipped, never overwritten. Everything is checked first and each saved product is read back and compared; a failure puts every product the call touched back. It never publishes, unpublishes, creates or deletes a product. One `undo_batch` puts the previous values back unless you changed those fields since.
* `list_batches` - list recent Pro changes.
* `undo_batch` - undo one batch.

Every tool is also available over REST (`POST /wp-json/ai-editor-divi5/v1/pro/<tool>`) and, except `manage_global_colors`, `manage_global_variables`, `manage_global_presets`, `set_custom_fields`, `create_product` and `update_products` (MCP and REST only), in the free plugin's ChatGPT OpenAPI spec, with the same permission checks. The Pro tools stay off, over every transport, until the licence is unlocked.

Each tool checks the permission it really needs (for example `manage_options` to set the front page), not only that the request is authenticated.

```

**Edit 3 of 3 in `pro-addon/readme.txt`** - replace this exact text:

```text
Deleting the plugin removes its own data (licence state, batch history, saved code proposals). It does not undo your site's front page, menu, CSS, page statuses, header or footer content or Theme Builder templates: undo those first if you want them back.

== Changelog ==

= 0.10.0 =
* New: `create_product` creates new simple WooCommerce products as drafts: name, descriptions, SKU, prices, stock, existing categories and tags and Media Library images, weight, dimensions and tax status. Up to 25 products per call, checked before anything is saved: if one is refused none is created and every reason is listed. A product is never published, an existing product is never changed, and every saved product is read back and compared (a difference removes what the call created). Add `dry_run` to preview. One `undo_batch` moves the created drafts to the trash, unless you edited, published or trashed one since.
```

with:

```text
Deleting the plugin removes its own data (licence state, batch history, saved code proposals). It does not undo your site's front page, menu, CSS, page statuses, header or footer content or Theme Builder templates: undo those first if you want them back.

== Changelog ==

= 0.11.0 =
* New: `update_products` changes existing WooCommerce products: prices and sale schedules, stock, texts, SKU, categories, tags, images, weight, dimensions and tax status of simple, external and variable products, and the price and stock of a variable product's variation rows. Up to 25 products per call. Every real run needs the single-use token of a preview (`dry_run`) that shows each change as before and after; a product that changed since the preview is skipped. Checked before anything is saved, read back after, and put back if anything fails. It never changes a product's status, type or slug and never creates or deletes one. One `undo_batch` restores the previous values unless you changed those fields since.
* Over MCP and REST only: this is the 20th Pro tool, and the ChatGPT importer's 30 operations are used up.

= 0.10.0 =
* New: `create_product` creates new simple WooCommerce products as drafts: name, descriptions, SKU, prices, stock, existing categories and tags and Media Library images, weight, dimensions and tax status. Up to 25 products per call, checked before anything is saved: if one is refused none is created and every reason is listed. A product is never published, an existing product is never changed, and every saved product is read back and compared (a difference removes what the call created). Add `dry_run` to preview. One `undo_batch` moves the created drafts to the trash, unless you edited, published or trashed one since.
```

- [ ] **Step 5: `pro-addon/README.md`**

Make these edits (use the Edit tool; each old text is unique):
1. In the `src/ProTool.php ...` table row: `lists the nineteen tools` -> `lists the twenty tools`.
2. In the `src/Tools/` row: `the nineteen tools:` -> `the twenty tools:` and append `, \`update_products\`` after `` `create_product` `` at the end of the list.
3. Insert this section immediately BEFORE the line `## Known limits`:

```markdown
## Update products (`update_products`)

The tool changes EXISTING simple, external and variable WooCommerce products, and the price and stock of a variable product's variation rows. It needs WooCommerce active, `edit_products`, `edit_post` on every product (plus `assign_product_terms` when categories or tags are given) and `edit_posts` like the other Pro tools. Arguments: `updates` (1 to 25 objects, each `{ "id": <product>, ...fields }`), a strict boolean `dry_run` and a `token`. An omitted field is left alone, `""` clears a text, the SKU, a price, a sale date or the weight, a list replaces the whole set, an unknown key is refused by name.

| Group | Fields and rules |
|---|---|
| Prices and sale window | `regular_price`, `sale_price` (decimal strings), `sale_from`, `sale_to` (`YYYY-MM-DD`). Judged against the RESULTING state: a sale price needs a regular price and is lower; sale dates need a sale price; `sale_to` is not before `sale_from`. A sale starts at 00:00:00 of its first day and ends at 23:59:59 of its last (site time zone). Not available on a variable product itself (use its variation rows). |
| Stock | `manage_stock`, `stock_quantity` (0 to 1,000,000), `stock_status`, `backorders`. A quantity needs managed stock, managed stock needs a quantity, `stock_status` is refused while stock is managed. External products have no stock fields. |
| Text and catalogue | `name`, `description`, `short_description`, `sku`, `categories`, `tags`, `image_id` (0 removes it), `gallery_image_ids`, `weight`, `dimensions` (only the axes given change), `tax_status`. The same rules as `create_product`; the SKU may be the product's own. |
| Variation rows | `variations`: up to 100 objects `{ "id": <variation of this product>, regular_price, sale_price, sale_from, sale_to, manage_stock, stock_quantity, stock_status, backorders }`; at most 200 rows per call. |

Never changed: status (publish, draft, private, trash), type, slug, grouped products, attributes, the variations themselves (nothing is created or deleted), tax and shipping classes, downloads, upsells, any other meta.

**Preview and token.** `dry_run: true` validates everything (all or nothing: every reason comes back, each prefixed `Product N (#id "name")`, cut at 6,000 characters) and returns, per product, a before and after of only the fields that would change, plus ONE `token`. A real run needs that token (single use, 30 minutes, same user, same arguments). The token carries a hash of each product's whole current state, variation rows included: a product that changed since the preview is SKIPPED and reported (`changed_since_preview`), the others proceed. A refused run keeps its token; it is spent when the first write starts. A scheduled sale (`sale_from` in the future) leaves the price at the regular price until WooCommerce's own scheduler starts it; the preview says so in `notes`.

**Apply, read back, roll back.** Products are saved one at a time; each is read back and compared (texts through `ProductValuePolicy::sameText`, so core's `&amp;` is the same text; prices and dates as exact values; categories: a requested set must match exactly, an empty one accepts the store's default; derived values such as a variable parent's price or a managed product's stock status are not compared). A failed save or a difference puts every product the run touched back, records NO batch and answers with a safe message (only this add-on's own texts and WooCommerce's data-exception text are shown). A text whose current value is over 512 KB (or not valid UTF-8) is not changed, and the previous texts of one call may not exceed 1 MB.

**Undo.** A real run records ONE batch of `product_fields` items. `before` holds the previous values of only the fields that changed (the whole stock group where `manage_stock` changed, and per variation row only the fields that row changed); `after_hash` is the hash of those fields as read back. `undo_batch` restores a product only while those fields still hold what the run wrote (a field the owner changed since keeps the product item back; `force` skips that rule, never a permission or existence check), reads them back, and records the inverse so the undo can be undone.

MCP + REST only: the 30-operation ChatGPT budget is used up, so `update_products` is the sixth tool in `ProOpenApi::MCP_REST_ONLY`. Spec: `docs/superpowers/specs/2026-10-13-pro-update-products-design.md`; live gate: `make verify-pro-update-products`. Known limits: two real runs racing each other are not serialised (WooCommerce's own admin has the same property); a persistent object cache can show stale reads right after a save.

```
4. In the `Known limits` bullet about the ChatGPT importer: `The 15th to 19th Pro tools, ... \`set_custom_fields\` and \`create_product\`, are MCP + REST only` -> `The 15th to 20th Pro tools, ... \`set_custom_fields\`, \`create_product\` and \`update_products\`, are MCP + REST only`.
5. In the `verify-pro-*` bullet: `all twelve refuse to run` -> `every one of them refuses to run`.

- [ ] **Step 6: `CLAUDE.md`**

1. In "The Pro add-on" bullet: `0.10.0: compat guard` -> `0.11.0: compat guard`; `registry with 19 tools` -> `registry with 20 tools`; append `, changing existing WooCommerce products (\`update_products\`)` after `simple WooCommerce products as drafts (\`create_product\`)`.
2. In the entry-points table, directly after the `make verify-pro-create-product` row add:
   `| \`make verify-pro-update-products\` | Real-site check of update_products with the real WooProductWriter: preview/token, prices and sale window, stock, texts, terms, images, variation rows (parent price follows), skip of a product changed since the preview, rollback on a failing or lying writer, one undo (and undo of the undo, and a field edited since), shop-manager kses, refusals; originals byte-identical (self-cleaning; dev site only; free + Pro + WooCommerce active; evicts old batch rows) |`
3. In "Current state", add as the FIRST bullet: `- **Unreleased (branch feat/pro-update-products): Pro 0.11.0 update_products** (sub-project B, second tool): changes existing simple/external/variable WooCommerce products (prices and sale window, stock, texts, SKU, terms, images, size, variation rows) behind a before/after preview and a single-use PreviewToken (per-product state hash; a product changed since is skipped), read back, rolled back on any failure, one undoable batch (\`product_fields\` kind: previous values of only the changed fields); MCP + REST only (ProOpenApi::MCP_REST_ONLY); never changes status/type/slug, never creates or deletes; spec/plan docs/superpowers/specs|plans/2026-10-13-pro-update-products*; live gate make verify-pro-update-products. bulk_adjust_prices is next. Release (store upload, divi5lab.com copy 20 Pro tools) is the owner's step.`
4. In the "Unreleased (branch feat/pro-create-product)" bullet replace the sentence `update_products and bulk_adjust_prices are next.` with `update_products is built (next bullet up); bulk_adjust_prices is next.` and `divi5lab.com copy 19 Pro tools` with `divi5lab.com copy 19-20 Pro tools`.

- [ ] **Step 7: Run everything**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK (1982 tests, ...)` (the counts in this plan were measured in a scratch copy; if yours differs by a few, what matters is **0 failures, 0 errors** - say so in your report).
Run: `./vendor/bin/phpunit` (the free suite) - Expected: `OK (1056 tests ...)` unchanged.
Run: `make build-pro` - Expected: `[build-pro] Packaging jhmg-ai-editor-for-divi-5-pro version 0.11.0` and a zip in `dist/` (git-ignored).

- [ ] **Step 8: Commit**

```bash
git add pro-addon/src/Tools.php pro-addon/src/Rest/ProOpenApi.php pro-addon/tests/ToolsTest.php pro-addon/tests/ProOpenApiTest.php pro-addon/tests/ExpiryKeepsAllToolsTest.php pro-addon/tests/bootstrap.php pro-addon/jhmg-ai-editor-for-divi-5-pro.php pro-addon/readme.txt pro-addon/README.md CLAUDE.md
git commit -m "$(cat <<'EOF'
feat(pro): register update_products (20th Pro tool, MCP + REST only), version 0.11.0, docs

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: The live gate `make verify-pro-update-products`

**Files:**
- Create: `scripts/verify-pro-update-products.php`
- Modify: `Makefile` (`.PHONY` list and a new target), `pro-addon/tests/DevGuardTest.php` (13 -> 14 scripts)

**Interfaces:**
- Consumes: the finished tool (Task 5), the free plugin's `ProductService::get` / `WooProductGateway` (read path), `BatchTable`, `Batches`, `scripts/dev-guard.php`.
- Produces: a self-cleaning WP-CLI gate. It creates its OWN throwaway products (simple, plain, other, trashed, external, grouped, variable with 3 variations), a category, a tag, two media files and two users, and edits only those; the products the site already has must be byte-identical afterwards.

**Rules for this task (important):**
- The gate runs on the Docker dev site only (`scripts/dev-guard.php`). WooCommerce, the free plugin and the Pro add-on must be active (`make up` first; never `wp plugin delete` anything). WooCommerce must still be active afterwards.
- A **FAIL is a real finding** about the tool or the writer: report it with the output; never edit the gate to make it pass. Fix a defect in the gate only when it is clearly the script's own (a typo, a wrong helper); say exactly what you changed and why. A **NOTE** records a WooCommerce behaviour the design leaves open.
- The script was written without WooCommerce in front of it. Expect to run it, read the first failures, and decide script bug versus product bug each time. The things most likely to need a script fix: the keys of the free `get_product` output, the shape of the variation attribute (`size`), and timing of WooCommerce's transients.
- If the Docker site is not available, stop and report BLOCKED; do not skip the task.

- [ ] **Step 1: Create the script**

Create `scripts/verify-pro-update-products.php`:

```php
<?php
/**
 * Real-site check of the Pro tool update_products: the real tool with the REAL WooProductWriter (WooCommerce's own CRUD classes), the
 * real batch table, the real undo, WordPress's REAL wp_kses_post() and wp_strip_all_tags() (the unit tests only use stand-ins), and
 * the free plugin's own read path (ProductService::get) plus WooCommerce's getters, so what the AI asked for is compared with what
 * WooCommerce stored through independent code paths. It proves: a dry run changes nothing; a real run needs the single-use token;
 * prices, a sale window, stock, texts, SKU, terms, images, weight and variation rows are applied and read back; the variable parent's
 * derived price follows its variations; a product changed between the preview and the run is skipped; a failing writer, and a
 * writer that saves something else, put every product back and record no batch; one undo restores every value exactly and an undo
 * can be undone; a field edited after the run is skipped by the undo; the shop manager role works (core kses stores "&" as "&amp;");
 * an editor without edit_products is refused; grouped, trashed, missing and variation ids are refused; and that the products the
 * site already has are byte-identical afterwards.
 *
 * Self-cleaning: it force-deletes every product it created (SKU AIED-UP-*, or name "AIED UP ..."; also trashed ones), its product
 * category and tag (slugs aied-up-*), its Media Library files ("AIED UP ..."), its throwaway users (aied_pro_up_*) and the batch rows
 * it recorded. It records several batches and the batch store keeps only the newest 20, so on a site that already has batches it
 * evicts the oldest real ones: dev sites only.
 *
 * Run: make verify-pro-update-products   (needs `make up`, WooCommerce, the free plugin and the Pro add-on active; no licence is
 * needed: the tool is called directly, not through the licence-gated registry)
 *
 * A FAIL is a finding about the tool or the writer: report it, never edit this script to hide it. A NOTE records a behaviour of
 * WooCommerce that the design leaves open (see the spec's "Open points").
 */

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;
use AiEditorDivi5\Pro\Tools\UpdateProducts;
use AiEditorDivi5\WP\ProductService;
use AiEditorDivi5\WP\WooProductGateway;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( UpdateProducts::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro (with update_products) on this site first.' );
}
require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();
if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'wc_get_product' ) ) {
    WP_CLI::error( 'WooCommerce is not active on this site; activate it first.' );
}
if ( ! class_exists( ProductService::class ) || ! class_exists( WooProductGateway::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (with get_product) on this site first.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

const AIED_UP_SKU    = 'AIED-UP-';
const AIED_UP_NAME   = 'AIED UP ';
const AIED_UP_SLUG   = 'aied-up-';
const AIED_UP_USERS  = [ 'editor' => 'aied_pro_up_editor', 'shop_manager' => 'aied_pro_up_shop' ];
const AIED_UP_SECRET = '/var/www/html/secret.php';

/** Decorates the real writer: the Nth apply() throws a foreign exception with a path in it, or one field is silently put back after a save. */
final class AiedUpFailingWriter implements ProductWriter
{
    public int $applyCalls = 0;

    public function __construct( private WooProductWriter $real, private int $failOn = 0, private ?string $dropField = null )
    {
    }

    public function isActive(): bool { return $this->real->isActive(); }
    public function skuTaken( string $sku ): bool { return $this->real->skuTaken( $sku ); }
    public function skuOwner( string $sku ): int { return $this->real->skuOwner( $sku ); }
    public function termIds( string $taxonomy, array $slugs ): array { return $this->real->termIds( $taxonomy, $slugs ); }
    public function imageUsable( int $id ): bool { return $this->real->imageUsable( $id ); }
    public function create( array $values ): int { return $this->real->create( $values ); }
    public function snapshot( int $id ): ?array { return $this->real->snapshot( $id ); }
    public function typeOf( int $id ): ?string { return $this->real->typeOf( $id ); }
    public function product( int $id ): ?array { return $this->real->product( $id ); }
    public function delete( int $id ): bool { return $this->real->delete( $id ); }
    public function editUrl( int $id ): string { return $this->real->editUrl( $id ); }

    public function apply( int $id, array $changes ): void
    {
        if ( $this->failOn === ++$this->applyCalls ) {
            throw new \RuntimeException( AIED_UP_SECRET . ' simulated writer failure' );
        }
        $was = null !== $this->dropField ? ( $this->real->product( $id )[ $this->dropField ] ?? null ) : null;
        $this->real->apply( $id, $changes );
        if ( null !== $this->dropField && array_key_exists( $this->dropField, $changes ) && 1 === $this->applyCalls ) {
            $this->real->apply( $id, [ $this->dropField => $was ] ); // the store "saved something else"
        }
    }
}

/** Delete the throwaway users this script creates: only those exact logins, never user 1. */
$deleteUsers = static function (): void {
    foreach ( AIED_UP_USERS as $login ) {
        $u = get_user_by( 'login', $login );
        if ( $u instanceof \WP_User && $login === $u->user_login && (int) $u->ID > 1 ) {
            wp_delete_user( (int) $u->ID );
        }
    }
};
/** Every product or variation (any status, the trash too) this script could have made: SKU AIED-UP-* or name "AIED UP ...". */
$mine = static function (): array {
    global $wpdb;
    $bySku  = (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' WHERE p.post_type IN ('product','product_variation') AND m.meta_value LIKE %s",
        $wpdb->esc_like( AIED_UP_SKU ) . '%'
    ) );
    $byName = (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_title LIKE %s",
        $wpdb->esc_like( AIED_UP_NAME ) . '%'
    ) );
    $ids = array_values( array_unique( array_map( 'intval', array_merge( $bySku, $byName ) ) ) );
    sort( $ids );

    return $ids;
};
$forceDelete = static function ( int $id ): void {
    $p = wc_get_product( $id );
    if ( $p ) {
        $p->delete( true );
    } else {
        wp_delete_post( $id, true );
    }
};
$myAttachments = static function (): array {
    global $wpdb;

    return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title LIKE %s",
        $wpdb->esc_like( AIED_UP_NAME ) . '%'
    ) ) );
};
$myTerms = static function (): array {
    $out = [];
    foreach ( [ 'product_cat', 'product_tag' ] as $tax ) {
        $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
        foreach ( is_array( $terms ) ? $terms : [] as $t ) {
            if ( str_starts_with( (string) $t->slug, AIED_UP_SLUG ) ) {
                $out[] = [ (int) $t->term_id, $tax ];
            }
        }
    }

    return $out;
};
$removeThrowaways = static function () use ( $deleteUsers, $mine, $forceDelete, $myAttachments, $myTerms ): void {
    foreach ( array_reverse( $mine() ) as $id ) { // variations (higher ids) before their parents
        $forceDelete( $id );
    }
    foreach ( $mine() as $id ) {
        $forceDelete( $id );
    }
    foreach ( $myAttachments() as $id ) {
        wp_delete_attachment( $id, true );
    }
    foreach ( $myTerms() as [ $termId, $tax ] ) {
        wp_delete_term( $termId, $tax );
    }
    $deleteUsers();
};
// Every product and variation the site has (any status), as one hash: proof that nothing was written to them.
$snapshot = static function (): string {
    global $wpdb;
    $ids  = array_map( 'intval', (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') ORDER BY ID" ) );
    $rows = [];
    foreach ( $ids as $id ) {
        clean_post_cache( $id );
        $post   = get_post( $id );
        $p      = wc_get_product( $id );
        $rows[] = [
            $id, $post ? $post->post_title : null, $post ? $post->post_status : null,
            $p ? $p->get_regular_price( 'edit' ) : null, $p ? $p->get_sale_price( 'edit' ) : null,
            $p ? $p->get_stock_status( 'edit' ) : null, $p ? $p->get_stock_quantity( 'edit' ) : null,
            $post ? $post->post_modified_gmt : null, $post ? $post->post_password : null,
        ];
    }

    return md5( serialize( $rows ) );
};
$totalProducts = static function ( string $types = "'product','product_variation'" ): int {
    global $wpdb;

    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$types})" ); // phpcs:ignore -- a constant list
};

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$note = static function ( string $label ): void {
    WP_CLI::log( 'NOTE  ' . $label );
};

wp_set_current_user( 1 );
// A previous run that was killed may have left its throwaways behind: remove only those exact ones.
$removeThrowaways();

$batchIds = [];
BatchTable::ensureReady( true );
$check( BatchTable::exists(), 'the batch table exists on the real database (' . BatchTable::name() . ')' );
$log    = Batches::log();
$undo   = Batches::undo();
$tool   = new UpdateProducts( $log );
$writer = new WooProductWriter();

$track = static function ( array $r ) use ( &$batchIds ): array {
    if ( isset( $r['batch']['id'] ) ) {
        $batchIds[] = (int) $r['batch']['id'];
    }

    return $r;
};
$trackUndo = static function ( array $r ) use ( &$batchIds ): array {
    if ( isset( $r['undo_batch']['id'] ) ) {
        $batchIds[] = (int) $r['undo_batch']['id'];
    }

    return $r;
};
/** Run a tool (the real one by default); returns [ payload, error message or null ]. Anything but a refusal is "UNEXPECTED". */
$run = static function ( array $args, ?UpdateProducts $with = null ) use ( &$tool, $track ): array {
    try {
        return [ $track( ( $with ?? $tool )->run( $args ) ), null ];
    } catch ( \InvalidArgumentException $e ) {
        return [ [], $e->getMessage() ];
    } catch ( \Throwable $e ) {
        return [ [], 'UNEXPECTED ' . get_class( $e ) . ': ' . $e->getMessage() ];
    }
};
/** Preview, then a real run with the token. Returns [ preview, result, error ]. */
$go = static function ( array $updates, ?UpdateProducts $with = null ) use ( $run ): array {
    [ $p, $perr ] = $run( [ 'updates' => $updates, 'dry_run' => true ], $with );
    if ( null !== $perr || empty( $p['token'] ) ) {
        return [ $p, [], $perr ?? 'the preview made no token' ];
    }
    [ $r, $err ] = $run( [ 'updates' => $updates, 'token' => $p['token'] ], $with );

    return [ $p, $r, $err ];
};
$newestId = static fn (): int => (int) ( $log->recent( 1 )[0]['id'] ?? 0 );
/** The free plugin's get_product, through its own gateway: a read path independent of WooProductWriter::product(). */
$readBack = static function ( int $id ): array {
    clean_post_cache( $id );
    $r = ProductService::get( $id, new WooProductGateway(), 'current_user_can' );

    return true === ( $r['ok'] ?? false ) ? (array) ( $r['data']['product'] ?? [] ) : [];
};
$termIds = static fn ( array $p, string $key ): array => array_map( 'intval', array_column( (array) ( $p[ $key ] ?? [] ), 'id' ) );
/** WooCommerce's own getters (edit context) for one product or variation: a second independent read path. */
$wc = static function ( int $id ): array {
    clean_post_cache( $id );
    $p = wc_get_product( $id );
    if ( ! $p ) {
        return [];
    }
    $from = $p->get_date_on_sale_from( 'edit' );
    $to   = $p->get_date_on_sale_to( 'edit' );

    return [
        'regular' => (string) $p->get_regular_price( 'edit' ), 'sale' => (string) $p->get_sale_price( 'edit' ), 'price' => (string) $p->get_price( 'edit' ),
        'from' => $from ? $from->date( 'Y-m-d' ) : '', 'to' => $to ? $to->date( 'Y-m-d' ) : '', 'on_sale' => $p->is_on_sale( 'edit' ),
        'manage' => (bool) $p->get_manage_stock( 'edit' ), 'qty' => $p->get_stock_quantity( 'edit' ), 'status' => (string) $p->get_stock_status( 'edit' ),
        'slug' => (string) get_post_field( 'post_name', $id ), 'post_status' => (string) get_post_status( $id ), 'date' => (string) get_post_field( 'post_date', $id ),
    ];
};
$day = static fn ( int $offset ): string => (string) wp_date( 'Y-m-d', time() + $offset * DAY_IN_SECONDS );

$makeSimple = static function ( string $name, string $sku, array $props ): int {
    $p = new WC_Product_Simple();
    $p->set_name( $name );
    $p->set_sku( $sku );
    $p->set_status( 'publish' );
    foreach ( $props as $key => $value ) {
        $p->{'set_' . $key}( $value );
    }

    return (int) $p->save();
};

$before       = $snapshot();
$beforeTotal  = $totalProducts();
$beforeOnly   = $totalProducts( "'product'" );
$beforeIds    = $mine();
$check( [] === $beforeIds, "the site starts without throwaways ({$beforeOnly} products, {$beforeTotal} products and variations, in every status)" );

$users = [];
try {
    // ---- fixtures ------------------------------------------------------------------------------------------------------------
    $cat = wp_insert_term( AIED_UP_NAME . 'Cat', 'product_cat', [ 'slug' => AIED_UP_SLUG . 'cat' ] );
    $tag = wp_insert_term( AIED_UP_NAME . 'Tag', 'product_tag', [ 'slug' => AIED_UP_SLUG . 'tag' ] );
    if ( is_wp_error( $cat ) || is_wp_error( $tag ) ) {
        // Not WP_CLI::error(): it exits at once and would skip the cleanup in the `finally` below.
        throw new \RuntimeException( 'Could not create the throwaway category or tag.' );
    }
    $catId = (int) $cat['term_id'];
    $tagId = (int) $tag['term_id'];
    $png   = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
    $attach = static function ( string $file, string $bits, string $mime, string $title ): int {
        $up = wp_upload_bits( $file, null, $bits );
        if ( ! empty( $up['error'] ) ) {
            throw new \RuntimeException( 'Could not upload a throwaway file.' );
        }
        $id = wp_insert_attachment( [ 'post_title' => $title, 'post_mime_type' => $mime, 'post_status' => 'inherit', 'guid' => $up['url'] ], $up['file'], 0, true );
        if ( is_wp_error( $id ) ) {
            @unlink( $up['file'] ); // phpcs:ignore
            throw new \RuntimeException( 'Could not create a throwaway attachment.' );
        }

        return (int) $id;
    };
    $img = $attach( 'aied-up-image.png', $png, 'image/png', AIED_UP_NAME . 'image' );
    $doc = $attach( 'aied-up-doc.txt', 'not an image', 'text/plain', AIED_UP_NAME . 'doc' );

    $S = $makeSimple( AIED_UP_NAME . 'Simple', AIED_UP_SKU . 'S', [
        'regular_price' => '20', 'manage_stock' => true, 'stock_quantity' => 10, 'description' => '<p>Old description</p>', 'short_description' => 'Old short', 'weight' => '1',
    ] );
    $U = $makeSimple( AIED_UP_NAME . 'Plain', AIED_UP_SKU . 'U', [ 'stock_status' => 'outofstock' ] );
    $O = $makeSimple( AIED_UP_NAME . 'Other', AIED_UP_SKU . 'O', [ 'regular_price' => '5' ] );
    $T = $makeSimple( AIED_UP_NAME . 'Trashed', AIED_UP_SKU . 'T', [ 'regular_price' => '5' ] );
    wp_trash_post( $T );

    $ext = new WC_Product_External();
    $ext->set_name( AIED_UP_NAME . 'External' );
    $ext->set_sku( AIED_UP_SKU . 'E' );
    $ext->set_status( 'publish' );
    $ext->set_product_url( 'https://example.com/buy' );
    $ext->set_regular_price( '9' );
    $E = (int) $ext->save();

    $grp = new WC_Product_Grouped();
    $grp->set_name( AIED_UP_NAME . 'Grouped' );
    $grp->set_sku( AIED_UP_SKU . 'G' );
    $grp->set_status( 'publish' );
    $grp->set_children( [ $S ] );
    $G = (int) $grp->save();

    $var = new WC_Product_Variable();
    $var->set_name( AIED_UP_NAME . 'Variable' );
    $var->set_sku( AIED_UP_SKU . 'V' );
    $var->set_status( 'publish' );
    $attr = new WC_Product_Attribute();
    $attr->set_id( 0 );
    $attr->set_name( 'Size' );
    $attr->set_options( [ 'Small', 'Large', 'Huge' ] );
    $attr->set_position( 0 );
    $attr->set_visible( true );
    $attr->set_variation( true );
    $var->set_attributes( [ $attr ] );
    $V = (int) $var->save();
    $vids = [];
    foreach ( [ 'Small' => [ '10', 5 ], 'Large' => [ '12', 3 ], 'Huge' => [ '15', 0 ] ] as $size => [ $price, $qty ] ) {
        $v = new WC_Product_Variation();
        $v->set_parent_id( $V );
        $v->set_attributes( [ 'size' => $size ] );
        $v->set_regular_price( $price );
        $v->set_manage_stock( true );
        $v->set_stock_quantity( $qty );
        $v->set_status( 'publish' );
        $vids[ $size ] = (int) $v->save();
    }
    WC_Product_Variable::sync( $V );
    [ $vSmall, $vLarge, $vHuge ] = array_values( $vids );
    $check( $S > 0 && $U > 0 && $O > 0 && $T > 0 && $E > 0 && $G > 0 && $V > 0 && 3 === count( array_filter( $vids ) ) && $catId > 0 && $tagId > 0 && $img > 0 && $doc > 0,
        'the throwaway fixtures exist: simple, plain, other, trashed, external, grouped and variable products (3 variations), a category, a tag, an image and a text file' );
    $defaultCat = (int) get_option( 'default_product_cat', 0 );
    $allIds     = [ $S, $U, $O, $E, $G, $V ];
    $fixtures   = static function () use ( $allIds, $writer ): string {
        $rows = [];
        foreach ( $allIds as $id ) {
            clean_post_cache( $id );
            $rows[ $id ] = $writer->product( $id );
        }

        return md5( serialize( $rows ) );
    };
    $stateOf = static function ( array $ids ) use ( $writer ): array {
        $out = [];
        foreach ( $ids as $id ) {
            clean_post_cache( $id );
            $out[ $id ] = $writer->product( $id );
        }

        return $out;
    };
    $original   = $stateOf( [ $S, $U, $V ] );
    $slugBefore = [ $S => get_post_field( 'post_name', $S ), $U => get_post_field( 'post_name', $U ), $V => get_post_field( 'post_name', $V ) ];
    $dateBefore = [ $S => get_post_field( 'post_date', $S ), $U => get_post_field( 'post_date', $U ), $V => get_post_field( 'post_date', $V ) ];

    // ---- one big update: prices and a sale window, stock, texts, SKU, terms, image, size, variation rows --------------------------
    $yesterday = $day( -1 );
    $inMonth   = $day( 30 );
    $big       = [
        [ 'id' => $S, 'name' => AIED_UP_NAME . 'Simple Two', 'regular_price' => '25', 'sale_price' => '19.50', 'sale_from' => $yesterday, 'sale_to' => $inMonth, 'stock_quantity' => 7,
          'description' => 'Fish & chips <p>New</p>', 'sku' => AIED_UP_SKU . 'S2' ],
        [ 'id' => $U, 'manage_stock' => true, 'stock_quantity' => 4, 'weight' => '2.5', 'dimensions' => [ 'length' => '10', 'height' => '2.5' ], 'tax_status' => 'none',
          'categories' => [ AIED_UP_SLUG . 'cat' ], 'tags' => [ AIED_UP_SLUG . 'tag' ], 'image_id' => $img ],
        [ 'id' => $V, 'variations' => [ [ 'id' => $vSmall, 'regular_price' => '11', 'stock_quantity' => 9 ], [ 'id' => $vLarge, 'sale_price' => '9.50' ], [ 'id' => $vHuge, 'stock_quantity' => 6 ] ] ],
    ];

    // dry run
    $newestBefore = $newestId();
    $fixBefore    = $fixtures();
    [ $p, $err ] = $run( [ 'updates' => $big, 'dry_run' => true ] );
    $check( null === $err && false === ( $p['changed'] ?? null ) && true === ( $p['dry_run'] ?? null ) && 3 === ( $p['would_change'] ?? 0 ) && ! empty( $p['token'] ), 'a dry run of three products previews all three and returns a token' . ( null === $err ? '' : ': ' . $err ) );
    $check( $fixBefore === $fixtures() && $newestBefore === $newestId() && ! isset( $p['batch'] ), 'a dry run changes nothing and records no batch' );
    $check( '20' === ( $p['products'][0]['changes']['regular_price']['before'] ?? null ) && '25' === ( $p['products'][0]['changes']['regular_price']['after'] ?? null ), 'the preview shows before and after of each changed field' );
    $token = (string) ( $p['token'] ?? '' );

    // refusals of a real run without a good token
    [ , $err ] = $run( [ 'updates' => $big ] );
    $check( null !== $err && str_contains( $err, 'needs the token' ) && $fixBefore === $fixtures(), 'a real run without a token is refused and changes nothing' );
    [ , $err ] = $run( [ 'updates' => array_slice( $big, 0, 2 ), 'token' => $token ] );
    $check( null !== $err && str_contains( $err, 'needs the token' ) && $fixBefore === $fixtures(), 'the token of other arguments is refused' );

    // the real run
    [ $r, $err ] = $run( [ 'updates' => $big, 'token' => $token ] );
    $check( null === $err && true === ( $r['changed'] ?? null ) && 3 === count( (array) ( $r['updated'] ?? [] ) ) && [] === ( $r['skipped'] ?? [ 1 ] ), 'a real run with the token updates all three' . ( null === $err ? '' : ': ' . $err ) );
    $check( true === ( $r['batch']['undoable'] ?? false ), 'the run recorded one undoable batch' );
    $batchMain = (int) ( $r['batch']['id'] ?? 0 );

    $s = $readBack( $S );
    $check( AIED_UP_NAME . 'Simple Two' === ( $s['name'] ?? null ) && '25' === ( $s['regular_price'] ?? null ) && '19.50' === ( $s['sale_price'] ?? null ) && 7 === ( $s['stock_quantity'] ?? null ) && AIED_UP_SKU . 'S2' === ( $s['sku'] ?? null ),
        'get_product (free) reads back name, prices, stock and SKU of the simple product' . ( ( $s['name'] ?? null ) === AIED_UP_NAME . 'Simple Two' ? '' : ': ' . wp_json_encode( $s ) ) );
    $check( 'Fish & chips <p>New</p>' === ( $s['description'] ?? null ), 'the description with "&" is stored as sent (administrator, unfiltered_html)' );
    $w = $wc( $S );
    $check( $yesterday === $w['from'] && $inMonth === $w['to'] && true === $w['on_sale'] && '19.50' === $w['price'], "the sale window is read back as {$yesterday} to {$inMonth}, the product is on sale and its price is the sale price" . ( $yesterday === $w['from'] ? '' : ': ' . wp_json_encode( $w ) ) );
    $check( 'Old short' === ( $s['short_description'] ?? null ) && '1' === ( $s['weight'] ?? null ) && 'taxable' === ( $s['tax_status'] ?? null ), 'fields the update did not name are untouched (short description, weight, tax status)' );
    $check( $slugBefore[ $S ] === $w['slug'] && 'publish' === $w['post_status'] && $dateBefore[ $S ] === $w['date'], 'the simple product keeps its slug, its publish status and its date' );

    $u = $readBack( $U );
    $check( true === ( $u['manage_stock'] ?? null ) && 4 === ( $u['stock_quantity'] ?? null ) && 'instock' === ( $u['stock_status'] ?? null ), 'the plain product now manages stock: quantity 4, status derived to instock' );
    $check( '2.5' === ( $u['weight'] ?? null ) && 'none' === ( $u['tax_status'] ?? null ) && '10' === ( $u['dimensions']['length'] ?? null ) && '2.5' === ( $u['dimensions']['height'] ?? null ) && '' === ( $u['dimensions']['width'] ?? 'x' ), 'weight, tax status and the two dimension axes given are stored; the third is untouched' );
    $check( [ $catId ] === $termIds( $u, 'categories' ) && [ $tagId ] === $termIds( $u, 'tags' ) && wp_get_attachment_url( $img ) === ( $u['images']['featured'] ?? null ), 'categories and tags replaced the old ones and the featured image is set (free read path)' );
    $wu = $wc( $U );
    $check( $slugBefore[ $U ] === $wu['slug'] && 'publish' === $wu['post_status'], 'the plain product keeps its slug and its publish status' );

    $rs = $wc( $vSmall );
    $rl = $wc( $vLarge );
    $rh = $wc( $vHuge );
    $check( '11' === $rs['regular'] && 9 === $rs['qty'], 'variation Small: regular price 11, quantity 9' );
    $check( '9.50' === $rl['sale'] && '12' === $rl['regular'] && true === $rl['on_sale'], 'variation Large: sale price 9.50 under its regular 12' );
    $check( 6 === $rh['qty'] && 'instock' === $rh['status'], 'variation Huge: quantity 6, status derived to instock' );
    clean_post_cache( $V );
    $parent = wc_get_product( $V );
    $check( 9.5 === (float) $parent->get_variation_price( 'min', true ) && 15.0 === (float) $parent->get_variation_price( 'max', true ), 'the variable parent\'s price range follows its rows (min 9.50, max 15)' );
    $wv = $wc( $V );
    $check( $slugBefore[ $V ] === $wv['slug'] && 'publish' === $wv['post_status'] && $dateBefore[ $V ] === $wv['date'], 'the variable product keeps its slug, its publish status and its date' );

    // the token is single use
    [ , $err ] = $run( [ 'updates' => $big, 'token' => $token ] );
    $check( null !== $err && str_contains( $err, 'needs the token' ), 'the token works once: a second run with it is refused' );

    // ---- one undo puts every value back exactly --------------------------------------------------------------------------------
    $u1 = $trackUndo( $undo->undo( $batchMain ) );
    $check( 3 === ( $u1['restored'] ?? 0 ) && true === ( $u1['fully_undone'] ?? false ), 'undo_batch restores all three products and reports a full undo' );
    $now = $stateOf( [ $S, $U, $V ] );
    $diff = [];
    foreach ( $original as $id => $o ) {
        if ( $o !== $now[ $id ] ) {
            $diff[] = $id . ':' . implode( ',', array_keys( array_diff_assoc( array_map( 'serialize', $o ), array_map( 'serialize', (array) $now[ $id ] ) ) ) );
        }
    }
    $check( [] === $diff, 'after the undo every field of the three products equals its original value, variation rows included' . ( [] === $diff ? '' : ' (differs: ' . implode( '; ', $diff ) . ')' ) );
    $check( $original[ $U ]['category_ids'] === $now[ $U ]['category_ids'], 'the plain product is back under its original category (' . implode( ',', $now[ $U ]['category_ids'] ) . ')' );
    $inv = (int) ( $u1['undo_batch']['id'] ?? 0 );
    $u2  = $trackUndo( $undo->undo( $inv ) );
    $check( 3 === ( $u2['restored'] ?? 0 ) && '25' === $wc( $S )['regular'] && 9 === $wc( $vSmall )['qty'], 'the undo can be undone: the updated values are back' );
    $u3 = $trackUndo( $undo->undo( (int) ( $u2['undo_batch']['id'] ?? 0 ) ) );
    $check( 3 === ( $u3['restored'] ?? 0 ) && '20' === $wc( $S )['regular'], 'and undone once more: the originals are back' );

    // ---- a product changed between the preview and the run is skipped; the others proceed ------------------------------------------
    $two = [ [ 'id' => $S, 'regular_price' => '30' ], [ 'id' => $U, 'weight' => '3' ] ];
    [ $p, $perr ] = $run( [ 'updates' => $two, 'dry_run' => true ] );
    $sp = wc_get_product( $S );
    $sp->set_regular_price( '28' ); // the owner edits it after the preview
    $sp->save();
    [ $r, $err ] = $run( [ 'updates' => $two, 'token' => (string) ( $p['token'] ?? '' ) ] );
    $check( null === $err && [ $U ] === array_map( 'intval', array_column( (array) ( $r['updated'] ?? [] ), 'id' ) ) && [ $S ] === array_map( 'intval', array_column( (array) ( $r['skipped'] ?? [] ), 'id' ) ), 'a product changed after the preview is skipped and reported, the other proceeds' . ( null === $err ? '' : ': ' . $err ) );
    $check( '28' === $wc( $S )['regular'], 'the skipped product keeps the owner\'s value' );
    $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    $sp = wc_get_product( $S );
    $sp->set_regular_price( '20' );
    $sp->save();

    // ---- a field edited after a run is skipped by the undo -----------------------------------------------------------------------------
    [ , $r, $err ] = $go( [ [ 'id' => $S, 'regular_price' => '33' ], [ 'id' => $U, 'weight' => '4' ] ] );
    $sp = wc_get_product( $S );
    $sp->set_regular_price( '34' ); // the owner edits a field the run changed
    $sp->save();
    $ux = $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    $results = array_column( (array) ( $ux['items'] ?? [] ), 'result' );
    $check( null === $err && [ 'changed_since', 'restored' ] === $results && '34' === $wc( $S )['regular'] && '' === (string) $writer->product( $U )['weight'], 'undo restores the untouched product and skips the one whose field the owner edited since' );
    $sp = wc_get_product( $S );
    $sp->set_regular_price( '20' );
    $sp->save();

    // ---- the failure paths: every product the run touched is put back, no batch ----------------------------------------------------
    $failUpdates = [ [ 'id' => $S, 'regular_price' => '31', 'stock_quantity' => 2 ], [ 'id' => $U, 'weight' => '4', 'tax_status' => 'shipping' ], [ 'id' => $V, 'variations' => [ [ 'id' => $vSmall, 'regular_price' => '99.5' ] ] ] ];
    $fixBefore    = $fixtures();
    $stateBefore  = $stateOf( [ $S, $U, $V ] );
    $newestBefore = $newestId();
    $failing = new UpdateProducts( $log, new AiedUpFailingWriter( new WooProductWriter(), 2 ) );
    [ , , $err ] = $go( $failUpdates, $failing );
    $check( null !== $err && str_contains( $err, 'Could not update Product 2' ) && ! str_contains( $err, '/var/www' ) && ! str_contains( $err, 'simulated' ), 'a failing second save is refused with a message that shows no path: ' . (string) $err );
    $check( $fixBefore === $fixtures() && $stateBefore === $stateOf( [ $S, $U, $V ] ) && $newestBefore === $newestId(), 'after the failing save every product is exactly as before (first product put back) and no batch was recorded' );

    $dropping = new UpdateProducts( $log, new AiedUpFailingWriter( new WooProductWriter(), 0, 'regular_price' ) );
    [ , , $err ] = $go( $failUpdates, $dropping );
    $check( null !== $err && str_contains( $err, 'saved differently than asked (regular_price)' ), 'a store that saves something else is caught by the read-back: ' . (string) $err );
    $check( $fixBefore === $fixtures() && $stateBefore === $stateOf( [ $S, $U, $V ] ) && $newestBefore === $newestId(), 'after the read-back difference every product is exactly as before and no batch was recorded' );

    // ---- permissions and the shop manager role ---------------------------------------------------------------------------------------------
    foreach ( AIED_UP_USERS as $role => $login ) {
        $uid = wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.invalid', 'role' => $role ] );
        if ( is_wp_error( $uid ) ) {
            throw new \RuntimeException( 'Could not create a throwaway user.' );
        }
        $users[ $role ] = (int) $uid;
    }
    wp_set_current_user( $users['editor'] );
    $fixBefore = $fixtures();
    [ , $err ] = $run( [ 'updates' => [ [ 'id' => $S, 'regular_price' => '40' ] ], 'dry_run' => true ] );
    $check( null !== $err && str_contains( $err, 'edit_products' ) && $fixBefore === $fixtures(), 'an editor without edit_products is refused and nothing changes' );

    wp_set_current_user( $users['shop_manager'] );
    $fixBefore = $fixtures();
    [ , $r, $err ] = $go( [ [ 'id' => $S, 'name' => AIED_UP_NAME . 'Salt & Pepper', 'description' => 'Fish & chips' ] ] );
    $check( null === $err && true === ( $r['changed'] ?? null ), 'a shop manager changes a name and a description containing "&" (core kses stores "&amp;"; the read-back accepts it)' . ( null === $err ? '' : ': ' . $err ) );
    $sm = $readBack( $S );
    $check( str_contains( (string) ( $sm['name'] ?? '' ), 'Salt &amp; Pepper' ) || str_contains( (string) ( $sm['name'] ?? '' ), 'Salt & Pepper' ), 'the stored name is "Salt & Pepper" or its "&amp;" form: ' . (string) ( $sm['name'] ?? '' ) );
    [ $p2 ] = $run( [ 'updates' => [ [ 'id' => $S, 'name' => AIED_UP_NAME . 'Salt & Pepper' ] ], 'dry_run' => true ] );
    $check( [ $S ] === array_map( 'intval', (array) ( $p2['unchanged'] ?? [] ) ), 'asking for the same name again is "unchanged" (the stored "&amp;" is the same name)' );
    $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    wp_set_current_user( 1 );
    $check( $fixBefore === $fixtures(), 'the shop manager\'s change was undone' );

    // ---- refusals: nothing is changed, no batch -----------------------------------------------------------------------------------------
    $fixBefore    = $fixtures();
    $newestBefore = $newestId();
    $cases = [
        'a grouped product'                  => [ [ [ 'id' => $G, 'name' => AIED_UP_NAME . 'x' ] ], 'grouped products are not supported' ],
        'a variation id as the product id'   => [ [ [ 'id' => $vSmall, 'regular_price' => '1' ] ], 'product not found' ],
        'a trashed product'                  => [ [ [ 'id' => $T, 'regular_price' => '1' ] ], 'product not found' ],
        'a missing id'                       => [ [ [ 'id' => 99999999, 'regular_price' => '1' ] ], 'product not found' ],
        'status in the update'               => [ [ [ 'id' => $S, 'status' => 'draft' ] ], 'never changes a product\'s status, type or slug' ],
        'a slug in the update'               => [ [ [ 'id' => $S, 'slug' => 'new-slug' ] ], '"slug" is not a supported field' ],
        'a sale price above the regular'     => [ [ [ 'id' => $S, 'sale_price' => '99' ] ], 'must be lower than the "regular_price"' ],
        'a sale date without a sale price'   => [ [ [ 'id' => $S, 'sale_from' => $day( 1 ) ] ], 'sale dates need a "sale_price"' ],
        'a SKU another product has'          => [ [ [ 'id' => $S, 'sku' => AIED_UP_SKU . 'O' ] ], 'already used by another product' ],
        'markup in a SKU'                    => [ [ [ 'id' => $S, 'sku' => '<b>x</b>' ] ], '"sku" must not contain < or >' ],
        'a backslash in a name'              => [ [ [ 'id' => $S, 'name' => AIED_UP_NAME . 'back\\slash' ] ], 'backslash' ],
        'a script in a description'          => [ [ [ 'id' => $S, 'description' => '<script>alert(1)</script>' ] ], 'script' ],
        'an unknown category'                => [ [ [ 'id' => $S, 'categories' => [ AIED_UP_SLUG . 'nope' ] ] ], 'does not exist' ],
        'an image that is a text file'       => [ [ [ 'id' => $S, 'image_id' => $doc ] ], 'is not an image' ],
        'stock on an external product'       => [ [ [ 'id' => $E, 'stock_quantity' => 3 ] ], 'not available on an external product' ],
        'a price on a variable product'      => [ [ [ 'id' => $V, 'regular_price' => '5' ] ], 'not available on a variable product' ],
        'a variation of another product'     => [ [ [ 'id' => $V, 'variations' => [ [ 'id' => $S, 'regular_price' => '1' ] ] ] ], 'does not belong to this product' ],
        'a stock status while managed'       => [ [ [ 'id' => $S, 'stock_status' => 'outofstock' ] ], 'cannot be set while stock is managed' ],
    ];
    foreach ( $cases as $label => [ $updates, $needle ] ) {
        [ , $err ] = $run( [ 'updates' => array_merge( [ [ 'id' => $O, 'regular_price' => '6' ] ], $updates ), 'dry_run' => true ] );
        $check( null !== $err && str_starts_with( $err, 'Nothing was changed.' ) && str_contains( $err, $needle ) && $fixBefore === $fixtures() && $newestBefore === $newestId(),
            "refused: {$label} (the valid product beside it was not changed, no batch)" . ( null !== $err && str_contains( $err, $needle ) ? '' : ': ' . (string) $err ) );
    }

    // ---- what WooCommerce does with a sale window (the design's open points) -------------------------------------------------------------
    [ , $r, $err ] = $go( [ [ 'id' => $S, 'sale_price' => '15', 'sale_from' => $day( 10 ) ] ] );
    $ws = $wc( $S );
    $check( null === $err && false === $ws['on_sale'] && '20' === $ws['price'], 'a sale that starts in ten days leaves the price at the regular price (not on sale yet)' . ( null === $err ? '' : ': ' . $err ) );
    $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    [ , $r, $err ] = $go( [ [ 'id' => $S, 'sale_price' => '10', 'sale_from' => '2020-01-01', 'sale_to' => '2020-02-01' ] ] );
    if ( null === $err ) {
        $note( 'a sale that ended in 2020 was accepted and read back as asked: on_sale=' . wp_json_encode( $wc( $S )['on_sale'] ) . ', price=' . $wc( $S )['price'] );
        $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    } else {
        $note( 'a sale that ended in 2020 was refused by the read-back (WooCommerce changed it): ' . $err );
        $check( '20' === $wc( $S )['regular'] && '' === $wc( $S )['sale'], 'and the product was left as it was' );
    }

    // ---- everything the checks above changed is back --------------------------------------------------------------------------------------
    $finalState = $stateOf( [ $S, $U, $V ] );
    $check( $finalState === $original, 'the three fixtures end in exactly their original state, variation rows included' );
} catch ( Throwable $e ) {
    $check( false, 'unexpected error: ' . $e->getMessage() );
} finally {
    global $wpdb;
    wp_set_current_user( 1 );
    $removeThrowaways();
    if ( [] !== $batchIds ) {
        $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batchIds ) ) ) . ')' ); // phpcs:ignore
    }
}

$check( [] === $mine() && [] === $myAttachments() && [] === $myTerms(), 'cleanup: every throwaway product (any status), the category, the tag and the files are gone' );
$check( ! get_user_by( 'login', AIED_UP_USERS['editor'] ) && ! get_user_by( 'login', AIED_UP_USERS['shop_manager'] ), 'cleanup: the throwaway users are gone' );
$leftBatches = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batchIds ) ) ) . ')' ); // phpcs:ignore
$check( 0 === $leftBatches, 'cleanup: the ' . count( array_unique( $batchIds ) ) . ' batch rows this run recorded are gone' );
$check( $snapshot() === $before, 'cleanup: every original product and variation is byte-identical (' . $before . ')' );
$check( $beforeTotal === $totalProducts() && $beforeOnly === $totalProducts( "'product'" ), "cleanup: the numbers of products ({$beforeOnly}) and of products and variations ({$beforeTotal}) in every status, the trash included, are the original ones again" );

if ( [] !== $failures ) {
    WP_CLI::error( 'update_products NOT verified: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'update_products verified on the real site.' );
```

- [ ] **Step 2: Makefile target**

**Edit 1 of 2 in `Makefile`** - replace this exact text:

```make
.PHONY: up down clean test test-pro build-pro verify-pro-skeleton verify-pro-settings verify-pro-custom-fields verify-pro-create-product verify-pro-theme-layouts verify-pro-theme-templates verify-pro-global-colors verify-pro-global-variables verify-pro-global-presets verify-pro-site-tools verify-pro-rest verify-pro-build-site verify-pro-licence-tiers validate install shell-wp shell-wpcli export-layouts app-password schema-gap verify-modules verify-history verify-dry-run verify-global-styles verify-site-structure verify-acf-custom-fields verify-custom-fields verify-project-layouts verify-products

# ---------------------------------------------------------------
# up — bootstrap the full environment (idempotent)
```

with:

```make
.PHONY: up down clean test test-pro build-pro verify-pro-skeleton verify-pro-settings verify-pro-custom-fields verify-pro-create-product verify-pro-update-products verify-pro-theme-layouts verify-pro-theme-templates verify-pro-global-colors verify-pro-global-variables verify-pro-global-presets verify-pro-site-tools verify-pro-rest verify-pro-build-site verify-pro-licence-tiers validate install shell-wp shell-wpcli export-layouts app-password schema-gap verify-modules verify-history verify-dry-run verify-global-styles verify-site-structure verify-acf-custom-fields verify-custom-fields verify-project-layouts verify-products

# ---------------------------------------------------------------
# up — bootstrap the full environment (idempotent)
```

**Edit 2 of 2 in `Makefile`** - replace this exact text:

```make
# ---------------------------------------------------------------
verify-pro-create-product:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-create-product.php --user=1

# ---------------------------------------------------------------
# verify-pro-theme-layouts — real-site check of the Pro header/footer tools through the real registry (self-cleaning; free + Pro plugins must be active; dev site only: it evicts old batch rows)
```

with:

```make
# ---------------------------------------------------------------
verify-pro-create-product:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-create-product.php --user=1

# ---------------------------------------------------------------
# verify-pro-update-products — real-site check of the Pro tool update_products (self-cleaning; dev site only; free + Pro plugins and WooCommerce active; it records several batches, so on a site with batches it evicts the oldest real ones)
# ---------------------------------------------------------------
verify-pro-update-products:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-update-products.php --user=1

# ---------------------------------------------------------------
# verify-pro-theme-layouts — real-site check of the Pro header/footer tools through the real registry (self-cleaning; free + Pro plugins must be active; dev site only: it evicts old batch rows)
```

- [ ] **Step 3: Pin the new script in `DevGuardTest`**

In `pro-addon/tests/DevGuardTest.php`, replace this exact text:

```php
assertCount( 13, $scripts )
```

with:

```php
assertCount( 14, $scripts )
```

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter DevGuardTest` - Expected: `OK` (the new script requires `dev-guard.php` and calls `aied_require_dev_site();` before its `$failures = [];` line).
Run: `php -l scripts/verify-pro-update-products.php` - Expected: no syntax errors.

- [ ] **Step 4: Run the gate on the dev site**

Run: `make up` (idempotent), then `make verify-pro-update-products`.
Expected: every line `PASS`, a few `NOTE` lines (the 2020 sale), and `Success: update_products verified on the real site.` Paste the whole output in your report.
Then run `make verify-pro-create-product` once - Expected: still all PASS (Task 2 changed the decorator in that script).

- [ ] **Step 5: Run it a second time**

Run: `make verify-pro-update-products` again - Expected: identical result (the gate is self-cleaning and repeatable). Then check by hand that nothing is left: `docker compose exec -T wpcli wp post list --post_type=product,product_variation --post_status=any --fields=ID,post_title --format=csv | grep -i "AIED UP"` prints nothing.

- [ ] **Step 6: Whole suites**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK (1982 tests, ...)`.
Run: `./vendor/bin/phpunit` - Expected: `OK (1056 tests, ...)`.

- [ ] **Step 7: Commit**

```bash
git add scripts/verify-pro-update-products.php Makefile pro-addon/tests/DevGuardTest.php
git commit -m "$(cat <<'EOF'
test(pro): live gate verify-pro-update-products (real WooCommerce: preview/token, writes, variations, rollback, undo, kses, refusals)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review (done while writing)

- **Spec coverage:** Design 1 (tool, arguments, capabilities, MCP-only) -> Tasks 5, 6; field groups and rules -> Task 3; "never changed" fields -> Task 3 (unknown keys) and Task 7; unchanged detection -> Tasks 3, 5; preview, all-or-nothing, 6,000 cut -> Task 5; real run, token, per-product hash, skip -> Task 5; result shape -> Task 5. Design 2 (`checkPartial`, `product()`/`apply()`, `UpdateProducts`, `ProductFieldsKind`, apply/rollback, 512 KB/1 MB, messages) -> Tasks 2-5. Design 3 (undo) -> Task 4 (+ end-to-end in Task 5 and Task 7). Design 4 (wiring, docs, version, tests pinned) -> Task 6. Testing section -> Tasks 3-5, 7. Open points: scheduled-sale note -> Task 5 (`testAFutureSaleCarriesANoteAndAPastOneDoesNot`), parent price derived -> Task 7, object cache -> README limits.
- **Placeholders:** none; every step carries its code or exact edit.
- **Type consistency:** value shapes `{fields, variations}` are produced by `ProductFields::pick/asShape`, stored by `ProductFieldsKind::itemFor`, compared by `ProductDiff::compare`; change sets use the keys of `ProductWriter::product()`; `tracked()` is a key shape and `pick()` reads only keys.
