# `html_to_divi`: deterministic HTML to Divi 5 conversion (free plugin)

Date: 2026-10-14. Status: approved by the owner's delegation ("find a way to integrate html to divi in this plugin,
answer your own question yourself").
Source: backlog item "HTML or AI-built site to Divi 5 workflow: a guided flow, or a converter alongside your existing
converter line."

## The question, answered

*Is converting HTML to Divi 5 inside this plugin related and feasible?* Yes, in a bounded form.

- Related: the plugin's whole point is that the AI can be creative while a deterministic gate keeps the layout valid.
  Turning a page of HTML the assistant (or the owner) already has into a valid Divi 5 draft is exactly that, and the
  assistant otherwise has to re-type the whole page as verbose Divi block JSON (slow, expensive in tokens, error prone).
- Feasible: PHP's DOM parser is built in, the plugin already owns proven module shapes (`data/section-recipes.json`,
  `fixtures/valid/`, `StyleGuide`) and the validator proves every result.
- Not the same product as `url-to-divi` (the owner's commercial converter): that one captures a LIVE page with full
  visual fidelity from the browser-rendered DOM and computed styles. This tool converts **supplied HTML markup** into a
  clean Divi 5 **structure with the real copy, links and images**, plus a small, conservative style subset. It does not
  fetch URLs, does not run JavaScript, and says plainly what it could not reproduce. Pixel-perfect capture stays in
  `url-to-divi`; the readme and guides point to it for that need.

## Goal and success criteria

`html_to_divi` takes HTML and returns (and optionally saves as a **draft**) a Divi 5 layout that:
1. always passes the validator (a conversion that cannot be made valid reports violations and creates nothing);
2. keeps every piece of the source's visible copy, in order, and every link target (verified by a test that compares
   the text content of source and result);
3. never carries custom code: no `<script>`, `<style>`, inline event handlers, `javascript:` URLs, iframes, forms or CSS
   leave the converter (they are listed in the report as "not converted" with the reason);
4. is deterministic: the same HTML gives byte-identical output (pinned by a test);
5. reports honestly: counts of converted elements and a list of everything flagged, each with its position.

## Design

### Tool (MCP + REST; read-only unless `create` is true)

`html_to_divi` arguments (strict types; non-strings refused, never cast):

| Argument | Rule |
|---|---|
| `html` | Required string, at most 400,000 bytes (UTF-8 required; a document or a fragment). |
| `title` | Optional string (page title). Required when `create` is true. If missing and the HTML has a `<title>` or one `<h1>`, that is suggested in the result, never silently used. |
| `create` | Optional strict boolean, default false. When true and the layout is valid, saves a new **draft** page through the same code path as `create_page` (so history, usage log, post-save checks and advisories are identical). |
| `image_mode` | `placeholder` (default) or `keep`. See Images. |
| `styles` | `none` or `basic` (default `basic`). `none` skips the style subset entirely. |

Result (JSON): `{ valid, layout (the Divi block markup, omitted when create saved it and layout_omitted is stated),
violations[], advisories{}, report{ elements{converted:{section,row,column,heading,text,image,button,divider,list…},
flagged_count}, flagged[{type,reason,path}], text_check{source_chars, result_chars, equal}, links[], images[{src,alt,
action}] , suggested_title }, page{id,status,edit_url} (only when created) }`. Everything is capped (flagged list 100
entries, each string 200 chars).

It requires the same capability as `create_page` (`edit_pages`; `create` additionally needs `publish`-less draft
creation like `create_page`). It is subject to approval mode only where `create_page` is (it is not: `create_page` is
exempt). It is logged as endpoint `html_to_divi` (result `read` for a preview, `created` for a save).

No remote calls, no URL arguments, no file arguments. The HTML is data.

### Units (`wp-plugin/src/`, pure, no WordPress functions except through injected seams)

