# Read WooCommerce products: `list_products` + `get_product` (design)

Date: 2026-10-13. Status: draft for owner review. Free plugin, read-only (sub-project A).

## Context

A user with a WooCommerce site reported that the plugin "just reads the pages, not the products" and could not answer a
product count. Today the plugin has no WooCommerce code: `get_site_structure` lists `product` as a public post type
without counts or data, and `get_custom_fields` hides the keys WooCommerce uses (they start with `_`).

The owner chose the widest scope, split in two sub-projects, each with its own spec, plan and release:

| # | Sub-project | Plugin | Status |
|---|---|---|---|
| A | Read products: `list_products`, `get_product`, counts in `get_site_structure` (this spec) | free 4.9.0 | specifying |
| B | Write products: price, sale price, stock, text, variations (`update_products`), bulk price changes (`bulk_adjust_prices`), draft creation (`create_product`) | Pro | later, own spec (candidates in "Out of scope") |

Free is every read. Pro is every write. A does not depend on B.

## Goal

The assistant can see the store's real catalogue: how many products there are, which ones, their prices, stock, categories
and variations, so it answers store questions correctly and writes shop copy or pages that fit what the store sells.

Success: on a real WooCommerce site (simple, variable, grouped and external products, drafts included) the assistant gets
accurate counts and per-product data; a product the user may not edit, and every private field listed below, never reaches it;
nothing is written; without WooCommerce nothing breaks.

## Design

### 1. Tools (MCP + REST, read-only)

Both are MCP and REST only: the ChatGPT importer's 30 operations are used up (like `get_site_structure` and
`get_custom_fields`). `OpenApiSpec` is not touched and a lockstep test pins the absence on purpose. The free plugin goes from
18 to 20 tools.

**`list_products`** (`GET /products`). All inputs optional; a wrong type is rejected 400 / -32602, never cast:

| Input | Values |
|---|---|
| `status` | `publish`, `draft`, `pending`, `private`, `future`, or `any` (default; never trash or auto-draft) |
| `type` | `simple`, `variable`, `grouped`, `external` |
| `category` | category slug (string, at most 200 characters) |
| `stock_status` | `instock`, `outofstock`, `onbackorder` |
| `on_sale` | strict boolean |
| `search` | string, at most 100 characters |
| `orderby` / `order` | `title` (default), `date`, `price` / `asc` (default), `desc` |
| `page` / `per_page` | integers, `page` >= 1; `per_page` 1 to 50, default 25 (above 50 is refused, not clamped) |

Output:

```
{ woocommerce: { active, version, currency },
  totals: { all, by_status: { publish, draft, ... }, published_by_type: { simple, variable, grouped, external } },
  products: [ row, ... ], page, per_page, total_matching, total_pages }
```

A row: `id`, `name` (cut at 200), `type`, `status`, `sku`, `price` (the current price), `regular_price`, `sale_price`,
`on_sale`, `stock_status`, `manage_stock`, `stock_quantity` (null when stock is not managed), `categories`
(`[{id, name, slug}]`, at most 20), `url`, and `variations_count` for a variable product. Prices stay the strings WooCommerce
stores: no formatting, no arithmetic.

**`get_product`** (`GET /products/{id}`). Input: `id` (integer, required). A product in the trash answers NOT_FOUND, like a missing one (`list_products` never lists the trash either). Output: the row fields plus `description` (cut
at 5,000 characters) and `short_description` (cut at 1,000), both as stored HTML; `tax_status`, `tax_class`, `weight`,
`dimensions`, `shipping_class` (slug), `sold_individually`, `backorders`, `low_stock_amount`, `virtual`, `downloadable`
(a flag only), `featured`, `catalog_visibility`; `attributes` (`[{name, options, variation, visible, taxonomy}]`, at most 50
attributes with 50 options each); `images` (featured URL and at most 20 gallery URLs); `tags` (at most 20); `upsell_ids` and
`cross_sell_ids` (ids, at most 50 each); `date_created`, `date_modified` (ISO 8601); `average_rating` and `review_count`
(numbers only). By type:

- external: `external: { product_url, button_text }`.
- grouped: `children` (child ids, at most 100).
- variable: `variations` (each: `id`, `sku`, `status`, `price`, `regular_price`, `sale_price`, `on_sale`, `stock_status`,
  `manage_stock`, `stock_quantity`, `attributes` as name-to-value (the value is the term NAME for a taxonomy attribute, see "Findings from the real site"), `image` URL), at most 100, plus `variations_total` and
  `variations_truncated` (true when more exist).

