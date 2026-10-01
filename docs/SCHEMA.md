# Divi 5 Layout — Empirical Schema Documentation

> **Status: COMPLETE** — Written from real exports captured via `make export-layouts`.
> All findings are from Divi 5.8.0 running on WordPress 6.7 / PHP 8.3.
> Nothing here is inferred from Divi 4 or training data.

---

## Deviation from the original brief

The brief assumed Divi 5 uses a "JSON-based data structure". Reality: **Divi 5 stores layouts as WordPress Gutenberg block HTML** in `post_content`. The JSON is embedded inside block comment attributes, not the top-level format.

The validator and fixtures follow reality. The canonical "layout file" format is a JSON envelope containing `post_content` (see below).

---

## 1. Storage location

| Item | Value |
|---|---|
| Primary storage | `post_content` column (`wp_posts` table) |
| Format | WordPress Gutenberg block HTML (comment-delimited) |
| Key post meta | `_et_pb_use_divi_5 = on` — marks the page as Divi 5 |
| Key post meta | `_et_pb_use_builder = on` — Divi builder is active |
| Custom tables | None (no `wp_et_*` tables observed) |
| Divi Library | Uses `divi/layout` block type; `ET_Builder_Layout` class not present in Divi 5 |

---

## 2. Block format

Divi 5 stores content as **WordPress Gutenberg blocks** using HTML comment syntax:

```
<!-- wp:divi/BLOCKTYPE {JSON_ATTRS} -->   ← opening block (has children)
  ... child blocks ...
<!-- /wp:divi/BLOCKTYPE -->               ← closing block

<!-- wp:divi/BLOCKTYPE {JSON_ATTRS} /-->  ← self-closing block (leaf module)
```

The outer wrapper is always `divi/placeholder`.

---

## 3. Structural hierarchy

```
divi/placeholder              ← always the root wrapper
  divi/section                ← layout section (can have multiple)
    divi/row                  ← row within a section (can have multiple)
      divi/column             ← column within a row (can have multiple)
        [leaf modules]        ← content modules (self-closing)
        divi/row              ← OR a nested row (see below)
```

**Top-level forms** (confirmed across a real 25-page production site): a page's
root children are not always `divi/placeholder`. Real pages also place
`divi/section` blocks **directly** at the top level, and `divi/global-layout`
(Theme Builder global references — these carry no `builderVersion`). All three
are valid roots.

**More real modules** (confirmed on the production site): `divi/group` and
`divi/column-inner` act as general flex containers (same children as a column);
`divi/group-carousel` contains `divi/group`; `divi/code`, `divi/sidebar`,
`divi/testimonial` are leaf modules in a column; `divi/code` can also appear in
`divi/accordion`. A `divi/text` may be saved as a paired block wrapping nested
text/HTML (so text is the one leaf allowed to have children).

**Nested rows** (confirmed in real export `page-23-page-nested-structure.json`):
A `divi/column` may contain a `divi/row` — alongside leaf modules in the same
column — and this nests to arbitrary depth (`column → row → column → row → …`).
Nested rows are structurally identical to top-level rows (same `columnStructure`
attrs); there is no separate "specialty" block type. The validator allows
`divi/row` as a child of `divi/column` and recurses with the same rules.

**Known leaf module types** (observed in 5.8.0):
- `divi/heading`
- `divi/text`
- `divi/image`
- `divi/button`

**Structural blocks** (have children, use open/close pairs):
- `divi/placeholder` (root only)
- `divi/section`
- `divi/row`
- `divi/column`

**Also registered** (seen in block registry, not yet observed in page content):
- `divi/shortcode-module`
- `divi/layout`

---

## 4. Block attribute structure

Every block carries a `builderVersion` attribute and optional `module` object:

```json
{
  "builderVersion": "5.8.0",
  "module": {
    "advanced": { ... },
    "decoration": { ... }
  }
}
```

### 4a. `module.advanced` — layout/structure attributes

Observed on `divi/section`:
```json
"advanced": {}
```

Observed on `divi/row`:
```json
"advanced": {
  "columnStructure": {
    "desktop": { "value": "4_4" }
  },
  "flexColumnStructure": {
    "desktop": { "value": "equal-columns_1" }
  }
}
```

Observed on `divi/column`:
```json
"advanced": {
  "type": {
    "desktop": { "value": "4_4" }
  }
}
```

### 4b. `module.decoration` — visual attributes

Observed on `divi/row`:
```json
"decoration": {
  "layout": {
    "desktop": { "value": { "flexWrap": "nowrap" } }
  }
}
```

Observed on `divi/column`:
```json
"decoration": {
  "sizing": {
    "desktop": { "value": { "flexType": "24_24" } }
  }
}
```

---