| Unit | Responsibility |
|---|---|
| `HtmlSource` | Parses the string with `DOMDocument` (`LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT`, `loadHTML` with an XML encoding declaration trick for UTF-8, external entity loading never enabled, depth and node caps: 20 levels, 5,000 elements, over the cap is refused with a plain reason). Removes `script`, `style`, `noscript`, `template`, `iframe`, `object`, `embed`, `form` and their content, comments, event attributes and `javascript:`/`data:`/`vbscript:` URLs, and returns a **normalised tree** of small value objects (`Node{tag, attrs (allow-listed: href, src, alt, title, id, class, style, colspan…), children, text}`), recording each removal as a flagged item. Collects `<style>` text separately for the style subset (never emitted). |
| `HtmlSections` | Pure heuristics that turn the tree into a **section plan**: top-level landmarks (`header`, `main`'s children, `section`, `article`, `footer`, or the body's direct block children when none exist) become sections; `nav` is flagged ("menus are set by the site owner") and its link list reported, never converted to a menu; consecutive loose blocks merge into one section. Inside a section, a container whose children are 2 to 4 siblings with the same tag (or the same first class) and similar structure becomes one **row with N columns**; more than 4 repeated siblings are chunked into rows of 3 (or 2 when the first chunk shows 2-column content); anything else is a single column with its blocks in order. Depth is limited: wrappers that only hold one child are unwrapped. All thresholds are named constants with tests. |
| `HtmlModules` | Maps leaf content to modules: `h1`-`h6` -> `divi/heading` (level kept; a second `h1` is kept but advised), `p`, `blockquote`, `pre`/`code` (as text), inline-formatted runs -> `divi/text` with sanitised inner HTML (allow-list: `p br strong b em i u a(href,title,target=_blank gets rel=noopener) ul ol li span(no attrs) blockquote small sub sup h1-h6 table thead tbody tr th td`); `ul`/`ol` -> `divi/text` list HTML; `img` / `picture` -> `divi/image`; `a` or `button` that looks like a call to action (class contains btn/button/cta, role=button, or it is the sole child link of a block) -> `divi/button`; `hr` -> `divi/divider`; consecutive inline text merges into one text module; `video`, `audio`, `svg`, `canvas`, `map`, widgets: flagged ("not converted: <reason>"). Unknown elements are descended into (their text is never lost). |
| `DivMarkup` | The ONLY place that writes Divi block markup. Every module's attribute shape comes from a proven source: it starts from the exact shapes in `data/section-recipes.json` and `fixtures/valid/` (a test asserts that each template string equals a shape found in a recipe or fixture, so nothing is invented from memory). Output is `<!-- wp:divi/... {json} -->` with `builderVersion` as the recipes use, JSON via `wp_json_encode`-equivalent flags used elsewhere in the plugin, stable key order. |
| `HtmlStyles` (style subset, `styles=basic`) | A tiny deterministic CSS reader for inline `style=""` and simple rules in the collected `<style>` text: selectors limited to `tag`, `.class`, `#id`, and comma lists of those (anything with combinators, pseudo-classes, `@media`, `var()`, `calc()` is skipped and counted); specificity then source order. Properties mapped ONLY where the style guide teaches a proven shape: `color` (heading/text/button text), `background-color` (section/row/column/button), `text-align`, `font-size` (heading/text, px/rem/em to px), `font-weight` (bold/numeric), `max-width` (px), `padding` (px, per side). Colors accepted: `#rgb`, `#rrggbb`, `rgb()`, a CSS named-color list; anything else skipped. A color that exactly equals one of the site's global colors is NOT swapped (that is an AI/owner decision); unmapped properties are counted in the report ("styles ignored: N rules, e.g. flex, grid, gradients, animations"). A value that CodePolicy / the style-value guard would refuse is dropped, never emitted. |
| `HtmlImages` | `image_mode=placeholder`: every `img` whose `src` is not already a token or an attachment URL of this site gets a **built-in pack image** chosen deterministically (words from `alt`, file name and the section's heading are matched against the pack manifest's tags/keywords; ties by manifest order; fallback by section role), as the recipes store it (`{{aied:image:<token>}}`), and the original `src`/`alt` are listed in the report so the assistant or owner can swap in a Media Library image (`list_media_images`). `image_mode=keep`: the `src` is kept only when it is a root-relative or same-site URL that resolves to an attachment of this site (`attachment_url_to_postid`, injected seam); any other URL (external host, relative path) is still replaced by a placeholder and flagged, because third-party image hosts never reach the layout. Alt text is always kept. |
| `HtmlToDivi` | The facade: source -> sections -> modules -> styles -> markup, then `LayoutGate::check()` (the validator plus the custom-code policy, exactly as every write path), then, only if `create` is true and the layout is valid, the existing page-creation service used by `create_page` (extract it into a shared method if it is inline in `McpHandler`; do not duplicate it). Also computes `text_check` (normalised visible text of the source vs the result; whitespace-collapsed; flagged-removed text excluded and listed) and adds the existing `StyleReferences` advisories. |

