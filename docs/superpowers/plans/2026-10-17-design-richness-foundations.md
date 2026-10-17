# Design Richness: Foundations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the machinery that lets the AI produce varied, designed pages: a render-proven shape registry and probe, tagged and filterable recipes with color/font tokens, design languages, on-demand style-guide topics, and a non-blocking variety advisory.

**Architecture:** Everything lives in the free plugin's existing guide/advisory pattern (pure classes in `wp-plugin/src/`, data in `wp-plugin/data/`, advisories attached in `StyleReferences::attach`). The shape registry and its tests are dev-side only (`tests/Support/`, `fixtures/shapes/`), never shipped. No new tool, route or OpenAPI operation: only optional strict-string parameters on `get_style_guide` and `get_section_recipes`.

**Tech Stack:** PHP 8.1 (PHPUnit via `vendor/bin/phpunit`), Node + Playwright (`scripts/*.cjs`, dev only), Docker dev site with real Divi 5.15.1.

**Spec:** `docs/superpowers/specs/2026-10-17-design-richness-design.md`

**Scope note:** This is plan 1 of 2. It builds the machinery and proves it on a pilot (2 languages, 1 tokenised recipe, tags on the 17 existing recipes). Plan 2 (vocabulary mining batches, recipe batches 17 to 60+, the remaining design languages, moving long guide sections into topics, readme counts) is written after this lands, because those batches consume the registry schema, probe and token vocabulary defined here.

## Global Constraints

