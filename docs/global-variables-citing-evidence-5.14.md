# Citing global variables in a layout: live evidence (Divi 5.14, Docker dev site, 2026-10-06)

Spike for phase B of `manage_global_variables` (spec `docs/superpowers/specs/2026-10-06-pro-global-variables-design.md`). Script: `scripts/spike-global-variables-citing.php` (dev sites only, self-cleaning: creates throwaway variables through the real Pro tool, builds throwaway PUBLISHED pages, fetches each page's HTML and same-site stylesheets, deletes everything, proves the stored variables are identical to the start). The owner also made a REAL builder page (page 17, `/newpage/`) with a numbers variable `12px` on a heading's font size, a global color on a text module and a Customizer font, which is the ground truth for the stored form.

## Answer

**Yes: a layout can cite a numbers variable (any length-like attribute) and a strings variable (heading title, text content), in the form Divi's builder itself stores, and Divi renders both.** Verified on a programmatic page for the builder's own variable and for variables written by our tool IF they carry the builder's stored shape. **Our tool does not write that shape yet, and Divi silently ignores such variables.** That is a bug in `manage_global_variables` 0.8.0 (unreleased) found by this spike.

## Ground truth: what the builder stores

Variable entry in the stored option (builder-made, numbers):
`{"id":"gvid-f7rcuxrcdb","label":"12px","value":"12px","order":"1","status":"active","lastUpdated":"2026-10-06T13:59:08.174Z","variableType":"numbers"}`
Our tool wrote only `label, value, order, status`.

Citation in the page content (a heading's font size, as stored by the builder; `"` is written `"` inside block attributes):
`"size":"$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-f7rcuxrcdb\",\"settings\":{}}})$"`
Rendered by Divi: `font-size:var(--gvid-f7rcuxrcdb)` plus `<style class="et-vb-global-data et-vb-global-numeric-vars">:root{--gvid-f7rcuxrcdb: 12px;}</style>`.
A global color is cited as `$variable({"type":"color","value":{"name":"gcid-...","settings":{}}})$` (already taught); the color `ai editor variable` rendered as `color:var(--gcid-kp4fwcc7jc)`. Ids written by the builder are short (`gvid-f7rcuxrcdb`); our tool writes `gvid-` + UUID, which Divi accepts (`^gvid-[a-z0-9-]+$`).
The builder's Customizer-font variable (`--et_global_heading_font`) is not stored in the option and was not cited by id in the page; fonts remain out of scope.

## Results of the probes (all pages written programmatically, all accepted by our gate `LayoutGate`)

| Variant | Divi's output |
|---|---|
| Control: literals | `padding-top:40px`, text shown |
| Our tool's variable (no `id`/`lastUpdated`/`variableType`), `content` form in padding top, font size, heading title, text content | **Dropped:** no `padding-top`, no text, no `gvid-` in the page; the `:root` definition is not emitted |
| Same, raw `var(--gvid-...)` in padding/font size | `var(--gvid-...)` used but **no definition emitted** |
| `content` form with `settings` `{"before":"","after":""}` for our unpatched strings | Nothing shown |
| **The builder's own variable** (`gvid-f7rcuxrcdb`), `content` form, same four places | `padding-top:var(--gvid-f7rcuxrcdb)`, font size `var(...)`, definition `--gvid-f7rcuxrcdb: 12px` emitted, heading and text show the string |
| **Our variables patched to the builder's shape** (`id`, `lastUpdated`, `variableType` added), `content` form | Same as the builder's: padding and font size use `var(--gvid-...)`, `--gvid-...: 40px` defined, the string appears in the `<h1>` and the text module |
| Patched variables ARCHIVED | **Identical to active:** still defined, still used, string still shown. Archiving does not break a page that cites it |
| A cited id that does not exist (`content` form) | **Dropped silently:** no `padding-top`, no text; no raw token leaks |

(An earlier "archived"/"missing" round used our unpatched shape and was not meaningful; the rows above come from the patched-shape rounds.)

## Consequences

1. **Fixed in branch fix/pro-variables-builder-shape** (the live gate `make verify-pro-global-variables` now publishes a throwaway page and proves Divi renders a cited variable). **Fix `manage_global_variables`** (Pro 0.8.0, not released): a created variable must carry `id` (= its key), `lastUpdated` (`Y-m-d\TH:i:s.v\Z`, as colors) and `variableType` (= its group); an edit must refresh `lastUpdated`. Its live gate must prove a cited variable actually renders (the unit tests and the old gate could not see this: Divi's own read and write accept the incomplete entry).
2. **The validator/gate cannot tell a working citation from a dead one** (a missing id is `valid` yet renders nothing), exactly like an unknown preset id. A non-blocking advisory for unknown `gvid-` ids (like `StyleReferences` for presets and colors) is worth building.
3. **Guides may teach the `content` citation form** for numbers (any attribute that takes a length) and strings (heading title, text content), traced to a real builder export (page 17) and a live render. Not proven: other attributes, other modules, `before`/`after` settings on strings, fonts, images, links, gradients.
4. Archived variables still render, so retiring a variable by archiving is safe for cited pages.
