# Structured data (schema.org JSON-LD) per page: Pro `set_page_schema` (design, NOT built)

Date: 2026-10-14. Status: **specced, not built.** It changes what customer sites print in `<head>` and collides with SEO
plugins, so the decisions below need the owner before any code is written.
Source: backlog item "Schema / structured data per page (Pro candidate)".

## Goal

The assistant can attach correct schema.org structured data to one page ("add FAQ schema to this page", "mark this as a local
business") without anyone writing JSON-LD by hand, and without the plugin ever saving arbitrary script.

## Why Pro, why a typed tool

- Pro, not free: it writes post meta and prints on the front end. The free plugin is read-only for metadata (`WpOrgComplianceTest`
  bans the meta-write functions) and never saves script.
- JSON-LD is **data**, not code, if (and only if) it is generated from typed fields. The tool never accepts a raw JSON-LD string:
  the assistant sends fields for one of a small set of types; the add-on builds the JSON with `wp_json_encode` (with
  `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` so nothing can close the `<script>`), and prints it itself.

## Design

**Tool** `set_page_schema` (MCP + REST only, like the other late Pro tools; `ProOpenApi::MCP_REST_ONLY`): arguments `page_id`, `type`
and the type's typed `fields`, plus `dry_run` (strict boolean) and the usual Pro batch/undo (one new item kind `page_schema`).
`type` is one of a fixed list; anything else is refused by name.

| Type | Typed fields | Check against the page |
|---|---|---|
| `FAQPage` | `questions`: list of `{question, answer}` (1 to 20) | every question text must appear in the page's visible text (Google requires the markup to match visible content) |
| `Article` / `BlogPosting` | `headline`, `author_name`, `date_published`, `date_modified`, `image` (Media Library id) | headline appears on the page; image is an attachment the user may read |
| `LocalBusiness` | `name`, `address` parts, `telephone`, `opening_hours` (structured), `geo`, `image`, `url` | name appears on the page; telephone format |
| `Organization` | `name`, `url`, `logo` (Media Library id), `same_as` (https URLs) | |
| `Product` | WooCommerce product id only; fields are READ from the product (price, availability, sku), never typed | needs WooCommerce; reads through the free `get_product` rules |

No `Review`/`AggregateRating` (fabricated ratings are the classic abuse), no `Event`, no free-form types.

**Storage and output.** Meta key `_aied_pro_schema` (JSON of the typed fields, versioned), written only by the tool. The add-on prints
`<script type="application/ld+json">` in `wp_head` for singular views of that page. A second tool call replaces it; `set_page_schema`
with `type: "none"` removes it. Undo through the existing batch store restores the previous meta value.

**Safety.** All strings are plain text (tags stripped, length-capped); URLs must be http(s); dates ISO 8601; numbers bounded.
The printed JSON is re-validated against the type's schema before it is output (a broken stored value prints nothing). The tool
reports what it will print (`dry_run` returns the exact JSON-LD).

## Decisions the owner must make first

1. **SEO plugin collisions.** Yoast, Rank Math and AIOSEO already print a JSON-LD graph. Options: (a) detect an active SEO plugin
   and refuse with a plain explanation (simplest, safest); (b) print alongside and risk duplicate or conflicting entities;
   (c) merge into their graph through their filters (per-plugin work, brittle). Recommendation: (a), with a note on the Pro tab.
2. **Is a front-end output change acceptable in this add-on at all?** Today it only edits stored content and settings.
3. **FAQ rich results are now limited by Google to a few site types.** Ship `FAQPage` anyway (still valid structured data) or
   leave it out of the first version?
4. **Scope of the first version:** `FAQPage` + `LocalBusiness` + `Organization` only, adding the rest on demand.

## Testing (when built)

Unit: every type's builder (golden JSON per type), refusal of every unsafe input (script-closing strings, `javascript:` URLs,
oversized lists, wrong types, unknown keys), the printed output never contains `</script>`, the visible-text check, no write for
`dry_run`, batch undo restores the old meta. Live gate `make verify-pro-schema` on the dev site: save each type on a throwaway page,
fetch the front end, parse the JSON-LD out of the HTML, validate it, confirm only one block, undo, and confirm it is absent for a
different page and for logged-out users of an unpublished page. Self-cleaning; dev site only.

## Out of scope

Reading or merging an SEO plugin's graph, bulk schema across the site, schema for archives or the home page, any raw JSON-LD input.
