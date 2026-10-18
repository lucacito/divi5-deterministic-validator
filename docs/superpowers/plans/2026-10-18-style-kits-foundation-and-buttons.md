# Style Kits: foundation and buttons pilot, Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A style kit (a named table of border / radius / fill / hover / text / spacing decisions) is resolved into recipes at serve time through per-module adapters, proven end to end on `divi/button`, with a default kit per design language and a `style_kit` override.

**Architecture:** New pure classes `StyleKits` (data + schema), `KitAdapters` (decisions to attribute patch, one method per module) and `KitResolver` (finds markers and whole-value kit tokens in recipe markup, merges adapter output, strips markers). `SectionRecipes::recipe()` runs `ImageTokens`, then `KitResolver`, then the existing `RecipeTokens` (colour and font), so the adapter emits `{{aied:color:<role>}}` tokens and never a hex. Probes come first and decide which decisions the v1 vocabulary may contain.

**Tech Stack:** PHP 8 (plugin, PHPUnit via `make test`), Node + Playwright (probe and render scripts), dev Docker site with Divi 5.15.1.

**Spec:** `docs/superpowers/specs/2026-10-18-style-kits-design.md` (approved 2026-10-18). Read it first; this plan only adds what the spec leaves open.

## Global Constraints

- The validator (`src/`, `wp-plugin/validator/`) is NOT touched; `tests/ValidatorSyncTest` must stay green.
- No Divi setting from memory: every attribute path an adapter can emit is in `fixtures/shapes/verified-shapes.json` (proven by export or `scripts/verify-shapes.cjs`) before the adapter uses it. A candidate that measures DEAD or css-only is not used.
- No custom CSS, JS or PHP in output; no remote calls in `wp-plugin/`; no new MCP tool, REST route or OpenAPI operation (only optional parameters and `get_style_guide` topics).
- No push, release, version bump or zip rebuild. The tracked root `jhmg-ai-editor-for-divi-5.zip` stays the published 4.12.0 build (if `scripts/build-plugin-zip.sh` is run for Plugin Check, copy the result elsewhere and `git checkout --` the tracked zip).
- Size rule: `wp-plugin/data/section-recipes.json` ends every task no larger than it started (now 702,311 bytes; bound 700 KB = 716,800 stays). `style-kits.json` at most 120 KB and at most 1.5 KB per kit, decisions only (no markup, no hex colour, no `<`).
- Every kit works with every language: text pairs AA 4.5:1, a border or outline that carries meaning 3:1.
- Branch `feat/style-kits`. Max 3 parallel workers; ONE probe or render run at a time on the dev site; every throwaway page deleted by its run token.
- Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Plugin PHP style: match `RecipeTokens.php` (`declare(strict_types=1)`, `AiEditorDivi5\WP`, `if ( ! defined( 'ABSPATH' ) ) { exit; }`, spaces inside parentheses). Every new `wp-plugin/src/*.php` must be required in `wp-plugin/src/autoload.php` (`PluginAutoloadCoversEverySourceFileTest`).

## Review Focus

