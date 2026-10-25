# Carriers Adapter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The existing kit roles reach four more carriers, and one role is new. `rule-accent` (new) on `divi/divider` (light and surface bands: the accent colour at the strong rule width, in the kit's style); `card` / `panel` on `divi/testimonial` (the card patch, no ground); `chip` on `divi/blurb` (the icon chip at the blurb's `imageIcon.*` paths); `rule` / `rule-strong` on `divi/number-counter` (the group top edge). Every path is probe-proven ON its carrier and pinned in `KitAdapters::CARRIER_PROVEN`; the colours are proven with zero exceptions; 11 library blocks in 5 recipes migrate; the guide wording is generated.

**Architecture:** The rules-and-chips pattern again (probe first, a proven-decisions constant pinned to a vocabulary table, golden + sha256 freeze, migration tests with own ink / band walk / merge trap, render-gate codes in `verify-kits`). Probe (Task 1); audit helper and the measured accent floor (Task 2); adapter, resolver and generated guide (Task 3); proofs and the new golden (Task 4); the recipe migration by a deterministic script (Task 5); render-gate slots (Task 6); docs (Task 7); controller live gates and the whole-branch review (Task 8).

**Tech Stack:** pure PHP 8 (`wp-plugin/src/`), PHPUnit (`php -d memory_limit=-1 vendor/bin/phpunit`, `make test`), Node + Playwright probe/render scripts against the Docker dev site (Divi 5.15.1).

**Spec:** `docs/superpowers/specs/2026-10-24-carriers-adapter-design.md` (Rulings 1-12). Templates: `docs/superpowers/plans/2026-10-21-rules-chips-adapter.md` and its spec Outcome, `docs/superpowers/plans/2026-10-24-type-adapter.md` (plan format). Code to mirror: `KitAdapters::rulePatch()` / `chipPatch()` / `card()` + `RULE_PROVEN` / `CHIP_PROVEN`, `KitResolver::marker()`, `KitRuleAdapterTest::vocabularyDecisions()` / `probedPaths()`, `KitChipAdapterTest` (contrast cells), `KitRuleGoldenTest`, `KitGoldenFreezeTest`, `RuleMigrationTest` / `ChipMigrationTest` (helpers `blocks()`, `leaves()`, `bandProblems()`, `mergeTrapProblems()`), `tests/Support/RuleChipAudit.php`, `RecipeLibraryTest::markerBands()`, `scripts/verify-kits.cjs` + `scripts/lib/kit-checks.cjs` + `scripts/lib/kit-rule-run.cjs` + `scripts/lib/kit-card-run.cjs` + `scripts/lib/kit-sheet-offline.php`, `scripts/test-verify-kits.cjs`, `scripts/test-kit-card-run.cjs`, `scripts/migrate-type-markers.php` (the idempotent migration-script shape).

Branch: the controller's (default `feat/carriers-adapter` off the `feat/finish-leftovers` head 7165133, which is local main 2f10970 plus one guide commit), worktree `/Users/Lucas/Documents/JHMG-Local/wt-carriers-adapter` for implementers. `git merge --no-ff` into LOCAL main at the end. NO push, release, version bump, zip rebuild, readme edit or SVN.

