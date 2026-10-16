# Divi 5.15.1 currency, and how to stay ahead

Status: BUILT the same day on branch `feat/divi-515-currency` (see "Outcome" at the end; not merged, not released) · Date: 2026-10-16 · Divi studied: 5.15.1 (changelog dated 2026-10-10), compared with 5.14.0
Targets: Pro 0.12.1 (hotfix), free 4.12.0, then the items below in order.

## Goal

1. Make every claim the plugins make true on Divi 5.15.1, with evidence.
2. Support what 5.15 changed in how pages are built (Components and the Slot module) without weakening the gate.
3. Pick the few features that keep this product ahead of Divi's own AI Agent, Respira, WPVibe and Novamira.

## What was done and proven on 2026-10-16

The Docker site now runs Divi 5.15.1 (`divi/Divi.zip` is the 5.15.1 build; the 5.14.0 build is kept as `divi/Divi copy.zip`).

| Check | Result |
|---|---|
| `make schema-gap` | 116 modules declared; only **one** is new since 5.14: `divi/global-slot`. The other 28 "missing" are the same WooCommerce/third-party modules as before. |
| `make verify-modules` | 53 candidates, **no status changed** against `docs/module-verification-5.14.json`. Evidence written to `docs/module-verification-5.15.json` (new, uncommitted). `divi/global-slot` is not a candidate: the harness skips category `structure`. |
| `make test` | 1757 tests, 11614 assertions, OK (the suite does not need Divi, so this proves the validator is unchanged, not that 5.15.1 works). |
| `make verify-html-to-divi` | All fixtures saved as drafts, validated and rendered by 5.15.1: PASS. |
| `make verify-guide-shapes` | Per-device shapes, equal-size images and phone stacking: PASS on 5.15.1. |
| `make verify-global-styles`, `verify-page-title`, `verify-history` | PASS (75 PASS, 0 FAIL for the title gate). |
| `make verify-pro-global-presets`, `-global-colors`, `-theme-layouts` | PASS. |
| **`make verify-pro-global-variables`** | **FAIL, a real regression: see F1.** |
| `make verify-pro-skeleton` | 4 FAIL, all about the licence ("locked, no_key"): the dev site has a licence activated, so the gate's premise does not hold here. Not a Divi issue; not investigated further. |

Not run: `make export-layouts` (it rewrites the committed fixtures from pages saved on 5.14 and would only change the version stamp), `verify-oauth`, `verify-products`, `verify-pro-*` for products, site tools, build_site and custom fields (not touched by anything in the 5.15 diff, but not re-run either).

### Incident on the dev site (disclosed)

While cleaning up after the failed variables gate I rewrote the stored global variables with a read that returned the wrong shape, and removed the site's 5 variables. Restored: `gvid-f7rcuxrcdb` ("12px", the owner's variable that page 17 and `docs/global-variables-citing-evidence-5.14.md` rely on), rebuilt from the evidence doc. **Not recoverable:** four variables with UUID ids (2 numbers, 2 strings) whose values were never written down. Their only trace is a stale footer-template CSS cache, so they look like leftovers of earlier test runs, but that is an inference. Nothing in the repo references them. If any of them mattered, they have to be recreated by hand. Lesson recorded: never rewrite the variables option directly, and never "clean up" after a gate by hand-writing storage.

## Findings

### F1. Divi 5.15.1 can no longer delete a global variable through its save path (breaks Pro `manage_global_variables`)

`GlobalData::set_global_variables()` now returns a `WP_Error` (`global_variable_ids_missing`, HTTP 400) and writes nothing when the payload omits any existing `gvid-` id. The Divi source says why: "deletion is implemented by archiving variables, so ordinary saves must preserve every existing ID." It also validates every new or changed variable (malformed data, unknown status, empty active value) and takes a 5-second write lock. Confirmed live with a direct call on the dev site.

Impact on Pro 0.12.0 (`pro-addon/src/Settings/GlobalVariables.php::write()`):
- It ignores the return value of `set_global_variables()`. It does read back the stored state, so a failed write shows up as a mismatch rather than silent success, but the message cannot say why.
- **Undo of a "create" removes the created variables by omitting them from the map. On 5.15.1 that write is refused.** The undo cannot complete.
- The gate `verify-pro-global-variables` seeds two throwaway variables and removes them at the end; on 5.15.1 the removal is refused, so the gate leaves them behind. It must be changed before it is run again (see work item 1).