### Wiring and lockstep

- `McpHandler` (tool definition + dispatch), `RestController` (`POST /html-to-divi`), both through one shared service
  class (`HtmlToDiviService`) so MCP and REST cannot drift. **MCP + REST only**: the ChatGPT importer budget is used up
  (like `get_site_structure`); add it to the documented exceptions list and pin the absence with
  `HtmlToDiviLockstepTest` as the other lockstep tests do. Tool count 20 -> 21; update every place that pins the count
  or the tool list (`WpOrgComplianceTest`, readme "What your AI assistant can do (21 tools)", `AdminPage` Features tab,
  the tool-list fixtures) with the narrowest justified change and say so in the commit message.
- `get_site_guide` (the guided flow added by the guide agent in this branch) gains one sentence pointing the
  assistant at `html_to_divi` for the mechanical part, keeping its own steps for judgement (mapping to the site's
  recipes, global styles, flags). `get_style_guide` is untouched.
- Docs: `docs/html-to-divi-workflow.md` rewritten around the tool; readme feature bullet, FAQ entry "Can it turn my HTML
  page into a Divi page?" with the honest limits and the pointer to the URL-capture converter line; changelog under
  4.10.0.

### Safety

- Input is never executed or fetched. Output passes `LayoutGate`; a failing conversion returns the violations and saves
  nothing (the tool cannot create an invalid page, by construction).
- Resource limits (size, depth, node count, time budget through node caps) prevent algorithmic abuse; `DOMDocument`
  with `LIBXML_NONET`, no `LIBXML_DTDLOAD`, no `LIBXML_NOENT`.
- Created pages are drafts; undo history works as for `create_page` (nothing to undo on creation).
- The style subset can never produce `css.freeForm`, `mainElement` or any custom-code attribute; a test scans all output
  with `CodePolicy`.

## Testing

- Unit tests per unit; a corpus of 12 hand-written HTML fixtures in `fixtures/html/` (landing page, blog article, pricing
  cards (3 repeated cards), feature grid (6 items chunked), FAQ list, footer-only fragment, nested divs soup, a page full
  of forbidden content (script/iframe/form/onclick/`javascript:`), a table, entities/RTL/UTF-8, an empty input, a huge
  input at the cap). Each fixture has: conversion succeeds or is refused as expected, **output validates**,
  determinism (run twice, byte-equal), text preservation (`text_check.equal`), no forbidden strings in the output, links
  preserved, golden file for the markup shape (review the first golden by eye).
- Property-style test: for 200 pseudo-random HTML trees (seeded generator over the allowed tag set) the output either
  validates or the converter refuses with a plain reason; it never throws and never emits a script.
- Grounding test: every module template equals a shape in the recipes or fixtures.
- Lockstep + compliance + no-custom-code tests stay green.
- Live gate `make verify-html-to-divi` (Docker, real Divi 5.14): converts every fixture, creates each as a throwaway
  draft through the real handler, renders each with the real Divi, asserts the visible text of the rendered page
  contains the source's text and that no `<script>` from the source appears, then deletes everything.
- A before/after screenshot of one converted fixture on the Docker site (headless browser as in
  `scripts/build-wporg-art.cjs`) saved to the scratchpad for the report.

## Phases (each independently shippable)

1. Structure, copy, links, images, report, `create` (no styles): `styles=none` behaviour.
2. The style subset (`styles=basic`).
Phase 1 must be complete and green before phase 2 starts.

## Out of scope

Fetching URLs (the plugin makes no remote calls); JavaScript-rendered pages; pixel-fidelity layout, flex/grid
reproduction, animations, forms, embeds, menus, WooCommerce/dynamic content (all listed in the report as not
converted); converting the other builders' formats; a multi-page site import (the assistant calls the tool once per
page, guided by `get_site_guide`).
