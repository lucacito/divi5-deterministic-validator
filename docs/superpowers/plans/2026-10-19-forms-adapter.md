# Forms Adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A style kit also styles contact forms: one marker `"aied":{"kit":"form","on":"light|surface|accent|dark"}` on `divi/contact-form` resolves to the kit's field look, submit button and captcha ink, proven on the real Divi, with the four form recipes migrated.

**Architecture:** The accordion-adapter pattern again: probe every path ON `divi/contact-form` first; a new `form` branch in `KitAdapters::patch` (button, card and item output untouched) that reuses the button's treatment code for the submit button; a resolver role; pure contrast proofs over every kit x language x band x state; recipe migration with the merge-trap rules; a render-gate extension; guide wording generated from `KitAdapters::APPLIED` inside the existing `kit-rules` and `forms` topics (no new topic name).

**Tech Stack:** pure PHP 8 (`wp-plugin/src/`), PHPUnit (`make test`), Node + Playwright probe/render scripts against the Docker dev site (Divi 5.15.1).

**Spec:** `docs/superpowers/specs/2026-10-19-forms-adapter-design.md` (approved by delegation 2026-10-19; Rulings 1-5 are listed again in its Outcome). Pattern: `docs/superpowers/plans/2026-10-19-accordion-adapter.md` and its spec Outcome; the code to mirror is `KitAdapters::item()`, `KitItemAdapterTest`, `KitItemGoldenTest`, `ItemMigrationTest`, `StyleGuideKitRulesTest`, `scripts/lib/kit-item-run.cjs`.

## Global Constraints

- Branch `feat/forms-adapter` (main checkout; T3/T4/T5 may run in git worktrees off it). No push, release or version bump; the tracked root zip stays the published 4.12.0 build; `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` stays empty. The validator is untouched and deterministic.
- No custom CSS/JS/PHP, no hex in a recipe (colour and font tokens only), no remote calls in `wp-plugin/`, no new MCP tool, REST route or OpenAPI operation, no new guide topic name; `tests/WpOrgComplianceTest.php` untouched and green. The Pro suite (`make test-pro`) stays green and untouched.
- No Divi setting from memory: every path the adapter writes is in `fixtures/shapes/verified-shapes.json` AND probed ON `divi/contact-form` (the registry is path-only; a `divi/button` result proves nothing here). A path DEAD on the form goes to `fixtures/shapes/dead-on-module.txt` and is never written.
- **Probe-conditional rule (binds T2):** for every decision below, write only what T1 proved ON `divi/contact-form`; otherwise omit it and let the generated guide say so. Each branch is pinned by a test in T2 against `kit-form-vocabulary.md` (see T2 step 1).
- Contrast proofs: zero exceptions, no baseline file; a failing cell amends the role table, never freezes. Never weaken an existing test; goldens `kit-adapter-patches.json`, `kit-card-patches.json`, `kit-item-patches.json` stay byte-identical.
- Sizes (spec section 6, asserted in T2/T4): default guide exactly 28,975 B; `kit-rules` <= 3,525 + 700 B; `forms` topic <= its current size + 350 B; each kit topic grows <= 40 B and the largest stays <= 9,700 B; catalog <= 10,000 B at 50 kits; `topicParameterDescription()` <= 260 characters; `section-recipes.json` ends below 675,810 B (bound 716,800).
- Max 3 parallel workers; ONE probe or render at a time on the dev site (`pgrep -f "node scripts/verify-"` must be empty first); the controller runs every live gate. Check `git branch --show-current` before and after long runs. Worktree probe/render runs need `PLAYWRIGHT_PATH=/Users/Lucas/Documents/JHMG-Local/layoutlab/node_modules/playwright` and `COMPOSE_PROJECT_NAME=divi5deterministicvalidator`.
- Commit only your own files by explicit path, with the commit trailer the controller gives.

## Review Focus

1. A submit button that is not the kit's primary control on that band (a second table instead of `treatment()`/`hover()`), or that writes padding/shadow/transform at a path the probe did not prove on the form (T2).
2. A field that matches its container (field fill = the colour the form is printed on), or a field frame under 3:1 on the field fill or the container (T2/T3).
3. The placeholder colour omitted (the field font colour does not reach it) or the captcha ink missing on any band (T2/T3).
4. A migrated form still carrying its own `field.*`, `button.*` or captcha colour, or `on` not equal to the colour the form is actually printed on (T4).
5. The kit writing any `module.decoration` on the form: the form's own frame, radius, maxWidth and alignment stay the recipe's (T2/T4).
6. Byte budgets and the default guide size (T2).

