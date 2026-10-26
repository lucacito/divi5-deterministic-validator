# Pro Design Switch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Pro tool `switch_design` that restyles existing Divi 5 pages and Divi Projects from one design (design language + style kit) to another DETERMINISTICALLY: kit markers, type and rhythm markers, colour and font tokens and the media-radius token are recovered from the saved page under the source design and the page is re-resolved by the free plugin's own pipeline under the target design; previewed (dry run + `PreviewToken`), gated (`LayoutGate::check($new, $stored)`), saved through `HistoryService::write`, undoable as ONE batch of `post_content` items; MCP + REST only.

**Architecture:** A pure engine in `pro-addon/src/Design/` (scan and bands, leaf matching, marker recovery, context-based role reverse, detection, orchestration) that calls only public free classes; the tool `pro-addon/src/Tools/SwitchDesign.php` on the `FindReplaceSite` template; proofs in the Pro suite against the free recipe library (byte identity with the direct build, named difference classes D1-D5 with pinned counts, identity, idempotence, round trip, gate validity, and the dev scorer as an independent oracle); a live gate that saves, renders, compares and undoes on the dev site.

**Tech Stack:** pure PHP 8 (Pro add-on), PHPUnit (`make test-pro`, `php -d memory_limit=-1 vendor/bin/phpunit -c pro-addon/phpunit.xml --filter X`), Node + Playwright for the live render (controller only).

**Spec:** `docs/superpowers/specs/2026-10-25-pro-design-switch-design.md` (sections 1-11). Templates: `pro-addon/src/Tools/FindReplaceSite.php` (preview/token/batch/skip reasons), `docs/superpowers/plans/2026-10-13-pro-bulk-adjust-prices.md` (Pro write-tool plan, live gate shape), `docs/superpowers/plans/2026-10-24-carriers-adapter.md` (plan format). Code to mirror: `tests/Support/Dogfood/Expected.php` (one-block resolved expectations), `tests/Support/Dogfood/Page.php` (bands D3, `colourKey`), `SectionRecipes::resolveMarkup()`, `BlockAttrs`, `PostContentKind::itemFor()`, `PreviewToken`, `BatchLog::record()/attach()`, `ProOpenApi::MCP_REST_ONLY`, `pro-addon/tests/FreeApiCompatTest.php`, `scripts/verify-pro-bulk-adjust-prices.php` and `scripts/dev-guard.php`, `scripts/verify-kits.cjs` (Chromium measuring).

The plan writer's throwaway prototype (scratchpad `proto.php` / `proto2.php`, about 300 lines, NOT committed) measured every number below; Task 0 re-measures with committed code paths and STOPS on a difference larger than the stated tolerance.

Branch: the controller's (default `feat/pro-design-switch` off local main 6d8ae9a), worktree `/Users/Lucas/Documents/JHMG-Local/wt-pro-design-switch` for implementers. `git merge --no-ff` into LOCAL main at the end. NO push, release, Pro version bump, `Compat::MIN_FREE` change, zip build, store release or SVN (Ruling 8 of the spec).

## Controller rulings (2026-10-25; WHERE THIS SECTION CONFLICTS WITH THE TEXT BELOW, THIS SECTION WINS)

1. **Zero free-plugin change.** `git diff main -- wp-plugin src jhmg-ai-editor-for-divi-5.zip` stays EMPTY. Every free symbol the engine uses is public today and is pinned in `pro-addon/tests/FreeApiCompatTest.php`; the five private `KitResolver` constants are COPIED into `Design\MarkerSpace` and pinned equal through `ReflectionClassConstant` (Task 1).
2. **The engine never builds a patch itself.** Every expected patch is the free pipeline's output for a one-block marker markup (`SectionRecipes::resolveMarkup`), exactly as `tests/Support/Dogfood/Expected.php` does; re-application is `SectionRecipes::resolveMarkup()` of the unresolved page. No adapter logic, colour table or merge rule is re-implemented in Pro.
3. **Collisions** (spec 5.3): exact when one role has the value; else the library context (derived at run time from `SectionRecipes::rawMarkup` under a synthetic palette); unanimous context = exact; majority context = `assumed` (reported per block); otherwise `ambiguous` (left). A collision whose roles all give the same TARGET value is exact.
4. **"Indistinguishable is switched"**: a block that fully matches a candidate is switched as that candidate (D2, D4), reported only when a smaller candidate also matched with a different target patch.
5. **Full matches only in `leave` (default)**; `conform` is Task 7 and opt-in.
6. **Named difference classes D1-D5** (spec section 6) are the ONLY accepted differences from the direct build. Every proof classifies each difference with ONE pure classifier (`pro-addon/tests/Support/DesignDiffClassifier.php`); an unclassified difference fails. Identical counts are pinned EXACTLY; each Dn count is a shrink-only cap (lowering a cap is a deliberate edit with the new measurement in the commit message; raising one is a STOP).
7. **STOP conditions** (report to the controller, do not work around): Task 0 numbers off by more than 1% (identical counts) or any D-class that is not in the table; a free symbol the engine needs is not public; a proof needs a baseline file; `LayoutGate` refuses any switched library output.
8. **Tool count**: Task 8 reads the current `Tools::all()` count at its start (21 today; 22 if `check_page_render` of `2026-10-25-pro-render-check.md` has merged) and moves every pin by exactly one; `switch_design` joins `ProOpenApi::MCP_REST_ONLY` (16 free + 14 Pro OpenAPI operations = 30, measured).

## Global Constraints

