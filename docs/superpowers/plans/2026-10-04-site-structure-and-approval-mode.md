# Site Structure Tool, Approval Mode and Protocol Fixes (free 4.6.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship free plugin 4.6.0: a read-only `get_site_structure` tool (post types, taxonomies, image sizes, ACF field groups), an opt-in "Require a preview before every save" approval mode, measured token numbers, and two MCP protocol fixes, together with the already-built (uncommitted) 4.5.1 setup-docs work.

**Architecture:** `SiteStructure` is a pure class with injected readers (the `GlobalStyles` pattern) wired into MCP and REST only (not OpenAPI: the ChatGPT importer's 30-operation budget is used up). `ApprovalMode` is a small class (option + single-use transient keyed by user, page and content hash) called from the four free write entry points just before `HistoryService::write()`; it is NOT placed inside `HistoryService` because the Pro add-on writes through that. Protocol fixes are two edits in `McpHandler`.

**Tech Stack:** PHP 8.1+, PHPUnit 11 (`vendor/bin/phpunit`, `make test`), WordPress shims in `tests/bootstrap.php` and `tests/WpRestShims.php`, WP-CLI in Docker for the live check.

**Spec:** `docs/superpowers/specs/2026-10-04-site-structure-and-approval-mode-design.md`

## Global Constraints

- The validator (`src/`, `wp-plugin/validator/`) is NOT touched. No AI calls anywhere.
- WordPress.org compliance (`tests/WpOrgComplianceTest.php`): no remote calls, no licence gating, no tool that saves CSS/PHP or changes site settings (menus, front page, users, plugins). The word "menu" and "front page" stay out of `readme.txt` except in the one FAQ sentence the test pins. The readme mentions "Pro add-on" in exactly one sentence (do not add another). Never weaken that test: only update its pinned tool list, tool count and version strings.
- The tool is read-only: it never reads field VALUES, `functions.php` or any file, and never lists menus.
- `get_site_structure` is in MCP and REST only. `OpenApiSpec` stays at 16 operations (`DryRunLockstepTest::testTheOperationCountIsUnchanged` keeps asserting 16, on purpose).
- Strict argument types: a wrong-typed argument is rejected (400 / -32602), never cast.
- Approval mode: option `ai_editor_divi5_require_preview` (`'1'` = on, default off, autoload false); preview lifetime 30 minutes (`1800` seconds), single use; refusal code `PREVIEW_REQUIRED`; REST status 428; exempt: `create_page`, `restore_page_version`, every Pro tool.
- Caps: 100 items per section, 100 ACF fields per group, 50 choices per field, 200 characters per text value.
- Version: 4.6.0 (header, `AI_EDITOR_DIVI5_VERSION`, readme Stable tag). The unreleased 4.5.1 changelog entry is merged into 4.6.0.
- Commit trailer: end every commit message with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. **Commit steps run only after the owner confirms commits are wanted** (the branch holds uncommitted 4.5.1 work). If not confirmed, skip the commit steps and leave the work staged.
- Run all commands from the repo root: `/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator`. Branch: `feat/setup-docs-4-5-1`.

## Review Focus

Inputs and conditions the spec implies that no happy-path test would hit. Each has a test in the owning task.

1. `include` given as a comma string with spaces (`"post_types, acf"`), an empty string, `[]`, an unknown name, a number or a boolean: spaces trimmed; empty means everything; everything else is a clear 400 / -32602 (Task 2, Task 3).
2. ACF returning `false` (no fields) for a group, a group with no usable key, an inactive group, or a reader that throws: an empty list or section plus a note, never a crash (Task 2).
3. Approval mode: a preview of content A must not unlock content B (one character different), another page, or another user (Task 4, Task 5).
4. Approval mode: an INVALID preview must not unlock a save, and an invalid save without a preview must come back as violations (so the AI can fix it), not as `PREVIEW_REQUIRED` (Task 5).
5. The option holding something other than `'1'` (`'yes'`, `'true'`, `1`, empty) must mean OFF, and with the mode off no transient is written and nothing is refused (Task 4).
6. A very large ACF install (101 groups, 101 choices): capped and flagged `truncated: true`, response stays bounded (Task 2).
7. Object-cache eviction of the preview: the save is refused and the AI previews again; it can never let an unpreviewed save through (Task 4: a missing transient means refused).

## File Structure

| File | Responsibility |
|---|---|
| `wp-plugin/src/SiteStructure.php` (new) | Pure: parse `include`, read WordPress/ACF structure via injected readers, cap and normalise. |
| `wp-plugin/src/ApprovalMode.php` (new) | The setting, the preview transient, the refusal body. |
| `wp-plugin/src/McpHandler.php` | `ping` and 202 fixes; `get_site_structure` tool; approval calls in `toolUpdate`/`toolEditContent`. |
| `wp-plugin/src/RestController.php` | `GET /site-structure`; approval calls in `update_page`/`edit_page`. |
| `wp-plugin/src/OpenApiSpec.php` | Document the 428 response on the two write operations only. |
| `wp-plugin/src/AdminPage.php` | Approval-mode card, handler, notices, Features cards. |
| `wp-plugin/src/autoload.php`, `wp-plugin/uninstall.php` | Load the two classes; delete the option on uninstall. |
| `wp-plugin/src/StyleGuide.php`, `wp-plugin/readme.txt`, `wp-plugin/jhmg-ai-editor-for-divi-5.php` | Guide sentence, docs, version. |
| `tests/SiteStructureShims.php` (new) | WordPress/ACF function shims driven by `$GLOBALS['__ss_*']`. |
| `tests/McpProtocolTest.php`, `SiteStructureTest.php`, `SiteStructureLockstepTest.php`, `ApprovalModeTest.php`, `ApprovalModeHandlersTest.php`, `ApprovalCardTest.php` (new) | The new proof. |
| `scripts/measure-tokens.php` (new), `docs/token-usage.md` (generated) | Token numbers. |
| `scripts/verify-site-structure.php` (new), `Makefile` | Live check on the Docker site. |

---

### Task 0: Checkpoint the work already on the branch (only if the owner approved commits)

**Files:** none changed.

- [ ] **Step 1: Run the suite on the current branch**

Run: `make test 2>&1 | tail -4`
Expected: `OK (734 tests, ...)`.

- [ ] **Step 2: Commit the 4.5.1 setup-docs work, the backlog doc, the spec and this plan**

```bash
git add -A wp-plugin tests jhmg-ai-editor-for-divi-5.zip docs/feature-requests-2026-10-04.md docs/superpowers/specs/2026-10-04-site-structure-and-approval-mode-design.md docs/superpowers/plans/2026-10-04-site-structure-and-approval-mode.md
git commit -m "feat: setup docs, Gemini tab, local-site notes and FAQ (4.5.1 work); spec and plan for 4.6.0

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

Leave `CLAUDE.md` out of this commit: it already carried the owner's own uncommitted edits before this work began. It is updated in Task 8 and the owner decides whether to commit it.

---

### Task 1: MCP protocol fixes (`ping` returns `{}`, notifications return 202)

**Files:**
- Modify: `wp-plugin/src/McpHandler.php` (the `match` in `handle()`, `rpcResult()`, `noContent()`)
- Test: `tests/McpProtocolTest.php` (new)

**Interfaces:**
- Produces: `McpHandler::handle()` answers `ping` with `result: {}` (a `\stdClass`) and `notifications/initialized` with a `WP_REST_Response(null, 202)`.

- [ ] **Step 1: Write the failing test**

Create `tests/McpProtocolTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\McpHandler;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** Two spec points a strict MCP client checks: ping answers {} and a notification is accepted with 202. */
class McpProtocolTest extends TestCase
{
    private function call( array $body ): \WP_REST_Response
    {
        return ( new McpHandler() )->handle( new \WP_REST_Request( $body ) );
    }

    public function testPingAnswersWithAnEmptyJsonObject(): void
    {
        $r = $this->call( [ 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping' ] );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( '{"jsonrpc":"2.0","id":5,"result":{}}', json_encode( $r->get_data() ) );
    }

    public function testANotificationIsAcceptedWith202AndNoBody(): void
    {
        $r = $this->call( [ 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ] );
        $this->assertSame( 202, $r->get_status() );
        $this->assertNull( $r->get_data() );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `vendor/bin/phpunit tests/McpProtocolTest.php`
Expected: 2 failures (`"result":[]`, and status 204).

- [ ] **Step 3: Implement**

In `wp-plugin/src/McpHandler.php`:

Replace `'notifications/initialized' => $this->noContent(),` with `'notifications/initialized' => $this->accepted(),` and `'ping'                     => $this->rpcResult($id, []),` with `'ping'                     => $this->rpcResult($id, new \stdClass()),`.

Replace
```php
    private function rpcResult(mixed $id, array $result): WP_REST_Response
```
with
```php
    private function rpcResult(mixed $id, array|\stdClass $result): WP_REST_Response
```

Replace
```php
    private function noContent(): WP_REST_Response
    {
        return new WP_REST_Response(null, 204);
    }
```
with
```php
    /** The MCP HTTP transport answers a notification with 202 Accepted and no body. */
    private function accepted(): WP_REST_Response
    {
        return new WP_REST_Response(null, 202);
    }
```

- [ ] **Step 4: Run it and see it pass, then the whole suite**

Run: `vendor/bin/phpunit tests/McpProtocolTest.php && make test 2>&1 | tail -3`
Expected: `OK (2 tests` then the full suite `OK`.

- [ ] **Step 5: Commit**

```bash
git add wp-plugin/src/McpHandler.php tests/McpProtocolTest.php
git commit -m "fix: MCP ping answers {} and notifications are accepted with 202

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `SiteStructure` (pure class + shims + tests)

**Files:**
- Create: `wp-plugin/src/SiteStructure.php`
- Create: `tests/SiteStructureShims.php`
- Test: `tests/SiteStructureTest.php` (new)

**Interfaces:**
- Produces:
  - `SiteStructure::SECTIONS` = `['post_types','taxonomies','image_sizes','acf']`; constants `MAX_ITEMS=100`, `MAX_FIELDS=100`, `MAX_CHOICES=50`, `MAX_TEXT=200`.
  - `SiteStructure::parseInclude(mixed $value): ?array` returns `list<string>` in `SECTIONS` order (all sections for `null`, `''`, `[]`), or `null` when invalid. Accepts a comma-separated string or a list of strings.
  - `SiteStructure::readers(): array` with keys `post_types`, `taxonomies`, `image_sizes`, `acf_groups`, `acf_fields` (each a callable or `null`).
  - `SiteStructure::catalog(?array $include = null): array` and `SiteStructure::catalogWith(array $readers, ?array $include = null): array`.
  - Result shape: only the requested sections, plus `truncated` (bool) and `notes` (list<string>). `post_types[]` = `{name,label,supports[],taxonomies[],has_archive,hierarchical}`; `taxonomies[]` = `{name,label,object_types[],hierarchical}`; `image_sizes[]` = `{name,width,height,crop}`; `acf` = `{active, groups:[{key,title,fields:[{name,label,type,choices?:[{value,label}]}]}]}`.

- [ ] **Step 1: Write the shims**

Create `tests/SiteStructureShims.php`:

```php
<?php

declare(strict_types=1);

/**
 * WordPress and ACF read functions driven by $GLOBALS['__ss_*'], so the REAL readers in SiteStructure
 * can be exercised without WordPress. Required only by the tests that need them. Defining the acf_*
 * functions makes ACF look "active" for the rest of the process; tests that need "ACF absent" pass
 * null readers to SiteStructure::catalogWith() instead.
 */

if ( ! function_exists( 'get_post_types' ) ) {
    function get_post_types( $args = [], $output = 'names' ) { return $GLOBALS['__ss_post_types'] ?? []; }
}
if ( ! function_exists( 'get_taxonomies' ) ) {
    function get_taxonomies( $args = [], $output = 'names' ) { return $GLOBALS['__ss_taxonomies'] ?? []; }
}
if ( ! function_exists( 'get_object_taxonomies' ) ) {
    function get_object_taxonomies( $type ) { return $GLOBALS['__ss_object_taxonomies'][ $type ] ?? []; }
}
if ( ! function_exists( 'get_all_post_type_supports' ) ) {
    function get_all_post_type_supports( $type ) { return $GLOBALS['__ss_supports'][ $type ] ?? []; }
}
if ( ! function_exists( 'wp_get_registered_image_subsizes' ) ) {
    function wp_get_registered_image_subsizes() { return $GLOBALS['__ss_image_sizes'] ?? []; }
}
if ( ! function_exists( 'acf_get_field_groups' ) ) {
    function acf_get_field_groups() { return $GLOBALS['__ss_acf_groups'] ?? []; }
}
if ( ! function_exists( 'acf_get_fields' ) ) {
    function acf_get_fields( $key ) { return $GLOBALS['__ss_acf_fields'][ $key ] ?? false; } // ACF returns false when a group has no fields
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/SiteStructureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\SiteStructure;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SiteStructureShims.php';
require_once __DIR__ . '/../wp-plugin/src/SiteStructure.php';

class SiteStructureTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ( [ 'post_types', 'taxonomies', 'object_taxonomies', 'supports', 'image_sizes', 'acf_groups', 'acf_fields' ] as $k ) {
            unset( $GLOBALS[ '__ss_' . $k ] );
        }
    }

    /** @return array<string,mixed> */
    private function readers( array $override = [] ): array
    {
        return $override + [
            'post_types'  => static fn () => [
                'page' => [ 'label' => 'Pages', 'supports' => [ 'title', 'editor' ], 'taxonomies' => [], 'has_archive' => false, 'hierarchical' => true ],
                'book' => [ 'label' => 'Books', 'supports' => [ 'title' ], 'taxonomies' => [ 'genre' ], 'has_archive' => true, 'hierarchical' => false ],
            ],
            'taxonomies'  => static fn () => [ 'genre' => [ 'label' => 'Genres', 'object_types' => [ 'book' ], 'hierarchical' => true ] ],
            'image_sizes' => static fn () => [ 'thumbnail' => [ 'width' => 150, 'height' => 150, 'crop' => true ], 'large' => [ 'width' => 1024, 'height' => 1024, 'crop' => false ] ],
            'acf_groups'  => static fn () => [
                [ 'key' => 'group_a', 'title' => 'Book details', 'active' => true ],
                [ 'key' => 'group_off', 'title' => 'Switched off', 'active' => false ],
                [ 'title' => 'No key here' ],
            ],
            'acf_fields'  => static fn ( string $key ) => 'group_a' === $key ? [
                [ 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text' ],
                [ 'name' => 'format', 'label' => 'Format', 'type' => 'select', 'choices' => [ 'hc' => 'Hardcover', 'pb' => 'Paperback' ] ],
            ] : false,
        ];
    }

    // ---- parseInclude -------------------------------------------------------------------------

    public function testParseIncludeDefaultsToEverySection(): void
    {
        foreach ( [ null, '', [] ] as $empty ) {
            $this->assertSame( SiteStructure::SECTIONS, SiteStructure::parseInclude( $empty ) );
        }
    }

    public function testParseIncludeAcceptsAListOrACommaStringAndKeepsSectionOrder(): void
    {
        $this->assertSame( [ 'post_types', 'acf' ], SiteStructure::parseInclude( [ 'acf', 'post_types', 'acf' ] ) );
        $this->assertSame( [ 'post_types', 'acf' ], SiteStructure::parseInclude( 'post_types, acf' ), 'spaces are trimmed' );
        $this->assertSame( [ 'image_sizes' ], SiteStructure::parseInclude( 'image_sizes' ) );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'badIncludes' )]
    public function testParseIncludeRejectsAnythingElse( mixed $bad ): void
    {
        $this->assertNull( SiteStructure::parseInclude( $bad ) );
    }

    /** @return list<array{mixed}> */
    public static function badIncludes(): array
    {
        return [ [ [ 'nope' ] ], [ [ 1 ] ], [ 'post_types,nope' ], [ 'post_types,,acf' ], [ [ 'a' => 'acf' ] ], [ 7 ], [ true ], [ false ], [ [ [ 'acf' ] ] ] ];
    }

    // ---- catalogWith --------------------------------------------------------------------------

    public function testReturnsEverySectionByDefaultWithTheDocumentedShape(): void
    {
        $d = SiteStructure::catalogWith( $this->readers() );

        $this->assertSame( [ 'post_types', 'taxonomies', 'image_sizes', 'acf', 'truncated', 'notes' ], array_keys( $d ) );
        $this->assertSame( [ 'page', 'book' ], array_column( $d['post_types'], 'name' ) );
        $this->assertSame( [ 'name' => 'book', 'label' => 'Books', 'supports' => [ 'title' ], 'taxonomies' => [ 'genre' ], 'has_archive' => true, 'hierarchical' => false ], $d['post_types'][1] );
        $this->assertSame( [ [ 'name' => 'genre', 'label' => 'Genres', 'object_types' => [ 'book' ], 'hierarchical' => true ] ], $d['taxonomies'] );
        $this->assertSame( [ 'name' => 'thumbnail', 'width' => 150, 'height' => 150, 'crop' => true ], $d['image_sizes'][0] );
        $this->assertFalse( $d['truncated'] );
        $this->assertSame( [], $d['notes'] );
    }

    public function testIncludeLimitsTheSectionsReturned(): void
    {
        $d = SiteStructure::catalogWith( $this->readers(), [ 'image_sizes' ] );
        $this->assertSame( [ 'image_sizes', 'truncated', 'notes' ], array_keys( $d ) );
    }

    public function testAcfGroupsListFieldsAndChoicesButSkipInactiveAndKeylessGroups(): void
    {
        $acf = SiteStructure::catalogWith( $this->readers(), [ 'acf' ] )['acf'];

        $this->assertTrue( $acf['active'] );
        $this->assertSame( [ 'group_a', '' ], array_column( $acf['groups'], 'key' ), 'the inactive group is skipped, the keyless one has no fields' );
        $this->assertSame( [], $acf['groups'][1]['fields'] );
        $this->assertSame( [ 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text' ], $acf['groups'][0]['fields'][0] );
        $this->assertSame(
            [ [ 'value' => 'hc', 'label' => 'Hardcover' ], [ 'value' => 'pb', 'label' => 'Paperback' ] ],
            $acf['groups'][0]['fields'][1]['choices']
        );
    }

    public function testAGroupWhoseFieldsComeBackAsFalseHasAnEmptyFieldList(): void
    {
        $r   = $this->readers( [ 'acf_groups' => static fn () => [ [ 'key' => 'group_empty', 'title' => 'Empty' ] ] ] );
        $acf = SiteStructure::catalogWith( $r, [ 'acf' ] )['acf'];
        $this->assertSame( [], $acf['groups'][0]['fields'] );
    }

    public function testWithoutAcfTheSectionIsInactiveWithANote(): void
    {
        $d = SiteStructure::catalogWith( $this->readers( [ 'acf_groups' => null, 'acf_fields' => null ] ), [ 'acf' ] );
        $this->assertSame( [ 'active' => false, 'groups' => [] ], $d['acf'] );
        $this->assertNotEmpty( $d['notes'] );
    }

    public function testAMissingOrThrowingReaderGivesAnEmptySectionAndANoteNeverAnError(): void
    {
        $r = $this->readers( [
            'post_types' => null,
            'taxonomies' => static function () { throw new \RuntimeException( 'boom' ); },
        ] );
        $d = SiteStructure::catalogWith( $r );
        $this->assertSame( [], $d['post_types'] );
        $this->assertSame( [], $d['taxonomies'] );
        $this->assertCount( 2, $d['notes'] );
        $this->assertNotEmpty( $d['image_sizes'], 'the other sections are unaffected' );
    }

    public function testLongListsAreCappedAndFlaggedTruncated(): void
    {
        $many  = [];
        for ( $i = 1; $i <= 101; $i++ ) {
            $many[ 'type' . $i ] = [ 'label' => 'T' . $i ];
        }
        $d = SiteStructure::catalogWith( $this->readers( [ 'post_types' => static fn () => $many ] ), [ 'post_types' ] );
        $this->assertCount( SiteStructure::MAX_ITEMS, $d['post_types'] );
        $this->assertTrue( $d['truncated'] );

        $choices = [];
        for ( $i = 1; $i <= 51; $i++ ) {
            $choices[ 'v' . $i ] = 'L' . $i;
        }
        $r = $this->readers( [ 'acf_fields' => static fn () => [ [ 'name' => 'c', 'label' => 'C', 'type' => 'select', 'choices' => $choices ] ] ] );
        $d = SiteStructure::catalogWith( $r, [ 'acf' ] );
        $this->assertCount( SiteStructure::MAX_CHOICES, $d['acf']['groups'][0]['fields'][0]['choices'] );
        $this->assertTrue( $d['truncated'] );
    }

    public function testTextValuesAreCutAndNonScalarsBecomeEmpty(): void
    {
        $r = $this->readers( [ 'post_types' => static fn () => [ 'x' => [ 'label' => str_repeat( 'é', 300 ), 'supports' => [ [ 'nested' ], 'title' ] ] ] ] );
        $d = SiteStructure::catalogWith( $r, [ 'post_types' ] );
        $this->assertSame( SiteStructure::MAX_TEXT, mb_strlen( $d['post_types'][0]['label'] ) );
        $this->assertSame( [ 'title' ], $d['post_types'][0]['supports'], 'only strings are kept' );
    }

    // ---- the real readers over the WordPress / ACF shims ---------------------------------------

    public function testTheRealReadersMapWordPressObjectsAndAcfArrays(): void
    {
        $GLOBALS['__ss_post_types']        = [ 'book' => (object) [ 'label' => 'Books', 'has_archive' => true, 'hierarchical' => false ] ];
        $GLOBALS['__ss_supports']          = [ 'book' => [ 'title' => true, 'editor' => true, 'comments' => false ] ];
        $GLOBALS['__ss_object_taxonomies'] = [ 'book' => [ 'genre' ] ];
        $GLOBALS['__ss_taxonomies']        = [ 'genre' => (object) [ 'label' => 'Genres', 'object_type' => [ 'book' ], 'hierarchical' => true ] ];
        $GLOBALS['__ss_image_sizes']       = [ 'card' => [ 'width' => 400, 'height' => 300, 'crop' => [ 'center', 'top' ] ] ];
        $GLOBALS['__ss_acf_groups']        = [ [ 'key' => 'group_1', 'title' => 'Book' ] ];
        $GLOBALS['__ss_acf_fields']        = [ 'group_1' => [ [ 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text', 'value' => 'SECRET-VALUE' ] ] ];

        $d = SiteStructure::catalog();

        $this->assertSame( [ 'name' => 'book', 'label' => 'Books', 'supports' => [ 'title', 'editor' ], 'taxonomies' => [ 'genre' ], 'has_archive' => true, 'hierarchical' => false ], $d['post_types'][0] );
        $this->assertSame( [ 'name' => 'genre', 'label' => 'Genres', 'object_types' => [ 'book' ], 'hierarchical' => true ], $d['taxonomies'][0] );
        $this->assertTrue( $d['image_sizes'][0]['crop'], 'an array crop position counts as cropped' );
        $this->assertSame( [ 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text' ], $d['acf']['groups'][0]['fields'][0] );
        $this->assertStringNotContainsString( 'SECRET-VALUE', json_encode( $d ), 'field values are never returned' );
    }
}
```

- [ ] **Step 3: Run and watch it fail**

Run: `vendor/bin/phpunit tests/SiteStructureTest.php`
Expected: error `Class "AiEditorDivi5\WP\SiteStructure" not found` (file missing).

- [ ] **Step 4: Implement**

Create `wp-plugin/src/SiteStructure.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A READ-ONLY view of how this site is set up: public post types, taxonomies, image sizes and, when
 * Advanced Custom Fields is active, its field groups and fields (names, labels, types, choices; never
 * field values). Everything comes from WordPress's and ACF's own public read functions, each behind a
 * guard: a missing or throwing reader means an empty section plus a note. Nothing here writes anything,
 * and nothing reads theme files or menus.
 */
final class SiteStructure
{
    public const SECTIONS    = [ 'post_types', 'taxonomies', 'image_sizes', 'acf' ];
    public const MAX_ITEMS   = 100;
    public const MAX_FIELDS  = 100;
    public const MAX_CHOICES = 50;
    public const MAX_TEXT    = 200;

    /**
     * The sections a caller asked for: every section for null, '' or [], a comma-separated string or a
     * list of section names otherwise. Anything else (an unknown name, a non-string entry, a number, a
     * boolean, an associative array) is invalid: null, so the caller answers 400 / -32602.
     *
     * @return ?list<string>
     */
    public static function parseInclude( mixed $value ): ?array
    {
        if ( null === $value || '' === $value || [] === $value ) {
            return self::SECTIONS;
        }
        if ( is_string( $value ) ) {
            $value = array_map( 'trim', explode( ',', $value ) );
        }
        if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
            return null;
        }
        $wanted = [];
        foreach ( $value as $section ) {
            if ( ! is_string( $section ) || ! in_array( $section, self::SECTIONS, true ) ) {
                return null;
            }
            $wanted[ $section ] = true;
        }

        return array_values( array_filter( self::SECTIONS, static fn ( string $s ): bool => isset( $wanted[ $s ] ) ) );
    }

    /** @return array<string, ?callable> */
    public static function readers(): array
    {
        return [
            'post_types'  => function_exists( 'get_post_types' ) ? [ self::class, 'readPostTypes' ] : null,
            'taxonomies'  => function_exists( 'get_taxonomies' ) ? [ self::class, 'readTaxonomies' ] : null,
            'image_sizes' => function_exists( 'wp_get_registered_image_subsizes' ) ? 'wp_get_registered_image_subsizes' : null,
            'acf_groups'  => function_exists( 'acf_get_field_groups' ) ? 'acf_get_field_groups' : null,
            'acf_fields'  => function_exists( 'acf_get_fields' ) ? 'acf_get_fields' : null,
        ];
    }

    /** @return array<string,array<string,mixed>> name => raw post type data */
    public static function readPostTypes(): array
    {
        $out = [];
        foreach ( (array) get_post_types( [ 'public' => true ], 'objects' ) as $name => $type ) {
            $name           = (string) $name;
            $out[ $name ] = [
                'label'        => (string) ( $type->label ?? $name ),
                'supports'     => function_exists( 'get_all_post_type_supports' ) ? array_keys( array_filter( (array) get_all_post_type_supports( $name ) ) ) : [],
                'taxonomies'   => function_exists( 'get_object_taxonomies' ) ? array_values( (array) get_object_taxonomies( $name ) ) : [],
                'has_archive'  => (bool) ( $type->has_archive ?? false ),
                'hierarchical' => (bool) ( $type->hierarchical ?? false ),
            ];
        }

        return $out;
    }

    /** @return array<string,array<string,mixed>> name => raw taxonomy data */
    public static function readTaxonomies(): array
    {
        $out = [];
        foreach ( (array) get_taxonomies( [ 'public' => true ], 'objects' ) as $name => $tax ) {
            $name           = (string) $name;
            $out[ $name ] = [
                'label'        => (string) ( $tax->label ?? $name ),
                'object_types' => array_values( (array) ( $tax->object_type ?? [] ) ),
                'hierarchical' => (bool) ( $tax->hierarchical ?? false ),
            ];
        }

        return $out;
    }

    /** What `get_site_structure` returns, read from the real WordPress and ACF functions. @return array<string,mixed> */
    public static function catalog( ?array $include = null ): array
    {
        return self::catalogWith( self::readers(), $include );
    }

    /**
     * @param array<string, ?callable> $readers post_types, taxonomies, image_sizes, acf_groups, acf_fields
     * @param ?list<string>            $include sections to return; null = all
     * @return array<string,mixed>
     */
    public static function catalogWith( array $readers, ?array $include = null ): array
    {
        $include   = $include ?? self::SECTIONS;
        $notes     = [];
        $truncated = false;
        $out       = [];

        if ( in_array( 'post_types', $include, true ) ) {
            $raw = self::read( $readers['post_types'] ?? null );
            if ( null === $raw ) {
                $notes[] = 'Post type data is not available on this site.';
            }
            $out['post_types'] = self::named( $raw ?? [], $truncated, static fn ( string $name, array $d ): array => [
                'name'         => $name,
                'label'        => self::text( $d['label'] ?? $name ),
                'supports'     => self::strings( $d['supports'] ?? [] ),
                'taxonomies'   => self::strings( $d['taxonomies'] ?? [] ),
                'has_archive'  => (bool) ( $d['has_archive'] ?? false ),
                'hierarchical' => (bool) ( $d['hierarchical'] ?? false ),
            ] );
        }

        if ( in_array( 'taxonomies', $include, true ) ) {
            $raw = self::read( $readers['taxonomies'] ?? null );
            if ( null === $raw ) {
                $notes[] = 'Taxonomy data is not available on this site.';
            }
            $out['taxonomies'] = self::named( $raw ?? [], $truncated, static fn ( string $name, array $d ): array => [
                'name'         => $name,
                'label'        => self::text( $d['label'] ?? $name ),
                'object_types' => self::strings( $d['object_types'] ?? [] ),
                'hierarchical' => (bool) ( $d['hierarchical'] ?? false ),
            ] );
        }

        if ( in_array( 'image_sizes', $include, true ) ) {
            $raw = self::read( $readers['image_sizes'] ?? null );
            if ( null === $raw ) {
                $notes[] = 'Image size data is not available on this site.';
            }
            $out['image_sizes'] = self::named( $raw ?? [], $truncated, static fn ( string $name, array $d ): array => [
                'name'   => self::text( $name ),
                'width'  => (int) ( $d['width'] ?? 0 ),
                'height' => (int) ( $d['height'] ?? 0 ),
                'crop'   => ! empty( $d['crop'] ),
            ] );
        }

        if ( in_array( 'acf', $include, true ) ) {
            $out['acf'] = self::acf( $readers, $truncated, $notes );
        }

        return $out + [ 'truncated' => $truncated, 'notes' => $notes ];
    }

    /**
     * @param array<string, ?callable> $readers
     * @param list<string>             $notes
     * @return array{active: bool, groups: list<array<string,mixed>>}
     */
    private static function acf( array $readers, bool &$truncated, array &$notes ): array
    {
        $groups = self::read( $readers['acf_groups'] ?? null );
        if ( null === $groups ) {
            $notes[] = 'Advanced Custom Fields is not active on this site.';

            return [ 'active' => false, 'groups' => [] ];
        }

        $list = [];
        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) || ( array_key_exists( 'active', $group ) && ! $group['active'] ) ) {
                continue;
            }
            if ( count( $list ) >= self::MAX_ITEMS ) {
                $truncated = true;
                break;
            }
            $key     = is_string( $group['key'] ?? null ) ? $group['key'] : '';
            $fields  = '' === $key ? null : self::read( $readers['acf_fields'] ?? null, [ $key ] );
            $list[]  = [ 'key' => $key, 'title' => self::text( $group['title'] ?? '' ), 'fields' => self::fields( $fields ?? [], $truncated ) ];
        }

        return [ 'active' => true, 'groups' => $list ];
    }

    /** @param array<int|string,mixed> $raw @return list<array<string,mixed>> */
    private static function fields( array $raw, bool &$truncated ): array
    {
        $list = [];
        foreach ( $raw as $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            if ( count( $list ) >= self::MAX_FIELDS ) {
                $truncated = true;
                break;
            }
            $field = [ 'name' => self::text( $f['name'] ?? '' ), 'label' => self::text( $f['label'] ?? '' ), 'type' => self::text( $f['type'] ?? '' ) ];
            if ( isset( $f['choices'] ) && is_array( $f['choices'] ) && [] !== $f['choices'] ) {
                $choices = [];
                foreach ( $f['choices'] as $value => $label ) {
                    if ( count( $choices ) >= self::MAX_CHOICES ) {
                        $truncated = true;
                        break;
                    }
                    $choices[] = [ 'value' => self::text( (string) $value ), 'label' => self::text( $label ) ];
                }
                $field['choices'] = $choices;
            }
            $list[] = $field;
        }

        return $list;
    }

    /** Calls a reader; null when it is missing, throws, or does not return an array. @param list<mixed> $args */
    private static function read( ?callable $reader, array $args = [] ): ?array
    {
        if ( null === $reader ) {
            return null;
        }
        try {
            $value = $reader( ...$args );
        } catch ( \Throwable $e ) {
            return null;
        }

        return is_array( $value ) ? $value : null;
    }

    /**
     * @param array<int|string,mixed> $raw
     * @param callable(string,array<string,mixed>):array<string,mixed> $map
     * @return list<array<string,mixed>>
     */
    private static function named( array $raw, bool &$truncated, callable $map ): array
    {
        $list = [];
        foreach ( $raw as $name => $data ) {
            if ( ! is_array( $data ) ) {
                continue;
            }
            if ( count( $list ) >= self::MAX_ITEMS ) {
                $truncated = true;
                break;
            }
            $list[] = $map( (string) $name, $data );
        }

        return $list;
    }

    /** @return list<string> only the string entries, each cut */
    private static function strings( mixed $value ): array
    {
        return is_array( $value )
            ? array_values( array_map( [ self::class, 'text' ], array_filter( $value, 'is_string' ) ) )
            : [];
    }

    private static function text( mixed $value ): string
    {
        $text = is_scalar( $value ) ? (string) $value : '';

        return mb_substr( $text, 0, self::MAX_TEXT );
    }
}
```

- [ ] **Step 5: Run and see it pass**

Run: `vendor/bin/phpunit tests/SiteStructureTest.php`
Expected: `OK` (all tests, including the 9 invalid-include cases).

- [ ] **Step 6: Commit**

```bash
git add wp-plugin/src/SiteStructure.php tests/SiteStructureShims.php tests/SiteStructureTest.php
git commit -m "feat: SiteStructure, a read-only view of post types, taxonomies, image sizes and ACF

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Wire `get_site_structure` into MCP and REST, docs and compliance test

**Files:**
- Modify: `wp-plugin/src/McpHandler.php` (tool list entry after `list_global_styles`, dispatch, handler)
- Modify: `wp-plugin/src/RestController.php` (route after `/global-styles`, handler after `global_styles`)
- Modify: `wp-plugin/src/autoload.php`, `wp-plugin/src/StyleGuide.php`, `wp-plugin/src/AdminPage.php` (Features card + comment)
- Modify: `wp-plugin/readme.txt`, `tests/WpOrgComplianceTest.php`
- Test: `tests/SiteStructureLockstepTest.php` (new)

**Interfaces:**
- Consumes: `SiteStructure::parseInclude()`, `SiteStructure::catalog()`, `SiteStructure::SECTIONS` (Task 2); `PageAccess::CAP`; `UsageTracker::log(string,?int,string)`.
- Produces: MCP tool `get_site_structure` (argument `include`), REST `GET /ai-editor-divi5/v1/site-structure?include=...`, usage-log endpoint name `site_structure`.

- [ ] **Step 1: Write the failing lockstep test**

Create `tests/SiteStructureLockstepTest.php`:

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
require_once __DIR__ . '/SiteStructureShims.php';
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
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/SiteStructure.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** get_site_structure: same meaning in MCP and REST, read-only, and deliberately NOT in the ChatGPT OpenAPI spec. */
class SiteStructureLockstepTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_posts']       = [];
        $GLOBALS['__wp_postmeta']    = [];
        $GLOBALS['__wp_caps']        = [ 'edit_pages' => true ];
        $GLOBALS['__ss_post_types']  = [ 'book' => (object) [ 'label' => 'Books', 'has_archive' => true, 'hierarchical' => false ] ];
        $GLOBALS['__ss_image_sizes'] = [ 'card' => [ 'width' => 400, 'height' => 300, 'crop' => false ] ];
        $GLOBALS['__ss_acf_groups']  = [ [ 'key' => 'group_1', 'title' => 'Book' ] ];
        $GLOBALS['__ss_acf_fields']  = [ 'group_1' => [ [ 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text' ] ] ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__ss_post_types'], $GLOBALS['__ss_image_sizes'], $GLOBALS['__ss_acf_groups'], $GLOBALS['__ss_acf_fields'] );
        $GLOBALS['__wp_can'] = true;
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    /** @return array<string,mixed> the JSON-RPC response */
    private function mcpCall( array $args = [] ): array
    {
        $req = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'get_site_structure', 'arguments' => $args ?: new \stdClass() ] ] );

        return (array) ( new McpHandler() )->handle( $req )->get_data();
    }

    public function testMcpDeclaresAndDispatchesTheTool(): void
    {
        $mcp = $this->src( 'McpHandler.php' );
        $this->assertStringContainsString( "'name'        => 'get_site_structure'", $mcp );
        $this->assertMatchesRegularExpression( "/'get_site_structure'\s*=>\s*\\\$this->toolSiteStructure/", $mcp );
    }

    public function testRestRegistersTheRouteAsReadOnly(): void
    {
        $rest = $this->src( 'RestController.php' );
        $this->assertMatchesRegularExpression( "/'\/site-structure',\s*\[\s*'methods'\s*=>\s*WP_REST_Server::READABLE/", $rest );
    }

    public function testItIsNotInTheChatGptSpecOnPurpose(): void
    {
        // The importer allows 30 operations and the free (16) plus Pro (14) tools already use all 30 (owner decision 2026-10-04).
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $this->assertArrayNotHasKey( '/site-structure', $spec['paths'] );
        $this->assertStringNotContainsString( 'site-structure', json_encode( $spec ) );
    }

    public function testRestAndMcpReturnTheSameStructure(): void
    {
        $rest = ( new RestController() )->site_structure( new \WP_REST_Request() );
        $this->assertSame( 200, $rest->get_status() );
        $d = (array) $rest->get_data();
        $this->assertSame( [ 'book' ], array_column( $d['post_types'], 'name' ) );
        $this->assertSame( 'isbn', $d['acf']['groups'][0]['fields'][0]['name'] );

        $ok = $this->mcpCall();
        $this->assertArrayNotHasKey( 'error', $ok );
        $this->assertSame( $d, json_decode( $ok['result']['content'][0]['text'], true ) );
    }

    public function testIncludeWorksOnBothSurfaces(): void
    {
        $rest = ( new RestController() )->site_structure( new \WP_REST_Request( [], [ 'include' => 'image_sizes, acf' ] ) );
        $this->assertSame( [ 'image_sizes', 'acf', 'truncated', 'notes' ], array_keys( (array) $rest->get_data() ) );

        $mcp = json_decode( $this->mcpCall( [ 'include' => [ 'image_sizes' ] ] )['result']['content'][0]['text'], true );
        $this->assertSame( [ 'image_sizes', 'truncated', 'notes' ], array_keys( $mcp ) );
    }

    public function testABadIncludeIsRejectedNeverCast(): void
    {
        foreach ( [ 'nope', [ 'nope' ], 7, true ] as $bad ) {
            $r = ( new RestController() )->site_structure( new \WP_REST_Request( [], [ 'include' => $bad ] ) );
            $this->assertInstanceOf( \WP_Error::class, $r );
            $this->assertSame( 400, $r->data['status'] );
            $e = $this->mcpCall( [ 'include' => $bad ] );
            $this->assertSame( -32602, $e['error']['code'] );
        }
    }

    public function testBothSurfacesRefuseAUserWhoCannotEditPages(): void
    {
        $GLOBALS['__wp_caps'] = [];
        $denied = $this->mcpCall();
        $this->assertSame( -32602, $denied['error']['code'] );
        $this->assertStringContainsString( 'permission', $denied['error']['message'] );
        $r = ( new RestController() )->site_structure( new \WP_REST_Request() );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 403, $r->data['status'] );
    }

    public function testTheToolWritesNothing(): void
    {
        $before = [ $GLOBALS['__wp_options'], $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        ( new RestController() )->site_structure( new \WP_REST_Request() );
        $this->mcpCall();
        $this->assertSame( $before, [ $GLOBALS['__wp_options'], $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ] );
    }

    public function testTheStyleGuideTellsTheAiToCallIt(): void
    {
        $this->assertStringContainsString( '`get_site_structure`', $this->src( 'StyleGuide.php' ) );
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `vendor/bin/phpunit tests/SiteStructureLockstepTest.php`
Expected: failures (tool not declared, `site_structure` undefined method).

- [ ] **Step 3: Implement MCP**

In `wp-plugin/src/McpHandler.php`, after the closing `],` of the `list_global_styles` tool definition (the one ending with `'required'   => [],` then `],`), add this entry to the `$tools` array:

```php
            [
                'name'        => 'get_site_structure',
                'description' => 'List how this site is set up (read-only): public post types, taxonomies, image sizes and, when Advanced Custom Fields is active, its field groups and fields (names, labels, types and choices; never field values). Call it before building a page that lists custom post types, uses a given image size or should refer to ACF fields. Pass "include" to get only some sections: post_types, taxonomies, image_sizes, acf.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['include' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['post_types', 'taxonomies', 'image_sizes', 'acf']], 'description' => 'Optional. Sections to return; default all.']],
                    'required'   => [],
                ],
            ],
```

In the dispatch `match`, after `'list_global_styles' => $this->toolListGlobalStyles($id, $arguments),` add:

```php
            'get_site_structure' => $this->toolSiteStructure($id, $arguments),
```

After the `toolListGlobalStyles` method add:

```php
    private function toolSiteStructure(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('site_structure', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read the site structure.');
        }
        $include = SiteStructure::parseInclude($args['include'] ?? null);
        if ($include === null) {
            return $this->rpcError($id, -32602, 'include must be a list of: ' . implode(', ', SiteStructure::SECTIONS) . '.');
        }
        $result = SiteStructure::catalog($include);
        UsageTracker::log('site_structure', null, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($result)]],
        ]);
    }
```

- [ ] **Step 4: Implement REST**

In `wp-plugin/src/RestController.php`, directly after the `/global-styles` `register_rest_route(...)` block add:

```php
        // GET /site-structure — read-only post types, taxonomies, image sizes and ACF field groups
        register_rest_route(self::NS, '/site-structure', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'site_structure'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);
```

After the `global_styles` method add:

```php
    public function site_structure(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('site_structure', null, 'error');
            return new WP_Error('forbidden', 'You do not have permission to read the site structure.', ['status' => 403]);
        }
        $include = SiteStructure::parseInclude($request->get_param('include'));
        if ($include === null) {
            return new WP_Error('invalid_field', '"include" must be a list of: ' . implode(', ', SiteStructure::SECTIONS) . '.', ['status' => 400]);
        }
        $result = SiteStructure::catalog($include);
        UsageTracker::log('site_structure', null, 'read');

        return new WP_REST_Response($result, 200);
    }
```

- [ ] **Step 5: Autoload, guide sentence, Features card**

In `wp-plugin/src/autoload.php`, after the line `require_once __DIR__ . '/GlobalStyles.php';` add `require_once __DIR__ . '/SiteStructure.php';`.

In `wp-plugin/src/StyleGuide.php`, immediately before the line `## Match the site (colors and presets)` insert this section (keep the blank line after it):

```
## Match the site's structure
Call `get_site_structure` before building a page that should list a custom post type or
taxonomy, use an image at a particular registered size, or refer to Advanced Custom Fields
field names. It is read-only and never returns field values; it only tells you what exists.

```

In `wp-plugin/src/AdminPage.php`, change the comment `// [title, description, tools it covers] — the 16 tools your AI assistant can call.` to say `17 tools`, and add this entry right after the `'Match your site\'s colors and presets'` entry in `$features`:

```php
            [ __( 'Fit your site (post types, image sizes, ACF)', 'jhmg-ai-editor-for-divi-5' ), __( 'Your AI can look up your custom post types, taxonomies, image sizes and Advanced Custom Fields field groups (read-only), so pages match how your site is set up. It never reads field values or your theme files.', 'jhmg-ai-editor-for-divi-5' ), 'get_site_structure' ],
```

- [ ] **Step 6: Readme and compliance test**

In `wp-plugin/readme.txt`: change `= What your AI assistant can do (16 tools) =` to `(17 tools)`; under "Read and check:" add after the `list_global_styles` bullet:

```
* `get_site_structure`: list your site's custom post types, taxonomies, image sizes and Advanced Custom Fields field groups so pages fit your site (read-only: never field values or theme files; available over MCP and the REST API, not in the ChatGPT Action).
```

Replace the FAQ block `= Can it read my ACF fields, custom post types or functions.php? =` and its answer with:

```
= Can it read my ACF fields, custom post types or functions.php? =

It can read how your site is set up: custom post types, taxonomies, image sizes and, when Advanced Custom Fields is active, its field groups and fields (names, labels, types and choices). It never reads field values, your theme files or `functions.php`. This is available to assistants that connect over MCP or the REST API, not to the ChatGPT Action.
```

In `tests/WpOrgComplianceTest.php`: in the pinned tool array add `'get_site_structure'` in alphabetical position (after `'get_site_guide'`, before `'get_style_guide'`), change `$this->assertCount(16, $tools);` to `17`, replace the comment above it with `// 17 tools: 4.6.0 adds the READ-ONLY get_site_structure (post types, taxonomies, image sizes, ACF). Nothing is gated and nothing writes a setting; no rule of this test is loosened.`, and change `'(16 tools)'` to `'(17 tools)'`.

- [ ] **Step 7: Run everything**

Run: `vendor/bin/phpunit tests/SiteStructureLockstepTest.php && make test 2>&1 | tail -4`
Expected: the new test passes and the whole suite is `OK`. If `StyleGuideTest` or an admin/Features test pins a size or card count and fails, update ONLY that pinned number, with a one-line comment saying why (a new section / card was added).

- [ ] **Step 8: Commit**

```bash
git add wp-plugin tests
git commit -m "feat: get_site_structure, a read-only tool for post types, taxonomies, image sizes and ACF

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `ApprovalMode` class

**Files:**
- Create: `wp-plugin/src/ApprovalMode.php`
- Modify: `wp-plugin/src/autoload.php`
- Test: `tests/ApprovalModeTest.php` (new)

**Interfaces:**
- Produces:
  - `ApprovalMode::OPTION` (`'ai_editor_divi5_require_preview'`), `TTL` (`1800`), `CODE` (`'PREVIEW_REQUIRED'`), `MESSAGE`.
  - `ApprovalMode::enabled(): bool` (only the stored string `'1'` is on), `ApprovalMode::set(bool $on): void`.
  - `ApprovalMode::key(int $userId, int $pageId, string $content): string` (transient name, `aied_prev_` + 40 hex chars).
  - `ApprovalMode::recordPreview(int $pageId, string $content, bool $valid): void`: stores a transient only when the mode is on AND the preview was valid.
  - `ApprovalMode::consume(int $pageId, string $content): bool`: `true` when the mode is off, or when a matching preview existed (it is deleted); `false` otherwise.
  - `ApprovalMode::refusal(): array` = `['saved'=>false,'code'=>'PREVIEW_REQUIRED','message'=>MESSAGE]`.

- [ ] **Step 1: Write the failing test**

Create `tests/ApprovalModeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ApprovalMode;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ApprovalMode.php';

class ApprovalModeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options']       = [];
        $GLOBALS['__wp_transients']    = [];
        $GLOBALS['__wp_transient_ttl'] = [];
        $GLOBALS['__wp_current_user_id'] = 7;
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_current_user_id'] );
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
    }

    public function testItIsOffByDefaultAndSetTurnsItOnAndOff(): void
    {
        $this->assertFalse( ApprovalMode::enabled() );
        ApprovalMode::set( true );
        $this->assertTrue( ApprovalMode::enabled() );
        $this->assertSame( '1', $GLOBALS['__wp_options'][ ApprovalMode::OPTION ] );
        ApprovalMode::set( false );
        $this->assertFalse( ApprovalMode::enabled() );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'notOne' )]
    public function testOnlyTheStoredStringOneMeansOn( mixed $stored ): void
    {
        $GLOBALS['__wp_options'][ ApprovalMode::OPTION ] = $stored;
        $this->assertFalse( ApprovalMode::enabled() );
    }

    /** @return list<array{mixed}> */
    public static function notOne(): array
    {
        return [ [ 'yes' ], [ 'true' ], [ '' ], [ 0 ], [ '0' ], [ false ], [ [ '1' ] ] ];
    }

    public function testWhenOffNothingIsRecordedAndEverySaveIsAllowed(): void
    {
        ApprovalMode::recordPreview( 5, 'content', true );
        $this->assertSame( [], $GLOBALS['__wp_transients'], 'no state is written while the mode is off' );
        $this->assertTrue( ApprovalMode::consume( 5, 'content' ) );
    }

    public function testASavingWithoutAPreviewIsRefusedWhenOn(): void
    {
        ApprovalMode::set( true );
        $this->assertFalse( ApprovalMode::consume( 5, 'content' ), 'a missing preview (or an evicted one) is a refusal' );
    }

    public function testAValidPreviewUnlocksExactlyOneSaveOfExactlyThatContent(): void
    {
        ApprovalMode::set( true );
        ApprovalMode::recordPreview( 5, 'content', true );

        $key = ApprovalMode::key( 7, 5, 'content' );
        $this->assertSame( ApprovalMode::TTL, $GLOBALS['__wp_transient_ttl'][ $key ] );
        $this->assertSame( 1800, ApprovalMode::TTL );

        $this->assertTrue( ApprovalMode::consume( 5, 'content' ) );
        $this->assertFalse( ApprovalMode::consume( 5, 'content' ), 'single use: the same save again needs a new preview' );
    }

    public function testAnInvalidPreviewUnlocksNothing(): void
    {
        ApprovalMode::set( true );
        ApprovalMode::recordPreview( 5, 'content', false );
        $this->assertFalse( ApprovalMode::consume( 5, 'content' ) );
        $this->assertSame( [], $GLOBALS['__wp_transients'] );
    }

    public function testAPreviewDoesNotUnlockOtherContentAnotherPageOrAnotherUser(): void
    {
        ApprovalMode::set( true );
        ApprovalMode::recordPreview( 5, 'content', true );

        $this->assertFalse( ApprovalMode::consume( 5, 'content ' ), 'one extra character' );
        $this->assertFalse( ApprovalMode::consume( 6, 'content' ), 'another page' );

        $GLOBALS['__wp_current_user_id'] = 8;
        $this->assertFalse( ApprovalMode::consume( 5, 'content' ), 'another user' );

        $GLOBALS['__wp_current_user_id'] = 7;
        $this->assertTrue( ApprovalMode::consume( 5, 'content' ), 'the original preview is still there for its owner' );
    }

    public function testTheKeyIsAShortStableTransientName(): void
    {
        $key = ApprovalMode::key( 7, 5, 'content' );
        $this->assertMatchesRegularExpression( '/^aied_prev_[0-9a-f]{40}$/', $key );
        $this->assertSame( $key, ApprovalMode::key( 7, 5, 'content' ) );
        $this->assertNotSame( $key, ApprovalMode::key( 7, 5, 'other' ) );
    }

    public function testTheRefusalBodyNamesTheCodeAndTheNextStep(): void
    {
        $r = ApprovalMode::refusal();
        $this->assertFalse( $r['saved'] );
        $this->assertSame( 'PREVIEW_REQUIRED', $r['code'] );
        $this->assertStringContainsString( 'dry_run', $r['message'] );
        $this->assertStringContainsString( 'Nothing was saved', $r['message'] );
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `vendor/bin/phpunit tests/ApprovalModeTest.php`
Expected: error, class `ApprovalMode` not found.

- [ ] **Step 3: Implement**

Create `wp-plugin/src/ApprovalMode.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Optional "Require a preview before every save" setting. When it is on, the free plugin's page-saving
 * entry points refuse a save unless the exact resulting content was previewed (a valid dry run) by the
 * same user for the same page in the last 30 minutes. A preview is single use. State is one transient
 * per preview; if it is evicted the only effect is that the AI must preview again.
 */
final class ApprovalMode
{
    public const OPTION  = 'ai_editor_divi5_require_preview';
    public const TTL     = 1800;
    public const CODE    = 'PREVIEW_REQUIRED';
    public const MESSAGE = 'Approval mode is on: this change was not previewed. Call again with dry_run set to true first, show the owner the result, then repeat the same call without dry_run to save it. Nothing was saved.';

    public static function enabled(): bool
    {
        return '1' === get_option( self::OPTION, '0' );
    }

    public static function set( bool $on ): void
    {
        update_option( self::OPTION, $on ? '1' : '0', false );
    }

    public static function key( int $userId, int $pageId, string $content ): string
    {
        return 'aied_prev_' . substr( hash( 'sha256', $userId . '|' . $pageId . '|' . hash( 'sha256', $content ) ), 0, 40 );
    }

    /** Remember a successful, valid preview (only while the mode is on). */
    public static function recordPreview( int $pageId, string $content, bool $valid ): void
    {
        if ( ! $valid || ! self::enabled() ) {
            return;
        }
        set_transient( self::key( get_current_user_id(), $pageId, $content ), 1, self::TTL );
    }

    /** True when a save may go ahead: the mode is off, or a matching preview existed (it is used up now). */
    public static function consume( int $pageId, string $content ): bool
    {
        if ( ! self::enabled() ) {
            return true;
        }
        $key = self::key( get_current_user_id(), $pageId, $content );
        if ( false === get_transient( $key ) ) {
            return false;
        }
        delete_transient( $key );

        return true;
    }

    /** @return array{saved:false, code:string, message:string} */
    public static function refusal(): array
    {
        return [ 'saved' => false, 'code' => self::CODE, 'message' => self::MESSAGE ];
    }
}
```

In `wp-plugin/src/autoload.php`, after the `SiteStructure.php` line add `require_once __DIR__ . '/ApprovalMode.php';`.

- [ ] **Step 4: Run and see it pass**

Run: `vendor/bin/phpunit tests/ApprovalModeTest.php`
Expected: `OK`.

- [ ] **Step 5: Commit**

```bash
git add wp-plugin/src/ApprovalMode.php wp-plugin/src/autoload.php tests/ApprovalModeTest.php
git commit -m "feat: ApprovalMode, a single-use preview requirement for saves

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Enforce approval mode on the four write entry points

**Files:**
- Modify: `wp-plugin/src/McpHandler.php` (`toolUpdate`, `toolEditContent`)
- Modify: `wp-plugin/src/RestController.php` (`update_page`, `edit_page`)
- Modify: `wp-plugin/src/OpenApiSpec.php` (428 response on the two write operations)
- Test: `tests/ApprovalModeHandlersTest.php` (new)

**Interfaces:**
- Consumes: `ApprovalMode::recordPreview()`, `ApprovalMode::consume()`, `ApprovalMode::refusal()` (Task 4); `DryRun::preview()` result key `valid`.
- Produces: with the mode on, a real save without a matching preview is refused: MCP tool result `isError: true` with body `{saved:false, code:'PREVIEW_REQUIRED', message}`; REST status 428 with the same body.

- [ ] **Step 1: Write the failing behavioural test**

Create `tests/ApprovalModeHandlersTest.php` (same harness as `DryRunHandlersTest`):

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
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** Approval mode, driven through the REAL REST and MCP handlers against the fake database. */
class ApprovalModeHandlersTest extends TestCase
{
    private const ORIGINAL = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private const NEW_VALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private const NEW_INVALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/not-a-module {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']         = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_transient_ttl']   = [];
        $GLOBALS['__wp_current_user_id'] = 7;
        $post                            = new \WP_Post();
        $post->ID                        = 5;
        $post->post_content              = self::ORIGINAL;
        $GLOBALS['__wp_posts']           = [ 5 => $post ];
        $GLOBALS['__wp_postmeta']        = [];
        $GLOBALS['__wp_update_fail']     = false;
        $GLOBALS['__wp_update_calls']    = 0;
        $GLOBALS['__wp_can']             = true;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']            = null;
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        unset( $GLOBALS['__wp_current_user_id'] );
        $GLOBALS['__wp_can'] = true;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function unwrap( mixed $r ): array
    {
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function restUpdate( array $body ): array
    {
        return $this->unwrap( ( new RestController() )->update_page( new \WP_REST_Request( $body, [ 'id' => 5 ] ) ) );
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function restEdit( array $body ): array
    {
        return $this->unwrap( ( new RestController() )->edit_page( new \WP_REST_Request( $body, [ 'id' => 5 ] ) ) );
    }

    /** @return array{0:?int,1:array<string,mixed>,2:bool} JSON-RPC error code, decoded tool result, isError */
    private function mcp( string $tool, array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], [], true ];
        }

        return [ null, (array) json_decode( $data['result']['content'][0]['text'], true ), (bool) ( $data['result']['isError'] ?? false ) ];
    }

    private function assertUnchanged( string $why ): void
    {
        $this->assertSame( self::ORIGINAL, $GLOBALS['__wp_posts'][5]->post_content, "{$why}: content changed" );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], "{$why}: wp_update_post was called" );
    }

    // ---- off: nothing changes -------------------------------------------------------------------

    public function testWithTheModeOffSavesNeedNoPreviewAndNoStateIsWritten(): void
    {
        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );
        $this->assertSame( [], $GLOBALS['__wp_transients'] );
    }

    // ---- on: the four entry points ---------------------------------------------------------------

    public function testRestUpdateIsRefusedWith428WithoutAPreviewThenAllowedOnceAfterOne(): void
    {
        ApprovalMode::set( true );

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 428, $status );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] );
        $this->assertFalse( $data['saved'] );
        $this->assertUnchanged( 'no preview' );

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['valid'] );
        $this->assertUnchanged( 'the preview itself' );

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( self::NEW_VALID, $GLOBALS['__wp_posts'][5]->post_content );

        [ $status ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 428, $status, 'the preview was used up' );
    }

    public function testRestEditFollowsTheSameRule(): void
    {
        ApprovalMode::set( true );
        $body = [ 'find' => '4_4', 'replace' => '1_2' ];

        [ $status, $data ] = $this->restEdit( $body );
        $this->assertSame( 428, $status );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] );
        $this->assertUnchanged( 'edit without preview' );

        $this->restEdit( $body + [ 'dry_run' => true ] );
        [ $status, $data ] = $this->restEdit( $body );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertStringContainsString( '1_2', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testMcpUpdateIsAToolErrorWithoutAPreviewThenAllowedAfterOne(): void
    {
        ApprovalMode::set( true );
        $args = [ 'page_id' => 5, 'post_content' => self::NEW_VALID ];

        [ $err, $data, $isError ] = $this->mcp( 'update_page_layout', $args );
        $this->assertNull( $err );
        $this->assertTrue( $isError );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] );
        $this->assertUnchanged( 'mcp update without preview' );

        $this->mcp( 'update_page_layout', $args + [ 'dry_run' => true ] );
        [ $err, $data, $isError ] = $this->mcp( 'update_page_layout', $args );
        $this->assertFalse( $isError );
        $this->assertTrue( $data['saved'] );
    }

    public function testMcpEditFollowsTheSameRule(): void
    {
        ApprovalMode::set( true );
        $args = [ 'page_id' => 5, 'find' => '4_4', 'replace' => '1_2' ];

        [ , $data, $isError ] = $this->mcp( 'edit_page_content', $args );
        $this->assertTrue( $isError );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] );
        $this->assertUnchanged( 'mcp edit without preview' );

        $this->mcp( 'edit_page_content', $args + [ 'dry_run' => true ] );
        [ , $data, $isError ] = $this->mcp( 'edit_page_content', $args );
        $this->assertFalse( $isError );
        $this->assertTrue( $data['saved'] );
    }

    // ---- what a preview does and does not unlock ---------------------------------------------------

    public function testAPreviewOfOneChangeDoesNotUnlockADifferentChange(): void
    {
        ApprovalMode::set( true );
        $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );

        [ $status ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID . ' ' ] );
        $this->assertSame( 428, $status, 'one extra character is a different change' );
        $this->assertUnchanged( 'different content' );

        $GLOBALS['__wp_current_user_id'] = 8;
        [ $status ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 428, $status, 'another user cannot use this user\'s preview' );
        $this->assertUnchanged( 'another user' );
    }

    public function testAnInvalidPreviewUnlocksNothingAndAnInvalidSaveReturnsViolationsNotTheApprovalError(): void
    {
        ApprovalMode::set( true );

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_INVALID, 'dry_run' => true ] );
        $this->assertSame( 200, $status );
        $this->assertFalse( $data['valid'] );
        $this->assertSame( [], $GLOBALS['__wp_transients'], 'an invalid preview records nothing' );

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_INVALID ] );
        $this->assertSame( 422, $status, 'the AI gets the violations so it can fix the layout' );
        $this->assertArrayHasKey( 'violations', $data );
        $this->assertArrayNotHasKey( 'code', $data );
        $this->assertUnchanged( 'invalid save' );
    }

    public function testTurningTheModeOffBetweenPreviewAndSaveStillSaves(): void
    {
        ApprovalMode::set( true );
        $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );
        ApprovalMode::set( false );

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `vendor/bin/phpunit tests/ApprovalModeHandlersTest.php`
Expected: the "on" tests fail (saves go through without a preview, no 428).

- [ ] **Step 3: Implement in `McpHandler`**

In `toolUpdate`, replace

```php
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach(DryRun::preview((string) $post->post_content, $content), $content))]],
            ]);
