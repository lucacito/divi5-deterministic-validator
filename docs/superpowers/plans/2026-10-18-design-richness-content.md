# Design Richness: Content and Vocabulary Implementation Plan (plan 2 of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Grow what the AI can build from: a render-proven Divi vocabulary (backgrounds, dividers, motion, layout, typography), 10 design languages, and a recipe library of 60 or more distinct, tokenised, tagged sections that all render cleanly.

**Architecture:** Plan 1 (merged to main at 8030639) built the machinery. This plan feeds it. Every new Divi setting enters through the effect probe and the shape registry; every recipe is composed only from registered shapes, gated by validation, a real-Divi render at three widths (`make verify-recipes`) and contrast pairs; the guides learn the new vocabulary through on-demand `get_style_guide` topics so the default answer does not grow.

**Tech Stack:** PHP 8.1 + PHPUnit (`vendor/bin/phpunit`, Pro: `-c pro-addon/phpunit.xml`), Node + Playwright (`scripts/*.cjs`), Docker dev site with real Divi 5.15.1 (`make up`), Divi's own sources in `divi/Divi-5-15-1.zip`.

**Spec:** `docs/superpowers/specs/2026-10-17-design-richness-design.md`. Plan 1: `docs/superpowers/plans/2026-10-17-design-richness-foundations.md`.

## Global Constraints