Creating, editing, reordering and archiving still work (additions and changes are allowed). Colors, presets and Theme Builder layouts are not affected (gates pass).

### F2. Components and the Slot module change what a saved page can contain

5.15.0 turned global modules into "Components": nesting, selective sync on nested attributes, local editing mode, a new `divi/global-slot` module (a placeholder inside a component where each instance puts its own content), import/export with layouts, and a migration (`GlobalModuleSyncNodeMigration`, `global-module-sync-nodes.v1`) that adds `syncNodeId` values and explicit `localAttrs` ownership to existing instances. From Divi's source (not yet from an export):
- An instance references its component with a `globalModule` attribute and carries a snapshot plus `localAttrs`.
- Slot content sits inside the instance as `divi/global-slot` blocks keyed by a `slotId` attribute, and is grafted into the component template at render time (`GlobalSlotUtils::collect_payloads` / `apply_payloads`).
- `syncNodeId` and `localChildren: true` appear as block attributes.

What the validator does today: it accepts `divi/global-layout` at the top level and inside a section (Theme Builder references, real-export precedent) and has no rule for `divi/global-slot`. **A page that contains a component instance with filled slots will probably be refused, so the AI could not edit it.** That is a guess from the source; it has to be proven with a real export (work item 2) before anything is promoted. The grounding rule stands: a `module.json` entry is not enough; we need a real export plus a live render.

### F3. Divi ships every one to two weeks

5.13 (Sep 11), 5.13.1 (Sep 18), 5.14 (Sep 28), 5.15.0 (Oct 9), 5.15.1 (Oct 10). Today's comparison took one session by hand. The product's claim ("rules come from real Divi 5") is only as good as how fast it follows. The plugin already shows an admin notice when the installed Divi is newer than `DiviCompat::TESTED`; the gap is on our side, in the time to re-verify.

### F4. Where the competition is moving (from `docs/competitor-respira-and-extra-2026-10.md`, retrieved 2026-10-14)

- Respira and Novamira both shipped a check that stops an edit that removes most of a page's text or images (Aug and Oct 2026). We have a deterministic validator but no "content loss" signal.
- Divi's own AI Agent just cut tokens (page-wide image replacement, only the latest layout kept in later requests) and gained component tools. Our median page costs about 12,500 tokens to read (`docs/token-usage.md`).
- Respira's "duplicate the page, edit the copy, review the diff" is a sandbox story. We have dry run, approval mode and per-write undo. We should not copy the sandbox, but we should not leave the comparison unanswered either.
- Divi 5.15.1 fixed "frontend styles breaking on a layout when a spacing value contained an invalid character". That is exactly the class of fault a deterministic gate can catch before it is saved.

## Work items, in order

### 1. Pro 0.12.1: variable undo and error reporting (hotfix, do first)

Why: a shipped, paid tool cannot finish an undo on the newest Divi (F1).
- **Undo of a create archives** the variable it created (status `archived`) instead of removing it. This is what Divi itself does for "delete". The undo result says "archived, not deleted" in plain words, and a later undo of that undo makes it active again. `BatchUndo`'s changed-since rule stays: an entry edited after the create is left alone.
- `GlobalVariables::write()` reads the `WP_Error` from `set_global_variables()` and returns its code and message, so the tool reports "Divi refused the save: <reason>" instead of a bare mismatch. Same for the new change validation (malformed value, unknown status, empty active value): check before writing where we can, and always surface Divi's message.
- Keep working on Divi 5.14 and 5.15.0, where the guard may not exist (the changelog lists it under 5.15.1 only; the tool must not assume either way). Probe behaviour, do not compare version numbers.
- Bump `Compat` tested version, README and `readme.txt` text for the Pro tool ("undo archives").
- **Gate rewrite:** `verify-pro-global-variables.php` seeds and cleanup must use the same rule (seed variables get archived, and the script reports that the dev site now holds archived leftovers) or, for the dev site only, restore the exact stored option from a snapshot taken at the start. No hand-written storage writes outside that snapshot restore.
- Acceptance: gate green on 5.14 and 5.15.1 sites; unit tests for "Divi refuses" and "undo archives"; `make test-pro` green; Pro release routine in `CLAUDE.md`.