```
with
```php
            $preview = DryRun::preview((string) $post->post_content, $content);
            ApprovalMode::recordPreview($pageId, $content, (bool) $preview['valid']);

            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach($preview, $content))]],
            ]);
```

In `toolUpdate`, replace
```php
        // Snapshots the previous content, then saves (wp_slash is applied inside HistoryService).
        $write = HistoryService::write($pageId, $content, 'update_page_layout');
```
with
```php
        if (!ApprovalMode::consume($pageId, $content)) {
            UsageTracker::log('update_layout', $pageId, 'error');
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(ApprovalMode::refusal())]],
                'isError' => true,
            ]);
        }

        // Snapshots the previous content, then saves (wp_slash is applied inside HistoryService).
        $write = HistoryService::write($pageId, $content, 'update_page_layout');
```

In `toolEditContent`, replace
```php
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach(DryRun::preview((string) $post->post_content, $edit['content']) + ['replaced' => $edit['count']], $edit['content']))]],
            ]);
```
with
```php
            $preview = DryRun::preview((string) $post->post_content, $edit['content']);
            ApprovalMode::recordPreview($pageId, $edit['content'], (bool) $preview['valid']);

            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach($preview + ['replaced' => $edit['count']], $edit['content']))]],
            ]);
```

and replace
```php
        $write = HistoryService::write($pageId, $edit['content'], 'edit_page_content');
