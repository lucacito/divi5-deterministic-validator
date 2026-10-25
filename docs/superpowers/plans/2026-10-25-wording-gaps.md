# Wording Gaps Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the 14 gaps six blind assistants reported in the served guide (spec table): a new generated topic `kit-patterns`, a generated "Kit" line in every design-language topic, small generated fixes in `kit-rules` and `controls`, a same-length swap in the default guide, kit-topic samples served in the asked language with the guide's `builderVersion`, and one role-table fix for the hover of framed `invert` kits (glass-edge on glass-dark).

**Architecture:** Guide text is generated from the constants, patches and data that decide it (`KitAdapters`, `DesignLanguages`, the recipes); every path is linted against the shape registry, every named recipe is read and checked for the pattern it is named for, every byte budget is pinned. One live probe (P1) decides the two icon/tag layout claims before they are written. A blind dogfood re-run is the acceptance test.

**Tech Stack:** pure PHP 8 (`wp-plugin/src/`), PHPUnit (`php -d memory_limit=-1 vendor/bin/phpunit`, `make test`), the dogfood scripts (`scripts/dogfood-*.php`, `scripts/dogfood-render.cjs`), `scripts/verify-shapes.cjs` and `scripts/verify-kits.cjs` against the Docker dev site (Divi 5.15.1, controller only).

**Spec:** `docs/superpowers/specs/2026-10-25-wording-gaps-design.md` (gap table, Rulings 1-11). Templates: `docs/superpowers/plans/2026-10-24-carriers-adapter.md` (format), `docs/superpowers/specs/2026-10-21-guide-polish-rules-chips-design.md` (the earlier wording rounds and their dogfood method), `docs/dogfood.md` (routine). Code to mirror: `StyleGuide::{kitRules(), carrierRules(), ruleChipShapes(), bandSentence(), groundSentence(), controlsKitSentence(), objectValuesParagraph(), topicParameterDescription(), styleKitTopic(), kitButton(), kitCard(), kitInvertedPanel(), kitToggle(), kitSurfaceSentence(), kitColourLegend()}`, `DesignLanguages::{generatedLines(), GENERATED_PLACE, GENERATED_LINE_MAX}`, `KitAdapters::{patch(), hover(), ruleWidth(), chipPatch(), RULE_COLOUR}`, `McpHandler` / `RestController` / `OpenApiSpec` handling of `get_section_recipes` `design_language`, tests `StyleGuideKitRulesTest` (`ShapePaths::fromGuideText()` + `ShapeRegistry::covers()` at line ~1773), `GuideRecipePointersTest::problems()`, `KitGoldenFreezeTest`, `CarrierBudgetTest`, `LanguageTypeBudgetTest`, `DogfoodPrepareTest`.

Branch `feat/wording-gaps` from local main 895610e, worktree `/Users/Lucas/Documents/JHMG-Local/wt-wording`. `git merge --no-ff` into LOCAL main at the end. NO push, release, version bump, zip rebuild, readme edit or SVN.

Decisions this plan makes where the spec leaves room (each restated in the spec Outcome by Task 8):
- New names: `StyleGuide::{kitPatterns(), BUILDER_VERSION}`, `KitAdapters::BAND_FILL` (public: band => palette role, `['light'=>'bg','surface'=>'surface','accent'=>'accent','dark'=>'overlay','panel'=>'text']`), `DesignLanguages::kitLine()` (third generated line), tests `tests/KitPatternsTopicTest.php`, `tests/KitHoverVisibilityTest.php`, `tests/WordingGapsBudgetTest.php`, `tests/StyleGuideLanguageParamTest.php`; candidates `fixtures/shapes/candidates/kit-patterns.json` (ids `kp-*`, no other probe uses `kp-`).
- `StyleGuide::markdown( ?string $topic = null, ?string $language = null )`: the second parameter is read only for `style-kit:<id>`; every other caller is unchanged.
- No new Make target, no new source file in `wp-plugin/src/` (if one appears: `wp-plugin/src/autoload.php` and `PluginAutoloadCoversEverySourceFileTest`).

## Controller rulings (2026-10-25; WHERE THIS SECTION CONFLICTS WITH THE TEXT BELOW, THIS SECTION WINS)

Restated from the spec; they bind every implementer:
1. One new topic `kit-patterns` (served right after `kit-rules`), `<= 6,000 B` pinned, generated; it never names the `aied` marker or a `{{aied:` token; its own name is never backticked.
2. `topic` description: 258 -> 272 characters, pin `<= 260` -> `<= 290` in every test that pins it.
3. Band roles are stated in `kit-rules` (`bandSentence()` from `KitAdapters::BAND_FILL`), not in `kit-patterns`.
4. "The kit wins" moves early through a generated Kit line in every design-language topic (`<= 260 B`, printed after "- Style kit: <id>").
5. Reword in `design-languages.json` ONLY: brutalist-flat "Open every section with a 6px black line (the divider module)" -> "Open every section with a strong black line (the divider module)"; luxe "(1px line in the accent colour)" and "(a 1px line in the accent colour)" -> "(the kit's accent rule)" (wording may be adjusted by the implementer, numbers may not be added).
6. `get_style_guide` takes an optional strict-string `design_language` used for `style-kit:<id>` only. [If the controller picks the spec's fallback instead, Task 3b Steps 3-5 are skipped and Step 6's header wording is used.]
7. Hover: `invert` with kit border colour `same` and a width other than `0px` keeps the REST frame colour on hover. Nothing else in the role table moves. Proof scope: framed kits x 10 languages x {light, panel} x {control, control-secondary}, rest box >= 1.5 => hover box >= 1.5, zero exceptions.
8. `kit-rules` stays `<= 9,600 B` through the trims of Task 4 Step 4; only if that fails, `<= 9,700 B` with the measured number in the commit message.
9. No icon `maxWidth` rule and no "row of tags / icon chips" claim beyond what P1 (Task 2) proves.
10. Divider: "no `show` key and no spacing needed"; no default-spacing number.
11. Glyph: both `&#x...;` and `&#x...;` are the same value; the builder writes the second.
12. STOP conditions: P1 contradicts the recipe render (an icon chip with align left does NOT hug) -> STOP, the controller amends Ruling 9; the hover proof has any failure after the fix -> STOP (no exception list); any `style-kit:<id>` x language over 9,950 B -> STOP.