Decisions this plan makes where the spec says "Left to the builder" (each restated in the spec Outcome by Task 7):
- One constant `KitAdapters::CARRIER_PROVEN` (20 keys, the "Adapter decisions" table of `fixtures/shapes/kit-carrier-vocabulary.md`, in order); `RULE_PROVEN` and `CHIP_PROVEN` are NOT changed (each is pinned to its own vocabulary file).
- New names: `KitAdapters::{CARRIER_PROVEN, ACCENT_RULE_COLOUR, ACCENT_RULE_BANDS, GROUND_BLOCKS}`, private `KitAdapters::RULE_CARRIERS` gains `'divi/number-counter' => 'counter-top'`, `StyleGuide::{appliedNounsSentence(), carrierRules()}`, `tests/Support/CarrierAudit.php`, `tests/CarrierAuditTest.php`, `tests/KitCarrierAdapterTest.php`, `tests/KitCarrierGoldenTest.php`, `tests/golden/kit-carrier-patches.json`, `tests/CarrierMigrationTest.php`, `scripts/migrate-carrier-markers.php`, `scripts/lib/kit-carrier-run.cjs`, `RuleChipAudit::ACCENT_RULE_FLOOR`.
- No new Make target (the migration report is `CarrierAuditTest`'s pinned table; `make card-report` is untouched).

## Controller rulings (2026-10-24; WHERE THIS SECTION CONFLICTS WITH THE TEXT BELOW, THIS SECTION WINS)

Restated from the spec; they bind every implementer:
1. **Ruling 1 (`rule-accent` bands):** the measured accent-vs-band minimum is light 2.304, surface 2.448, accent 1.000, dark 1.401, panel 1.103 (playful-pastel / corporate-trust / tech-mono); no band is 3:1 in all ten languages. `rule-accent` is a decorative brand mark with `RuleChipAudit::ACCENT_RULE_FLOOR = 2.3` (the shipped library's minimum, already the `accent | light | 2.304` row of `fixtures/shapes/rule-floor.md`, rounded down, frozen, never lowered); bands `light` and `surface` ONLY.
2. **Ruling 2:** `rule-accent` on `divi/divider` only.
3. **Ruling 3:** `divi/testimonial` takes `card` and `panel`; the library block is a `panel` on `light`. No ground on a testimonial (`GROUND_BLOCKS` in KitAdapters and KitResolver).
4. **Ruling 4:** the four counters are `rule-strong` on `accent` (full `accent-text` ink = strong, the rules adapter's Spec issue 6 rule).
5. **Ruling 5:** the blurb chip writes radius, fill and glyph only; the recipe keeps padding (16px) and glyph size (32px).
6. **Ruling 6:** a NEW golden `tests/golden/kit-carrier-patches.json` (240 patches), frozen as the ninth `KitGoldenFreezeTest` entry; the eight existing hashes are NOT touched.
7. **Ruling 7:** the catalog (`style-kits`) prints `appliedNounsSentence()` (noun-only); `kit-rules` keeps `appliedSentence()`.
8. **Ruling 8:** `kit-rules` pin 9,000 -> 9,600 B; the new carrier wording is ONE generated paragraph `## More carriers` (`carrierRules()`, at most 560 B incl. heading), the Rules and chips paragraph and its 525 B / 1,900 B sub-pins do not move.
9. **Ruling 9:** `notAppliedSentence()` says "keeps" when only "every other module" is left.
10. **Ruling 10:** `controls` changes only by its generated sentence (expected "`rule` or `rule-strong` or `rule-accent`", +18 B, pinned exactly by Task 3).
11. **Ruling 11:** `RuleMigrationTest`, `ChipMigrationTest`, `RecipeLibraryTest` card checks and `LanguageResolverTest::testTheSharedScannerKeepsKitOutputByteIdentical` are scoped to the carriers/roles they were written for, by the minimal edits listed in Task 5; the 11 new markers are pinned in `CarrierMigrationTest`.
12. **STOP conditions (probe):** a carrier BASE decision DEAD (`testimonial-fill`, `testimonial-frame`, `testimonial-radius`, `testimonial-quote`, `blurb-icon-radius`, `counter-top`, `divider-accent-over-preset`) -> STOP before Task 3; the controller drops the carrier or amends the spec. Any other DEAD decision -> its key is false, the adapter writes nothing for it (fallbacks in Task 3) and the guide says so.

## Global Constraints

Copied from the spec and from CLAUDE.md's hard constraints:
- **The validator is deterministic. No AI/LLM calls in it, ever.** `src/` and `wp-plugin/validator/` are not touched (`git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` stays empty).
- **No Divi schema from memory.** Every leaf an adapter writes is in `fixtures/shapes/verified-shapes.json` AND has a PASS candidate ON its carrier in `fixtures/shapes/probe-results-kit-carrier.json` (the registry is path-only: `module.decoration` hits different elements on different modules). `imageIcon.decoration.border.*` is not in the registry today: Task 1's promotion adds it.
- **Recipes and guides are grounded in real exports**; every migrated recipe is re-validated through `LayoutGate` under every kit and every language; recipes keep tokens and markers only (no hex, no literal family), no custom CSS/JS/PHP.
- Anti-goals: no new MCP tool, REST route, OpenAPI operation or guide topic NAME; no remote call or custom code in `wp-plugin/`; no weakened test; no baseline file for a proof (zero exceptions); the eight frozen goldens byte-identical.
- Sizes (measured at 7165133 by the plan writer; Task 0 re-measures and STOPS on a difference): default guide **exactly 28,975 B**; `topicParameterDescription()` **exactly 258 characters**; `kit-rules` 8,997 B -> **<= 9,600 B** (Ruling 8); catalog `style-kits` 8,444 B -> **about 8,178 B** (Ruling 7), 50-kit projection **<= 9,950 B**; `controls` 8,331 B -> **8,331 + the generated delta** (Ruling 10); every `style-kit:<id>` topic **unchanged** (largest neo-brutal 9,599 B); every other topic unchanged; `section-recipes.json` **< 652,430 B** (simulated about 651,200 B) and below 716,800 B; `style-kits.json` 25,479 B and `design-languages.json` unchanged.
- Register no new `wp-plugin/src/*.php` file (none is planned; if one is added, `wp-plugin/src/autoload.php` and `PluginAutoloadCoversEverySourceFileTest`).
- `tests/WpOrgComplianceTest.php` untouched and green; the Pro suite (`make test-pro`, 2169) green and untouched; `wp-plugin/readme.txt` untouched.
- macOS `sed -i` needs a suffix: edit data with PHP or `python3 -I` scripts, never `sed -i`. Recipe edits ONLY through `scripts/migrate-carrier-markers.php` plus the one prose fix of Task 5, never by hand.
- Max 3 parallel workers. ONE probe or render at a time on the dev site (`pgrep -f "node scripts/verify-"` empty first). Implementers never touch the dev site; the controller runs every live gate. **Live gates that execute plugin PHP on the site (`verify-kits`, `verify-recipes`) must run from the MAIN checkout with the branch checked out** (`docker-compose.yml` mounts `./wp-plugin` of the main checkout read-only). `verify-shapes` renders raw attributes and may run from a worktree with `PLAYWRIGHT_PATH=/Users/Lucas/Documents/JHMG-Local/layoutlab/node_modules/playwright` and `COMPOSE_PROJECT_NAME=divi5deterministicvalidator`. Check `git branch --show-current` before and after every long run.
- Commit only your own files by explicit path (`git add <path>...`), message ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Explicit model per Agent dispatch: T0 sonnet, T2 sonnet, T3 **opus**, T4 sonnet, T5 **opus**, T6 sonnet, T7 sonnet; task reviews sonnet; plan review and final whole-branch review **opus**. Task 1 (probe) and Task 8 are the controller's.

## Review Focus

Each line names the test that pins it and the task that owns it.

1. A leaf written that Task 1 did not prove ON that carrier: `KitCarrierAdapterTest::testEveryLeafIsAProbedPathOnItsCarrier`, `testCarrierProvenMatchesTheVocabularyTable` (T3).
2. A new carrier silently changing an old carrier's patch: `KitGoldenFreezeTest::testTheEarlierGoldensAreByteIdentical` (8 hashes unchanged) and the existing golden tests (T4); `testTheGroupColumnDividerIconPatchesAreUnchanged` (T3: a sha256 over every old (block, role, band, kit) patch taken at the Task 3 base).
3. A ground accepted on a testimonial, `rule-accent` on a band or block it does not take, a chip on a blurb without both colours proven on accent: `testUnknownCombinationsAreNull`, `KitResolverTest::testCarrierMarkersTheResolverDoesNotKnowAreLeftAndReported` (T3).
4. Registration points disagreeing (APPLIED vs ROLE_BLOCKS vs ROLE_SURFACES vs the guide): `KitCardAdapterTest`'s existing ROLE_BLOCKS-equals-APPLIED test, `testRoleSurfacesNameTheAdaptersBands` (T3).
5. A twin identity broken (testimonial vs group card, counter vs group rule, blurb vs icon chip colours) while all decisions are true: `testTheTestimonialPatchIsTheGroupCardPatch`, `testTheCounterPatchIsTheGroupRulePatch`, `testTheBlurbChipTakesTheIconChipColours` (T3).
6. An unreadable cell: `testAccentRuleContrastOnEveryKitLanguageAndBand` (800), `testTheAccentRuleBandsAreMaximal`, `testBlurbChipGlyphContrast` (1,600), `testTheTestimonialsOwnInksAreReadableOnBothFills` (80) (T4).
7. A floor lowered or typed by hand: `RuleChipAuditTest::testTheAccentFloorIsTheShippedMinimumRoundedDown` (T2).
8. A migrated block keeping the literal the kit writes, a marker on the wrong band, a block lost or added: `CarrierMigrationTest` (`testTheElevenMarkersArePinnedAndNothingIsBespoke`, `testMarkedCarriersCarryNoInkOfTheirOwnAndMatchTheirBand`, `testMarkedCarriersKeepOnlyStructureUnderEveryKitAndLanguage`) (T5).
9. A guide clause claiming an unproven decision, a path in the guide typed by hand, a byte budget broken: `StyleGuideKitRulesTest::testTheMoreCarriersParagraphFollowsTheMapAndTheDecisions`, `testTheCarrierBudgets` (T3).
10. A render gate that passes when nothing was measured or an expectation is null: `emptyRunProblems` extended with `carriersChecked`, one synthetic departure per new code (T6).
11. An old test silently widened or weakened by the scoping edits: every edit of Task 5 Step 5 is listed with its before/after assertion; the reviewer diffs them line by line (T5).

---

### Task 0: Measure, pin the budgets, inventory every test that will move (pure; sonnet; first)

**Files:**
- Create: `tests/CarrierBudgetTest.php`
- Modify: none

**Interfaces:**
- Produces: the size pins every later task keeps; the confirmed list of tests Tasks 3 and 5 edit.

- [ ] **Step 1: Re-measure at the branch head** with a throwaway script in the scratchpad (not committed; load `tests/bootstrap.php` and `wp-plugin/src/autoload.php`): `strlen()` of `StyleGuide::markdown()`, `topicParameterDescription()`, `markdown($t)` for `kit-rules`, `style-kits`, `controls`, `typography`, every `style-kit:<id>` (largest), every `StyleGuide::topics()` (largest), the 50-kit catalog projection (the reflection trick of `StyleGuideKitRulesTest` line 760-777), `filesize()` of `section-recipes.json`, `style-kits.json`, `design-languages.json`. Expected (STOP and report if any differs): 28,975 / 258 / 8,997 / 8,444 / 8,331 / 7,943 / neo-brutal 9,599 / `layout-advanced` 9,973 / 9,928 / 652,430 / 25,479 / 28,662.
- [ ] **Step 2: Simulate the new sentences** (same script): `StyleGuide::appliedSentence($n)` and `notAppliedSentence($n)` with `$n = APPLIED` plus `divi/divider` => `['rule','rule-strong','rule-accent']`, `divi/testimonial` => `['card','panel']`, `divi/blurb` => `['chip']`, `divi/number-counter` => `['rule','rule-strong']` (measured while writing this plan: 445 -> 524 B with `rule-accent` as an unnamed role; Task 3 adds it to `APPLIED_NOUNS` as "rules", expected about 494 B). Record both numbers in the commit message.
- [ ] **Step 3: Write `tests/CarrierBudgetTest.php`** (green now; Task 3 tightens it): `testTheDefaultGuideAndTheTopicDescriptionDoNotMove` (28975, 258), `testTheKitTopicsDoNotMove` (every `style-kit:<id>` equals its size today, pinned as a 40-entry literal map generated once by the scratch script; largest 9599), `testKitRulesStaysWithinItsPin` (`<= 9600`), `testTheCatalogProjectionStaysWithinItsBound` (50-kit projection `<= 9950`), `testTheRecipeFileDoesNotGrow` (`<= 652430`).
- [ ] **Step 4: Confirm the inventory** with `git grep -n -E "8444|8997|9000|assertSame\( 45|'divi/blurb'|stats-counter-band\|divi/number-counter|blog-feed\|divi/divider|icon-values\|divi/divider|LegacyKitResolver" -- tests`; the hits must be within this list (any other file: STOP, the controller extends Task 3 or 5):

  | File | What moves | Owner |
  |---|---|---|
  | `tests/StyleGuideKitRulesTest.php` (247, 249, 519, 1321, 1342, 1361, 1651; the `9000` bounds 129, 348-349, 741, 1317, 1339, 1357) | `kit-rules` 8,997 -> measured, pin 9,600; catalog 8,444 -> measured | T3 |
  | `tests/StyleGuideKitTopicsTest.php` 589 (and 98-99 keep passing: `Kit-driven today:` and `notAppliedSentence()` stay in the catalog) | catalog size | T3 |
  | `tests/LanguageTypeBudgetTest.php` 55, `tests/LanguageRhythmBudgetTest.php` 48 | catalog 8,444 -> measured; `controls` 8,331 -> measured | T3 |
  | `tests/KitChipAdapterTest.php` 413 (`divi/blurb` in the "no chip here" list) | `divi/blurb` removed from that list | T3 |
  | `tests/RuleChipAuditTest.php` 281 | the counters are no longer exemptions | T5 |
  | `tests/RuleMigrationTest.php` 161-176 (`EXEMPT_LOOKS`), 384, 414, 452-455, 464 | scoped to divider/group/column; 15 -> 9 exemptions; unmarked list `[]` | T5 |
  | `tests/ChipMigrationTest.php` (chip marker parsing) | scoped to group/icon | T5 |
  | `tests/RecipeLibraryTest.php` 1796 (`45` kit-marker recipes) | 45 -> 49 | T5 |
  | `tests/LanguageResolverTest.php` 408-438 (legacy scanner proof) | scoped to the 44 kit-marker recipes whose markers are all legacy (block, role) pairs | T5 |
  | `tests/KitGoldenFreezeTest.php` | ninth entry | T4 |
- [ ] **Step 5:** `php -d memory_limit=-1 vendor/bin/phpunit --filter CarrierBudgetTest` PASS; commit `tests/CarrierBudgetTest.php`.

---

### Task 1: Probe the carrier paths ON their carriers (live, serial, controller-run)

**Files:**
- Create: `fixtures/shapes/candidates/kit-carrier.json`, `fixtures/shapes/kit-carrier-vocabulary.md`
- Written by the probe: `fixtures/shapes/probe-results-kit-carrier.json`
- Modify (through `php scripts/promote-shapes.php` only): `fixtures/shapes/verified-shapes.json` (new paths: `imageIcon.decoration.border.{bp}.value.radius.*`; evidence ids for the rest), `docs/shape-evidence-5.15.1.md` (the `kx-*` sections and the "Not taught" list); by hand only for a DEAD path the library would write: `fixtures/shapes/dead-on-module.txt` (one line per carrier and path, with the evidence id)

**Interfaces:**
- Consumes: the candidate format of `fixtures/shapes/candidates/kit-card.json` / `kit-rule.json` / `kit-chip.json` (`id`, `block`, `base`, `set`, `parent`, `wrapperBase`, `state`, `pseudo`, `geometry`, `expect`, `note`); `scripts/verify-shapes.cjs` already supports `testimonial`, `blurb`, `number-counter` and `divider` (its `SUPPORTED` list).
- Produces: `kit-carrier-vocabulary.md` in the layout of `kit-rule-vocabulary.md`, ENDING with `## Adapter decisions` and `| Decision | Verdict | Path the adapter writes | Evidence |`, exactly these 20 rows in this order (Task 3's constant and tests read them): `testimonial-fill`, `testimonial-frame`, `testimonial-underline`, `testimonial-radius`, `testimonial-quote`, `testimonial-padding`, `testimonial-shadow`, `testimonial-hover-lift`, `testimonial-hover-frame`, `testimonial-hover-shadow`, `blurb-icon-radius`, `blurb-icon-fill`, `blurb-icon-glyph`, `counter-top`, `counter-top-dashed`, `counter-top-dotted`, `counter-top-double`, `counter-top-alpha`, `divider-accent-over-preset`, `divider-accent-style`.

- [ ] **Step 0: Measure Divi's defaults first** (one base render per carrier, no `set`): the testimonial's computed `padding` (Divi's CSS: `.et_pb_testimonial{padding:30px}`, `.et_pb_testimonial_no_bg{padding:30px 0 0}`), its background (a default fill would make a `#ffffff` control DEAD), its `::before` quote box (`top:-16px`, `background:#f5f5f5` in Divi's CSS); the blurb `.et-pb-icon` `border-radius` (0) and `border` (`0 solid #333`); the counter's `border-top` (none); the divider's line over `modulePreset:["1ep0co506u"]` (weight, style, width: the preset may not exist on the dev site; record what renders). **A control must differ from the measured default.**
- [ ] **Step 1: Write the candidates** (all rendered at 1280/900/400; colours are probe hexes, never tokens):
  - testimonial (`block: "testimonial"`, base = the recipe's shape: author, jobTitle, company, content, `module.decoration.overflow` x/y `visible`, `layout.display` block, a background `#e0f2fe`): `kx-testimonial-control` (fill `#fde68a`, PASS), `kx-testimonial-dead-padding-30px` (set the measured default padding, expect DEAD), `kx-testimonial-fill` (PASS), `kx-testimonial-frame-width-{1px,2px,4px}`, `kx-testimonial-style-{dashed,dotted,double}`, `kx-testimonial-frame-colour`, `kx-testimonial-edge-bottom` (`styles.all` 0px + `styles.bottom` 2px), `kx-testimonial-radius-{16,pill}`, `kx-testimonial-padding-48` (`module.decoration.spacing.desktop.value.padding` 48px x4), `kx-testimonial-shadow-{soft,hard,edge,glow}` (the four `KitAdapters::shadow()` value forms), `kx-testimonial-hover-lift` (`state: hover`, `transform.desktop.hover.translate.y` -4px), `kx-testimonial-hover-frame`, `kx-testimonial-hover-shadow`, `kx-testimonial-quote` (radius 16px with the base's `overflow: visible`: `pseudo: "::before"` + `geometry`, PASS = the quote box keeps its top above the module and is not clipped). 22 candidates (20 PASS, 1 DEAD, plus the control).
  - blurb (`block: "blurb"`, base = the recipe's icon: `useIcon` on, `&#xf005;` fa 900, `imageIcon.decoration.background` `#f59e0b`, `imageIcon.advanced.color` `#111827`, iconFontSize 32px, alignment left, padding 16px x4): `kx-blurb-icon-control` (fill `#2563eb`, PASS), `kx-blurb-icon-dead-radius-0px` (DEAD), `kx-blurb-icon-radius-{4px,12px,999px}` (four corners, PASS), `kx-blurb-icon-radius-on-fill` (radius 12px; PASS only when the element whose `border-radius` changed is the element that paints the fill: the `sample` selector of this candidate and of `kx-blurb-icon-control` must be the same `.et-pb-icon`), `kx-blurb-icon-fill`, `kx-blurb-icon-glyph`, `kx-blurb-icon-pill-in-column` (999px with the base padding: no `phoneOverflow`, the box inside its column at 400 px). 9 candidates.
  - counter (`block: "number-counter"`, base = the recipe's counter: title, number 64/56/44px, orientation left, `spacing.padding.top` 20px, `sizing.width` 100%, `border.styles.all` 0px `#111827` solid + `styles.top.width` 1px): `kx-counter-control` (top colour `#2563eb`, PASS), `kx-counter-dead-all-0px` (DEAD), `kx-counter-top-{solid,dashed,dotted,double}` (style on `all` and `top`), `kx-counter-top-width-{1px,2px,3px,4px}`, `kx-counter-top-colour-alpha` (`rgba(17, 24, 39, 0.4)` on `all` and `top`). 11 candidates.
  - divider over the preset (`block: "divider"`, base `{"modulePreset":["1ep0co506u"],"divider":{"advanced":{"line":{"desktop":{"value":{"color":"#f59e0b"}}}}}}`): `kx-divider-accent-control` (colour `#2563eb`, PASS), `kx-divider-accent-weight-{2px,3px,4px}` (PASS: the block's weight wins over the preset), `kx-divider-accent-style-dashed` (PASS), `kx-divider-accent-alignself` (blog-feed's `module.decoration.sizing.desktop.value.alignSelf` `flex-start` in the base, set weight 3px, PASS). 6 candidates.
  - Total 48 candidates, 3 expected DEAD (`*-dead-*`); notes cite `BlurbModule.php` (the `imageIcon.decoration.border` selector `.et_pb_main_blurb_image .et-pb-icon`), `testimonial/module.json` (`border` propertySelectors, `quoteIcon` selector `:before`) and the Divi CSS defaults of Step 0.
- [ ] **Step 2: Run, one run at a time.** `pgrep -f "node scripts/verify-"` empty, then `node scripts/verify-shapes.cjs fixtures/shapes/candidates/kit-carrier.json`. Check: every control PASS and every expected DEAD DEAD (else the run is not trusted: re-probe, never freeze); each width candidate's sample shows the computed width; each hover candidate changed only in the hover state; no `phoneOverflow`; no `aied shape probe ` page remains. Record every DEAD / css-only / OVERFLOW; never drop a candidate.
- [ ] **Step 3: Promote** with `php scripts/promote-shapes.php fixtures/shapes/probe-results-kit-carrier.json fixtures/shapes/candidates/kit-carrier.json`. Default-value controls get NO `dead-on-module.txt` line.
- [ ] **Step 4: Write the vocabulary file.** A decision is PASS only when every candidate it names PASSED ON the carrier at all three widths (`testimonial-frame`: the three widths, three styles and the colour; `counter-top`: control + four widths + solid; `blurb-icon-radius`: three radii + on-fill). Apply controller ruling 12 (STOP on a base decision). Then `make test` green (registry, evidence and dead-on-module tests); commit the five files.

---

### Task 2: Audit helper and the accent floor (pure; sonnet; worktree, parallel with Task 1)

**Files:**
- Create: `tests/Support/CarrierAudit.php`, `tests/CarrierAuditTest.php`
- Modify: `tests/Support/RuleChipAudit.php` (`ACCENT_RULE_FLOOR` constant + docblock only; SHAPE_EXEMPTIONS stay until Task 5), `tests/RuleChipAuditTest.php` (one new test)

**Interfaces:**
- `CarrierAudit::blocks( array $recipes ): list<array{recipe:string,block:string,index:int,role:string,on:string,marked:bool}>`: the 11 target blocks, identified by the rule of the spec's audit table (an accent-coloured `divi/divider`; a `divi/testimonial` with a module background; a `divi/blurb` with `imageIcon.decoration.background`; a `divi/number-counter` with `styles.all` 0px + `styles.top` > 0), `on` from `RecipeLibraryTest::markerBands()` (the walk the rule and chip audits use), `role` by Rulings 1-4 or by the marker once marked, so the list is the same before and after Task 5.
- `CarrierAudit::UNADAPTED`: `module => reason` for the spec's "stays unadapted" table (`divi/circle-counter`, `divi/slider`, `divi/slide`, `divi/countdown-timer`, `divi/social-media-follow`, `divi/before-after-image`, `divi/blog`, `divi/shop`, `divi/group-carousel`, `divi/signup`), with `UNADAPTED_COUNTS` (blocks, recipes) measured: 4/1, 2/2, 6/2, 1/1, 1/1, 1/1, 1/1, 1/1, 2/2, 0/0.
- `RuleChipAudit::ACCENT_RULE_FLOOR = 2.3` (docblock: the `accent | light | 2.304 | playful-pastel` row of `fixtures/shapes/rule-floor.md`, two shipped rules, rounded down, never lowered).

- [ ] **Step 1: Write the failing tests.** `CarrierAuditTest::testTheAuditFindsTheElevenCarrierBlocks` (pinned table: `blog-feed|divi/divider|0` rule-accent light, `icon-values|divi/divider|0` rule-accent light, `testimonial|divi/testimonial|0` panel light, `blurb-grid|divi/blurb|0..3` chip surface, `stats-counter-band|divi/number-counter|0..3` rule-strong accent; 11 blocks, 5 recipes); `testTheUnadaptedModulesAreCountedAndHaveAReason` (counts from the file equal `UNADAPTED_COUNTS`; every reason non-empty; `divi/signup` 0); `testTheAuditClassifiesPlantedBlocks` (a `border`-coloured divider is not a target, a counter with no rule is not, a testimonial without a fill is not); `RuleChipAuditTest::testTheAccentFloorIsTheShippedMinimumRoundedDown` (the minimum of `RuleChipAudit::ratio('accent', 'light', $lang)` over the 10 languages, 2.304, `floor($min * 10) / 10 === ACCENT_RULE_FLOOR`, and the two shipped accent dividers are on `light`).
- [ ] **Step 2:** run `php -d memory_limit=-1 vendor/bin/phpunit --filter 'CarrierAudit|RuleChipAuditTest'`: FAIL; implement; PASS. The tests must pass unchanged after Task 5 (they read role and band from the marker once marked).
- [ ] **Step 3:** commit.

---

### Task 3: Adapter, resolver, generated guide (opus; needs Tasks 1 and 2)

**Files:**
- Modify: `wp-plugin/src/KitAdapters.php`, `wp-plugin/src/KitResolver.php`, `wp-plugin/src/StyleGuide.php`, `tests/KitResolverTest.php`, `tests/KitChipAdapterTest.php` (line 413 list), `tests/StyleGuideKitRulesTest.php`, `tests/StyleGuideKitTopicsTest.php`, `tests/LanguageTypeBudgetTest.php`, `tests/LanguageRhythmBudgetTest.php`, `tests/CarrierBudgetTest.php`
- Create: `tests/KitCarrierAdapterTest.php`

**Interfaces:**
- `KitAdapters`:
  - `APPLIED` gains `'divi/testimonial' => [ 'card', 'panel' ]`, `'divi/blurb' => [ 'chip' ]`, `'divi/number-counter' => [ 'rule', 'rule-strong' ]`, and `'divi/divider' => [ 'rule', 'rule-strong', 'rule-accent' ]` (appended entries; the old entries keep their order).
  - `public const CARRIER_PROVEN = [ ... 20 keys ... ];` exactly the vocabulary table (order and verdicts).
  - `public const ACCENT_RULE_COLOUR = 'accent'; public const ACCENT_RULE_BANDS = [ 'light', 'surface' ]; public const GROUND_BLOCKS = [ 'divi/group', 'divi/column' ];`
  - private `RULE_CARRIERS` gains `'divi/number-counter' => 'counter-top'`; `rulePatch()` keeps its signature and default (`RULE_PROVEN`); `patch()` passes `self::RULE_PROVEN + self::CARRIER_PROVEN` so the `counter-top*` keys are found; the top-edge branch is shared by group, column and counter.
  - `rule-accent` in `patch()`'s thin branch: bands `ACCENT_RULE_BANDS`, divider only, `rulePatch()` with colour `{{aied:color:accent}}`, width `ruleWidth('rule-accent', ...)` (= the strong width: the function's non-`rule` branch), style fallback by `divider-<style>` (RULE_PROVEN); when `divider-accent-over-preset` or `divider-accent-style` is false: null, resp. `solid`.
  - `chipPatch()` gains a `divi/blurb` branch: `imageIcon.decoration.border.desktop.value.radius` = `{{aied:kit:radius-control}}` (when `blurb-icon-radius`), `imageIcon.decoration.background.desktop.value.color` (when `blurb-icon-fill`; `surface` on accent, else `accent`), `imageIcon.advanced.color.desktop.value` (when `blurb-icon-glyph`; `text` on accent, else `accent-text`); on an accent band both colour decisions must be true or `[]`. New optional trailing parameter `array $carrierProven = self::CARRIER_PROVEN` (planted maps in tests).
  - `card()` gains optional trailing parameters `string $blockName = 'divi/group', array $carrierProven = self::CARRIER_PROVEN`: for `divi/testimonial` each key is dropped when its decision is false (`testimonial-underline` false -> the full frame form, as the form adapter's fallback; `testimonial-padding` false -> no padding; shadow, lift, hover frame each by its key); group/column output unchanged byte for byte.
  - `patch()`: a ground is refused unless the block is in `GROUND_BLOCKS`; class docblock gains a "Carrier table" section (the four carriers, bands, fallbacks, proofs).
- `KitResolver`: `ROLE_BLOCKS` gains `'rule-accent' => [ 'divi/divider' ]`, `'divi/testimonial'` on `card` / `panel`, `'divi/number-counter'` on `rule` / `rule-strong`, `'divi/blurb'` on `chip` (appended); `ROLE_SURFACES` gains `'rule-accent' => [ 'light', 'surface' ]`; `marker()` refuses a `ground` on a block outside `[ 'divi/group', 'divi/column' ]` (a private const mirroring `KitAdapters::GROUND_BLOCKS`, pinned equal by a test); docblock lists the new roles and carriers.
- `StyleGuide`:
  - `APPLIED_NOUNS` gains `'rule-accent' => 'rules'`; `NOT_APPLIED_NOUNS` unchanged (blurbs and testimonials drop out of the sentence automatically); `notAppliedSentence()` says "keeps" when the only noun is "every other module" (Ruling 9).
  - `public static function appliedNounsSentence( ?array $applied = null ): string`: "Kit-driven today: " + the nouns of `appliedSentence()` in the same order, joined the same way, + " (blocks and marker roles: topic=kit-rules)."; the catalog (line 195) prints it instead of `appliedSentence()` (Ruling 7).
  - `public static function carrierRules( ?array $applied = null, ?array $proven = null ): string`: `''` unless at least one of the four carriers is in `$applied`; else `"\n## More carriers\n\n"` + one clause per carrier present whose base decision is true, generated from `APPLIED`, `CARRIER_PROVEN`, `ACCENT_RULE_BANDS` and the patch itself (paths printed from the patch's leaves, never typed): the testimonial takes the card and panel patch (no ground; its quote icon keeps Divi's colours; padding/underline fallbacks said only when false); a blurb's icon takes the icon chip (control radius at `imageIcon.decoration.border.desktop.value.radius`, fill and glyph at their paths; padding and size stay); a counter takes a rule as the top edge of a group; `rule-accent` on a `divi/divider` is the accent colour at the strong width on a light or surface band only, a brand mark, not a separator. `kitRules()` appends it after the Rules and chips paragraph. At most 560 B.
  - `controlsKitSentence()` unchanged in code (its divider list grows by itself: Ruling 10).

- [ ] **Step 0 (before any edit):** a scratch script (not committed) prints the sha256 of the JSON of `KitAdapters::patch($b, $r, $on, $kit, $g)` over every OLD (block, role) of `APPLIED`, every band of the old tables, every ground the old code accepts and every one of the 40 kits, plus of `KitResolver::resolve(rawMarkup($n), $kit)` for every recipe x kit; keep both hashes; `testTheGroupColumnDividerIconPatchesAreUnchanged` pins the first as a literal.
- [ ] **Step 1: Write the failing tests** (`KitCarrierAdapterTest`, helpers as `KitRuleAdapterTest`: `vocabularyDecisions()` reading `kit-carrier-vocabulary.md`, `probedPaths()` reading `candidates/kit-carrier.json` + `probe-results-kit-carrier.json` per `block`, `leaves()`):
  - `testCarrierProvenMatchesTheVocabularyTable` (20 rows, order, verdicts).
  - `testEveryLeafIsAProbedPathOnItsCarrier` (40 kits x every new (block, role, band); a planted `module.decoration.spacing` leaf on a blurb fails).
  - `testEveryEmittedCarrierPathIsInTheRegistryAndNotDeadOnItsCarrier` (`ShapeRegistry` + `DeadOnModule`, planted DEAD line fails for that carrier only).
  - `testTheTestimonialPatchIsTheGroupCardPatch`, `testTheCounterPatchIsTheGroupRulePatch`, `testTheBlurbChipTakesTheIconChipColours` (identities with all decisions true, every kit and band).
  - `testTheAccentRuleIsTheAccentAtTheStrongWidth` (colour token, width = `ruleWidth('rule-strong', ...)` for 40 kits, style fallback with a planted `divider-dashed => false`), `testTheAccentRuleTakesTwoBandsOnly`.
  - `testUnprovenDecisionsFallBackOrRefuse` (each of the 20 keys planted false gives the fallback of controller ruling 12 / the Interfaces above).
  - `testUnknownCombinationsAreNull` (ground on a testimonial, `rule-accent` on group/column/counter/accent/dark/panel, `chip` on a testimonial, `card` on a blurb or counter, an invalid kit).
  - `testTheGroupColumnDividerIconPatchesAreUnchanged` (the Step 0 hash).
  - `testPatchValidatesInsideARealLayout` (`LayoutGate::check` on a section/row/column holding each resolved new-carrier block, every kit).
  - `testThePatchIsDeterministic`.
  - `KitResolverTest::testCarrierMarkersResolveAndAreStripped` (each new carrier and role, every band it takes, any key order) and `testCarrierMarkersTheResolverDoesNotKnowAreLeftAndReported` (a ground on a testimonial, `rule-accent` on `accent`, `rule-accent` on a group, `chip` on a testimonial: byte-identical and `LEFT_MARKER`); `testRoleSurfacesNameTheAdaptersBands` (for every role, `ROLE_SURFACES` equals the union of bands the adapter answers non-null for, over the 40 kits).
  - `StyleGuideKitRulesTest::testTheMoreCarriersParagraphFollowsTheMapAndTheDecisions` (each clause appears only while its carrier is in a planted map and its base decision is true; a planted false padding decision makes the fallback appear; every backticked path is in the registry; `''` with none of the four carriers), `testTheCatalogPrintsTheNounOnlySentence` (`appliedNounsSentence()` once, `appliedSentence()` not, `notAppliedSentence()` still present), `testTheNotAppliedSentenceAgreesInNumber`.
  - `testTheCarrierBudgets` in `CarrierBudgetTest`, tightened to exact: `kit-rules` = 8,997 + (new applied sentence - 445) + Ruling 9 delta + `strlen(carrierRules())`, and `<= 9600`; catalog = measured, 50-kit projection `<= 9950`; `controls` = 8,331 + measured delta; default guide 28,975; topic description 258; every kit topic unchanged.
  - Deliberate re-pins (each with a one-line comment "carriers adapter: <old> -> <new>"): the `kit-rules` and catalog numbers in `StyleGuideKitRulesTest`, `StyleGuideKitTopicsTest`, `LanguageTypeBudgetTest`, `LanguageRhythmBudgetTest` (Task 0's table); the `9000` bounds become `9600`; `KitChipAdapterTest` line 413 drops `divi/blurb`.
- [ ] **Step 2: Run** `php -d memory_limit=-1 vendor/bin/phpunit --filter 'KitCarrier|KitResolver|KitChip|KitRule|KitCard|StyleGuideKit|CarrierBudget|LanguageTypeBudget|LanguageRhythmBudget'`; expect FAIL.
- [ ] **Step 3: Implement.** Re-run the Step 0 script: the old-patch hash is identical; the served-recipe hash is identical (no recipe carries a new-carrier marker yet).
- [ ] **Step 4:** `make test` and `make test-pro` green (the eight goldens unchanged); commit.

---

### Tasks 4, 5 and 6 need Task 3 and may run in parallel (separate worktrees off the Task 3 head, max 3 workers). Task 4 consumes `patch()` and `ACCENT_RULE_FLOOR`; Task 5 consumes `CarrierAudit` (T2) and the resolver (T3); Task 6 consumes the markers and `kit-sheet-offline.php`. After merging, the controller re-runs `make test`.

### Task 4: Proofs, the new golden and the freeze (sonnet)

**Files:**
- Create: `tests/KitCarrierGoldenTest.php`, `tests/golden/kit-carrier-patches.json`
- Modify: `tests/KitCarrierAdapterTest.php` (proof tests), `tests/KitGoldenFreezeTest.php`

**Interfaces:**
- Golden: keys = the 10 default kits (`DesignLanguages` order, each language's kit), each `{ "kit": <decisions>, "patches": { "divi/testimonial|card|light": ..., ... } }`: testimonial card x4 + panel x4, blurb chip x4, counter rule x5 + rule-strong x5, divider rule-accent x2 = 24 per kit, 240 in all; pretty JSON, trailing newline, the `kit-rule-patches.json` format.

- [ ] **Step 1: Contrast and visibility proofs** (in `KitCarrierAdapterTest`, the `KitChipAdapterTest` cell style, failures listed as `kit/language/band: ratio`):
  - `testAccentRuleContrastOnEveryKitLanguageAndBand`: 40 x 10 x 2 = 800 cells, the resolved line colour composited over the band (`RuleChipAudit::ratio()`'s band model) at least `RuleChipAudit::ACCENT_RULE_FLOOR`; zero exceptions; assert the cell count.
  - `testTheAccentRuleBandsAreMaximal`: for `accent`, `dark`, `panel`, at least one language is below the floor (measured 1.000, 1.401, 1.103), so no band was left out by hand.
  - `testBlurbChipGlyphContrast`: 40 x 10 x 4 = 1,600 cells, glyph on fill at 3:1.
  - `testTheTestimonialsOwnInksAreReadableOnBothFills`: the migrated block's own author, jobTitle, company and content colour tokens (read from the RAW recipe after Task 5; before Task 5 from a fixture copy of the block) on `surface` and `bg` at 4.5:1 in all 10 languages (80 cells).
  - `testTheCarrierLooksAreDistinct`: blurb chip looks = the set of distinct control radii (8: 0, 2, 4, 6, 8, 10, 12, 999px), accent rule looks = 6 (width x style: 2px dashed, 2px solid, 3px dotted, 3px solid, 4px double, 4px solid), pinned literally; counter and testimonial inherit by the Task 3 identities.
- [ ] **Step 2: the golden.** `KitCarrierGoldenTest::testEveryDefaultKitResolvesItsCarrierPatchesByteIdentically` (10 entries, 240 patches, each equal to `KitAdapters::patch()`, the entry's kit equal to `StyleKits::get()`; docblock says how to regenerate, only deliberately). Generate once with a scratch script; review by eye (raw-frame: 4px solid accent rule; soft-rounded: 999px blurb radius if its control radius is 999px; an underline kit's testimonial card takes the bottom edge).
- [ ] **Step 3: the freeze.** `KitGoldenFreezeTest::FROZEN` gains `'kit-carrier-patches.json' => <its sha256>` (last), `assertCount( 9, ... )`, docblock: "the carriers adapter only ADDED kit-carrier-patches.json". The eight existing hashes are NOT edited.
- [ ] **Step 4:** `make test`; commit.

---

### Task 5: Migrate the 11 blocks and scope the old tests (opus)

**Files:**
- Create: `scripts/migrate-carrier-markers.php` (dev-only, pure, idempotent), `tests/CarrierMigrationTest.php`
- Modify: `wp-plugin/data/section-recipes.json` (ONLY through the script, plus the one prose fix), `tests/Support/RuleChipAudit.php` (SHAPE_EXEMPTIONS -6; `kindOf()` ignores markers of the new carriers and roles), `tests/RuleChipAuditTest.php`, `tests/RuleMigrationTest.php`, `tests/ChipMigrationTest.php`, `tests/RecipeLibraryTest.php`, `tests/LanguageResolverTest.php`

**Interfaces:**
- Markers (APPENDED as the last top-level key, the library's convention): `"aied":{"kit":"rule-accent","on":"light"}` (blog-feed divider 0, icon-values divider 0), `"aied":{"kit":"panel","on":"light"}` (testimonial 0), `"aied":{"kit":"chip","on":"surface"}` (blurb-grid blurb 0-3), `"aied":{"kit":"rule-strong","on":"accent"}` (stats-counter-band number-counter 0-3).
- Dropped literals (what the kit now writes; nothing else changes): dividers `divider.advanced.line` (whole; `modulePreset` and `alignSelf` stay); testimonial `module.decoration.background` and `module.decoration.border` (whole; `overflow`, `layout` stay); blurbs `imageIcon.decoration.background` and `imageIcon.advanced.color` (padding, sizing, innerContent stay); counters `module.decoration.border` (whole; padding, sizing, text orientation, title and number stay).

- [ ] **Step 1: Write the failing tests** in `CarrierMigrationTest` (helpers copied from `RuleMigrationTest`: `blocks()`, `leaves()`, `bandProblems()` via `RecipeLibraryTest::markerBands()`):
  - `MIGRATED` = the 11 keys as literals `"<recipe>|<block>|<index>" => "<role> on <band>"`; `BESPOKE_CARRIERS = []`, `BESPOKE_CARRIERS_MAX = 0`, both asserted; `testTheElevenMarkersArePinnedAndNothingIsBespoke` (parsed markers on the four carriers == `MIGRATED`; every block `CarrierAudit::blocks()` lists is marked).
  - `testMarkedCarriersCarryNoInkOfTheirOwnAndMatchTheirBand` (raw markup: no dropped literal remains; each marker's `on` equals the walk's band).
  - `testMarkedCarriersKeepOnlyStructureUnderEveryKitAndLanguage` (the merge trap: for 5 recipes x 40 kits x 10 languages, each marked block in `SectionRecipes::recipe()` output has exactly the patch's leaves at the kit's keys and, for every other leaf, the raw block's value after `RecipeTokens::resolve`; no `aied` key).
  - `testEveryMigratedRecipeIsServedValidUnderEveryKitAndLanguage` (`LayoutGate::check` valid, no `{{aied:`, empty `KitResolver::leftovers()` and `LanguageResolver::leftovers()`).
  - `testRecipesFileIsSmallerThanBeforeTheCarriers` (`< 652430`).
  - `testCarrierMarkedRecipeProseDoesNotDescribeTheKitLook` (title, description, when of the 5 recipes against `RuleMigrationTest::RULE_DECIDED_PHRASES`, `ChipMigrationTest::CHIP_DECIDED_PHRASES` and `RecipeLibraryTest::CARD_DECIDED_PHRASES`, made public or copied).
  - Planted both ways: `testAnUnmigratedRecipeFailsEveryCheckAndTheCleanShapePasses`, `testTheMergeTrapCatchesStraysAndOverrides` (a stray `imageIcon.advanced.color` on a marked blurb, a kept `divider.advanced.line.desktop.value.color`, a leftover marker each fail).
- [ ] **Step 2: Write `scripts/migrate-carrier-markers.php`** (`php scripts/migrate-carrier-markers.php [--check]`): for each `CarrierAudit::blocks()` row not yet marked, decode the attribute JSON and ASSERT a byte round trip with `json_encode( ..., JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )` followed, when the original spells no raw `&`, by `&` -> `&` (measured: the 4 blurbs need the restore, the other 7 round-trip plainly; any mismatch: STOP); drop the literals of the Interfaces list (dropping an object only when it becomes empty); append `aied`; re-encode the same way; refuse a result containing `--`; write the file with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` and a trailing newline. `--check` writes nothing and exits 1 if anything would change; a second run changes nothing.
- [ ] **Step 3: Migrate and reword.** Run the script; then, by a small PHP edit of the `description` field only, stats-counter-band "each under a thin rule" -> "each under a rule" (matched by `RULE_DECIDED_PHRASES`). Measure the file before/after (expected about 651,200 B).
- [ ] **Step 4: Scope the old tests (Ruling 11), each edit with a comment naming this plan:**
  - `RuleChipAudit::SHAPE_EXEMPTIONS`: remove `stats-counter-band|divi/number-counter|0..3` and `blog-feed|divi/divider|0`, `icon-values|divi/divider|0` (15 -> 9); `kindOf()` returns null for a `rule-accent` marker and for any marker on `divi/number-counter` (they are `CarrierMigrationTest`'s).
  - `RuleChipAuditTest` line ~281: the counter expectations removed; the exemption list asserted as the 9.
  - `RuleMigrationTest`: `ruleMarker()` takes the block name and answers only on `divi/divider`, `divi/group`, `divi/column` and roles `rule` / `rule-strong`; `markersInFile()` counts spelled markers per block comment of those three carriers; the 57 / 22 / 35 / 21 pins are unchanged; `EXEMPT_LOOKS` loses the six keys ("15 blocks" -> "9 blocks"); `testNoRuleIsBespokeAndEveryUnmarkedRuleLikeBlockIsAShapeDecision` expects `[]` unmarked (the two accent dividers are now rule-accent).
  - `ChipMigrationTest`: `chipMarker()` takes the block name and answers only on `divi/group` and `divi/icon`; the 14 / 3 pins are unchanged.
  - `RecipeLibraryTest` line 1796: `45` -> `49` (testimonial, blurb-grid, stats-counter-band, blog-feed join; icon-values already counted), comment says why; the card checks keep passing because `CARD_KEEPS` already allows `overflow` and `layout` (if a card check names the carrier `divi/group|divi/column` explicitly and fails on the testimonial, scope it to those two and cover the testimonial in `CarrierMigrationTest`; record which).
  - `LanguageResolverTest::testTheSharedScannerKeepsKitOutputByteIdentical`: compare only recipes whose every marker is a legacy (block, role) pair of `LegacyKitResolver::ROLE_BLOCKS` (44 after the migration: 45 minus icon-values, which gains a `rule-accent`), assert 44; `LegacyKitResolver` itself is not edited.
- [ ] **Step 5: `make test`** (incl. `CarrierAuditTest` unchanged and green, the rule/chip/card/form/item/image/type/rhythm migration tests, `CardInkWalkTest`, `ShapeRegistryTest` through `ServedVariants`, `DesignVariety*Test`, `NoCustomCodeTest`, `Dogfood*Test`: the dogfood edit base holds none of the 5 recipes, so it does not move), `make card-report` unchanged; commit.

---

### Task 6: Render gate: the carriers in verify-kits (sonnet; needs Task 3; pure parts only, the live run is the controller's)

**Files:**
- Create: `scripts/lib/kit-carrier-run.cjs` (`measureCarriers(page, slots)`: one rendered cell)
- Modify: `scripts/lib/kit-checks.cjs` (`CARRIER_SLOTS`, `carrierMarkup()`, `carrierAttrs()`, `expectCarrierFromAttrs()`, `missingCarrierExpectations()`, `compareCarrier()`, `emptyRunProblems()` gains `carriersChecked`), `scripts/lib/kit-sheet-offline.php` (adds the carrier slots to the sheet and exports `accentRuleFloor` = `RuleChipAudit::ACCENT_RULE_FLOOR`), `scripts/verify-kits.cjs`, `scripts/test-verify-kits.cjs`, `scripts/test-kit-card-run.cjs`, `Makefile` (the `verify-kits` comment only)

**Interfaces:**
- `CARRIER_SLOTS` (appended after every existing slot, so no existing key or order moves): `testimonial-card-bg` (`card`, light, a real hover), `testimonial-panel-surface` (`panel`, surface), `blurb-chip-surface`, `blurb-chip-accent`, `counter-rule-strong-accent`, `counter-rule-bg`, `accent-rule-bg`, `accent-rule-surface`; each found by a UNIQUE heading or author text.
- Codes: `carrier` (a slot missing, on the page twice, or a run that checked none), `testimonial-fill|frame|radius|padding|shadow|hover|quote` (the `::before` box above the module top and not clipped: computed `overflow` visible and the pseudo's rect), `blurb-chip-radius|fill|glyph|contrast` (on `.et_pb_main_blurb_image .et-pb-icon`), `counter-rule-style|width|colour|contrast` (`border-top-*` of `.et_pb_number_counter`), `accent-rule-style|width|colour|contrast` (`.et_pb_divider::before` `border-top-*`; contrast at least the exported `accentRuleFloor`), plus `overflow`.
- Expectations from the RESOLVED markup (the sheet JSON), never typed; a null expectation fails the slot.

- [ ] **Step 1: Pure tests first** (`scripts/test-verify-kits.cjs`): for each new code a match, a mismatch, a missing element, an empty selection and a null expectation; the offline sheet resolves all 8 slots for all 88 cells with zero leftovers and a valid gate; `accentRuleFloor` is read from the sheet. Show RED then GREEN.
- [ ] **Step 2: Synthetic departures** (`scripts/test-kit-card-run.cjs`, Playwright from `PLAYWRIGHT_PATH`, no dev site): pages with Divi's class names (`et_pb_testimonial` with a `:before`, `et_pb_blurb` > `.et_pb_blurb_content` > `.et_pb_main_blurb_image` > `.et-pb-icon`, `et_pb_number_counter`, `et_pb_divider`); an agreeing page passes; one departure per code, each caught with that code (wrong radius on the icon, a fill on the wrapper instead of the icon, a clipped quote, a dashed counter line where solid is expected, an accent line below the floor, a slot twice, nothing checked).
- [ ] **Step 3:** `node scripts/test-verify-kits.cjs`, `node scripts/test-kit-card-run.cjs`, `make test`; do not touch the dev site; commit.

---

### Task 7: Docs, token measurement, outcome (sonnet)

**Files:**
- Modify: the spec (`## Outcome`), `CLAUDE.md` (a compact "Carriers adapter" paragraph after the Rhythm adapter paragraph, in the style of the earlier adapters; the `make verify-kits` row of the Entry points table gains the 8 carrier slots and codes), `docs/token-usage.md` (`php scripts/measure-tokens.php`), `fixtures/shapes/kit-carrier-vocabulary.md` (final pass), the docblocks of `KitAdapters`, `KitResolver`, `StyleGuide::carrierRules()`.

- [ ] **Step 1:** write the Outcome from measured facts only: probe counts and every DEAD / css-only result; `CARRIER_PROVEN`; any fallback taken; the 11 markers; the six exemptions removed; the golden (240 patches) and the freeze (9 hashes, the eight earlier unchanged); every byte size before/after (Global Constraints list); suite counts (free, Pro, the two node test files); the live numbers from the controller's ledger; Rulings 1-12 restated; deviations; known limits (an accent rule is decorative at 2.3:1, not structural; the testimonial's quote disc keeps Divi's #f5f5f5; the divider preset `1ep0co506u` is a dev-site preset id, so on a customer site the accent mark is a full-width line; the blurb chip's shape at 999px depends on the glyph box (a wide glyph gives an ellipse); counters' numbers and titles, circle counters, sliders, countdown, social, before-after, blog, shop and carousels stay the recipe's; `divi/signup` declined); owner decisions left (merge and release; whether the slider pair becomes a coupled panel later).
- [ ] **Step 2:** `make test`, `make test-pro`, `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` empty; commit. (Memory is the controller's.)

---

### Task 8: Final live gates and the whole-branch review (controller, live, serial; not an implementer task)

ONE live gate at a time, in this order (cheapest and most diagnostic first, the 3-hour run before the recipe render so a kit defect is found before recipes are judged). Before and after each: `pgrep -f "node scripts/verify-"` empty, `git branch --show-current` = the branch; `verify-kits` and `verify-recipes` from the MAIN checkout.

- [ ] (Task 1, early) the probe `node scripts/verify-shapes.cjs fixtures/shapes/candidates/kit-carrier.json`: every expectation agrees.
- [ ] `make verify-shapes`: OK, every expectation of `known.json` agrees; remove any stray untracked `probe-results-known.json`.
- [ ] Kit smoke first (cheap, catches a sheet defect): `node scripts/verify-kits.cjs --kits quiet-solid,raw-frame,soft-rounded --languages editorial,glass-dark`: 0 FAIL; then the FULL `make verify-kits` (88 cells, about 3 h 30 min with the 8 new slots; this adapter changes `KitAdapters` and `KitResolver`, so the full run is required): 0 FAIL; `--clean-orphans` finds 0 `aied kit ` pages.
- [ ] Narrowed recipe render, ONE chunk at a time: `node scripts/verify-recipes.cjs --names testimonial,blurb-grid,stats-counter-band,blog-feed,icon-values --languages editorial,glass-dark,organic-warm,tech-mono,playful-pastel,corporate-trust,soft-saas,bold-vibrant,brutalist-flat,luxe` (5 x 10 x 3 widths), then 5 unmarked guard recipes chosen with a fixed seed (`php -r 'mt_srand(20261025); ...'` over the recipes without any marker of this branch, recorded) under the default look; expect 0 FAIL, 0 KNOWN-FAIL, `fixtures/shapes/recipe-baseline.txt` still empty. blog-feed needs posts on the dev site (it lists the site's own posts): a `gate`/empty failure there is checked against the posts first, never allowed.
- [ ] `php -d memory_limit=-1 vendor/bin/phpunit` (full free suite) and `make test-pro` (2169): green; `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` empty.
- [ ] The final whole-branch review on opus against the Review Focus list (every line's test exists and would fail on its planted case), one fix wave, one scoped re-review; then `git merge --no-ff` into LOCAL main per the owner's standing instruction (no push), CLAUDE.md and memory updated.

---

## Parallelism map

| Phase | Work | Who | Can run beside |
|---|---|---|---|
| A | T0 (budgets, inventory) | sonnet | nothing (short, first) |
| B | T1 probe | controller, live | T2 (worktree) |
| B | T2 audit + accent floor | sonnet | T1 |
| C | T3 adapter, resolver, guide | opus | nothing (needs T1 + T2) |
| D | T4 proofs + golden + freeze, T5 migration + scoping, T6 gate (pure) | sonnet / opus / sonnet | each other (3 workers) |
| E | T7 docs | sonnet | T8's first gates |
| F | T8 live gates (serial), then the opus review | controller | nothing on the dev site |

At most 3 implementers at once; one live run on the dev site at a time; merge each worktree in task order and re-run `make test` after each merge. Note: T4's `testTheTestimonialsOwnInksAreReadableOnBothFills` reads the raw block, so it merges after T5 or uses its fixture copy (Step 1 says how).

---

## Spec issues found

The plan does not silently deviate on any of these; each has the plan's default in brackets. The controller resolves them before Task 1 (issues 1, 5) and Task 3 (the rest).

1. **No band gives `rule-accent` 3:1 in all ten languages.** Measured accent-vs-band minimum: light 2.304 (playful-pastel), surface 2.448, accent 1.000, dark 1.401 (corporate-trust), panel 1.103 (tech-mono); brutalist-flat is 2.81 on bg. The brief's criterion yields zero bands. [Decorative mark, floor 2.3 measured from the two shipped dividers (already in `rule-floor.md`), bands light and surface; spec Ruling 1.]
2. **A ground would be accepted on a testimonial.** `KitAdapters::patch()` and `KitResolver::marker()` check a ground by ROLE only; today only group/column carry card roles, so it never mattered. [`GROUND_BLOCKS` in both; tests.]
3. **The applied sentence would break the 50-kit catalog projection.** It is printed in the catalog (StyleGuide.php line 195) and `kit-rules` (389), not the default guide; with the new map it grows 445 -> 524 B and the projection 9,928 -> about 9,978 B (bound 9,950). [Noun-only sentence in the catalog (-266 B); full sentence in `kit-rules`; spec Rulings 7 and 8.]
4. **`kit-rules` has 3 B of headroom** (8,997 of a 9,000 pin). [Pin 9,600, one generated paragraph of at most 560 B; spec Ruling 8.]
5. **`imageIcon.decoration.border.*` is not in the shape registry at all**, so the blurb radius needs the probe's promotion, not only an evidence id; and Divi prints that border on `.et-pb-icon` while the fill is printed on `.et-pb-icon, .et_pb_image_wrap` (BlurbModule.php, module.json), so "radius on the same element as the fill" is its own candidate. [`kx-blurb-icon-radius-on-fill`.]
6. **`notAppliedSentence()` would print "Every other module keep"** once blurbs and testimonials leave its list. [Number agreement; spec Ruling 9.]
7. **The four blurbs mix two encodings** (`&` in the glyph code, raw `<p>` in the content): neither `json_encode` nor `RecipeTokens::encodeJson` round-trips them (measured); the other 7 targets round-trip with plain `json_encode` (the testimonial does NOT with `encodeJson`). [Plain `json_encode` + `&` -> `&` only on blocks with no raw `&`, asserted per block.]
8. **Old tests that would silently change meaning:** `RuleMigrationTest` counts every `"kit":"rule-strong"` in the file (the counters add 4), `LanguageResolverTest`'s legacy-scanner proof covers every kit-marker recipe (icon-values gains a role the frozen legacy resolver does not know), `RecipeLibraryTest` counts 45 kit-marker recipes (49 after), `KitChipAdapterTest` lists `divi/blurb` as a block with no chip, `RuleChipAuditTest` expects the counters as exemptions. [Scoped edits listed in Task 5 Step 4 and Task 3 Step 1; spec Ruling 11.]
9. **The eight goldens cannot move by themselves** (their tests enumerate literal carriers), so a "re-freeze" is unnecessary. [A ninth golden; spec Ruling 6.]
10. **`controls` grows by itself**: `controlsKitSentence()` prints the divider's whole role list. [+18 B accepted and pinned; spec Ruling 10.]
11. **stats-counter-band's prose says "each under a thin rule"**, which `RULE_DECIDED_PHRASES` bans once its counters carry a rule marker. [Reworded to "each under a rule".]
12. **The divider preset `1ep0co506u` is a site preset id from the dev site's exports**; on a customer site it does not exist (Divi falls back to the default preset), so the accent mark's length and default weight differ there. The kit's weight wins over a preset (probed: `kx-divider-accent-weight-*`). [Kept as is; a known limit in the Outcome; removing the preset is a separate owner decision.]
13. **Counters as `rule-strong` thicken the line** from 1px to the strong width (2px for the 22 two-pixel-solid kits). [Accepted by Ruling 4; the cost if wrong is four marker roles.]
14. **The testimonial's hanging quote disc** keeps Divi's own background (`#f5f5f5` in Divi's CSS) and colour, so under a dark language a light disc sits on a dark card, as it does today. [Out of scope; known limit.]
15. **`blog-feed` renders the site's own posts**: the narrowed recipe render depends on the dev site having posts. [Checked before judging a failure; never an allowance.]