```
with
```php
        if (!ApprovalMode::consume($pageId, $edit['content'])) {
            UsageTracker::log('edit_content', $pageId, 'error');
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(ApprovalMode::refusal())]],
                'isError' => true,
            ]);
        }

        $write = HistoryService::write($pageId, $edit['content'], 'edit_page_content');
```

- [ ] **Step 4: Implement in `RestController`**

In `update_page`, replace
```php
            return new WP_REST_Response(StyleReferences::attach(DryRun::preview((string) $post->post_content, $content), $content), 200);
```
with
```php
            $preview = DryRun::preview((string) $post->post_content, $content);
            ApprovalMode::recordPreview($id, $content, (bool) $preview['valid']);

            return new WP_REST_Response(StyleReferences::attach($preview, $content), 200);
```

and replace
```php
        // Validation passed — snapshot the previous content, then save (wp_slash inside HistoryService).
        $write = HistoryService::write($id, $content, 'update_page');
```
with
```php
        if (!ApprovalMode::consume($id, $content)) {
            UsageTracker::log('update_page', $id, 'error');
            return new WP_REST_Response(ApprovalMode::refusal(), 428);
        }

        // Validation passed — snapshot the previous content, then save (wp_slash inside HistoryService).
        $write = HistoryService::write($id, $content, 'update_page');
