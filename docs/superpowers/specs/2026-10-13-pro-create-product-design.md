# Create WooCommerce products: Pro `create_product` (design)

Date: 2026-10-13. Status: draft for owner review. Pro add-on (sub-project B, first tool).

## Context

The free plugin 4.9.0 reads WooCommerce products (`list_products`, `get_product`; spec `2026-10-13-woocommerce-products-read-design.md`). Every write belongs to the Pro add-on. Sub-project B was split into three tools in build order: `create_product` (this spec), `update_products` (price, stock, text, variations) and `bulk_adjust_prices`. The owner chose `create_product` first, for simple products: it is the safest write (always a draft, undo = trash) and it follows two existing patterns in the add-on: `SetCustomFields` (tool shape, `dry_run`, all-or-nothing validation, read-back verification, one batch) and `PageCreatedKind` (creation with undo by trash and the changed-since rule).

## Goal

The assistant can create new simple WooCommerce products as drafts, with real prices, stock, categories and images, so a store owner can say "add these 10 products from my price list" and review and publish them in WooCommerce.

Success: on a real WooCommerce site, a call creates exactly the drafts asked for (verifiable with the free `get_product`); a refused product creates nothing; one undo takes the whole call away; nothing is ever published; nothing is written to the site except the new products.

## Design

### 1. The tool (MCP + REST, Pro, the 19th Pro tool)

