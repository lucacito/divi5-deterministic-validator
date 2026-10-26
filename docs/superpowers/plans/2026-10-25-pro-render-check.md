# Pro Render Check Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** After every layout save (free write handlers, free creates, Pro write tools), the Pro add-on renders the saved
page in-process. If Divi throws, the page goes back to its exact previous bytes and the answer says what failed:
`render_check: {ok, ms, rolled_back, failure}`. Plus a read-only Pro tool `check_page_render` (MCP + REST only) and a
"Safety net" card on the Pro tab.

**Architecture:** One generic, licence-free free-plugin filter `jhmg_aied_post_write_check`, fired inside
`HistoryService::write()` and `DraftPage::insertChecked()`. The free plugin sanitises the answers
(`ExtensionGuard::postWriteChecks`) and owns the put-back (`PostWriteCheck::putBack`, compare-and-restore of the
in-memory previous bytes, no new snapshot). The Pro add-on owns the render (`InProcessRenderer` behind the
`RenderChecker` seam), the pure policy, budget, locator, hints and report, the listener, the settings, the log and the
tool.

**Tech Stack:** PHP 8 (`wp-plugin/src/`, `pro-addon/src/`), PHPUnit (`php -d memory_limit=-1 vendor/bin/phpunit`, `make
test`, `make test-pro`), a `wp eval-file` live gate plus an HTTP leg against the Docker dev site (Divi 5.15.1).

**Spec:** `docs/superpowers/specs/2026-10-25-pro-render-check-design.md` (D1-D8, Spec issues 1-12). Templates:
`docs/superpowers/plans/2026-10-13-pro-bulk-adjust-prices.md` (Pro tool, REST, MCP_REST_ONLY, live gate) and
`docs/superpowers/plans/2026-10-24-carriers-adapter.md` (rulings / constraints / review focus layout). Code to mirror:
`HistoryService::writeTitle()` (the `wp_insert_post_data` pin), `scripts/sweep-render-fatals.php` (`$render`,
`$wrap`, `$assemble`, the calibration block), `scripts/spike-post-save-render-check.php` (`$summarise` marker rule),
`ExtensionGuard::map()` (built-ins win), `pro-addon/src/Tools/FindReplaceSite.php` (injected `$write` closure),
`pro-addon/src/Tools/BuildSite.php::create()` (delete-on-failure), `pro-addon/src/Rest/ProOpenApi.php::MCP_REST_ONLY`,
`pro-addon/tests/FreeApiCompatTest.php`, `scripts/verify-pro-build-site.php` (gate shape, `scripts/dev-guard.php`).

Branch: the controller's (default `feat/pro-render-check` off local main 6d8ae9a), worktree
`/Users/Lucas/Documents/JHMG-Local/wt-pro-render-check` for implementers. `git merge --no-ff` into LOCAL main at the
end. NO push, release, version bump, zip rebuild, readme.txt edit or SVN (the owner releases).

## Controller rulings (2026-10-25; WHERE THIS SECTION CONFLICTS WITH THE TEXT BELOW, THIS SECTION WINS)

1. **Only a throw rolls back** (plus a recorded `E_USER_ERROR` / `E_RECOVERABLE_ERROR`, and an uncatchable fatal via
   the shutdown put-back). Empty output, warnings, notices, deprecations and "no marker" NEVER roll back (spec D3).
2. **Previous-version control** (D4): on a throw the previous content is rendered in memory. If it throws too, the save
   stays (`reason:"page_already_failed"`).
3. **The put-back is NOT `HistoryService::restore()`** (Spec issue 1). It writes the exact previous bytes (and the
   previous title when the write changed it) with the `writeTitle()` pin, takes no snapshot, and runs only while the
   post still holds the content this write stored.
4. **`HistoryService::write()` keeps its exact signature.** Only return keys are added. The Pro `FreeApiCompatTest`
   list `MAY_GAIN_OPTIONAL_TRAILING` is NOT touched.
5. **No listener = byte-identical.** With `has_filter(PostWriteCheck::HOOK)` false, the free plugin does not call the
   filter, registers no shutdown function, and every answer of every free tool is identical to main. A test pins it
   for all seven write and create paths.
6. **Free wording:** no "Pro", "premium", "licence", "upgrade" anywhere in `wp-plugin/` (`COMMERCIAL_WORDS`). The free
   code says "an add-on" or "a listener".
7. **Theme layouts are skipped** (`skipped:"theme_layout"`, D8). `build_site add_page` calls the Pro checker directly.
8. **`check_page_render` is MCP + REST only**: the 8th `ProOpenApi::MCP_REST_ONLY` entry. The OpenAPI operation count
   stays 30.