```

In `edit_page`, replace
```php
            return new WP_REST_Response(StyleReferences::attach(DryRun::preview((string) $post->post_content, $edit['content']) + ['replaced' => $edit['count']], $edit['content']), 200);
```
with
```php
            $preview = DryRun::preview((string) $post->post_content, $edit['content']);
            ApprovalMode::recordPreview($id, $edit['content'], (bool) $preview['valid']);

            return new WP_REST_Response(StyleReferences::attach($preview + ['replaced' => $edit['count']], $edit['content']), 200);
```

and replace
```php
        $write = HistoryService::write($id, $edit['content'], 'edit_page');
```
with
```php
        if (!ApprovalMode::consume($id, $edit['content'])) {
            UsageTracker::log('edit_page', $id, 'error');
            return new WP_REST_Response(ApprovalMode::refusal(), 428);
        }

        $write = HistoryService::write($id, $edit['content'], 'edit_page');
```

- [ ] **Step 5: Document the 428 in OpenAPI (no new operation)**

In `wp-plugin/src/OpenApiSpec.php`, in the `put` operation's `responses`, add after the `'404'` line:
```php
                            '428' => ['description' => 'Approval mode is on and this exact change was not previewed. Call again with dry_run true first, then repeat the save.'],
```
and add the identical line to the `/pages/{id}/edit` `post` operation's `responses` (after its `'404'` line).

- [ ] **Step 6: Run the new test, then everything**

Run: `vendor/bin/phpunit tests/ApprovalModeHandlersTest.php && make test 2>&1 | tail -4`
Expected: all pass. `DryRunLockstepTest` (dry-run return before write, 16 operations) and `GlobalStylesLockstepTest` must still pass unchanged.

- [ ] **Step 7: Commit**

```bash
git add wp-plugin/src/McpHandler.php wp-plugin/src/RestController.php wp-plugin/src/OpenApiSpec.php tests/ApprovalModeHandlersTest.php
git commit -m "feat: approval mode refuses a save unless the same change was previewed

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Approval mode in the admin (toggle, Features card, uninstall, readme)