## 5. Module-specific content keys

Each leaf module has its own top-level content attribute with an `innerContent` structure:

```json
{
  "CONTENT_KEY": {
    "innerContent": {
      "desktop": {
        "value": VALUE
      }
    }
  }
}
```

| Module | Content key | Value type | Example value |
|---|---|---|---|
| `divi/heading` | `title` | string | `"Your Title Goes Here"` |
| `divi/text` | `content` | string (HTML) | `"<p>Your content...</p>"` |
| `divi/image` | `image` | object | `{"src": "data:image/..."}` |
| `divi/button` | `button` | object | `{"text": "Click Here"}` |

**Critical**: the `value` for `divi/image` and `divi/button` is an **object**, not a scalar. Passing a scalar string where an object is expected is the deep-merge fatal case.

---

## 6. Responsive attribute pattern

All attribute values use a responsive wrapper:
```json
{
  "desktop": { "value": ... }
}
```

Additional breakpoints (`tablet`, `phone`) may exist but are not required.

---

## 7. Render-critical fields

Fields whose absence or wrong type cause Divi to fail to render:

| Field | Required on | Expected type | Fatal if wrong |
|---|---|---|---|
| `builderVersion` | Every block | string | Silent render fail |
| `title.innerContent.desktop.value` | `divi/heading` | string | Module not rendered |
| `content.innerContent.desktop.value` | `divi/text` | string | Module not rendered |
| `image.innerContent.desktop.value` | `divi/image` | object | Deep-merge PHP fatal |
| `button.innerContent.desktop.value` | `divi/button` | object | Deep-merge PHP fatal |

---

## 8. Fixture format (canonical "layout file")

Since the format is Gutenberg block HTML (not raw JSON), the canonical "layout file" wraps `post_content` in a JSON envelope:

```json
{
  "source": "divi5-real-export",
  "format": "gutenberg-blocks",
  "divi_version": "5.8.0",
  "post_id": 7,
  "post_content": "<!-- wp:divi/placeholder -->...",
  "divi_meta": {
    "_et_pb_use_divi_5": "on",
    "_et_pb_use_builder": "on"
  },
  "exported_at": "2026-06-25T..."
}
```

The validator reads the `post_content` field from this envelope and parses the block HTML.

---

## 9. Real export excerpt (page-7-homepage.json)

```
<!-- wp:divi/placeholder -->
<!-- wp:divi/section {"builderVersion":"5.8.0"} -->
<!-- wp:divi/row {"module":{"advanced":{"columnStructure":{"desktop":{"value":"4_4"}},...}},"builderVersion":"5.8.0"} -->
<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}},...},"builderVersion":"5.8.0"} -->
<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Your Title Goes Here"}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>...</p>"}}},"builderVersion":"5.8.0"} /-->
<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"data:image/..."}}}}, "builderVersion":"5.8.0"} /-->
<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Click Here"}}}},"builderVersion":"5.8.0"} /-->
<!-- /wp:divi/column -->
<!-- /wp:divi/row -->
<!-- /wp:divi/section -->
<!-- /wp:divi/placeholder -->
```

---

## 10. Divi 5.14 modules (render-verified)

- **Divi version:** 5.14.0 (`docs/module-verification-5.14.json`)
- **Verification date:** 2026-09-30
- **Tooling:** `make schema-gap` lists modules in `divi/Divi.zip` the validator does not know; `make verify-modules` renders every placeable candidate through real Divi in Docker (the still-missing modules **and** the already-promoted ones, so a Divi update re-proves the shipped schema) and writes the evidence file; it refuses to overwrite committed evidence with a result set that loses or demotes modules (`scripts/check-evidence.php`). `scripts/promote-modules.php` generates `src/VerifiedModules.php` (mirrored to `wp-plugin/validator/`) from that evidence. Never hand-edit the generated file.

### What "render-verified" means

Each promoted module was rendered through real Divi 5.14 and produced its own `et_pb_<name>` class without PHP diagnostics. That proves the module exists and renders. It does **not** prove the visual builder allows a given placement: the harness has no control for a placement Divi should reject, so rendering cannot discriminate placement (`placement_note` in the evidence file).

For that reason only the **column** placement is promoted (`section > row > column > module`), matching real-export precedent. Like every hand-written column module, promoted modules are also valid inside `divi/column-inner` and `divi/group`. **Section placement is deliberately not accepted**, even though the evidence shows these modules also rendered directly under a section. Accepting it would rest on rendering alone.

