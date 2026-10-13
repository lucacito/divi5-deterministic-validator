# Pro `bulk_adjust_prices` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the 21st Pro tool, `bulk_adjust_prices` (Pro add-on 0.12.0): raise or lower the REGULAR prices of every simple, external and variable WooCommerce product a filter matches, by a percent or a fixed amount, with exact decimal arithmetic and the shop's rounding (plus an optional "end in"), behind a before/after preview and a single-use token, read back, rolled back on any failure, and undoable with one `undo_batch`.

**Architecture:** A pure `ProductFilter` validates and normalises the arguments (`filter`, `change`, `round_to`); a pure `PriceAdjuster` does the exact maths and the per-price skip reasons; `ProductWriter::findProducts()` (the only new WooCommerce-facing code) resolves the filter; `BulkAdjustPrices` ties them together exactly as `UpdateProducts` does for tokens and hashes. The apply, read-back, rollback and batch code that `UpdateProducts` already has is moved unchanged into a shared `ProductUpdateRun` that both tools call. Undo is the existing `ProductFieldsKind`; it needs no change.

**Tech Stack:** PHP 8.1+ (no 8.2-only syntax), PHPUnit 11 (`./vendor/bin/phpunit -c pro-addon/phpunit.xml`), WooCommerce CRUD classes, the add-on's `Batch`/`Site\PreviewToken` machinery, WP-CLI live gate in Docker.

**Spec:** `docs/superpowers/specs/2026-10-13-pro-bulk-adjust-prices-design.md` (approved by the owner; it is the binding authority - read it before any task). Model plan: `docs/superpowers/plans/2026-10-13-pro-update-products.md`.

## How this plan was made (read once)

Every code block below was first written and run in a scratch copy of the repository: the Pro suite was green there (1992 existing tests plus the new ones = 2138) and the free suite unchanged (1056); the mutation checks listed under each task were run and fail as stated; the 60 generated price cases were computed independently with Python's Decimal. The code blocks are therefore the intended final content of each file. If a command in a task still does not give the stated result, a **test or command in this plan is wrong - fix it in your brief and say so in your report; never silently weaken a test**. A FAIL in the live gate is a finding about the tool or the writer, never a reason to edit the gate.

## Global Constraints

- The free plugin (`wp-plugin/`, `src/`) is NOT changed. WordPress.org compliance rules in `CLAUDE.md` stay; `tests/WpOrgComplianceTest.php` is never weakened.
- `WooProductWriter` is the ONLY class that calls WooCommerce and has NO unit test by design (a stub `WC_Product_Simple` would flip `isActive()` for the whole PHPUnit process). Prove writer changes with the live gate and by reading; unit-test the pure and fake-driven layers.
- `bulk_adjust_prices` changes the REGULAR price only (a simple/external product's, and every variation row of a variable product). It never reads a sale price as input, never writes a sale price, a status, a type or a slug, and never creates or deletes anything. Grouped products are never matched.
- MCP + REST only: it joins `ProOpenApi::MCP_REST_ONLY` (the ChatGPT importer's 30 operations are used up). Registry capability `edit_posts`; inside the tool: WooCommerce active, `edit_products`, `edit_post` per product (a product the user may not edit is not matched at all).
- Arithmetic is exact: no floats, no BCMath. Percent: signed decimal string with at most 2 decimals, from -90 to 500, not zero. Amount: signed decimal string with at most 6 decimals, absolute value at most 100000, not zero. `round_to`: `"0"`/`"1"` (whole numbers) or a decimal below 1 with at most 2 decimals; nearest, ties up. Without it: the shop's decimals, half up.
- At most 100 matched products and 400 changed price fields per call; a bigger match is refused with a message to narrow the filter (never truncated). Scan limit 500 candidates when `on_sale` is used.
- Every real run needs the single-use `PreviewToken` (30 minutes, bound to the user, the normalised arguments and a hash of each previewed product's whole current state). A product changed since the preview is skipped (`changed_since_preview`); a product that matches now but was not previewed is never touched (`not_in_preview`). The token is spent after validation, right before the first write.
- Pro tripwires you must not trip: `tests/NoPriceLiteralsTest.php` (no standalone 49/99/149/449 in `pro-addon/src`, `readme.txt`, `README.md` - note this includes examples like `0.99`: use `0.95`); `tests/ProSafetyTest.php` (no `eval`/`exec`/`unserialize`, no `http(s)://` literal in a `src` string); `tests/ReadmeTest.php` (every tool in `Tools::all()` listed in `pro-addon/readme.txt`; Stable tag equals `AIED_PRO_VERSION` in `tests/bootstrap.php`); `ToolsTest` pins the exact tool list, the MCP-only list, the registered kinds and a 300-character description cap; `ExpiryKeepsAllToolsTest` pins the tool count; `DevGuardTest` pins the number of `verify-pro-*` scripts.
- Test helpers must not be named `run()`. Pro tests use `tests/WpRestShims.php`: `current_user_can` honours `$GLOBALS['__wp_caps']` and a capability NOT listed is DENIED.
- Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Do not push, upload or publish anything.
- Working in the main checkout on branch `feat/pro-bulk-adjust-prices` is intentional (the Docker dev site mounts this path).

## Rulings made while planning (the owner can overrule; each says what it costs if wrong)

1. **`findProducts()` takes the normalised filter as it is (category and tag SLUGS, status list, types list...) and a limit; `priceDecimals()` is a second new writer method.** WooCommerce's own query takes slugs, so the tool only checks that the slugs exist. Cost if wrong: an interface signature.
2. **The real run works on the union of the token's products and what the filter matches now.** A product in the token is re-checked against its hash. A product that matches now but is not in the token is classified again: if it would still be skipped or unchanged it is reported like that; if it would now change it is only counted (`not_in_preview`) and never touched. Cost if wrong: a product the owner expected to be included is reported but not changed (safe).
3. **A variable product with no variations is skipped (`no_variations`)**; the spec was silent. Cost: one reason code.
4. **A rounded price equal to the old price is "unchanged", not an error**; only a rounding that moves the price the OPPOSITE way to the change is skipped (`rounding_reversed`).
5. **`round_to` `"1"` is stored as `"0"` (both mean whole numbers), and `"0.50"` as `"0.5"`**, so the same request written differently gives the same token. A shop with 0 decimals still gets the decimals `round_to` asks for (`0.95` gives `21.95`). Cost if wrong: prices with decimals in a no-decimal shop (display rounds them).
6. **Rounded prices are written with the shop's decimals** (`22.00`, not `22`); the read-back compares as numbers, so `22` and `22.00` are the same price.
7. **`ProductUpdateRun` keeps the wording "Could not update Product N ..." for both tools** (the existing `update_products` tests assert it). Cost: a bulk failure says "update" not "adjust".
8. **The preview-time `skipped` list is recomputed at the real run, not copied from the preview**; a real-run `skipped` entry for a product that changed has only `id` and `reason` (`changed_since_preview`), as in `update_products`.
9. **Tool description is 294 characters** (the importer cap pinned by `ToolsTest` is 300).

## Review Focus (inputs the spec implies; each has a test in the task that owns the code)

1. A price whose float arithmetic is wrong (19.99 plus 10 percent), exact half ties (10.05 plus 10 percent gives 11.055, up to 11.06), huge prices near the digit limit without integer overflow (Task 2: `testPercentAndAmountToTheShopsDecimals`, `testATieRoundsUp`, `testZeroAndNegativePricesAndTheLargestPrice`).
2. A number sent where a decimal STRING is required, a `%` sign, a currency symbol, a thousands separator, `+5`, `round_to` as a number (Task 3: `badChanges`, `testRoundTo`).
3. A filter that matches everything by accident: an empty filter, `status`/`exclude_ids` alone, `all` combined with other filters (Task 3: `testAnyOneNarrowingKeyIsEnoughButStatusAndExcludeAloneAreNot`, `testAllMustBeAskedForAndStandsAlone`).
4. Drafts and private products are not touched unless asked for (Task 5: `testTheFilterKeys`).
5. The same request written another way (key order, list order, `10.0`, `0.50`) opens the same token (Task 3: `testTheSameRequestWrittenAnotherWayNormalisesAlike`; Task 5: `testTheSameRequestWrittenAnotherWayOpensTheToken`).
6. A refused run keeps its token (Task 5: `testARefusedRunKeepsItsToken`).
7. A product the user may not edit is invisible, not an error (Task 5: `testProductsTheUserMayNotEditAreNotMatched`).

## Execution notes for the controller

- Per task: implementer = sonnet; task reviewer = sonnet, except **Task 1 (a refactor of reviewed failure-path code), Task 4 (the real writer, no unit test), Task 5 (core logic and every failure path) and Task 7 (the live gate) get an opus reviewer.** Tasks 2, 3 and 6 are sonnet/sonnet.
- Reviewers must repeat the **RED proofs** listed in each task (apply the mutation, watch the named test fail, revert).
- Task order is fixed and serial: 1 -> 2 -> 3 -> 4 -> 5 -> 6 -> 7 (Tasks 1, 2, 3 and 5 all edit `autoload.php`).
- After Task 7: a final whole-branch review (opus), at most ONE fix wave, then a scoped re-review, then `superpowers:finishing-a-development-branch`.

## File structure

| File | Responsibility | Task |
|---|---|---|
| `pro-addon/src/Products/ProductUpdateRun.php` (new) | the shared apply / read-back / rollback / batch engine, plus `display()` and `cut()` | 1 |
| `pro-addon/src/Tools/UpdateProducts.php` | now calls the engine (behaviour unchanged) | 1 |
| `pro-addon/src/Products/PriceAdjuster.php` (new) | exact price maths, skip reasons; pure | 2 |
| `pro-addon/src/Products/ProductFilter.php` (new) | validate and normalise `filter`, `change`, `round_to`; pure | 3 |
| `pro-addon/src/Products/ProductWriter.php`, `WooProductWriter.php`, `tests/FakeProductWriter.php` | `findProducts()`, `priceDecimals()` | 4 |
| both existing live-gate scripts | their decorators delegate the two new methods | 4 |
| `pro-addon/src/Tools/BulkAdjustPrices.php` (new) | the tool | 5 |
| wiring, docs, version | `Tools.php`, `ProOpenApi.php`, pinned tests, `readme.txt`, `README.md`, `CLAUDE.md`, plugin header | 6 |
| `scripts/verify-pro-bulk-adjust-prices.php` (new), `Makefile`, `DevGuardTest` | the live gate | 7 |

---

### Task 1: Extract the shared apply engine (`ProductUpdateRun`)

**Files:**
- Create: `pro-addon/src/Products/ProductUpdateRun.php`
- Modify: `pro-addon/src/Tools/UpdateProducts.php`, `pro-addon/src/autoload.php`

**Interfaces:**
- Consumes: `ProductWriter`, `ProductFields`, `ProductDiff`, `ProductSaveFailed`, `ProductFieldsKind::itemFor`, `BatchLog` (all existing).
- Produces (exact):
  - `new ProductUpdateRun( BatchLog $log, ProductWriter $writer )`
  - `->execute( array $plans, array $skipped, array $unchanged, string $toolName, string $summary, array $extra = [] ): array` - the code that was `UpdateProducts::apply()` and `::rollback()`, moved unchanged. Each plan has `id`, `label`, `name`, `changes`, `previous` (value shape), `display`. `$summary` is a `sprintf` format with one `%d` (`'Updated %d product(s)'`). `$extra` is merged into the result. Returns the result array (`changed`, `dry_run`, `updated`, `skipped`, `unchanged`, `batch`, plus `$extra`) or throws the same safe `\InvalidArgumentException` as before.
  - `ProductUpdateRun::display( array $changes, array $current, array $slugsFor = [] ): array` and `ProductUpdateRun::cut( string $name ): string` (public static; moved from `UpdateProducts`).

This is a pure refactor: there is NO new test on purpose. The existing tests of `UpdateProductsTest` (42) and `ProductFieldsKindTest` (20) (rollback, read-back differences, safe messages, batch contents, undo) are the proof that behaviour did not change. If ANY existing test needs a change, the refactor is wrong: stop and report.

- [ ] **Step 1: Run the existing tests to see the baseline**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK (1992 tests ...)`.

- [ ] **Step 2: Create the engine**

Create `pro-addon/src/Products/ProductUpdateRun.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Kinds\ProductFieldsKind;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The part of a product write that update_products and bulk_adjust_prices share: apply the planned changes product by product, read each
 * back and compare it with what was asked (ProductDiff, against the FULL read-back state), and on any exception or difference put every
 * product the run started to change back, record NO batch and fail with a safe message. On success it records ONE batch of
 * `product_fields` items. The callers do everything before this (arguments, token, planning) and spend the token right before calling it.
 */
final class ProductUpdateRun
{
    public function __construct( private BatchLog $log, private ProductWriter $writer )
    {
    }

    /**
     * @param list<array<string,mixed>> $plans each: id, label, name, changes, previous (value shape), display
     * @param list<array<string,mixed>> $skipped
     * @param list<int> $unchanged
     * @param string $toolName the tool name stored with the batch
     * @param string $summary sprintf format with one %d for the batch summary, for example "Updated %d product(s)"
     * @param array<string,mixed> $extra more keys for the result
     * @return array<string,mixed>
     * @throws \InvalidArgumentException with a message that is safe to show
     */
    public function execute( array $plans, array $skipped, array $unchanged, string $toolName, string $summary, array $extra = [] ): array
    {
        $writer  = $this->writer;
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
                $diff  = ProductDiff::compare( $asked, ProductFields::asShape( $back ) );
                if ( [] !== $diff ) {
                    throw new ProductSaveFailed( 'it was saved differently than asked (' . implode( ', ', $diff ) . ')' );
                }
                $plans[ $k ]['after']      = ProductFields::pick( $back, $plan['previous'] );
                $plans[ $k ]['name_after'] = (string) $back['name'];
            }
        } catch ( \Throwable $e ) {
            $notRestored = $this->rollback( $done );
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
        $batch = $this->log->record( $toolName, sprintf( $summary, count( $records ) ), $records );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'updated' => $updated, 'skipped' => $skipped, 'unchanged' => $unchanged ] + $extra, $batch );
    }

    /**
     * Puts the previous values back on every product the run started to change, newest first, and checks each read-back.
     *
     * @param list<array<string,mixed>> $done
     * @return list<int> the ids that could not be put back
     */
    private function rollback( array $done ): array
    {
        $left = [];
        foreach ( array_reverse( $done ) as $plan ) {
            try {
                $this->writer->apply( $plan['id'], ProductFields::asChanges( $plan['previous'] ) );
                $back = $this->writer->product( $plan['id'] );
                if ( null === $back || [] !== ProductDiff::compare( $plan['previous'], ProductFields::asShape( $back ) ) ) {
                    $left[] = $plan['id'];
                }
            } catch ( \Throwable $e ) {
                $left[] = $plan['id'];
            }
        }

        return $left;
    }

    /**
     * Before and after of what a change set does, for the preview and the result.
     *
     * @param array<string,mixed> $changes
     * @param array<string,mixed> $current
     * @param array<string,list<string>> $slugsFor category_ids / tag_ids => the slugs that were asked for
     * @return array{fields: array<string,mixed>, variations: list<array<string,mixed>>}
     */
    public static function display( array $changes, array $current, array $slugsFor = [] ): array
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

    /** A product name made safe and short for a label or a message. */
    public static function cut( string $name ): string
    {
        $name = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $name );

        return mb_strlen( $name ) > 60 ? mb_substr( $name, 0, 60 ) . '...' : $name;
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
}
```

- [ ] **Step 3: Make `UpdateProducts` use it**

**Edit 1 of 5 in `pro-addon/src/Tools/UpdateProducts.php`** - replace this exact text:

```php
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
```

with:

```php
use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Products\ProductFields;
use AiEditorDivi5\Pro\Products\ProductValuePolicy;
use AiEditorDivi5\Pro\Products\ProductUpdateRun;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;
use AiEditorDivi5\Pro\Site\PreviewToken;
```

**Edit 2 of 5 in `pro-addon/src/Tools/UpdateProducts.php`** - replace this exact text:

```php
            return [ 'changed' => false, 'dry_run' => false, 'updated' => [], 'skipped' => $skipped, 'unchanged' => $unchanged, 'message' => 'Nothing was changed.' ];
        }

        return $this->apply( $plans, $skipped, $unchanged );
    }

    /**
```

with:

```php
            return [ 'changed' => false, 'dry_run' => false, 'updated' => [], 'skipped' => $skipped, 'unchanged' => $unchanged, 'message' => 'Nothing was changed.' ];
        }

        return ( new ProductUpdateRun( $this->log, $writer ) )->execute( $plans, $skipped, $unchanged, self::NAME, 'Updated %d product(s)' );
    }

    /**
```

**Edit 3 of 5 in `pro-addon/src/Tools/UpdateProducts.php`** - replace this exact text:

```php
            'reasons'  => [],
            'changes'  => $changes,
            'previous' => $previous,
            'display'  => self::display( $changes, $current, $slugsFor ),
            'current'  => $current,
            'rows'     => count( (array) ( $changes['variations'] ?? [] ) ),
            'bytes'    => $bytes,
```

with:

```php
            'reasons'  => [],
            'changes'  => $changes,
            'previous' => $previous,
            'display'  => ProductUpdateRun::display( $changes, $current, $slugsFor ),
            'current'  => $current,
            'rows'     => count( (array) ( $changes['variations'] ?? [] ) ),
            'bytes'    => $bytes,
```

**Edit 4 of 5 in `pro-addon/src/Tools/UpdateProducts.php`** - replace this exact text:

```php
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
                $diff  = ProductDiff::compare( $asked, ProductFields::asShape( $back ) );
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
                if ( null === $back || [] !== ProductDiff::compare( $plan['previous'], ProductFields::asShape( $back ) ) ) {
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
```

with:

```php
        ];
    }

    /** A sale that starts in the future leaves the price at the regular price until WooCommerce's own scheduler starts it. @param array<string,mixed> $changes @return list<string> */
    private static function notes( array $changes, string $today ): array
    {
```

**Edit 5 of 5 in `pro-addon/src/Tools/UpdateProducts.php`** - replace this exact text:

```php
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
```

with:

```php
        return [];
    }

    private static function label( int $n, int $id, string $name ): string
    {
        return "Product {$n} (#{$id} \"" . ProductUpdateRun::cut( $name ) . '")';
    }

    private function writer(): ProductWriter
```

- [ ] **Step 4: Register the file**

In `pro-addon/src/autoload.php`, replace this exact text:

```php
require_once __DIR__ . '/Kinds/ProductFieldsKind.php';

```

with:

```php
require_once __DIR__ . '/Kinds/ProductFieldsKind.php';
require_once __DIR__ . '/Products/ProductUpdateRun.php';

