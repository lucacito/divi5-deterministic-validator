# Studio compiler (plan 1 of 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A pure-PHP, deterministic compiler: a small JSON **brief** (sections, words, pictures, one design language, one style kit) in, valid Divi 5 markup plus an honest report out, built by filling the slots of the 75 existing recipes.

**Architecture:** Slots are derived from recipe markup at runtime by `StudioSlots` (no new data file; a golden pins the table). `StudioBrief` validates a brief strictly, `StudioLinks` holds the link policy, `StudioCompiler` fills each section's raw recipe markup, resolves it through the existing serve pipeline (one small behaviour-preserving refactor of `SectionRecipes`), joins the sections and runs `LayoutGate`. No UI, no tool, no route, no storage here: plans 2 (Studio screen, brief storage, preview) and 3 (MCP/REST tool and guide) build on this.

**Tech Stack:** PHP 8, PHPUnit (`php -d memory_limit=-1 vendor/bin/phpunit`), the plugin's existing classes in `wp-plugin/src/`.

**Spec:** `docs/superpowers/specs/2026-11-09-studio-design.md` (read it first, including "Rules settled after review").

## Global Constraints

- The validator (`src/`, `wp-plugin/validator/`) is not touched. No AI, no network, no `wp_remote_*` in anything new; every new file in `wp-plugin/src/` starts with `declare(strict_types=1);` and the `ABSPATH` guard like its neighbours.
- The compiler only moves text, links and picture tokens inside recipe markup that is already grounded. It never writes new structure or attribute shapes.
- Every compiled result goes through `LayoutGate::check()`; `tests/WpOrgComplianceTest.php` stays green and is never weakened (no meta-write functions, no new hosts).
- Same brief, same output, byte for byte. Text a person or assistant supplies is data: escaped, never interpreted.
- Unsupplied slots keep the recipe's sample copy and are reported as `unfilled` (placeholders never block). Empty or whitespace-only values count as not supplied.
- No release, no version bump, no zip rebuild, no readme edit. Commit steps run only if the owner has allowed commits in the session. Work on a branch `feat/studio-compiler` from `main`.
- Existing goldens (`tests/golden/**`, tools/list, OpenAPI) must stay unchanged.

## Review Focus

1. A supplied text containing `{{aied:color:bg}}`, `"aied":{"kit":"control"}`, `$variable(` or `<script>` must come out as inert visible text (the serve pipeline runs after the fill and would otherwise resolve it). Pinned in Task 4.
2. A supplied value of `""` or whitespace keeps the sample copy and is listed unfilled. Task 4.
3. Multi-byte, RTL, emoji and invalid UTF-8 values: length is counted in characters, invalid UTF-8 is refused, valid text round-trips. Task 5.
4. The same loop recipe used in two sections must surface `LoopAdvisory`'s `duplicate_loop_id` in the report, not be silently built. Task 6.
5. A brief that is not an object, is empty, has more than 20 sections, or has a section with no `recipe` is refused with a stable code, never a PHP warning. Task 5.

---

### Task 1: Expose the serve pipeline for raw markup

**Files:**
- Modify: `wp-plugin/src/SectionRecipes.php:409-441` (`recipe()`)
- Test: `tests/StudioPipelineTest.php`

**Interfaces:**
- Produces: `SectionRecipes::resolveRaw(string $rawMarkup, ?string $language = null, ?string $kit = null): ?string`: exactly what `recipe()` does after it finds the recipe (language and kit lookup and validation, `ImageTokens::resolve`, `resolveMarkup`), taking the markup instead of a name. `recipe()` becomes a lookup plus a call to it.

- [ ] **Step 1: Branch.** `git checkout -b feat/studio-compiler`.
- [ ] **Step 2: Failing test** `testResolveRawEqualsRecipeForEveryRecipeAndEveryLanguage`: for every `SectionRecipes::names()` and every `DesignLanguages::ids()`, `SectionRecipes::recipe($n, $l) === SectionRecipes::resolveRaw(SectionRecipes::rawMarkup($n), $l)`. Also `testResolveRawNullForUnknownLanguageOrKit` (unknown ids return null, as `recipe()` does today). Use the `require_once` block of `tests/TypeMigrationTest.php` as the template.
- [ ] **Step 3: Run** `php -d memory_limit=-1 vendor/bin/phpunit --filter StudioPipelineTest`. Expected: FAIL, method not defined.
- [ ] **Step 4: Implement** `resolveRaw` by moving the body of `recipe()` after the loop into it; `recipe()` finds the row and returns `self::resolveRaw($r['markup'], $language, $kit)`. No behaviour change.
- [ ] **Step 5: Run** the new test, then `php -d memory_limit=-1 vendor/bin/phpunit` (whole suite). Expected: PASS, nothing else changed.
- [ ] **Step 6: Commit** `git add -A && git commit -m "Studio: SectionRecipes::resolveRaw (behaviour-preserving)"`.

