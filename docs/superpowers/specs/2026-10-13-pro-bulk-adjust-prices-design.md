# Bulk price adjustment: Pro `bulk_adjust_prices` (design)

Date: 2026-10-13. Status: draft for owner review. Pro add-on (sub-project B, third tool), version 0.12.0.

## Context

Sub-project B gives the AI write access to WooCommerce in the Pro add-on, one tool at a time: `create_product` (built), `update_products` (built, merged to local `main`), `bulk_adjust_prices` (this spec). The free plugin stays read-only.

A shop owner's most common bulk request is "raise everything in this category by 10%" or "take 5 off all accessories". `update_products` can do it only by the AI reading each product, doing the arithmetic itself and sending up to 25 products per call, with the risk of an arithmetic or rounding mistake on live prices. `bulk_adjust_prices` does the arithmetic and the selection deterministically, behind the same preview, single-use token, read-back, rollback and undo as `update_products`.

Owner decisions (2026-10-13, this session):
- It changes the **regular price only**. Sale prices are never touched.
- Products are chosen by **filter fields resolved by the tool** (the preview lists exactly what matched and the token fixes that list).
- Prices are rounded to the shop's decimals, half up, with an optional **"end in"** (`round_to`).
- A matched product that cannot be adjusted is **skipped and listed with a reason**; the rest proceeds.

Facts from building `update_products` that this design relies on: WooCommerce's `wc_get_products` ignores an `on_sale` argument (filtered in PHP; the free plugin scans at most 500); the free `list_products` already proved the catalogue query on the dev site; a sale price at or above the regular price is invalid (`update_products` enforces "sale is strictly lower"); `WooProductWriter` is the only class that calls WooCommerce and has no unit test (proved by a live gate).

## Goal

The assistant can raise or lower the regular prices of every product matching a filter by a percent or a fixed amount, after the owner (or the assistant, with the owner watching) has seen a before/after preview of every product, and the whole change can be undone with one `undo_batch`.

Success: on a real WooCommerce site, a previewed adjustment is applied exactly (verifiable with the free `get_product`); a product changed by anyone since the preview is never overwritten; prices are never rounded to zero or below, never put at or below an active sale price, and never calculated with floating point; a failed save restores every product the run touched and records no batch; nothing is published, unpublished, created or deleted.

## Design

### 1. The tool (MCP + REST, Pro, the 21st Pro tool)