**Files:**
- Modify: `wp-plugin/src/AdminPage.php` (hook, handler, `approvalCard()`, notices, Settings view, Features card)
- Modify: `wp-plugin/uninstall.php`, `wp-plugin/readme.txt`
- Test: `tests/AdminFormShims.php` (new, no namespace) and `tests/ApprovalCardTest.php` (new)

**Interfaces:**
- Consumes: `ApprovalMode::enabled()`, `ApprovalMode::set()`, `ApprovalMode::OPTION`.
- Produces: `AdminPage::approvalCard(): void` (renders the card), admin-post action `ai_editor_divi5_toggle_approval`, notices `approval_on` / `approval_off`.

- [ ] **Step 1: Write the failing test**

First create `tests/AdminFormShims.php`. It must NOT declare a namespace, otherwise the functions would not be global and `AdminPage` could not see them:

```php
<?php

declare(strict_types=1);

/** The few admin form helpers the approval card needs. Guarded so they never clash with another shim. */

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) { return 'https://acme.example/wp-admin/' . $path; }
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( $action = -1 ) { echo '<input type="hidden" name="_wpnonce" value="nonce-for-' . $action . '">'; }
}
if ( ! function_exists( 'checked' ) ) {
    function checked( $checked, $current = true, $display = true ) { $out = $checked == $current ? " checked='checked'" : ''; if ( $display ) { echo $out; } return $out; }
}
```