### 2. Components and Slot: real export first, then the validator (blocks everything about components)

1. **Evidence (needs the builder UI; owner or a Playwright run like `scripts/verify-guide-shapes.cjs`):** on the 5.15.1 dev site build and save (a) a plain component of a section, (b) a component with one Slot, (c) a page with an instance whose slot is empty, (d) the same with the slot filled by a text and a button, (e) a nested component, (f) a Theme Builder layout using a component. Export each page's `post_content` to `fixtures/valid/component-*.json`, stamped with `divi_version`. Also export the component's own `et_pb_layout` content.
2. **Observe:** run `make validate` on each. Whatever is refused tells us exactly what is missing, instead of guessing.
3. **Derive rules from the exports:** where `divi/global-slot` may appear (inside an instance? inside a template only?), which attributes the instance blocks carry (`globalModule`, `localAttrs`, `syncNodeId`, `slotId`, `localChildren`), and whether the instance sits as a section, a row, or a `divi/global-layout`.
4. **Render proof:** extend `scripts/verify-modules.php` (or add `verify-components`) so the slot case renders on the live site, with the same pass/fail record, written to `docs/module-verification-5.15.json`.
5. **Promote** through `scripts/promote-modules.php` (never by hand; `src/VerifiedModules.php` stays generated) and mirror to `wp-plugin/validator/`.
6. Tests: each fixture validates; an instance with a slot inside a column is rejected; a slot with no `slotId` is rejected (only if the exports show it is required).
- Acceptance: every component fixture passes `make validate`, `make test` green, the two validator copies identical, `docs/SCHEMA.md` updated, block-type count in `readme.txt` checked by `WpOrgComplianceTest`.
- Risk: if exports show instances are stored as something the section/row/column model cannot express, the honest outcome is "the plugin reads and edits around them but refuses to rewrite them", not a loosened gate.

### 3. Free 4.12.0: "verified on Divi 5.15"

Only after item 2 has either landed or been consciously deferred (see owner decision A).
- `DiviCompat::TESTED` to `5.15`; readme text "verified … in real Divi 5.14" and the guide strings that say "Verified on Divi 5.14" updated to what was re-proven today (the guide-shapes gate passed on 5.15.1; copy `docs/guide-shapes-evidence-5.14.md` to a 5.15 version with the new run).
- Commit `docs/module-verification-5.15.json`; confirm the generated `VerifiedModules` does not change (no status moved).
- `CLAUDE.md` "Current state": Divi 5.15.1 studied, this spec, the F1 and F2 findings.
- Changelog only claims what is shipped: "verified on Divi 5.15.1", plus whatever item 2 delivers.
- Gates: `make test`, `make verify-modules`, `verify-html-to-divi`, `verify-guide-shapes`, `verify-global-styles`, `verify-page-title`, `verify-history`, Plugin Check clean on the dev copy and the extracted zip.

### 4. Component awareness in the AI's view (free, read-only; after item 2)

- **Reading:** `get_site_structure` gets a `components` section (id, name, where used, slot ids) read only through Divi's own read functions, behind `class_exists`/`is_callable` guards, as `GlobalStyles` does. No new tool, so the ChatGPT importer budget stays untouched (MCP + REST only, like the other site-structure data).
- **Advisory (non-blocking):** `advisories.components` on `validate_layout`, saves and dry runs when the AI changes an attribute that the component owns (the instance snapshot is overwritten from the template at render time, so the edit would silently do nothing) or removes a slot's content. It never changes `valid`, same contract as `style_references`.
- **Guide text:** a short "components" section in `get_style_guide`: how to recognise an instance, what to edit (local attributes and slot content), what not to rewrite. Every concrete shape in it must come from the item 2 exports and be re-validated in the suite.
- Acceptance: lockstep tests for MCP and REST, advisory tests from the real fixtures, `make verify-site-structure` extended.

### 5. A deterministic "content loss" signal (table stakes, cheap, fits the product)

- `LayoutDiff` already compares before and after. Add counts of visible text characters, headings, images, links and buttons, and a `content_loss` advisory in the dry-run and save result when a save removes more than a threshold (proposed: more than 50% of text characters, or more than half of the images, on a page with at least a few of them).
- In approval mode it can become a refusal that needs the preview first (the existing `PREVIEW_REQUIRED` flow), but never a silent block outside approval mode, because a deliberate rewrite is legitimate.
- Deterministic by construction (counting, no inference). Spec the exact thresholds with the numbers from the 12 real fixtures so they are not guessed.
- Acceptance: unit tests on real fixtures; no change to the verdict `valid`; documented in the readme as "the AI is told when a save removes most of a page".

