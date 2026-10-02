# 4.4.0 — Reuse the site's own presets and colors (read-only global styles)

Status: APPROVED by the owner on 2026-10-01 (all six decisions at the bottom, defaults) · Date: 2026-10-01 · Target release: JHMG AI Editor for Divi 5 4.4.0, built on top of 4.3.0 and **held** (nothing is uploaded; the 4.0.0 review is still pending).
Roadmap: undo (4.0.0) → history table (4.1.0) → dry-run diff (4.2.0) → header/footer (4.3.0) → **global styles (4.4.0)** → Pro add-on spec.

## Goal

Let an AI assistant build and edit layouts that **look like the site it is working on**: reuse the site's global colors and module presets instead of hard-coding colors or silently losing them. Today the AI cannot see either, and the recipes it is handed cite preset and color IDs that exist only on the demo site they were captured on.

## Evidence (spike, 2026-10-01; Divi 5.14 on the Docker dev site; nothing written to the site)

**Where Divi 5 stores them** (read from the shipped theme, `includes/builder-5/server/Packages/GlobalData/`, and confirmed in the dev DB):

| Data | Storage | Read API (public static, pure reads, in-memory cache) |
|---|---|---|
| Module presets | option `et_divi_builder_global_presets_d5`: `{ module: { "<divi/heading>": { default: <id>, items: { <uuid>: { id, moduleName, name, attrs, styleAttrs, renderAttrs?, … } } } } }` (dev site: 12 modules, 87 presets, 236 KB) | `GlobalPreset::get_data()` |
| Global colors | `et_divi['et_global_data']['global_colors']`, key `gcid-…`, value `{ color, label, status, … }`; the five Customizer colors (`gcid-heading-color`, `gcid-body-color`, `gcid-primary-color`, `gcid-secondary-color`, `gcid-link-color`) are always present, generated from theme options | `GlobalData::get_global_colors()` |
| Global variables (numbers, strings, images, links, fonts, gradients) | option `et_divi_global_variables`: `{ <type>: { "gvid-…": { label, value, order, status } } }` (dev site: empty) | `GlobalData::get_global_variables()` |

**Writing is outside the free plugin.** Divi's own preset save requires `edit_theme_options` plus Visual Builder access, and all three are site-wide design settings. Creating or editing them stays Pro add-on territory (the WordPress.org line on site settings). This spec only **reads**.

**How layouts reference them (real exports, `fixtures/valid/`):** `"modulePreset": ["<id>"]` (an array, a stack; `["default"]` 424 times) and colors as `$variable({"type":"color","value":{"name":"gcid-…","settings":{}}})$` (102 times). There is no `groupPreset` in any export, and no export contains a non-color `$variable` (numbers, strings, fonts…). The dev site has no group presets and no variables.