- The validator stays pure and deterministic; `src/` is canonical and `wp-plugin/validator/` is its mirror. This plan does not change the validator.
- No Divi schema from memory: every shape must trace to a real builder export, or to Divi's own `module.json`/PHP style classes, proven by a live render on the real Divi.
- No new MCP tool, REST route or OpenAPI operation (the ChatGPT importer's 30-operation budget is used up); optional parameters only; MCP, REST and OpenAPI stay in lockstep.
- Nothing licence-gated, remote-fetching or custom-code-saving enters `wp-plugin/`; `tests/WpOrgComplianceTest.php` is not weakened (a new local data file read gets an explicit allowlist entry).
- String arguments are type-checked (non-strings are 400 / -32602), never cast.
- The default `get_style_guide` answer must not grow (measure with `scripts/measure-tokens.php`).
- Advisories never change `valid`, never block a save, never rewrite content.
- Max 3 parallel workers if tasks are parallelised.
- Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Work on branch `feat/design-richness`.
- No release, version bump or SVN step in this plan (the owner decides releases).

## Decisions the spec left open (planner's calls, flagged for the owner)

- The registry lives in `fixtures/shapes/verified-shapes.json` (not `wp-plugin/data/`): only tests and dev scripts read it, so it stays out of the zip.
- The default `get_style_guide` text is not restructured here; it keeps today's text plus a one-line topic index. New material arrives as topics. Moving existing long sections into topics is a Plan 2 task.
- A "ratchet" file freezes today's guide paths so the registry lint catches NEW unproven paths without having to re-prove the old guide in this plan.

## Review Focus

- A page with fewer than 3 sections, empty content or unparseable content: `DesignVariety::advise` returns null and never throws.
- `design_language` / `topic` / `role` / `layout` / `mood` given as a number, array or bool, or as an unknown id: refused or answered with the list of valid ids, never cast, and never a recipe still holding raw `{{aied:color:...}}` tokens.
- An `edit_page_content` that touches one section of a long page gets no variety advisory even if the page is repetitive.
- A saved page that contains a stray `{{aied:...}}` token gets `advisories.unresolved_tokens` listing it; a recipe served with no language has no raw tokens at all.
- A design language whose palette has a text/background pair below WCAG AA fails the suite (no low-contrast language can ship).

---

### Task 1: Shape paths, registry, seed and lint (dev-side)

**Files:**
- Create: `tests/Support/ShapePaths.php`, `tests/Support/ShapeRegistry.php`, `scripts/seed-shape-registry.php`, `fixtures/shapes/verified-shapes.json`, `fixtures/shapes/legacy-guide-paths.txt`, `tests/ShapeRegistryTest.php`
- Modify: `wp-plugin/src/SectionRecipes.php` (add `rawMarkup`)

**Interfaces:**
- Produces: `Divi5Validator\Tests\Support\ShapePaths::fromBlockAttrs(array $attrs): list<string>` returns the normalized attribute paths of one block: breakpoint keys (`desktop|tablet|phone|tabletWide|phoneWide`) become `{bp}`, numeric keys become `{n}`, descent stops at `innerContent` (the path ends at `.innerContent`), and `builderVersion`, `modulePreset`, `syncNodeId`, `slotId`, `globalModule`, `blockName`, `localAttrs`, `localAttrOverridePaths` are exempt. `ShapePaths::fromMarkup(string $markup): list<string>` (all blocks, unique, sorted) and `ShapePaths::fromGuideText(string $md): list<string>` (dotted paths of 4 or more segments found in Markdown, `{bp}` kept, a literal `desktop` normalized to `{bp}`).
- Produces: `ShapeRegistry::load(?string $file = null): self`; `covers(string $normalizedPath): bool`; `all(): list<array{path:string,how:string,evidence:string}>`. `how` is `export` or `probe`.
- Produces: `SectionRecipes::rawMarkup(string $name): ?string` (tokens unresolved).

- [ ] **Step 1: Write failing tests** in `tests/ShapeRegistryTest.php` (namespace `Divi5Validator\Tests`, requires the two Support files and `SectionRecipes`): `testPathNormalisation` asserts `ShapePaths::fromBlockAttrs(['module'=>['decoration'=>['background'=>['desktop'=>['value'=>['gradient'=>['stops'=>[['position'=>0]]]]]]]],'builderVersion'=>'5.8.0','title'=>['innerContent'=>['desktop'=>['value'=>'x']]]])` equals `['module.decoration.background.{bp}.value.gradient.stops.{n}.position','title.innerContent']`; `testEveryRecipePathIsRegistered` loads every recipe via `rawMarkup`, collects paths and asserts each is `covers`ed (message lists the missing ones); `testRegistryEntriesAreWellFormed` (every entry has a path with no literal `desktop`, `how` in `export|probe`, a non-empty `evidence` that is an existing file path (before any `#`)); `testNewGuidePathsMustBeRegisteredOrFrozen` collects `fromGuideText(StyleGuide::markdown())` and asserts each is registered or listed in `fixtures/shapes/legacy-guide-paths.txt`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter ShapeRegistryTest`. Expected: FAIL (classes missing).
- [ ] **Step 3: Implement** `ShapePaths`, `ShapeRegistry`, `SectionRecipes::rawMarkup`, and `scripts/seed-shape-registry.php`, which walks every block in `fixtures/valid/*.json` (these are real builder exports; read `post_content` from each) and writes one `how: export` entry per distinct normalized path, with `evidence` set to the first fixture file that contains it, to `fixtures/shapes/verified-shapes.json` (`{"divi":"5.15.1","shapes":[...]}`, sorted, byte-stable). Also write `legacy-guide-paths.txt` once from the current guide (one path per line, sorted). Paths in recipes that no fixture contains stay unregistered and fail the lint: for each, either find the export that has it or remove it from the recipe; do not register it by hand.
- [ ] **Step 4: Run** `php scripts/seed-shape-registry.php && vendor/bin/phpunit --filter ShapeRegistryTest`. Expected: PASS.
- [ ] **Step 5: Run** `vendor/bin/phpunit`. Expected: all green (no regressions).
- [ ] **Step 6: Commit** `feat: render-proven shape registry with recipe and guide lint`.

### Task 2: Effect probe and promotion (live, Docker)

**Files:**
- Create: `scripts/verify-shapes.cjs`, `scripts/promote-shapes.php`, `fixtures/shapes/candidates/known.json`
- Modify: `Makefile` (target `verify-shapes`, add to `.PHONY`)

**Interfaces:**
- Consumes: the page-saving and Playwright helpers pattern of `scripts/verify-guide-shapes.cjs`; `ShapePaths`/registry from Task 1.
- Produces: candidate file format `[{ "id": string, "block": "section|row|column|group|heading|text|image|button|blurb|...", "base": object (attrs of the block), "set": { "<dotted path with desktop>": <value> } }]`. `node scripts/verify-shapes.cjs <candidates.json>` prints `PASS`/`DEAD` per candidate and writes `fixtures/shapes/probe-results-5.15.1.json` (`[{id, effect: bool, widths: {"1280": bool, "900": bool, "400": bool}, phoneOverflow: bool}]`). `php scripts/promote-shapes.php <results.json> <candidates.json>` appends an entry `how: probe`, `evidence: docs/shape-evidence-5.15.1.md#<id>` for every candidate with `effect: true` and `phoneOverflow: false`, and appends a row to `docs/shape-evidence-5.15.1.md`.

- [ ] **Step 1: Write `fixtures/shapes/candidates/known.json`** with known-good and known-dead cases from `docs/guide-shapes-evidence-5.14.md`: good = heading `title.decoration.font.font.desktop.value.size` 48px/with tablet 36px/phone 24px, and image `module.advanced.sizing.desktop.value.aspectRatio` `{"width":"4","height":"3"}` plus `image.decoration.fit.desktop.value.objectFit` `cover`; dead = image `image.decoration.fit.desktop.value.type` `cover`, image `image.innerContent.desktop.value.forceFullwidth` `true`.
- [ ] **Step 2: Implement `verify-shapes.cjs`.** For each candidate save two throwaway draft pages through `LayoutGate::check` + `wp_insert_post(wp_slash(...))` (as `verify-guide-shapes.cjs` does), identical except the `set` values; render both with the real Divi in Chromium at 1280, 900 and 400 px; `effect` is true when the emitted page stylesheet text (post ids normalized) or the hash of the computed styles of the module element and its descendants differs between the two at that width; `phoneOverflow` is true when `document.documentElement.scrollWidth > innerWidth` at 400. Dev site only (refuse non-localhost), every page deleted at the end, exit non-zero if any known case disagrees with its expectation (`known.json` entries carry `"expect":"PASS|DEAD"`).
- [ ] **Step 3: Run** `make up` (if not up) then `node scripts/verify-shapes.cjs fixtures/shapes/candidates/known.json`. Expected: the two good cases `PASS`, the two dead cases `DEAD`, exit 0, no throwaway pages left (`wp post list --post_status=draft` shows none created by the run).
- [ ] **Step 4: Implement `promote-shapes.php`** and run it on the results; the registry gains the good entries (not the dead ones). Add a PHPUnit assertion to `ShapeRegistryTest` that `covers('title.decoration.font.font.{bp}.value.size')` still holds and that the dead path `image.decoration.fit.{bp}.value.type` is NOT covered.
- [ ] **Step 5: Run** `vendor/bin/phpunit --filter ShapeRegistryTest`. Expected: PASS.
- [ ] **Step 6: Commit** `feat: effect probe that rejects shapes which render nothing (make verify-shapes)`.

### Task 3: Recipe tags and a filterable, compact catalog

**Files:**
- Modify: `wp-plugin/data/section-recipes.json` (add tags to the 17), `wp-plugin/src/SectionRecipes.php`
- Test: `tests/SectionRecipesTest.php`

**Interfaces:**
- Produces: each recipe gains `tags`: `{role: string, layout: string, mood: list<string>, density: "airy|balanced|dense", background: "solid|gradient|image|none", needs_images: bool}`. Controlled vocabularies as public constants on `SectionRecipes`: `ROLES = ['hero','proof','features','stats','testimonials','pricing','team','faq','process','cta','gallery','contact','header','transition','content','shop','blog']`, `LAYOUTS = ['centered','split','grid','bento','zigzag','stacked','carousel','overlap','band','list','form']`, `MOODS` filled from the design languages in Task 5 (initially `['any']`).
- Produces: `SectionRecipes::catalog(?string $role = null, ?string $layout = null, ?string $mood = null): string` (no filters = compact: recipe names grouped under role headings, a few words each, plus how to fetch one; with filters = the full one-line descriptions of the matches only; no match = a message listing valid values); `SectionRecipes::tags(string $name): ?array`; `SectionRecipes::suggest(array $avoidLayouts, array $avoidRoles, int $limit = 3): list<string>` (deterministic: recipes whose layout and role are not in the avoid lists, in catalog order).

- [ ] **Step 1: Write failing tests** in `tests/SectionRecipesTest.php`: `testEveryRecipeHasValidTags` (every recipe has all tag keys, `role` in `ROLES`, `layout` in `LAYOUTS`); `testCatalogFiltersByRole` (`catalog('hero')` contains `hero-cta` and not `contact-form`); `testUnknownFilterValueListsValidOnes`; `testDefaultCatalogIsCompact` (default catalog byte length is at most 2x the pre-change length, recorded as constant `1900` in the test after measuring the current catalog, and at most 4500 once recipes grow, assert `<= 2 * baseline`); `testSuggestSkipsAvoidedLayouts` (`suggest(['grid'], ['features'])` returns no recipe tagged grid or features).
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter SectionRecipesTest`. Expected: FAIL.
- [ ] **Step 3: Tag the 17 recipes** in the JSON (judging each by its actual markup: for example `card-grid-3` is `features`/`grid`, `hero-cta` is `hero`/`centered`, `split-image-text` is `features`/`split`), then implement the three methods. Keep `names()` and `recipe()` unchanged in behaviour.
- [ ] **Step 4: Run** `vendor/bin/phpunit --filter 'SectionRecipes|ShapeRegistry|Compliance'`. Expected: PASS (the existing catalog tests still find `_Stage:_`, `Hero`, `Social proof` in the catalog: keep those words in the compact form or adjust the test to the unfiltered "full" form it is really checking).
- [ ] **Step 5: Commit** `feat: tag section recipes and filter the catalog by role, layout and mood`.

### Task 4: Recipe filters on all three transports

**Files:**
- Modify: `wp-plugin/src/McpHandler.php` (`get_section_recipes` inputSchema and `toolSectionRecipes`), `wp-plugin/src/RestController.php` (`section_recipes` route args and handler), `wp-plugin/src/OpenApiSpec.php` (`/section-recipes` parameters)
- Test: `tests/SectionRecipesLockstepTest.php`

**Interfaces:**
- Consumes: `SectionRecipes::catalog($role,$layout,$mood)` from Task 3, `PageAccess::stringArg`.
- Produces: optional strict-string arguments `role`, `layout`, `mood` on MCP (`-32602` when not a string), REST (`400` when not a string) and OpenAPI (three query parameters, type string). `name` still wins when given.

- [ ] **Step 1: Write failing tests** `testMcpAcceptsRoleFilter` (call the handler like `DryRunLockstepTest` does, with `{"role":"hero"}`; result text contains `hero-cta`, not `contact-form`), `testMcpRejectsNonStringFilters` (`role: 5`, `layout: ["x"]`, `mood: true` each give a JSON-RPC error `-32602`), `testRestRejectsNonStringFilters` (400), `testOpenApiListsTheThreeParameters`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter SectionRecipesLockstepTest`. Expected: FAIL.
- [ ] **Step 3: Implement** in the three files; keep all other behaviour byte-identical (existing tests must still pass).
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: all green.
- [ ] **Step 5: Commit** `feat: role, layout and mood filters for get_section_recipes (MCP, REST, OpenAPI)`.

### Task 5: Design languages (data, loader, contrast)

**Files:**
- Create: `wp-plugin/data/design-languages.json`, `wp-plugin/src/DesignLanguages.php`, `tests/DesignLanguagesTest.php`
- Modify: `wp-plugin/src/autoload.php` (require after `ImageTokens.php`), `tests/WpOrgComplianceTest.php` (add `'src/DesignLanguages.php'` to the allowed local data read list next to `SectionRecipes`, line ~44)

**Interfaces:**
- Produces: `DesignLanguages::ids(): list<string>`; `DesignLanguages::get(string $id): ?array` returning `{id, title, summary, colors: {bg, surface, text, muted, accent, accent_text, border: hex}, fonts: {display: string, body: string, eyebrow: string}, radius: string, section_padding: string, shadow: string, motion: "none|subtle|lively", rhythm: string, favors: list<string> (recipe layouts), avoids: list<string>, hero: string, cta: string}`; `DesignLanguages::markdown(?string $id = null): string` (index when null, the full brief for one language otherwise); `DesignLanguages::contrast(string $fg, string $bg): float` (WCAG ratio from two hex colors).
- Starter data: two original languages, `editorial` (light, serif display, generous whitespace, asymmetric splits) and `glass-dark` (dark, deep gradient backgrounds, translucent cards). Written fresh; not copied from the layoutlab store. Fonts must be ones the style guide already shows or the Divi font list supports (`Playfair Display` appears in the guide).

- [ ] **Step 1: Write failing tests**: `testLanguagesLoadAndHaveEveryField`; `testEveryLanguagePaletteMeetsWcagAa` (`text`/`bg`, `text`/`surface`, `accent_text`/`accent`: ratio at least 4.5; `muted`/`bg` at least 3.0 for large/secondary text); `testContrastKnownValues` (`contrast('#000000','#ffffff')` is 21.0 within 0.01; `contrast('#777777','#ffffff')` below 4.5); `testUnknownIdReturnsNull`; `testMarkdownIndexListsEveryId`; `testMoodsMatchRecipeVocabulary` (the `MOODS` constant added to `SectionRecipes` equals `['any', ...DesignLanguages::ids()]`).
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter DesignLanguagesTest`. Expected: FAIL.
- [ ] **Step 3: Implement** the class and write the two languages, choosing hex values that pass the contrast test; set `SectionRecipes::MOODS` from `DesignLanguages::ids()` (add `'any'`), and re-tag the 17 recipes' `mood` to `['any']` (they are untokenised).
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: all green, including `WpOrgComplianceTest`.
- [ ] **Step 5: Commit** `feat: design languages with contrast-checked palettes`.

### Task 6: Recipe tokens and the `design_language` parameter

**Files:**
- Create: `wp-plugin/src/RecipeTokens.php`, `tests/RecipeTokensTest.php`
- Modify: `wp-plugin/src/SectionRecipes.php`, `wp-plugin/data/section-recipes.json` (tokenise `hero-cta`), `wp-plugin/src/McpHandler.php`, `wp-plugin/src/RestController.php`, `wp-plugin/src/OpenApiSpec.php`, `wp-plugin/src/autoload.php`, `tests/SectionRecipesLockstepTest.php`

**Interfaces:**
- Consumes: `DesignLanguages::get`.
- Produces: token grammar `{{aied:color:<role>}}` with roles `bg|surface|text|muted|accent|accent-text|border`, and `{{aied:font:<role>}}` with roles `display|body|eyebrow` (a `-` in the token for `accent_text`). `RecipeTokens::resolve(string $markup, array $language): string`; `RecipeTokens::default(): array` (the language used when none is requested: the recipe's original look, equal to `editorial`'s fields so no raw token can ever escape); `RecipeTokens::found(string $text): list<string>`. `SectionRecipes::recipe(string $name, ?string $language = null): ?string` resolves image tokens (as today) and then color/font tokens. An unknown language id returns null and the transports answer with the list of valid ids (MCP result text, REST 404 with `languages`).
- Produces: optional strict-string `design_language` on MCP / REST / OpenAPI.

- [ ] **Step 1: Write failing tests**: in `RecipeTokensTest`: `testResolvesColorAndFontTokens`, `testUnknownRoleStaysDetectableViaFound`, `testDefaultLanguageLeavesNoToken` (every recipe served with no language has `RecipeTokens::found(...) === []` and still validates with `Validator::validateContent`); `testEveryRecipeValidatesUnderEveryLanguage` (loop recipes x `DesignLanguages::ids()`); in the lockstep test: MCP `{"name":"hero-cta","design_language":"glass-dark"}` contains the language's `bg` hex and no `{{aied:`; `design_language: 7` is `-32602`; unknown id answers with the valid ids; REST equivalents (400, 404).
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'RecipeTokens|SectionRecipesLockstep'`. Expected: FAIL.
- [ ] **Step 3: Implement.** Tokenise `hero-cta`: replace its hard-coded background, text, accent and font values with tokens (leave every other recipe untouched; those are Plan 2). The registry lint from Task 1 must still pass.
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: all green.
- [ ] **Step 5: Commit** `feat: color and font tokens in recipes, resolved from a design language`.

### Task 7: Style guide topics

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php`, `wp-plugin/src/McpHandler.php`, `wp-plugin/src/RestController.php`, `wp-plugin/src/OpenApiSpec.php`
- Test: `tests/StyleGuideTopicsTest.php`

**Interfaces:**
- Produces: `StyleGuide::markdown(?string $topic = null): string`: no topic returns exactly today's text followed by one appended line `Topics: call get_style_guide with topic=<name>: design-languages, design-language:<id>.` (nothing else added); `StyleGuide::topics(): list<string>` (`design-languages` plus `design-language:<id>` for every id); a topic returns `DesignLanguages::markdown(...)`; an unknown topic returns the topic list under the heading `Unknown topic`. Optional strict-string `topic` on MCP (`-32602`), REST `?topic=` (400), OpenAPI parameter.

- [ ] **Step 1: Write failing tests**: `testDefaultGuideOnlyGrowsByTheTopicLine` (length of `markdown()` minus length of `markdown()` before the change, recorded as a constant, is at most 400 bytes); `testDesignLanguagesTopicListsAllIds`; `testLanguageTopicReturnsTheBrief`; `testUnknownTopicListsTopics`; transport tests as in Task 4 (`topic: []` is `-32602`; REST `topic` non-string 400).
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter StyleGuide`. Expected: FAIL (existing `StyleGuideTest` stays green).
- [ ] **Step 3: Implement** in the four files; the existing worked example and every existing assertion on `markdown()` remain valid.
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: all green.
- [ ] **Step 5: Commit** `feat: on-demand style guide topics and the design-languages topic`.

### Task 8: `DesignVariety` (pure)

**Files:**
- Create: `wp-plugin/src/DesignVariety.php`, `tests/DesignVarietyTest.php`
- Modify: `wp-plugin/src/autoload.php`

**Interfaces:**
- Consumes: `BlockParser`/`Block` (as `ContentLoss` uses them), `CodePolicy::tooLarge/tooDeep`, `SectionRecipes::suggest`.
- Produces: `DesignVariety::fingerprints(string $content): ?list<array{layout:string,cards:bool,background:string,focal:bool,depth:bool}>` (one entry per `divi/section`, null if unparseable or too large/deep); `DesignVariety::advise(string $content, ?string $previous = null): ?array` returning `{findings: list<{code:string, sections:list<int>, message:string, try:list<string>}>, message:string}` or null. Constants: `MIN_SECTIONS = 3`, `REPEAT_LIMIT = 3`, `CARD_SHARE = 0.34`, `MOTION_MIN_SECTIONS = 5`. Codes: `REPEATED_LAYOUT` (same layout fingerprint in adjacent sections, or at least `REPEAT_LIMIT` times on the page), `CARD_GRID_OVERUSE` (card-grid sections, meaning at least 3 equal columns each with the same module sequence, are more than `CARD_SHARE` of sections), `FLAT_BACKGROUNDS` (every section solid or none), `NO_FOCAL_ELEMENT` (first section has no background image or gradient, no image module and no heading of 48px / 3em / 4vw or larger), `NO_MOTION_OR_DEPTH` (no box shadow, transform, position, animation or scroll setting anywhere on a page of at least `MOTION_MIN_SECTIONS`). Silent (null) for fewer than `MIN_SECTIONS` sections, and when `$previous` is given and fewer than 2 section fingerprints differ and the section count is unchanged.

- [ ] **Step 1: Write failing tests** with small generated layouts (a helper builds sections): `testThreeIdenticalCardSectionsFlagRepeatedAndCardOveruse` (codes include both, `sections` are the 0-based indexes); `testVariedPageIsClean` (hero, split, grid, stats, cta with a gradient hero and a shadow is null); `testTwoSectionPageIsSilent`; `testGarbageAndEmptyContentReturnNullAndNeverThrow` (`''`, `'<!-- wp:divi/section'`, a 5 MB string); `testPlainFirstSectionFlagsNoFocal` (20px heading, white background) and `testLargeHeadingIsFocal` (`5vw`); `testEditThatChangesOneSectionIsSilentOnARepetitivePage` (previous and new differ in one section); `testFindingsSuggestRecipesFromOtherLayouts` (every name in `try` exists in `SectionRecipes::names()` and is not tagged with the flagged layout); `testSameInputSameOutput` (deterministic).
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter DesignVarietyTest`. Expected: FAIL.
- [ ] **Step 3: Implement.** A section's `layout` string is its rows' `columnStructure` values joined, followed by the ordered module names of its first row's columns (collapsing consecutive repeats of the same module); `cards` is true for a row of at least 3 columns whose module sequences are identical; `background` reads `module.decoration.background.desktop.value` (`gradient.enabled` is gradient, `image.url` is image, `color` is solid, else none); `depth` is true when any block has `boxShadow`, `transform`, `position`, `animation` or `scroll` under `module.decoration` or an element's `decoration`. Messages are plain English naming the sections (1-based in the text, 0-based in `sections`).
- [ ] **Step 4: Run** `vendor/bin/phpunit --filter DesignVarietyTest`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: deterministic design variety advisory`.

### Task 9: Attach the advisories everywhere

**Files:**
- Modify: `wp-plugin/src/StyleReferences.php` (`attach`), `tests/StyleAdvisoryHandlersTest.php`
- Create: `tests/DesignVarietyHandlersTest.php`

**Interfaces:**
- Consumes: `DesignVariety::advise`, `RecipeTokens::found`.
- Produces: `StyleReferences::attach` adds `advisories.design_variety` (the `advise` result) and `advisories.unresolved_tokens` (`{tokens: list<string>, message: string}` when `RecipeTokens::found($content)` or `ImageTokens::hasUnresolved($content)` is non-empty). No other path changes: every save, create, validate, dry-run and `html_to_divi` path already calls `attach`.

- [ ] **Step 1: Write failing tests** in `DesignVarietyHandlersTest` using the real handler call style of `StyleAdvisoryHandlersTest`: `validate_layout` on a repetitive 4-section page returns `valid: true` and `advisories.design_variety.findings`; a dry run and `update_page_layout` do too, and the stored content is byte-identical to the input (the advisory never rewrites); the same page via `edit_page_content` touching one section has no `design_variety`; a page containing `{{aied:color:accent}}` returns `advisories.unresolved_tokens.tokens` equal to `['{{aied:color:accent}}']` while still `valid`; REST parity for `validate`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'DesignVarietyHandlers|StyleAdvisoryHandlers'`. Expected: FAIL.
- [ ] **Step 3: Implement** in `attach` (keep it a thin composition like the existing advisories).
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: all green.
- [ ] **Step 5: Commit** `feat: attach design variety and unresolved-token advisories to every layout path`.

### Task 10: Teach composition in the guides

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php` (the "Design guidance" paragraph), `wp-plugin/src/LandingGuide.php`, `wp-plugin/src/SiteGuide.php`
- Test: `tests/GuideDesignDirectionTest.php`

**Interfaces:** none new.

- [ ] **Step 1: Write failing tests**: each of the three guides contains `design-languages` and tells the AI to pick a language first and state it; the style guide's design paragraph no longer says "ONE clear aesthetic direction" without pointing at the topic; each guide states the rhythm rules verbatim from this list: never the same layout in adjacent sections, alternate background treatment, at most one card grid per three sections, give the first section a focal element (image, gradient or large type); each guide mentions `advisories.design_variety` and says to act on it. Existing worked examples still validate (existing tests).
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter GuideDesignDirectionTest`. Expected: FAIL.
- [ ] **Step 3: Edit the three guides** (keep the default style guide growth within the Task 7 limit of 400 bytes beyond the topic line by trading the old paragraph for the new one; re-check `testDefaultGuideOnlyGrowsByTheTopicLine`, raising its recorded baseline only to the post-edit size and noting it in the commit).
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: all green.
- [ ] **Step 5: Commit** `docs: guides teach picking a design language and varying the rhythm`.

### Task 11: Measure, document, full gate

**Files:**
- Modify: `scripts/measure-tokens.php`, `docs/token-usage.md` (regenerated), `CLAUDE.md` (a short "Design richness foundations" entry under Architecture and the new `make verify-shapes` row in the entry-points table), `Makefile` (`verify-recipes` is Plan 2; only `verify-shapes` here)

- [ ] **Step 1: Extend `measure-tokens.php`** to report the default style guide, each topic, the default recipe catalog and one served recipe; run `php scripts/measure-tokens.php` and commit the regenerated `docs/token-usage.md`.
- [ ] **Step 2: Run the full gates**: `make test` (expect all green), `bash scripts/build-plugin-zip.sh` then Plugin Check on the extracted zip per CLAUDE.md (expect clean), `node scripts/verify-shapes.cjs fixtures/shapes/candidates/known.json` (expect the Task 2 result), `make verify-guide-shapes` (expect all PASS: nothing it covers changed).
- [ ] **Step 3: Update `CLAUDE.md`** (state what exists, that the registry is dev-side, that no release was made) and commit `docs: record the design richness foundations`.

---

## Self-review

- **Spec coverage:** registry + lint + probe (T1, T2); filterable tagged recipes and compact catalog (T3, T4); design languages with contrast tests (T5); tokens and `design_language` (T6); guide topics (T7); variety advisory and unresolved-token advisory (T8, T9); guides teaching rhythm (T10); token budget and docs (T11). Deliberately not here, all Plan 2: vocabulary mining batches (spec section 2), recipes beyond the 17 and `make verify-recipes` (section 3), the other 8 languages, moving long guide sections into topics, readme counts, and the contact-sheet artifact. Phase 2 is a separate design.
- **Step scan:** each step names its test, signature or command; bodies are left to the implementer where signature plus tests decide them.
- **Type consistency:** `SectionRecipes::catalog/tags/suggest/recipe` signatures are defined in T3/T6 and used in T4/T8; `DesignLanguages::get/ids/markdown/contrast` defined in T5 and used in T6/T7; `RecipeTokens::found` defined in T6 and used in T9; `ShapePaths`/`ShapeRegistry` defined in T1 and used in T2.
- **Proportion:** the plan is roughly twice the spec's length; each task is signatures, test names and commands.
