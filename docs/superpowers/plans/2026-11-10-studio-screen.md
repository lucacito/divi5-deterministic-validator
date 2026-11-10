# Divi 5 Lab screen Implementation Plan (plan 2 of 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A new free admin tab "Divi 5 Lab" (internally still "Studio"): five steps with a live preview that compile a brief (plan 1) into a Divi 5 draft page, save the brief, and rebuild the page from it without ever destroying content.

**Architecture:** Pure units first (stack suggestion, brief merge, store), then a service that is the only thing that saves (`StudioService`: preview, create, rebuild, hand-off), then the screen (PHP render + `admin-ajax` handlers + `studio.js`). The preview approach is decided by a short live spike (Task 1); everything else is testable without a site. No new MCP/REST tool (plan 3). Compiling stays `StudioCompiler::compile`; saving stays `DraftPage::insert` / `HistoryService`; every result passes `LayoutGate`.

**Tech Stack:** PHP 8 (WordPress 6.0+, no `array_is_list`), PHPUnit with the light WP shims in `tests/bootstrap.php`, plain JS (no build step), Playwright for the live gate (as the other `verify-*` scripts).

**Spec:** `docs/superpowers/specs/2026-11-09-studio-design.md` (rules 1 to 7 are binding). Plan 1: `docs/superpowers/plans/2026-11-09-studio-compiler.md` (merged on main, ca7fa9f). Dev note: `docs/studio-compiler.md`.

## Global Constraints

- **Naming (owner decision 2026-11-10).** The user-facing name is **Divi 5 Lab**: the tab label, the screen's headings and notices, the temporary preview draft's title (`Divi 5 Lab preview (temporary) <userId>`), the hand-off text and every string a person or assistant reads. Internal identifiers keep "Studio" (classes, files, the table, ajax actions, the tab slug `studio`, test names, `make verify-studio`), as the plugin already keeps `ai_editor_divi5_*` internally. "Studio" never appears in a translatable string. The Pro add-on is sold at divi5lab.com, so no string in the screen may suggest the Lab is paid or part of Pro (`testNoProMentionOutsideTheProCard` covers it).

- Free plugin, no licence gating, no remote calls, no post-meta writes in `wp-plugin/` (`WpOrgComplianceTest` is never weakened; a justified explicit exception is the only way in). The only Pro mention in the UI stays `AdminPage::proCard()`.
- Pages are always created as drafts; every save goes through `LayoutGate` and (for an existing page) `HistoryService`.
- No AI call, no network, no telemetry. The person's assistant arrives in plan 3.
- Supplied text is data: every value echoed into HTML is escaped (`esc_html`/`esc_attr`), into JS only through `textContent`/`value`, never `innerHTML`.
- Tab capability is `manage_options` (like the other tabs); AJAX handlers check a nonce and the same capability; creating a page also needs `edit_pages`.
- Stored brief: own table `{prefix}ai_editor_divi5_studio` (precedent `HistoryTable`/`OAuthTable`), dropped by `uninstall.php`; a brief is readable and writable only by its owner (`created_by`).
- Limits: brief JSON at most 200 KB stored; at most 50 stored briefs per user (oldest unlinked briefs are removed first); `StudioBrief::MAX_SECTIONS` 20 stays.
- No release, version bump, zip rebuild or readme edit. The `topic` description (272 of 300 characters) is not touched.

## Review Focus

- A placeholder with `&`, `<`, `$` or a quote must read correctly in the preview, the created page's frontend render and the Visual Builder input (plan 1 left this unverified): Task 8 proves it live; Task 2 proves the spike's draft carries the exact escaped text.
- Another user's brief id (guessed, or after a role change) must neither be read, previewed, rebuilt nor overwritten: Task 3 and Task 5 tests.
- A page deleted, trashed, published or edited in the builder after creation: rebuild takes the "new draft instead" path (spec rule 1), never overwrites: Task 4 tests with a stubbed `WP` layer plus Task 8 live.
- Two rebuilds at once, or a double click on Create: the second finds a stored content hash that no longer matches and takes the new-draft path or is refused as a duplicate (same brief hash within 10 seconds): Task 4 test.
- A library change between create and rebuild (spec rule 6): removed recipe gives a clear failure and keeps the other sections' words: Task 4 test.

---