## Global Constraints

- **The validator is deterministic. No AI/LLM calls in it, ever.** `src/` and `wp-plugin/validator/` are not touched (`git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` stays empty).
- **No Divi schema from memory.** Every backticked attribute path the new or changed text prints is covered by `fixtures/shapes/verified-shapes.json` (via `ShapePaths::fromGuideText()` and `ShapeRegistry::covers()`); every structural claim (a tag row, an icon chip hugging) traces to a real recipe render or to P1.
- **Guides are grounded in real exports**: every recipe named exists (`GuideRecipePointersTest::testEveryRecipeNamedInTheStyleGuideAndItsTopicsExists` covers the new topic automatically) and is checked for the pattern it is named for.
- Anti-goals: no new MCP tool, REST route, OpenAPI operation; no remote call or custom code in `wp-plugin/`; no weakened test; no baseline file; no recipe change; `section-recipes.json`, `style-kits.json` unchanged.
- Sizes (measured at 895610e by the plan writer; Task 0 re-measures and STOPS on a difference): default guide **exactly 28,975 B** before and after; `topicParameterDescription()` 258 -> **exactly 272**; `kit-rules` 9,598 -> **<= 9,600** (Ruling 8); `controls` 9,105 -> **<= 9,500**; `style-kits` 8,178 unchanged; largest `style-kit:<id>` 9,599 (neo-brutal) -> **<= 9,950 for every language**; `kit-patterns` **<= 6,000**; every design-language topic grows by its Kit line plus one newline (+ the Ruling 5 rewords for brutalist-flat and luxe); `layout-advanced` 9,973 and every other topic unchanged.
- `tests/WpOrgComplianceTest.php` untouched and green; the Pro suite (`make test-pro`, 2169) green and untouched (Pro's `FreeApiCompatTest` pins some free signatures: `StyleGuide::markdown()` gaining an optional trailing parameter must be checked against it in Task 3b).
- Edit data with PHP or `python3 -I` scripts, never `sed -i` (macOS). `design-languages.json` changes ONLY the Ruling 5 phrases.
- Max 3 parallel workers. ONE probe, render or dogfood render at a time on the dev site (`pgrep -fl "node scripts/(verify|dogfood)-"` empty first). Implementers never touch the dev site. Live gates that run plugin PHP on the site (`verify-kits`, `dogfood-render`) run from the MAIN checkout with the branch checked out; `verify-shapes` may run from the worktree with `PLAYWRIGHT_PATH=/Users/Lucas/Documents/JHMG-Local/layoutlab/node_modules/playwright` and `COMPOSE_PROJECT_NAME=divi5deterministicvalidator`. Check `git branch --show-current` before and after every long run. A live sweep may already be running on the dev site: wait for it.
- Commit only your own files by explicit path, message ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Models: T0 sonnet, T1 sonnet, T3a **opus**, T3b **opus**, T4 **opus**, T5 sonnet, T7 opus, T8 sonnet; task reviews sonnet; the plan review and the final whole-branch review **opus**. T2, T6 and T9 are the controller's.

## Review Focus

Each line names the test that pins it and the task that owns it.

1. A path in `kit-patterns` or in a changed sentence that is not in the registry, or typed by hand where a patch decides it: `KitPatternsTopicTest::testEveryPathIsRegistered`, `testEveryNumberIsTheAdaptersOwn` (T4).
2. A recipe named for a pattern it does not show: `KitPatternsTopicTest::testEachNamedRecipeShowsItsPattern` (T4).
3. A budget broken or silently re-pinned: `WordingGapsBudgetTest` (T0, tightened by T4), the existing pins edited only as listed in Task 0 Step 4 (every task).
4. The hover fix touching any kit other than glass-edge and heavy-block, or any decision other than the `invert` + `same` hover frame: `KitHoverVisibilityTest::testOnlyTheFramedInvertSameHoverFrameMoved` (a sha256 over every patch of the other 38 kits taken before the edit), `KitGoldenFreezeTest` (7 hashes unchanged, 2 re-frozen with the reason) (T3a).
5. An unreadable or invisible hover: `KitHoverVisibilityTest::testAFramedButtonKeepsItsBoxOnHover` (zero exceptions) plus the existing button and form contrast proofs unchanged and green (T3a).
6. The `design_language` parameter cast instead of refused, or served differently on MCP, REST and OpenAPI: `StyleGuideLanguageParamTest` (T3b).
7. A kit-topic sample whose colours are not the asked language's, or a `builderVersion` other than 5.8.0 in any generated snippet: `StyleGuideLanguageParamTest::testEveryKitTopicResolvesInTheAskedLanguage`, `testEverySnippetCarriesTheGuideBuilderVersion` (T3b).
8. A language Kit line claiming a module no adapter styles or a width the adapter does not write: `KitPatternsTopicTest::testTheKitLineFollowsTheMapAndTheStrongWidth` (T4).
9. The default guide not exactly 28,975 B, or the old row-stacking shape still taught: `WordingGapsBudgetTest::testTheDefaultGuideIsExactlyTheSameSize`, `KitPatternsTopicTest::testTheDefaultGuideTeachesOneRowStackingShape` (T4).
10. A claim about tag or icon-chip rows that P1 did not prove: `KitPatternsTopicTest::testTheLayoutClaimsFollowTheProbe` (reads `fixtures/shapes/probe-results-kit-patterns.json`) (T4).

---

### Task 0: Measure, pin the budgets, inventory every test that will move (pure; sonnet; first)

**Files:**
- Create: `tests/WordingGapsBudgetTest.php`

- [ ] **Step 1: Re-measure at the branch head** (a scratch script, not committed; it loads `vendor/autoload.php`, `tests/bootstrap.php`, `wp-plugin/src/autoload.php`): `strlen()` of `StyleGuide::markdown()`, `topicParameterDescription()`, `markdown($t)` for `kit-rules`, `controls`, `style-kits`, every `style-kit:<id>` (largest), every `design-language:<id>`, every other topic. Expected (STOP and report if any differs): 28,975 / 258 / 9,598 / 9,105 / 8,178 / neo-brutal 9,599 / editorial 2,281, glass-dark 2,613, organic-warm 2,607, tech-mono 2,744, playful-pastel 2,890, corporate-trust 3,041, soft-saas 3,720, bold-vibrant 3,480, brutalist-flat 3,012, luxe 3,219 / `layout-advanced` 9,973, `layout-sizing` 9,972, `motion` 9,800.
- [ ] **Step 2: Write `tests/WordingGapsBudgetTest.php`** (green now; Tasks 3b and 4 tighten it): default guide exactly 28,975; description exactly 258 (Task 4 changes to 272); `kit-rules <= 9600`; `controls <= 9500`; every `style-kit:<id>` `<= 9950`; every other topic not named in this plan equal to its Step 1 size (a literal map generated once by the scratch script).
- [ ] **Step 3: Measure the default-guide swap** (scratch only): the bytes of the section "## Mobile: collapse multi-column rows to one column" through the blank line before "## Buttons side by side" (expected 360) and of the gradient bullet (expected 237); record both in the commit message.
- [ ] **Step 4: Confirm the inventory** with `git grep -n -E "assertSame\( ?258|<= ?260|260, strlen|9600|28975|resolved for|5\.0\.0-public-beta\.1|'5\.0\.2'|GENERATED_PLACE|GUIDE_NAMES|kit-adapter-patches|kit-form-patches|Mobile: collapse|stops\.0|show\` switch|testTheKitTopicsDoNotMove" -- tests scripts`. The hits must be within this list (any other file: STOP, the controller extends the owning task):
  - `tests/StyleGuideTopicsTest.php` (`testTheTopicParameterDescriptionLeavesHeadroom` `<= 260` -> `<= 290`; the topics-list test if it pins the order) - T4
  - `tests/CarrierBudgetTest.php` (258 x2 -> 272; `testTheKitTopicsDoNotMove` 40-entry map -> re-pinned to the new sizes; 28975 unchanged) - T3b (map), T4 (258)
  - `tests/LanguageTypeBudgetTest.php`, `tests/LanguageRhythmBudgetTest.php` (258 -> 272; exact language-topic growth formulas gain the Kit line, and the Ruling 5 delta for brutalist-flat and luxe; `testTheKitTopicsDoNotMove`) - T3b, T4
  - `tests/StyleGuideKitRulesTest.php` (258 -> 272; kit-rules pins; the tag-clause and accordion-clause assertions that Task 4 rewords; `testTheBandsAreDefinedOnce`) - T4
  - `tests/StyleGuideKitTopicsTest.php` (editorial-only legend assertions become per-language; the "resolved for `editorial`" header; the 28975 pin unchanged) - T3b
  - `tests/KitAdapterGoldenTest.php`, `tests/KitFormGoldenTest.php`, `tests/KitGoldenFreezeTest.php` (two goldens re-frozen) - T3a
  - `tests/KitAdaptersTest.php` (any assertion that an `invert` + `same` hover frame follows the fill) - T3a
  - `tests/DogfoodPrepareTest.php`, `tests/Support/Dogfood/Prepare.php` (`07-kit-patterns.md`, the language passed to `style-kit:`) - T5
  - `tests/KitResolverTest.php`, `tests/StyleGuideTypographyTopicTest.php` (hits on `5.0.0-public-beta.1` / `GENERATED_PLACE`: expected to stay unchanged; confirm and note)
- [ ] **Step 5:** `php -d memory_limit=-1 vendor/bin/phpunit --filter WordingGapsBudgetTest` PASS; commit `tests/WordingGapsBudgetTest.php`.

### Task 1: Audits as tests (pure; sonnet; parallel with Task 2)

**Files:**
- Create: `tests/KitHoverVisibilityTest.php` (the failing proof and the audit), `tests/WordingGapsEvidenceTest.php`

- [ ] **Step 1: The hover audit.** `KitHoverVisibilityTest` helpers: `box(array $patch, string $state, array $language, string $bandHex): float` = the larger of fill-vs-band and (frame width > 0) frame-vs-band, colours through `RecipeTokens::resolve()` and `Divi5Validator\Tests\Support\Colour::opaque()`/`contrast()`, transparent = no fill; bands `light` = `bg`, `accent` = `accent`, `panel` = `text` (`KitAdapters::BAND_FILL` once Task 4 lands; a local literal until then, with a TODO naming Task 4). Tests:
  - `testTheAuditCountsTheVanishingHovers` (pins the audit as measured at 895610e: over 40 kits x 10 languages x {light, accent, panel} x 2 roles = 2,400 cells, 60 have rest box >= 1.5 and hover box < 1.1; 30 are the five `invert` + `same` kits on light in editorial, glass-dark, tech-mono, corporate-trust, soft-saas, luxe; 30 are flat-square, block-caps, pill-caps on panel in all ten). Task 3a changes the expected numbers to 48 / 18 / 30 deliberately.
  - `testAFramedButtonKeepsItsBoxOnHover` (Ruling 7 scope; expected RED now with exactly 12 failures: glass-edge and heavy-block, `control`, light, the six languages). Mark it with a comment "turns green in Task 3a"; do not skip it: commit it RED only on the task branch if the controller allows, else commit it in Task 3a.
- [ ] **Step 2: Evidence pins** (`WordingGapsEvidenceTest`, each a fact the new text will state):
  - `testTheRecipesWriteThePlainGlyphAndTheExportTheEscapedOne`: every `"unicode"` in `section-recipes.json` matches `&#x[0-9a-f]+;`; `fixtures/valid/page-17-newpage.json` contains `&#xf0a9;`; `json_decode('"&#xf0a9;"') === '&#xf0a9;'`.
  - `testTheDividerPatchWritesNoShowAndNoSpacing`: for every kit and every rule role and band, the `divi/divider` patch's leaves are exactly `divider.advanced.line.desktop.value.{color,weight,style}`.
  - `testTheTestimonialRecipeInks`: the `testimonial` recipe's `divi/testimonial` writes `author` `text`, `jobTitle` `muted`, `company` `muted`, `content` body `text` at the four paths of spec row 11a, and each path is covered by the registry.
  - `testGradientStopsAreAListInExportsAndRecipes`: every `"stops"` in `section-recipes.json` and in `fixtures/valid/*.json` decodes to a JSON list of objects with exactly `position` and `color`; the export's positions are numbers.
  - `testNoRecipeRowUsesThePhoneFlexDirectionShape`: no `divi/row` in the recipes has `layout.phone.value.flexDirection`; at least 30 rows carry `flexWrap` at `phone`.
  - `testTheFeaturedPlan`: `pricing-three-tier` column #2 carries `card` + `on` light + ground `accent`; its features group `rule` on `accent`; its button `control` on `accent`; columns #1 and #3 `card` on light with a `rule` on `surface` and a `control-secondary`; no `divi/column` of the recipe carries a rule marker.
  - `testTheTagAndIconChipShapes`: portfolio-featured-grid's tag groups (padding 4px/10px, one `divi/text` child, in a parent group with `flexDirection` row and `flexWrap` wrap) and icon-values' / features-carousel-strip's icon chips (`icon.advanced.align` left, padding on `module.decoration.spacing`, no `sizing.maxWidth`).
  - `testQuietAndStrongShareAColourInTwoLanguages`: `border` equals `text` exactly in bold-vibrant and brutalist-flat and in no other language; the quiet-vs-band minimum on light/surface over the other eight is 1.18 (soft-saas) and the maximum 1.58 (glass-dark), rounded to two places.
- [ ] **Step 3:** `php -d memory_limit=-1 vendor/bin/phpunit --filter 'WordingGapsEvidenceTest|KitHoverVisibilityTest'`: evidence green, `testAFramedButtonKeepsItsBoxOnHover` RED with exactly 12 failures; commit.

### Task 2: P1, the tag and icon-chip layout probe (live, serial, controller-run; parallel with Task 1)

**Files:**
- Create: `fixtures/shapes/candidates/kit-patterns.json`, `fixtures/shapes/probe-results-kit-patterns.json` (the probe writes it), `fixtures/shapes/kit-patterns-vocabulary.md`

- [ ] **Step 0: Measure the bases first** (no `set`): (a) one `divi/icon` chip as the recipes write it (fill `#16a34a`, radius 999px, padding 10px, size 20px, align left) in a plain column; (b) the same with NO align (Divi's default centre); (c) a flex-row group (`flexDirection` row, `flexWrap` wrap, `columnGap` 12px, `rowGap` 8px, `alignItems` center) holding four (a) chips; (d) the same group holding three tags as portfolio-featured-grid writes them (group, 4px/10px padding, 2px solid frame, one `divi/text` label 12px). Record each module box's width and height and each group's row count at 1280 and 400 px (read the probe's geometry; if the probe cannot express a base, render ONE throwaway page through `make dogfood-render` and read `render.json` plus the screenshots instead, and say so in the vocabulary file).
- [ ] **Step 1: Candidates** `kp-icon-maxwidth-48` (base (a) plus `module.decoration.sizing.desktop.value.maxWidth` 48px: DEAD expected if the box already hugs), `kp-icon-center-maxwidth-48` (on base (b)), `kp-row-icons-wrap` and `kp-row-tags-wrap` (bases (c), (d): one row at 1280; wraps, no overflow at 400).
- [ ] **Step 2: Run** `node scripts/verify-shapes.cjs fixtures/shapes/candidates/kit-patterns.json` (one run at a time). Write the vocabulary file with one row per claim (`icon-hugs-left`, `icon-hugs-center`, `row-of-icons`, `row-of-tags`), each PASS (proven) or not, with the measured widths. Ruling 12: if `icon-hugs-left` is NOT proven, STOP.
- [ ] **Step 3: Promote** with `php scripts/promote-shapes.php fixtures/shapes/probe-results-kit-patterns.json fixtures/shapes/candidates/kit-patterns.json`; `make test` green; commit the files. No `aied shape probe ` page remains.

### Task 3a: The hover fix, its proof, the two goldens (opus; needs Task 1; parallel with Task 3b)

**Files:**
- Modify: `wp-plugin/src/KitAdapters.php` (`hover()` only, plus its docblock), `tests/KitHoverVisibilityTest.php`, `tests/KitAdaptersTest.php` (only the assertions Step 0 finds), `tests/golden/kit-adapter-patches.json`, `tests/golden/kit-form-patches.json`, `tests/KitGoldenFreezeTest.php`

- [ ] **Step 0 (before any edit):** a scratch script prints the sha256 of the JSON of `KitAdapters::patch()` over every (block, role, band, ground) of `APPLIED` for the 38 kits other than glass-edge and heavy-block, and for glass-edge and heavy-block every patch that is NOT a `divi/button` or `divi/contact-form` patch; `testOnlyTheFramedInvertSameHoverFrameMoved` pins both hashes as literals. List every existing test that asserts the `invert` + `same` hover frame (expected: few or none in `KitAdaptersTest`).
- [ ] **Step 1: Tests first.** `testAFramedButtonKeepsItsBoxOnHover` (Ruling 7 scope, zero exceptions); `testOnlyTheFramedInvertSameHoverFrameMoved`; `testTheFramedInvertSameHoverKeepsTheRestFrame` (glass-edge on light, accent and dark: hover frame colour = rest frame colour; fill and label still swap); `testTheZeroWidthInvertKitsAreUnchanged` (flat-square, block-caps, pill-caps patches byte-identical to Step 0); update `testTheAuditCountsTheVanishingHovers` to 48 / 18 / 30. RED.
- [ ] **Step 2: Implement** in `hover()`'s `invert` branch: the frame follows the fill only when the kit border colour is `same` AND the rest width is `0px`; otherwise it keeps `$rest['border']` (Ruling 7). Docblock: the measured reason (glass-edge on glass-dark, 12 cells, minimum 4.82:1 after).
- [ ] **Step 3: Goldens.** Regenerate `kit-adapter-patches.json` and `kit-form-patches.json` with their documented regeneration route; diff them: ONLY glass-edge and heavy-block hover frame colours may change (any other byte: STOP). `KitGoldenFreezeTest::FROZEN` gets the two new hashes, the docblock says "the wording-gaps hover fix (Ruling 7) re-froze kit-adapter-patches.json and kit-form-patches.json"; the other seven hashes untouched.
- [ ] **Step 4:** the existing button, card, form, item, rule, chip and carrier contrast proofs, `StyleKitsTest` (visible-difference rule) and `make test` green; commit.

### Task 3b: Kit-topic samples in the asked language, one builderVersion (opus; parallel with Task 3a)

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php` (`styleKitTopic()`, `kitButton()`, `kitCard()`, `kitInvertedPanel()`, `kitToggle()`, `markdown()`, the legend header lines), `wp-plugin/src/McpHandler.php`, `wp-plugin/src/RestController.php`, `wp-plugin/src/OpenApiSpec.php`, `tests/StyleGuideKitTopicsTest.php`, `tests/CarrierBudgetTest.php` / `tests/LanguageTypeBudgetTest.php` (`testTheKitTopicsDoNotMove` maps), `tests/WordingGapsBudgetTest.php`
- Create: `tests/StyleGuideLanguageParamTest.php`

- [ ] **Step 1: Tests first** (`StyleGuideLanguageParamTest`): `testEverySnippetCarriesTheGuideBuilderVersion` (every `"builderVersion"` in every `style-kit:<id>` topic, every language, equals `StyleGuide::BUILDER_VERSION` = `5.8.0`, the value the default guide states); `testEveryKitTopicResolvesInTheAskedLanguage` (40 kits x 10 languages: every hex in the code blocks is a colour of that language, `kitColourLegend()` names that language's roles, the header names the language); `testWithoutALanguageTheTopicIsEditorials` (byte-equal to `markdown('style-kit:<id>', 'editorial')`); `testTheParameterIsStrictOnEveryTransport` (MCP `design_language` non-string -> -32602, REST -> 400; unknown id -> a normal answer listing the ids, as `get_section_recipes` does; MCP, REST and OpenAPI declare it; the OpenAPI operation count unchanged); `testEveryKitTopicFitsForEveryLanguage` (`<= 9950`). RED.
- [ ] **Step 2: builderVersion.** `public const BUILDER_VERSION = '5.8.0'` in `StyleGuide`, used by the four snippet builders (the literals at lines ~959, 980, 985, 1012, 1117 disappear).
- [ ] **Step 3: The language.** `styleKitTopic( string $id, string $languageId = 'editorial' )`; the two legend headers say "resolved for `<language>`"; `markdown( ?string $topic = null, ?string $language = null )` passes it only for `style-kit:`. Check Pro's `FreeApiCompatTest` (`make test-pro`) accepts an optional trailing parameter on `StyleGuide::markdown()`; if it pins the signature, STOP (the controller decides: a separate public method instead).
- [ ] **Step 4: Transports.** MCP `get_style_guide` input schema gains `design_language` (description: "With topic=style-kit:<id>: resolve the sample blocks in this design language (ids: ...). Default: editorial."); `RestController` reads `?design_language=` with `PageAccess::stringArg`; `OpenApiSpec` adds the query parameter to `/style-guide`. Lockstep tests green.
- [ ] **Step 5: `kit-rules` "Reusing a kit topic's blocks"** is left for Task 4 (it owns `kit-rules`); this task only exposes the parameter.
- [ ] **Step 6:** re-pin the 40-entry `testTheKitTopicsDoNotMove` maps to the new sizes (generated by a scratch script; the commit message lists the largest per language); `make test`, `make test-pro` green; commit.

### Task 4: The guide text (opus; needs Tasks 2, 3a and 3b merged)

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php` (`topics()`, `markdown()`, new `kitPatterns()`, `bandSentence()`, `itemRules()`, `ruleChipShapes()` tag clause, `kitRules()` Reusing paragraph, `controlsTopicText()` or a new generated clause beside it, `guide()` default-guide swap), `wp-plugin/src/KitAdapters.php` (`BAND_FILL` only), `wp-plugin/src/DesignLanguages.php` (`kitLine()`, `GENERATED_PLACE`, `generatedLines()`), `wp-plugin/data/design-languages.json` (Ruling 5 phrases only), the tests listed in Task 0 Step 4 for T4
- Create: `tests/KitPatternsTopicTest.php`

- [ ] **Step 1: Tests first** (`KitPatternsTopicTest`), RED:
  - `testTheTopicIsListedServedAndWithinBudget` (after `kit-rules` in `topics()`; MCP and REST serve the same text; `<= 6000`; no `aied`, no `{{aied:`, no `http`; the default guide's topic line and the description name it; description exactly 272).
  - `testEveryPathIsRegistered` (`ShapePaths::fromGuideText()` over the topic, each `ShapeRegistry::covers()`).
  - `testEveryNumberIsTheAdaptersOwn`: the tag frame rule equals `chipPatch()`'s width for all 40 kits (the kit width; 1px for a 0px kit; 3px for a double under 3px); the strong and quiet widths printed equal `ruleWidth()`; the hover sentences equal the roles read from `patch()` for one kit of each hover kind present in `style-kits.json` (`none`, `lift`, `fill-swap`, `invert`), on light, accent and panel; the submit sentence equals `formPatch()`'s button colours.
  - `testEachNamedRecipeShowsItsPattern`: every backticked recipe name in the topic is in a map recipe => the check of `WordingGapsEvidenceTest` that proves it (portfolio-featured-grid: tags; icon-values, features-carousel-strip: icon chips; pricing-three-tier: plan cards and the featured plan; testimonial: inks; list-icon-dividers or team-grid: rules).
  - `testTheLayoutClaimsFollowTheProbe`: a "row of tags" / "row of icon chips" / "centred icon" sentence appears only when its P1 row is PASS; `maxWidth` appears only if P1 shows a box that does not hug.
  - `testTheQuietStrongSentenceIsGenerated`: the languages named as "quiet = strong colour" are exactly those with `border` == `text`; the faint range printed equals the measured min/max (two places).
  - `testTheLimitsNameTheKitsTheHoverFixCannotReach`: the 0px `invert` kits named are exactly the `invert` kits with width `0px` in `style-kits.json`.
  - `testTheKitLineFollowsTheMapAndTheStrongWidth` (every language topic: the line is printed after "- Style kit:", `<= 260` B, its nouns are `appliedNounsSentence()`'s, its width is `ruleWidth('rule-strong', $kit['border'])` of the language's own kit).
  - `testTheBandSentenceNamesTheRoles` (`bandSentence()` prints each band with its `BAND_FILL` role; `BAND_FILL` equals the reverse of `tests/Support/Dogfood/Page.php` PALETTE's first role per band).
  - `testTheAccordionSentenceSaysEachItemNotTheWhole` and `testTheTagClauseSaysTheKitWidth` (kit-rules).
  - `testTheControlsButtonDeadSentenceNamesTheContactFormException` (present while `FORM_PROVEN['button-padding']` and `['button-shadow']` are true; absent when a planted map makes either false).
  - `testTheDefaultGuideTeachesOneRowStackingShape` (no "Mobile: collapse"; the row shape is the measured one; the gradient is a list) and `WordingGapsBudgetTest::testTheDefaultGuideIsExactlyTheSameSize` (28,975).
- [ ] **Step 2: `kit-patterns`** (`StyleGuide::kitPatterns()`), sections in this order, each generated:
  1. One-line intro: what the topic is; "get_section_recipes with `name` and `style_kit` serves each named recipe resolved under any kit".
  2. **Tags in a row** (1a): the parent flex-row group (paths `module.decoration.layout.desktop.value` with `flexDirection` row, `flexWrap` wrap, `columnGap`, `rowGap`, `alignItems` center) as P1 proves; each tag a `divi/group` (padding at `module.decoration.spacing.desktop.value.padding`, e.g. 4px/10px) holding ONE `divi/text` label in the language's eyebrow style, `muted` (the band's ink on a dark band); frame = the kit's border width and style (rule from `chipPatch()`), radius = the kit's control radius, so a 0px kit gives square tags even where a language says pill (the kit wins). Recipe: portfolio-featured-grid.
  3. **Icon chips** (1b): `divi/icon` with `icon.advanced.align` left (and centre only if P1 proves it), padding on `module.decoration.spacing`, glyph size at `icon.advanced.size.desktop.value`; the module box hugs glyph plus padding; radius and fill from `kit-rules`. A row of icon chips only as P1 proves. Recipes: icon-values, features-carousel-strip.
  4. **Plan columns and the featured plan** (2, 3): plans are separated by the row's column gap and a `card` on each `divi/column` (no side rule exists; `kit-rules`); the inside of a plan is ruled by a quiet top edge on its features group; the featured plan is an accent-ground card (fill accent, frame in the fill colour, its text accent-text, its inner rule the quiet rule of the accent band, its button the kit's primary for an accent band: the colours printed from `patch()`); an inverted panel's button takes the panel treatment (printed from `patch()`). Recipe: pricing-three-tier (column #2 is the featured plan).
  5. **Quiet and strong rules, dividers** (6, 8): the quiet line is a decorative separator (faint: printed range on light and surface); in the languages where the hairline colour is the text colour (printed list) quiet and strong differ only by width (printed rule); a divider needs no `show` key and no spacing (the kit's own dividers carry neither); a line that opens a section is a `rule-strong` divider. Recipes: list-icon-dividers, team-grid.
  6. **Hover colours** (7): one sentence per hover kind present in the kits (from `patch()`: `none` and `lift` keep the colours; `fill-swap` turns a filled button transparent in the band's ink and fills an outline one; `invert` swaps label and fill, a framed kit keeps its frame colour), on a panel (filled -> transparent in the panel's ink), on an accent band or accent ground (printed), the contact-form submit (the kit's primary for its band). Limits sentence (generated): the 0px `invert` kits (printed list) leave only the label on hover where the page colour equals the label colour; on tech-mono's accent band the `invert` hover of a framed text-frame kit is faint.
  7. **Testimonial text** (11a): author at `author.decoration.font.font.desktop.value.color` in text, job title and company at their `...font.font.desktop.value.color` in muted, the quote at `content.decoration.bodyFont.body.font.desktop.value.color` in text; the card from `kit-rules`. Recipe: testimonial.
  8. **Glyph codes in block JSON** (9): the plain `&#xf00c;` and the escaped `&#xf00c;` are the same value; Divi's builder writes the escaped one; either validates.
  9. `## Limits`: what P1 did not prove; the hover limits; Divi's default divider spacing is not measured.
- [ ] **Step 3: Design-language Kit line** (`DesignLanguages::kitLine()`, `GENERATED_PLACE['kit'] = 'style_kit'`): e.g. "- Kit: raw-frame decides the corners, borders, shadow and hover of buttons, cards, accordion and toggle items, forms, rules and chips; where this topic's prose differs, the kit wins (a section's opening line is its strong rule: 4px). Patterns: topic=kit-patterns." (nouns from the map, the width from `ruleWidth()`; `<= 260` B). Then the Ruling 5 rewords in `design-languages.json` (a `python3 -I` script; the JSON's formatting kept byte-for-byte elsewhere).
- [ ] **Step 4: `kit-rules`** (Ruling 8): `bandSentence()` from `KitAdapters::BAND_FILL` ("`on` = the band's fill: `light` bg (the page colour, a dark language's too), `surface` surface, `accent` accent, `dark` a photo or overlay, `panel` text (inside an inverted card)"), no longer than today; the accordion sentence "On the accordion write all of it on the parent; Divi draws the frame, radius and shadow around each item, never around the whole accordion, and the items carry none:"; the tag clause "(width and style: the kit's, 1px at least, 3px for a double; colour ...)"; with Task 3b's parameter, the Reusing paragraph becomes "Every block in a kit topic is real ... resolved for the `design_language` you pass (`editorial` when none) ... in any other language swap each colour by its role (the legend), never by value, and the font family for your language's body font." Trims, in this order, until `<= 9,600`: (a) the Reusing paragraph's longer wording above; (b) "every state is written, so none falls back to Divi's grey defaults;" -> "every state is written;"; (c) the More carriers paragraph's "a brand mark, not a separator" stays. Only then Ruling 8's fallback.
- [ ] **Step 5: `controls`**: after "write them on the module." a generated " This is a `divi/button`; a contact form's submit is the opposite (topic=kit-rules)." while the two `FORM_PROVEN` decisions are true. `<= 9,500`.
- [ ] **Step 6: The default guide** (exactly 28,975 B): replace the Mobile section with "## Mobile: rows and flex groups" (a row of columns stacks only with the row shape above: `flexWrap` "wrap" at `phone` and each column's `flexType` "24_24"; a flex `divi/group` or column holding modules side by side stacks with its own `layout.phone.value.flexDirection` "column"); the gradient bullet becomes "`…gradient.stops` = a list: `[{"position":0,"color":"rgba(10,10,10,.5)"},{"position":100,"color":"rgba(0,0,0,.6)"}]`, `…gradient.length`="100%""; pad or cut words (never a path) to the exact byte.
- [ ] **Step 7:** re-pin the exact tests of Task 0 Step 4 owned by T4, each edit with a comment naming this plan; `make test`, `make test-pro` green; record every size before/after in the commit message; commit.

### Task 5: The dogfood harness follows the guide (sonnet; needs Task 4)

**Files:**
- Modify: `tests/Support/Dogfood/Prepare.php`, `tests/DogfoodPrepareTest.php`, `docs/dogfood.md` (the file list only)

- [ ] **Step 1:** `GUIDE_FILES` gains `'07-kit-patterns.md' => 'kit-patterns'`; `03-style-kit.md` is `StyleGuide::markdown('style-kit:<kit>', <language>)` (with Task 3b's parameter); `TASK.md` says "02..07 are topics it points to". Tests first (`GUIDE_NAMES`, byte-identical repeat, the refresh/refuse rules unchanged).
- [ ] **Step 2:** `DogfoodCorpusTest` still green (the corpus scores do not depend on the guide files); `make test`; commit.

### Task 6: Blind dogfood re-run (controller; fresh agents; renders serial)

- [ ] **Step 1: Prepare** into fresh out dirs (never over round 2's): `make dogfood-prepare KIT=glass-edge LANGUAGE=glass-dark OUT=dist/dogfood/wg-glass-edge-glass-dark`, `make dogfood-prepare KIT=raw-frame LANGUAGE=brutalist-flat OUT=dist/dogfood/wg-raw-frame-brutalist-flat`, and a third pair if budget allows: `dotted-pill` x `playful-pastel` (pill tags under a 2.30:1 accent).
- [ ] **Step 2: Assistants:** one fresh general-purpose agent per pair, exactly the `docs/dogfood.md` prompt, no hints (max 3 at once).
- [ ] **Step 3: Score** each page with `make dogfood-score PAGE=... KIT=... LANGUAGE=...` (`ARGS="--json"` kept); then **render** one at a time from the main checkout with the branch checked out (`make dogfood-render PAGE=...`).
- [ ] **Step 4: Classify** every sentence of each assistant's "unclear / missing / contradictory" paragraph against gaps 1-14 (re-reported / new / none) in a table for Task 8. Done means: scores >= 95%, renders clean (valid, 0 overflow, no console or PHP error), no gap 1-14 re-reported as unanswered; a re-report goes to Task 7.

### Task 7: One wording fix wave from the re-run (opus; only if Task 6 finds a re-report or a new grounded gap)

- [ ] Fix only wording (no adapter, no data beyond Ruling 5), generated as above, within the same budgets; tests first; then the controller repeats Task 6 for the affected pair only. A gap that needs evidence the repo does not have is recorded as a limit, not guessed.

### Task 8: Docs and the Outcome (sonnet)

**Files:**
- Modify: `docs/superpowers/specs/2026-10-25-wording-gaps-design.md` (Outcome), `docs/dogfood.md` (round 3 summary), `CLAUDE.md` is the controller's

- [ ] **Step 1:** the Outcome from measured facts only: per gap the answering text (topic, function) or why not; P1 results; the hover audit before/after (60 -> 48 vanishing cells; 12 fixed; the remaining 18 + 30 + 4 and why); the two re-frozen goldens and seven unchanged hashes; every size before/after (default guide 28,975 = 28,975; description 258 -> 272; `kit-rules`; `controls`; `kit-patterns`; largest kit topic per language; each language topic); suite counts (free, Pro); the dogfood table (scores, renders, re-reports) and the live ledger; deviations; known limits; owner decisions left (merge and release; whether the 0px `invert` kits should get a hover rule; the recipe `builderVersion` values (old exports, untouched)).
- [ ] **Step 2:** `make test`, `make test-pro`, `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` empty; commit.

### Task 9: Final live gates and the whole-branch review (controller, live, serial)

- [ ] (Task 2, early) P1 as above.
- [ ] `make verify-shapes`: OK (the new `kp-*` evidence did not disturb `known.json`).
- [ ] Narrowed kit render for the hover fix: `node scripts/verify-kits.cjs --kits glass-edge,heavy-block` (editorial and glass-dark cells) and `--kits heavy-block --languages bold-vibrant`, `--kits glass-edge --languages glass-dark`: 0 FAIL (the real hover now keeps the frame); `--clean-orphans` finds 0 pages.
- [ ] Task 6 renders (already serial).
- [ ] `php -d memory_limit=-1 vendor/bin/phpunit` (full) and `make test-pro` (2169): green; the validator diff empty.
- [ ] The opus whole-branch review against the Review Focus list (every line's test exists and fails on its planted case), one fix wave, one scoped re-review; `git merge --no-ff` into LOCAL main (no push); CLAUDE.md and memory updated by the controller.

---

## Parallelism map

| Phase | Work | Who | Can run beside |
|---|---|---|---|
| A | T0 budgets + inventory | sonnet | nothing (short, first) |
| B | T1 audits as tests | sonnet | T2 |
| B | T2 P1 probe | controller, live | T1 |
| C | T3a hover fix + goldens | opus | T3b (different files except none shared) |
| C | T3b kit-topic language + builderVersion + transports | opus | T3a |
| D | T4 guide text | opus | nothing (owns StyleGuide.php, needs T2, T3a, T3b) |
| E | T5 dogfood harness | sonnet | T9's verify-shapes / narrowed verify-kits |
| F | T6 blind re-run (agents parallel, renders serial), T7 if needed | controller / opus | nothing on the dev site |
| G | T8 docs | sonnet | T9 review prep |
| H | T9 final gates + opus review | controller | nothing |

At most 3 workers at once; ONE live run on the dev site at a time; merge each worktree in task order and re-run `make test` after each merge. T3a and T3b do not share a file (T3a: `KitAdapters.php` + goldens + `KitAdaptersTest`; T3b: `StyleGuide.php`, the three transports, kit-topic tests); if `CarrierBudgetTest` is touched by both, T3a does not touch it.

---

## Spec issues found

The plan does not silently deviate; each has the plan's default in brackets. The controller resolves 1-3 before Task 3, the rest before Task 4.

1. **The `design_language` parameter is an API change** in a free tool (MCP schema, REST, OpenAPI). [Adopt (Ruling 6); the fallback is a two-sentence header change plus the `builderVersion` fix.]
2. **Pro's `FreeApiCompatTest` may pin `StyleGuide::markdown()`'s signature.** [Checked in Task 3b Step 3; STOP if it does.]
3. **The hover proof at 1.5:1 cannot be zero-exception on the accent band** (4 tech-mono cells at 1.10:1 for the framed `invert` kits with a `text`/`accent` frame) without a second role change. [Scope light + panel; the 4 cells are a stated limit.]
4. **Two goldens must be re-frozen** (`kit-adapter-patches.json`, `kit-form-patches.json`) because glass-edge and heavy-block are in them; that is a deliberate re-freeze, not an addition. [Accepted under Ruling 7; the other seven hashes stay.]
5. **A row of several tags and a row of icon chips are in no recipe** (one tag per card; icon chips sit in cards or carousel slides). [P1 decides; until then the topic teaches the recipe's single tag and single chip.]
6. **The dogfood brief asks for "three plans in three columns separated by rules"**, a pattern no kit draws (no side edge). [Keep the brief (it is the stress test of gap 2); the topic answers it.]
7. **`GuideRecipePointersTest` reads every backticked hyphenated word as a recipe name** unless it is a topic, a language id, a kit id, a marker role (`KitAdapters::APPLIED`) or in its `NOT_RECIPES` list (`recipeMentions()`). Topic names and roles are therefore safe once `kit-patterns` is in `topics()`; a backticked colour role such as `accent-text` or a path fragment with a hyphen is NOT. [Write colour roles unbackticked (as `kit-rules` does); extend `NOT_RECIPES` only with a justified entry.]
8. **The kit-topic budget per language is unmeasured** (a language whose roles share more values prints a longer legend). [Task 3b measures all 400 and STOPS above 9,950.]
9. **Recipes keep their old `builderVersion` values** (e.g. `5.0.0-public-alpha.23`, `5.2.1`): served recipes still disagree with the guide's 5.8.0. [Out of scope (recipe bytes, many tests); recorded as an owner decision.]
10. **`bandSentence()` is also defined from `RULE_BANDS` and `CHIP_BANDS`**; `BAND_FILL` must cover exactly their union. [A test pins it.]
