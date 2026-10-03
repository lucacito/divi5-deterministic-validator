# Pro Add-on 0.4.0: Site-Wide Find/Replace, Audit, and REST + ChatGPT Parity — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the last two Pro tools of the v1 spec, `find_replace_site` and `audit_site`, and give every Pro tool a REST route and a ChatGPT OpenAPI operation that cannot drift from the MCP tool, with a live proof on the real dev site.

**Architecture:** One `ToolRunner` becomes the single call path for a Pro tool (capability check, argument errors, no leaked failures), used by the MCP registry and by the new REST routes. A small `Site/` layer defines what "the site" means for a site-wide tool (`SiteItems`: Divi 5 pages the user can edit, plus the live header/footer only with the Theme Builder permission), the signed preview token (`PreviewToken`) and the internal-link reader (`LinkScanner`). `find_replace_site` reuses the free `PageEditor::apply`, the shared `LayoutGate`, the free `HistoryService` and the existing `post_content` / `theme_layout_content` undo kinds, so one batch of up to 50 items undoes through the engine unchanged. `audit_site` is read-only. `ProRest` and `ProOpenApi` generate `POST /pro/<tool>` and one OpenAPI operation per tool from the tool list through the free plugin's documented hooks.

**Tech Stack:** PHP 8.1+, PHPUnit (`make test-pro`, shims in `pro-addon/tests/`), WordPress core functions, the free plugin's `RestController::require_edit_posts` and `OpenApiSpec`, Divi 5.14 on the Docker site for the live scripts. No new dependencies. Nothing in `wp-plugin/` or `src/` changes.

**Spec:** `docs/superpowers/specs/2026-10-02-pro-addon-design.md` (sections `find_replace_site`, `audit_site`, "Batches and undo", "Verification", "Risks" 3; Task 6 adds a short "REST and ChatGPT parity" section to it). Plan 5 of the roadmap in `pro-addon-roadmap`.

**Baseline:** `main` after the Plan 4 merge (`d20c22d`) plus this plan's commit; `make test-pro` = 571 tests, `make test` = 726, both green before Task 1. End state: `make test-pro` = 679, `make test` = 726.

## What the research settled (decisions already made — do not re-litigate)