### Task 1: Preview spike (live, dev site, throwaway)

**Files:**
- Create: `scripts/spike-studio-preview.cjs` (or `.php` via wp-cli, builder's choice), `docs/studio-preview-evidence.md`

**Interfaces:**
- Produces: a decision recorded in `docs/studio-preview-evidence.md`: `PREVIEW_MODE = 'draft'` (a private draft rendered in an iframe) or `'approximation'`. Task 5 reads it.

- [ ] **Step 1:** On the Docker dev site (Divi 5.15.1), compile three different briefs with `StudioCompiler`, save the markup into ONE draft titled `Divi 5 Lab preview (temporary) <userId>` (via `DraftPage::insert`, then `wp_update_post` for the next two), and open each version's preview URL (`get_preview_post_link`) in a real browser at 1280 and 400 px.
- [ ] **Step 2:** Record, per version: real theme header and footer present; the sections' styling matches the brief's language (Divi regenerated its CSS after each update, not the first version's); an iframe of the preview inside an admin page loads (no frame-blocking header); text containing `&`, `<`, `$` and `"` displays literally in heading, button label and counter number (this closes plan 1's open risk); the draft does not appear in the front-end, sitemap or search; it does appear in `list_divi_pages` (note how it is named so the owner can tell).
- [ ] **Step 3:** Decide and write the decision, the measured evidence and the cleanup rule (delete on Create and when older than 24 h) into `docs/studio-preview-evidence.md`. If any of the first three checks fails, record `approximation` and why. Delete the spike draft(s). Commit.

Expected: the evidence file answers each check with PASS/FAIL and names the mode.

### Task 2: Pure stack and merge units

**Files:**
- Create: `wp-plugin/src/StudioStack.php`, `wp-plugin/src/StudioMerge.php`; Test: `tests/StudioStackTest.php`, `tests/StudioMergeTest.php`; modify `wp-plugin/src/autoload.php`

**Interfaces:**
- Consumes: `SectionRecipes::tags(string): ?array` (role, layout, density, needs_images), `SectionRecipes::names()`, `StudioSlots::extract(string $raw)`, `StudioBrief::GOALS`.
- Produces:
  - `StudioStack::forGoal(string $goal, string $density = 'balanced'): list<string>` recipe ids, an ordered section stack per goal (each id exists; first is a hero; last is a closing call to action for sell/book/signup);
  - `StudioStack::variants(string $recipe): list<array{recipe:string,layout:string,items:int,needs_images:bool}>` same-role recipes with the item count of each (items = number of slots with role item_title, or 0);
  - `StudioStack::itemCount(string $recipe): int`;
  - `StudioMerge::slots(array $brief, int $section, array $incoming): array{brief:array,applied:list<string>,ignored:list<string>}` merges slot values into one section (partial writes allowed, an empty string clears, unknown slot ids are `ignored`), pure, never changes other sections or the language/kit;
  - `StudioMerge::swapRecipe(array $brief, int $section, string $newRecipe): array{brief:array,kept:list<string>,dropped:list<string>}` keeps values whose slot id exists in the new recipe, reports the rest.

- [ ] **Step 1: Write failing tests.** `testEveryGoalStackIsValid` (for each goal in `StudioBrief::GOALS`, every id exists, at most `StudioBrief::MAX_SECTIONS`, first role is `hero`, `StudioCompiler::compile` of the bare stack under all 10 languages is ok); `testVariantsShareTheRoleAndCountItems` (each variant's role equals the recipe's role, `items` equals the number of `item_title` slots); `testStacksDiffer` (no two goals give the same stack); `testDensityOnlyReordersWithinTheSameRoles` (airy/compact change variants, not the roles); `testMergePartialWriteKeepsOtherValues`, `testMergeEmptyStringClears`, `testMergeUnknownSlotIsIgnoredNotWritten`, `testMergeNeverTouchesOtherSections`, `testSwapRecipeKeepsMatchingSlotsAndReportsTheRest`.
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter 'StudioStack|StudioMerge'`; Expected: FAIL (classes missing).
- [ ] **Step 3: Implement** both classes. The default stacks are a data table in `StudioStack` built from recipe `role` and `stage` tags (name roles, not recipe ids, then pick the recipe of that role closest to `density`); no prose or claims.
- [ ] **Step 4:** Run the filter again, then the full suite; Expected: PASS.
- [ ] **Step 5:** Commit `Studio: stack suggestion and brief merge`.

### Task 3: Own table and store

**Files:**
- Create: `wp-plugin/src/StudioTable.php`, `wp-plugin/src/StudioStore.php`; Test: `tests/StudioStoreTest.php`; modify `wp-plugin/src/autoload.php`, the plugin's table-creation hook (where `HistoryTable::createTable` is called), `wp-plugin/uninstall.php`, `tests/WpOrgComplianceTest.php` (only the justified table-name entry, as `OAuthTable` has)

**Interfaces:**
- Produces: `StudioTable::name(): string`, `schemaSql(): string`, `createTable(): void`, `drop(): void`;
  `StudioStore::save(int $user, array $brief, ?int $id = null): array{ok:bool,id:int,code:?string}` (a brief of an `$id` owned by another user is `NOT_FOUND`, same code as a missing one; over 200 KB is `TOO_LARGE`; prunes to 50 per user, unlinked first);
  `StudioStore::get(int $user, int $id): ?array{id:int,brief:array,page_id:?int,content_hash:?string,brief_hash:string,plugin_version:string,updated_at:string}`;
  `StudioStore::link(int $user, int $id, int $pageId, string $contentHash): bool`; `StudioStore::forPage(int $user, int $pageId): ?array`; `StudioStore::delete(int $user, int $id): bool`; `StudioStore::deleteAll(): void`.
  Queries live only in `StudioStore` and all use `$wpdb->prepare`.

- [ ] **Step 1: Write failing tests** with the same `$wpdb` shim style `HistoryStoreTest` uses: `testSaveAndGetRoundTrip`, `testAnotherUserGetsNotFound`, `testSaveOverAnotherUsersIdIsNotFound`, `testOver200KbIsRefused`, `testPrunesOldestUnlinkedFirst` (51st brief keeps linked ones), `testLinkStoresPageAndHash`, `testForPageFindsTheBrief`, `testDeleteAllEmptiesTheTable`, `testSchemaHasNoPostMeta`.
- [ ] **Step 2:** Run the filter; Expected: FAIL.
- [ ] **Step 3: Implement.** Columns: id, created_by, page_id NULL, brief longtext, brief_hash char(64), content_hash char(64) NULL, plugin_version varchar(20), created_at, updated_at. `uninstall.php` calls `StudioTable::drop()`.
- [ ] **Step 4:** Run the filter, `--filter WpOrg`, then the full suite; Expected: PASS.
- [ ] **Step 5:** Commit `Studio: own table and store`.

### Task 4: StudioService (the only saver)

**Files:**
- Create: `wp-plugin/src/StudioService.php`; Test: `tests/StudioServiceTest.php`; modify `wp-plugin/src/autoload.php`

**Interfaces:**
- Consumes: `StudioCompiler::compile`, `StudioStore`, `DraftPage::insert`, `HistoryService`, `StudioMerge`, `StudioBrief::check`.
- Produces (all return arrays with `ok`, `code`, `message`, never throw; `$user` is the acting user id):
  - `preview(int $user, array $brief): array{ok:bool,markup:?string,report:array}` report = compile's problems, unfilled, refused, warnings, advice; no write;
  - `create(int $user, array $brief, ?int $storedId = null): array{ok:bool,page_id:?int,brief_id:?int,edit_url:?string,report:array}` compiles, requires `ok`, inserts a DRAFT titled by the brief, stores/links the brief with the content hash (`sha256` of the markup); a second identical create by the same user within 10 seconds returns the first result (`duplicate:true`);
  - `rebuild(int $user, int $briefId): array{ok:bool,mode:'overwritten'|'new_draft',page_id:?int,reason:?string,report:array}` implements spec rule 1: overwrite only when the stored page's content hash equals the stored `content_hash` and `post_status` is `draft` (snapshot through `HistoryService` first, source label `studio`), otherwise create a new draft and say why (`edited`, `published`, `trashed`, `missing`); rule 6: a recipe id missing from the library gives `ok:false`, `code:'RECIPE_REMOVED'` naming the section, with the other sections' values kept;
  - `handoff(int $user, int $briefId, string $site): array{instruction:string}` the ready-made instruction text (names the brief id and the tool `studio_build` that plan 3 adds, repeats "do not invent facts, prices, names or numbers; leave a slot empty if unknown"); the text is a constant template, the id is an integer.

- [ ] **Step 1: Write failing tests** (WP functions stubbed through the existing shims): `testPreviewWritesNothing`, `testCreateMakesADraftAndStoresTheBrief`, `testCreateRefusesAnInvalidCompile`, `testDoubleCreateWithinTenSecondsReturnsTheFirst`, `testRebuildOverwritesAnUntouchedDraftAfterASnapshot`, `testRebuildOfAnEditedPageMakesANewDraft`, `testRebuildOfAPublishedPageMakesANewDraft`, `testRebuildOfATrashedOrMissingPageMakesANewDraft`, `testRebuildNeverTouchesAnotherUsersBrief`, `testRemovedRecipeFailsAndKeepsTheOtherSections`, `testHandoffTextHasTheNoInventedFactsRule`, `testPlaceholdersNeverBlockACreate` (empty slots, `ok:true`, listed in `report.unfilled`).
- [ ] **Step 2:** Run the filter; Expected: FAIL.
- [ ] **Step 3: Implement.** Content hash from the markup exactly as saved (post content after `wp_slash`/unslash round trip: compare against `get_post_field('post_content')`). Capability checks stay in the handlers (Task 6); the service checks ownership only.
- [ ] **Step 4:** Run the filter and the full suite; Expected: PASS.
- [ ] **Step 5:** Commit `Studio: preview, create, rebuild and hand-off service`.

### Task 5: Live preview unit

**Files:**
- Create: `wp-plugin/src/StudioPreview.php`; Test: `tests/StudioPreviewTest.php`

**Interfaces:**
- Consumes: the decision in `docs/studio-preview-evidence.md`.
- Produces: `StudioPreview::url(int $user, string $markup): array{mode:'draft'|'approximation',url:?string,html:?string}`. In `draft` mode it updates the one `Divi 5 Lab preview (temporary) <user>` draft (creating it if missing, always `draft`, never published) and returns its preview URL; in `approximation` mode it returns `html` (the compiled markup rendered by a minimal wrapper, clearly labelled "Approximate preview"). `StudioPreview::cleanup(int $user)` deletes the preview draft; `StudioPreview::cleanupStale()` removes any older than 24 h.

- [ ] **Step 1: Write failing tests:** `testReusesTheSameDraftPerUser`, `testNeverPublishes`, `testCleanupDeletesIt`, `testStaleOnesAreRemoved`, `testApproximationIsLabelled` (the mode the spike chose is the one pinned; the other has a test too).
- [ ] **Step 2-4:** Run to fail, implement, run to pass (filter then full suite).
- [ ] **Step 5:** Commit `Studio: live preview`.

### Task 6: AJAX handlers

**Files:**
- Create: `wp-plugin/src/StudioAjax.php`; Test: `tests/StudioAjaxTest.php`; modify `AdminPage::register()` (one line calling `StudioAjax::register()`)

**Interfaces:**
- Produces actions (all `wp_ajax_` only, nonce `ai_editor_divi5_studio`, capability `manage_options`, create/rebuild also `edit_pages`): `aied_studio_stack` (goal, density -> stack with variants), `aied_studio_preview` (brief JSON -> `StudioService::preview` + `StudioPreview::url`), `aied_studio_save` (brief -> brief id), `aied_studio_create`, `aied_studio_rebuild`, `aied_studio_handoff`, `aied_studio_brief` (id -> stored brief, for the screen's refresh/poll). Every handler returns `wp_send_json_*` with the report; input is read as strict strings/JSON, a non-string is a 400.

- [ ] **Step 1: Write failing tests:** `testEveryActionRefusesAMissingNonce`, `testEveryActionRefusesALowCapability`, `testCreateNeedsEditPages`, `testNonStringInputIs400`, `testBriefOfAnotherUserIs404`, `testResponsesNeverEchoUnescapedHtml` (a brief with `<script>` comes back as JSON data only).
- [ ] **Step 2-4:** Run to fail, implement, run to pass.
- [ ] **Step 5:** Commit `Studio: ajax handlers`.

### Task 7: The screen

**Files:**
- Create: `wp-plugin/src/StudioScreen.php`, `wp-plugin/assets/studio.js`, `wp-plugin/assets/studio.css`; Test: `tests/StudioScreenTest.php`; modify `AdminPage::render()` (a fourth core tab with slug `studio` labelled "Divi 5 Lab", between Features and Settings) and `AdminPage::enqueueAssets()` (studio assets only on that tab)

**Interfaces:**
- Produces: `StudioScreen::render(): void` the five steps (Goal and business; Look and feel; Sections; Words and pictures; Review and create) with the live preview beside them, as spec. `StudioScreen::config(int $user): array` the localized data (nonce, ajax URL, goals, the 10 languages with their one-line summaries, the kits, the stack per goal, i18n). Stepper is keyboard-operable (real `<button>`s, `aria-current="step"`, focus moves to the step heading), the preview iframe has a title, colour is never the only signal. Pictures: Media Library picker (`wp.media`) first, then the built-in pack list; a person-supplied URL is validated by the same `StudioLinks::pictureProblem`. Review shows the validator verdict, the unfilled list by section and role, the desktop/tablet/phone width switch, and Create as draft; after creating it offers Open in Divi, Rebuild and Copy instruction for my assistant.

- [ ] **Step 1: Write failing tests** (render to string with shims): `testTheTabIsRegisteredOnce`, `testFiveStepsRenderInOrder`, `testConfigListsAllLanguagesKitsAndGoals`, `testNoUserTextIsOutputUnescaped` (site title containing `<script>` as the pre-filled business name), `testOnlyTheStudioTabLoadsStudioAssets`, `testNoProMentionOutsideTheProCard` (`WpOrgComplianceTest` stays green), `testEveryStringIsTranslatable`, `testTheWordStudioNeverAppearsInAUserFacingString`.
- [ ] **Step 2-4:** Run to fail, implement (server-rendered markup, JS adds behaviour; with JS disabled the screen says so and shows nothing broken), run to pass; run `--filter WpOrg` and the full suite.
- [ ] **Step 5:** Commit `Studio: the admin screen`.

### Task 8: Live gate, docs

**Files:**
- Create: `scripts/verify-studio.cjs` (+ `scripts/test-verify-studio.cjs` pure tests), `Makefile` target `verify-studio`; modify `docs/studio-compiler.md` (new "Studio screen" section), `CLAUDE.md` (one paragraph), `docs/studio-preview-evidence.md` if the gate changes a finding

**Interfaces:**
- Produces `make verify-studio`: on the Docker dev site in a real administrator session (reduced motion, as `verify-pro-megamenu`): walks the five steps for two goals; checks the preview at 1280/900/400 (no overflow, no console or PHP error); creates a draft and checks the saved page equals the compiled markup, is a draft, renders with Divi, and text with `&`, `<`, `$` and `"` displays literally in heading, button label and counter number (closes plan 1's open risk, records the answer in the dev note); rebuild of an untouched page (overwritten, one history snapshot, undo restores bytes), of a builder-edited page and of a published page (new draft); another user's brief id refused; keyboard-only run through the stepper; self-cleaning (`Divi 5 Lab preview` and `aied studio` titles, `--clean-orphans`); refuses a non-local site (`scripts/dev-guard.php`).

- [ ] **Step 1:** Write the pure tests for the script's expectation builders; run, see them fail.
- [ ] **Step 2:** Write the script; run the pure tests, then `make verify-studio` once; Expected: all PASS, 0 FAIL, no orphan pages.
- [ ] **Step 3:** If the literal-display check fails, fix `StudioSlots::fill` escaping per field kind with a failing test first (plain fields hold text, not entities), re-run plan 1's suite and the gate.
- [ ] **Step 4:** Write the docs, run the full suite and `make test` budget checks (`WpOrgComplianceTest`).
- [ ] **Step 5:** Commit `Studio: live gate and docs`.

## Self-review

- Spec coverage: five steps and the preview (Tasks 1, 5, 7), saved brief per page (3, 4), rebuild safety and undo (4), placeholders (4, 7), item counts (2, 7), hand-off (4, 6, 7; the tool itself is plan 3), links (plan 1 plus the picker in 7), library changes (4), uninstall (3). The AI route and the guide topic are plan 3.
- Open for the owner at review: the default stack per goal (Task 2) is the builder's choice from recipe roles; the preview draft appears in `list_divi_pages` under a clear name (Task 1 records exactly how).