### 2. Safety rules (pure classes, one WooCommerce-facing class, one service)

The built design (it replaced the first draft's `ProductTarget` / `ProductCatalog`):

- **`ProductReader`** (pure): formatting plus every cap above and every privacy rule. It receives plain arrays of getter
  values, never a `WC_Product`, so the unit tests need no WooCommerce.
- **`ProductQuery`** (pure): parses and validates the `list_products` input (REST strings or typed MCP values) into the
  arguments the gateway takes; a wrong type or value is rejected, never cast or clamped.
- **`ProductGateway`** (interface) and **`WooProductGateway`**: the interface is what the service needs from a store and
  holds the `MAX_SCAN = 500` constant of the on-sale scan. `WooProductGateway` is the ONLY class that touches WooCommerce,
  through its own public read functions (`wc_get_products`, `wc_get_product`, `get_woocommerce_currency`, `wp_count_posts`,
  `get_terms`, `get_term_by`), each behind `function_exists` / `class_exists` checks where it can be missing. It copies an
  explicit list of fields into a plain array and never calls a getter for downloads, the purchase note or meta. `isProduct()`
  is true only for a post of the exact type `product` that is not in the trash: a missing id, a variation, a post of
  another type and a trashed product are all NOT_FOUND.
- **`ProductService`**: the orchestration shared by the MCP and REST adapters: permissions, the NOT_FOUND rule (missing,
  wrong type, trashed and not editable all answer the same NOT_FOUND, 404 / -32602, so nothing leaks about which products
  exist), the error kinds (`forbidden`, `invalid`, `not_found`, `server`), and the rule that any `Throwable` from
  WooCommerce becomes one "WooCommerce could not be read" error and never escapes as a PHP fatal. It never touches
  WooCommerce itself and never logs; `McpHandler` and `RestController` are thin adapters that map its result and log usage.
- **Authorisation.** The API-key user still needs `edit_pages` (existing rule). `list_products` additionally requires
  `edit_products` and `edit_others_products` (admins and shop managers have both; plain editors do not), refused with 403
  / -32602 naming the capability. Each row is also re-checked with `edit_post`. `get_product` re-checks `edit_post`.
- **Never returned**: downloadable file names or URLs (paid content), the purchase note (shown to customers only after
  buying), customer and order data, product meta and custom fields (they stay behind `get_custom_fields`), any cost field, and
  the description and short description of a password-protected product (`password_protected: true`,
  `description_hidden: true`, no text).
- **Totals.** `by_status` comes from `wp_count_posts('product')` (cheap, exact; trash and auto-draft left out).
  `published_by_type` comes from the `product_type` term counts, which WordPress keeps for published products only, and is
  named that way on purpose.
- WooCommerce inactive: a normal success with `woocommerce: { active: false }`, no products and one message. It is not an error.
- Logged by `UsageTracker` as endpoints `list_products` and `get_product`, result `read`, or `error` for a refusal.

### 3. Counts in `get_site_structure`

Each public post type gains `count` (an integer): published items from `wp_count_posts` (`inherit` for attachments). This
answers "how many products do I have?" on any site and costs one cached call per type. Nothing else in the tool changes.

### 4. Guide and docs

- `StyleGuide` gets one short paragraph: before writing a shop page or product copy, call `list_products` (and
  `get_product` for one item) so prices, names and categories are real; never invent a product.
- The readme tool list gets the two tools (20 tools) and a 4.9.0 changelog entry; the FAQ says plainly that the free plugin
  only reads products and never changes a price or stock level.
- Tool descriptions in `McpHandler` say what is returned and what is never returned.

### 5. Compliance (`WpOrgComplianceTest`)

The 20-tool list is updated. A new assertion bans WooCommerce write calls anywhere in `wp-plugin/` (the setters on a
product such as `set_regular_price`, `set_sale_price`, `set_stock_quantity`, `set_stock_status`, `set_name`,
`set_description`, and `wc_update_product_stock`, `wc_create_product`, `wc_update_product`, `->save(` on a
product). The exact identifier list is the `WOO_WRITE_BAN` regex in the test (it also bans `set_props`, the sale-date setters,
`wc_reduce_stock_levels` / `wc_increase_stock_levels` and a direct `update_post_meta` / `add_post_meta` of `_price`,
`_regular_price`, `_sale_price`, `_stock` or `_stock_status`), with a planted-string test so a regex that never matches
cannot pass silently. `set_status` is deliberately NOT in it: `WP_REST_Response::set_status()` is ordinary code in this
plugin, and a product status write is caught by `->save(` and the other names. No existing rule is loosened. No licence gate, no remote call, no
new third-party host.

## Testing

- `ProductReader` unit tests for every product type (simple, variable, grouped, external), every cap (text cut, 100
  variations with the truncation flag, 20 images, 50 attributes), and the privacy rules (downloads, purchase note,
  password-protected text, meta never present).
- `ProductService` (missing, a variation id, a post of another type, a trashed product, not editable: all the same NOT_FOUND).
- `WooProductGateway` and the two handlers against WooCommerce shims, MCP and REST in lockstep, argument type checks
  (`on_sale` as a string is refused), capability refusals, WooCommerce inactive, a throwing WooCommerce.
- `SiteStructure` `count` per type; the OpenAPI spec does NOT contain the tools (lockstep test, as for
  `get_site_structure`); `WpOrgComplianceTest` with the new list and the write ban.
- Real-site gate `make verify-products` on the dev site's 18 real products (14 simple, 2 variable, 1 grouped, 1 external):
  totals match WooCommerce's own count, a throwaway draft, a password-protected product and a downloadable product are
  created and checked (the download URL and the text must not appear), a plain editor is refused, everything created is
  deleted, and the existing products are byte-identical afterwards (proof nothing was written). Dev site only.

## Out of scope

- Every write (price, stock, text, status, categories, variations, creation): sub-project B, Pro, own spec. Candidates, in
  build order: `update_products` (up to 25 products per call; price, sale price with schedule, stock quantity and status,
  backorders, titles and descriptions, variation rows; all-or-nothing, `dry_run`, one undoable batch),
  `bulk_adjust_prices` (percent or fixed change over a filter, with a preview token, like `find_replace_site`),
  `create_product` (always a draft). All MCP + REST only.
- Orders, customers, coupons, reports and reviews text: never in the free plugin.
- Divi's WooCommerce modules in layouts: 19 of them are still unverified (`docs/SCHEMA.md`); needs real exports first.
- ChatGPT Action parity: the 30-operation budget is used up.
- Filtering by price range, attribute or tag: possible later without changing the contract.

## Open points

- `published_by_type` relies on WooCommerce keeping `product_type` term counts current; the live gate compares it with a
  direct count, and if the two ever differ the plan switches to a counted query.
- Whether `get_product` should expose variation `image` URLs for variations without their own image (WooCommerce falls back
  to the parent): the first version returns the variation's own image or null.
- WooCommerce's wc_get_products() ignores an on_sale argument (checked on 11.1.2 with the dev site's 18 products: 7 are on sale, the argument returned all 18), so the on_sale filter scans at most 500 matching products in PHP and says so in notes when it was cut.

