# Accordion Adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A style kit also styles accordion and toggle items: markers `item` (boxed) and `item-rule` (rule rows) on `divi/accordion` and `divi/toggle`, resolved by `KitAdapters`, proven on the real Divi, with the three recipes that use them migrated.

**Architecture:** The card-adapter pattern again: probe every path ON each module first, a new adapter branch in `KitAdapters::patch` (button and card output untouched), resolver roles, pure contrast proofs over every kit x language x role x band x state, a render-gate extension with a real click, recipe migration with the merge-trap rules, and a generated `kit-rules` guide topic that frees guide space.

**Tech Stack:** pure PHP 8 (`wp-plugin/src/`), PHPUnit (`make test`), Node + Playwright probe/render scripts against the Docker dev site (Divi 5.15.1).

**Spec:** `docs/superpowers/specs/2026-10-19-accordion-adapter-design.md` (approved by the owner 2026-10-19 with the recommended decisions: accordion as the next adapter, roles `item` and `item-rule`, no open-state emphasis and no hover in v1, the new `kit-rules` topic; the image-radius token pass stays a separate small branch). Pattern and process: `docs/superpowers/plans/2026-10-19-card-adapter.md`, `docs/superpowers/specs/2026-10-19-card-adapter-design.md` (Outcome), and the kit-limits work (`docs/superpowers/plans/2026-10-19-kit-limits.md`), which this plan is rebased onto.

## Global Constraints

