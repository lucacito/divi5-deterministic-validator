# Guide polish for rules and chips Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the guide gaps the dogfood check found for rules and chips, and extend the render gate to the adapter branches it never rendered.

**Architecture:** Task 1 changes only generated guide text in `wp-plugin/src/StyleGuide.php` (from `KitAdapters::APPLIED`, `RULE_PROVEN`, `CHIP_PROVEN`) plus its tests. Task 2 changes only dev tooling (`scripts/`). They share no file and may run in parallel worktrees.

**Tech Stack:** pure PHP 8 + PHPUnit; Node + Playwright render gate (controller runs every live gate).

**Spec:** `docs/superpowers/specs/2026-10-21-guide-polish-rules-chips-design.md` (read it: the findings, rulings and budgets live there).

## Global Constraints

- Validator `src/` and `wp-plugin/validator/` untouched; no new MCP tool, REST route or OpenAPI operation; no custom CSS/JS/PHP and no remote call in `wp-plugin/`; no hex in recipes; recipes, kits, probes, goldens untouched (`git diff main -- wp-plugin/data tests/golden fixtures/shapes` must be empty except where a task says otherwise).
- Every path or fact the guide states is conditional on the proven-decision maps exactly like the existing "Rules and chips" paragraph: a decision that is not proven is never claimed. Paths named are the probe-proven ones (`fixtures/shapes/kit-rule-vocabulary.md`, `kit-chip-vocabulary.md`).
- Budgets (asserted): default guide exactly 28,975 B; `kit-rules` at most 7,000 B (replaces the old 5,257 pin on purpose, with a comment); `controls` at most 9,500 B; composition at most 10,000 B; the 40 kit topics byte-identical to today (largest 9,599 B); catalog 8,444 B (50 kits at most 9,950 B); `topicParameterDescription()` 258 characters; no `aied` token in any served topic; `section-recipes.json` untouched.
- Never weaken a test; commit only your own files by explicit path; trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; never touch the dev site (controller runs live gates); run the free suite with `php -d memory_limit=-1 vendor/bin/phpunit` (make test needs docker).

## Review Focus

1. A guide sentence claiming a form the probe did not prove (a path, a style, a glyph field): the existing planted-false-map tests are extended to every new clause (T1).
2. The exact width rule in the guide disagreeing with `KitAdapters::ruleWidth()` for some kit: a test derives the guide's stated rule from `ruleWidth()` over widths 0px-4px and the double kit and compares (T1).
3. A new slot that passes while measuring nothing (count 0, null expectation): the empty-run and null-expectation tests cover every new slot (T2).
4. A new slot whose expectation disagrees with the PHP proof (a gate stricter than the proof gives false FAILs live): the new slots reuse the existing floors (strong 3.0, quiet 1.1, glyph on fill 3.0) and a test compares them (T2).

---

