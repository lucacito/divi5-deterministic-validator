# Kit Limits Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the three limits the card adapter left: tilted cards keep their tilt on hover, inverted and accent grounds (and the buttons on them) follow the kit, and the featured pricing plan stays the most prominent card; plus the outline kits' solid secondary becomes a ghost.

**Architecture:** An optional `ground` field on the existing `card`/`panel` markers (no new role), a button surface `on:"panel"`, a one-entry role-table change for the outline secondary, a wrapper group that carries a card's tilt, and a stricter merge rule. No new probe: every path is already proven on divi/group, divi/column and divi/button.

**Tech Stack:** pure PHP 8 (`wp-plugin/src/`), PHPUnit (`make test`), Node + Playwright render gate against the Docker dev site (Divi 5.15.1).

**Spec:** `docs/superpowers/specs/2026-10-19-kit-limits-design.md` (approved by the owner 2026-10-19 with all five recommended decisions: wrapper for the tilt; `ground` field; featured plan = accent ground; outline secondary = ghost; compare-two-panels' CTA follows the kit). Background: `docs/superpowers/specs/2026-10-19-card-adapter-design.md` (Outcome), `docs/superpowers/plans/2026-10-19-card-adapter.md` (the pattern and process), ledger of that work `.superpowers/sdd/2026-10-19-card-adapter/progress.md`.

## Global Constraints

- Branch `feat/kit-limits` (from main ae0688d). No push, release or version bump (the owner pushes); the tracked root zip stays the published 4.12.0 build; `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` stays empty.
- The validator is untouched and deterministic. No custom CSS/JS/PHP, no remote calls in `wp-plugin/`, no new MCP tool/REST route/OpenAPI operation. `tests/WpOrgComplianceTest.php` untouched and green.
- No Divi setting from memory: no new path is written; every path the adapter emits stays in `fixtures/shapes/verified-shapes.json` and out of `fixtures/shapes/dead-on-module.txt`. If a task finds it needs a new path, STOP and report.
- `data/section-recipes.json` ends smaller than 683,182 B (bound 716,800); the tilt task may add up to 500 B, every other task must not grow it; `data/style-kits.json` unchanged except the one summary sentence if the secondary change requires it; the default style guide (28,964 B, ceiling 29,108) and the catalog topic are NOT touched; each kit topic stays <= 10,000 B.
- Existing goldens `tests/golden/kit-adapter-patches.json` and `tests/golden/kit-card-patches.json` stay byte-identical, EXCEPT the outline kits' secondary entries (changed on purpose in Task 3, listed in the commit body and report).
- Contrast proofs: zero exceptions, no baseline file; a failing cell means amending the role table, never freezing.
- Max 3 parallel workers; ONE probe or render at a time on the dev site (`pgrep -f "node scripts/verify-"` first); the controller runs every live gate. Check `git branch --show-current` before and after any long run (another session shares this checkout).
- Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; commit only your own files by explicit path.

## Review Focus

1. A `ground` on an `accent` or `dark` band, on a block other than group/column, with a bad value, or in a marker with extra keys: left in place and reported by `KitResolver::leftovers`, never guessed (T2).
2. The existing 3,200 card cells and the golden stay byte-identical when no `ground` is given (T2).
3. A button on an inverted panel (`on:"panel"`) must never vanish: `invert` hover on a `text` panel would paint text on text; label and border contrast on the panel proven for every kit x language (T2).
4. The ghost secondary must keep a visible hierarchy under every kit and stay readable on light, accent and photo bands (T3).
5. A migrated card carrying ANY transform other than the kit's hover must fail the merge-trap test; the wrapper must keep the card's grid sizing, overlap (zIndex, negative margin) and the phone reset (T1).
6. The featured plan must be MORE prominent than its neighbours in every kit x language, not merely different (T6).
7. Children that keep a colour token wrong for the new fill (e.g. `muted` on an inverted card, `text` on an accent tile): the ink walk must fail with planted cases (T2).

---

### Task 1: Tilt moves to a wrapper; marked cards carry no transform of their own

**Files:**
- Modify: `wp-plugin/data/section-recipes.json` (hero-gradient-stats: the three tilted cards), `tests/RecipeLibraryTest.php` (merge-trap rule and planted cases), `tests/CardMigrationReportTest.php` only if its pins read the wrapper
- Report helper: `php scripts/card-migration-report.php --all`

**Interfaces:**
- Consumes: the card-adapter pattern (`.superpowers/sdd/2026-10-19-card-adapter/task-7-report.md`, `RecipeLibraryTest::markedCardProblems`).
- Produces: a recipe where each tilted card sits inside an UNMARKED plain `divi/group` that carries the rest transform and its phone reset (and the outer-box properties the card had: sizing share of the grid, margins, `zIndex`); the marked card inside keeps only layout, sizing inside the wrapper, padding is the kit's. Rule for later tasks: a marked card may carry NO transform except the kit's exact hover.

- [ ] **Step 1: Write the failing tests**: in `RecipeLibraryTest` change the allowed keys of a marked card so ANY `transform` key (rest or hover) other than the kit's exact hover fails; planted cases: a marked card with a rest rotate fails, a wrapper-carried tilt (marked card with no transform inside a tilted unmarked group) passes, a recipe hover transform still fails; add `testTiltedCardsSitInAWrapper`: in hero-gradient-stats each marked card's parent is an unmarked group whose `module.decoration.transform.desktop.value.rotate.z` is `-3deg`, `2deg`, `-1deg` in order and whose phone value resets rotate to `0deg`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'RecipeLibrary|CardMigrationReport'`; expect FAIL.
- [ ] **Step 3: Migrate hero-gradient-stats**: three wrapper groups (minimal byte diff, round-trip pretty print) carrying the transform, the card's former margins/`zIndex` (outer-box properties) and the phone reset; the marked cards keep `layout`/`sizing` they need inside the wrapper. The band walk, ink walk, merge trap and size checks must stay green; recipes file at most +500 B.
- [ ] **Step 4: Run** `make test`; expect PASS. Commit by explicit path.
- [ ] **Step 5 (live, controller)**: `node scripts/verify-recipes.cjs --names hero-gradient-stats --languages default,editorial,glass-dark,brutalist-flat,playful-pastel,tech-mono,organic-warm,luxe` all PASS and `scratchpad/tilt-probe.cjs`-style hover read under soft-lift: wrapper keeps its rotate, card goes to translateY(-4px) inside it, nothing tilts at 400 px. A broken layout is a stop-and-ask point (fallback = the adapter copying the rest transform into the hover, needs probes).

---

### Task 2: Grounds (`inverted`, `accent`) and the button surface `panel`

**Files:**
- Modify: `wp-plugin/src/KitResolver.php`, `wp-plugin/src/KitAdapters.php`
- Test: `tests/KitCardAdapterTest.php`, `tests/KitAdaptersTest.php`, `tests/KitResolverTest.php`, `tests/CardInkWalkTest.php`, `tests/KitCardGoldenTest.php`, `tests/KitAdapterGoldenTest.php`; new golden entries only (`tests/golden/kit-card-patches.json`, `tests/golden/kit-adapter-patches.json` gain entries; no existing entry changes)

**Interfaces:**
- Consumes: `KitAdapters::card()` and `shadow()`/`ink()`, `KitResolver::marker()`, the existing 3,200-cell card contrast test and the button contrast test.
- Produces: `KitAdapters::patch( string $block, string $role, string $on, array $kit, ?string $ground = null ): ?array` (a fifth OPTIONAL parameter; `KitResolver` passes the marker's `ground`); marker shape `"aied":{"kit":"card|panel","on":"light|surface","ground":"inverted|accent"}` (keys `kit`, `on`, `ground` only; `ground` accepted only for roles card/panel on `light|surface` bands; anything else is a leftover); a button marker may name `"on":"panel"` (roles control/control-secondary). `KitAdapters::APPLIED` unchanged.

Ground rules (spec section 2): fill `text` (inverted) or `accent` (accent); frame = kit width/style, colour = the fill; radius, padding (`card`), shadow exactly as a plain card on that band; hover `card` only: lift only, never a hover frame colour; panel: no hover (unchanged). Button `on:"panel"` row: filled treatment fill `bg`, label `text` for every kit colour; transparent ink (outline, ghost) `bg`; hover `fill-swap` as on light with those colours, `invert` behaves as `fill-swap`; lift and shadows as on light (shadow ink on a panel = `bg`; decide and document so the shadow stays visible against the panel; if no ink works, no shadow on `panel`, say so in the docblock).

- [ ] **Step 1: Write the failing tests**: `KitCardAdapterTest`: 40 kits x 10 languages x {card, panel} x {inverted, accent} x {light, surface} x {rest, hover} (3,200 cells) — fill is `text`/`accent`, children-pair AA of the allowed inks (`bg`/`surface` on inverted; `accent-text` on accent) , fill differs from the band (inverted >= 11.98:1 against the band is a measured fact, accent >= 2.0:1 decorative), frame colour equals fill, no hover frame, no hover on a panel; refusal cases (ground on accent/dark band, on a button/image block, `ground:"x"`, extra key, ground without card/panel); `KitAdaptersTest`: the `panel` surface in the button proof (40 x 10 x 2 roles x 2 states = 1,600 cells): label >= 4.5:1 on the fill, transparent border >= 3:1 on the panel, no state where fill equals the panel; goldens: new entries for the ten default kits x card/panel x inverted/accent x light/surface and x control/control-secondary on panel, existing entries byte-identical (the existing golden tests must pass untouched); `CardInkWalkTest` planted cases: a `muted` child on an inverted card fails, a `text` child on an accent tile fails, `bg` on inverted and `accent-text` on accent pass; the walk reads the RESOLVED fill (ground included) so no walker change is expected beyond reading it.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'KitCardAdapter|KitAdapters|KitResolver|CardInkWalk|Golden'`; expect FAIL.
- [ ] **Step 3: Implement** the resolver marker extension (strict shape), the `ground` branch in `card()` and the `panel` row in the button role table; update both docblocks (role tables). Do not change any output when `ground` is absent and `on` is light|accent|dark.
- [ ] **Step 4: Run** `make test`; expect PASS with the old goldens untouched. If a proof cell fails, amend the role table (never an exception list) and say so in the report. Commit.

---

### Task 3: Outline kits' secondary becomes a ghost

**Files:**
- Modify: `wp-plugin/src/KitAdapters.php` (`SECONDARY`), `wp-plugin/src/StyleKits.php` (`describe()` derives its sentence from `secondaryFill`), kit topic wording only if a hand-typed sentence says the secondary is a block
- Test: `tests/KitAdaptersTest.php`, `tests/StyleKitsTest.php`, `tests/StyleGuideKitTopicsTest.php`, `tests/KitAdapterGoldenTest.php`, `tests/golden/kit-adapter-patches.json`

**Interfaces:**
- Consumes: `KitAdapters::secondaryFill()`, the button treatments.
- Produces: `SECONDARY['outline'] = 'ghost'` (transparent, no frame, the surface's ink: `text` on light, `accent-text` on an accent band, `inverse` on a photo); every other secondary unchanged.

- [ ] **Step 1: Write the failing tests**: for the 13 outline kits the secondary patch on light/accent/dark is a ghost (zero border width, transparent fill, ink per surface); the button contrast proof stays at zero exceptions; a visible hierarchy test: for every outline kit the primary (outlined) differs from the secondary (ghost) in frame width and the ghost has no frame; the near-duplicate button rule (`StyleKitsTest::NEAR_DUPLICATE_EXEMPTIONS`, shrink-only at 4) must not grow; the generated kit summary no longer says 'The secondary is a filled block' for outline kits (it derives from `secondaryFill`); the golden: EXACTLY the secondary entries of the 13 outline kits change, nothing else (assert the set of changed keys in the test with the 13 kit ids).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the one-entry change and any wording that derives from it; regenerate ONLY the affected golden entries deliberately and list them in the commit body and report.
- [ ] **Step 4: Run** `make test` and `make test-pro`; expect PASS. Commit.

---

### Task 4: Render gate: grounds, panel buttons, a featured middle card

**Files:**
- Modify: `scripts/lib/kit-checks.cjs`, `scripts/lib/kit-card-run.cjs`, `scripts/verify-kits.cjs`, `scripts/test-verify-kits.cjs`, `scripts/test-kit-card-run.cjs`, `scripts/lib/kit-sheet-offline.php` (the offline sheet proof), `Makefile` comment

**Interfaces:**
- Consumes: Task 2 markers; the sheet and expectation machinery of the card gate (expectations read from the resolved markup, a null never skips).
- Produces: per kit/language cell, on the light and surface bands: an inverted card and panel, an accent tile, and an inverted panel holding a primary and a secondary `on:"panel"` button; and in the three-column row an ACCENT-ground middle card between two plain cards. New failure codes `ground-fill`, `panel-button` (label/border contrast as painted on the panel) and `featured` (prominence: the mean painted luminance difference between a card's box and its band must be at least 1.5x the larger neighbour's; the threshold is confirmed on the first smoke, then frozen, never lowered after a failure).

- [ ] **Step 1: Write the pure tests first** (`test-verify-kits.cjs`): matching, mismatching, tolerance and a missing-field-fails case for each new expectation; the prominence function (featured stronger / equal / weaker); an offline proof that the generated sheet resolves with zero leftovers and passes `LayoutGate` for every kit x the ten languages; synthetic Chromium departures in `test-kit-card-run.cjs` for each new code. Show RED then GREEN.
- [ ] **Step 2: Implement** the sheet extension and the checks. Do not touch the dev site (the controller runs the live smoke).
- [ ] **Step 3: Run** `node scripts/test-verify-kits.cjs`, `node scripts/test-kit-card-run.cjs`, `make test`. Commit.
- [ ] **Step 4 (live, controller)**: `node scripts/verify-kits.cjs --kits quiet-solid,hairline-dashed,heavy-block --languages editorial,glass-dark` smoke (about 10 minutes); expect 0 FAIL; fix rounds go back to the implementer.

---

### Task 5: Migrate the four bespoke grounds and the two bespoke buttons

**Files:**
- Modify: `wp-plugin/data/section-recipes.json` (compare-two-panels, features-bento, pricing-two-plan-highlight), `tests/RecipeLibraryTest.php` (`BESPOKE_CARDS` loses the 4 groups, `BESPOKE_BUTTONS` loses its 2 buttons), `tests/CardMigrationReportTest.php` pins

**Interfaces:**
- Consumes: Task 2 `ground` and `on:"panel"`; the Task 7-9 migration rules of the card adapter (replace the block's own card decoration by the marker, keep margins/layout/sizing; the merge trap; bands by ancestor walk, a `ground` block's `on` is the band behind it).
- Produces: compare-two-panels group #7 `{"kit":"panel","on":"light|surface","ground":"inverted"}`, features-bento group #1 `ground:"accent"` and #3 `ground:"inverted"` (role `card`), pricing-two-plan-highlight group #8 `ground:"inverted"` (role panel or card as the report classes it), and the two buttons (compare-two-panels #0, pricing-two-plan-highlight #1) become `"aied":{"kit":"control","on":"panel"}` (or `accent` for a button inside the accent tile); the bespoke radius token on those groups goes (the marker writes the radius). `BESPOKE_CARDS` ends with only the featured pricing column (removed in Task 6); `BESPOKE_BUTTONS` ends empty (its cap removed or 0).

- [ ] **Step 1: Write the failing tests**: shrink the two allow-lists first (cap constants lowered) so the tests fail until the markers are in; extend the band walk to the `ground` field (the `on` must still equal the real band; a ground on an accent/dark band fails); the merge-trap test over the new markers; the ink walk over every migrated ground.
- [ ] **Step 2: Migrate** and re-check each recipe's descriptions/prose for claims about the look (the CTA in compare-two-panels is no longer accent: reword if the text says so) and `tags.pairs` (the fill roles changed from literals to resolved ones: keep the pairs honest). The recipes file must shrink.
- [ ] **Step 3: Run** `make test`; expect PASS. Report size before/after. Commit.
- [ ] **Step 4 (live, controller)**: `verify-recipes --names compare-two-panels,features-bento,pricing-two-plan-highlight` under the default look and 7 languages.

---

### Task 6: The featured plan is an accent ground

**Files:**
- Modify: `wp-plugin/data/section-recipes.json` (pricing-three-tier: column #2 and its children), `tests/RecipeLibraryTest.php` (`BESPOKE_CARDS` ends empty), `tests/KitCardAdapterTest.php` (prominence proof)

**Interfaces:**
- Consumes: Task 2 `ground:"accent"`, the Task 4 `featured` render check.
- Produces: column #2 `{"kit":"card","on":"light","ground":"accent"}` (same corners, frame width, shadow, padding and hover as its neighbours plus an accent body); children re-inked in the recipe: headings and texts `accent-text`, its primary button `on:"accent"`, the badge a `surface` chip with a `text` label; the badge and body pairs added to the recipe's `tags.pairs` (AA in all ten languages, already tested).

- [ ] **Step 1: Write the failing tests**: pure prominence proof: for every kit x language the featured fill-vs-band contrast exceeds the plain card's and is >= 2.0:1 while frame width, radius and shadow form equal the neighbour's; `BESPOKE_CARDS` cap to 0 and its test; ink walk on the migrated column; planted failure (a featured card with a `text` heading fails).
- [ ] **Step 2: Migrate** pricing-three-tier column #2 (drop its literals: surface fill, 2px accent frame, shadow, radius token; keep layout/sizing/margins); reword prose that promises an accent frame/shadow.
- [ ] **Step 3: Run** `make test`; expect PASS; size must shrink. Commit.
- [ ] **Step 4 (live, controller)**: `verify-recipes --names pricing-three-tier` under 8 looks; look at playful-pastel and brutalist-flat by eye.

---

### Task 7: Kit topics show a resolved inverted panel with a panel button

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php` (`styleKitTopic` card section: one generated inverted panel with a `panel` button), `tests/StyleGuideKitTopicsTest.php`

**Interfaces:**
- Consumes: Task 2; `kitColourLegend()` (every hex in every snippet must appear in the legend with its role(s); the existing test enforces it).
- Produces: each `style-kit:<id>` topic gains one resolved inverted panel (real pipeline) with a primary `panel` button, and the sentences are generated from the adapter/`APPLIED`, never typed per kit; the default guide and the catalog topic are NOT touched.

- [ ] **Step 1: Write the failing tests**: every kit topic contains the new snippet, it validates and resolves (no leftovers), the legend assertion covers its colours (the `text` fill and `bg` ink hex both named with their roles), the largest topic is <= 10,000 B (pin the number), the default guide size and the catalog size assertions unchanged.
- [ ] **Step 2: Implement; Step 3: run** `make test` and `make test-pro`; expect PASS. Commit.

---

### Task 8: Docs, token measurement, outcome

**Files:**
- Modify: `docs/superpowers/specs/2026-10-19-kit-limits-design.md` (Outcome section; Status APPROVED), `docs/superpowers/specs/2026-10-19-card-adapter-design.md` (strike the three limits through with a pointer to the new Outcome), `CLAUDE.md` (the Card adapter paragraph: grounds, `on:"panel"`, ghost secondary, wrapper tilt; the bespoke lists now empty; counts), `docs/token-usage.md` (re-measure with `php scripts/measure-tokens.php`), `fixtures/shapes/kit-card-vocabulary.md` / `kit-button-vocabulary.md` if a status line changed, `KitAdapters.php` docblock role tables (final form)

- [ ] **Step 1:** write the Outcome from measured facts only (commands and the ledger; live gate numbers come from the controller's ledger lines, not memory); state what changed on purpose (13 outline secondaries; compare-two-panels' CTA no longer accent; the featured plan is an accent block), what was not done, known limits (a hard/edge shadow in `text` ink merges with an inverted fill in chunky-pill-offset and raw-frame; ink-walk fail-open limits as before), and owner decisions left.
- [ ] **Step 2: Run** `make test`, `make test-pro`, `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` (empty). Commit.

---

### Task 9: Final live gates and review (controller)

- [ ] **Step 1:** full `make verify-kits` (expect about 85-90 minutes, 0 FAIL), then `verify-recipes` for the recipes this branch can change, NOT all 71 (owner-approved speed-up A): every recipe that carries a card/panel marker (15) or a kit button marker (about 22; `php scripts/card-migration-report.php --all` and a scan for `"aied"` markers give the list) plus 10 untouched recipes as a guard sample, under the default look and glass-dark (about 30 minutes); then `node scripts/verify-shapes.cjs fixtures/shapes/candidates/known.json` (PASS, PASS, DEAD, DEAD); check the branch before and after each. A failure outside the changed set widens the run to all 71.
- [ ] **Step 2:** final whole-branch review on the most capable model, one fix wave, one scoped re-review; then the controller merges per the owner's instruction.