---

### Task 1: Probe the form paths on divi/contact-form (live, serial)

**Files:**
- Create: `fixtures/shapes/candidates/kit-form.json`, `fixtures/shapes/probe-results-kit-form.json` (written by the probe), `fixtures/shapes/kit-form-vocabulary.md`
- Modify: `fixtures/shapes/verified-shapes.json` and `docs/shape-evidence-5.15.1.md` (through `scripts/promote-shapes.php` only), `fixtures/shapes/dead-on-module.txt`; `scripts/verify-shapes.cjs` only if it cannot take `"block":"contact-form"` with two `divi/contact-field` children and a captcha (a minimal change)

**Interfaces:**
- Consumes: the known-good control of `fixtures/shapes/candidates/known.json`; the forms topic's proven paths (`StyleGuide::formsTopic()`); `KitAdapters::shadow()` and `button()` output forms (write the shadow and transform values exactly as those emit them).
- Produces: `kit-form-vocabulary.md` in the layout of `kit-item-vocabulary.md` (decision, what passed, registered paths, status v1/later/not taught). It MUST end with a table "Adapter decisions" with one row per conditional decision and a PASS/DEAD verdict: `button-padding`, `button-shadow`, `button-lift`, `field-underline`, `field-padding`. T2's const and test read this table.

- [ ] **Step 1: Write the candidates** (ids `kf-<decision>`, all `"block":"contact-form"`; spec section 4): submit fill; border width 1/2/4px, colour, radius; label colour, capitalization, letter spacing, weight; hover fill, label and border (a real hover); padding at `button.decoration.spacing` AND at `module.decoration.spacing` (record which one pads the BUTTON, not the form); shadow at `button.decoration.boxShadow` AND `module.decoration.boxShadow`; transform (the lift, `translate.y -4px` on hover) at `button.decoration.transform` AND `module.decoration.transform` (same: record what moves); field border bottom-only (`styles.all.width 0px` + `styles.bottom`, the card underline form); field padding; field radius in the token form `{{aied:kit:radius-control}}` as resolved; captcha ink; placeholder colour. Each rendered at 1280/900/400.
- [ ] **Step 2: Run, one run at a time**: `pgrep -f "node scripts/verify-"` empty, then `node scripts/verify-shapes.cjs fixtures/shapes/candidates/kit-form.json`. Record every DEAD, css-only or OVERFLOW result (never drop a candidate to make a run green); check no `aied shape probe` page remains.
- [ ] **Step 3: Promote** with `php scripts/promote-shapes.php fixtures/shapes/probe-results-kit-form.json fixtures/shapes/candidates/kit-form.json`; add every DEAD (`divi/contact-form`, path) pair to `dead-on-module.txt` in its carrier-scoped line form.
- [ ] **Step 4: Write `kit-form-vocabulary.md`** with the Adapter decisions table (a `module.decoration.*` path that moves or pads the whole form instead of the button is DEAD for the button decision); `make test` (registry and dead-on-module tests green); commit.

---

### Task 2: Adapter, resolver role `form`, generated guide, parameter headroom

**Files:**
- Modify: `wp-plugin/src/KitAdapters.php` (`APPLIED`, `patch()`, new private `form()`, docblock role table), `wp-plugin/src/KitResolver.php` (`ROLE_BLOCKS`, `ROLE_SURFACES`, docblock), `wp-plugin/src/StyleGuide.php` (`kitRules()` Forms paragraph, `APPLIED_NOUNS` gains `form` => "contact forms", new `formsKitSentence(?array $applied = null)` appended where `formsTopic()` is served, the fixed text of `topicParameterDescription()`)
- Create: `tests/KitFormAdapterTest.php`
- Modify: `tests/KitResolverTest.php`, `tests/StyleGuideKitRulesTest.php`, `tests/StyleGuideKitTopicsTest.php`, `tests/StyleGuideTopicsTest.php` (the 260-character pin), `tests/KitAdaptersTest.php` only to keep role/surface expectations true