1. **What "the site" is.** Only Divi 5 pages (`_et_pb_use_divi_5 = on`, post type `page`) the user can `edit_post`, in statuses publish/draft/pending/private (an audit also reads `future`; trash and auto-drafts never). Classic pages, posts and anything the user cannot edit are never seen. The live header and footer join only with `scope` `layouts` or `all`, `edit_theme_options` AND Divi's Theme Builder bar (`LayoutTarget`); otherwise they are left out with a note (and `scope=layouts` is refused). More than 1000 Divi 5 pages: refused with a request to narrow with `page_ids`, never a silent partial search.
2. **The preview token is self-contained and signed**, not a state hash like `manage_theme_template`'s: the spec wants an item that changed since the preview to be skipped while the others still apply, so the token carries `id => content hash` for every item the preview would change, a hash of the arguments and the time (30 minutes), signed with `wp_hash`. A real run recomputes the plan from the CURRENT content and compares each item with the token's hash. No transient, no table.
3. **One batch, existing undo kinds.** A real run records ONE batch of at most 50 items: pages as `post_content` items, layouts as `theme_layout_content` items. `BatchUndo` is unchanged. An item whose result fails `LayoutGate::check($new, $stored)` is skipped and reported (`invalid_result`), never saved; a failed save is skipped (`save_failed`); more than 50 matching items are refused with the count.
4. **`audit_site` findings are only what can be proven without a request:** the layout checker's own violations (including custom code already stored), unknown preset/color ids through the free advisory (`StyleReferences::attach`), and internal links read from the content (`LINK_NO_POST`: no page or post has that address, which can also be an archive or a custom route, and the finding says so; `LINK_NOT_PUBLISHED`). Links are read from block attributes whose key ends in url/link/href and from `href` inside escaped text HTML, as real Divi 5 stores them (checked against `fixtures/valid/`). NO image alt-text check (no real Image-module export), NO remote request, nothing written. 25 items per call with a cursor; findings capped at 200 per call with the totals by code always complete.
5. **REST/ChatGPT parity is generated, not hand-written.** `POST /wp-json/ai-editor-divi5/v1/pro/<tool_name>` with the arguments as the JSON body; authentication is the free plugin's `require_edit_posts` (API key, Application Password or a logged-in editor) plus the tool's own capability through the shared `ToolRunner`. Locked licence: no route and no operation is registered, and a licence revoked after registration refuses at request time. OpenAPI: one operation per tool, so the spec is 16 free + 13 Pro = **29 of the importer's 30** operations; there is room for NO 14th Pro operation without an explicit decision about the ChatGPT surface.
6. **Out of scope (later specs):** regex find/replace, image alt-text audit, remote link checking, an audit UI, whole-site generation, pricing and the divi5lab.com page.
7. **Evidence:** both live scripts in this plan were already run against the real Divi 5.14 dev site while the plan was replayed and passed after two script defects were fixed (a `+` array union that kept the wrong `scope`, and the REST server having been built before the script's hooks existed under WP-CLI).

## Global Constraints

Copied from the spec and `CLAUDE.md`; every task implicitly includes them.

- **The validator stays deterministic and untouched.** Every write goes through `\AiEditorDivi5\WP\LayoutGate::check( $content, $storedContent )` with the STORED content as the baseline. Nothing in this plan edits `src/` or `wp-plugin/`.
- **No Divi schema from memory.** The link-key shapes (`linkUrl`, `url`, `href=` inside escaped HTML) come from the real exports in `fixtures/valid/`; anything else Divi-specific is proven by the live scripts.
- **Extension contract.** The add-on attaches only through the `jhmg_aied_*` hooks (`jhmg_aied_register_rest_routes`, `jhmg_aied_openapi_paths`, `jhmg_aied_openapi_schemas`, `jhmg_aied_mcp_*`); it checks capabilities itself; it never overrides a built-in tool, route or path.
- **Every tool type-checks arguments** (never cast) and returns safe messages; tool descriptions at most 300 characters (`ToolsTest` enforces it); OpenAPI: every description and summary at most 300 characters and no object schema without `properties` or `additionalProperties`.
- **Every write is a batch** in `{prefix}aied_pro_batches` (last 20 kept). Undo is honest (changed-since rule, `no_undo_point`, never "fully undone" unless every item restored).
- **No remote request, no PHP execution, no custom CSS/JS/PHP saved, no licence gating in `wp-plugin/`.** `audit_site` and `LinkScanner` make no network call (a test scans for it). Nothing is pushed, uploaded or released by this plan.
- Namespace `AiEditorDivi5\Pro`; PHP 8.1 (no standalone `null` return types, no `readonly` classes).

## Review Focus

Failure modes the spec implies but a happy-path test would miss, most likely first. Each has a test in the task named in brackets.

1. **A site-wide write overwriting something that changed after the preview**: an item edited in Divi between preview and apply must be skipped and reported, a new match that appeared since must not be touched, and a forged, tampered, expired, replayed-with-other-arguments or future-dated token must be refused. [Task 2]
2. **Touching what is not the site's Divi 5 content**: classic pages, posts, trashed pages, pages the user cannot edit, the header/footer without scope or Theme Builder permission, and results that fail the layout checks (including newly added custom code) must never be saved; exactly 50 matches is allowed and 51 is refused with the count. [Task 2]
3. **Audit false alarms and silent writes**: external links, anchors, `mailto:`, `tel:`, `javascript:`, uploads, admin/REST paths, the home page and dynamic `$variable(...)` values must not be reported as broken internal links; the audit must change nothing and make no request. [Task 3]
4. **REST weaker than MCP**: an anonymous call must be 401, a user without the tool's capability 403 (the tool never runs), a locked licence must register nothing (and refuse a call made after a revoke), a JSON body that is a list must be 400, and a failure must never leak its message. [Task 4]
5. **The ChatGPT spec breaking the importer or drifting**: at most 30 operations in total, every description at most 300 characters, no bare object schema, unique operationIds, and MCP, REST and OpenAPI exposing exactly the same tools with the same arguments. [Task 4]

## Execution notes (read before Task 1)

- **Run the whole plan in the MAIN checkout on a branch**, not a worktree: `git switch -c feat/pro-site-tools` from `main`. The Docker site mounts `./pro-addon` and `./scripts` from the checkout where `docker compose` runs.
- **Live tasks (5):** the Pro add-on is INACTIVE on the dev site. Activate it for the run and deactivate it afterwards, always with enough memory (Divi 5 exhausts the default 128 MB under plain `wp`): `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin activate jhmg-ai-editor-for-divi-5-pro` … `plugin deactivate …`. The scripts are self-cleaning and run on the dev site only (`verify-pro-site-tools` evicts old batch rows).
- **Never type a JSON-style unicode escape (a backslash followed by `u` and four hex digits) into a command or a file written through a tool call**: the tool channel turns it into the character itself and silently changes the file. Use the extractor, which copies code blocks byte for byte; build such strings with `chr( 92 )` in PHP, as `LinkScannerTest` does.
- **Commit trailer, exactly:** `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Subagents must not substitute their own model name. Check `git log -1 --format=%B` after every implementer.
- zsh: do not `cd` into subfolders in the Bash tool (the working directory persists); use absolute paths. Do not push, upload or release anything.
- **Final whole-branch review on the most capable model (opus)** after Task 6; fix findings in ONE dispatch; then merge locally when asked.

---

## File Structure

Create (all under `pro-addon/` unless noted):

| Path | Responsibility |
|---|---|
| `src/ToolRunner.php` | the one call path for a Pro tool: capability, the call, failure mapping (shared by MCP and REST) |
| `src/Site/SiteItems.php` | which pages and live layouts a site-wide tool may look at, for the current user |
| `src/Site/PreviewToken.php` | the signed, self-contained, expiring preview token (`id => content hash`) |
| `src/Site/LinkScanner.php` | internal links of a layout as root-relative paths; no request, ever |
| `src/Tools/FindReplaceSite.php`, `src/Tools/AuditSite.php` | the two tools |
| `src/Rest/ProRest.php`, `src/Rest/ProOpenApi.php` | `POST /pro/<tool>` routes and the OpenAPI operations, generated from the tool list |
| `tests/WpSiteShims.php`, `tests/WpRestRouteShims.php` | `get_posts` / `url_to_postid` / `get_post_status` and `register_rest_route` / `is_user_logged_in` shims |
| `tests/ToolRunnerTest.php`, `PreviewTokenTest.php`, `FindReplaceSiteTest.php`, `LinkScannerTest.php`, `AuditSiteTest.php`, `ProRestTest.php`, `ProParityTest.php` | the suites |
| `../scripts/verify-pro-site-tools.php`, `../scripts/verify-pro-rest.php` | live, self-cleaning checks on the Docker site |

Modify: `src/ToolRegistry.php`, `src/autoload.php`, `src/Tools.php`, `src/Plugin.php`, `src/Compat.php`, `tests/ToolsTest.php`, `tests/FreeApiCompatTest.php`, `tests/ReadmeTest.php`, `tests/bootstrap.php`, `readme.txt`, `jhmg-ai-editor-for-divi-5-pro.php`, `README.md`, `../Makefile`, `../CLAUDE.md`, the spec.

---

### Task 0: Branch

- [ ] **Step 1: Create the branch from main**

```bash
git switch main && git status --short && git switch -c feat/pro-site-tools
```
Expected: no output from `git status --short` (clean tree: this plan file is already committed on `main`), then `Switched to a new branch 'feat/pro-site-tools'`.

- [ ] **Step 2: Confirm the baseline**

Run: `make test-pro 2>&1 | tail -3` → `OK (571 tests, 2158 assertions)`; `make test 2>&1 | tail -2` → `OK (726 tests, 5241 assertions)`.

### Task 1: `ToolRunner`, the one call path for a Pro tool

**Files:**
- Create: `pro-addon/src/ToolRunner.php`, `pro-addon/tests/ToolRunnerTest.php`
- Modify: `pro-addon/src/ToolRegistry.php` (replaced whole), `pro-addon/src/autoload.php`

**Interfaces:**
- Produces: `AiEditorDivi5\Pro\ToolRunner::run( ProTool $tool, array $arguments ): array` returning `['ok'=>true,'payload'=>array]` or `['ok'=>false,'status'=>int,'code'=>string,'message'=>string]` (403 `forbidden` without the tool's capability, the tool never runs; 400 `invalid_argument` for an `\InvalidArgumentException`, message safe for the client; 500 `tool_failed` for anything else, generic message). `ToolRegistry` gains `get( string $name ): ?ProTool`, `tools(): list<ProTool>` and `isUnlocked(): bool`, and its MCP `claim()` now goes through `ToolRunner` with identical behaviour (the existing `ToolRegistryTest` must stay green unchanged).
- Consumes: `ProTool` (`name()`, `capability()`, `run()`).

- [ ] **Step 1: Write the failing test**

Create `pro-addon/tests/ToolRunnerTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\ToolRunner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/FakeTool.php';

/** The one call path shared by the MCP registry and the REST routes. */
class ToolRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    public function testASuccessfulCallReturnsThePayload(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', null, [ 'done' => 1 ] );
        $r    = ToolRunner::run( $tool, [ 'x' => 2 ] );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [ 'done' => 1, 'args' => [ 'x' => 2 ] ], $r['payload'] );
        $this->assertSame( 1, $tool->runs );
    }

    public function testAUserWithoutTheCapabilityIsRefusedBeforeTheToolRuns(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $tool                 = new FakeTool();
        $r                    = ToolRunner::run( $tool, [] );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( [ 403, 'forbidden' ], [ $r['status'], $r['code'] ] );
        $this->assertStringContainsString( 'fake_pro_tool', $r['message'] );
        $this->assertSame( 0, $tool->runs );
    }

    public function testABadArgumentIsA400WithTheToolsOwnMessage(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', new \InvalidArgumentException( '"page_id" must be an integer.' ) );
        $r    = ToolRunner::run( $tool, [] );
        $this->assertSame( [ false, 400, 'invalid_argument', '"page_id" must be an integer.' ], [ $r['ok'], $r['status'], $r['code'], $r['message'] ] );
    }

    public function testAnUnexpectedFailureIsA500AndNeverLeaksItsMessage(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', new \RuntimeException( 'SECRET db password=hunter2' ) );
        $r    = ToolRunner::run( $tool, [] );
        $this->assertSame( [ false, 500, 'tool_failed' ], [ $r['ok'], $r['status'], $r['code'] ] );
        $this->assertStringNotContainsString( 'hunter2', (string) json_encode( $r ) );
    }

    public function testTheRegistryExposesItsToolsAndTheLicenceStateForOtherTransports(): void
    {
        $tool     = new FakeTool();
        $unlocked = true;
        $reg      = new ToolRegistry( [ $tool ], static function () use ( &$unlocked ): bool {
            return $unlocked;
        } );
        $this->assertSame( $tool, $reg->get( 'fake_pro_tool' ) );
        $this->assertNull( $reg->get( 'nope' ) );
        $this->assertSame( [ $tool ], $reg->tools() );
        $this->assertTrue( $reg->isUnlocked() );
        $unlocked = false;
        $this->assertFalse( $reg->isUnlocked() );
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter ToolRunnerTest 2>&1 | tail -6`
Expected: `Class "AiEditorDivi5\Pro\ToolRunner" not found`.

- [ ] **Step 3: Write the runner and the registry that uses it**

Create `pro-addon/src/ToolRunner.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one place a Pro tool is actually called: capability check, the call, and the mapping of what it throws to a
 * transport-neutral failure. The MCP registry and the REST routes both go through it, so a tool cannot be reachable
 * over one transport with weaker rules than the other.
 */
final class ToolRunner
{
    /**
     * @param array<string,mixed> $arguments
     * @return array{ok:true, payload:array<string,mixed>}|array{ok:false, status:int, code:string, message:string}
     */
    public static function run( ProTool $tool, array $arguments ): array
    {
        $name = $tool->name();
        if ( ! current_user_can( $tool->capability() ) ) {
            return self::fail( 403, 'forbidden', "You do not have permission to use {$name}." );
        }
        try {
            return [ 'ok' => true, 'payload' => $tool->run( $arguments ) ];
        } catch ( \InvalidArgumentException $e ) {
            // The message is written to be shown to the client.
            return self::fail( 400, 'invalid_argument', $e->getMessage() );
        } catch ( \Throwable $e ) {
            return self::fail( 500, 'tool_failed', 'The tool failed unexpectedly.' );
        }
    }

    /** @return array{ok:false, status:int, code:string, message:string} */
    private static function fail( int $status, string $code, string $message ): array
    {
        return [ 'ok' => false, 'status' => $status, 'code' => $code, 'message' => $message ];
    }
}
```

Replace `pro-addon/src/ToolRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only place the add-on talks to the free plugin's tool hooks. Tools are advertised and callable only
 * while the licence is unlocked; each call re-checks the unlock and the tool's capability.
 */
final class ToolRegistry
{
    /** @var array<string,ProTool> */
    private array $tools = [];

    /**
     * @param list<ProTool> $tools
     * @param \Closure():bool $unlocked
     */
    public function __construct( array $tools, private \Closure $unlocked )
    {
        foreach ( $tools as $tool ) {
            $this->tools[ $tool->name() ] = $tool;
        }
    }

    /** The tool with this name, or null (no licence check: callers that serve a request check `isUnlocked()`). */
    public function get( string $name ): ?ProTool
    {
        return $this->tools[ $name ] ?? null;
    }

    /** @return list<ProTool> */
    public function tools(): array
    {
        return array_values( $this->tools );
    }

    public function isUnlocked(): bool
    {
        return ( $this->unlocked )();
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys( $this->tools );
    }

    public function register(): void
    {
        add_filter( 'jhmg_aied_mcp_tools', [ $this, 'definitions' ] );
        add_filter( 'jhmg_aied_mcp_call', [ $this, 'claim' ], 10, 4 );
    }

    /** `jhmg_aied_mcp_tools`: append the Pro tool definitions while unlocked. */
    public function definitions( mixed $tools ): mixed
    {
        if ( ! is_array( $tools ) || ! ( $this->unlocked )() ) {
            return $tools;
        }
        foreach ( $this->tools as $tool ) {
            $tools[] = $tool->definition();
        }

        return $tools;
    }

    /**
     * `jhmg_aied_mcp_call`: claim a call for one of our tools, or return `$response` untouched.
     *
     * @param array<string,mixed> $arguments
     */
    public function claim( mixed $response, string $name, array $arguments, mixed $rpcId ): mixed
    {
        if ( null !== $response || ! isset( $this->tools[ $name ] ) || ! ( $this->unlocked )() ) {
            return $response;
        }
        $run = ToolRunner::run( $this->tools[ $name ], $arguments );
        if ( ! $run['ok'] ) {
            return self::error( $rpcId, 500 === $run['status'] ? -32603 : -32602, $run['message'] );
        }
        $payload = $run['payload'];

        return new \WP_REST_Response(
            [ 'jsonrpc' => '2.0', 'id' => $rpcId, 'result' => [ 'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $payload ) ] ] ] ],
            200
        );
    }

    private static function error( mixed $rpcId, int $code, string $message ): \WP_REST_Response
    {
        return new \WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $rpcId, 'error' => [ 'code' => $code, 'message' => $message ] ], 200 );
    }
}
```
(The only differences from the file you are replacing: `claim()` calls `ToolRunner::run()` instead of checking the capability and catching exceptions itself, and the three accessors `get()`, `tools()` and `isUnlocked()` are new.)

`pro-addon/src/autoload.php`: before the line `require_once __DIR__ . '/ToolRegistry.php';` add `require_once __DIR__ . '/ToolRunner.php';`.

- [ ] **Step 4: Run the whole Pro suite**

Run: `make test-pro 2>&1 | tail -3` → all green with no failures, risky tests, deprecations or warnings (`OK (578 tests, 2175 assertions)`).

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src pro-addon/tests
git commit -q -F - <<'EOF'
refactor(pro): ToolRunner is the one call path for a Pro tool (capability, argument errors, no leaked failures)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).

### Task 2: `find_replace_site`

**Files:**
- Create: `pro-addon/src/Site/SiteItems.php`, `pro-addon/src/Site/PreviewToken.php`, `pro-addon/src/Tools/FindReplaceSite.php`
- Create: `pro-addon/tests/WpSiteShims.php`, `pro-addon/tests/PreviewTokenTest.php`, `pro-addon/tests/FindReplaceSiteTest.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Tools.php`, `pro-addon/src/Compat.php`, `pro-addon/tests/ToolsTest.php`, `pro-addon/tests/FreeApiCompatTest.php`, `pro-addon/readme.txt`

**Interfaces:**
- Consumes: `LayoutTarget::resolve()` and `ThemeLayouts::userMay()/liveLayoutKinds()` (Plan 4), `PostContentKind::itemFor()`, `ThemeLayoutKind::itemFor()`, `LayoutCaches::clear()`, `BatchLog::record/attach`, `Canon::hash`, `Args`. From the free plugin (pinned): `PageEditor::apply( string $content, string $find, string $replace, ?int $expectCount ): array{ok,content,count,error}`, `LayoutGate::check( string, ?string ): ValidationResult`, `LayoutDiff::compare( string, string ): array` (new pin), `HistoryService::write( int, string, string ): array`.
- Produces: `SiteItems` (`PAGE_LIMIT`, `SCOPES`, `EDIT_STATUSES`, `AUDIT_STATUSES`, `LAYOUTS_SKIPPED`; `collect( string $scope, array $statuses, ?array $ids ): array{items,truncated,layouts_note}`; `content( int ): string`), reused by Task 3; `PreviewToken` (`TTL`, `make( string $argsHash, array $items, int $now ): string`, `open( string $token, string $argsHash, int $now ): ?array`); `FindReplaceSite` (`NAME`, `MAX_ITEMS = 50`, `MAX_BYTES`; constructor `( BatchLog $log, ?\Closure $write = null, ?\Closure $clock = null )`, capability `edit_pages`).

The test shim file `WpSiteShims.php` gives the in-memory post store `get_posts()`, `url_to_postid()` and `get_post_status()`; Task 3 reuses it.

- [ ] **Step 1: Write the shims and the failing suites**

Create `pro-addon/tests/WpSiteShims.php`:

```php
<?php

declare(strict_types=1);

/**
 * `get_posts()` and `url_to_postid()` over the in-memory `$GLOBALS['__wp_posts']` / `__wp_postmeta` store, for the
 * site-wide tools. Supports exactly what SiteItems and the audit use: post_type, post_status (string or list),
 * fields=ids, post__in, orderby ID, posts_per_page and a single meta_query equality. Guarded like every shim.
 */
if ( ! function_exists( 'get_posts' ) ) {
    function get_posts( $args = [] ) {
        $types    = (array) ( $args['post_type'] ?? 'post' );
        $statuses = (array) ( $args['post_status'] ?? 'publish' );
        $in       = isset( $args['post__in'] ) ? array_map( 'intval', (array) $args['post__in'] ) : null;
        $meta     = $args['meta_query'][0] ?? null;
        $out      = [];
        $posts    = $GLOBALS['__wp_posts'] ?? [];
        ksort( $posts );
        foreach ( $posts as $id => $p ) {
            if ( ! in_array( $p->post_type, $types, true ) ) {
                continue;
            }
            if ( ! in_array( 'any', $statuses, true ) && ! in_array( $p->post_status, $statuses, true ) ) {
                continue;
            }
            if ( null !== $in && ! in_array( (int) $id, $in, true ) ) {
                continue;
            }
            if ( null !== $meta && (string) ( $GLOBALS['__wp_postmeta'][ $id ][ $meta['key'] ] ?? '' ) !== (string) $meta['value'] ) {
                continue;
            }
            $out[] = $id;
        }
        $limit = (int) ( $args['posts_per_page'] ?? -1 );
        if ( $limit > 0 ) {
            $out = array_slice( $out, 0, $limit );
        }

        return 'ids' === ( $args['fields'] ?? '' ) ? $out : array_map( static fn ( $id ) => $GLOBALS['__wp_posts'][ $id ], $out );
    }
}
if ( ! function_exists( 'url_to_postid' ) ) {
    /** Resolves `?p=ID`, `?page_id=ID` and `/slug/` against the stored posts' `post_name`; 0 when nothing matches. */
    function url_to_postid( $url ) {
        $parts = parse_url( (string) $url );
        parse_str( (string) ( $parts['query'] ?? '' ), $q );
        foreach ( [ 'p', 'page_id' ] as $k ) {
            if ( isset( $q[ $k ] ) && isset( $GLOBALS['__wp_posts'][ (int) $q[ $k ] ] ) ) {
                return (int) $q[ $k ];
            }
        }
        $slug = trim( (string) ( $parts['path'] ?? '' ), '/' );
        foreach ( $GLOBALS['__wp_posts'] ?? [] as $id => $p ) {
            if ( '' !== $slug && ( $p->post_name ?? '' ) === $slug ) {
                return (int) $id;
            }
        }

        return 0;
    }
}
if ( ! function_exists( 'get_post_status' ) ) {
    function get_post_status( $id = 0 ) {
        $p = $GLOBALS['__wp_posts'][ (int) $id ] ?? null;

        return $p ? $p->post_status : false;
    }
}
```

Create `pro-addon/tests/PreviewTokenTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Site\PreviewToken;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class PreviewTokenTest extends TestCase
{
    public function testItRoundTripsTheItemMap(): void
    {
        $t = PreviewToken::make( 'args1', [ 11 => 'aaaa', 12 => 'bbbb' ], 1000 );
        $this->assertSame( [ 11 => 'aaaa', 12 => 'bbbb' ], PreviewToken::open( $t, 'args1', 1000 ) );
        $this->assertSame( [], PreviewToken::open( PreviewToken::make( 'args1', [], 1000 ), 'args1', 1000 ), 'an empty map is still a valid token' );
    }

    public function testItIsBoundToTheArguments(): void
    {
        $t = PreviewToken::make( 'args1', [ 11 => 'aaaa' ], 1000 );
        $this->assertNull( PreviewToken::open( $t, 'args2', 1000 ) );
    }

    public function testItExpiresAndRefusesATimeFromTheFuture(): void
    {
        $t = PreviewToken::make( 'a', [ 1 => 'x' ], 1000 );
        $this->assertNotNull( PreviewToken::open( $t, 'a', 1000 + PreviewToken::TTL ) );
        $this->assertNull( PreviewToken::open( $t, 'a', 1000 + PreviewToken::TTL + 1 ) );
        $this->assertNull( PreviewToken::open( $t, 'a', 999 ), 'made in the future: refused' );
    }

    public function testATamperedOrMalformedTokenIsRefused(): void
    {
        $t               = PreviewToken::make( 'a', [ 1 => 'x' ], 1000 );
        [ $payload, $sig ] = explode( '.', $t );
        $forged          = rtrim( strtr( base64_encode( (string) json_encode( [ 'v' => 1, 'a' => 'a', 'i' => [ 1 => 'x', 2 => 'y' ], 't' => 1000 ] ) ), '+/', '-_' ), '=' );
        foreach ( [ '', 'x', $payload, $payload . '.', '.' . $sig, $forged . '.' . $sig, $payload . '.' . $sig . '.extra', $payload . '.' . strrev( $sig ), "{$payload}x.{$sig}" ] as $bad ) {
            $this->assertNull( PreviewToken::open( $bad, 'a', 1000 ), json_encode( $bad ) );
        }
    }

    public function testAValidSignatureOverAnUnexpectedPayloadIsRefused(): void
    {
        foreach ( [ 'not json', json_encode( [ 'v' => 2, 'a' => 'a', 'i' => [], 't' => 1000 ] ), json_encode( [ 'v' => 1, 'a' => 'a', 'i' => 'x', 't' => 1000 ] ), json_encode( [ 'v' => 1, 'a' => 'a', 'i' => [], 't' => '1000' ] ) ] as $json ) {
            $payload = rtrim( strtr( base64_encode( (string) $json ), '+/', '-_' ), '=' );
            $token   = $payload . '.' . wp_hash( 'aied-site|' . $payload );
            $this->assertNull( PreviewToken::open( $token, 'a', 1000 ), (string) $json );
        }
    }
}
```

Create `pro-addon/tests/FindReplaceSiteTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Site\PreviewToken;
use AiEditorDivi5\Pro\Tools\FindReplaceSite;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';
require_once __DIR__ . '/WpSiteShims.php';

class FindReplaceSiteTest extends TestCase
{
    use ThemeLayoutFixture;

    private FindReplaceSite $tool;
    private int $now = 1000000;

    /** A valid Divi 5 layout whose heading says $word. */
    private static function text( string $word ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $word . '"}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $this->tool = new FindReplaceSite( $this->log, null, fn (): int => $this->now );
        $GLOBALS['__wp_caps']['edit_posts'] = true;
        foreach ( [ 11, 12, 13 ] as $id ) {
            $this->themePost( $id, 'page', 'publish', [ '_et_pb_use_divi_5' => 'on' ], self::text( 'Hello AIED-OLD world' ) );
        }
        $this->themePost( 14, 'page', 'draft', [ '_et_pb_use_divi_5' => 'on' ], self::text( 'Draft AIED-OLD' ) );
        $this->themePost( 15, 'page', 'publish', [ '_et_pb_use_divi_5' => 'on' ], self::text( 'No match here' ) );
        $this->themePost( 16, 'page', 'trash', [ '_et_pb_use_divi_5' => 'on' ], self::text( 'Trashed AIED-OLD' ) );
        $this->themePost( 17, 'page', 'publish', [], '<p>Classic AIED-OLD page, not Divi 5</p>' );
        $this->themePost( 18, 'post', 'publish', [ '_et_pb_use_divi_5' => 'on' ], self::text( 'A post AIED-OLD' ) );
        $GLOBALS['__wp_posts'][201]->post_content = self::text( 'Header AIED-OLD' );
        $GLOBALS['__wp_posts'][202]->post_content = self::text( 'Footer AIED-OLD' );
        $GLOBALS['__wp_posts'][5]->post_content   = '';   // the fixture page is not a Divi 5 page
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function args( array $extra = [] ): array
    {
        return $extra + [ 'find' => 'AIED-OLD', 'replace' => 'AIED-NEW' ];
    }

    /** Preview, then apply with the token. @param array<string,mixed> $extra @return array<string,mixed> */
    private function apply( array $extra = [] ): array
    {
        $p = $this->tool->run( $this->args( $extra ) + [ 'dry_run' => true ] );
        $this->assertArrayHasKey( 'token', $p );

        return $this->tool->run( $this->args( $extra ) + [ 'token' => $p['token'] ] );
    }

    private function refused( array $args, string $message ): void
    {
        $before = [ array_map( fn ( $i ) => $this->layoutContent( $i ), [ 11, 12, 13, 14, 201, 202 ] ), $GLOBALS['__wp_update_calls'], count( $this->repo->rows ) ];
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( $before, [ array_map( fn ( $i ) => $this->layoutContent( $i ), [ 11, 12, 13, 14, 201, 202 ] ), $GLOBALS['__wp_update_calls'], count( $this->repo->rows ) ] );
    }

    public function testADryRunListsTheMatchesAndATokenAndChangesNothing(): void
    {
        $r = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 4, $r['matched'], 'pages 11-13 and the draft 14; not 15 (no match), 16 (trash), 17 (not Divi 5), 18 (a post)' );
        $this->assertSame( [ 11, 12, 13, 14 ], array_column( $r['items'], 'id' ) );
        $this->assertSame( 4, $r['would_change'] );
        $this->assertSame( 0, $r['would_skip'] );
        $this->assertSame( 1, $r['items'][0]['occurrences'] );
        $this->assertNotNull( $r['items'][0]['diff'] );
        $this->assertNotSame( '', $r['token'] );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testARealRunReplacesEverywhereAsOneUndoableBatch(): void
    {
        $r = $this->apply();
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 4, $r['replaced_in'] );
        $this->assertSame( [], $r['skipped'] );
        $this->assertSame( self::text( 'Hello AIED-NEW world' ), $this->layoutContent( 11 ) );
        $this->assertSame( self::text( 'Draft AIED-NEW' ), $this->layoutContent( 14 ) );
        $this->assertSame( self::text( 'No match here' ), $this->layoutContent( 15 ) );
        $this->assertSame( self::text( 'Trashed AIED-OLD' ), $this->layoutContent( 16 ) );
        $this->assertSame( '<p>Classic AIED-OLD page, not Divi 5</p>', $this->layoutContent( 17 ) );
        $this->assertSame( self::text( 'A post AIED-OLD' ), $this->layoutContent( 18 ) );
        $this->assertSame( self::text( 'Header AIED-OLD' ), $this->layoutContent( 201 ), 'layouts only with scope layouts or all' );
        $this->assertCount( 1, $this->repo->rows, 'one batch for the whole run' );
        $this->assertCount( 4, $this->repo->rows[ $r['batch']['id'] ]['items'] );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( 1, $this->historyRows( 11 ) );

        $undo = new \AiEditorDivi5\Pro\Batch\BatchUndo( [ 'post_content' => new \AiEditorDivi5\Pro\Kinds\PostContentKind() ], $this->log );
        $u    = $undo->undo( $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( self::text( 'Hello AIED-OLD world' ), $this->layoutContent( 11 ) );
        $this->assertSame( self::text( 'Draft AIED-OLD' ), $this->layoutContent( 14 ) );
    }

    public function testEveryOccurrenceInAnItemIsReplaced(): void
    {
        $GLOBALS['__wp_posts'][11]->post_content = self::text( 'AIED-OLD and AIED-OLD again' );
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->assertSame( 2, $p['items'][0]['occurrences'] );
        $this->apply();
        $this->assertSame( self::text( 'AIED-NEW and AIED-NEW again' ), $this->layoutContent( 11 ) );
    }

    public function testARealRunWithoutAValidTokenIsRefusedAndChangesNothing(): void
    {
        $this->refused( $this->args(), 'needs the token' );
        $this->refused( $this->args( [ 'token' => 'nope' ] ), 'needs the token' );
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->refused( $this->args( [ 'token' => $p['token'], 'replace' => 'DIFFERENT' ] ), 'needs the token' );
        $this->refused( $this->args( [ 'token' => $p['token'], 'scope' => 'all' ] ), 'needs the token' );
        $this->refused( $this->args( [ 'token' => $p['token'], 'page_ids' => [ 11 ] ] ), 'needs the token' );
        $this->refused( $this->args( [ 'token' => $p['token'] . 'x' ] ), 'needs the token' );
    }

    public function testATokenExpires(): void
    {
        $p        = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->now += PreviewToken::TTL + 1;
        $this->refused( $this->args( [ 'token' => $p['token'] ] ), 'needs the token' );
    }

    public function testAnItemChangedSinceThePreviewIsSkippedAndReportedTheRestApply(): void
    {
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $GLOBALS['__wp_posts'][12]->post_content = self::text( 'Edited in the builder AIED-OLD' );
        $r = $this->tool->run( $this->args( [ 'token' => $p['token'] ] ) );
        $this->assertSame( 3, $r['replaced_in'] );
        $this->assertSame( [ [ 'kind' => 'page', 'id' => 12, 'title' => 'Post 12', 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
        $this->assertSame( self::text( 'Edited in the builder AIED-OLD' ), $this->layoutContent( 12 ), 'never overwritten' );
        $this->assertSame( self::text( 'Hello AIED-NEW world' ), $this->layoutContent( 11 ) );
    }

    public function testANewMatchAfterThePreviewIsNotTouched(): void
    {
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $GLOBALS['__wp_posts'][15]->post_content = self::text( 'Now AIED-OLD too' );
        $r = $this->tool->run( $this->args( [ 'token' => $p['token'] ] ) );
        $this->assertSame( 4, $r['replaced_in'] );
        $this->assertSame( 'not_in_preview', $r['skipped'][0]['reason'] );
        $this->assertSame( self::text( 'Now AIED-OLD too' ), $this->layoutContent( 15 ) );
    }

    public function testAnItemWhoseResultWouldFailTheChecksIsSkippedNeverSaved(): void
    {
        $args = [ 'find' => 'wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Draft', 'replace' => 'wp:divi/not-a-module {"title":{"innerContent":{"desktop":{"value":"Draft' ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertSame( 1, $p['matched'] );
        $this->assertFalse( $p['items'][0]['valid'] );
        $this->assertSame( 1, $p['would_skip'] );
        $this->assertNotEmpty( $p['items'][0]['violations'] );
        $r = $this->tool->run( $args + [ 'token' => $p['token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 'invalid_result', $r['skipped'][0]['reason'] );
        $this->assertSame( self::text( 'Draft AIED-OLD' ), $this->layoutContent( 14 ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testAnInvalidItemNeverStopsTheOthers(): void
    {
        // page 11 would become invalid; page 12 stays fine
        $GLOBALS['__wp_posts'][11]->post_content = str_replace( 'Hello AIED-OLD world', 'X AIED-OLD', self::text( 'x' ) );
        $args = [ 'find' => 'wp:divi/heading', 'replace' => 'wp:divi/not-a-module', 'page_ids' => [ 11 ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertSame( 1, $p['would_skip'] );
        $this->apply();
        $this->assertSame( self::text( 'Hello AIED-NEW world' ), $this->layoutContent( 12 ) );
    }

    public function testMoreThanFiftyItemsAreRefusedWithTheCountNotTruncated(): void
    {
        for ( $i = 100; $i < 151; $i++ ) {
            $this->themePost( $i, 'page', 'publish', [ '_et_pb_use_divi_5' => 'on' ], self::text( 'many AIED-OLD' ) );
        }
        $this->refused( $this->args( [ 'dry_run' => true ] ), '55 items contain that text; the limit is 50' );
        $r = $this->tool->run( $this->args( [ 'dry_run' => true, 'page_ids' => range( 100, 149 ) ] ) );
        $this->assertSame( 50, $r['matched'], 'exactly fifty is allowed' );
    }

    public function testPageIdsNarrowTheScope(): void
    {
        $r = $this->apply( [ 'page_ids' => [ 12, 13, 15, 17, 999 ] ] );
        $this->assertSame( 2, $r['replaced_in'] );
        $this->assertSame( self::text( 'Hello AIED-OLD world' ), $this->layoutContent( 11 ) );
        $this->assertSame( self::text( 'Hello AIED-NEW world' ), $this->layoutContent( 12 ) );
    }

    public function testAPageTheUserCannotEditIsNeverSeen(): void
    {
        $GLOBALS['__wp_caps']['edit_post:13'] = false;
        $r = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $this->assertSame( [ 11, 12, 14 ], array_column( $r['items'], 'id' ) );
    }

    public function testLayoutsAreIncludedOnlyWithScopeAndThemeBuilderPermission(): void
    {
        $r = $this->tool->run( $this->args( [ 'dry_run' => true, 'scope' => 'layouts' ] ) );
        $this->assertSame( [ 201, 202 ], array_column( $r['items'], 'id' ) );
        $this->assertSame( [ 'header', 'footer' ], array_column( $r['items'], 'kind' ) );

        $r = $this->tool->run( $this->args( [ 'dry_run' => true, 'scope' => 'all' ] ) );
        $this->assertSame( [ 11, 12, 13, 14, 201, 202 ], array_column( $r['items'], 'id' ) );

        $GLOBALS['__divi_role_allowed'] = false;
        $r = $this->tool->run( $this->args( [ 'dry_run' => true, 'scope' => 'all' ] ) );
        $this->assertSame( [ 11, 12, 13, 14 ], array_column( $r['items'], 'id' ), 'without Divi\'s bar the layouts are left out' );
        $this->assertStringContainsString( 'skipped', $r['layouts_note'] );
        unset( $GLOBALS['__divi_role_allowed'] );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true ];   // no edit_theme_options
        $r = $this->tool->run( $this->args( [ 'dry_run' => true, 'scope' => 'all' ] ) );
        $this->assertSame( [ 11, 12, 13, 14 ], array_column( $r['items'], 'id' ) );
        $this->refused( $this->args( [ 'scope' => 'layouts' ] ), 'permission' );
    }

    public function testALayoutIsWrittenThroughTheLayoutKindAndClearsDiviCaches(): void
    {
        $r = $this->apply( [ 'scope' => 'layouts' ] );
        $this->assertSame( 2, $r['replaced_in'] );
        $this->assertSame( self::text( 'Header AIED-NEW' ), $this->layoutContent( 201 ) );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'] );
        $kinds = array_column( $this->repo->rows[ $r['batch']['id'] ]['items'], 'kind' );
        $this->assertSame( [ 'theme_layout_content', 'theme_layout_content' ], $kinds );

        $u = $this->undoEngine()->undo( $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( self::text( 'Header AIED-OLD' ), $this->layoutContent( 201 ) );
    }

    public function testAMixedBatchUndoesPagesAndLayoutsTogether(): void
    {
        $r    = $this->apply( [ 'scope' => 'all' ] );
        $undo = new \AiEditorDivi5\Pro\Batch\BatchUndo(
            [ 'post_content' => new \AiEditorDivi5\Pro\Kinds\PostContentKind(), 'theme_layout_content' => new \AiEditorDivi5\Pro\Kinds\ThemeLayoutKind() ],
            $this->log
        );
        $u = $undo->undo( $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( self::text( 'Footer AIED-OLD' ), $this->layoutContent( 202 ) );
        $this->assertSame( self::text( 'Hello AIED-OLD world' ), $this->layoutContent( 11 ) );
    }

    public function testNoMatchChangesAndRecordsNothing(): void
    {
        $r = $this->tool->run( [ 'find' => 'NOT-ANYWHERE', 'replace' => 'x' ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 0, $r['matched'] );
        $this->assertArrayNotHasKey( 'token', $r );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testASaveFailureSkipsTheItemAndRecordsNothing(): void
    {
        $p = $this->tool->run( $this->args( [ 'dry_run' => true ] ) );
        $GLOBALS['__wp_update_fail'] = true;
        $r = $this->tool->run( $this->args( [ 'token' => $p['token'] ] ) );
        $this->assertFalse( $r['changed'] );
        $this->assertCount( 4, $r['skipped'] );
        $this->assertSame( 'save_failed', $r['skipped'][0]['reason'] );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testAWriteWithoutAStoredSnapshotHasNoUndoPoint(): void
    {
        $write = static function ( int $id, string $content ): array {
            $GLOBALS['__wp_posts'][ $id ]->post_content = $content;

            return [ 'ok' => true, 'snapshot' => [ 'stored' => false, 'version_id' => null, 'reason' => 'too_large' ] ];
        };
        $tool = new FindReplaceSite( $this->log, $write, fn (): int => $this->now );
        $p    = $tool->run( $this->args( [ 'dry_run' => true, 'page_ids' => [ 11 ] ] ) );
        $r    = $tool->run( $this->args( [ 'page_ids' => [ 11 ], 'token' => $p['token'] ] ) );
        $u    = ( new \AiEditorDivi5\Pro\Batch\BatchUndo( [ 'post_content' => new \AiEditorDivi5\Pro\Kinds\PostContentKind() ], $this->log ) )->undo( $r['batch']['id'] );
        $this->assertSame( 'no_undo_point', $u['items'][0]['result'] );
        $this->assertFalse( $u['fully_undone'] );
    }

    public function testABatchThatCannotBeRecordedStillReportsTheWriteHonestly(): void
    {
        $this->repo->failInsert = true;
        $r                      = $this->apply( [ 'page_ids' => [ 11 ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertFalse( $r['batch']['undoable'] );
        $this->assertSame( self::text( 'Hello AIED-NEW world' ), $this->layoutContent( 11 ) );
    }

    public function testUndoNeverOverwritesAPageEditedSince(): void
    {
        $r = $this->apply( [ 'page_ids' => [ 11, 12 ] ] );
        $GLOBALS['__wp_posts'][12]->post_content = 'EDITED LATER';
        $u = ( new \AiEditorDivi5\Pro\Batch\BatchUndo( [ 'post_content' => new \AiEditorDivi5\Pro\Kinds\PostContentKind() ], $this->log ) )->undo( $r['batch']['id'] );
        $this->assertFalse( $u['fully_undone'] );
        $this->assertSame( 'EDITED LATER', $this->layoutContent( 12 ) );
        $this->assertSame( self::text( 'Hello AIED-OLD world' ), $this->layoutContent( 11 ) );
    }

    public function testBadArgumentsAreRejectedNotCast(): void
    {
        $this->refused( [ 'replace' => 'x' ], '"find" is required.' );
        $this->refused( [ 'find' => 5, 'replace' => 'x' ], '"find" must be a string.' );
        $this->refused( [ 'find' => '  ', 'replace' => 'x' ], '"find" must not be empty.' );
        $this->refused( [ 'find' => 'a' ], '"replace" is required' );
        $this->refused( [ 'find' => 'a', 'replace' => 5 ], '"replace" must be a string.' );
        $this->refused( [ 'find' => 'a', 'replace' => 'a' ], 'identical' );
        $this->refused( $this->args( [ 'scope' => 'everything' ] ), '"scope" must be one of: pages, layouts, all.' );
        $this->refused( $this->args( [ 'scope' => 5 ] ), '"scope" must be a string.' );
        $this->refused( $this->args( [ 'page_ids' => 'x' ] ), '"page_ids" must be a list.' );
        $this->refused( $this->args( [ 'page_ids' => [] ] ), '"page_ids" must not be empty.' );
        $this->refused( $this->args( [ 'page_ids' => [ '1' ] ] ), 'positive integers' );
        $this->refused( $this->args( [ 'page_ids' => [ 0 ] ] ), 'positive integers' );
        $this->refused( $this->args( [ 'dry_run' => 'yes' ] ), '"dry_run" must be true or false.' );
        $this->refused( $this->args( [ 'token' => 5 ] ), '"token" must be a string.' );
        $this->refused( [ 'find' => str_repeat( 'a', FindReplaceSite::MAX_BYTES + 1 ), 'replace' => 'x' ], 'at most' );
    }

    public function testItIsRegisteredAndNeedsEditPages(): void
    {
        $this->assertContains( 'find_replace_site', array_map( static fn ( $t ) => $t->name(), \AiEditorDivi5\Pro\Tools::all() ) );
        $this->assertSame( 'edit_pages', $this->tool->capability() );
        $d = $this->tool->definition();
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'find', 'replace' ], $d['inputSchema']['required'] );
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter 'PreviewTokenTest|FindReplaceSiteTest' 2>&1 | tail -8`
Expected: `Class "AiEditorDivi5\Pro\Site\PreviewToken" not found` / `FindReplaceSite` not found.

- [ ] **Step 3: Write the site layer and the tool**

Create `pro-addon/src/Site/SiteItems.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Site;

use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use AiEditorDivi5\Pro\ThemeBuilder\ThemeLayouts;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Which pages and Theme Builder layouts a site-wide tool may look at FOR THE CURRENT USER: Divi 5 pages the user can
 * edit, and (only with `edit_theme_options` and Divi's Theme Builder bar) the live header and footer layouts. One
 * definition, so `find_replace_site` and `audit_site` can never disagree about what "the site" is.
 */
final class SiteItems
{
    /** More Divi 5 pages than this and the tool asks for a narrower scope instead of silently looking at some. */
    public const PAGE_LIMIT = 1000;

    public const SCOPES = [ 'pages', 'layouts', 'all' ];

    /** Statuses a write may touch (trash and auto-drafts never). */
    public const EDIT_STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    /** Statuses an audit reads (a scheduled page is still a page). */
    public const AUDIT_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

    public const LAYOUTS_SKIPPED = 'Header and footer layouts were skipped: they need the edit_theme_options capability and Divi\'s Theme Builder permission.';

    /**
     * @param list<string> $statuses
     * @param list<int>|null $ids restrict to these post ids (pages and layouts alike); null = no restriction
     * @return array{items: list<array{kind:string, id:int, title:string, status:string}>, truncated: bool, layouts_note: ?string}
     */
    public static function collect( string $scope, array $statuses, ?array $ids ): array
    {
        $items     = [];
        $truncated = false;
        $note      = null;

        if ( 'layouts' !== $scope ) {
            $query = [
                'post_type'      => 'page',
                'post_status'    => $statuses,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'posts_per_page' => self::PAGE_LIMIT + 1,
                'no_found_rows'  => true,
                'meta_query'     => [ [ 'key' => '_et_pb_use_divi_5', 'value' => 'on' ] ],
            ];
            if ( null !== $ids ) {
                $query['post__in'] = [] === $ids ? [ 0 ] : $ids;
            }
            $found = array_map( 'intval', (array) get_posts( $query ) );
            if ( count( $found ) > self::PAGE_LIMIT ) {
                $truncated = true;
                $found     = array_slice( $found, 0, self::PAGE_LIMIT );
            }
            foreach ( $found as $id ) {
                $post = get_post( $id );
                if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
                    continue;
                }
                $items[] = [ 'kind' => 'page', 'id' => $id, 'title' => (string) $post->post_title, 'status' => (string) $post->post_status ];
            }
        }

        if ( 'pages' !== $scope ) {
            if ( ThemeLayouts::userMay() && current_user_can( 'edit_theme_options' ) ) {
                $layouts = ThemeLayouts::liveLayoutKinds();
                ksort( $layouts );
                foreach ( $layouts as $id => $kind ) {
                    if ( null !== $ids && ! in_array( $id, $ids, true ) ) {
                        continue;
                    }
                    $target = LayoutTarget::resolve( $id );
                    if ( null !== $target['error'] || null === $target['post'] ) {
                        continue;
                    }
                    $items[] = [ 'kind' => $kind, 'id' => $id, 'title' => (string) $target['post']->post_title, 'status' => (string) $target['post']->post_status ];
                }
            } else {
                $note = self::LAYOUTS_SKIPPED;
            }
        }

        return [ 'items' => $items, 'truncated' => $truncated, 'layouts_note' => $note ];
    }

    public static function content( int $id ): string
    {
        $post = get_post( $id );

        return $post ? (string) $post->post_content : '';
    }
}
```

Create `pro-addon/src/Site/PreviewToken.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Site;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The preview token of a site-wide write. It is self-contained and signed (`wp_hash`): it carries a hash of the
 * arguments, the id => content-hash of every item the preview would change, and the time it was made. A real run
 * must present it; items whose content changed since the preview are then skipped, never overwritten.
 */
final class PreviewToken
{
    public const TTL = 1800;

    /** @param array<int,string> $items item id => content hash */
    public static function make( string $argsHash, array $items, int $now ): string
    {
        $payload = rtrim( strtr( base64_encode( (string) json_encode( [ 'v' => 1, 'a' => $argsHash, 'i' => $items, 't' => $now ] ) ), '+/', '-_' ), '=' );

        return $payload . '.' . wp_hash( 'aied-site|' . $payload );
    }

    /**
     * @return array<int,string>|null the item map when the token is genuine, for these arguments and not expired
     */
    public static function open( string $token, string $argsHash, int $now ): ?array
    {
        $parts = explode( '.', $token );
        if ( 2 !== count( $parts ) || ! hash_equals( wp_hash( 'aied-site|' . $parts[0] ), $parts[1] ) ) {
            return null;
        }
        $data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );
        if ( ! is_array( $data ) || 1 !== ( $data['v'] ?? null ) || ! hash_equals( $argsHash, (string) ( $data['a'] ?? '' ) ) ) {
            return null;
        }
        $t = $data['t'] ?? null;
        if ( ! is_int( $t ) || $now < $t || $now - $t > self::TTL || ! is_array( $data['i'] ?? null ) ) {
            return null;
        }
        $items = [];
        foreach ( $data['i'] as $id => $hash ) {
            $items[ (int) $id ] = (string) $hash;
        }

        return $items;
    }
}
```

Create `pro-addon/src/Tools/FindReplaceSite.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\PostContentKind;
use AiEditorDivi5\Pro\Kinds\ThemeLayoutKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Site\PreviewToken;
use AiEditorDivi5\Pro\Site\SiteItems;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutCaches;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\LayoutDiff;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\PageEditor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Literal, case-sensitive find and replace across the site's Divi 5 pages and (with the Theme Builder permission) its
 * live header and footer, as ONE previewed, undoable batch. The replacement itself is the free plugin's own
 * `PageEditor::apply()` and every result must pass the shared layout gate with the stored content as baseline; an item
 * that would fail it is skipped and reported, never saved. A real run needs the token from a dry run of the same
 * arguments, and an item that changed since that preview is skipped.
 */
final class FindReplaceSite implements ProTool
{
    public const NAME      = 'find_replace_site';
    public const MAX_ITEMS = 50;
    public const MAX_BYTES = 20000;

    private \Closure $clock;

    /**
     * @param (\Closure(int,string,string):array<string,mixed>)|null $write fn(postId, content, tool): HistoryService::write()'s result
     * @param (\Closure():int)|null $clock fn(): unix time
     */
    public function __construct( private BatchLog $log, private ?\Closure $write = null, ?\Closure $clock = null )
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_pages';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Replace literal text across Divi 5 pages (and, with Theme Builder permission, the live header and footer) as one undoable batch. Always call with dry_run=true first, then repeat with the token it returns. Items that would fail the layout checks are skipped. At most 50 items per run.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'find'     => [ 'type' => 'string', 'description' => 'Exact text to find (case-sensitive, literal, not a pattern)' ],
                    'replace'  => [ 'type' => 'string', 'description' => 'Text to put in its place (may be empty)' ],
                    'scope'    => [ 'type' => 'string', 'description' => 'pages (default), layouts or all', 'enum' => SiteItems::SCOPES ],
                    'page_ids' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Optional. Only these page or layout ids' ],
                    'dry_run'  => [ 'type' => 'boolean', 'description' => 'Preview only; returns the token a real run needs' ],
                    'token'    => [ 'type' => 'string', 'description' => 'The token from the dry run of the same arguments' ],
                ],
                'required'   => [ 'find', 'replace' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $find = (string) Args::string( $arguments, 'find', true, self::MAX_BYTES );
        if ( ! array_key_exists( 'replace', $arguments ) || null === $arguments['replace'] ) {
            throw new \InvalidArgumentException( '"replace" is required (it may be an empty string).' );
        }
        $replace = (string) Args::string( $arguments, 'replace', false, self::MAX_BYTES );
        if ( $find === $replace ) {
            throw new \InvalidArgumentException( 'The "find" and "replace" text are identical; nothing to change.' );
        }
        $scope = array_key_exists( 'scope', $arguments ) && null !== $arguments['scope'] ? (string) Args::string( $arguments, 'scope', true, 20 ) : 'pages';
        if ( ! in_array( $scope, SiteItems::SCOPES, true ) ) {
            throw new \InvalidArgumentException( '"scope" must be one of: ' . implode( ', ', SiteItems::SCOPES ) . '.' );
        }
        $ids = null;
        if ( array_key_exists( 'page_ids', $arguments ) && null !== $arguments['page_ids'] ) {
            $ids = [];
            foreach ( Args::listOf( $arguments, 'page_ids', 200 ) as $id ) {
                if ( ! is_int( $id ) || $id < 1 ) {
                    throw new \InvalidArgumentException( '"page_ids" must be a list of positive integers.' );
                }
                $ids[] = $id;
            }
            $ids = array_values( array_unique( $ids ) );
            sort( $ids );
        }
        $dry   = Args::bool( $arguments, 'dry_run' );
        $token = Args::string( $arguments, 'token', false, 8000 );
        if ( 'layouts' === $scope && ! current_user_can( 'edit_theme_options' ) ) {
            throw new \InvalidArgumentException( 'You do not have permission to change header and footer layouts (edit_theme_options and Divi\'s Theme Builder permission).' );
        }

        $set = SiteItems::collect( $scope, SiteItems::EDIT_STATUSES, $ids );
        if ( $set['truncated'] ) {
            throw new \InvalidArgumentException( 'This site has more than ' . SiteItems::PAGE_LIMIT . ' Divi 5 pages, so the search would be incomplete. Narrow it with page_ids.' );
        }

        $candidates = [];
        foreach ( $set['items'] as $item ) {
            $content = SiteItems::content( $item['id'] );
            $count   = substr_count( $content, $find );
            if ( $count > 0 ) {
                $candidates[] = $item + [ 'content' => $content, 'occurrences' => $count, 'hash' => substr( Canon::hash( $content ), 0, 16 ) ];
            }
        }
        $extra = null === $set['layouts_note'] ? [] : [ 'layouts_note' => $set['layouts_note'] ];
        if ( [] === $candidates ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'matched' => 0, 'message' => 'The text was not found in any of the pages or layouts in scope. Nothing changed.' ] + $extra;
        }
        if ( count( $candidates ) > self::MAX_ITEMS ) {
            throw new \InvalidArgumentException( count( $candidates ) . ' items contain that text; the limit is ' . self::MAX_ITEMS . ' per run. Narrow it with page_ids or scope.' );
        }

        // The replacement and the gate, per item, against the STORED content.
        $plan = [];
        foreach ( $candidates as $c ) {
            $edit = PageEditor::apply( $c['content'], $find, $replace, $c['occurrences'] );
            $new  = $edit['ok'] ? $edit['content'] : $c['content'];
            $gate = LayoutGate::check( $new, $c['content'] );
            $plan[] = $c + [ 'new' => $new, 'valid' => $edit['ok'] && $gate->isValid(), 'codes' => self::codes( $gate ) ];
        }
        $argsHash = Canon::hash( [ 'find' => $find, 'replace' => $replace, 'scope' => $scope, 'ids' => $ids ] );

        if ( $dry ) {
            return $this->preview( $plan, $argsHash ) + $extra;
        }

        $map = null === $token ? null : PreviewToken::open( $token, $argsHash, ( $this->clock )() );
        if ( null === $map ) {
            throw new \InvalidArgumentException( 'A real run needs the token from a dry run of the same arguments, made in the last 30 minutes. Call again with dry_run=true.' );
        }

        return $this->apply( $plan, $map, $find ) + $extra;
    }

    /**
     * @param list<array<string,mixed>> $plan
     * @return array<string,mixed>
     */
    private function preview( array $plan, string $argsHash ): array
    {
        $items = [];
        $map   = [];
        $skip  = 0;
        foreach ( $plan as $p ) {
            $row = [ 'kind' => $p['kind'], 'id' => $p['id'], 'title' => $p['title'], 'occurrences' => $p['occurrences'], 'valid' => $p['valid'] ];
            if ( $p['valid'] ) {
                $map[ $p['id'] ] = $p['hash'];
                $diff            = LayoutDiff::compare( $p['content'], $p['new'] );
                $row['diff']     = $diff['diff']['summary'] ?? null;
            } else {
                $skip++;
                $row['violations'] = $p['codes'];
            }
            $items[] = $row;
        }

        return [
            'changed'      => false,
            'dry_run'      => true,
            'matched'      => count( $plan ),
            'would_change' => count( $map ),
            'would_skip'   => $skip,
            'items'        => $items,
            'token'        => PreviewToken::make( $argsHash, $map, ( $this->clock )() ),
            'message'      => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to apply it.',
        ];
    }

    /**
     * @param list<array<string,mixed>> $plan
     * @param array<int,string> $map id => content hash the preview saw
     * @return array<string,mixed>
     */
    private function apply( array $plan, array $map, string $find ): array
    {
        $write   = $this->write ?? static fn ( int $id, string $content, string $tool ): array => HistoryService::write( $id, $content, $tool );
        $items   = [];
        $skipped = [];
        $layouts = false;
        foreach ( $plan as $p ) {
            $row = [ 'kind' => $p['kind'], 'id' => $p['id'], 'title' => $p['title'] ];
            if ( ! $p['valid'] ) {
                $skipped[] = $row + [ 'reason' => 'invalid_result', 'violations' => $p['codes'] ];
                continue;
            }
            if ( ! isset( $map[ $p['id'] ] ) ) {
                $skipped[] = $row + [ 'reason' => 'not_in_preview' ];
                continue;
            }
            if ( $map[ $p['id'] ] !== $p['hash'] ) {
                $skipped[] = $row + [ 'reason' => 'changed_since_preview' ];
                continue;
            }
            $result = $write( $p['id'], $p['new'], self::NAME );
            if ( empty( $result['ok'] ) ) {
                $skipped[] = $row + [ 'reason' => 'save_failed' ];
                continue;
            }
            $snapshot = is_array( $result['snapshot'] ?? null ) ? $result['snapshot'] : [];
            $now      = get_post( $p['id'] );
            $stored   = $now ? (string) $now->post_content : $p['new'];
            if ( 'page' === $p['kind'] ) {
                $items[] = PostContentKind::itemFor( $p['id'], $p['title'], $stored, $snapshot );
            } else {
                $items[]  = ThemeLayoutKind::itemFor( $p['id'], $p['title'], $p['kind'], $stored, $snapshot );
                $layouts = true;
            }
        }
        if ( $layouts ) {
            LayoutCaches::clear();
        }
        if ( [] === $items ) {
            return [ 'changed' => false, 'dry_run' => false, 'replaced_in' => 0, 'skipped' => $skipped, 'message' => 'Nothing was saved.' ];
        }
        $short = strlen( $find ) > 60 ? substr( $find, 0, 57 ) . '...' : $find;
        $batch = $this->log->record( self::NAME, 'Replaced "' . $short . '" in ' . count( $items ) . ' item(s)', $items );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'replaced_in' => count( $items ), 'skipped' => $skipped ], $batch );
    }

    /** @return list<string> */
    private static function codes( \Divi5Validator\ValidationResult $gate ): array
    {
        $codes = [];
        foreach ( $gate->violations() as $v ) {
            $codes[ $v->toArray()['code'] ] = true;
        }

        return array_slice( array_keys( $codes ), 0, 10 );
    }
}
```

- [ ] **Step 4: Wire it in and pin what it now calls**

`pro-addon/src/autoload.php`: after `require_once __DIR__ . '/Tools/ManageThemeTemplate.php';` add
```php
require_once __DIR__ . '/Site/SiteItems.php';
require_once __DIR__ . '/Site/PreviewToken.php';
require_once __DIR__ . '/Tools/FindReplaceSite.php';
```

`pro-addon/src/Tools.php`: add `use AiEditorDivi5\Pro\Tools\FindReplaceSite;` after the `EditThemeLayout` import, and `new FindReplaceSite( $log ),` after `new ManageThemeTemplate( $log ),` in `all()`.

`pro-addon/src/Compat.php`: in `REQUIRED_CLASSES`, after the `LayoutGate` line add `'AiEditorDivi5\\WP\\LayoutDiff',` (the guard test `ThemeBuilderGuardTest` fails until every free class the add-on references is listed).

`pro-addon/tests/FreeApiCompatTest.php`: in `signatures()`, directly before the `DryRun`/`preview` row add:

```php
            [ $w . 'LayoutDiff', 'compare', [ 'before' => 'string', 'after' => 'string' ], 'array' ],
```

`pro-addon/tests/ToolsTest.php`: append `'find_replace_site'` to the expected tool list (after `'manage_theme_template'`).

`pro-addon/readme.txt`: before the `list_batches` bullet add
```
* `find_replace_site` - replace literal text across your Divi 5 pages (and, with Theme Builder permission, the header and footer) as one undoable batch. You must preview first (`dry_run`) and repeat with the token; pages whose result would fail the layout checks are skipped; at most 50 items per run.
```

- [ ] **Step 5: Run the whole Pro suite**

Run: `make test-pro 2>&1 | tail -3` → all green with no failures, risky tests, deprecations or warnings (`OK (612 tests, 2362 assertions)`).

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src pro-addon/tests pro-addon/readme.txt
git commit -q -F - <<'EOF'
feat(pro): find_replace_site (previewed, token-bound, one undoable batch of at most 50 items)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).

### Task 3: `audit_site`

**Files:**
- Create: `pro-addon/src/Site/LinkScanner.php`, `pro-addon/src/Tools/AuditSite.php`
- Create: `pro-addon/tests/LinkScannerTest.php`, `pro-addon/tests/AuditSiteTest.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Tools.php`, `pro-addon/tests/ToolsTest.php`, `pro-addon/readme.txt`

**Interfaces:**
- Consumes: `SiteItems::collect( 'pages'|'layouts'|'all', AUDIT_STATUSES, null )` and `SiteItems::content()` (Task 2), `WpSiteShims` (Task 2), the free `LayoutGate::check( string ): ValidationResult` (no baseline: stored custom code is reported) and `StyleReferences::attach( array, string ): array` (pinned), WordPress `url_to_postid()`, `get_post_status()`, `home_url()`.
- Produces: `LinkScanner::internalPaths( string $content, string $siteHost ): list<string>` (`MAX_LINKS = 200`); `AuditSite` (`NAME`, `PAGE_SIZE = 25`, `MAX_FINDINGS = 200`, codes `LINK_NO_POST`, `LINK_NOT_PUBLISHED`, `UNKNOWN_PRESET_ID`, `UNKNOWN_COLOR_ID`; constructor `( ?\Closure $styleAdvisor = null, int $maxFindings = 200 )`, capability `edit_pages`). Result: `scope`, `total_items`, `scanned`, `items_with_findings`, `findings` (each `kind,id,title,code,count,detail`), `findings_truncated`, `totals_by_code`, `next_cursor` (int or null), optional `note`, `layouts_note`.

`LinkScannerTest` builds its escaped-HTML cases with `chr( 92 )` on purpose (see "Execution notes"): do not retype those strings.

- [ ] **Step 1: Write the failing suites**

Create `pro-addon/tests/LinkScannerTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Site\LinkScanner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class LinkScannerTest extends TestCase
{
    /** A button module with the given linkUrl, the way real Divi 5 content stores it. */
    private static function button( string $url ): string
    {
        return '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go","linkUrl":"' . $url . '"}}}},"builderVersion":"5.14.0"} /-->';
    }

    /** The JSON escape of a double quote inside block attributes (backslash, u, 0022), built without typing it. */
    private static function q(): string
    {
        return chr( 92 ) . 'u0022';
    }

    /** A text module whose escaped HTML contains the given anchor opening, as WordPress stores block attributes. */
    private static function html( string $anchor ): string
    {
        $lt = chr( 92 ) . 'u003c';
        $gt = chr( 92 ) . 'u003e';

        return '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"' . $lt . 'p' . $gt . $lt . $anchor . $gt . 'x' . $lt . '/a' . $gt . $lt . '/p' . $gt . '"}}}} /-->';
    }

    /** @return array<string,array{string,list<string>}> */
    public static function cases(): array
    {
        return [
            'root-relative page'            => [ self::button( '/about/' ), [ '/about/' ] ],
            'absolute own site'             => [ self::button( 'https://example.com/services/' ), [ '/services/' ] ],
            'own site with www and query'   => [ self::button( 'https://www.example.com/?p=7' ), [ '/?p=7' ] ],
            'other host'                    => [ self::button( 'https://other.com/about/' ), [] ],
            'a subdomain is another host'   => [ self::button( 'https://shop.example.com/' ), [] ],
            'anchor'                        => [ self::button( '#contact' ), [] ],
            'hash only'                     => [ self::button( '#' ), [] ],
            'home page'                     => [ self::button( '/' ), [] ],
            'own home absolute'             => [ self::button( 'https://example.com/' ), [] ],
            'mailto'                        => [ self::button( 'mailto:a@b.co' ), [] ],
            'tel'                           => [ self::button( 'tel:+123' ), [] ],
            'javascript'                    => [ self::button( 'javascript:void(0)' ), [] ],
            'protocol-relative'             => [ self::button( '//example.com/x' ), [] ],
            'relative without slash'        => [ self::button( 'about' ), [] ],
            'dynamic variable'              => [ self::button( '$variable({\"type\":\"content\"})$' ), [] ],
            'uploads'                       => [ self::button( '/wp-content/uploads/2026/a.pdf' ), [] ],
            'admin and rest'                => [ self::button( '/wp-admin/x' ) . self::button( '/wp-json/x' ) . self::button( '/wp-login.php' ), [] ],
            'fragment is dropped'           => [ self::button( '/about/#team' ), [ '/about/' ] ],
            'the same link twice'           => [ self::button( '/about/' ) . self::button( '/about/' ), [ '/about/' ] ],
            'image url on this site'        => [ '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"x","url":"https://example.com/page-with-image/"}}}}} /-->', [ '/page-with-image/' ] ],
            'escaped slashes decode'        => [ self::button( 'https:\/\/example.com\/escaped\/' ), [ '/escaped/' ] ],
            'href in escaped html'          => [ self::html( 'a href=' . self::q() . '/in-text/' . self::q() ), [ '/in-text/' ] ],
            'external href in html'         => [ self::html( 'a href=' . self::q() . 'https://elsewhere.org/' . self::q() ), [] ],
            'no links at all'               => [ '<!-- wp:divi/section /-->', [] ],
            'empty'                         => [ '', [] ],
        ];
    }

    /** @param list<string> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'cases' )]
    public function testInternalPaths( string $content, array $expected ): void
    {
        $this->assertSame( $expected, LinkScanner::internalPaths( $content, 'example.com' ) );
    }

    public function testItNeverReturnsMoreThanTheCap(): void
    {
        $content = '';
        for ( $i = 0; $i < LinkScanner::MAX_LINKS + 50; $i++ ) {
            $content .= self::button( "/page-{$i}/" );
        }
        $this->assertCount( LinkScanner::MAX_LINKS, LinkScanner::internalPaths( $content, 'example.com' ) );
    }

    public function testItMakesNoRemoteRequest(): void
    {
        $source = (string) file_get_contents( __DIR__ . '/../src/Site/LinkScanner.php' );
        $this->assertDoesNotMatchRegularExpression( '/wp_remote_|curl_|file_get_contents\s*\(|fsockopen|stream_socket/i', $source );
    }
}
```

Create `pro-addon/tests/AuditSiteTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Tools\AuditSite;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';
require_once __DIR__ . '/WpSiteShims.php';

class AuditSiteTest extends TestCase
{
    use ThemeLayoutFixture;

    private AuditSite $tool;

    /** A valid layout with one button linking to $url (empty: no button). */
    private static function page( string $url = '' ): string
    {
        $button = '' === $url ? '' : '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go","linkUrl":"' . $url . '"}}}},"builderVersion":"5.14.0"} /-->';

        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} -->' . $button . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $this->tool = new AuditSite();
        $GLOBALS['__wp_caps']['edit_posts'] = true;
        $GLOBALS['__wp_posts'][5]->post_content = '';
        // link targets (plain objects: the WP_Post shim has no post_name property)
        $GLOBALS['__wp_posts'][90] = (object) [ 'ID' => 90, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_content' => '', 'post_name' => 'about' ];
        $GLOBALS['__wp_posts'][91] = (object) [ 'ID' => 91, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Draft', 'post_content' => '', 'post_name' => 'draft-page' ];
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
    }

    private function divi( int $id, string $content, string $status = 'publish' ): void
    {
        $this->themePost( $id, 'page', $status, [ '_et_pb_use_divi_5' => 'on' ], $content );
    }

    /** @param array<string,mixed> $r @return array<string,list<array<string,mixed>>> findings of one item by code */
    private static function byItem( array $r, int $id ): array
    {
        $out = [];
        foreach ( $r['findings'] as $f ) {
            if ( $f['id'] === $id ) {
                $out[ $f['code'] ][] = $f;
            }
        }

        return $out;
    }

    public function testACleanSiteHasNoFindings(): void
    {
        $this->divi( 11, self::page() );
        $this->divi( 12, self::page( '/about/' ) );
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $this->assertSame( 2, $r['total_items'] );
        $this->assertSame( 2, $r['scanned'] );
        $this->assertSame( [], $r['findings'] );
        $this->assertSame( [], $r['totals_by_code'] );
        $this->assertSame( 0, $r['items_with_findings'] );
        $this->assertNull( $r['next_cursor'] );
        $this->assertFalse( $r['findings_truncated'] );
    }

    public function testLayoutCheckerViolationsAreReportedWithTheirCodes(): void
    {
        $this->divi( 11, str_replace( 'wp:divi/button', 'wp:divi/not-a-module', self::page( '/about/' ) ) );
        $this->divi( 12, str_replace( 'wp:divi/button {', 'wp:divi/button {"css":{"desktop":{"value":{"freeForm":"selector{color:red}"}}},', self::page( '/about/' ) ) );
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $a = self::byItem( $r, 11 );
        $this->assertNotEmpty( $a, 'the unknown module is a finding' );
        $this->assertStringContainsString( 'not-a-module', json_encode( $a ) );
        $b = self::byItem( $r, 12 );
        $this->assertArrayHasKey( 'CUSTOM_CSS_NOT_ALLOWED', $b, 'custom code already stored is reported' );
        $this->assertGreaterThan( 0, $r['totals_by_code']['CUSTOM_CSS_NOT_ALLOWED'] );
    }

    public function testUnknownPresetAndColorIdsComeFromTheFreePluginsAdvisory(): void
    {
        $advisor = static fn ( string $content ): ?array => str_contains( $content, 'MARK' ) ? [
            'unknown_presets' => [ [ 'id' => 'preset-x', 'count' => 2 ], [ 'id' => 'preset-y', 'count' => 1 ] ],
            'unknown_colors'  => [ [ 'id' => 'gcid-abc', 'count' => 1 ] ],
        ] : null;
        $tool = new AuditSite( $advisor );
        $this->divi( 11, self::page( '/about/' ) . '<!-- MARK -->' );
        $this->divi( 12, self::page() );
        $r = $tool->run( [ 'scope' => 'pages' ] );
        $a = self::byItem( $r, 11 );
        $this->assertSame( 2, $a['UNKNOWN_PRESET_ID'][0]['count'] );
        $this->assertSame( 'preset-x, preset-y', $a['UNKNOWN_PRESET_ID'][0]['detail'] );
        $this->assertSame( 'gcid-abc', $a['UNKNOWN_COLOR_ID'][0]['detail'] );
        $this->assertSame( [], self::byItem( $r, 12 ) );
    }

    public function testInternalLinksToNothingAndToUnpublishedPagesAreFound(): void
    {
        $content = self::page( '/missing/' ) . self::page( '/draft-page/' ) . self::page( 'https://example.com/about/' ) . self::page( 'https://other.com/x/' ) . self::page( '#top' ) . self::page( 'mailto:a@b.co' ) . self::page( '/wp-content/uploads/a.pdf' ) . self::page( '/' );
        $this->divi( 11, $content );
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $a = self::byItem( $r, 11 );
        $this->assertSame( 1, $a['LINK_NO_POST'][0]['count'] );
        $this->assertStringContainsString( '/missing/', $a['LINK_NO_POST'][0]['detail'] );
        $this->assertSame( 1, $a['LINK_NOT_PUBLISHED'][0]['count'] );
        $this->assertStringContainsString( '/draft-page/ (draft)', $a['LINK_NOT_PUBLISHED'][0]['detail'] );
        $this->assertArrayNotHasKey( 'LINK_NO_POST', array_diff_key( $a, [ 'LINK_NO_POST' => 1 ] ) );
    }

    public function testItIsPaginatedTwentyFivePerCall(): void
    {
        for ( $i = 100; $i < 160; $i++ ) {
            $this->divi( $i, self::page() );
        }
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $this->assertSame( [ 60, 25, 25 ], [ $r['total_items'], $r['scanned'], $r['next_cursor'] ] );
        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 25 ] );
        $this->assertSame( [ 25, 50 ], [ $r['scanned'], $r['next_cursor'] ] );
        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 50 ] );
        $this->assertSame( [ 10, null ], [ $r['scanned'], $r['next_cursor'] ] );
        $r = $this->tool->run( [ 'scope' => 'pages', 'cursor' => 500 ] );
        $this->assertSame( [ 0, null ], [ $r['scanned'], $r['next_cursor'] ] );
    }

    public function testFindingsAreCappedButTheTotalsStayComplete(): void
    {
        $tool = new AuditSite( null, 3 );
        for ( $i = 100; $i < 105; $i++ ) {
            $this->divi( $i, self::page( "/gone-{$i}/" ) );
        }
        $r = $tool->run( [ 'scope' => 'pages' ] );
        $this->assertCount( 3, $r['findings'] );
        $this->assertTrue( $r['findings_truncated'] );
        $this->assertSame( 5, $r['totals_by_code']['LINK_NO_POST'] );
        $this->assertSame( 5, $r['items_with_findings'] );
    }

    public function testItNeverChangesAnything(): void
    {
        $this->divi( 11, self::page( '/missing/' ) );
        $this->divi( 12, str_replace( 'wp:divi/button', 'wp:divi/not-a-module', self::page() ) );
        $before = [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'], $GLOBALS['__wp_options'] ];
        $this->tool->run( [] );
        $this->tool->run( [ 'scope' => 'layouts' ] );
        $this->assertEquals( $before, [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'], $GLOBALS['__wp_options'] ] );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testItContainsNoWriteOrRemoteCall(): void
    {
        $source = (string) file_get_contents( __DIR__ . '/../src/Tools/AuditSite.php' );
        $this->assertDoesNotMatchRegularExpression( '/wp_update_post|wp_insert_post|update_post_meta|add_post_meta|delete_post_meta|update_option|HistoryService|wp_remote_|curl_|file_get_contents\s*\(/', $source );
    }

    public function testOnlyDivi5PagesTheUserCanEditAndNotTrashedAreAudited(): void
    {
        $this->divi( 11, self::page() );
        $this->divi( 12, self::page(), 'draft' );
        $this->divi( 13, self::page(), 'future' );
        $this->divi( 14, self::page(), 'trash' );
        $this->themePost( 15, 'page', 'publish', [], self::page() );
        $this->divi( 16, self::page() );
        $GLOBALS['__wp_caps']['edit_post:16'] = false;
        $r = $this->tool->run( [ 'scope' => 'pages' ] );
        $this->assertSame( 3, $r['total_items'], 'publish, draft and future; not trash, not classic, not a page the user cannot edit' );
    }

    public function testLayoutsNeedTheThemeBuilderPermission(): void
    {
        $GLOBALS['__wp_posts'][201]->post_content = str_replace( 'wp:divi/shop', 'wp:divi/not-a-module', self::header() );
        $r = $this->tool->run( [ 'scope' => 'layouts' ] );
        $this->assertSame( 2, $r['total_items'] );
        $this->assertNotEmpty( self::byItem( $r, 201 ) );
        $this->assertSame( [], self::byItem( $r, 202 ) );

        $this->divi( 11, self::page() );
        $GLOBALS['__divi_role_allowed'] = false;
        $r = $this->tool->run( [] );
        $this->assertSame( 1, $r['total_items'], 'layouts are left out without Divi\'s bar' );
        $this->assertStringContainsString( 'skipped', $r['layouts_note'] );
        unset( $GLOBALS['__divi_role_allowed'] );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true ];
        $r = $this->tool->run( [] );
        $this->assertSame( 1, $r['total_items'] );
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'permission' );
        $this->tool->run( [ 'scope' => 'layouts' ] );
    }

    public function testBadArgumentsAreRejectedNotCast(): void
    {
        foreach ( [
            [ [ 'scope' => 'everything' ], '"scope" must be one of: pages, layouts, all.' ],
            [ [ 'scope' => 5 ], '"scope" must be a string.' ],
            [ [ 'cursor' => '25' ], '"cursor" must be an integer.' ],
            [ [ 'cursor' => -1 ], '"cursor" must be at least 0.' ],
            [ [ 'cursor' => 1.5 ], '"cursor" must be an integer.' ],
        ] as [ $args, $message ] ) {
            try {
                $this->tool->run( $args );
                $this->fail( $message );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertSame( $message, $e->getMessage() );
            }
        }
    }

    public function testItIsRegisteredAndNeedsEditPages(): void
    {
        $this->assertContains( 'audit_site', array_map( static fn ( $t ) => $t->name(), \AiEditorDivi5\Pro\Tools::all() ) );
        $this->assertSame( 'edit_pages', $this->tool->capability() );
        $d = $this->tool->definition();
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [], $d['inputSchema']['required'] );
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter 'LinkScannerTest|AuditSiteTest' 2>&1 | tail -8`
Expected: `Class "AiEditorDivi5\Pro\Site\LinkScanner" not found` / `AuditSite` not found.

- [ ] **Step 3: Write the scanner and the tool**

Create `pro-addon/src/Site/LinkScanner.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Site;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Finds the INTERNAL links in a Divi 5 layout, as root-relative paths, without ever making a request. Link strings
 * are read from the two places real Divi 5 content keeps them (verified against the real exports in fixtures/valid/):
 * block attributes whose key ends in `url`, `Url`, `link` or `href` (e.g. `"linkUrl":"/about/"`, `"url":"https://..."`),
 * and `href=` attributes inside the escaped HTML of text modules (`href="/about/"`). External links, anchors,
 * mailto/tel/javascript/data links, dynamic `$variable(...)` values, the home page and WordPress's own paths
 * (uploads, admin, REST) are not internal links to a page and are left out.
 */
final class LinkScanner
{
    public const MAX_LINKS = 200;

    private const ATTRIBUTE = '/"[A-Za-z]*(?:[Uu]rl|[Ll]ink|[Hh]ref)"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/';
    private const HTML_HREF = '/href=\\\\u0022(.*?)\\\\u0022/s';
    private const SKIPPED   = '#^/(?:wp-content|wp-includes|wp-admin|wp-json|wp-login\.php|feed)(?:/|$|\?)#i';

    /** @return list<string> root-relative paths (with their query string), unique, at most MAX_LINKS */
    public static function internalPaths( string $content, string $siteHost ): array
    {
        $values = [];
        if ( preg_match_all( self::ATTRIBUTE, $content, $m ) ) {
            foreach ( $m[1] as $raw ) {
                $values[] = self::decode( $raw );
            }
        }
        if ( preg_match_all( self::HTML_HREF, $content, $m ) ) {
            foreach ( $m[1] as $raw ) {
                $values[] = self::decode( $raw );
            }
        }
        $paths = [];
        foreach ( $values as $value ) {
            $path = self::toInternalPath( trim( $value ), strtolower( preg_replace( '/^www\./i', '', $siteHost ) ?? $siteHost ) );
            if ( null !== $path ) {
                $paths[ $path ] = true;
                if ( count( $paths ) >= self::MAX_LINKS ) {
                    break;
                }
            }
        }

        return array_keys( $paths );
    }

    private static function decode( string $raw ): string
    {
        $decoded = json_decode( '"' . $raw . '"' );

        return is_string( $decoded ) ? $decoded : $raw;
    }

    private static function toInternalPath( string $value, string $host ): ?string
    {
        if ( '' === $value || '#' === $value[0] || str_starts_with( $value, '$variable(' ) || 1 === preg_match( '/^(?:mailto|tel|sms|javascript|data):/i', $value ) ) {
            return null;
        }
        if ( 1 === preg_match( '#^https?://#i', $value ) ) {
            $parts = parse_url( $value );
            if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || strtolower( preg_replace( '/^www\./i', '', (string) $parts['host'] ) ?? '' ) !== $host ) {
                return null;
            }
            $value = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
        }
        if ( '/' !== $value[0] || str_starts_with( $value, '//' ) ) {
            return null;
        }
        $value = (string) preg_replace( '/#.*$/', '', $value );
        if ( '/' === $value || '' === $value || 1 === preg_match( self::SKIPPED, $value ) ) {
            return null;
        }

        return $value;
    }
}
```

Create `pro-addon/src/Tools/AuditSite.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Site\LinkScanner;
use AiEditorDivi5\Pro\Site\SiteItems;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\StyleReferences;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A read-only, deterministic audit of the site's Divi 5 pages and live header/footer: layout checker violations
 * (including custom code the plugin will not save), preset and global-color ids the site does not have, and internal
 * links that point at no page or at an unpublished one. 25 items per call with a cursor. No AI, no remote request,
 * nothing is ever written.
 */
final class AuditSite implements ProTool
{
    public const NAME         = 'audit_site';
    public const PAGE_SIZE    = 25;
    public const MAX_FINDINGS = 200;

    public const LINK_NO_POST       = 'LINK_NO_POST';
    public const LINK_NOT_PUBLISHED = 'LINK_NOT_PUBLISHED';
    public const UNKNOWN_PRESET     = 'UNKNOWN_PRESET_ID';
    public const UNKNOWN_COLOR      = 'UNKNOWN_COLOR_ID';

    private \Closure $styleAdvisor;

    /**
     * @param (\Closure(string):?array<string,mixed>)|null $styleAdvisor fn(content): the free plugin's style-reference advisory or null
     */
    public function __construct( ?\Closure $styleAdvisor = null, private int $maxFindings = self::MAX_FINDINGS )
    {
        $this->styleAdvisor = $styleAdvisor ?? static fn ( string $content ): ?array => StyleReferences::attach( [], $content )['advisories']['style_references'] ?? null;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_pages';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Read-only audit of Divi 5 pages and the live header/footer: layout check violations, unknown preset or color ids, and internal links to missing or unpublished pages. 25 items per call; pass the returned next_cursor to continue. Makes no remote request.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'scope'  => [ 'type' => 'string', 'description' => 'pages, layouts or all (default all)', 'enum' => SiteItems::SCOPES ],
                    'cursor' => [ 'type' => 'integer', 'description' => 'Where to continue: the next_cursor of the previous call (default 0)' ],
                ],
                'required'   => [],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $scope = array_key_exists( 'scope', $arguments ) && null !== $arguments['scope'] ? (string) Args::string( $arguments, 'scope', true, 20 ) : 'all';
        if ( ! in_array( $scope, SiteItems::SCOPES, true ) ) {
            throw new \InvalidArgumentException( '"scope" must be one of: ' . implode( ', ', SiteItems::SCOPES ) . '.' );
        }
        $cursor = (int) Args::int( $arguments, 'cursor', false, 0 );
        if ( 'layouts' === $scope && ! current_user_can( 'edit_theme_options' ) ) {
            throw new \InvalidArgumentException( 'You do not have permission to read header and footer layouts (edit_theme_options and Divi\'s Theme Builder permission).' );
        }

        $set   = SiteItems::collect( $scope, SiteItems::AUDIT_STATUSES, null );
        $total = count( $set['items'] );
        $slice = array_slice( $set['items'], $cursor, self::PAGE_SIZE );
        $host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );

        $findings = [];
        $totals   = [];
        $problems = 0;
        foreach ( $slice as $item ) {
            $found = $this->auditItem( SiteItems::content( $item['id'] ), $host );
            if ( [] !== $found ) {
                $problems++;
            }
            foreach ( $found as $f ) {
                $totals[ $f['code'] ] = ( $totals[ $f['code'] ] ?? 0 ) + 1;
                $findings[]           = [ 'kind' => $item['kind'], 'id' => $item['id'], 'title' => $item['title'] ] + $f;
            }
        }
        $truncated = count( $findings ) > $this->maxFindings;
        ksort( $totals );

        $out = [
            'scope'              => $scope,
            'total_items'        => $total,
            'scanned'            => count( $slice ),
            'items_with_findings' => $problems,
            'findings'           => array_slice( $findings, 0, $this->maxFindings ),
            'findings_truncated' => $truncated,
            'totals_by_code'     => $totals,
            'next_cursor'        => $cursor + self::PAGE_SIZE < $total ? $cursor + self::PAGE_SIZE : null,
        ];
        if ( $set['truncated'] ) {
            $out['note'] = 'This site has more than ' . SiteItems::PAGE_LIMIT . ' Divi 5 pages; only the first ' . SiteItems::PAGE_LIMIT . ' (by id) are audited.';
        }
        if ( null !== $set['layouts_note'] ) {
            $out['layouts_note'] = $set['layouts_note'];
        }

        return $out;
    }

    /**
     * @return list<array{code:string, count:int, detail:string}>
     */
    private function auditItem( string $content, string $host ): array
    {
        $findings = [];

        // 1. Layout checker (structure, schema, and the no-custom-code policy), on the stored content as it is.
        $byCode = [];
        foreach ( LayoutGate::check( $content )->violations() as $v ) {
            $a = $v->toArray();
            $byCode[ $a['code'] ]['count']  = ( $byCode[ $a['code'] ]['count'] ?? 0 ) + 1;
            $byCode[ $a['code'] ]['detail'] ??= $a['path'] . ': ' . $a['message'];
        }
        foreach ( $byCode as $code => $row ) {
            $findings[] = [ 'code' => (string) $code, 'count' => $row['count'], 'detail' => $row['detail'] ];
        }

        // 2. Preset and global-color ids this site does not have (the free plugin's own advisory).
        $advice = ( $this->styleAdvisor )( $content );
        if ( is_array( $advice ) ) {
            foreach ( [ 'unknown_presets' => self::UNKNOWN_PRESET, 'unknown_colors' => self::UNKNOWN_COLOR ] as $key => $code ) {
                $rows = is_array( $advice[ $key ] ?? null ) ? $advice[ $key ] : [];
                if ( [] !== $rows ) {
                    $findings[] = [ 'code' => $code, 'count' => count( $rows ), 'detail' => implode( ', ', array_slice( array_map( static fn ( $r ): string => (string) ( $r['id'] ?? '' ), $rows ), 0, 5 ) ) ];
                }
            }
        }

        // 3. Internal links to nothing, or to something that is not published. Read from the content; nothing is requested.
        $missing = [];
        $draft   = [];
        $base    = rtrim( home_url(), '/' );
        foreach ( LinkScanner::internalPaths( $content, $host ) as $path ) {
            $id = (int) url_to_postid( $base . $path );
            if ( 0 === $id ) {
                $missing[] = $path;
                continue;
            }
            $status = (string) get_post_status( $id );
            if ( 'publish' !== $status ) {
                $draft[] = $path . ' (' . ( '' === $status ? 'unknown' : $status ) . ')';
            }
        }
        if ( [] !== $missing ) {
            $findings[] = [ 'code' => self::LINK_NO_POST, 'count' => count( $missing ), 'detail' => implode( ', ', array_slice( $missing, 0, 5 ) ) . ' (no page or post has this address; it may be an archive or a custom route)' ];
        }
        if ( [] !== $draft ) {
            $findings[] = [ 'code' => self::LINK_NOT_PUBLISHED, 'count' => count( $draft ), 'detail' => implode( ', ', array_slice( $draft, 0, 5 ) ) ];
        }

        return $findings;
    }
}
```

- [ ] **Step 4: Wire it in**

`pro-addon/src/autoload.php`: after `require_once __DIR__ . '/Tools/FindReplaceSite.php';` add
```php
require_once __DIR__ . '/Site/LinkScanner.php';
require_once __DIR__ . '/Tools/AuditSite.php';
```

`pro-addon/src/Tools.php`: add `use AiEditorDivi5\Pro\Tools\AuditSite;` before the `EditThemeLayout` import, and `new AuditSite(),` after `new FindReplaceSite( $log ),` in `all()`.

`pro-addon/tests/ToolsTest.php`: append `'audit_site'` to the expected tool list (after `'find_replace_site'`).

`pro-addon/readme.txt`: before the `list_batches` bullet add
```
* `audit_site` - a read-only check of your Divi 5 pages and header/footer: layout check violations (including custom code the plugin would not save), unknown preset or color ids, and internal links to missing or unpublished pages. 25 items per call. It never makes a remote request and never changes anything.
```

- [ ] **Step 5: Run the whole Pro suite**

Run: `make test-pro 2>&1 | tail -3` → all green with no failures, risky tests, deprecations or warnings (`OK (655 tests, 2454 assertions)`).

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src pro-addon/tests pro-addon/readme.txt
git commit -q -F - <<'EOF'
feat(pro): audit_site (read-only, deterministic: layout checks, style ids, internal links; 25 per call with a cursor)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).

### Task 4: REST routes and ChatGPT OpenAPI for every Pro tool

**Files:**
- Create: `pro-addon/src/Rest/ProRest.php`, `pro-addon/src/Rest/ProOpenApi.php`
- Create: `pro-addon/tests/WpRestRouteShims.php`, `pro-addon/tests/ProRestTest.php`, `pro-addon/tests/ProParityTest.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Plugin.php`, `pro-addon/src/Compat.php`, `pro-addon/tests/FreeApiCompatTest.php`

**Interfaces:**
- Consumes: `ToolRegistry` (`get`, `tools`, `isUnlocked`, `definitions`), `ToolRunner::run` (Task 1); the free plugin's hooks `jhmg_aied_register_rest_routes( string $namespace )`, `jhmg_aied_openapi_paths( array $paths, string $base )`, `jhmg_aied_openapi_schemas( array $schemas )`, `RestController::require_edit_posts(): bool|WP_Error` (public instance method, new pin) and `OpenApiSpec::spec( string $base, string $version ): array`.
- Produces: `ProRest` (`PREFIX = '/pro/'`; `register()`, `routes( mixed $namespace )`, `authorize()`, `handle( string $name, WP_REST_Request $request )`) and `ProOpenApi` (`register()`, `paths()`, `schemas()`): `POST /pro/<tool_name>` per tool and one OpenAPI operation per tool (`operationId` camelCase of the name; schemas `ProResult` and `ProError`). Both register nothing while the licence is locked, and `handle()` refuses a call made after a revoke (403 `pro_locked`). Error mapping: `ToolRunner`'s status and code become a `WP_Error` (400 `invalid_argument`, 403 `forbidden`, 500 `tool_failed`); a body that is a JSON list is 400.

`ProParityTest` is the lockstep proof: MCP, REST and the spec expose exactly the same tools with the same arguments, the spec has at most 30 operations (16 free + 13 Pro = 29), every description and summary at most 300 characters, unique operationIds, no bare object schema, and nothing is added while locked.

- [ ] **Step 1: Write the shims and the failing suites**

Create `pro-addon/tests/WpRestRouteShims.php`:

```php
<?php

declare(strict_types=1);

/**
 * Records `register_rest_route()` calls in `$GLOBALS['__wp_rest_routes']` (list of [namespace, route, args]) and
 * answers `is_user_logged_in()` from `$GLOBALS['__wp_logged_in']`. Guarded like every shim.
 */
$GLOBALS['__wp_rest_routes'] = [];
$GLOBALS['__wp_logged_in']   = false;

if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( $namespace, $route, $args = [] ) {
        $GLOBALS['__wp_rest_routes'][] = [ (string) $namespace, (string) $route, $args ];

        return true;
    }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
    function is_user_logged_in() {
        return (bool) $GLOBALS['__wp_logged_in'];
    }
}
```

Create `pro-addon/tests/ProRestTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Rest\ProRest;
use AiEditorDivi5\Pro\ToolRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/FakeTool.php';
require_once __DIR__ . '/WpRestRouteShims.php';

class ProRestTest extends TestCase
{
    private bool $unlocked = true;

    protected function setUp(): void
    {
        $this->unlocked              = true;
        $GLOBALS['__wp_filters']     = [];
        $GLOBALS['__wp_rest_routes'] = [];
        $GLOBALS['__wp_logged_in']   = false;
        $GLOBALS['__wp_can']         = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_filters'] = [];
        $GLOBALS['__wp_can']     = true;
        $GLOBALS['__wp_logged_in'] = false;
        unset( $GLOBALS['__wp_caps'] );
    }

    /** @param list<FakeTool> $tools */
    private function rest( array $tools ): ProRest
    {
        return new ProRest( new ToolRegistry( $tools, fn (): bool => $this->unlocked ) );
    }

    /** @param array<string,mixed> $body */
    private function call( ProRest $rest, string $name, array $body = [] ): mixed
    {
        return $rest->handle( $name, new \WP_REST_Request( $body ) );
    }

    public function testItAddsOnePostRoutePerToolOnTheFreePluginsHook(): void
    {
        $rest = $this->rest( [ new FakeTool( 'fake_pro_tool' ), new FakeTool( 'other_tool' ) ] );
        $rest->register();
        $this->assertArrayHasKey( 'jhmg_aied_register_rest_routes', $GLOBALS['__wp_filters'] );
        do_action( 'jhmg_aied_register_rest_routes', 'ai-editor-divi5/v1' );
        $this->assertSame( [ '/pro/fake_pro_tool', '/pro/other_tool' ], array_column( $GLOBALS['__wp_rest_routes'], 1 ) );
        foreach ( $GLOBALS['__wp_rest_routes'] as [ $ns, , $args ] ) {
            $this->assertSame( 'ai-editor-divi5/v1', $ns );
            $this->assertSame( 'POST', $args['methods'] );
            $this->assertSame( [ $rest, 'authorize' ], $args['permission_callback'], 'the free plugin\'s authentication, never __return_true' );
        }
    }

    public function testWhileLockedNoRouteIsRegistered(): void
    {
        $this->unlocked = false;
        $rest           = $this->rest( [ new FakeTool() ] );
        $rest->routes( 'ai-editor-divi5/v1' );
        $this->assertSame( [], $GLOBALS['__wp_rest_routes'] );
    }

    public function testAnUnusableNamespaceRegistersNothing(): void
    {
        $this->rest( [ new FakeTool() ] )->routes( null );
        $this->assertSame( [], $GLOBALS['__wp_rest_routes'] );
    }

    public function testASuccessfulCallReturnsThePayloadWith200(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', null, [ 'done' => 2 ] );
        $r    = $this->call( $this->rest( [ $tool ] ), 'fake_pro_tool', [ 'x' => 1 ] );
        $this->assertInstanceOf( \WP_REST_Response::class, $r );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( [ 'done' => 2, 'args' => [ 'x' => 1 ] ], $r->get_data() );
        $this->assertSame( 1, $tool->runs );
    }

    public function testABadArgumentIsA400WithTheToolsMessage(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', new \InvalidArgumentException( '"page_id" must be an integer.' ) );
        $r    = $this->call( $this->rest( [ $tool ] ), 'fake_pro_tool' );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( [ 'invalid_argument', '"page_id" must be an integer.', 400 ], [ $r->code, $r->message, $r->data['status'] ] );
    }

    public function testAUserWithoutTheToolsCapabilityGets403AndTheToolNeverRuns(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $tool                 = new FakeTool();
        $r                    = $this->call( $this->rest( [ $tool ] ), 'fake_pro_tool' );
        $this->assertSame( [ 'forbidden', 403 ], [ $r->code, $r->data['status'] ] );
        $this->assertSame( 0, $tool->runs );
    }

    public function testAnUnexpectedFailureIsA500AndNeverLeaks(): void
    {
        $tool = new FakeTool( 'fake_pro_tool', 'manage_options', new \RuntimeException( 'SECRET hunter2 /var/www/x.php' ) );
        $r    = $this->call( $this->rest( [ $tool ] ), 'fake_pro_tool' );
        $this->assertSame( [ 'tool_failed', 500 ], [ $r->code, $r->data['status'] ] );
        $this->assertStringNotContainsString( 'hunter2', (string) json_encode( $r ) );
    }

    public function testALicenceRevokedAfterRegistrationStopsTheCall(): void
    {
        $tool           = new FakeTool();
        $rest           = $this->rest( [ $tool ] );
        $this->unlocked = false;
        $r              = $this->call( $rest, 'fake_pro_tool' );
        $this->assertSame( [ 'pro_locked', 403 ], [ $r->code, $r->data['status'] ] );
        $this->assertSame( 0, $tool->runs );
    }

    public function testAnUnknownToolIsRefusedLikeALockedOne(): void
    {
        $r = $this->call( $this->rest( [ new FakeTool() ] ), 'not_a_tool' );
        $this->assertSame( 403, $r->data['status'] );
    }

    public function testABodyThatIsNotAJsonObjectIsA400(): void
    {
        $tool = new FakeTool();
        $r    = $this->call( $this->rest( [ $tool ] ), 'fake_pro_tool', [ 1, 2, 3 ] );
        $this->assertSame( 400, $r->data['status'] );
        $this->assertSame( 0, $tool->runs );
    }

    public function testAnEmptyBodyIsNoArguments(): void
    {
        $tool = new FakeTool();
        $r    = $this->call( $this->rest( [ $tool ] ), 'fake_pro_tool', [] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( [], $r->get_data()['args'] );
    }

    public function testAuthorizeRefusesWhileLockedAndOtherwiseUsesTheFreePluginsRule(): void
    {
        $rest           = $this->rest( [ new FakeTool() ] );
        $this->unlocked = false;
        $locked         = $rest->authorize();
        $this->assertSame( [ 'pro_locked', 403 ], [ $locked->code, $locked->data['status'] ] );

        $this->unlocked = true;
        $r              = $rest->authorize();
        $this->assertSame( [ 'unauthorized', 401 ], [ $r->code, $r->data['status'] ], 'no key and not logged in' );

        $GLOBALS['__wp_logged_in'] = true;
        $GLOBALS['__wp_caps']      = [ 'edit_posts' => true ];
        $this->assertTrue( $rest->authorize() );

        $GLOBALS['__wp_caps'] = [ 'read' => true ];
        $r                    = $rest->authorize();
        $this->assertSame( [ 'forbidden', 403 ], [ $r->code, $r->data['status'] ] );
    }
}
```

Create `pro-addon/tests/ProParityTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Plugin;
use AiEditorDivi5\Pro\Rest\ProOpenApi;
use AiEditorDivi5\Pro\Rest\ProRest;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\WP\OpenApiSpec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/WpRestRouteShims.php';

/**
 * MCP, REST and the ChatGPT OpenAPI spec must expose exactly the same Pro tools with the same arguments, and the
 * spec must stay inside the ChatGPT importer's limits. All three are generated from the tool list, so this proves it.
 */
class ProParityTest extends TestCase
{
    private ToolRegistry $registry;
    /** @var array<string,mixed> */
    private array $spec;

    protected function setUp(): void
    {
        $GLOBALS['__wp_filters']     = [];
        $GLOBALS['__wp_rest_routes'] = [];
        $this->registry              = new ToolRegistry( Tools::all(), static fn (): bool => true );
        $this->registry->register();
        ( new ProRest( $this->registry ) )->register();
        ( new ProOpenApi( $this->registry ) )->register();
        do_action( 'jhmg_aied_register_rest_routes', 'ai-editor-divi5/v1' );
        $this->spec = OpenApiSpec::spec( 'https://example.com/wp-json/ai-editor-divi5/v1', '4.4.0' );
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_filters'] = [];
    }

    /** @return list<array{string,string,array<string,mixed>}> every operation: method, path, operation */
    private function operations(): array
    {
        $ops = [];
        foreach ( $this->spec['paths'] as $path => $methods ) {
            foreach ( $methods as $method => $op ) {
                $ops[] = [ (string) $method, (string) $path, $op ];
            }
        }

        return $ops;
    }

    /** @return list<array{string,string,array<string,mixed>}> */
    private function proOperations(): array
    {
        return array_values( array_filter( $this->operations(), static fn ( array $o ): bool => str_starts_with( $o[1], '/pro/' ) ) );
    }

    public function testMcpRestAndOpenApiExposeExactlyTheSameTools(): void
    {
        $mcp = array_column( $this->registry->definitions( [] ), 'name' );
        $rest = array_map( static fn ( array $r ): string => substr( $r[1], strlen( '/pro/' ) ), $GLOBALS['__wp_rest_routes'] );
        $api  = array_map( static fn ( array $o ): string => substr( $o[1], strlen( '/pro/' ) ), $this->proOperations() );
        $this->assertSame( $mcp, $rest );
        $this->assertSame( $mcp, $api );
        $this->assertCount( count( Tools::all() ), $mcp );
    }

    public function testEveryOperationCarriesTheToolsOwnArguments(): void
    {
        foreach ( $this->registry->definitions( [] ) as $def ) {
            $op     = $this->spec['paths']['/pro/' . $def['name']]['post'];
            $schema = $op['requestBody']['content']['application/json']['schema'];
            $this->assertSame( array_keys( $def['inputSchema']['properties'] ), array_keys( $schema['properties'] ), $def['name'] );
            $this->assertSame( $def['inputSchema']['required'] ?? [], $schema['required'] ?? [], $def['name'] );
            $this->assertSame( $def['description'], $op['description'], $def['name'] );
            $this->assertSame( [] !== ( $def['inputSchema']['required'] ?? [] ), $op['requestBody']['required'], $def['name'] );
        }
    }

    public function testTheSpecStaysInsideTheChatGptImporterLimits(): void
    {
        $ops = $this->operations();
        $this->assertLessThanOrEqual( 30, count( $ops ), 'the importer caps a spec at 30 operations' );
        $ids = [];
        foreach ( $ops as [ $method, $path, $op ] ) {
            $this->assertLessThanOrEqual( 300, strlen( (string) $op['description'] ), "{$method} {$path} description" );
            $this->assertLessThanOrEqual( 300, strlen( (string) ( $op['summary'] ?? '' ) ), "{$method} {$path} summary" );
            $ids[] = $op['operationId'];
        }
        $this->assertSame( $ids, array_values( array_unique( $ids ) ), 'operationIds are unique' );
        foreach ( $this->proOperations() as [ , $path, $op ] ) {
            $this->assertMatchesRegularExpression( '/^[a-z][A-Za-z0-9]*$/', $op['operationId'], $path );
        }
    }

    public function testNoProObjectSchemaIsBare(): void
    {
        $check = function ( mixed $node, string $where ) use ( &$check ): void {
            if ( ! is_array( $node ) ) {
                return;
            }
            if ( ( $node['type'] ?? null ) === 'object' ) {
                $this->assertTrue( isset( $node['properties'] ) && [] !== $node['properties'] || isset( $node['additionalProperties'] ), "bare object schema at {$where}" );
            }
            foreach ( $node as $k => $v ) {
                $check( $v, $where . '/' . $k );
            }
        };
        foreach ( $this->proOperations() as [ , $path, $op ] ) {
            $check( $op, $path );
        }
        $check( $this->spec['components']['schemas']['ProResult'], 'ProResult' );
        $check( $this->spec['components']['schemas']['ProError'], 'ProError' );
    }

    public function testTheFreePluginsOwnOperationsAreUntouched(): void
    {
        $free = array_filter( $this->operations(), static fn ( array $o ): bool => ! str_starts_with( $o[1], '/pro/' ) );
        $this->assertCount( 16, $free );
        $this->assertSame( [ '/pro/' ], array_values( array_unique( array_map( static fn ( array $o ): string => substr( $o[1], 0, 5 ), $this->proOperations() ) ) ) );
    }

    public function testWhileLockedNothingIsAdded(): void
    {
        $GLOBALS['__wp_filters']     = [];
        $GLOBALS['__wp_rest_routes'] = [];
        $locked                      = new ToolRegistry( Tools::all(), static fn (): bool => false );
        ( new ProRest( $locked ) )->register();
        ( new ProOpenApi( $locked ) )->register();
        do_action( 'jhmg_aied_register_rest_routes', 'ai-editor-divi5/v1' );
        $spec = OpenApiSpec::spec( 'https://example.com/wp-json/ai-editor-divi5/v1', '4.4.0' );
        $this->assertSame( [], $GLOBALS['__wp_rest_routes'] );
        $this->assertSame( [], array_filter( array_keys( $spec['paths'] ), static fn ( string $p ): bool => str_starts_with( $p, '/pro/' ) ) );
        $this->assertArrayNotHasKey( 'ProResult', $spec['components']['schemas'] );
    }

    public function testTheAddOnWiresRestAndOpenApiWhenItBoots(): void
    {
        $GLOBALS['__wp_filters'] = [];
        $GLOBALS['__wp_options'] = [ 'aied_pro_premium_unlocked' => 1 ];
        \AiEditorDivi5\Pro\Licensing::resetForTests();
        $this->assertTrue( Plugin::bootWith( '4.4.0', static fn (): bool => true ) );
        $this->assertArrayHasKey( 'jhmg_aied_register_rest_routes', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'jhmg_aied_openapi_paths', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'jhmg_aied_openapi_schemas', $GLOBALS['__wp_filters'] );
        $GLOBALS['__wp_options'] = [];
        \AiEditorDivi5\Pro\Licensing::resetForTests();
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter 'ProRestTest|ProParityTest' 2>&1 | tail -8`
Expected: `Class "AiEditorDivi5\Pro\Rest\ProRest" not found`.

- [ ] **Step 3: Write the two faces**

Create `pro-addon/src/Rest/ProRest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Rest;

use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\ToolRunner;
use AiEditorDivi5\WP\RestController;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The REST face of every Pro tool, generated from the same tool list as the MCP face: `POST /pro/<tool_name>` with the
 * tool's arguments as the JSON body. Authentication is the free plugin's own (`RestController::require_edit_posts`:
 * plugin API key, Application Password or a logged-in editor); the tool's own capability, argument checks and error
 * mapping are `ToolRunner`, shared with MCP. While the licence is locked no route is registered, and a licence
 * revoked after registration stops a call at request time.
 */
final class ProRest
{
    public const PREFIX = '/pro/';

    public function __construct( private ToolRegistry $registry )
    {
    }

    public function register(): void
    {
        add_action( 'jhmg_aied_register_rest_routes', [ $this, 'routes' ] );
    }

    /** `jhmg_aied_register_rest_routes`: one POST route per Pro tool, only while unlocked. */
    public function routes( mixed $namespace ): void
    {
        if ( ! is_string( $namespace ) || ! $this->registry->isUnlocked() ) {
            return;
        }
        foreach ( $this->registry->tools() as $tool ) {
            $name = $tool->name();
            register_rest_route( $namespace, self::PREFIX . $name, [
                'methods'             => 'POST',
                'callback'            => fn ( $request ) => $this->handle( $name, $request ),
                'permission_callback' => [ $this, 'authorize' ],
            ] );
        }
    }

    /** @return bool|\WP_Error */
    public function authorize(): mixed
    {
        if ( ! $this->registry->isUnlocked() ) {
            return new \WP_Error( 'pro_locked', 'The Pro tools are not unlocked on this site.', [ 'status' => 403 ] );
        }

        return ( new RestController() )->require_edit_posts();
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function handle( string $name, \WP_REST_Request $request ): mixed
    {
        $tool = $this->registry->get( $name );
        if ( null === $tool || ! $this->registry->isUnlocked() ) {
            return new \WP_Error( 'pro_locked', 'The Pro tools are not unlocked on this site.', [ 'status' => 403 ] );
        }
        $body = $request->get_json_params();
        if ( null !== $body && ( ! is_array( $body ) || ( [] !== $body && array_is_list( $body ) ) ) ) {
            return new \WP_Error( 'invalid_argument', 'The request body must be a JSON object of arguments.', [ 'status' => 400 ] );
        }
        $run = ToolRunner::run( $tool, $body ?? [] );
        if ( ! $run['ok'] ) {
            return new \WP_Error( $run['code'], $run['message'], [ 'status' => $run['status'] ] );
        }

        return new \WP_REST_Response( $run['payload'], 200 );
    }
}
```

Create `pro-addon/src/Rest/ProOpenApi.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Rest;

use AiEditorDivi5\Pro\ToolRegistry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ChatGPT/OpenAPI face of every Pro tool, generated from each tool's own definition so it cannot drift from the MCP
 * and REST faces. One operation per tool (the importer caps a spec at 30 operations in total; the free plugin has 16),
 * every description at most 300 characters, and no object schema without properties.
 */
final class ProOpenApi
{
    public function __construct( private ToolRegistry $registry )
    {
    }

    public function register(): void
    {
        add_filter( 'jhmg_aied_openapi_paths', [ $this, 'paths' ], 10, 2 );
        add_filter( 'jhmg_aied_openapi_schemas', [ $this, 'schemas' ] );
    }

    /** @param mixed $paths @return mixed */
    public function paths( mixed $paths, mixed $base = '' ): mixed
    {
        if ( ! is_array( $paths ) || ! $this->registry->isUnlocked() ) {
            return $paths;
        }
        foreach ( $this->registry->tools() as $tool ) {
            $def    = $tool->definition();
            $schema = self::requestSchema( $def['inputSchema'] );
            $op     = [
                'operationId' => self::operationId( $def['name'] ),
                'summary'     => self::summary( $def['description'] ),
                'description' => $def['description'],
                'requestBody' => [
                    'required' => [] !== ( $def['inputSchema']['required'] ?? [] ),
                    'content'  => [ 'application/json' => [ 'schema' => $schema ] ],
                ],
                'responses'   => [
                    '200' => [ 'description' => 'Success', 'content' => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/ProResult' ] ] ] ],
                    '400' => [ 'description' => 'A missing or invalid argument (the message says which)', 'content' => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/ProError' ] ] ] ],
                    '403' => [ 'description' => 'The user lacks the permission this tool needs, or the Pro tools are not unlocked' ],
                ],
            ];
            $paths[ ProRest::PREFIX . $def['name'] ] = [ 'post' => $op ];
        }

        return $paths;
    }

    /** @param mixed $schemas @return mixed */
    public function schemas( mixed $schemas ): mixed
    {
        if ( ! is_array( $schemas ) || ! $this->registry->isUnlocked() ) {
            return $schemas;
        }
        $schemas['ProResult'] = [
            'type'                 => 'object',
            'description'          => 'What a Pro tool returns. Write tools add a batch (id, undoable) that undo_batch accepts.',
            'properties'           => [
                'changed' => [ 'type' => 'boolean', 'description' => 'True if something was changed' ],
                'dry_run' => [ 'type' => 'boolean', 'description' => 'True for a preview' ],
                'token'   => [ 'type' => 'string', 'description' => 'On a preview of a site-wide change: pass it back to apply that change' ],
                'batch'   => [ 'type' => 'object', 'description' => 'The recorded change (id, undoable)', 'additionalProperties' => true ],
            ],
            'additionalProperties' => true,
        ];
        $schemas['ProError'] = [
            'type'       => 'object',
            'properties' => [
                'code'    => [ 'type' => 'string' ],
                'message' => [ 'type' => 'string', 'description' => 'Safe to show to the user' ],
            ],
        ];

        return $schemas;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private static function requestSchema( array $input ): array
    {
        $props  = is_array( $input['properties'] ?? null ) ? $input['properties'] : [];
        $schema = [ 'type' => 'object', 'properties' => $props ];
        if ( [] === $props ) {
            $schema['additionalProperties'] = true;
        }
        if ( [] !== ( $input['required'] ?? [] ) ) {
            $schema['required'] = array_values( $input['required'] );
        }

        return $schema;
    }

    private static function operationId( string $toolName ): string
    {
        return lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $toolName ) ) ) );
    }

    private static function summary( string $description ): string
    {
        $first = trim( (string) preg_replace( '/\s+/', ' ', strtok( $description, '.' ) ?: $description ) );

        return strlen( $first ) > 120 ? substr( $first, 0, 117 ) . '...' : $first;
    }
}
```

- [ ] **Step 4: Wire it in and pin what it now calls**

`pro-addon/src/autoload.php`: before the line `require_once __DIR__ . '/Admin/AdminActions.php';` add
```php
require_once __DIR__ . '/Rest/ProRest.php';
require_once __DIR__ . '/Rest/ProOpenApi.php';
```

`pro-addon/src/Plugin.php`: add `use AiEditorDivi5\Pro\Rest\ProOpenApi;` and `use AiEditorDivi5\Pro\Rest\ProRest;` after the `BatchTable` import, and in `wire()` directly after `$registry->register();` add:

```php
        ( new ProRest( $registry ) )->register();
        ( new ProOpenApi( $registry ) )->register();
```

`pro-addon/src/Compat.php`: in `REQUIRED_CLASSES`, after the `LayoutDiff` line add `'AiEditorDivi5\\WP\\RestController',`.

`pro-addon/tests/FreeApiCompatTest.php`: directly before `public function testTheEditTargetErrorConstantsStayTheSame(): void` add:

```php
    public function testTheRestAuthenticationMethodTheProRoutesUseKeepsItsShape(): void
    {
        $m = new \ReflectionMethod( \AiEditorDivi5\WP\RestController::class, 'require_edit_posts' );
        $this->assertTrue( $m->isPublic() && ! $m->isStatic(), 'RestController::require_edit_posts must stay a public instance method' );
        $this->assertSame( [], $m->getParameters() );
        $this->assertSame( 'WP_Error|bool', (string) $m->getReturnType() );
        $s = new \ReflectionMethod( \AiEditorDivi5\WP\OpenApiSpec::class, 'spec' );
        $this->assertTrue( $s->isStatic() && $s->isPublic() );
    }

```

- [ ] **Step 5: Run the whole Pro suite**

Run: `make test-pro 2>&1 | tail -3` → all green with no failures, risky tests, deprecations or warnings (`OK (679 tests, 2708 assertions)`).

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src pro-addon/tests
git commit -q -F - <<'EOF'
feat(pro): REST routes and ChatGPT OpenAPI operations for every Pro tool, generated from the tool list

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).

### Task 5: Live proof on the real dev site (`make verify-pro-site-tools`, `make verify-pro-rest`)

**Files:**
- Create: `scripts/verify-pro-site-tools.php`, `scripts/verify-pro-rest.php`
- Modify: `Makefile`

**Interfaces:**
- Consumes: the two new tools, the REST and OpenAPI faces, the real `ToolRegistry`, `Batches`, `BatchTable`, the free history, the real WordPress REST server and the live `/openapi.json`.
- Produces: two self-cleaning checks (exit 0 = every check passed). `verify-pro-site-tools` builds throwaway Divi 5 pages (titled `aied verify ...`), the Theme Builder fixtures of Plan 4, and proves find/replace (preview, token, byte-exact replace, undo, changed-since, invalid-result skip, the 50 cap, layouts only with scope and permission, the permission layers) and the audit (violations, custom CSS, unknown preset id, missing and draft links, paging through the whole site at 25 per call, read-only). `verify-pro-rest` is read-only and proves the live spec (29 operations, descriptions, schemas), authentication (anonymous 401, API key, logged-in), the three tools over REST, error mapping, the capability gate and a locked licence.

Both scripts were run against the real Divi 5.14 dev site while this plan was replayed and passed. If a check fails on your run, treat it as a real finding: fix the code, not the check, unless it is a script defect.

- [ ] **Step 1: Write the scripts**

Create `scripts/verify-pro-site-tools.php`:

```php
<?php
/**
 * Real-site check of the Pro site-wide tools (find_replace_site, audit_site), driven through the REAL ToolRegistry
 * (so the capability gate is part of what is proven), the real batch table, the real free history table and Divi's
 * own Theme Builder posts on the Docker site. It proves: a find/replace preview touches nothing and returns a token;
 * a real run needs it; every occurrence in every matching Divi 5 page is replaced byte for byte as ONE batch that
 * undo_batch restores; classic pages, trashed pages and posts are never touched; an item changed since the preview
 * is skipped; a result that would fail the layout checks is skipped and never saved; more than 50 matches are refused
 * with the count; the live header/footer are included only with scope layouts and the Theme Builder permission; the
 * permission layers refuse. And that audit_site finds layout violations, custom code, unknown preset ids, missing and
 * unpublished internal links, pages through the whole site 25 at a time, and changes nothing.
 *
 * Run: make verify-pro-site-tools   (needs `make up`, the free plugin and the Pro add-on active on the dev site;
 * no licence is needed: the registry is built with the unlock forced)
 *
 * Builds throwaway fixtures (posts titled 'aied verify ...') and removes exactly what it created, even on failure.
 * WARNING: it records about 6 batches, and the batch store keeps only the newest 20, so on a site that already has
 * batches it evicts the oldest real ones. Run it on a throwaway dev site only.
 */

use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use Divi5Validator\Validator;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( HistoryStore::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro on this site first.' );
}
foreach ( [ 'et_theme_builder_get_theme_builder_post_id', 'et_theme_builder_insert_layout', 'et_theme_builder_store_template', 'et_theme_builder_clear_wp_cache', 'et_pb_is_allowed' ] as $fn ) {
    if ( ! function_exists( $fn ) ) {
        WP_CLI::error( 'Divi Theme Builder functions not available' );
    }
}

global $wpdb;

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$layout = static function ( string $title ): string {
    return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"' . $title . '"}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};
$rows = static function ( int $pageId ): int {
    return count( HistoryStore::summaries( $pageId ) );
};
$state = static function ( int $pageId ): array {
    clean_post_cache( $pageId );
    $p = get_post( $pageId );

    return [ 'content' => $p->post_content, 'modified' => $p->post_modified_gmt, 'history' => count( HistoryStore::summaries( $pageId ) ) ];
};
$batchIds = [];
$registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
$call     = static function ( string $name, array $args ) use ( $registry, &$batchIds ): array {
    $res = $registry->claim( null, $name, $args, 1 );
    if ( ! $res instanceof WP_REST_Response ) {
        return [ 'error' => [ 'code' => 0, 'message' => 'not claimed' ], 'result' => null ];
    }
    $data = (array) $res->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => $data['error'], 'result' => null ];
    }
    $result = (array) json_decode( (string) ( $data['result']['content'][0]['text'] ?? '' ), true );
    foreach ( [ 'batch', 'undo_batch' ] as $k ) {
        if ( isset( $result[ $k ]['id'] ) ) {
            $batchIds[] = (int) $result[ $k ]['id'];
        }
    }

    return [ 'error' => null, 'result' => $result ];
};
$log_kind = static fn ( int $id ): string => (string) ( \AiEditorDivi5\Pro\Batch\Batches::log()->find( $id )['items'][0]['kind'] ?? '' );
$errHas = static fn ( array $r, string $needle ): bool => null !== $r['error'] && false !== stripos( (string) $r['error']['message'], $needle );
$tbTypes = [ 'et_theme_builder', 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout' ];
$countTb = static function ( ?string $only = null ) use ( $wpdb, $tbTypes ): int {
    $types = null === $only ? $tbTypes : [ $only ];
    $in    = "'" . implode( "','", $types ) . "'";

    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$in})" ); // phpcs:ignore
};
$countAied = static function () use ( $wpdb ): int {
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'aied verify%'" ); // phpcs:ignore
};
$roleSentinel = '__aied_verify_absent__';
$roleOption   = 'et_pb_role_settings';
$backupOption = static fn ( int $tb ): string => 'et_tb_templates_backup_' . $tb;

HistoryTable::ensureReady( true );
BatchTable::ensureReady( true );

// ---- 1. guard + baseline ---------------------------------------------------------------------------
$baselineCount = $countTb();
$liveTbBefore  = (int) et_theme_builder_get_theme_builder_post_id( true, false );
$draftTbBefore = (int) et_theme_builder_get_theme_builder_post_id( false, false );
$roleBefore    = get_option( $roleOption, $roleSentinel );
$roleExisted   = $roleSentinel !== $roleBefore;
WP_CLI::log( "baseline: {$baselineCount} Theme Builder post(s); live Theme Builder id {$liveTbBefore}; draft Theme Builder id {$draftTbBefore}; {$roleOption} " . ( $roleExisted ? 'exists' : 'does not exist' ) );
if ( $liveTbBefore > 0 ) {
    $tbTemplatesBefore = get_post_meta( $liveTbBefore, '_et_template', false );
}

$H = $F = $B = $O = $T = $DL = $DT = $P = $PG = 0;
$tb = $draftTb = 0;
$createdTb = $createdDraftTb = false;
$liveBackupBefore = $draftBackupBefore = null;
$draftTemplatesBefore = [];
$created = []; // every post id this run creates

$insertLayout = static function ( string $type, string $title, string $content ) use ( &$created ): int {
    $id = et_theme_builder_insert_layout( [ 'post_type' => $type, 'post_title' => $title, 'post_content' => wp_slash( $content ) ] );
    if ( is_wp_error( $id ) || ! $id ) {
        throw new \RuntimeException( "could not create the {$type} fixture" );
    }
    $created[] = (int) $id;
    update_post_meta( $id, '_et_pb_use_divi_5', 'on' );
    update_post_meta( $id, '_et_pb_use_builder', 'on' );

    return (int) $id;
};
$insertTemplate = static function ( int $themeBuilderId, string $title, int $header, int $body, int $footer ) use ( &$created ): int {
    $id = et_theme_builder_store_template(
        $themeBuilderId,
        [
            'title'               => $title,
            'default'             => '0',
            'enabled'             => '1',
            'autogenerated_title' => '0',
            'layouts'             => [
                'header' => [ 'id' => $header, 'enabled' => true ],
                'body'   => [ 'id' => $body, 'enabled' => true ],
                'footer' => [ 'id' => $footer, 'enabled' => true ],
            ],
            'use_on'              => [],
            'exclude_from'        => [],
        ],
        true
    );
    if ( ! $id ) {
        throw new \RuntimeException( "could not create the template fixture {$title}" );
    }
    $created[] = (int) $id;

    return (int) $id;
};

try {
    $check( ( new Validator() )->validateContent( $layout( 'x' ) )->isValid(), 'the fixture layout used by this script passes the checker' );
    $check( et_pb_is_allowed( 'theme_builder' ) && current_user_can( 'edit_others_posts' ), 'setup: user 1 starts with edit_others_posts and the Divi theme_builder capability' );

    // ---- 2. listing must not create anything ---------------------------------------------------------
    $tbCountBefore = $countTb( 'et_theme_builder' );
    $l = $call( 'list_theme_layouts', [] );
    $check( null === $l['error'], 'listing before any fixture is not an error' );
    $check( $countTb( 'et_theme_builder' ) === $tbCountBefore && (int) et_theme_builder_get_theme_builder_post_id( true, false ) === $liveTbBefore, 'listing never creates a Theme Builder post' );
    if ( 0 === $liveTbBefore ) {
        $check( 0 === ( $l['result']['count'] ?? null ) && is_string( $l['result']['note'] ?? null ), 'with no live Theme Builder the listing is empty and carries the explanatory note' );
    }

    // ---- 3. fixtures, built with Divi's own functions -------------------------------------------------
    $tb        = (int) et_theme_builder_get_theme_builder_post_id( true, true );
    $createdTb = 0 === $liveTbBefore;
    if ( $createdTb ) {
        $created[] = $tb;
    } else {
        $liveBackupBefore = get_option( $backupOption( $tb ), null );
    }

    $H  = $insertLayout( 'et_header_layout', 'aied verify header', $layout( 'AIED-HEADER-V1' ) );
    $F  = $insertLayout( 'et_footer_layout', 'aied verify footer', $layout( 'AIED-FOOTER-V1' ) );
    $B  = $insertLayout( 'et_body_layout', 'aied verify body', $layout( 'AIED-BODY-V1' ) );
    $O  = $insertLayout( 'et_header_layout', 'aied verify orphan header', $layout( 'AIED-ORPHAN-V1' ) );
    $T  = $insertTemplate( $tb, 'aied verify template', $H, $B, $F );
    add_post_meta( $tb, '_et_template', $T ); // append, never replace
    if ( is_array( $liveBackupBefore ) ) {
        update_option( $backupOption( $tb ), array_merge( array_map( 'intval', $liveBackupBefore ), [ $T ] ) );
    }

    // draft-only: a layout used only by a template of the DRAFT Theme Builder
    $draftTb        = (int) et_theme_builder_get_theme_builder_post_id( false, true );
    $createdDraftTb = 0 === $draftTbBefore;
    if ( $createdDraftTb ) {
        $created[] = $draftTb;
    } else {
        $draftBackupBefore    = get_option( $backupOption( $draftTb ), null );
        $draftTemplatesBefore = get_post_meta( $draftTb, '_et_template', false );
    }
    $DL = $insertLayout( 'et_header_layout', 'aied verify draft header', $layout( 'AIED-DRAFT-V1' ) );
    $DT = $insertTemplate( $draftTb, 'aied verify draft template', $DL, 0, 0 );
    add_post_meta( $draftTb, '_et_template', $DT );

    $P = (int) wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'aied verify post', 'post_content' => wp_slash( $layout( 'AIED-POST-V1' ) ) ] );
    $created[] = $P;
    et_theme_builder_clear_wp_cache( 'all' );

    $check( $H > 0 && $F > 0 && $B > 0 && $O > 0 && $T > 0 && $DL > 0 && $DT > 0 && $P > 0 && $tb > 0 && $draftTb > 0, 'fixtures created: header, footer, body, orphan header, live template, draft layout and template, an ordinary post' );
    clean_post_cache( $H );
    $check( get_post( $H )->post_content === $layout( 'AIED-HEADER-V1' ) && get_post( $F )->post_content === $layout( 'AIED-FOOTER-V1' ), 'the fixture layouts stored byte-exact content (wp_slash)' );

    // ---- 4. assignment state, snapshotted ----------------------------------------------------------------
    $metaSnapshot   = static fn ( int $id ): array => get_post_meta( $id );
    $metaT          = $metaSnapshot( $T );
    $metaDT         = $metaSnapshot( $DT );
    $tbSnapshot     = get_post_meta( $tb, '_et_template', false );
    $draftTbSnap    = get_post_meta( $draftTb, '_et_template', false );
    $liveTbAfterFix = (int) et_theme_builder_get_theme_builder_post_id( true, false );
    $draftTbAfterFix = (int) et_theme_builder_get_theme_builder_post_id( false, false );
    $check( $liveTbAfterFix === $tb && $draftTbAfterFix === $draftTb, 'the Divi live and draft Theme Builder posts resolve to the ones this run uses' );

    // ---- 5. throwaway pages --------------------------------------------------------------------------------
    $divi = static function ( string $title, string $content, string $status = 'publish', array $extra = [] ) use ( &$created ): int {
        $id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => $status, 'post_title' => $title, 'post_content' => wp_slash( $content ) ] + $extra, true );
        if ( is_wp_error( $id ) ) {
            throw new \RuntimeException( 'could not create a throwaway page: ' . $id->get_error_message() );
        }
        $created[] = (int) $id;
        update_post_meta( $id, '_et_pb_use_divi_5', 'on' );
        update_post_meta( $id, '_et_pb_use_builder', 'on' );

        return (int) $id;
    };
    $read = static function ( int $id ): string {
        clean_post_cache( $id );

        return (string) get_post( $id )->post_content;
    };
    $A1 = $divi( 'aied verify fr 1', $layout( 'Hello AIED-FR-OLD world' ) );
    $A2 = $divi( 'aied verify fr 2', $layout( 'AIED-FR-OLD and AIED-FR-OLD again' ) );
    $A3 = $divi( 'aied verify fr 3', $layout( 'Draft AIED-FR-OLD' ), 'draft' );
    $CL = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'aied verify classic', 'post_content' => '<p>Classic AIED-FR-OLD</p>' ] );
    $created[] = $CL;
    $TR = $divi( 'aied verify trashed', $layout( 'Trashed AIED-FR-OLD' ), 'trash' );
    $find = [ 'find' => 'AIED-FR-OLD', 'replace' => 'AIED-FR-NEW' ];
    $expected = static fn ( string $c ): string => str_replace( 'AIED-FR-OLD', 'AIED-FR-NEW', $c );
    $c0 = [ $A1 => $read( $A1 ), $A2 => $read( $A2 ), $A3 => $read( $A3 ) ];
    $check( true, 'fixtures: three Divi 5 pages (one draft), a classic page, a trashed page' );

    // ---- 5a. find_replace_site: preview, token, apply ---------------------------------------------------------
    $before = [ $state( $A1 ), $state( $A2 ), $state( $A3 ) ];
    sleep( 1 );
    $d = $call( 'find_replace_site', $find + [ 'dry_run' => true ] );
    $ids = array_column( (array) ( $d['result']['items'] ?? [] ), 'id' );
    $check( null === $d['error'] && [ $A1, $A2, $A3 ] === $ids && 3 === ( $d['result']['matched'] ?? null ) && is_string( $d['result']['token'] ?? null ), 'dry run: exactly the three Divi 5 pages match (not the classic page, not the trashed one) and a token comes back' );
    $check( 2 === (int) ( $d['result']['items'][1]['occurrences'] ?? 0 ), 'dry run counts both occurrences in page 2' );
    $check( [ $state( $A1 ), $state( $A2 ), $state( $A3 ) ] === $before, 'the dry run left content, post_modified and history untouched' );
    $check( $errHas( $call( 'find_replace_site', $find ), 'needs the token' ), 'a real run without a token is refused' );
    $check( $errHas( $call( 'find_replace_site', [ 'find' => 'AIED-FR-OLD', 'replace' => 'OTHER', 'token' => $d['result']['token'] ] ), 'needs the token' ), 'a token for other arguments is refused' );
    $r = $call( 'find_replace_site', $find + [ 'token' => $d['result']['token'] ] );
    $check( null === $r['error'] && true === ( $r['result']['changed'] ?? null ) && 3 === ( $r['result']['replaced_in'] ?? null ) && [] === ( $r['result']['skipped'] ?? null ) && true === ( $r['result']['batch']['undoable'] ?? null ), 'real run: three items replaced as one undoable batch' );
    $check( $read( $A1 ) === $expected( $c0[ $A1 ] ) && $read( $A2 ) === $expected( $c0[ $A2 ] ) && $read( $A3 ) === $expected( $c0[ $A3 ] ), 'every occurrence is replaced, byte for byte (wp_slash exact)' );
    $check( '<p>Classic AIED-FR-OLD</p>' === $read( $CL ) && false !== strpos( $read( $TR ), 'AIED-FR-OLD' ), 'the classic page and the trashed page were never touched' );
    $check( 1 === $rows( $A1 ) && 1 === $rows( $A2 ), 'each replaced page has exactly one history snapshot' );
    $u = $call( 'undo_batch', [ 'batch_id' => (int) $r['result']['batch']['id'] ] );
    $check( true === ( $u['result']['fully_undone'] ?? null ) && $read( $A1 ) === $c0[ $A1 ] && $read( $A2 ) === $c0[ $A2 ] && $read( $A3 ) === $c0[ $A3 ], 'undo_batch puts all three pages back byte for byte, fully undone' );

    // ---- 5b. an item changed since the preview is skipped -----------------------------------------------------
    $d = $call( 'find_replace_site', $find + [ 'dry_run' => true ] );
    wp_update_post( wp_slash( [ 'ID' => $A2, 'post_content' => $layout( 'Edited in the builder AIED-FR-OLD' ) ] ) );
    $r = $call( 'find_replace_site', $find + [ 'token' => $d['result']['token'] ] );
    $check( 2 === ( $r['result']['replaced_in'] ?? null ) && 'changed_since_preview' === ( $r['result']['skipped'][0]['reason'] ?? null ) && $A2 === ( $r['result']['skipped'][0]['id'] ?? null ), 'a page edited after the preview is skipped and reported; the others are replaced' );
    $check( $read( $A2 ) === $layout( 'Edited in the builder AIED-FR-OLD' ), 'the skipped page keeps the builder edit' );
    $call( 'undo_batch', [ 'batch_id' => (int) $r['result']['batch']['id'] ] );
    wp_update_post( wp_slash( [ 'ID' => $A2, 'post_content' => $c0[ $A2 ] ] ) );

    // ---- 5c. a result that would fail the checks is skipped, never saved ----------------------------------------
    $bad = [ 'find' => 'wp:divi/heading', 'replace' => 'wp:divi/not-a-module', 'page_ids' => [ $A1 ] ];
    $d   = $call( 'find_replace_site', $bad + [ 'dry_run' => true ] );
    $check( 1 === ( $d['result']['would_skip'] ?? null ) && false === ( $d['result']['items'][0]['valid'] ?? null ) && ! empty( $d['result']['items'][0]['violations'] ), 'preview: the page that would become invalid is flagged with violation codes' );
    $r = $call( 'find_replace_site', $bad + [ 'token' => $d['result']['token'] ] );
    $check( false === ( $r['result']['changed'] ?? null ) && 'invalid_result' === ( $r['result']['skipped'][0]['reason'] ?? null ) && $read( $A1 ) === $c0[ $A1 ], 'real run: skipped as invalid_result and never saved' );

    // ---- 5d. more than fifty matches are refused with the count -----------------------------------------------
    $many = [];
    for ( $i = 0; $i < 51; $i++ ) {
        $many[] = $divi( 'aied verify cap ' . $i, $layout( 'AIED-FR-CAP' ) );
    }
    $capRefusal = $call( 'find_replace_site', [ 'find' => 'AIED-FR-CAP', 'replace' => 'X', 'dry_run' => true ] );
    $check( $errHas( $capRefusal, '51 items contain that text; the limit is 50' ), 'fifty-one matches are refused with the count, not truncated' );
    $d = $call( 'find_replace_site', [ 'find' => 'AIED-FR-CAP', 'replace' => 'X', 'dry_run' => true, 'page_ids' => array_slice( $many, 0, 50 ) ] );
    $check( null === $d['error'] && 50 === ( $d['result']['matched'] ?? null ), 'exactly fifty is allowed (preview only)' );

    // ---- 5e. layouts: only with scope and the Theme Builder permission --------------------------------------------
    $d = $call( 'find_replace_site', [ 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-FR', 'dry_run' => true ] );
    $check( 0 === ( $d['result']['matched'] ?? -1 ), 'scope pages (default) never matches a header or footer' );
    $L = [ 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-FR', 'scope' => 'layouts' ];
    $d = $call( 'find_replace_site', $L + [ 'dry_run' => true ] );
    $check( [ $H ] === array_column( (array) ( $d['result']['items'] ?? [] ), 'id' ) && 'header' === ( $d['result']['items'][0]['kind'] ?? null ), 'scope layouts matches the live header' );
    $r = $call( 'find_replace_site', $L + [ 'token' => $d['result']['token'] ] );
    $check( true === ( $r['result']['changed'] ?? null ) && $read( $H ) === $layout( 'AIED-HEADER-FR' ) && 'theme_layout_content' === ( $r['result']['batch'] ? $log_kind( (int) $r['result']['batch']['id'] ) : '' ), 'the header is replaced and recorded as a theme_layout_content item' );
    $u = $call( 'undo_batch', [ 'batch_id' => (int) $r['result']['batch']['id'] ] );
    $check( true === ( $u['result']['fully_undone'] ?? null ) && $read( $H ) === $layout( 'AIED-HEADER-V1' ), 'undo restores the header byte for byte' );
    $strip = static fn ( $caps ) => array_merge( (array) $caps, [ 'edit_theme_options' => false ] );
    add_filter( 'user_has_cap', $strip );
    try {
        $check( $errHas( $call( 'find_replace_site', $L ), 'permission' ), 'without edit_theme_options, scope layouts is refused' );
        $d = $call( 'find_replace_site', array_merge( $L, [ 'scope' => 'all', 'dry_run' => true ] ) );
        $check( 0 === ( $d['result']['matched'] ?? -1 ) && is_string( $d['result']['layouts_note'] ?? null ), 'without edit_theme_options, scope all skips the layouts and says so' );
    } finally {
        remove_filter( 'user_has_cap', $strip );
    }

    // ---- 5f. the registry's own capability gate ---------------------------------------------------------------
    $strip = static fn ( $caps ) => array_merge( (array) $caps, [ 'edit_pages' => false ] );
    add_filter( 'user_has_cap', $strip );
    try {
        foreach ( [ 'find_replace_site' => $find, 'audit_site' => [] ] as $tool => $args ) {
            $check( $errHas( $call( $tool, $args ), 'permission' ), "without edit_pages, {$tool} is refused by the registry" );
        }
    } finally {
        remove_filter( 'user_has_cap', $strip );
    }

    // ---- 5g. audit_site ------------------------------------------------------------------------------------------
    $PUB = $divi( 'aied verify audit published', $layout( 'fine' ), 'publish', [ 'post_name' => 'aied-verify-published' ] );
    $DRF = $divi( 'aied verify audit draft target', $layout( 'draft target' ), 'draft', [ 'post_name' => 'aied-verify-draft' ] );
    $linkBtn = static fn ( string $url ): string => str_replace( '<!-- /wp:divi/column -->', '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go","linkUrl":"' . $url . '"}}}},"builderVersion":"5.14.0"} /--><!-- /wp:divi/column -->', $layout( 'links' ) );
    $AOK  = $divi( 'aied verify audit ok', $linkBtn( '/aied-verify-published/' ) );
    $ABAD = $divi( 'aied verify audit bad module', str_replace( 'wp:divi/heading', 'wp:divi/not-a-module', $layout( 'x' ) ) );
    $ALNK = $divi( 'aied verify audit links', $linkBtn( '/aied-verify-missing/' ) . $linkBtn( '/aied-verify-draft/' ) . $linkBtn( 'https://elsewhere.invalid/x/' ) . $linkBtn( '#top' ) );
    $ACSS = $divi( 'aied verify audit css', str_replace( '"builderVersion":"5.14.0"} /--><!-- /wp:divi/column', '"builderVersion":"5.14.0","css":{"desktop":{"value":{"freeForm":"selector{color:red}"}}}} /--><!-- /wp:divi/column', $layout( 'css' ) ) );
    $APRE = $divi( 'aied verify audit preset', str_replace( '"builderVersion":"5.14.0"} /--><!-- /wp:divi/column', '"modulePreset":["aied-verify-unknown-preset"],"builderVersion":"5.14.0"} /--><!-- /wp:divi/column', $layout( 'preset' ) ) );
    $mine = [ $PUB, $DRF, $AOK, $ABAD, $ALNK, $ACSS, $APRE ];
    $snap = [];
    foreach ( $mine as $id ) {
        $snap[ $id ] = $state( $id );
    }
    $postsBefore  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ); // phpcs:ignore
    $batchesBefore = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() ); // phpcs:ignore

    $all = [];
    $cursor = 0;
    $calls = 0;
    $total = null;
    $maxScanned = 0;
    do {
        $a = $call( 'audit_site', [ 'scope' => 'pages', 'cursor' => $cursor ] );
        if ( null !== $a['error'] ) {
            $check( false, 'audit_site page call failed: ' . ( $a['error']['message'] ?? '?' ) );
            break;
        }
        $calls++;
        $total       = $a['result']['total_items'];
        $maxScanned  = max( $maxScanned, (int) $a['result']['scanned'] );
        foreach ( $a['result']['findings'] as $f ) {
            $all[ $f['id'] ][ $f['code'] ] = $f;
        }
        $cursor = $a['result']['next_cursor'];
    } while ( null !== $cursor && $calls < 200 );
    $diviPages = count( get_posts( [ 'post_type' => 'page', 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'fields' => 'ids', 'posts_per_page' => -1, 'meta_query' => [ [ 'key' => '_et_pb_use_divi_5', 'value' => 'on' ] ] ] ) );
    $check( $total === $diviPages && $maxScanned <= 25 && $calls === (int) ceil( $diviPages / 25 ), "audit pages through the whole site: {$diviPages} Divi 5 pages in {$calls} calls of at most 25" );
    $check( empty( $all[ $AOK ] ) && empty( $all[ $PUB ] ), 'a page with a valid layout and a link to a published page has no findings' );
    $check( ! empty( $all[ $ABAD ] ), 'a page with an unknown module has layout violations' );
    $check( isset( $all[ $ACSS ]['CUSTOM_CSS_NOT_ALLOWED'] ), 'custom CSS already stored is reported (CUSTOM_CSS_NOT_ALLOWED)' );
    $check( isset( $all[ $ALNK ]['LINK_NO_POST'] ) && false !== strpos( $all[ $ALNK ]['LINK_NO_POST']['detail'], '/aied-verify-missing/' ) && 1 === $all[ $ALNK ]['LINK_NO_POST']['count'], 'a link to a missing page is LINK_NO_POST (and the external link and #top are ignored)' );
    $check( isset( $all[ $ALNK ]['LINK_NOT_PUBLISHED'] ) && false !== strpos( $all[ $ALNK ]['LINK_NOT_PUBLISHED']['detail'], '/aied-verify-draft/ (draft)' ), 'a link to a draft page is LINK_NOT_PUBLISHED' );
    $check( isset( $all[ $APRE ]['UNKNOWN_PRESET_ID'] ) && false !== strpos( $all[ $APRE ]['UNKNOWN_PRESET_ID']['detail'], 'aied-verify-unknown-preset' ), 'a preset id this site does not have is UNKNOWN_PRESET_ID' );
    $lay = $call( 'audit_site', [ 'scope' => 'layouts' ] );
    $check( null === $lay['error'] && 2 <= (int) $lay['result']['total_items'] && in_array( $H, array_column( $lay['result']['findings'], 'id' ), true ) === false, 'scope layouts audits the live header and footer (valid fixtures: no findings for them)' );
    $same = true;
    foreach ( $mine as $id ) {
        $same = $same && $state( $id ) === $snap[ $id ];
    }
    $check( $same, 'the audit changed no page: content, post_modified and history are identical' );
    $check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) === $postsBefore && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() ) === $batchesBefore, 'the audit created no post and no batch' ); // phpcs:ignore
} catch ( \Throwable $e ) {
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    // ---- 6. cleanup: exactly what this run created, newest first -----------------------------------------------------
    try {
        // The Role Editor option is restored in its own finally above; this is only a last-resort guard
        // for an exception thrown before that block was reached.
        if ( $roleSentinel === get_option( $roleOption, $roleSentinel ) && $roleExisted ) {
            update_option( $roleOption, $roleBefore );
        }
        $remove = static function ( int $id ): void {
            if ( $id > 0 && null !== get_post( $id ) ) {
                wp_delete_post( $id, true );
            }
        };
        foreach ( [ $P, $PG, $DT, $DL, $T, $B, $O, $F, $H ] as $id ) {
            $remove( (int) $id );
        }
        if ( $draftTb > 0 ) {
            if ( $createdDraftTb ) {
                $remove( $draftTb );
            } else {
                delete_post_meta( $draftTb, '_et_template', $DT );
                if ( get_post_meta( $draftTb, '_et_template', false ) !== $draftTemplatesBefore ) {
                    delete_post_meta( $draftTb, '_et_template' );
                    foreach ( $draftTemplatesBefore as $tid ) {
                        add_post_meta( $draftTb, '_et_template', $tid );
                    }
                }
            }
            null === $draftBackupBefore ? delete_option( $backupOption( $draftTb ) ) : update_option( $backupOption( $draftTb ), $draftBackupBefore );
        }
        if ( $tb > 0 ) {
            if ( $createdTb ) {
                $remove( $tb );
            } else {
                delete_post_meta( $tb, '_et_template', $T );
                if ( get_post_meta( $tb, '_et_template', false ) !== ( $tbTemplatesBefore ?? [] ) ) {
                    delete_post_meta( $tb, '_et_template' );
                    foreach ( $tbTemplatesBefore ?? [] as $tid ) {
                        add_post_meta( $tb, '_et_template', $tid );
                    }
                }
            }
            null === $liveBackupBefore ? delete_option( $backupOption( $tb ) ) : update_option( $backupOption( $tb ), $liveBackupBefore );
        }
        foreach ( $created as $id ) {
            $remove( (int) $id ); // anything created but not reached above (an abort part-way through the fixtures)
        }
        if ( [] !== $batchIds ) {
            $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
        }
        et_theme_builder_clear_wp_cache( 'all' );
        if ( class_exists( 'ET_Core_PageResource' ) ) {
            ET_Core_PageResource::remove_static_resources( 'all', 'all' );
        }
    } catch ( \Throwable $e ) {
        $failures[] = 'cleanup aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
        WP_CLI::log( 'FAIL  cleanup aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
    }
}

// ---- the site is exactly as it was ----------------------------------------------------------------------------------------
$finalCount = $countTb();
$leftover   = $countAied();
$roleNow    = get_option( $roleOption, $roleSentinel );
$historyGone = true;
foreach ( [ $H, $F ] as $id ) {
    if ( $id > 0 && 0 !== count( HistoryStore::summaries( (int) $id ) ) ) {
        $historyGone = false;
    }
}
$check( $finalCount === $baselineCount && 0 === $leftover, "cleanup: Theme Builder post count {$finalCount} equals the baseline {$baselineCount} and no post titled 'aied verify ...' remains ({$leftover})" );
$check( $historyGone, 'cleanup: no history rows remain for the throwaway layouts' );
$left = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
$check( 0 === $left, "cleanup: none of the batch rows this run created remain ({$left} left)" );
$check( $roleExisted ? $roleNow === $roleBefore : $roleSentinel === $roleNow, "cleanup: {$roleOption} is " . ( $roleExisted ? 'unchanged' : 'not existing, as at the start' ) );
$check( (int) et_theme_builder_get_theme_builder_post_id( true, false ) === $liveTbBefore && (int) et_theme_builder_get_theme_builder_post_id( false, false ) === $draftTbBefore, 'cleanup: the live and draft Theme Builder post ids are what they were at the start' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Pro site-wide tools verified through the real registry.' );
```

Create `scripts/verify-pro-rest.php`:

```php
<?php
/**
 * Real-site check of the Pro REST and ChatGPT (OpenAPI) faces on the real WordPress REST server: the routes exist
 * for every Pro tool, authenticate with the plugin API key (and refuse an anonymous call), map errors to 400/403/401,
 * run the tool through the same capability gate as MCP, and the live /openapi.json carries every Pro operation inside
 * the ChatGPT importer's limits. Read-only: it calls only previews and read tools and one invalid-argument write, and
 * it creates and changes nothing.
 *
 * Run: make verify-pro-rest   (needs `make up`, the free plugin and the Pro add-on active on the dev site; no licence
 * is needed: this script registers the Pro REST/OpenAPI hooks itself with the unlock forced and rebuilds the REST
 * server, exactly as the add-on wires them when licensed)
 */

use AiEditorDivi5\Pro\Rest\ProOpenApi;
use AiEditorDivi5\Pro\Rest\ProRest;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( \AiEditorDivi5\WP\RestController::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro on this site first.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};

// The add-on only wires these while licensed; force the unlock here, BEFORE the REST server is first built.
$registry = new ToolRegistry( Tools::all(), static fn (): bool => true );
( new ProRest( $registry ) )->register();
( new ProOpenApi( $registry ) )->register();
// Under WP-CLI the REST server was already built during bootstrap, before these hooks existed: build it again so
// the routes are registered (on a real request the server is built after plugins load, so this is not needed).
$GLOBALS['wp_rest_server'] = null;

$ns   = '/ai-editor-divi5/v1';
$send = static function ( string $route, array $body = [], ?string $rawBody = null, string $method = 'POST' ) use ( $ns ): array {
    $req = new WP_REST_Request( $method, $ns . $route );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( $rawBody ?? wp_json_encode( $body ) );
    $res  = rest_do_request( $req );
    $data = (array) $res->get_data();

    return [ 'status' => $res->get_status(), 'data' => $data ];
};
$asKey = static function ( callable $fn ) {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    wp_set_current_user( 0 );
    try {
        return $fn();
    } finally {
        unset( $_SERVER['HTTP_AUTHORIZATION'] );
        wp_set_current_user( 1 );
    }
};

$names = array_map( static fn ( $t ): string => $t->name(), Tools::all() );

// ---- the live OpenAPI spec -----------------------------------------------------------------------------------------
$spec = $send( '/openapi.json', [], '', 'GET' );
$paths = (array) ( $spec['data']['paths'] ?? [] );
$ops   = 0;
$long  = 0;
$ids   = [];
foreach ( $paths as $methods ) {
    foreach ( (array) $methods as $op ) {
        $ops++;
        $long += strlen( (string) ( $op['description'] ?? '' ) ) > 300 ? 1 : 0;
        $ids[] = $op['operationId'] ?? '';
    }
}
$check( 200 === $spec['status'], 'GET /openapi.json answers 200' );
$proPaths = array_filter( array_keys( $paths ), static fn ( string $p ): bool => str_starts_with( $p, '/pro/' ) );
$check( count( $proPaths ) === count( $names ) && [] === array_diff( array_map( static fn ( string $n ): string => '/pro/' . $n, $names ), array_keys( $paths ) ), 'the live spec has one /pro/ operation for each of the ' . count( $names ) . ' Pro tools' );
$check( $ops <= 30 && 0 === $long && count( $ids ) === count( array_unique( $ids ) ), "the live spec has {$ops} operations (limit 30), every description at most 300 characters, unique operationIds" );
$check( isset( $spec['data']['components']['schemas']['ProResult'], $spec['data']['components']['schemas']['ProError'] ), 'the ProResult and ProError schemas are in the spec' );

// ---- authentication -------------------------------------------------------------------------------------------------
wp_set_current_user( 0 );
$anon = $send( '/pro/list_batches', [] );
wp_set_current_user( 1 );
$check( 401 === $anon['status'], 'an anonymous call is 401' );
$keyed = $asKey( static fn () => $send( '/pro/list_batches', [] ) );
$check( 200 === $keyed['status'] && isset( $keyed['data']['batches'] ), 'the plugin API key (Bearer) authenticates: list_batches answers 200' );
$logged = $send( '/pro/list_batches', [] );
$check( 200 === $logged['status'], 'a logged-in administrator is accepted too' );

// ---- tools over REST ------------------------------------------------------------------------------------------------
$a = $send( '/pro/audit_site', [ 'scope' => 'pages' ] );
$check( 200 === $a['status'] && isset( $a['data']['total_items'] ) && array_key_exists( 'next_cursor', $a['data'] ), 'audit_site over REST: 200 with total_items and next_cursor' );
$f = $send( '/pro/find_replace_site', [ 'find' => 'AIED-REST-NOWHERE-' . wp_generate_password( 12, false ), 'replace' => 'x', 'dry_run' => true ] );
$check( 200 === $f['status'] && 0 === ( $f['data']['matched'] ?? -1 ), 'find_replace_site dry run over REST: 200, matched 0' );
$l = $send( '/pro/list_theme_layouts', [] );
$check( 200 === $l['status'] && isset( $l['data']['templates'] ), 'list_theme_layouts over REST: 200 with templates' );

// ---- error mapping --------------------------------------------------------------------------------------------------
$bad = $send( '/pro/set_front_page', [ 'page_id' => 'not-a-number' ] );
$check( 400 === $bad['status'] && 'invalid_argument' === ( $bad['data']['code'] ?? null ) && false !== strpos( (string) ( $bad['data']['message'] ?? '' ), 'page_id' ), 'an invalid argument is 400 with the tool\'s own message (nothing was changed)' );
$nf = $send( '/pro/undo_batch', [ 'batch_id' => 987654321 ] );
$check( 400 === $nf['status'] && false !== strpos( (string) ( $nf['data']['message'] ?? '' ), 'not found' ), 'undo_batch of a batch that does not exist is 400 "not found"' );
$arr = $send( '/pro/list_batches', [], '[1,2,3]' );
$check( 400 === $arr['status'], 'a JSON body that is a list, not an object, is 400' );

// ---- the tool's own capability, same gate as MCP ----------------------------------------------------------------------
$strip = static fn ( $caps ) => array_merge( (array) $caps, [ 'edit_theme_options' => false ] );
add_filter( 'user_has_cap', $strip );
try {
    $no = $send( '/pro/list_theme_layouts', [] );
    $check( 403 === $no['status'] && 'forbidden' === ( $no['data']['code'] ?? null ), 'without edit_theme_options, list_theme_layouts is 403 over REST' );
    $ok = $send( '/pro/list_batches', [] );
    $check( 200 === $ok['status'], 'a tool that needs a different capability still works' );
} finally {
    remove_filter( 'user_has_cap', $strip );
}

// ---- a locked licence --------------------------------------------------------------------------------------------------
$locked = new ProRest( new ToolRegistry( Tools::all(), static fn (): bool => false ) );
$r      = $locked->handle( 'list_batches', new WP_REST_Request( 'POST', $ns . '/pro/list_batches' ) );
$check( $r instanceof WP_Error && 403 === ( $r->get_error_data()['status'] ?? 0 ), 'with the licence locked a call is refused (403 pro_locked)' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Pro REST and OpenAPI faces verified on the real REST server.' );
```

(Both scripts come from the extractor byte for byte. The unicode-escape warning in "Execution notes" matters here too: do not retype them.)

- [ ] **Step 2: Add the Makefile targets**

In `Makefile`, add `verify-pro-site-tools verify-pro-rest` to the end of the `verify-pro-theme-layouts verify-pro-theme-templates` part of the `.PHONY` line, and add before the `# validate` banner:

```make
# ---------------------------------------------------------------
# verify-pro-site-tools — real-site check of find_replace_site and audit_site through the real registry (self-cleaning; free + Pro plugins must be active; dev site only: it evicts old batch rows)
# ---------------------------------------------------------------
verify-pro-site-tools:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-site-tools.php --user=1

# ---------------------------------------------------------------
# verify-pro-rest — real-site check of the Pro REST routes and the live OpenAPI spec (read-only; free + Pro plugins must be active)
# ---------------------------------------------------------------
verify-pro-rest:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-rest.php --user=1
```
(Recipe lines start with a TAB.)

- [ ] **Step 3: Lint, activate Pro, run both live, deactivate Pro**

```bash
php -l scripts/verify-pro-site-tools.php && php -l scripts/verify-pro-rest.php
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin activate jhmg-ai-editor-for-divi-5-pro
make verify-pro-site-tools 2>&1 | tail -60
make verify-pro-rest 2>&1 | tail -25
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin deactivate jhmg-ai-editor-for-divi-5-pro
```
Expected: no `FAIL` line anywhere; `Success: Pro site-wide tools verified through the real registry.` and `Success: Pro REST and OpenAPI faces verified on the real REST server.`; the cleanup lines all `PASS` (Theme Builder post count back to its baseline, no `aied verify` posts left, no history rows, no batch rows). If the first command fails, still run the deactivate.

- [ ] **Step 4: Confirm the site is as it was**

Run: `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin list --fields=name,status | grep jhmg-ai` → the free plugin `active`, the Pro add-on `inactive`.

- [ ] **Step 5: Commit**

```bash
git add scripts/verify-pro-site-tools.php scripts/verify-pro-rest.php Makefile
git commit -q -F - <<'EOF'
test(pro): live verification of the site-wide tools and the REST/OpenAPI faces (make verify-pro-site-tools, make verify-pro-rest)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).

### Task 6: Documentation, version 0.4.0, final checks

**Files:**
- Modify: `pro-addon/README.md`, `pro-addon/readme.txt`, `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`, `pro-addon/tests/bootstrap.php`, `pro-addon/tests/ReadmeTest.php`, `CLAUDE.md`, `docs/superpowers/specs/2026-10-02-pro-addon-design.md`

- [ ] **Step 1: Bump the version to 0.4.0 and update the descriptions**

- `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`: ` * Version:           0.3.0` → `0.4.0`; `define( 'AIED_PRO_VERSION', '0.3.0' );` → `'0.4.0'`; in the header `Description:` line change `edit the live Divi header and footer, file PHP snippets` to `edit the live Divi header and footer, find and replace text across your pages in one undoable batch, audit pages for layout problems and broken links, file PHP snippets`.
- `pro-addon/tests/bootstrap.php`: `define( 'AIED_PRO_VERSION', '0.3.0' );` → `'0.4.0'`.
- `pro-addon/readme.txt`: `Stable tag:        0.3.0` → `0.4.0`; in the short description change `add CSS and edit the live Divi header and footer, and lets you undo each change in one step.` to `add CSS, edit the live Divi header and footer, find and replace text across your pages and audit them for layout problems and broken links, and lets you undo each change in one step.`; before the sentence beginning `Each tool checks the permission it really needs` add the paragraph `Every tool is also available over REST (`POST /wp-json/ai-editor-divi5/v1/pro/<tool>`) and in the free plugin's ChatGPT OpenAPI spec, with the same permission checks. The Pro tools stay off, over every transport, until the licence is unlocked.` followed by a blank line; and before `= 0.3.0 =` in the changelog add:

```
= 0.4.0 =
* New: `find_replace_site` (literal find and replace across your Divi 5 pages as one previewed, undoable batch) and `audit_site` (a read-only check for layout problems, unknown preset or color ids and links to missing or unpublished pages).
* New: every Pro tool is also available over REST (`POST /wp-json/ai-editor-divi5/v1/pro/<tool>`) and in the free plugin's ChatGPT OpenAPI spec, with the same permission checks as the MCP tools.

```
- `pro-addon/tests/ReadmeTest.php`: in `testTheHeaderDescriptionClaimsNothingThatIsNotShipped` replace the banned list `[ 'site-wide', 'audit', 'find and replace', 'find/replace' ]` (those features now ship) with `[ 'regex', 'regular expression', 'woocommerce', 're-brand', 'global colors', 'whole site from a brief' ]` (what is genuinely not shipped).

- [ ] **Step 2: Update `pro-addon/README.md`**

- `## What exists (0.3.0)` → `## What exists (0.4.0)`.
- In the `src/ProTool.php, src/ToolRegistry.php, src/Tools.php` row replace `` `Tools::all()` lists the eleven tools `` with `` `Tools::all()` lists the thirteen tools; `src/ToolRunner.php` is the one call path (capability check, argument errors, no leaked failures) shared by MCP and REST ``.
- In the `src/Tools/` row replace `the eleven tools: ` with `the thirteen tools: ` and append `` , `find_replace_site`, `audit_site` `` after `` `manage_theme_template` ``.
- Before the `| `src/Admin/` |` row add two rows: `` | `src/Site/` | what "the site" means for a site-wide tool (`SiteItems`: Divi 5 pages the user can edit and, with the Theme Builder permission, the live header/footer), the signed preview token (`PreviewToken`), and the internal-link reader (`LinkScanner`, no request is ever made) | `` and `` | `src/Rest/` | the REST and ChatGPT/OpenAPI faces of every Pro tool (`ProRest`: `POST /pro/<tool>`; `ProOpenApi`), generated from the tool list so MCP, REST and the spec cannot drift | ``.
- In the sentence listing the real-site checks replace `` `make verify-pro-theme-layouts` and `make verify-pro-theme-templates` are the real-site checks `` with `` `make verify-pro-theme-layouts`, `make verify-pro-theme-templates`, `make verify-pro-site-tools` and `make verify-pro-rest` are the real-site checks ``.

- [ ] **Step 3: Update `CLAUDE.md`**

- In "Three parts, one repo": `0.3.0: compat guard, licensing with the updater, a licence-gated tool` → `0.4.0: compat guard, ...`; and `registry with 11 tools (settings, batches and undo, Theme Builder header/footer and template tools), the Pro admin tab` → `registry with 13 tools (settings, batches and undo, Theme Builder header/footer and template tools, site-wide find/replace and audit), REST and ChatGPT parity for all of them, the Pro admin tab`.
- In "Current state", before the `**Pro add-on 0.3.0**` bullet add a `**Pro add-on 0.4.0**` bullet summarising: `find_replace_site` (literal; Divi 5 pages, the live header/footer only with scope and the Theme Builder permission; ONE batch of at most 50 items; a signed 30-minute preview token carries the id => content-hash map so an item changed since the preview is skipped; every result through `LayoutGate::check($new, $stored)`, an invalid one skipped, never saved), `audit_site` (read-only: layout violations including stored custom code, unknown preset/color ids via the free advisory, internal links to nothing or to unpublished posts read from the content, 25 items per call with a cursor, no remote request), `ToolRunner` (one call path for MCP and REST), REST (`POST /wp-json/ai-editor-divi5/v1/pro/<tool>`, auth = the free plugin's `require_edit_posts` plus the tool's own capability) and ChatGPT OpenAPI parity generated from the tool list (16 free + 13 Pro = 29 of the importer's 30 operations); the plan `docs/superpowers/plans/2026-10-03-pro-site-tools-and-rest.md`; verified by `make test-pro`, `make test`, `make verify-pro-site-tools`, `make verify-pro-rest`; known limit: the audit's link check cannot tell a missing page from an archive or custom route (the finding says so).
- In the `**Pro add-on 0.3.0**` bullet change `(branch `feat/pro-theme-layouts`; NOT merged, NOT released, NOT pushed)` to `(merged to LOCAL main d20c22d; NOT released, NOT pushed)` and delete its trailing sentence `Next: Plan 5 (`find_replace_site`, `audit_site`, REST/OpenAPI parity: Pro = 13 tools, 29 of 30 ChatGPT operations).` (keep the `(real Divi 5.14).` before it as the end of that bullet).
- In the `**Pro add-on 0.2.0**` bullet change `(branch `feat/pro-settings-batches`; NOT merged, NOT released, NOT pushed)` to `(merged to LOCAL main dd929d6; NOT released, NOT pushed)`, change `MCP + admin only: REST/OpenAPI for the Pro tools is Plan 5. Plan:` to `MCP + admin only at that version (REST/OpenAPI came with 0.4.0). Plan:`, and delete `Next: Plan 5 (find/replace, audit, REST/OpenAPI` plus its continuation line `parity, live verification). ` so the sentence continues with `Before the first Pro sale`.
- In the entry-points table add after the `make verify-pro-theme-templates` row: `| make verify-pro-site-tools | Real-site check of find_replace_site and audit_site through the real registry (self-cleaning; dev site only: it evicts old batch rows) |` and `| make verify-pro-rest | Real-site check of the Pro REST routes and the live OpenAPI spec (read-only) |`.

- [ ] **Step 4: Update the spec (`docs/superpowers/specs/2026-10-02-pro-addon-design.md`)**

- Before `## Licensing and distribution` add the section:

```
## REST and ChatGPT parity (built in 0.4.0)

Every Pro tool is also exposed as `POST /wp-json/ai-editor-divi5/v1/pro/<tool_name>` (the tool's arguments as the JSON body) and as one operation in the free plugin's OpenAPI spec, both generated from the tool list so MCP, REST and the spec cannot drift. Authentication is the free plugin's own (`RestController::require_edit_posts`: plugin API key, Application Password or a logged-in editor); the tool's own capability, argument checks and error mapping are one shared `ToolRunner` (bad argument 400, missing capability 403, anything else a generic 500). While the licence is locked no route and no operation is registered. The spec stays at 29 of the importer's 30 operations (16 free + 13 Pro), every description at most 300 characters, no bare object schema.
```
- In "Verification", replace the two bullets `- Batches: find/replace across throwaway pages and a layout, then undo, including a changed-since case.` and `- Audit over the real dev pages and the fixtures.` with `- Batches: find/replace across throwaway pages and a layout, then undo, including a changed-since case (`make verify-pro-site-tools`, run 2026-10-03: passed).`, `- Audit over the real dev pages and the fixtures, paged through the whole site (same script).` and `- REST and the live OpenAPI spec on the real REST server (`make verify-pro-rest`, run 2026-10-03: passed).`

- [ ] **Step 5: Final checks**

```bash
make test-pro 2>&1 | tail -3
make test 2>&1 | tail -2
make build-pro 2>&1 | tail -2
unzip -l dist/jhmg-ai-editor-for-divi-5-pro.zip | grep -E "Site/|Rest/|ToolRunner|staged|tests/"
git status --short
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin list --fields=name,status | grep jhmg-ai
```
Expected: `OK (679 tests, 2710 assertions)` for the Pro suite; `OK (726 tests, 5241 assertions)`; the zip lists `src/Site/*`, `src/Rest/*` and `src/ToolRunner.php` and NO `staged` or `tests/` entries; a clean tree apart from the docs you just edited; the free plugin `active` and the Pro add-on `inactive`.

- [ ] **Step 6: Commit**

```bash
git add pro-addon CLAUDE.md docs/superpowers/specs/2026-10-02-pro-addon-design.md
git commit -q -F - <<'EOF'
docs(pro): 0.4.0 (site-wide find/replace, audit, REST and ChatGPT parity): version, README, readme.txt changelog, CLAUDE.md, spec

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).

- [ ] **Step 7: Final whole-branch review on the most capable model (opus)**

Dispatch one reviewer over `git diff main...feat/pro-site-tools` with the Review Focus list above and "What the research settled" as the brief. Verify its claims with grep before acting. Fix real findings in ONE dispatch, then one scoped re-review. Do not merge, push or release until asked.

---

## As built (deviations from the text above)

Recorded during execution on 2026-10-03; the code in the repo is authoritative where it differs from the plan text.

- **Task 3 fix round:** the audit link check hands `url_to_postid` the ORIGIN of `home_url()` plus the root-relative path (the plan built `rtrim( home_url(), '/' ) . $path`, which doubled the path on subdirectory installs and reported every internal link as LINK_NO_POST); `LinkScanner` skips WordPress paths (`wp-content`, `wp-includes`, `wp-admin`, `wp-json`, `wp-login.php`) as a segment anywhere in the path, a final `feed` segment, paths whose last segment has a file extension, and search links; the attribute regex is possessive (a very long value no longer silently disables the scan); the test shim strips the home path like WordPress; `AuditSiteTest` and `LinkScannerTest` gained the matching cases. End state: `make test-pro` 689 tests at that point (702 at the end, see below), `make test` 726.
- **Gate results:** `make verify-pro-site-tools` and `make verify-pro-rest` passed on the Docker dev site (Divi 5.14; Pro add-on activated only for the runs and deactivated afterwards).
- **Deferred on purpose (reviews):** the preview token is not tied to the user and can be replayed within 30 minutes after an `undo_batch` restores the previewed content; an item that loses the text since the preview is not reported; `audit_site` does not report that more than 200 links in one item were not checked and does not memoise `url_to_postid` / the style-id lookup across items; generated OpenAPI summaries cut at the first period and 401/500 responses are not documented; `ProParityTest` compares property keys only; one operation of ChatGPT headroom is left (29 of 30), so a 14th Pro operation needs an explicit decision; the live scripts keep a few vacuous or weak checks (listed in the review ledger, dev-only).
- **Final-review fix wave (opus review of the whole branch, 2026-10-03):** `find_replace_site` now reports as `skipped` (reason `changed_since_preview`, id only) every previewed item that dropped out of the plan since the preview (text edited away, page trashed, edit right lost), including the case where nothing matches any more on a real run; the vacuous invalid-item test was rewritten so one real run holds one invalid and one valid item; `scope=layouts` is refused in both tools unless the user has `edit_theme_options` AND Divi's Theme Builder permission; `audit_site` adds a `LINKS_NOT_ALL_CHECKED` finding at the 200-link cap and `LinkScanner` skips query-only root links (`/?utm_source=x`) unless they carry `p=` or `page_id=`; `SiteItems::collect` suspends object-cache additions while it walks the pages; `ProParityTest` compares whole property arrays; `ToolRegistryTest` runs standalone. End state: `make test-pro` 702 tests, `make test` 726; both live scripts re-run on the dev site against the final code and passed.
- **Still deferred (reviews):** the audit cursor is an index offset (the window shifts if pages are created or deleted between calls); a `/?p=N` link's status is reported without a `read_post` check; the token is not tied to the user.