- **The validator is deterministic; no AI/LLM call anywhere in this work** (engine, tool, tests). `src/` and `wp-plugin/validator/` untouched.
- **No Divi schema from memory**: the engine writes NO attribute path of its own. Paths come from the free resolvers (Ruling 2) or are the ones a page already holds. The only literal a Pro file may carry is the five copied constants (pinned).
- **No custom CSS/JS/PHP**: every output goes through `LayoutGate::check()` (which runs `CodePolicy`) before a preview says `valid` and before a save.
- **WordPress.org compliance**: nothing in `wp-plugin/` changes (Ruling 1); the free guides never mention the switch; `tests/WpOrgComplianceTest.php` untouched and green.
- **Pro safety**: no `wp_remote_*`, no meta writes, no raw SQL in the new files (`pro-addon/tests/ProSafetyTest.php` must stay green unchanged); every new `pro-addon/src/**/*.php` is `require_once`d in `pro-addon/src/autoload.php` (strict_types, `ABSPATH` guard, like its neighbours).
- **Determinism**: no clock, random number, locale or hash-order dependence in `Design\*` (the token and batch are the tool's); the same input gives the same bytes (asserted).
- Suites: free `make test` green and unchanged in count; Pro `make test-pro` green (2169 at 6d8ae9a; each task states its delta). Heavy matrix tests must keep the WHOLE Pro suite under 4 minutes on the controller's machine (measured by Task 6).
- macOS `sed -i` needs a suffix: edit with PHP or `python3 -I` scripts, never `sed -i`.
- Max 3 parallel workers. Implementers NEVER touch the dev site, Docker or any `verify-*` script; the controller runs Task 9's gate and Task 11.
- Commit only your own files by explicit path (`git add <path>...`), message ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Explicit model per Agent dispatch: T0 sonnet, T1 sonnet, T2 **opus**, T3 **opus**, T4 **opus**, T5 **opus**, T6 **opus**, T7 **opus**, T8 sonnet (opus for its review), T9 sonnet (script) / controller (run), T10 sonnet; task reviews sonnet; plan review and the final whole-branch review **opus**.

## Review Focus

Each line names the test that pins it and the task that owns it.

1. A free symbol drifting under the engine (renamed, private, different signature or value): `FreeApiCompatTest` new rows, `MarkerSpaceTest::testTheCopiedConstantsEqualTheFreeOnes` (T1).
2. A patch, colour table or merge rule re-implemented in Pro: `DesignSourceRulesTest::testNoDesignFileSpellsAnAttributePathOrHex` (a scan of `pro-addon/src/Design/*.php` for `decoration.`, `#[0-9a-f]{3,6}`, `rgba(`) (T3).
3. Bands disagreeing with the scorer's: `BandsTest::testBandsEqualTheScorersOnEveryLibraryAndCorpusBlock` (T2).
4. A byte changed in a block the switch did not decide to change: `JsonLeavesTest::testUntouchedBytesStayIdentical`, `DesignSwitchPropertiesTest::testIdentityIsANoOp` (T2, T6).
5. A partial match switched in `leave` mode, or a match stripped without its pruning: `MarkerRecoveryTest::testAPartialMatchIsDriftedAndLeft`, `testStripPrunesOnlyWhatItEmptied` (T3).
6. A collision guessed silently: `RoleReverseTest::testEveryNonExactDecisionIsReported` (T4), `DesignSwitchMatrixTest` D1/D3 counts (T6).
7. Detection changing a page with low confidence: `DetectorTest::testATieThatUnresolvesDifferentlyIsRefused` (T5).
8. The headline claim: `DesignSwitchMatrixTest::testDefaultDesignsMatrix` (7,100), `testSeededCrossMatrix` (10,224), `DesignSwitchKitMatrixTest` (4,800 + 4,480), each with exact identical counts and D caps (T6).
9. A saved page the gate would refuse, custom code, or an unvalidated save: `SwitchDesignTest::testAnInvalidResultIsNeverSaved`, `DesignSwitchPropertiesTest::testEveryNthOutputPassesTheGate` (T6, T8).
10. A token replay, a page changed since the preview, a free update between preview and run: `SwitchDesignTest::testTokenIsSingleUseAndBound`, `testChangedSincePreviewIsSkipped`, `testAPlannedOutputThatChangedIsSkipped` (T8).
11. Undo not restoring every byte: `SwitchDesignTest::testUndoBatchRestoresEveryPage` (unit, with the in-memory repository) and the live gate (T8, T9).
12. MCP/REST/OpenAPI drift and the 30-operation budget: `ProParityTest`, `ProOpenApiTest` (absence of `/pro/switch_design`), `ToolsTest` (T8).

---

### Task 0: Measure, inventory, pin the facts (pure; sonnet; first)

**Files:**
- Create: `pro-addon/tests/DesignInventoryTest.php`
- Modify: none

**Interfaces:**
- Produces: the pinned facts every later task relies on; the confirmed list of Pro tests Task 8 moves.

- [ ] **Step 1: Re-measure** with a throwaway scratchpad script (load `tests/bootstrap.php`, `wp-plugin/src/autoload.php`): (a) the colour/font/type collision table of spec section 3 (normalised colour keys, `surface-glass` counted only where the language sets `surface_glass`); (b) the token inventory of `section-recipes.json`: 860 colour tokens (781 at key `color`, 79 at `value`, 0 alpha), 628 font tokens (all `family`), 11 `{{aied:kit:radius-media}}`, 405 markers; (c) the OpenAPI budget: `OpenApiSpec::spec('x','1')` has 16 operations and `ProOpenApi` adds 14; (d) `Tools::all()` count (21, or 22 if the render check merged: record which); (e) `PageHistory::MAX_BYTES` 524288; (f) the 14-recipe kit-marker cover set (`pricing-three-tier, features-bento, pricing-two-plan-highlight, icon-values, list-icon-dividers, portfolio-featured-grid, blurb-grid, contact-form, testimonial, features-accordion-image, proof-press-quotes, stats-counter-band, faq-accordion, faq-toggle-list`: greedy cover of the 25 (block, role, ground) kit markers and the media token). Any difference: STOP (Ruling 7).
- [ ] **Step 2: Write `DesignInventoryTest`** (green now): `testTheCollisionTableIsTheMeasuredOne` (the 10-row table as a literal), `testTheRecipeTokenInventory`, `testTheCoverSetCoversEveryKitMarker`, `testTheOpenApiBudgetIsUsedUp` (16 + 14 = 30).
- [ ] **Step 3: Inventory the Pro tests that pin the tool count or the MCP-only list**: `git grep -n -E "twenty-one|count\( Tools::all|MCP_REST_ONLY|bulk_adjust_prices'" -- pro-addon/tests pro-addon/README.md pro-addon/readme.txt`; record the hits in the commit message (Task 8 edits exactly those).

**Verify:** `php -d memory_limit=-1 vendor/bin/phpunit -c pro-addon/phpunit.xml --filter DesignInventoryTest` PASS; Pro suite 2169 + new tests, green.

**Commit:** `pro-addon/tests/DesignInventoryTest.php` ("Design switch Task 0: pin the measured collision table, token inventory and budgets").

---

### Task 1: Free API pins and the marker space (pure; sonnet; parallel with Task 2)

**Files:**
- Create: `pro-addon/src/Design/MarkerSpace.php`, `pro-addon/src/Design/Design.php`, `pro-addon/tests/MarkerSpaceTest.php`, `pro-addon/tests/DesignTest.php`
- Modify: `pro-addon/tests/FreeApiCompatTest.php` (new signature rows and constant checks only), `pro-addon/src/autoload.php`

**Interfaces:**
- `MarkerSpace::ROLE_BLOCKS`, `ROLE_SURFACES`, `ROLE_GROUNDS`, `GROUND_SURFACES`, `GROUND_BLOCKS` (copies of `KitResolver`'s private constants, same order).
- `MarkerSpace::kitCandidates( string $block ): list<array{kit:string, on:string, ground?:string}>`: every marker `KitResolver::marker()` would accept on `$block`, in ROLE_BLOCKS order, `on` in ROLE_SURFACES order, a ground candidate right after its plain one.
- `MarkerSpace::languageCandidates( string $block ): list<array<string,string>>` from `LanguageAdapters::APPLIED` (`[ 'type' => 'display' ]`, `[ 'rhythm' => 'section' ]`, ...).
- `Design::make( string $languageId, string $kitId ): self` (throws `\InvalidArgumentException` with the valid ids; a kit with `StyleKits::problems()` is refused); readonly `languageId`, `kitId`, `language`, `kit`; `Design::equals( Design $o ): bool`.

- [ ] **Step 1: Failing tests.** `MarkerSpaceTest::testTheCopiedConstantsEqualTheFreeOnes` (`new \ReflectionClassConstant( KitResolver::class, $name )->getValue()` for each of the five equals the copy); `testEveryKitCandidateResolves` (for every block in ROLE_BLOCKS and every candidate, `SectionRecipes::resolveMarkup` of the one-block markup under editorial + quiet-solid leaves no `KitResolver::leftovers()`); `testNoAcceptedMarkerIsMissing` (for a planted grid of every role x every band x every ground on every block name in ROLE_BLOCKS: the resolver resolves it iff `kitCandidates` lists it); `testLanguageCandidatesFollowApplied`. `DesignTest`: unknown ids refused with the list; `"default"` is NOT accepted here (the tool maps it, Task 8). `FreeApiCompatTest` rows: `SectionRecipes::resolveMarkup(markup string, language array, kit array): ?string`, `SectionRecipes::rawMarkup(name string): ?string`, `SectionRecipes::names(): array`, `DesignLanguages::get(id string): ?array`, `DesignLanguages::ids(): array`, `StyleKits::get/ids/problems/valueToken` (exact current signatures, read them), `RecipeTokens::encodeJson(value mixed): string`, `BlockAttrs::decode(json string): ?stdClass`, `KitResolver::leftovers(markup string): array`, `LanguageResolver::leftovers(markup string): array`; constant checks: `BlockAttrs::BLOCK` is a string, `LanguageAdapters::APPLIED` and `TYPE_HEADING_LEVELS` are arrays with the measured shapes, `PageHistory::MAX_BYTES === 524288`.
- [ ] **Step 2:** implement; run `--filter 'MarkerSpaceTest|DesignTest|FreeApiCompatTest'` PASS.

**Verify:** Pro suite green; `git diff main -- wp-plugin` empty.

**Commit:** the six files ("Design switch Task 1: marker space copies pinned to the free resolver, design value object, free API pins").

---

### Task 2: Scan, bands and byte-preserving leaves (pure; opus; parallel with Task 1)

**Files:**
- Create: `pro-addon/src/Design/PageScan.php`, `pro-addon/src/Design/Bands.php`, `pro-addon/src/Design/JsonLeaves.php`, `pro-addon/tests/PageScanTest.php`, `pro-addon/tests/BandsTest.php`, `pro-addon/tests/JsonLeavesTest.php`
- Modify: `pro-addon/src/autoload.php`

**Interfaces:**
- `PageScan::scan( string $page, array $language ): list<array{name:string, json:?string, offset:int, length:int, band:string, parent:?int}>`: every OPENING block comment (with or without attributes, self-closing included) in document order; `offset`/`length` are the byte span of the attribute JSON (`-1`/`0` when none); `band` per spec 5.1. Closing comments pop the band stack; an unbalanced page (more closes than opens) is scanned to the end without throwing and reported by `PageScan::balanced()`.
- `Bands::of( array $attrs, array $language ): ?string` (the band a block's OWN background paints, the D3 rule) and `Bands::colourKey( string $c ): ?string` (normalised `r,g,b,a`): a port of `Page::bandOf` / `Page::colourKey` (cite the source lines in the docblock).
- `JsonLeaves::strings( string $json ): list<array{path:string, key:string, value:string, offset:int, length:int}>`: every STRING leaf of a JSON object text with its JSON path (`.a.b[].c`), its key and the byte span of the raw string token (quotes excluded); a real tokenizer over the JSON text (strings with escapes, numbers, literals, nesting), never a regex alignment. `JsonLeaves::replace( string $json, array $edits ): string` where `$edits` is offset => new raw string content (already JSON-escaped): bytes outside the edited spans are copied verbatim.

- [ ] **Step 1: Failing tests.** `BandsTest::testBandsEqualTheScorersOnEveryLibraryAndCorpusBlock`: for every recipe built under the ten (language, default kit) designs and every page of `fixtures/dogfood/corpus.json` under its own design, `PageScan::scan()` bands equal `Divi5Validator\Tests\Support\Dogfood\Page::parse()->blocks()` bands block for block (same count, same names). `testColourKeyMatchesThePagesOne` over a fixed list (`#FFF`, `#ffffff`, `rgb(255 255 255)`, `rgba(0,0,0,0.7)`, `transparent`, `#ffffff80`, junk). `PageScanTest`: nested sections/rows/columns, self-closing blocks, blocks without attributes, a `-->` inside a JSON string, unbalanced input. `JsonLeavesTest::testStringsFindsEveryStringLeaf` (compared with `json_decode` traversal on every block of every recipe: same paths, same decoded values, same order), `testUntouchedBytesStayIdentical` (replace one leaf in every recipe block; every other byte equal), `testEscapedStringsAreSpannedExactly` (`\"`, `\\`, `<`, `\/`), `testInvalidJsonIsRefused` (returns [] and `replace` returns the input).
- [ ] **Step 2:** implement; `--filter 'PageScanTest|BandsTest|JsonLeavesTest'` PASS.

**Verify:** Pro suite green; time of `BandsTest` recorded in the commit message (budget: under 10 s).

**Commit:** the six files plus autoload ("Design switch Task 2: page scan with scorer-equal bands, byte-preserving JSON leaves").

---

### Task 3: Expectations and marker recovery (pure; opus; needs Tasks 1 and 2)

**Files:**
- Create: `pro-addon/src/Design/Expectations.php`, `pro-addon/src/Design/Leaves.php`, `pro-addon/src/Design/MarkerRecovery.php`, `pro-addon/tests/ExpectationsTest.php`, `pro-addon/tests/MarkerRecoveryTest.php`, `pro-addon/tests/DesignSourceRulesTest.php`
- Modify: `pro-addon/src/autoload.php`

**Interfaces:**
- `Expectations::__construct( Design $d )`; `->attrs( string $block, array $marker ): ?\stdClass`: the decoded attributes of `SectionRecipes::resolveMarkup( '<!-- wp:'.$block.' '.json_encode(['aied'=>$marker]).' /-->', ... )` (null when leftovers remain or the result still holds `{{aied:`); memoised per instance.
- `Leaves::of( \stdClass $n ): list<array{0:list<string>,1:mixed}>` (a list and an empty object are one leaf); `Leaves::count( \stdClass $attrs, \stdClass $patch ): int` (leaves of `$patch` present in `$attrs` with the same JSON value; `-1` when one differs or is missing); `Leaves::agreeing( $attrs, $patch ): int` (how many agree, for `drifted`); `Leaves::strip( \stdClass $attrs, \stdClass $patch ): void` (removes the patch's leaves and prunes, along each removed path only, every object the removal emptied).
- `MarkerRecovery::recover( string $block, \stdClass $attrs, string $band, ?int $headingLevelHint, Expectations $src, Expectations $tgt ): array{status:'kit'|'language'|'drifted'|'unclassified'|'none', marker:?array, patch:?\stdClass, assumed:?string, differing:list<string>}`; on `kit`/`language` the caller strips `patch` and sets `aied` = `marker`. Rules exactly as spec 5.2: most matched leaves wins; a tie between candidates with different TARGET patches -> the band (`on === band`, a button on `surface` -> `light`, plain before ground) then the heading level (`TYPE_HEADING_LEVELS`) for type; still tied -> the first in candidate order with `assumed` = `"band unknown"`; a smaller candidate that also matched with a different target patch -> `assumed` = `"larger match preferred"` (D4).
- `MarkerRecovery::isDecorated( \stdClass $attrs ): bool`: the block has any leaf under a path some candidate patch of its name writes (for `unclassified`).

- [ ] **Step 1: Failing tests.** `ExpectationsTest`: the result equals the scorer's `Expected::attrs()` for every (block, role, band, ground) of the ten default designs (byte-equal JSON); a marker the resolver leaves gives null. `MarkerRecoveryTest`: for EVERY marked block of EVERY recipe under the ten default designs, recovery returns the recipe's own marker (role, on, ground) or a marker whose source AND target patches equal it for every target (asserted by resolving both), and stripping returns attributes that decode equal to the recipe block's own attributes minus its marker (the measured exceptions are the D4 blocks: list them by recipe and index, shrink-only); `testAPartialMatchIsDriftedAndLeft` (a resolved button with its radius edited); `testStripPrunesOnlyWhatItEmptied` (a pre-existing `{}` elsewhere survives); `testHeadingLevelBreaksTheDisplayTitleTie` (corporate-trust); `testABandUnknownTieIsAssumed`. `DesignSourceRulesTest::testNoDesignFileSpellsAnAttributePathOrHex` (scan `pro-addon/src/Design/*.php`: no `decoration.`, `advanced.`, `#` + 3/6 hex digits, `rgba(`; `MarkerSpace.php` exempt only for its five constants).
- [ ] **Step 2:** implement; `--filter 'ExpectationsTest|MarkerRecoveryTest|DesignSourceRulesTest'` PASS.

**Verify:** Pro suite green.

**Commit:** the six files plus autoload ("Design switch Task 3: expectations from the free pipeline, exact marker recovery").

---

### Task 4: Context table and role reverse (pure; opus; needs Task 2; parallel with Task 3)

**Files:**
- Create: `pro-addon/src/Design/ContextTable.php`, `pro-addon/src/Design/RoleReverse.php`, `pro-addon/tests/ContextTableTest.php`, `pro-addon/tests/RoleReverseTest.php`
- Modify: `pro-addon/src/autoload.php`

**Interfaces:**
- `ContextTable::current(): self` (memoised per request, keyed by `hash_file('sha256', <free recipes file>)` read through `SectionRecipes`' own data path constant or, if none is public, by the sha256 of the concatenated `rawMarkup()` of `names()`); built by resolving every raw recipe under a SYNTHETIC language (the editorial language with every colour role given its own distinct hex and every font role its own family; type and section space unchanged) and the kit `quiet-solid`, scanning raw and resolved in parallel (`PageScan`, same block order) and recording each colour token at (block, path, band) and each font token at (block, path, the block's raw type role or `-`). `->colour( string $block, string $path, string $band ): array<string,int>`, `->font( string $block, string $path, string $typeRole ): array<string,int>` (role => count), `->hash(): string`.
- `RoleReverse::__construct( Design $from, Design $to, ContextTable $t )`; `->colour( string $value, string $block, string $path, string $band ): array{token:?string, status:'exact'|'context'|'same-target'|'assumed'|'ambiguous'|'unknown', roles:list<string>}` and `->font( ... string $typeRole )` the same; `->alpha( string $value ): ?string` (`{{aied:color:<role>:<NN>}}` when the value equals `RecipeTokens`' alpha form of exactly one role, else null).

- [ ] **Step 1: Failing tests.** `ContextTableTest`: the synthetic language passes `DesignLanguages::valid()` (or the test states which check is skipped and why); the table's totals equal the token inventory of Task 0 (860 colour, 628 font occurrences); deterministic (two builds equal); `testTheHashFollowsTheRecipes`. `RoleReverseTest`: every value of every language's palette returns `exact` where one role has it; for each collision of the spec table, a planted context with one attested role returns `context`, with a majority returns `assumed`, with none returns `ambiguous`; `same-target` when all colliding roles map to one target value; `testEveryNonExactDecisionIsReported` (the decision always names its roles); `#FFF` matches `#ffffff`; a value in no palette is `unknown`; `alpha()` round trip for every role x {10, 40, 45, 70} in every language, null where two roles share the rgb.
- [ ] **Step 2:** implement; `--filter 'ContextTableTest|RoleReverseTest'` PASS; record `ContextTable::current()` build time (budget 0.2 s; measured 0.06 s by the prototype).

**Verify:** Pro suite green.

**Commit:** the four files plus autoload ("Design switch Task 4: run-time context table from the installed recipes, role reverse with reported decisions").

---

### Task 5: Detection and the switch (pure; opus; needs Tasks 3 and 4)

**Files:**
- Create: `pro-addon/src/Design/Detector.php`, `pro-addon/src/Design/DesignSwitch.php`, `pro-addon/src/Design/SwitchResult.php`, `pro-addon/tests/DetectorTest.php`, `pro-addon/tests/DesignSwitchTest.php`
- Modify: `pro-addon/src/autoload.php`

**Interfaces:**
- `DesignSwitch::run( string $page, Design $from, Design $to, string $mode = 'leave' ): SwitchResult`: scan; per block with attributes: marker recovery (Task 3), media token (an image radius object equal to the source kit's `valueToken('radius-media')`, D2), then every `color`/`value`/`family` string leaf (`JsonLeaves`) through `RoleReverse` with the block's band and recovered type role; a block that got a marker or a media token is decoded, stripped, re-encoded with `RecipeTokens::encodeJson`, then its leaves rewritten; any other block only has its leaves rewritten in place; finally `SectionRecipes::resolveMarkup( $unresolved, $to->language, $to->kit )`. A null resolve (a leftover) returns the ORIGINAL page with `error` = `"unresolved"` (never a partial page). `$from->equals($to)` returns the page unchanged, `changed:false`.
- `SwitchResult`: `content`, `changed`, `switched` (`markers` role => count, `type`, `rhythm`, `colours` role => count, `fonts` role => count, `images`), `left` (`unknown_colours` value => count, `unknown_fonts`, `ambiguous`, `drifted`, `unclassified`, `global_refs` (count of `gcid-`/`$variable(` values)), `assumed`, `error`; `toArray( int $max = 25 )` (lists cut to `$max` entries plus `*_count`).
- `Detector::detect( string $page, ?string $languageId, ?string $kitId ): array{language:?string, kit:?string, source:array{language:'given'|'detected', kit:'given'|'detected'|'none'}, confidence:array, runner_up:?array, refused:?string}`: spec 5.4; a page with no kit carrier block gets the target's kit as source kit with `kit: 'none'` (nothing to recover); the margin floor is fixed here from the measurement of Step 1 and pinned.

- [ ] **Step 1: Measure detection** on every recipe under the ten default designs and 4 seeded designs each (seed 11): expected 284 of 284 languages; kits 140 exact + 56 harmless (unresolve equal under both kits) + 80 none, 8 refused or harmless after the tie rule. Fix the margin floor so no wrong kit with a different unresolve is ever accepted on that set; pin.
- [ ] **Step 2: Failing tests.** `DetectorTest::testDetectionOnTheLibrary` (the Step 1 table pinned), `testATieThatUnresolvesDifferentlyIsRefused`, `testGivenIdsWin`. `DesignSwitchTest`: one recipe end to end (pricing-three-tier editorial/quiet-solid -> luxe/gilt-hairline equals the direct build); `testIdentityReturnsThePageUnchanged`; `testAnUnresolvedResultReturnsTheOriginal` (an adapter refusal planted with a kit that `StyleKits::problems()` flags is impossible through `Design::make`, so plant a page with a bogus `aied` marker: leftovers -> original + error); `testTheReportCountsEveryDecision` (the sums of `switched` + `left` + `assumed` equal the leaves inspected); `testTheSameInputGivesTheSameBytes` (run twice).
- [ ] **Step 3:** implement; `--filter 'DetectorTest|DesignSwitchTest'` PASS.

**Verify:** Pro suite green.

**Commit:** the five files plus autoload ("Design switch Task 5: detection and the deterministic switch").

---

### Task 6: The proofs (pure; opus; needs Task 5)

**Files:**
- Create: `pro-addon/tests/Support/DesignDiffClassifier.php`, `pro-addon/tests/DesignSwitchMatrixTest.php`, `pro-addon/tests/DesignSwitchKitMatrixTest.php`, `pro-addon/tests/DesignSwitchPropertiesTest.php`, `pro-addon/tests/DesignSwitchScorerLockstepTest.php`

**Interfaces:**
- `DesignDiffClassifier::classify( string $switched, string $direct ): list<string>` (`[]` = byte-identical): pairs blocks in order (a different block count is `STRUCTURE`, always a failure); for each differing block flattens both decoded attribute sets; JSON-equal = `D5`; a differing `.family` leaf = `D1`; a differing leaf under `image.decoration.border` = `D2`; a differing background `color` leaf = `D3`; anything else = `D4`; a difference that fits no rule = `UNCLASSIFIED` (fails). The rules are the spec's section 6 table, one `if` each.

- [ ] **Step 1: The matrices** (counts measured by the prototype; re-measure, pin exactly; caps shrink-only):
  - `DesignSwitchMatrixTest::testDefaultDesignsMatrix`: 71 recipes x 10 x 10 (language, default kit) = **7,100** switches; identical **6,571**; caps D1 435, D2 48, D3 36, D4 9, D5 1 (switch counts by first class; also assert the per-recipe set: D1 only in the 19 recipes of the spec, D2 only team-grid and gallery-masonry-style, D3 only proof-logo-wall and pricing-comparison-table, D4/D5 only contact-location-form).
  - `testSeededCrossMatrix`: seed 7, 12 (language, kit) pairs drawn with `mt_srand(7)` + `mt_rand` exactly as recorded in the test (the pair list is ALSO written out literally and asserted, so a PHP RNG change cannot move it silently): **10,224** switches, identical **9,533**, caps D1 513, D2 64, D4 48 (+12 with D3), D3 42, D5 10 (+2 with D3).
  - `DesignSwitchKitMatrixTest::testFullKitMatrixOnThreeRecipes`: pricing-three-tier, features-bento, contact-form under editorial, 40 x 40 = **4,800**; `testCoverSetUnderSeededTargets`: the 14 cover recipes x 40 source kits x 8 seeded target kits = **4,480**; identical counts measured and pinned, only D2/D4/D5 allowed.
- [ ] **Step 2: Properties** (`DesignSwitchPropertiesTest`, on the matrix-1 set): `testIdentityIsANoOp` (710; pinned 709 + the one D4/D5 case named); `testSwitchingTwiceToTheSameTargetIsANoOp` (6,390; 6,381 pinned, the 9 named); `testRoundTripReturnsTheOriginal` (6,390; 5,879 pinned; every failure classified D1-D5); `testEveryNthOutputPassesTheGate` (every 7th output: `LayoutGate::check($out, $in)->isValid()`, 912 of 912); `testNoOutputCarriesATokenOrMarker` (`RecipeTokens::advise()` null, `KitResolver::leftovers()` empty).
- [ ] **Step 3: Scorer lockstep** (`DesignSwitchScorerLockstepTest`): for every recipe, source editorial/quiet-solid, the ten default targets (710 pairs): `Scorer($toKit, $toLanguage)->details()` of the switched page equals that of the direct build check for check, except checks on blocks the classifier named (listed); and on the 8 corpus pages switched to the ten default targets the scorer runs without throwing and every block the switch reported as switched passes all its checks under the target.
- [ ] **Step 4: Time budget.** Run the whole Pro suite; record its wall time in the commit message; if it exceeds 4 minutes, reduce `testSeededCrossMatrix` to 8 pairs (re-measure, re-pin) and say so.

**Verify:** `make test-pro` green; every test in this task fails when the engine's tie-break, prune or context rule is mutated (do it once each by hand, record in the commit message, revert).

**Commit:** the five files ("Design switch Task 6: byte-identity matrices with named differences, properties, scorer lockstep").

---

### Task 7: `conform` mode (pure; opus; needs Task 5; parallel with Tasks 6 and 8)

**Files:**
- Create: `pro-addon/tests/DesignConformTest.php`
- Modify: `pro-addon/src/Design/MarkerRecovery.php`, `pro-addon/src/Design/DesignSwitch.php`, `pro-addon/src/Design/SwitchResult.php`

**Interfaces:**
- `DesignSwitch::run( ..., 'conform' )`: a `drifted` block (nearest candidate agrees on at least half its leaves, ties broken as in Task 3) has EVERY leaf at a path its candidate's source patch writes removed (whatever the value) and gets the marker; each removed leaf whose value differed is reported under `overridden` (block, path, old value; at most 25 + count).

- [ ] **Step 1: Measure** on the 8 corpus pages x the ten default targets: scorer overall before (leave) and after (conform) per cell; the share of corpus blocks the engine conforms; the disagreements between the scorer's best (role, band) and the engine's choice.
- [ ] **Step 2: Failing tests.** `testConformedBlocksScoreFullyUnderTheTarget` (every conformed block passes all its scorer checks, all 80 cells); `testTheScorerAgreesWithTheEnginesRole` (disagreements pinned as a shrink-only list from Step 1); `testLeaveModeIsUnchanged` (matrix-1 counts of Task 6 unchanged with `leave`); `testEveryOverriddenLeafIsReported`.
- [ ] **Step 3:** implement; PASS.

**Verify:** Pro suite green; Task 6 counts unchanged.

**Commit:** ("Design switch Task 7: opt-in conform mode, scored by the dogfood scorer").

---

### Task 8: The tool, REST, batch and undo (sonnet, opus review; needs Task 5)

**Files:**
- Create: `pro-addon/src/Tools/SwitchDesign.php`, `pro-addon/tests/SwitchDesignTest.php`
- Modify: `pro-addon/src/Tools.php`, `pro-addon/src/Rest/ProOpenApi.php` (`MCP_REST_ONLY` gains `switch_design`), `pro-addon/src/autoload.php`, the tests Task 0 Step 3 listed (`ToolsTest`, `ProParityTest`, `ProOpenApiTest` absence assertion, `ReadmeTest` if it counts), `pro-addon/README.md` (tool list line only; the section is Task 10)

**Interfaces:**
- `SwitchDesign implements ProTool`: `NAME = 'switch_design'`, `MAX_PAGES = 25`, `capability()` `edit_pages`, `__construct( BatchLog $log, ?\Closure $write = null, ?\Closure $clock = null )` (the `FindReplaceSite` seams). Definition: description at most 300 characters ("Restyle Divi 5 pages built with this plugin to another design language and/or style kit, deterministically: colours, fonts, headline type, section spacing and kit-styled modules. Always dry_run=true first, then repeat with the token. Undoable."), input schema per spec section 7 (`page_id`, `page_ids`, `to_language`, `to_kit`, `from_language`, `from_kit`, `mode` enum, `dry_run`, `token`).
- Run order: arguments (strict `Args`; exactly one of `page_id`/`page_ids`; at least one of `to_*`; `to_kit: "default"` -> the target language's `kit`; ids checked with the valid lists); run-time guard (refuse with a plain message when `LanguageResolver` or the carrier roles are missing in the installed free plugin); per page `EditTarget::resolve` (missing/forbidden -> skipped with `not_found` / `forbidden`, never leaked), size check (`too_large_for_undo` over `PageHistory::MAX_BYTES` for stored or new content), detection (`detection_ambiguous`), `DesignSwitch::run`, `LayoutGate::check( $new, $stored )` (`invalid_result`), `nothing_to_switch`. Argument hash = `Canon::hash` of the normalised arguments (sorted ids, resolved `to` ids, mode); preview map = page id => `substr(Canon::hash($stored),0,16) . ':' . substr(Canon::hash($new),0,16)`.
- Preview payload: `changed:false`, `dry_run:true`, `pages` (per page: id, title, kind, `from`, `to`, `report` (`SwitchResult::toArray()`), `valid`, `violations` codes, `diff` (`LayoutDiff::compare` summary), advisories via `StyleReferences::attach`), `would_change`, `would_skip`, `token`, `message`.
- Apply: token required (`PreviewToken::open`, then `spend`); per page recompute; skip `not_in_preview`, `changed_since_preview` (either half of the hash pair differs), `invalid_result`, `save_failed`; write `HistoryService::write( $id, $new, self::NAME )`; item `PostContentKind::itemFor( $id, $title, <stored after write>, $snapshot )`; ONE `BatchLog::record( self::NAME, 'Switched N page(s) to <language> + <kit>', $items )`; `BatchLog::attach`.

- [ ] **Step 1: Failing tests** (`SwitchDesignTest`, WP shims of the Pro suite, the in-memory batch repository, the `$write` seam): `testDefinitionAndSchema`; `testArgumentsAreStrict` (both page arguments, neither, 26 pages, a non-int id, unknown ids, `mode` outside the enum, a non-boolean `dry_run`); `testPreviewReportsAndReturnsAToken`; `testAnInvalidResultIsNeverSaved`; `testTooLargeForUndoIsRefused`; `testForbiddenPagesAreSkippedWithoutLeaking`; `testTokenIsSingleUseAndBound` (other user, other args, expired, replayed); `testChangedSincePreviewIsSkipped`; `testAPlannedOutputThatChangedIsSkipped` (swap the context through a planted recipe hash); `testOneBatchOfPostContentItems`; `testUndoBatchRestoresEveryPage` (`BatchUndo` with `PostContentKind` and a fake restorer over the history the seam recorded: every page's content back byte for byte); `testIdentityTargetIsChangedFalse`; `testMcpAndRestGiveTheSamePayload` (`ToolRunner` through `ToolRegistry::claim` and `ProRest::handle`).
- [ ] **Step 2:** wire (`Tools::all()` appends `new SwitchDesign( $log )` LAST; `MCP_REST_ONLY` appends `switch_design`); move each count pin listed in Task 0 by exactly one (Ruling 8); implement; PASS.

**Verify:** `make test-pro` green; `ProOpenApiTest` asserts `/pro/switch_design` absent and 30 operations; `ProSafetyTest` unchanged and green.

**Commit:** ("Design switch Task 8: the switch_design tool, MCP + REST, one undoable batch").

---

### Task 9: Live gate `make verify-pro-switch-design` (script: sonnet; run: controller; needs Task 8)

**Files:**
- Create: `scripts/verify-pro-switch-design.php` (wp eval-file part), `scripts/verify-pro-switch-design.cjs` (orchestrator + Chromium compare), `scripts/test-verify-pro-switch-design.cjs` (pure tests of the comparison helpers)
- Modify: `Makefile` (target + `.PHONY` + a one-line comment like its neighbours)

**Interfaces:**
- PHP part (dev-guard first; `--user=1`; free + Pro active and unlocked): stage `build` creates two throwaway drafts titled `aied switch <runToken> source|direct` from six recipes of the matrix-1 CLEAN set (hero-cta, section-intro, blurb-grid, stats-counter, faq-accordion, cta-split-form; Task 6 confirms they are clean for editorial/quiet-solid -> luxe/gilt-hairline) under the source and target designs (`wp_slash` on insert); stage `switch` calls `rest_do_request( POST /ai-editor-divi5/v1/pro/switch_design )` with `dry_run` (asserts `valid:true`, the expected `switched` counts, no `ambiguous`), then with the token, then the same token again (refused); reads back and asserts the stored source page equals the direct page byte for byte; MCP parity through the free MCP handler `tools/call`; an editor without `edit_post` on the page refused; stage `undo` runs `undo_batch` and asserts the stored content equals the original byte for byte; stage `changed` edits a page between preview and run (skipped `changed_since_preview`); stage `cleanup` deletes every `aied switch ` post (also `--clean-orphans`). JSON lines to stdout, PASS/FAIL per check.
- Node part: one run at a time (`pgrep -f "node scripts/verify-"` empty); after `switch`, renders the switched and the direct draft at 1280 and 400 px (admin bar hidden, as `verify-kits`), and for every module compares computed `color`, `background-color`, `font-family`, `font-weight`, `letter-spacing`, `text-transform`, `border-*`, `border-radius`, `padding`, `box-shadow` and the bounding box (equal within 0.5 px); screenshots to `dist/switch-design/`; exit non-zero on any FAIL or when nothing was compared.

- [ ] **Step 1:** write the pure tests (comparison of two module lists: equal, a colour off, a missing module, an empty run) and the scripts; `node scripts/test-verify-pro-switch-design.cjs` PASS.
- [ ] **Step 2 (controller):** `make verify-pro-switch-design` on the Docker site (Divi 5.15.1, the branch's free and Pro mounted); record PASS counts and the screenshots; no orphan `aied switch ` post remains.

**Verify:** the gate exits 0; `wp post list --s="aied switch"` empty afterwards.

**Commit:** the four files ("Design switch Task 9: live gate (save, render compare, undo)"); the controller adds the live result to the spec Outcome in Task 11.

---

### Task 10: Docs (sonnet; needs Tasks 6-9)

**Files:**
- Modify: `pro-addon/README.md` (a `## Design switch (switch_design)` section in the style of `bulk_adjust_prices`: arguments table, preview and token, the left/assumed lists, D1-D5 in plain words, limits, MCP + REST only, spec and gate names; `Known limits` gains the ChatGPT line update), `pro-addon/readme.txt` (one feature line; no price), `CLAUDE.md` (one paragraph "Design switch (Pro, unreleased, branch ...)" with the measured numbers and the owner decisions left; the Pro add-on bullet's tool count), `docs/superpowers/specs/2026-10-25-pro-design-switch-design.md` (Outcome: the measured counts of Tasks 5-7, deviations, the live result placeholder for Task 11)
- Do NOT modify: anything under `wp-plugin/` (the free readme and guides never mention Pro features), `docs/pricing-page.md` (owner copy; the market claim of spec section 2 is handed to the owner in the Outcome)

- [ ] **Step 1:** write; every number copied from a test or a commit message of this branch, never retyped from the plan.
- [ ] **Step 2:** `make test` and `make test-pro` green (the README tests).

**Commit:** ("Design switch Task 10: Pro README section, CLAUDE.md paragraph, spec Outcome").

---

### Task 11: Controller: live gate, whole-branch review, merge (controller; opus review)

- [ ] **Step 1:** `make test`, `make test-pro`, `git diff main -- wp-plugin src` empty.
- [ ] **Step 2:** Task 9 Step 2 (the live gate), then `make verify-pro-rest` (read-only: the live OpenAPI still has 30 operations and no `/pro/switch_design`).
- [ ] **Step 3:** an independent opus whole-branch review against the spec and this plan's Review Focus; fix findings in the owning task's files.
- [ ] **Step 4:** fill the spec Outcome (live counts, review findings), `git merge --no-ff` into LOCAL main. No push, no release.

---

## Parallelism map

```
T0 ──┬── T1 ──┐
     └── T2 ──┼── T3 ──┐
              └── T4 ──┴── T5 ──┬── T6 ──┐
                                ├── T7 ──┤
                                └── T8 ── T9 (script) ──┴── T10 ── T11 (controller)
```

- Wave 1: T0. Wave 2: T1 ∥ T2. Wave 3: T3 ∥ T4 (T3 needs T1 and T2; T4 needs T2). Wave 4: T5. Wave 5: T6 ∥ T7 ∥ T8 (at most 3 workers; T7 and T6 both read `DesignSwitch`, T7 edits it: T6 runs its matrices again after T7 lands and the counts must not move). Wave 6: T9 script. Wave 7: T10. Then T11.

## Spec issues found (brief versus code; the side followed)

1. **Colour/font reverse lookup is not exact** (brief: exact once the source is known): 7 of 10 languages share a colour between roles, 9 of 10 a font family (spec section 3). Followed: the code; the context rule and the named classes D1 and D3.
2. **Item kind name**: the brief's `page_content` is `post_content` (`PostContentKind`) in the code. Followed: the code.
3. **The scorer is a judge, not an inverter**, and promoting it into shipped free code would break the Pro Contract (`pro-addon/README.md`: no free change beyond hooks) and invite a Guideline 5 reading. Followed: a new exact engine in Pro; the scorer stays dev tooling and is the oracle (Task 6 Step 3, Task 7); its band rule is ported and pinned equal (Task 2).
4. **Free version**: the type, rhythm and carriers adapters are on local main, not in the published free 4.14.0, and `Compat::MIN_FREE` is 4.9.0. Followed: a run-time guard in the tool (Task 8); the `MIN_FREE` bump is the owner's at release.
5. **Tool count**: `docs/superpowers/plans/2026-10-25-pro-render-check.md` adds `check_page_render`; whichever merges second moves the pins by one more (Ruling 8).
6. **`page_id` only** in the brief: refined to `page_id` or `page_ids` (1-25), one batch (spec Ruling 7).
7. **"Byte-identical or named differences"**: measured; five classes D1-D5, all pinned (Task 6). The brief's full 71 x 10 x 40 matrix is sampled: the full kit matrix (113,600 switches, about 5 minutes) is too slow for the suite.
8. **`page_ids` and the token**: `PreviewToken` maps id => one string; the plan stores "stored hash:planned hash" in that string so a free-plugin update between preview and run is caught without a token format change.