`create_product`: arguments `products` (array, 1 to 25 items) and `dry_run` (strict boolean, default false; a non-boolean is refused). MCP and REST only: it joins `ProOpenApi::MCP_REST_ONLY` (the ChatGPT importer's 30 operations are used up) and the REST route is generated from the tool list as for every Pro tool. Registry capability `edit_posts` (the same as `set_custom_fields`); the tool itself then requires WooCommerce to be active (otherwise it says so) and the `edit_products` capability, plus `assign_product_terms` when categories or tags are given.

Each product (a wrong type is refused, never cast):

| Field | Rule |
|---|---|
| `name` | Required string, 1 to 200 characters, plain text: it is refused if `wp_strip_all_tags` would change it or it holds control characters. |
| `description` | Optional string, at most 10,000 characters, post-content HTML. Refused by `FieldValuePolicy::refusal()` (script, style, iframe, object, embed, PHP tags, event-handler attributes, `javascript:`/`vbscript:`/`data:text/html` URLs, invalid UTF-8) and refused if `wp_kses_post()` would change it. |
| `short_description` | The same rules, at most 1,000 characters. |
| `sku` | Optional string, 1 to 100 printable ASCII characters. Must be unique on the site (checked with WooCommerce's own lookup) and unique inside the call. |
| `regular_price` | Optional decimal string matching `^\d{1,10}(\.\d{1,6})?$` (no currency symbol, no thousands separator). `"0"` is allowed. |
| `sale_price` | Optional decimal string, the same format. Needs `regular_price` and must be lower than it. |
| `manage_stock` | Optional strict boolean, default false. |
| `stock_quantity` | Whole number 0 to 1,000,000. Required when `manage_stock` is true; refused when it is not. |
| `stock_status` | `instock`, `outofstock` or `onbackorder`. Only when `manage_stock` is false (when stock is managed WooCommerce derives it); default `instock`. |
| `backorders` | `no`, `notify` or `yes`. Only when `manage_stock` is true. |
| `categories` | Optional list of existing `product_cat` slugs, at most 10. An unknown slug is refused (the message names it); the tool never creates a category. |
| `tags` | Optional list of existing `product_tag` slugs, at most 20; same rule. |
| `image_id` | Optional integer: an existing Media Library image (`image/*` attachment) the user may read (`upload_files` plus `read_post` on it, the free plugin's `list_media_images` rule). Never a URL. |
| `gallery_image_ids` | Optional list of such ids, at most 10. |
| `weight`, `dimensions` (`length`, `width`, `height`) | Optional decimal strings, the same format. |
| `tax_status` | `taxable` (default), `shipping` or `none`. |
| `virtual`, `sold_individually` | Optional strict booleans. |

Anything else in a product object (a `slug`, a `status`, `type`, downloads, attributes, a URL) is refused by name: unknown keys are never ignored silently.

All-or-nothing: every product is validated before anything is created; if any is refused nothing is created and every reason comes back, each prefixed with the product's position and name (for example `Product 3 "Blue Hat": sku "HAT-1" is already used`). A message is cut at 6,000 characters with an ellipsis, as `set_custom_fields` does.

`dry_run: true` runs every check and returns what would be created (the normalised values per product); it writes nothing, records no batch and needs no preview token (drafts only, at most 25 products).

A real run returns `{ changed: true, dry_run: false, products: [ { id, name, sku, status: "draft", edit_url } ], batch }` (the batch block is the add-on's usual `BatchLog::attach` payload).

### 2. Safety and units

Everything lives in `pro-addon/`; the free plugin is not changed and `Compat::MIN_FREE` stays as it is (the tool calls nothing new in the free plugin).

- **`ProductValuePolicy`** (pure, `src/Products/`): validates one product's arguments and returns the normalised values or a list of refusal reasons. It holds every rule of the table above, including the unknown-key refusal. It reuses `FieldValuePolicy`. It needs no WordPress or WooCommerce call (price and text rules are plain PHP; the kses and strip-tags checks are passed in as callables so tests can drive them).
- **`ProductStore`** (`src/Products/`): the only class that calls WooCommerce, replaceable in tests (as `PostMetaStore` is). It creates a draft with `WC_Product_Simple` and WooCommerce's own CRUD setters and `save()`, looks up SKUs (`wc_get_product_id_by_sku`), term slugs (`get_term_by`) and attachments, reads a saved product back, and deletes one (`wp_delete_post( $id, true )`, used only to roll back a product this same call created seconds ago). It never touches an existing product.
- **`CreateProduct`** (`src/Tools/`): the tool, shaped like `SetCustomFields`.
- **Read-back check:** after each save the product is read back and its name, status (must be `draft`), SKU, prices, stock fields and category ids are compared with what was asked. A failed save, an exception, or any difference rolls back every product this call created (they are deleted outright: they are seconds old and nothing else references them) and the call fails with the reason; if a rollback cannot delete a product the error names it and tells the owner to check it. No batch is recorded for a failed call.
- **No custom code, no remote access:** texts are refused as above; there is no URL argument anywhere; an image is only ever an existing attachment id.

### 3. Undo

A real run records ONE batch whose items are `product_created` (`ref` = product id, `before` null, `after_hash` = a hash of the snapshot: name, description, short description, SKU, regular and sale price, manage-stock flag, stock quantity, stock status, category ids). `undo_batch` trashes a created product only while it is still a draft and still matches its snapshot (the changed-since rule); a product the owner edited, published or made private is skipped and reported, never trashed. The inverse is a `product_trashed` item (untrash restores it as a draft), mirroring `PageCreatedKind` / `PageTrashedKind`. With `EMPTY_TRASH_DAYS` 0 the trash is a permanent delete, so the page kinds' extra guard applies here too: a changed product is never deleted, forced or not, and the inverse has no undo point. Per-item capability at undo: `edit_post` and `delete_post` on the product. The two new kinds are registered with the others in `Batches`.

### 4. Wiring, docs and release

`Tools.php` (registry), `autoload.php`, `ProOpenApi::MCP_REST_ONLY`, `Batches` (kinds). The Pro readme and README get the tool; changelog `= 0.10.0 =`; version 0.10.0 (header, `AIED_PRO_VERSION`, readme Stable tag, the test bootstrap constant); `make build-pro` zip. The divi5lab.com copy (19 Pro tools) and the store release are release steps for the owner's go-ahead, not part of the build.

## Testing

- `ProductValuePolicy`: every field rule (valid and invalid), unknown keys, price relations (sale needs regular, sale below regular), stock combinations, caps, text refusals, the 25-product limit.
- `CreateProduct` with a fake `ProductStore`: all-or-nothing refusal with every reason, `dry_run` writes nothing and records no batch, success records one batch of N items, rollback when the Nth save fails or the read-back differs, WooCommerce inactive, missing capabilities, strict argument types.
- The kinds: undo trashes an unchanged draft; skips an edited, published or private product; `EMPTY_TRASH_DAYS` 0 guard; the inverse restores a draft; per-item capability.
- Registry, REST and OpenAPI parity (as `SetCustomFields` has): the tool is advertised, behind the licence gate, in `MCP_REST_ONLY`, and the Pro tool counts in the existing parity tests become 19.
- Real-site gate `make verify-pro-create-product` on the dev site (Pro plus free plugins active, WooCommerce 11.1.2): real drafts are created and checked with the free `get_product`; `dry_run` creates nothing; a duplicate SKU, an unknown category, a bad price, a sale price above the regular price and a script in a description are each refused with nothing created; a throwaway Media Library image is accepted as `image_id`; one `undo_batch` trashes the products; a product edited after creation is skipped by the undo; a plain editor is refused; the 18 existing products are byte-identical afterwards and every throwaway (products, image, user) is deleted. Dev site only.

## Out of scope

- Variable, grouped and external products; downloadable products (file URLs are paid content); sale schedules; attributes; upsells and cross-sells; custom fields on the new product (`set_custom_fields` covers keys the site already knows); creating categories, tags or images.
- Publishing a product and changing an existing product: `update_products` (next in sub-project B). `bulk_adjust_prices` follows it.
- The ChatGPT Action: the 30-operation budget is used up.

## Open points

- WooCommerce decides the final slug and may append a number when the name repeats; the response returns the saved product, so the assistant sees it.
- Whether to also accept `featured` and `catalog_visibility` in a later version: left out now to keep the first version small.
- A persistent object cache can serve stale product reads right after a create; the read-back check reads through WooCommerce CRUD, not a direct query, so it sees what WooCommerce will show.
