# Dogfood Harness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the "dogfood" routine (a fresh assistant builds a Divi page from the served guide only) repeatable and its judgement measurable: `dogfood-prepare` writes the guide files and a TASK.md, `dogfood-score` statically scores a page against the REAL kit adapters, `dogfood-render` validates, renders and deletes it on the dev site, and a pinned regression corpus documents what real assistants got right and wrong.

**Architecture:** Thin scripts over unit-testable classes. All logic lives in dev-side PHP classes under `tests/Support/Dogfood/` (namespace `Divi5Validator\Tests\Support\Dogfood`, autoloaded by composer `autoload-dev`), which read the plugin ONLY through its public classes (`StyleGuide`, `StyleKits`, `DesignLanguages`, `SectionRecipes`, `KitResolver`, `RecipeTokens`, `KitAdapters` constants, `LayoutGate` in tests) and the validator's `BlockParser`. Expected values are never a hand table: the scorer builds a one-block marker markup (`"aied":{"kit":<role>,"on":<band>}`) and resolves it with `KitResolver::resolve` + `RecipeTokens::resolve`, exactly as a recipe is served, then reads the resolved attributes. `scripts/dogfood-prepare.php` and `scripts/dogfood-score.php` are argument parsing plus one call. `scripts/dogfood-render.cjs` mirrors the prototype `render.cjs` on top of `scripts/lib/throwaway-pages.cjs`, with its pure helpers in `scripts/lib/dogfood-render.cjs`.

**Tech Stack:** PHP 8.1 + PHPUnit 11 (`make test`), Node + Playwright (from `../layoutlab/node_modules/playwright`, as `verify-kits`) against the Docker dev site for the render script only.

**Spec:** `docs/superpowers/specs/2026-10-22-dogfood-harness-design.md` (approved by delegation; Rulings 1-5). Prototype (scratch, untracked, read it): `/private/tmp/claude-501/-Users-Lucas-Documents-JHMG-Local-Divi-5-Deterministic-Validator/b0a2b375-b647-47c9-8329-5faff98d2b94/scratchpad/dogfood/` (`export.php`, `observe.php`, `render.cjs`, the `TASK.md` files, and the 7 produced pages). Code to mirror: `scripts/verify-kits.cjs` (argument parsing, `PLAYWRIGHT_PATH`, `AIED_SITE`, `--clean-orphans`, base64 payload into `wp eval`), `scripts/lib/throwaway-pages.cjs`, `scripts/test-verify-kits.cjs` (pure node tests), `tests/Support/RuleChipAudit.php` (block walk, `fills()`, the band idea of `RecipeLibraryTest::cardBandOf`), `scripts/rule-chip-audit.php` (thin dev script), `tests/Support/Colour.php` (`rgba()`).

Decisions this plan makes where the spec leaves a choice (each is one constant or one sentence; Task 5 copies them into the spec Outcome):
- D1 Category names (7, fixed order): `button`, `rule`, `chip`, `framed`, `item`, `form`, `image`. Check names are `<category>.<check>`; the full list is `Scorer::CHECKS` (Task 2).
- D2 Multi-role blocks (button control/secondary, rule quiet/strong, item/item-rule, card/panel/grounds) are judged against the **best-matching role**: the candidate role with the most passing checks; a tie goes to the first role in `KitAdapters::APPLIED` order (grounds after the plain roles, `inverted` before `accent`). The chosen role is reported per block.
- D3 Bands of a freehand page are inferred by reverse lookup: a section/row/column/group background hex is mapped to its language role with the fixed priority `bg, surface, surface-glass, accent, text, overlay, inverse` (first role whose resolved value equals the colour), then role -> band `bg`->light, `surface`/`surface-glass`->surface, `accent`->accent, `text`->panel, `overlay`/`inverse`->dark; a background image, or a gradient with an `overlay` stop, is `dark`; any other colour or gradient is `unknown`. The nearest painting ancestor wins (as `RecipeLibraryTest::cardBandOf`). A band the role does not take (`surface` for a button) uses the adapter's own substitution (`surface` -> `light` for buttons, as `formPatch()` does); `unknown`, or a band the role cannot take, judges colours against the UNION of the role's bands and is reported as `band: "unknown"`.
- D4 Colours are role-set membership (Ruling 2): the observed colour, normalised to `[r,g,b,a]` with alpha rounded to 2 decimals (`Colour::rgba`; `#FFF` == `#ffffff` == `rgb(255,255,255)`), must equal one of the resolved colours the candidate role's patch writes at that path on that band. Lengths are compared numerically in px (`2px` == `2.0px`), tolerance 0; a missing value is `absent` and fails, except where a row of the table below says otherwise.
- D5 `dogfood-score` exits 0 after measuring (any score), 1 only with `--min <pct>` and an overall score below it, 2 on a usage error (unknown kit or language, unreadable file, a page `BlockParser` cannot parse: that is not a measurement).
- D6 `--language` default for the scorer: the one language whose `kit` is the given kit, else `editorial`; the output always names the language used. The corpus always passes it.
- D7 `dogfood-prepare` default out: `dist/dogfood/<kit>-<language>` for `freehand`, `dist/dogfood/<kit>-<language>-edit` for `edit` (so the two never overwrite each other). It writes `prepare.json` (kit, language, task, every file with its byte size) next to the guide files, for the cost section of the doc.
- D8 TASK.md templates live in `scripts/dogfood/TASK-freehand.md` and `scripts/dogfood/TASK-edit.md`, the prototype texts verbatim with `%KIT%`, `%LANGUAGE%`, `%OUT%` placeholders (no `{{...}}` syntax).
- D9 `dogfood-render --kit <id>` is a label only (page title and summary); it changes nothing else. Output dir `dist/dogfood/<name>/` with `<name>` = the page's parent directory name, plus `-<stem>` when the file is not `page.html` (`edit-glass-edge-page-edited`). Exit 1 on an invalid layout, overflow > 0 at either width, a console error, a PHP error text on the page, or an incomplete cleanup.
- D10 The fresh run of the final task uses kit `double-frame` under `corporate-trust` (not in the corpus; a 4px double exercises the double floor of `ruleWidth()`). Its score is recorded in the Outcome only, never pinned (Ruling 5).

## Controller rulings on the Spec issues (2026-10-22; where this section conflicts with the text below, THIS SECTION WINS)