```

- [ ] **Step 5: Run everything**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter 'UpdateProductsTest|ProductFieldsKindTest'` - Expected: `OK` with the same test counts as before (42 and 20).
Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK` (the scan tests count the new file: 1995 tests).
Run: `git diff --stat` - Expected: `UpdateProducts.php` shrinks by roughly the size of the new file's moved code; no test file changed.

- [ ] **Step 6: RED proof (do it, then revert)**

In `ProductUpdateRun::execute()` change `$notRestored = $this->rollback( $done );` to `$notRestored = [];`. Expected: `UpdateProductsTest::testAFailedSaveRestoresEveryProductThisRunChangedAndRecordsNoBatch` and others fail (the engine's rollback is what they test). Revert.

- [ ] **Step 7: Commit**

```bash
git add pro-addon/src/Products/ProductUpdateRun.php pro-addon/src/Tools/UpdateProducts.php pro-addon/src/autoload.php
git commit -m "$(cat <<'EOF'
refactor(pro): move update_products' apply, read-back, rollback and batch code into a shared ProductUpdateRun (no behaviour change)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `PriceAdjuster` - the exact price maths (pure)

**Files:**
- Create: `pro-addon/src/Products/PriceAdjuster.php`
- Create: `pro-addon/tests/PriceAdjusterTest.php`
- Modify: `pro-addon/src/autoload.php`

**Interfaces:**
- Consumes: nothing.
- Produces (exact):
  - `PriceAdjuster::adjust( string $old, string $sale, string $mode, string $value, int $decimals, ?string $roundTo ): array{price: ?string, reason: ?string}` - the new price as a decimal string with `$decimals` decimals (or the decimals of `$roundTo` when that has more), or a reason code from `PriceAdjuster::REASONS`: `no_regular_price`, `not_positive`, `too_large`, `rounding_reversed`, `not_above_sale`. It trusts `$mode` (`percent`|`amount`), `$value` and `$roundTo` were validated by `ProductFilter` (Task 3). A result equal to `$old` is returned as a price (the caller decides "unchanged" with `same()`).
  - `PriceAdjuster::same( string $a, string $b ): bool`, `PriceAdjuster::units( string $decimal ): int` (whole millionths), constants `SCALE` (1000000), `MAX_INT` (9999999999), `REASONS` (code => sentence).

- [ ] **Step 1: Write the tests**

