# Rhythm Adapter (Adapter B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A design language also decides the vertical air between sections. A recipe `divi/section` carries `"aied":{"rhythm":"section|open|tight"}`; when the recipe is served, the language's top and bottom padding for that role are written for desktop, tablet and phone at the section's proven spacing path. The data lives on the language (`section_space`, the structured form of the existing `section_padding` prose, pinned to it by a test); `open` and `tight` are DERIVED from it by one pure function (Ruling 8); one generated "Section spacing" line in each `design-language:<id>` topic gives the same numbers to a hand-written page; a probe and the existing render gate prove it on the real Divi; 46 sections of the library are migrated.

**Architecture:** Adapter A's machinery, extended, nothing copied. `LanguageAdapters` gains the kind `rhythm` (`APPLIED['rhythm']`, `RHYTHM_PATHS`, `RHYTHM_PROVEN` pinned to a vocabulary table, `rhythmPatch()`); `LanguageResolver` gains `LEFT['rhythm']` and otherwise resolves rhythm markers with the code it already has (it is generic over `KINDS`); `SectionRecipes::resolveMarkup()`, `BlockAttrs`, `KitResolver` and `ServedVariants` are unchanged in behaviour. `DesignLanguages` gains the data checks, the derived-role function and the generated line. A golden + sha256 freeze, a pure audit and migration report, a deterministic migration script, and rhythm slots in the existing gate `make verify-language-type` (spec Ruling 11: one gate script).

**Tech Stack:** pure PHP 8 (`wp-plugin/src/`), PHPUnit (`php -d memory_limit=-1 vendor/bin/phpunit`, `make test`), Node + Playwright probe/render scripts against the Docker dev site (Divi 5.15.1).