### 6. Cheaper reads and shorter refusals (answers Divi's token work)

- `get_page_layout` and `GET /pages/{id}` take an optional `view` (`outline` | `block`): an outline is block names, ids, and the visible text of each module; `block` returns one block by id. Existing behaviour stays the default. A parameter on an existing tool, not a new tool.
- Shorten refusals: list the allowed child types once, cap repeated violations (`docs/token-usage.md` already notes a 96-violation worst case of about 5,400 tokens).
- Re-measure with `scripts/measure-tokens.php` and update `docs/token-usage.md`; the goal is a measured number, not a claim.
- Acceptance: median page outline well under a quarter of the full read (measure it); strict-parameter tests; MCP/REST/OpenAPI parity (the parameter adds no operation).

### 7. Style-value sanity in the gate

- 5.15.1 fixed layouts whose styles broke on an invalid spacing character and a Transform Skew crash on a blank desktop value. Add checks that a spacing/size attribute is a value Divi can use, derived from the `module.json` value types and proven by a render that shows the bad value actually breaks the page (live-render proof, as for the style-reference advisory).
- Scope: the families we already guard (`CodePolicy` named list); do not claim completeness.
- Start as an advisory, promote to a violation only for values the render proves harmful.

### 8. `make divi-currency` (answers F3: react the day Divi ships)

One command that takes the new zip and produces the report this session produced by hand: version and changelog diff, new and removed modules, changed `module.json` files, `schema-gap`, a theme upgrade in Docker, `verify-modules`, the cheap gates, and a short markdown report in `docs/`. It stops and says so if any gate fails, and never promotes a module by itself.
- Add the Pro compat run (`verify-pro-global-*`, `verify-pro-theme-layouts`) to the same command, behind the existing dev-site guard.
- Acceptance: running it on 5.14.0 → 5.15.1 reproduces today's findings (F1 included).

### 9. Pro `manage_components` (decision, not yet a commitment)

Divi's own agent now saves, converts, updates and deletes components inside the builder. An external-agent equivalent would be a Pro tool using only Divi's own functions, undoable, with a preview token, MCP + REST only. Do not build it before items 2 and 4 show what the data model allows; decide then.

### 10. Guide coverage candidates (each needs a real export before it is taught)