1. A button whose label or border colour equals the surface it sits on (invisible button): pinned for every kit x language x role x `on` value (Task 4).
2. Text that merely CONTAINS the marker spelling (`"aied":{"kit":"control"}` inside a text module's HTML or a heading) must never be rewritten: only block-comment attribute JSON is parsed (Task 3).
3. Unknown role, unknown `on`, a marker on a non-button block, a marker not at the top level of the attributes, a malformed attribute JSON: left untouched, reported by `KitResolver::leftovers()`, never guessed, never a PHP warning (Task 3).
4. Resolving twice equals resolving once; no marker or kit token survives in served output (Task 3, 5).
5. Unreadable or empty kit data: a recipe is never served with a marker or token unresolved; `recipe()` returns null like an unknown language (Task 5).
6. Unknown `style_kit`: refused with the list of valid ids, not silently defaulted (Task 5). `style_kit` without `design_language` uses the default language `editorial`.
7. Hostile or oversize values in kit data (a hex colour, `<`, `--`, a 10 KB string, a number where a string belongs) fail the schema test, so the data file can never break the attribute JSON (Task 2).

---

### Task 1: Probe batch for the button vocabulary

**Files:**
- Create: `fixtures/shapes/candidates/kit-button.json`
- Modify: `fixtures/shapes/verified-shapes.json`, `fixtures/shapes/probe-results-kit-button.json`, `docs/shape-evidence-5.15.1.md` (via `scripts/promote-shapes.php`)
- Create: `fixtures/shapes/kit-button-vocabulary.md` (the outcome table Tasks 2 and 4 read)

**Interfaces:**
- Produces: for each candidate, PASS / DEAD / css-only in the registry and in `kit-button-vocabulary.md`, as a table `decision | value forms that PASS | path(s)`.

- [ ] **Step 1: Write the candidates** in `fixtures/shapes/candidates/kit-button.json` (same format as `known.json` / `recipe-cta.json`; take every attribute path from the registry or from the Divi theme's own `module.json` / `Declarations` code in `divi/Divi.zip`, cite the source in each `note`, never from memory). One candidate per line below; each with a control page as the probe already does:
  - fill: `rgba(255,255,255,0)` background (already used by `hero-image-scrim`) renders no fill; `transparent` keyword (expect either; record which PASS)
  - border `styles.all.style` values `solid`, `dashed`, `dotted`, `double`; width 1, 2, 4px
  - partial border: only `styles.bottom` (width/style/colour) on a button; only left
  - hover on an outline button: border colour, background colour, font colour together
  - gradient: `gradient.enabled` plus the stops / colours paths the module definition shows
  - shadow: `module.decoration.boxShadow` on a button module; the button element's own shadow path if the definition has one; a hard shadow (blur 0, offset 6px); a hover shadow
  - transform: `module.decoration.transform` translateY on hover (lift); skew x
  - text: `capitalization` uppercase, `letterSpacing` 2px, `weight` 700
  - padding: `button.decoration.spacing` vertical 14px horizontal 28px
- [ ] **Step 2: Run** `node scripts/verify-shapes.cjs fixtures/shapes/candidates/kit-button.json` (Docker site up, no other probe or `make verify-recipes` running). Expected: every candidate gets a verdict; a candidate that disagrees with its `expect` is investigated, not edited away.
- [ ] **Step 3: Promote** with `php scripts/promote-shapes.php <results> fixtures/shapes/candidates/kit-button.json`; confirm `make test` is green (registry lint, `ShapeRegistryTest`).
- [ ] **Step 4: Write `fixtures/shapes/kit-button-vocabulary.md`**: the table of what v1 may use, one row per decision (`fill`, `border width/style/colour`, `radius`, `hover`, `shadow rest/hover`, `lift`, `text`, `padding`), marking each as v1 / later (probed but deferred) / not taught (DEAD or css-only). Anything not PASS is not v1.
- [ ] **Step 5: Commit** `probe: button kit vocabulary (evidence, registry, outcome table)`.

### Task 2: `StyleKits` data, schema and loader

**Files:**
- Create: `wp-plugin/data/style-kits.json`, `wp-plugin/src/StyleKits.php`, `tests/StyleKitsTest.php`
- Modify: `wp-plugin/src/autoload.php`

**Interfaces:**
- Produces (`final class AiEditorDivi5\WP\StyleKits`):
  - `all(): list<array<string,mixed>>`, `ids(): list<string>`, `get(string $id): ?array`, `parse(string $raw): list<array>` (corrupt text = `[]`, never an error), `clearCache(): void`
  - `problems(array $kit): list<string>` the schema check (empty list = valid)
  - `valueToken(array $kit, string $name): ?array` for `radius-control|radius-card|radius-media|space-control|space-card`, null for any other name. Radius returns `['topLeft'=>v,'topRight'=>v,'bottomRight'=>v,'bottomLeft'=>v]`; `space-control` returns `['top'=>..,'right'=>..,'bottom'=>..,'left'=>..,'syncVertical'=>'on','syncHorizontal'=>'on']` in the form the registry's `button.decoration.spacing` paths prove; `space-card` the same four sides.
  - `markdown(?string $id = null): string` one-line-per-kit catalog, or one kit's decisions
- Kit shape (all keys required unless marked): `id` kebab-case, `title`, `summary` (at most 160 chars), `avoids` (optional list of layout ids, hint only), `fill` in `solid|outline|ghost`, `color` in `accent|text|inverse` (role carrying the fill, outline or label), `border` `{width: "0px".."4px", style: one of the Task 1 v1 styles, color: accent|text|border|inverse|same}`, `radius` `{control, card, media}` as `Npx` (0 to 48) or `999px`, `shadow` `{rest, hover}` each `none` (more only if Task 1 marks it v1), `hover` in `fill-swap|invert|none` (plus `lift` / `darken` only if Task 1 marks them v1), `text` `{capitalization: none|uppercase, letterSpacing: "0px".."4px", weight: "400".."800"}`, `space` `{control: {vertical, horizontal}, card}` as `Npx`.
- Consumes: Task 1's `kit-button-vocabulary.md` (the allowed `border.style` list and extra hover/shadow values).

- [ ] **Step 1: Failing tests** in `tests/StyleKitsTest.php`: `testEveryShippedKitIsValid` (`problems()` is `[]` for each; ids unique kebab-case), `testProblemsRejectsWhatItShould` (a data provider of bad kits: a hex colour anywhere, a value containing `<`, `>`, `&`, `--` or a quote, a 10 KB string, a number where a string belongs, unknown `fill`, radius `-1px` and `49px` and `1em`, an unknown extra key, missing `border`; each yields a non-empty list), `testTheFileStaysSmall` (file at most 120 KB, each kit's `json_encode` at most 1,536 bytes, no value looks like markup), `testParseOfCorruptTextIsEmpty`, `testValueTokenForeignNameIsNull`, `testValueTokenRadiusAndSpaceShapes`, `testMarkdownListsEveryKitOnce`.
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/StyleKitsTest.php`; expected FAIL (class missing).
- [ ] **Step 3: Implement** `StyleKits` as above, reading `data/style-kits.json` (a JSON list, memoised like `DesignLanguages`, same `parse` leniency). Ship exactly two kits now so the mechanism is provable: `soft-solid` (solid, accent, no border, radius 8px) and `outline-2` (outline, text colour role, 2px solid border, radius 4px, hover `fill-swap`). Add the file to `autoload.php`.
- [ ] **Step 4: Run** the test file and `vendor/bin/phpunit tests/PluginAutoloadCoversEverySourceFileTest.php`; expected PASS.
- [ ] **Step 5: Commit** `feat: style kits data, schema and loader`.

### Task 3: `KitResolver` (markers, whole-value tokens, encoder)

**Files:**
- Create: `wp-plugin/src/KitResolver.php`, `tests/KitResolverTest.php`
- Modify: `wp-plugin/src/RecipeTokens.php` (make the JSON-safe encoder reusable; extend `advise()`), `wp-plugin/src/autoload.php`

**Interfaces:**
- Produces:
  - `KitResolver::resolve(string $markup, array $kit): string`
  - `KitResolver::leftovers(string $markup): list<string>` the markers and `{{aied:kit:...}}` tokens still present (fixed, bounded descriptions, never echoing content)
  - `RecipeTokens::encodeJson(mixed $value): string` (public; `json_encode` with the flags `jsonSafe()` uses, then the same `< > & --` replacements over the whole string); `jsonSafe()` keeps its behaviour.
- Marker spelling (pinned): a TOP-LEVEL attribute of a block comment, `"aied":{"kit":"<role>"}` or `"aied":{"kit":"<role>","on":"<surface>"}`. Roles in v1: `control`, `control-secondary` (only valid on `divi/button`); `on` in `light|accent|dark`, default `light`. Whole-value tokens: an attribute value that is exactly the JSON string `"{{aied:kit:<name>}}"` (a name from `StyleKits::valueToken`) is replaced by that object.
- Consumes: `KitAdapters::patch(string $blockName, string $role, string $on, array $kit): ?array` from Task 4 (a stub returning null until then is acceptable; tests use a fake adapter by injection: give `resolve()` an optional third parameter `?callable $adapter = null`).

- [ ] **Step 1: Failing tests** (`KitResolverTest`): `testMarkedButtonGetsAdapterPatchAndLosesMarker` (recipe keys not in the patch survive, patch keys win, key ORDER of untouched keys preserved), `testOnlyMarkedBlocksAreReencoded` (every other block comment in a real recipe is byte-identical), `testTextThatContainsTheMarkerIsNeverRewritten` (a `divi/text` whose innerContent contains the marker spelling, both inside the comment JSON string and in the HTML after it), `testUnknownRoleOnOrBlockAreLeftAndReported` (table of cases incl. marker not top level, malformed JSON, role `control` on `divi/blurb`, `on:"sideways"`), `testWholeValueTokenBecomesObjectAndPartialDoesNot` (`"x {{aied:kit:radius-card}}"` untouched and reported), `testResolveIsIdempotentAndLeavesNoMarker`, `testOutputIsJsonSafeWithHostileKitStrings` (call `resolve` with a hand-built kit whose strings bypass the schema: output still decodes and contains no raw `<`, `>`, `&`, `--` inside a comment), `testEveryShippedRecipeStillParsesUnchanged` (resolve with the default kit over all 71 recipes: output equals input for recipes with no marker), `testAdviseReportsALeftoverMarker` (`RecipeTokens::advise()` returns the fixed entry for a leftover marker; unchanged for markup without one).
- [ ] **Step 2: Run** `vendor/bin/phpunit tests/KitResolverTest.php`; expected FAIL.
- [ ] **Step 3: Implement** a block-comment scanner (regex for `<!-- wp:divi/<name> {json} -->` and `/-->`, as `HtmlToDivi`/`ShapePaths` tolerate; decode with `json_decode(..., true)`, depth-limited; a failed decode means leave and report), merge with a recursive array merge where the patch wins and lists are replaced not appended, re-encode with `RecipeTokens::encodeJson`, strip the marker. Extend `advise()` so a leftover marker adds the fixed entry `{"aied":{"kit":...}}` once (no echo of the real content).
- [ ] **Step 4: Run** the test file and `tests/RecipeTokensTest.php`; expected PASS.
- [ ] **Step 5: Commit** `feat: KitResolver (markers, whole-value kit tokens), shared JSON-safe encoder`.

### Task 4: Button adapter and contrast proof

**Files:**
- Create: `wp-plugin/src/KitAdapters.php`, `tests/KitAdaptersTest.php`
- Modify: `wp-plugin/src/autoload.php`

**Interfaces:**
- Produces: `KitAdapters::patch(string $blockName, string $role, string $on, array $kit): ?array` returning the attribute subtree to merge (for `divi/button`: under `button.decoration.{background,border,font,spacing}` at `desktop` only; `innerContent` and `module` are never touched), null for any other block/role/`on`. Colours are emitted as `{{aied:color:<role>}}` tokens; the radius and spacing as whole-value kit tokens `{{aied:kit:radius-control}}` / `{{aied:kit:space-control}}`. Every path the method can emit is in the registry; it writes EVERY path it controls explicitly (an outline button gets the transparent background value Task 1 proved, not an absent key), because an absent key falls back to the site's module preset.
- Role table (pinned, `L` = light, `A` = accent band, `D` = dark photo/overlay; `c` = kit colour role):

  | kit.fill | primary `control` | secondary `control-secondary` |
  |---|---|---|
  | solid | L: fill c, label on(c), border = kit border; A: fill `surface`, label `text`; D: fill c, label on(c) | the kit's `outline` treatment of the same colour |
  | outline | L: transparent, label c, border c at kit width; A and D: transparent, label and border `inverse` | the kit's `solid` treatment |
  | ghost | L: transparent, label c, border width 0; A and D: label `inverse` | the kit's `outline` treatment |

  `on(accent)=accent_text`, `on(text)=bg`, `on(inverse)=text`. `hover`: `fill-swap` swaps the filled and transparent treatments of the same colours; `invert` swaps label and fill colours; `none` repeats the rest values for the hover paths. `text` and `space` decisions map to the font and spacing paths; the radius is always the `control` step.
- Consumes: `StyleKits` kit shape, Task 1 vocabulary.

- [ ] **Step 1: Failing tests** (`KitAdaptersTest`): `testEveryEmittedPathIsInTheRegistry` (use `tests/Support/ShapeRegistry` and `ShapePaths`), `testPatchValidatesInsideAButtonBlock` (merge into a minimal `divi/button`, resolve tokens, run the real `LayoutGate`), `testNoHexLiteralsAndNoUnresolvedTokensBeyondColourAndKit`, `testUnknownCombinationsAreNull`, `testOutlineWritesTheTransparentBackgroundExplicitly`, and the key proof `testNoInvisibleButtonInAnyKitLanguageRoleSurface`: for every shipped kit x every design language x `control`/`control-secondary` x `light`/`accent`/`dark`, compute label-on-fill contrast (at least 4.5), border-vs-surface (at least 3.0, where the border carries the shape: outline and ghost-with-border) and the same for the hover state; `L` surface is `bg` and `surface`, `A` is `accent`, `D` is `overlay` composited on a mid grey (state the assumption in the test and reuse `RecipeLibraryTest`'s colour helpers by moving them to `tests/Support/Colour.php` rather than copying). A combination that cannot pass is a kit data bug (fix the kit or add a documented `avoids`), never a relaxed bound.
- [ ] **Step 2: Run**; expected FAIL.
- [ ] **Step 3: Implement** `KitAdapters::patch` for `divi/button` per the role table.
- [ ] **Step 4: Run** the new tests and `vendor/bin/phpunit tests/KitResolverTest.php tests/RecipeLibraryTest.php`; expected PASS.
- [ ] **Step 5: Commit** `feat: button adapter for style kits with contrast proof`.

### Task 5: Languages get a kit; `style_kit` reaches the AI

**Files:**
- Modify: `wp-plugin/data/design-languages.json` (add `"kit"` to each of the 10), `wp-plugin/src/DesignLanguages.php`, `wp-plugin/src/SectionRecipes.php`, `wp-plugin/src/McpHandler.php`, `wp-plugin/src/RestController.php`, `wp-plugin/src/OpenApiSpec.php`
- Test: `tests/DesignLanguagesTest.php`, `tests/SectionRecipesTest.php`, `tests/SectionRecipesLockstepTest.php`, `tests/McpProtocolTest.php`, `tests/OpenApiSpecTest.php`

**Interfaces:**
- Produces: `SectionRecipes::recipe(string $name, ?string $language = null, ?string $kit = null): ?string`: kit = explicit `$kit` ?? the language's `kit` ?? null; unknown language or kit id, or unreadable kit data, returns null (never partly resolved). Order: `ImageTokens`, `KitResolver::resolve`, `RecipeTokens::resolve`. Optional string parameter `style_kit` next to `design_language` on `get_section_recipes` (MCP), `GET /section-recipes` (REST), OpenAPI `getSectionRecipes`; same strict-string rule (non-string = -32602 / 400), used only together with `name` like `design_language`; unknown id: MCP text listing `StyleKits::markdown()`, REST 404 `{error, kits: ids}`. Each language's `kit` must name an existing kit (`DesignLanguagesTest`); `DesignLanguages::markdown($id)` gains a line `Style kit: <id>`.
- Consumes: Tasks 2 to 4.

- [ ] **Step 1: Failing tests**: language `kit` field present and resolvable for all 10; `recipe($n, 'brutalist-flat')` differs from `recipe($n, 'editorial')` in the button border and radius for a migrated recipe (after Task 7; until then use an injected fixture recipe); `recipe($n, null, 'outline-2')` honours the override; unknown kit returns null; `style_kit` strict-string and unknown-kit contract on MCP and REST; OpenAPI parameter present and described; `SectionRecipesLockstepTest` updated for the parameter; `testNoServedRecipeContainsAMarkerOrKitToken` over all recipes x languages x kits.
- [ ] **Step 2: Run** the listed test files; expected FAIL.
- [ ] **Step 3: Implement** the signatures above. Assign the 10 languages their default kit using only `soft-solid` and `outline-2` for now (Task 8 replaces them with the final per-language kits; record the interim in the commit message).
- [ ] **Step 4: Run** `make test`; expected all green.
- [ ] **Step 5: Commit** `feat: design languages name a default style kit; style_kit parameter on get_section_recipes`.

### Task 6: Guide topics `style-kits` and `style-kit:<id>`

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php`; Test: `tests/StyleGuideTopicsTest.php`, new `tests/StyleGuideKitTopicsTest.php`; `docs/token-usage.md` via `scripts/measure-tokens.php`

**Interfaces:**
- Produces: `StyleGuide::topics()` gains `style-kits` and one `style-kit:<id>` per kit (collapsed to `style-kit:<id>` in the topic line like the language entry); `markdown('style-kits')` = compact catalog (`StyleKits::markdown()`) plus how to choose and the sentence that a kit is a bundle of decisions applied to a module; `markdown('style-kit:<id>')` = the kit's decisions and, for each adapted module (button only now), the RESOLVED attribute snippet for each role/`on` under the language's colour roles written as `{{aied:color:<role>}}`-free real JSON for the `editorial` language, so the AI can build a button in that kit without a recipe. Each topic at most 10,000 bytes; the default guide still under its ceiling (29,108 bytes; if the two new topic names exceed it, raise nothing silently: report to the controller).
- Consumes: `StyleKits::markdown`, `KitAdapters::patch`, `KitResolver::resolve`, `RecipeTokens::resolve`.

- [ ] **Step 1: Failing tests**: topics listed and unknown id answered like unknown language; bound 10,000 bytes each; the button snippet inside `style-kit:outline-2` validates through `LayoutGate` and passes the registry lint (reuse the helper `StyleGuideTopicsTest` uses); the default guide names both topics and stays under its ceiling.
- [ ] **Step 2: Run**; expected FAIL.
- [ ] **Step 3: Implement** both topics.
- [ ] **Step 4: Run** `make test`; then `php scripts/measure-tokens.php` and commit the regenerated `docs/token-usage.md`.
- [ ] **Step 5: Commit** `feat: style-kits guide topics`.

### Task 7: Migrate the 28 recipe buttons

**Files:**
- Modify: `wp-plugin/data/section-recipes.json` (the 22 recipes that contain `divi/button`), tests that read raw markup only if they now need resolved markup (`RecipeLibraryTest` colour-pair and literal-colour helpers: resolve each recipe under each language and kit first)
- Create: `scripts/kit-migration-report.php`

**Interfaces:**
- Marker choice per button (pinned from the audit of today's 28 buttons): solid accent buttons become `control`; the outlined `text`/`bg`/`surface` buttons beside a solid one become `control-secondary`; `hero-image-scrim`'s inverse outline stays `control-secondary` with `"on":"dark"`; `cta-band`'s surface button on the accent band is `control` with `"on":"accent"`. A button keeps its `module`, `innerContent` (text, link) and layout keys; it loses `button.decoration.{background,border,font colour,spacing}` exactly the paths the adapter writes. Baked pill/`0px` radii on buttons are dropped (the kit decides; spec decision 5).
- Produces: `scripts/kit-migration-report.php` writes `dist/kit-migration-report.md` (git-ignored): for each of the 28 buttons x 10 languages, the property differences between the OLD resolved markup (`git show 97f0774:wp-plugin/data/section-recipes.json`) and the new one (fill, border, radius, label colour, hover), plus the file size before and after.

- [ ] **Step 1: Failing test** `testEveryRecipeButtonIsKitDriven` in `RecipeLibraryTest`: no raw recipe button carries a literal `background`/`border`/`font.color` path; the raw file is smaller than 702,311 bytes; the 700 KB bound still holds.
- [ ] **Step 2: Run**; expected FAIL.
- [ ] **Step 3: Migrate** all 28 buttons (scripted edit with a throwaway script in the scratchpad, reviewed by diff; keep unrelated bytes identical). Move `RecipeLibraryTest` helpers onto resolved markup where a raw read now misses the button colours.
- [ ] **Step 4: Run** `make test` (expect green), then once the dev site is free `node scripts/verify-recipes.cjs --names <the 22> --languages editorial,glass-dark,brutalist-flat,playful-pastel` and require 0 FAIL (PASS and the known WARNs only), plus the unchanged `ungrounded-recipe-paths.txt` stays empty (`php scripts/list-ungrounded-recipe-paths.php`).
- [ ] **Step 5: Commit** `feat: recipe buttons are driven by style kits`; report the file-size delta and attach a summary of `kit-migration-report.md` to the controller.

### Task 8: One default kit per language, and the kit family

**Files:**
- Modify: `wp-plugin/data/style-kits.json`, `wp-plugin/data/design-languages.json`, `tests/StyleKitsTest.php`, `tests/DesignLanguagesTest.php`

**Interfaces:**
- Produces: ten kits, one per language, each matching that language's `radius` and `shadow` prose (editorial: 4px, solid, no shadow, fill-swap hover; brutalist-flat: 0px, thick 4px border, uppercase; soft-saas and playful-pastel: large radius or pill; luxe, glass-dark, tech-mono, corporate-trust, organic-warm, bold-vibrant: derive from each language's prose; use only v1 vocabulary). Plus the kits that survive Task 9. A test generates the check that the language prose and its kit agree on the corner radius family (square 0 to 4px, soft 8 to 16px, round 20px+, pill 999px) and fails on a contradiction.
- Consumes: Task 1 vocabulary; language prose.

- [ ] **Step 1: Failing tests**: each language's default kit exists and its radius family matches the prose keyword family; kit ids unique; Task 4's contrast test still passes for all kits.
- [ ] **Step 2: Run**; expected FAIL.
- [ ] **Step 3: Author** the ten kits and point each language at its own; keep `soft-solid` and `outline-2`.
- [ ] **Step 4: Run** `make test`.
- [ ] **Step 5: Commit** `feat: a default style kit per design language`.

### Task 9: The button catalog from the owner's sheet (needs the sheet)

**Blocked until** the owner puts the 125-button sheet image in the repo (proposed `docs/inspiration/button-sheet-125.png`, ask the controller; the image was shared in chat in an earlier session and is not on disk).

**Files:**
- Create: `docs/button-sheet-classification.md`, further kits in `wp-plugin/data/style-kits.json`, probe candidates for the "needs a probe" group (reuse Task 1's file or a second `kit-button-2.json`)

**Interfaces:**
- Produces: a classification of all 125 sheet buttons into buildable now (v1 vocabulary), needs a probe (gradient, dashed/dotted, partial border, skew, hard shadow, if Task 1 left them undecided), needs custom CSS (skipped, listed with the reason). The buildable ones collapse into original kits (inspired by, not copies of, the sheet), expected 25 to 35 distinct decision sets (colour is the language's job, so colour-only variants merge); each kit gets a title and a one-line summary a human would recognise ("outlined 2px", "pill soft", "underline link"). Probed-and-passing ones are added to the vocabulary (extend `StyleKits::problems()` and `KitAdapters` in the same task with tests) and used by further kits.

- [ ] **Step 1:** Classify the sheet in `docs/button-sheet-classification.md` (table: sheet number, short description, class, kit id or skip reason).
- [ ] **Step 2:** Probe the "needs a probe" group (one run at a time), promote, update `kit-button-vocabulary.md`.
- [ ] **Step 3: Failing tests** for each new vocabulary value (schema accepts it, adapter emits only registry paths, contrast test covers it).
- [ ] **Step 4: Implement** the vocabulary extension and author the kits.
- [ ] **Step 5: Run** `make test`; **Commit** `feat: button kits from the owner's sheet, with classification`.

### Task 10: `make verify-kits` render gate

**Files:**
- Create: `scripts/verify-kits.cjs`; Modify: `Makefile`

**Interfaces:**
- Produces: `make verify-kits` (flags `--kits a,b`, `--languages x,y`; default: every kit under `editorial` and `glass-dark`, plus every language under its own default kit). For each cell it creates ONE throwaway draft page (title token `aied kit <runToken> ...`, deleted at the end like `verify-recipes`) holding a "kit sheet": a primary and a secondary button on each surface (`light` on bg, `light` on surface, `accent` band, `dark` photo band). It renders at 1280/900/400 and fails on a PHP error, horizontal overflow, a button whose computed style disagrees with the kit's decisions (border width, style and colour, border-radius, background colour, label colour, text-transform), or contrast below the bounds in Global Constraints. Prints one `PASS|FAIL <kit>/<language>/<width>` line each and writes `dist/kit-sheet/index.html`. Dev site only (refuse a non-local site like the other verify scripts). Reuse `verify-recipes.cjs` helpers by extracting them into `scripts/lib/` if that is smaller than copying; do not copy-paste more than a few lines.

- [ ] **Step 1: Write** the script and a unit test for its pure parts (expected-vs-computed comparison, run-token title), mirroring `scripts/test-recipe-measure.cjs`.
- [ ] **Step 2: Run** `node scripts/test-recipe-measure.cjs`-style test for the new pure part; expected PASS.
- [ ] **Step 3: Run** `make verify-kits` once the dev site is free; expected 0 FAIL, every created page deleted (check `wp post list --post_type=page --s="aied kit"` is empty).
- [ ] **Step 4: Commit** `feat: make verify-kits renders every style kit on the real Divi`.

### Task 11: Docs and whole-branch review

**Files:**
- Modify: `CLAUDE.md` (a "Style kits" paragraph under Design richness, the new `make verify-kits` row, current state), `docs/token-usage.md` (regenerated), the readme only if it states a count that changed (`WpOrgComplianceTest`)

- [ ] **Step 1:** Update CLAUDE.md in the existing voice and numbers (kits, topics, gate, size rule, what is deferred: card adapter next, accordion/tabs/blurb/forms later, image-to-layout later).
- [ ] **Step 2:** Run `make test`, `make test-pro`, `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` (must be empty), `make verify-recipes` (full default run, one run at a time), `make verify-kits`.
- [ ] **Step 3:** Dispatch the whole-branch review (fresh reviewer, the spec and this plan as input); fix findings in rounds.
- [ ] **Step 4: Commit** `docs: style kits (CLAUDE.md, token usage)`; the controller then reports to the owner. Nothing is pushed, released or version-bumped.