9. **Default ON** (`aied_pro_render_check` missing = on); runs only while `Licensing::isPremium()`.
10. **No `Compat::MIN_FREE` change**; Pro feature-detects `class_exists('AiEditorDivi5\\WP\\PostWriteCheck')`.
11. **STOP conditions (live, Task 8):** (a) the HTTP leg shows the clean page's REST-context render has NO Divi module
    marker, or a real-Divi crash that the CLI render catches is NOT caught inside the REST request -> STOP. The render
    context is wrong, and the controller amends the spec before anything ships. (b) A render-only check changes any
    post meta, option (other than the Pro log) or the `et-cache` listing -> STOP. (c) The memory-fatal shutdown leg
    leaves the crashing content stored -> the shutdown put-back is reported as NOT proven (spec section 8 and the
    README say so); it is not a ship blocker, because the catchable case is the proven one.

## Global Constraints

- **The validator is deterministic and untouched.** `git diff main -- src wp-plugin/validator` stays empty. The render
  check is an add-on safety net AFTER the gate. It never replaces or weakens `LayoutGate::check()`, which every write
  path still runs first.
- **WordPress.org compliance.** Nothing licence-gated, remote-fetching or custom-code-saving enters `wp-plugin/`
  (`tests/WpOrgComplianceTest.php`, never weakened). The new free code makes no HTTP request, writes no option and no
  post meta, and adds no tool (the free MCP tool list and the 16 OpenAPI operations are unchanged). An in-process
  render is not a remote request, and the render itself lives in the Pro add-on anyway.
- **Pro extends the free plugin only through `jhmg_aied_*` hooks** and the free classes pinned in
  `pro-addon/tests/FreeApiCompatTest.php`. New pins: `PostWriteCheck::{HOOK, ABORTED, FAILED, ROLLBACK_FAILED}`, the
  `write()` rollback return keys, and `EditTarget::resolve` (already pinned).
- **Every candidate layout is gated before it is rendered** (`check_page_render` with `post_content`): invalid layouts
  and custom code are never executed.
- **No secret, no HTML, no absolute server path in any answer.** Messages are cut at 200 characters, and ABSPATH, the
  theme dir and the plugin dir are stripped.
- **Dev site only for live work**: `scripts/dev-guard.php`; every page the gate creates is titled `aied render-check
  <token> ...` and deleted in a `finally`. The controller cleans leftovers. ONE live gate at a time (other gates share
  the site).