- The validator stays pure and deterministic; `src/` canonical, `wp-plugin/validator/` its mirror. This plan changes neither.
- No Divi schema from memory: every shape must trace to a real builder export, or to Divi's own `module.json`/PHP style classes, proven by a live render on the real Divi. A shape the probe measures DEAD is never taught; it is listed under "not taught" in the evidence doc.
- The free plugin never saves custom CSS, JS or PHP, and never uses a Divi Code module. `css.{bp}.value.freeForm` and anything under a module's `css` key is NEVER taught (it is in the registry only because a real export contains it). No backdrop blur. No video file or URL in any recipe (no stock video; background video is not taught).
- No licence-gating, remote fetching or custom-code saving in `wp-plugin/`; `tests/WpOrgComplianceTest.php` is not weakened (a new local data-file read gets an explicit allowlist entry).
- No new MCP tool, REST route or OpenAPI operation (the ChatGPT importer's 30-operation budget is used up); MCP, REST and OpenAPI stay in lockstep for any parameter.
- No release, version bump, SVN step or rebuild of the tracked root zip `jhmg-ai-editor-for-divi-5.zip` (it must stay byte-identical to the published 4.12.0 build; `scripts/build-plugin-zip.sh` overwrites it: run it only into a copy and `git checkout --` the tracked file afterwards).
- The default `get_style_guide` answer must not grow beyond the Plan 1 ceiling: `strlen(StyleGuide::markdown()) <= 27908 + 1200`. New vocabulary lives in topics, each at most 10,000 bytes.
- String arguments are type-checked (non-strings are 400 / -32602), never cast. Any text that quotes caller input back goes through `SectionRecipes::plain()`.
- Dev-site safety: live scripts refuse non-localhost, create only their own throwaway pages (title prefix `aied ` plus a script-specific word), delete exactly those by title prefix (never by a search), and never touch existing posts, options, presets, colors or variables.
- Max 3 parallel Claude workers if tasks are ever parallelised. Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Work on branch `feat/design-richness-2`.

## Review Focus

- A recipe that validates but renders broken or overflows at 400 px, or that reads as the same skeleton as another recipe with only colours changed (cosmetic variety).
- Text unreadable under some design language (a tokenised recipe whose text role sits on a background role that fails WCAG AA in one language).
- A taught shape that works on a section but is dead on a row/column/module (the topic claims the wrong element).
- A language value (font name, hex, rgba) that breaks the block-comment JSON when inserted (`"`, `\`, newline), or a font Divi cannot load.
- The default guide, the recipe catalog or a topic growing past its token budget so the AI skims or ignores it.

## Recipe batch protocol (referenced by Tasks 12-16)

A recipe is accepted only when ALL of these hold; a batch task's tests encode them:
1. JSON entry in `wp-plugin/data/section-recipes.json` with `name` (kebab-case, unique), `title`, `description`, `when`, `stage`, `markup`, and `tags` = `{role, layout, mood, density, background, needs_images, pairs}` where `pairs` is a list of `[foregroundRole, backgroundRole]` colour-role pairs the recipe actually puts text on (e.g. `["text","surface"]`, `["accent-text","accent"]`, `["inverse","overlay"]`). `layout` is a value of `SectionRecipes::LAYOUTS`; `role` of `ROLES`; `mood` lists language ids it suits or `["any"]`.
2. Every attribute path is in `fixtures/shapes/verified-shapes.json` (`ShapeRegistryTest::testEveryRecipePathIsRegistered`, no new entry in `ungrounded-recipe-paths.txt`).
3. Colours and fonts are tokens (`{{aied:color:<role>}}`, `{{aied:font:<role>}}`); the only literal colours allowed are pure white/black used as documented neutral scrims. The recipe validates (`Validator::validateContent` inside the placeholder wrapper) under EVERY language id, serves with no remaining token, and every `pairs` entry meets WCAG AA (4.5, 3.0 for large text) in every language.
4. Structure is distinct: its `DesignVariety` fingerprint layout string differs from every other recipe's (tested across the whole library), unless the tag set differs materially in `layout` AND `background`.
5. Real-Divi render (`make verify-recipes`, Task 2): no PHP errors/notices, no horizontal overflow at 1280/900/400 px, phone layout stacks multi-column rows, images use only `{{aied:image:<token>}}` tokens from the built-in pack, no remote host.
6. Responsive shapes follow the guide (`columnStructure`/`flexColumnStructure`/phone `flexWrap` rules) and exactly one `h1` per recipe at most (the hero), headings otherwise h2-h4.

---

### Task 1: Probe v2 and parked test hygiene

**Files:**
- Modify: `scripts/verify-shapes.cjs`, `scripts/promote-shapes.php`, `tests/ShapeRegistryTest.php`, `tests/DesignVarietyTest.php`, `tests/SectionRecipesLockstepTest.php`, `tests/StyleGuideTopicsTest.php`, `wp-plugin/src/DesignLanguages.php`, `wp-plugin/src/SectionRecipes.php`
- Create: `fixtures/shapes/candidates/known-extended.json`

**Interfaces:**
- Produces: candidate file format unchanged plus optional `"parent": "<block name>"` (the block is placed inside a wrapper of that module, e.g. `group` containing a heading) and `"children": [<block spec>]`; `node scripts/verify-shapes.cjs <candidates.json> [--out <results.json>]` (default output path becomes `fixtures/shapes/probe-results-<candidates basename>.json`, never the committed `probe-results-5.15.1.json` unless asked); per-candidate failures are recorded as `ERROR <id>: reason` and the run continues; a candidate with `phoneOverflow` prints `OVERFLOW`, not `PASS`; SIGINT/SIGTERM trigger the same title-prefix cleanup; supported blocks: section, row, column, group, heading, text, image, button, blurb, divider, number-counter, testimonial, icon (more may be added per need). `DesignLanguages::clearCache()` and `SectionRecipes::clearCache()` (test helpers; the loaders memoise in a static).

- [ ] **Step 1: Write failing tests** for the parked items: (a) `DesignVarietyTest::testExclusionStopsTheFlaggedRecipeBeingSuggested` for the crafted page hero-cta + card-grid-4 x3 + blurb-grid x3 (`try` must not contain `card-grid-4`; verify it FAILS when the exclusion in `DesignVariety::finding` is bypassed via a temporary local edit, then restore); (b) `ShapeRegistryTest` negative cases using a fake registry/text: an unregistered path in a recipe fails, a new unregistered guide path fails the ratchet, `covers()` exact-leaf method `coversLeaf()` rejects a proper prefix; (c) the Task 4 minors: `SectionRecipesLockstepTest` bad filter plus `name` is refused on MCP and REST, an unknown `layout` and `mood` each give the 200 + message answer, and the unfiltered-catalog test asserts a frozen heading line instead of comparing `catalog()` with itself; (d) `StyleGuideTopicsTest`: object/`''` topic on the transports; (e) a language-and-recipe memoisation test (second call does not re-read the file; `clearCache()` restores).
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'DesignVariety|ShapeRegistry|SectionRecipesLockstep|StyleGuideTopics|DesignLanguages|SectionRecipesTest'`. Expected: FAIL.
- [ ] **Step 3: Implement** the memoisation + `clearCache()` in both loaders, `ShapeRegistry::coversLeaf`, and the probe changes above (per-candidate error isolation, `parent`/`children`, extra block builders, safe default output path, signal cleanup, `OVERFLOW` label, quote-safe candidate ids via the base64 payload). Add `fixtures/shapes/candidates/known-extended.json` with: a `group` containing a heading with a known-good padding shape (`PASS`), a known-dead `group` shape (`DEAD`), an image-inside-column case, and one deliberately erroring candidate (unsupported block) that the run reports as `ERROR` while still finishing the others.
- [ ] **Step 4: Run** `vendor/bin/phpunit` (expect green) then `node scripts/verify-shapes.cjs fixtures/shapes/candidates/known.json` (expect PASS, PASS, DEAD, DEAD, exit 0) and `node scripts/verify-shapes.cjs fixtures/shapes/candidates/known-extended.json --out <scratch>/ext.json` (expect the PASS/DEAD lines, one `ERROR` line, other results still written). Show that no `aied shape probe ` page remains.
- [ ] **Step 5: Commit** `test: probe v2, loader memoisation and the parked test hygiene`.

### Task 2: `make verify-recipes` and the contact sheet

**Files:**
- Create: `scripts/verify-recipes.cjs`
- Modify: `Makefile` (target `verify-recipes`, `.PHONY`), `.gitignore` if `dist/` is not already ignored, `tests/RecipeLibraryTest.php` (create)

**Interfaces:**
- Consumes: `SectionRecipes::names()`, `SectionRecipes::recipe(name, language)`, `DesignLanguages::ids()`, `LayoutGate::check`.
- Produces: `node scripts/verify-recipes.cjs [--names a,b] [--languages x,y]` renders each selected recipe under each selected language (defaults: every recipe under the default look and under `glass-dark`, plus every language once on three representative recipes) at 1280, 900 and 400 px on the dev site; prints `PASS|FAIL <recipe>/<language>/<width> reason`; fails on any PHP error/notice/warning text in the HTML, a rendered page whose `scrollWidth > innerWidth`, an empty page, or a multi-column row that does not stack at 400 px; writes full-page screenshots to `dist/contact-sheet/<recipe>-<language>-<width>.png` and `dist/contact-sheet/index.html` (a plain HTML grid, one row per recipe, desktop and phone side by side). `tests/RecipeLibraryTest.php` holds the pure library invariants that need no Docker: unique names, tags valid, `pairs` roles valid and AA in every language (skip recipes without `pairs` until Task 11 adds them), pairwise-distinct fingerprint layouts (protocol item 4), no remote host, no unresolved token under any language.

- [ ] **Step 1: Write failing tests** in `tests/RecipeLibraryTest.php` for the invariants above (they pass today for the 17 except `pairs`/distinctness, which the test reports as the allow-listed baseline in a frozen file `fixtures/shapes/recipe-baseline.txt`; the baseline may only shrink).
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter RecipeLibraryTest`. Expected: FAIL (class missing).
- [ ] **Step 3: Implement** the tests' helpers and `scripts/verify-recipes.cjs` following `scripts/verify-guide-shapes.cjs` (login cookie, throwaway drafts titled `aied recipe <name> <language>`, try/finally plus signal cleanup, exact-prefix sweep). Throwaway pages go through `LayoutGate::check` first.
- [ ] **Step 4: Run** `vendor/bin/phpunit` (green) and `make verify-recipes`. Expected: all 17 existing recipes PASS at three widths under the default look and `glass-dark`, any pre-existing failure is reported (not hidden): record real failures in the report and, if a recipe cannot render cleanly, list it in `fixtures/shapes/recipe-baseline.txt` with the reason (Plan task 11 fixes or retires it). No throwaway page remains.
- [ ] **Step 5: Commit** `feat: verify-recipes renders every recipe at three widths and writes a contact sheet`.

### Tasks 3-7: Vocabulary families (one task per family)

Each family task follows the SAME steps (referred to as the **family protocol**) and differs only in sources and candidates:

- [ ] **F1 Mine candidates** into `fixtures/shapes/candidates/<family>.json` from the named Divi sources: read the attribute names and the default value shapes from Divi's PHP style classes in `divi/Divi-5-15-1.zip` (`Divi/includes/builder-5/server/Packages/StyleLibrary/Declarations/<Family>/`, extract to a NEW empty directory under the scratchpad and read from there) and the module definitions (`.../module-library/src/components/<module>/module.json`); where a path cannot be derived from source, capture it from a real builder round-trip driven headless with Playwright (the method of `scripts/verify-components.php`/`docs/components-evidence-5.15.md`). Never type a value from memory: each candidate cites its source file and line in a `note`.
- [ ] **F2 Probe**: `node scripts/verify-shapes.cjs fixtures/shapes/candidates/<family>.json --out fixtures/shapes/probe-results-<family>.json`; dead candidates are recorded, not hidden.
- [ ] **F3 Promote**: `php scripts/promote-shapes.php fixtures/shapes/probe-results-<family>.json fixtures/shapes/candidates/<family>.json` adds `how: probe` entries and sections to `docs/shape-evidence-5.15.1.md` (a "Not taught" subsection lists dead/overflowing candidates and why).
- [ ] **F4 Teach**: add a style-guide topic `<topic>` (constant in `StyleGuide.php`, listed by `StyleGuide::topics()`, at most 10,000 bytes) that teaches ONLY promoted shapes: exact path, accepted values (from the probe candidates that passed), one worked example per shape group that validates, honest limits. The lint `ShapeRegistryTest::testTopicPathsAreRegistered` (added in Task 3) fails on any dotted path in a topic that is not registered.
- [ ] **F5 Tests and commit**: `StyleGuideTopicsTest` asserts the topic exists, is within budget, and its worked examples validate; `vendor/bin/phpunit` green; measure with `php scripts/measure-tokens.php`; commit `feat: <topic> vocabulary proven on the real Divi`.

### Task 3: Backgrounds (topic `backgrounds`)

**Files:** Create `fixtures/shapes/candidates/backgrounds.json`; modify `wp-plugin/src/StyleGuide.php`, `tests/ShapeRegistryTest.php` (add `testTopicPathsAreRegistered` for every topic), `tests/StyleGuideTopicsTest.php`, `fixtures/shapes/verified-shapes.json`, `docs/shape-evidence-5.15.1.md`.

**Interfaces:** Produces topic `backgrounds` and `StyleGuide::topics()` containing it. Sources: `StyleLibrary/Declarations/Background/Traits/ConstantsTrait.php` (defaults: gradient `type` linear/radial/conic with `direction`/`directionRadial`/`stops`/`overlaysImage`; image `size`/`position`/`repeat`/`blend`/`parallax.enabled`; **pattern** `enabled`/`style`/`color`/`size`/`repeat`/`blend`; **mask** `enabled`/`style`/`color`/`aspectRatio`/`size`/`blend`), `Background/Utils/BackgroundStyleUtils.php` (the lists of valid pattern and mask style names), and the section module's `contentBackground`. NOT taught: background video (needs a file/URL) — excluded by the global constraints even if the probe passes it.

- [ ] Run the family protocol F1-F5 for: multi-stop and radial/conic gradients, pattern (at least 4 styles), mask (at least 4 styles), image size/position/repeat/blend, parallax. Candidates target section and row (and column where Divi's module.json offers background there).

### Task 4: Section dividers and inner shadow (topic `dividers`)

**Files:** Create `fixtures/shapes/candidates/dividers.json`; modify `StyleGuide.php`, tests, registry, evidence.

**Interfaces:** Produces topic `dividers`. Sources: `StyleLibrary/Declarations/Dividers/Dividers.php` + `Dividers/Utils` (style names, `flip`, `height`, `repeat`, `arrangement`, `fullwidth`, per-placement top/bottom), the section module's `advanced.dividers`, `advanced.innerShadow` and `advanced.gutter` in `module-library/src/components/section/module.json`.

- [ ] Run the family protocol for: top and bottom divider with several styles, flip/height/repeat/arrangement/colour, inner shadow, and the row gutter option if it renders differently; probe on sections adjacent to a differently coloured section so the divider is visible (the candidate's base page includes a neighbour section).

### Task 5: Motion, hover and scroll (topic `motion`)

**Files:** Create `fixtures/shapes/candidates/motion.json`; modify `StyleGuide.php`, tests, registry, evidence.

**Interfaces:** Produces topic `motion`. Sources: `StyleLibrary/Declarations/Transition/` (+ `TransitionTraits`), module `decoration.animation`, `decoration.sticky`, `decoration.scroll`, hover states for background/border/boxShadow/transform/filters/font; verify the guide's existing sticky/scroll text against these and extend it in the topic (do not edit the default guide text).

- [ ] Run the family protocol for: transition duration/delay/speed curve, hover (not just `.value`) for background colour, box shadow, transform scale/translate, border colour and button colours, entrance animation styles/directions/duration/delay/repeat/intensity/starting opacity, scroll effects beyond fade (vertical and horizontal motion, scaling, rotating, blur). `decoration.interactions` is included only if it can be built from source and proven; otherwise listed under "not taught". The probe for hover candidates uses Playwright hover on the element and compares computed styles.

### Task 6: Advanced layout and responsive control (topic `layout-advanced`)

**Files:** Create `fixtures/shapes/candidates/layout.json`; modify `StyleGuide.php`, tests, registry, evidence.

**Interfaces:** Produces topic `layout-advanced`. Sources: `StyleLibrary/Declarations/Position`, `Order`, `ZIndex`, `Overflow`, `Sizing`, `Spacing`, `Layout`, `DisabledOn`, and `decoration.transform`.

- [ ] Run the family protocol for: absolute/relative/fixed positioning with origin and offsets (overlap recipes depend on this), negative margins, z-index, `order` per device, `disabledOn` (hide per device; the 5.14 evidence marks it untested: measure it at 1280/900/400), sizing (minHeight/maxWidth/aspectRatio/height), overflow, transform translate/scale/rotate, flex alignment variants and `flexType` ratios beyond the guide's. The probe's phone-overflow check is the gate for any positioned/overlapping candidate.

### Task 7: Typography, imagery and controls (topic `typography-imagery`)

**Files:** Create `fixtures/shapes/candidates/typography.json`; modify `StyleGuide.php`, tests, registry, evidence.

**Interfaces:** Produces topic `typography-imagery`. Sources: `StyleLibrary/Declarations/Font`, `Text`, `TextEffects`, `TextShadow`, `Filters`, `ObjectFit`, `Border`, `BoxShadow`, `Button`, `ButtonIcon`, `Icon`, `OverlayIcon`; module.json for heading, text, image, button, blurb, icon, number-counter.

- [ ] Run the family protocol for: font weight/style/letter-spacing/line-height/text-transform per heading level, text shadow and text effects, heading level fonts inside text modules, image filters/fit/border radius/shadow/overlay, button icon and hover, blurb/icon sizes and colours, number counter styling, divider module styling. Fonts: the family names the topic mentions must exist in `Divi/core/json-data/google-fonts.json` (read from the zip).

### Task 8: Token safety and language validation

**Files:**
- Modify: `wp-plugin/src/RecipeTokens.php`, `wp-plugin/src/DesignLanguages.php`, `tests/RecipeTokensTest.php`, `tests/DesignLanguagesTest.php`
- Create: `fixtures/shapes/google-fonts-names.txt` (names extracted once from Divi's `google-fonts.json`, one per line, sorted; generated by `scripts/extract-divi-fonts.php`)

**Interfaces:**
- Produces: `RecipeTokens::resolve` inserts values JSON-safely (escape `"`, `\` and control characters as JSON would inside a string); `DesignLanguages::valid(array $language): list<string>` returns a list of problems (empty = valid) used by the test: required fields present, colours match `/^#[0-9a-f]{6}$/i` or valid `rgba(...)` (0<a<1), font names exist in `fixtures/shapes/google-fonts-names.txt` or are a web-safe stack, ids kebab-case unique, `favors`/`avoids` use `SectionRecipes::LAYOUTS`, all text free of `<`, `>`, backticks and `{{`.

- [ ] **Step 1: Write failing tests**: a language whose font is `Evil" Font` resolves to valid JSON in a recipe (`json_decode` of every attribute JSON of the served markup succeeds); `valid()` rejects each broken fixture language (bad hex, bad alpha, unknown font, missing field, `{{` in prose); every shipped language is `valid() === []`; every shipped font is in the extracted list.
- [ ] **Step 2: Run** the filtered tests. Expected: FAIL.
- [ ] **Step 3: Implement** the escaping, `valid()`, `scripts/extract-divi-fonts.php` (reads `google-fonts.json` from the Divi zip passed as an argument, writes the names file), and run it against `divi/Divi-5-15-1.zip`.
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: green. If a shipped font is not in the list (`Inter`, `Source Sans 3`, `Playfair Display`), change the language to a listed font and say so.
- [ ] **Step 5: Commit** `fix: JSON-safe token values and a validity check for design languages`.

### Task 9: Two more colour roles and four languages

**Files:**
- Modify: `wp-plugin/data/design-languages.json`, `wp-plugin/src/DesignLanguages.php`, `wp-plugin/src/RecipeTokens.php`, `wp-plugin/src/StyleGuide.php` (language topics are generated: no edit needed beyond the role list text), tests

**Interfaces:**
- Produces: every language gains `colors.inverse` (text colour for dark bands and photos) and `colors.overlay` (an `rgba(...)` scrim with 0.35 <= alpha <= 0.8); tokens `{{aied:color:inverse}}` and `{{aied:color:overlay}}` (roles list becomes `bg|surface|surface-glass|text|muted|accent|accent-text|border|inverse|overlay`); the WCAG test requires `inverse` over `overlay` composited over BOTH white and black to be at least 4.5 (worst case for light text on a photo). New languages: `organic-warm`, `tech-mono`, `playful-pastel`, `corporate-trust`, each original, complete (palette, fonts, radius, section padding, shadow/border treatment, motion, rhythm, favors, avoids, hero, cta), contrast-tested, `valid() === []`.

- [ ] **Step 1: Write failing tests**: all languages have `inverse` and `overlay`; the compositing test; token resolution for the two new roles; `SectionRecipes::moods()` lists the four new ids; `DesignLanguages::ids()` has 6 languages.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'DesignLanguages|RecipeTokens|SectionRecipes'`. Expected: FAIL.
- [ ] **Step 3: Implement** the roles in the two existing languages and write the four new ones. Written fresh; no text from the layoutlab project. Each language's rhythm and `favors` must make a visibly different page than another (different background strategy, radius scale, type pairing, motion level).
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: green.
- [ ] **Step 5: Commit** `feat: inverse and overlay colour roles and four more design languages`.

### Task 10: Four more languages

**Files:** Modify `wp-plugin/data/design-languages.json`, tests.

**Interfaces:** Produces `soft-saas`, `bold-vibrant`, `brutalist-flat`, `luxe` (10 languages in total), each complete and valid under Task 8/9's checks. `luxe` and `bold-vibrant` must not both prefer the same hero layout; a test pins that the 10 languages' `hero` and `rhythm` texts are pairwise different and each `favors` set differs from every other.

- [ ] **Step 1: Write failing tests** (ids, `valid()`, contrast, pairwise-different `favors`/`hero`).
- [ ] **Step 2: Run** the filtered tests. Expected: FAIL.
- [ ] **Step 3: Write the four languages.**
- [ ] **Step 4: Run** `vendor/bin/phpunit`. Expected: green.
- [ ] **Step 5: Commit** `feat: soft-saas, bold-vibrant, brutalist-flat and luxe design languages`.

### Task 11: Tokenise the 17 existing recipes

**Files:** Modify `wp-plugin/data/section-recipes.json`, `fixtures/shapes/recipe-baseline.txt`, `tests/RecipeLibraryTest.php`, `tests/SectionRecipesTest.php`.

**Interfaces:** Every one of the 17 recipes follows protocol items 1-6: colours and fonts tokenised, `pairs` declared, valid under all 10 languages, rendered clean by `make verify-recipes` (default look and `glass-dark`). Text on a photo or dark band uses `inverse`/`overlay`; text on cards uses `text`/`surface` (or `surface-glass` where the recipe is a glass card); buttons use `accent`/`accent-text`. Where a recipe's structure cannot honour the protocol (it fails the render gate), it is rebuilt from registered shapes or retired (removed from the JSON) with the reason in the report; the final count may drop below 17 before Tasks 12-16 add to it.

- [ ] **Step 1: Write failing tests**: `RecipeLibraryTest` no longer skips recipes without `pairs` and the baseline file must be EMPTY; no literal hex/rgba colours remain in any recipe markup except the allowed neutral scrims; `DesignVariety` fingerprint layouts are pairwise distinct among the recipes that remain (recipes that tie are rebuilt or retired).
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'RecipeLibraryTest|SectionRecipesTest|ShapeRegistryTest'`. Expected: FAIL.
- [ ] **Step 3: Tokenise** in two commits (recipes 1-9, 10-17) following the protocol; keep each recipe's structure and text unless the render gate or fingerprint-distinctness forces a change; re-tag `mood` with the languages each suits.
- [ ] **Step 4: Run** `vendor/bin/phpunit` then `make verify-recipes` (all PASS). Show the contact sheet path.
- [ ] **Step 5: Commit** `feat: every existing recipe is tokenised, contrast-paired and render-checked`.

### Tasks 12-16: New recipe batches (each follows the Recipe batch protocol)

For each batch: the implementer first reads the guide topics from Tasks 3-7 and the existing recipes of the same role, then composes the recipes below from REGISTERED shapes and already-proven module arrangements only. A module in a parent not shown by any recipe/export (a new compound arrangement) is accepted only when `make verify-recipes` renders it cleanly AND the validator accepts it. Each batch's tests: protocol items 1-6 over the new recipes (`RecipeLibraryTest` covers the library), and the catalog/filter tests list each new role-layout combination. Each batch ends with `make verify-recipes --names <new names>` all PASS and one commit `feat: <role> recipes (<count>)`.

### Task 12: Heroes and page headers (target 12)

**Files:** Modify `wp-plugin/data/section-recipes.json`, tests. 6 heroes: centered type-led with a pattern background, split image right, split image left with overlap card, full-bleed image with scrim (`inverse`/`overlay`), gradient with floating stat cards (overlap), minimal editorial headline with a kicker; 3 inner-page headers: title band, breadcrumb band, image band; 3 more by judgement from the languages' `hero` texts. Exactly one `h1` per hero.

- [ ] Steps: failing library tests for the new names and tags; compose; run `vendor/bin/phpunit`; `make verify-recipes --names <batch>`; commit.

### Task 13: Features and process (target 12)

**Files:** Modify `wp-plugin/data/section-recipes.json`, tests. Bento grid with unequal tiles, zigzag alternating image/text, numbered steps, horizontal process steps, vertical timeline, icon list with dividers, tabs, accordion beside an image, checklist, two-column comparison, feature carousel strip, big-number feature highlights. Not more than two of the 12 may fingerprint as card grids.

- [ ] Steps: as Task 12.

### Task 14: Proof, stats and testimonials (target 10)

**Files:** Modify `wp-plugin/data/section-recipes.json`, tests. Logo wall, press quotes, rating strip, counter band, circle counters, big-number captions, single large quote, testimonial slider, testimonial grid with avatars, quote over an image. Avatars/photos use pack tokens only.

- [ ] Steps: as Task 12.

### Task 15: Pricing, team, FAQ and story (target 10)

**Files:** Modify `wp-plugin/data/section-recipes.json`, tests. 3-tier pricing, 2-tier pricing with a highlighted plan, comparison-table style pricing, team grid, founder note, values, FAQ accordion, two-column Q&A, toggle list, story timeline.

- [ ] Steps: as Task 12.

### Task 16: Calls to action, gallery, contact and transitions (target 10)

**Files:** Modify `wp-plugin/data/section-recipes.json`, tests. CTA band, split CTA with form, card-over-image CTA, countdown CTA, masonry-style gallery, filterable portfolio, before/after, form + map, form + details, a pair of transition recipes (a divider-shaped edge and an overlapping edge between sections).

- [ ] Steps: as Task 12. After this task `SectionRecipes::names()` has at least 60 entries OR the report lists exactly which roles shipped smaller and why (the spec allows a smaller count; the readme states the real number).

### Task 17: Guides, catalog and docs in sync with the library

**Files:**
- Modify: `wp-plugin/src/LandingGuide.php`, `wp-plugin/src/SiteGuide.php`, `wp-plugin/src/SectionRecipes.php` (compact catalog text if the role headings changed), `wp-plugin/readme.txt` (lines 34 and 77: the real recipe count), `tests/WpOrgComplianceTest.php` (if it pins the count), `tests/GuideDesignDirectionTest.php`, `tests/LandingGuideTest.php`, `docs/token-usage.md` (regenerated), `CLAUDE.md`

**Interfaces:** the landing guide's persuasion-stage list names recipes that fit each stage and that exist with that structure (the parked mismatch: `card-grid-3` for "3 sharp pains", `card-grid-4` for benefits, `card-grid-3` for numbered steps must be replaced using the new `features`/`process` recipes and `role`/`layout` filters); a test asserts every recipe name mentioned in LandingGuide/SiteGuide/StyleGuide exists in `SectionRecipes::names()` and that its tag `role` is consistent with the stage it is offered for (a map stage -> allowed roles lives in the test); the readme states `SectionRecipes::count()` (add `count(): int`) and WpOrgComplianceTest checks the readme's number against it; the default catalog stays at most 2 x the Plan 1 baseline... measured and recorded (it will grow with 60+ names: raise the allowed bound ONLY to the measured size of the grouped-by-role compact form and record why in the test).

- [ ] **Step 1: Write failing tests** (names exist, roles match stages, readme count equals `count()`, token budgets).
- [ ] **Step 2: Run** the filtered guide/compliance tests. Expected: FAIL.
- [ ] **Step 3: Rewrite** the landing/site guide recipe pointers, add `SectionRecipes::count()`, update the readme lines (counts only; no version bump, no changelog entry), regenerate `docs/token-usage.md`, and update CLAUDE.md's "Design richness" entry to describe the final library, the 10 languages, the topics, and the remaining owner decisions.
- [ ] **Step 4: Run** `vendor/bin/phpunit` (green), the Pro suite `vendor/bin/phpunit -c pro-addon/phpunit.xml` (green), `make verify-recipes` (all PASS), `make verify-shapes`, `make verify-guide-shapes`, and Plugin Check on the dev copy and on an extracted build (build into a copy; restore the tracked zip with `git checkout --`; show `git status` clean for it).
- [ ] **Step 5: Commit** `docs: guides, catalog, readme counts and CLAUDE.md match the final library`.

---

## Self-review

- **Spec coverage:** G1 registry grows (T3-7 via probe); G2 topics for new vocabulary (T3-7); G3 60+ recipes tagged, distinct, rendered (T11-16, T2); G4 10 languages with contrast (T8-10); G5 advisory already built, parked test closed (T1); G6 constraints restated. Spec 2 (mining) = T3-7; spec 3 colour/font tokens for every recipe = T11-16 (+ `inverse`/`overlay` roles, T9); spec 5 variety advisory = done in Plan 1; verification live gates `make verify-recipes` (T2) and the contact sheet (T2, produced, not a release gate: the owner said no review gate for now). Deliberately not done: moving the existing long default-guide sections into topics (the default does not grow, so it is not needed), `list_global_styles` id substitution in recipes, background video, release steps, Phase 2 (image-to-layout, its own design).
- **Step scan:** vocabulary tasks cannot name values in advance (they come from Divi's source); the family protocol makes each step checkable (probe output, registry entries, topic lint). Recipe tasks name target roles/variants, not markup.
- **Type consistency:** `coversLeaf`, `clearCache`, `valid`, `count`, token roles `inverse|overlay`, tags `pairs` are defined in T1/T8/T9/T17 before use (`pairs` is introduced in the protocol and enforced from T11; T2 skips recipes lacking it via the frozen baseline).
- **Proportion:** the plan is long because it covers 17 tasks, but only T1, T2 and T8 contain code-level decisions; the rest are protocol-driven batches.