**What happens when the reference does not exist** (live render on the dev site, a throwaway published page, deleted afterwards; Divi's code agrees):
- Unknown `modulePreset` id → Divi silently falls back to the module's **default preset**: the unknown-preset heading rendered as `<h1>` exactly like the explicit `["default"]` heading, while the known "Heading 3" preset rendered `<h3>`.
- Unknown `gcid-…` → the page CSS contains `color: var(--gcid-0000…)` with **no definition** of that variable, so the colour is silently lost (the property is invalid at computed-value time and the text inherits).
- The validator gives the same verdict for unknown and known ids (checked with `Validator::validateContent`): it is agnostic, and must stay so (it is deterministic and knows nothing about a particular site).

**The gap in what we ship:** the 17 recipe layouts contain 28 blocks citing non-default preset ids (27 of them also carry inline attributes, 1 is preset-only) and 15 distinct `gcid-…` ids (2 of them, `gcid-heading-color` and `gcid-primary-color`, are always-present Customizer ids; the other 13 are custom ids that were not individually checked against the dev site's color list). All 25 distinct recipe preset ids exist on the dev site, which is where they were captured, and nowhere else is guaranteed. On a customer site the presets degrade silently to the module default and the custom colors to an undefined color variable, so the page does not look the way the recipe was verified.

**Not proven (stated honestly):** variables of types other than color (their reference syntax appears in no real export, so this plugin lists them but never teaches how to reference them); group presets; how Divi's own preset *runtime migration* treats very old preset formats (we only read id, name and the attribute areas, so it does not affect us).

## Scope

In:
- One new **read-only** tool `list_global_styles` (MCP) / `GET /global-styles` (REST) / `listGlobalStyles` (OpenAPI): the site's module presets, global colors and global variables.
- A non-blocking **advisory** on the layout tools: when a layout cites a preset id or a `gcid-…` that does not exist on this site, say so, with the ids.
- Guide updates: `StyleGuide` (reuse site colors and presets, with the one grounded reference shape) and `SectionRecipes` (the recipes' ids come from a demo site; swap them).

Out — never in the free plugin:
- creating, editing, deleting, importing or exporting presets, global colors or variables; anything that writes `et_global_data`, the presets option or the variables option (Pro add-on).
- teaching or checking non-color variable references (no real-export grounding).
- changing the validator (`src/` and `wp-plugin/validator/` stay untouched and in sync): an unknown reference is **not** a violation, because Divi renders the layout (with the fallback above).
- rewriting the recipe data: the ids in `data/section-recipes.json` stay as captured (stripping them would change what was verified); the advisory and the guides do the work.

## Design

### `GlobalStyles` (new, pure and read-only; `wp-plugin/src/GlobalStyles.php`)

Same shape as `ThemeLayouts`: the WordPress/Divi reads are injected so the logic is unit-testable.

- `catalogWith( ?callable $presets, ?callable $colors, ?callable $variables ): array` — each reader is Divi's function, or `null` when Divi's class is missing (then that part is empty and the result says so). `catalog()` wires the three real Divi static methods behind `class_exists` / `method_exists` guards. No `get_option`, no storage names: Divi's own read API is the contract, so a change in storage does not break us, and a missing API fails closed to empty.
- Presets: for every module, every item that is an array with a string `id` and string `name` (anything else, e.g. the stray `"0"` entry found on the dev site, is skipped, never an error). Output per preset: `{ id, module, name, default: bool }`, plus `controls`: the distinct attribute areas the preset sets, as short dotted paths such as `title.decoration.font` (depth ≤ 3, ≤ 12 per preset), and `renders` when `renderAttrs` sets something structural (for example `{ "headingLevel": "h3" }` is shown as `heading level h3`). No raw attribute dump: the point is to let the AI choose, not to ship 236 KB. Sorted by module then name.
- Colors: only `status: active` entries. `{ id, label, color, source: "theme"|"custom" }`, where `theme` is one of the five Customizer ids. Sorted theme first, then by label.
- Variables: only active entries, per type: `{ id, label, value }`, with the note that only colors can be referenced from a layout by this plugin.
- Optional input `module` (string, type-checked like every string argument: a non-string is 400 / -32602, never cast) filters presets to one module name (for example `divi/button`).
- Caps: ≤ 300 presets, ≤ 200 colors, ≤ 100 variables per type; if cut, `truncated: true` and a note saying how to filter. All values cut to 200 characters.
- Result: `{ presets: [...], colors: [...], variables: { <type>: [...] }, counts: {...}, truncated: bool, notes: [...] }`. `notes` states plainly when Divi's API is unavailable or when the site has no custom presets/colors/variables, and always says: "Read-only. To create or change presets or colors, use Divi's Visual Builder."
- Authorisation: the plugin-wide key rule (`edit_pages`, as for the other read tools). These are design vocabulary with no secrets, and the user can already see them in the builder. (Divi's own preset API wants `edit_theme_options`; see the decisions below.)
- Logged as endpoint `list_global_styles`, result `read`.

### `StyleReferences` (new, pure; `wp-plugin/src/StyleReferences.php`)

`StyleReferences::advise( string $content, array $catalog ): ?array` — returns `null` when everything resolves (the common case: the response stays byte-identical to today).

- Walks the layout with the validator's own `BlockParser` (read-only use of the bundled validator, as `LayoutDiff` does; no change to it).
- Collects `modulePreset` values (a string or a list of strings; `default` and the empty string are never "unknown") and every `gcid-<chars>` token found in any string attribute value (this covers the `$variable(...)$` form and any bare id).
- A preset id is **known** if it appears under ANY module in the catalog (ids are UUIDs, so a cross-module collision is not a concern, and it avoids re-implementing Divi's module-name conversion, whose false positives would be worse than a missed advisory). A color id is known if it is in the catalog's colors (including inactive ones: Divi still defines them).
- Output: `{ unknown_presets: [ { id, count } ], unknown_colors: [ { id, count } ], message }`, ≤ 20 ids per list. The message is fixed text: "These ids do not exist on this site; Divi falls back to the module's default preset (presets) or leaves the color undefined (colors). Call list_global_styles and use this site's own ids." It never changes `valid`, never blocks a save, and never alters stored content.
- Attached as `advisories.style_references` in the responses of `validate_layout`, `update_page_layout`, `edit_page_content`, `create_page` and the `dry_run` preview (and the REST equivalents) **only when non-null**. It reads the catalog once per request. If the catalog is unavailable (no Divi API) nothing is attached.

### Tools (MCP, REST, ChatGPT OpenAPI in lockstep)

- **New:** `list_global_styles` / `GET /global-styles` / `listGlobalStyles`. Tool count 16 → **17**, OpenAPI operations 16 → **17** (cap 30). `WpOrgComplianceTest`'s pinned tool list and the readme's tool count are updated with the justification: a new **read-only** tool, nothing gated, nothing that writes settings.
- Existing tools: the optional `advisories` field described above; their descriptions gain one sentence. OpenAPI response schemas document it (≤ 300-char descriptions, no bare objects, per the existing OpenAPI rules).

### Guides

- `StyleGuide`: a short section "Match the site": call `list_global_styles` before styling; reuse a site color through the real reference shape (the `$variable({"type":"color","value":{"name":"gcid-…","settings":{}}})$` form from the exports, as already shown in the guide's colour examples — only ids from the tool, never invented); a module with no `modulePreset` (or `["default"]`) already gets the site's default preset for its type, so most modules need nothing; choose a named preset only when its name and `controls` fit; never invent an id.
- `SectionRecipes` (served text, not the data): one paragraph: "The preset and color ids inside these recipes come from a demo site. On this site they may not exist: swap them for this site's ids (list_global_styles) or remove the `modulePreset`." The recipe JSON is unchanged, and the test that re-validates every recipe still passes (the validator is agnostic).
- No Pro mention, no licence wording (the compliance scanner bans those words in the plugin).

### Readme and Dashboard

- Readme: add the tool line and one sentence under the permissions/limits text: the plugin can read your site's presets and colors; it cannot create or change them. Update the tool count and the "Features" description. No "menu"/"front page" wording (the compliance test's rule). A short changelog entry for 4.4.0.
- Dashboard "Features" card: one more row ("Match your site's colors and presets"). The `wporg-assets/` Features screenshot is already stale from 4.3.0; one refresh covers both.

## Compliance

- No new storage, option, table, remote call or licence wording. Divi's functions are only *read* (`get_data`, `get_global_colors`, `get_global_variables`); none of them writes (checked in the source: in-memory caches only).
- No change to what the plugin can *write*: the free plugin still never writes presets, colors, variables or any site setting. `WpOrgComplianceTest` gets a new guard that no source file in `wp-plugin/` calls `GlobalPreset::save_data`, `process_presets`, `delete_data`, `set_global_colors`, `set_global_variables` or `import_global_variables`, or writes the three option names.

## Testing

- Pure/unit: `GlobalStyles` against shims (all three readers present/absent/throwing; malformed entries such as the `"0"` module, non-array items, missing ids/names; inactive colors; caps and `truncated`; `module` filter; sorting; the `controls` and `renders` summaries); `StyleReferences` (string and list `modulePreset`, `default`, empty, nested modules, `gcid` inside `$variable(...)$`, unknown vs known vs inactive, multiple occurrences counted, ≤ 20 ids, null when clean, null when catalog unavailable, never alters its input).
- Lockstep + handlers: `list_global_styles` in MCP, REST and OpenAPI (`GlobalStylesLockstepTest`, like `ThemeLayoutsLockstepTest`); the advisory present on all five layout tools and their dry-run preview exactly when unknown ids exist, absent (response byte-identical to today) otherwise; `valid` and the save outcome unchanged by an advisory; a non-string `module` rejected 400 / -32602; a user without `edit_pages` refused.
- Real site (`make verify-global-styles`, Docker): drives the REAL REST and MCP handlers: the live catalog matches Divi's own read API (counts, the five theme colors, the default preset ids, the stray `"0"` entry tolerated); a throwaway draft page citing a known preset + color and an unknown preset + color gets the advisory for exactly the unknown ones; and the three option values are **byte-identical before and after** (the read-only proof). It creates and deletes one draft page, like `verify-dry-run`.
- Plugin Check clean on the dev copy and the extracted zip; zip rebuilt; version bump to 4.4.0 in the header, `AI_EDITOR_DIVI5_VERSION` and readme (Stable tag + Changelog).

## Release

Built and merged locally as 4.4.0, **not uploaded**. Order unchanged: 4.1.0 + 4.2.0 first (cut from 9e806a3), 4.3.0 and 4.4.0 as later updates after the first WordPress.org approval. 4.4.0 carries no new write capability, so it adds little review surface.

## Risks / things to decide

1. **Divi internals.** The three classes are internal to Divi 5. Each call is `class_exists` / `method_exists` guarded and wrapped so an exception or a missing class yields an empty section plus a note, never a fatal error.
2. **Advisory noise.** `edit_page_content` on a page that already contains foreign ids will mention them on every edit. Accepted: it is truthful, capped, and non-blocking.
3. **Presets that silently restyle.** The site's default presets apply to every module without a `modulePreset`, so an AI layout on a heavily styled site inherits that look (a feature, and now documented in the guide).
4. **Variables other than color** are listed but unexplained by design (no real-export grounding). If the owner supplies a real export that uses number/string/font variables, a follow-up can ground and teach them.
5. **Performance.** The presets option is 236 KB on the dev site; it is read once per request and summarised, never returned raw.

## Decisions I need from the owner (defaults if you just say "approved")

- Read-only only; creating/editing presets, colors or variables stays out of the free plugin: **yes** (default).
- Advisory is non-blocking and never changes `valid` or the save: **yes** (default; blocking would refuse layouts Divi renders fine).
- Recipe data keeps its demo ids; the guide and the advisory handle the swap: **yes** (default).
- Authorisation is the plugin-wide `edit_pages` rule, not Divi's stricter `edit_theme_options`: **yes** (default). The alternative is to require `edit_theme_options` too, which would hide the catalog from Editors who can already see the presets in the builder.
- Non-color variables listed, but not taught or checked: **yes** (default).
- Built now, held, version 4.4.0 on top of 4.3.0: **yes** (default).
