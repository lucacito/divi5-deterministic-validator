# Edit Divi Project layouts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The AI can list, read, edit, preview (dry run), save, undo and restore the layout of a Divi Project exactly as it does for a page, behind the same validator gate, history snapshot, dry run and approval mode.

**Architecture:** `EditTarget` (the single rule behind every page tool and route) gets an allowlist `['page', 'project']`; all handlers keep calling it, so undo, dry run and approval mode carry over untouched. Listing (`list_divi_pages`, `GET /pages`, `listDiviPages`) gets an optional `post_type` argument so Projects can be discovered. No new tool, no new OpenAPI operation.

**Tech Stack:** PHP 8.x, PHPUnit (`vendor/bin/phpunit`), WordPress shims in `tests/`, WP-CLI live gate in Docker (`make verify-project-layouts`).

**Spec:** `docs/superpowers/specs/2026-10-08-project-layouts-design.md` (read it first; this plan implements it).

## Global Constraints

- Branch `feat/project-layouts`. Never touch `src/` or `wp-plugin/validator/` (the validator is out of scope).
- Allowed types, exactly: `EditTarget::ALLOWED_TYPES = [ 'page', 'project' ]`. Blog posts (`post`), attachments, Theme Builder types and every other type stay "not found". No filter for extra types.
- `EditTarget::resolve( int $id ): array{post: ?\WP_Post, kind: ?string, error: ?string}` keeps its signature; `EditTarget::NOT_FOUND = 'not_found'` and `EditTarget::FORBIDDEN = 'forbidden'` keep their values (Pro add-on's `pro-addon/tests/FreeApiCompatTest.php` pins them). A wrong type is `NOT_FOUND` (checked before the capability); no `edit_post` is `FORBIDDEN`; `kind` is the post type of a resolved post.
- No new tool: the free tool count stays 18 and the ChatGPT importer's 30-operation budget is untouched. `page_id` and the `/pages/{id}` routes keep their names.
- `list_divi_pages` / `GET /pages` / `listDiviPages` gain an optional `post_type` (`page` default or `project`); anything else (any other string, a non-string) is rejected: REST 400 `invalid_field`, MCP -32602, never cast. The capability check (`PageAccess::CAP`) stays BEFORE the query; the query stays filtered by `_et_pb_use_divi_5 == on`, at most 100, only posts the user can `edit_post`. Each listed item gains `post_type`; the response keys stay `pages` and `count`.
- `create_page` stays page-only. Every write still goes through `LayoutGate::check()`, `HistoryService` and (when on) approval mode; none of that code changes.
- Read-only on settings/meta: no new meta writes, no remote calls, no custom-code saving; `WpOrgComplianceTest` and `NoThemeLayoutsInFreeTest` are never weakened; no "Theme Builder" / header-footer-layout wording under `wp-plugin/`.
- Every PHP file under `wp-plugin/src/` keeps `declare(strict_types=1);`, the `AiEditorDivi5\WP` namespace and the ABSPATH guard.
- Do NOT bump the plugin version, edit the readme changelog or rebuild `jhmg-ai-editor-for-divi-5.zip` (release is the owner's step). Do not touch or stage `CLAUDE.md` except in Task 5 via the recipe given there (the owner has uncommitted edits in it).
- Commit trailer on every commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Test commands: `vendor/bin/phpunit --filter <Name>` for one class, `vendor/bin/phpunit` for the suite (baseline: 946 tests pass), `make test-pro` (baseline 1513 pass).

## Review Focus

1. A blog post (`post`) or attachment id must be "not found" on EVERY id-taking tool and route, with the content untouched and no `wp_update_post` call (Task 2 `testABlogPostIsNotFoundOnEveryIdTakingToolAndRoute`).
2. A Project the user cannot edit gets the same 403 / permission refusal as a page, not a leak and not a save (Task 2 `testAProjectTheUserCannotEditIsForbidden`).
3. `post_type` list argument edge cases: missing, null, empty string (all mean `page`), `PROJECT` (wrong case) and a non-string (array, int, bool) are handled deterministically: only the exact strings `page` and `project` pass (Task 3).
4. An invalid layout on a Project is refused with the violations and nothing is saved or snapshotted (Task 2 `testAnInvalidLayoutIsRefusedForAProject`).
5. Restore of a Project brings back the old content and is itself snapshotted (Task 2 `testRestoreBringsBackAProjectsOldContent`).

---

### Task 1: `EditTarget` allowlist

**Files:**
- Modify: `wp-plugin/src/EditTarget.php`
- Modify: `tests/EditTargetTest.php`

**Interfaces:**
- Produces: `EditTarget::ALLOWED_TYPES` (`public const`, `list<string>` = `['page', 'project']`); `EditTarget::resolve( int $id )` now also resolves `project` posts, `kind` = the post's type.

- [ ] **Step 1: Write the failing tests**

In `tests/EditTargetTest.php`:

(a) In `setUp()`, after `$this->post( 5, 'page' );` add a Project and a second blog-post-like type:

```php
        $this->post( 8, 'project' );
```

(b) Replace `testAPageTheUserCanEditResolves` and add the Project tests below it:

```php
    public function testAPageTheUserCanEditResolves(): void
    {
        $r = EditTarget::resolve( 5 );
        $this->assertSame( [ 5, 'page', null ], [ $r['post']->ID, $r['kind'], $r['error'] ] );
    }

    public function testADiviProjectTheUserCanEditResolvesWithItsOwnKind(): void
    {
        $r = EditTarget::resolve( 8 );
        $this->assertSame( [ 8, 'project', null ], [ $r['post']->ID, $r['kind'], $r['error'] ] );
    }

    public function testTheAllowlistIsExactlyPagesAndProjects(): void
    {
        $this->assertSame( [ 'page', 'project' ], EditTarget::ALLOWED_TYPES );
    }

    public function testAProjectTheUserCannotEditIsForbidden(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_post:8' => false ];
        $r = EditTarget::resolve( 8 );
        $this->assertSame( EditTarget::FORBIDDEN, $r['error'] );
        $this->assertNull( $r['post'] );
        $this->assertNull( $r['kind'] );
        $this->assertNull( EditTarget::resolve( 5 )['error'], 'the page is still editable' );
    }
```

(c) Rename the data-provider test `testEverythingThatIsNotAPageIsNotFoundNeverForbidden` to `testEverythingThatIsNotAPageOrAProjectIsNotFoundNeverForbidden` (keep its body and provider; the provider already holds a blog post (6), an attachment (7) and the Theme Builder ids, none of which may resolve).

(d) Rename `testANonPageIsNotFoundEvenWhenTheUserCannotEditIt` to `testAnotherTypeIsNotFoundEvenWhenTheUserCannotEditIt` (body unchanged).

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter EditTargetTest`
Expected: FAIL (`ALLOWED_TYPES` undefined; the Project resolves as NOT_FOUND).

- [ ] **Step 3: Implement**

Replace the body of `wp-plugin/src/EditTarget.php` after the `use`/`ABSPATH` header with:

```php
/**
 * The one rule for "which post may the plugin read and edit": a page or a Divi Project the current user can edit.
 * Anything else is reported like a missing post, so nothing leaks about other post types.
 */
final class EditTarget
{
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';

    /** The only post types the plugin reads and edits layouts of. A fixed list on purpose. */
    public const ALLOWED_TYPES = [ 'page', 'project' ];

    /**
     * @return array{post: ?\WP_Post, kind: ?string, error: ?string} kind is the post type ('page' or 'project') or null
     */
    public static function resolve( int $id ): array
    {
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post || ! in_array( $post->post_type, self::ALLOWED_TYPES, true ) ) {
            return self::fail( self::NOT_FOUND );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return self::fail( self::FORBIDDEN );
        }

        return [ 'post' => $post, 'kind' => $post->post_type, 'error' => null ];
    }

    /** @return array{post: null, kind: null, error: string} */
    private static function fail( string $error ): array
    {
        return [ 'post' => null, 'kind' => null, 'error' => $error ];
    }
}
```
(Keep whatever `declare`, namespace and ABSPATH lines the file already has above the docblock.)

- [ ] **Step 4: Run to verify they pass, then the suites that call EditTarget**

Run: `vendor/bin/phpunit --filter EditTargetTest` (PASS), then `vendor/bin/phpunit` (all pass; baseline 946) and `make test-pro` (1513 pass: `FreeApiCompatTest` pins the signature and constants).

- [ ] **Step 5: Commit**

```bash
git add wp-plugin/src/EditTarget.php tests/EditTargetTest.php
git commit -m "$(cat <<'EOF'
feat: EditTarget accepts Divi Projects (allowlist page + project)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: a Project goes through every handler (integration tests)

**Files:**
- Create: `tests/ProjectLayoutsHandlersTest.php`

**Interfaces:**
- Consumes: `EditTarget` (Task 1) through the REAL `McpHandler` and `RestController`. No production code changes expected in this task; if a test reveals a real defect, STOP and report it as a finding instead of patching silently.

- [ ] **Step 1: Write the tests**

Create `tests/ProjectLayoutsHandlersTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ApprovalMode;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/ApprovalMode.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/**
 * A Divi Project (post 5) is read, edited, previewed, snapshotted and restored through the REAL REST and MCP handlers exactly like
 * a page, and a blog post (post 6) is refused everywhere. Post 7 is a page, to prove pages still work.
 */
class ProjectLayoutsHandlersTest extends TestCase
{
    private const ORIGINAL = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private const NEW_VALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private const NEW_INVALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/not-a-module {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private function addPost( int $id, string $type ): void
    {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = $type;
        $post->post_title   = "Post {$id}";
        $post->post_content = self::ORIGINAL;
        $GLOBALS['__wp_posts'][ $id ]    = $post;
        $GLOBALS['__wp_postmeta'][ $id ] = [];
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']         = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_transient_ttl']   = [];
        $GLOBALS['__wp_current_user_id'] = 7;
        $GLOBALS['__wp_posts']           = [];
        $GLOBALS['__wp_postmeta']        = [];
        $GLOBALS['__wp_update_fail']     = false;
        $GLOBALS['__wp_update_calls']    = 0;
        $GLOBALS['__wp_can']             = true;
        unset( $GLOBALS['__wp_caps'] );
        $this->addPost( 5, 'project' );
        $this->addPost( 6, 'post' );
        $this->addPost( 7, 'page' );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']            = null;
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        unset( $GLOBALS['__wp_current_user_id'], $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can'] = true;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function unwrap( mixed $r ): array
    {
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code, 'message' => $r->message ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( string $method, int $id, array $body = [] ): array
    {
        return $this->unwrap( ( new RestController() )->$method( new \WP_REST_Request( $body, [ 'id' => $id ] ) ) );
    }

    /** @return array{0:?int,1:array<string,mixed>,2:bool,3:string} JSON-RPC error code, decoded tool result, isError, error message */
    private function mcp( string $tool, array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], [], true, (string) $data['error']['message'] ];
        }

        return [ null, (array) json_decode( $data['result']['content'][0]['text'], true ), (bool) ( $data['result']['isError'] ?? false ), '' ];
    }

    private function assertUnchanged( int $id, string $why ): void
    {
        $this->assertSame( self::ORIGINAL, $GLOBALS['__wp_posts'][ $id ]->post_content, "{$why}: content changed" );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], "{$why}: wp_update_post was called" );
    }

    // ---- reading ------------------------------------------------------------------------------

    public function testAProjectCanBeReadOverRestAndMcp(): void
    {
        [ $status, $data ] = $this->rest( 'get_page', 5 );
        $this->assertSame( 200, $status );
        $this->assertSame( 'project', $data['post_type'] );
        $this->assertSame( self::ORIGINAL, $data['post_content'] );

        [ $code, $result, $isError ] = $this->mcp( 'get_page_layout', [ 'page_id' => 5 ] );
        $this->assertNull( $code );
        $this->assertFalse( $isError );
        $this->assertSame( 'project', $result['post_type'] );
        $this->assertSame( self::ORIGINAL, $result['post_content'] );
    }

    public function testAPageStillWorks(): void
    {
        [ $status, $data ] = $this->rest( 'get_page', 7 );
        $this->assertSame( 200, $status );
        $this->assertSame( 'page', $data['post_type'] );
    }

    // ---- writing: save, refuse, dry run, approval mode ---------------------------------------------

    public function testAValidLayoutIsSavedToAProjectAfterASnapshot(): void
    {
        [ $status, $data ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertTrue( $data['history']['stored'], 'the previous content was snapshotted first' );
        $this->assertSame( self::NEW_VALID, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 1, $GLOBALS['__wp_update_calls'] );
    }

    public function testAnInvalidLayoutIsRefusedForAProject(): void
    {
        [ $status, $data ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_INVALID ] );
        $this->assertSame( 422, $status );
        $this->assertFalse( $data['saved'] );
        $this->assertNotEmpty( $data['violations'] );
        $this->assertUnchanged( 5, 'invalid layout' );
        $this->assertSame( [], \AiEditorDivi5\WP\HistoryService::listFor( 5 ), 'nothing was snapshotted' );
    }

    public function testDryRunLeavesAProjectUntouched(): void
    {
        [ $status, $data ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['valid'] );
        $this->assertUnchanged( 5, 'dry run' );
    }

    public function testApprovalModeAppliesToProjects(): void
    {
        ApprovalMode::set( true );
        [ $status, $data ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 428, $status );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] );
        $this->assertUnchanged( 5, 'no preview' );

        $this->assertSame( 200, $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] )[0] );
        [ $status ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 200, $status, 'saved after a valid preview' );
        $this->assertSame( self::NEW_VALID, $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testMcpUpdateAndSurgicalEditWorkOnAProject(): void
    {
        [ $code, , $isError ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID ] );
        $this->assertNull( $code );
        $this->assertFalse( $isError );
        $this->assertSame( self::NEW_VALID, $GLOBALS['__wp_posts'][5]->post_content );

        [ $code, , $isError ] = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => '4_4', 'replace' => '1_2', 'expect_count' => 1 ] );
        $this->assertNull( $code );
        $this->assertFalse( $isError );
        $this->assertStringContainsString( '"value":"1_2"', $GLOBALS['__wp_posts'][5]->post_content );
    }

    // ---- undo ------------------------------------------------------------------------------------

    public function testRestoreBringsBackAProjectsOldContent(): void
    {
        [ , $saved ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID ] );
        $versionId   = (int) $saved['history']['version_id'];
        $this->assertGreaterThan( 0, $versionId );

        [ $status, $list ] = $this->rest( 'history_list', 5 );
        $this->assertSame( 200, $status );
        $this->assertSame( 1, $list['count'] );

        [ $status, $data ] = $this->rest( 'history_restore', 5, [ 'version_id' => $versionId ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['restored'] );
        $this->assertSame( self::ORIGINAL, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertTrue( $data['history']['stored'], 'the restore is itself snapshotted' );

        [ $code, $mcpList ] = $this->mcp( 'list_page_history', [ 'page_id' => 5 ] );
        $this->assertNull( $code );
        $this->assertSame( 2, $mcpList['count'] );
    }

    // ---- refusals ---------------------------------------------------------------------------------

    public function testABlogPostIsNotFoundOnEveryIdTakingToolAndRoute(): void
    {
        $restCalls = [
            [ 'get_page', [] ],
            [ 'update_page', [ 'post_content' => self::NEW_VALID ] ],
            [ 'edit_page', [ 'find' => '4_4', 'replace' => '1_2' ] ],
            [ 'history_list', [] ],
            [ 'history_entry', [ 'version_id' => 1 ] ],
            [ 'history_restore', [ 'version_id' => 1 ] ],
        ];
        foreach ( $restCalls as [ $method, $body ] ) {
            [ $status, $data ] = $this->rest( $method, 6, $body );
            $this->assertSame( 404, $status, "REST {$method} on a blog post" );
            $this->assertSame( 'not_found', $data['error'] );
        }
        $mcpCalls = [
            [ 'get_page_layout', [ 'page_id' => 6 ] ],
            [ 'update_page_layout', [ 'page_id' => 6, 'post_content' => self::NEW_VALID ] ],
            [ 'edit_page_content', [ 'page_id' => 6, 'find' => '4_4', 'replace' => '1_2' ] ],
            [ 'list_page_history', [ 'page_id' => 6 ] ],
            [ 'get_page_history_entry', [ 'page_id' => 6, 'version_id' => 1 ] ],
            [ 'restore_page_version', [ 'page_id' => 6, 'version_id' => 1 ] ],
        ];
        foreach ( $mcpCalls as [ $tool, $args ] ) {
            [ $code ] = $this->mcp( $tool, $args );
            $this->assertSame( -32602, $code, "MCP {$tool} on a blog post" );
        }
        $this->assertUnchanged( 6, 'a blog post' );
    }

    public function testAProjectTheUserCannotEditIsForbidden(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'edit_post' => true, 'edit_post:5' => false ];
        [ $status, $data ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 403, $status );
        $this->assertSame( 'forbidden', $data['error'] );
        [ $code, , , $message ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID ] );
        $this->assertSame( -32602, $code );
        $this->assertStringContainsString( 'permission', $message );
        $this->assertUnchanged( 5, 'no edit rights' );
    }
}
```

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/phpunit --filter ProjectLayoutsHandlersTest`
Expected: PASS (Task 1 already widened the rule). If a test fails because of a key or shim mismatch in the test itself (for example the exact name of a key in a handler's response, or `FakeWpdb`'s property for history rows), read the real response shape in `RestController`/`McpHandler`/`tests/FakeWpdb.php` and fix THE TEST; if it fails because a handler genuinely mishandles a Project, STOP and report the finding with the failing output.

- [ ] **Step 3: Mutation check (prove the tests test something)**

Temporarily change `ALLOWED_TYPES` in `wp-plugin/src/EditTarget.php` to `[ 'page' ]`, run `vendor/bin/phpunit --filter ProjectLayoutsHandlersTest` and confirm the Project tests now FAIL (record the count), then restore `[ 'page', 'project' ]` and confirm PASS again. Do not commit the temporary change (`git diff wp-plugin/` must be empty before the commit).

- [ ] **Step 4: Run the whole suite**

Run: `vendor/bin/phpunit`
Expected: all pass (946 + the new tests).

- [ ] **Step 5: Commit**

```bash
git add tests/ProjectLayoutsHandlersTest.php
git commit -m "$(cat <<'EOF'
test: a Divi Project goes through every page handler, a blog post is refused

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: listing Projects (`post_type`) and the wording

**Files:**
- Modify: `wp-plugin/src/PageAccess.php` (new helper)
- Modify: `wp-plugin/src/McpHandler.php` (tool declaration ~line 100-104, dispatch ~line 280, `toolListPages` ~line 343; descriptions and messages)
- Modify: `wp-plugin/src/RestController.php` (`list_pages` ~line 204; messages)
- Modify: `wp-plugin/src/OpenApiSpec.php` (`PageSummary` ~line 73, `listDiviPages` ~line 215)
- Modify: `tests/PageAccessTest.php`, `tests/OpenApiSpecTest.php`
- Create: `tests/ListPostTypeTest.php`
- Modify (only if they fail on the new wording): any test asserting the old "Page N not found" / "permission to ... page" text.

**Interfaces:**
- Consumes: `EditTarget::ALLOWED_TYPES` (Task 1).
- Produces: `PageAccess::postTypeArg( array $args, string $key, array $allowed ): ?string` (`'page'` when the key is absent, null or `''`; the string itself when it is exactly one of `$allowed`; null for anything else, which the caller answers 400 / -32602); MCP `list_divi_pages` and REST `GET /pages` accept `post_type`; list items carry `post_type`; OpenAPI `listDiviPages` documents the query parameter.

- [ ] **Step 1: Write the failing tests**

(a) In `tests/PageAccessTest.php` add (inside the class; `PageAccess` is already imported there):

```php
    public function testPostTypeArgDefaultsToPageAndOnlyAcceptsTheExactAllowedStrings(): void
    {
        $allowed = [ 'page', 'project' ];
        foreach ( [ [], [ 'post_type' => null ], [ 'post_type' => '' ] ] as $none ) {
            $this->assertSame( 'page', PageAccess::postTypeArg( $none, 'post_type', $allowed ) );
        }
        $this->assertSame( 'page', PageAccess::postTypeArg( [ 'post_type' => 'page' ], 'post_type', $allowed ) );
        $this->assertSame( 'project', PageAccess::postTypeArg( [ 'post_type' => 'project' ], 'post_type', $allowed ) );
        foreach ( [ 'post', 'PROJECT', ' project', 'project ', 'et_header_layout', 'attachment', 7, true, [ 'project' ], 1.5, (object) [] ] as $bad ) {
            $this->assertNull( PageAccess::postTypeArg( [ 'post_type' => $bad ], 'post_type', $allowed ), json_encode( $bad ) );
        }
    }
```

(b) Create `tests/ListPostTypeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/WpInsertPostShims.php';
require_once __DIR__ . '/GetPostsShim.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

if ( ! function_exists( 'get_edit_post_link' ) ) {
    function get_edit_post_link( $id = 0, $context = 'display' ) { return 'https://example.com/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
}

/** list_divi_pages / GET /pages: the optional post_type (page by default, or project), validated, on MCP, REST and in the OpenAPI spec. */
class ListPostTypeTest extends TestCase
{
    private function post( int $id, string $type ): \WP_Post
    {
        $p             = new \WP_Post();
        $p->ID         = $id;
        $p->post_type  = $type;
        $p->post_title = "{$type} {$id}";
        $GLOBALS['__wp_postmeta'][ $id ] = [ '_et_pb_use_divi_5' => 'on', '_et_pb_use_builder' => 'on' ];

        return $p;
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__wp_caps']     = [ 'edit_pages' => true, 'edit_post' => true, 'edit_post:21' => false ];
        $GLOBALS['__cfd_posts']   = [
            'page'    => [ $this->post( 11, 'page' ) ],
            'project' => [ $this->post( 20, 'project' ), $this->post( 21, 'project' ) ],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        unset( $GLOBALS['__cfd_posts'], $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can'] = true;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( array $params ): array
    {
        $r = ( new RestController() )->list_pages( new \WP_REST_Request( [], $params ) );
        if ( $r instanceof \WP_Error ) {
            return [ (int) $r->data['status'], [ 'error' => $r->code ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array{0:?int,1:array<string,mixed>} */
    private function mcp( array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'list_divi_pages', 'arguments' => $args ?: new \stdClass() ] ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], [] ];
        }

        return [ null, (array) json_decode( $data['result']['content'][0]['text'], true ) ];
    }

    public function testTheDefaultIsPagesAndEveryItemReportsItsPostType(): void
    {
        foreach ( [ $this->rest( [] ), [ 200, $this->mcp( [] )[1] ] ] as [ $status, $d ] ) {
            $this->assertSame( 200, $status );
            $this->assertSame( [ 11 ], array_column( $d['pages'], 'id' ) );
            $this->assertSame( [ 'page' ], array_column( $d['pages'], 'post_type' ) );
            $this->assertSame( 1, $d['count'] );
        }
    }

    public function testProjectsAreListedWhenAskedForAndOnlyThoseTheUserCanEdit(): void
    {
        [ $status, $d ] = $this->rest( [ 'post_type' => 'project' ] );
        $this->assertSame( 200, $status );
        $this->assertSame( [ 20 ], array_column( $d['pages'], 'id' ), 'project 21 is not editable by this user' );
        $this->assertSame( [ 'project' ], array_column( $d['pages'], 'post_type' ) );

        [ $code, $m ] = $this->mcp( [ 'post_type' => 'project' ] );
        $this->assertNull( $code );
        $this->assertSame( [ 20 ], array_column( $m['pages'], 'id' ) );
        $this->assertSame( [ 'project' ], array_column( $m['pages'], 'post_type' ) );
    }

    public function testAnExplicitPageAndAnEmptyValueMeanPages(): void
    {
        $this->assertSame( [ 11 ], array_column( $this->rest( [ 'post_type' => 'page' ] )[1]['pages'], 'id' ) );
        $this->assertSame( [ 11 ], array_column( $this->rest( [ 'post_type' => '' ] )[1]['pages'], 'id' ) );
        $this->assertSame( [ 11 ], array_column( $this->mcp( [ 'post_type' => 'page' ] )[1]['pages'], 'id' ) );
    }

    public function testAnythingElseIsRejectedNeverCast(): void
    {
        foreach ( [ 'post', 'PROJECT', 'attachment', 'et_header_layout', 7, true, [ 'project' ] ] as $bad ) {
            [ $status, $d ] = $this->rest( [ 'post_type' => $bad ] );
            $this->assertSame( 400, $status, json_encode( $bad ) );
            $this->assertSame( 'invalid_field', $d['error'] );
            [ $code ] = $this->mcp( [ 'post_type' => $bad ] );
            $this->assertSame( -32602, $code, json_encode( $bad ) );
        }
    }

    public function testTheCapabilityCheckStillComesFirst(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true ];
        [ $status ] = $this->rest( [ 'post_type' => 'project' ] );
        $this->assertSame( 403, $status );
        $this->assertSame( -32602, $this->mcp( [ 'post_type' => 'project' ] )[0] );
        [ $status ] = $this->rest( [ 'post_type' => 'nonsense' ] );
        $this->assertSame( 403, $status, 'a user without edit_pages learns nothing about the argument' );
    }

    public function testToolsListDeclaresAGeminiSafePostTypeEnum(): void
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();
        $tool = null;
        foreach ( $data['result']['tools'] as $t ) {
            if ( 'list_divi_pages' === $t['name'] ) {
                $tool = $t;
            }
        }
        $this->assertNotNull( $tool );
        $prop = $tool['inputSchema']['properties']['post_type'];
        $this->assertSame( 'string', $prop['type'] );
        $this->assertSame( [ 'page', 'project' ], $prop['enum'] );
        $this->assertSame( [], $tool['inputSchema']['required'] );
        $json = (string) json_encode( $tool['inputSchema'] );
        foreach ( [ 'additionalProperties', '$ref', 'oneOf' ] as $banned ) {
            $this->assertStringNotContainsString( $banned, $json );
        }
    }

    public function testTheOpenApiSpecDocumentsThePostTypeParameter(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $op   = $spec['paths']['/pages']['get'];
        $this->assertSame( 'listDiviPages', $op['operationId'] );
        $param = null;
        foreach ( $op['parameters'] ?? [] as $p ) {
            if ( 'post_type' === ( $p['name'] ?? '' ) ) {
                $param = $p;
            }
        }
        $this->assertNotNull( $param, 'listDiviPages documents post_type' );
        $this->assertSame( 'query', $param['in'] );
        $this->assertFalse( $param['required'] );
        $this->assertSame( [ 'page', 'project' ], $param['schema']['enum'] );
        $this->assertSame( 'page', $param['schema']['default'] );
        $this->assertArrayHasKey( 'post_type', $spec['components']['schemas']['PageSummary']['properties'] );
    }
}
```

Run: `vendor/bin/phpunit --filter "PageAccessTest|ListPostTypeTest"`. Expected: FAIL (`postTypeArg` undefined; list ignores `post_type`).

- [ ] **Step 2: Implement `PageAccess::postTypeArg`**

In `wp-plugin/src/PageAccess.php`, after `stringArg()` add:

```php
    /**
     * Optional post type argument: 'page' when absent, null or '', the string itself when it is exactly one of $allowed,
     * and null for anything else (a different string, wrong case, a number, a bool, an array) so the caller can answer
     * 400 / -32602 instead of guessing.
     *
     * @param array<string,mixed> $args
     * @param list<string>        $allowed
     */
    public static function postTypeArg(array $args, string $key, array $allowed): ?string
    {
        if (!array_key_exists($key, $args) || $args[$key] === null || $args[$key] === '') {
            return 'page';
        }
        if (!is_string($args[$key]) || !in_array($args[$key], $allowed, true)) {
            return null;
        }
        return $args[$key];
    }
```

- [ ] **Step 3: MCP**

In `wp-plugin/src/McpHandler.php`:

(a) The `list_divi_pages` tool entry (~line 100) becomes:

```php
            [
                'name'        => 'list_divi_pages',
                'description' => 'List the WordPress pages (or Divi Projects, with post_type "project") built with the Divi 5 editor that you can edit. Returns IDs, titles, status, post type and links. The IDs work with get_page_layout, update_page_layout, edit_page_content and the history tools.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['post_type' => ['type' => 'string', 'enum' => ['page', 'project'], 'description' => 'Optional. "page" (default) or "project" (Divi Projects).']],
                    'required'   => [],
                ],
            ],
```

(b) Dispatch: `'list_divi_pages'    => $this->toolListPages($id),` becomes `'list_divi_pages'    => $this->toolListPages($id, $arguments),`.

(c) `toolListPages`: change the signature to `private function toolListPages(mixed $id, array $args): WP_REST_Response`; keep the capability check first; directly after it (before `get_posts(`) add:

```php
        $postType = PageAccess::postTypeArg($args, 'post_type', EditTarget::ALLOWED_TYPES);
        if ($postType === null) {
            return $this->rpcError($id, -32602, 'post_type must be one of: ' . implode(', ', EditTarget::ALLOWED_TYPES) . '.');
        }
```
then use `'post_type' => $postType,` in the `get_posts` arguments and add `'post_type' => $p->post_type,` to each listed item (after `'status'`). Keep every other line (capability first, `filterEditable`, the `phpcs:ignore` comment) as it is.

(d) Wording: in the descriptions of `get_page_layout`, `update_page_layout`, `edit_page_content`, `list_page_history`, `get_page_history_entry` and `restore_page_version`, change "page" to "page or Divi Project" where it names the target (for example "a specific page by ID" -> "a specific page or Divi Project by ID"); keep each description otherwise intact. In the `page_id` parameter descriptions say "page or Divi Project ID". In the error messages replace `Page {$pageId} not found.` with `Page or Project {$pageId} not found.` and `permission to read|edit|access page {$pageId}` with `permission to read|edit|access page or Project {$pageId}` (and the matching "Version ... not found for page" with "for page or Project"). Do not touch the `create_page` text or its "permission to create pages" message.

- [ ] **Step 4: REST**

In `wp-plugin/src/RestController.php` `list_pages`: keep the capability check first; directly after it (before `get_posts(`) add:

```php
        $postType = PageAccess::postTypeArg(['post_type' => $request->get_param('post_type')], 'post_type', EditTarget::ALLOWED_TYPES);
        if ($postType === null) {
            return new WP_Error('invalid_field', '"post_type" must be one of: ' . implode(', ', EditTarget::ALLOWED_TYPES) . '.', ['status' => 400]);
        }
```
use `'post_type' => $postType,` in the query and add `'post_type' => $p->post_type,` to each listed item (after `'status'`). Apply the same message wording changes as in MCP to the REST messages for get/update/history/edit (`Page $id not found.` -> `Page or Project $id not found.`, `... read|edit|access page $id.` -> `... page or Project $id.`, `... for page {$post->ID}.` -> `... for page or Project {$post->ID}.`). Do not touch `create_page` messages.

- [ ] **Step 5: OpenAPI**

In `wp-plugin/src/OpenApiSpec.php`:
- `PageSummary` properties: add `'post_type' => ['type' => 'string', 'description' => 'page or project'],`.
- `listDiviPages`: summary `'List Divi 5 pages or Projects'`; description `'Returns the WordPress pages (or, with post_type=project, the Divi Projects) that use the Divi 5 builder and that the authenticated user can edit, including their IDs and edit links.'`; add before `'responses'`:

```php
                        'parameters'  => [
                            ['name' => 'post_type', 'in' => 'query', 'required' => false, 'description' => 'page (default) or project', 'schema' => ['type' => 'string', 'enum' => ['page', 'project'], 'default' => 'page']],
                        ],
```
and add `'400' => ['description' => 'post_type is not page or project'],` to its responses. Also change the `getPageLayout`/`updatePageLayout`/`editPageContent`/history operations' descriptions from "page" to "page or Divi Project" only where they name the target (small wording edits); do not change any operationId, path or schema.

- [ ] **Step 6: Run everything**

Run `vendor/bin/phpunit --filter "PageAccessTest|ListPostTypeTest|OpenApiSpec"` (PASS), then `vendor/bin/phpunit` (all pass). If an older test fails only because it asserts the old "Page N not found" / "permission to ... page" wording, update THAT expectation to the new wording and name the test in the commit message; if `tests/PageAccessTest.php`'s source-based list test fails, fix the CODE so its invariants still hold (capability check before `get_posts(`; `filterEditable` kept; `(string) get_permalink($p)` and `(string) get_edit_post_link($p->ID, 'raw')` kept) rather than loosening the test. Then `make test-pro` (1513 pass).

- [ ] **Step 7: Commit**

```bash
git add wp-plugin/src/PageAccess.php wp-plugin/src/McpHandler.php wp-plugin/src/RestController.php wp-plugin/src/OpenApiSpec.php tests/PageAccessTest.php tests/ListPostTypeTest.php
git commit -m "$(cat <<'EOF'
feat: list_divi_pages and GET /pages list Divi Projects with post_type; page-or-Project wording

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```
(Add every other test file you had to edit in Step 6 to `git add`.)

---

### Task 4: live gate `make verify-project-layouts`

**Files:**
- Create: `scripts/verify-project-layouts.php`
- Modify: `Makefile` (`.PHONY` line 1 and a new target after `verify-custom-fields`)

**Interfaces:** consumes everything above through the REAL handlers on the Docker dev site.

Prerequisite: the Docker dev site is up (`docker compose ps` shows divi5val_wp / divi5val_wpcli) and loads this checkout's `wp-plugin`. If not, STOP and report BLOCKED; do not fake results.

- [ ] **Step 1: Write the script**

Model it on `scripts/verify-custom-fields.php` (read it first and copy its helper style: `$check`, `$rest`, `$mcp`, `aied_require_dev_site()`, the `finally` cleanup, the safe deletion rules). Create `scripts/verify-project-layouts.php`:

```php
<?php
/**
 * Real-site check for editing Divi Project layouts: the REAL REST and MCP handlers on the Docker site. A throwaway draft Project is
 * listed (post_type=project), read, previewed (dry run), saved, snapshotted and restored; an invalid layout is refused; a
 * throwaway draft blog post is refused by every id-taking tool and route.
 *
 * Run: make verify-project-layouts   (needs `make up` and the plugin active on the dev site; the site needs the Divi "project" type)
 * Creates throwaway posts and always deletes them. Dev sites only (scripts/dev-guard.php).
 */

use AiEditorDivi5\WP\EditTarget;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

if ( ! class_exists( EditTarget::class ) || ! defined( 'AiEditorDivi5\WP\EditTarget::ALLOWED_TYPES' ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (with Project layout support) on this site first.' );
}
if ( ! post_type_exists( 'project' ) ) {
    WP_CLI::error( 'This site has no "project" post type (Divi Projects are disabled); enable them in Divi > Theme Options first.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$rest = static function ( string $method, string $route, array $body = [], array $params = [] ): array {
    $req = new WP_REST_Request( $method, '/ai-editor-divi5/v1' . $route );
    $req->set_header( 'content-type', 'application/json' );
    if ( [] !== $body ) {
        $req->set_body( wp_json_encode( $body ) );
    }
    foreach ( $params as $k => $v ) {
        $req->set_param( $k, $v );
    }
    $res = rest_do_request( $req );

    return [ 'status' => $res->get_status(), 'data' => (array) $res->get_data() ];
};
$mcp = static function ( string $tool, array $args ): array {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => (object) $args ] ] ) );
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    $res                           = rest_do_request( $req );
    unset( $_SERVER['HTTP_AUTHORIZATION'] );
    wp_set_current_user( 1 );
    $data = (array) $res->get_data();
    $text = $data['result']['content'][0]['text'] ?? '';

    return [ 'error' => $data['error'] ?? null, 'result' => (array) json_decode( (string) $text, true ) ];
};
$layout = static function ( string $title ): string {
    $attrs = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $title ] ] ], 'builderVersion' => '5.14.0' ];

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading ' . serialize_block_attributes( $attrs ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};

$ORIGINAL = $layout( 'Project original aied' );
$NEW      = $layout( 'Project changed aied' );
$BAD      = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/not-a-module {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
$posts    = [];
try {
    $meta = [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ];
    $prj  = wp_insert_post( [ 'post_type' => 'project', 'post_status' => 'draft', 'post_title' => 'aied project verify', 'post_content' => wp_slash( $ORIGINAL ), 'meta_input' => $meta ], true );
    $blog = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'aied blog verify', 'post_content' => wp_slash( $ORIGINAL ), 'meta_input' => $meta ], true );
    foreach ( [ $prj, $blog ] as $id ) {
        if ( is_wp_error( $id ) ) {
            throw new RuntimeException( $id->get_error_message() );
        }
        $posts[] = (int) $id;
    }
    $check( true, 'a throwaway draft Project and a throwaway draft blog post exist' );

    // 1. discovery
    $l = $rest( 'GET', '/pages', [], [ 'post_type' => 'project' ] );
    $check( 200 === $l['status'] && in_array( (int) $prj, array_map( 'intval', array_column( (array) $l['data']['pages'], 'id' ) ), true ), 'REST GET /pages?post_type=project lists the Project' );
    $check( 'project' === ( array_column( (array) $l['data']['pages'], 'post_type', 'id' )[ $prj ] ?? '' ), 'the listed item reports post_type project' );
    $lp = $rest( 'GET', '/pages' );
    $check( ! in_array( (int) $prj, array_map( 'intval', array_column( (array) $lp['data']['pages'], 'id' ) ), true ), 'the default listing (pages) does not include the Project' );
    $check( 400 === $rest( 'GET', '/pages', [], [ 'post_type' => 'post' ] )['status'], 'REST rejects post_type=post (400)' );
    $m = $mcp( 'list_divi_pages', [ 'post_type' => 'project' ] );
    $check( null === $m['error'] && in_array( (int) $prj, array_map( 'intval', array_column( (array) ( $m['result']['pages'] ?? [] ), 'id' ) ), true ), 'MCP list_divi_pages post_type=project lists the Project' );
    $check( -32602 === ( $mcp( 'list_divi_pages', [ 'post_type' => 'post' ] )['error']['code'] ?? null ), 'MCP rejects post_type=post (-32602)' );

    // 2. read
    $g = $rest( 'GET', "/pages/{$prj}" );
    $check( 200 === $g['status'] && 'project' === ( $g['data']['post_type'] ?? '' ), 'REST reads the Project (post_type project)' );
    $gm = $mcp( 'get_page_layout', [ 'page_id' => (int) $prj ] );
    $check( null === $gm['error'] && 'project' === ( $gm['result']['post_type'] ?? '' ), 'MCP get_page_layout reads the Project' );

    // 3. dry run, invalid, save
    $d = $rest( 'PUT', "/pages/{$prj}", [ 'post_content' => $NEW, 'dry_run' => true ] );
    $check( 200 === $d['status'] && true === ( $d['data']['valid'] ?? null ), 'dry run on the Project returns a valid preview' );
    $check( get_post_field( 'post_content', $prj ) === $ORIGINAL, 'dry run left the Project untouched' );
    $bad = $rest( 'PUT', "/pages/{$prj}", [ 'post_content' => $BAD ] );
    $check( 422 === $bad['status'] && get_post_field( 'post_content', $prj ) === $ORIGINAL, 'an invalid layout is refused (422) and nothing is saved' );
    $s = $rest( 'PUT', "/pages/{$prj}", [ 'post_content' => $NEW ] );
    $check( 200 === $s['status'] && true === ( $s['data']['saved'] ?? null ), 'a valid layout is saved to the Project' );
    $check( true === ( $s['data']['history']['stored'] ?? null ), 'the previous content was snapshotted first' );
    $check( get_post_field( 'post_content', $prj ) === $NEW, 'the Project now holds the new layout' );

    // 4. undo
    $versionId = (int) ( $s['data']['history']['version_id'] ?? 0 );
    $hl        = $rest( 'GET', "/pages/{$prj}/history" );
    $check( 200 === $hl['status'] && (int) ( $hl['data']['count'] ?? 0 ) >= 1, 'the history lists the snapshot' );
    $r = $rest( 'POST', "/pages/{$prj}/restore", [ 'version_id' => $versionId ] );
    $check( 200 === $r['status'] && true === ( $r['data']['restored'] ?? null ) && get_post_field( 'post_content', $prj ) === $ORIGINAL, 'restore brings the original layout back' );

    // 5. a blog post is refused by every id-taking tool and route
    $before = get_post_field( 'post_content', $blog );
    $refused = [
        $rest( 'GET', "/pages/{$blog}" )['status'],
        $rest( 'PUT', "/pages/{$blog}", [ 'post_content' => $NEW ] )['status'],
        $rest( 'POST', "/pages/{$blog}/edit", [ 'find' => 'aied', 'replace' => 'x' ] )['status'],
        $rest( 'GET', "/pages/{$blog}/history" )['status'],
    ];
    $check( [ 404, 404, 404, 404 ] === $refused, 'REST: a blog post is 404 on get, update, edit and history' );
    $codes = [
        $mcp( 'get_page_layout', [ 'page_id' => (int) $blog ] )['error']['code'] ?? null,
        $mcp( 'update_page_layout', [ 'page_id' => (int) $blog, 'post_content' => $NEW ] )['error']['code'] ?? null,
        $mcp( 'edit_page_content', [ 'page_id' => (int) $blog, 'find' => 'aied', 'replace' => 'x' ] )['error']['code'] ?? null,
        $mcp( 'list_page_history', [ 'page_id' => (int) $blog ] )['error']['code'] ?? null,
    ];
    $check( [ -32602, -32602, -32602, -32602 ] === $codes, 'MCP: a blog post is refused on get, update, edit and history' );
    $check( get_post_field( 'post_content', $blog ) === $before, 'the blog post was never touched' );
} catch ( Throwable $e ) {
    $check( false, 'unexpected error: ' . $e->getMessage() );
} finally {
    foreach ( $posts as $id ) {
        wp_delete_post( $id, true );
    }
    wp_set_current_user( 1 );
}

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed.' );
}
WP_CLI::success( 'Project layouts verified on the real site.' );
```
If a REST route used above does not exist under that exact path (`/pages/{id}/history`, `/pages/{id}/restore`, `/pages/{id}/edit`), read `register_routes()` in `RestController.php` and use the real paths; `defined( 'AiEditorDivi5\WP\EditTarget::ALLOWED_TYPES' )` must be replaced by `defined( EditTarget::class . '::ALLOWED_TYPES' )` if the former does not work (a class constant check).

- [ ] **Step 2: Add the Makefile target**

Append `verify-project-layouts` to the `.PHONY` line and, after the `verify-custom-fields` target, add:

```make
# ---------------------------------------------------------------
# verify-project-layouts — real-site check of editing Divi Project layouts (Docker site; a throwaway draft Project and blog post, always deleted)
# ---------------------------------------------------------------
verify-project-layouts:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-project-layouts.php --user=1
```

- [ ] **Step 3: Run the gate**

Run: `make verify-project-layouts`
Expected: every line `PASS`, final `Success: Project layouts verified on the real site.` If a check FAILs, diagnose (superpowers:systematic-debugging): never weaken a check; a plugin defect is reported as a finding with the failing output. Afterwards confirm no throwaway content remains: `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp post list --post_type=any --post_status=any --s="aied" --fields=ID,post_title,post_type --user=1` lists nothing.

- [ ] **Step 4: Commit**

```bash
git add scripts/verify-project-layouts.php Makefile
git commit -m "$(cat <<'EOF'
test: make verify-project-layouts, a real-site gate for Divi Project layouts

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: docs and final verification

**Files:**
- Modify: `wp-plugin/readme.txt`, `CLAUDE.md`

- [ ] **Step 1: Readme**

In `wp-plugin/readme.txt` change the places that describe reading and editing a "page" so they also say Divi Projects, keeping every compliance-sensitive phrase intact (do not add the phrase "licence key", "upgrade", "premium", "site tools"; do not touch the changelog; keep the "(18 tools)" heading): the intro/feature lines about reading and editing pages, the bullets for `list_divi_pages`, `get_page_layout`, `edit_page_content`, `update_page_layout` and the history/undo bullets (add "or Divi Project" where they name the target, and one clause on `list_divi_pages`: "pass post_type project to list Divi Projects"), and the FAQ sentence that says what the tools can do ("...the content of pages..." becomes "...the content of pages and Divi Projects..."; `create_page` still creates pages only). Run `vendor/bin/phpunit --filter WpOrg` after editing; fix wording (not the tests) if a compliance test objects.

- [ ] **Step 2: CLAUDE.md (owner has uncommitted edits: use this recipe)**

`CLAUDE.md` holds the OWNER's uncommitted edits; commit ONLY your hunks, with no interactive tools:
a. `git show HEAD:CLAUDE.md > <scratchpad>/CLAUDE.head.md` (scratchpad: `/private/tmp/claude-501/-Users-Lucas-Documents-JHMG-Local-Divi-5-Deterministic-Validator/35406e12-9e03-4a9e-b84b-94900e6e1ef2/scratchpad`).
b. Make these edits to that scratch copy AND the same edits to the working `CLAUDE.md` with the Edit tool (adapt an anchor if the owner's edits changed it; keep the content identical): the sentence that says writes "(`update_page_layout`, `edit_page_content`, REST PUT/edit)" and the `Theme layouts` paragraph's "`EditTarget::resolve()` is page-only" become "`EditTarget::resolve()` accepts pages and Divi Projects only (`EditTarget::ALLOWED_TYPES`)"; add to the tool-description area one sentence: "Divi Projects (v4.8.0, unreleased): `EditTarget::ALLOWED_TYPES = ['page','project']`, so every page tool and route also works on a Project's ID (`page_id` keeps its name); `list_divi_pages` / `GET /pages` take an optional `post_type` (page or project); `create_page` stays page-only; blog posts and every other type stay refused"; add a row to the entry-points table: `` | `make verify-project-layouts` | Real-site check of editing Divi Project layouts (self-cleaning; dev site only) | ``; add a "Current state" bullet: "**Unreleased (branch feat/project-layouts): Divi Project layouts** (sub-project B of the Projects/custom-fields request; C = write field values is later). Spec/plan `docs/superpowers/specs|plans/2026-10-08-project-layouts*`. Version, changelog and zip are the owner's release step."
c. `blob=$(git hash-object -w <scratch copy>)`, `git update-index --cacheinfo 100644,$blob,CLAUDE.md`, confirm `git diff --cached --stat` shows ONLY `CLAUDE.md` with your hunks, commit it (`docs: CLAUDE.md for Divi Project layouts`, with the trailer).
d. Afterwards `git diff CLAUDE.md` must show only the owner's original uncommitted edits. If the recipe fails, edit the working file only, leave it uncommitted and say so in the report.

- [ ] **Step 3: Final verification**

Run in order and read the real output: `vendor/bin/phpunit` (exit 0), `make test-pro` (exit 0), `make verify-project-layouts` (all PASS), `make verify-custom-fields` (still 19 PASS: EditTarget's widening must not have changed `get_custom_fields`), `git status --short` (only the owner's `CLAUDE.md`). Do NOT run Plugin Check, bump versions, edit the changelog or rebuild the zip.

- [ ] **Step 4: Commit the readme**

```bash
git add wp-plugin/readme.txt
git commit -m "$(cat <<'EOF'
docs: readme says pages and Divi Projects where tools read, edit and undo layouts

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

## Self-review (done against the spec)

- **Spec coverage:** §1 allowlist -> Task 1; §2 handlers (no logic change, wording, history/recent) -> Tasks 2 and 3 (wording); §3 discovery -> Task 3 (MCP, REST, OpenAPI, validation); §4 unchanged -> Global Constraints and Task 2's page-still-works test; §5 safety -> Task 2 (invalid, dry run, approval mode, forbidden) and constraints; §6 docs -> Task 5; Testing section -> Tasks 1-4 and the final verification.
- **Placeholders:** none; the two spots that depend on real response shapes (Task 2 Step 2, Task 4 Step 1 route paths) tell the implementer exactly where to read the truth and what to do if a handler, not the test, is wrong.
- **Type consistency:** `EditTarget::ALLOWED_TYPES`, `PageAccess::postTypeArg( array $args, string $key, array $allowed ): ?string`, the REST `list_pages` / MCP `toolListPages( mixed $id, array $args )` signatures and the `post_type` item key are used identically in every task.