- **Max 3 parallel workers** (owner feedback 2026-10-17).
- Commits end with the trailer from the session's system-reminder (`Co-Authored-By: Claude Opus 5.5
  <noreply@anthropic.com>`).

## Review Focus

Reviewers check, in this order:
1. **False rollbacks:** can any path roll back a page whose render did NOT throw? (A throwing listener, an inconclusive
   result, empty output, a budget skip, page-already-failed, a guard-dropped report: none may roll back.)
2. **Exact put-back:** byte-identical content AND title after a rollback, for a user WITHOUT `unfiltered_html` too. No
   history row for the crashing content. Compare-and-restore honoured.
3. **No listener = identical:** diff the answers of all seven paths with and without the add-on's absence (test, not
   eyeballing).
4. **Global state:** `$GLOBALS['post']`, superglobals, the error handler, ob levels and postdata restored on every exit
   path (throw, return, nested throw inside a `finally`).
5. **Compliance wording and calls** in `wp-plugin/` (`WpOrgComplianceTest` green, no "Pro").
6. **Lockstep:** MCP and REST give the same `render_check` and refusal shape; the Pro tool is on MCP and REST and NOT in
   OpenAPI.

---

### Task 0: Inventory and pins (pure; sonnet; first, short)

**Files:** none changed except a scratch note in the task's report.
**Steps:**
- [ ] List every free test that pins the answer shape of `update_page_layout`, `edit_page_content`, `PUT
  /pages/{id}`, `POST /pages/{id}/edit`, `create_page` (MCP, REST), `html_to_divi create=true`, including lockstep and
  golden tests (`grep -rln "saved' *=>\|'created'" tests`). Task 1 must keep them green unchanged.
- [ ] List every Pro test that pins the tool count (21), `MCP_REST_ONLY` (7 entries) or the tool list (ToolsTest,
  ProParityTest, ProOpenApiTest, ProRestTest, ReadmeTest, ProPageTest). Task 5 updates exactly those.
- [ ] Confirm `UsageTracker::RESULT_INVALID` exists (it does, `UsageTracker.php:27`), that the free test shims have no
  `has_filter` (they do not), and that `add_filter` ignores priority (it does, `tests/bootstrap.php:276`).
- [ ] Record the baseline counts: `make test` and `make test-pro` (expected free about 2,758+, Pro 2169; use the real
  numbers).
**Verify:** the report lists the files and the two baseline counts.
**Commit:** none.

### Task 1: The free hook, the put-back and the create discard (free; opus; parallel with Task 2)

**Files:**
- Create `wp-plugin/src/PostWriteCheck.php` (and `require_once` it where `HistoryService.php` loads its siblings; add it
  to `wp-plugin/src/autoload.php` if classes are listed there).
- Modify `wp-plugin/src/HistoryService.php` (`write()` only), `wp-plugin/src/ExtensionGuard.php` (+`postWriteChecks()`),
  `wp-plugin/src/DraftPage.php` (+`insertChecked()`), `wp-plugin/src/McpHandler.php` (update, edit, create),
  `wp-plugin/src/RestController.php` (update, edit, create), `wp-plugin/src/HtmlToDiviService.php` (create).
- Create `tests/PostWriteCheckTest.php`, `tests/PostWriteCheckHandlersTest.php`; extend `tests/ExtensionHooksTest.php`;
  add a guarded `has_filter` shim to `tests/bootstrap.php`.
- Modify `docs/EXTENDING.md` (hook rows, the contract, the "Built-in tools always win" exception paragraph, a non-Pro
  example).

**Interfaces:** exactly spec section 4 (free part). Reserved keys = the union of every key any built-in write/create
answer can carry (build the list from the handlers; pin it in a test so a new built-in key must be added there).

**Steps:**
- [ ] Write failing tests first (`PostWriteCheckTest`):
  - no listener: `write()` returns exactly `['ok'=>true,'snapshot'=>...]` (plus `title_after` when given); the filter
    was not called; no shutdown function registered (seam: `PostWriteCheck::shutdownRegistered()` for tests).
  - listener `ok:true` -> `checks` present, page content = new.
  - listener `ok:false, rollback:true` -> `ok:false, error:FAILED, rolled_back:true`; stored content === previous bytes;
    title === previous title when `$newTitle` was given; history row count unchanged after the write's own snapshot
    (no second row); `checks.render_check.rolled_back === true` (written by the free plugin).
  - `ok:false` without `rollback:true` -> save kept, `checks` reported.
  - `ok:null` + `rollback:true` -> no rollback (both conditions needed).
  - listener throws -> save kept, `post_write_checks.error === true` in `checks` merge output, no rollback.
  - compare-and-restore: a listener that itself changes the post content (simulating a concurrent save) and then asks
    for a rollback -> `error: ROLLBACK_FAILED, rollback_error:"changed_since"`, the concurrent content stays.
  - `wp_update_post` failing during put-back -> `ROLLBACK_FAILED, rollback_error:"update_failed"`.
  - re-entrancy: a listener that calls `HistoryService::write()` on another page -> the inner write runs no checks.
  - guard (`ExtensionGuard::postWriteChecks`): drops non-array filter results, bad names (`'Bad'`, `'1x'`, 41 chars),
    reserved names (`saved`, `history`, ...), non-array reports, a missing or non-bool/null `ok`; truncates `message`
    to 300 chars after `wp_strip_all_tags` + whitespace collapse; a > 4,096 B report reduced to
    `{ok, rollback, message, note}`.
  - `merge()`: built-in keys win; check keys appended in order.
  - `onShutdown(['type'=>E_ERROR,...])` with pending -> put back and `ABORTED` action fired with `(postId, tool,
    type)`; `onShutdown(null)` with pending -> nothing; `onShutdown(fatal)` without pending -> nothing; the pending
    state is cleared after a normal check.
  - `insertChecked()`: no listener -> same id as `insert()` and no extra keys; rollback request -> post deleted
    (`wp_delete_post` shim records `force=true`), `id:null, rolled_back:true`; not deleted when status changed, content
    changed, or a history row exists (`rollback_error:"changed_since"`).
- [ ] Implement `PostWriteCheck` (static `$pending`, static `$running`, lazily registered shutdown function; the
  put-back reuses a private helper extracted from `writeTitle()`'s pin, or a copy with the same comment; the free
  plugin writes `rolled_back` into every report that asked).
- [ ] `HistoryService::write()`: after `wp_update_post` succeeds, re-read `$storedNew = (string) get_post($pageId)->post_content`,
  call `PostWriteCheck::afterWrite($pageId, $tool, $previousContent, $newTitle !== null ? $previousTitle : null,
  $storedNew)` and map it onto the return (spec section 4). `restore()` and `writeTitle()` are NOT changed.
- [ ] Handlers (`PostWriteCheckHandlersTest`, MCP and REST in lockstep): on success `PostWriteCheck::merge($out,
  $write['checks'] ?? [])` BEFORE `StyleReferences::attach`; on `FAILED` the refusal (MCP `isError`, REST 422; body in
  spec section 3.8); on `ROLLBACK_FAILED` MCP `isError`, REST `WP_REST_Response(..., 500)` with the body; usage log
  `RESULT_INVALID` with 1. Creates use `insertChecked()` with tool names `create_page`, `create_page` (REST) and
  `html_to_divi`; a discarded create answers `created:false, rolled_back:true, render_check, message` (MCP `isError`,
  REST 422, `HtmlToDiviService` status 422).
- [ ] The "no listener = identical" test: run all seven paths through the real handlers with the shims, once with no
  listener on main's behaviour (capture the JSON), assert byte equality with fixtures captured in the test from the
  same inputs (or compare key sets and values against the pre-change expectations already pinned by the existing
  tests listed in Task 0).
- [ ] `ExtensionHooksTest`: the source calls `apply_filters('jhmg_aied_post_write_check'` (in `PostWriteCheck.php`) and
  `do_action('jhmg_aied_post_write_check_aborted'`.
- [ ] `docs/EXTENDING.md`: two rows in the hook table, a "Post-write checks" section (contract, rollback semantics,
  reserved keys, throwing listener, no-listener identity, the shutdown path), the exception paragraph under "Built-in
  tools always win", and the 40-section mu-plugin example.

**Verify:** `php -d memory_limit=-1 vendor/bin/phpunit --filter 'PostWriteCheck|ExtensionHooks|WpOrgCompliance|McpProtocol|HtmlToDiviLockstep|History'`
then `make test` (all green, count = baseline + new). `git diff main -- src wp-plugin/validator` empty.
`grep -rn "\bPro\b" wp-plugin/src/PostWriteCheck.php` empty.
**Commit:** `Free: generic post-write check hook with exact put-back (jhmg_aied_post_write_check)`.

### Task 2: The Pro pure engine (Pro; sonnet; parallel with Task 1)

**Files:** create `pro-addon/src/Render/{RenderChecker,RenderOutcome,RenderPolicy,RenderBudget,BlockLocator,FailureHints,FailureReport}.php`;
register them in `pro-addon/src/autoload.php` if it maps classes; tests
`pro-addon/tests/{RenderPolicyTest,RenderBudgetTest,BlockLocatorTest,FailureHintsTest,FailureReportTest}.php` and a
fake `pro-addon/tests/FakeRenderChecker.php` (queue of outcomes, records `(postId, content)` calls).

**Interfaces:** spec section 4 (Pro part). `BlockLocator::locate()` takes the parsed tree as WordPress's
`parse_blocks()` array shape (`blockName`, `attrs`, `innerBlocks`, `innerHTML`, `innerContent`), a `$throws(string
$markup): bool` closure and a `$serialize(array $blocks): string` closure (in production `serialize_blocks`). It
assembles ancestors with ONE child exactly like the sweep's `$wrap` / `$assemble` (`innerHTML ''`, `innerContent
[null]`). Paths are `/`-joined indexes from the root list (`"0/0/0/1"`).

**Steps:**
- [ ] `RenderPolicyTest`, the table of spec 3.6/3.7: throw + previous clean -> `{ok:false, rollback:true}`; throw +
  previous `''` (create) -> rollback; throw + previous throws -> `{ok:false, rollback:false,
  reason:"page_already_failed"}`; fatal-level diagnostic counts as a throw; warnings only -> `ok:true`; empty HTML ->
  `ok:true, notes:["empty_output"]`; Divi blocks but 0 markers -> `{ok:null, reason:"renderer_inactive"}`; no Divi
  blocks and 0 markers -> `ok:true` (nothing to mark).
- [ ] `RenderBudgetTest` with an injected clock: 20 s and 60 renders; `max_execution_time` 30 with 21 s gone ->
  `"time_budget"`; 0 = no limit.
- [ ] `BlockLocatorTest` on fake trees: exact leaf (depth 5, the sweep's testimonial chain shape), parent interaction (no
  child throws alone), `partial` past 40 renders or 3,000 ms, `none` when the root does not throw alone (context), a
  `divi/placeholder` root, empty tree.
- [ ] `FailureHintsTest`, using the VERBATIM messages from `fixtures/shapes/render-fatals.json` (read the file in the
  test): `ArrayUtility::get_value(): Argument #1 ($array) must be of type array, string given` -> the object-expected
  hint naming the string-typed keys; `Expected a string value, but a array value was given` and `trim()` /
  `ltrim(): Argument #1 ($string) must be of type string, array given` -> the string-expected hint naming the
  object-typed keys; the variant `Expected a string value, but array value was given` (no "a") too; the three module
  methods typed `string` (`AccordionItemModule::is_content_empty`, `LoginModule::render_logged_in_as_content`,
  `PricingTablesItemModule::render_pricing_list`, "must be of type string, array given") -> the string-expected hint;
  any other message -> null. The stored `error` values carry a `<Class>: ` prefix and are cut at 200 characters: strip
  the prefix before matching. The test also asserts every distinct fatal `error` in `render-fatals.json` maps to a hint
  or is listed in a pinned "no hint" list (so a new family is noticed).
- [ ] `FailureReportTest`: path prefixes stripped (`/var/www/html/wp-content/themes/Divi/...` -> `Divi/...`); message
  cut at 200; `value_types` only `<key>.innerContent.desktop.value` types (`string|object|array|null|number|bool`), at
  most 20 keys, sorted; never a value; JSON of the report under 4,096 B for a 200-character message.
**Verify:** `make test-pro` green; `ProSafetyTest` green.
**Commit:** `Pro: render-check pure engine (policy, budget, locator, hints, report)`.

### Task 3: Renderer, listener, settings, log, self-test (Pro; opus; needs Tasks 1 and 2)

**Files:** create `pro-addon/src/Render/{InProcessRenderer,RenderCheckListener,RenderCheckSettings,SafetyNetLog,SelfTest}.php`,
`pro-addon/data/render-selftest-testimonial.txt` (ONE real testimonial block chain copied byte-for-byte from the
`fixtures/valid/*.json` block the sweep's calibration used: `render-fatals.json` `calibration.source` names it); modify
`pro-addon/src/Plugin.php` (wire the listener and settings when `class_exists(PostWriteCheck)`; `check_page_render`
regardless), `pro-addon/src/ProPage.php` (the "Safety net" card), `pro-addon/uninstall.php` (delete the two options);
tests `pro-addon/tests/{RenderCheckListenerTest,RenderCheckSettingsTest,SafetyNetLogTest,SelfTestTest,InProcessRendererShapeTest}.php`;
extend `pro-addon/tests/FreeApiCompatTest.php`.

**Steps:**
- [ ] `InProcessRenderer` exactly per spec 3.5 (clone, superglobals, `setup_postdata`, error handler returning
  `false`, `ob_start` + level restore, `try/catch \Throwable`, `finally` restoring everything in reverse order, `hrtime`,
  marker count). Frames: up to 15 `class::function` + file relative to `WP_CONTENT_DIR`. Unit-test only its shape
  (`InProcessRendererShapeTest`: with shimmed `apply_filters('the_content')` returning HTML, throwing, echoing,
  triggering a warning; assert globals, `$_POST`, the handler and `ob_get_level()` are restored in all four). The real
  behaviour is the live gate's.
- [ ] `RenderCheckListener::__invoke(array $checks, int $postId, string $tool, string $previous): array`: return
  `$checks` untouched when disabled, locked, the post is not `page`/`project` (theme layouts:
  `render_check:{ok:null, skipped:"theme_layout"}`), or the post is missing. Otherwise apply the budget, render the
  STORED content, run the policy, render the previous on a throw, locate on a fail (`parse_blocks` / `serialize_blocks`
  in the closures), build the report, log fails and skips-by-budget, and return `$checks + ['render_check' => report]`
  with `rollback` and a `message` ("Divi threw <Class> in <module> (block <path>): <head>. <hint>").
  `RenderCheckListenerTest` with `FakeRenderChecker` covers every policy row, the post-type skip, disabled, locked,
  budget exhaustion across 3 calls, the locator receiving the stored content, and "the listener never throws" (a
  renderer that throws a non-render exception, e.g. from parse_blocks, gives `ok:null, reason:"check_error"`, no
  rollback).
- [ ] `RenderCheckSettings`: option `aied_pro_render_check` (`on`/`off`, missing = on), admin-post action
  `aied_pro_render_check_save` (nonce + `manage_options`, redirect with notice). `SafetyNetLog`: option
  `aied_pro_render_log`, autoload `false`, ring of 20 `{at, page_id, title, tool, source, ok, rolled_back, module,
  exception, reason}`, "Clear log" action (nonce + `manage_options`). It also listens to
  `jhmg_aied_post_write_check_aborted` (source `shutdown`).
- [ ] `SelfTest`: in memory only, in the context of the newest page the admin can `edit_post`, render the
  self-test block clean and with `company.innerContent.desktop.value` set to a string (the sweep's `$setKey`). It
  answers one of the three outcomes of spec 3.12, plus "no page to test with". The result is logged (source
  `selftest`). Unit-tested with `FakeRenderChecker`.
- [ ] `ProPage` card: switch, last events table (escaped), Clear log, Run self-test, and the line "needs the free
  plugin version with post-write checks" when `PostWriteCheck` is missing. `ProPageTest` assertions for each state.
- [ ] `FreeApiCompatTest`: pin `PostWriteCheck::HOOK === 'jhmg_aied_post_write_check'`, `ABORTED`, `FAILED`,
  `ROLLBACK_FAILED`, and (reflection on a shimmed run) that `HistoryService::write()` returns `error`, `rolled_back`,
  `checks`, `snapshot`, `message` on a rollback.
**Verify:** `make test-pro`, `make test` green; `ProSafetyTest` green (no banned construct: the renderer uses no
`eval`/`exec`/remote call).
**Commit:** `Pro: in-process render check after every layout save, safety-net log, self-test`.

### Task 4: Pro write tools integration (Pro; sonnet; needs Task 3)

**Files:** modify `pro-addon/src/Tools/FindReplaceSite.php`, `pro-addon/src/Tools/BuildSite.php`,
`pro-addon/src/ThemeBuilder/LayoutWriter.php`; tests `FindReplaceSiteTest`, `BuildSiteTest`, `UpdateThemeLayoutTest`
additions.
**Steps:**
- [ ] `FindReplaceSite::apply()`: `$result['error'] === PostWriteCheck::FAILED` -> `skipped[] = row + ['reason' =>
  'render_failed', 'render_check' => $result['checks']['render_check'] ?? null]`; `ROLLBACK_FAILED` -> `reason:
  'render_failed_not_restored'` + the report (no batch item: the content may be the new one; the message tells the
  owner to check that item); a saved item with a `render_check` contributes to `render_checks:{checked, failed,
  skipped}` in the answer. Read the error constants through `class_exists` (older free: absent, no change).
- [ ] `BuildSite::create()`: after `wp_insert_post` and BEFORE `$this->log->append`, if the listener is active, run the
  Pro checker on the new post (previous `''`). On `rollback`, `wp_delete_post($id, true)`, close a fresh build as in
  the existing failure branch, and throw `\InvalidArgumentException` with the report message and "Nothing was
  created". Inject the checker through the constructor (default: the production listener's check method).
- [ ] `LayoutWriter::apply()`: pass `render_check` from `$result['checks']` into its answer when present (the listener
  reports `skipped:"theme_layout"`).
**Verify:** `make test-pro` green.
**Commit:** `Pro: render check in find_replace_site and build_site; layout tools report the skip`.

### Task 5: check_page_render tool, REST, MCP_REST_ONLY (Pro; sonnet; needs Task 2, can run with Task 4 after Task 3's renderer exists)

**Files:** create `pro-addon/src/Tools/CheckPageRender.php`, `pro-addon/tests/CheckPageRenderTest.php`; modify
`pro-addon/src/Tools.php` (22nd tool), `pro-addon/src/Rest/ProOpenApi.php` (`MCP_REST_ONLY` + `'check_page_render'`),
and the count/list pins found in Task 0 (ToolsTest, ProParityTest, ProOpenApiTest, ProRestTest, ProPageTest).
**Steps:**
- [ ] Definition: description at most 300 characters ("Renders up to 10 pages (or one candidate layout, never saved)
  in-process and reports whether Divi throws, where, and why. Read-only."); input schema `page_ids` (array of
  integers, 1-10, unique) or `page_id` (integer) + `post_content` (string); `additionalProperties:false`.
- [ ] `run()`: strict args (`Args`; a non-list, duplicate, more than 10, both forms, or neither -> `InvalidArgumentException`);
  each page through `EditTarget::resolve()` (not found / forbidden -> listed in `not_checked` with the reason, never
  an exception for one bad page in a list); a candidate -> `LayoutGate::check($content, $stored)` first, invalid ->
  `{valid:false, violations, checked:0}`; budget; per page `render_check` (policy with previous = null: a throw is
  `ok:false, rollback:false`); a failure logged with source `check`.
- [ ] Tests: argument matrix, permission per page, gate-first (a fake renderer records NO call for an invalid
  candidate), budget, output shape, read-only (no `wp_update_post`, no batch record: the shims record calls), MCP and
  REST parity (`ProParityTest` pattern), absent from the OpenAPI paths, operation count still 30.
**Verify:** `make test-pro` green.
**Commit:** `Pro: check_page_render (read-only, MCP + REST only)`.

### Task 6: Live gate script (sonnet; needs Tasks 1-5; writing only, the run is Task 8)

**Files:** create `scripts/verify-pro-render-check.php` (wp eval-file, `aied_require_dev_site()`),
`scripts/verify-pro-render-check-http.cjs` (Node, no dependencies beyond the existing scripts' style: `fetch` to the
MCP endpoint with the dev API key, read the same way `scripts/verify-settings-ui.cjs` / the OAuth gate get it), and the
`Makefile` target `verify-pro-render-check` (php leg, then the HTTP leg, then the memory-fatal leg, then cleanup;
`.PHONY` updated). The script installs the production listener with an `unlocked = true` closure (as the other Pro
gates call tools directly, no licence needed) and records the original `aied_pro_render_check` value to restore.
**Legs (each prints PASS/FAIL lines; any FAIL exits non-zero; always cleans up):**
1. **Calibration:** the self-test block chain renders clean in-process, the `company`-string mutation throws
   `ArrayUtility::get_value` TypeError (as `render-fatals.json`), and the clean one is clean again.
2. **Real Divi crash through the real write path:** a throwaway draft with the clean testimonial; then
   `HistoryService::write($id, <company-string content>, 'gate')` directly (no validator by design, Spec issue 2) ->
   `ok:false, error:FAILED, rolled_back:true`; stored content byte-identical to the clean bytes; `post_title`
   unchanged; history rows +1 (the pre-write snapshot) and no row containing the crashing content;
   `failure.module === 'divi/testimonial'`, `exception === 'TypeError'`, `block_path` set, `value_types.company ===
   'string'`, a hint; one log entry.
3. **Same with a title change:** `write($id, crash, 'gate', 'New title')` -> title back to the old one.
4. **A user without `unfiltered_html`** (throwaway author, deleted at the end): the same leg 2 -> byte-identical
   put-back (the kses pin works).
5. **Full handler path, MCP and REST in-process:** register a gate-only `the_content` filter that throws
   `\RuntimeException('aied-gate-throw')` when the content contains `AIED-RC-THROW` (a text module value the validator
   accepts). `update_page_layout`, `edit_page_content`, `PUT /pages/{id}`, `POST /pages/{id}/edit`: refusal shapes of
   spec 3.8, content unchanged, MCP and REST bodies equal apart from transport fields. A clean save: `render_check.ok
   === true`, `ms` > 0.
6. **Page already failed:** seed the page with the crashing content by `wp_update_post` (bypassing everything), then
   `write()` another crashing variant -> saved, `rollback:false`, `reason:"page_already_failed"`; then a clean write ->
   saved, `ok:true`.
7. **Creates:** `create_page` (MCP, REST) and `html_to_divi create=true` with `AIED-RC-THROW` -> `created:false,
   rolled_back:true`, and no post with that title exists afterwards.
8. **Pro tools:** `find_replace_site` over two throwaway pages, one becoming `AIED-RC-THROW` -> that one is skipped
   `render_failed`, the other saved, the batch has one item; `build_site add_page` with the marker -> refused, no page,
   no batch item; `update_theme_layout` dry run plus a real no-op-safe call on a throwaway layout ONLY if the theme
   gates' fixture pattern allows it, otherwise assert the listener's `theme_layout` skip by calling it directly.
9. **check_page_render:** on the clean page (`ok:true`), on the seeded crashing page (`ok:false`, located), on a
   candidate (in memory: `post_modified` unchanged), on an invalid candidate (no render), with 11 ids (refused).
10. **Side effects:** for a render-only `check_page_render` on a real recipe-built page: every post meta of the page,
    the options table (name + value hash, excluding `aied_pro_render_log`, transients and `cron`) and the
    `wp-content/et-cache` listing (names + sizes) are identical before and after. Any difference -> FAIL (STOP ruling 11b).
11. **Switch:** `aied_pro_render_check = off` -> no `render_check` key, a crashing `write()` stays saved (then cleaned).
12. **Cost:** every one of the 71 recipes (`SectionRecipes::names()`) inserted as ONE throwaway draft each (resolved by
    the real pipeline), rendered by `check_page_render` in batches of 10: all `ok:true` (any `ok:false` on a shipped
    recipe is a FAIL), and ms min/median/p95/max plus peak memory are printed for the evidence doc.
13. **HTTP leg** (`verify-pro-render-check-http.cjs`, real HTTP to `/wp-json/ai-editor-divi5/v1/mcp` and REST): the
    clean page's `update_page_layout` returns `render_check.ok === true` with a marker-backed pass (not
    `renderer_inactive`). The testimonial crash cannot go through the write handlers (the validator refuses it; plan
    Spec issue 2), so the php leg PUBLISHES one throwaway page with the crashing content seeded by `wp_update_post`,
    and the HTTP leg calls `check_page_render` on it over MCP -> `ok:false`, TypeError, module testimonial (proving the
    throw is caught inside a real REST request); then a `curl` of the published page from the host -> HTTP 500 (the
    sweep's equivalence), and of the clean page -> 200. STOP ruling 11a applies.
14. **Memory-fatal leg** (a separate `wp eval-file` process with `-d memory_limit=64M`): a gate-only `the_content`
    filter that allocates until exhaustion when it sees `AIED-RC-OOM`, then `write()` with that content. The process
    dies; a second process asserts the stored content is the previous bytes and that the log has a `shutdown` entry.
    The result is recorded as PROVEN or NOT PROVEN (ruling 11c).
15. **Cleanup:** every `aied render-check ` page, the throwaway user, the gate filters, the option restored, the log
    restored to its pre-run value; a leftover count of 0 is printed.
**Verify (offline):** `php -l` on the script; the Node script's pure helpers (argument parsing, result tally) in
`scripts/test-verify-pro-render-check.cjs` (`node scripts/test-verify-pro-render-check.cjs` green).
**Commit:** `Gate: make verify-pro-render-check (self-cleaning, dev site only)`.

### Task 7: Docs (sonnet; after Tasks 1-6 merge)

**Files:** `CLAUDE.md` (a "Render check (Pro, unreleased)" paragraph; the Pro tool count 21 -> 22 and the 8
`MCP_REST_ONLY` entries; the free hook in the Extension hooks sentence; the `make verify-pro-render-check` row in Entry
points), `pro-addon/README.md` (section "Render check and check_page_render": what, defaults, limits, the release note
"raise MIN_FREE only if you want to require the hook"), `pro-addon/readme.txt` ONLY if `ReadmeTest` requires the tool
list there (otherwise untouched), spec Outcome section (filled by the controller after Task 8), and
`docs/render-check-evidence-5.15.1.md` (from the gate output: calibration, the legs, cost table, the HTTP equivalence,
the memory-fatal verdict).
**Verify:** `make test`, `make test-pro` green (ReadmeTest, WpOrgComplianceTest).
**Commit:** `Docs: render check (CLAUDE.md, Pro README, evidence)`.

### Task 8: Live gates and whole-branch review (controller; live, serial; not an implementer task)

- [ ] `make up`; the free and Pro plugins active on the dev site; NO other gate running.
- [ ] `make verify-pro-render-check` -> all PASS (STOP rulings 11a/11b), 0 leftovers.
- [ ] Re-run the gates the change touches: `make verify-history`, `make verify-dry-run`, `make verify-page-title`
  (title pin and put-back share code), `make verify-html-to-divi` (creates), `make verify-pro-site-tools`, `make
  verify-pro-build-site`, `make verify-pro-rest` (operation count 30).
- [ ] Plugin Check on the free dev copy (no new errors).
- [ ] Whole-branch review with the Review Focus list; fix rounds; the spec Outcome (numbers, the memory-fatal verdict,
  the cost table, known limits).
- [ ] `git merge --no-ff` into LOCAL main. No push, no release.

## Parallelism map

```
Task 0 (inventory, short)
   |--> Task 1 (free hook)        [worker A, opus]   } in parallel (max 3 workers)
   |--> Task 2 (Pro pure engine)  [worker B, sonnet] }
            \__________________/
                     |
                Task 3 (renderer, listener, settings, log, self-test) [worker A, opus]
                     |
          +----------+-----------+
          |                      |
   Task 4 (Pro write tools)   Task 5 (check_page_render)   [workers B and C, sonnet; separate worktrees off the Task 3 head]
          +----------+-----------+
                     |
                Task 6 (gate script; may start when Task 3 lands, finish after 4 and 5)
                     |
                Task 7 (docs)  ->  Task 8 (controller: live gates, review, merge)
```

Task 1 and Task 2 touch disjoint trees (`wp-plugin/`, `tests/` vs `pro-addon/`). Tasks 4 and 5 touch disjoint Pro
files except `Tools.php` / the count pins (Task 5 only). After each merge the controller re-runs `make test` and `make
test-pro`.

## Spec issues found

The spec's "Spec issues found" (1-12) apply. Plan-level additions:
1. **The brief's gate idea "a direct `wp_update_post` to simulate a shape the validator does not know" cannot test the
   hook** (it never reaches `HistoryService::write()`). Followed: legs 2-4 call `write()` directly, leg 6 uses
   `wp_update_post` only to seed, and leg 5 uses a gate-only throwing `the_content` filter for the full handler path.
2. **The real-Divi crash cannot go over HTTP through the write handlers**, because the validator now refuses
   `company` as a string (`VerifiedContentTypes`). Followed: the HTTP leg proves REST-context detection with the
   read-only `check_page_render` on a seeded page, plus the host `curl` 500/200 equivalence.
3. **`ProTool::run` exceptions other than `InvalidArgumentException` become a generic 500** (`ToolRunner.php`). So a
   `build_site` render refusal must be an `InvalidArgumentException` carrying the report text, not a
   `RuntimeException`.
4. **`find_replace_site` today maps every failed `write()` to `save_failed`** (`FindReplaceSite.php:224`). Task 4
   distinguishes the two new errors; an older free plugin never produces them, so the old mapping stays as the
   fallback.
5. **The Pro gates run tools without a licence by calling them directly** (`verify-pro-build-site.php` header). The
   listener's licence check is therefore bypassed in the gate through its injected `unlocked` closure. The licence gate
   itself is unit-tested (Task 3), not live.