Create `pro-addon/tests/PriceAdjusterTest.php` (the 60 rows of `generated()` were computed with Python's `Decimal` and `ROUND_HALF_UP`, independently of the PHP code):

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Products\PriceAdjuster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/** The exact decimal arithmetic of bulk_adjust_prices. No floats: the generated expectations were worked out with Python's Decimal. */
class PriceAdjusterTest extends TestCase
{
    /** @return array{price: ?string, reason: ?string} */
    private function adjust( string $old, string $mode, string $value, int $decimals = 2, ?string $roundTo = null, string $sale = '' ): array
    {
        return PriceAdjuster::adjust( $old, $sale, $mode, $value, $decimals, $roundTo );
    }

    private function price( string $old, string $mode, string $value, int $decimals = 2, ?string $roundTo = null, string $sale = '' ): string
    {
        $r = $this->adjust( $old, $mode, $value, $decimals, $roundTo, $sale );
        $this->assertNull( $r['reason'], (string) $r['reason'] );

        return (string) $r['price'];
    }

    private function reason( string $old, string $mode, string $value, int $decimals = 2, ?string $roundTo = null, string $sale = '' ): string
    {
        $r = $this->adjust( $old, $mode, $value, $decimals, $roundTo, $sale );
        $this->assertNull( $r['price'], (string) $r['price'] );

        return (string) $r['reason'];
    }

    /** @return list<array{string,string,string,int,string}> old, mode, value, decimals, expected price or reason */
    public static function generated(): array
    {
        return [
        [ '67912.7', 'amount', '12659', 0, '80572' ],
        [ '1722338', 'amount', '8193', 2, '1730531.00' ],
        [ '450255', 'percent', '204.19', 2, '1369630.68' ],
        [ '14649.8', 'percent', '281.13', 2, '55834.78' ],
        [ '123964', 'percent', '333.28', 3, '537111.219' ],
        [ '1222634', 'amount', '-2675.1', 0, '1219959' ],
        [ '116741.1', 'amount', '-253.2', 2, '116487.90' ],
        [ '2470.29', 'percent', '-12.47', 2, '2162.24' ],
        [ '119790.3', 'amount', '-23615', 2, '96175.30' ],
        [ '12499.3', 'amount', '14.59', 4, '12513.8900' ],
        [ '658.815', 'amount', '-63.04', 1, '595.8' ],
        [ '166593.5', 'percent', '-26.36', 2, '122679.45' ],
        [ '629.669', 'amount', '17.804', 2, '647.47' ],
        [ '1277080', 'percent', '255.5', 2, '4540019.40' ],
        [ '3459.51', 'percent', '240.44', 2, '11777.56' ],
        [ '82224', 'amount', '-77.1', 2, '82146.90' ],
        [ '1041.603', 'percent', '-18.67', 2, '847.14' ],
        [ '994257', 'percent', '399.17', 3, '4963032.667' ],
        [ '649.294', 'amount', '16.964', 3, '666.258' ],
        [ '727723', 'amount', '-670.5', 2, '727052.50' ],
        [ '245.568', 'percent', '63', 4, '400.2758' ],
        [ '60278.9', 'percent', '180.76', 2, '169239.04' ],
        [ '1922.703', 'percent', '29.02', 2, '2480.67' ],
        [ '8423.1', 'percent', '202.14', 4, '25449.5543' ],
        [ '11538.95', 'amount', '-6.488', 1, '11532.5' ],
        [ '316505', 'percent', '19.15', 1, '377115.7' ],
        [ '138101', 'percent', '237.82', 4, '466532.7982' ],
        [ '123548.2', 'amount', '-11524', 1, '112024.2' ],
        [ '8785.95', 'amount', '-21776', 2, 'not_positive' ],
        [ '1886.458', 'amount', '-3.853', 0, '1883' ],
        [ '1009.827', 'percent', '44.91', 0, '1463' ],
        [ '437.809', 'percent', '-7.96', 2, '402.96' ],
        [ '1259817', 'percent', '-79.85', 2, '253853.13' ],
        [ '317226', 'amount', '10221', 0, '327447' ],
        [ '183360.8', 'amount', '-202.65', 2, '183158.15' ],
        [ '12630.72', 'amount', '-21950', 4, 'not_positive' ],
        [ '1023.553', 'amount', '17.08', 0, '1041' ],
        [ '302237', 'amount', '185.19', 2, '302422.19' ],
        [ '173823.6', 'percent', '54.48', 2, '268522.70' ],
        [ '75865', 'percent', '266.1', 2, '277741.77' ],
        [ '1348295', 'amount', '39.73', 1, '1348334.7' ],
        [ '74594.9', 'amount', '1170.9', 2, '75765.80' ],
        [ '170186.3', 'percent', '182.59', 3, '480929.465' ],
        [ '168469.7', 'percent', '259.23', 2, '605193.70' ],
        [ '745669', 'percent', '103.11', 2, '1514528.31' ],
        [ '54352.9', 'amount', '-6.91', 2, '54345.99' ],
        [ '16890.1', 'percent', '68.66', 2, '28486.84' ],
        [ '4125.23', 'percent', '236.31', 2, '13873.56' ],
        [ '1888084', 'amount', '295.85', 4, '1888379.8500' ],
        [ '1348748', 'percent', '174.63', 4, '3704066.6324' ],
        [ '149210.9', 'amount', '2826.2', 2, '152037.10' ],
        [ '16549.37', 'percent', '393.05', 2, '81596.67' ],
        [ '971.319', 'percent', '395', 1, '4808.0' ],
        [ '35652.4', 'percent', '19.05', 2, '42444.18' ],
        [ '1897.613', 'percent', '320.8', 4, '7985.1555' ],
        [ '1249.631', 'amount', '-1978.3', 0, 'not_positive' ],
        [ '29870', 'percent', '204.3', 4, '90894.4100' ],
        [ '40853.7', 'percent', '85.04', 1, '75595.7' ],
        [ '61439.6', 'amount', '-13.003', 4, '61426.5970' ],
        [ '274882', 'amount', '28.831', 3, '274910.831' ],
        ];
    }

    #[DataProvider( 'generated' )]
    public function testAgainstIndependentlyComputedDecimals( string $old, string $mode, string $value, int $decimals, string $expected ): void
    {
        $r = PriceAdjuster::adjust( $old, '', $mode, $value, $decimals, null );
        $this->assertSame( $expected, $r['price'] ?? $r['reason'], "{$old} {$mode} {$value} d{$decimals}" );
    }

    public function testPercentAndAmountToTheShopsDecimals(): void
    {
        $this->assertSame( '22.00', $this->price( '20', 'percent', '10' ) );
        $this->assertSame( '21.99', $this->price( '19.99', 'percent', '10' ), '21.989 rounds to 21.99 (a float gives 21.989000000000004)' );
        $this->assertSame( '70.00', $this->price( '80', 'percent', '-12.5' ) );
        $this->assertSame( '15.00', $this->price( '20', 'amount', '-5' ) );
        $this->assertSame( '20.50', $this->price( '20', 'amount', '0.5' ) );
        $this->assertSame( '22', $this->price( '19.99', 'percent', '10', 0 ) );
        $this->assertSame( '21.989', $this->price( '19.99', 'percent', '10', 3 ) );
    }

    public function testATieRoundsUp(): void
    {
        $this->assertSame( '11.06', $this->price( '10.05', 'percent', '10' ), '11.055 is a tie' );
        $this->assertSame( '1.01', $this->price( '1', 'amount', '0.005' ), '1.005 is a tie' );
        $this->assertSame( '1.00', $this->price( '1', 'amount', '-0.005' ), '0.995 is a tie, and up is back to the old price (no change)' );
    }

    public function testRoundToEndsPricesTheWayAskedNearestTiesUp(): void
    {
        $this->assertSame( '21.99', $this->price( '20', 'percent', '10', 2, '0.99' ), '22.00 -> nearest .99 is 21.99' );
        $this->assertSame( '22.5', $this->price( '20', 'percent', '10', 1, '0.5' ), '22.00 is exactly between 21.5 and 22.5: up' );
        $this->assertSame( '22.00', $this->price( '19.99', 'percent', '10', 2, '0' ), 'whole numbers' );
        $this->assertSame( '29.95', $this->price( '25', 'percent', '20', 2, '0.95' ) );
        $this->assertSame( '14.99', $this->price( '19.99', 'amount', '-5', 2, '0.99' ) );
        $this->assertSame( '21.99', $this->price( '19.99', 'percent', '10', 0, '0.99' ), '21.989 -> nearest .99; a shop with 0 decimals still gets the two decimals round_to asks for' );
    }

    public function testRoundingMayNotUndoTheChange(): void
    {
        $this->assertSame( 'rounding_reversed', $this->reason( '20', 'percent', '1', 2, '0.99' ), '20.20 -> 19.99 is below the old price' );
        $this->assertSame( 'rounding_reversed', $this->reason( '19.9', 'percent', '-1', 2, '0.99' ), '19.701 -> 19.99 is above the old price 19.90' );
        $this->assertSame( '20.00', $this->price( '20', 'amount', '0.004' ), 'a change that rounds away is no change, not an error' );
    }

    public function testZeroAndNegativePricesAndTheLargestPrice(): void
    {
        $this->assertSame( 'not_positive', $this->reason( '20', 'amount', '-20' ) );
        $this->assertSame( 'not_positive', $this->reason( '20', 'amount', '-25' ) );
        $this->assertSame( 'not_positive', $this->reason( '0.01', 'percent', '-90' ), '0.001 rounds to 0.00' );
        $this->assertSame( 'not_positive', $this->reason( '1', 'amount', '-0.996', 2, '0' ), 'rounds to 0' );
        $this->assertSame( 'too_large', $this->reason( '9999999999', 'percent', '10' ) );
        $this->assertSame( 'too_large', $this->reason( '9999999999.99', 'amount', '0.009999' ), 'rounds to 10000000000.00' );
        $this->assertSame( '9999999999.999999', $this->price( '9999999999.99', 'amount', '0.009999', 6 ) );
        $this->assertSame( 'too_large', $this->reason( '9999999999.999999', 'percent', '500' ), 'no integer overflow on the way' );
    }

    public function testThePriceMustStayAboveAnActiveSale(): void
    {
        $this->assertSame( 'not_above_sale', $this->reason( '20', 'amount', '-3', 2, null, '18' ), '17 is below the sale' );
        $this->assertSame( 'not_above_sale', $this->reason( '20', 'amount', '-2', 2, null, '18' ), 'equal is not above' );
        $this->assertSame( '18.01', $this->price( '20', 'amount', '-1.99', 2, null, '18' ) );
        $this->assertSame( '22.00', $this->price( '20', 'percent', '10', 2, null, '18' ), 'a rise is fine' );
    }

    public function testAProductWithoutARegularPrice(): void
    {
        $this->assertSame( 'no_regular_price', $this->reason( '', 'percent', '10' ) );
    }

    public function testSameAndUnits(): void
    {
        $this->assertTrue( PriceAdjuster::same( '20', '20.00' ) );
        $this->assertFalse( PriceAdjuster::same( '20', '20.01' ) );
        $this->assertFalse( PriceAdjuster::same( '', '' ), 'two empties are not a price' );
        $this->assertSame( 19990000, PriceAdjuster::units( '19.99' ) );
        $this->assertSame( -12500000, PriceAdjuster::units( '-12.5' ) );
        $this->assertSame( 1, PriceAdjuster::units( '0.000001' ) );
        $this->assertSame( 5000000, PriceAdjuster::units( '5' ) );
    }

    public function testEveryReasonHasAnExplanation(): void
    {
        foreach ( [ 'no_regular_price', 'not_positive', 'too_large', 'rounding_reversed', 'not_above_sale' ] as $code ) {
            $this->assertArrayHasKey( $code, PriceAdjuster::REASONS );
        }
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter PriceAdjusterTest`
Expected: errors `Class "AiEditorDivi5\Pro\Products\PriceAdjuster" not found`.

- [ ] **Step 3: Create `PriceAdjuster`**

Create `pro-addon/src/Products/PriceAdjuster.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The arithmetic of bulk_adjust_prices, exact and pure: no floats, no BCMath, no WordPress. A price is a decimal string with at most
 * 10 integer and 6 decimal digits; internally it is a whole number of millionths, which fits a PHP int (10 digits + 6 = 16 digits). A
 * percent factor would overflow that, so a price is split (price = q * 10000 + r) before it is multiplied.
 *
 * It trusts that `$mode` / `$value` / `$roundTo` were validated by ProductFilter.
 */
final class PriceAdjuster
{
    public const SCALE   = 1000000;
    public const MAX_INT = 9999999999;

    /** The reason codes of a price that cannot be adjusted. */
    public const REASONS = [
        'no_regular_price'  => 'the product has no regular price',
        'not_positive'      => 'the new price would be zero or below',
        'too_large'         => 'the new price would be above the largest price WooCommerce can hold here',
        'rounding_reversed' => 'rounding would move the price the opposite way to the change',
        'not_above_sale'    => 'the new regular price would not stay above the active sale price',
    ];

    /**
     * @param string $old the regular price now ("" for none)
     * @param string $sale the sale price now ("" for none)
     * @param string $mode "percent" or "amount"
     * @param string $value signed decimal string, for example "10", "-12.5", "-5"
     * @param int $decimals the shop's number of price decimals (0 to 6)
     * @param ?string $roundTo "0.95" and the like, or null
     * @return array{price: ?string, reason: ?string} the new price (a decimal string with `$decimals` decimals, or more when `$roundTo` has more) or the reason code
     */
    public static function adjust( string $old, string $sale, string $mode, string $value, int $decimals, ?string $roundTo ): array
    {
        if ( '' === $old ) {
            return self::no( 'no_regular_price' );
        }
        $decimals = max( 0, min( 6, $decimals ) );
        $oldUnits = self::units( $old );

        // The exact result as whole millionths ($whole) plus a fraction of a millionth ($rem / 10000).
        if ( 'percent' === $mode ) {
            $factor = 10000 + intdiv( self::units( $value ), 10000 ); // at most 2 decimals, so this is an exact count of hundredths of a percent
            $q      = intdiv( $oldUnits, 10000 );
            $r      = $oldUnits % 10000;
            $whole  = $q * $factor + intdiv( $r * $factor, 10000 );
            $rem    = ( $r * $factor ) % 10000;
        } else {
            $whole = $oldUnits + self::units( $value );
            $rem   = 0;
        }
        if ( $whole < 0 || ( 0 === $whole && 0 === $rem ) ) {
            return self::no( 'not_positive' );
        }

        if ( null !== $roundTo ) {
            // The nearest price that ends in $roundTo (ties up): n + r for a whole number n.
            $r     = self::units( $roundTo );
            $n     = self::floorDiv( $whole - $r + intdiv( self::SCALE, 2 ), self::SCALE );
            $final = $n * self::SCALE + $r;
            $shown = max( $decimals, self::decimalsOf( $roundTo ) );
        } else {
            $step  = (int) ( 10 ** ( 6 - $decimals ) );
            $k     = intdiv( $whole, $step );
            $rm    = $whole % $step;
            $up    = ( $rm * 2 * 10000 + $rem * 2 ) >= $step * 10000 ? 1 : 0;
            $final = ( $k + $up ) * $step;
            $shown = $decimals;
        }

        if ( $final <= 0 ) {
            return self::no( 'not_positive' );
        }
        if ( $final > self::MAX_INT * self::SCALE + ( self::SCALE - 1 ) ) {
            return self::no( 'too_large' );
        }
        // Rounding must not undo the change: a rise may not end below the old price, nor a cut above it.
        $rise = '-' !== substr( $value, 0, 1 );
        if ( ( $rise && $final < $oldUnits ) || ( ! $rise && $final > $oldUnits ) ) {
            return self::no( 'rounding_reversed' );
        }
        if ( '' !== $sale && $final <= self::units( $sale ) ) {
            return self::no( 'not_above_sale' );
        }

        return [ 'price' => self::text( $final, $shown ), 'reason' => null ];
    }

    /** Whether two decimal strings hold the same number. */
    public static function same( string $a, string $b ): bool
    {
        return '' !== $a && '' !== $b && self::units( $a ) === self::units( $b );
    }

    /** A decimal string such as "-12.5" or "20.95" as whole millionths. */
    public static function units( string $decimal ): int
    {
        $neg = '-' === substr( $decimal, 0, 1 );
        $d   = ltrim( $decimal, '+-' );
        [ $int, $frac ] = array_pad( explode( '.', $d, 2 ), 2, '' );
        $frac = substr( str_pad( $frac, 6, '0' ), 0, 6 );
        $n    = (int) $int * self::SCALE + (int) $frac;

        return $neg ? -$n : $n;
    }

    /** Whole millionths as a decimal string with exactly $decimals decimals (the value is already a multiple of the step). */
    private static function text( int $units, int $decimals ): string
    {
        $int  = intdiv( $units, self::SCALE );
        $frac = str_pad( (string) ( $units % self::SCALE ), 6, '0', STR_PAD_LEFT );

        return 0 === $decimals ? (string) $int : $int . '.' . substr( $frac, 0, $decimals );
    }

    private static function decimalsOf( string $decimal ): int
    {
        $pos = strpos( $decimal, '.' );

        return false === $pos ? 0 : strlen( rtrim( substr( $decimal, $pos + 1 ), '0' ) );
    }

    private static function floorDiv( int $a, int $b ): int
    {
        $q = intdiv( $a, $b );

        return ( $a % $b < 0 ) ? $q - 1 : $q;
    }

    /** @return array{price: null, reason: string} */
    private static function no( string $reason ): array
    {
        return [ 'price' => null, 'reason' => $reason ];
    }
}
```

- [ ] **Step 4: Register it**

In `pro-addon/src/autoload.php`, replace this exact text:

```php
require_once __DIR__ . '/Products/ProductDiff.php';

```

with:

```php
require_once __DIR__ . '/Products/ProductDiff.php';
require_once __DIR__ . '/Products/PriceAdjuster.php';

```

- [ ] **Step 5: Run the new tests and the suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter PriceAdjusterTest` - Expected: `OK (69 tests, 136 assertions)`.
Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK` (`NoPriceLiteralsTest` scans the new file: the comments use `0.95`, never `0.99`).

- [ ] **Step 6: RED proofs (do each, then revert)**

1. In `adjust()` change `>= $step * 10000 ? 1 : 0` to `> $step * 10000 ? 1 : 0` (ties round down). Expected: `testATieRoundsUp` and at least one generated case fail.
2. Change `if ( '' !== $sale && $final <= self::units( $sale ) ) {` to `if ( false ) {`. Expected: `testThePriceMustStayAboveAnActiveSale` fails.

- [ ] **Step 7: Commit**

```bash
git add pro-addon/src/Products/PriceAdjuster.php pro-addon/tests/PriceAdjusterTest.php pro-addon/src/autoload.php
git commit -m "$(cat <<'EOF'
feat(pro): PriceAdjuster for bulk_adjust_prices (exact decimal maths, shop rounding, round_to, skip reasons)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `ProductFilter` - validate and normalise the arguments (pure)

**Files:**
- Create: `pro-addon/src/Products/ProductFilter.php`
- Create: `pro-addon/tests/ProductFilterTest.php`
- Modify: `pro-addon/src/autoload.php`

**Interfaces:**
- Consumes: `PriceAdjuster::units()`, `PriceAdjuster::SCALE` (Task 2).
- Produces (exact):
  - `ProductFilter::parse( array $arguments ): array{spec: ?array, reasons: list<string>}` - reads only `filter`, `change`, `round_to`. `spec` is null when any reason exists, otherwise `['filter' => [...], 'change' => ['mode' => 'percent'|'amount', 'value' => string], 'round_to' => ?string]`, where `filter` always has every key: `categories`, `tags`, `types`, `ids`, `exclude_ids`, `status` (lists, sorted and unique; `status` defaults to `['publish']`), `stock_status`, `on_sale`, `search` (null when absent), `all` (bool). Values are normalised: `"10.0"` -> `"10"`, `"010"` -> `"10"`, `"0.50"` -> `"0.5"`, `round_to` `"1"` and `"0.00"` -> `"0"`.
  - Constants `MAX_CATEGORIES` 10, `MAX_TAGS` 20, `MAX_IDS` 100, `TYPES`, `STATUSES`, `STOCK_STATUSES`, `MODES`.

- [ ] **Step 1: Write the tests**

Create `pro-addon/tests/ProductFilterTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Products\ProductFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/** The arguments of bulk_adjust_prices: validated, normalised, every reason at once, nothing cast. */
class ProductFilterTest extends TestCase
{
    /** @return array<string,mixed> */
    private function ok( array $args ): array
    {
        $r = ProductFilter::parse( $args );
        $this->assertSame( [], $r['reasons'], json_encode( $r['reasons'] ) );
        $this->assertIsArray( $r['spec'] );

        return $r['spec'];
    }

    private function refused( array $args, string $needle ): void
    {
        $r = ProductFilter::parse( $args );
        $this->assertNull( $r['spec'] );
        $this->assertNotSame( [], $r['reasons'] );
        $this->assertStringContainsString( $needle, implode( ' | ', $r['reasons'] ) );
    }

    private function change( string $mode = 'percent', string $value = '10' ): array
    {
        return [ 'mode' => $mode, 'value' => $value ];
    }

    public function testAMinimalRequestIsNormalisedWithDefaults(): void
    {
        $spec = $this->ok( [ 'filter' => [ 'categories' => [ 'shirts' ] ], 'change' => $this->change() ] );
        $this->assertSame( [ 'shirts' ], $spec['filter']['categories'] );
        $this->assertSame( [ 'publish' ], $spec['filter']['status'], 'only published products unless asked' );
        $this->assertSame( [], $spec['filter']['types'] );
        $this->assertNull( $spec['filter']['stock_status'] );
        $this->assertFalse( $spec['filter']['all'] );
        $this->assertSame( [ 'mode' => 'percent', 'value' => '10' ], $spec['change'] );
        $this->assertNull( $spec['round_to'] );
    }

    public function testTheSameRequestWrittenAnotherWayNormalisesAlike(): void
    {
        $a = $this->ok( [ 'filter' => [ 'categories' => [ 'b', 'a', 'a' ], 'ids' => [ 9, 3 ], 'status' => [ 'private', 'publish' ] ], 'change' => $this->change( 'percent', '10.0' ), 'round_to' => '0.50' ] );
        $b = $this->ok( [ 'round_to' => '0.5', 'change' => $this->change( 'percent', '010' ), 'filter' => [ 'status' => [ 'publish', 'private' ], 'ids' => [ 3, 9 ], 'categories' => [ 'a', 'b' ] ] ] );
        $this->assertEquals( $a, $b );
        $this->assertSame( [ 'a', 'b' ], $a['filter']['categories'] );
        $this->assertSame( [ 3, 9 ], $a['filter']['ids'] );
        $this->assertSame( '10', $a['change']['value'] );
        $this->assertSame( '0.5', $a['round_to'] );
    }

    public function testEveryNarrowingKey(): void
    {
        $spec = $this->ok( [ 'filter' => [
            'categories' => [ 'c1' ], 'tags' => [ 't1' ], 'types' => [ 'variable', 'simple' ], 'stock_status' => 'instock', 'on_sale' => false,
            'search' => '  hat ', 'ids' => [ 5 ], 'exclude_ids' => [ 6 ], 'status' => [ 'draft' ],
        ], 'change' => $this->change( 'amount', '-5' ) ] );
        $f = $spec['filter'];
        $this->assertSame( [ [ 'simple', 'variable' ], 'instock', false, 'hat', [ 5 ], [ 6 ], [ 'draft' ] ], [ $f['types'], $f['stock_status'], $f['on_sale'], $f['search'], $f['ids'], $f['exclude_ids'], $f['status'] ] );
    }

    public function testAnyOneNarrowingKeyIsEnoughButStatusAndExcludeAloneAreNot(): void
    {
        foreach ( [ [ 'tags' => [ 't' ] ], [ 'types' => [ 'simple' ] ], [ 'stock_status' => 'outofstock' ], [ 'on_sale' => true ], [ 'on_sale' => false ], [ 'search' => 'x' ], [ 'ids' => [ 1 ] ] ] as $filter ) {
            $this->ok( [ 'filter' => $filter, 'change' => $this->change() ] );
        }
        $this->refused( [ 'filter' => [ 'status' => [ 'draft' ] ], 'change' => $this->change() ], 'must narrow the products' );
        $this->refused( [ 'filter' => [ 'exclude_ids' => [ 1 ] ], 'change' => $this->change() ], 'must narrow the products' );
        $this->refused( [ 'filter' => [], 'change' => $this->change() ], '"filter" is required' );
    }

    public function testAllMustBeAskedForAndStandsAlone(): void
    {
        $spec = $this->ok( [ 'filter' => [ 'all' => true ], 'change' => $this->change() ] );
        $this->assertTrue( $spec['filter']['all'] );
        $this->ok( [ 'filter' => [ 'all' => true, 'exclude_ids' => [ 4 ], 'status' => [ 'publish', 'private' ] ], 'change' => $this->change() ] );
        $this->refused( [ 'filter' => [ 'all' => true, 'categories' => [ 'x' ] ], 'change' => $this->change() ], 'cannot be combined' );
        $this->refused( [ 'filter' => [ 'all' => false ], 'change' => $this->change() ], 'must narrow the products' );
        $this->refused( [ 'filter' => [ 'all' => 'yes' ], 'change' => $this->change() ], '"filter.all" must be true or false' );
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function badFilters(): array
    {
        return [
            'not an object'       => [ [ 'filter' => 'shirts' ], '"filter" is required and must be an object' ],
            'a list'              => [ [ 'filter' => [ 'shirts' ] ], '"filter" is required and must be an object' ],
            'unknown key'         => [ [ 'filter' => [ 'colour' => 'red' ] ], '"filter.colour" is not a supported filter' ],
            'category string'     => [ [ 'filter' => [ 'categories' => 'shirts' ] ], '"filter.categories" must be a list of 1 to 10' ],
            'empty list'          => [ [ 'filter' => [ 'categories' => [] ] ], '"filter.categories" must be a list of 1 to 10' ],
            'too many categories' => [ [ 'filter' => [ 'categories' => [ 'a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8', 'a9', 'a10', 'a11' ] ] ], 'a list of 1 to 10' ],
            'bad slug'            => [ [ 'filter' => [ 'tags' => [ 'a b' ] ] ], 'only slugs' ],
            'slug number'         => [ [ 'filter' => [ 'tags' => [ 5 ] ] ], 'only slugs' ],
            'bad type'            => [ [ 'filter' => [ 'types' => [ 'grouped' ] ] ], '"filter.types" must be a list of: simple, variable, external' ],
            'bad stock'           => [ [ 'filter' => [ 'stock_status' => 'sold' ] ], '"filter.stock_status" must be one of' ],
            'on_sale string'      => [ [ 'filter' => [ 'on_sale' => 'true' ] ], '"filter.on_sale" must be true or false' ],
            'blank search'        => [ [ 'filter' => [ 'search' => '   ' ] ], '"filter.search" must be a non-empty string' ],
            'long search'         => [ [ 'filter' => [ 'search' => str_repeat( 'x', 101 ) ] ], 'at most 100 characters' ],
            'id as string'        => [ [ 'filter' => [ 'ids' => [ '5' ] ] ], 'whole product ids from 1' ],
            'id zero'             => [ [ 'filter' => [ 'ids' => [ 0 ] ] ], 'whole product ids from 1' ],
            'too many ids'        => [ [ 'filter' => [ 'ids' => range( 1, 101 ) ] ], '1 to 100 product ids' ],
            'bad status'          => [ [ 'filter' => [ 'categories' => [ 'a' ], 'status' => [ 'trash' ] ] ], '"filter.status" must be a list of: publish, private, draft, pending' ],
        ];
    }

    #[DataProvider( 'badFilters' )]
    public function testBadFiltersAreRefusedByName( array $args, string $needle ): void
    {
        $this->refused( $args + [ 'change' => $this->change() ], $needle );
    }

    /** @return array<string,array{mixed,string}> */
    public static function badChanges(): array
    {
        return [
            'missing'           => [ null, '"change" is required' ],
            'not an object'     => [ '10', '"change" is required' ],
            'unknown key'       => [ [ 'mode' => 'percent', 'value' => '10', 'round' => 1 ], '"change.round" is not supported' ],
            'bad mode'          => [ [ 'mode' => 'multiply', 'value' => '2' ], '"change.mode" must be "percent" or "amount"' ],
            'number'            => [ [ 'mode' => 'percent', 'value' => 10 ], 'not a number' ],
            'percent sign'      => [ [ 'mode' => 'percent', 'value' => '10%' ], 'no % sign' ],
            'three decimals'    => [ [ 'mode' => 'percent', 'value' => '1.255' ], 'at most 2 decimals' ],
            'zero'              => [ [ 'mode' => 'percent', 'value' => '0' ], 'must not be zero' ],
            'zero amount'       => [ [ 'mode' => 'amount', 'value' => '0.00' ], 'must not be zero' ],
            'percent too low'   => [ [ 'mode' => 'percent', 'value' => '-90.01' ], 'from -90 to 500' ],
            'percent too high'  => [ [ 'mode' => 'percent', 'value' => '500.01' ], 'from -90 to 500' ],
            'amount currency'   => [ [ 'mode' => 'amount', 'value' => '$5' ], 'no currency symbol' ],
            'amount thousands'  => [ [ 'mode' => 'amount', 'value' => '1,000' ], 'no currency symbol' ],
            'amount too big'    => [ [ 'mode' => 'amount', 'value' => '100000.5' ], 'at most 100000 either way' ],
            'amount plus sign'  => [ [ 'mode' => 'amount', 'value' => '+5' ], 'no currency symbol' ],
        ];
    }

    #[DataProvider( 'badChanges' )]
    public function testBadChangesAreRefused( mixed $change, string $needle ): void
    {
        $args = [ 'filter' => [ 'categories' => [ 'a' ] ] ];
        if ( null !== $change ) {
            $args['change'] = $change;
        }
        $this->refused( $args, $needle );
    }

    public function testTheLimitsOfChangeAreAccepted(): void
    {
        foreach ( [ [ 'percent', '-90' ], [ 'percent', '500' ], [ 'percent', '0.01' ], [ 'percent', '-12.5' ], [ 'amount', '100000' ], [ 'amount', '-100000' ], [ 'amount', '0.000001' ], [ 'amount', '2.50' ] ] as [ $mode, $value ] ) {
            $spec = $this->ok( [ 'filter' => [ 'ids' => [ 1 ] ], 'change' => $this->change( $mode, $value ) ] );
            $this->assertSame( $mode, $spec['change']['mode'] );
        }
        $this->assertSame( '2.5', $this->ok( [ 'filter' => [ 'ids' => [ 1 ] ], 'change' => $this->change( 'amount', '2.50' ) ] )['change']['value'] );
    }

    public function testRoundTo(): void
    {
        foreach ( [ '0.99' => '0.99', '0.95' => '0.95', '0.5' => '0.5', '0.50' => '0.5', '0' => '0', '1' => '0', '0.00' => '0' ] as $in => $out ) {
            $this->assertSame( $out, $this->ok( [ 'filter' => [ 'ids' => [ 1 ] ], 'change' => $this->change(), 'round_to' => (string) $in ] )['round_to'], (string) $in );
        }
        foreach ( [ 0.99, 99, '1.5', '2', '.99', '0.999', '-0.5', '' ] as $bad ) {
            $this->refused( [ 'filter' => [ 'ids' => [ 1 ] ], 'change' => $this->change(), 'round_to' => $bad ], '"round_to" must be a string' );
        }
    }

    public function testEveryReasonIsReportedAtOnce(): void
    {
        $r = ProductFilter::parse( [ 'filter' => [ 'colour' => 1 ], 'change' => [ 'mode' => 'percent', 'value' => 'ten' ], 'round_to' => 5 ] );
        $this->assertNull( $r['spec'] );
        $this->assertGreaterThanOrEqual( 3, count( $r['reasons'] ) );
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter ProductFilterTest`
Expected: errors `Class "AiEditorDivi5\Pro\Products\ProductFilter" not found`.

- [ ] **Step 3: Create `ProductFilter`**

Create `pro-addon/src/Products/ProductFilter.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Products;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The arguments of bulk_adjust_prices (`filter`, `change`, `round_to`), validated and normalised. Pure: no WordPress call. A wrong type is
 * refused with a reason, never cast; every reason is returned at once. The normalised spec is what the preview token is bound to, so
 * the same request written another way (keys in another order, a list in another order, "10.0" for "10") gives the same token.
 */
final class ProductFilter
{
    public const MAX_CATEGORIES = 10;
    public const MAX_TAGS       = 20;
    public const MAX_IDS        = 100;
    public const TYPES          = [ 'simple', 'variable', 'external' ];
    public const STATUSES       = [ 'publish', 'private', 'draft', 'pending' ];
    public const STOCK_STATUSES = [ 'instock', 'outofstock', 'onbackorder' ];
    public const MODES          = [ 'percent', 'amount' ];

    private const FILTER_KEYS = [ 'categories', 'tags', 'types', 'stock_status', 'on_sale', 'search', 'ids', 'all', 'exclude_ids', 'status' ];
    private const SLUG        = '/^[A-Za-z0-9%_-]{1,200}$/D';

    /**
     * @param array<string,mixed> $arguments the tool arguments (only `filter`, `change` and `round_to` are read)
     * @return array{spec: ?array{filter: array<string,mixed>, change: array{mode:string, value:string}, round_to: ?string}, reasons: list<string>}
     */
    public static function parse( array $arguments ): array
    {
        $r      = [];
        $filter = self::filter( $arguments['filter'] ?? null, $r );
        $change = self::change( $arguments['change'] ?? null, $r );
        $round  = self::roundTo( $arguments['round_to'] ?? null, $r );

        return [ 'spec' => [] === $r ? [ 'filter' => $filter, 'change' => $change, 'round_to' => $round ] : null, 'reasons' => $r ];
    }

    /**
     * @param list<string> $r
     * @return array<string,mixed>
     */
    private static function filter( mixed $in, array &$r ): array
    {
        $out = [
            'categories' => [], 'tags' => [], 'types' => [], 'stock_status' => null, 'on_sale' => null, 'search' => null,
            'ids' => [], 'all' => false, 'exclude_ids' => [], 'status' => [ 'publish' ],
        ];
        if ( ! is_array( $in ) || array_is_list( $in ) ) {
            $r[] = '"filter" is required and must be an object';

            return $out;
        }
        foreach ( array_keys( $in ) as $key ) {
            if ( ! in_array( $key, self::FILTER_KEYS, true ) ) {
                $r[] = '"filter.' . self::shown( (string) $key ) . '" is not a supported filter';
            }
        }
        $out['categories'] = self::slugs( $in, 'categories', self::MAX_CATEGORIES, $r );
        $out['tags']       = self::slugs( $in, 'tags', self::MAX_TAGS, $r );
        $out['types']      = self::enumList( $in, 'types', self::TYPES, false, $r );
        $out['ids']        = self::idList( $in, 'ids', $r );
        $out['exclude_ids'] = self::idList( $in, 'exclude_ids', $r );
        if ( self::given( $in, 'status' ) ) {
            $status = self::enumList( $in, 'status', self::STATUSES, true, $r );
            if ( [] !== $status ) {
                $out['status'] = $status;
            }
        }
        if ( self::given( $in, 'stock_status' ) ) {
            if ( is_string( $in['stock_status'] ) && in_array( $in['stock_status'], self::STOCK_STATUSES, true ) ) {
                $out['stock_status'] = $in['stock_status'];
            } else {
                $r[] = '"filter.stock_status" must be one of: ' . implode( ', ', self::STOCK_STATUSES );
            }
        }
        if ( self::given( $in, 'on_sale' ) ) {
            if ( is_bool( $in['on_sale'] ) ) {
                $out['on_sale'] = $in['on_sale'];
            } else {
                $r[] = '"filter.on_sale" must be true or false';
            }
        }
        if ( self::given( $in, 'search' ) ) {
            $search = $in['search'];
            if ( ! is_string( $search ) || '' === trim( $search ) || mb_strlen( trim( $search ) ) > 100 || 1 !== preg_match( '//u', $search ) ) {
                $r[] = '"filter.search" must be a non-empty string of at most 100 characters';
            } else {
                $out['search'] = trim( $search );
            }
        }
        if ( self::given( $in, 'all' ) ) {
            if ( true === $in['all'] ) {
                $out['all'] = true;
            } elseif ( false !== $in['all'] ) {
                $r[] = '"filter.all" must be true or false';
            }
        }

        $selects = [] !== $out['categories'] || [] !== $out['tags'] || [] !== $out['types'] || null !== $out['stock_status'] || null !== $out['on_sale']
            || null !== $out['search'] || [] !== $out['ids'];
        if ( $out['all'] && $selects ) {
            $r[] = '"filter.all" cannot be combined with other filters; use either "all": true or the narrowing filters';
        } elseif ( ! $out['all'] && ! $selects && [] === $r ) {
            $r[] = '"filter" must narrow the products (categories, tags, types, stock_status, on_sale, search or ids), or say "all": true to adjust every product';
        }

        return $out;
    }

    /**
     * @param list<string> $r
     * @return array{mode: string, value: string}
     */
    private static function change( mixed $in, array &$r ): array
    {
        $none = [ 'mode' => 'percent', 'value' => '0' ];
        if ( ! is_array( $in ) || array_is_list( $in ) ) {
            $r[] = '"change" is required and must be an object: { "mode": "percent" or "amount", "value": "-10" }';

            return $none;
        }
        foreach ( array_keys( $in ) as $key ) {
            if ( 'mode' !== $key && 'value' !== $key ) {
                $r[] = '"change.' . self::shown( (string) $key ) . '" is not supported (only "mode" and "value")';
            }
        }
        $mode = $in['mode'] ?? null;
        if ( ! is_string( $mode ) || ! in_array( $mode, self::MODES, true ) ) {
            $r[] = '"change.mode" must be "percent" or "amount"';

            return $none;
        }
        $value = $in['value'] ?? null;
        $regex = 'percent' === $mode ? '/^-?\d{1,3}(\.\d{1,2})?$/D' : '/^-?\d{1,6}(\.\d{1,6})?$/D';
        if ( ! is_string( $value ) || 1 !== preg_match( $regex, $value ) ) {
            $r[] = 'percent' === $mode
                ? '"change.value" must be a signed decimal string with at most 2 decimals, for example "10" or "-12.5" (no % sign, not a number)'
                : '"change.value" must be a signed decimal string with at most 6 decimals, for example "-5" or "2.50" (no currency symbol, not a number)';

            return [ 'mode' => $mode, 'value' => '0' ];
        }
        $units = PriceAdjuster::units( $value );
        if ( 0 === $units ) {
            $r[] = '"change.value" must not be zero';
        } elseif ( 'percent' === $mode && ( $units < -90 * PriceAdjuster::SCALE || $units > 500 * PriceAdjuster::SCALE ) ) {
            $r[] = '"change.value" for a percent must be from -90 to 500';
        } elseif ( 'amount' === $mode && abs( $units ) > 100000 * PriceAdjuster::SCALE ) {
            $r[] = '"change.value" for an amount must be at most 100000 either way';
        }

        return [ 'mode' => $mode, 'value' => self::canonical( $units ) ];
    }

    /** @param list<string> $r */
    private static function roundTo( mixed $in, array &$r ): ?string
    {
        if ( null === $in ) {
            return null;
        }
        if ( ! is_string( $in ) || 1 !== preg_match( '/^(?:[01]|0\.\d{1,2})$/D', $in ) ) {
            $r[] = '"round_to" must be a string such as "0.95", "0.75" or "0.5" (what the new price should end in), or "0" for whole numbers';

            return null;
        }

        return '1' === $in ? '0' : self::canonical( PriceAdjuster::units( $in ) );
    }

    /** Millionths as the shortest decimal string ("10", "-12.5", "0.95"). */
    private static function canonical( int $units ): string
    {
        $neg  = $units < 0;
        $abs  = abs( $units );
        $int  = intdiv( $abs, PriceAdjuster::SCALE );
        $frac = rtrim( str_pad( (string) ( $abs % PriceAdjuster::SCALE ), 6, '0', STR_PAD_LEFT ), '0' );

        return ( $neg ? '-' : '' ) . $int . ( '' === $frac ? '' : '.' . $frac );
    }

    /** @param array<string,mixed> $in */
    private static function given( array $in, string $key ): bool
    {
        return array_key_exists( $key, $in ) && null !== $in[ $key ];
    }

    /**
     * @param array<string,mixed> $in
     * @param list<string> $r
     * @return list<string>
     */
    private static function slugs( array $in, string $key, int $max, array &$r ): array
    {
        if ( ! self::given( $in, $key ) ) {
            return [];
        }
        $list = $in[ $key ];
        if ( ! is_array( $list ) || ! array_is_list( $list ) || [] === $list || count( $list ) > $max ) {
            $r[] = "\"filter.{$key}\" must be a list of 1 to {$max} category or tag slugs";

            return [];
        }
        foreach ( $list as $slug ) {
            if ( ! is_string( $slug ) || 1 !== preg_match( self::SLUG, $slug ) ) {
                $r[] = "\"filter.{$key}\" must contain only slugs (letters, digits, \"-\", \"_\" or \"%\")";

                return [];
            }
        }
        $list = array_values( array_unique( $list ) );
        sort( $list );

        return $list;
    }

    /**
     * @param array<string,mixed> $in
     * @param list<string> $allowed
     * @param list<string> $r
     * @return list<string>
     */
    private static function enumList( array $in, string $key, array $allowed, bool $required, array &$r ): array
    {
        if ( ! self::given( $in, $key ) ) {
            return [];
        }
        $list = $in[ $key ];
        if ( ! is_array( $list ) || ! array_is_list( $list ) || [] === $list ) {
            $r[] = "\"filter.{$key}\" must be a list of: " . implode( ', ', $allowed );

            return [];
        }
        foreach ( $list as $v ) {
            if ( ! is_string( $v ) || ! in_array( $v, $allowed, true ) ) {
                $r[] = "\"filter.{$key}\" must be a list of: " . implode( ', ', $allowed );

                return [];
            }
        }
        $list = array_values( array_unique( $list ) );
        sort( $list );

        return $list;
    }

    /**
     * @param array<string,mixed> $in
     * @param list<string> $r
     * @return list<int>
     */
    private static function idList( array $in, string $key, array &$r ): array
    {
        if ( ! self::given( $in, $key ) ) {
            return [];
        }
        $list = $in[ $key ];
        if ( ! is_array( $list ) || ! array_is_list( $list ) || [] === $list || count( $list ) > self::MAX_IDS ) {
            $r[] = "\"filter.{$key}\" must be a list of 1 to " . self::MAX_IDS . ' product ids';

            return [];
        }
        foreach ( $list as $id ) {
            if ( ! is_int( $id ) || $id < 1 ) {
                $r[] = "\"filter.{$key}\" must contain only whole product ids from 1";

                return [];
            }
        }
        $list = array_values( array_unique( $list ) );
        sort( $list );

        return $list;
    }

    private static function shown( string $key ): string
    {
        return mb_strlen( $key ) > 80 ? mb_substr( $key, 0, 80 ) . '...' : $key;
    }
}
```

- [ ] **Step 4: Register it**

In `pro-addon/src/autoload.php`, replace this exact text:

```php
require_once __DIR__ . '/Products/PriceAdjuster.php';

```

with:

```php
require_once __DIR__ . '/Products/PriceAdjuster.php';
require_once __DIR__ . '/Products/ProductFilter.php';

```

- [ ] **Step 5: Run the new tests and the suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter ProductFilterTest` - Expected: `OK (40 tests, 229 assertions)`.
Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK`.

- [ ] **Step 6: RED proof (do it, then revert)**

Change `} elseif ( ! $out['all'] && ! $selects && [] === $r ) {` to `} elseif ( false ) {`. Expected: `testAnyOneNarrowingKeyIsEnoughButStatusAndExcludeAloneAreNot` and the `badFilters` row `'unknown key'`-style tests that expect the "must narrow" reason fail (3 failures). Revert.

- [ ] **Step 7: Commit**

```bash
git add pro-addon/src/Products/ProductFilter.php pro-addon/tests/ProductFilterTest.php pro-addon/src/autoload.php
git commit -m "$(cat <<'EOF'
feat(pro): ProductFilter for bulk_adjust_prices (validated, normalised filter, change and round_to)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: The writer finds products (`findProducts`, `priceDecimals`)

**Files:**
- Modify: `pro-addon/src/Products/ProductWriter.php`, `pro-addon/src/Products/WooProductWriter.php`, `pro-addon/tests/FakeProductWriter.php`
- Modify: `scripts/verify-pro-update-products.php`, `scripts/verify-pro-create-product.php` (their decorators implement `ProductWriter` and would die without the two new methods)

**Interfaces:**
- Consumes: nothing new.
- Produces (exact):
  - `ProductWriter::findProducts( array $criteria, int $limit ): array{ids: list<int>, truncated: bool}` - `$criteria` is a normalised `ProductFilter` filter (`status`, `types`, `categories` and `tags` as SLUGS, `stock_status`, `on_sale`, `search`, `ids`, `exclude_ids`; keys may be missing or empty). Simple, variable and external products only (never grouped, variations or the trash), oldest id first, at most `$limit` ids; `truncated` is true when there were more or when too many candidates had to be scanned to decide `on_sale`.
  - `ProductWriter::priceDecimals(): int`.
  - `FakeProductWriter`: `public int $decimals = 2`, `public bool $findTruncated = false`, `findProducts()`/`priceDecimals()` over its `$products` (category/tag slugs resolved through `$terms`; `on_sale` = non-empty `sale_price`).

No new unit test: the interface and fake are test infrastructure driven by Task 5's tests; `WooProductWriter` is proven by Task 7's live gate and by reading. The existing suite must stay green.

- [ ] **Step 1: Extend the interface**

**Edit 1 of 1 in `pro-addon/src/Products/ProductWriter.php`** - replace this exact text:

```php
     */
    public function apply( int $id, array $changes ): void;

    /** Permanently delete (used only to roll back a product this same call just created). */
    public function delete( int $id ): bool;

```

with:

```php
     */
    public function apply( int $id, array $changes ): void;

    /**
     * The ids of the simple, variable and external products (never grouped, never variations, never the trash) that match the criteria of
     * a normalised bulk_adjust_prices filter (see ProductFilter): `status` (list), `types` (list, empty = all three), `categories` and
     * `tags` (slugs; a category also matches its children), `stock_status`, `on_sale` (decided in PHP: WooCommerce ignores that
     * query argument), `search` (words in the name), `ids`, `exclude_ids`. Oldest id first. At most `$limit` ids are returned;
     * `truncated` is true when there were more, or when too many candidates had to be scanned to decide `on_sale`.
     *
     * @param array<string,mixed> $criteria
     * @return array{ids: list<int>, truncated: bool}
     */
    public function findProducts( array $criteria, int $limit ): array;

    /** The number of decimals the shop shows prices with (WooCommerce's own setting). */
    public function priceDecimals(): int;

    /** Permanently delete (used only to roll back a product this same call just created). */
    public function delete( int $id ): bool;

```

- [ ] **Step 2: Implement in the real writer**

**Edit 1 of 1 in `pro-addon/src/Products/WooProductWriter.php`** - replace this exact text:

```php
            'virtual'           => (bool) $p->get_virtual(),
            'sold_individually' => (bool) $p->get_sold_individually(),
        ];
    }

    public function typeOf( int $id ): ?string
```

with:

```php
            'virtual'           => (bool) $p->get_virtual(),
            'sold_individually' => (bool) $p->get_sold_individually(),
        ];
    }

    /** Candidates examined to decide `on_sale` (same cap as the free plugin's product list). */
    private const MAX_SCAN = 500;

    public function findProducts( array $criteria, int $limit ): array
    {
        $onSale = $criteria['on_sale'] ?? null;
        $q      = [
            'return'  => 'ids',
            'status'  => $criteria['status'] ?? [ 'publish' ],
            'type'    => [] === ( $criteria['types'] ?? [] ) ? [ 'simple', 'variable', 'external' ] : $criteria['types'],
            'orderby' => 'ID',
            'order'   => 'ASC',
        ];
        if ( [] !== ( $criteria['categories'] ?? [] ) ) {
            $q['category'] = $criteria['categories'];
        }
        if ( [] !== ( $criteria['tags'] ?? [] ) ) {
            $q['tag'] = $criteria['tags'];
        }
        if ( null !== ( $criteria['stock_status'] ?? null ) ) {
            $q['stock_status'] = $criteria['stock_status'];
        }
        if ( null !== ( $criteria['search'] ?? null ) ) {
            $q['s'] = $criteria['search'];
        }
        if ( [] !== ( $criteria['ids'] ?? [] ) ) {
            $q['include'] = $criteria['ids'];
        }
        if ( [] !== ( $criteria['exclude_ids'] ?? [] ) ) {
            $q['exclude'] = $criteria['exclude_ids'];
        }
        $cap = null === $onSale ? $limit : self::MAX_SCAN;
        $ids = array_map( 'intval', (array) wc_get_products( $q + [ 'limit' => $cap + 1, 'paginate' => false ] ) );
        $truncated = count( $ids ) > $cap;
        $ids       = array_slice( $ids, 0, $cap );
        if ( null !== $onSale ) {
            $hits = [];
            foreach ( $ids as $id ) {
                $p = wc_get_product( $id );
                if ( $p && (bool) $p->is_on_sale() === $onSale ) {
                    $hits[] = $id;
                }
            }
            $ids = $hits;
            if ( count( $ids ) > $limit ) {
                $truncated = true;
                $ids       = array_slice( $ids, 0, $limit );
            }
        }

        return [ 'ids' => $ids, 'truncated' => $truncated ];
    }

    public function priceDecimals(): int
    {
        return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
    }

    public function typeOf( int $id ): ?string
```

Notes for the reader and the reviewer (nothing here runs in PHPUnit; judge it against WooCommerce behaviour): `wc_get_products()` takes `status`, `type`, `category` and `tag` (slugs; a category query includes children), `stock_status`, `s` (search), `include`, `exclude`, and ignores an `on_sale` argument, so on-sale is decided in PHP over at most 500 scanned candidates (the same approach and cap as the free plugin's product list). Without `on_sale` the query asks for `limit + 1` rows to know whether it overflowed.

- [ ] **Step 3: Extend the fake**

**Edit 1 of 1 in `pro-addon/tests/FakeProductWriter.php`** - replace this exact text:

```php
        }

        return $s;
    }

    public function typeOf( int $id ): ?string