All ten "Spec issues found" are accepted as the plan proposes them: (1) palette collisions: fixed role priority; (2) framed groups judged against the card radius, tags against the control radius; (3) a card with neither frame nor radius is unclassified; (4) images pass the media radius OR 0px, shaped images unclassified; (5) a rule on a framed block: only width and style judged; (6) edit runs scored on the whole page (a `--base` mode is a possible follow-up, not built); (7) `dogfood-render` takes NO `--kit` option: the plan's label (D9) is dropped, the script takes only the page path (and `--name`, `--clean-orphans`); (8) edit-base images stay example.com (documented); (9) exit 2 for usage errors and an unparseable page (the spec's "exit 0 always" is amended: it measures, errors are not measurements); (10) edit default output gets `-edit`. The spec statement about exit 0 and `dogfood-render [--kit ...]` is amended accordingly in the spec Outcome by Task 5.

## Global Constraints

Copied from CLAUDE.md's hard constraints and the spec, as they apply here:
- **The validator is deterministic and untouched; `wp-plugin/` is untouched.** `git diff main -- src wp-plugin jhmg-ai-editor-for-divi-5.zip` stays EMPTY at every task's end (dev tooling only; nothing is shipped, no version bump, no zip rebuild, no release, no push).
- **No AI/LLM calls, no API keys, no network in the repo** (Ruling 4). The scripts never call a model; the assistant step is run by a person or by Claude Code's `Agent` tool outside them. `dogfood-prepare` and `dogfood-score` are pure PHP: no site, no Docker, no HTTP. `dogfood-render` talks only to the local dev site (`devSiteOnly()`), through `docker compose exec wpcli` and a local Chromium.
- **Dev site only for the render script**: every page it creates is titled `aied dogfood <runToken> ...` and deleted by that exact run prefix; `--clean-orphans` removes leftovers only when no other run is going. ONE live run at a time (`pgrep -f "node scripts/(verify|dogfood)-"` empty first). Implementers never touch the dev site, Docker or a live run: Task 4's live run and the final task are the controller's.
- **No Divi schema from memory.** The scorer invents no attribute path or value: every expected value is read from the resolved output of the real adapters (`testExpectationsAreTheResolvedAdapterPatches`); the observed paths it reads are exactly the paths those patches write, plus the same paths' `top` side for a framed block (the guide's "a framed block keeps `styles.all` and gets only `styles.top`").
- **Never weaken a test**: no existing test, golden or bound is edited. `tests/golden/*` stay byte-identical (`KitGoldenFreezeTest` keeps passing untouched). If a conformance page does not score 100%, or a mutation flips more than its check, STOP and report the block, the check and both values: the controller decides (it can be a scorer bug or a real recipe marker that disagrees with its band, which is a finding, not something to excuse). No exemption list is added without a controller ruling.
- `tests/WpOrgComplianceTest.php` untouched and green; the Pro suite untouched (`make test-pro` not needed, nothing under `pro-addon/` changes).
- Determinism: the same inputs give byte-identical prepare output and byte-identical score JSON (keys in fixed order, no time, no absolute paths in the JSON except what the caller passed for `page`).
- Max 3 parallel workers. Commit only your own files by explicit path, with the commit trailer the controller gives. Check `git branch --show-current` = `feat/dogfood-and-html-styles` before every commit. Model hints: implementers sonnet; task reviewers sonnet, **opus for Task 2 (scorer core)**; final whole-branch review opus.

## Review Focus

Each line names the test that pins it and the task that owns it.

1. An expected value typed by hand instead of read from the resolved adapter patch: `testExpectationsAreTheResolvedAdapterPatches` (T2).
2. A check with no planted pass and no planted fail, or a check missing from `Scorer::CHECKS`: `testEveryCheckHasAPlantedPassAndFail` (T2).
3. A kit-built page that does not score full marks, or a mutation that flips another check too: `testConformancePagesScoreFullMarksInEveryCategory`, `testEachMutationFlipsExactlyItsCheck` (T2).
4. A colour judged as one exact hex instead of the band's role set (or an alpha written differently failing): `testColourIsRoleSetMembership` (T2).
5. A block the scorer cannot place counted as passed or failed: `testUnclassifiedBlocksAreNeitherPassedNorFailed`, `testClassificationOfPlantedBlocks` (T2).
6. Band inference guessing (a hex collision resolved differently on two runs, a photo band missed): `testBandsAreInferredByTheFixedRolePriority` (T2).
7. Non-deterministic score output or exit codes outside D5: `testScoringIsDeterministic`, `testTheScoreScriptExitCodes` (T2).
8. Prepare output that differs on repeat, misses a topic the kit page needs, or leaves a placeholder: `testPrepareIsByteIdenticalOnRepeat`, `testPrepareWritesTheGuideTopicsAKitPageNeeds`, `testTaskFilesHaveNoPlaceholderLeft` (T1).
9. The edit base drifting from the prototype: `testTheEditBaseIsTheSixRecipesUnderTheKit` (T1) and `testTheCorpusEditBaseIsPrepareOutput` (T3).
10. A scorer change that silently moves a corpus score: `testTheCorpusScoresArePinned` (T3).
11. A corpus page that is not a valid layout: `testEveryCorpusPageIsValid` (T3).
12. Harness code leaking into the shipped plugin: `testNothingOfTheHarnessIsShipped` (T3).
13. The render script deleting a page that is not its own, or a summary that passes with nothing measured: `scripts/test-dogfood-render.cjs` (`run title and prefix`, `summary fails when a width was not measured`) (T4); 0 orphan pages after the live run (final task).

---

## Parallelism and order

- **Wave 1 (parallel, 2 worktrees off the branch head):** Task 1 (prepare) and Task 4 (render script, pure parts). Task 4 consumes nothing from Task 1.
- **Wave 2:** Task 2 (scorer core) after Task 1 is merged: its conformance page is built with `Prepare::editBase()` / `Prepare::recipePage()`. Task 4 may still be running (2 workers).
- **Wave 3 (parallel, 2 worktrees off the Task 2 head):** Task 3 (corpus: consumes `Scorer` and `Prepare`) and Task 5 (docs, Makefile, CLAUDE.md: consumes the script interfaces of Tasks 1, 2, 4; it writes no score numbers).
- **Task 6 (controller, live, serial)** after everything is merged; then the final opus review.

---

### Task 1: `dogfood-prepare` (pure PHP)

**Files:**
- Create: `tests/Support/Dogfood/Prepare.php`, `scripts/dogfood-prepare.php`, `scripts/dogfood/TASK-freehand.md`, `scripts/dogfood/TASK-edit.md`, `tests/DogfoodPrepareTest.php`

