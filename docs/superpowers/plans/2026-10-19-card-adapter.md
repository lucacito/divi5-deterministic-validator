# Card Adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A style kit also styles the card surfaces: `divi/group` (and `divi/column` if its probes pass) marked `card` or `panel` take fill, frame, radius, padding, shadow and hover from the kit, in every design language, with zero contrast exceptions.

**Architecture:** One new branch inside `KitAdapters::patch` (the button code is not touched) writes a card patch on `module.decoration.*`; `KitResolver` learns two roles and a fourth surface for them; recipes migrate from literal border/radius/padding/shadow/fill to a marker. Every path is probed on the real Divi per carrier first (the registry is path-only), proofs are pure PHP, and a render gate on the real Divi extends `verify-kits`.

**Tech Stack:** pure PHP 8 (`wp-plugin/src/`), PHPUnit (`make test`), Node + Playwright probe/render scripts against the Docker dev site (Divi 5.15.1).

**Spec:** `docs/superpowers/specs/2026-10-19-card-adapter-design.md` (approved 2026-10-19 with the recommended decisions: group + column carriers, 1px frame floor, fill always `surface`/`bg`, hover on `card` only, inverted surfaces parked). Background: `docs/superpowers/specs/2026-10-18-style-kits-design.md`, `fixtures/shapes/kit-button-vocabulary.md`, ledger `.superpowers/sdd/2026-10-18-style-kits-foundation-and-buttons/progress.md`.

## Global Constraints

- Branch `feat/card-adapter`. No push, release, version bump or zip rebuild; the tracked root zip stays the published 4.12.0 build; `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` stays empty.
- The validator (`src/`, `wp-plugin/validator/`) is untouched. No custom CSS/JS/PHP, no remote calls in `wp-plugin/`.
- No Divi setting from memory: every path the adapter writes is in `fixtures/shapes/verified-shapes.json` AND probed ON its carrier (group, column); a path dead on a carrier goes into `fixtures/shapes/dead-on-module.txt` and is not written there.
- No new MCP tool, REST route or OpenAPI operation; optional parameters and guide topics only.
- Recipes keep colour/font tokens and kit tokens only (no hex). `{{aied:kit:radius-card}}` and `{{aied:kit:space-card}}` already exist in `StyleKits::valueToken`.
- `data/section-recipes.json` must end SMALLER than 695,877 bytes (bound 716,800 unchanged); every migrating task checks its own size.
- `data/style-kits.json` (40 kits, 25,486 B) is NOT edited: the card derives from existing fields. `tests/golden/kit-adapter-patches.json` must still match (button output of every old kit, byte for byte).
- Contrast proof: zero exceptions, no baseline file. If a cell fails, amend the role table in the adapter docblock and the spec outcome, never freeze exceptions.
- Max 3 parallel workers. ONE probe or render at a time on the dev site: run `pgrep -f "node scripts/verify-"` first. Recipe renders in chunks of about 18 (`node scripts/verify-recipes.cjs --names a,b,c`); a 404 flood means the login expired (re-run the chunk).
- Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Commit only your own files by explicit path.

## Review Focus

Failure modes the spec implies; each has a test in the owning task:

1. A recipe card that still carries its own background/border literal next to a marker: the adapter's value wins, no duplicate key survives (T3).
2. A `card`/`panel` marker on a block that is not group/column, or `on:"surface"` on a button: left unresolved and reported by `KitResolver::leftovers`, never guessed (T3).
3. A `panel` must carry no hover of any kind and no padding key, so the recipe's asymmetric padding (hero-split-left-overlap 56/48/56/152) survives the merge (T3).
4. A kit with `border.width 0px` (22 of 40 solid kits) still yields a visible 1px `border`-role frame; underline kits yield only a bottom edge (T3).
5. A card whose child image pokes out of the rounded corners (probe `kc-radius-clips-image`; the result decides whether recipes with an image child keep their image radius) (T1, T6).
6. Phone width: `space.card` up to 40px on a 400px screen must not overflow or squeeze text (render gate at 400px) (T6, T7).
7. A recipe whose children assume a fill the adapter does not give (descendant ink walk with a planted bad child to prove it is not vacuous) (T4).
8. A hover frame colour that is unreadable against the card fill in some language (contrast over every kit x language x band) (T4).

---

### Task 1: Probe the card paths on group and column

