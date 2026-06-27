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