### Task 2: Link policy

**Files:**
- Create: `wp-plugin/src/StudioLinks.php`
- Test: `tests/StudioLinksTest.php`

**Interfaces:**
- Produces: `StudioLinks::problem(string $url): ?string` returns null when allowed, else one of `LINK_TOO_LONG` (over 2000 characters), `LINK_MALFORMED` (control characters, spaces, userinfo `user@` in an https URL, no host, `{{`, `$variable(`), `LINK_SCHEME_NOT_ALLOWED`. Allowed forms only: `https://host/...`, `mailto:` + one address, `tel:` + digits and `+ - ( ) .` and spaces, `#anchor` (`[A-Za-z][A-Za-z0-9_-]*`), and a site path starting with a single `/` (not `//`). `StudioLinks::sitePath(string $url, string $homeUrl): ?string` returns the path (with query dropped) when the URL is a site path or an https URL on `$homeUrl`'s host, else null (used for "page no longer exists" warnings).

- [ ] **Step 1: Failing tests** (data provider): allowed: `https://example.com/a?b=1`, `mailto:a@b.co`, `tel:+1 (555) 010-1234`, `#pricing`, `/contact/`. Refused with the named code: `javascript:alert(1)` and `data:text/html,x` and `http://example.com` (SCHEME), `//evil.com`, `https://user@example.com`, `https://`, `/a b`, `{{aied:color:bg}}`, `$variable({})$` (MALFORMED), 2001 `a`s after `/` (TOO_LONG). `sitePath('https://example.com/x/?y=1','https://example.com')` is `/x/`; `sitePath('https://other.com/x','https://example.com')` is null.
- [ ] **Step 2: Run** `--filter StudioLinksTest`. Expected: FAIL.
- [ ] **Step 3: Implement** `StudioLinks` as above (pure; `parse_url` plus anchored regexes).
- [ ] **Step 4: Run** the test. Expected: PASS. **Step 5: Commit.**

### Task 3: Slot extraction

**Files:**
- Create: `wp-plugin/src/StudioSlots.php`, `tests/golden/studio-slots.json`
- Modify: `wp-plugin/src/autoload.php` (add `require_once __DIR__ . '/StudioSlots.php';`, and likewise for the classes of Tasks 2, 5, 6)
- Test: `tests/StudioSlotsTest.php`

**Interfaces:**
- Consumes: `BlockAttrs::BLOCK`, `BlockAttrs::decode()`, `SectionRecipes::rawMarkup()`, `SectionRecipes::names()`.
- Produces: `StudioSlots::extract(string $rawMarkup): list<array{id:string,block:string,index:int,kind:'text'|'link'|'picture'|'alt',role:string,sample:string,rich:bool}>`. `index` is the order among ALL blocks of that name in the recipe (the numbering the other audits use). Ids and roles:
  - `divi/heading`: `heading.<i>`, text at `title.innerContent.desktop.value`; role from `title.decoration.font.font.desktop.value.headingLevel`: h1/h2 or missing `headline`, h3 `subhead`, h4-h6 `eyebrow`.
  - HTML fields, one slot per top-level `h1`-`h6` or `p` element in document order, skipping elements whose text is empty or `&nbsp;`, none nested in another `p`; id `<short>.<i>.<field>.<k>` with `k` the 0-based order among those elements; role by tag (h1/h2 `headline`, h3 `subhead`, h4-h6 `eyebrow`, p `body`); `rich` true when the element's inner HTML has any tag. Fields: `divi/text` `content.innerContent.desktop.value` (field `body`, so `text.<i>.body.<k>`), and the same path and field for `blurb`, `accordion-item`, `toggle`, `slide`, `testimonial`.
  - plain text fields (kind `text`): `title` at `title.innerContent.desktop.value` for `accordion-item`, `toggle`, `slide`, `number-counter`, `circle-counter` (role `item_title`); `blurb` title at `title.innerContent.desktop.value.text`; `number` at `number.innerContent.desktop.value` for the two counters (role `figure`); `contact-field` `label` at `fieldItem.innerContent.desktop.value` (role `field_label`); `testimonial` `author`, `job` (`jobTitle`), `company` (`company.innerContent.desktop.value.text`) under `<path>.innerContent.desktop.value` (role `attribution`).
  - `divi/button`: `button.<i>.text` (role `button_label`, path `button.innerContent.desktop.value.text`) and `button.<i>.link` (kind `link`, path `...value.linkUrl`), the link slot omitted when the sample starts with `$variable(` (loop-owned).
  - pictures (kind `picture`, role `picture`): `image.<i>` at `image.innerContent.desktop.value.src` with `image.<i>.alt` (kind `alt`); `testimonial.<i>.portrait` at `portrait.innerContent.desktop.value.url` with `.alt`; and for ANY block a `module.decoration.background.desktop.value.image.url` whose value is a `{{aied:image:*}}` token: `<short>.<i>.background`.