Then create `tests/ApprovalCardTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AdminPage;
use AiEditorDivi5\WP\ApprovalMode;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdminFormShims.php';
require_once __DIR__ . '/../wp-plugin/src/ApprovalMode.php';
require_once __DIR__ . '/../wp-plugin/src/AdminPage.php';

class ApprovalCardTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = [];
    }

    private function card(): string
    {
        ob_start();
        ( new AdminPage() )->approvalCard();

        return (string) ob_get_clean();
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/' . $file );
    }

    public function testTheCardPostsToAdminPostWithANonceAndTheCheckboxIsUncheckedByDefault(): void
    {
        $html = $this->card();
        $this->assertStringContainsString( 'name="action" value="ai_editor_divi5_toggle_approval"', $html );
        $this->assertStringContainsString( 'nonce-for-ai_editor_divi5_toggle_approval', $html );
        $this->assertStringContainsString( 'name="require_preview"', $html );
        $this->assertStringNotContainsString( "checked='checked'", $html );
        $this->assertStringContainsString( 'Require a preview before every save', $html );
    }

    public function testTheCheckboxIsCheckedWhenTheModeIsOn(): void
    {
        ApprovalMode::set( true );
        $this->assertStringContainsString( "checked='checked'", $this->card() );
    }

    public function testTheHandlerIsRegisteredGuardedAndOnlyOneMeansOn(): void
    {
        $src = $this->src( 'src/AdminPage.php' );
        $this->assertStringContainsString( "add_action('admin_post_ai_editor_divi5_toggle_approval', [\$this, 'handleToggleApproval']);", $src );
        $this->assertStringContainsString( "\$this->guard('ai_editor_divi5_toggle_approval');", $src );
        $this->assertStringContainsString( "'1' === sanitize_text_field( wp_unslash( \$_POST['require_preview'] ?? '' ) )", $src );
        $this->assertStringContainsString( "'approval_on'", $src );
        $this->assertStringContainsString( "'approval_off'", $src );
    }

    public function testTheCardIsShownOnTheSettingsTabAndDescribedOnTheFeaturesTab(): void
    {
        $src = $this->src( 'src/AdminPage.php' );
        $this->assertStringContainsString( '$this->approvalCard();', $src );
        $this->assertStringContainsString( 'Approval mode (optional)', $src );
    }

    public function testUninstallRemovesTheSettingAndTheReadmeExplainsIt(): void
    {
        $this->assertStringContainsString( 'ApprovalMode::OPTION', $this->src( 'uninstall.php' ) );
        $readme = $this->src( 'readme.txt' );
        $this->assertStringContainsString( 'Approval mode', $readme );
        $this->assertStringContainsString( 'Require a preview before every save', $readme );
    }
}
```

- [ ] **Step 2: Run and watch it fail**

Run: `vendor/bin/phpunit tests/ApprovalCardTest.php`
Expected: failures (`approvalCard` undefined, strings missing).

- [ ] **Step 3: Implement in `AdminPage`**

After the line `add_action('admin_post_ai_editor_divi5_dismiss_pro_card',   [$this, 'handleDismissProCard']);` add:

```php
        add_action('admin_post_ai_editor_divi5_toggle_approval',    [$this, 'handleToggleApproval']);
```

After the `handleClearUsage` method add:

```php
    public function handleToggleApproval(): void
    {
        $this->guard('ai_editor_divi5_toggle_approval'); // guard() verifies the nonce via check_admin_referer().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
        $on = '1' === sanitize_text_field( wp_unslash( $_POST['require_preview'] ?? '' ) );
        ApprovalMode::set( $on );
        $this->redirect('settings', $on ? 'approval_on' : 'approval_off');
    }
```

Add this public method just before `viewSettings()`:

```php
    /** The Settings card for the optional "Require a preview before every save" mode. */
    public function approvalCard(): void
    {
        $on = ApprovalMode::enabled();
        ?>
        <div class="aied-card">
            <h3><?php esc_html_e( 'Approval mode', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
            <p class="aied-muted"><?php esc_html_e( 'When this is on, your AI assistant must preview every change before it can save it: it asks for a preview (nothing is saved), shows you the result, and only then repeats the change to save it. New pages are always drafts and every save can still be undone. Off by default.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ai_editor_divi5_toggle_approval">
                <?php wp_nonce_field( 'ai_editor_divi5_toggle_approval' ); ?>
                <label>
                    <input type="checkbox" name="require_preview" value="1"<?php checked( $on ); ?>>
                    <?php esc_html_e( 'Require a preview before every save', 'jhmg-ai-editor-for-divi-5' ); ?>
                </label>
                <button type="submit" class="button"><?php esc_html_e( 'Save', 'jhmg-ai-editor-for-divi-5' ); ?></button>
            </form>
        </div>
        <?php
    }
```

In `viewSettings()`, replace the line `        <!-- Activity log -->` with:

```php
        <?php $this->approvalCard(); ?>

        <!-- Activity log -->
```

In the notice map (the array containing `'usage_cleared'`), add:

```php
            'approval_on'        => __( 'Approval mode is on. Your AI assistant must preview every change before it can save it.', 'jhmg-ai-editor-for-divi-5' ),
            'approval_off'       => __( 'Approval mode is off.', 'jhmg-ai-editor-for-divi-5' ),
```

In `$features` (Features tab), add after the `'Undo AI edits'` entry:

```php
            [ __( 'Approval mode (optional)', 'jhmg-ai-editor-for-divi-5' ), __( 'Turn on "Require a preview before every save" in Settings and your AI must show a preview of every change before it can save it. Off by default.', 'jhmg-ai-editor-for-divi-5' ), 'dry_run' ],
```