`bulk_adjust_prices`: arguments `filter` (object, see below), `change` (object), `round_to` (optional string), `dry_run` (strict boolean, default false) and `token` (string). MCP and REST only: it joins `ProOpenApi::MCP_REST_ONLY` (the ChatGPT importer's 30 operations are used up). Registry capability `edit_posts`; inside the tool: WooCommerce active, `edit_products`, and `edit_post` on every product it touches. A matched product the user may not edit is not matched at all.

**`change`** (required): `{ "mode": "percent" | "amount", "value": "<signed decimal string>" }`.
- `percent`: the value is a signed decimal with at most 2 decimals, from `-90` to `500`, not `0` (for example `"10"` raises prices by 10%, `"-12.5"` lowers them by 12.5%).
- `amount`: a signed decimal with at most 6 decimals, absolute value at most `100000`, not `0` (for example `"-5"` takes 5 off in the shop currency).
- A number instead of a string, a currency symbol or a thousands separator is refused, never cast.

**`round_to`** (optional): a decimal string from `0` up to but not including `1`, with at most 2 decimals (for example `"0.99"`, `"0.95"`, `"0.5"`); `"0"` and `"1"` both mean whole numbers. Without it, prices are rounded half up to the shop's number of decimals (`wc_get_price_decimals()`, read by the writer). With it, the new price is the nearest price that ends in `round_to` (ties go up). If rounding moves a price the opposite way to the requested change (a rise that ends below the old price, or the reverse), the product is skipped (`rounding_reversed`).

**`filter`** (required, must contain at least one of `categories`, `tags`, `types`, `stock_status`, `on_sale`, `search`, `ids`; `status` and `exclude_ids` alone do not count, and "all products" must be asked for with `"all": true`):

| Key | Meaning |
|---|---|
| `categories` | list of existing category slugs (at most 10); a product in any of them, including products in child categories |
| `tags` | list of existing tag slugs (at most 20); a product with any of them |
| `types` | list of `simple`, `variable`, `external` (grouped products are never adjustable) |
| `stock_status` | `instock`, `outofstock` or `onbackorder` (evaluated on the product itself; for a variable product that is WooCommerce's own parent value) |
| `on_sale` | `true` or `false` (the product itself is on sale now) |
| `search` | words contained in the name (case-insensitive, at most 100 characters) |
| `ids` | explicit list of product ids (at most 100) |
| `all` | `true` to match every product in the allowed statuses |
| `exclude_ids` | list of product ids (at most 100) removed from whatever matched |
| `status` | list of `publish`, `private`, `draft`, `pending`; default `["publish"]` so drafts and private products are never changed by accident |

Different keys combine with AND, the values inside one list with OR. Unknown keys, wrong types and unknown category or tag slugs are refused by name.

**What is adjusted.** A simple or external product: its `regular_price`. A variable product: the `regular_price` of every variation row (that is where its prices live); the parent has none. A sale price (`sale_price`, `sale_from`, `sale_to`) is never read as an input and never written. Grouped products are not matched.

**Per-product rules, evaluated on the exact stored decimal (no floats).**
1. The new price is the old price changed by the percent or amount, then rounded as above.
2. A product with no regular price (or, for a variable product, any row with none) is skipped: `no_regular_price`.
3. A new price of `0` or below is skipped: `not_positive`. A new price above `9999999999` is skipped: `too_large`.
4. A new price at or below an active sale price is skipped: `not_above_sale` (the existing sale price must stay strictly lower; this is checked per row for a variable product).
5. A new price equal to the old one is `unchanged` (not an error, not in the batch).
6. A variable product is adjusted as a whole or not at all: if any row would be skipped, the whole product is skipped with the row ids and reasons, so a variable product never ends up half adjusted.

**Limits.** A filter that matches more than 100 products, or an adjustment that would change more than 400 price fields (simple regular prices plus variation rows) in one call, is refused as a whole with a message telling the person to narrow the filter. The tool never truncates silently. The tool scans at most 500 candidate products; if the filter would need more, it is refused with the same message.

**Preview.** `dry_run: true` validates everything and returns: `summary` (`matched`, `would_change`, `skipped`, `unchanged` counts), `products` (per product: id, name, type, `before` and `after` regular price, or per variation row), `skipped` (id, name, reason code and a short sentence; for a variable product the rows that blocked it), `unchanged` (ids), and ONE `token`. It writes nothing and records no batch. If nothing would change there is no token.

**Real run.** It needs the `token` of a dry run of the same arguments, made by the same user in the last 30 minutes and not used before (the existing `PreviewToken` rules). The token binds the arguments and a hash of each product's whole current state (the same `product()` state hash as `update_products`). The real run acts only on the products in the token: it loads each, and a product whose current hash differs, or that is gone, is skipped and reported (`changed_since_preview`); a product that now matches the filter but was not in the preview is not touched and is counted as `not_in_preview`. The new prices are recomputed from the unchanged state, so they equal the previewed ones. The token is spent after validation, right before the first write (a refused run keeps it).

Result: `{ changed, dry_run: false, updated: [ { id, name, changes: { regular_price: {before, after} } , variations?: [...] } ], skipped: [ { id, reason } ], unchanged: [ids], not_in_preview: int }` plus the usual `BatchLog::attach` block.

### 2. Units and safety

Everything lives in `pro-addon/`; the free plugin is not changed. `Compat::MIN_FREE` is unchanged.

- **`PriceAdjuster`** (`src/Products/`, pure): `adjust( string $old, string $mode, string $value, int $decimals, ?string $roundTo ): array{price: ?string, reason: ?string}` and the helpers it needs. Exact arithmetic on decimal strings (a price has at most 10 integer and 6 decimal digits, and multiplying it by a percent factor can overflow a PHP int, so the maths works on split integer parts or a small string multiplier; never floats, and never relying on BCMath being installed). Fully unit-tested: half-up ties, a 0-decimal and a 3-decimal shop, `round_to` values, negative and large values, reversed rounding, boundaries of the sale and zero rules.
- **`ProductFilter`** (`src/Products/`, pure): validates and normalises the `filter` object (and `change`, `round_to`) with every reason, no WordPress calls. Site checks (category and tag slugs exist, the user's rights) belong to the tool.
- **`ProductWriter` gains `findProducts( array $filter, int $limit ): array{ids: list<int>, truncated: bool}`** (statuses, types, category and tag ids, stock status, on-sale evaluated in PHP, name search; never returns grouped products, variations or products in the trash; at most `$limit + 1` ids so the caller can tell it overflowed) and **`priceDecimals(): int`**. `WooProductWriter` implements them (the only WooCommerce-facing code, proved by the live gate); `FakeProductWriter` gets the matching methods. `product()` and `apply()` are reused unchanged.
- **The apply engine is shared, not copied.** `UpdateProducts`' apply, read-back, rollback and batch-recording code moves unchanged into a shared class (`ProductUpdateRun`, `src/Products/`); `UpdateProducts` and `BulkAdjustPrices` both call it. The behaviour of `update_products` does not change; its existing tests and its live gate are the proof, and a refactor that makes any of them fail is rejected. The shared class takes the planned products (id, label, changes, previous values, display), the skipped and unchanged lists and a batch summary text ("Adjusted prices of N product(s)"), and returns the result array or throws the same safe `InvalidArgumentException`.
- **`BulkAdjustPrices`** (`src/Tools/`): the tool. It validates arguments, resolves the filter, computes the adjustments, builds the preview or the plans, and hands plans to the shared engine.
- **Batch.** One batch of `product_fields` items (`ProductFieldsKind`, unchanged): `before` is the previous `regular_price` of the product (or of each changed variation row), `after_hash` the hash of those fields as read back. `undo_batch` therefore needs no new code, including `conflicts` (undo refuses when restoring a price would make an active sale invalid) and `force` semantics.
- **Messages and information handling** follow `update_products`: only tool-generated text and `WC_Data_Exception` text reach the client; names are sanitised and cut in labels; a message is cut at 6,000 characters.

### 3. Wiring, docs and release

`Tools.php`, `autoload.php`, `ProOpenApi::MCP_REST_ONLY` (+ `bulk_adjust_prices`, 7th entry), the pinned tests (`ToolsTest` tool list and MCP-only list, `ProOpenApiTest`, `ExpiryKeepsAllToolsTest` count 21, `DevGuardTest` script count 15), `readme.txt` (tool list, `= 0.12.0 =`, Stable tag), `README.md`, version 0.12.0 (header, `AIED_PRO_VERSION`, test bootstrap), `CLAUDE.md`, `make build-pro`. The store release and the divi5lab.com copy (21 Pro tools) are the owner's step.

## Testing

- `PriceAdjuster`: percent and amount, rises and cuts, half-up at the exact tie, shops with 0, 2 and 3 decimals, `round_to` `0`, `0.5`, `0.95`, `0.99`, `1`, rounding reversed, zero and negative results, the maximum, a price like `19.99` that a float would get wrong, large prices near the digit limit, no float use (property-style checks against hand-computed decimals).
- `ProductFilter`: every key valid and invalid, unknown keys, empty filter refused, `all: true`, status default, list limits, `change` and `round_to` validation (numbers instead of strings, symbols, zero, out of range).
- `BulkAdjustPrices` with the fake writer: preview content and token; real run without or with a wrong, expired, spent, other-user or other-arguments token refused; a product changed after the preview skipped while the others proceed; a product that newly matches is not touched (`not_in_preview`); every skip reason (including the variable product skipped as a whole); unchanged; more than 100 matched and more than 400 fields refused; unknown category slug; a product the user may not edit not matched; WooCommerce inactive; missing capabilities; rollback when the Nth save fails and when a read-back differs; the batch items and one `undo_batch` restoring every price; undo refusing with `conflicts` when a sale was added since.
- The shared engine: all existing `UpdateProductsTest`, `ProductFieldsKindTest` tests unchanged and green.
- Registry, REST and OpenAPI parity as for the other MCP-only tools (the Pro tool counts become 21).
- Real-site gate `make verify-pro-bulk-adjust-prices` (dev site only; free + Pro plugins and WooCommerce active). The script creates its OWN throwaway products (a throwaway category, simple products with and without a sale price, one with no regular price, a draft, an external product, a variable product with variations, 101 cheap simple products for the cap) and adjusts only those. Checks: a dry run changes nothing and returns a token; a real run without a token is refused; a percent rise and an amount cut are applied with the shop's rounding and with `round_to`, read back through the free `get_product` and WooCommerce's getters; variation rows move and the variable parent's price range follows; every skip reason appears for the product built to trigger it; the draft is untouched with the default `status`; a product edited between preview and run is skipped; a failing writer and a lying writer restore everything and record no batch; one `undo_batch` restores every price exactly (and an undo after the owner added a sale price is refused with `conflicts`); the cap refuses 101 matches; a plain editor without `edit_products` is refused; the products the site already has are byte-identical afterwards and every throwaway (products, category, users, batch rows) is deleted.

## Out of scope

- Changing sale prices or sale schedules, scheduling a price change for a later date, per-category different percentages in one call (several calls do it), tax-inclusive/exclusive handling, currency conversion, price history beyond the undo batch.
- Publishing, creating or deleting products; any field other than the regular price (use `update_products`).
- The ChatGPT Action: the 30-operation budget is used up.

## Open points

- `on_sale` is evaluated in PHP over at most 500 scanned candidates (WooCommerce ignores the query argument); a catalogue larger than that needs a narrower filter.
- A persistent object cache can show stale reads right after a save; the read-back goes through WooCommerce's CRUD.
- Two real runs racing each other are not serialised (WooCommerce's own admin has the same property); the per-product state hash narrows the window to the time of one run.
- A variable product's price range is derived by WooCommerce after a row is saved; the read-back does not compare it, and the live gate checks that it follows.