- Produces: `StudioSlots::recipesWithSlot(string $slotId): list<string>` (recipe names that have that slot id, in library order) and `StudioSlots::EXEMPT_PATHS` (list of path suffixes under `innerContent` that are not copy: icon `unicode`/`type`/`weight`/`useIcon`, social-network `title`/`label`/`network` values, anything the coverage test shows is not copy, each with a one-line reason).

- [ ] **Step 1: Failing tests.** `testSlotIdsAreUniqueWithinEachRecipe`; `testHeroCtaSlots` (`hero-cta` includes a `headline` slot, a `button_label` and a `link`, and a `picture` slot `section.0.background`); `testCoverage`: for every recipe, every string leaf under a key path containing `innerContent` is either the value of an extracted slot or ends with an `EXEMPT_PATHS` entry, and every `{{aied:image:*}}` string in the markup is a picture slot value (so a new copy-bearing block type in a future recipe fails the test); `testGoldenTable`: the table (recipe => list of `id|role|kind`) equals `tests/golden/studio-slots.json`, regenerated with `AIED_UPDATE_GOLDEN=1` (same idiom as `HtmlToDiviTest`); `testRecipesWithSlot`.
- [ ] **Step 2: Run** `--filter StudioSlotsTest`. Expected: FAIL.
- [ ] **Step 3: Implement** `extract` by scanning with `BlockAttrs::BLOCK`, decoding with `BlockAttrs::decode`, reading the paths above; HTML elements are found with a regex over the decoded HTML string (`<(h[1-6]|p)(\s[^>]*)?>(.*?)</\1>`, not a DOM parser, so untouched bytes stay untouched in Task 4). Note: the paths above are the 14 copy shapes found in the 75 recipes; if the coverage test finds another, add it to the table or to `EXEMPT_PATHS` with a reason.
- [ ] **Step 4: Generate the golden** with `AIED_UPDATE_GOLDEN=1`, read the diff once for sanity, run the tests again. Expected: PASS.
- [ ] **Step 5: Commit.**

### Task 4: Slot filling

**Files:**
- Modify: `wp-plugin/src/StudioSlots.php`
- Test: `tests/StudioFillTest.php`

**Interfaces:**
- Consumes: `StudioSlots::extract`, `StudioLinks::problem`, `RecipeTokens::encodeJson`, `BlockAttrs`.
- Produces: `StudioSlots::fill(string $rawMarkup, array $values): array{markup:string, filled:list<string>, unfilled:list<string>, unknown:list<string>, refused:array<string,string>}`. `$values` maps slot id to a string, except picture slots, which take `['pack'=>token]` or `['url'=>string]` (an `alt` slot id takes a string). Text and alt values: trimmed; `""` after trim means not supplied; HTML-special characters escaped with `htmlspecialchars(..., ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')`; `{` written as `&#123;` and `$` as `&#36;` (so no token or marker survives), and for an HTML element slot the element's inner HTML is replaced (its tag and attributes kept); the sample's inline markup is dropped when a `rich` slot is filled. A link goes in only if `StudioLinks::problem` is null, otherwise it lands in `refused[id] = code`. A picture `pack` token is written as `{{aied:image:<token>}}`; a `url` must pass `StudioLinks` as an `https://` or site-path link. An id in `$values` that is not a slot of this markup goes in `unknown`. A block with no filled slot is not re-encoded: its bytes stay identical. A re-encoded block uses `RecipeTokens::encodeJson` on the decoded attributes and keeps the comment prefix and suffix.