- [ ] **Step 4: Uninstall and readme**

In `wp-plugin/uninstall.php`, after the line `delete_option('ai_editor_divi5_db_version');` add:

```php
delete_option( AiEditorDivi5\WP\ApprovalMode::OPTION );
```

In `wp-plugin/readme.txt`, extend the answer of `= Is it dangerous to let AI edit my site? Is there an approval step? =` by appending this sentence at the end of the answer: `If you want a stricter rule, turn on Approval mode in Settings: your assistant must then preview every change before it can save it.` Also add this bullet to the "Examples to try" area's neighbouring section "What makes it smart" only if a fitting bullet list exists there; otherwise skip (the FAQ and changelog carry it).

- [ ] **Step 5: Run the new test, then everything**

Run: `vendor/bin/phpunit tests/ApprovalCardTest.php && make test 2>&1 | tail -4`
Expected: all pass. If an existing admin or Features test pins the number of Features cards, update that number (now 11) with a one-line comment.

- [ ] **Step 6: Commit**

```bash
git add wp-plugin tests/AdminFormShims.php tests/ApprovalCardTest.php
git commit -m "feat: approval mode setting, Features card and uninstall cleanup

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Token numbers

**Files:**
- Create: `scripts/measure-tokens.php` (dev-only, not in the plugin zip)
- Create (generated): `docs/token-usage.md`
- Modify: `wp-plugin/readme.txt` (one FAQ answer)

**Interfaces:**
- Consumes: `McpHandler::handle()` (`tools/list`), `LayoutGate::check()`, `DryRun::preview()`, `fixtures/valid/*.json`.
- Produces: `docs/token-usage.md` with measured sizes and estimated tokens (bytes / 4); the readme FAQ quotes only those numbers.

- [ ] **Step 1: Write the script**

Create `scripts/measure-tokens.php`:

```php
<?php

declare(strict_types=1);

/**
 * DEV-ONLY (not part of the plugin zip). Measures how much of an AI assistant's context the plugin's own
 * answers use, and writes docs/token-usage.md. Validation itself runs in PHP and costs no tokens; what costs
 * tokens is what the assistant has to read. Tokens are ESTIMATED as bytes / 4 (a common rule of thumb for
 * English text and JSON; the real count depends on the model).
 *
 * Run: php scripts/measure-tokens.php
 */

require __DIR__ . '/../tests/bootstrap.php';
require_once __DIR__ . '/../tests/FakeWpdb.php';
require_once __DIR__ . '/../tests/WpRestShims.php';
foreach ( glob( __DIR__ . '/../wp-plugin/src/*.php' ) ?: [] as $file ) {
    if ( in_array( basename( $file ), [ 'autoload.php', 'index.php', 'AdminPage.php' ], true ) ) {
        continue; // the loader, the directory guard and the admin screen are not needed to answer tool calls
    }
    require_once $file;
}

use AiEditorDivi5\WP\DryRun;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\McpHandler;

$est  = static fn ( int $bytes ): int => (int) ceil( $bytes / 4 );
$rows = [];
$add  = static function ( string $what, string $detail, string $text ) use ( &$rows, $est ): void {
    $bytes  = strlen( $text );
    $rows[] = [ $what, $detail, $bytes, $est( $bytes ) ];
};

// 1. What the assistant loads once per conversation: the tool definitions.
$list = ( new McpHandler() )->handle( new WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ] ) )->get_data();
$tools = (array) $list['result']['tools'];
$add( 'Tool definitions', count( $tools ) . ' tools, loaded once per conversation (free plugin only)', (string) json_encode( $list['result'] ) );

// 2. Reading a page: the layout the assistant gets back from get_page_layout (smallest, median, largest real fixture).
$sizes = [];
foreach ( glob( __DIR__ . '/../fixtures/valid/page-*.json' ) ?: [] as $f ) {
    $content = (string) ( json_decode( (string) file_get_contents( $f ), true )['post_content'] ?? '' );
    if ( '' !== $content ) {
        $sizes[ basename( $f ) ] = $content;
    }
}
uasort( $sizes, static fn ( string $a, string $b ): int => strlen( $a ) <=> strlen( $b ) );
$names  = array_keys( $sizes );
$median = $names[ intdiv( count( $names ), 2 ) ];
foreach ( [ 'smallest' => $names[0], 'median' => $median, 'largest' => $names[ count( $names ) - 1 ] ] as $label => $name ) {
    $add( "Reading a page ({$label})", 'the layout get_page_layout returns, from ' . $name, $sizes[ $name ] );
}

// 3. A refusal: what the assistant reads when the checker says no.
$small   = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
$refusal = static function ( string $content ): array {
    $r = LayoutGate::check( $content );

    return [ count( $r->violations() ), (string) json_encode( [ 'saved' => false, 'valid' => false, 'violations' => array_map( static fn ( $v ) => $v->toArray(), $r->violations() ) ] ) ];
};
[ $n, $body ] = $refusal( str_replace( 'divi/shop', 'divi/not-a-module', $small ) );
$add( 'A refusal: one mistake', "{$n} violation, e.g. an unknown block type", $body );
[ $n, $body ] = $refusal( str_replace( '"builderVersion"', '"builderVers1on"', $sizes[ $median ] ) );
$add( 'A refusal: every block wrong (worst case)', "{$n} violations on the median page", $body );

// 4. A preview (dry run): the diff the assistant reads before a save in approval mode.
$changed = (string) preg_replace( '/"value":"([^"]{3,})"/', '"value":"Changed text"', $sizes[ $median ], 1 );
$add( 'A preview (dry run) of a one-line text change', 'verdict plus a capped diff, on the median page', (string) json_encode( DryRun::preview( $sizes[ $median ], $changed ) ) );

// ---- report ------------------------------------------------------------------------------------------------
$md  = "# Token usage of the AI Editor for Divi 5 (free plugin)\n\n";
$md .= 'Measured ' . date( 'Y-m-d' ) . " by `scripts/measure-tokens.php`. Tokens are ESTIMATED as bytes / 4; the real count depends on the model.\n\n";
$md .= "Checking a layout runs in PHP on your server and costs **0 tokens**. What costs tokens is what the assistant has to read.\n\n";
$md .= "| What | Detail | Bytes | Approx. tokens |\n| --- | --- | ---: | ---: |\n";
foreach ( $rows as [ $what, $detail, $bytes, $tokens ] ) {
    $md .= "| {$what} | {$detail} | " . number_format( $bytes ) . ' | ' . number_format( $tokens ) . " |\n";
}
$md .= "\nThe Pro add-on adds its own tool definitions on top of the first row.\n";
file_put_contents( __DIR__ . '/../docs/token-usage.md', $md );
echo $md;
```

- [ ] **Step 2: Run it**

Run: `php scripts/measure-tokens.php`
Expected: the table prints and `docs/token-usage.md` is written. If a required file fatals (for example a class that needs a missing shim), add that file's basename to the exclusion list in the `in_array(...)` call with a one-line reason; do not stub plugin code.

- [ ] **Step 3: Put the measured numbers into the readme FAQ**

In `wp-plugin/readme.txt`, replace the answer to `= Does the checking use up my AI tokens? =` with (fill the three bracketed numbers from the table you just generated, rounded to the nearest hundred tokens; keep the word "about"):

```
No. The check runs in PHP on your own server. Your assistant only reads what the plugin sends back: the tool list it loads once per conversation (about [TOOLS_TOKENS] tokens), the page it is editing, and a short list of error codes when a layout is refused (about [REFUSAL_TOKENS] tokens for a typical mistake). These are estimates; the real count depends on the model. The measured figures are listed in the plugin's documentation on divi5lab.com.
```

Do not write that URL claim if `docs/token-usage.md` has not been published there: delete the last sentence in that case and mention nothing about where the numbers live. Replace `[TOOLS_TOKENS]` and `[REFUSAL_TOKENS]` with real numbers; the plan is not complete while a bracket remains.

- [ ] **Step 4: Check the readme still passes the compliance test**

Run: `vendor/bin/phpunit tests/WpOrgComplianceTest.php`
Expected: `OK`.

- [ ] **Step 5: Commit**

```bash
git add scripts/measure-tokens.php docs/token-usage.md wp-plugin/readme.txt
git commit -m "docs: measured token numbers for the free plugin

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Live check, release prep (4.6.0) and docs

**Files:**
- Create: `scripts/verify-site-structure.php`
- Modify: `Makefile`, `wp-plugin/jhmg-ai-editor-for-divi-5.php`, `wp-plugin/readme.txt`, `tests/WpOrgNamingTest.php`, `CLAUDE.md`, `docs/feature-requests-2026-10-04.md`
- Rebuild: `jhmg-ai-editor-for-divi-5.zip`

**Interfaces:**
- Consumes: everything above, on the real Docker site (`make up`; plugin active; WP-CLI container `wpcli`).

- [ ] **Step 1: Write the live check**

Create `scripts/verify-site-structure.php`:

```php
<?php
/**
 * Real-site check for get_site_structure, approval mode and the MCP protocol fixes. The unit tests cover
 * the logic against shims; this drives the REAL REST and MCP handlers on the Docker site.
 *
 * Run: make verify-site-structure   (needs `make up` and the plugin active on the dev site)
 * It creates throwaway draft pages, restores the approval-mode setting, and deletes the pages again.
 */

use AiEditorDivi5\WP\ApprovalMode;
use AiEditorDivi5\WP\SiteStructure;

if ( ! class_exists( SiteStructure::class ) || ! class_exists( ApprovalMode::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (4.6.0 or later) on this site first.' );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$layout = static function ( string $title ): string {
    $attrs = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $title ] ] ], 'builderVersion' => '5.14.0' ];

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading ' . serialize_block_attributes( $attrs ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
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
/** One JSON-RPC call to the MCP endpoint with the plugin's API key; returns the raw response object. */
$rpc = static function ( string $method, array $params = [] ): WP_REST_Response {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params ] ) );
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    $res                           = rest_do_request( $req );
    unset( $_SERVER['HTTP_AUTHORIZATION'] );
    wp_set_current_user( 1 ); // authenticateRequest() switched to the key owner; back to --user=1 for the REST calls

    return $res;
};
$mcp = static function ( string $tool, array $args ) use ( $rpc ): array {
    $data = (array) $rpc( 'tools/call', [ 'name' => $tool, 'arguments' => (object) $args ] )->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => $data['error'], 'isError' => true, 'result' => null ];
    }
    $text = $data['result']['content'][0]['text'] ?? '';

    return [ 'error' => null, 'isError' => (bool) ( $data['result']['isError'] ?? false ), 'result' => json_decode( (string) $text, true ) ];
};

$settingBefore = get_option( ApprovalMode::OPTION, null );
$pageId        = 0;

try {
    // ---- 1. MCP protocol fixes ---------------------------------------------------------------------
    $ping = $rpc( 'ping' );
    $check( 200 === $ping->get_status() && is_object( ( (array) $ping->get_data() )['result'] ?? null ), 'ping answers with an empty object, not a list' );
    $note = $rpc( 'notifications/initialized' );
    $check( 202 === $note->get_status(), 'a notification is accepted with 202' );

    // ---- 2. get_site_structure ----------------------------------------------------------------------
    $r = $rest( 'GET', '/site-structure' );
    $check( 200 === $r['status'], 'REST GET /site-structure answers 200' );
    $check( in_array( 'page', array_column( $r['data']['post_types'] ?? [], 'name' ), true ), 'the page post type is listed' );
    $check( isset( $r['data']['image_sizes'][0]['width'] ), 'image sizes are listed with a width' );
    $check( isset( $r['data']['acf']['active'] ), 'the ACF section reports whether ACF is active (' . ( ! empty( $r['data']['acf']['active'] ) ? 'active' : 'not active' ) . ')' );
    $m = $mcp( 'get_site_structure', [] );
    $check( null === $m['error'] && ! $m['isError'] && ( $m['result']['post_types'] ?? null ) === $r['data']['post_types'], 'MCP returns the same post types as REST' );
    $only = $mcp( 'get_site_structure', [ 'include' => [ 'image_sizes' ] ] );
    $check( isset( $only['result']['image_sizes'] ) && ! isset( $only['result']['post_types'] ), 'include limits the sections' );
    $bad = $mcp( 'get_site_structure', [ 'include' => 'nope' ] );
    $check( null !== $bad['error'] && -32602 === $bad['error']['code'], 'a bad include is rejected with -32602' );

    // ---- 3. approval mode ----------------------------------------------------------------------------
    $pageId = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'AIED verify (temporary)', 'post_content' => wp_slash( $layout( 'One' ) ) ] );
    $check( $pageId > 0, 'a throwaway draft page exists' );

    ApprovalMode::set( false );
    $two = $layout( 'Two' );
    $r   = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $two ] );
    $check( 200 === $r['status'] && true === ( $r['data']['saved'] ?? null ), 'off: a save needs no preview' );

    ApprovalMode::set( true );
    $three = $layout( 'Three' );
    $r     = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $three ] );
    $check( 428 === $r['status'] && 'PREVIEW_REQUIRED' === ( $r['data']['code'] ?? null ) && get_post( $pageId )->post_content === $two, 'on: a save without a preview is refused (428) and the page is unchanged' );
    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $three, 'dry_run' => true ] );
    $check( 200 === $r['status'] && true === ( $r['data']['valid'] ?? null ) && false === ( $r['data']['saved'] ?? null ), 'a preview works and saves nothing' );
    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $three ] );
    $check( 200 === $r['status'] && true === ( $r['data']['saved'] ?? null ) && get_post( $pageId )->post_content === $three, 'after the preview the same save goes through' );
    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $three ] );
    $check( 428 === $r['status'], 'the preview was single use: repeating the save is refused again' );

    $four = $layout( 'Four' );
    $m    = $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => $four ] );
    $check( $m['isError'] && 'PREVIEW_REQUIRED' === ( $m['result']['code'] ?? null ), 'MCP update: refused without a preview' );
    $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => $four, 'dry_run' => true ] );
    $m = $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => $four ] );
    $check( ! $m['isError'] && true === ( $m['result']['saved'] ?? null ), 'MCP update: saved after the preview' );

    $edit = [ 'page_id' => $pageId, 'find' => 'Four', 'replace' => 'Five' ];
    $m    = $mcp( 'edit_page_content', $edit );
    $check( $m['isError'] && 'PREVIEW_REQUIRED' === ( $m['result']['code'] ?? null ), 'MCP edit: refused without a preview' );
    $mcp( 'edit_page_content', $edit + [ 'dry_run' => true ] );
    $m = $mcp( 'edit_page_content', $edit );
    $check( ! $m['isError'] && true === ( $m['result']['saved'] ?? null ) && str_contains( get_post( $pageId )->post_content, 'Five' ), 'MCP edit: saved after the preview' );

    $c = $rest( 'POST', '/pages', [ 'title' => 'AIED verify created (temporary)', 'post_content' => $layout( 'Created' ) ] );
    $check( 201 === $c['status'], 'create_page is exempt: a new draft needs no preview' );

    // ---- 4. usage log ----------------------------------------------------------------------------------
    global $wpdb;
    $usage = \AiEditorDivi5\WP\UsageTracker::tableName();
    $n     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$usage} WHERE endpoint = %s AND result = %s", 'site_structure', 'read' ) ); // phpcs:ignore
    $check( $n >= 3, "site_structure reads appear in the usage log ({$n})" );
} catch ( \Throwable $e ) {
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    if ( null === $settingBefore ) {
        delete_option( ApprovalMode::OPTION );
    } else {
        update_option( ApprovalMode::OPTION, $settingBefore, false );
    }
    $temp = get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => 'AIED verify' ] );
    foreach ( $temp as $id ) {
        wp_delete_post( (int) $id, true );
    }
}

$check( get_option( ApprovalMode::OPTION, null ) === $settingBefore, 'the approval-mode setting is back to what it was' );
$check( [] === get_posts( [ 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 's' => 'AIED verify' ] ), 'the throwaway pages are gone' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'get_site_structure, approval mode and the protocol fixes verified on the real REST and MCP handlers.' );
```

In `Makefile`: append ` verify-site-structure` to the `.PHONY` line, and add after the `verify-global-styles` target:

```make
# ---------------------------------------------------------------
# verify-site-structure — real-site check of get_site_structure, approval mode and the MCP protocol fixes (Docker site; two throwaway draft pages; restores the setting)
# ---------------------------------------------------------------
verify-site-structure:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-site-structure.php --user=1
```

- [ ] **Step 2: Run the live check**

Run: `make verify-site-structure`
Expected: every line `PASS`, ending `Success: get_site_structure, approval mode and the protocol fixes verified ...`. The dev site must run the CURRENT dev copy of the plugin (the project's Docker setup mounts `wp-plugin/`). If a line fails, fix the cause in the plugin or script, never by deleting the check. If the Docker site is not running, say so in the report instead of claiming this step passed.

- [ ] **Step 3: Version, changelog, pins**

- `wp-plugin/jhmg-ai-editor-for-divi-5.php`: `Version:           4.5.1` to `4.6.0`, and `define('AI_EDITOR_DIVI5_VERSION', '4.5.1');` to `'4.6.0'`.
- `wp-plugin/readme.txt`: `Stable tag:        4.5.1` to `4.6.0`. Replace the whole `= 4.5.1 =` changelog entry (and add to it) with this `= 4.6.0 =` entry, placed above `= 4.5.0 =`:

```
= 4.6.0 =
* New: your AI assistant can look up how your site is set up with `get_site_structure`: custom post types, taxonomies, image sizes and, when Advanced Custom Fields is active, its field groups and fields (names, labels, types and choices; never field values). Read-only.
* New: Approval mode (Settings screen, off by default). When it is on, your AI assistant must preview every change before it can save it.
* Improved: clearer setup on the Settings screen. Claude Desktop now gets the working setup (it connects through a small Node.js helper, so the screen says Node.js is needed and what to do if Mac or Windows reports a missing `npx`), Gemini CLI has its own tab, and there is a note on which assistants can reach a local site. The Features screen says that your global colors, variables and presets are read-only.
* Fixed: the connection check (`ping`) now answers exactly as the MCP specification asks, which strict clients require.
* New: FAQ entries on safety, token use, Gemini, local sites and the Mac connection error.
```

  and replace the `= 4.5.1 =` Upgrade Notice with `= 4.6.0 =` / `Adds a read-only look-up of your site's post types, taxonomies, image sizes and ACF fields for your AI assistant, an optional Approval mode, and clearer setup instructions. Nothing changes unless your AI assistant uses it or you turn Approval mode on.`
- `tests/WpOrgNamingTest.php`: change the three `4.5.1` pins (`Version:\s+4\.5\.1`, `'4.5.1'`, `Stable tag:\s+4\.5\.1`) to `4.6.0` / `4\.6\.0`.

- [ ] **Step 4: Update the project docs**

In `CLAUDE.md` (the owner already has uncommitted edits in this file; edit only these spots and leave everything else as it is):
1. "**16 MCP tools, all free:**" → "**17 MCP tools, all free:**"; add `get_site_structure` (read-only: post types, taxonomies, image sizes, ACF field groups; `SiteStructure`; MCP and REST only) to that list; the sentence listing "`list_divi_pages`, `list_global_styles` ..." gains it.
2. In "Same logic is exposed three ways, kept in lockstep: ..." append: `Exception (owner decision 2026-10-04): get_site_structure is MCP + REST only, because the ChatGPT importer allows 30 operations and the free (16) plus Pro (14) tools already use all 30; \`SiteStructureLockstepTest\` pins the absence on purpose.`
3. Add a paragraph after "Dry run (v4.2.0): ...": `Approval mode (v4.6.0): option ai_editor_divi5_require_preview (default off, Settings card). When on, update_page_layout, edit_page_content, PUT /pages/{id} and POST /pages/{id}/edit refuse a save (MCP isError / REST 428, code PREVIEW_REQUIRED) unless the same resulting content was previewed validly by the same user for the same page in the last 30 minutes (ApprovalMode: single-use transient). create_page, restore and every Pro tool are exempt; the check sits in the four handlers, NOT in HistoryService (Pro writes through it).`
4. In "## Current state" add a top bullet: `**Free plugin 4.6.0 (built on branch feat/setup-docs-4-5-1, NOT uploaded; the owner uploads)**: get_site_structure (17th tool), approval mode, measured token numbers (docs/token-usage.md), the setup-docs batch (Claude Desktop mcp-remote snippet + Node.js notes, Gemini CLI tab, local-site note, FAQ), MCP ping/202 fixes. Spec/plan: docs/superpowers/specs|plans/2026-10-04-site-structure-and-approval-mode*. #9 (unknown third-party blocks) is parked: confirmed by spike, needs a real Divi Pixel/Plus export.` and update the tool count / "17 tools" mentions in the "WordPress.org compliance rule" paragraph ("the 16-tool list" → "the 17-tool list").
5. Add `make verify-site-structure` to the entry-points table.

In `docs/feature-requests-2026-10-04.md`: add `**Done in 4.6.0 (unreleased):**` to the Notes cell of rows 2, 3 (read-only structure tool; menus and file contents deliberately excluded), 4 (Gemini tab), 5 (approval mode) and 6 (token numbers in `docs/token-usage.md`); tick the five setup/doc checkboxes under "Setup and documentation fixes".

- [ ] **Step 5: Full verification**

Run, in order, and read the output of each:
```bash
make test 2>&1 | tail -4
bash scripts/build-plugin-zip.sh 2>&1 | tail -3
unzip -p jhmg-ai-editor-for-divi-5.zip jhmg-ai-editor-for-divi-5/readme.txt | grep -m1 "Stable tag"
unzip -l jhmg-ai-editor-for-divi-5.zip | grep -c "SiteStructure.php\|ApprovalMode.php"
diff -rq src wp-plugin/validator
```
Expected: suite `OK`; zip built; `Stable tag:        4.6.0`; count `2`; the only `diff` line is `Only in wp-plugin/validator: index.php`.

- [ ] **Step 6: Plugin Check**

Follow the project routine (`CLAUDE.md`, "The plugin build"): run Plugin Check on the dev copy and on the extracted zip in the Docker env, with `php -d memory_limit=1G` (the default 128M limit crashes on the Divi theme). Never `wp plugin delete` a test copy; remove its folder instead. Report the real result. If Plugin Check is not installed or cannot run in the Docker env, say that plainly: do NOT report it clean.

- [ ] **Step 7: Save a memory note and commit**

Update `/Users/Lucas/.claude/projects/-Users-Lucas-Documents-JHMG-Local-Divi-5-Deterministic-Validator/memory/setup-docs-4-5-1.md`: rename its focus to 4.6.0 (built, not uploaded), list what the release contains, and keep the #9 parked note. Then:

```bash
git add scripts/verify-site-structure.php Makefile wp-plugin tests docs jhmg-ai-editor-for-divi-5.zip
git commit -m "release: free plugin 4.6.0 (site structure tool, approval mode, protocol fixes, setup docs)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

Leave `CLAUDE.md` unstaged unless the owner says to commit it. Do NOT push, and do NOT touch SVN or WordPress.org: the owner uploads.

---

## Self-review (done while writing)

- **Spec coverage:** section 1 (tool, readers, caps, auth, wiring, ChatGPT exception, compliance edits) = Tasks 2-3; section 2 (setting, behaviour, mechanism, exemptions, helper placement) = Tasks 4-6; section 3 (token numbers) = Task 7; section 4 (ping, 202) = Task 1; testing list = per-task tests plus Task 8's live check and Plugin Check; release (4.6.0, merged changelog) = Task 8. The spec's "Risks" (transient eviction, choices, menus excluded) are covered by tests and the Global Constraints.
- **Placeholders:** the only deliberate fill-ins are the two token numbers in Task 7 Step 3, with an explicit "not complete while a bracket remains" rule because the values only exist after the script runs.
- **Types:** `parseInclude` returns `?list<string>` and is consumed as such in both handlers; `ApprovalMode::consume/recordPreview/refusal` names and signatures match across Tasks 4-6; the preview `valid` key comes from `DryRun::preview()`; REST 428 and MCP `isError` match the spec.
- **Known pre-existing wrinkle:** `readme.txt` still lists "ChatGPT, through a custom GPT Action" under compatible assistants; Task 3 states in the tool bullet and the FAQ that the new tool is not in the ChatGPT Action, which keeps the readme honest.
