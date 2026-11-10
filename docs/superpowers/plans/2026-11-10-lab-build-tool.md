# Lab assistant tool (`lab_build`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An assistant (Claude, ChatGPT in Developer mode, Cursor ...) builds a page from a short brief through one new MCP and REST tool, `lab_build`, and fills the words of a brief a person started in the Lab screen, with no way to overwrite what the person changed in the meantime.

**Architecture:** One pure-ish service class `StudioAssistant` (same shape as `HtmlToDiviService::run`: arguments in, `{status, body}` out, called by both `McpHandler` and `RestController` so they cannot drift) sits on the existing `StudioBrief` / `StudioMerge` / `StudioSlots` / `StudioService` / `StudioStore`. The tool has four actions (`catalog`, `open`, `fill`, `build`) so one name serves the hand-off text the screen already prints. Writes to a stored brief use a compare-and-set on the brief's hash (`base_hash`), added to `StudioStore::save` and also sent by the Lab screen. No new table, no remote call, no AI in the plugin.

**Tech Stack:** PHP 8 (WordPress plugin, `wp-plugin/src/`), PHPUnit with the repo's WP shims (`tests/FakeWpdb.php`, `tests/WpRestShims.php`), plain JS (`wp-plugin/assets/studio.js`, `studio-core.js`, node tests `scripts/test-studio-ui.cjs`), the live gate `make verify-studio`.

**Spec:** `docs/superpowers/specs/2026-11-09-studio-design.md` (sections "The AI route" and rule 4 "Handing the brief to the assistant"); the screen it hands off from is documented in `docs/studio-compiler.md` ("Lab screen", "Known limits", "Owner decisions left"); plan 2 is `docs/superpowers/plans/2026-11-10-studio-screen.md`.

## Global Constraints

- User-facing name is **Lab** (never "Studio" in a string a person reads; tool descriptions and responses say Lab). Internal names stay `Studio*`; the tool name is `lab_build` (`StudioService::TOOL`, already printed by the hand-off instruction).
- WordPress.org rules: no remote calls, no licence gating, no post-meta writes (the only writes are `StudioStore` rows and `DraftPage::insert` drafts), ABSPATH guard in every PHP file, text domain `jhmg-ai-editor-for-divi-5`. `tests/WpOrgComplianceTest.php` is never weakened.
- The validator (`src/`, `wp-plugin/validator/`) is NOT touched. Every page the tool makes is a DRAFT and passes `LayoutGate` (already inside `StudioCompiler::compile`).
- Tool budget: the ChatGPT importer's 30 operations are used up (16 free + 14 Pro), so `lab_build` is **MCP and REST only**, pinned absent from `OpenApiSpec` by a lockstep test (precedent: `HtmlToDiviLockstepTest`, `SiteStructureLockstepTest`).
- Strict arguments, like every other tool: unknown keys refused by name, wrong types refused (HTTP 400 / JSON-RPC -32602), never cast. Capability: `PageAccess::CAP` (`edit_pages`) for every action; `create:true` also needs `publish_pages` (as `html_to_divi`). Brief ownership is the acting user (`get_current_user_id()`); another user's id is a plain NOT_FOUND.
- Text from the assistant is untrusted: it only ever reaches a brief through `StudioMerge::slots` and `StudioBrief::check`, and the page only through `StudioSlots::fill` (which escapes). Responses never echo more brief text than the person's own brief already holds.
- NO release, version bump, zip rebuild or readme edit. Release-time wording is collected in `docs/lab-build-release-notes-draft.md` (Task 8), including that the readme's "21 tools" becomes 22.
- Keep `get_style_guide`'s `topic` description untouched (it has 21 characters of headroom at most; this plan adds NO guide topic: the tool teaches itself through `action: "catalog"`).
- Suites must stay green: `php -d memory_limit=-1 vendor/bin/phpunit` (6879 tests at the start), `node scripts/test-studio-ui.cjs` (19), `node scripts/test-verify-studio.cjs` (15).

## Review Focus

Each line below also has its test in the task that owns the code.