- [ ] **Step 1: Failing tests.** `testEncodeJsonRoundTripsEveryRecipeBlock` (for every recipe and every block, `RecipeTokens::encodeJson(BlockAttrs::decode($json))` equals the stored `$json`; if this fails the fix belongs here, in how `fill` re-encodes, and the test documents the exact difference); `testFillNothingIsByteIdentical` (empty `$values`, markup unchanged, `unfilled` equals every slot id); `testTextIsEscaped` (value `<b>x</b> & "q"` appears as `&lt;b&gt;x&lt;/b&gt; &amp; &quot;q&quot;`); `testTokensAndMarkersAreInert` (values `{{aied:color:bg}}`, `{{aied:image:hero-blue-1}}`, `{"aied":{"kit":"control"}}`, `$variable({})$`: after `SectionRecipes::resolveRaw(fill(...)['markup'], 'editorial')` none of the four strings, resolved or raw, appears as a token or marker and `KitResolver::leftovers` is empty); `testEmptyAndWhitespaceCountAsUnfilled`; `testUnknownSlotReported`; `testLinkRefusedKeepsSample` (`javascript:x` lands in `refused` with `LINK_SCHEME_NOT_ALLOWED`, markup link unchanged); `testPictureByPackAndByUrl`; `testFilledRichSlotDropsInlineMarkup`; `testOnlyTheSuppliedElementChanges` (fill `text.0.body.1` of a recipe with two paragraphs: the first paragraph's bytes are untouched).
- [ ] **Step 2: Run** `--filter StudioFillTest`. Expected: FAIL.
- [ ] **Step 3: Implement** `fill` as specified. Slot values are applied per block: decode, set the path (or the k-th element), re-encode, splice into the markup.
- [ ] **Step 4: Run** the test. Expected: PASS. **Step 5: Commit.**

### Task 5: Brief validation

**Files:**
- Create: `wp-plugin/src/StudioBrief.php`
- Test: `tests/StudioBriefTest.php`

**Interfaces:**
- Consumes: `DesignLanguages::ids()`, `StyleKits::ids()`, `SectionRecipes::names()`, `ImagePack::manifest()`, `PageTitle::parse()`, `StudioSlots`, `StudioLinks`.
- Produces: constants `StudioBrief::VERSION = 1`, `MAX_SECTIONS = 20`, `MAX_TEXT = 2000`, `GOALS = ['sell','book','signup','explain','showcase']`, `RECIPE_ALIASES = []` (old name => current name; a test proves every alias target exists); `StudioBrief::check(mixed $brief): array{ok:bool, brief:?array, problems:list<array{path:string,code:string,message:string}>}`. Brief shape: `{"version":1,"title":str,"goal":goal,"language":id,"kit":id (optional),"sections":[{"recipe":name,"slots":{id:string},"pictures":{id:{"pack":token}|{"url":str}}}]}`. `pictures` and `slots` optional; `kit` defaults to the language's own kit at compile time. Codes: `NOT_AN_OBJECT`, `UNKNOWN_KEY`, `WRONG_TYPE`, `MISSING`, `UNKNOWN_LANGUAGE`, `UNKNOWN_KIT` (message lists valid ids), `UNKNOWN_RECIPE`, `TOO_MANY_SECTIONS`, `NO_SECTIONS`, `UNKNOWN_SLOT` (message lists the recipe's slot ids and `recipesWithSlot()` of the asked id, so "a fourth card" names layouts that hold four), `TEXT_TOO_LONG` (characters, `MAX_TEXT`), `INVALID_UTF8`, `UNKNOWN_PACK_IMAGE`, plus the `StudioLinks` codes prefixed by the path. `brief` in the result is the normalised brief (aliases resolved, trimmed title, empty values dropped). Never throws, never warns, on any input.

- [ ] **Step 1: Failing tests.** One test per code above with the smallest brief that triggers it; `testValidBriefIsNormalised`; `testMultibyteLengthCountsCharacters` (2000 `é` passes, 2001 fails; emoji and RTL text pass); `testInvalidUtf8Refused`; `testGarbageNeverWarns` (`null`, `7`, `"x"`, `[]`, nested depth 100 arrays: ok false, no exception, run with `failOnWarning` from phpunit.xml); `testAliasTargetsExist`.
- [ ] **Step 2: Run** `--filter StudioBriefTest`. Expected: FAIL. **Step 3: Implement.** **Step 4: Run.** Expected: PASS. **Step 5: Commit.**

### Task 6: The compiler

**Files:**
- Create: `wp-plugin/src/StudioCompiler.php`
- Test: `tests/StudioCompilerTest.php`