Child items are vouched for only through their parent's render: `divi/map-pin` (in `divi/fullwidth-map`), `divi/slide` (in `divi/fullwidth-slider`), `divi/post-filter-item` (in `divi/post-filter`) and `divi/video-slider-item` (in `divi/video-slider`). Child items are rejected when placed directly in a column or a section (verified: `divi/map-pin`, `divi/slide`, `divi/post-filter-item` and `divi/video-slider-item` each fail alone in both placements). They are **not**, however, confined to their parent. Like every existing child item (`divi/slide`, `divi/tab`, ...), they are accepted as children of any *leaf* module, because the validator does not restrict which children a leaf module may have. This is pre-existing behaviour, not something this release introduced. For example `divi/text > divi/video-slider-item` and `divi/map > divi/map-pin` both validate. Tightening leaf nesting is a separate future change.

### How verification was done

Each module was rendered via `apply_filters('the_content')` under WP-CLI inside the real Divi 5.14 site and classified by `RenderEvidence`. This is not an HTTP front-end fetch, so the `wp_head`/footer/enqueue paths are not exercised. A follow-up is a front-end smoke fetch of a page holding all 24 modules.

### Promoted modules (24)

Accepted in a column placement only. The evidence records that each of these also rendered directly under a section; that is not the placement the validator accepts.

- `divi/charts`
- `divi/comments`
- `divi/dropdown`
- `divi/filterable-portfolio`
- `divi/fullwidth-code`
- `divi/fullwidth-image`
- `divi/fullwidth-map`
- `divi/fullwidth-menu`
- `divi/fullwidth-portfolio`
- `divi/fullwidth-post-content`
- `divi/fullwidth-post-slider`
- `divi/fullwidth-post-title`
- `divi/fullwidth-slider`
- `divi/link`
- `divi/lottie`
- `divi/portfolio`
- `divi/post-content`
- `divi/post-filter`
- `divi/post-slider`
- `divi/post-title`
- `divi/svg`
- `divi/table-of-contents`
- `divi/tooltip`
- `divi/video-slider`

No candidate module failed outright (no `fail` results in the evidence).

### Known gaps (29 modules, all still rejected)

Divi 5.14 declares 115 modules; the validator still rejects 29 of them: the 28 harness-probed candidates below, plus one child item the harness never probes (see the end of this section).

These 28 candidates have status `needs-real-export` in the evidence: the harness found none of its module-specific class markers in the rendered output. None of them are accepted by the validator; they are rejected rather than guessed. The evidence records only that no marker was found. The groupings below are computed from the recorded render samples; statements marked *inference* are not established by the evidence.

#### WooCommerce modules whose output carries `et_pb_wc` classes the harness does not recognise (19)

The rendered sample contains `et_pb_wc*` classes, so these modules did render something, but not classes the harness's module-specific markers match. They need a real export or an explicit class map before they can be promoted.

- `divi/woocommerce-breadcrumb`
- `divi/woocommerce-cart-products`
- `divi/woocommerce-checkout-additional-info`
- `divi/woocommerce-checkout-billing`
- `divi/woocommerce-checkout-order-details`
- `divi/woocommerce-checkout-payment-info`
- `divi/woocommerce-checkout-shipping`
- `divi/woocommerce-cross-sells`
- `divi/woocommerce-product-add-to-cart`
- `divi/woocommerce-product-additional-info`
- `divi/woocommerce-product-description`
- `divi/woocommerce-product-gallery`
- `divi/woocommerce-product-images`
- `divi/woocommerce-product-meta`
- `divi/woocommerce-product-price`
- `divi/woocommerce-product-reviews`
- `divi/woocommerce-product-tabs`
- `divi/woocommerce-product-title`
- `divi/woocommerce-related-products`

#### WooCommerce modules that rendered an empty column (5)

The render produced only the empty section/row/column wrapper (about 330 bytes) with no module output at all. The cause is not established by the evidence. *Inference, unverified:* these modules may render nothing without cart, product or order context. They need a real export before they can be promoted.

- `divi/woocommerce-cart-notice`
- `divi/woocommerce-cart-totals`
- `divi/woocommerce-product-rating`
- `divi/woocommerce-product-stock`
- `divi/woocommerce-product-upsell`

#### Third-party plugin modules that rendered an empty column (4)

The render produced only the empty wrapper, and the evidence records just "output had none of the expected module markers". *Inference, unverified:* this is probably because the third-party plugin (Contact Form 7, Gravity Forms, Imagely/NextGEN Gallery, a payment provider) is not installed on the test site.

- `divi/contact-form-7`
- `divi/gravity-forms`
- `divi/imagely-gallery`
- `divi/payment-button`

#### Child of an already-known parent, not probed (1)

- `divi/signup-custom-field`: a child of the already-known `divi/signup`. It exists in Divi 5.14 but is still rejected. The candidate filter excludes child items and the promoter only promotes children of *missing* parents, so the harness does not probe children of known parents (follow-up). This is not evidence of any render problem.