## Findings from the real site (WooCommerce 11.1.2, dev site, `make verify-products`)

- **`orderby=price` is ignored by WooCommerce.** `wc_get_products()` hands it to `WP_Query`, which sorts by date. The gateway
  therefore sorts by each product's lowest `_price` meta through a marked `posts_clauses` filter (for that one query only; it
  is always removed again in a `finally`). A descending order for a variable or grouped product therefore sorts by its LOWEST
  price (the `price` the row shows), whereas WooCommerce's shop sorts descending by the highest price.
- **`wc_product_meta_lookup` is not used** for that sort: the table was empty on the dev site, so a sort through it would be wrong
  there.
- **The write-ban identifier list** (section 5) excludes `set_status` on purpose, because `WP_REST_Response::set_status()` exists.
- **`wp_count_posts` totals can be stale** on a site with a persistent object cache after a published product is permanently
  deleted (WordPress core behaviour: the cached count is cleared on a status change only). `totals` come from it, so they can
  lag until the cache expires; the live gate clears the in-process cache for this reason.
- **Variation attribute values are slugs in WooCommerce.** `$variation->get_attributes()` returns the TERM SLUG for a taxonomy
  attribute (percent-encoded on a non-English store), while the product-level `options` carry the term names, so the AI would
  have read `{"Color": "red"}` next to options `["Red", "Blue"]`. A custom (non-taxonomy) attribute is keyed by its sanitised
  name unless `wc_attribute_label()` is given the parent product. The gateway maps a non-empty taxonomy value through
  `get_term_by( 'slug', ... )` to the term name (falling back to the raw value when no such term exists; an empty value, WooCommerce's
  "any", stays empty) and passes the parent product to `wc_attribute_label()`. The live gate checks every variation attribute
  value of every variable product against the real terms.