### Task 1: Generated guide wording (kit-rules, controls, forms, composition)

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php` (`ruleChipRules()`, `kitRules()`, `controlsKitSentence()`, the Contact forms paragraph / `formsKitSentence()`, the composition topic "One-side rule" section), `tests/StyleGuideKitRulesTest.php`, `tests/StyleGuideTopicsTest.php` or the composition topic's own test only where a pin must move
- Do not modify: `KitAdapters.php`, `KitResolver.php`, data, goldens

**Interfaces:**
- Consumes: `KitAdapters::APPLIED`, `RULE_PROVEN`, `CHIP_PROVEN`, `RULE_BANDS`, `CHIP_BANDS`, `ruleWidth(string $role, array $border): string` (the width table: quiet = max(1, min(2, w)); strong = max(2, min(4, w + 1)); a double never under 3px), the probe vocabularies.
- Produces: the revised served text of `kit-rules`, `controls`, the forms paragraph and composition; no new public API.

- [ ] **Step 1: Write the failing tests** in `StyleGuideKitRulesTest` (names are the assertions):
  - `testTheRulesPassageStatesTheExactWidthRuleAndMatchesRuleWidth`: the served `kit-rules` contains a sentence with the quiet rule (kit width, 1px minimum, 2px maximum) and the strong rule (kit width plus 1px, 2px minimum, 4px maximum), and for each width 0px-4px plus the double kit the numbers that sentence implies equal `KitAdapters::ruleWidth()` for both roles; a double is never under 3px.
  - `testTheRulesPassageNamesTheProvenShapes`: with the shipped (all-true) decisions the passage names `divider.advanced.line.desktop.value` with `color`, `weight`, `style`; the top edge as `module.decoration.border.desktop.value.styles` with `all` at 0px plus `top` (width, style, colour) and that the other three sides are zero; the tag (`module.decoration.border.desktop.value` radius corners and `styles.all`); the icon chip (the same radius, `module.decoration.background.desktop.value.color`, `icon.advanced.color.desktop.value`) and the glyph at `icon.innerContent.desktop.value` (`unicode`, `type`, `weight`); and that the line style is the kit's border style.
  - `testEachShapeIsOnlyStatedWhileItsDecisionIsProven`: planted-false maps remove the matching clause (divider shape absent when `divider-line` is false; top-edge shape absent when `group-top` and `column-top` are false; icon shape absent when all icon decisions are false; the glyph clause tied to `icon-fill`/`icon-glyph` being offered) and the removed text is asserted ABSENT; the all-true text for the pre-existing sentences is byte-identical to today's (pin the old paragraph's literal text as a prefix).
  - `testTheKitWinsOverTheLanguage`: `kit-rules` contains one sentence saying that where a design language's prose and the kit disagree about corners, borders, shadow or hover, the kit wins.
  - `testTheFormsTextSaysTheSubmitPathIsLiveOnAContactForm`: the served forms paragraph in `kit-rules` (or the forms topic sentence, wherever the submit path is named) says that on a contact form the submit's padding, shadow and lift are at `button.decoration.*`, the opposite of a `divi/button`, and still names no unproven decision.
  - `testControlsKitSentenceIsConditionalOnTheProvenDecisions`: with `divider-line` false the divider half is absent; with all icon decisions false the icon half is absent; with the shipped values the sentence is today's text (<= 250 B).
  - `testTheCompositionOneSideRuleSectionSaysTheKitDecides`: the composition topic's "One-side rule" section keeps its example and gains one clause that under a style kit the width, style and colour come from the kit (the example is the default look); composition <= 10,000 B.
  - `testTheBudgetsOfTheGuidePolish`: default guide `=== 28975`; `kit-rules <= 7000`; `controls <= 9500`; 40 kit topics byte-equal to the literal sizes already pinned (largest 9,599); catalog 8,444 and its 50-kit projection <= 9,950; `topicParameterDescription()` 258; no served topic contains `aied`.
  Replace the old `kit-rules <= 5,257` pin with `<= 7,000` and a comment naming this plan; re-pin the exact `kit-rules` and `controls` sizes where a test pins them exactly (deliberately, with a comment).
- [ ] **Step 2: Run** `php -d memory_limit=-1 vendor/bin/phpunit --filter 'StyleGuide'`; expect the new tests FAIL.
- [ ] **Step 3: Implement** in `StyleGuide.php`: extend `ruleChipRules()` with the generated shapes passage (each clause gated on the same maps as the existing sentences; paths written as in the vocabulary tables), add the "kit wins" sentence to the Reusing section, extend the forms text, make `controlsKitSentence()` conditional per carrier, add the composition clause. Wording is terse; measure the bytes.
- [ ] **Step 4: Run** the focused tests, then the full free suite and the Pro suite (`make test-pro`'s command, see the Makefile); expect green with every golden and every other served topic byte-identical (assert with the existing hash pins). Commit.

### Task 2: Render gate: the adapter branches the live gate never rendered

**Files:**
- Modify: `scripts/lib/kit-checks.cjs` (`RULE_SLOTS`, `CHIP_SLOTS`, `ruleMarkup`, `chipMarkup`, expectations, counts), `scripts/lib/kit-rule-run.cjs`, `scripts/verify-kits.cjs` (wiring, NOTE lines), `scripts/lib/kit-sheet-offline.php`, `scripts/test-verify-kits.cjs`, `scripts/test-kit-card-run.cjs`, `Makefile` (the verify-kits comment only)
- Do not modify: plugin PHP, tests/*.php, recipes, goldens

**Interfaces:**
- Consumes: the existing rule/chip gate machinery (Task 6 of the rules and chips plan): slots found by a unique heading, expectations read from the RESOLVED markup, `rule-style|width|colour|contrast`, `chip-radius|frame|fill|contrast`, floors strong 3.0 / quiet 1.1 / glyph on fill 3.0, admin bar and fixed elements hidden while sampling.
- Produces: new slots per kit/language cell: a `rule` and a `rule-strong` divider on the surface band (`on:"surface"`) and on the accent band (`on:"accent"`); a `rule` and a `rule-strong` top-rule group on the panel (inside an inverted `text`-filled card, `on:"panel"`); an icon chip on an accent band (`on:"accent"`: fill `surface`, glyph `text`); a tag chip on the dark photo band (`on:"dark"`) and one on the surface band. Counts, expectations and the empty-run guard cover them; the pure test count rises from 100.

- [ ] **Step 1: Write the pure tests first** in `scripts/test-verify-kits.cjs` (for each new slot: count, expectation read from the resolved markup incl. alpha forms, mismatch, tolerance, a missing slot fails, an empty selection fails, a null expectation fails; the offline sheet resolves with zero leftovers and passes `LayoutGate` for every kit x ten languages) and one synthetic Chromium departure per new slot in `scripts/test-kit-card-run.cjs` (a wrong style, a wrong width, a faint quiet line on the panel, an accent-band icon chip whose fill equals the band, a missing slot); show RED then GREEN. Chromium departures run on a LOCAL headless browser with synthetic pages (`PLAYWRIGHT_PATH=/Users/Lucas/Documents/JHMG-Local/layoutlab/node_modules/playwright`), never the dev site.
- [ ] **Step 2: Implement; do not touch the dev site.** Keep the old slots and codes as they are; add the new ones; keep all earlier tests passing.
- [ ] **Step 3:** `node scripts/test-verify-kits.cjs`, `node scripts/test-kit-card-run.cjs`, the free suite; commit. Report the new pure test count.
- [ ] **Step 4 (live, controller):** smoke `node scripts/verify-kits.cjs --kits quiet-solid,hairline-dashed,double-frame,dotted-pill,raw-frame --languages editorial,glass-dark`, 0 FAIL, then the full `make verify-kits`.

---

### Task 3: Second wording pass (the gaps all three dogfood assistants reported)

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php` (the default guide's Structure block; the Rules and chips passage; the Contact forms paragraph; optionally the Items paragraph), `tests/StyleGuideKitRulesTest.php` and whichever test pins the default guide size (`StyleGuideTopicsTest` / `StyleGuideKitTopicsTest`: find with grep `28975`)
- Do not modify: `KitAdapters.php`, data, goldens, recipes

**Interfaces:**
- Consumes: Task 1's passage (merged); `KitAdapters::formPatch()` (the field padding is written at `field.decoration.spacing.{bp}.value.padding.{top,right,bottom,left}`, proven by probe kf-field-padding and `FORM_PROVEN['field-padding']`), `KitAdapters::item()` output for `divi/accordion` (read-only; for the leaves), the validator's own statement (`wp-plugin/validator/Validator.php` ~287: a layout may start with `divi/placeholder` OR directly with `divi/section`, confirmed across real exports).
- Produces: reworded served text; the default guide at most 29,108 B (its existing ceiling; today 28,975, re-pinned deliberately with a comment); `kit-rules` at most 7,600 B (this task amends the plan's 7,000 B pin on purpose: Ruling 5 below).

- [ ] **Step 1: Write the failing tests:**
  - `testTheStructureBlockSaysTheRootWrapperIsOptional`: the default guide's Structure block no longer says `(root wrapper, required)`; it says that a layout may start with `divi/placeholder` (Divi's own exports do) or directly with `divi/section` (as the worked examples do), and both validate; the default guide `<= 29108` and `>= 28975 - 0` (re-pinned exact size with a comment); `StyleGuideTopicsTest`'s pin for 28,975 moves deliberately.
  - `testTheTagChipClauseSaysTransparentFillAndOwnPadding`: the tag clause says the fill stays transparent and the tag's padding and size are yours (only while the tag is offered; absent when tag decisions are planted false).
  - `testTheContactFormsParagraphNamesTheFieldPaddingPath`: the Contact forms paragraph names `field.decoration.spacing.desktop.value.padding` (the four sides, the kit's control padding) only while `field-padding` is proven (planted false removes it).
  - `testTheItemsParagraphNamesWhereAnAccordionCarriesTheKitLook` ONLY if it fits the budget: the Items paragraph says where an accordion parent carries the frame, radius, shadow and title/icon colours (taken from `KitAdapters::item()` for `divi/accordion`, one clause per leaf group, paths as probe-proven in `fixtures/shapes/kit-item-vocabulary.md`); skip this test and the clause if `kit-rules` would pass 7,600 B and say so in the report.
  - `testTheBudgetsOfTheSecondPass`: default guide pinned exactly and `<= 29108`; `kit-rules <= 7600`; `controls`, `forms`, the 40 kit topics (largest 9,599), the catalog (8,444) and `topicParameterDescription()` (258) unchanged; no served topic contains `aied`.
- [ ] **Step 2: Run** `php -d memory_limit=-1 vendor/bin/phpunit --filter 'StyleGuide'`; expect FAIL.
- [ ] **Step 3: Implement** the wording in `StyleGuide.php` (terse; every clause gated like the existing ones).
- [ ] **Step 4: Run** the focused tests, the full free suite and the Pro suite; commit.

---

### Task 4: Docs and live gates (controller)

- [ ] Append the Outcome to the spec: what changed, the byte sizes before/after (`php scripts/measure-tokens.php`, `docs/token-usage.md`), pure test count, live gate numbers, the dogfood re-run on dotted-pill, the findings left for a separate guide pass (Ruling 4), and update the "Rules and chips adapter" paragraph of `CLAUDE.md` (known limits).


---

### Task 5: Round 3 wording (the second dogfood round: freehand underlined-label/glass-dark and soft-rounded/organic-warm, and an edit of a glass-edge page)

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php` (the generated kit-rules text: `ruleChipRules()` / `ruleChipShapes`-style helper, the Reusing section, the Items paragraph), `tests/StyleGuideKitRulesTest.php`
- Do not modify: `KitAdapters.php`, `KitResolver.php`, data, goldens, recipes, fixtures

**Interfaces:**
- Consumes: the merged guide polish (kit-rules 7,864 B); `KitAdapters::RULE_BANDS`, `CHIP_BANDS`, `RULE_COLOUR`, `chipPatch()` (the tag frame colour is `RULE_COLOUR['rule'][on]` when the kit's frame role is `border`, else `RULE_COLOUR['rule-strong'][on]`); the probe fact in `fixtures/shapes/kit-rule-vocabulary.md` section "Guide-only fact" (a top edge on a block that already has a frame: keep `styles.all`, write only `styles.top`); shipped recipes for the accordion-item shape and the glyph codes.
- Produces: reworded served text; `kit-rules` at most 9,000 B (a deliberate raise of the 7,900 B pin, real bound 10,000); default guide exactly 28,975 B; everything else byte-identical.

- [ ] **Step 1: Write the failing tests** (names are the assertions; each conditional clause also gets a planted-false-map test asserting ABSENT):
  - `testTheTopEdgeClauseSaysHowToRuleABlockThatAlreadyHasAFrame`: the shapes passage says: on a block that already has a frame keep `styles.all` and write only `styles.top` (width, style, colour); the `all` 0px form is for a block without a frame; only while a group or column rule carrier is offered.
  - `testTheRulesPassageSaysThereIsNoSideEdge`: one sentence: there is no left or right edge, so side-by-side columns are not separated by a vertical rule; use the top edge or a divider line.
  - `testTheBandsAreDefinedOnce`: one sentence defining `on`: `light` is the language's own page colour (also in a dark language), `surface` its surface band, `accent` the accent band, `dark` a photo or overlay band, `panel` the inside of an inverted card; the sentence names only bands in `KitAdapters::RULE_BANDS` / `CHIP_BANDS`.
  - `testTheTagFrameColourAndTheChipSizeAreStated`: the tag clause gives the frame colour (the quiet hairline colour for a kit whose border colour is `same` or `border`, the strong ink otherwise, per `chipPatch()`; check against `chipPatch()` for several kits and bands) and the icon-chip clause says its size is the glyph size (`icon.advanced.size.desktop.value`) plus the module padding (`module.decoration.spacing.desktop.value.padding`), the glyph box being yours to size.
  - `testTheAccordionItemShapeIsTaught`: the Items paragraph (only while the accordion answers an item role) gives the item shape: `title.innerContent.desktop.value`, `content.innerContent.desktop.value` and `module.advanced.open.desktop.value` "on" for the open item, as the shipped recipes write it (verify against faq-accordion's markup).
  - `testTheGlyphCodesAreTheOnesTheShippedRecipesRender`: the icon-chip clause lists glyph codes, each present in the shipped recipes' `divi/icon` blocks with type `fa` weight `900` (derive the allowed set from `section-recipes.json`; a planted code outside the set fails); at most 12 codes with their usual names only where certain (f00c check, f00d close, f005 star, f017 clock, f0e7 bolt, f201 chart, f3c5 pin, f0e0 envelope, f095 phone, f013 gear, f10d quote).
  - `testTheBudgetsOfRound3`: default guide exactly 28,975; `kit-rules <= 9000` (comment: deliberate raise, real bound 10,000); controls (8,331), forms (6,474), composition (8,706), catalog (8,444), the 40 kit topics (largest 9,599) and `topicParameterDescription()` (258) unchanged; no served topic contains `aied`.
- [ ] **Step 2: Run** `php -d memory_limit=-1 vendor/bin/phpunit --filter 'StyleGuide'`; expect the new tests FAIL.
- [ ] **Step 3: Implement** the wording (terse; each clause gated like the existing ones; if `kit-rules` would pass 9,000 B drop the glyph-code clause first and say so in the report).
- [ ] **Step 4: Run** the focused tests, the free suite and the Pro suite; commit.
