# html_to_divi Phase 2 (Styles) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** With `styles=basic` (already the default) `html_to_divi` carries a small, proven, readability-safe subset of the source's CSS (text colour, background colour, font family, size, weight, alignment, padding, row max width, a button's label colour, fill and corners) from inline `style=""` and simple `<style>` rules into the layout; `styles=none` stays the exact phase-1 output; the report says what was carried and what was left out and why.

**Architecture:** Five new pure units in `wp-plugin/src/` and two small seams in phase-1 code, wired in the existing facade. `HtmlSource` additionally hands over the `<style>` text, each element's `style=""` text and presentational attributes (never left in the DOM, never emitted). `HtmlMapper::map($body, true)` additionally records, per section/row/column/module, the XPath (`DOMNode::getNodePath()`) of the source element(s) it came from (`false`, the default and the `styles=none` path, returns today's plan unchanged). `HtmlCss` parses CSS text and values; `HtmlStyles` runs the cascade and inheritance over the DOM and returns one computed record per element, marking every property a construct it cannot read might change as *unresolved* ("soundness": a carried value always equals what a browser computes at desktop width for that element); `HtmlFonts` picks a family Divi can load; `HtmlStyleBlocks` turns the plan plus the computed records into per-block candidate styles (anchors, padding, max width, sizes, per-device steps); `HtmlReadability` decides colours with the WCAG contrast of `DesignLanguages::contrast()` under one rule, *paint and ink travel together*; `DiviMarkup` (still the ONLY writer) gains an optional trailing `$style` array per block, writing only paths proven ON that block.

**Tech Stack:** pure PHP 8 (`wp-plugin/src/`), PHPUnit (`make test`), Node + Playwright probe/render scripts against the Docker dev site (Divi 5.15.1).

**Spec:** `docs/superpowers/specs/2026-10-22-html-to-divi-styles-design.md` (approved by delegation; Rulings 1-7). Phase 1: `docs/superpowers/specs/2026-10-14-html-to-divi-design.md`. Templates: `docs/superpowers/plans/2026-10-19-forms-adapter.md` (probe first, a `*_PROVEN` constant pinned to a vocabulary "Adapter decisions" table), `docs/superpowers/plans/2026-10-21-rules-chips-adapter.md`. Code to mirror: `KitAdapters::RULE_PROVEN` + its vocabulary test, `DiviMarkupTest::known()` (per-block grounding from exports and served recipes), `tests/Support/ShapeRegistry.php`, `tests/Support/DeadOnModule.php`, `scripts/verify-guide-shapes.cjs` (login cookie, `?page_id=&preview=true`, module lookup by unique text).