```

with:

```php
        }

        return $s;
    }

    /** The shop's price decimals the fake reports. */
    public int $decimals = 2;
    /** Make findProducts() report an overflow (more matches than the limit, or too many candidates to scan). */
    public bool $findTruncated = false;

    public function priceDecimals(): int { return $this->decimals; }

    public function findProducts( array $criteria, int $limit ): array
    {
        $ids = [];
        foreach ( array_keys( $this->products ) as $id ) {
            $p = $this->product( (int) $id );
            if ( null === $p ) {
                continue;
            }
            $types = [] === ( $criteria['types'] ?? [] ) ? [ 'simple', 'variable', 'external' ] : $criteria['types'];
            if ( ! in_array( $p['type'], $types, true ) || ! in_array( $p['status'] ?? 'publish', $criteria['status'] ?? [ 'publish' ], true ) ) {
                continue;
            }
            foreach ( [ 'categories' => [ 'product_cat', 'category_ids' ], 'tags' => [ 'product_tag', 'tag_ids' ] ] as $key => [ $taxonomy, $field ] ) {
                $want = array_map( fn ( string $slug ): int => $this->terms[ $taxonomy ][ $slug ] ?? -1, $criteria[ $key ] ?? [] );
                if ( [] !== $want && [] === array_intersect( $want, $p[ $field ] ) ) {
                    continue 2;
                }
            }
            if ( null !== ( $criteria['stock_status'] ?? null ) && $criteria['stock_status'] !== $p['stock_status'] ) {
                continue;
            }
            if ( null !== ( $criteria['on_sale'] ?? null ) && ( '' !== (string) ( $p['sale_price'] ?? '' ) ) !== $criteria['on_sale'] ) {
                continue;
            }
            if ( null !== ( $criteria['search'] ?? null ) && false === stripos( (string) $p['name'], (string) $criteria['search'] ) ) {
                continue;
            }
            if ( [] !== ( $criteria['ids'] ?? [] ) && ! in_array( (int) $id, $criteria['ids'], true ) ) {
                continue;
            }
            if ( in_array( (int) $id, $criteria['exclude_ids'] ?? [], true ) ) {
                continue;
            }
            $ids[] = (int) $id;
        }
        sort( $ids );

        return [ 'ids' => array_slice( $ids, 0, $limit ), 'truncated' => $this->findTruncated || count( $ids ) > $limit ];
    }

    public function typeOf( int $id ): ?string