1. Two writers, one brief: the person saves in the Lab screen while the assistant fills (and the other way round). The second writer holding a stale hash must get CONFLICT and change nothing (Task 1 store, Task 2 screen, Task 4 tool).
2. An UPDATE that changes nothing (same content in the same second) must not be reported as a conflict (Task 1).
3. `fill` with a slot id the section does not have, a wrong kind, or a section index out of range must write nothing for that entry, report it by name, and never throw (Task 4).
4. An assistant sending hostile words (`{{aied:image:x}}`, `$variable(`, `<script>`, `</p>`) must produce a valid page and a brief that still validates (Task 4, reusing the compiler's escaping).
5. `catalog` must stay small enough to be worth calling (bounded bytes, tested) and must not list recipes the compiler cannot build (Task 3).
6. `create:true` on a brief whose page was edited in Divi must make a NEW draft and say why (existing `StudioService::rebuild` rule), never overwrite (Task 5).

---

### Task 1: Compare-and-set in `StudioStore`

**Files:**
- Modify: `wp-plugin/src/StudioStore.php` (the `save` method and a new constant)
- Test: `tests/StudioStoreTest.php`

**Interfaces:**
- Consumes: the table row `brief_hash` (sha256 of the JSON, already written by `save`); `StudioStore::get()` already returns `brief_hash`.
- Produces: `StudioStore::CONFLICT = 'CONFLICT'`; `StudioStore::save( int $user, array $brief, ?int $id = null, ?string $expectedHash = null ): array{ok:bool,id:int,code:?string}`. With `$expectedHash` non-null and `$id` non-null, the UPDATE is conditional on `brief_hash = $expectedHash`; if no row changed, re-read the row: when its stored hash already equals the hash of the JSON being written, return ok (idempotent), otherwise return `fail(CONFLICT)`. `$expectedHash` with `$id === null` is ignored (an insert cannot conflict). Existing callers keep working unchanged.

- [ ] **Step 1: Write the failing tests** in `tests/StudioStoreTest.php`: `testSaveWithTheCurrentHashSucceeds`; `testSaveWithAStaleHashIsAConflictAndChangesNothing` (save v1, read its hash, save v2 without a hash, then save v3 with v1's hash: `CONFLICT`, stored brief still v2); `testSaveWithTheCurrentHashAndIdenticalContentIsNotAConflict` (the no-op UPDATE case: save the same brief again with its own hash, expect ok); `testSaveWithAHashForAnotherUsersBriefIsNotFound` (still `NOT_FOUND`, not `CONFLICT`); `testExpectedHashWithoutAnIdIsIgnored`. If `FakeWpdb` cannot evaluate an `AND brief_hash = %s` UPDATE, extend `FakeWpdb` minimally (it is a test shim) and say so in the report.
- [ ] **Step 2: Run** `php -d memory_limit=-1 vendor/bin/phpunit --filter StudioStoreTest` — Expected: the new tests FAIL (unknown argument / no conflict code).
- [ ] **Step 3: Implement** the conditional UPDATE in `StudioStore::save` as described above; keep the existing phpcs ignore comments and `$wpdb->prepare` usage; the "no row changed" re-read must use the existing private `row()`.
- [ ] **Step 4: Run** `php -d memory_limit=-1 vendor/bin/phpunit --filter Studio` — Expected: PASS.
- [ ] **Step 5: Commit** `git add -A && git commit -m "Lab: compare-and-set for stored briefs (StudioStore::save expectedHash)"` (end the message with the Co-Authored-By trailer given in the session).

---

### Task 2: The Lab screen sends and honours the brief hash

**Files:**
- Modify: `wp-plugin/src/StudioAjax.php` (`handleSave`, `handleBrief`; `handleCreate` / `handleRebuild` only if they save a stored brief: read them first), `wp-plugin/assets/studio.js`, `wp-plugin/assets/studio-core.js`, `wp-plugin/src/StudioScreen.php` (config carries the stored brief's `hash`), `wp-plugin/src/StudioService.php` only if `create()` / `rebuild()` need to accept an expected hash (prefer not: do the compare in the handler before calling).
- Test: `tests/StudioAjaxTest.php`, `tests/StudioScreenTest.php`, `scripts/test-studio-ui.cjs`

**Interfaces:**
- Consumes: Task 1's `StudioStore::save(..., $expectedHash)` and `StudioStore::CONFLICT`.
- Produces: `handleSave` accepts an optional `base_hash` (64 hex characters, else 400 `BAD_REQUEST`); success data gains `hash` (the new stored hash, from `StudioStore::get`); a CONFLICT answers HTTP 409 with `code: "CONFLICT"` and data `{brief, hash}` (the CURRENT stored brief and hash, for the client to merge). `handleBrief` success data gains `hash`. In `studio-core.js`: `resolveConflict(state, current, synced, keys, catalog)` returns `{ action: "retry", state }` when the stored brief differs from what the screen last synced only in slot/picture words (it merges those words with the existing `mergeAssistant` rules and keeps the person's own typing), or `{ action: "reload" }` when goal, language, kit, title or the list/order of recipes differ. The screen keeps `baseHash` (from config and every save/brief answer), sends it with every save, on `retry` saves once more with the new hash, on `reload` stops, saves nothing and shows the sentence "Your assistant changed the look or the sections while you were editing. Reload this page to see the change. Nothing you typed was sent." (translatable string in `i18n()`).

- [ ] **Step 1: Write the failing tests.** PHP (`StudioAjaxTest`): save with the current `base_hash` succeeds and returns `hash`; stale `base_hash` → 409 `CONFLICT` carrying the current brief and hash, store unchanged; malformed `base_hash` → 400; no `base_hash` still saves (old behaviour, the first save of a new brief); `handleBrief` returns `hash`. `StudioScreenTest`: the config carries `hash` for a stored brief and none otherwise. Node (`test-studio-ui.cjs`): `resolveConflict` — words-only change → `retry` with the assistant's words merged and the person's unsaved words kept; changed recipe list → `reload`; changed language → `reload`; identical → `retry` unchanged.
- [ ] **Step 2: Run** the three suites — Expected: the new tests FAIL.
- [ ] **Step 3: Implement** the handler changes, the config field, `resolveConflict` and the `studio.js` wiring (one retry at most per save; `beforeunload`/dirty logic unchanged; no `innerHTML`: the existing test scanning the JS must still pass).
- [ ] **Step 4: Run** `php -d memory_limit=-1 vendor/bin/phpunit --filter Studio`, `node scripts/test-studio-ui.cjs`, `node scripts/test-verify-studio.cjs` — Expected: PASS.
- [ ] **Step 5: Commit** `Lab: the screen sends the brief hash and handles a conflict`.

---

### Task 3: `StudioAssistant` — argument checks, `catalog` and `open`

**Files:**
- Create: `wp-plugin/src/StudioAssistant.php`
- Modify: `wp-plugin/src/autoload.php` only if classes are registered by hand there (check how `StudioService` is loaded and do the same)
- Test: `tests/StudioAssistantTest.php`

**Interfaces:**
- Consumes: `StudioBrief::GOALS`, `DesignLanguages::ids()` / `get()`, `StyleKits::ids()`, `SectionRecipes::names()` / `tags()` (role per recipe), `StudioSlots::extract(SectionRecipes::rawMarkup($name))`, `StudioStore::get()`, `PageAccess::stringArg` / `boolArg`, `UsageTracker::log`.
- Produces: `StudioAssistant::run( array $args ): array{status:int, body:array<string,mixed>}` (200 ok, 400 bad input, 403 not allowed, 404 brief not found, 409 CONFLICT, 422 brief or layout refused, 500 failure; `ENDPOINT = 'lab_build'`; `KNOWN = ['action','recipe','role','brief_id','base_hash','sections','brief','create']`). This task implements the check order (capability → unknown keys → types → `action` required and one of `catalog|open|fill|build`) and two actions:
  - `catalog`: no `recipe` → `{goals, languages:[{id,name}], kits:[id], recipes:{role:[name]}, how:string}` where `how` is a 3-4 sentence plain-English description of the four actions and the rules (no invented facts, leave unknown slots empty, drafts only); with `recipe` → `{recipe, role, slots:[{id,kind,role,sample}]}` (unknown recipe → 400 naming the closest valid ones is NOT required; a plain 400 is enough). Optional `role` filters the recipe list.
  - `open` (needs `brief_id`, a positive integer; a non-integer is 400): `{brief_id, base_hash, brief, sections:[{section:int, recipe, slots:[{id,kind,role,sample,value,filled:bool}]}], unfilled_count:int, page:{id,status}|null, rules:string}`; `base_hash` is the stored `brief_hash`.

- [ ] **Step 1: Write the failing tests** (`StudioAssistantTest`, with the WP shims the other `Studio*Test`s use): 403 without `edit_pages`; unknown argument named in the 400; `action` missing / unknown / not a string → 400; `brief_id` of `"3"`, `0`, `-1`, `1.5`, `true` → 400; `catalog` lists exactly the recipes `SectionRecipes::names()` returns, grouped by role, every goal and every language id, its JSON is at most 9,000 bytes (`testCatalogIsSmall`), and `catalog` with a recipe returns exactly `StudioSlots::extract` ids; `open` returns the stored brief, a `base_hash` equal to `StudioStore::get()['brief_hash']`, one entry per section with `filled` true only for slots with a value, and 404 for a missing brief and for ANOTHER user's brief (same body as missing); `open` writes nothing (brief row byte-identical, no page).
- [ ] **Step 2: Run** `php -d memory_limit=-1 vendor/bin/phpunit --filter StudioAssistantTest` — Expected: FAIL (class missing).
- [ ] **Step 3: Implement** `StudioAssistant::run` with a private method per action; log `UsageTracker::log('lab_build', $pageId|null, 'read'|'error')`.
- [ ] **Step 4: Run** the filter again, then `--filter Studio` — Expected: PASS.
- [ ] **Step 5: Commit** `Lab: the assistant service (catalog and open)`.

---

### Task 4: `fill` — merge words into a stored brief, safely

**Files:**
- Modify: `wp-plugin/src/StudioAssistant.php`
- Test: `tests/StudioAssistantTest.php`

**Interfaces:**
- Consumes: Task 1's compare-and-set, Task 3's service, `StudioMerge::slots( array $brief, int $section, array $incoming ): array{brief,applied,ignored}`, `StudioBrief::check`, `StudioService::preview( int $user, array $brief )`.
- Produces: action `fill` with `brief_id` (required), `base_hash` (required, 64 hex), `sections` (required, a list of at most 20 objects `{section:int, slots?:object, pictures?:object}`; a section index out of range, a duplicate index, or a non-object entry is a 400 for the WHOLE call before anything is written). Behaviour: load the brief (404 if not the user's), merge each entry with `StudioMerge::slots` (slots and pictures in one `incoming` map), validate the merged brief with `StudioBrief::check` (problems → 422, nothing saved), save with `StudioStore::save($user, $brief, $id, $baseHash)`; a CONFLICT answers 409 with `{code:"CONFLICT", current_base_hash, message}` and NO brief text. Success body: `{brief_id, base_hash (new), applied:[{section,slot}], ignored:[{section,slot}], unfilled_count, report: StudioService::preview()['report'], page:{id,status}|null, message}`. Calling `fill` with an empty `sections` list is a 400 (nothing to do). The page is NOT rebuilt by `fill` alone (that is `create`, Task 5).

- [ ] **Step 1: Write the failing tests:** current hash → saved, new hash differs, `applied` lists exactly the written slots; stale hash → 409, brief row unchanged; hash for a brief the user does not own → 404; `ignored` lists a slot id the recipe lacks and a string sent to a picture slot and an object sent to a text slot, and none of them is written; section out of range / duplicate section / `sections: []` / `sections` not a list / entry not an object → 400 and the stored brief byte-identical; a 2,001-character value → 422 from `StudioBrief::check`, nothing saved; hostile words (`{{aied:image:logo}}`, `$variable(--x)$`, `<script>alert(1)</script>`, `</p><p>`) are saved and then `StudioService::preview` still returns `ok:true` with markup that contains no `<script` and no raw token; two `fill` calls in a row with the new hash from the first both succeed; the empty string clears a slot (sample copy returns, `unfilled_count` rises).
- [ ] **Step 2: Run** the filter — Expected: FAIL.
- [ ] **Step 3: Implement** `fill`. Order: validate everything, then one `StudioStore::save`. Never call `wp_slash` or write post meta.
- [ ] **Step 4: Run** `--filter Studio` — Expected: PASS.
- [ ] **Step 5: Commit** `Lab: the assistant fills the words of a stored brief (compare-and-set)`.

---

### Task 5: `build` and `create`

**Files:**
- Modify: `wp-plugin/src/StudioAssistant.php`
- Test: `tests/StudioAssistantTest.php`

**Interfaces:**
- Consumes: `StudioService::preview( int $user, array $brief )`, `StudioService::create( int $user, array $brief, ?int $storedId = null )`, `StudioService::rebuild( int $user, int $briefId )`.
- Produces:
  - action `build` with `brief` (an object, required): no `create` or `create:false` → `{valid:bool, created:false, report, markup_omitted:true, message, next}` (preview; the markup is never returned: the assistant does not need it and it costs tokens; an invalid brief is 422 with `report.problems`); `create:true` (needs `publish_pages`, else 403; `title` comes from the brief) → `StudioService::create`, body `{created:true, page:{id,status:"draft",link}, brief_id, base_hash, duplicate:bool, report, message}`.
  - `fill` and `open` gain `create:true` handling only on `fill`: after a successful merge and save, `create:true` calls `StudioService::rebuild( $user, $briefId )` when the brief already has a page, else `StudioService::create( $user, $brief, $briefId )`; the body adds `build:{mode:"overwritten"|"new_draft"|"created", reason:?string, page:{id,status,link}}`. `create` requires `publish_pages`.
  - Every refusal from the service (`INVALID_BRIEF`, `LAYOUT_INVALID`, `RECIPE_REMOVED`, `NOT_FOUND`, `STORE_FAILED`, `SAVE_FAILED`, `INTERNAL_ERROR`) maps to a status (INVALID_BRIEF/LAYOUT_INVALID/RECIPE_REMOVED 422, NOT_FOUND 404, others 500) with `code` and `message` only.

- [ ] **Step 1: Write the failing tests:** a good brief previews (`created:false`, no `markup` key, nothing in `__wp_posts`); an invalid brief (unknown recipe, unknown language) is 422 listing `report.problems` paths; `create:true` makes exactly one DRAFT page whose content contains the supplied headline, links the brief, and a second identical call within `DUPLICATE_SECONDS` returns `duplicate:true` and no second page; `create:true` without `publish_pages` is 403 and creates nothing; `fill` with `create:true` on a brief with no page creates a draft; on a brief whose page is untouched it overwrites it and history has exactly one `studio` row; when the page was edited in the builder (change its stored content) it makes a NEW draft with `reason:"edited"` and leaves the edited page byte-identical (Review Focus 6); `build` never returns a published page and no tool path sets `post_status` other than `draft`.
- [ ] **Step 2: Run** the filter — Expected: FAIL.
- [ ] **Step 3: Implement** both actions on top of the existing service; no duplicate of its overwrite rules.
- [ ] **Step 4: Run** `--filter Studio` — Expected: PASS.
- [ ] **Step 5: Commit** `Lab: the assistant previews and creates drafts from a brief`.

---

### Task 6: Register the tool (MCP, REST, goldens, counts)

**Files:**
- Modify: `wp-plugin/src/ToolCatalog.php` (definition `lab_build`), `wp-plugin/src/McpHandler.php` (dispatch `'lab_build' => $this->toolLabBuild($id, $arguments)` and the method, copied in shape from `toolHtmlToDivi`), `wp-plugin/src/RestController.php` (`POST /lab-build`, `permission_callback` `require_edit_posts`, callback `lab_build`, copied in shape from `html_to_divi`), `tests/WpOrgComplianceTest.php` (the tool-name list gains `lab_build` in sorted position, `assertCount(22, $tools)` with a comment line "22 tools: Lab's assistant tool ..."), the `tests/golden/companion/` goldens (re-frozen deliberately with `AIED_UPDATE_GOLDEN=1`; the diff must be ONLY the new tool and its operation absence).
- Create: `tests/LabBuildLockstepTest.php` (model: `tests/HtmlToDiviLockstepTest.php`)
- Check, then modify only if a test requires it: `tests/Abilit*Test.php` / `AbilityCatalog` (decision recorded in the ledger: NO ability in this plan; if a test insists every tool has one, rule and record).

**Interfaces:**
- Consumes: `StudioAssistant::run`.
- Produces: the tool visible in `tools/list` and callable through `tools/call`; the same `{status, body}` mapping `toolHtmlToDivi` uses (400/403 become JSON-RPC -32602; 404, 409, 422, 500 are a normal result with `isError:true`); REST returns the body with the status; 403 is a `WP_Error`.

The tool definition (exact text for the schema; the description is the only teaching the assistant gets before `catalog`, keep it under 1,400 characters):

- name `lab_build`; description: "Build a Divi 5 page from a short brief instead of writing Divi markup. A brief is a design language, an optional style kit and an ordered list of proven section layouts (recipes), each with the words and pictures to fill in; the plugin builds valid Divi 5 from it, deterministically, always as a DRAFT. Start with action \"catalog\" (goals, languages, kits, recipes by role, and the rules). Action \"open\" reads a brief the site owner started in the Lab screen (brief_id) and lists every slot, with the sample copy it shows now. Action \"fill\" writes words into those slots (sections: [{section, slots:{slot id: text}}]) and needs the base_hash you got from open or the last fill; if the owner changed the brief meanwhile you get CONFLICT and nothing is written: open it again. Action \"build\" takes a whole brief and previews it, or saves a new draft page with create=true. Never invent facts, prices, names or numbers: leave a slot out if you do not know it. Pictures come from the Media Library or the built-in pack (see get_image_guide)."
- `inputSchema`: `action` (enum catalog|open|fill|build, required); `recipe` (string); `role` (string); `brief_id` (integer, minimum 1); `base_hash` (string); `sections` (array of objects: `section` integer, `slots` object, `pictures` object); `brief` (object); `create` (boolean).

- [ ] **Step 1: Write the failing tests** (`LabBuildLockstepTest`): `tools/list` declares `lab_build` and the dispatch line exists; MCP and REST give the same meaning for a good `catalog`, a bad argument (-32602 vs 400), a forbidden user (-32602 vs `WP_Error` 403), a 409 conflict (`isError:true` vs status 409) and a 422 brief; the OpenAPI spec (`OpenApiSpec::spec(...)`) has no path containing `lab-build` and its operation count is still 16; `ToolCatalog::get('lab_build')` is non-null and its description is at most 1,400 characters (`StudioScreen::assistantReady()` is now true: assert it); the compliance test's name list and count (22).
- [ ] **Step 2: Run** `--filter 'LabBuildLockstepTest|WpOrgComplianceTest|ToolsListGoldenTest|OpenApi'` — Expected: FAIL.
- [ ] **Step 3: Implement** the catalog entry, the MCP method and dispatch, the REST route and callback; then `AIED_UPDATE_GOLDEN=1 php -d memory_limit=-1 vendor/bin/phpunit --filter ToolsListGoldenTest` and review `git diff tests/golden` (only `lab_build` added).
- [ ] **Step 4: Run** the full suite `php -d memory_limit=-1 vendor/bin/phpunit` — Expected: PASS. Fix any test that pinned "hand-off hidden until the tool exists" in a way that now flips (the screen tests pass an explicit `$assistant` flag; any test reading the real catalog must now expect the hand-off to be offered).
- [ ] **Step 5: Commit** `Lab: register the lab_build tool (MCP and REST only)`.

---

### Task 7: The live gate: the hand-off and a real round trip

**Files:**
- Modify: `scripts/verify-studio.cjs`, `scripts/verify-studio-server.php`, `scripts/lib/studio-gate.cjs`, `scripts/test-verify-studio.cjs`, `docs/studio-compiler.md`
- Test: `scripts/test-verify-studio.cjs` (pure), then the live run

**Interfaces:**
- Consumes: the registered tool, the real `McpHandler` and `RestController` on the dev site (as the existing server commands do), the screen's hand-off box and poll.
- Produces: new gate sections (existing letters stay): **I** the assistant round trip through the REAL MCP handler: `catalog` (size bound), a brief saved by the real admin-ajax save, `open` (hash equals the stored hash), `fill` with words containing `&`, `<b>`, `$5`, quotes and a backslash, the Lab screen (real Chromium, real admin session) reopened with `?brief=<id>` or its poll shows those words in the field values (literal), a second `fill` with the OLD hash is 409 and the stored brief is unchanged, a screen save with a stale hash gets the conflict message and the assistant's words survive, `fill` with `create:true` makes a draft that renders with the words literally (as section E already checks), and a foreign-user brief id is a plain 404 through the tool; **G** gains the hand-off checks that were SKIPPED: the hand-off box is now visible, "Copy instruction" copies a text that names `lab_build` and the brief id (clipboard fallback textarea checked when the clipboard API is refused), so the SKIP count becomes 0 for that check. The gate stays self-cleaning and keeps its "never delete a post that is not the run's own" guard.

- [ ] **Step 1: Write the failing pure tests** in `test-verify-studio.cjs` for the new expectation builders in `studio-gate.cjs` (the words the gate sends and the literal-value comparison; the 409 expectation; the instruction-text check) — run `node scripts/test-verify-studio.cjs`, Expected: FAIL.
- [ ] **Step 2: Implement** the builders and the gate sections; update `docs/studio-compiler.md` (the "Known limits" line about the hidden hand-off and the compare-and-set item move to "built").
- [ ] **Step 3: Run** `node scripts/test-verify-studio.cjs` — Expected: PASS; then `make verify-studio` (dev site only, one gate at a time, about 2 minutes) — Expected: the summary line `N PASS, 0 SKIP, 0 FAIL` (the old clipboard SKIP is gone; if any other check legitimately cannot run, it stays a SKIP with its reason and the report says so).
- [ ] **Step 4: Commit** `Lab: live gate for the assistant round trip and the hand-off`.

---

### Task 8: Tokens, docs, release notes

**Files:**
- Modify: `docs/token-usage.md`, `scripts/measure-tokens.php` (a new measurement; follow how the existing ones are registered), `docs/studio-compiler.md`, `CLAUDE.md` (the "Divi 5 Lab screen" paragraph becomes "Lab screen" with the plan 3 outcome; the tool paragraph; the free tool count stays described as 21 released + `lab_build` unreleased)
- Create: `docs/lab-build-release-notes-draft.md`, `tests/LabBuildDocsTest.php`

**Interfaces:**
- Produces: a measured comparison (bytes and an approximate token count by the repo's existing method) of building the same six-section page as (a) a brief through `lab_build build`, (b) Divi markup through `create_page` (the compiled markup of that brief), and (c) the tool's own overhead (the `tools/list` entry and one `catalog` answer); the numbers go into `docs/token-usage.md`; the release notes draft lists the user-facing wording, the line "the readme's tool count 21 becomes 22 (`readme.txt` lines 99, 260, 317, 372 and the AdminPage comment)", the ChatGPT note (the connector is offered `lab_build`; the legacy Action is not), and the "not confirmed with a real assistant account" caveat.

- [ ] **Step 1: Write the failing test** `LabBuildDocsTest`: the draft file exists, names `lab_build`, says 22, and `docs/token-usage.md` contains the new section heading and no number that disagrees with what `scripts/measure-tokens.php` prints for it.
- [ ] **Step 2: Run** it — Expected: FAIL.
- [ ] **Step 3: Implement** the measurement, the docs and the CLAUDE.md update.
- [ ] **Step 4: Run** the full suite, `node scripts/test-studio-ui.cjs`, `node scripts/test-verify-studio.cjs` — Expected: all PASS.
- [ ] **Step 5: Commit** `Lab: measured token cost, release-notes draft, docs`.