**Interfaces:**
- Consumes: T1's vocabulary; `KitAdapters::treatment()`, `hover()`, `ink()`, `shadow()`, `frameStyles()`, `cardFrameRole()`, `StyleKits::valueToken`.
- Produces: `KitAdapters::APPLIED['divi/contact-form'] = ['form']`; `KitAdapters::patch('divi/contact-form', 'form', $on, $kit)` for `$on` in light|surface|accent|dark, null for any other block, role, band or a `ground`; `KitResolver` accepts the marker through the existing strict `marker()`. A public const `KitAdapters::FORM_PROVEN` (decision => bool, the five decisions of T1's table) is the one switch the adapter and the guide sentences read.
- Rules (spec section 2): field fill `surface`, `bg` when `on` is `surface`; field ink `text`, placeholder `muted`, family not written (recipe-owned); field frame = kit width with a 1px floor, kit style, colour `text` when the kit border colour is `text`, `accent` or `inverse`, else `muted` (Ruling 1); underline kit: bottom edge only if `FORM_PROVEN['field-underline']`, else the full frame at 1px (Ruling 2); field radius `{{aied:kit:radius-control}}`; field padding only if `FORM_PROVEN['field-padding']`; no field shadow, no field hover or focus (Ruling 3); submit = the kit's `control` treatment and hover on the band, with `surface` mapped to the button's `light` treatment (the button has no `surface` band) and judged against the surface colour; submit padding, shadow and lift only where `FORM_PROVEN` says so and only at the path T1 proved (else the recipe keeps its padding, Ruling 4); captcha ink `text` on light/surface, `accent-text` on accent, `inverse` on dark; NO `module.decoration` key.

- [ ] **Step 1: Write the failing tests.** `KitFormAdapterTest` (helpers as `KitItemAdapterTest`): `testEveryEmittedFormPathIsInTheRegistryAndNotDeadOnTheForm` (40 kits x 4 bands), `testEveryLeafIsAProbedPathOnTheForm`, `testFormProvenMatchesTheVocabularyTable` (parses the Adapter decisions table of `kit-form-vocabulary.md`; a mismatch fails), `testUnprovenDecisionsWriteNothing` (for each decision false: no leaf at its paths; true: the leaf is the T1 path), `testTheSubmitButtonIsTheKitControlOnTheBand` (submit leaves equal `patch('divi/button','control', band')` under the path mapping; `surface` uses `light`), `testFieldFillNeverMatchesTheContainer`, `testFieldFrameRules` (floor, colour mapping, underline branch both ways), `testPlaceholderAndCaptchaInkAreWrittenOnEveryBand`, `testNoModuleDecorationIsWritten`, `testNoHexAndNoUnresolvedTokensBeyondColourAndKit`, `testPatchValidatesInsideARealContactForm` (`LayoutGate`, with two fields and a captcha), `testUnknownCombinationsAreNull`, `testThePatchIsDeterministic`, `testAppliedNamesTheForm`. Resolver: marker resolves and is stripped; wrong band, wrong block, `ground` or an extra key left and reported by `leftovers()`. Guide: `testKitRulesHasAFormsParagraphGeneratedFromTheMap` (a planted map without the form drops it; the paragraph follows `FORM_PROVEN`: planted false/true changes the padding and underline wording), `testFormsTopicGainsOneGeneratedKitSentence` ('' without the form in the map), `testTopicParameterDescriptionLeavesHeadroom` (`<= 260` characters), `testTheDefaultGuideIsStill28975Bytes`, and the byte budgets of Global Constraints as assertions.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'KitForm|KitResolver|StyleGuideKit|StyleGuideTopics'`; expect FAIL.
- [ ] **Step 3: Implement** `form()`, `FORM_PROVEN`, `APPLIED`, the resolver roles, the generated sentences (wording free within the budgets), and reword only the fixed text of `topicParameterDescription()` (generated topic list unchanged). Do not change any existing output beyond the generated `appliedSentence()`/`kitRules()` text.
- [ ] **Step 4: Run** `make test` and `make test-pro`; expect PASS with the three old goldens byte-identical. Commit.

---

### Tasks 3, 4 and 5 need only Task 2 and may run in parallel (separate worktrees, max 3 workers).

### Task 3: Proofs: contrast, golden, near-duplicates, dead-on-module guard

**Files:**
- Modify: `tests/KitFormAdapterTest.php`, `tests/StyleKitsTest.php` (near-duplicate check, like `ITEM_NEAR_DUPLICATES`), `tests/KitAdaptersTest.php` (dead-on-module guard covers `divi/contact-form`)
- Create: `tests/KitFormGoldenTest.php`, `tests/golden/kit-form-patches.json`, `fixtures/shapes/form-near-duplicates.txt` (only if pairs exist)

**Interfaces:**
- Consumes: T2's `patch()`; the contrast helpers of `KitItemAdapterTest` (`opaqueOn`, the `$judge` pattern) and the button proof's outline/ghost floors in `KitAdaptersTest`.
- Produces: green proofs T4 and T5 keep green.

- [ ] **Step 1: Write the failing proofs**: `public static formContrastProblems(...)` and `testNoUnreadableFormInAnyKitLanguageBandState` over 40 kits x 10 languages x 4 bands x {rest, hover} = 3,200 cells (assert the count): field text and placeholder on the field fill 4.5:1; field frame on the field fill and on the container 3:1; submit label on its fill and submit border against the container per the button proof (filled 4.5 / 3; outline and ghost as defined there), in the hover state the hover values; captcha ink on the band 4.5:1 (fields are judged identical in both states: no field hover is written). `testTheFormContrastCheckCatchesWhatItShould` (planted: muted frame swapped for `border`, placeholder removed, field fill = container, captcha ink on the wrong band; each must fail). `KitFormGoldenTest::testEveryLanguageDefaultKitResolvesItsFormByteIdentically` (10 default kits x 4 bands = 40 patches; regenerate only deliberately). Near-duplicates: the form visible-difference measure like the item one; `form-near-duplicates.txt` is exactly the set of identical pairs, shrink-only with a cap equal to its count; stop and report if more than 60 of 780 pairs. Guard: `testTheDeadOnModuleGuardCoversTheForm` (a planted dead `divi/contact-form` path fails).
- [ ] **Step 2: Run, expect FAIL; Step 3: fix by amending the role table in `form()` (never an exemption), keeping T2's tests green; Step 4: `make test`; commit.**

---

### Task 4: Migrate the four recipe forms

**Files:**
- Modify: `wp-plugin/data/section-recipes.json` (contact-form, cta-split-form, contact-location-form, contact-details-form), `scripts/card-migration-report.php` and `tests/CardMigrationReportTest.php` only if the report must list `divi/contact-form` (class `form`, `MARKED=form`)
- Create: `tests/FormMigrationTest.php` (mirror `ItemMigrationTest`)

**Interfaces:**
- Consumes: T2 (T3 runs in parallel; rerun its proofs after merging).
- Produces: one `form` marker per recipe form, `on` = the colour the form is printed on: the form module's own background if it has one, else the nearest ancestor fill, where a marked panel counts as its RESOLVED fill (`surface` on a light band, `bg` = `light` on a surface band). Expected from the audit: contact-form `light`, cta-split-form `light`, contact-location-form `surface`, contact-details-form `surface`; the band walk, not the audit, decides. Each form drops its `field.*` colours, frame, radius (and padding if written), its placeholder colour, its whole `button.decoration.*` and button colours (and any button padding the kit now writes), and its captcha colour; it keeps the field font family/size, field types, labels, the module's own frame, radius, maxWidth and alignment, and every field child unchanged except decoration the kit now writes.

- [ ] **Step 1: Write the failing tests**: `testMarkedFormsCarryNoInkOfTheirOwnAndMatchTheirBand` (`ownInkProblems`, `bandProblems` for form markers, including a `divi/contact-field` child carrying a colour or frame under a marked form), `testMarkedFormsKeepOnlyStructureUnderEveryKitAndLanguage` (`mergeTrapProblems`: after resolving, every field/button/captcha leaf equals the kit's patch), `testTheFourFormMarkersArePinnedAndNoFormIsBespoke` (`BESPOKE_FORMS = []`, `BESPOKE_FORMS_MAX = 0`, both asserted), `testRecipesFileIsSmallerThanBeforeTheForms` (< 675,810 B), `testFormMarkedRecipeProseDoesNotDescribeTheFormLook`, plus the planted cases both ways (`testAnUnmigratedRecipeWithAMarkerFailsEveryCheckAndTheCleanShapePasses`, `testTheMergeTrapCatchesStraysAndOverrides`, `testTheFormBandWalkCatchesWhatItShould`).
- [ ] **Step 2: Migrate** with a minimal byte diff; reword prose that names the old field or button look; moods are not changed unless a language's `avoids` allows it (never add a mood-avoids exemption).
- [ ] **Step 3: `make test` (incl. `CardInkWalkTest`, `RecipeLibraryTest`, `make card-report` if touched); commit.**

---

### Task 5: Render gate: forms in verify-kits

**Files:**
- Modify: `scripts/lib/kit-checks.cjs` (`formMarkup(slot)`, `formAttrs(markup)`, `expectFormFromAttrs`, `missingFormExpectations`, `compareForm`, `formContrastProblems`; the form slots in `sheetMarkup()`), `scripts/verify-kits.cjs` (wiring, `form-observations.json`), `scripts/lib/kit-sheet-offline.php` (form slots), `scripts/test-verify-kits.cjs`, `scripts/test-kit-card-run.cjs` (synthetic departures), `Makefile` (the verify-kits comment)
- Create: `scripts/lib/kit-form-run.cjs` (exports `checkForms`, like `kit-item-run.cjs`)

**Interfaces:**
- Consumes: T2 markers; the button measures (`compareButton`, `shadowProblems`, the real-hover path) and the card gate machinery (expectations read from the resolved markup, a null never skips, admin bar and fixed elements hidden while sampling).
- Produces: per cell a `form` on the light band and one on the surface band (two fields, a captcha, a submit); codes `form` (count; a run that checked no form fails), `form-field-fill`, `form-field-border` (width/style/colour, per side for an underline), `form-field-radius`, `form-field-ink`, `form-field-placeholder` (`getComputedStyle(el, '::placeholder')`), `form-button` (the existing button measures incl. a real hover and any written lift), `form-captcha`, `form-contrast`, plus clipping and overflow at 1280/900/400.

- [ ] **Step 1: Write the pure tests first** (matching, mismatching, tolerance, a missing field fails, an empty selection fails, the offline sheet resolves with zero leftovers and passes `LayoutGate` for every kit x ten languages; synthetic Chromium departures, one per code); show RED then GREEN.
- [ ] **Step 2: Implement; do not touch the dev site.** Step 3: `node scripts/test-verify-kits.cjs`, `node scripts/test-kit-card-run.cjs`, `make test`; all old tests still pass; commit.
- [ ] **Step 4 (live, controller):** smoke `node scripts/verify-kits.cjs --kits quiet-solid,hairline-dashed,heavy-block --languages editorial,glass-dark`, expect 0 FAIL; fix rounds go back to the implementer.

---

### Task 6: Docs, token measurement, outcome

**Files:**
- Modify: the spec (Outcome section), `CLAUDE.md` (a compact "Forms adapter" paragraph after the Accordion adapter one, and the `make verify-kits` row: the form codes), `docs/token-usage.md` (`php scripts/measure-tokens.php`), `fixtures/shapes/kit-form-vocabulary.md` final pass, the `KitAdapters.php` / `KitResolver.php` docblock role tables

- [ ] **Step 1:** write the Outcome from measured facts only (probe counts and every DEAD result, the `FORM_PROVEN` values, proof cell counts, golden and near-duplicate counts, all byte sizes of spec section 6 before/after, live gate numbers from the controller's ledger), Rulings 1-5 restated, deviations, known limits (signup not adapted, no focus ring, no field hover, whatever T1 left unwritten), owner decisions left (merge and release; blurb and testimonial next; image-radius pass).
- [ ] **Step 2:** `make test`, `make test-pro`, `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` empty; commit.

---

## Gates (controller, live, serial; not tasks)

Check `pgrep -f "node scripts/verify-"` and `git branch --show-current` before and after each.

- `node scripts/verify-shapes.cjs fixtures/shapes/candidates/known.json` (or `make verify-shapes`): OK.
- `make verify-kits` (full run, about 2 hours plus the forms): every cell PASS with the form codes, 0 FAIL.
- Narrowed recipe render: `node scripts/verify-recipes.cjs --names contact-form,cta-split-form,contact-location-form,contact-details-form,<every recipe with a kit marker>,<10 guard recipes>` under the default look and the languages at 1280/900/400: 0 FAIL, baseline still empty.
- Then the final whole-branch review on the most capable model, one fix wave, one scoped re-review; the merge per the owner's instruction (no push).