```

- [ ] **Step 4: Keep both existing live gates valid**

**Edit 1 of 1 in `scripts/verify-pro-update-products.php`** - replace this exact text:

```php
    public function product( int $id ): ?array { return $this->real->product( $id ); }
    public function delete( int $id ): bool { return $this->real->delete( $id ); }
    public function editUrl( int $id ): string { return $this->real->editUrl( $id ); }

    public function apply( int $id, array $changes ): void
    {
```

with:

```php
    public function product( int $id ): ?array { return $this->real->product( $id ); }
    public function delete( int $id ): bool { return $this->real->delete( $id ); }
    public function editUrl( int $id ): string { return $this->real->editUrl( $id ); }
    public function findProducts( array $criteria, int $limit ): array { return $this->real->findProducts( $criteria, $limit ); }
    public function priceDecimals(): int { return $this->real->priceDecimals(); }

    public function apply( int $id, array $changes ): void
    {
```

**Edit 1 of 1 in `scripts/verify-pro-create-product.php`** - replace this exact text:

```php
            $this->real->apply( $id, $changes );
        }

        public function termIds( string $taxonomy, array $slugs ): array
        {
            return $this->real->termIds( $taxonomy, $slugs );
```

with:

```php
            $this->real->apply( $id, $changes );
        }

        public function findProducts( array $criteria, int $limit ): array
        {
            return $this->real->findProducts( $criteria, $limit );
        }

        public function priceDecimals(): int
        {
            return $this->real->priceDecimals();
        }

        public function termIds( string $taxonomy, array $slugs ): array
        {
            return $this->real->termIds( $taxonomy, $slugs );
```

- [ ] **Step 5: Syntax and suite**

Run: `php -l pro-addon/src/Products/ProductWriter.php && php -l pro-addon/src/Products/WooProductWriter.php && php -l pro-addon/tests/FakeProductWriter.php && php -l scripts/verify-pro-update-products.php && php -l scripts/verify-pro-create-product.php` - Expected: `No syntax errors detected` five times.
Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK`.
Run: `grep -rn "implements ProductWriter" --include='*.php' pro-addon scripts tests` - Expected: exactly `WooProductWriter`, `FakeProductWriter`, the anonymous class in `verify-pro-create-product.php` and `AiedUpFailingWriter` in `verify-pro-update-products.php`.

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src/Products/ProductWriter.php pro-addon/src/Products/WooProductWriter.php pro-addon/tests/FakeProductWriter.php scripts/verify-pro-update-products.php scripts/verify-pro-create-product.php
git commit -m "$(cat <<'EOF'
feat(pro): ProductWriter::findProducts and priceDecimals for bulk_adjust_prices

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: The `BulkAdjustPrices` tool

**Files:**
- Create: `pro-addon/src/Tools/BulkAdjustPrices.php`
- Create: `pro-addon/tests/BulkAdjustPricesTest.php`
- Modify: `pro-addon/src/autoload.php`

**Interfaces:**
- Consumes: Tasks 1-4: `ProductUpdateRun`, `PriceAdjuster`, `ProductFilter`, `ProductWriter::findProducts/priceDecimals/termIds/typeOf/product`, `FakeProductWriter`; existing `PreviewToken`, `Canon`, `Args`, `ProductFields`.
- Produces: `new BulkAdjustPrices( BatchLog $log, ?ProductWriter $writer = null, ?\Closure $clock = null )` implementing `ProTool` (`name()` = `bulk_adjust_prices`, `capability()` = `edit_posts`); `BulkAdjustPrices::REASONS` (code => sentence, `PriceAdjuster::REASONS` plus `no_variations`, `variation_blocked`, `not_found`); constants `MAX_PRODUCTS` 100, `MAX_FIELDS` 400. `run( array $arguments ): array`, arguments `filter`, `change`, `round_to`, `dry_run` (strict boolean), `token`. Results:
  - dry run: `{ changed:false, dry_run:true, summary:{matched, would_change, skipped, unchanged}, products:[{id,name,type,changes:{regular_price:{before,after}},variations?:[{id,changes}]}], skipped:[{id,name,reason,detail,rows?:[{id,reason,detail}]}], unchanged:[ids], token?, message }` (no `token` when nothing would change);
  - real run: the `ProductUpdateRun` result (`changed, dry_run:false, updated, skipped, unchanged, batch`) plus `not_in_preview`, or `{changed:false, ..., not_in_preview, message:'Nothing was changed.'}`;
  - every refusal is an `\InvalidArgumentException` whose message starts `Nothing was changed. N problem(s): ...` (cut at 6,000 characters) or is the token message.

Behaviour in one paragraph (spec Design 1-2): arguments are parsed by `ProductFilter` (all reasons at once), WooCommerce and `edit_products` checked, category/tag slugs must exist, then (real runs) the token is opened. `findProducts()` resolves the filter (limit 100); products the user may not edit are dropped; a dry run refuses when more than 100 matched or the scan overflowed. For every candidate (a real run: the matches plus the token's ids) the tool loads the state, checks the token's hash, and adjusts the regular price(s) with `PriceAdjuster` (a variable product as a whole or not at all). Plans are capped at 400 price fields. A dry run returns the preview and a token over `id => hash`; a real run spends the token (after validation) and hands the plans to `ProductUpdateRun` (batch summary `Adjusted prices of N product(s)`). A product matching now but absent from the token is only counted (`not_in_preview`).

- [ ] **Step 1: Write the tests**

Create `pro-addon/tests/BulkAdjustPricesTest.php`:

```php
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
```

- [ ] **Step 2: Run them to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter BulkAdjustPricesTest`
Expected: errors `Class "AiEditorDivi5\Pro\Tools\BulkAdjustPrices" not found`.

- [ ] **Step 3: Create the tool**

Create `pro-addon/src/Tools/BulkAdjustPrices.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Products\PriceAdjuster;
use AiEditorDivi5\Pro\Products\ProductFields;
use AiEditorDivi5\Pro\Products\ProductFilter;
use AiEditorDivi5\Pro\Products\ProductUpdateRun;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;
use AiEditorDivi5\Pro\Site\PreviewToken;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Raise or lower the REGULAR prices of the simple, external and variable WooCommerce products a filter matches, by a percent or an
 * amount, with exact decimal arithmetic. It changes live prices, so a real run needs the single-use token of a dry run of the same
 * arguments; the token fixes the list of products and each product's state, and a product that changed since is skipped. A product that
 * cannot be adjusted is skipped with a reason, the rest proceed. The apply, read-back, rollback and batch are the engine update_products
 * uses (ProductUpdateRun). Never touches a sale price, a status or anything but the regular price.
 */
final class BulkAdjustPrices implements ProTool
{
    public const NAME         = 'bulk_adjust_prices';
    public const MAX_PRODUCTS = 100;
    public const MAX_FIELDS   = 400;
    private const MAX_MESSAGE = 6000;
    private const NEEDS_TOKEN = 'A real run needs the token from a dry run of the same arguments, made by you in the last 30 minutes and not used before. Call again with dry_run=true.';
    private const CHANGED     = 'changed_since_preview';

    /** What a skip code means, for the answer. */
    public const REASONS = [
        'no_variations'     => 'the variable product has no variations to adjust',
        'variation_blocked' => 'a variation of this product cannot be adjusted, so the product is left as it is',
        'not_found'         => 'the product could not be read',
    ] + PriceAdjuster::REASONS;

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
        $list = static fn ( string $d, string $item ): array => [ 'type' => 'array', 'description' => $d, 'items' => [ 'type' => $item ] ];

        return [
            'name'        => self::NAME,
            'description' => 'Raise or lower the REGULAR prices of the WooCommerce products a filter matches, by a percent or an amount, rounded exactly. Never touches sale prices or anything else. Call with dry_run=true first, then repeat with its token. Unadjustable products are skipped with a reason. One undoable batch.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'filter'   => [
                        'type'        => 'object',
                        'description' => 'Which products. Give at least one of categories, tags, types, stock_status, on_sale, search, ids - or all=true. Only published products unless status says otherwise.',
                        'properties'  => [
                            'categories'   => $list( 'Category slugs (also matches child categories)', 'string' ),
                            'tags'         => $list( 'Tag slugs', 'string' ),
                            'types'        => $list( 'simple, variable and/or external', 'string' ),
                            'stock_status' => [ 'type' => 'string', 'description' => 'instock, outofstock or onbackorder' ],
                            'on_sale'      => [ 'type' => 'boolean', 'description' => 'Only products on sale now (true) or not (false)' ],
                            'search'       => [ 'type' => 'string', 'description' => 'Words in the product name' ],
                            'ids'          => $list( 'Product ids (at most 100)', 'integer' ),
                            'all'          => [ 'type' => 'boolean', 'description' => 'true = every product (in the allowed statuses); cannot be combined with the narrowing filters' ],
                            'exclude_ids'  => $list( 'Product ids to leave out', 'integer' ),
                            'status'       => $list( 'publish (default), private, draft and/or pending', 'string' ),
                        ],
                    ],
                    'change'   => [
                        'type'        => 'object',
                        'description' => 'mode "percent" (-90 to 500) or "amount", value a signed decimal STRING, for example {"mode":"percent","value":"-10"}',
                        'properties'  => [
                            'mode'  => [ 'type' => 'string', 'description' => 'percent or amount' ],
                            'value' => [ 'type' => 'string', 'description' => 'Signed decimal string, for example "10", "-12.5", "-5"' ],
                        ],
                        'required'    => [ 'mode', 'value' ],
                    ],
                    'round_to' => [ 'type' => 'string', 'description' => 'Optional: what new prices should end in, for example "0.95" or "0.5"; "0" for whole numbers. Without it prices are rounded to the shop\'s decimals.' ],
                    'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview only; returns the token a real run needs' ],
                    'token'    => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                ],
                'required'   => [ 'filter', 'change' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $dry    = Args::bool( $arguments, 'dry_run' );
        $token  = Args::string( $arguments, 'token', false, 8000 );
        $parsed = ProductFilter::parse( $arguments );
        if ( null === $parsed['spec'] ) {
            self::refuse( $parsed['reasons'] );
        }
        $spec   = $parsed['spec'];
        $writer = $this->writer();

        if ( ! $writer->isActive() ) {
            throw new \InvalidArgumentException( 'WooCommerce is not active on this site.' );
        }
        if ( ! current_user_can( 'edit_products' ) ) {
            throw new \InvalidArgumentException( 'You need the edit_products permission to change products.' );
        }
        $missing = [];
        foreach ( [ 'category' => [ 'product_cat', 'categories' ], 'tag' => [ 'product_tag', 'tags' ] ] as $word => [ $taxonomy, $key ] ) {
            if ( [] !== $spec['filter'][ $key ] ) {
                foreach ( $writer->termIds( $taxonomy, $spec['filter'][ $key ] )['missing'] as $slug ) {
                    $missing[] = "{$word} \"{$slug}\" does not exist";
                }
            }
        }
        if ( [] !== $missing ) {
            self::refuse( $missing );
        }

        $now      = ( $this->clock )();
        $userId   = (int) get_current_user_id();
        $argsHash = Canon::hash( $spec );
        $map      = null;
        if ( ! $dry ) {
            $map = null === $token ? null : PreviewToken::open( $token, $argsHash, $now, $userId );
            if ( null === $map ) {
                throw new \InvalidArgumentException( self::NEEDS_TOKEN );
            }
        }

        // Which products: what the filter matches now; a real run also takes the products the preview listed.
        $found = $writer->findProducts( $spec['filter'], self::MAX_PRODUCTS );
        $ids   = array_values( array_filter( array_map( 'intval', $found['ids'] ), static fn ( int $id ): bool => current_user_can( 'edit_post', $id ) ) );
        if ( $dry && ( $found['truncated'] || count( $ids ) > self::MAX_PRODUCTS ) ) {
            self::refuse( [ 'More than ' . self::MAX_PRODUCTS . ' products match this filter (or too many to check); narrow it with categories, tags, types, ids or search and try again' ] );
        }
        $candidates = $ids;
        if ( null !== $map ) {
            $candidates = array_values( array_unique( array_merge( $ids, array_keys( $map ) ) ) );
            sort( $candidates );
        }

        $plans        = [];
        $skipped      = [];
        $unchanged    = [];
        $notInPreview = 0;
        $fields       = 0;
        foreach ( $candidates as $i => $id ) {
            $current = $this->load( $id, $writer );
            $inMap   = null !== $map && isset( $map[ $id ] );
            if ( null === $current ) {
                if ( $inMap ) {
                    $skipped[] = [ 'id' => $id, 'reason' => self::CHANGED ];
                } elseif ( null === $map ) {
                    $skipped[] = [ 'id' => $id, 'name' => '', 'reason' => 'not_found', 'detail' => self::REASONS['not_found'] ];
                }
                continue;
            }
            $hash = substr( Canon::hash( $current ), 0, 32 );
            if ( $inMap && $map[ $id ] !== $hash ) {
                $skipped[] = [ 'id' => $id, 'reason' => self::CHANGED ];
                continue;
            }
            $name = ProductUpdateRun::cut( (string) $current['name'] );
            $adj  = self::adjustment( $current, $spec['change'], $writer->priceDecimals(), $spec['round_to'] );
            if ( 'skip' === $adj['status'] ) {
                $skipped[] = [ 'id' => $id, 'name' => $name, 'reason' => $adj['reason'], 'detail' => self::REASONS[ $adj['reason'] ] ] + ( isset( $adj['rows'] ) ? [ 'rows' => $adj['rows'] ] : [] );
                continue;
            }
            if ( 'unchanged' === $adj['status'] ) {
                $unchanged[] = $id;
                continue;
            }
            if ( null !== $map && ! $inMap ) {
                $notInPreview++; // it matches now but was not previewed: never touched
                continue;
            }
            $fields += $adj['fields'];
            $plans[] = [
                'id'       => $id,
                'label'    => 'Product ' . ( $i + 1 ) . " (#{$id} \"{$name}\")",
                'name'     => (string) $current['name'],
                'hash'     => $hash,
                'current'  => $current,
                'changes'  => $adj['changes'],
                'previous' => ProductFields::pick( $current, ProductFields::tracked( $adj['changes'] ) ),
                'display'  => ProductUpdateRun::display( $adj['changes'], $current ),
            ];
        }
        if ( $fields > self::MAX_FIELDS ) {
            self::refuse( [ "This adjustment would change {$fields} prices; the limit is " . self::MAX_FIELDS . ' per call, so narrow the filter' ] );
        }

        if ( $dry ) {
            return $this->preview( $plans, $skipped, $unchanged, count( $ids ), $argsHash, $now, $userId );
        }
        PreviewToken::spend( (string) $token );
        if ( [] === $plans ) {
            return [ 'changed' => false, 'dry_run' => false, 'updated' => [], 'skipped' => $skipped, 'unchanged' => $unchanged, 'not_in_preview' => $notInPreview, 'message' => 'Nothing was changed.' ];
        }

        return ( new ProductUpdateRun( $this->log, $writer ) )->execute( $plans, $skipped, $unchanged, self::NAME, 'Adjusted prices of %d product(s)', [ 'not_in_preview' => $notInPreview ] );
    }

    /**
     * What the change does to one product's regular price(s). A variable product is adjusted as a whole or not at all.
     *
     * @param array<string,mixed> $current ProductWriter::product()
     * @param array{mode:string, value:string} $change
     * @return array{status: string, reason?: string, rows?: list<array{id:int, reason:string, detail:string}>, changes?: array<string,mixed>, fields?: int}
     */
    private static function adjustment( array $current, array $change, int $decimals, ?string $roundTo ): array
    {
        if ( 'variable' === $current['type'] ) {
            if ( [] === $current['variations'] ) {
                return [ 'status' => 'skip', 'reason' => 'no_variations' ];
            }
            $changes = [];
            $bad     = [];
            foreach ( $current['variations'] as $vid => $row ) {
                $r = PriceAdjuster::adjust( (string) $row['regular_price'], (string) $row['sale_price'], $change['mode'], $change['value'], $decimals, $roundTo );
                if ( null !== $r['reason'] ) {
                    $bad[] = [ 'id' => (int) $vid, 'reason' => $r['reason'], 'detail' => self::REASONS[ $r['reason'] ] ];
                } elseif ( ! PriceAdjuster::same( (string) $r['price'], (string) $row['regular_price'] ) ) {
                    $changes[ (int) $vid ] = [ 'regular_price' => $r['price'] ];
                }
            }
            if ( [] !== $bad ) {
                return [ 'status' => 'skip', 'reason' => 'variation_blocked', 'rows' => $bad ];
            }

            return [] === $changes ? [ 'status' => 'unchanged' ] : [ 'status' => 'change', 'changes' => [ 'variations' => $changes ], 'fields' => count( $changes ) ];
        }
        $r = PriceAdjuster::adjust( (string) $current['regular_price'], (string) $current['sale_price'], $change['mode'], $change['value'], $decimals, $roundTo );
        if ( null !== $r['reason'] ) {
            return [ 'status' => 'skip', 'reason' => $r['reason'] ];
        }
        if ( PriceAdjuster::same( (string) $r['price'], (string) $current['regular_price'] ) ) {
            return [ 'status' => 'unchanged' ];
        }

        return [ 'status' => 'change', 'changes' => [ 'regular_price' => $r['price'] ], 'fields' => 1 ];
    }

    /** @return ?array<string,mixed> the product's state, or null when it is gone, not editable by this user or not adjustable */
    private function load( int $id, ProductWriter $writer ): ?array
    {
        $type = $writer->typeOf( $id );
        if ( null === $type || ! current_user_can( 'edit_post', $id ) ) {
            return null;
        }

        return $writer->product( $id );
    }

    /**
     * @param list<array<string,mixed>> $plans
     * @param list<array<string,mixed>> $skipped
     * @param list<int> $unchanged
     * @return array<string,mixed>
     */
    private function preview( array $plans, array $skipped, array $unchanged, int $matched, string $argsHash, int $now, int $userId ): array
    {
        $map      = [];
        $products = [];
        foreach ( $plans as $plan ) {
            $map[ $plan['id'] ] = $plan['hash'];
            $row                = [ 'id' => $plan['id'], 'name' => ProductUpdateRun::cut( $plan['name'] ), 'type' => (string) $plan['current']['type'], 'changes' => $plan['display']['fields'] ];
            if ( [] !== $plan['display']['variations'] ) {
                $row['variations'] = $plan['display']['variations'];
            }
            $products[] = $row;
        }
        $out = [
            'changed'   => false,
            'dry_run'   => true,
            'summary'   => [ 'matched' => $matched, 'would_change' => count( $plans ), 'skipped' => count( $skipped ), 'unchanged' => count( $unchanged ) ],
            'products'  => $products,
            'skipped'   => $skipped,
            'unchanged' => $unchanged,
        ];
        if ( [] === $plans ) {
            return $out + [ 'message' => 'Nothing to adjust: no matched product would change. There is nothing to apply.' ];
        }

        return $out + [
            'token'   => PreviewToken::make( $argsHash, $map, $now, $userId ),
            'message' => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to apply it (it works once, within 30 minutes).',
        ];
    }

    /** @param list<string> $reasons */
    private static function refuse( array $reasons ): never
    {
        $message = 'Nothing was changed. ' . count( $reasons ) . ' problem(s): ' . implode( '; ', $reasons ) . '.';
        if ( mb_strlen( $message ) > self::MAX_MESSAGE ) {
            $message = mb_substr( $message, 0, self::MAX_MESSAGE - 3 ) . '...';
        }
        throw new \InvalidArgumentException( $message );
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
require_once __DIR__ . '/Tools/UpdateProducts.php';

```

with:

```php
require_once __DIR__ . '/Tools/UpdateProducts.php';
require_once __DIR__ . '/Tools/BulkAdjustPrices.php';

```

- [ ] **Step 5: Run the tool tests and the suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter BulkAdjustPricesTest` - Expected: `OK (25 tests, 169 assertions)`.
Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK`.

- [ ] **Step 6: RED proofs (opus reviewer: do every one, then revert)**

Each mutation is in `BulkAdjustPrices.php`; the named tests must FAIL with it (counts are from the scratch run over `BulkAdjustPricesTest|PriceAdjusterTest|ProductFilterTest`):

| Mutation | Must fail |
|---|---|
| `if ( $inMap && $map[ $id ] !== $hash ) {` -> `if ( false ) {` | `testAProductChangedSinceThePreviewIsSkippedAndTheOthersProceed` and 2 more |
| delete `PreviewToken::spend( (string) $token );` | `testARealRunNeedsAGenuineUnusedTokenForTheSameArgumentsByTheSameUserInTime` and 1 more |
| `if ( null !== $map && ! $inMap ) {` -> `if ( false ) {` | `testAProductThatMatchesNowButWasNotPreviewedIsNeverTouched` |
| `if ( $fields > self::MAX_FIELDS ) {` -> `if ( false ) {` | `testMoreThanFourHundredPricesIsRefused` |
| delete the `if ( [] !== $bad ) { return ... 'variation_blocked' ...; }` block | `testAVariableProductIsAdjustedAsAWholeOrNotAtAll` and 1 more |
| `$dry && ( $found['truncated'] || count( $ids ) > self::MAX_PRODUCTS )` -> `false` | `testMoreThanOneHundredMatchesIsRefusedNotTruncated` |

- [ ] **Step 7: Commit**

```bash
git add pro-addon/src/Tools/BulkAdjustPrices.php pro-addon/tests/BulkAdjustPricesTest.php pro-addon/src/autoload.php
git commit -m "$(cat <<'EOF'
feat(pro): bulk_adjust_prices tool (filter + percent/amount, preview + token, skip with reasons, shared apply engine)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Wiring, version 0.12.0 and docs

**Files:**
- Modify: `pro-addon/src/Tools.php`, `pro-addon/src/Rest/ProOpenApi.php`
- Modify (pinned tests): `pro-addon/tests/ToolsTest.php`, `pro-addon/tests/ProOpenApiTest.php`, `pro-addon/tests/ExpiryKeepsAllToolsTest.php`, `pro-addon/tests/bootstrap.php`
- Modify (version/docs): `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`, `pro-addon/readme.txt`, `pro-addon/README.md`, `CLAUDE.md`

**Interfaces:**
- Consumes: `BulkAdjustPrices` (Task 5).
- Produces: the registry exposes 21 Pro tools; `ProOpenApi::MCP_REST_ONLY` has seven entries ending in `bulk_adjust_prices`; `AIED_PRO_VERSION` is `0.12.0`.

The pinned tests are changed FIRST, so Step 2 shows them failing.

- [ ] **Step 1: Update the pinned tests**

In `pro-addon/tests/ToolsTest.php` (testThisReleaseShipsExactlyTheseTools), replace this exact text:

```php
'create_product', 'update_products' ],
            array_map
```

with:

```php
'create_product', 'update_products', 'bulk_adjust_prices' ],
            array_map
```

In `pro-addon/tests/ToolsTest.php` (the comment in testTheChatGptOperationBudgetIsRespected), replace this exact text:

```php
The 15th to 20th Pro tools, manage_global_colors, manage_global_variables, manage_global_presets, set_custom_fields, create_product and update_products, are
```

with:

```php
The 15th to 21st Pro tools, manage_global_colors, manage_global_variables, manage_global_presets, set_custom_fields, create_product, update_products and bulk_adjust_prices, are
```

In `pro-addon/tests/ToolsTest.php` (the pinned MCP-only list), replace this exact text:

```php
'create_product', 'update_products' ], ProOpenApi::MCP_REST_ONLY
```

with:

```php
'create_product', 'update_products', 'bulk_adjust_prices' ], ProOpenApi::MCP_REST_ONLY
```

In `pro-addon/tests/ProOpenApiTest.php`, replace this exact text:

```php
        $this->assertArrayNotHasKey( '/pro/update_products', $paths, 'MCP + REST only: no room in the 30-operation ChatGPT spec' );

```

with:

```php
        $this->assertArrayNotHasKey( '/pro/update_products', $paths, 'MCP + REST only: no room in the 30-operation ChatGPT spec' );
        $this->assertArrayNotHasKey( '/pro/bulk_adjust_prices', $paths, 'MCP + REST only: no room in the 30-operation ChatGPT spec' );

```

In `pro-addon/tests/ExpiryKeepsAllToolsTest.php` change the three places that say 20 to 21: the docblock line `Verify that all 20 Pro tools are callable in lapsed states` (-> `all 21 Pro tools`), `$this->assertCount( 20, $names );` (-> `21`) and the message `'all 20 Pro tools are advertised'` (-> `'all 21 Pro tools are advertised'`).

In `pro-addon/tests/bootstrap.php` change `define( 'AIED_PRO_VERSION', '0.11.0' );` to `'0.12.0'`.

- [ ] **Step 2: Run the suite and see the expected failures**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml`
Expected failures (and only these): five tests of `ExpiryKeepsAllToolsTest` (the count is now 21), `ReadmeTest::testTheStableTagIsTheCurrentVersion`, `ToolsTest::testThisReleaseShipsExactlyTheseTools` and `ToolsTest::testTheChatGptOperationBudgetIsRespected`.

- [ ] **Step 3: Wire the tool**

In `pro-addon/src/Tools.php` (the use line, after AuditSite), replace this exact text:

```php
use AiEditorDivi5\Pro\Tools\AuditSite;

```

with:

```php
use AiEditorDivi5\Pro\Tools\AuditSite;
use AiEditorDivi5\Pro\Tools\BulkAdjustPrices;

```

In `pro-addon/src/Tools.php` (the list, after UpdateProducts), replace this exact text:

```php
            new UpdateProducts( $log ),

```

with:

```php
            new UpdateProducts( $log ),
            new BulkAdjustPrices( $log ),

```

In `pro-addon/src/Rest/ProOpenApi.php`, replace this exact text:

```php
'create_product', 'update_products' ];
```

with:

```php
'create_product', 'update_products', 'bulk_adjust_prices' ];
```

- [ ] **Step 4: Version 0.12.0 and the add-on's readme**

In `pro-addon/jhmg-ai-editor-for-divi-5-pro.php` change the header line ` * Version:           0.11.0` to `0.12.0` and `define( 'AIED_PRO_VERSION', '0.11.0' );` to `'0.12.0'`.

**Edit 1 of 3 in `pro-addon/readme.txt`** - replace this exact text:

```text
Contributors:      lucaslopvet
Requires at least: 6.0
Requires PHP:      8.1
Stable tag:        0.11.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

```

with:

```text
Contributors:      lucaslopvet
Requires at least: 6.0
Requires PHP:      8.1
Stable tag:        0.12.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

```

**Edit 2 of 3 in `pro-addon/readme.txt`** - replace this exact text:

```text
* `set_custom_fields` - set custom field values on one page, Divi Project or other post you can edit (only keys your site already uses; never protected or secret keys, code, or ACF lists and images; all or nothing; `dry_run` previews; `undo_batch` puts the values back as long as nothing changed them afterwards).
* `create_product` - create up to 25 new simple WooCommerce products per call as drafts (never published): name, descriptions, SKU, regular and sale price, stock, backorders, existing categories and tags, a main image and gallery from your Media Library, weight, dimensions, tax status, virtual and sold individually. Needs WooCommerce. Everything is checked first and nothing is created unless every product passes (unknown categories, tags or images are refused, never created); each saved product is read back and compared, and a difference removes what the call created. `dry_run` previews; one `undo_batch` moves the created drafts to the trash unless you edited, published or trashed one since. It never changes an existing product.
* `update_products` - change existing WooCommerce products (simple, external and variable): regular and sale price with a sale schedule, stock, name, descriptions, SKU, existing categories and tags, images, weight, dimensions, tax status, and the price and stock of a variable product's variation rows. Needs WooCommerce. It changes live products, so every real run needs the token from a preview (`dry_run`), which shows each change as before and after; a product that changed since the preview is skipped, never overwritten. Everything is checked first and each saved product is read back and compared; a failure puts every product the call touched back. It never publishes, unpublishes, creates or deletes a product. One `undo_batch` puts the previous values back unless you changed those fields since.
* `list_batches` - list recent Pro changes.
* `undo_batch` - undo one batch.

Every tool is also available over REST (`POST /wp-json/ai-editor-divi5/v1/pro/<tool>`) and, except `manage_global_colors`, `manage_global_variables`, `manage_global_presets`, `set_custom_fields`, `create_product` and `update_products` (MCP and REST only), in the free plugin's ChatGPT OpenAPI spec, with the same permission checks. The Pro tools stay off, over every transport, until the licence is unlocked.

Each tool checks the permission it really needs (for example `manage_options` to set the front page), not only that the request is authenticated.

```

with:

```text
* `set_custom_fields` - set custom field values on one page, Divi Project or other post you can edit (only keys your site already uses; never protected or secret keys, code, or ACF lists and images; all or nothing; `dry_run` previews; `undo_batch` puts the values back as long as nothing changed them afterwards).
* `create_product` - create up to 25 new simple WooCommerce products per call as drafts (never published): name, descriptions, SKU, regular and sale price, stock, backorders, existing categories and tags, a main image and gallery from your Media Library, weight, dimensions, tax status, virtual and sold individually. Needs WooCommerce. Everything is checked first and nothing is created unless every product passes (unknown categories, tags or images are refused, never created); each saved product is read back and compared, and a difference removes what the call created. `dry_run` previews; one `undo_batch` moves the created drafts to the trash unless you edited, published or trashed one since. It never changes an existing product.
* `update_products` - change existing WooCommerce products (simple, external and variable): regular and sale price with a sale schedule, stock, name, descriptions, SKU, existing categories and tags, images, weight, dimensions, tax status, and the price and stock of a variable product's variation rows. Needs WooCommerce. It changes live products, so every real run needs the token from a preview (`dry_run`), which shows each change as before and after; a product that changed since the preview is skipped, never overwritten. Everything is checked first and each saved product is read back and compared; a failure puts every product the call touched back. It never publishes, unpublishes, creates or deletes a product. One `undo_batch` puts the previous values back unless you changed those fields since.
* `bulk_adjust_prices` - raise or lower the regular prices of every product a filter matches (categories, tags, types, stock status, on sale or not, name search, product ids, and which statuses; published products only unless you say otherwise) by a percent or a fixed amount. Needs WooCommerce. Sale prices are never touched. Prices are calculated exactly (no floating point), rounded to your shop's decimals, and optionally made to end in what you choose (for example `0.95`). A product that cannot be adjusted (no regular price, a price at or below its sale price, a price of zero or less) is skipped with a reason and the rest proceed; a variable product is adjusted as a whole or not at all. It changes live prices, so every real run needs the token from a preview (`dry_run`), which lists every product with its price before and after; a product that changed since the preview is skipped, never overwritten. A call adjusts at most 100 products. A failed save puts every product the call touched back. One `undo_batch` puts the previous prices back unless you changed them since.
* `list_batches` - list recent Pro changes.
* `undo_batch` - undo one batch.

Every tool is also available over REST (`POST /wp-json/ai-editor-divi5/v1/pro/<tool>`) and, except `manage_global_colors`, `manage_global_variables`, `manage_global_presets`, `set_custom_fields`, `create_product`, `update_products` and `bulk_adjust_prices` (MCP and REST only), in the free plugin's ChatGPT OpenAPI spec, with the same permission checks. The Pro tools stay off, over every transport, until the licence is unlocked.

Each tool checks the permission it really needs (for example `manage_options` to set the front page), not only that the request is authenticated.

```

**Edit 3 of 3 in `pro-addon/readme.txt`** - replace this exact text:

```text
Deleting the plugin removes its own data (licence state, batch history, saved code proposals). It does not undo your site's front page, menu, CSS, page statuses, header or footer content or Theme Builder templates: undo those first if you want them back.

== Changelog ==

= 0.11.0 =
* New: `update_products` changes existing WooCommerce products: prices and sale schedules, stock, texts, SKU, categories, tags, images, weight, dimensions and tax status of simple, external and variable products, and the price and stock of a variable product's variation rows. Up to 25 products per call. Every real run needs the single-use token of a preview (`dry_run`) that shows each change as before and after; a product that changed since the preview is skipped. Checked before anything is saved, read back after, and put back if anything fails. It never changes a product's status, type or slug and never creates or deletes one. One `undo_batch` restores the previous values unless you changed those fields since.
```

with:

```text
Deleting the plugin removes its own data (licence state, batch history, saved code proposals). It does not undo your site's front page, menu, CSS, page statuses, header or footer content or Theme Builder templates: undo those first if you want them back.

== Changelog ==

= 0.12.0 =
* New: `bulk_adjust_prices` raises or lowers the regular prices of every product a filter matches by a percent or a fixed amount: exact decimal arithmetic, your shop's rounding, an optional "end in" such as `0.95`, a preview with every price before and after, a single-use token for the real run, products that cannot be adjusted skipped with a reason, products changed since the preview skipped, a rollback if anything fails to save, and one undo. At most 100 products per call. It never changes sale prices, statuses or anything else.
* Over MCP and REST only: this is the 21st Pro tool, and the ChatGPT importer's 30 operations are used up.

= 0.11.0 =
* New: `update_products` changes existing WooCommerce products: prices and sale schedules, stock, texts, SKU, categories, tags, images, weight, dimensions and tax status of simple, external and variable products, and the price and stock of a variable product's variation rows. Up to 25 products per call. Every real run needs the single-use token of a preview (`dry_run`) that shows each change as before and after; a product that changed since the preview is skipped. Checked before anything is saved, read back after, and put back if anything fails. It never changes a product's status, type or slug and never creates or deletes one. One `undo_batch` restores the previous values unless you changed those fields since.
```

- [ ] **Step 5: `pro-addon/README.md`**

Make these edits (use the Edit tool; each old text is unique):
1. In the `src/ProTool.php ...` table row: `lists the twenty tools` -> `lists the twenty-one tools`.
2. In the `src/Tools/` row: `the twenty tools:` -> `the twenty-one tools:` and append `, \`bulk_adjust_prices\`` after `` `update_products` `` at the end of the list.
3. In the `src/Products/` row (the one that names ProductFields and ProductDiff) add `PriceAdjuster`, `ProductFilter` and `ProductUpdateRun` with a few words each (exact maths; the filter parser; the shared apply engine of both write tools).
4. Insert this section immediately BEFORE the line `## Known limits`:

```markdown
## Bulk price changes (`bulk_adjust_prices`)

The tool raises or lowers the REGULAR prices of every simple, external and variable WooCommerce product a filter matches, by a percent or a fixed amount. It needs WooCommerce active, `edit_products`, `edit_post` on every product it touches and `edit_posts` like the other Pro tools. Arguments: `filter`, `change`, an optional `round_to`, a strict boolean `dry_run` and a `token`.

| Argument | Rule |
|---|---|
| `filter` | at least one of `categories` (slugs, children included), `tags`, `types` (`simple`, `variable`, `external`), `stock_status`, `on_sale`, `search` (words in the name), `ids`; or `"all": true` (which cannot be combined with those). `exclude_ids` and `status` (default `["publish"]`: drafts and private products are only changed when asked for) refine it but do not count as narrowing. Keys combine with AND, the values inside one list with OR. |
| `change` | `{ "mode": "percent", "value": "-10" }` (a signed decimal STRING, at most 2 decimals, from -90 to 500) or `{ "mode": "amount", "value": "-5" }` (at most 6 decimals, at most 100000 either way). Never zero, never a number, a `%` sign or a currency symbol. |
| `round_to` | what new prices should end in, for example `"0.95"` or `"0.5"`; `"0"` or `"1"` for whole numbers. The nearest such price is used, a tie goes up. Without it prices are rounded half up to the shop's number of decimals. |

Only the regular price changes: a simple or external product's own, and every variation row of a variable product. Sale prices, statuses and everything else are never touched. Grouped products are never matched. The arithmetic is exact (no floating point). A matched product that cannot be adjusted is skipped with a reason and the rest proceed: `no_regular_price`, `not_positive` (the new price would be zero or below), `too_large`, `not_above_sale` (the new regular price must stay above an active sale price), `rounding_reversed` (rounding would move the price the opposite way to the change), `no_variations`, and `variation_blocked` for a variable product with a row that cannot be adjusted (such a product is adjusted as a whole or not at all; the answer lists the rows). A price that comes out the same is reported as unchanged.

**Preview and token.** `dry_run: true` validates everything and returns a summary, every product that would change with its price before and after (variation rows by id), the skipped products with reasons, and ONE `token` (none when nothing would change). A real run needs that token (single use, 30 minutes, same user, same normalised request). The token fixes the list of products and a hash of each product's whole current state: a product that changed since is SKIPPED (`changed_since_preview`) and the others proceed; a product that matches the filter now but was not previewed is not touched and is counted as `not_in_preview`. A refused run keeps its token; it is spent once all checks pass, just before anything is saved.

**Limits.** At most 100 matched products and 400 changed prices per call. A bigger match is refused with a message to narrow the filter; it is never cut off silently. When `on_sale` is used, at most 500 candidate products are examined, and more than that is refused the same way.

**Apply, read back, roll back, undo.** The same engine as `update_products` (`ProductUpdateRun`): products are saved one at a time and each is read back and compared; a failed save or a difference puts every product the run touched back and records NO batch. A real run records ONE batch of `product_fields` items holding the previous regular prices; `undo_batch` restores them while they still hold what the run wrote, and refuses (`conflicts`) when restoring a price would make WooCommerce discard a sale price added since.

MCP + REST only: the 30-operation ChatGPT budget is used up, so `bulk_adjust_prices` is the seventh tool in `ProOpenApi::MCP_REST_ONLY`. Spec: `docs/superpowers/specs/2026-10-13-pro-bulk-adjust-prices-design.md`; live gate: `make verify-pro-bulk-adjust-prices`.

```
5. In the `Known limits` bullet about the ChatGPT importer: `The 15th to 20th Pro tools, ... \`create_product\` and \`update_products\`, are MCP + REST only` -> `The 15th to 21st Pro tools, ... \`create_product\`, \`update_products\` and \`bulk_adjust_prices\`, are MCP + REST only`.

- [ ] **Step 6: `CLAUDE.md`**

1. In "The Pro add-on" bullet: `0.11.0: compat guard` -> `0.12.0: compat guard`; `registry with 20 tools` -> `registry with 21 tools`; append `, bulk regular-price changes over a filter (\`bulk_adjust_prices\`)` after `changing existing WooCommerce products (\`update_products\`)`.
2. In the entry-points table, directly after the `make verify-pro-update-products` row add:
   `| \`make verify-pro-bulk-adjust-prices\` | Real-site check of bulk_adjust_prices with the real WooProductWriter and WooCommerce's own catalogue query: preview/token, percent and amount changes with the shop's rounding and round_to, the filter (category, draft status, on_sale, ids, the 100-product cap), skip reasons, variable products (parent price follows), a product changed since the preview, a new match left alone, rollback on a failing or lying writer, one undo (and undo refused with conflicts), editor/shop-manager permissions; originals byte-identical (self-cleaning; dev site only; free + Pro + WooCommerce active; evicts every batch row on the dev site) |`
3. In "Current state", add as the FIRST bullet: `- **Unreleased (branch feat/pro-bulk-adjust-prices): Pro 0.12.0 bulk_adjust_prices** (sub-project B, third tool): raises or lowers the REGULAR prices of the products a filter matches (categories, tags, types, stock status, on sale, name search, ids; published only by default) by a percent or an amount with exact decimal arithmetic, the shop's rounding and an optional "end in" (round_to); preview with every price before/after and a single-use PreviewToken over the matched list and per-product state hashes (a product changed since is skipped, a new match is left alone); products that cannot be adjusted are skipped with a reason (a variable product as a whole); at most 100 products / 400 prices per call; the apply, read-back, rollback and batch code is the shared ProductUpdateRun that update_products also uses; undo is the existing product_fields kind; MCP + REST only (ProOpenApi::MCP_REST_ONLY); never touches sale prices, status or anything else; spec/plan docs/superpowers/specs|plans/2026-10-13-pro-bulk-adjust-prices*; live gate make verify-pro-bulk-adjust-prices. Release (store upload, divi5lab.com copy 21 Pro tools) is the owner's step.`
4. In the "Unreleased (branch feat/pro-update-products)" bullet replace `bulk_adjust_prices is next.` with `bulk_adjust_prices is built (bullet above).` if that sentence is there; otherwise leave the bullet alone.

- [ ] **Step 7: Run everything**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK (2138 tests, ...)` (measured in a scratch copy; if yours differs by a few, what matters is **0 failures, 0 errors** - say so).
Run: `./vendor/bin/phpunit` (the free suite) - Expected: `OK (1056 tests ...)` unchanged.
Run: `make build-pro` - Expected: `[build-pro] Packaging jhmg-ai-editor-for-divi-5-pro version 0.12.0` and a zip in `dist/` (git-ignored).

- [ ] **Step 8: Commit**

```bash
git add pro-addon/src/Tools.php pro-addon/src/Rest/ProOpenApi.php pro-addon/tests/ToolsTest.php pro-addon/tests/ProOpenApiTest.php pro-addon/tests/ExpiryKeepsAllToolsTest.php pro-addon/tests/bootstrap.php pro-addon/jhmg-ai-editor-for-divi-5-pro.php pro-addon/readme.txt pro-addon/README.md CLAUDE.md
git commit -m "$(cat <<'EOF'
feat(pro): register bulk_adjust_prices (21st Pro tool, MCP + REST only), version 0.12.0, docs

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: The live gate `make verify-pro-bulk-adjust-prices`

**Files:**
- Create: `scripts/verify-pro-bulk-adjust-prices.php`
- Modify: `Makefile` (`.PHONY` list and a new target), `pro-addon/tests/DevGuardTest.php` (14 -> 15 scripts)

**Interfaces:**
- Consumes: the finished tool (Task 5), the free plugin's `ProductService::get` / `WooProductGateway` (read path), `BatchTable`, `Batches`, `scripts/dev-guard.php`, the writer's `findProducts()` on the REAL catalogue query.
- Produces: a self-cleaning WP-CLI gate. It creates its OWN throwaway products (simple, with a sale price, without a price, a draft, external, variable with two variations one on sale, an outsider in another category, 101 cheap products for the cap), three categories and two users, and edits only those; the products the site already has must be byte-identical afterwards.

**Rules for this task (important):**
- The gate runs on the Docker dev site only (`scripts/dev-guard.php`). WooCommerce, the free plugin and the Pro add-on must be active (`make up` first; never `wp plugin delete` anything). WooCommerce must still be active afterwards.
- A **FAIL is a real finding** about the tool or the writer: report it with the output; never edit the gate to make it pass, and do not change production code on your own. Fix a defect in the gate only when it is clearly the script's own (a typo, a wrong helper, a wrong key of the free `get_product` output...); say exactly what and why. A **NOTE** records a WooCommerce behaviour the design leaves open.
- The script was written without WooCommerce in front of it. Expect to run it, read the first failures, and decide script bug versus product bug each time. The things most likely to need a script fix: the keys of the free `get_product` output, whether the dev shop shows 2 price decimals (the gate says so in its second check and assumes it), the variation attribute shape, and how WooCommerce's query treats `on_sale` for a variable product (the check expects the variable product to count as on sale when one variation is).
- If the Docker site is not available, stop and report BLOCKED; do not skip the task.

- [ ] **Step 1: Create the script**

Create `scripts/verify-pro-bulk-adjust-prices.php`:

```php
<?php
/**
 * Real-site check of the Pro tool bulk_adjust_prices: the real tool with the REAL WooProductWriter (WooCommerce's own CRUD classes, its
 * real catalogue query), the real batch table and undo, and the free plugin's own read path (ProductService::get) plus WooCommerce's
 * getters, so what the tool calculated is compared with what WooCommerce stored through independent code paths. It proves: a dry run
 * changes nothing and returns a token; a real run needs the single-use token; percent and amount changes are applied with the shop's
 * rounding and with round_to; the filter finds what it should (category, draft status, ids, the 100-product cap); sale prices are never
 * touched; products that cannot be adjusted are skipped with the right reason, a variable product as a whole; a product changed between
 * preview and run is skipped and one that newly matches is left alone; a failing writer and a writer that saves something else put
 * every product back and record no batch; one undo restores every price exactly (and refuses, reason conflicts, when a sale price added
 * since would be discarded); an editor without edit_products is refused; and the products the site already has are byte-identical afterwards.
 *
 * Self-cleaning: it force-deletes every product it created (SKU AIED-BA-*, or name "AIED BA ..."; also trashed ones), its product
 * categories (slugs aied-ba-*), its throwaway users (aied_pro_ba_*) and the batch rows it recorded. It records many batches and the
 * batch store keeps only the newest 20, so it evicts EVERY batch row already on the dev site: dev sites only.
 *
 * Run: make verify-pro-bulk-adjust-prices   (needs `make up`, WooCommerce, the free plugin and the Pro add-on active; no licence is
 * needed: the tool is called directly, not through the licence-gated registry)
 *
 * A FAIL is a finding about the tool or the writer: report it, never edit this script to hide it. A NOTE records a behaviour of
 * WooCommerce that the design leaves open.
 */

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Products\ProductWriter;
use AiEditorDivi5\Pro\Products\WooProductWriter;
use AiEditorDivi5\Pro\Tools\BulkAdjustPrices;
use AiEditorDivi5\WP\ProductService;
use AiEditorDivi5\WP\WooProductGateway;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( BulkAdjustPrices::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5-pro (with bulk_adjust_prices) on this site first.' );
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

const AIED_BA_SKU    = 'AIED-BA-';
const AIED_BA_NAME   = 'AIED BA ';
const AIED_BA_SLUG   = 'aied-ba-';
const AIED_BA_USERS  = [ 'editor' => 'aied_pro_ba_editor', 'shop_manager' => 'aied_pro_ba_shop' ];
const AIED_BA_SECRET = '/var/www/html/secret.php';

/** Decorates the real writer: the Nth apply() throws a foreign exception with a path in it, or one field is silently put back after a save. */
final class AiedBaFailingWriter implements ProductWriter
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
    public function findProducts( array $criteria, int $limit ): array { return $this->real->findProducts( $criteria, $limit ); }
    public function priceDecimals(): int { return $this->real->priceDecimals(); }

    public function apply( int $id, array $changes ): void
    {
        if ( $this->failOn === ++$this->applyCalls ) {
            throw new \RuntimeException( AIED_BA_SECRET . ' simulated writer failure' );
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
    foreach ( AIED_BA_USERS as $login ) {
        $u = get_user_by( 'login', $login );
        if ( $u instanceof \WP_User && $login === $u->user_login && (int) $u->ID > 1 ) {
            wp_delete_user( (int) $u->ID );
        }
    }
};
/** Every product or variation (any status, the trash too) this script could have made: SKU AIED-BA-* or name "AIED BA ...". */
$mine = static function (): array {
    global $wpdb;
    $bySku  = (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_sku' WHERE p.post_type IN ('product','product_variation') AND m.meta_value LIKE %s",
        $wpdb->esc_like( AIED_BA_SKU ) . '%'
    ) );
    $byName = (array) $wpdb->get_col( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_title LIKE %s",
        $wpdb->esc_like( AIED_BA_NAME ) . '%'
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
        $wpdb->esc_like( AIED_BA_NAME ) . '%'
    ) ) );
};
$myTerms = static function (): array {
    $out = [];
    foreach ( [ 'product_cat', 'product_tag' ] as $tax ) {
        $terms = get_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
        foreach ( is_array( $terms ) ? $terms : [] as $t ) {
            if ( str_starts_with( (string) $t->slug, AIED_BA_SLUG ) ) {
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
$tool   = new BulkAdjustPrices( $log );
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
$run = static function ( array $args, ?BulkAdjustPrices $with = null ) use ( &$tool, $track ): array {
    try {
        return [ $track( ( $with ?? $tool )->run( $args ) ), null ];
    } catch ( \InvalidArgumentException $e ) {
        return [ [], $e->getMessage() ];
    } catch ( \Throwable $e ) {
        return [ [], 'UNEXPECTED ' . get_class( $e ) . ': ' . $e->getMessage() ];
    }
};
/** Preview, then a real run with the token. Returns [ preview, result, error ]. */
$go = static function ( array $args, ?BulkAdjustPrices $with = null ) use ( $run ): array {
    [ $p, $perr ] = $run( $args + [ 'dry_run' => true ], $with );
    if ( null !== $perr || empty( $p['token'] ) ) {
        return [ $p, [], $perr ?? 'the preview made no token' ];
    }
    [ $r, $err ] = $run( $args + [ 'token' => $p['token'] ], $with );

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
    $cat   = wp_insert_term( AIED_BA_NAME . 'Cat', 'product_cat', [ 'slug' => AIED_BA_SLUG . 'cat' ] );
    $other = wp_insert_term( AIED_BA_NAME . 'Other', 'product_cat', [ 'slug' => AIED_BA_SLUG . 'other' ] );
    $many  = wp_insert_term( AIED_BA_NAME . 'Many', 'product_cat', [ 'slug' => AIED_BA_SLUG . 'many' ] );
    if ( is_wp_error( $cat ) || is_wp_error( $other ) || is_wp_error( $many ) ) {
        // Not WP_CLI::error(): it exits at once and would skip the cleanup in the `finally` below.
        throw new \RuntimeException( 'Could not create the throwaway categories.' );
    }
    $catId   = (int) $cat['term_id'];
    $otherId = (int) $other['term_id'];
    $manyId  = (int) $many['term_id'];
    $inCat   = static fn ( string $name, string $sku, array $props, int $cid = 0 ): int => $makeSimple( $name, $sku, $props + [ 'category_ids' => [ $cid > 0 ? $cid : $catId ] ] );

    $P1 = $inCat( AIED_BA_NAME . 'Hat', AIED_BA_SKU . '1', [ 'regular_price' => '20' ] );
    $P2 = $inCat( AIED_BA_NAME . 'Scarf', AIED_BA_SKU . '2', [ 'regular_price' => '19.99' ] );
    $P3 = $inCat( AIED_BA_NAME . 'Mug', AIED_BA_SKU . '3', [ 'regular_price' => '10', 'sale_price' => '8' ] );
    $P4 = $inCat( AIED_BA_NAME . 'Gift', AIED_BA_SKU . '4', [] );
    $P5 = $inCat( AIED_BA_NAME . 'Draft', AIED_BA_SKU . '5', [ 'regular_price' => '30', 'status' => 'draft' ] );
    $X  = $inCat( AIED_BA_NAME . 'Outsider', AIED_BA_SKU . 'X', [ 'regular_price' => '50' ], $otherId );

    $ext = new WC_Product_External();
    $ext->set_name( AIED_BA_NAME . 'External' );
    $ext->set_sku( AIED_BA_SKU . '6' );
    $ext->set_status( 'publish' );
    $ext->set_product_url( 'https://example.com/buy' );
    $ext->set_regular_price( '5' );
    $ext->set_category_ids( [ $catId ] );
    $P6 = (int) $ext->save();

    $var = new WC_Product_Variable();
    $var->set_name( AIED_BA_NAME . 'Variable' );
    $var->set_sku( AIED_BA_SKU . 'V' );
    $var->set_status( 'publish' );
    $var->set_category_ids( [ $catId ] );
    $attr = new WC_Product_Attribute();
    $attr->set_id( 0 );
    $attr->set_name( 'Size' );
    $attr->set_options( [ 'Small', 'Large' ] );
    $attr->set_position( 0 );
    $attr->set_visible( true );
    $attr->set_variation( true );
    $var->set_attributes( [ $attr ] );
    $V    = (int) $var->save();
    $vids = [];
    foreach ( [ 'Small' => [ '10', '' ], 'Large' => [ '12', '9' ] ] as $size => [ $price, $sale ] ) {
        $v = new WC_Product_Variation();
        $v->set_parent_id( $V );
        $v->set_attributes( [ 'size' => $size ] );
        $v->set_regular_price( $price );
        if ( '' !== $sale ) {
            $v->set_sale_price( $sale );
        }
        $v->set_status( 'publish' );
        $vids[ $size ] = (int) $v->save();
    }
    WC_Product_Variable::sync( $V );
    [ $vSmall, $vLarge ] = array_values( $vids );
    $check( $P1 > 0 && $P2 > 0 && $P3 > 0 && $P4 > 0 && $P5 > 0 && $P6 > 0 && $X > 0 && $V > 0 && $vSmall > 0 && $vLarge > 0 && $catId > 0 && $otherId > 0, 'the throwaway fixtures exist: six simple/external products (one without a price, one a draft), a variable product with two variations (one on sale), an outsider and categories' );
    $decimals = $writer->priceDecimals();
    $check( 2 === $decimals, "the shop shows prices with {$decimals} decimals (this gate's expectations assume 2)" );
    $allIds   = [ $P1, $P2, $P3, $P4, $P5, $P6, $X, $V ];
    $stateOf  = static function ( array $ids ) use ( $writer ): array {
        $out = [];
        foreach ( $ids as $id ) {
            clean_post_cache( $id );
            $out[ $id ] = $writer->product( $id );
        }

        return $out;
    };
    $fixtures = static fn (): string => md5( serialize( $stateOf( $allIds ) ) );
    $original = $stateOf( $allIds );
    $adjust   = static fn ( array $filter, string $mode, string $value, array $extra = [] ): array => [ 'filter' => $filter, 'change' => [ 'mode' => $mode, 'value' => $value ] ] + $extra;
    $inCatF   = [ 'categories' => [ AIED_BA_SLUG . 'cat' ] ];
    $undoLast = static function ( array $r ) use ( $undo, $trackUndo ): array {
        return $trackUndo( $undo->undo( (int) ( $r['batch']['id'] ?? 0 ) ) );
    };

    // ---- preview: percent +10 over the category -------------------------------------------------------------------------------------
    $newestBefore = $newestId();
    $fixBefore    = $fixtures();
    [ $p, $err ] = $run( $adjust( $inCatF, 'percent', '10' ) + [ 'dry_run' => true ] );
    $ids = array_map( 'intval', array_column( (array) ( $p['products'] ?? [] ), 'id' ) );
    $check( null === $err && false === ( $p['changed'] ?? null ) && [ $P1, $P2, $P3, $P6, $V ] === $ids, 'a dry run over the category previews the five products that can change (draft, outsider and the price-less product are not among them)' . ( null === $err ? '' : ': ' . $err ) );
    $skipIds = array_map( 'intval', array_column( (array) ( $p['skipped'] ?? [] ), 'id' ) );
    $check( [ $P4 ] === $skipIds && 'no_regular_price' === ( $p['skipped'][0]['reason'] ?? '' ) && 6 === ( $p['summary']['matched'] ?? 0 ), 'the product without a regular price is skipped (no_regular_price); six products matched' );
    $check( ! empty( $p['token'] ) && $fixBefore === $fixtures() && $newestBefore === $newestId() && ! isset( $p['batch'] ), 'a dry run returns a token, changes nothing and records no batch' );
    $token = (string) ( $p['token'] ?? '' );
    [ , $err ] = $run( $adjust( $inCatF, 'percent', '10' ) );
    $check( null !== $err && str_contains( $err, 'needs the token' ) && $fixBefore === $fixtures(), 'a real run without a token is refused and changes nothing' );
    [ , $err ] = $run( $adjust( $inCatF, 'percent', '11' ) + [ 'token' => $token ] );
    $check( null !== $err && str_contains( $err, 'needs the token' ) && $fixBefore === $fixtures(), 'the token of another request is refused' );

    // ---- the real run ---------------------------------------------------------------------------------------------------------------
    [ $r, $err ] = $run( $adjust( $inCatF, 'percent', '10' ) + [ 'token' => $token ] );
    $check( null === $err && true === ( $r['changed'] ?? null ) && 5 === count( (array) ( $r['updated'] ?? [] ) ) && true === ( $r['batch']['undoable'] ?? false ), 'a real run with the token updates the five products and records one undoable batch' . ( null === $err ? '' : ': ' . $err ) );
    $main = $r;
    $w1 = $readBack( $P1 );
    $w2 = $readBack( $P2 );
    $w3 = $readBack( $P3 );
    $w6 = $readBack( $P6 );
    $check( '22.00' === ( $w1['regular_price'] ?? null ) && '21.99' === ( $w2['regular_price'] ?? null ) && '11.00' === ( $w3['regular_price'] ?? null ) && '5.50' === ( $w6['regular_price'] ?? null ),
        'get_product (free) reads back the new regular prices: 20 -> 22.00, 19.99 -> 21.99 (21.989 rounded), 10 -> 11.00, external 5 -> 5.50' . ( '22.00' === ( $w1['regular_price'] ?? null ) ? '' : ': ' . wp_json_encode( [ $w1['regular_price'] ?? null, $w2['regular_price'] ?? null, $w3['regular_price'] ?? null, $w6['regular_price'] ?? null ] ) ) );
    $check( '8' === ( $w3['sale_price'] ?? null ) || '8.00' === ( $w3['sale_price'] ?? null ), 'a sale price is never touched (the mug still has its sale price 8)' );
    $check( '11.00' === $wc( $vSmall )['regular'] && '13.20' === $wc( $vLarge )['regular'] && '9' === rtrim( rtrim( $wc( $vLarge )['sale'], '0' ), '.' ), 'the variable product\'s variation rows moved (10 -> 11.00, 12 -> 13.20) and the sale price of the second is untouched' );
    clean_post_cache( $V );
    $parent = wc_get_product( $V );
    $check( 9.0 === (float) $parent->get_variation_price( 'min', true ) && 11.0 === (float) $parent->get_variation_price( 'max', true ), 'the variable parent\'s price range follows its rows (min 9 = the sale price, max 11)' );
    $check( 'draft' === $wc( $P5 )['post_status'] && '30' === $wc( $P5 )['regular'] && '50' === $wc( $X )['regular'] && '' === $wc( $P4 )['regular'], 'the draft, the outsider and the product without a price are untouched' );
    $check( 'publish' === $wc( $P1 )['post_status'] && $original[ $P1 ]['status'] === $wc( $P1 )['post_status'], 'status is unchanged' );
    [ , $err ] = $run( $adjust( $inCatF, 'percent', '10' ) + [ 'token' => $token ] );
    $check( null !== $err && str_contains( $err, 'needs the token' ), 'the token works once' );

    // ---- one undo restores every price exactly; the undo can be undone ------------------------------------------------------------------
    $u1 = $undoLast( $main );
    $check( 5 === ( $u1['restored'] ?? 0 ) && true === ( $u1['fully_undone'] ?? false ) && $original === $stateOf( $allIds ), 'undo_batch restores all five products and every product state equals its original' );
    $u2 = $trackUndo( $undo->undo( (int) ( $u1['undo_batch']['id'] ?? 0 ) ) );
    $check( 5 === ( $u2['restored'] ?? 0 ) && '22.00' === $wc( $P1 )['regular'], 'the undo can be undone' );
    $trackUndo( $undo->undo( (int) ( $u2['undo_batch']['id'] ?? 0 ) ) );
    $check( $original === $stateOf( $allIds ), 'and undone once more: the originals are back' );

    // ---- amount, round_to and the skip reasons -------------------------------------------------------------------------------------------
    [ $p, $r, $err ] = $go( $adjust( [ 'ids' => [ $P1, $P3 ] ], 'amount', '-5' ) );
    $check( null === $err && [ $P1 ] === array_map( 'intval', array_column( (array) ( $r['updated'] ?? [] ) , 'id' ) ) && '15.00' === $wc( $P1 )['regular'], 'an amount of -5 takes the hat from 20 to 15.00' . ( null === $err ? '' : ': ' . $err ) );
    $check( 'not_above_sale' === ( array_column( (array) ( $p['skipped'] ?? [] ), 'reason', 'id' )[ $P3 ] ?? '' ) && '10' === $wc( $P3 )['regular'], 'the mug (10 - 5 = 5, below its sale price 8) is skipped with not_above_sale and keeps its price' );
    $undoLast( $r );
    [ , $r, $err ] = $go( $adjust( [ 'ids' => [ $P1 ] ], 'percent', '10', [ 'round_to' => '0.95' ] ) );
    $check( null === $err && '21.95' === $wc( $P1 )['regular'], 'round_to 0.95 makes the new price end in .95 (22.00 -> 21.95)' . ( null === $err ? '' : ': ' . $err ) );
    $undoLast( $r );
    [ $p ] = $run( $adjust( [ 'ids' => [ $P1 ] ], 'amount', '-20', [ 'dry_run' => true ] ) );
    $check( 'not_positive' === ( $p['skipped'][0]['reason'] ?? '' ), 'a change that would make the price zero is skipped (not_positive)' );
    [ $p ] = $run( $adjust( [ 'ids' => [ $V ] ], 'amount', '-5', [ 'dry_run' => true ] ) );
    $check( 'variation_blocked' === ( $p['skipped'][0]['reason'] ?? '' ) && [ $vLarge ] === array_map( 'intval', array_column( (array) ( $p['skipped'][0]['rows'] ?? [] ), 'id' ) ) && empty( $p['token'] ), 'a variable product with one blocked row is skipped as a whole (12 - 5 = 7 is below the sale 9); the other row is not adjusted either' );

    // ---- the filter ---------------------------------------------------------------------------------------------------------------------
    [ $p ] = $run( $adjust( $inCatF, 'percent', '10', [ 'dry_run' => true ] ) );
    $check( ! in_array( $P5, array_map( 'intval', array_column( (array) ( $p['products'] ?? [] ), 'id' ) ), true ), 'only published products are matched by default' );
    [ $p, $r, $err ] = $go( $adjust( [ 'ids' => [ $P5 ], 'status' => [ 'draft' ] ], 'percent', '10' ) );
    $check( null === $err && '33.00' === $wc( $P5 )['regular'] && 'draft' === $wc( $P5 )['post_status'], 'with status ["draft"] the draft is adjusted (30 -> 33.00) and stays a draft' . ( null === $err ? '' : ': ' . $err ) );
    $undoLast( $r );
    [ $p ] = $run( $adjust( [ 'on_sale' => true, 'categories' => [ AIED_BA_SLUG . 'cat' ] ], 'percent', '10', [ 'dry_run' => true ] ) );
    $check( [ $P3, $V ] === array_map( 'intval', array_column( (array) ( $p['products'] ?? [] ), 'id' ) ), 'on_sale=true finds the products that are on sale now (the mug and the variable product with a sale variation) - decided in PHP' );
    [ , $err ] = $run( $adjust( [ 'categories' => [ AIED_BA_SLUG . 'nope' ] ], 'percent', '10', [ 'dry_run' => true ] ) );
    $check( null !== $err && str_contains( $err, 'does not exist' ), 'an unknown category slug is refused by name' );
    [ , $err ] = $run( $adjust( [ 'status' => [ 'draft' ] ], 'percent', '10', [ 'dry_run' => true ] ) );
    $check( null !== $err && str_contains( $err, 'must narrow the products' ), 'a filter that narrows nothing is refused' );
    [ , $err ] = $run( [ 'filter' => $inCatF, 'change' => [ 'mode' => 'percent', 'value' => 10 ], 'dry_run' => true ] );
    $check( null !== $err && str_contains( $err, 'signed decimal string' ), 'a number instead of a string for the change is refused' );

    // ---- between the preview and the run ------------------------------------------------------------------------------------------------
    [ $p ] = $run( $adjust( $inCatF, 'percent', '10', [ 'dry_run' => true ] ) );
    $edit = wc_get_product( $P1 );
    $edit->set_regular_price( '21' ); // the owner edits it after the preview
    $edit->save();
    $late = $inCat( AIED_BA_NAME . 'Late', AIED_BA_SKU . 'L', [ 'regular_price' => '40' ] ); // matches the filter now, was not previewed
    [ $r, $err ] = $run( $adjust( $inCatF, 'percent', '10' ) + [ 'token' => (string) ( $p['token'] ?? '' ) ] );
    $check( null === $err && in_array( $P1, array_map( 'intval', array_column( (array) ( $r['skipped'] ?? [] ), 'id' ) ), true ) && '21' === $wc( $P1 )['regular'], 'a product changed after the preview is skipped (changed_since_preview) and keeps the owner\'s price' . ( null === $err ? '' : ': ' . $err ) );
    $check( 1 === ( $r['not_in_preview'] ?? -1 ) && '40' === $wc( $late )['regular'], 'a product that matches now but was not previewed is not touched (not_in_preview: 1)' );
    $undoLast( $r );
    $edit = wc_get_product( $P1 );
    $edit->set_regular_price( '20' );
    $edit->save();
    wp_delete_post( $late, true );

    // ---- failure paths: every product the run touched is put back, no batch ----------------------------------------------------------------------
    $fixBefore    = $fixtures();
    $stateBefore  = $stateOf( $allIds );
    $newestBefore = $newestId();
    $failing = new BulkAdjustPrices( $log, new AiedBaFailingWriter( new WooProductWriter(), 2 ) );
    [ , , $err ] = $go( $adjust( $inCatF, 'percent', '10' ), $failing );
    $check( null !== $err && str_contains( $err, 'Could not update' ) && ! str_contains( $err, '/var/www' ) && ! str_contains( $err, 'simulated' ), 'a failing second save is refused with a message that shows no path: ' . (string) $err );
    $check( $fixBefore === $fixtures() && $stateBefore === $stateOf( $allIds ) && $newestBefore === $newestId(), 'after the failing save every product is exactly as before and no batch was recorded' );
    $dropping = new BulkAdjustPrices( $log, new AiedBaFailingWriter( new WooProductWriter(), 0, 'regular_price' ) );
    [ , , $err ] = $go( $adjust( $inCatF, 'percent', '10' ), $dropping );
    $check( null !== $err && str_contains( $err, 'saved differently than asked (regular_price)' ), 'a store that saves something else is caught by the read-back: ' . (string) $err );
    $check( $fixBefore === $fixtures() && $stateBefore === $stateOf( $allIds ) && $newestBefore === $newestId(), 'after the read-back difference every product is exactly as before and no batch was recorded' );

    // ---- undo refuses when it would make WooCommerce discard a sale price added since ---------------------------------------------------------
    [ , $r, $err ] = $go( $adjust( [ 'ids' => [ $P1 ] ], 'percent', '10' ) );
    $edit = wc_get_product( $P1 );
    $edit->set_sale_price( '21' ); // valid under the new 22.00, but not under the old 20
    $edit->save();
    $ux = $undoLast( $r );
    $check( 0 === ( $ux['restored'] ?? -1 ) && 'conflicts' === ( $ux['items'][0]['reason'] ?? '' ) && '22.00' === $wc( $P1 )['regular'] && '21' === rtrim( rtrim( $wc( $P1 )['sale'], '0' ), '.' ), 'undo refuses (conflicts) rather than let WooCommerce discard the sale price the owner added since' );
    $edit = wc_get_product( $P1 );
    $edit->set_sale_price( '' );
    $edit->set_regular_price( '20' );
    $edit->save();

    // ---- the 100-product cap ------------------------------------------------------------------------------------------------------------
    for ( $i = 1; $i <= 101; $i++ ) {
        $makeSimple( AIED_BA_NAME . "M{$i}", AIED_BA_SKU . "M{$i}", [ 'regular_price' => '10', 'category_ids' => [ $manyId ] ] );
    }
    $fixBefore    = $fixtures();
    $newestBefore = $newestId();
    [ , $err ] = $run( $adjust( [ 'categories' => [ AIED_BA_SLUG . 'many' ] ], 'percent', '10', [ 'dry_run' => true ] ) );
    $check( null !== $err && str_contains( $err, 'More than 100 products match' ) && $newestBefore === $newestId(), '101 matching products are refused (never truncated), nothing is changed' );

    // ---- permissions --------------------------------------------------------------------------------------------------------------------
    foreach ( AIED_BA_USERS as $role => $login ) {
        $uid = wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.invalid', 'role' => $role ] );
        if ( is_wp_error( $uid ) ) {
            throw new \RuntimeException( 'Could not create a throwaway user.' );
        }
        $users[ $role ] = (int) $uid;
    }
    wp_set_current_user( $users['editor'] );
    [ , $err ] = $run( $adjust( $inCatF, 'percent', '10', [ 'dry_run' => true ] ) );
    $check( null !== $err && str_contains( $err, 'edit_products' ), 'an editor without edit_products is refused' );
    wp_set_current_user( $users['shop_manager'] );
    [ , $r, $err ] = $go( $adjust( [ 'ids' => [ $P1 ] ], 'percent', '10' ) );
    $check( null === $err && '22.00' === $wc( $P1 )['regular'], 'a shop manager adjusts a price' . ( null === $err ? '' : ': ' . $err ) );
    $undoLast( $r );
    wp_set_current_user( 1 );

    // ---- everything the checks above changed is back ----------------------------------------------------------------------------------------
    $check( $original === $stateOf( $allIds ), 'the eight fixtures end in exactly their original state, variation rows included' );
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

$check( [] === $mine() && [] === $myTerms(), 'cleanup: every throwaway product (any status) and category is gone' );
$check( ! get_user_by( 'login', AIED_BA_USERS['editor'] ) && ! get_user_by( 'login', AIED_BA_USERS['shop_manager'] ), 'cleanup: the throwaway users are gone' );
$leftBatches = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', array_unique( $batchIds ) ) ) . ')' ); // phpcs:ignore
$check( 0 === $leftBatches, 'cleanup: the ' . count( array_unique( $batchIds ) ) . ' batch rows this run recorded are gone' );
$check( $snapshot() === $before, 'cleanup: every original product and variation is byte-identical (' . $before . ')' );
$check( $beforeTotal === $totalProducts() && $beforeOnly === $totalProducts( "'product'" ), "cleanup: the numbers of products ({$beforeOnly}) and of products and variations ({$beforeTotal}) in every status, the trash included, are the original ones again" );

if ( [] !== $failures ) {
    WP_CLI::error( 'bulk_adjust_prices NOT verified: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'bulk_adjust_prices verified on the real site.' );
```

- [ ] **Step 2: Makefile target**

**Edit 1 of 2 in `Makefile`** - replace this exact text:

```make
.PHONY: up down clean test test-pro build-pro verify-pro-skeleton verify-pro-settings verify-pro-custom-fields verify-pro-create-product verify-pro-update-products verify-pro-theme-layouts verify-pro-theme-templates verify-pro-global-colors verify-pro-global-variables verify-pro-global-presets verify-pro-site-tools verify-pro-rest verify-pro-build-site verify-pro-licence-tiers validate install shell-wp shell-wpcli export-layouts app-password schema-gap verify-modules verify-history verify-dry-run verify-global-styles verify-site-structure verify-acf-custom-fields verify-custom-fields verify-project-layouts verify-products

# ---------------------------------------------------------------
# up — bootstrap the full environment (idempotent)
```

with:

```make
.PHONY: up down clean test test-pro build-pro verify-pro-skeleton verify-pro-settings verify-pro-custom-fields verify-pro-create-product verify-pro-update-products verify-pro-bulk-adjust-prices verify-pro-theme-layouts verify-pro-theme-templates verify-pro-global-colors verify-pro-global-variables verify-pro-global-presets verify-pro-site-tools verify-pro-rest verify-pro-build-site verify-pro-licence-tiers validate install shell-wp shell-wpcli export-layouts app-password schema-gap verify-modules verify-history verify-dry-run verify-global-styles verify-site-structure verify-acf-custom-fields verify-custom-fields verify-project-layouts verify-products

# ---------------------------------------------------------------
# up — bootstrap the full environment (idempotent)
```

**Edit 2 of 2 in `Makefile`** - replace this exact text:

```make
# ---------------------------------------------------------------
verify-pro-update-products:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-update-products.php --user=1

# ---------------------------------------------------------------
# verify-pro-theme-layouts — real-site check of the Pro header/footer tools through the real registry (self-cleaning; free + Pro plugins must be active; dev site only: it evicts old batch rows)
```

with:

```make
# ---------------------------------------------------------------
verify-pro-update-products:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-update-products.php --user=1

# ---------------------------------------------------------------
# verify-pro-bulk-adjust-prices — real-site check of the Pro tool bulk_adjust_prices (self-cleaning; dev site only; free + Pro plugins and WooCommerce active; it records many batches, so it evicts EVERY batch row already on the dev site)
# ---------------------------------------------------------------
verify-pro-bulk-adjust-prices:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-bulk-adjust-prices.php --user=1

# ---------------------------------------------------------------
# verify-pro-theme-layouts — real-site check of the Pro header/footer tools through the real registry (self-cleaning; free + Pro plugins must be active; dev site only: it evicts old batch rows)
```

- [ ] **Step 3: Pin the new script in `DevGuardTest`**

In `pro-addon/tests/DevGuardTest.php`, replace this exact text:

```php
assertCount( 14, $scripts )
```

with:

```php
assertCount( 15, $scripts )
```

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter DevGuardTest` - Expected: `OK`.
Run: `php -l scripts/verify-pro-bulk-adjust-prices.php` - Expected: no syntax errors.

- [ ] **Step 4: Run the gate on the dev site**

Run: `make up` (idempotent), then `make verify-pro-bulk-adjust-prices`.
Expected: every line `PASS` and `Success: bulk_adjust_prices verified on the real site.` Paste the whole output in your report.
Then run `make verify-pro-update-products` and `make verify-pro-create-product` once each - Expected: still all PASS (their decorators got the two new methods in Task 4, and `UpdateProducts` now runs through the shared engine).

- [ ] **Step 5: Run it a second time**

Run: `make verify-pro-bulk-adjust-prices` again - Expected: identical result (self-cleaning, repeatable). Then check by hand that nothing is left: `docker compose exec -T wpcli wp post list --post_type=product,product_variation --post_status=any --fields=ID,post_title --format=csv | grep -i "AIED BA"` prints nothing.

- [ ] **Step 6: Whole suites**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml` - Expected: `OK (2138 tests, ...)`.
Run: `./vendor/bin/phpunit` - Expected: `OK (1056 tests, ...)`.

- [ ] **Step 7: Commit**

```bash
git add scripts/verify-pro-bulk-adjust-prices.php Makefile pro-addon/tests/DevGuardTest.php
git commit -m "$(cat <<'EOF'
test(pro): live gate verify-pro-bulk-adjust-prices (real WooCommerce: filter, exact prices, rounding, skips, token, rollback, undo, permissions)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review (done while writing)

- **Spec coverage:** Design 1 (arguments, change/round_to/filter rules, what is adjusted, per-product rules 1-6, limits, preview, real run, result) -> Tasks 2, 3, 5; Design 2 (units: PriceAdjuster, ProductFilter, findProducts/priceDecimals, shared engine, BulkAdjustPrices, batch) -> Tasks 1-5; Design 3 (wiring, docs, release) -> Task 6; Testing section -> Tasks 2, 3, 5, 7; the shared engine "existing tests unchanged and green" -> Task 1. Open points: `on_sale` scan cap -> Task 4 / README; object cache and racing runs -> README limits.
- **Placeholders:** none; every step carries its code or exact edit.
- **Type consistency:** plans have `id, label, name, hash, current, changes, previous, display`; `ProductUpdateRun::execute()` reads `id, label, name, changes, previous, display`; `ProductFilter::parse()` spec keys feed `findProducts()` criteria and `PriceAdjuster::adjust()` arguments; the filter keys are the criteria keys.