Decisions this plan makes where the spec leaves a choice (each is a constant or one sentence; Task 12 copies them into the spec Outcome):
- D1 **Paint and ink travel together** (the readability rule, Task 8): a heading or text module gets a colour only when it sits on a block the converter paints, and every module on a painted block gets one (its declared colour, or the CSS initial `#000000` when nothing is declared); a module that cannot be inked readably drops the paint of every block that contains it; a button carries its fill and label as a pair or not at all, and an unpaired button on paint becomes a ghost (fill `KitAdapters::TRANSPARENT`, label = its parent's ink). This makes the result independent of the site theme's colours (see Spec issue 4).
- D2 **Canvas paint:** a section whose modules declare any text colour, and that has no background on its anchor chain, is painted with the source canvas `#ffffff` (the CSS initial canvas), so a declared colour is never printed on the theme's unknown background.
- D3 **Wrapper backgrounds propagate to sections** (light or dark, one rule): a section's paint is its own element's background, else the nearest ancestor's (wrappers, `body`, `html`). The spec's dark-page rule is the dark case of this (Spec issue 5).
- D4 **Soundness / poison:** a property that any construct the reader cannot evaluate might set for an element (a combinator or pseudo-class rule whose subject matches it, an `@media`/`@supports`/`@container`/`@layer` rule whose subject matches it, `var()`/`calc()`/`clamp()`/`min()`/`max()`/`env()`/`attr()`, a keyword or unit it does not read, the `font` shorthand, `bgcolor`/`align`/`color`/`text` attributes, `opacity`/`filter`/`mix-blend-mode`/`-webkit-text-fill-color` for colour) is *unresolved* on that element (and, for inherited properties, below it) instead of falling back to a lower rule. User-action states (`:hover :focus :active :visited :focus-visible :focus-within`) and pseudo-elements never poison. A selector the reader cannot parse at all (unbalanced) is dropped, as a browser drops it.
- D5 **UA own-only properties:** `h1`-`h6` take `font-size` and `font-weight` only from their own declarations (the UA sheet sets them); `a` and `button` take `color` (and `button` also `background-color`, `font-*`, `text-align`) only from their own; `pre code kbd samp tt` take `font-family` only from their own; `center` contributes `text-align:center` at the lowest priority.
- D6 **Max width lands on rows only:** no real export writes `module.decoration.sizing.*.maxWidth` on a section or a column (scan of `fixtures/valid` + served recipes: section 0, column 0, row 10). A row's max width = the smallest carried `max-width` on its anchor and every ancestor up to `html`. A `max-width` on a module's own element is ignored (`not_on_block`).
- D7 **Text alignment on `divi/text`** is `module.advanced.text.text.desktop.value.orientation` (exported on `divi/text` 27 times, registered) when Task 1 proves it, else `content.decoration.bodyFont.body.font.desktop.value.textAlign` (measured by `make verify-guide-shapes`, NOT in the registry) when Task 1 proves that; `left` is never written (Divi's default); `justify` only if proven.
- D8 **Values:** colours lowercase `#rrggbb`; sizes, padding, max width and radius integer `"<n>px"` strings (round half up); weight a string `"100"`..`"900"`; radius clamped 0-999 px (the spec lists no clamp for it; 999px is the pill value the kits use).
- D9 **No new note:** `report.notes` loses the "not converted yet" sentence and gains nothing; `report.styles` (only when `styles=basic`) carries the detail. `styles=none` reports have no `styles` key (the phase-1 report exactly).
- D10 **Class names:** `HtmlCss`, `HtmlStyles`, `HtmlFonts`, `HtmlStyleBlocks`, `HtmlReadability`; constants `DiviMarkup::STYLE_PROVEN`, `HtmlCss::REASONS`; test helper `tests/Support/StyledLayoutOracle.php`; candidates prefix `hs-`.

## Controller rulings on the Spec issues (2026-10-22; where this section conflicts with the text below, THIS SECTION WINS)

All eleven "Spec issues found" are accepted as the plan proposes them. Specifically: (1) the font list ships as `wp-plugin/data/google-fonts-names.txt` with TWO narrow, justified entries in `WpOrgComplianceTest` (a `COMMERCIAL_EXCEPTIONS` row scoped to that one file and the substring " Pro", and a `LOCAL_READS` row): this is the "justified explicit exception" CLAUDE.md allows, not a weakening, because the file is a list of Google font family names (Maven Pro, Crimson Pro ...) and not user-facing copy; the test that the file is byte-equal to the fixture and that its only hits are family names stays; do NOT evade the scan by renaming the extension or encoding the list; (2) the phase-1 seam is added as the plan says (`HtmlSource` hands over `css`, `inline`, `presentational` without keeping `style` in the DOM; the mapper gets an opt-in `$sources` flag, no rewrite); (3) `HtmlToDiviGroundingTest` is created; (4)(5) D1 paint-and-ink-together and D2 canvas paint replace the per-colour check, D3 propagates any carried wrapper background; (6) `@media`-set properties are not carried; (7) max width on rows only; (8) both text-align paths are probed, orientation first; (9) compound selectors stay poison-only (a later phase may carry them); (10) headings and links do not inherit size, weight or colour; (11) radius clamp 0-999 px. The spec's readability wording ("the theme colour wins") is superseded by D1/D2 in the Outcome written by Task 12.

## Global Constraints

Copied from CLAUDE.md's hard constraints and the spec:
- **The validator is deterministic. No AI/LLM calls in it, ever.** `src/` and `wp-plugin/validator/` are not touched (`git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip` stays empty). The converter itself is deterministic too: no randomness, no clock, no locale-dependent formatting (`sprintf('%d')`, never `number_format` with locale); the same HTML gives the same bytes.
- **No Divi setting from memory.** Every attribute path `DiviMarkup` writes for a style is (a) in `fixtures/shapes/verified-shapes.json` AND (b) proven ON that block: written on that block name by a real export or a served recipe (`DiviMarkupTest::known()`), or PASS for a Task 1 `hs-*` candidate whose `block` is that block. The registry is path-only (the forms lesson: a path proven on one module proves nothing on another), so (b) is checked per block. A path DEAD on a block (`fixtures/shapes/dead-on-module.txt`) is never written.
- **Probe-conditional rule (binds Tasks 7-9):** an implementer MUST NOT write a path whose decision in `DiviMarkup::STYLE_PROVEN` is false; a test pins that constant to the "Adapter decisions" table of `fixtures/shapes/html-styles-vocabulary.md`.
- **No custom CSS, JS or PHP and no remote calls in `wp-plugin/`.** No `<style>`, `style=""`, `css.freeForm`, `mainElement` or any custom-code attribute ever leaves the converter (every result is scanned by `CodePolicy::violations()`); no `wp_remote_*`, cURL or remote reads; the bundled font list is read from a local file only.
- **No new MCP tool, REST route or OpenAPI operation.** The `html_to_divi` schema is unchanged (`HtmlToDiviLockstepTest` pins the property list); only descriptions change. `html_to_divi` stays out of the ChatGPT OpenAPI spec.
- **`tests/WpOrgComplianceTest.php` is never weakened.** The two additions this plan needs (Spec issue 1) are explicit, justified entries in its existing allow-lists, nothing is removed or loosened; the controller confirms them before Task 5 merges.
- **No release, version bump, zip rebuild or push.** `wp-plugin/readme.txt` is NOT edited (its release-time sentences are proposed in Task 12 for the owner). `wp-plugin/jhmg-ai-editor-for-divi-5.php` untouched. The Pro suite (`make test-pro`) green and untouched.
- **Goldens:** the `styles=none` output is byte-identical to phase 1: the three existing goldens (`tests/golden/html-to-divi/{01-landing,03-pricing-cards,13-images}.layout.txt`) stay byte-identical under BOTH modes (those pages have no CSS), and every new styled fixture gets a `*.none.layout.txt` golden recorded by Task 3 on the UNMODIFIED converter, which no later task may change. Styled goldens (`14-styled-*.layout.txt`) are created only in Task 9, with `AIED_UPDATE_GOLDEN=1`, and reviewed by eye.
- Max 3 parallel workers; ONE probe or render at a time on the dev site (`pgrep -f "node scripts/verify-"` must be empty first); the controller runs every live step (Task 1's probe, Task 11's gate); implementers never touch the dev site, Docker or the node verify scripts. Check `git branch --show-current` (`feat/dogfood-and-html-styles`) before and after long runs. Worktree probe/render runs need `PLAYWRIGHT_PATH=/Users/Lucas/Documents/JHMG-Local/layoutlab/node_modules/playwright` and `COMPOSE_PROJECT_NAME=divi5deterministicvalidator`.
- Every new `wp-plugin/src/` class is added to `wp-plugin/src/autoload.php` (after `HtmlProse.php`, in dependency order; `PluginAutoloadCoversEverySourceFileTest`), to the require list of `tests/HtmlTestCase.php` and to the class list of `tests/HtmlToDiviLockstepTest.php`. Data files need no registration; reading one needs a `LOCAL_READS` entry in `WpOrgComplianceTest`.
- Commit only your own files by explicit path, with the commit trailer the controller gives. Model hints: implementers sonnet; task reviewers sonnet, EXCEPT Task 2 (CSS reader), Task 6 (cascade) and Task 8 (readability core): opus; plan review and final whole-branch review opus.

## Review Focus

Each line names the test that pins it and the task that owns it.

1. A style path written on a block it was not proven ON (bodyFont `textAlign` on a text module without a PASS, `maxWidth` on a section or column, a web-safe family without a PASS): `HtmlToDiviGroundingTest::testEveryStylePathIsGroundedOnItsOwnBlock`, `testNoStylePathIsDeadOnItsModule`, `testADecisionThatIsFalseIsNeverWritten`, `HtmlStylesVocabularyTest::testStyleProvenMatchesTheVocabularyTable` (T7).
2. `styles=none` output changed in any byte, or the phase-1 goldens changed under `basic`: `HtmlToDiviTest::testStylesNoneGoldenLayout`, `testThePhaseOneGoldensAreTheSameInBothModes`, `testStylesNoneIgnoresEveryStyle` (T3), `testRandomStyledHtmlNeverBreaksTheContract` twin assertion (T10).
3. A mapper or source change that alters the phase-1 plan: `HtmlMapperTest` unchanged and green, `HtmlMapperTest::testSourcesAreOnlyAddedWhenAskedFor`, `HtmlSourceTest::testStyleTextAndInlineStylesAreHandedOverButNeverKeptInTheDom` (T4).
4. A CSS construct carried that a browser would not apply, or a lower rule carried where an unreadable one wins (combinator, `@media`, `var()`, `font` shorthand, `!important` order): `HtmlStylesTest::testAPropertyAnUnreadableRuleMightSetIsUnresolved`, `testImportantBeatsNormalAndInlineBeatsRules`, `testMediaRulesPoisonButDoNotApply` (T6); live `hs-color`/`hs-size` source-vs-render equality (T11).
5. Inherited font size or weight on a heading, or an inherited colour on a link button: `HtmlStylesTest::testHeadingsTakeSizeAndWeightOnlyFromTheirOwnRules`, `testLinksAndButtonsTakeColourOnlyFromTheirOwnRules` (T6).
6. A carried text colour below 4.5:1 (3:1 large) against what it is printed on, ink without paint, paint without ink, half a button pair: `HtmlStylesReadabilityTest::*` (T8), `StyledLayoutOracle` over the fixtures and 200 seeded random styled pages (T9, T10), live `hs-contrast` on every module of every styled page (T11).
7. The fixpoint depending on module order: `HtmlStylesReadabilityTest::testTheDecisionDoesNotDependOnModuleOrder` (T8).
8. A colour, length or family value outside its form or clamp reaching the layout, or a value `CodePolicy` would refuse: `HtmlCssTest::testValueForms*`, `DiviMarkupStyleTest::testStyleValuesAreValidatedBeforeWriting`, `HtmlToDiviTest::testNothingDangerousCanBeInTheResult` (styled fixtures included) (T2, T7, T9).
9. A font Divi cannot load carried, or the shipped font list drifting from Divi's: `HtmlFontsTest::testTheShippedFontListEqualsTheFixture`, `testOnlyGoogleOrProvenWebSafeFamiliesAreChosen` (T5).
10. The compliance test loosened beyond the two named entries: `WpOrgComplianceTest` diff reviewed line by line; `HtmlFontsTest::testTheFontListIsTheOnlyNewCommercialWordException` (T5).
11. The `styles` description still saying "not converted yet" anywhere, or growing past its bound: `HtmlToDiviLockstepTest::testTheStylesDescriptionsSayWhatIsCarried` (T9), `HtmlToDiviDocsTest::testNoClaimSaysStylesAreNotConverted` (T12).
12. A live gate that passes when it measured nothing or when the source element was not found: `scripts/test-verify-html-styles.cjs` (`emptyRunProblems`, `mappingProblems`) (T11).
13. A determinism break (map iteration order, float formatting): `HtmlStylesTest::testTheSameInputGivesTheSameRecords`, `HtmlToDiviTest::testTheSameHtmlGivesTheSameBytes` (T6, T9).

---

## Task order and parallelism

| Wave | Tasks (parallel) | Consumes | Notes |
|---|---|---|---|
| 1 | **T1** probe (candidates + vocabulary by a worker, live run by the controller), **T2** `HtmlCss`, **T3** fixtures + none goldens | nothing | T3 MUST record its none goldens on the unmodified converter (branch head before any other task merges). |
| 2 | **T4** source + mapper seams, **T5** `HtmlFonts` + font data, **T7** `DiviMarkup` style writer + grounding | T4: T3 merged; T5: T1 verdict `websafe-family`; T7: T1 verdicts | T7 can start on the always-proven paths before T1 lands and finish the conditional ones after. |
| 3 | **T6** `HtmlStyles` cascade | T2, T4 | opus review |
| 4 | **T8** `HtmlStyleBlocks` + `HtmlReadability` | T6, T7 (interfaces), T5 | opus review |
| 5 | **T9** wiring, report, descriptions, styled goldens | T8 | |
| 6 | **T10** property test + oracle, **T11** live gate scripts (controller runs) | T9 | T11's run is serial on the dev site. |
| 7 | **T12** docs, CLAUDE.md, spec Outcome, final review | all | final review opus |

---

## Task 1: Probe the paths that are not yet proven ON their block (live, controller-run)

**Files:**
- Create: `fixtures/shapes/candidates/html-styles.json`, `fixtures/shapes/html-styles-vocabulary.md`
- Created by the run / promotion: `fixtures/shapes/probe-results-html-styles.json`, sections in `docs/shape-evidence-5.15.1.md`, new entries in `fixtures/shapes/verified-shapes.json` (only via `php scripts/promote-shapes.php`), optionally `fixtures/shapes/not-taught-html-styles.json`, new lines in `fixtures/shapes/dead-on-module.txt` for any DEAD path

Already proven ON the block (no probe, evidence = an export or served recipe on that block name in `DiviMarkupTest::known()` plus a render gate): heading `title.decoration.font.font.{desktop,tablet,phone}.value.{color,size,weight,textAlign,family}` (Google family), text `content.decoration.bodyFont.body.font.{desktop,tablet,phone}.value.{color,size,weight,family}`, button `button.decoration.background.desktop.value.color`, `button.decoration.font.font.desktop.value.color`, `button.decoration.border.desktop.value.radius.{topLeft,topRight,bottomRight,bottomLeft}` (served kit recipes; `make verify-kits` measured fill, label and radius on `divi/button`, 264 PASS), section/row/column `module.decoration.background.desktop.value.color` and `module.decoration.spacing.desktop.value.padding.{top,right,bottom,left}`, row `module.decoration.sizing.desktop.value.maxWidth` (export only: probed below for effect and centring).

- [ ] **Step 1 (worker): write the candidates** in the `kit-rule.json` format (`id`, `block`, `base`, `set`, optional `expect`, `note`), text content in `base.content.innerContent.desktop.value`. Exactly these ids:

| id | block | set (desktop) | base content / extra | expect |
|---|---|---|---|---|
| hs-heading-color-control | heading | `title.decoration.font.font.desktop.value.color` = `#dc2626` | | PASS |
| hs-text-orientation-center | text | `module.advanced.text.text.desktop.value.orientation` = `center` | a 3-line paragraph | PASS |
| hs-text-orientation-right | text | orientation = `right` | same | PASS |
| hs-text-orientation-justified | text | orientation = `justified` | same | (none) |
| hs-text-orientation-left | text | orientation = `left` | same | DEAD (default-value check) |
| hs-text-bodyfont-align-center | text | `content.decoration.bodyFont.body.font.desktop.value.textAlign` = `center` | same | (none) |
| hs-text-bodyfont-align-justify | text | same path = `justify` | same | (none) |
| hs-heading-align-justify | heading | `title.decoration.font.font.desktop.value.textAlign` = `justify` | a heading that wraps to 3 lines at 400 px | (none) |
| hs-heading-family-georgia | heading | `title...value.family` = `Georgia` | | (none) |
| hs-heading-family-courier | heading | `title...value.family` = `Courier New` | | (none) |
| hs-text-family-georgia | text | `content...body.font.desktop.value.family` = `Georgia` | | (none) |
| hs-text-ink-ul | text | `content...body.font.desktop.value.color` = `#dc2626` | `<ul><li>One</li><li>Two</li></ul>` only | PASS |
| hs-text-ink-ol | text | same | `<ol><li>One</li><li>Two</li></ol>` only | PASS |
| hs-text-ink-quote | text | same | `<blockquote><p>Quoted</p></blockquote>` only | (none) |
| hs-row-max-width | row | `module.decoration.sizing.desktop.value.maxWidth` = `600px` | `"geometry": true` | PASS |
| hs-row-background | row | `module.decoration.background.desktop.value.color` = `#1e293b` | | PASS |
| hs-column-background | column | same | | PASS |
| hs-column-padding | column | `module.decoration.spacing.desktop.value.padding.top` = `64px` | | PASS |
| hs-section-padding | section | `module.decoration.spacing.desktop.value.padding.left` = `48px` | | PASS |
| hs-button-ghost | button | `button.decoration.background.desktop.value.color` = `rgba(255,255,255,0)`, `button.decoration.font.font.desktop.value.color` = `#e2e8f0` | | PASS |

- [ ] **Step 2 (worker): write the vocabulary** `fixtures/shapes/html-styles-vocabulary.md`: one table of every probed decision (what PASSED, registered path, status) and an `## Adapter decisions` table whose rows are EXACTLY the 13 keys of `DiviMarkup::STYLE_PROVEN`, in this order, verdict column `PASS` or `FAIL`, path column, evidence ids:
`text-align-orientation` (hs-text-orientation-center AND -right), `text-align-bodyfont` (hs-text-bodyfont-align-center), `text-justify` (hs-text-orientation-justified when `text-align-orientation` is PASS, else hs-text-bodyfont-align-justify), `heading-justify` (hs-heading-align-justify), `websafe-family` (all three family candidates), `row-max-width` (hs-row-max-width PASS AND the geometry shows the row centred within 1 px), `list-ink-ul`, `list-ink-ol`, `quote-ink`, `row-background`, `column-background`, `column-padding`, `section-padding`. Verdicts are left as `TBD` by the worker.
- [ ] **Step 3 (controller, live, serial):** `pgrep -f "node scripts/verify-"` empty; `node scripts/verify-shapes.cjs fixtures/shapes/candidates/html-styles.json --out fixtures/shapes/probe-results-html-styles.json`; `php scripts/promote-shapes.php fixtures/shapes/probe-results-html-styles.json fixtures/shapes/candidates/html-styles.json`; then `make verify-shapes` (known.json, must stay green and unchanged).
- [ ] **Step 4 (controller):** fill the verdicts (PASS only for `effect: true`, no phone overflow, not css-only); add a `dead-on-module.txt` line for any DEAD candidate path (format `divi/<block>|<path prefix>|<id>|<reason>`); if hs-text-orientation-left is not DEAD, write that down (it would mean `left` is not the default: then D7 changes to "write `left` when declared" and the controller amends Task 7 before it starts).
- [ ] **Step 5:** commit candidates, results, vocabulary, registry, evidence, dead list.

Acceptance: `git diff fixtures/shapes/candidates/known.json` empty; every Adapter-decisions row has a verdict; the registry gained only promoted paths.

---

## Task 2: `HtmlCss`, the pure CSS text and value reader

**Files:**
- Create: `wp-plugin/src/HtmlCss.php`, `tests/HtmlCssTest.php`
- Modify: `wp-plugin/src/autoload.php`, `tests/HtmlTestCase.php` (require list)

**Interfaces:**
```php
final class HtmlCss
{
    public const MAX_CSS_BYTES    = 100000;   // the rest of a longer stylesheet is not read: ignored['css_cap']++
    public const MAX_RULES        = 2000;
    public const MAX_DECLARATIONS = 100;      // per block
    public const MAX_SELECTOR     = 200;      // characters per selector

    /** Closed list of `ignored` reasons (report keys). */
    public const REASONS = [ 'selector', 'at_rule', 'media', 'function', 'keyword', 'alpha', 'colour_form', 'unit', 'image_or_gradient',
        'property', 'pseudo_state', 'shorthand', 'presentational', 'effect', 'external', 'css_cap', 'not_on_block', 'mixed', 'unproven',
        'font_unavailable', 'generic_font', 'unknown_background', 'unreadable', 'unresolved', 'paint_dropped', 'button_ghosted' ];

    /** The 148 CSS named colours (CSS Color 4), lowercase name => #rrggbb ('transparent' is not in it). */
    public const NAMED = [ /* ... */ ];

    /**
     * @return array{
     *   rules: list<array{subject: array{tag:?string, ids:list<string>, classes:list<string>, other:bool},
     *                     simple: bool, specificity: array{0:int,1:int,2:int}, poison_only: bool, order: int,
     *                     declarations: list<Declaration>}>,
     *   ignored: array<string,int>
     * }
     * simple       = the selector is exactly one `tag`, `.class` or `#id` (the spec's grammar): it APPLIES.
     * poison_only  = not simple, or inside a block at-rule: it never applies, but marks its properties unresolved on every
     *                element its subject (rightmost compound: tag, ids, classes; `other` = it also has attribute or non-state
     *                pseudo-class parts) could match. A selector whose subject has a pseudo-element or ONLY user-action
     *                pseudo-classes yields no rule at all (`pseudo_state`).
     */
    public static function stylesheet( string $css ): array;

    /**
     * One declaration block (`style=""` text or a rule body). Shorthands expanded: `padding` -> 4 sides, `border-radius` -> 4
     * corners, `background` -> `background-color` (or a poison of it), `font` -> poison of size, weight and family.
     * @return list<array{property:string, value:?string, important:bool, poison:?string}>  poison = the reason, value null
     */
    public static function declarations( string $block, array &$ignored ): array;

    /** @return array{kind:'colour',hex:string}|array{kind:'none'}|array{kind:'poison',reason:string} */
    public static function colour( string $value ): array;
    /** background shorthand: one colour token -> colour; `none`/`transparent` -> none; anything else -> poison image_or_gradient */
    public static function background( string $value ): array;
    /** px for px/rem (16)/em (x $em)/% (only when $percentOfEm, x $em/100); null = unsupported (unit) */
    public static function length( string $value, float $em, bool $percentOfEm ): ?float;
    /** @return list<string> family names, quotes removed, original case kept */
    public static function families( string $value ): array;
    public static function weight( string $value ): ?int;          // 100..900 step 100, normal=400, bold=700; bolder/lighter -> null
    public static function align( string $value ): ?string;        // left|right|center|justify; start/end/-webkit-* -> null
}
```
Parsing rules (all named in the class docblock): comments removed first; strings and parentheses respected when splitting on `;`, `,` and `{}`; `@import`, `@charset`, `@namespace` statements counted (`external` for `@import`, `at_rule` otherwise); `@font-face`, `@keyframes`, `@page`, `@property`, `@counter-style`, `@font-feature-values` blocks skipped and counted (`at_rule`); `@media`, `@supports`, `@container`, `@layer`, `@scope`, `@document` blocks: inner rules parsed with `poison_only = true`, counted `media` per declaration; unbalanced braces: the rest of the text is dropped (`selector`); custom properties (`--x:`) counted `property`; colours: `#rgb`, `#rrggbb` (any case), `rgb(r,g,b)` and `rgb(r g b)` with integers 0-255, named colours; `#rgba`, `#rrggbbaa`, `rgba()`, `hsla()`, `rgb(... / a)`, `transparent` -> `alpha` (`transparent` as a background is `none`); `hsl()`, percentages, `currentcolor`, system colours -> `colour_form`; functions -> `function`; `inherit` on an inherited property is a no-op declaration (no poison); `initial`, `unset`, `revert`, `revert-layer` -> poison `keyword`.

- [ ] **Step 1: failing tests** in `tests/HtmlCssTest.php` (each asserts exact arrays):
  - `testSimpleSelectorsApplyAndCommaListsSplitPerItem`: `h1, .lead, #top, .a .b { color: red }` gives three simple rules (specificities `[0,0,1]`, `[0,1,0]`, `[1,0,0]`) and one poison-only rule with subject `classes:['b']`.
  - `testCompoundAttributeAndStructuralSelectorsArePoisonOnly`: `p.lead`, `a[href]`, `li:first-child`, `:root`, `:is(h1,h2)` -> poison-only with the expected subjects (`:root` and `:is()` subject `tag:null, other:true`).
  - `testStatesAndPseudoElementsYieldNoRule`: `a:hover`, `.btn:focus-visible`, `p::first-line`, `li::marker` -> no rule, `pseudo_state` counted 4.
  - `testMediaAndSupportsBlocksArePoisonOnly`: inner rules flagged, `media` count = number of inner declarations; `@font-face{...}` and `@keyframes x{...}` skipped (`at_rule` 2); `@import url(x.css);` counted `external` 1.
  - `testImportantIsReadAndShorthandsExpand`: `padding: 1px 2px 3px` -> top 1, right 2, bottom 3, left 2; `border-radius: 4px 8px` -> 4 corners; `font: 700 20px Inter` -> three poisons `shorthand`; `color: red !important` -> important true.
  - `testValueFormsColours`: `#ABC`->`#aabbcc`, `rgb(10, 20, 30)` and `rgb(10 20 30)`->`#0a141e`, `RebeccaPurple`->`#663399`, `rgba(0,0,0,.5)`/`#0008`/`transparent` -> `alpha`, `hsl(0 0% 0%)` -> `colour_form`, `var(--x)` -> `function`, `rgb(300,0,0)` -> `colour_form`.
  - `testValueFormsBackground`: `#123` -> colour; `none` -> none; `#fff url(a.png)`, `linear-gradient(red, blue)` -> poison `image_or_gradient`.
  - `testValueFormsLengths`: `24px`->24, `1.5rem`->24, `2em` with em 18 -> 36, `150%` font-size with em 16 -> 24, `150%` padding -> null, `5vw`/`calc(1px)`/`large` -> null.
  - `testValueFormsFamiliesWeightsAligns`: `"Playfair Display", Georgia , serif` -> 3 names; weights `bold`->700, `350`->null, `bolder`->null; aligns `start`->null, `CENTER`->`center`.
  - `testCapsAreEnforcedAndCounted`: 100,001-byte CSS -> `css_cap` 1, 2,001 rules -> 2,000 kept, a 201-character selector -> `selector`.
  - `testTheNamedColourListIsCssColourFour`: 148 entries, lowercase keys, `transparent` absent, spot values (`rebeccapurple`, `aliceblue` `#f0f8ff`, `grey`=`gray`).
  - `testGarbageNeverThrowsAndIsDeterministic`: 500 seeded garbage strings (unbalanced quotes, braces, NUL, 10 MB of `{`) never throw, each parsed twice gives the same array.
- [ ] **Step 2:** run `vendor/bin/phpunit --filter HtmlCssTest` (fails), implement, run (passes), `make test` green.
- [ ] **Step 3:** commit.

---

## Task 3: Styled fixtures and the `styles=none` goldens (on the UNMODIFIED converter)

**Files:**
- Create: `fixtures/html/14-styled-inline.html`, `14-styled-classes.html`, `14-styled-dark.html`, `14-styled-backgrounds.html`, `14-styled-fonts.html`, `14-styled-hostile.html`; `tests/golden/html-to-divi/14-styled-*.none.layout.txt` (six)
- Modify: `fixtures/html/README.md` (six rows), `tests/HtmlToDiviTest.php`

Fixture content (every module's first 24 visible characters unique on its page, for the live gate's lookup; each page at most 8 KB; no external URL except placeholder image addresses as in `13-images.html`):
- `14-styled-inline`: only `style=""`: a hero `<section>` with `background:#0f172a; padding:96px 24px` and `text-align:center`, an `h1` `color:#ffffff; font-size:56px; font-weight:800`, a `p` `color:#cbd5e1; font-size:20px`, an `a class="btn"` `background:#f59e0b; color:#111827; border-radius:999px`; a second section with a `div` container `max-width:720px; padding:40px` holding an `h2` and two `p`.
- `14-styled-classes`: one `<style>` with tag, `.class`, `#id` rules and comma lists; specificity battles (an `#intro` rule beating a `.lead` rule beating `p`; a later equal rule winning; an inline style beating all); `body{font-family:Inter, sans-serif; font-size:18px; color:#1f2937}`; `h2{font-size:2em}` (relative to 18 -> 36px, so tablet 27 / phone 20); a three-card grid `.card{background:#ffffff; padding:24px}` on `section.features{background:#f3f4f6}` (the section rule is the single class `.features`); `.btn{background:#2563eb; color:#fff; border-radius:8px}`.
- `14-styled-dark`: `body{background:#0b1120; color:#e2e8f0}`, headings `#f8fafc`, one section with its own `#1e293b`, accent buttons, and ONE separate last section whose `p` is `color:#475569` (2.49:1 on `#0b1120`: dropped and listed, its section unpainted). Used for the screenshot pair.
- `14-styled-backgrounds`: a section with `background: linear-gradient(...)` and white text; one with `background-image:url(/hero.jpg)`; one with `background:#123456` (carried) and light text; one with `background-color:rgba(0,0,0,.5)`; a `p` with its own `background:#000; color:#fff` inside a light section (an uncarried background between text and paint).
- `14-styled-fonts`: `@font-face{...}`, `@import url(...)`; headings `"Playfair Display", serif`; body `"Brand Sans", Inter, sans-serif` (Inter carried, Brand Sans listed); one `p` `Georgia, serif`; one `p` `system-ui, sans-serif` only (nothing carried); a `pre` (UA monospace family: not inherited).
- `14-styled-hostile`: `!important` battles, `color: var(--ink)`, `font-size: calc(1rem + 2vw)`, `clamp()`, `@media (min-width:768px){ h1{font-size:72px} }` over `h1{font-size:40px}`, `a:hover{...}`, `.hero h1{color:#fff}` (poison), `p.lead`, `[data-x]`, an unclosed `{`, `font-size:900px` (clamped to 200), `padding:-4px`, `font: italic 700 20px Inter`, `<table bgcolor="#000">`, `<center>`, upper-case hex, `RebeccaPurple`, `opacity:.6` on a section.

- [ ] **Step 1: tests** in `tests/HtmlToDiviTest.php`:
  - `noneGoldens()` provider: the three phase-1 names mapped to their existing `.layout.txt` files, the six `14-styled-*` names mapped to `*.none.layout.txt`.
  - `testStylesNoneGoldenLayout( string $name, string $file )`: `convert($html, ['styles'=>'none'])['layout']` equals the file. It writes the file ONLY when `AIED_UPDATE_NONE_GOLDEN` is set (never on `AIED_UPDATE_GOLDEN`), so a later styled-golden regeneration cannot touch it.
  - `testThePhaseOneGoldensAreTheSameInBothModes`: for 01, 03, 13 the default (`basic`) layout equals the `none` layout equals the file.
  - `testStylesNoneIgnoresEveryStyle` (data provider: all fixtures): `convert($html, none)['layout'] === convert(self::bare($html), none)['layout']`, where `bare()` (a static test helper) removes every `style="..."`/`style='...'` attribute and every `<style>...</style>` element with a DOM pass (not a regex), so no CSS can ever reach the `none` output.
  - Existing `testTheSameHtmlGivesTheSameBytes`: its second assertion becomes `convert(bare($html), basic) === convert(bare($html), none)` (a page without CSS is the same in both modes), and the old "styles do not change the structure" message moves to `testStylesNoneIgnoresEveryStyle`.
- [ ] **Step 2:** `AIED_UPDATE_NONE_GOLDEN=1 vendor/bin/phpunit --filter testStylesNoneGoldenLayout`, review the six files by eye (structure, every word), then `make test` green with the env var unset.
- [ ] **Step 3:** `git diff --exit-code tests/golden/html-to-divi/0*.layout.txt tests/golden/html-to-divi/13-images.layout.txt`; commit.

---

## Task 4: The two seams: `HtmlSource` hands over styles, `HtmlMapper` records sources

**Files:**
- Modify: `wp-plugin/src/HtmlSource.php`, `wp-plugin/src/HtmlMapper.php`, `tests/HtmlSourceTest.php`, `tests/HtmlMapperTest.php`

**Interfaces:**
```php
// HtmlSource::parse() return array gains three keys (always present; no other key changes):
//   'css'            => string                      every <style> element's text in document order (head AND body), joined
//                                                    with "\n", read BEFORE the elements are removed; cut at HtmlCss::MAX_CSS_BYTES
//   'inline'         => array<string,string>         getNodePath() => the style attribute text, for <html>, <body> and every kept
//                                                    element; captured in cleanAttributes() BEFORE the attribute is removed (paths
//                                                    are computed after all removals, so they match the returned DOM)
//   'presentational' => array<string,list<string>>  getNodePath() => the names of bgcolor, align, color, text attributes it had
// The `style` attribute is still removed from the DOM (HtmlSourceTest pins `style=` absent).

// HtmlMapper:
public static function map( \DOMElement $body, bool $sources = false ): array;
// With $sources = true ONLY, the plan gains:
//   section: 'src' => ?string (the section element's path; null for a run of loose nodes),
//            'ctx' => string  (src, or the path of the loose nodes' parent element)
//   cards chunk: 'src' => string (path of the cards' parent: the run's parent element, or the <ul>/<ol> of a card group),
//                'column_src' => list<string> (one per entry of 'columns', same order, BEFORE empty columns are filtered)
//   heading / button module: 'src' => list<string> one path (the h*, a or button element)
//   text module: 'src' => list<string> one path per merged piece: the block element (p, ul, ol, blockquote, pre, table ...) or, for
//                a run of inline nodes, the run's parent element; plus 'container' => list<string> the same list except that a
//                block element is replaced by its parent (the element the text sits directly in), for the row anchor
//   heading / button: 'container' => list<string> one path (the parent element)
// image and divider modules get nothing. With $sources = false the plan is byte-for-byte today's.
```
Implementation notes: `addProse()`, `addHeading()`, `addButton()` gain an optional trailing `list<\DOMNode> $from = []` / `?\DOMElement $from = null`; a merge into the previous text module appends to its `src`/`container`; `flushRun` passes the run nodes; every call site in `walk()` passes its node. No other logic changes.

- [ ] **Step 1: failing tests**
  - `HtmlSourceTest::testStyleTextAndInlineStylesAreHandedOverButNeverKeptInTheDom`: `<head><style>h1{color:red}</style></head><body style="color:#111"><h1 style="font-size:40px">x</h1><style>p{}</style></body>` -> `css` = `"h1{color:red}\np{}"`, `inline['/html/body'] === 'color:#111'`, `inline['/html/body/h1'] === 'font-size:40px'`, and the serialised body contains no `style=`.
  - `testPresentationalAttributesAreRecorded`: `<table bgcolor="#000"><tr><td align="center">` -> both recorded by path; neither attribute in the DOM.
  - `testAnInlineStyleOfARemovedElementIsNotHandedOver`: a `style=""` inside a removed `<form>` is absent from `inline`.
  - `HtmlMapperTest::testSourcesAreOnlyAddedWhenAskedFor`: for every `fixtures/html/*.html`, `map($body)` equals `map($body, false)`, and stripping the keys `src`, `ctx`, `column_src`, `container` from `map($body, true)` gives exactly `map($body)`.
  - `testTextModulesRecordEveryMergedPiece`: `<p>a</p>text <em>b</em><ul><li>c</li></ul>` in a `div` -> one text module with three `src` entries (`p`, the div, `ul`).
  - `testCardsRecordTheirContainerAndEachCard`: a `.grid` of three `div.card` -> `src` = the grid's path, `column_src` = the three cards' paths.
  - `testSourcePathsAreDeterministic`: two parses of the same HTML give `assertSame` plans with sources.
- [ ] **Step 2:** implement; `make test` green; `git diff --exit-code tests/golden` (no golden changed); commit.

---

## Task 5: `HtmlFonts` and the shipped font list

**Files:**
- Create: `wp-plugin/src/HtmlFonts.php`, `wp-plugin/data/google-fonts-names.txt` (a byte copy of `fixtures/shapes/google-fonts-names.txt`), `tests/HtmlFontsTest.php`
- Modify: `wp-plugin/src/autoload.php`, `tests/HtmlTestCase.php`, `tests/WpOrgComplianceTest.php` (two entries, Spec issue 1)

**Interfaces:**
```php
final class HtmlFonts
{
    public const DATA     = __DIR__ . '/../data/google-fonts-names.txt';
    /** Web-safe families, canonical case. Used only when DiviMarkup::STYLE_PROVEN['websafe-family'] is true. */
    public const WEB_SAFE = [ 'Arial', 'Helvetica', 'Georgia', 'Times New Roman', 'Verdana', 'Tahoma', 'Trebuchet MS', 'Courier New' ];
    public const GENERIC  = [ 'serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui', 'ui-serif', 'ui-sans-serif',
        'ui-monospace', 'ui-rounded', 'emoji', 'math', 'fangsong', '-apple-system', 'blinkmacsystemfont', 'inherit', 'initial', 'unset', 'revert' ];

    /** Lowercase name => canonical name of every Divi Google font (memoised; read with file_get_contents( self::DATA )). @return array<string,string> */
    public static function google(): array;

    /**
     * The first family of $families (left to right) that Divi can load: a Google font or (when proven) a web-safe one, matched
     * case-insensitively, returned in canonical case. Generic keywords are skipped (`generic_font`); every other family before the
     * chosen one is listed. @param list<string> $families
     * @return array{family:?string, fonts:list<array{source:string, action:string}>}
     *   action: 'carried' | 'not available in Divi: used <X>, the next family in the list' | 'not available in Divi: kept the theme font'
     */
    public static function choose( array $families ): array;
}
```
- [ ] **Step 1: failing tests**
  - `testTheShippedFontListEqualsTheFixture`: `sha1_file` of both files equal; 1,945 lines; sorted; no empty line.
  - `testOnlyGoogleOrProvenWebSafeFamiliesAreChosen`: `['Brand Sans','inter','sans-serif']` -> `Inter`, fonts `[{Brand Sans, 'not available in Divi: used Inter, the next family in the list'}, {Inter, carried}]`; `['Georgia']` -> `Georgia` iff `websafe-family` is true, else null with "kept the theme font"; `['sans-serif']` -> null and no font entry; `['PLAYFAIR DISPLAY']` -> `Playfair Display`.
  - `testTheFontListIsTheOnlyNewCommercialWordException`: reads `WpOrgComplianceTest`'s source and asserts exactly one `COMMERCIAL_EXCEPTIONS` row names `data/google-fonts-names.txt` and that every line of the font list that matches the test's `COMMERCIAL_WORDS` regex is a family name ending in ` Pro` (today: Advent Pro, Andada Pro, Anonymous Pro, Be Vietnam Pro, Crimson Pro, Kantumruy Pro, Kdam Thmor Pro, Maven Pro, Readex Pro, ... pinned as a count).
- [ ] **Step 2: compliance entries** (explicit, justified, nothing loosened):
  - `LOCAL_READS`: `'src/HtmlFonts.php' => ['self::DATA' => "DATA = __DIR__ . '/../data/google-fonts-names.txt' (bundled)"]`.
  - `COMMERCIAL_EXCEPTIONS`: `['data/google-fonts-names.txt', ' Pro', 'Google Fonts family names Divi ships (for example "Maven Pro"); a copy of fixtures/shapes/google-fonts-names.txt pinned by HtmlFontsTest, not wording']`.
- [ ] **Step 3:** implement; `make test` green (including `WpOrgComplianceTest`); commit. The controller reads the compliance diff before merging.

---

## Task 6: `HtmlStyles`, the cascade and inheritance (opus review)

**Files:**
- Create: `wp-plugin/src/HtmlStyles.php`, `tests/HtmlStylesTest.php`
- Modify: `wp-plugin/src/autoload.php`, `tests/HtmlTestCase.php`

**Interfaces:**
```php
final class HtmlStyles
{
    /** UA sheet facts the cascade must respect (D5): tag => properties taken ONLY from the element's own declarations. */
    public const OWN_ONLY = [ 'h1' => ['size','weight'], 'h2' => ['size','weight'], 'h3' => ['size','weight'], 'h4' => ['size','weight'],
        'h5' => ['size','weight'], 'h6' => ['size','weight'], 'a' => ['color'], 'button' => ['color','background','size','weight','family','align'],
        'pre' => ['family'], 'code' => ['family'], 'kbd' => ['family'], 'samp' => ['family'], 'tt' => ['family'] ];
    public const DEFAULT_SIZE = 16.0;

    /**
     * @param array<string,string>       $inline         HtmlSource 'inline'
     * @param array<string,list<string>> $presentational HtmlSource 'presentational'
     * @return array{nodes: array<string, Computed>, ignored: array<string,int>}  keyed by getNodePath(), html and body included
     *
     * Computed = array{
     *   color:  array{state:'set'|'initial'|'unresolved', value:?string, reason:?string},   initial value '#000000'
     *   family: array{state:'set'|'initial'|'unresolved', value:?list<string>, reason:?string},
     *   size:   array{state:'set'|'initial'|'unresolved', value:?float, reason:?string},    initial 16.0 (state initial)
     *   weight: array{state:'set'|'initial'|'unresolved', value:?int, reason:?string},
     *   align:  array{state:'set'|'initial'|'unresolved', value:?string, reason:?string},
     *   background: array{state:'none'|'set'|'unknown', value:?string, reason:?string},    not inherited
     *   padding: array{top:int|string|null, right:..., bottom:..., left:...},                px, 'unresolved' or null; not inherited
     *   max_width: int|string|null,  radius: list<int>|string|null                         'unresolved' or null; not inherited
     * }
     */
    public static function resolve( \DOMElement $body, string $css, array $inline, array $presentational ): array;
}
```
Cascade (documented in the class docblock): for each element and property, candidates are the matching simple rules' declarations, the poison-only rules' declarations whose subject matches (tag equal or null, every id and class present), the inline declarations and the presentational markers (`bgcolor` -> background poison, `align` -> align poison, `color`/`text` -> color poison); the winner is the highest by (important, inline, specificity, order) with poison-only entries ranking as normal rules at their own specificity and order; a poison winner makes the property `unresolved`. Inherited properties (`color`, `family`, `size`, `weight`, `align`) take the parent's computed value when no candidate exists, except the `OWN_ONLY` ones (state `initial`). `font-size` in `em`/`%` resolves against the parent's computed size (unresolved parent -> unresolved); `rem` = 16. `opacity` < 1, `filter` other than `none`, `mix-blend-mode` other than `normal`, `-webkit-text-fill-color` poison `color` for the element and below (`effect`). Rules are indexed by tag, id and class once (no element x rule scan). Clamps are NOT applied here (Task 8 applies them, so the report can count them).

- [ ] **Step 1: failing tests** (each builds a small document and asserts computed records by path):
  - `testSpecificityThenOrderThenInline`: `#a` beats `.b` beats `p`; of two equal rules the later wins; inline beats `#a`.
  - `testImportantBeatsNormalAndInlineBeatsRules`: a `p{color:red !important}` beats inline `color:blue`; inline `!important` beats both.
  - `testInheritanceAndRelativeSizes`: `body{font-size:18px;color:#111}` -> `p` 18 and `#111111` (state set); `p{font-size:1.5em}` inside `div{font-size:20px}` -> 30; `rem` ignores the parent.
  - `testHeadingsTakeSizeAndWeightOnlyFromTheirOwnRules`: `body{font-size:18px;font-weight:300}` -> `h1` size and weight `initial`; `h1{font-size:2em}` -> 36.
  - `testLinksAndButtonsTakeColourOnlyFromTheirOwnRules`: `body{color:#111}` -> `a` colour `initial`; `.btn{color:#fff}` on the `a` -> set.
  - `testAPropertyAnUnreadableRuleMightSetIsUnresolved`: `.hero h1{color:#fff}` + `h1{color:#000}` -> `h1` colour unresolved; `h2` (no match) `#000000`; `p{color:var(--x)}` -> unresolved and its `span` child unresolved; `font: 20px Inter` -> size, weight, family unresolved.
  - `testMediaRulesPoisonButDoNotApply`: `h1{font-size:40px} @media (min-width:768px){h1{font-size:72px}}` -> unresolved (reason `media`); a media rule for `h3` leaves `h2` at its own value.
  - `testStatesNeverPoison`: `a:hover{color:red}` leaves the `a`'s own colour set.
  - `testBackgroundsAreOwnOnly`: `section{background:#123}` -> section set `#112233`, its `p` background none; `background-image:url(x)` with a colour -> unknown; `bgcolor` attribute -> unknown.
  - `testEffectsPoisonColourBelow`: `section{opacity:.6}` -> every descendant's colour unresolved (`effect`).
  - `testCenterAlignsAtTheLowestPriority`: `<center><p>` -> align center; `p{text-align:left}` inside wins.
  - `testTheSameInputGivesTheSameRecords`: two runs over every `14-styled-*` fixture `assertSame`; keys sorted by document order.
  - `testABigStyledPageIsFast`: a generated page of 4,000 elements and 1,500 rules resolves in under 2 seconds.
- [ ] **Step 2:** implement; `make test` green; commit.

---

## Task 7: `DiviMarkup` writes styles, and the grounding test

**Files:**
- Modify: `wp-plugin/src/DiviMarkup.php`
- Create: `tests/DiviMarkupStyleTest.php`, `tests/HtmlToDiviGroundingTest.php`, `tests/HtmlStylesVocabularyTest.php`

**Interfaces:**
```php
/** Decision => proven ON its block by Task 1 (fixtures/shapes/html-styles-vocabulary.md "Adapter decisions", same keys, same order). */
public const STYLE_PROVEN = [ 'text-align-orientation' => ?, 'text-align-bodyfont' => ?, 'text-justify' => ?, 'heading-justify' => ?,
    'websafe-family' => ?, 'row-max-width' => ?, 'list-ink-ul' => ?, 'list-ink-ol' => ?, 'quote-ink' => ?, 'row-background' => ?,
    'column-background' => ?, 'column-padding' => ?, 'section-padding' => ? ];   // ? = the Task 1 verdict (PASS = true)

public static function section( string $label, string $rows, array $style = [] ): string;
//  $style: background?:#rrggbb, padding?:array<'top'|'right'|'bottom'|'left', '<n>px'>   (padding only if section-padding)
public static function row( array $columns, array $style = [], array $columnStyles = [] ): string;
//  $style: background? (row-background), padding?, max_width?:'<n>px' (row-max-width); $columnStyles: list<array{background?, padding?}>
public static function heading( string $text, int $level, array $style = [] ): string;
//  color?, size?:int (px), weight?:'100'..'900', align?:'center'|'right'|'justify' (justify only if heading-justify), family?:string
public static function text( string $html, array $style = [] ): string;              // same keys as heading
public static function button( string $text, ?string $linkUrl, bool $newTab, array $style = [] ): string;
//  color?, background?:#rrggbb|KitAdapters::TRANSPARENT literal 'rgba(255,255,255,0)', radius?:int (px)
```
Exact shapes (keys in this order; a key is present only when its value is):
- heading: `title` = `{innerContent, decoration:{font:{font:{desktop:{value:{headingLevel, color, size, weight, textAlign, family}}, tablet:{value:{size}}, phone:{value:{size}}}}}}`; `tablet`/`phone` only when `size > 32`: tablet `round(0.75 x size)`, phone `max(20, round(0.55 x size))`.
- text: `content` = `{innerContent, decoration:{bodyFont:{body:{font:{desktop:{value:{color, size, weight, family, textAlign*}}, tablet, phone}}}}}` then `module` = `{advanced:{text:{text:{desktop:{value:{orientation}}}}}}` (D7: `orientation` when `text-align-orientation`, else `textAlign*` in `bodyFont` when `text-align-bodyfont`, else no alignment), then `builderVersion`.
- button: `button` = `{innerContent, decoration:{background:{desktop:{value:{color}}}, border:{desktop:{value:{radius:{topLeft, topRight, bottomRight, bottomLeft}}}}, font:{font:{desktop:{value:{color}}}}}}`.
- section: `module` = `{meta (as today, when a label), decoration:{background:{desktop:{value:{color}}}, spacing:{desktop:{value:{padding:{top,right,bottom,left}}}}}}`, then `builderVersion`.
- row: `module.decoration` = `{layout (as today), background, spacing, sizing:{desktop:{value:{maxWidth}}}}`; column: `module.decoration` = `{sizing (as today), background, spacing}`.
Values are validated before writing (an invalid value is a `\InvalidArgumentException`: a converter bug, never user input): colour `/^#[0-9a-f]{6}\z/` (or the transparent literal on a button fill), px `/^\d{1,4}px\z/`, size 8-200, padding 0-200, max width 200-2400, radius 0-999, weight in the nine values, family `/^[A-Za-z0-9 ]{1,60}\z/`, align in the allowed set, unknown `$style` key refused.

- [ ] **Step 1: failing tests**
  - `DiviMarkupStyleTest::testAnEmptyStyleIsTodaysMarkup`: for every `DiviMarkupTest::samples()` case, the call with `[]` (and `[], []` for rows) is byte-identical to the call without.
  - `testEveryStyleKeyLandsOnItsPath`: one sample per key per block; the decoded JSON has the value at exactly the path above.
  - `testLargeSizesGetTabletAndPhoneSteps`: 56 -> 42 / 31; 33 -> 25 / 20; 32 -> no steps; text likewise.
  - `testStyleValuesAreValidatedBeforeWriting`: `#fff`, `red`, `12em`, `-1px`, `1000`, family `Inter;}` each throw.
  - `testStyledMarkupIsValidAndHasNoCustomCode`: a page of fully styled blocks passes `LayoutGate::check()` and `CodePolicy::violations()` is `[]`.
  - `HtmlToDiviGroundingTest::testEveryStylePathIsInTheRegistry`: every leaf path of the fully styled samples (`ShapePaths::fromMarkup`) is covered by `ShapeRegistry::load()`.
  - `testEveryStylePathIsGroundedOnItsOwnBlock`: per block, each path is in the per-block set built like `DiviMarkupTest::known()` (exports + served recipes, extract that builder into `tests/Support/KnownBlockPaths.php` and use it from both tests) OR is the path of a `STYLE_PROVEN` decision whose vocabulary row is PASS and whose evidence candidates have `block` equal to this block.
  - `testNoStylePathIsDeadOnItsModule`: `DeadOnModule::load()->violations($markup) === []` for the styled samples.
  - `testADecisionThatIsFalseIsNeverWritten`: for every `STYLE_PROVEN` key that is false, its path appears in no sample even when the style asks for it (the key is silently not written and the caller is told by a return-free contract: Task 8 never asks; this test plants the ask).
  - `HtmlStylesVocabularyTest::testStyleProvenMatchesTheVocabularyTable`: parses the "Adapter decisions" table: same 13 keys, same order, PASS iff true.
- [ ] **Step 2:** implement (`DiviMarkupTest` unchanged and green); `make test`; commit.

---

## Task 8: `HtmlStyleBlocks` and `HtmlReadability` (the readability core, opus review)

**Files:**
- Create: `wp-plugin/src/HtmlStyleBlocks.php`, `wp-plugin/src/HtmlReadability.php`, `tests/HtmlStyleBlocksTest.php`, `tests/HtmlStylesReadabilityTest.php`
- Modify: `wp-plugin/src/autoload.php`, `tests/HtmlTestCase.php` (add these and `DesignLanguages`)

**Interfaces:**
```php
final class HtmlStyleBlocks
{
    public const SIZE_MIN = 8; public const SIZE_MAX = 200; public const PAD_MAX = 200;
    public const WIDTH_MIN = 200; public const WIDTH_MAX = 2400; public const RADIUS_MAX = 999;
    public const CANVAS = '#ffffff';

    /**
     * The plan (map($body, true)) plus HtmlStyles::resolve() -> candidate styles per block, before any colour decision.
     * @return array{sections: list<SectionC>, clamped:int, ignored: array<string,int>, fonts: list<array{source:string,action:string}>}
     * SectionC = {paint: Paint, padding: array<side,int>, rows: list<RowC>}            one RowC per plan chunk
     * RowC     = {paint: Paint, padding, max_width:?int, columns: list<ColumnC>}         a cards chunk's RowC applies to each Divi row it becomes
     * ColumnC  = {paint: Paint, padding, modules: list<ModuleC>}                         a single chunk has one ColumnC with no paint or padding
     * Paint    = {state:'none'|'set'|'unknown', value:?string}
     * ModuleC  = {type, path, ink: {state:'set'|'initial'|'unresolved'|'mixed', value:?string}, size:?int, weight:?string,
     *             align:?string, family:?string, large:bool, lists:list<'ul'|'ol'|'blockquote'>,
     *             between: array{column:bool, row:bool, section:bool},   an uncarried background between the module's style
     *                                                                     element(s) (inclusive) and that level's anchor (exclusive)
     *             button only: fill: Paint (own), label: {state, value}, parent_ink: {state, value}, radius:?int}
     */
    public static function build( array $plan, array $computed ): array;
}

final class HtmlReadability
{
    public const NORMAL = 4.5; public const LARGE = 3.0;    // large = size >= 24, or >= 19 with weight >= 700 (carried values only)

    /**
     * Paint and ink travel together (D1, D2): decides every colour and returns the styles DiviMarkup writes plus the report parts.
     * @return array{sections: list<array{style:array, rows: list<array{style:array, columns: list<array{style:array, modules: list<array>}>}>}>,
     *               report: array{carried: array<string,int>, ignored: array<string,int>, dropped_unreadable: list<array>, clamped:int}}
     */
    public static function decide( array $blocks ): array;
}
```
Anchors (`HtmlStyleBlocks`): section anchor = `src` (own) or `ctx`; section paint = the first `background` state that is not `none` on [anchor, its ancestors up to `html`] (D3) with `set` -> paint, `unknown` -> unknown. Row anchor = the cards chunk's `src`, or for a single chunk the lowest common ancestor of its modules' `container` paths; no row anchor (paint none, no padding) when it equals the section anchor. Column anchor = its `column_src`. Padding from a block's own anchor only (each side `set` -> clamped int; `unresolved` sides not written, counted `unresolved`). Row `max_width` = the smallest carried max width on [row anchor or, if none, the section anchor or ctx, and its ancestors up to `html`] (D6). Module values: size, weight, align, family from the computed record of its first `src`; for a text module every property on which its `src` records disagree is dropped (`mixed`; ink state `mixed`); family through `HtmlFonts::choose()`; align `left` dropped (D7), `justify` only if proven; `large` from carried size and weight; `lists` from the module html (`<ul>`, `<ol>`, `<blockquote>`).

Decision (`HtmlReadability::decide`), per section, simultaneous update per pass until nothing changes:
1. A level is *kept-painted* if its paint is `set` and not dropped. Section: canvas paint `#ffffff` (D2) when its own paint is `none` and any heading/text module in it has ink `set`.
2. For each heading/text module: L = the innermost kept-painted level containing it (column, row, section). No L -> no colour (a `set` ink is listed: `unknown_background` when a containing level is `unknown`, else `paint_dropped`). With L: the module FAILS when `between[L]` is true, or a level inside L has an original paint not `none`, or its ink is `unresolved`/`mixed`, or its list kinds are not all proven (`list-ink-ul`, `list-ink-ol`, `quote-ink`), or `contrast(ink ?? '#000000', paint(L)) < (large ? 3.0 : 4.5)`; else its colour is written.
3. For each button: a *pair* when its own fill is `set`, its own label is `set` and the label/fill contrast is >= 4.5: write fill, label (and radius). Else, with an L: a *ghost* (fill transparent, label = `parent_ink` value or `#000000` when `initial`) that must pass rule 2's checks against paint(L) (`button_ghosted`), or the button FAILS. Without L: no colours (radius still written).
4. Every FAILING module drops the paint of every kept-painted level that contains it. Repeat from 1 (the canvas decision is not re-made: a dropped canvas stays dropped).
5. Only after the fixpoint: write paints of kept-painted levels, inks, pairs and ghosts. Padding, max width, size, weight, align, family and radius are written regardless of paint (they do not affect readability). `dropped_unreadable` lists every module whose `set` ink was not written: `{path, block, reason, color, background, ratio}` (ratio rounded to 2 decimals as a string, background null when unknown), at most 25; `carried` counts blocks per property (`color` = inks + button labels, `background` = painted blocks + button fills, `font`, `size`, `weight`, `align`, `padding`, `max_width`, `radius`).

- [ ] **Step 1: failing tests** (`HtmlStylesReadabilityTest`; inputs are small `HtmlStyleBlocks` arrays built by a helper, plus three end-to-end documents through `build()`):
  - `testContrastIsTheWcagFormula`: `#767676`/`#ffffff` 4.54, `#777777`/`#ffffff` 4.48 (via `DesignLanguages::contrast`, compared with `tests/Support/Colour::contrast`), so the threshold edge is exact.
  - `testADeclaredColourOnAnUnpaintedSectionGetsTheCanvas`: `h1{color:#1d4ed8}` on a page without backgrounds -> section paint `#ffffff`, h1 ink written, the `p` beside it inked `#000000`.
  - `testAPageWithoutColoursStaysUnstyled`: no colour, no background -> no paint, no ink anywhere (phase-1 colours).
  - `testTheDarkPageCarriesItsBackgroundIntoEverySection`: `body{background:#0b1120;color:#e2e8f0}` -> every section painted `#0b1120`, every module inked `#e2e8f0`.
  - `testUnreadableTextDropsThePaintOfItsChain`: a `#475569` `p` on the dark page -> that section unpainted, every module in it uncoloured, listed with ratio `"2.49"`; the other sections untouched.
  - `testAnUnknownBackgroundBetweenTextAndPaintFails`: a `p` with its own `background:#000;color:#fff` in a painted section -> the section's paint dropped, `unknown_background`.
  - `testAGradientSectionIsNeverPainted`: white text on `linear-gradient` -> no paint, no ink, listed `unknown_background`.
  - `testLargeTextUsesTheThreeToOneFloor`: `#949494` on white at 24px (3.03:1) carried; at 23px not; at 19px bold carried.
  - `testAButtonCarriesItsPairOrNothing`: fill + label readable -> both; fill without label off paint -> neither; label `#ffffff` on fill `#fde047` -> neither.
  - `testAnUnpairedButtonOnPaintBecomesAGhost`: plain `a.btn` in a dark painted section -> fill transparent, label `#e2e8f0`.
  - `testCardsArePaintedOnTheirOwnColumns`: the classes fixture -> section `#f3f4f6`, each column `#ffffff`, modules inked against `#ffffff`.
  - `testTheDecisionDoesNotDependOnModuleOrder`: the same section with modules permuted -> the same set of kept paints.
  - `testItTerminatesWithinTheNumberOfPaints`: a crafted section of 4 nested failures converges in <= 4 passes (pass count exposed for the test via the report's internal `passes`, stripped by the facade).
  - `HtmlStyleBlocksTest::testAnchorsMaxWidthAndPadding`: the inline fixture's container -> row anchor, `max_width` 720, row padding 40 x 4; a lone heading directly in a section -> no row anchor.
  - `testClampsAreAppliedAndCounted`: 900px -> 200 (`clamped` 1), padding -4px -> not written (`unit`), max width 100px -> 200.
  - `testMixedTextPiecesDropOnlyTheDisagreeingProperties`: two `p` with the same size and different colours -> size kept, ink `mixed`.
- [ ] **Step 2:** implement; `make test` green; commit.

---

## Task 9: Wiring, report, descriptions and the styled goldens

**Files:**
- Modify: `wp-plugin/src/HtmlToDivi.php`, `wp-plugin/src/McpHandler.php` (descriptions only), `wp-plugin/src/SiteGuide.php` (step 5 sentence), `tests/HtmlToDiviTest.php`, `tests/HtmlToDiviLockstepTest.php`, `tests/golden/html-to-divi/14-styled-*.layout.txt` (new, generated)

Facade (`HtmlToDivi::convert`): `none` -> exactly today's path (`HtmlMapper::map($body)`; no `HtmlStyles` call; report without `styles`). `basic` -> `map($body, true)`, `HtmlStyles::resolve($body, $source['css'], $source['inline'], $source['presentational'])`, `HtmlStyleBlocks::build()`, `HtmlReadability::decide()`, and every `DiviMarkup::section/row/heading/text/button` call gets its style array (a cards chunk's row style goes to each of its Divi rows; column styles follow the columns that survive the empty-column filter: filter indices, not values, so `column_src` stays aligned). `report.styles` = `{carried, ignored (the union of HtmlCss, HtmlStyles, HtmlStyleBlocks and HtmlReadability counters, keys sorted), dropped_unreadable, fonts (unique by source, at most 20), clamped}`; every string capped at 200 characters. The "not converted yet" note is removed (D9).

Descriptions (no schema change):
- `html_to_divi` description: replace "It does not read colors or fonts from the HTML (styles are not converted yet): style the result with get_style_guide." with "With styles=basic (the default) it also carries the HTML's basic colors, fonts, sizes, alignment, padding and widths where Divi can show them and the text stays readable; report.styles lists what was carried and what was left out. Finish the look with get_style_guide."
- `styles` property: "Optional. basic (default): carries text and background colors, Google or web-safe fonts, font size and weight, alignment, padding, row width and button colors and corners from inline styles and simple <style> rules, only where the text stays readable. none: structure and content only."
- `SiteGuide` step 5: "**Make it look right**: the converter carries the HTML's basic colors, fonts and spacing where Divi can show them and the text stays readable (`report.styles` lists what it left out). Finish the look with `get_style_guide` and `list_global_styles`, and use a section recipe for a hero or call to action where it fits."

- [ ] **Step 1: tests**
  - `HtmlToDiviTest`: replace `testTheStyleNoteIsOnlyThereWhenStylesAreAskedFor` with `testTheStylesReportIsOnlyThereWhenStylesAreAskedFor` (`basic` has `report.styles` with the five keys; `none` has no `styles` key; no note in either contains "not converted yet").
  - `goldens()` provider gains the six `14-styled-*` names; `testGoldenLayout` unchanged (default mode).
  - `testEveryStyledFixtureMeetsTheOracle` (provider: the six): `StyledLayoutOracle::problems($layout) === []` (Task 10 adds the class; until it lands this test is written against a minimal inline version of rule (1) and (2) and switched to the class in Task 10).
  - `testTheStyledFixturesCarryWhatTheyShould`: pinned facts per fixture (inline: h1 `#ffffff` 56px weight 800 centred, section `#0f172a`, button pair, row max width 720; classes: `#intro` colour wins, h2 36/27/20, three white columns on `#f3f4f6`; dark: four sections painted, the last one unpainted and listed; backgrounds: `#123456` section painted, gradient/image/rgba sections unpainted; fonts: Playfair Display on headings, Inter on text, Brand Sans listed, `pre` without family; hostile: h1 size unresolved (media), `.hero h1` colour unresolved, 200px clamped, `RebeccaPurple` carried as `#663399` where readable).
  - Existing provider-driven tests (`testEveryFixtureConvertsToAValidLayout`, text, links, `testNothingDangerousCanBeInTheResult`) run over the six automatically.
  - `HtmlToDiviLockstepTest::testTheStylesDescriptionsSayWhatIsCarried`: the tool and `styles` descriptions contain neither "not converted yet" nor "only adds a reminder", the `styles` description is <= 300 characters, the tool description is at most 120 bytes longer than today's (measure today's length in the test's docblock), and the property list is unchanged.
- [ ] **Step 2:** implement; `AIED_UPDATE_GOLDEN=1 vendor/bin/phpunit --filter testGoldenLayout`; `git diff --exit-code tests/golden/html-to-divi/0*.layout.txt tests/golden/html-to-divi/13-images.layout.txt tests/golden/html-to-divi/*.none.layout.txt`; the reviewer reads every `14-styled-*.layout.txt` next to its `.none` twin (only style keys may differ); `make test` green; commit.

---

## Task 10: The property test over random styled HTML and the layout oracle

**Files:**
- Create: `tests/Support/StyledLayoutOracle.php`
- Modify: `tests/HtmlToDiviTest.php`

**Interfaces:**
```php
final class StyledLayoutOracle
{
    /**
     * Problems of a converted layout, judged from the OUTPUT only (BlockParser), independent of the converter's code:
     *  1 ink only on paint: every heading/text colour has a painted ancestor block (column, row, section);
     *  2 paint implies ink: every heading/text under a painted block has a colour;
     *  3 every such colour meets 4.5:1 (3:1 when its written size >= 24, or >= 19 with weight >= 700) against the innermost paint;
     *  4 a button has a label colour iff it has a fill; a solid fill pair meets 4.5:1; a transparent fill only under paint, its label
     *    meeting the floor against that paint; every button under paint has a pair or a ghost;
     *  5 value forms: #rrggbb, '<n>px' within the clamps, family in HtmlFonts::google() or (when proven) WEB_SAFE.
     * @return list<string>
     */
    public static function problems( string $layout ): array;
}
```
- [ ] **Step 1:** `testTheOracleCatchesPlantedFaults`: five hand-made layouts, one per rule, each gives exactly its problem.
- [ ] **Step 2:** `testRandomStyledHtmlNeverBreaksTheContract`: seeded (`AIED_SEED`, default 20261022), 200 cases; the generator extends the phase-1 one: each element gets a random `style=""` from a pool (colours in every form incl. alpha, `var()`, `currentColor`; backgrounds incl. gradients; sizes px/em/rem/%/keywords/`calc()`; weights; families from Inter, "Playfair Display", Georgia, "Brand X", sans-serif; aligns; padding shorthands incl. negative and %; max widths; radii; `opacity`; `!important`), and the page gets a random `<style>` of 0-12 rules over `tag`, `.card`, `.btn`, `#x`, `.hero h1`, `a:hover`, `p.lead`, `@media (min-width:600px){...}`. It returns a pair `[styled, bare]` from the same choices (bare = the same tree without any style). Assertions per case: never throws; `ok` or a plain error; valid through `LayoutGate`; `text_check.equal`; `CodePolicy::violations() === []`; no `style=` or `<style` in the layout; `StyledLayoutOracle::problems() === []`; `convert(styled, none).layout === convert(bare, none).layout === convert(bare, basic).layout`; and the same seed gives the same bytes twice. `> 100` cases convert.
- [ ] **Step 3:** switch `testEveryStyledFixtureMeetsTheOracle` to the class; run 40 seeds locally (`for s in $(seq 1 40); do AIED_SEED=$s vendor/bin/phpunit --filter RandomStyled || break; done`); `make test`; commit.

---

## Task 11: The live gate extension of `make verify-html-to-divi` (scripts by a worker, run by the controller)

**Files:**
- Create: `scripts/verify-html-to-divi-styles.php` (wp eval-file helper), `scripts/verify-html-to-divi-styles.cjs`, `scripts/lib/html-styles-measure.cjs`, `scripts/test-verify-html-styles.cjs`
- Modify: `Makefile` (`verify-html-to-divi` runs the existing PHP gate, then `node scripts/test-verify-html-styles.cjs`, then `node scripts/verify-html-to-divi-styles.cjs`; its comment line updated)

Helper (`wp eval-file /scripts/verify-html-to-divi-styles.php create|delete <ids>|clean`, `dev-guard.php`): `create` saves every `/fixtures/html/14-styled-*.html` through the REAL `HtmlToDiviService::run` (`create=true`, title `aied html styles <token> <name>`), reads the stored content back, and prints JSON: per page `{name, id, source_html, blocks:[{block, snippet (first 24 visible characters of the module, or of the first module inside a section/row/column), written:{...decoded style attrs}, src:?xpath}]}` (block order = the layout's order; module `src` from `HtmlMapper::map($body, true)` on the same HTML, in plan order). `delete` and `clean` (every page titled `aied html styles `) remove pages; the node script always calls `delete` in `finally`.

Node gate (one run at a time, localhost only, login cookie and `?page_id=<id>&preview=true` like `verify-guide-shapes.cjs`): for each page, (a) the SOURCE: `page.setContent(source_html)` with JavaScript disabled and every request aborted, at 1280 px; each module's source element by `document.evaluate(src)`; (b) the DIVI page at 1280, 900 and 400 px; each module = the `.et_pb_module` whose text contains its snippet, each block container = its closest `.et_pb_section` / `.et_pb_row` / `.et_pb_column`. Codes:
- `hs-color`, `hs-weight`, `hs-align`, `hs-size` (1280: Divi computed = written = source computed; 900 and 400: = the written tablet/phone step where written), `hs-family` (Divi's first computed family = written; written is in the source's computed `font-family` list), `hs-bg` (a painted block's computed background = written; for a block with its own anchor = the source element's computed background; canvas = `rgb(255, 255, 255)`), `hs-padding`, `hs-max-width` (row width <= written and centred within 1 px), `hs-radius`, `hs-button` (label and fill = written, contrast >= 4.5 on the painted fill),
- `hs-contrast`: EVERY heading, text paragraph/list item and button label of every styled page (styled or not), at 1280: computed colour vs the painted background (first non-transparent computed background up the tree; a background image on that path makes it `unmeasurable`, which is a FAIL only under a written paint) >= 4.5 (3.0 large),
- `hs-overflow`: at 400 px `document.documentElement.scrollWidth <= 400`,
- `hs-mapping`: a source element not found, or whose text does not start with the snippet, FAILS (never skipped),
- `hs-measured`: a page with zero measured modules FAILS,
- screenshots `dist/html-to-divi/14-styled-dark.source.png` and `14-styled-dark.divi.png` at 1280 (full page), named in the log for the review.
Pure helpers (`html-styles-measure.cjs`: `rgbToHex`, `firstFamily`, `familyList`, `contrast`, `paintedBackground(tree)`, `emptyRunProblems(results)`, `mappingProblems(results)`) with tests in `scripts/test-verify-html-styles.cjs` (synthetic objects, no browser; at least one planted failure per code).

- [ ] **Step 1 (worker):** write the four files and the Makefile change; `node scripts/test-verify-html-styles.cjs` passes locally (pure, no site). Commit.
- [ ] **Step 2 (controller, live, serial):** `make verify-html-to-divi`; on FAIL, triage: a converter fault goes back to Task 8/9 as a fix with a new unit test reproducing it; a Divi rendering fact (a path that renders differently than its probe showed) goes to the vocabulary and `STYLE_PROVEN`; a gate fault is fixed in the gate with a pure test. Record PASS/FAIL counts and the run time for the Outcome; look at the screenshot pair.

---

## Task 12: Docs, CLAUDE.md, the spec Outcome and the final review

**Files:**
- Modify: `docs/html-to-divi-workflow.md`, `CLAUDE.md` (`html_to_divi` paragraph and the `make verify-html-to-divi` row of the Entry points table), `docs/superpowers/specs/2026-10-22-html-to-divi-styles-design.md` (an `## Outcome` section), `docs/token-usage.md` (the tool-definitions row, re-measured with `php scripts/measure-tokens.php` as that doc says), `fixtures/html/README.md` (verified wording)
- Create: `tests/HtmlToDiviDocsTest.php`

- [ ] **Step 1:** `HtmlToDiviDocsTest::testNoClaimSaysStylesAreNotConverted`: `CLAUDE.md`, `docs/html-to-divi-workflow.md`, `wp-plugin/src/McpHandler.php`, `wp-plugin/src/SiteGuide.php`, `wp-plugin/src/HtmlToDivi.php` contain none of "not converted yet", "Styles (colors, fonts) are NOT converted", "styles are not converted"; `wp-plugin/readme.txt` is EXCLUDED on purpose (owner-applied at release; the test names it in a comment).
- [ ] **Step 2:** workflow doc: the "CSS" row of the "What it will not do" table becomes "CSS that Divi cannot show or that would make text hard to read | The converter carries only a proven subset | Gradients, images, media queries, hover, borders, shadows, line height and the rest are counted in `report.styles.ignored`; colours that would be unreadable are dropped and listed"; a new section "What it carries" (the property list, the readability rule in two sentences, the font rule); "Limits": 19 sample pages, Divi 5.15.1, the styled checks of the gate.
- [ ] **Step 3:** CLAUDE.md: replace "Styles (colors, fonts) are NOT converted yet (phase 2)." with one paragraph: phase 2 (spec, plan), the five units and two seams, paint-and-ink, soundness, `STYLE_PROVEN` pinned to `fixtures/shapes/html-styles-vocabulary.md`, the font list copy, the two compliance entries, the six styled fixtures and their `.none` goldens, the oracle and the property test, the live gate codes, the known limits below.
- [ ] **Step 4:** spec Outcome: the decisions D1-D10, the Task 1 verdict table, test counts (free suite before/after), gate numbers, tool-definition bytes before/after, the Spec issues and how the controller ruled, known limits (links inside text keep the theme's link colour; external stylesheets and `<link>` are not read; compound selectors such as `p.lead` are not applied (poison only); column and section max width not written; button family/size/padding not carried; a mobile-first stylesheet carries little because its desktop values live in media queries; only plugin-carried properties are measured live), and the owner's release-time readme changes (below).
- [ ] **Step 5: proposed `readme.txt` sentences for the owner (NOT applied):** the tool bullet: "... the structure, all the copy, the links and the images, plus the basic colors, fonts and spacing where Divi can show them and the text stays readable. ... Scripts, forms, embeds and site navigation are never converted and are listed in a report (available over MCP and the REST API, not in the ChatGPT Action)."; the FAQ "Can it turn my HTML page into a Divi page?": replace "colors and fonts are not converted yet" with "it carries the basic colors, fonts and spacing of the HTML where Divi can show them, and never a color that would make text hard to read"; a changelog line for the release: "Improved: `html_to_divi` now carries the HTML's basic colors, fonts, sizes, alignment and spacing, and drops any color that would make text hard to read."
- [ ] **Step 6:** `make test` (free) and `make test-pro` green; `git diff main -- src wp-plugin/validator jhmg-ai-editor-for-divi-5.zip wp-plugin/readme.txt wp-plugin/jhmg-ai-editor-for-divi-5.php` empty; final whole-branch review (opus) against the Review Focus list; commit.

---

## Spec issues found

1. **The font list trips the compliance scan.** `WpOrgComplianceTest` scans every `.txt` under `wp-plugin/` with `\bPro\b` (`COMMERCIAL_WORDS`), and Divi's Google list has family names such as "Maven Pro", "Crimson Pro", "Be Vietnam Pro"; reading a data file also needs a `LOCAL_READS` entry. Proposed (Task 5): two explicit, justified allow-list entries (a `COMMERCIAL_EXCEPTIONS` row scoped to that one file and the substring " Pro", and a `LOCAL_READS` row), with a test that the font file is byte-equal to the fixture and that its only hits are ` Pro` family names. Nothing is loosened, but the controller must confirm this is acceptable under "never weaken"; the alternative is to drop every "... Pro" family from the shipped copy (then the copy is no longer "equal" to the fixture as the spec says).
2. **The phase-1 seam does not exist yet.** The spec says `HtmlSource` keeps `style` on each node and collects `<style>` text; the code removes the `style` attribute (`HtmlSourceTest` pins `style=` absent from the DOM) and drops `<style>` without collecting it (and only cleans `body`, so `<head>` styles and `body`/`html` own styles are never seen). Task 4 adds the hand-over (`css`, `inline`, `presentational`) without keeping `style` in the DOM. The mapper also has no link from modules to DOM nodes; Task 4 adds an opt-in `$sources` flag rather than a rewrite.
3. **`HtmlToDiviGroundingTest` does not exist**; the grounding today is `DiviMarkupTest::testEveryAttributePathExistsInARealExportOrRecipe` (exports + served recipes only, per block). Task 7 creates `HtmlToDiviGroundingTest` for the style paths (registry + per-block export/recipe OR a PASS probe on that block) and leaves `DiviMarkupTest` as it is.
4. **Ruling 1's "the theme colour wins" can produce unreadable text.** When a dark background is carried and some text's colour is dropped, that text prints in the theme's colour (unknown to the converter, usually dark) on the carried dark background; likewise a carried light colour printed on the theme's background. The plan replaces the per-colour check by D1 (paint and ink travel together) and D2 (canvas paint), which keep Ruling 1's intent (readability first, never silent) without any assumption about the theme. Cost: a section loses its carried background when any module on it cannot be inked readably.
5. **The dark-page rule is generalised** (D3): every carried wrapper/body/html background propagates to the sections, not only a dark one, because the readability check must judge text against what is actually painted.
6. **Ruling 2 "flattened" is read strictly** (D4): a property that an `@media` rule sets for an element is not carried (counted `media`) instead of carrying the base value, because the base value of a mobile-first stylesheet is not what the source shows at desktop width and the live gate compares at 1280 px. Cost: mobile-first CSS carries little.
7. **Max width on sections and columns is not grounded**: no real export writes `module.decoration.sizing.*.maxWidth` on `divi/section` or `divi/column`; the plan writes it on rows only (D6). Probing it on those two blocks could be added to Task 1 if the controller wants it.
8. **Text alignment on `divi/text`:** the path the style guide teaches (`content.decoration.bodyFont.body.font.{bp}.value.textAlign`, measured by `make verify-guide-shapes`) is not in the shape registry and no export writes it on a text module; the exported, registered path is `module.advanced.text.text.{bp}.value.orientation`. Task 1 probes both; D7 picks orientation first.
9. **The spec's selector grammar excludes compound selectors** (`p.lead`, `a.btn`, `.btn.primary`), which are common. The plan follows the spec (they are poison-only, so a property they set is not carried rather than carried wrong); a later phase could apply compounds.
10. **Headings and links do not inherit font size, weight or colour in a browser** (the UA sheet sets them); the spec's inheritance list would carry `body{font-size:18px}` onto an `h1`. The plan follows the browser (D5).
11. **Radius has no clamp in the spec**; the plan clamps 0-999 px (D8).
