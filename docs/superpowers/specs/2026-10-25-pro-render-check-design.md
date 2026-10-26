# Pro render check: render after every layout save, put the page back if Divi crashes (design)

Date: 2026-10-25. Status: design, not built. Plan: `docs/superpowers/plans/2026-10-25-pro-render-check.md`.
Owner decision (2026-10-25): revenue first. The Pro add-on needs features competitors do not have. This one backs the
product's main promise ("an AI assistant can never save a broken Divi layout") on the one failure class the
deterministic validator cannot fully foresee: content that makes Divi throw while it renders.

## 1. Problem and evidence

- `docs/render-fatal-sweep.md` / `fixtures/shapes/render-fatals.json` (Divi 5.15.1, 2026-10-25): 92 wrong-typed
  `<key>.innerContent.desktop.value` values make Divi throw while it renders. On the front end that is an HTTP 500 for
  the WHOLE page. In-process verdicts matched real HTTP in 9 of 9 checks. The validator now guards the 80 proven keys
  (`src/VerifiedContentTypes.php`), but the sweep covered only `desktop.value` of `innerContent` keys. It did not cover
  tablet/phone/hover/sticky values, `decoration`/`advanced` attributes, presets, dynamic content, modules with no real
  block, or Divi releases after 5.15.1. The schema cannot list every crash.