**Interfaces:**
- Consumes: Tasks 1, 3, 4, 5; `LayoutGate::check()`, `DesignVariety::advise()`, `LoopAdvisory::advise()`, `StudioLinks::sitePath()`.
- Produces: `StudioCompiler::compile(array $brief, ?callable $pageExists = null, string $homeUrl = ''): array{ok:bool, markup:?string, problems:list<array{path:string,code:string,message:string}>, violations:list<array<string,mixed>>, unfilled:list<array{section:int,recipe:string,slot:string,role:string}>, refused:list<array{section:int,slot:string,code:string}>, warnings:list<array{section:int,slot:string,code:string,message:string}>, advice:array<string,mixed>}`. Steps: `StudioBrief::check` (problems, `ok` false, no markup); per section `SectionRecipes::rawMarkup` -> `StudioSlots::fill` -> `SectionRecipes::resolveRaw($markup, $language, $kit)` (a null result is problem `RECIPE_UNRESOLVED`); sections joined with `"\n\n"`; `LayoutGate::check($all)` (invalid: `ok` false, `violations` filled, markup still returned for diagnosis); `unfilled` lists every unsupplied slot of every section (pictures included); `refused` collects the fill step's refused links (the compile still succeeds with the sample link kept); `warnings` has `LINK_PAGE_MISSING` for an own-site link for which `$pageExists($sitePath)` returns false; `advice` carries `design_variety` and `loops` when the advisories return non-null.

- [ ] **Step 1: Failing tests.** `testSameBriefSameBytes` (compile twice, `===`); `testLanguageChangesTheLook` (same brief, two languages: different markup, both `ok`); `testBriefIsMuchSmallerThanMarkup` (a six-section brief: `strlen(json_encode($brief)) * 10 <= strlen($markup)`; if this fails, report the real ratio rather than weakening it silently); `testUnfilledIsListedAndDoesNotBlock`; `testRefusedLinkKeepsSampleAndReports`; `testMissingPageWarning` (callable returns false); `testInvalidBriefReturnsProblemsAndNoMarkup`; `testKitDefaultsToLanguageKit` and `testExplicitKitWins`; `testSameLoopRecipeTwiceReportsDuplicateLoopId` (`loop-posts-cards` in two sections: `advice['loops']['duplicate_loop_id']` is non-empty); `testBackToBackSameLayoutGivesVarietyAdvice` (`advice['design_variety']` present when two sections share a layout).
- [ ] **Step 2: Run** `--filter StudioCompilerTest`. Expected: FAIL. **Step 3: Implement.** **Step 4: Run.** Expected: PASS. **Step 5: Commit.**

### Task 7: Whole-library proof

**Files:**
- Test: `tests/StudioLibraryProofTest.php`
- Create: `docs/studio-compiler.md` (short dev note: the brief format, slot id scheme, rules; no release wording)
- Modify: `CLAUDE.md` (one paragraph "Studio compiler (unreleased, branch `feat/studio-compiler`)": classes, tests, the rules, what plans 2 and 3 add; same style as neighbouring paragraphs)

**Interfaces:** consumes `StudioCompiler::compile`, `StudioSlots::extract`.

- [ ] **Step 1: Write the tests.** `testEveryRecipeUnderEveryLanguage`: for each recipe and each language id, a one-section brief supplying a unique marker string for every text, alt and `https://example.com/<slot>` link slot compiles `ok`, passes `LayoutGate`, has no `{{`, `"aied"` or `$variable(` left that the recipe did not carry itself, and every supplied marker appears exactly once in the markup (no copy dropped, none duplicated); `unfilled` then holds only picture slots. `testHostileValuesNeverBreakAPage`: 200 seeded random briefs (`AIED_SEED=n` selects the seed, default 1) whose values are random mixes of quotes, backslashes, `-->`, `<!--`, braces, tokens, `<script>`, NULs, 2000-character strings, emoji and RTL text, across random recipes and languages: every result is either refused with problems or `ok` and valid, never a PHP warning, and no output contains `<script` or `javascript:`.
- [ ] **Step 2: Run** `php -d memory_limit=-1 vendor/bin/phpunit --filter StudioLibraryProofTest`. Expected: PASS, or failures that are real defects in Tasks 3 to 6: fix them there, with a regression test in that task's file.
- [ ] **Step 3: Run the whole suite** `php -d memory_limit=-1 vendor/bin/phpunit` and `php -d memory_limit=-1 vendor/bin/phpunit --filter WpOrgCompliance`. Expected: PASS; goldens untouched (`git diff --stat tests/golden` shows only `studio-slots.json` added).
- [ ] **Step 4: Write the dev note and the CLAUDE.md paragraph.**
- [ ] **Step 5: Commit.**
