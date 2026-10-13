# Update WooCommerce products: Pro `update_products` (design)

Date: 2026-10-13. Status: draft for owner review. Pro add-on (sub-project B, second tool).

## Context

Sub-project B gives the AI write access to WooCommerce in the Pro add-on, one tool at a time: `create_product` (built, drafts only), `update_products` (this spec), then `bulk_adjust_prices`. The free plugin stays read-only (`list_products`, `get_product`).

`update_products` is the riskiest write so far: it changes products that are already live, so a wrong price reaches customers at once. The owner decided (2026-10-13): every real run is protected by a preview and a single-use token (the model of `find_replace_site`); the first version covers prices and sale schedules, stock, text and catalogue data, and variation rows; it never changes a product's publish status (publishing existing drafts becomes its own small tool later).

It reuses `create_product`'s pieces (`ProductValuePolicy` rules, the `ProductWriter` door to WooCommerce, the batch kinds pattern) and `find_replace_site`'s token flow (`PreviewToken`: signed, single-use, 30 minutes, bound to the user, the exact arguments and a per-item content hash).

Facts proven live while building `create_product` (WooCommerce 11.1.2) that this design bakes in: WooCommerce assigns the default "Uncategorized" category when a product has none; core kses stores `&` as `&amp;` in titles and texts for users without `unfiltered_html` (a shop manager), so texts are compared through `ProductValuePolicy::sameText()`; WooCommerce silently drops backslashes in names and texts (refused); the SKU is echoed unescaped by the front-end template (so `<` and `>` are refused in names and SKUs); `$p->get_id()` and hooks behave as documented in `create_product`'s writer (not needed here: an update inserts nothing).

## Goal

The assistant can change prices, sale schedules, stock, texts and catalogue data of existing simple, external and variable products, including variation rows, after the owner (or the assistant, with the owner watching) has seen a before/after preview, and every change can be undone.

Success: on a real WooCommerce site, a previewed change is applied exactly (verifiable with the free `get_product`); a product changed by anyone since the preview is never overwritten; a failed save restores every product this run touched; nothing is published, unpublished, trashed or created; one `undo_batch` puts the previous values back unless the owner changed those fields since.

## Design

### 1. The tool (MCP + REST, Pro, the 20th Pro tool)