- `docs/post-save-render-check-evidence-5.14.md` (spike, backlog 2e, 2026-10-06): an in-process render
  (`apply_filters('the_content', ...)` in the post's context) works for drafts with no network and no login. It took
  6-90 ms for a small page and 2.1 s for the largest real page (1,073 modules). A loopback HTTP fetch is unreliable
  (it failed in Docker, and drafts give 404 without a login). An "empty module" heuristic has many false positives
  (993 empty images in a Divi loop, plus dividers, maps and post navigation that are empty by design). Divi's
  exceptions can be caught in-process. A real PHP fatal (memory, time) cannot.
- The spike recommended against building a render check in 2026-10, because its value over the gate was small. The
  sweep changes that: 92 proven whole-page crashes, about 59 of them in a direction nothing guarded until 2026-10-25,
  and every new Divi release can add more.

## 2. What the feature does

After a layout write is saved, the Pro add-on renders the page in-process with exactly the content that is now
stored. If Divi throws, the page goes back to the bytes it held before the write, and the answer says what failed
(module, block path, exception class, the start of the message, and a hint). A page that crashes Divi's renderer does
not stay saved, unless the page already crashed before the write (section 5.3).

Plus one read-only Pro tool, `check_page_render`. It renders up to 10 existing pages, or one candidate layout in
memory, and reports the same way. Use cases: "check every page after a Divi update", and "will this layout crash?"
before saving.

Not built: a dry-run render inside the free `dry_run` previews (that would need a second free hook; `check_page_render`
with `post_content` covers the use), a render check for Theme Builder layouts, presets, global colours/variables or
custom fields (section 9), screenshots, and browser or JavaScript checks.

## 3. Architecture

```
free HistoryService::write()            (the one write path: the free handlers and the Pro tools)
   snapshot -> wp_update_post -> PostWriteCheck::afterWrite()  --apply_filters('jhmg_aied_post_write_check')-->  Pro RenderCheckListener
                                     |  sanitised by ExtensionGuard::postWriteChecks()                               |  InProcessRenderer (seam: RenderChecker)
                                     |  a check that asks for rollback -> PostWriteCheck::putBack()                  |  RenderPolicy / BlockLocator / FailureReport (pure)
                                     v                                                                                |  SafetyNetLog (Pro option)
   write() returns ok:false error=post_write_check_failed  ->  handlers answer saved:false, rolled_back:true, render_check:{...}
free DraftPage::insertChecked()       (create_page MCP/REST, html_to_divi create=true): the same hook, previous content ''; a failure discards the new draft
Pro BuildSite add_page                (inserts its own drafts): calls the Pro checker directly
Pro check_page_render                 (read-only tool, MCP + REST only)
```

### 3.1 The free-plugin hook (Decision D1)

One new generic, licence-free filter in the free plugin, fired by a new pure-ish class `PostWriteCheck`:

```php
/**
 * @param array<string,array> $checks         name => report, starts empty
 * @param int                 $postId         the page or Project just written
 * @param string              $tool           the write's tool name (update_page_layout, edit_page, find_replace_site, create_page, ...)
 * @param string              $previousContent the post_content before the write ('' for a create)
 */
apply_filters( 'jhmg_aied_post_write_check', [], $postId, $tool, $previousContent );
```

The contract (documented in `docs/EXTENDING.md`, enforced by `ExtensionGuard::postWriteChecks()`):
- Each entry is `name => report`. `name` matches `/^[a-z][a-z0-9_]{1,39}$/` and is not a reserved response key
  (`saved valid violations history page title message replaced created layout advisories rolled_back rollback_error
  matches layout_changed converted report next layout_omitted` and every key a built-in answer already carries:
  built-in keys always win). `report` is an array whose `ok` is `true`, `false` or `null`. An entry that breaks any of
  these rules is dropped.
- An entry asks for a rollback only with `rollback === true` AND `ok === false`. `message` (optional) is a string; the
  free plugin strips tags, collapses whitespace and cuts it to 300 characters. A report whose JSON is over 4,096 bytes
  is reduced to `{ok, rollback, message, note:"report too large"}`.
- If the listener throws, the free plugin catches the `Throwable` and keeps the save. Nothing is put back, and the
  answer carries `post_write_checks: {error:true}`. A bug in an add-on never undoes a good save.
- The add-on can never supply content. A rollback puts back the exact bytes (and title) the page held before this
  write. Nothing else can happen.
- With no listener registered (`has_filter()` false) the free plugin does not call the filter, registers no shutdown
  function, and every answer is byte-identical to today's. This is pinned by a test.

Why the hook sits inside `HistoryService::write()` and not in the handlers: every layout write already goes through
`write()`. That is the free plugin's four write handlers plus the Pro tools `find_replace_site`, `update_theme_layout`
and `edit_theme_layout` (`FindReplaceSite.php:205`, `LayoutWriter.php:75`). One hook covers all of them, and a future
Pro write tool is covered by default. `write()` keeps its signature (no new parameter), so the Pro
`FreeApiCompatTest`'s "only three methods may gain optional parameters" rule is not touched. Only the return shape
gains keys.

This is a deliberate, narrow exception to "Built-in tools always win" (`docs/EXTENDING.md`): an add-on can make a
built-in write stricter (have it undone to what the person already had), but it can never change what is written,
override a response key, or intercept the call. EXTENDING.md gets a paragraph saying exactly this, plus a non-Pro
example: a site's own mu-plugin that refuses layouts with more than 40 sections.

### 3.2 Rollback (`PostWriteCheck::putBack`, free) (Decision D2)

- Compare-and-restore: right after `wp_update_post`, `write()` re-reads the stored content `$storedNew`. The put-back
  runs only while the post still holds `$storedNew` (re-read just before). Otherwise it answers
  `rollback_error: "changed_since"` and nothing is overwritten, because a concurrent save wins.
- Exact bytes: `wp_update_post(['ID', 'post_content' => previous, 'post_title' => previousTitle when this write changed
  the title])` with the same `wp_insert_post_data` pin `writeTitle()` uses. The stored previous bytes are put back
  unfiltered, so kses for a user without `unfiltered_html` cannot rewrite them. This is not new input, so no security
  filter is bypassed (the same reasoning as `writeTitle()`, verified on Divi 5.14).
- No new snapshot. The pre-write snapshot already holds the previous content. The crashing content is never written to
  history, so nobody can restore it by mistake. `history` in the answer is the pre-write snapshot unchanged
  (`stored` true/false as before; `version_id` points at content equal to the current page).
- Independent of history: the previous bytes are in memory, so a write whose snapshot was skipped (`too_large`,
  `store_failed`, `duplicate`) still rolls back.
- No recursion: the put-back calls `wp_update_post`, not `write()`. A static re-entrancy flag in `PostWriteCheck`
  makes a `write()` reached from inside a listener skip the checks.
- An uncatchable fatal during the check: `PostWriteCheck` registers ONE shutdown function per request, lazily, the
  first time a check runs with a listener present. While a check is in flight a static `$pending` holds
  `(postId, previous bytes, previous title, storedNew)`. If the request ends with `$pending` set and `error_get_last()`
  is a fatal type (`E_ERROR`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_PARSE`, `E_RECOVERABLE_ERROR`), the shutdown
  function cleans the output buffers above its level, calls `wp_raise_memory_limit('admin')` and runs the same
  compare-and-restore put-back. It then fires `do_action('jhmg_aied_post_write_check_aborted', $postId, $tool,
  $errorType)` so the add-on can log it. The client gets WordPress's own fatal-error answer. The page is back to its
  previous bytes, and the AI sees that with `get_page_layout`. A request that ends by `exit` with no fatal is not put
  back (it is ambiguous; see section 8). A process the host kills (OOM killer, SIGKILL) runs no shutdown function: an
  honest limit.

### 3.3 Creates (`DraftPage::insertChecked`, free)

`create_page` (MCP, REST) and `html_to_divi` with `create=true` insert a new draft through `DraftPage::insert()`. A new
`DraftPage::insertChecked($title, $content, $slug, $tool)` inserts, runs the same hook with previous content `''`, and
on a rollback request DISCARDS the draft. Discarding means `wp_delete_post($id, true)`, and only while the post still
exists, is a `page`, is a `draft`, holds exactly the content stored at insert, and has no history rows. Otherwise it
answers `rollback_error: "changed_since"` and keeps the post. The answer is `created:false, rolled_back:true,
render_check:{...}`. A permanent delete (not the trash), because a trashed crashing draft could be untrashed. The
draft existed for well under a few seconds, had no history and was never published. `DraftPage::insert()` stays
unchanged (Pro does not call it).

### 3.4 The Pro engine

| Unit | Kind | Job |
|---|---|---|
| `Render\RenderChecker` | interface (seam) | `render(\WP_Post $post, string $content): RenderOutcome` |
| `Render\InProcessRenderer` | WP (live-gated) | the ONLY Pro class that calls `apply_filters('the_content')`, `setup_postdata`, `set_error_handler`, `ob_*` |
| `Render\RenderOutcome` | value | threw (class, message, reduced trace), fatal-level diagnostics, warning/notice/deprecation counts, html bytes, Divi module-marker count, ms, memory |
| `Render\RenderPolicy` | pure | outcome(s) to verdict: pass / fail-rollback / fail-no-rollback / inconclusive |
| `Render\RenderBudget` | pure (clock injected) | per-request time and count budget |
| `Render\BlockLocator` | pure (render and serialise closures injected) | finds the smallest failing block (the sweep's ancestors-with-one-child technique) |
| `Render\FailureHints` | pure | maps proven exception messages to plain-English hints (the sweep's two directions) |
| `Render\FailureReport` | pure | builds the `render_check` report: strips server paths, caps text, value-type map of the failing block |
| `Render\RenderCheckListener` | WP glue | the `jhmg_aied_post_write_check` listener: enabled? unlocked? post type? budget? render, policy, locate, report, log |
| `Render\RenderCheckSettings` | WP glue | Pro option `aied_pro_render_check` (`on`/`off`, missing = on), admin-post save with nonce + `manage_options` |
| `Render\SafetyNetLog` | WP glue | Pro option `aied_pro_render_log` (autoload off): the last 20 events, shown on the Pro tab |
| `Tools\CheckPageRender` | ProTool | `check_page_render` (read-only) |

### 3.5 How the saved page is rendered (InProcessRenderer)

The sweep's technique (`scripts/sweep-render-fatals.php`, `$render`), which was calibrated against HTTP:
1. `$clone = clone $post; $clone->post_content = $content;` (in memory; the stored post is never touched by a render).
2. Save `$GLOBALS['post']`, `$_POST`, `$_REQUEST`, `$_FILES`, then empty the three superglobals. A contact-form or
   login module can then never see a submission during the render. Restore all of them in `finally`.
3. `$GLOBALS['post'] = $clone; setup_postdata($clone);` The main query (`$wp_query`, `$wp_the_query`) is NOT touched:
   no `query_posts`.
4. `set_error_handler` that counts by type and keeps the first 5 messages (each 200 characters, paths stripped).
   `E_USER_ERROR` and `E_RECOVERABLE_ERROR` are recorded as fatal-level. The handler returns `false`, so PHP's normal
   logging still happens (the site's error log is not silenced). `restore_error_handler()` runs in `finally`.
5. `$level = ob_get_level(); ob_start();` then `apply_filters('the_content', $content)` inside
   `try { } catch (\Throwable)`. In `finally`, every buffer above `$level` is closed and discarded.
6. `wp_reset_postdata()`; restore `$GLOBALS['post']`.
7. Record ms (`hrtime`) and the count of Divi module markers in the HTML (`et_pb_module` class or `et_pb_<slug>_<n>`,
   the spike's `$summarise` rule).

The exact content rendered after a save is the STORED content (`get_post($id)->post_content`, re-read), not the
request. Core's kses may have changed it, and the stored content is what visitors get.

Only the post content is rendered. The Theme Builder header, footer and body template around it, `wp_head` and
`wp_footer` style output, and the Visual Builder are not part of the render (section 9).

### 3.6 What counts as a failure (Decision D3)

| Outcome of rendering the new stored content | Verdict | Rollback |
|---|---|---|
| `Throwable` | fail | yes, unless the previous content also throws (5.3) |
| a recorded `E_USER_ERROR` / `E_RECOVERABLE_ERROR` | fail | as above |
| an uncatchable fatal | (the request dies) | yes, by the free shutdown put-back |
| warnings, notices, deprecations | pass, counts reported | never |
| empty or near-empty HTML | `ok:true` with `notes:["empty_output"]` | never |
| the content has Divi blocks, no `Throwable`, but the HTML has NO Divi module marker | inconclusive `ok:null, reason:"renderer_inactive"` | never |
| not rendered (budget, too large, post type, disabled) | `ok:null, skipped:<reason>` (no key at all when disabled) | never |

Only a throw is a proven whole-page crash (the sweep: in-process throw = HTTP 500 in 9 of 9). Empty output is NOT a
failure. It is the spike's main false-positive class: loops with no data, by-design empty modules, and display
conditions that hide modules from the API user. Rolling back a good page would be worse than missing an empty one.

### 3.7 Rollback policy (RenderPolicy) (Decision D4)

1. Render the new stored content. If there is no throw, the verdict is pass (or inconclusive / notes, as above).
2. On a throw, render the PREVIOUS content in memory (same post, same technique).
   - Previous is clean (or `''`): `ok:false, rollback:true`. The write caused the crash.
   - Previous ALSO throws: `ok:false, rollback:false, reason:"page_already_failed"`. Rolling back would bring back a page
     that crashes too, and a throw that hits both versions may be environmental (another plugin's `the_content`
     filter, the render context). The save is kept, and the answer says the page crashed before and after the write.
3. Locate (only on a fail): `BlockLocator` walks the parsed tree depth-first. At each level it renders
   ancestors + one child (with its own subtree) and descends into the first child that throws alone. It stops at the
   deepest block that throws alone. If no child throws alone but the parent does, it reports the parent (an
   interaction). Bounds: at most 40 renders and 3 s. Past a bound it reports the deepest failing block found so far
   with `located:"partial"`.

### 3.8 Report shape (every write tool, MCP and REST)

```json
"render_check": {
  "ok": false, "ms": 41, "rolled_back": true,
  "failure": {
    "exception": "TypeError",
    "message": "ArrayUtility::get_value(): Argument #1 ($array) must be of type array, string given",
    "module": "divi/testimonial", "block_path": "0/0/0/0/1", "located": "exact",
    "value_types": { "company": "string", "author": "object", "content": "string" },
    "hint": "A content value Divi reads as an object ({...}) is a plain string. Check value_types: company."
  },
  "diagnostics": { "warnings": 0, "notices": 0, "deprecations": 0 },
  "divi_version": "5.15.1"
}
```

- `rolled_back` is written by the free plugin into every report that asked for a rollback (the truth about what
  happened). The add-on never sets it.
- `message` is at most 200 characters. Absolute paths (ABSPATH, the theme and plugin dirs) are stripped and no HTML is
  included. `value_types` covers the failing block's `<key>.innerContent.desktop.value` types only (at most 20 keys,
  types only, never values).
- A refusal: MCP `isError:true` with `{saved:false, valid:true, rolled_back:true, history, page, render_check,
  message}`. REST 422 with the same body. For a create: `{created:false, rolled_back:true, render_check, message}`.
  The free plugin builds the message: "The layout was saved, then failed a check after saving (render_check), and the
  page was put back exactly as it was. Nothing changed." followed by the report's `message`.
- A failed rollback (`changed_since`, `update_failed`): MCP `isError:true`, REST 500 with a body:
  `{saved:true, rolled_back:false, rollback_error, render_check, history, message: "... the page may be broken; check
  it with get_page_layout and use restore_page_version <version_id>."}`.
- The free plugin's usage log records a refusal as `invalid` with 1 violation (the existing `RESULT_INVALID`).
- Pro tools: `find_replace_site` lists a refused item under `skipped` with `reason:"render_failed"` and its
  `render_check`, and its answer gains `render_checks:{checked, failed, skipped}`. `build_site` `add_page` deletes the
  page it just inserted (its existing failure pattern) and refuses with the report text. The header/footer tools
  return `render_check:{ok:null, skipped:"theme_layout"}`.

### 3.9 Budget (RenderBudget)

Per request: at most 20 s of render time and 60 renders in total (locating included). Before each render the budget
also requires 10 s or more left of `max_execution_time` when that limit is non-zero (`ini_get`; request start from
`$_SERVER['REQUEST_TIME_FLOAT']`). Past the budget a check answers `ok:null, skipped:"time_budget"` and the save stays.
A 50-item `find_replace_site` on small pages fits easily (spike: 6-90 ms per page). On huge pages it degrades
honestly, never silently. Content over `CodePolicy`'s size cap is `skipped:"too_large"`.

### 3.10 Which writes are checked

| Write | Checked | How |
|---|---|---|
| free `update_page_layout` / `PUT /pages/{id}` with content (title too) | yes | `write()` hook; the title is put back too |
| free `edit_page_content` / `POST /pages/{id}/edit` | yes | `write()` hook |
| free title-only `update_page_layout` / `PUT` | no | the layout is not touched (`writeTitle()`) |
| free `create_page`, `html_to_divi create=true` | yes | `DraftPage::insertChecked()`; a failure discards the draft |
| free `restore_page_version`, admin Restore, Pro `undo_batch` | no | restore brings back the person's own content by design; checking it would loop |
| Pro `find_replace_site` (pages) | yes | `write()` hook |
| Pro `find_replace_site` (layouts), `update_theme_layout`, `edit_theme_layout` | skipped | rendering a Theme Builder layout through `the_content` is unproven (section 9) |
| Pro `build_site` `add_page` | yes | direct call to the Pro checker |
| Pro `publish_page`, `build_site launch` | no | status only; the content was checked when written |
| Pro presets/colours/variables/custom fields | no | not layout writes (section 9) |

### 3.11 check_page_render (Pro tool 22, read-only, MCP + REST only)

- Capability `edit_pages`; each page through the free `EditTarget::resolve()` (pages and Divi Projects the user may
  edit).
- Arguments: `page_ids` (1-10 distinct positive ints) XOR `page_id` + `post_content` (one candidate, rendered in
  memory, never saved; it must pass `LayoutGate::check($content, $stored)` first, otherwise the answer is
  `valid:false` + violations and nothing is rendered, because invalid or custom code is never executed).
- Answer: `{checked, pages:[{page_id, title, render_check}], not_checked:[{page_id, reason}], divi_version,
  note}`. The same budget, the same report, the previous-version control is replaced by "the stored content" for a
  candidate, and there is never a rollback.
- No batch, no option write except the safety-net log entry for a failure (`source:"check"`).
- `ProOpenApi::MCP_REST_ONLY` gains it (8 entries). The ChatGPT importer's 30 operations stay at 30.

### 3.12 Settings and visibility

- A "Safety net" card on the Pro tab (`ProPage`): an on/off switch (default ON while the licence is unlocked), the last
  20 events (time, page, tool, module, exception, rolled back yes/no) with "Clear log", and a "Run self-test" button.
- The self-test renders, in memory and in the context of the newest page the admin can edit, a real testimonial block
  (copied from `fixtures/valid/`, the sweep's calibration block) clean, then with `company` as a string. It shows
  "Safety net works on this site (Divi X)" when the clean render passes and the bad one throws. It shows "This Divi
  version no longer crashes on the test case" when both pass (not an error). It shows "The safety net cannot see Divi
  errors on this site (another plugin catches them)" when the clean one passes, the bad one does not throw and no marker
  is missing. The result is stored in the log. Nothing is saved to any post.
- Licence: the listener and the tool run only while `Licensing::isPremium()`. An expired licence keeps Pro tools
  working by policy (`ExpiryKeepsAllToolsTest`), so the safety net keeps working too.
- An older free plugin without `PostWriteCheck`: the Pro tools work as before, the card says "needs the free plugin
  version with post-write checks", and `check_page_render` still works (it needs no free hook).

## 4. Interfaces (exact)

Free (`wp-plugin/src/PostWriteCheck.php`, new; no word "Pro", no licence, no remote call):
```php
final class PostWriteCheck {
    public const HOOK    = 'jhmg_aied_post_write_check';
    public const ABORTED = 'jhmg_aied_post_write_check_aborted';
    public const FAILED  = 'post_write_check_failed';          // write()['error'] after a rollback
    public const ROLLBACK_FAILED = 'post_write_check_rollback_failed';
    /** @return array{checks:array<string,array>, rollback:bool, rolled_back:bool, rollback_error:?string, listener_error:bool} */
    public static function afterWrite( int $postId, string $tool, string $previousContent, ?string $previousTitle, string $storedNew ): array;
    /** @return array{ok:bool, error:?string} */
    public static function putBack( int $postId, string $previousContent, ?string $previousTitle, string $storedNew ): array;
    /** @param array<string,mixed> $out the built-in answer @return array<string,mixed> built-in keys win */
    public static function merge( array $out, array $checks ): array;
    public static function refusalMessage( array $checks ): string;
    /** Test seam for the shutdown path. */
    public static function onShutdown( ?array $lastError ): void;
}
ExtensionGuard::postWriteChecks( mixed $filtered, array $reservedKeys ): array   // sanitised name => report
DraftPage::insertChecked( string $title, string $content, string $slug, string $tool ): array  // {id:?int, error:?\WP_Error, checks, rolled_back, rollback_error}
```
`HistoryService::write()` keeps its signature. On success its return gains `checks` (only when non-empty). On a
rollback it returns `['ok' => false, 'error' => PostWriteCheck::FAILED, 'message', 'snapshot', 'checks',
'rolled_back' => true]`. On a failed put-back it returns `['ok' => false, 'error' => PostWriteCheck::ROLLBACK_FAILED,
..., 'rolled_back' => false, 'rollback_error']`.

Pro (`pro-addon/src/Render/`):
```php
interface RenderChecker { public function render( \WP_Post $post, string $content ): RenderOutcome; }
final class RenderOutcome { public function __construct( public readonly ?string $exception, public readonly ?string $message, public readonly array $frames, public readonly int $fatalLevel, public readonly array $counts, public readonly int $bytes, public readonly int $markers, public readonly int $ms, public readonly array $samples ) {} public function threw(): bool; }
final class RenderPolicy { public static function verdict( RenderOutcome $new, ?RenderOutcome $previous, bool $contentHasDiviBlocks ): array /* {ok:?bool, rollback:bool, reason:?string, notes:list<string>} */; }
final class RenderBudget { public function __construct( \Closure $clock, float $requestStart, int $maxExecution, float $budgetSeconds = 20.0, int $maxRenders = 60 ); public function allows(): ?string /* null or skip reason */; public function spend( int $ms ): void; }
final class BlockLocator { public static function locate( array $tree, \Closure $throws, \Closure $serialize, int $maxRenders = 40, int $maxMs = 3000, ?\Closure $clock = null ): array /* {path:?string, block:?string, attrs:array, located:'exact'|'parent'|'partial'|'none', renders:int} */; }
final class FailureHints { public static function hint( string $exception, string $message, array $valueTypes ): ?string; }
final class FailureReport { public static function build( RenderOutcome $o, array $verdict, array $location, string $diviVersion, array $pathPrefixes ): array; public static function valueTypes( array $attrs ): array; }
```

## 5. Decisions

- **D1 the hook:** a generic veto filter inside `HistoryService::write()` plus the same hook in
  `DraftPage::insertChecked()`. The free plugin owns the sanitising and the put-back. The add-on only reports and asks.
- **D2 the rollback:** an in-memory compare-and-restore of the exact previous bytes and title, with no snapshot of the
  crashing content. Not `HistoryService::restore()` (Spec issue 1).
- **D3 failures:** a throw (and the two recoverable fatal levels) only. Empty output and warnings never roll back.
- **D4 control render:** the previous content is rendered on a failure. "Page already failed" keeps the save.
- **D5 default:** ON for Pro. Typical cost is tens of ms per save (spike: 6-90 ms), and the guarantee is the product.
- **D6 tool:** `check_page_render` ships (read-only, MCP + REST only). It makes the engine visible and covers "after a
  Divi update".
- **D7 compatibility:** Pro feature-detects `class_exists(PostWriteCheck::class)`. `Compat::MIN_FREE` is NOT raised,
  because raising it would switch off all 21 Pro tools for sites on an older free version.
- **D8 theme layouts:** skipped in v1 with an honest `skipped:"theme_layout"`. A spike is a follow-up.

## 6. Proof plan

- Free unit tests (`tests/PostWriteCheckTest.php`, `tests/ExtensionHooksTest.php` additions): no listener = byte-identical
  `write()` and handler answers; a rollback request puts back exact bytes and title and adds no history row; a throwing
  listener keeps the save; the guard drops bad names, reserved keys, non-array reports and missing `ok`, and reduces an
  oversized report; compare-and-restore refuses after a concurrent change; re-entrancy; `onShutdown` with and without
  a fatal; `insertChecked` discards only under every condition; MCP and REST answer the same shape (lockstep);
  `WpOrgComplianceTest` stays green (no "Pro" wording, no remote call, no option or meta writes).
- Pro unit tests: policy table, budget, locator (fake tree, fake renderer: exact, parent, partial, none, bounds), hints
  (the two proven message families, verbatim from `render-fatals.json`), report (path stripping, caps, types only), the
  listener with a fake `RenderChecker` (disabled, locked, post type, budget, pass, fail + previous clean, fail +
  previous fails, inconclusive), the tool (args, gate first, per-page permission, budget), settings and log (nonce,
  capability, ring of 20), `FreeApiCompatTest` pins `PostWriteCheck::HOOK/FAILED/ROLLBACK_FAILED` and the `write()`
  return keys, `ProSafetyTest` stays green.
- Live gate `make verify-pro-render-check` (dev site only, self-cleaning; section 7 of the plan): the real Divi crash
  through the real `HistoryService::write()`, the full MCP and REST handler path through a gate-only `the_content`
  marker filter, the HTTP leg (REST context fidelity), page-already-failed, title rollback, creates, find_replace_site,
  build_site, shutdown put-back after a real memory-exhaustion fatal in a separate process, side effects (page meta, the
  `et-cache` listing and the options table are unchanged by a render-only check), the switch, cost on all 71 recipes
  rendered as pages, and zero orphans. Evidence goes to `docs/render-check-evidence-5.15.1.md`.

## 7. Risks

1. **False rollback of a good page.** The page throws only in the API render context and not on the front end, or a
   listener bug. Mitigations: the previous-version control, "no marker = inconclusive", a throwing listener never rolls
   back, the HTTP leg in the gate, and every rollback logged and visible.
2. **Performance.** One extra render per save (a second, plus up to 40 small ones, only on failure). Budget 20 s / 60
   renders per request; slow hosts with 30 s `max_execution_time` get `skipped:"time_budget"`, never a timeout we cause
   knowingly. The cost is measured on all 71 recipes in the gate.
3. **Side effects of running page code inside an API request.** Shortcodes and third-party `the_content` filters run,
   as they would for a visitor. Superglobals are emptied, buffers are discarded and globals restored, the gate measures
   meta/cache/options, and an uncatchable fatal goes through the shutdown put-back (proven live for memory exhaustion;
   a time-limit fatal is best effort). Between the save and the put-back (milliseconds to seconds) a visitor can get the
   500, and a page cache could store it.
4. **Compliance (WordPress.org).** The free plugin gains a veto hook, a put-back and a draft discard that only an add-on
   triggers. Mitigation: generic and documented as a public API with a non-Pro example; no licence, "Pro" wording,
   remote call, option or meta write; `WpOrgComplianceTest` and a new "no listener = identical" test. A reviewer could
   still call it add-on plumbing. The hooks precedent (`jhmg_aied_*`) already passed review.
5. **Overclaiming.** Not covered: Theme Builder layouts, presets/colours/variables, custom-field values, browser and
   JavaScript-only breakage, Visual Builder-only errors, wp_head/footer style output, the OOM killer, and Divi releases
   with a different render path. The marketing line must be "every AI layout save is test-rendered and a page that
   crashes Divi is put back", never "can never break".

## 8. Known limits (to restate in the Outcome)

- In-process, not a browser. A crash in `wp_head`/`wp_footer` style output, in the Theme Builder wrapper, in JavaScript
  or only in the Visual Builder is not seen.
- A request ending with `exit` (no fatal) during a render is not put back (a plugin's `wp_die` inside a shortcode is
  ambiguous). A host-killed process runs no shutdown function.
- Context differences: modules that read the main query (`is_singular()`, the queried object) may render differently
  than on the front end, because the main query is not replaced.
- Divi versions: hints and the self-test case are proven on 5.15.1. The throw rule is version-independent.
- A concurrent save between the write and the put-back wins (`changed_since`). The put-back changes `post_modified` and
  adds a WordPress revision, like any save.

## 9. Follow-ups (not in v1)

A Theme Builder layout render spike; `audit_site` with `render:true`; a render check after `manage_global_presets`
(site-wide: sample pages that cite the preset); a render after `set_custom_fields` for pages bound to the field; the
free `dry_run` with a pre-save render through a second hook.

## Spec issues found (brief vs code; which side this design follows)

1. **Brief: roll back through `restore_page_version` semantics (snapshot-before-restore).** Code:
   `HistoryService::restore()` snapshots the CURRENT content first ("before restore of #N"). That would put the
   crashing layout into the history, where it can be restored again. It also re-runs core's kses on the restored
   content for users without `unfiltered_html` (the problem `writeTitle()` already solves), and it needs a stored
   snapshot, which is not always there (`too_large`, `store_failed`). **Followed: the code facts.** A dedicated exact
   put-back from memory, no new snapshot; the answer still reports `history.stored` of the pre-write snapshot.
2. **Brief: the live gate should "use the test seam or a direct `wp_update_post`" to save a crashing layout.** A direct
   `wp_update_post` never reaches the hook, which lives in `HistoryService::write()`. **Followed: the code.** The gate
   calls `HistoryService::write()` directly (it has no validator by design: the handlers validate before calling it).
   `wp_update_post` is used only to seed a crashing PREVIOUS version, and a gate-only `the_content` marker filter makes
   the full MCP/REST handler path fail on content the validator accepts.
3. **Brief: an empty body counts as a failure.** Evidence (spike) shows empty output is the main false-positive class,
   and only a throw is proven to be a 500. **Followed: the evidence.** Empty output is a note, never a rollback.
4. **Brief: "fatal error text" in the output counts as a failure.** Nothing proves that Divi prints such text instead
   of throwing. **Followed: not a failure.**
5. **Brief: the sweep "matched real HTTP 9/9".** True, but the sweep ran under WP-CLI (`wp eval-file`), not inside a
   REST request, where `REST_REQUEST` is defined and Divi may load or behave differently. **Followed: not assumed.** The
   gate has an HTTP leg against the real MCP endpoint, and "no Divi module marker in the output" is inconclusive, never
   a pass.
6. **Brief: hook "AFTER a successful write in `HistoryService::write()`/the four write handlers" must stay within the
   ExtensionGuard model.** `docs/EXTENDING.md` says built-in behaviour cannot be intercepted. A post-write veto changes
   a built-in tool's outcome. **Followed: a narrow, documented exception** (the add-on can only ask for the exact
   previous bytes back; built-in keys win; a throwing listener never undoes), stated in EXTENDING.md.
7. **Brief: covers "any Pro write tool".** The header/footer tools and `find_replace_site` with layouts write through
   `HistoryService::write()`, but a Theme Builder layout is not rendered through `the_content` in its own post context
   on the front end, and that render is unproven. `build_site add_page` does not use `write()` or `DraftPage` at all
   (`BuildSite.php:172` calls `wp_insert_post`). **Followed: the code.** Theme layouts are skipped with a reason, and
   `add_page` calls the Pro checker directly.
8. **Brief: "Pro MIN_FREE bump note".** Raising `Compat::MIN_FREE` would switch off every Pro tool on older free
   versions. **Followed: feature detection** (D7). The owner may still raise it at release.
9. **`FreeApiCompatTest` allows exactly three methods to gain optional parameters.** **Followed: no signature change.**
   Only return keys are added, and they are newly pinned.
10. **CLAUDE.md says the Pro add-on has 21 tools and `MCP_REST_ONLY` has 7 entries.** This adds a 22nd tool and an 8th
    entry; the ChatGPT budget stays at 30 of 30. The docs task updates CLAUDE.md, `pro-addon/README.md` and the counts
    pinned in the Pro tests.
11. **`WpOrgComplianceTest::COMMERCIAL_WORDS` bans `\bPro\b` everywhere in `wp-plugin/`.** The free code and docblocks
    must say "an add-on".
12. **The free test shims' `add_filter` ignores the priority and there is no `has_filter` shim** (`tests/bootstrap.php`).
    The free task adds a guarded `has_filter` shim, and tests must not rely on priorities.