**Files:**
- Create: `fixtures/shapes/candidates/kit-card.json`, `fixtures/shapes/kit-card-vocabulary.md`
- Modify: `fixtures/shapes/verified-shapes.json` and `docs/shape-evidence-5.15.1.md` (through `scripts/promote-shapes.php`), `fixtures/shapes/dead-on-module.txt`, `scripts/verify-shapes.cjs` only if it cannot take `"block":"column"`
- Result file: `fixtures/shapes/probe-results-kit-card.json`

**Interfaces:**
- Produces: for each decision, PASS/DEAD/css-only per carrier, written as `kit-card-vocabulary.md` (same table layout as `kit-button-vocabulary.md`: decision, what passed, registered paths, status v1/later/not taught). Tasks 3, 8 read it: the adapter writes only PASS paths; a column row that is DEAD cancels the column part of T8.

- [ ] **Step 1: Write the candidates** (ids `kc-<decision>-<carrier>`, one per decision per carrier; `block` `group` and `column`, a `base` with a surface fill and a text child). Decisions: frame width 1/2/4px; style solid/dashed/dotted/double; frame colour; bottom-only edge (`styles.all.width 0px` + `styles.bottom`); four-corner radius incl. `999px`; padding four sides; background colour; shadow soft/hard/edge/glow in the exact forms `KitAdapters::shadow()` writes; hover lift (`translate.y -4px`), hover frame colour (`border.hover.styles.all.color`), hover shadow; and `kc-radius-clips-image` (a card with radius and an image child: expect PASS only if the child's corners are clipped, otherwise record DEAD with the reason). All paths under `module.decoration.*`.
- [ ] **Step 2: Run the probe, one run at a time**: `pgrep -f "node scripts/verify-"` must be empty, then `node scripts/verify-shapes.cjs fixtures/shapes/candidates/kit-card.json`. Expected: every non-clip candidate PASS; any DEAD/css-only is recorded, not hidden.
- [ ] **Step 3: Promote** PASS paths with `php scripts/promote-shapes.php fixtures/shapes/probe-results-kit-card.json fixtures/shapes/candidates/kit-card.json`; add every DEAD (module, path) pair to `fixtures/shapes/dead-on-module.txt` in its existing line format (`module|path prefix|evidence id|reason`).
- [ ] **Step 4: Write `kit-card-vocabulary.md`** and run `make test` (the registry and dead-on-module tests must stay green). Commit by explicit path.

---

### Task 2: Committed card classification report

**Files:**
- Create: `scripts/card-migration-report.php`, `tests/CardMigrationReportTest.php`
- Modify: `Makefile` (target `card-report`, add to `.PHONY`)

**Interfaces:**
- Produces: `CardReport::classify(array $recipes): list<array{recipe:string,block:string,index:int,class:string,role:?string,bytes:int}>` inside the script (guarded so the test can `require` it), classes `wrapper|padded-wrapper|rule|grid-cell|chip|inverted|card|panel`; `index` is the block's order among that recipe's blocks of the same name. The roles for the ~20 groups follow the spec's reading (cards: hero-gradient-stats x3, features-bento surface tiles x3, hero-bordered-cells inner cells x3; panels: hero-split-left-overlap, hero-spec-card, hero-bordered-cells outer, compare-two-panels#0, pricing-two-plan-highlight#0, cta-split-form, cta-card-over-image, contact-location-form, contact-details-form, transition-overlap-panel). The 12 column cards (icon-values, proof-press-quotes, testimonials-avatar-grid, pricing-three-tier) are classed `card` or `panel` by the same rules (testimonial and price cards are `card`).
- Consumes: `wp-plugin/data/section-recipes.json` (read raw, parse block comments, ignore the existing `aied` markers).

- [ ] **Step 1: Write `CardMigrationReportTest`**: counts over the shipped file are pinned (356 groups; 212 + 41 wrappers; the card/panel/inverted counts from the spec; the 12 column cards); a planted two-recipe fixture classifies a surface card with frame/radius as `card`, a top-rule row as `rule`, a 999px chip as `chip`, a `text`-filled panel as `inverted`.
- [ ] **Step 2: Run it, expect FAIL** (script missing).
- [ ] **Step 3: Implement** the script (CLI prints a table per recipe and the totals; `make card-report`). The classification rules are the spec's table; anything unclassifiable fails loudly.
- [ ] **Step 4: Run** `make test`; expect PASS. If the shipped counts differ from the spec's by more than the spec's "about", stop and report the difference to the controller. Commit.

---

### Task 3: Card adapter and resolver roles

**Files:**
- Modify: `wp-plugin/src/KitAdapters.php`, `wp-plugin/src/KitResolver.php`
- Create: `tests/KitCardAdapterTest.php`, `tests/KitCardGoldenTest.php`, `tests/golden/kit-card-patches.json`
- Modify: `tests/KitResolverTest.php`, `tests/KitAdaptersTest.php` (only to keep its role/surface expectations true)

**Interfaces:**
- Consumes: `StyleKits::get/problems/valueToken`, `KitAdapters::shadow()` and `ink()` (reuse, do not copy), T1's vocabulary (carriers and which forms passed).
- Produces: `KitAdapters::patch(string $blockName, string $role, string $on, array $kit): ?array` additionally answers roles `card` and `panel` on `divi/group` (and `divi/column` iff T1 passed it) for `$on` in `light|surface|accent|dark`, null otherwise; `KitAdapters::APPLIED` public const `array<string, list<string>>` = block => roles it answers (buttons: `divi/button` => control, control-secondary), the one source the guide wording uses (T5). `KitResolver` accepts `card`/`panel` markers on those blocks with the extra surface `surface` for them only; `control*` keep light|accent|dark.

Card patch (all under `module.decoration`, desktop; the rules from spec section 2):
- fill: `{{aied:color:surface}}`, or `bg` when `$on` is `surface`.
- border: `radius` = `{{aied:kit:radius-card}}`; `styles.all` = width (kit width, `1px` when the kit's is `0px`), kit style, colour (`same` -> `border`; `accent`/`text`/`border` as given; `inverse` -> `text`); for `border.sides: bottom` `styles.all.width 0px` plus `styles.bottom` as the button's underline form.
- `card` only: padding `{{aied:kit:space-card}}`; hover: `lift` -> `transform.desktop.hover.translate.y` `-4px`; `fill-swap`/`invert` -> `border.desktop.hover.styles.all.color` = `{{aied:color:accent}}`; the shadow pair as `shadow()` gives it (rest and hover).
- `panel`: no padding key, and NO `hover` key anywhere (border, boxShadow, transform); rest shadow only.

- [ ] **Step 1: Write the failing tests** in `KitCardAdapterTest` (same helpers style as `KitAdaptersTest`): `testEveryEmittedCardPathIsInTheRegistryAndNotDeadOnItsCarrier` (all 40 kits x roles x surfaces x carriers, using `ShapePaths`, `ShapeRegistry`, `DeadOnModule`); `testCardPatchValidatesInsideAGroupAndAColumnBlock` (merge into a real recipe block, run `LayoutGate::check`); `testNoHexAndNoUnresolvedTokensBeyondColourAndKit`; `testFillIsSurfaceOrBgByBand` (`surface` band -> `bg`, others -> `surface`); `testZeroWidthKitsGetAOnePxBorderRoleFrame` (a `0px`-border kit); `testUnderlineKitWritesOnlyTheBottomEdge`; `testPanelWritesNoPaddingAndNoHoverKeysAnywhere` (walk the patch for any `hover` key; Review Focus 3); `testCardHoverFollowsTheKit` (lift, fill-swap, invert, none); `testShadowsMatchTheButtonForms`; `testPatchIsDeterministic`; `testUnknownCombinationsAreNull` (button block with `card`, group with `control`, `on:"surface"` for `control`, an invalid kit). In `KitResolverTest`: `testCardMarkerOnAGroupResolvesAndIsStripped`; `testMarkerValueWinsOverARecipeLiteral` (block with its own border/background literal plus marker: the merged JSON has the adapter's values, one key each; Review Focus 1); `testCardMarkerOnAnImageIsLeftAndReported`; `testSurfaceOnAButtonIsLeftAndReported` (Review Focus 2).
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'KitCardAdapter|KitResolver'`; expect FAIL.
- [ ] **Step 3: Implement** the card branch in `KitAdapters::patch` (private `card()` next to `button()`), `APPLIED`, and the role/surface table in `KitResolver` (`ROLE_BLOCKS` gains `card`/`panel`; a per-role surface list replaces the single `SURFACES`). Do not change any button output; update both docblocks (role table for cards, final form).
- [ ] **Step 4: Capture the golden** `tests/golden/kit-card-patches.json` for the ten language default kits x `card`/`panel` x four surfaces (patches only, plus each kit's decisions as the existing golden does) and write `KitCardGoldenTest` mirroring `KitAdapterGoldenTest`.
- [ ] **Step 5: Run** `make test`; expect PASS including `KitAdapterGoldenTest` unchanged. Commit.

---

### Task 4: Contrast, ink-walk and visible-difference proofs

**Files:**
- Modify: `tests/KitCardAdapterTest.php`, `tests/StyleKitsTest.php`
- Create: `tests/CardInkWalkTest.php`, `fixtures/shapes/card-near-duplicates.txt` (only if needed, see step 4)

**Interfaces:**
- Consumes: T3's `patch()`; `Colour` support class; the pair/contrast code the button proof uses (`KitAdaptersTest::colourOf`, `stateProblems`) and `SectionRecipes` pair data (`tags.pairs`).
- Produces: nothing for later tasks except a green proof; T6/T7 migrations must keep it green.

- [ ] **Step 1: Write the failing contrast proof** `testNoUnreadableCardInAnyKitLanguageRoleBand` (every shipped kit x all 10 languages x role x band, rest and hover): resolved fill is `surface` or `bg`; (text, fill) and (muted, fill) >= 4.5; a frame in `accent` or `text` is >= 3.0 against the fill, also the hover frame colour. Plus `testTheCardContrastCheckCatchesWhatItShould` (feed a deliberately bad kit/fill and a bad frame colour; the check must report).
- [ ] **Step 2: Write the ink walk** `CardInkWalkTest`: for every recipe block carrying a `card`/`panel` marker, resolve its fill role by `on`, collect the colour tokens on its descendants (text/heading font colours need 4.5 against that fill in all 10 languages; icon and divider colours 3.0; descendant buttons are skipped, they carry their own fill), and fail with recipe and block index. Non-vacuity: a planted recipe whose child uses `{{aied:color:inverse}}` on a light-language card must fail the walker; and an assertion that the number of walked cards equals the number of markers in the file.
- [ ] **Step 3: Run**, expect the contrast test to FAIL or PASS honestly. If any cell fails, amend the adapter's role table (not the bounds, no exception list), update the docblock, re-run until zero failures, and record the amendment in the commit body.
- [ ] **Step 4: Visible-difference measure** in `StyleKitsTest`: `testEveryKitHasAVisiblyDifferentCard` over all pairs of the 40 kits using the card patch under one fixed language: two kits are the same card when radius differs < 4px, padding < 8px and frame width, style, colour role, shadow rest/hover and hover all match. Collect the identical pairs. If there are none, done. If there are some, write them to `fixtures/shapes/card-near-duplicates.txt`, assert the file is the exact set, shrink-only by an `assertLessThanOrEqual` on its count, and REPORT the count and the pairs in your summary; if more than 60 of 780 pairs are identical, stop and report to the controller (the owner decides, nothing is frozen silently).
- [ ] **Step 5: Run** `make test`; expect PASS. Commit.

---

### Task 5: Guide wording from one source

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php` (the "What is kit-driven today" paragraph, the "Applied today" line in `styleKitTopic`, and a resolved card and panel snippet next to the buttons), `wp-plugin/src/StyleKits.php` (`markdown()` card lines), `wp-plugin/src/SectionRecipes.php` (the `style_kit` description constant at line 57), `wp-plugin/src/KitResolver.php` docblock, the matching `style_kit` description in the MCP/REST/OpenAPI definitions only if they do not use the shared constant
- Test: `tests/StyleGuideKitTopicsTest.php`, `tests/SectionRecipesTest.php`

**Interfaces:**
- Consumes: `KitAdapters::APPLIED`.
- Produces: guide text built from `APPLIED` (no hand-typed per-kit claim).

- [ ] **Step 1: Write the failing tests**: the catalog and every `style-kit:<id>` topic no longer contain "not applied", "NOT applied today" or "Only buttons are kit-driven"; they name exactly the blocks and roles in `KitAdapters::APPLIED`; each `style-kit:<id>` topic includes a resolved card and a resolved panel produced by the real pipeline (assert the snippet equals `KitResolver::resolve` + `RecipeTokens::resolve` of a marker block, no `aied` left); the `style_kit` description is at most 300 characters and mentions cards; `style-kits` stays <= 10,000 B with 50 kits (existing assertion) and each `style-kit:<id>` <= 10,000 B.
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement** the wording from `APPLIED` (one helper `appliedSentence()` in `StyleGuide`), the two snippets, and the description change.
- [ ] **Step 4: Run** `make test` and `make test-pro`; expect PASS. Commit.

---

### Task 6: Extend verify-kits with a card and a panel

**Files:**
- Modify: `scripts/lib/kit-checks.cjs` (sheet template and expected values), `scripts/verify-kits.cjs` (checks and notes), `scripts/test-verify-kits.cjs` (pure tests), `Makefile` comment for `verify-kits`

**Interfaces:**
- Consumes: T3's markers; the sheet is resolved by the same PHP pipeline as today.
- Produces: per kit/language cell, one `card` and one `panel` on each of the four bands (a bg section, a surface section, an accent band, the photo band), each holding a heading and a paragraph and, in the bg-band card, an image child; the gate reads expected values from the resolved markup (never a table in the script) and fails with `FAIL <kit>/<language>/<width> <code>: detail` codes `card-border`, `card-radius`, `card-fill`, `card-padding`, `card-shadow`, `card-hover` (real Playwright hover: frame colour, shadow, translateY -4px for lift; a panel must show NO change on hover), `card-clip` (child image corners inside the radius, per T1's finding), `card-contrast` (heading and paragraph colour against the measured card fill >= 4.5).

- [ ] **Step 1: Write the pure tests first** in `test-verify-kits.cjs` for each new comparison function (matching, mismatching, tolerance, a panel that wrongly lifts) and a test that an empty card selection fails the run (the zero-cell rule the button gate has).
- [ ] **Step 2: Run** `node scripts/test-verify-kits.cjs`; expect FAIL, then implement the template and checks until it passes.
- [ ] **Step 3 (live, after T3-T5, controller runs it)**: `pgrep -f "node scripts/verify-"` empty, then `node scripts/verify-kits.cjs --kits quiet-solid,hairline-dashed,heavy-block --languages editorial,glass-dark` for a smoke; expect 0 FAIL. Prove the gate is non-empty: temporarily break one expectation (for example change a radius in the resolved markup) and see `card-radius` fail, then revert.
- [ ] **Step 4: Commit.** The full 88-cell run happens in Task 9.

---

### Task 7: Phase 1 migration, three recipes

**Files:**
- Modify: `wp-plugin/data/section-recipes.json` (hero-spec-card, hero-gradient-stats, cta-split-form), `tests/RecipeLibraryTest.php` (a `BESPOKE_CARDS` shrink-only allow-list like `BESPOKE_BUTTONS`, with the inverted/accent/chip/rule groups from T2's report; a size test)
- Test: `tests/RecipeLibraryTest.php`, `tests/SectionRecipesTest.php`

**Interfaces:**
- Consumes: T2's classification (which blocks), T3's markers, T4's proofs (must stay green).
- Produces: the migration recipe; later tasks repeat it.

Rule per migrated block: replace `module.decoration.{background,border,spacing,boxShadow}` literals (and a hover transform/border literal if present) with `"aied":{"kit":"card|panel","on":"<band>"}` where `on` is the real band behind it (an ancestor walk test ties the two, as the button's `on` guard does); keep everything else (layout, margins, sizing, content). Keep `module.decoration.spacing` margins if the block has margin keys (the marker writes only padding).

- [ ] **Step 1: Write the failing tests**: (a) `testCardMarkersMatchTheirBand` (walk ancestors: the nearest section/row/column background decides `light|surface|accent|dark`); (b) `testBespokeCardsAreListedAndShrinkOnly` (any group classed `card`/`panel` by T2 without a marker must be in `BESPOKE_CARDS`; any listed one that has a marker fails; the list can never grow past its first size); (c) `testRecipesFileIsSmallerThanBeforeTheCardAdapter` (< 695,877 bytes); (d) the three recipes resolve under every language x a sample of kits and pass `LayoutGate`, `DesignVariety`, the registry lint and `unresolved_tokens` (existing recipe-library tests already iterate marked recipes; confirm they pick these up).
- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Migrate the three recipes.** Re-read each recipe's `when`/description and mood tags: remove claims the kit can now change (corner/shadow prose); mood changes only where radius was the sole reason (the moods-vs-avoids and exemption tests stay shrink-only).
- [ ] **Step 4: Run** `make test`; expect PASS; report the file size before/after.
- [ ] **Step 5 (live, controller runs, one at a time)**: `node scripts/verify-recipes.cjs --names hero-spec-card,hero-gradient-stats,cta-split-form` under the default look and the 7 languages used for the button renders (editorial, glass-dark, brutalist-flat, playful-pastel, tech-mono, organic-warm, luxe via the script's own flags); expect all PASS. If a 400px overflow or a clipped image shows, fix the adapter or the recipe, not the gate. Commit.

---

### Task 8: Phase 2 migration, the other group recipes

**Files:**
- Modify: `wp-plugin/data/section-recipes.json` (hero-split-left-overlap, hero-bordered-cells, features-bento, compare-two-panels, pricing-two-plan-highlight, cta-card-over-image, contact-location-form, contact-details-form, transition-overlap-panel), `tests/RecipeLibraryTest.php` (`BESPOKE_CARDS` entries: compare-two-panels#1, pricing-two-plan-highlight#1 and the bento accent and `text` tiles, all inverted/accent by design)
- Test: as T7

**Interfaces:**
- Consumes: T7's rule and tests.

- [ ] **Step 1:** extend `BESPOKE_CARDS` first so the T7 test fails exactly for the 9 recipes still to migrate.
- [ ] **Step 2: Migrate** the 9 recipes under the T7 rule (panels keep their padding: hero-split-left-overlap, hero-bordered-cells outer, compare-two-panels#0, pricing-two-plan-highlight#0, cta-card-over-image, the two contact recipes, transition-overlap-panel; cards: bento surface tiles, hero-bordered-cells inner cells). Mood tags re-read per recipe as in T7.
- [ ] **Step 3: Run** `make test`; expect PASS; report size.
- [ ] **Step 4 (live, controller runs)**: renders in two chunks of the 9 recipes plus T7's 3 under default + 7 languages; expect 0 FAIL and no warnings. Commit.

---

### Task 9: Column cards (only if T1 passed column)

**Files:**
- Modify: `wp-plugin/data/section-recipes.json` (icon-values, proof-press-quotes, testimonials-avatar-grid, pricing-three-tier), `tests/RecipeLibraryTest.php`
- If T1 recorded a column path as DEAD: do not start; report that this task is cancelled and why.

**Interfaces:**
- Consumes: T7 rule; the column rows of `kit-card-vocabulary.md`.

- [ ] **Step 1:** extend `BESPOKE_CARDS` for these four so the tests fail first.
- [ ] **Step 2: Migrate** the 12 column cards (testimonial and price cards = `card`). A column keeps its `sizing`/`layout`/`flexColumnStructure`: the marker writes only the card's decoration paths (assert in a test that a migrated column's width structure is byte-identical to before).
- [ ] **Step 3: Run** `make test`; expect PASS; report size.
- [ ] **Step 4 (live, controller runs)**: render the 4 recipes under default + 7 languages; the 3-up/2-up/1-up grids must still stack at 400px. Commit.

---

### Task 10: Docs, token cost, project notes

**Files:**
- Modify: `CLAUDE.md` (Style kits paragraph and the Entry points row for `verify-kits`, one sentence each), `docs/superpowers/specs/2026-10-19-card-adapter-design.md` (an Outcome section: what was built, deviations, probe results, the measured size saving, near-duplicate pairs, known limits), `docs/token-usage.md` (re-measure with `php scripts/measure-tokens.php`), `fixtures/shapes/kit-card-vocabulary.md` (final)
- Test: `make test`, `make test-pro`

- [ ] **Step 1:** update the three docs with measured numbers only (sizes, counts, recipe numbers from the reports, not from memory).
- [ ] **Step 2: Run** `make test` and `make test-pro`; expect green. Run `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip`; expect empty. Commit.

---

### Task 11: Final live gates and whole-branch review (controller)

- [ ] **Step 1:** full `make verify-kits` (88 cells, about 50 minutes, nothing else on the site); expect 0 FAIL at 1280/900/400 for buttons AND cards.
- [ ] **Step 2:** full default `verify-recipes` in 4 chunks of about 18 recipes, a fresh login per chunk; expect 0 FAIL.
- [ ] **Step 3:** `make verify-shapes` (the four known candidates keep PASS, PASS, DEAD, DEAD).
- [ ] **Step 4:** final whole-branch review on the most capable model over `main..feat/card-adapter`; fix wave by resuming the implementer; scoped re-review.