`update_products`: arguments `updates` (array, 1 to 25 items), `dry_run` (strict boolean, default false) and `token` (string). MCP and REST only: it joins `ProOpenApi::MCP_REST_ONLY` (the ChatGPT importer's 30 operations are used up). Registry capability `edit_posts`; inside the tool: WooCommerce active, then `edit_products`, then per product `edit_post`, plus `assign_product_terms` when categories or tags are given.

Each update is an object with `id` (integer, a product) and the fields to change. An omitted field is left alone; unknown keys are refused by name; a wrong type is refused, never cast. The product must be a simple, external or variable product that is not in the trash; a grouped product (its price comes from its children), a variation id, a post of another type, a missing id and a product the user may not edit all answer the same `Product {id} not found.`-style refusal (grouped gets its own reason because it exists).

| Group | Fields and rules |
|---|---|
| Prices | `regular_price`, `sale_price` (decimal strings as in `create_product`; `""` clears a price or a sale price), `sale_from`, `sale_to` (`YYYY-MM-DD`, `""` clears). The resulting state must satisfy: a sale price needs a regular price and is strictly lower; sale dates need a sale price; `sale_to` is not before `sale_from`. Not available on variable products themselves (their price lives in the variation rows) or grouped products. |
| Stock | `manage_stock`, `stock_quantity` (whole number 0 to 1,000,000), `stock_status` (`instock`, `outofstock`, `onbackorder`), `backorders` (`no`, `notify`, `yes`). Relative rules, checked against the RESULTING state: a quantity needs managed stock (so does `backorders`); managed stock needs a quantity; `stock_status` is refused while stock is managed (WooCommerce works it out). External products have no stock fields. |
| Text and catalogue | `name`, `description`, `short_description`, `sku`, `categories`, `tags`, `image_id`, `gallery_image_ids`, `weight`, `dimensions`, `tax_status`. The same rules as `create_product` (plain-text name without `<`, `>`, backslash or padding; texts through `FieldValuePolicy::refusal()` and the kses rule with `sameText()`; SKU 1-100 printable ASCII without `<`, `>` or padding and unique on the site except for the product itself; existing category and tag slugs only; Media Library images the user may use; no URL anywhere). `""` clears a text field or the SKU. A list replaces the whole set (`categories: []` clears it; WooCommerce then files the product under its default category). |
| Variations (variable products only) | `variations`: a list of at most 100 objects, each with the variation `id` (it must belong to that product) and any of `regular_price`, `sale_price`, `sale_from`, `sale_to`, `manage_stock`, `stock_quantity`, `stock_status`, `backorders` under the same relative rules, evaluated per variation. At most 200 variation rows per call in total. |

Never changed by this tool: status (publish, draft, private, trash), type, slug, grouped children, attributes and variations themselves (no creating or deleting), tax class, shipping class, downloads, upsells and cross-sells, any other meta.

A field whose new value equals the current one is dropped as unchanged; a product with nothing to change is reported as unchanged; if nothing changes at all there is no token and no batch.

**Preview.** `dry_run: true` validates everything (all-or-nothing: if any update is refused nothing is previewed and every reason comes back, each prefixed `Product N (#id "name")`, the message cut at 6,000 characters with `...`) and returns, per product, a before and after of only the fields that would change (variation rows by id), plus ONE `token`. It writes nothing and records no batch.

**Real run.** It needs the `token` of a dry run of the same arguments, made by the same user in the last 30 minutes and not used before (the existing `PreviewToken` rules; a missing or wrong token is refused with the message `find_replace_site` uses, adapted). The token carries, per product, a hash of that product's complete current state (every field above plus every variation row, so a change by anyone to anything else the tool could touch is noticed). A product whose current hash differs from the token's is SKIPPED and reported (`changed since the preview`), the others proceed. The token is spent when the run starts.

Result: `{ changed, dry_run: false, updated: [ { id, name, changes: {field: {before, after}} } ], skipped: [ { id, reason } ], unchanged: [ids] }` plus the usual `BatchLog::attach` block.

### 2. Units and safety

Everything lives in `pro-addon/`; the free plugin is not changed. `Compat::MIN_FREE` stays as it is (the tool needs nothing new from the free plugin; the assistant reads current values with the free `get_product`).

- **`ProductValuePolicy` gains a partial mode** (`checkPartial`): create and update share every field rule. It takes only the given fields and the product's current state, evaluates the relative rules on the resulting state and returns only the fields that actually change, or every reason.
- **`ProductWriter` gains** `product( int $id ): ?array` (type, status, every editable field incl. sale dates, and for a variable product its variation rows; null for a missing, trashed, grouped or non-product id) and `apply( int $id, array $changes ): void` (writes through WooCommerce's CRUD; variation changes by id; never touches status, type or slug). The existing `snapshot()` / `create()` / `delete()` that `create_product` and its undo use are unchanged. `WooProductWriter` stays the only WooCommerce-facing class; `FakeProductWriter` gets the matching methods for the unit tests.
- **`UpdateProducts`** (`src/Tools/`): the tool, shaped like `FindReplaceSite` (token, skip-on-change, one batch).
- **`ProductFieldsKind`** (`product_fields`, `src/Kinds/`): one batch item per updated product.
- **Apply and rollback.** Products are applied one at a time. After each, the product is read back and compared with the intended result (texts through `sameText()`, prices and sale dates as exact values, categories: a non-empty requested set must match exactly, an empty one accepts the store's default category; derived values such as a variable parent's price or a managed product's stock status are not compared). A failed save, an exception or a difference restores every product this run already changed (and the failing one) to its previous values from the captured `before`, records NO batch and fails with a safe message (only tool-generated text and `WC_Data_Exception` text reach the client, as in `create_product`); a restore that cannot finish names the product ids.
- **Previous values are stored**, so a text field whose CURRENT value is over 512 KB is refused (too large to undo safely) and the previous texts of one call may not exceed 1 MB in total (the message says to split the call).
- **Messages and information handling** follow `create_product` (no exception text or path except the two safe classes; labels use the sanitised current name).

### 3. Undo

A real run records ONE batch of `product_fields` items (`ref` = product id). `before` holds the previous values of ONLY the fields that changed (and, per variation id, only the changed fields). `after_hash` is the hash of those same fields as read back after the save. `undo_batch` restores the previous values only while the same fields still equal what the run wrote (the changed-since rule; a field the owner changed since is skipped and the product reported); `force` skips that rule, never a permission or existence check. After a restore the values are read back; the inverse item records the values it replaced, so an undo can itself be undone. Per-item capability at undo: `edit_post` on the product. A product that is gone, in the trash, or no longer of an editable type is reported (`missing`), nothing else is touched.

### 4. Wiring, docs and release

`Tools.php`, `autoload.php`, `Batches::kinds()` (the new kind), `ProOpenApi::MCP_REST_ONLY` (+ `update_products`), the pinned tests (`ToolsTest` tool list and MCP-only list, `ProOpenApiTest`, `ExpiryKeepsAllToolsTest` count, kinds list), `readme.txt` (tool list, changelog `= 0.11.0 =`, version), `README.md`, version 0.11.0 (header, `AIED_PRO_VERSION`, Stable tag, test bootstrap), `CLAUDE.md`, `make build-pro`. The store release and the divi5lab.com copy (20 Pro tools) are release steps for the owner's go-ahead.

## Testing

- `ProductValuePolicy` partial mode: every field rule and every relative rule against a current state (valid and invalid), clearing semantics, unknown keys, variation rows, the 25/100/200 limits, sale schedule rules, a SKU equal to the product's own SKU (allowed) versus another product's (refused).
- `UpdateProducts` with a fake writer: preview returns only changed fields and one token; real run without/with a wrong, expired, spent, other-user or other-arguments token is refused; a product changed after the preview is skipped and the others proceed; all-or-nothing refusal with every reason; unchanged fields and products; rollback when the Nth save fails or a read-back differs (earlier products restored, no batch, a failing restore names the product); status/type/slug and unknown keys refused; grouped, variation-id, missing, trashed and not-editable products; WooCommerce inactive; missing capabilities; texts too large to undo.
- The kind: undo restores the previous values; skips a field changed since (per field and per variation row); `force`; undo of the undo; per-item capability; missing product.
- Registry, REST and OpenAPI parity as for the other MCP-only tools (the Pro tool counts become 20).
- Real-site gate `make verify-pro-update-products` (dev site only; free + Pro plugins and WooCommerce active). The script creates its OWN throwaway simple and variable products (with variations) and edits only those. Checks: a dry run changes nothing and returns a token; a real run without a token is refused; prices, sale price with a schedule, stock (managed and not), text, SKU, category/tag/image changes are applied and read back through the free `get_product`; variation rows are applied and the variable parent's derived price follows; a product changed between preview and run is skipped; a failure path (a failing writer, as in `create_product`) restores everything; one `undo_batch` restores every value exactly; a field edited after the run is skipped by the undo; undo of the undo works; real `wp_kses_post()` behaviour for a shop manager (`&` in a description); a plain editor without `edit_products` is refused; the 18 existing products and their variations are byte-identical afterwards and every throwaway (products, category, tag, image, users, batch rows) is deleted.

## Out of scope

- Publishing, unpublishing, trashing and creating products or variations (a `publish_product`-style tool and variable-product creation are later work); filter-based bulk changes (`bulk_adjust_prices`, next); attributes; tax and shipping classes; downloads; upsells and cross-sells; custom fields (`set_custom_fields` covers keys the site already knows); creating categories, tags and images.
- The ChatGPT Action: the 30-operation budget is used up.

## Open points

- A scheduled sale (`sale_from` in the future) leaves the product's current price at the regular price until WooCommerce's own scheduler starts the sale; the preview says so in a note when it applies.
- A variable parent's price range is derived by WooCommerce after a variation save; the read-back does not compare it, and the live gate checks that it follows.
- A persistent object cache can show stale reads right after a save; the read-back goes through WooCommerce's CRUD, not a direct query.
- Concurrent edits between the preview and the real run are handled per product by the state hash; two real runs racing each other are not serialised (WooCommerce's own admin has the same property).