**Spec:** `docs/superpowers/specs/2026-10-24-type-and-rhythm-adapters-design.md` (Adapter B: sections 1, 2 (rhythm marker), 3, 4, 5 (`lr-*`), 6, 7, 8 Rulings 8-10, 9). Template: the finished Adapter A plan `docs/superpowers/plans/2026-10-24-type-adapter.md` (its "B adds" notes are honoured below). Code to mirror (A's real code): `wp-plugin/src/LanguageAdapters.php` (`typePatch()`, `TYPE_PROVEN`), `wp-plugin/src/LanguageResolver.php`, `wp-plugin/src/DesignLanguages.php` (`typeProblems()`, `typeLine()`, `generatedLines()`, `GENERATED_LINE_MAX`, `markdown()`), `tests/LanguageAdaptersTest.php` (`vocabularyDecisions()`, `probed()`), `tests/LanguageResolverTest.php`, `tests/LanguageTypeGoldenTest.php`, `tests/KitGoldenFreezeTest.php`, `tests/Support/TypeAudit.php` + `tests/TypeAuditTest.php`, `scripts/type-migration-report.php`, `scripts/migrate-type-markers.php`, `tests/TypeMigrationTest.php`, `scripts/verify-language-type.cjs` + `scripts/lib/language-type-checks.cjs` / `language-type-run.cjs` / `language-type-sheet.php`, `scripts/test-verify-language-type.cjs`, `scripts/test-language-type-run.cjs`, `tests/LanguageTypeBudgetTest.php`.

Branch `feat/rhythm-adapter` from LOCAL main AFTER the controller merged `feat/type-adapter` (`--no-ff`; the controller records the merge hash here: `______`), plan commit on top, worktree `/Users/Lucas/Documents/JHMG-Local/wt-rhythm-adapter` for implementers. `git merge --no-ff` into LOCAL main at the end. NO push, release, version bump, zip rebuild, readme edit or SVN.

Decisions this plan makes where the spec says "Left to the builder" (each restated in the spec Outcome by Task 7):
- **Classification rule (measured, Task 4):** a library section is a rhythm candidate only when its padding is `Npx` top = bottom, N > 0, at ALL THREE breakpoints (`module.decoration.spacing.{desktop,tablet,phone}.value.padding`), with no other spacing key and no hover/sticky state. Its role is the one whose factor (section 1, open 1.25, tight 0.6) is nearest, in ratio (`|ln((desktop / 112) / factor)|`), to its desktop value over **112 px, the library's own section value** (31 of 71 sections). Equivalently: desktop >= 126 px open, <= 86 px tight, else section. A candidate whose desktop value is outside the band `[0.45, 1.6] x 112` (51-179 px) is not near any role and is an exemption. Everything else is a named exemption (Ruling 10 and the controller's "missing => literal").
- **Rounding and clamping (Ruling 8, exact):** `open` and `tight` are computed in integers, `intdiv( v * num + 4 * den, 8 * den ) * 8` with open `[5, 4]` and tight `[3, 5]` (nearest multiple of 8, a half rounds up; no float), then desktop is clamped to [56, 200], phone to [28, 120] and tablet to [phone, desktop] (a clamped value is the bound itself, so 28 px is a legal phone value). `section` is the data itself, never rounded (44 px stays 44 px).
- **Where the derivation lives:** in `DesignLanguages` (`sectionSpace()`), not in `LanguageAdapters`, because the generated guide line needs it and `DesignLanguages` must stay dependency-free (`tests/HtmlTestCase.php` loads it alone; `LanguageAdapters` already requires `DesignLanguages.php`, never the reverse). The spacing path string is `DesignLanguages::SECTION_SPACE_PATH = 'module.decoration.spacing'`; `LanguageAdapters::RHYTHM_PATHS['divi/section']` IS that constant (one source; a test pins it).
- **The generated line** sits under `## Shape, space and depth`, as the line right after `- Section padding: ...` (A's `generatedLines()` printed every line under `## Fonts`; `markdown()` now places each line by name). It names the path itself (`layout-sizing`, the topic that teaches spacing, is 9,972 of 10,000 B: no room for a sentence there). Template (computed while writing this plan; luxe, the longest, 239 B):
  `- Section spacing: padding top and bottom at module.decoration.spacing.{desktop,tablet,phone}.value.padding (never left or right); sections 120/80/56px, open (a hero or closing band) 152/104/72px, tight (a logo or stats strip) 72/48/32px.`
- **`syncVertical: "on"` is written** (spec section 2) beside equal top and bottom; the style guide's own evidence says the toggle alone is inert ("only a builder toggle"), so it only keeps the builder's linked control honest. Probe-guarded (`section-sync`); if that decision fails, the patch omits the key (the ONLY fallback).
- Class, constant, file and test names: `DesignLanguages::SECTION_SPACE_KEYS`, `SECTION_SPACE_BOUNDS`, `SECTION_SPACE_PATH`, `RHYTHM_ROLES`, `RHYTHM_FACTORS`, `sectionSpaceProblems()`, `sectionSpace()`, `sectionSpaceLine()`; `LanguageAdapters::RHYTHM_PATHS`, `RHYTHM_PROVEN`, `rhythmPatch()`; `tests/Support/RhythmAudit.php`, `tests/RhythmAuditTest.php`, `scripts/rhythm-migration-report.php` (`make rhythm-report`), `tests/LanguageRhythmAdapterTest.php`, `tests/LanguageRhythmGoldenTest.php`, `tests/golden/language-rhythm-patches.json`, `scripts/migrate-rhythm-markers.php`, `tests/RhythmMigrationTest.php`, `tests/LanguageRhythmBudgetTest.php`, `fixtures/shapes/candidates/language-rhythm.json`, `fixtures/shapes/language-rhythm-vocabulary.md`, `fixtures/shapes/probe-results-language-rhythm.json`, `scripts/lib/language-rhythm-checks.cjs`, `scripts/lib/language-rhythm-run.cjs`, `scripts/test-verify-language-rhythm.cjs`, `scripts/test-language-rhythm-run.cjs`.
- **The gate:** rhythm sections are added to the SAME throwaway page per language that `verify-language-type.cjs` builds (after its type section), measured by a sibling pure module (`language-rhythm-checks.cjs`) and a sibling run module (`language-rhythm-run.cjs`), so A's tested files stay as they are; the gate script and its Makefile target stay one (Ruling 11).

## Controller rulings (2026-10-24; WHERE THIS SECTION CONFLICTS WITH THE TEXT BELOW, THIS SECTION WINS)

Restated from the spec and A; they bind every implementer:
1. **Ruling 1:** rhythm lives on the LANGUAGE (`section_space` in `wp-plugin/data/design-languages.json`), never on a style kit. No kit file, kit adapter, kit resolver or kit golden changes.
2. **Ruling 8:** three roles, `section` (= the language's `section_space`), `open` (x1.25) and `tight` (x0.6), derived by `DesignLanguages::sectionSpace()` exactly as stated above. No per-language open/tight numbers are stored.
3. **Ruling 3:** the marker is exactly `"aied":{"rhythm":"<role>"}` (one key, no `on`), on `divi/section` only, resolved by `LanguageResolver` before the kit step. No library section carries any other `aied` marker today (measured), so the one-key rule costs nothing.
4. **Ruling 4:** the language wins. The patch writes `module.decoration.spacing.<desktop|tablet|phone>.value.padding.{top,bottom,syncVertical}` and nothing else (never left/right, margin, a state, a background, a divider); the migration DROPS `top`, `bottom` and `syncVertical` of every breakpoint of every marked section (all 46 carry nothing else under `spacing`, so their `spacing` object goes and the patch brings it back).
5. **Ruling 9:** rows, horizontal padding and other modules are out.
6. **Ruling 10 + controller:** asymmetric, `%`, `vw`, `0px`, missing (no padding, or desktop only) and out-of-band sections stay literal and are named exemptions (25, by reason, cap asserted).
7. **Probe-conditional rule:** `section-padding` false -> `rhythmPatch()` returns null (STOP). `section-sync` false -> the patch omits `syncVertical` (the golden follows). `section-image`, `section-first`, `section-later` or `section-overlap` false -> STOP before Task 3: the controller decides (they are conditions, not keys).
8. **Ruling 12:** the dogfood scorer is not extended (it scores kit decisions; it reads no section); say so in the Outcome.
9. Visible difference: no two languages share a `section_space` triple. Measured: all ten are distinct, so NO exemption file is created (cap 0, asserted). The DERIVED triples do collide (Spec issue 7) and are not a test.

## Global Constraints

Copied from the spec, A's plan and CLAUDE.md's hard constraints:
- **The validator is deterministic. No AI/LLM calls in it, ever.** `src/` and `wp-plugin/validator/` are not touched (`git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` stays empty).
- **No Divi schema from memory.** Every path the patch writes is in `fixtures/shapes/verified-shapes.json` (export-proven: `module.decoration.spacing.{bp}.value.padding.{top,bottom,syncVertical}`, `fixtures/valid/page-23-style-reference.json`) AND is probed ON `divi/section` by Task 1. A decision is read from `LanguageAdapters::RHYTHM_PROVEN`, which a test pins to the "Adapter decisions" table of `fixtures/shapes/language-rhythm-vocabulary.md`; every (language, role) value set the patch writes has its own PASS candidate (`lr-<language>-<role>`).
- **Recipes and guides are grounded in real exports**; every recipe is re-validated through `LayoutGate` under all 10 languages; recipes keep tokens and markers only; no custom CSS/JS/PHP.
- Anti-goals: no new MCP tool, REST route, OpenAPI operation or guide topic NAME; no remote call or custom code in `wp-plugin/`; no weakened test; no baseline file for a proof; goldens only ADDED (`KitGoldenFreezeTest`'s seven frozen goldens, the type golden among them, stay byte-identical).
- Sizes (measured while writing this plan at `feat/type-adapter` 2b40d59 = the merged state; asserted by `tests/LanguageRhythmBudgetTest.php`): default guide **exactly 28,975 B**; `topicParameterDescription()` **exactly 258**; `typography` **7,945 B unchanged**; `kit-rules` **8,997**, `style-kits` **8,444**, `controls` **8,331**, `design-languages` **2,592**, `layout-sizing` **9,972** unchanged; every `design-language:<id>` topic grows by exactly its Section spacing line + 1 newline, each line **<= 260 B** (`GENERATED_LINE_MAX`); `section-recipes.json` **< 662,117 B** (simulated after migration: **652,430 B**); `design-languages.json` (27,997 B) grows only by the ten `section_space` objects and the one tech-mono prose reword.
- Register nothing new in `wp-plugin/src/autoload.php` (no new source file; `PluginAutoloadCoversEverySourceFileTest` stays green as is).
- `tests/WpOrgComplianceTest.php` untouched and green; the Pro suite (`make test-pro`, 2169) green and untouched; `wp-plugin/readme.txt` untouched.
- macOS `sed -i` needs a suffix: edit data with PHP or `python3 -I` scripts, never `sed -i`. Recipe edits ONLY through `scripts/migrate-rhythm-markers.php` (Task 5).
- Max 3 parallel workers. ONE probe or render at a time on the dev site (`pgrep -f "node scripts/verify-"` empty first). Implementers never touch the dev site; the controller runs every live gate. Live gates that execute plugin PHP (`verify-language-type`, `verify-recipes`, `verify-kits`) run from the MAIN checkout with `feat/rhythm-adapter` checked out (`docker-compose.yml` mounts `./wp-plugin` of the main checkout); `verify-shapes` may run from a worktree with `PLAYWRIGHT_PATH=/Users/Lucas/Documents/JHMG-Local/layoutlab/node_modules/playwright` and `COMPOSE_PROJECT_NAME=divi5deterministicvalidator`. Check `git branch --show-current` before and after every long run.
- Commit only your own files by explicit path (`git add <path>...`), message ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Explicit model per Agent dispatch: T0 sonnet, T2 sonnet, T3 **opus**, T4 sonnet, T5 **opus**, T6 sonnet, T7 sonnet; task reviews sonnet; final whole-branch review **opus**. Task 1 and Task 8 are the controller's.

## Review Focus

Each line names the test that pins it and the task that owns it.

1. A path written that Task 1 did not prove on `divi/section`, or a (language, role) value set no candidate rendered: `LanguageRhythmAdapterTest::testEveryLeafIsAProbedPathOnTheSection`, `testEveryLanguageRoleWasProbed`, `testRhythmProvenMatchesTheVocabularyTable` (T3).
2. The patch writing anything beyond top/bottom/syncVertical at the three breakpoints, a left/right/margin key, a non-`Npx` value: `testThePatchWritesTopBottomAndSyncOnly`, `testTheSyncFallbackOmitsTheToggle`, `testUnprovenPaddingRefusesTheSection` (T3).
3. A rhythm marker on another block, a malformed marker, an extra key, `rhythm` with `type` or `kit`: left, reported, recipe refused: `LanguageResolverTest::testRhythmMarkersTheResolverDoesNotKnowAreLeftAndReported`, `SectionRecipesTest::testAnUnresolvableRhythmMarkerIsNeverServed` (T3).
4. A rhythm marker reaching the kit step or served markup: `LanguageResolverTest::testARhythmMarkerNeverReachesTheKitStep`, `RhythmMigrationTest::testNoMarkerTokenOrLeftoverReachesServedMarkup` (T3, T5).
5. The derivation wrong, unbounded or out of order: `DesignLanguagesTest::testTheDerivedRolesAreTheRuling8Table`, `testTheDerivedRolesHoldForEveryTripleInsideTheBounds`, `testTheShippedRolesAreStrictlyOrdered` (T2).
6. Data contradicting prose, a bound broken, a shape accepted that should not be: `testEverySectionSpaceIsItsProseSentence`, `testSectionSpaceProblemsCatchesEachBrokenShape`, `testNoProsePromisesOnePaddingForEverySection`, `testNoTwoLanguagesShareASectionSpaceTriple` (T2).
7. A golden changed, or an earlier golden touched: `LanguageRhythmGoldenTest`, `KitGoldenFreezeTest::testTheEarlierGoldensAreByteIdentical` (8 hashes) (T4).
8. A section lost, added, re-roled, left bespoke, or still carrying its own top/bottom/sync: `RhythmMigrationTest::testTheMarkersArePinnedAndNothingIsBespoke`, `testMarkedSectionsCarryNoPaddingOfTheirOwn`, `testTheExemptionsAreExactlyTheNamedSections`, the merge trap `testMarkedSectionsKeepEverythingElseUnderEveryLanguage` (T5).
9. A divider crossing its section's content because a role shrank the padding: `RhythmMigrationTest::testDividersFitTheirSectionsPaddingUnderEveryLanguage` (T5).
10. A guide line over its cap, a byte budget broken, the path typed twice: `LanguageRhythmBudgetTest`, `DesignLanguagesTest::testTheSectionSpacingLineIsGeneratedFromTheData`, `LanguageRhythmAdapterTest::testThePathIsTheLanguagesConstant` (T0, T2, T3).
11. A render gate that passes when nothing was measured or an expectation is null; an order check that compares expectations instead of the page: `emptyRhythmRunProblems` pure tests and one synthetic departure per code (T6).
12. A type-adapter pin silently loosened instead of amended: the diff of `LanguageAdaptersTest::testTheConstantsAgree` / `testUnknownCombinationsAreNull`, `DesignLanguagesTest::testEveryGeneratedLineFitsTheCap`, `LanguageTypeBudgetTest::testEachLanguageTopicGrowsByExactlyItsTypeLine` must be exact replacements (named in T2/T3), never deletions (T2, T3).

---

### Task 0: Measure, pin the budgets, confirm the callers (pure; sonnet; first)

**Files:**
- Create: `tests/LanguageRhythmBudgetTest.php`
- Modify: none

**Interfaces:**
- Produces: the size pins every later task keeps.

- [ ] **Step 1: Re-measure on the branch head** with a throwaway scratch script (not committed) loading `tests/bootstrap.php` and `wp-plugin/src/autoload.php`: `strlen()` of `StyleGuide::markdown()`, `topicParameterDescription()`, `markdown($t)` for `typography`, `kit-rules`, `style-kits`, `controls`, `design-languages`, `layout-sizing`, every `design-language:<id>`, the largest of `StyleGuide::topics()`, plus the two data files. Expected (measured while writing this plan; STOP and report if any differs):

  | Text | Bytes |
  |---|---|
  | default guide / `topicParameterDescription()` | 28,975 / 258 |
  | `typography` / `kit-rules` / `style-kits` / `controls` / `design-languages` | 7,945 / 8,997 / 8,444 / 8,331 / 2,592 |
  | `layout-sizing` / largest topic overall | 9,972 / `layout-advanced` 9,973 (both untouched) |
  | `design-language:` editorial / glass-dark / organic-warm / tech-mono / playful-pastel | 2,026 / 2,359 / 2,354 / 2,481 / 2,667 |
  | `design-language:` corporate-trust / soft-saas / bold-vibrant / brutalist-flat / luxe | 2,788 / 3,466 / 3,227 / 2,775 / 2,963 |
  | `wp-plugin/data/section-recipes.json` / `design-languages.json` | 662,117 / 27,997 |

  The largest language topic after B: soft-saas 3,466 + 233 + 1 = about 3,700 B (`DesignLanguagesTest` asserts < 5,000; the bound is 10,000). tech-mono's prose reword (Task 2) changes its topic by a known delta, recorded in the budget table by Task 2.
- [ ] **Step 2: Write `tests/LanguageRhythmBudgetTest.php`** (green now, tightened by Task 2): `private const BEFORE_LANGUAGE_TOPICS = [ 'editorial' => 2026, ... ]` (the ten literals above, in `DesignLanguages::ids()` order); `testNothingButTheLanguageTopicsMoves` (28975, 258, `typography` 7945, `kit-rules` 8997, `style-kits` 8444, `controls` 8331, `design-languages` 2592, `layout-sizing` 9972); `testEachLanguageTopicGrowsByAtMostOneGeneratedLine` (`<= BEFORE + 261`, `<= 10000`).
- [ ] **Step 3: Confirm the caller inventory.** `git grep -n -E "LanguageResolver::(resolve|leftovers)|SectionRecipes::(recipe|resolveMarkup)\(|ServedVariants::of" -- ':!docs'`: the hits must be exactly the callers A's Task 0 table and Task 3 created (`SectionRecipes.php`, `ServedVariants.php` and its four linters, `scripts/lib/language-type-sheet.php`, `tests/LanguageResolverTest.php`, `tests/SectionRecipesTest.php`, `tests/TypeMigrationTest.php`, the `recipe()` callers of A's table). None needs a change: the language step is generic over `LanguageAdapters::KINDS`, so every caller that runs it gets rhythm for free. Any other hit: STOP, the controller extends Task 3.
- [ ] **Step 4:** `php -d memory_limit=-1 vendor/bin/phpunit --filter LanguageRhythmBudgetTest` PASS; commit.

---

### Task 1: Probe the rhythm decisions on `divi/section` (live, serial, controller-run)

**Files:**
- Create: `fixtures/shapes/candidates/language-rhythm.json`, `fixtures/shapes/language-rhythm-vocabulary.md`
- Written by the probe: `fixtures/shapes/probe-results-language-rhythm.json`
- Modify (through `php scripts/promote-shapes.php` only): `fixtures/shapes/verified-shapes.json` (evidence ids; the paths exist), `docs/shape-evidence-5.15.1.md` (the `lr-*` sections); `fixtures/shapes/dead-on-module.txt` only if a library-used path turns out DEAD on the section (none expected)

**Interfaces:**
- Consumes: the candidate format of `fixtures/shapes/candidates/layout.json` (`id`, `block: "section"`, `base`, `set` (dotted paths), `neighbours` `{before, after}`, `geometry: true`, `expect`, `note`); the image URL placeholder `@SITE@/wp-content/plugins/jhmg-ai-editor-for-divi-5/assets/images/hero-blue-1.svg` (as `backgrounds.json`); the measured section default: **56 px top and bottom at 1280, 900 and 400 px** (`fixtures/shapes/probe-results-layout.json`, `lay-spc-section-padding-scale`, control samples `padding-top: 56px -> 120px | 80px | 48px`).
- Produces: `language-rhythm-vocabulary.md` in the layout of `language-type-vocabulary.md` (what was measured, the per-candidate table, "Not a value: `syncVertical`", NOT probed by ruling), ENDING with `## Adapter decisions` and `| Decision | Verdict | Path the adapter writes | Evidence |`, verdict PASS or DEAD, exactly these six rows in this order: `section-padding`, `section-sync`, `section-image`, `section-first`, `section-later`, `section-overlap`.

- [ ] **Step 0: The default first.** The rule: a value equal to the default reads css-only by construction. 56 px is the default AND a written value (editorial and organic-warm open phone 56 / 56, editorial section phone 56, six tight desktops 56), so every language candidate starts from a base of **24 px** top and bottom at all three breakpoints (24 is no written value; the smallest is 28), WITHOUT `syncVertical` (most library sections have none), so `set` adds the toggle.
- [ ] **Step 1: Write the candidates** (all rendered at 1280/900/400; plain section base `module.decoration.background.desktop.value.color` `#f1f5f9` plus the 24 px padding unless stated):
  - `lr-section-control`: no padding base; set desktop top/bottom `120px` (PASS).
  - `lr-section-default-56`: no padding base; set top/bottom `56px` at all three breakpoints (expect `PASS(css-only)`: the default; A's `*-dead-weight-500` precedent).
  - `lr-section-sync-alone`: set `syncVertical` `on` at the three breakpoints only (expect DEAD or `PASS(css-only)`, recorded: the toggle alone is inert, as the `layout-sizing` topic says).
  - `lr-<language>-<role>` for the 10 languages x 3 roles (30, PASS): set the nine keys `module.decoration.spacing.<bp>.value.padding.{top,bottom,syncVertical}` with these values (desktop/tablet/phone; `section` = the prose, `open`/`tight` = Ruling 8 as computed above; Task 3 pins the file to `sectionSpace()`):

    | language | section | open | tight |
    |---|---|---|---|
    | editorial | 120/80/56 | 152/104/72 | 72/48/32 |
    | glass-dark | 100/72/48 | 128/88/64 | 64/40/32 |
    | organic-warm | 96/64/44 | 120/80/56 | 56/40/28 |
    | tech-mono | 88/64/40 | 112/80/48 | 56/40/28 |
    | playful-pastel | 88/60/40 | 112/72/48 | 56/40/28 |
    | corporate-trust | 96/68/44 | 120/88/56 | 56/40/28 |
    | soft-saas | 104/72/48 | 128/88/64 | 64/40/32 |
    | bold-vibrant | 80/56/36 | 104/72/48 | 56/32/28 |
    | brutalist-flat | 64/48/32 | 80/64/40 | 56/32/28 |
    | luxe | 160/112/72 | 200/144/88 | 96/64/40 |

  - `lr-section-image`: base adds `background.desktop.value.image.url` = the image placeholder (and `color`); set the editorial `open` keys (PASS; `background-image` must NOT be among the changed properties at any width).
  - `lr-section-first`: `neighbours.after` one plain dark section (`#0f172a`, 80 px); set the editorial `section` keys (PASS: the first section of the page takes it).
  - `lr-section-later`: `neighbours.before` one plain dark section; set the editorial `tight` keys (PASS).
  - `lr-section-overlap`: the `lay-neg-section-top` shape (base adds `margin.top` `-80px`, `neighbours.before` a dark 80 px section), `geometry: true`; set the editorial `section` keys (PASS; the controller compares `geometry.control.overlap.area` with `geometry.candidate.overlap.area`: the padding must not change the overlap).
  - Total **37** candidates: 34 expected PASS, `lr-section-default-56` css-only, `lr-section-sync-alone` DEAD or css-only, nothing else. Notes cite `includes/builder-5/server/Packages/StyleLibrary/Declarations/Spacing/Spacing.php:112-123` (as the layout probe's notes do).
- [ ] **Step 2: Run, one run at a time.** `pgrep -f "node scripts/verify-"` empty, then `node scripts/verify-shapes.cjs fixtures/shapes/candidates/language-rhythm.json`. Check: the control PASS and the default css-only (else STOP: the run is not trusted); every `lr-<language>-<role>` changed at `1280`, `900` AND `400` (`widths`), and its samples show `padding-top: 24px -> <desktop>` at 1280, `<tablet>` at 900, `<phone>` at 400, with `padding-bottom` equal to `padding-top` (the toggle did not override bottom); `lr-section-image` has no `background-image` in `changedProps`; the overlap area is unchanged; no `phoneOverflow`; no `aied shape probe` page remains. A control that changes nothing is a defect of the candidate: re-probe, do not freeze.
- [ ] **Step 3: Promote** `php scripts/promote-shapes.php fixtures/shapes/probe-results-language-rhythm.json fixtures/shapes/candidates/language-rhythm.json`.
- [ ] **Step 4: Write the vocabulary file.** `section-padding` PASS only when ALL 30 language candidates and the control PASSED at all three widths with top = bottom = the value; `section-sync` PASS when the 30 (which carry the toggle) computed exactly the values (the toggle never moved a side) and `lr-section-sync-alone` changed no computed value; `section-image`, `section-first`, `section-later`, `section-overlap` PASS from their candidates as stated. Fallback rule: controller ruling 7. `make test` green (registry, evidence and dead-on-module tests); commit the files.

---

### Task 2: `section_space` data, validity, the derived roles, prose pins, the Section spacing line (pure; sonnet; worktree, parallel with Task 1)

**Files:**
- Modify: `wp-plugin/data/design-languages.json` (ten `section_space` objects, each right after `section_padding`; tech-mono `section_padding` prose reword), `wp-plugin/src/DesignLanguages.php`, `tests/DesignLanguagesTest.php`, `tests/LanguageRhythmBudgetTest.php` (exact), `tests/LanguageTypeBudgetTest.php` (one amended assertion)

**Interfaces:**
- Produces (consumed by Tasks 3-6):
  - `public const SECTION_SPACE_KEYS = [ 'desktop', 'tablet', 'phone' ];` `public const SECTION_SPACE_BOUNDS = [ 'desktop' => [ 56, 200 ], 'phone' => [ 28, 120 ] ];` `public const SECTION_SPACE_PATH = 'module.decoration.spacing';` `public const RHYTHM_ROLES = [ 'section', 'open', 'tight' ];` `public const RHYTHM_FACTORS = [ 'section' => [ 1, 1 ], 'open' => [ 5, 4 ], 'tight' => [ 3, 5 ] ];` (numerator, denominator: no float)
  - `public static function sectionSpaceProblems( array $language ): array` (`[]` = fine; each line starts `section_space`): an object with EXACTLY the three keys; each an `int` (not a string, not a float, not a bool); desktop within 56-200, phone within 28-120; desktop >= tablet >= phone.
  - `valid()` appends `sectionSpaceProblems()` (required, like `type`).
  - `public static function sectionSpace( array $language, string $role ): ?array` -> `[ 'desktop' => int, 'tablet' => int, 'phone' => int ]`: null for a role outside `RHYTHM_ROLES` or a language with a `section_space` problem; `section` = the data; `open` / `tight` = the rule in the header (integer rounding to 8, half up, then the clamps, tablet into [phone, desktop]).
  - `public static function sectionSpaceLine( array $language ): string`: the template in the header, numbers from `sectionSpace()`, path from `SECTION_SPACE_PATH`; `''` when the language has a `section_space` problem.
  - `generatedLines()` returns `[ 'type' => typeLine(), 'section_space' => sectionSpaceLine() ]`; `markdown($id)` prints `generatedLines()['type']` after the fonts line (as now) and `generatedLines()['section_space']` right after `- Section padding: ...` (a private `GENERATED_PLACE = [ 'type' => 'fonts', 'section_space' => 'section_padding' ]` map drives it, so a third line cannot land in the wrong place silently).

- [ ] **Step 1: Write the failing tests** in `DesignLanguagesTest`:
  - `testEverySectionSpaceIsItsProseSentence`: each language's `section_padding` matches `/(\d+)px top and bottom on desktop, (\d+)px on tablet, (\d+)px on phone/` exactly once and the three numbers equal `section_space` (measured: all ten follow the sentence; no exception list); a planted mismatch fails.
  - `testSectionSpaceProblemsCatchesEachBrokenShape` (provider in the `brokenLanguages()` style: missing, not an object, an extra key `wide`, missing `tablet`, `"120"` string, `120.0` float, `true`, desktop 54, desktop 208, phone 24, phone 128, tablet above desktop, phone above tablet; each names `section_space.<key>`), and `testTheSectionSpaceBoundsHold` over the ten.
  - `testNoTwoLanguagesShareASectionSpaceTriple` (cap 0, no exemption file) with a planted copy.
  - `testTheDerivedRolesAreTheRuling8Table`: the 30 literal triples of Task 1's table (typed once here as `private const RHYTHM_TABLE`), each equal to `sectionSpace($lang, $role)`; `section` equals the data unrounded.
  - `testTheDerivedRolesHoldForEveryTripleInsideTheBounds`: every triple of multiples of 4 with 56 <= desktop <= 200, 28 <= phone <= 120, phone <= tablet <= desktop: `open` and `tight` are ints within the bounds, ordered desktop >= tablet >= phone, each a multiple of 8 or a clamp bound (28 / 56 / 120 / 200) or equal to the clamped neighbour, and per breakpoint open >= section >= tight; and the half case `48 x 5/4 = 60 -> 64`, `40 x 3/5 = 24 -> 24 -> 28 (clamp)` pinned.
  - `testTheShippedRolesAreStrictlyOrdered`: for the ten languages, open > section > tight at every breakpoint (measured: holds; the narrowest gap is brutalist-flat desktop 64 vs tight 56); a planted language with desktop 56 fails it (tight clamps to 56).
  - `testNoProsePromisesOnePaddingForEverySection`: no string field of any language matches `/same padding on every section/i` (tech-mono did; Spec issue 3); a planted sentence fails.
  - `testTheSectionSpacingLineIsGeneratedFromTheData`: `markdown($id)` contains `sectionSpaceLine($lang)` exactly once, as the line right after the one starting `- Section padding: `; it contains the nine numbers of the three roles, `SECTION_SPACE_PATH`, `never left or right`; `<= GENERATED_LINE_MAX`; no `{{`, `<`, backtick.
  - Amend (exact replacement, Review Focus 12) `testEveryGeneratedLineFitsTheCap`: `assertSame(['type', 'section_space'], array_keys($lines))`.
  - Amend `LanguageTypeBudgetTest::testEachLanguageTopicGrowsByExactlyItsTypeLine`: the expected size becomes `$before + strlen($typeLine) + 1 + strlen(sectionSpaceLine) + 1 + TECH_MONO_PROSE_DELTA (tech-mono only)`, with a docblock line "plan B adds the Section spacing line and the tech-mono reword (rhythm adapter, Task 2)"; tighten `LanguageRhythmBudgetTest::testEachLanguageTopicGrowsByAtMostOneGeneratedLine` to `=== BEFORE[id] + strlen(sectionSpaceLine) + 1 (+ the tech-mono delta)` and rename it `testEachLanguageTopicGrowsByExactlyItsSectionSpacingLine`.
- [ ] **Step 2: Run** `php -d memory_limit=-1 vendor/bin/phpunit --filter 'DesignLanguagesTest|LanguageRhythmBudgetTest|LanguageTypeBudgetTest'`; expect FAIL.
- [ ] **Step 3: Implement.** Data with a PHP or `python3 -I` script that rewrites the file with the same formatting (verify the diff is ONLY the ten objects and the one sentence). JSON form `"section_space": { "desktop": 120, "tablet": 80, "phone": 56 }` with the ten triples of the `section` column above. tech-mono `section_padding`: "Tight, even, repeated: the same padding on every section." -> "Tight, even, repeated: every ordinary section has the same padding." (record the byte delta as `TECH_MONO_PROSE_DELTA` in both budget tests). Update the docblocks of `get()`, `valid()` and `generatedLines()`.
- [ ] **Step 4: Run** the filter, then `make test`; expect PASS (`LanguageAdaptersTest`'s `typeProblems` checks are unaffected; `valid()` is test-only). Commit.

---

### Task 3: The rhythm kind in LanguageAdapters and LanguageResolver (opus; needs Tasks 1 and 2)

**Files:**
- Create: `tests/LanguageRhythmAdapterTest.php`
- Modify: `wp-plugin/src/LanguageAdapters.php`, `wp-plugin/src/LanguageResolver.php` (one constant entry + docblocks), `tests/LanguageAdaptersTest.php` (two exact amendments), `tests/LanguageResolverTest.php`, `tests/SectionRecipesTest.php`, `tests/Support/ServedVariants.php` (exception message only: "left a language marker"), `wp-plugin/src/SectionRecipes.php` (`recipe()` / `resolveMarkup()` docblocks name rhythm; no code)

**Interfaces:**
- `LanguageAdapters`:
  - `public const KINDS = [ 'type', 'rhythm' ];`
  - `public const APPLIED = [ 'type' => [ ...unchanged ], 'rhythm' => [ 'divi/section' => [ 'section', 'open', 'tight' ] ] ];` (`StyleGuide::typographyLanguageSentence()` reads only `APPLIED['type']`: unchanged output, its test stays green)
  - `public const RHYTHM_PATHS = [ 'divi/section' => DesignLanguages::SECTION_SPACE_PATH ];`
  - `public const RHYTHM_PROVEN = [ 'section-padding' => true, 'section-sync' => true, 'section-image' => true, 'section-first' => true, 'section-later' => true, 'section-overlap' => true ];` (exactly the vocabulary table)
  - `patch()` gains `if ( 'rhythm' === $kind ) { return self::rhythmPatch( $block, $role, $language ); }`.
  - `public static function rhythmPatch( string $block, string $role, array $language, array $proven = self::RHYTHM_PROVEN ): ?array`: null unless `$role` is in `APPLIED['rhythm'][$block]`, `DesignLanguages::sectionSpace( $language, $role )` is not null, and `section-padding`, `section-image`, `section-first`, `section-later`, `section-overlap` are true in `$proven`; value per breakpoint `[ 'padding' => [ 'top' => "<n>px", 'bottom' => "<n>px", 'syncVertical' => 'on' ] ]` (`syncVertical` only when `section-sync` is true), nested as `[ 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => ... ], 'tablet' => ..., 'phone' => ... ] ] ] ]` built from `RHYTHM_PATHS[$block]`. Key order: desktop, tablet, phone; top, bottom, syncVertical.
- `LanguageResolver::LEFT = [ 'type' => '{"aied":{"type":...}}', 'rhythm' => '{"aied":{"rhythm":...}}' ];` Nothing else changes in its code (it is generic over `KINDS`; `leftovers()` already loops over `KINDS`).

- [ ] **Step 0:** the A Step 0 hash discipline, narrowed: a scratch script prints the sha256 of the concatenation of `SectionRecipes::recipe($n, $lang, $kit)` for every recipe under every language with its own kit; run before and after Step 3 and require the same hash (nothing is migrated yet, so adding the kind changes no served byte). Report both runs.
- [ ] **Step 1: Write the failing tests.**
  - `LanguageRhythmAdapterTest` (helpers like `LanguageAdaptersTest`: `vocabularyDecisions()` over `language-rhythm-vocabulary.md`, `probed()` over `candidates/language-rhythm.json` + `probe-results-language-rhythm.json`, `leaves()`): `testRhythmProvenMatchesTheVocabularyTable` (six rows, order, all true), `testEveryLeafIsAProbedPathOnTheSection` (10 x 3; a planted `padding.left` leaf fails), `testEveryLanguageRoleWasProbed` (for every (language, role) a PASS candidate `lr-<language>-<role>` whose `set` equals the patch's leaves exactly, i.e. the candidates file IS `sectionSpace()`), `testThePatchWritesTopBottomAndSyncOnly` (leaf set exactly `module.decoration.spacing.{desktop,tablet,phone}.value.padding.{top,bottom,syncVertical}`, values `/^[1-9][0-9]*px\z/`, top === bottom), `testThePatchIsTheLanguagesRhythm` (values equal `sectionSpace()` + `px`), `testTheSyncFallbackOmitsTheToggle` (planted `section-sync => false`: same values, no `syncVertical`), `testUnprovenPaddingRefusesTheSection` (planted `section-padding`, then `section-image`, ... `=> false` -> null each), `testUnknownCombinationsAreNull` (`section` on `divi/row`, `open` on `divi/heading`, role `hero`, kind `kit`, a language with a `section_space` problem; a language with a TYPE problem still resolves rhythm, and vice versa), `testThePathIsTheLanguagesConstant` (`RHYTHM_PATHS['divi/section'] === DesignLanguages::SECTION_SPACE_PATH` and `sectionSpaceLine()` contains it), `testPatchValidatesInsideARealLayout` (`LayoutGate::check` on a section with the patch holding a row/column/text, every language and role), `testTheRhythmPatchesAreNotDeadOnTheSection` (`DeadOnModule::load()->violations()` over every resolved section; a planted `divi/section|module.decoration.spacing.desktop.value.padding.top` line fails, scoped to that carrier), `testThePatchIsDeterministic`.
  - `LanguageAdaptersTest` amendments (exact replacements, Review Focus 12): `testTheConstantsAgree` pins `KINDS === ['type', 'rhythm']` and the full `APPLIED` with the rhythm entry; `testUnknownCombinationsAreNull` line 351 becomes `assertNull( patch( 'divi/heading', 'rhythm', 'display', $lang ) )` (a heading takes no rhythm role: still null for the right reason) plus `assertNull( patch( 'divi/section', 'type', 'display', $lang ) )`.
  - `LanguageResolverTest`: `testEachRhythmRoleResolvesAndIsStripped` (each role, every language: the patch wins over literal top/bottom at every breakpoint, `background`, `border` and an `advanced.dividers` block keep their keys and order, `aied` gone), `testRhythmMarkersTheResolverDoesNotKnowAreLeftAndReported` (`rhythm` on a row, a column, a heading; role `hero`; `{"rhythm":"open","on":"dark"}`; `{"rhythm":"open","type":"display"}`; `{"rhythm":"open","kit":"card"}`; a non-string role: byte-identical, `leftovers()` lists `LEFT['rhythm']` (and `LEFT['type']` where both keys are there, in order of first appearance)), `testATypeAndARhythmMarkerResolveInOnePass` (a marked section holding a marked heading: both resolved, both stripped), `testARhythmMarkerNeverReachesTheKitStep` (adapter spy as A's; an unresolvable rhythm marker makes `resolveMarkup()` null BEFORE the kit step).
  - `SectionRecipesTest` (A's `plantMarkedRecipe()` trick, planting a section): `testARhythmMarkedSectionIsServedInTheLanguagesRhythm` (each language), `testAnUnresolvableRhythmMarkerIsNeverServed` (`tight` on a row, an extra key: `recipe()` null), and extend `testTheStyleGuideKitSamplesCarryNoTypeMarker` to `"rhythm"` (rename `testTheStyleGuideKitSamplesCarryNoLanguageMarker`).
- [ ] **Step 2: Run** `php -d memory_limit=-1 vendor/bin/phpunit --filter 'LanguageRhythmAdapter|LanguageAdapters|LanguageResolver|SectionRecipesTest|ShapeRegistry|RecipeModuleSettings|StyleGuideLanguageType'`; expect FAIL.
- [ ] **Step 3: Implement.** Re-run the Step 0 hash: identical.
- [ ] **Step 4:** `make test` and `make test-pro`; PASS, every golden byte-identical. Commit.

---

### Tasks 4, 5 and 6 need Task 3 and may run in parallel (separate worktrees off the Task 3 head, max 3 workers). Task 4's audit half (Steps 1-3) only needs Task 0 and may start beside Tasks 1 and 2. After merging, the controller re-runs `make test`.

### Task 4: RhythmAudit, the migration report, the rhythm golden and the freeze (sonnet)

**Files:**
- Create: `tests/Support/RhythmAudit.php`, `tests/RhythmAuditTest.php`, `scripts/rhythm-migration-report.php`, `tests/LanguageRhythmGoldenTest.php`, `tests/golden/language-rhythm-patches.json`
- Modify: `Makefile` (`rhythm-report` target + `.PHONY` + a comment in the `type-report` style), `tests/KitGoldenFreezeTest.php`

**Interfaces:**
- `RhythmAudit::sections( array $recipes ): list<array{recipe:string,index:int,desktop:?int,tablet:?int,phone:?int,role:?string,marked:bool,exempt:?string}>`: every `divi/section` (index = its order among the recipe's sections; every recipe has exactly one today), `role` by the classification rule of this plan's header (`BASE = 112`, `BAND = [ 0.45, 1.6 ]`) or by its marker when marked (the list is the same before and after Task 5), `exempt` = the reason when the key is in `EXEMPTIONS`. Also `RhythmAudit::key()` (`recipe|divi/section|index`) and `RhythmAudit::role( int $desktop ): ?string` (public, for the planted tests).
- `public const EXEMPTIONS` (shrink-only, `EXEMPTIONS_MAX = 25` asserted), one reason per key:
  - `%`/`vw` (Ruling 10): `hero-cta` (8% desktop only), `card-grid-4` (8vw);
  - `0px` edge (Ruling 10, anti-goal 4): `hero-image-scrim` (0/0: the photo fills the section), `image-gallery` (0 top, 100 bottom: flush with the section above), `transition-overlap-panel` (0 top: the panel is pulled over the band above);
  - asymmetric: `section-intro` (16 px top only), `hero-editorial-minimal` (180/140), `hero-product-glow` (120/96), `header-title-band` (72/64), `header-divider-intro` (112/168 with a 100 px curve divider), `header-image-band` (120/104);
  - no desktop padding, 0px on phones (the old alpha shape): `blurb-grid`, `icon-features`, `testimonial`;
  - no padding (Divi's default, 56 px at every width; a marker would change a recipe that never chose a rhythm): `card-grid-3`, `split-image-text`, `contact-form`, `slider`, `image-carousel`, `newsletter-social`, `shop-grid`;
  - desktop only (Divi cascades it to tablet and phone): `stats-counter` (40), `blog-feed` (100), `icon-values` (100);
  - out of band: `proof-rating-strip` (32/28/28 px, a hairline strip thinner than any tight value a language gives, 56/32/28 at least).
- `scripts/rhythm-migration-report.php` (dev-only, pure; `php scripts/rhythm-migration-report.php`): one line per section `recipe | section#index | D/T/P | role | MARKED|- | exempt reason`, then `section 38, open 7, tight 1, exempt 25`.
- `tests/golden/language-rhythm-patches.json`: keys = language ids in `DesignLanguages::ids()` order, each `{ "section_space": <the data>, "patches": { "divi/section|section": ..., "divi/section|open": ..., "divi/section|tight": ... } }` = 30 patches from `LanguageAdapters::patch()`, pretty JSON + trailing newline, the `language-type-patches.json` format.

- [ ] **Step 1: Write the failing audit tests** in `RhythmAuditTest` (green on the UNMIGRATED file and, unchanged, after Task 5): `testTheAuditFindsTheLibrarysSections`: 71 sections in 71 recipes; **46 classified: section 38, open 7, tight 1**; the 25 `EXEMPTIONS` exactly; nothing else. The pinned table (measured at 2b40d59, desktop/tablet/phone):
  - open (7): hero-pattern-centered 160/112/72, quote-single-large 140/104/72, quote-over-image 160/120/88, founder-note 128/96/72, story-timeline 128/96/72, cta-card-over-image 160/120/72, transition-wave-band 152/120/80
  - tight (1): proof-logo-wall 80/64/48
  - section (38): hero-split-right, hero-split-left-overlap, hero-gradient-stats, hero-spec-card (120/88/56), hero-bordered-cells (96/72/48), stats-big-numbers (120/88/64), cta-band (96/80/64), and at 112/88/64: features-bento, features-zigzag, list-icon-dividers, checklist-two-column, steps-numbered, steps-horizontal, timeline-vertical, features-accordion-image, compare-two-panels, features-carousel-strip, proof-press-quotes, stats-counter-band, stats-circle-counters, stats-big-captions, testimonials-slider-quote, testimonials-avatar-grid, pricing-three-tier, pricing-two-plan-highlight, pricing-comparison-table, team-grid, values-list, faq-accordion, faq-two-column, faq-toggle-list, cta-split-form, cta-countdown, gallery-masonry-style, portfolio-featured-grid, before-after-compare, contact-location-form, contact-details-form
  - by recipe role: hero section 5 / open 1; features section 7; process section 3; stats section 4; proof section 1 / tight 1; testimonials section 2 / open 2; pricing section 3; team section 1 / open 1; content section 1 / open 1; faq section 3; cta section 3 / open 1; gallery section 3; contact section 2; transition open 1.

  Also `testTheRoleRuleIsTheNearestFactor` (`role(126)` open, `role(125)` section, `role(87)` section, `role(86)` tight, `role(50)` null, `role(51)` tight, `role(179)` open, `role(180)` null), `testTheAuditClassifiesPlantedSections` (an asymmetric, a `%`, a desktop-only, a no-padding, a hover-state and a margin-carrying section are not candidates) and `testTheExemptionsAreRealSectionsAndUnmarked`.
- [ ] **Step 2: Run** `--filter RhythmAudit`; FAIL; implement `RhythmAudit` and the report; PASS. `make rhythm-report` prints the table and the totals.
- [ ] **Step 3: Commit** the audit half.
- [ ] **Step 4 (needs Task 3): the golden.** `LanguageRhythmGoldenTest::testEveryLanguageResolvesItsRhythmByteIdentically` (10 entries, 30 patches, each equal to `LanguageAdapters::patch()`, `section_space` equal to the data; docblock says how to regenerate deliberately); generate ONCE with a scratch script; review by eye (luxe open desktop `200px`, four languages with tight `56px/40px/28px`, `syncVertical` on every breakpoint).
- [ ] **Step 5: the freeze.** `KitGoldenFreezeTest::FROZEN` gains `language-rhythm-patches.json` => its sha256 (last entry); `assertCount( 8, self::FROZEN )`; docblock updated. `make test`; commit.

---

### Task 5: Migrate the 46 sections (opus)

**Files:**
- Create: `scripts/migrate-rhythm-markers.php` (dev-only, pure, idempotent), `tests/RhythmMigrationTest.php`
- Modify: `wp-plugin/data/section-recipes.json` (ONLY through the script), `fixtures/dogfood/edit-glass-edge/page.html`, `fixtures/dogfood/corpus.json` (the edit base's `sha256`), `tests/DogfoodPrepareTest.php` and `tests/DogfoodCorpusTest.php` (`EDIT_BASE_SHA256`, the 54,680-byte size: the edit base holds pricing-three-tier, faq-accordion and team-grid under glass-dark, all three marked `section`)

**Interfaces:**
- Consumes: `RhythmAudit::sections()` / `EXEMPTIONS` (T4), `SectionRecipes::resolveMarkup()`.
- Produces: one marker per classified section, `"aied":{"rhythm":"<role>"}`, APPENDED as the last top-level key; each marked section drops `top`, `bottom` and `syncVertical` from `module.decoration.spacing.<bp>.value.padding` at every breakpoint, then any object that became empty (`padding`, `value`, the breakpoint, `spacing`; measured: all 46 lose their whole `spacing` object); nothing else changes.

- [ ] **Step 1: Write the failing tests** in `RhythmMigrationTest` (mirror `TypeMigrationTest`'s helpers: `rhythmMarker( array $attrs ): ?string` (throws on any other key), `sections()`, `leaves()`):
  - `MIGRATED` = the 46 keys of Task 4's table as `"<recipe>|divi/section|0" => "<role>"` literals (generated once from `RhythmAudit` by a scratch script and pasted); `BESPOKE_RHYTHM = []`, `BESPOKE_RHYTHM_MAX = 0`; `testTheMarkersArePinnedAndNothingIsBespoke` (parsed markers == `MIGRATED`; `substr_count` of `"aied":{"rhythm":` == 46; every unmarked section is in `RhythmAudit::EXEMPTIONS`).
  - `testMarkedSectionsCarryNoPaddingOfTheirOwn` (no `top|bottom|syncVertical` leaf under `module.decoration.spacing` of a marked section, raw markup).
  - `testMarkedSectionsKeepEverythingElseUnderEveryLanguage` (the merge trap; 46 recipes x 10 languages with their own kit: in `resolveMarkup()` output the marked section has exactly the patch's leaves under `module.decoration.spacing` and, for every OTHER leaf, the value of the raw block after `RecipeTokens::resolve` with the same language; no `aied`).
  - `testTheExemptionsAreExactlyTheNamedSections` (still unmarked, still carrying their literal padding, or none).
  - `testDividersFitTheirSectionsPaddingUnderEveryLanguage`: for every marked section with `module.advanced.dividers.{top,bottom}`, the divider height at each breakpoint (desktop value, then the tablet/phone override, cascading) is <= the resolved padding of that edge at that breakpoint under every language (measured: transition-wave-band, open, dividers 80/56/32 against the smallest open 80/64/40 of brutalist-flat: fits); a planted 90 px divider fails.
  - `testEveryMigratedRecipeIsServedValidUnderEveryLanguage` (46 x 10: `LayoutGate::check` valid, no `"aied"`, no `{{aied:`, both leftovers empty) and `testNoMarkerTokenOrLeftoverReachesServedMarkup` (all 71, default look and 10 languages).
  - `testRecipesFileIsSmallerThanBeforeTheRhythmMarkers` (`< 662117`).
  - `testRhythmMarkedRecipeProseAgreesWithItsRole`: the title/description/when of a marked recipe never name a padding number (`/\b\d+\s?px\b[^.]*\bpadding\b|\bpadding of \d+/i`), and a `section` or `tight` recipe never promises `tall padding` / `generous padding` (measured: only quote-over-image says "tall padding", and it is `open`).
  - Planted both ways: `testAnUnmigratedRecipeFailsEveryCheckAndTheCleanShapePasses`, `testTheMergeTrapCatchesStraysAndOverrides` (a stray tablet `top`, a changed background, a leftover marker each fail).
- [ ] **Step 2: Write `scripts/migrate-rhythm-markers.php`** (`php scripts/migrate-rhythm-markers.php [--check]`), in the shape of `migrate-type-markers.php`: per classified, unmarked, unexempt section assert that `json_encode( json_decode($json), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )` reproduces the original bytes (measured: all 71 sections do), drop the keys, append `aied`, re-encode with the same flags, refuse a result containing `--`; write the file with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` + newline (the file round-trips byte-identically; checked first). `--check` exits 1 when anything would change; a second run changes nothing. No prose rewording is needed (measured; the test above guards it).
- [ ] **Step 3: Migrate.** Run the script; the file must be **652,430 B** (simulated while writing this plan; a different number is reported, not forced).
- [ ] **Step 4: Re-pin the dogfood edit base** exactly as A's Task 5 Step 4 (`Prepare::editBase('glass-edge','glass-dark')`, `EDIT_BASE_SHA256` and the byte size in both tests, `corpus.json`); `testTheEditBaseScoresFullMarks` stays green with the same scores (the scorer reads no section padding, Ruling 12).
- [ ] **Step 5: `make test`** (incl. `RhythmAuditTest` (same 46 + 25, now by marker), `TypeMigrationTest`, `TypeAuditTest`, `RecipeLibraryTest` (kit selectors unchanged: `"aied":{"kit"`), the kit migration tests, `ShapeRegistryTest` through `ServedVariants`, `DesignVariety*Test`, `NoCustomCodeTest`, `Dogfood*Test`), `make rhythm-report` (46 MARKED), `make type-report` and `make card-report` (unchanged); commit.

---

### Task 6: Rhythm in the render gate `make verify-language-type` (sonnet; needs Task 3; pure parts only)

**Files:**
- Create: `scripts/lib/language-rhythm-checks.cjs` (pure), `scripts/lib/language-rhythm-run.cjs` (`checkRhythm(page, { width, expects })`), `scripts/test-verify-language-rhythm.cjs` (pure tests), `scripts/test-language-rhythm-run.cjs` (real Chromium, synthetic pages)
- Modify: `scripts/verify-language-type.cjs` (the page gets the rhythm sections after the type section; `checkRhythm` per width; `rhythmChecked`; `dist/type-sheet/rhythm-observations.json`; the header comment lists the rhythm codes; the index page text), `Makefile` (`verify-language-type` runs the two new test files after A's two)

**Interfaces:**
- `RHYTHM_SLOTS` (in document order, after the type section): `rhythm-section` (role `section`, band `{{aied:color:surface}}`, text `Rhythm band, section`), `rhythm-open` (`open`, `bg`, `Rhythm band, open`), `rhythm-tight` (`tight`, `surface`, `Rhythm band, tight`), `rhythm-image` (`open`, a background image `{{aied:image:hero-blue-1}}` with the `bg` colour, `Rhythm band over a photo`). Each: `divi/section` with the background, NO padding of its own and `"aied":{"rhythm":"<role>"}` as the last key, one row, one column, one `divi/text` with the unique text. `rhythmSheetMarkup()` returns the four; the gate builds the page from `T.sheetMarkup() + '\n' + R.rhythmSheetMarkup()` through the same `SectionRecipes::resolveMarkup()` call (`language-type-sheet.php` is unchanged: it reads any markup on stdin).
- Expectations from the RESOLVED markup only: `expectRhythmFromAttrs(attrs)` = `{ desktop:{top,bottom}, tablet:{...}, phone:{...} }` in px read from `module.decoration.spacing.<bp>.value.padding` (a breakpoint without a value is a hole, never "inherited"); `bpOf(width)` = 1280 desktop, 900 tablet, 400 phone.
- Codes: `rhythm` (a slot missing or twice; a run that checked no rhythm slot fails), `rhythm-expectation` (a hole: fail-closed), `rhythm-padding` (computed `padding-top` and `padding-bottom` of the slot's `.et_pb_section`, 0.5 px tolerance), `rhythm-order` (MEASURED open > section > tight, top and bottom, at every width; plus the same on the expectations as a pure cross-check), `rhythm-image` (the image slot's computed `background-image` is not `none`), and the existing `overflow`, `php-error`, `console-error`, `empty`, `gate`. (`rhythm` / `rhythm-expectation` / `rhythm-image` are additions to spec section 7's list, Spec issue 10.)
- `emptyRhythmRunProblems({ rhythmChecked })`; the gate's summary fails when `rhythmChecked` is 0.

- [ ] **Step 1: Pure tests first** (`scripts/test-verify-language-rhythm.cjs`): per code a match, a mismatch, the tolerance, a missing element, a twice-found slot, a null expectation; `bpOf`; `rhythm-order` on equal values (fails) and on a tablet-only inversion (fails); `emptyRhythmRunProblems`; and offline: `node -e` the two sheets into `php scripts/lib/language-type-sheet.php`: all 10 languages resolve with zero leftovers and a valid gate, and the expectations read from the markup equal `DesignLanguages::sectionSpace()` for each (a cross-check through a tiny `php -r`, not the source). RED then GREEN.
- [ ] **Step 2: Synthetic departures** (`scripts/test-language-rhythm-run.cjs`, Playwright from `PLAYWRIGHT_PATH`, no dev site; the `test-language-type-run.cjs` pattern): synthetic `.et_pb_section` pages with media queries at Divi's breakpoints (tablet `max-width: 980px`, phone `max-width: 767px`) generated from the editorial and luxe expectations, so an agreeing page passes at 1280/900/400; one departure per code, each caught with that code: a wrong desktop top, a wrong tablet bottom, the desktop value leaking to the phone, open rendered smaller than section, a slot missing, a slot twice, the photo missing, a page wider than the window.
- [ ] **Step 3:** `node scripts/test-verify-language-rhythm.cjs`, `node scripts/test-language-rhythm-run.cjs`, A's two test files, `make test`; do not touch the dev site; commit.
- [ ] **Step 4 (live, controller, main checkout on `feat/rhythm-adapter`):** `make verify-language-type`: 10 languages x 3 widths x (5 type + 4 rhythm) slots, 0 FAIL; `--clean-orphans` finds 0.

---

### Task 7: Docs, token measurement, outcome (sonnet)

**Files:**
- Modify: the spec (`## Outcome`, Adapter B part), `CLAUDE.md` (a compact "Rhythm adapter" paragraph after the "Type adapter" one; the Entry points table: the `make verify-language-type` row names the rhythm codes, a `make rhythm-report` row), `docs/token-usage.md` (`php scripts/measure-tokens.php`), `fixtures/shapes/language-rhythm-vocabulary.md` (final pass), the docblocks of `LanguageAdapters`, `LanguageResolver`, `DesignLanguages::generatedLines()`.

- [ ] **Step 1:** the Outcome from measured facts only: probe counts (37 candidates; css-only / DEAD results); `RHYTHM_PROVEN`; the derivation rule and the 30-cell table; the marker counts (46: section 38, open 7, tight 1) and the 25 exemptions by reason; golden (30 patches) and the freeze (8 hashes); all byte sizes before/after; suite counts; the controller's live numbers; Rulings 8-10 and 12 restated; deviations (`sectionSpace()` in `DesignLanguages`, `GENERATED_PLACE`, the line names the path, the three extra gate codes, the tech-mono prose, the dogfood re-pin); known limits (Spec issues 6-9: derived triples that collide, the brutalist-flat tight step of 8 px, a hand-written page that ignores the line, LandingGuide's vw advice, sections with no padding stay Divi's default, horizontal padding and rows untouched, the dogfood scorer does not score rhythm); owner decisions left (merge and release; migrating blog-feed and icon-values; whether heroes at 120 px should be `open`).
- [ ] **Step 2:** `make test`, `make test-pro`, `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` empty; commit. (Memory is the controller's.)

---

### Task 8: Final live gates and the whole-branch review (controller, live, serial)

From the MAIN checkout with `feat/rhythm-adapter` checked out. Before and after each: `pgrep -f "node scripts/verify-"` empty, `git branch --show-current` = `feat/rhythm-adapter`.

- [ ] `make verify-shapes`: OK; remove any stray untracked `probe-results-known.json`.
- [ ] `make verify-language-type`: 0 FAIL at all three widths for all 10 languages (type AND rhythm slots); `--clean-orphans` finds 0.
- [ ] Narrowed recipe render, ONE chunk at a time, under ALL 10 languages (`node scripts/verify-recipes.cjs --names <chunk> --languages editorial,glass-dark,organic-warm,tech-mono,playful-pastel,corporate-trust,soft-saas,bold-vibrant,brutalist-flat,luxe`): the 46 rhythm-marked recipes in four chunks (12, 12, 11, 11, catalog order, from `make rhythm-report`, recorded), then 5 exempt guard recipes chosen with `php -r 'mt_srand(20261025); ...'` over the 25 exemptions (recorded); 0 FAIL, 0 KNOWN-FAIL, `fixtures/shapes/recipe-baseline.txt` still empty. Watch brutalist-flat (tight 56/32/28 desktop, section 64) and the overlap-adjacent recipes hero-split-left-overlap and hero-gradient-stats at 400 px. A FAIL goes back to the implementer: a role or an exemption is amended with a reason, never an allowance.
- [ ] Narrowed kit smoke: `node scripts/verify-kits.cjs --kits quiet-solid,raw-frame --languages editorial,brutalist-flat`: 0 FAIL (no kit code changed).
- [ ] Plugin Check on the dev copy (spec section 9: cheap, at the end of B; Docker env): no errors; report.
- [ ] `php -d memory_limit=-1 vendor/bin/phpunit` and `make test-pro` (2169) green; `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` empty.
- [ ] The final whole-branch review on opus against the Review Focus list (each line's test exists and fails on its planted case), one fix wave, one scoped re-review; then `git merge --no-ff feat/rhythm-adapter` into LOCAL main (no push); CLAUDE.md and memory updated.

---

## Parallelism map

| Phase | Work | Who | Can run beside |
|---|---|---|---|
| A | T0 (budgets, callers) | sonnet | nothing (short, first) |
| B | T1 probe | controller, live | T2 (worktree), T4 Steps 1-3 (worktree) |
| B | T2 data, derivation, line | sonnet | T1, T4 audit half |
| B | T4 Steps 1-3 (RhythmAudit, report) | sonnet | T1, T2 |
| C | T3 adapter + resolver | opus | nothing (needs T1 + T2) |
| D | T4 Steps 4-5 (golden, freeze), T5 migration, T6 gate (pure) | sonnet / opus / sonnet | each other (3 workers) |
| E | T6 Step 4 live, T7 docs | controller / sonnet | each other |
| F | T8 | controller, then opus review | nothing |

At most 3 implementers at once; one live run on the dev site at a time; merge each worktree into `feat/rhythm-adapter` in task order and re-run `make test` after each merge. T4's audit half and T5 both read `RhythmAudit`: T5 starts from the merged T4 Step 3.

---

## Spec issues found

The plan does not silently deviate on any of these; each has the plan's default in brackets. The controller settles 1-5 before Task 2.

1. **Counts.** The spec says "about 40 section markers"; the rule gives **46** (section 38, open 7, tight 1). The spec's "lists END small" meets **25** exemptions in six reason classes (no padding 7, asymmetric 6, 0 px edge 3, desktop only 3, old alpha shape 3, `%`/`vw` 2, out of band 1). Ruling 10 names asymmetric, `%`, `vw` and `0px`; the "no padding" and "desktop only" classes come from the controller's brief ("missing => literal"). [25, cap asserted; owner option: mark blog-feed and icon-values (100 px desktop only) `section`, which gives them tablet and phone steps they lack today.]
2. **The role rule.** The spec's "open (a hero or closing band)" suggests hero => open by recipe role; that would move the five 120 px heroes and editorial's heroes from 120 to 152 px. [Nearest factor to the recipe's own value over 112 px: a 120 px hero is 1.07x and stays `section`; only hero-pattern-centered (160) is `open`. The role tag is reported, not used.]
3. **tech-mono's prose** says "the same padding on every section", which `open`/`tight` contradict. [Prose reworded to "every ordinary section has the same padding" (the sentence the parser reads is untouched); a test bans the old claim.]
4. **`syncVertical: "on"`** (spec section 2) is, by the style guide's own probe, an inert builder toggle; library sections mostly omit it. [Written, probe-guarded; fallback: omitted.]
5. **proof-rating-strip (32/28/28)** would become a 56-96 px band under `tight`. [Exempt as out of band (below 0.45 x 112).]
6. **A's code places every generated line under `## Fonts`** and two A tests pin `['type']` / "exactly its Type line". The spec (and A's plan note) want the Section spacing line under `## Shape, space and depth`. [`GENERATED_PLACE` map; the two A pins amended by exact replacement, Review Focus 12.]
7. **Clamping erases differences in derived triples:** tight is 56/40/28 for organic-warm, tech-mono, playful-pastel and corporate-trust; open is 128/88/64 for glass-dark and soft-saas; brutalist-flat tight desktop (56) is only 8 px under its section (64). The spec's visible-difference rule covers `section_space` only (all ten distinct). [Known limit; strict order is tested.]
8. **No room for a rhythm sentence in `layout-sizing`** (9,972 of 10,000 B). [The Section spacing line names the path itself, 205-239 B.]
9. **`LandingGuide` teaches "generous section padding (5-8vw)"**, which disagrees with the languages' px rhythm. Out of the spec's scope. [Untouched; listed for the owner.]
10. **Gate codes.** Spec section 7 lists `rhythm-padding`, `rhythm-order`, `overflow`. [Adds `rhythm` (count / nothing checked), `rhythm-expectation` (fail-closed, A's pattern) and `rhythm-image` (the spec's own "a section with a background image keeps it").]
11. **`DesignLanguages` cannot read `LanguageAdapters`** (A keeps it dependency-free). [`sectionSpace()` and `SECTION_SPACE_PATH` live in `DesignLanguages`; `LanguageAdapters` points to them; a test pins the identity.]
12. **The dogfood edit base** holds three marked sections (pricing-three-tier, faq-accordion, team-grid) under glass-dark (100/72/48 instead of 112/88/64). Not in the spec. [Re-pinned in Task 5 Step 4; scores unchanged.]
13. **Divi's section default is 56 px at every width**, which equals several written values. [Language candidates start from a 24 px base; the default check is css-only.]
14. **Overlap recipes.** The only section with a 0 px edge is exempt (transition-overlap-panel); hero-split-left-overlap and hero-gradient-stats are symmetric (their overlaps are negative margins INSIDE the row) and are marked `section`. [Marked; the narrowed recipe render at 400 px under brutalist-flat is the guard; a FAIL makes them exemptions with a reason.]
15. **Spec "desktop >= tablet >= phone after rounding"** needs an explicit tablet clamp (a phone clamped up to 28 can pass a rounded tablet of 24). [Tablet clamped into [phone, desktop]; the exhaustive test covers it.]