**Interfaces (exact signatures):**
```php
namespace Divi5Validator\Tests\Support\Dogfood;
final class Prepare {
    /** The recipes of the edit base, in page order (the prototype's). */
    public const EDIT_RECIPES = [ 'hero-cta', 'card-grid-3', 'pricing-three-tier', 'faq-accordion', 'team-grid', 'contact-form' ];
    /** Guide file => StyleGuide topic (null = the default guide); '%KIT%' / '%LANGUAGE%' substituted. Order is the file order. */
    public const GUIDE_FILES = [
        '01-style-guide.md'     => null,
        '02-kit-rules.md'       => 'kit-rules',
        '03-style-kit.md'       => 'style-kit:%KIT%',
        '04-design-language.md' => 'design-language:%LANGUAGE%',
        '05-controls.md'        => 'controls',
        '06-forms.md'           => 'forms',
    ];
    public const TASKS = [ 'freehand', 'edit' ];
    /** filename => content, in write order: the 6 guide files, TASK.md, page.html (edit only), prepare.json. Pure; throws \InvalidArgumentException (message lists the valid ids) on an unknown kit, language or task. */
    public static function files( string $kit, string $language, string $task, string $outDir ): array;
    /** '<!-- wp:divi/placeholder -->' . implode("\n", SectionRecipes::recipe($n, $language, $kit) for each name) . "\n<!-- /wp:divi/placeholder -->"; throws \RuntimeException if a recipe resolves to null. */
    public static function recipePage( array $names, string $kit, string $language ): string;
    /** recipePage(EDIT_RECIPES, ...). */
    public static function editBase( string $kit, string $language ): string;
    /** The default out dir relative to the repo root (D7). */
    public static function defaultOut( string $kit, string $language, string $task ): string;
    /** Writes files() into $outDir (mkdir -p), only those names, never deletes anything; returns the written paths. */
    public static function write( array $files, string $outDir ): array;
}
```
Script: `php scripts/dogfood-prepare.php <kit> <language> [--out <dir>] [--task freehand|edit]`: requires `vendor/autoload.php`, `tests/bootstrap.php`, `wp-plugin/src/autoload.php` (the prototype's loading); resolves `--out` (default `defaultOut()`) to an absolute path (relative to the repo root); prints one line per file `name bytes`; exit 2 with the message and a usage line on an unknown kit, language, task or flag.

`prepare.json` = `RecipeTokens::encodeJson`-free plain `json_encode(..., JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"` of `{"kit","language","task","files":{name: bytes, ...},"guide_bytes": <sum of the 6 guide files>}` (no time, no out path).

TASK.md: the template of the task with `%KIT%`, `%LANGUAGE%` replaced and `%OUT%` replaced by the absolute out dir (no trailing slash). The templates are the prototype texts verbatim (`hairline-dashed/TASK.md` for freehand, `edit-glass-edge/TASK.md` for edit), with the kit, language and the out directory replaced by the placeholders; the freehand one keeps "Northwind Coffee Roasters" and the required section order; the edit one keeps the four numbered changes and "save the result as %OUT%/page-edited.html".

- [ ] **Step 1: Write the failing tests** in `tests/DogfoodPrepareTest.php`:
  - `testPrepareWritesTheGuideTopicsAKitPageNeeds`: `files('hairline-dashed','tech-mono','freehand','/x')` has exactly the keys `01-style-guide.md`..`06-forms.md`, `TASK.md`, `prepare.json` in that order; each guide file equals `StyleGuide::markdown(<topic>)` for its topic (the default guide for `01`).
  - `testTheEditTaskAddsTheBasePage`: the `edit` keys are the same plus `page.html` between `TASK.md` and `prepare.json`; `page.html === Prepare::editBase(kit, language)`.
  - `testTheEditBaseIsTheSixRecipesUnderTheKit`: `editBase('glass-edge','glass-dark')` starts with `<!-- wp:divi/placeholder -->`, ends with `"\n<!-- /wp:divi/placeholder -->"`, contains no `{{aied:` and no `"aied"`, passes `LayoutGate::check()`, and its sha256 equals the sha256 of the prototype base page measured while writing this plan: **the prototype `edit-glass-edge/page.html` is byte-identical to this formula (54,131 bytes)**; the implementer computes the sha256 of that prototype file once and pins the literal (the corpus copy in Task 3 then has the same hash).
  - `testPrepareIsByteIdenticalOnRepeat`: `files()` twice with the same arguments are identical; and the script run twice (`proc_open` of `php scripts/dogfood-prepare.php hairline-dashed tech-mono --out <tmp>` and again `--task edit --out <tmp2>`) leaves files with identical sha256 on the second run.
  - `testTaskFilesHaveNoPlaceholderLeft`: for both tasks TASK.md contains the kit id, the language id, `<out>/page.html` (freehand) or `<out>/page-edited.html` (edit), "Use ONLY these files", and no `%KIT%`, `%LANGUAGE%`, `%OUT%`.
  - `testUnknownIdsAreRefusedWithTheValidList`: an unknown kit, language and task each throw `\InvalidArgumentException` whose message names at least one valid id; the script exits 2 for each.
  - `testDefaultOutKeepsTheTwoTasksApart`: `defaultOut('dotted-pill','playful-pastel','freehand') === 'dist/dogfood/dotted-pill-playful-pastel'`, edit adds `-edit`.
  - `testPrepareJsonListsTheBytesOfEveryFile`: decoded `prepare.json` has the 4 keys, `files` sizes equal `strlen()` of each other file, `guide_bytes` = the sum of the six guide files.
- [ ] **Step 2:** `vendor/bin/phpunit --filter DogfoodPrepare`: expect FAIL. Implement `Prepare`, the two templates, the script. Expect PASS.
- [ ] **Step 3:** `make test` green; `git diff main -- src wp-plugin jhmg-ai-editor-for-divi-5.zip` empty; commit the five files.

---

### Task 2: The scorer core (pure PHP; reviewer: opus)

**Files:**
- Create: `tests/Support/Dogfood/Page.php` (parse + walk + bands), `tests/Support/Dogfood/Expected.php` (resolved adapter expectations), `tests/Support/Dogfood/Scorer.php` (classification + checks + report), `tests/Support/Dogfood/Mutate.php` (test-only helpers to mutate one block of a page), `scripts/dogfood-score.php`, `tests/DogfoodScorerTest.php`, `tests/DogfoodScorerMutationTest.php`

**Interfaces (exact signatures):**
```php
final class Page {
    /** Parses with \Divi5Validator\BlockParser; wraps in a placeholder root unless the trimmed page already starts with one. Throws \InvalidArgumentException when the parse is not ok. */
    public static function parse( string $markup ): self;
    /** Every block in document order: list<array{key:string,name:string,attrs:array,band:string,parentBand:string,hasContent:bool}>;
     *  key = "<name>#<index among blocks of that name>" (the root placeholder is not listed); band = the band the block sits on (D3);
     *  parentBand = the band of its nearest painting ancestor's parent (used by framed.top-rule; equals band when the block paints nothing);
     *  hasContent = a divi/heading or divi/button descendant. */
    public function blocks( array $language ): array;
    /** The band a background attribute paints (D3), or null when it paints nothing. */
    public static function bandOf( array $attrs, array $language ): ?string;
}
final class Expected {
    public function __construct( array $kit, array $language );
    /** The resolved attributes KitResolver + RecipeTokens give a single `<!-- wp:<block> {"aied":{"kit":$role,"on":$on[,"ground":$ground]}} /-->`, or null when the resolver leaves the marker (role, band or ground not taken). Memoised. */
    public function attrs( string $block, string $role, string $on, ?string $ground = null ): ?array;
    /** The bands the role takes (KitResolver's ROLE_SURFACES, read through attrs(): a band whose marker is left is not taken). */
    public function bands( string $block, string $role ): array;
}
final class Scorer {
    public const CATEGORIES = [ 'button', 'rule', 'chip', 'framed', 'item', 'form', 'image' ];
    public const CHECKS = [ /* the 33 names of the check table below, in its order */ ];
    public function __construct( string $kitId, string $languageId );   // \InvalidArgumentException on unknown ids
    /** The report array: see "Report" below. */
    public function score( string $markup ): array;
    public static function json( array $report ): string;   // JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES, "\n"-terminated
    public static function text( array $report ): string;
    /** D6 */
    public static function defaultLanguage( string $kitId ): string;
}
```
Script: `php scripts/dogfood-score.php <page.html> <kit> [--language <id>] [--json] [--min <pct>]` (D5, D6): prints `text()` or `json()`; `report.page` is the path exactly as given.

**Classification** (structural and conservative, first match wins; "frame side" = the effective width of a side: `styles.<side>.width` if present, else `styles.all.width`, absent = 0; "fill" = `RuleChipAudit::fills()`'s definition: a background colour that is not fully transparent, an enabled gradient or an image):

| Block | Class | Rule |
|---|---|---|
| `divi/button` | button | always |
| `divi/divider` | rule (divider) | always, unless `line.show` is `off` (ignored) |
| `divi/group`, `divi/column` | rule (top edge) | top side > 0, the other three 0, no fill |
| `divi/accordion`, `divi/toggle` | item | always |
| `divi/contact-form` | form | always |
| `divi/icon` | chip (icon) | a module background colour |
| `divi/group` | chip (tag) | no fill, at least one frame side > 0, no `divi/heading`, `divi/button` or `divi/image` descendant |
| `divi/group`, `divi/column` | framed | (any frame side > 0 OR any corner radius > 0) AND (a fill OR `hasContent`) |
| `divi/image` | image | its 4 corners equal and below 999px (absent radius = 0px, Divi's square default); otherwise unclassified "shaped image" |
| anything else of these names | unclassified | only when it carries a border, a radius or a background (reason string says which); an undecorated wrapper is ignored and only counted (`ignored`) |

**Check table** (33 checks; "role patch" = `Expected::attrs()` of the candidate role on the block's band; each check is checked only when its subject exists, so a missing frame fails ONE check):

| Check | Subject (observed path) | Passes when | Candidate roles |
|---|---|---|---|
| `button.radius` | `button.decoration.border.desktop.value.radius` 4 corners | each corner == the role patch's (control radius) | `control`, `control-secondary` |
| `button.edge` | the 4 effective sides of `button...value.styles` | each side == the role patch's (so ghost = 0, underline = bottom only) | same |
| `button.edge-style` | the style of the visible sides (only when one side > 0) | == the patch's style | same |
| `button.fill` | filled (bg alpha > 0) vs transparent (absent or alpha 0) at `button.decoration.background.desktop.value.color` | same kind as the patch | same |
| `button.fill-colour` | that colour (only when filled) | D4 membership in the patch's fill | same |
| `rule.width` | divider `divider.advanced.line.desktop.value.weight`; top edge `styles.top.width` | == the patch's | `rule`, `rule-strong` |
| `rule.style` | `...line...style` / `styles.top.style` | == the patch's (the probe fallback is already in it) | same |
| `rule.colour` | `...line...color` / `styles.top.color` (fallback `styles.all.color`) | D4 | same |
| `chip.radius` | tag and icon `module.decoration.border.desktop.value.radius` 4 corners | == the patch's (control radius) | `chip` |
| `chip.frame` | tag: 4 effective sides | == the patch's (full frame, 1px floor, 3px double) | `chip` |
| `chip.frame-style` | tag: the visible sides' style | == patch | `chip` |
| `chip.frame-colour` | tag: `styles.all.color` | D4 | `chip` |
| `chip.fill-colour` | icon: `module.decoration.background.desktop.value.color` | D4 | `chip` |
| `chip.glyph-colour` | icon: `icon.advanced.color.desktop.value` | D4 (absent fails) | `chip` |
| `framed.radius` | `module.decoration.border.desktop.value.radius` 4 corners | == patch (card radius) | `card`, `panel`, `card`+`inverted`, `card`+`accent` (grounds only where `attrs()` answers) |
| `framed.frame` | effective right, bottom, left sides, and top unless a top rule is present | == patch | same |
| `framed.frame-style` | visible sides' style (only when a side > 0) | == patch | same |
| `framed.frame-colour` | colour of the visible sides | D4 | same |
| `framed.fill-colour` | `module.decoration.background.desktop.value.color` (absent fails) | D4 | same |
| `framed.top-rule` | only when `styles.top` exists and its width != the effective `all` width | width ∈ the `rule`/`rule-strong` top-edge patches' widths of the same carrier on `parentBand` and style == their style (colour NOT judged: Spec issue 5) | n/a |
| `item.radius` | `module.decoration.border.desktop.value.radius` | == patch (`item`: card radius; `item-rule`: 0px) | `item`, `item-rule` |
| `item.frame` | 4 effective sides | == patch | same |
| `item.frame-style` | visible sides' style | == patch | same |
| `item.fill-colour` | `openToggle` and `closedToggle` `.decoration.background.desktop.value.color` (both; absent fails) | D4 | same |
| `form.field-radius` | `field.decoration.border.desktop.value.radius` | == patch | `form` |
| `form.field-frame` | field 4 effective sides | == patch | `form` |
| `form.field-frame-style` | field visible sides' style | == patch | `form` |
| `form.field-fill-colour` | `field.decoration.background.desktop.value.color` | D4 | `form` |
| `form.button-radius` | `button.decoration.border.desktop.value.radius` | == patch | `form` |
| `form.button-edge` | submit 4 effective sides | == patch | `form` |
| `form.button-edge-style` | submit visible sides' style | == patch | `form` |
| `form.button-fill-colour` | `button.decoration.background.desktop.value.color` (only when filled) | D4 | `form` |
| `image.radius` | `image.decoration.border.desktop.value.radius` 4 corners | all == the kit's media radius (`StyleKits::valueToken($kit,'radius-media')`) OR all 0px (a flush image, as the library ships: Spec issue 4) | n/a |

**Report** (`score()`): `{"page":..., "kit":..., "language":..., "categories": {<cat>: {"checked":n,"passed":n,"score":<float 1 decimal>|"n/a"} for the 7 in order}, "overall": {"checked","passed","score"}, "failures": [{"check","block","role","band","expected","observed"}...] (document order, then check order), "unclassified": [{"block","reason"}...], "ignored": n}`. `score` = `round(100 * passed / checked, 1)`, `"n/a"` when `checked` is 0. `text()` prints one header line `dogfood score <page> kit=<kit> language=<language>`, one line per category `<cat> <passed>/<checked> <score>%` (or `n/a`), the overall line, then `FAIL <check> <block> (<role> on <band>): expected <e>, observed <o>` and `UNCLASSIFIED <block>: <reason>` lines.

**Conformance page** (used by the mutation test, built in the test from Task 1): `Prepare::recipePage(array_merge(Prepare::EDIT_RECIPES, ['portfolio-featured-grid', 'features-carousel-strip', 'list-icon-dividers']), $kit, $language)`: the edit base plus tags, icon chips and dividers, so all 7 categories are present (checked while writing this plan under glass-edge/glass-dark: buttons, top-rule groups on surface and on the accent ground, the plain and the accent-ground pricing cards, the accordion, the dark-band form, flush and media-radius images, 5 tags, 6 icon chips, 5 dividers; the hero's fill-only column and the [Most popular] badge come out unclassified, as they should).

- [ ] **Step 1: Write the failing tests** in `tests/DogfoodScorerTest.php` (planted blocks are tiny hand-written block comments with RESOLVED values; the expected values in a planted pass are taken from `Expected::attrs()`, never typed):
  - `testExpectationsAreTheResolvedAdapterPatches`: for 3 kits (`glass-edge`, `underline-link`, `double-frame`) x every (block, role) of `KitAdapters::APPLIED` x every band `bands()` returns, `attrs()` equals `json_decode` of the attributes in `RecipeTokens::resolve(KitResolver::resolve(<marker block>, $kit), $language)` minus nothing (same array), and contains no `{{aied:` string; a band the resolver refuses gives null.
  - `testEveryCheckHasAPlantedPassAndFail` (`#[DataProvider('plantedChecks')]`, one row per check name with a passing and a failing block): the passing page reports the check checked and passed, the failing page reports exactly that check failed; plus `testThePlantedProviderCoversEveryCheck`: the provider's names == `Scorer::CHECKS` (33, same order).
  - `testClassificationOfPlantedBlocks`: a top-only unfilled group = rule; the same with a fill = not a rule; a full-frame unfilled group with only a text = tag; with a heading inside = framed; a filled group with a radius, no frame and only text = unclassified; a filled column with no frame, no radius and a heading = unclassified; an undecorated group = ignored; an icon with no background but a radius = unclassified; an image with 999px corners or mixed corners = unclassified "shaped image"; a `show:off` divider = ignored.
  - `testColourIsRoleSetMembership`: under `glass-dark`, a quiet rule on light passes with `#2a3558`, `#2A3558`, `rgb(42,53,88)`; a strong-width rule picks `rule-strong` and passes with the `text` colour; a non-palette `#123456` fails `rule.colour`; a 40% rule on a dark band passes with `rgba(244,246,253,0.4)` and with `rgba(244, 246, 253, .40)`.
  - `testBandsAreInferredByTheFixedRolePriority`: under `playful-pastel` a `#ffffff` section is `surface` (surface before inverse), under `glass-dark` a `#0b1020` section is `light` (bg before accent_text); a section with a background image is `dark`; a group filled with the `text` colour makes its children `panel`; a non-palette section colour gives `unknown` and the colour checks of a rule inside use the union of the role's bands (a quiet rule in any band colour passes, a non-palette one fails); the nearest painting ancestor wins over the section.
  - `testBestRoleTieGoesToTheFirstRole`: a divider whose width matches `rule` and colour matches `rule-strong` reports role `rule` and fails `rule.colour` only.
  - `testUnclassifiedBlocksAreNeitherPassedNorFailed`: adding an unclassified block to a page leaves every category's checked/passed unchanged and adds one `unclassified` entry.
  - `testTheReportShapeAndNa`: a page with only buttons gives `"n/a"` for the 6 other categories, keys in the documented order, scores with one decimal.
  - `testScoringIsDeterministic`: `json(score(...))` twice on a corpus-sized page is byte-identical.
  - `testTheScoreScriptExitCodes` (`proc_open`): exit 0 and text output on a page; `--json` gives valid JSON equal to `Scorer::json`; `--min 101` exits 1; `--min 0` exits 0; an unknown kit, unknown language, missing file and an unparseable page each exit 2.
  - `testDefaultLanguage`: `defaultLanguage('glass-edge') === 'glass-dark'`, `defaultLanguage('dotted-pill') === 'editorial'`.
- [ ] **Step 2: Write the failing mutation tests** in `tests/DogfoodScorerMutationTest.php` (`Mutate` helpers: `set(string $page, string $blockKey, string $dotPath, mixed $value): string` and `remove(string $page, string $blockKey, string $dotPath): string`, re-encoding ONLY that block's attribute JSON with `RecipeTokens::encodeJson`, so every other byte stays):
  - `testConformancePagesScoreFullMarksInEveryCategory`: for EVERY kit (`StyleKits::ids()`, 40) under `editorial` and `glass-dark` (80 pages): every one of the 7 categories has `checked > 0` and `passed == checked`, `failures` is empty. If any cell fails: STOP and report (Global Constraints), never exempt.
  - `testTheConformanceUnclassifiedListIsPinned`: under glass-edge/glass-dark the `unclassified` block keys equal a literal list the implementer measures and pins (expected to contain the hero's fill-only `divi/column` and the pricing [Most popular] `divi/group`; the reviewer reads the list for sanity).
  - `testEachMutationFlipsExactlyItsCheck` (`#[DataProvider('mutations')]`, each row = kit, language, the first block of the kind, the mutation, the check expected to flip), run on `glass-edge`/`glass-dark`, `underline-link`/`editorial` and `double-frame`/`editorial` where the subject exists: after the mutation, `failures` gains exactly one entry whose `check` is the expected one and whose `block` is the mutated block; no other check goes from pass to fail (checked counts may only drop for the subject's dependent checks, e.g. a removed frame stops `framed.frame-style` being checked). Mutations (all values that no role can produce):
    - a button corner radius -> control radius + 7px: `button.radius`
    - a button's visible-side style -> another style of `StyleKits::BORDER_STYLES`: `button.edge-style`
    - a top-rule group's `styles.top.width` -> `7px`: `rule.width`
    - a divider's `weight` -> `7px`: `rule.width`; its `style` -> another style: `rule.style`; its `color` -> `#123456`: `rule.colour`
    - a tag's radius -> + 7px: `chip.radius`; an icon chip's radius -> + 7px: `chip.radius`
    - a framed card's `module.decoration.border.desktop.value.styles` removed (glass-edge only: card radius 20px keeps it classified): `framed.frame`; its radius -> + 7px: `framed.radius`; its style -> another style: `framed.frame-style`
    - the accordion's radius -> + 7px: `item.radius`; its style -> another style: `item.frame-style`
    - the form's field radius -> + 7px: `form.field-radius`; the submit's style -> another style: `form.button-edge-style`
    - a media-radius image's radius -> 7px corners: `image.radius`
- [ ] **Step 3:** `vendor/bin/phpunit --filter 'DogfoodScorer'`: expect FAIL; implement `Page`, `Expected`, `Scorer`, `Mutate`, the script; expect PASS (the 80-page test must stay under 30 s on this machine; memoise `Expected::attrs()` per kit/language).
- [ ] **Step 4:** `make test` green (count = previous + the new tests); diff check as in Global Constraints; commit.

---

### Task 3: The regression corpus (pure; needs Tasks 1 and 2)

**Files:**
- Create: `fixtures/dogfood/<name>/page.html` for `hairline-dashed`, `heavy-block`, `dotted-pill`, `underlined-label`, `soft-rounded`, `gilt-hairline`; `fixtures/dogfood/edit-glass-edge/page.html` (the base) and `fixtures/dogfood/edit-glass-edge/page-edited.html` (copied byte for byte from the prototype directory; nothing else: no screenshots, no guide files, no TASK.md); `fixtures/dogfood/corpus.json`; `tests/DogfoodCorpusTest.php`

**`corpus.json`** (a list, in this order, `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`):
```json
[{"name":"hairline-dashed","kit":"hairline-dashed","language":"tech-mono","task":"freehand","page":"hairline-dashed/page.html",
  "scores":{"button":[checked,passed],"rule":[..],"chip":[..],"framed":[..],"item":[..],"form":[..],"image":[..],"overall":[..]},
  "unclassified":n}, ...]
```
Entries: `hairline-dashed`/tech-mono, `heavy-block`/bold-vibrant, `dotted-pill`/playful-pastel, `underlined-label`/glass-dark, `soft-rounded`/organic-warm, `gilt-hairline`/luxe (all `freehand`); `edit-glass-edge-base`/glass-edge/glass-dark `task:"edit-base"` page `edit-glass-edge/page.html`; `edit-glass-edge`/glass-edge/glass-dark `task:"edit"` page `edit-glass-edge/page-edited.html`. A category with nothing checked is `[0,0]`.

The pinned numbers are **measured by running the scorer**, never invented: the implementer runs `php scripts/dogfood-score.php fixtures/dogfood/<page> <kit> --language <language> --json` for each entry, copies `checked`/`passed` and the `unclassified` count into `corpus.json`, and pastes the 8 text outputs into the task report. The controller reads them for sanity before merging (the `edit-base` entry must be 100% in every category it has: it is kit-built; the freehand pages are expected below 100% in places, e.g. a 0px-control kit's tags, which is what the corpus documents).

- [ ] **Step 1: Copy the pages** (`cp`, then `shasum -a 256` of source and copy equal; the base page's hash equals the literal pinned in Task 1).
- [ ] **Step 2: Write the failing tests** in `tests/DogfoodCorpusTest.php`:
  - `testTheCorpusScoresArePinned`: for each entry, `Scorer(kit, language)->score(page)` gives exactly the pinned `[checked, passed]` per category and overall, and the pinned `unclassified` count.
  - `testTheCorpusEditBaseIsPrepareOutput`: `edit-glass-edge/page.html === Prepare::editBase('glass-edge','glass-dark')`.
  - `testTheEditBaseScoresFullMarks`: the `edit-base` entry has `passed == checked` in every category with `checked > 0`.
  - `testEveryCorpusPageIsValid`: `LayoutGate::check($page)->isValid()` for all 8 pages (they were validated live in the guide rounds before the planned 4.14.0 release; a failure is a STOP-and-report, not a skip).
  - `testTheCorpusIsExactlyTheListedFiles`: the files under `fixtures/dogfood/` are exactly `corpus.json` and the 8 listed pages (so screenshots or guide dumps do not creep in); total size < 400 KB.
  - `testNothingOfTheHarnessIsShipped`: no file under `wp-plugin/` contains `dogfood` or `Dogfood` (case-sensitive grep over the tree), and `wp-plugin/src/autoload.php` names no class of `tests/Support/Dogfood/`.
- [ ] **Step 3:** run the scorer, fill `corpus.json`, `vendor/bin/phpunit --filter DogfoodCorpus` PASS, `make test` green, diff check, commit (the 8 pages, `corpus.json`, the test).

---

### Task 4: `dogfood-render` (pure parts by the implementer; the live run is the controller's)

**Files:**
- Create: `scripts/dogfood-render.cjs`, `scripts/lib/dogfood-render.cjs`, `scripts/test-dogfood-render.cjs`

**Interfaces** (`scripts/lib/dogfood-render.cjs`, CommonJS, pure, no `require` of Playwright or child_process):
```js
const TITLE_PREFIX = 'aied dogfood ';
/** argv (process.argv.slice(2)) -> { page, kit|null, out|null, cleanOrphans:false } | { cleanOrphans:true } | { error: USAGE }.
 *  Flags: --kit <id> (lower-case [a-z0-9-]+), --out <dir>, --clean-orphans (alone only). Exactly one page path unless --clean-orphans. */
function parseArgs(argv) {}
/** D9: 'edit-glass-edge/page-edited.html' -> 'edit-glass-edge-page-edited'; '<dir>/page.html' -> '<dir>'. Only [a-z0-9-] kept, else '-'. */
function outName(pagePath) {}
/** `${TITLE_PREFIX}${token} ${kit || '-'} ${name}`. */
function runTitle(token, kit, name) {}
/** results = { valid, verdict, widths: { 1280: {overflow, errors, phpError}, 400: {...} } | partial, cleanupOk } -> { ok, lines }.
 *  ok only when valid, BOTH widths measured, overflow 0 at both, no errors, no PHP error text, cleanupOk. */
function summarize(results) {}
module.exports = { TITLE_PREFIX, USAGE, parseArgs, outName, runTitle, summarize };
```
`scripts/dogfood-render.cjs` (mirrors the prototype `render.cjs`, structured like `verify-kits.cjs`): `ROOT`, `PW = process.env.PLAYWRIGHT_PATH || ../layoutlab/node_modules/playwright`, `SITE = process.env.AIED_SITE || http://localhost:8181`, `devSiteOnly(SITE)` first; `makeWp(ROOT)`; `throwawayPages(wp, TITLE_PREFIX, 'dogfood')`; `--clean-orphans` -> `removeOrphans()` and exit; `cleanupOnSignals(run, sweep, runPrefix)`. One `wp eval` with a base64 JSON payload `{c, t}` (as the prototype): `LayoutGate::check($c)`; invalid -> print the violation codes and exit 1 WITHOUT saving; valid -> `wp_insert_post(wp_slash([... 'post_status'=>'draft', 'meta_input'=>['_et_pb_use_divi_5'=>'on','_et_pb_use_builder'=>'on']]))`, push the id to `created`. The content is saved as given (no wrapping, no resolving): that is what an assistant would save. A page that still contains `{{aied:` is reported (`WARNING tokens left`), not refused. Then for 1280 and 400 (height 900): `loginSession`, `watchConsole`, `goto ?page_id=<id>&preview=true`, the prototype's scroll-to-load loop and 800 ms settle, a full-page screenshot `dist/dogfood/<name>/shot-<w>.png` (or `--out`), `scrollWidth - innerWidth`, the body text against `PHP_ERROR` of `scripts/lib/recipe-checks.cjs`; `finally`: `dropPage(id)`, `sweep()`, `endSession()`, browser close. Writes `render.json` (`{page, kit, title, valid, widths, cleanupOk}`) next to the shots, prints `summarize().lines`, exits 0/1 per D9.

- [ ] **Step 1: Write the failing pure tests** `scripts/test-dogfood-render.cjs` (node `assert`, the `t()` pattern of `test-verify-kits.cjs`, prints `N pure dogfood-render tests passed`):
  - `parse args`: a page alone; `--kit dotted-pill`; `--out x`; `--clean-orphans` alone; refused: no page, two pages, `--clean-orphans` with a page, `--kit` without a value, `--kit Bad_Id`, an unknown flag.
  - `out name`: `fixtures/dogfood/hairline-dashed/page.html` -> `hairline-dashed`; `.../edit-glass-edge/page-edited.html` -> `edit-glass-edge-page-edited`; odd characters become `-`.
  - `run title and prefix`: the title starts with `TITLE_PREFIX + token + ' '` (the run prefix `throwawayPages` sweeps by), `kit` null gives `-`, and a title of another gate (`aied kit ...`, `aied recipe ...`) never starts with `TITLE_PREFIX`.
  - `summary`: ok on a clean result; fails (and names why) on invalid, overflow 1 at 400, a console error, a PHP error, `cleanupOk:false`, and `summary fails when a width was not measured` (only 1280 present).
- [ ] **Step 2:** `node scripts/test-dogfood-render.cjs`: expect FAIL; implement the lib and the script; expect PASS. `node --check scripts/dogfood-render.cjs`. Do NOT run the script itself (it needs the dev site).
- [ ] **Step 3:** commit the three files. The live run is Task 6.

---

### Task 5: Doc, Makefile, CLAUDE.md, spec Outcome skeleton (needs the interfaces of Tasks 1, 2, 4; may run beside Task 3)

**Files:**
- Create: `docs/dogfood.md`
- Modify: `Makefile` (three targets and `.PHONY`), `CLAUDE.md` (one paragraph + three table rows), `docs/superpowers/specs/2026-10-22-dogfood-harness-design.md` (append an `## Outcome` section)

- [ ] **Step 1: `docs/dogfood.md`**, sections: *What it is* (3 scripts, the corpus, never shipped, no model calls); *When* (before releasing a guide or adapter change; not part of `make test`, not a release gate); *The routine* (1 `make dogfood-prepare KIT=<kit> LANGUAGE=<language> [TASK=edit]`; 2 start a FRESH assistant with no repo access: in Claude Code the `Agent` tool with exactly the prompt below, or paste TASK.md into any assistant together with the 6 guide files; 3 `make dogfood-score PAGE=<out>/page.html KIT=<kit> LANGUAGE=<language>`; 4 `make dogfood-render PAGE=<out>/page.html KIT=<kit>` (dev site, one run at a time); 5 read the assistant's "unclear / missing / contradictory" paragraph); *The exact prompt*: `Read <absolute out dir>/TASK.md and do exactly what it says. Use only the files in that directory; do not read any other file, search the web or look at a repository.`; *Reading the score* (categories, best role, bands and `unknown`, role-set colours, `unclassified` is neither good nor bad, `n/a`, what the scorer does NOT see: pixels (Ruling 1), copy, layout quality, whether an edit kept the rest byte-for-byte: diff `page.html` and `page-edited.html`); *What to do with a gap* (guide wording in the generator that owns it, a probe when the gap is a shape question, a pin or a test, then re-run the routine; never edit the corpus numbers by hand: a scorer change re-measures and re-pins them in the same commit with a reason); *Costs* (from `prepare.json`: the measured `guide_bytes` for one freehand prepare, plus the page size of the corpus pages (32-61 KB); state tokens only as "about bytes / 4, an estimate", and that one run is one assistant session); *Known limits* (the Spec issues of the plan, one line each); *Corpus* (what each entry is, how scores are pinned).
- [ ] **Step 2: `Makefile`**: add `dogfood-prepare dogfood-score dogfood-render` to `.PHONY`; three documented targets in the existing style (a `# name — description` comment block, then the recipe):
  - `dogfood-prepare`: `@php scripts/dogfood-prepare.php $(KIT) $(LANGUAGE) $(if $(TASK),--task $(TASK)) $(if $(OUT),--out $(OUT))`
  - `dogfood-score`: `@php scripts/dogfood-score.php $(PAGE) $(KIT) $(if $(LANGUAGE),--language $(LANGUAGE)) $(ARGS)`
  - `dogfood-render`: `@node scripts/test-dogfood-render.cjs` then `@node scripts/dogfood-render.cjs $(PAGE) $(if $(KIT),--kit $(KIT)) $(ARGS)` (comment: Docker dev site only, one run at a time, pages titled `aied dogfood <runToken> ...`, `ARGS=--clean-orphans` when no run is going)
  `make -n dogfood-prepare KIT=a LANGUAGE=b` prints the expected command (paste it in the report).
- [ ] **Step 3: `CLAUDE.md`**: one compact paragraph after the image-radius/rules-chips paragraphs: "Dogfood harness (dev-only, branch `feat/dogfood-and-html-styles`; spec ..., plan ...): `dogfood-prepare` writes the served guide (default guide + kit-rules, style-kit, design-language, controls, forms) and a TASK.md (freehand, or edit over a base page from 6 recipes) for a fresh assistant; `dogfood-score` statically scores a page against the real adapters (expected values = resolved marker patches; 7 categories, 33 checks, best-matching role, bands by reverse palette lookup, colours by role-set membership; unclassified blocks listed, never counted); `dogfood-render` validates through LayoutGate, renders one `aied dogfood ` draft at 1280/400 on the dev site and deletes it; corpus `fixtures/dogfood/` (6 freehand pages, the edit base and the edit, scores pinned by `DogfoodCorpusTest`); no model calls; not part of `make test` beyond its pure tests; routine in `docs/dogfood.md`." Plus three rows in the Entry points table for the three targets (one line each, same voice as the neighbours).
- [ ] **Step 4: spec Outcome skeleton**: append `## Outcome` with the decisions D1-D10 (one line each), the "Spec issues found" of this plan (one line each, with the controller's ruling when given), and empty placeholders `Corpus scores (measured in Task 3):`, `Live render (Task 6):`, `Fresh run (Task 6):` for the controller to fill.
- [ ] **Step 5:** `make test` green (docs only, but the WpOrg/readme tests must stay green: nothing in `wp-plugin/` changed); commit the four files.

---

### Task 6: Live runs and the fresh assistant run (controller; live, serial; not an implementer task)

- [ ] **Step 1: Preconditions.** `git branch --show-current` = `feat/dogfood-and-html-styles`; `pgrep -f "node scripts/(verify|dogfood)-"` empty; `make up` healthy; worktree runs need `PLAYWRIGHT_PATH=/Users/Lucas/Documents/JHMG-Local/layoutlab/node_modules/playwright` and `COMPOSE_PROJECT_NAME=divi5deterministicvalidator`.
- [ ] **Step 2: Render two corpus pages**, one at a time: `make dogfood-render PAGE=fixtures/dogfood/hairline-dashed/page.html KIT=hairline-dashed`, then `make dogfood-render PAGE=fixtures/dogfood/edit-glass-edge/page-edited.html KIT=glass-edge`. Expect valid, both widths measured, overflow 0, no console or PHP error, exit 0 (an overflow or error is recorded as a finding, not fixed in the corpus). Then `wp post list --post_type=page --post_status=any --fields=ID,post_title --format=csv | grep -c 'aied dogfood '` = **0** (0 orphans). Look at the four screenshots in `dist/dogfood/`.
- [ ] **Step 3: One fresh assistant run end to end** with a kit not in the corpus (D10): `make dogfood-prepare KIT=double-frame LANGUAGE=corporate-trust`; start ONE fresh `Agent` (general-purpose, no repo context) with exactly the prompt of `docs/dogfood.md`; when it returns: `make dogfood-score PAGE=dist/dogfood/double-frame-corporate-trust/page.html KIT=double-frame LANGUAGE=corporate-trust` (and `ARGS=--json` saved next to it), `make dogfood-render PAGE=... KIT=double-frame`, 0 orphans again.
- [ ] **Step 4: Record in the spec Outcome**: the corpus scores (from `corpus.json`), the two live renders (overflow, errors, orphans), the fresh run's per-category score, its failures and unclassified blocks, the assistant's own gap paragraph, `guide_bytes` and the page size, and whether `docs/dogfood.md` alone was enough. Do NOT add the fresh page to the corpus (Ruling 5). Then the final whole-branch review (opus), `make test` green, diff check empty.

---

## Spec issues found

1. **Palette hex collisions make reverse lookup ambiguous.** Measured in `design-languages.json`: editorial `#ffffff` = surface = accent_text; glass-dark `#0b1020` = bg = accent_text; playful-pastel `#ffffff` = surface = inverse and `#2b2457` = text = accent_text; corporate-trust `#ffffff` = bg = accent_text; soft-saas `#ffffff` = surface = accent_text; bold-vibrant `#ffffff` = surface = accent_text = inverse and `#0c0c14` = text = border; brutalist-flat `#ffffff` = surface = inverse and `#000000` = text = accent_text = border. The spec's "role-set membership" works for colours, but BANDS need one answer, so the plan fixes the priority of D3 (a playful-pastel `#ffffff` section is a surface band, never dark; a bold-vibrant `border`-filled grid cell reads as a `panel`). Membership also cannot tell a quiet `border` rule from a strong `text` rule in bold-vibrant and brutalist-flat (same hex): the width decides (D2).
2. **"Framed groups (card or control radius)"**: no adapter writes a control radius on a framed group (the tag is the only group with the control radius, and it is a chip). The plan judges framed groups against the CARD radius and tags against the control radius.
3. **A card written with neither a frame nor a radius cannot be placed structurally**: the shipped library has fill-only surfaces that no kit restyles (hero-cta's column, card-migration-report's "deliberately not listed" columns), so treating every filled block with a heading as a card would fail kit-built pages. Such a block is listed as unclassified. Consequence: the "missing frame" mutation is defined on a card that keeps its radius (glass-edge, card radius 20px), and a freehand card that drops both its frame and its radius is not caught (it shows up in `unclassified`).
4. **Images**: the library keeps flush images (0px) and shaped images (circles, arches) literal under every kit, so a kit-built page has images at 0px under a kit with a media radius. `image.radius` therefore passes the media radius OR 0px, and shaped images are unclassified; a page that leaves every image square passes. A stricter check would fail kit-built pages.
5. **A rule on top of a framed block** (`framed.top-rule`): the band its colour should be judged on (the card's own fill or the outer band) is not defined by any adapter (no marker combines a frame and a top rule), so only its width and style are judged, against the rule patches on the outer band.
6. **Edit runs**: the scorer judges the whole edited page against the kit, not the four requested changes, and "keep everything else byte-for-byte" is not measured (the doc says to diff the two files). A `--base` mode that scores only changed blocks is a possible follow-up.
7. **`dogfood-render --kit`** has no defined effect in the spec; the plan makes it a label (D9).
8. **The edit base's image URLs point at `https://example.com/...`** (the test bootstrap's `plugins_url` shim; the prototype base page has the same URLs, which is why it is byte-identical). On the dev site those images do not load (third-party origin: `watchConsole` ignores it, so it is not a console error). Kept for determinism and byte identity with the corpus; the doc says so.
9. **Exit codes**: the spec says "exit 0 always (it measures)"; the plan adds exit 2 for usage errors and an unparseable page, which are not measurements (D5).
10. **Default `--out` for `--task edit`** would collide with a freehand run of the same kit and language; the plan appends `-edit` (D7).