Scroll effects (Divi's agent just fixed applying Fade, Blur, Rotate, Horizontal Motion, Scaling), sticky with z-index, Group Carousel, Tooltip, Post Filter, the new Gallery Slider image design settings. Run the existing "capture styled exports → mine shapes → serve via `get_style_guide`" pipeline for each; teach only what is proven.

## Decisions for the owner

- **A.** Ship 4.12.0 as "verified on Divi 5.15.1" now (modules, converter, guides, Pro unaffected except F1), with components marked as "not yet supported" in the readme, or hold it until item 2 lands? Recommendation: ship the Pro hotfix first, then hold 4.12.0 for item 2 if the exports show component pages are refused, because a customer on 5.15 will meet components quickly.
- **B.** Item 2 step 1 needs the builder. Do it yourself, or approve a scripted browser run on the dev site?
- **C.** Content-loss threshold and whether approval mode may refuse on it (item 5).
- **D.** A duplicate-first "edit a copy" mode to answer Respira's sandbox story. Not recommended now: `create_page` drafts, dry run, approval mode and undo already cover the same risk, and a copy mode adds a second write path to keep in lockstep.
- **E.** The four lost variables: recreate if you remember what they were.

## What stays true

The validator stays pure and deterministic; nothing here adds an AI call, a remote request, or a licence check to `wp-plugin/`. No module is promoted on the strength of `module.json` alone; every new block type waits for an export and a live render. Nothing new is added to the ChatGPT importer's 30-operation budget (all new surface is a parameter or MCP + REST only).

## Order

1. Pro 0.12.1 (item 1). 2. Item 2 evidence, then rules. 3. Free 4.12.0 (item 3). 4. Items 4, 5, 6 as one or two free releases. 5. Items 7 and 8. 6. Items 9 and 10 by decision.


## Outcome (2026-10-16, same day)

The owner answered the open decisions with "build everything first" and "do what you think is best". What was decided and built:

| Item | Result | Evidence |
|---|---|---|
| 1. Pro 0.12.1 | Undo of a created variable ARCHIVES it (works on Divi 5.14 and 5.15.1 alike); Divi's own refusal message is passed on; live gate rewritten to restore the RAW stored row from a snapshot. Pro suite 2169. | `make verify-pro-global-variables` PASS on 5.15.1 |
| 2. Components and Slot | Six real builder exports (plain, row, module, slot empty, slot filled, nested) made headless with Playwright; `divi/global-layout` and `divi/global-slot` accepted in a column; a Slot needs a `slotId` and holds modules; rows or a Slot in a Slot, a Slot in a row/section, and a column component stay refused (a tried row inside a Slot did not take in the builder). Found on the way: adding a module next to a Slot edits the component for every page. | `docs/components-evidence-5.15.md`, `make verify-components` |
| 3. Free 4.12.0 | Version, readme, changelog, `DiviCompat::TESTED` 5.15, regenerated `VerifiedModules` (no status moved), zips rebuilt, Plugin Check clean on the dev copy, the zip is byte-identical to `wp-plugin/`. Free suite 1827. | `docs/divi-currency-5.15.1.md` |
| 4. Component awareness | `get_site_structure` `components` section; `advisories.components` (`unknown_components`, `component_owned_edits`); guide text. Proven: Divi shows the component's own content in every instance, and an instance of a missing component still shows its stored copy. | `scripts/spike-component-edit.php`, `make verify-components` |
| 5. Content loss | `advisories.content_loss`, advisory only. **Decision C:** it never refuses, not even in approval mode (approval mode already forces a preview of the exact change; a second refusal would block legitimate rewrites). Thresholds: more than half lost, judged only when the page had 200 characters of text / 2 images / 4 modules; calibrated on the 12 real page fixtures. | `ContentLossTest` |
| 6. Cheaper reads, shorter refusals | `view` outline/block on `get_page_layout` and `GET /pages/{id}`; compact refusals. Measured: a median page read 12,461 -> 2,028 tokens as an outline; a worst-case refusal 35,913 -> 2,022. | `docs/token-usage.md` |
| 7. Style-value sanity | A style value with an unclosed quote or bracket is refused (`STYLE_VALUE_BREAKS_PAGE`). In a real browser on 5.15.1 it made EVERY section lose its custom padding; 0 of 5,332 real values are unclosed. | `make verify-style-values` |
| 8. `make divi-currency` | Built; run end to end (install + all gates) on 5.15.1: 13 of 13 gates PASS, including the Pro ones. It lists `GlobalData.php` as worth reading (that is how F1 shows up), it does not find F1 by itself. | `docs/divi-currency-5.15.1.md` |
| 9. Pro `manage_components` | **Decided against.** Divi has no public API to create or convert components; writing Library items by hand would copy the builder's serialisation and every page that uses the component would change with it. The free side is read-only plus advisories. | CLAUDE.md |
| 10. Guide coverage | **Sticky** (`position` top/bottom/topBottom, `offset.top`) and **scroll effects** (`verticalMotion.enable`, `fade`; the other effects share the shape from Divi's own defaults) added to the style guide, from real builder saves plus browser measurements. Group Carousel, Tooltip, Post Filter and the Gallery Slider image settings were NOT done (each needs its own real export). | `make verify-guide-shapes` (now 30 checks) |

Other decisions: **B** done as a scripted browser session on the dev site (Playwright, from `../layoutlab`). **D** no "edit a copy" mode (dry run, approval mode, undo and `create_page` drafts already cover the risk, and a copy mode is a second write path to keep in lockstep). **E** only `gvid-f7rcuxrcdb` could be restored (its value is in the evidence doc); the four UUID-id variables have no recorded values and were not recreated.

Free suite 1827 tests, Pro suite 2169, 13 gates (unit suite, `verify-modules` and 11 real-site gates) green on Divi 5.15.1 in the `make divi-currency` run, plus the extended `verify-guide-shapes`. Nothing is committed; `fixtures/valid/verified-*.json` were regenerated by the module promotion (builderVersion 5.15.1, same modules).