- Branch `feat/accordion-adapter`, developed in the git worktree `/Users/Lucas/Documents/JHMG-Local/divi5-accordion-wt`. Task 1 (probes: fixtures and docs only) runs from the worktree while `feat/kit-limits` is being built; Tasks 2-7 start only after `feat/kit-limits` is merged to main and this branch is rebased onto it (`KitAdapters.php`, `KitResolver.php`, `StyleGuide.php` and the recipe tests change in both).
- No push, release or version bump (the owner pushes); the tracked root zip stays the published 4.12.0 build; `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` stays empty. The validator is untouched and deterministic. No custom CSS/JS/PHP, no remote calls in `wp-plugin/`, no new MCP tool/REST route/OpenAPI operation; `tests/WpOrgComplianceTest.php` untouched and green.
- No Divi setting from memory: every path the adapter writes is in `fixtures/shapes/verified-shapes.json` AND probed ON its module (accordion, toggle); a path DEAD on a module goes to `fixtures/shapes/dead-on-module.txt` and is not written there.
- `data/section-recipes.json` ends smaller than its size at the start of Task 6 (683,633 B on main after kit-limits, to be re-measured); bound 716,800. Default style guide 28,964 B (ceiling 29,108): not touched; every kit topic <= 10,000 B (tested at 50 kits).
- Goldens `kit-adapter-patches.json` and `kit-card-patches.json` stay byte-identical; the new golden is `tests/golden/kit-item-patches.json`.
- Contrast proofs: zero exceptions, no baseline file; fix the role table, never freeze.
- Max 3 parallel workers; ONE probe or render at a time on the dev site (`pgrep -f "node scripts/verify-"` first; the controller coordinates with the kit-limits live gates); the controller runs every live gate. Another session may share the main checkout: check `git branch --show-current` before and after long runs.
- Probe runs from the worktree need `PLAYWRIGHT_PATH=/Users/Lucas/Documents/JHMG-Local/layoutlab/node_modules/playwright` (the script's default is a sibling of the repo root) and `COMPOSE_PROJECT_NAME=divi5deterministicvalidator` (the running containers belong to that compose project); `.env` is already copied into the worktree.
- Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; commit only your own files by explicit path.

## Review Focus

1. A marker on the wrong block, a band the role does not accept (`item-rule` on accent/dark), a `ground`-like extra key: left in place and reported by `KitResolver::leftovers`, never guessed (T2).
2. An accordion-item under a marked accordion carrying its own decoration (an item-level value beats the parent's): fails a test (T3).
3. Divi's defaults (`#f4f4f4` closed fill, `#fff` open, 1px `#d9d9d9` frame, `#666`/`#333` titles, `#ccc` icon) must be overridden EXPLICITLY for both states by the adapter: an omitted key falls back to them (T2/T3).
4. A radius on an accordion that clips the open answer or the icon (probe `ki-radius-clips-*`) (T1/T5).
5. A real click opens a closed item and closes the open one; its fill and title colour switch to the open values (T5).
6. The `kit-rules` topic must keep every kit topic under 10,000 B at 50 kits and every hex in every snippet in the legend (T4).

---

### Task 1: Probe the item paths on divi/accordion and divi/toggle (runs now, in the worktree)

**Files:**
- Create: `fixtures/shapes/candidates/kit-item.json`, `fixtures/shapes/kit-item-vocabulary.md`
- Modify: `fixtures/shapes/verified-shapes.json` and `docs/shape-evidence-5.15.1.md` (through `scripts/promote-shapes.php`), `fixtures/shapes/dead-on-module.txt`, `scripts/verify-shapes.cjs` only if it cannot take `"block":"accordion"`/`"toggle"` (a minimal change)
- Result file: `fixtures/shapes/probe-results-kit-item.json`

**Interfaces:**
- Produces: `kit-item-vocabulary.md` in the layout of `fixtures/shapes/kit-card-vocabulary.md` (decision, what passed, registered paths, status v1/later/not taught, per module). Tasks 2, 3 and 5 read it: the adapter writes only PASS paths; anything not measured on a module is not written.

- [ ] **Step 1: Write the candidates** (ids `ki-<decision>-<module>`; blocks `accordion` with 3 items (measure on the items; one open) and `toggle`): frame width 1/2/4px; style solid/dashed/dotted/double; frame colour; underline rest form (`styles.all.width 0px` + `styles.bottom`); radius (and whether it clips the open answer and the icon); shadow soft/hard/edge/glow in the exact forms `KitAdapters::shadow()` writes; open and closed fill under a frame; open and closed title colour (the accordion's `openToggle`/`closedToggle` font are new; the toggle's `openToggle` font and `closedTitle`); closed and open icon colour (toggle both, accordion closed only); body colour on the content group; toggle only: `module.decoration.spacing` padding; accordion only: `module` padding (expected to pad the ANSWER: record the effect, it will not be written) and the 10px row gap under a frame. About 36 candidates; all paths from the spec section 2 (`module.decoration.*` plus the state groups the registry already lists for these modules, `docs/shape-evidence-5.15.1.md` `rf-acc-*` and `rt-toggle-*` sections).
- [ ] **Step 2: Run the probe, one run at a time**: from the worktree, `PLAYWRIGHT_PATH=... COMPOSE_PROJECT_NAME=divi5deterministicvalidator node scripts/verify-shapes.cjs fixtures/shapes/candidates/kit-item.json` after `pgrep -f "node scripts/verify-"` is empty. Every DEAD or css-only result is recorded, not hidden; check no `aied shape probe` page remains.
- [ ] **Step 3: Promote** PASS paths with `php scripts/promote-shapes.php fixtures/shapes/probe-results-kit-item.json fixtures/shapes/candidates/kit-item.json`; add every DEAD (module, path) pair to `fixtures/shapes/dead-on-module.txt`.
- [ ] **Step 4: Write `kit-item-vocabulary.md`** (include what was measured about clipping, the row gap, and the hover half NOT probed by ruling); run `make test` from the worktree (registry and dead-on-module tests stay green); commit by explicit path.

---

### Task 2: Adapter and resolver roles `item` and `item-rule`

**Files:**
- Modify: `wp-plugin/src/KitAdapters.php`, `wp-plugin/src/KitResolver.php`
- Create: `tests/KitItemAdapterTest.php`, `tests/KitItemGoldenTest.php`, `tests/golden/kit-item-patches.json`
- Modify: `tests/KitResolverTest.php`, `tests/KitAdaptersTest.php` only to keep role/surface expectations true

**Interfaces:**
- Consumes: Task 1's vocabulary; `KitAdapters::card()`/`shadow()`/`CARD_FRAME`, `StyleKits::valueToken`.
- Produces: `KitAdapters::patch` additionally answers roles `item` and `item-rule` on `divi/accordion` and `divi/toggle` (bands: item = light|surface|accent|dark; item-rule = light|surface), null otherwise; `KitAdapters::APPLIED` gains `'divi/accordion' => ['item','item-rule']` and `'divi/toggle' => ['item','item-rule']`; `KitResolver` accepts those markers (strict shape). The rules are spec section 2 (fill `surface`/`bg` for item, the band colour for item-rule, both open and closed states written; frame by `CARD_FRAME` with the 1px floor; item radius `{{aied:kit:radius-card}}`, item-rule radius 0 written; toggle padding `{{aied:kit:space-card}}` for item only; no shadow for item-rule (written none), the kit's rest shadow for item if probed; no hover; titles `text` open and closed, body `muted`, icons `text`), using ONLY paths Task 1 marked PASS on that module. A path Task 1 found dead is not written; say so in the docblock.

- [ ] **Step 1: Write the failing tests** (`KitItemAdapterTest`, same helper style as `KitCardAdapterTest`): every emitted path in the registry and not dead on its module (all 40 kits x roles x bands x both blocks); the patch validates inside a real accordion and toggle block via `LayoutGate`; Divi's defaults are overridden for both states (assert every state key the adapter must write is present, so none falls back to `#f4f4f4`/`#fff`/`#666`/`#333`/`#ccc`); frame, radius and padding rules per role; no hex and no unresolved token beyond colour/kit; unknown combinations null (item-rule on accent, a card role on a toggle, another block); resolver tests (marker resolves and is stripped, wrong band/role/extra key left and reported); golden for the ten language default kits x item/item-rule x allowed bands.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'KitItem|KitResolver'`; expect FAIL.
- [ ] **Step 3: Implement** the adapter branch (private `item()`), `APPLIED`, resolver roles; update both docblocks (role table). Do not change any existing output.
- [ ] **Step 4: Run** `make test`; expect PASS with the old goldens untouched. Commit.

---

### Task 3: Contrast proof, own-ink tests and visible-difference measure

**Files:**
- Modify: `tests/KitItemAdapterTest.php`, `tests/StyleKitsTest.php`
- Create: `tests/ItemMigrationTest.php` only if it keeps `RecipeLibraryTest.php` smaller (otherwise add to `RecipeLibraryTest.php`)

**Interfaces:**
- Consumes: Task 2; the contrast helpers of `KitCardAdapterTest`/`KitAdaptersTest`.
- Produces: green proofs for Task 6's migration to keep green.

- [ ] **Step 1: Write the failing proofs**: for every kit x 10 languages x role x band x state (closed, open): closed title on closed fill and open title on open fill AA 4.5, body on the open fill AA 4.5, icon on its fill 3:1, a meaningful (`text`) frame 3:1 on the fill; cells: item 40 x 10 x 4 x 2 = 3,200 and item-rule 40 x 10 x 2 x 2 = 1,600 (assert both counts); a planted bad input fails the check (non-vacuity); a recipe-level test that fails any migrated item block still carrying a colour, border, shadow or background of its own, and any `divi/accordion-item` under a marked accordion that carries decoration; the item visible-difference measure like the card one (a shrink-only exception file only if pairs are identical, report the count; stop and report if more than 60 of 780 pairs).
- [ ] **Step 2: Run, expect FAIL; Step 3: fix by amending the role table if a cell fails (never an exemption); Step 4: `make test`; commit.**

---

### Task 4: Guide: `kit-rules` topic, slimmer kit topics, a resolved toggle, generated wording

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php`, `wp-plugin/src/SectionRecipes.php` (the `style_kit` description if it names modules), `tests/StyleGuideKitTopicsTest.php`, `tests/GuideRecipePointersTest.php` if it needs the new topic skipped

**Interfaces:**
- Consumes: `KitAdapters::APPLIED`, `kitColourLegend()` (every hex in every snippet must appear in the legend with its role(s)).
- Produces: a generated topic `kit-rules` (what each adapted module takes from a kit, built from `APPLIED` and the adapter constants); each `style-kit:<id>` keeps only its own values and resolved snippets (button, card, panel, toggle) plus a one-line pointer; the catalog's pointer sentence and the kit topics name `kit-rules` (NOT the default guide: 0 B there); the catalog line about modules that keep the recipe's own styling is built from `APPLIED` (`appliedSentence()` gains the nouns "accordion and toggle items"); the `toggles` topic gains one generated sentence pointing to the kit topics (its tabs limit stays).

- [ ] **Step 1: Write the failing tests**: `kit-rules` exists, is generated (a planted map changes it), every kit topic <= 10,000 B (pin the largest), catalog <= 10,000 B at 50 kits, the default guide byte-identical in size, the legend assertion covers the new toggle snippet, the old repeated card paragraph appears once (in `kit-rules`) not 40 times.
- [ ] **Step 2: Run, expect FAIL; Step 3: implement; Step 4: `make test` and `make test-pro`; commit.**

---

### Task 5: Render gate: items and a real click

**Files:**
- Modify: `scripts/lib/kit-checks.cjs`, `scripts/lib/kit-card-run.cjs` (or a new `scripts/lib/kit-item-run.cjs` if clearer), `scripts/verify-kits.cjs`, `scripts/test-verify-kits.cjs`, `scripts/test-kit-card-run.cjs`, `scripts/lib/kit-sheet-offline.php`, `Makefile` comment

**Interfaces:**
- Consumes: Task 2 markers; the card gate machinery (expectations read from the resolved markup, a null never skips, the admin bar and fixed elements hidden while sampling).
- Produces: per cell on the light and surface bands: an `item` accordion (one open, two closed) and two `item-rule` toggles (one open); an `item` accordion on the accent and dark bands; codes `items` (count), `item-border`, `item-radius`, `item-fill` (per state), `item-title`, `item-body`, `item-icon`, `item-shadow`, `item-contrast`, `item-click` (a real click on a closed item opens it after Divi's slide: its fill and title colour become the open values and the previously open accordion item closes).

- [ ] **Step 1: Write the pure tests first** (matching, mismatching, tolerance, missing field fails, empty selection fails, the offline sheet resolves with zero leftovers and passes `LayoutGate` for every kit x ten languages; synthetic Chromium departures for each code incl. a click that does not open); show RED then GREEN.
- [ ] **Step 2: Implement; do not touch the dev site.** Step 3: `node scripts/test-verify-kits.cjs`, `node scripts/test-kit-card-run.cjs`, `make test`; commit.
- [ ] **Step 4 (live, controller)**: smoke `node scripts/verify-kits.cjs --kits quiet-solid,hairline-dashed,heavy-block --languages editorial,glass-dark`, expect 0 FAIL; fix rounds go back to the implementer.

---

### Task 6: Migrate the three recipes

**Files:**
- Modify: `wp-plugin/data/section-recipes.json` (faq-accordion, features-accordion-image, faq-toggle-list), `tests/RecipeLibraryTest.php` (band walk for item markers, merge-trap test for items, `BESPOKE_ITEMS` shrink-only with max 0, size check, the card phrase test extended to the new prose)

**Interfaces:**
- Consumes: Tasks 2-3; the card migration pattern (`.superpowers/sdd/2026-10-19-card-adapter/task-7-report.md`).
- Produces: faq-accordion's accordion `{"kit":"item","on":"light"}`, features-accordion-image's accordion and the 5 faq-toggle-list toggles `{"kit":"item-rule","on":"light"}` (each `on` equals the real band by the ancestor walk); each migrated block drops its own `module` border/boxShadow, open/closed fills and font colours, `closedTitle` colour, title/body colours, both icon colours (and, for `item`, its padding), keeping family/size/weight/line height, heading level, content and `open`; prose that names the old look reworded; moods re-read (widen only where a language's `avoids` does not exclude the layout; report the count of recipes that fit at most two languages before and after; never add a mood-avoids exemption).

- [ ] **Step 1: Write the failing tests** (allow-list first, band walk, merge trap with planted cases both ways, size shrinks); **Step 2: migrate** with a minimal byte diff; **Step 3: `make test`; commit; Step 4 (live, controller)**: `verify-recipes --names faq-accordion,features-accordion-image,faq-toggle-list` under default + 7 languages x 3 widths.

---

### Task 7: Docs, token measurement, outcome

**Files:**
- Modify: the spec (Outcome section), `CLAUDE.md` (a compact Accordion adapter paragraph and table rows), `docs/token-usage.md` (`php scripts/measure-tokens.php`), `fixtures/shapes/kit-item-vocabulary.md` final pass, the `KitAdapters.php` docblock role tables

- [ ] **Step 1:** write the Outcome from measured facts only (probe counts and DEAD results, proof cell counts, sizes, live gate numbers from the controller's ledger), deviations, known limits (tabs; no open-state emphasis; no hover; accordion padding reaches only the answer), owner decisions left (forms next? media-radius pass); **Step 2:** `make test`, `make test-pro`, `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` empty; commit.

---

### Task 8: Final live gates and review (controller)

- [ ] Full `make verify-kits` (expect about 85 minutes, 0 FAIL), then `verify-recipes` for the recipes this branch can change (the 3 migrated recipes under 8 looks, plus the recipes with accordion/toggle-adjacent kit blocks if any, plus a sample of 10 others for guard), `node scripts/verify-shapes.cjs fixtures/shapes/candidates/known.json`; check the branch before and after each; then the final whole-branch review on the most capable model, one fix wave, one scoped re-review, and the merge per the owner's instruction.
