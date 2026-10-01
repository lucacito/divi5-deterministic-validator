# Edit Existing Header/Footer (Theme Builder Layouts) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an AI client read, preview (`dry_run`), edit, undo and restore the content of the site's **existing, live** Theme Builder header and footer layouts through the page tools it already uses, discover them with one new read-only tool (`list_theme_layouts`), and never create, delete, assign or publish templates. Ships as 4.3.0 on its own branch (**not merged to `main`**, see Global Constraints).

**Architecture:** One resolver, `EditTarget::resolve($id)`, replaces the nine hard-coded "is it a page" checks (4 MCP handlers, 4 REST handlers, the Dashboard restore): a page behaves exactly as today; a header/footer layout is allowed only if it is used by a template of the LIVE Theme Builder (read by `ThemeLayouts` through Divi's own guarded read functions, never creating anything) and the caller has `edit_post` + `edit_others_posts`; every other post is "not found". `HistoryService` clears Divi's caches after a layout write. Everything else (validator gate, `dry_run`, snapshots, restore, history table) is reused unchanged.

**Tech Stack:** PHP 8.1+, WordPress REST API, PHPUnit, Docker WP + wp-cli (real-site check).

**Spec:** `docs/superpowers/specs/2026-10-01-theme-layouts-design.md` (approved by the owner 2026-10-01).

## Plan refinements found while writing (tell the owner at handoff)

1. `list_theme_layouts` omits a template's `header`/`footer`/`body` key when that template has no usable layout of that kind (instead of returning `null`), so the OpenAPI schema needs no nullable references.
2. `HistoryService::recent()` rows gain an additive `kind` (`page`/`header`/`footer`) so the Dashboard can label layout edits.
3. Layout payloads (REST layout envelope, MCP `get_page_layout`) gain an additive `post_type` field.

## Global Constraints

- PHP `>= 8.1`, WordPress `>= 6.0`; `declare(strict_types=1)` and the `ABSPATH` guard in every new plugin file; no `mb_*`.
- **Only live header and footer layouts are editable.** A layout is editable iff: its post type is `et_header_layout` / `et_footer_layout`, its status is `publish`, its id is referenced by `_et_header_layout_id` / `_et_footer_layout_id` of an `et_template` post (status `publish`) that belongs to the LIVE Theme Builder, AND the user has `edit_post` on it AND `edit_others_posts` AND Divi's Role Editor allows them the Theme Builder (`ThemeLayouts::userMay()`: `current_user_can( 'edit_others_posts' ) && function_exists( 'et_pb_is_allowed' ) && et_pb_is_allowed( 'theme_builder' )` — fails closed when the Divi function is missing; Divi grants `theme_builder` to administrators only by default). Body layouts (`et_body_layout`), templates (`et_template`), the Theme Builder post, orphaned layouts, layouts used only by a draft Theme Builder, and every other post type are reported exactly like a missing page (`Page N not found.` / 404 / -32602). Pages keep their current rule (`edit_post`) and messages byte for byte.
- **Divi's Theme Builder functions are only READ, and never create.** `et_theme_builder_get_theme_builder_post_id( true, false )` — the second argument `false` is mandatory (the default creates a Theme Builder post) — then `et_theme_builder_get_theme_builder_template_ids( true, $tbId )`. Both are `function_exists`-guarded; if either is missing the live set is empty. The only Divi functions that may be *called for effect* are the two cache clears in `DiviCompat::clearLayoutCaches()`: `et_theme_builder_clear_wp_cache( 'all' )` and `ET_Core_PageResource::remove_static_resources( 'all', 'all' )`, guarded by `function_exists` / `class_exists`.
- **Permission (added after the Task 1 review):** `ThemeLayouts::userMay()` is the one definition of "may this user touch Theme Builder content" and is used by `EditTarget`, `ThemeLayouts::listing()`'s `editable` flags and both `list_theme_layouts` handlers.
- **Never in the free plugin:** creating, deleting, duplicating or publishing templates or the Theme Builder; writing `_et_use_on`, `_et_exclude_from`, `_et_default`, `_et_enabled`, any `*_layout_id` / `*_layout_enabled`, or `_et_template`; touching Divi options. The only writes to layout posts are `post_content` through `HistoryService::write()/restore()` (the existing validated, snapshotted path).
- The validator in `src/` and `wp-plugin/validator/` is NOT touched. `PageHistory`, `HistoryStore`, `HistoryTable`, `DryRun`, `LayoutDiff` and the response shapes of existing tools are unchanged except the three additive fields above.
- Tool count 15 → **16** (`list_theme_layouts`), OpenAPI operations 15 → **16**. `tests/WpOrgComplianceTest.php`'s scanners are never weakened; the ONLY edits allowed there are its pinned tool list (+`list_theme_layouts`) and count (16), each justified (a new read-only tool). The readme must keep passing its checks: it may not contain `menu`, `front page`, `site-level tools`, `site tools` anywhere except inside the exact FAQ sentence `They cannot install plugins, change users, settings, menus or the front page, or run PHP or other server-side code.`; and none of `upgrade`, `premium`, `Pro` (standalone), `licence key`, `unlock`, `locked`, `upgrade to`, `license key`. In `wp-plugin/` text files never use `upgrade`, `premium`, `Pro` (standalone), `licence key`, `update_plugins`, `Licensing`, `PREMIUM`.
- OpenAPI (3.1.0): every operation description ≤ 300 characters; no response object schema without `properties`/`additionalProperties`.
- Branch `feat/theme-layouts` in the main checkout (the Docker site mounts `./wp-plugin` from it). **Do NOT merge to `main`** and never push/upload/send anything: `main` (4.2.0) stays the upload candidate and this release is held (spec "Release"). Every commit message ends with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Container wp-cli: `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp ...` (128M default kills plain `wp` in Divi). The MCP route accepts only the plugin API key as a Bearer header (a logged-in user gets 401); the REST routes accept a logged-in user. Never `wp plugin delete` on the dev site.
- Run the suite with `vendor/bin/phpunit` (`failOnWarning`/`failOnRisky`).

## Review Focus

1. **Nothing but live header/footer layouts and pages is ever readable or writable** through any tool or the Dashboard restore: orphan layouts, draft-Theme-Builder-only layouts, body layouts, templates, the Theme Builder post, ordinary posts, trashed/draft layouts are all "not found". (Task 1 `EditTarget` tests; Task 2 handler tests on all entry points; Task 4 real-site refusals.)
2. **Listing or resolving never creates a Theme Builder post** (`$create = false`). (Task 1 asserts the exact Divi call arguments; Task 4 counts Theme Builder posts before/after.)
3. **A caller without `edit_others_posts` is refused for layouts but nothing changes for pages.** (Task 1, Task 2, Task 4 with a `user_has_cap` filter.)
4. **Writing a layout never touches template assignment/enabled meta or the Theme Builder's template list.** (Task 4 snapshots every template meta and `_et_template` before/after.)
5. **Divi missing or different degrades safely**: no fatal, empty listing, header/footer ids refused. (Task 1 seam tests.)
6. **Existing page behaviour is unchanged** (messages, status codes, history, `dry_run`). (The existing suite plus Task 2 regression cases.)

---

## File Structure

| File | Responsibility |
|---|---|
| `wp-plugin/src/ThemeLayouts.php` (new) | Read-only view of the live Theme Builder: live template ids (guarded Divi reads), templates with their layouts, the id→kind map of editable layouts, the tool's listing |
| `wp-plugin/src/EditTarget.php` (new) | The single "which post may the plugin read/edit" rule |
| `wp-plugin/src/DiviCompat.php` (edit) | `clearLayoutCaches()` |
| `wp-plugin/src/HistoryService.php` (edit) | Cache clear after a layout write/restore; `kind` in `recent()` |
| `wp-plugin/src/McpHandler.php`, `RestController.php`, `AdminPage.php` (edit) | Use `EditTarget` at the 9 sites; `list_theme_layouts` tool/route; `post_type` in layout payloads; Dashboard label |
| `wp-plugin/src/OpenApiSpec.php` (edit) | `listThemeLayouts` operation + `ThemeTemplate`/`ThemeLayoutRef` schemas |
| `wp-plugin/src/StyleGuide.php`, `SiteGuide.php` (edit) | Header/footer guidance |
| `wp-plugin/src/autoload.php` (edit) | Require the two new classes |
| `tests/DiviThemeBuilderShims.php` (new), `tests/WpRestShims.php` (edit) | Divi function stand-ins; per-capability shim |
| `tests/ThemeLayoutsTest.php`, `tests/EditTargetTest.php`, `tests/ThemeLayoutHandlersTest.php`, `tests/ThemeLayoutsLockstepTest.php` (new) | Unit, handler and lockstep tests |
| `tests/WpOrgComplianceTest.php`, `tests/DryRunLockstepTest.php` (edit) | Pinned tool/operation counts |
| `scripts/verify-theme-layouts.php` (new), `Makefile` (edit) | Real-site check, `make verify-theme-layouts` |
| `wp-plugin/readme.txt`, `jhmg-ai-editor-for-divi-5.php`, `tests/WpOrgNamingTest.php`, `CLAUDE.md`, `jhmg-ai-editor-for-divi-5.zip` | 4.3.0 release prep |

---

### Task 1: `ThemeLayouts`, `EditTarget`, cache clear and shims (no handler changes yet)

**Files:**
- Create: `wp-plugin/src/ThemeLayouts.php`, `wp-plugin/src/EditTarget.php`, `tests/DiviThemeBuilderShims.php`, `tests/ThemeLayoutsTest.php`, `tests/EditTargetTest.php`
- Modify: `wp-plugin/src/DiviCompat.php`, `wp-plugin/src/HistoryService.php`, `wp-plugin/src/autoload.php`, `tests/WpRestShims.php`, `tests/HistoryServiceTest.php`, `tests/HistoryMigrationTest.php`, `tests/DryRunHandlersTest.php` (only their `require_once` lines)

**Interfaces:**
- Produces: `ThemeLayouts::HEADER = 'et_header_layout'`, `FOOTER = 'et_footer_layout'`, `BODY = 'et_body_layout'`; `kindOf(string $postType): ?string` (`'header'|'footer'|null`); `liveTemplateIdsWith(?callable $postIdFn, ?callable $idsFn): list<int>`; `liveTemplateIds(): list<int>`; `liveTemplates(): list<array>` (each `['template_id'=>int,'title'=>string,'default'=>bool,'enabled'=>bool,'use_on'=>list<string>,'exclude_from'=>list<string>,'header'=>?array,'footer'=>?array,'body'=>?array]` where a layout is `['layout_id'=>int,'title'=>string,'enabled'=>bool]`); `liveLayoutKinds(): array<int,string>` (layout id → `'header'|'footer'`); `listing(): array{templates: list<array>, count:int, note: ?string}` (adds `editable` per layout for the current user and omits absent layouts).
- Produces: `EditTarget::NOT_FOUND = 'not_found'`, `EditTarget::FORBIDDEN = 'forbidden'`, `EditTarget::resolve(int $id): array{post: ?\WP_Post, kind: ?string, error: ?string}` (`kind` is `'page'|'header'|'footer'`).
- Produces: `DiviCompat::clearLayoutCaches(): void`; `HistoryService::write()/restore()` call it after a successful write to a header/footer layout; `HistoryService::recent()` rows gain `kind`.

- [ ] **Step 1: Create the branch**

```bash
cd "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator"
git checkout -b feat/theme-layouts
```

- [ ] **Step 2: Test infrastructure**

Create `tests/DiviThemeBuilderShims.php`:

```php
<?php

declare(strict_types=1);

/**
 * Stand-ins for the few Divi Theme Builder functions the plugin reads, driven by
 *   $GLOBALS['__divi_tb'] = [ 'tb_id' => int, 'draft_id' => int, 'template_ids' => int[], 'draft_template_ids' => int[] ]
 * and recording every call. Required only by tests that need them; guarded like every other shim.
 */
if ( ! function_exists( 'et_theme_builder_get_theme_builder_post_id' ) ) {
    function et_theme_builder_get_theme_builder_post_id( $live, $create = true ) {
        $GLOBALS['__divi_tb_calls'][] = [ 'post_id', (bool) $live, (bool) $create ];

        return (int) ( $GLOBALS['__divi_tb'][ $live ? 'tb_id' : 'draft_id' ] ?? 0 );
    }
}
if ( ! function_exists( 'et_theme_builder_get_theme_builder_template_ids' ) ) {
    function et_theme_builder_get_theme_builder_template_ids( $live, $theme_builder_id = 0 ) {
        $GLOBALS['__divi_tb_calls'][] = [ 'template_ids', (bool) $live, (int) $theme_builder_id ];

        return (array) ( $GLOBALS['__divi_tb'][ $live ? 'template_ids' : 'draft_template_ids' ] ?? [] );
    }
}
if ( ! function_exists( 'et_theme_builder_clear_wp_cache' ) ) {
    function et_theme_builder_clear_wp_cache( $which = 'all' ) {
        $GLOBALS['__divi_cache_clears'][] = $which;
    }
}
if ( ! class_exists( 'ET_Core_PageResource' ) ) {
    class ET_Core_PageResource {
        public static function remove_static_resources( $a, $b ) {
            $GLOBALS['__divi_static_clears'][] = [ $a, $b ];
        }
    }
}
```

In `tests/WpRestShims.php` replace the `current_user_can` shim with a version that supports a per-capability map (default behaviour is unchanged):

```php
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $cap, ...$args ) {
        if ( isset( $GLOBALS['__wp_caps'] ) && is_array( $GLOBALS['__wp_caps'] ) ) {
            return (bool) ( $GLOBALS['__wp_caps'][ $cap ] ?? false ); // per-capability map: anything not listed is denied
        }

        return $GLOBALS['__wp_can'] ?? true;
    }
}
```

- [ ] **Step 3: Write the failing tests**

Create `tests/ThemeLayoutsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ThemeLayouts;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';
require_once __DIR__ . '/../wp-plugin/src/ThemeLayouts.php';

class ThemeLayoutsTest extends TestCase
{
    /** @param array<string,mixed> $meta */
    private function post( int $id, string $type, string $status = 'publish', string $title = '', array $meta = [] ): void
    {
        $p              = new \WP_Post();
        $p->ID          = $id;
        $p->post_type   = $type;
        $p->post_status = $status;
        $p->post_title  = $title;
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    protected function setUp(): void
    {
        $GLOBALS['__wp_posts']      = [];
        $GLOBALS['__wp_postmeta']   = [];
        $GLOBALS['__divi_tb']       = [ 'tb_id' => 100, 'draft_id' => 0, 'template_ids' => [ 200, 210 ], 'draft_template_ids' => [] ];
        $GLOBALS['__divi_tb_calls'] = [];
        $GLOBALS['__wp_can']        = true;
        unset( $GLOBALS['__wp_caps'] );

        $this->post( 100, 'et_theme_builder', 'publish', 'Theme Builder' );
        $this->post( 200, 'et_template', 'publish', 'Everything', [
            '_et_default' => '1', '_et_enabled' => '1',
            '_et_use_on' => [ 'singular:post_type:post:all' ], '_et_exclude_from' => [],
            '_et_header_layout_id' => 201, '_et_header_layout_enabled' => '1',
            '_et_footer_layout_id' => 202, '_et_footer_layout_enabled' => '0',
            '_et_body_layout_id' => 203,   '_et_body_layout_enabled' => '1',
        ] );
        $this->post( 201, 'et_header_layout', 'publish', 'Main Header' );
        $this->post( 202, 'et_footer_layout', 'publish', 'Main Footer' );
        $this->post( 203, 'et_body_layout', 'publish', 'Body' );
        $this->post( 210, 'et_template', 'publish', 'Shop', [
            '_et_default' => '0', '_et_enabled' => '1', '_et_use_on' => [ 'archive:taxonomy:product_cat:all' ],
            '_et_header_layout_id' => 211, '_et_header_layout_enabled' => '1',
            '_et_footer_layout_id' => 0, '_et_body_layout_id' => 0,
        ] );
        $this->post( 211, 'et_header_layout', 'publish', 'Shop Header' );
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [];
        unset( $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can'] = true;
    }

    public function testKindOfMapsOnlyHeaderAndFooter(): void
    {
        $this->assertSame( 'header', ThemeLayouts::kindOf( 'et_header_layout' ) );
        $this->assertSame( 'footer', ThemeLayouts::kindOf( 'et_footer_layout' ) );
        foreach ( [ 'et_body_layout', 'et_template', 'et_theme_builder', 'page', 'post', '' ] as $other ) {
            $this->assertNull( ThemeLayouts::kindOf( $other ), $other );
        }
    }

    public function testWithoutDiviFunctionsTheLiveSetIsEmpty(): void
    {
        $this->assertSame( [], ThemeLayouts::liveTemplateIdsWith( null, null ) );
        $this->assertSame( [], ThemeLayouts::liveTemplateIdsWith( static fn () => 100, null ) );
        $this->assertSame( [], ThemeLayouts::liveTemplateIdsWith( null, static fn () => [ 200 ] ) );
    }

    public function testTheThemeBuilderPostIsAskedForWithCreateFalseAndNeverCreated(): void
    {
        $seen = [];
        $ids  = ThemeLayouts::liveTemplateIdsWith(
            static function ( $live, $create ) use ( &$seen ) { $seen[] = [ 'post_id', $live, $create ]; return 100; },
            static function ( $live, $tb ) use ( &$seen ) { $seen[] = [ 'ids', $live, $tb ]; return [ '200', 210, 0, 'x' ]; }
        );
        $this->assertSame( [ [ 'post_id', true, false ], [ 'ids', true, 100 ] ], $seen );
        $this->assertSame( [ 200, 210 ], $ids );

        ThemeLayouts::liveTemplateIds();
        foreach ( $GLOBALS['__divi_tb_calls'] as $call ) {
            if ( 'post_id' === $call[0] ) {
                $this->assertTrue( $call[1], 'the LIVE Theme Builder is the one read' );
                $this->assertFalse( $call[2], 'create must be false: reading must never create a Theme Builder post' );
            }
        }
        $this->assertNotEmpty( $GLOBALS['__divi_tb_calls'] );
    }

    public function testNoLiveThemeBuilderMeansNothing(): void
    {
        $GLOBALS['__divi_tb']['tb_id'] = 0;
        $this->assertSame( [], ThemeLayouts::liveTemplateIds() );
        $this->assertSame( [], ThemeLayouts::liveTemplates() );
        $this->assertSame( [], ThemeLayouts::liveLayoutKinds() );
        $l = ThemeLayouts::listing();
        $this->assertSame( 0, $l['count'] );
        $this->assertSame( [], $l['templates'] );
        $this->assertStringContainsString( 'No live', (string) $l['note'] );
    }

    public function testLiveTemplatesCarryTheVerifiedMetaAndTheirLayouts(): void
    {
        $t = ThemeLayouts::liveTemplates();
        $this->assertCount( 2, $t );
        $this->assertSame( [
            'template_id'  => 200,
            'title'        => 'Everything',
            'default'      => true,
            'enabled'      => true,
            'use_on'       => [ 'singular:post_type:post:all' ],
            'exclude_from' => [],
            'header'       => [ 'layout_id' => 201, 'title' => 'Main Header', 'enabled' => true ],
            'footer'       => [ 'layout_id' => 202, 'title' => 'Main Footer', 'enabled' => false ],
            'body'         => [ 'layout_id' => 203, 'title' => 'Body', 'enabled' => true ],
        ], $t[0] );
        $this->assertFalse( $t[1]['default'] );
        $this->assertNull( $t[1]['footer'] );
        $this->assertNull( $t[1]['body'] );
        $this->assertSame( 211, $t[1]['header']['layout_id'] );
    }

    public function testOnlyHeadersAndFootersOfLiveTemplatesAreEditableKinds(): void
    {
        $this->assertSame( [ 201 => 'header', 202 => 'footer', 211 => 'header' ], ThemeLayouts::liveLayoutKinds() );
    }

    public function testBrokenReferencesAreIgnored(): void
    {
        $this->post( 220, 'et_template', 'publish', 'Broken', [
            '_et_header_layout_id' => 5,   // a page, not a header layout
            '_et_footer_layout_id' => 221, // a trashed footer
            '_et_body_layout_id' => 9999,  // does not exist
        ] );
        $this->post( 5, 'page', 'publish', 'A page' );
        $this->post( 221, 'et_footer_layout', 'trash', 'Gone' );
        $this->post( 230, 'page', 'publish', 'Not a template' );
        $this->post( 231, 'et_template', 'draft', 'Unpublished template', [ '_et_header_layout_id' => 232 ] );
        $this->post( 232, 'et_header_layout', 'publish', 'Header of a draft template' );
        $GLOBALS['__divi_tb']['template_ids'] = [ 200, 220, 230, 231, 404 ];

        $kinds = ThemeLayouts::liveLayoutKinds();
        $this->assertSame( [ 201 => 'header', 202 => 'footer' ], $kinds );
        $templates = ThemeLayouts::liveTemplates();
        $this->assertSame( [ 200, 220 ], array_column( $templates, 'template_id' ), 'non-templates, unpublished templates and missing ids are skipped' );
        $this->assertNull( $templates[1]['header'] );
        $this->assertNull( $templates[1]['footer'] );
        $this->assertNull( $templates[1]['body'] );
    }

    public function testLayoutsOfADraftThemeBuilderAreNotEditable(): void
    {
        $this->post( 300, 'et_theme_builder', 'auto-draft', 'Draft Theme Builder' );
        $this->post( 301, 'et_template', 'publish', 'Draft template', [ '_et_header_layout_id' => 302 ] );
        $this->post( 302, 'et_header_layout', 'publish', 'Draft-only header' );
        $GLOBALS['__divi_tb']['draft_id']           = 300;
        $GLOBALS['__divi_tb']['draft_template_ids'] = [ 301 ];

        $this->assertArrayNotHasKey( 302, ThemeLayouts::liveLayoutKinds() );
    }

    public function testListingMarksEditableForTheCurrentUserAndOmitsAbsentLayouts(): void
    {
        $l = ThemeLayouts::listing();
        $this->assertSame( 2, $l['count'] );
        $this->assertNull( $l['note'] );
        $first = $l['templates'][0];
        $this->assertTrue( $first['header']['editable'] );
        $this->assertTrue( $first['footer']['editable'] );
        $this->assertFalse( $first['body']['editable'], 'body layouts are listed but never editable' );
        $second = $l['templates'][1];
        $this->assertArrayNotHasKey( 'footer', $second );
        $this->assertArrayNotHasKey( 'body', $second );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => false ];
        $l = ThemeLayouts::listing();
        $this->assertFalse( $l['templates'][0]['header']['editable'] );
    }

    public function testLiveTemplatesAreNeverWrittenOrCreatedBySimplyReadingThem(): void
    {
        $before = [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        ThemeLayouts::listing();
        ThemeLayouts::liveLayoutKinds();
        $this->assertSame( $before[1], $GLOBALS['__wp_postmeta'] );
        $this->assertCount( count( $before[0] ), $GLOBALS['__wp_posts'] );
    }
}
```

Create `tests/EditTargetTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\EditTarget;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';
require_once __DIR__ . '/../wp-plugin/src/ThemeLayouts.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';

class EditTargetTest extends TestCase
{
    /** @param array<string,mixed> $meta */
    private function post( int $id, string $type, string $status = 'publish', array $meta = [] ): void
    {
        $p              = new \WP_Post();
        $p->ID          = $id;
        $p->post_type   = $type;
        $p->post_status = $status;
        $p->post_title  = "Post {$id}";
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    protected function setUp(): void
    {
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [ 'tb_id' => 100, 'draft_id' => 300, 'template_ids' => [ 200 ], 'draft_template_ids' => [ 301 ] ];
        $GLOBALS['__wp_caps']     = [ 'edit_post' => true, 'edit_others_posts' => true ];

        $this->post( 100, 'et_theme_builder' );
        $this->post( 200, 'et_template', 'publish', [ '_et_header_layout_id' => 201, '_et_footer_layout_id' => 202, '_et_body_layout_id' => 203 ] );
        $this->post( 201, 'et_header_layout' );
        $this->post( 202, 'et_footer_layout' );
        $this->post( 203, 'et_body_layout' );
        $this->post( 204, 'et_header_layout' );                       // orphan: no template uses it
        $this->post( 300, 'et_theme_builder', 'auto-draft' );
        $this->post( 301, 'et_template', 'publish', [ '_et_header_layout_id' => 302 ] );
        $this->post( 302, 'et_header_layout' );                        // used only by the draft Theme Builder
        $this->post( 5, 'page' );
        $this->post( 6, 'post' );
        $this->post( 7, 'attachment' );
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can']      = true;
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [];
    }

    public function testAPageBehavesExactlyAsBefore(): void
    {
        $r = EditTarget::resolve( 5 );
        $this->assertSame( [ 5, 'page', null ], [ $r['post']->ID, $r['kind'], $r['error'] ] );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => false, 'edit_others_posts' => true ];
        $r = EditTarget::resolve( 5 );
        $this->assertSame( EditTarget::FORBIDDEN, $r['error'] );
        $this->assertNull( $r['post'] );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => false ];
        $this->assertNull( EditTarget::resolve( 5 )['error'], 'pages never needed edit_others_posts and still do not' );
    }

    public function testALiveHeaderAndFooterAreEditable(): void
    {
        $h = EditTarget::resolve( 201 );
        $this->assertSame( [ 201, 'header', null ], [ $h['post']->ID, $h['kind'], $h['error'] ] );
        $f = EditTarget::resolve( 202 );
        $this->assertSame( [ 202, 'footer', null ], [ $f['post']->ID, $f['kind'], $f['error'] ] );
    }

    public function testALiveLayoutNeedsBothEditPostAndEditOthersPosts(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => false ];
        $this->assertSame( EditTarget::FORBIDDEN, EditTarget::resolve( 201 )['error'] );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => false, 'edit_others_posts' => true ];
        $this->assertSame( EditTarget::FORBIDDEN, EditTarget::resolve( 201 )['error'] );
        $GLOBALS['__wp_caps'] = [];
        $this->assertSame( EditTarget::FORBIDDEN, EditTarget::resolve( 202 )['error'] );
    }

    /** @return list<array{int,string}> */
    public static function neverEditable(): array
    {
        return [
            [ 203, 'a body layout' ],
            [ 204, 'an orphaned header (no live template uses it)' ],
            [ 302, 'a header used only by the draft Theme Builder' ],
            [ 200, 'a live template' ],
            [ 301, 'a draft-Theme-Builder template' ],
            [ 100, 'the live Theme Builder post' ],
            [ 300, 'the draft Theme Builder post' ],
            [ 6, 'an ordinary post' ],
            [ 7, 'an attachment' ],
            [ 9999, 'a post that does not exist' ],
            [ 0, 'id zero' ],
            [ -5, 'a negative id' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'neverEditable' )]
    public function testEverythingElseIsReportedAsNotFound( int $id, string $why ): void
    {
        $r = EditTarget::resolve( $id );
        $this->assertSame( EditTarget::NOT_FOUND, $r['error'], $why );
        $this->assertNull( $r['post'], $why );
        $this->assertNull( $r['kind'], $why );
    }

    public function testNotFoundEvenForAnAdministratorWhoCanDoEverything(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'manage_options' => true ];
        $this->assertSame( EditTarget::NOT_FOUND, EditTarget::resolve( 203 )['error'] );
        $this->assertSame( EditTarget::NOT_FOUND, EditTarget::resolve( 204 )['error'] );
    }

    public function testAnUnpublishedOrTrashedLiveLayoutIsNotFound(): void
    {
        foreach ( [ 'draft', 'trash', 'pending', 'private' ] as $status ) {
            $this->post( 201, 'et_header_layout', $status );
            $this->assertSame( EditTarget::NOT_FOUND, EditTarget::resolve( 201 )['error'], $status );
        }
    }

    public function testWithoutALiveThemeBuilderEveryLayoutIsNotFoundButPagesStillWork(): void
    {
        $GLOBALS['__divi_tb']['tb_id'] = 0;
        $this->assertSame( EditTarget::NOT_FOUND, EditTarget::resolve( 201 )['error'] );
        $this->assertNull( EditTarget::resolve( 5 )['error'] );
    }

    public function testResolvingNeverCreatesAThemeBuilderPost(): void
    {
        $GLOBALS['__divi_tb_calls'] = [];
        $postsBefore                = count( $GLOBALS['__wp_posts'] );
        EditTarget::resolve( 201 );
        EditTarget::resolve( 204 );
        $this->assertNotEmpty( $GLOBALS['__divi_tb_calls'] );
        foreach ( $GLOBALS['__divi_tb_calls'] as $call ) {
            if ( 'post_id' === $call[0] ) {
                $this->assertFalse( $call[2], 'create must always be false' );
            }
        }
        $this->assertCount( $postsBefore, $GLOBALS['__wp_posts'], 'no post was created while resolving' );
    }

    public function testAPageNeverTouchesTheThemeBuilder(): void
    {
        $GLOBALS['__divi_tb_calls'] = [];
        EditTarget::resolve( 5 );
        $this->assertSame( [], $GLOBALS['__divi_tb_calls'] );
    }
}
```

Cache-clear and `kind` tests: append these methods to `tests/HistoryServiceTest.php` (inside the class, before the final `}`) and add `require_once __DIR__ . '/DiviThemeBuilderShims.php';`, `require_once __DIR__ . '/../wp-plugin/src/ThemeLayouts.php';` and `require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';` to its top `require_once` block (the same three `require_once` lines, ThemeLayouts + DiviCompat only, go into `tests/HistoryMigrationTest.php` and `tests/DryRunHandlersTest.php` too, because `HistoryService` now references those classes):

```php
    public function testWritingAHeaderLayoutClearsDivisCachesButWritingAPageDoesNot(): void
    {
        $GLOBALS['__divi_cache_clears']  = [];
        $GLOBALS['__divi_static_clears'] = [];
        HistoryService::write( 5, 'PAGE EDIT', 'update_page_layout' );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'], 'a page save leaves Divi caches alone' );

        $h              = new \WP_Post();
        $h->ID          = 8;
        $h->post_type   = 'et_header_layout';
        $h->post_content = 'HEADER';
        $GLOBALS['__wp_posts'][8] = $h;
        $r = HistoryService::write( 8, 'HEADER EDIT', 'edit_page_content' );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'] );
        $this->assertSame( [ [ 'all', 'all' ] ], $GLOBALS['__divi_static_clears'] );

        HistoryService::restore( 8, (int) $r['snapshot']['version_id'] );
        $this->assertCount( 2, $GLOBALS['__divi_cache_clears'], 'a restore of a layout clears them too' );
    }

    public function testAFailedLayoutWriteDoesNotClearCaches(): void
    {
        $h              = new \WP_Post();
        $h->ID          = 8;
        $h->post_type   = 'et_footer_layout';
        $h->post_content = 'FOOTER';
        $GLOBALS['__wp_posts'][8]    = $h;
        $GLOBALS['__divi_cache_clears'] = [];
        $GLOBALS['__wp_update_fail']    = true;
        $r = HistoryService::write( 8, 'X', 'edit_page_content' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testRecentRowsCarryTheKindOfEachEditedPost(): void
    {
        $h              = new \WP_Post();
        $h->ID          = 8;
        $h->post_type   = 'et_header_layout';
        $h->post_title  = 'Main Header';
        $h->post_content = 'HEADER';
        $GLOBALS['__wp_posts'][8] = $h;
        HistoryService::write( 5, 'A', 'update_page_layout' );
        HistoryService::write( 8, 'B', 'edit_page_content' );
        $kinds = array_column( HistoryService::recent( 5 ), 'kind', 'page_id' );
        $this->assertSame( 'page', $kinds[5] );
        $this->assertSame( 'header', $kinds[8] );
    }
```

(`HistoryServiceTest::setUp` seeds post 5 as a plain object with `post_type => 'page'`; `HistoryService` only reads `->post_type`, so the mixed `(object)` / `\WP_Post` shapes are fine. If `WP_Post` is not yet defined when this test file runs alone, add `require_once __DIR__ . '/WpRestShims.php';` to its requires.)

- [ ] **Step 4: Run to see them fail**

Run: `vendor/bin/phpunit tests/ThemeLayoutsTest.php tests/EditTargetTest.php tests/HistoryServiceTest.php`
Expected: errors — `Class "AiEditorDivi5\WP\ThemeLayouts" not found` / `EditTarget` not found, and the three new HistoryService tests failing.

- [ ] **Step 5: Create `wp-plugin/src/ThemeLayouts.php`**

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A READ-ONLY view of the live Divi Theme Builder: which templates exist and which header, footer
 * and body layout posts they use. Nothing here writes, creates or publishes anything. The meta keys
 * are the ones Divi's own Theme Builder code stores (verified against the shipped theme).
 */
final class ThemeLayouts
{
    public const HEADER = 'et_header_layout';
    public const FOOTER = 'et_footer_layout';
    public const BODY   = 'et_body_layout';

    private const TEMPLATE = 'et_template';

    /** 'header' / 'footer' for the two editable layout post types, null for everything else. */
    public static function kindOf( string $postType ): ?string
    {
        return match ( $postType ) {
            self::HEADER => 'header',
            self::FOOTER => 'footer',
            default      => null,
        };
    }

    /**
     * Template ids of the LIVE Theme Builder, read through the two given Divi functions. Both must be
     * callable (Divi's Theme Builder present); otherwise the result is empty. The Theme Builder post is
     * requested with $create = false: reading must never create one.
     *
     * @return list<int>
     */
    public static function liveTemplateIdsWith( ?callable $postIdFn, ?callable $idsFn ): array
    {
        if ( null === $postIdFn || null === $idsFn ) {
            return [];
        }
        $themeBuilderId = (int) $postIdFn( true, false );
        if ( $themeBuilderId <= 0 ) {
            return [];
        }

        return array_values( array_filter(
            array_map( 'intval', (array) $idsFn( true, $themeBuilderId ) ),
            static fn ( int $id ): bool => $id > 0
        ) );
    }

    /** @return list<int> */
    public static function liveTemplateIds(): array
    {
        return self::liveTemplateIdsWith(
            function_exists( 'et_theme_builder_get_theme_builder_post_id' ) ? 'et_theme_builder_get_theme_builder_post_id' : null,
            function_exists( 'et_theme_builder_get_theme_builder_template_ids' ) ? 'et_theme_builder_get_theme_builder_template_ids' : null
        );
    }

    /**
     * The live templates with their layouts. A layout entry is null when the template has no usable
     * layout of that kind (id 0, missing, wrong post type or not published).
     *
     * @return list<array<string,mixed>>
     */
    public static function liveTemplates(): array
    {
        $out = [];
        foreach ( self::liveTemplateIds() as $templateId ) {
            $template = get_post( $templateId );
            if ( ! $template || self::TEMPLATE !== $template->post_type || 'publish' !== $template->post_status ) {
                continue;
            }
            $out[] = [
                'template_id'  => $templateId,
                'title'        => (string) $template->post_title,
                'default'      => '1' === (string) get_post_meta( $templateId, '_et_default', true ),
                'enabled'      => '1' === (string) get_post_meta( $templateId, '_et_enabled', true ),
                'use_on'       => self::strings( get_post_meta( $templateId, '_et_use_on', false ) ),
                'exclude_from' => self::strings( get_post_meta( $templateId, '_et_exclude_from', false ) ),
                'header'       => self::layout( $templateId, '_et_header_layout_id', '_et_header_layout_enabled', self::HEADER ),
                'footer'       => self::layout( $templateId, '_et_footer_layout_id', '_et_footer_layout_enabled', self::FOOTER ),
                'body'         => self::layout( $templateId, '_et_body_layout_id', '_et_body_layout_enabled', self::BODY ),
            ];
        }

        return $out;
    }

    /**
     * Layout id => 'header'|'footer' for every layout the plugin may edit (used by a live template).
     *
     * @return array<int,string>
     */
    public static function liveLayoutKinds(): array
    {
        $kinds = [];
        foreach ( self::liveTemplates() as $template ) {
            foreach ( [ 'header', 'footer' ] as $kind ) {
                if ( null !== $template[ $kind ] ) {
                    $kinds[ (int) $template[ $kind ]['layout_id'] ] = $kind;
                }
            }
        }

        return $kinds;
    }

    /**
     * What `list_theme_layouts` returns: absent layouts are omitted, each present layout says whether
     * the current user can edit it, and body layouts are listed but never editable.
     *
     * @return array{templates: list<array<string,mixed>>, count: int, note: ?string}
     */
    public static function listing(): array
    {
        $templates = [];
        foreach ( self::liveTemplates() as $template ) {
            foreach ( [ 'header', 'footer', 'body' ] as $kind ) {
                if ( null === $template[ $kind ] ) {
                    unset( $template[ $kind ] );
                    continue;
                }
                $template[ $kind ]['editable'] = 'body' !== $kind && current_user_can( 'edit_post', (int) $template[ $kind ]['layout_id'] );
            }
            $templates[] = $template;
        }

        return [
            'templates' => $templates,
            'count'     => count( $templates ),
            'note'      => [] === $templates ? 'No live Divi Theme Builder with templates was found, so there is no header or footer to edit.' : null,
        ];
    }

    /** @return array{layout_id:int, title:string, enabled:bool}|null */
    private static function layout( int $templateId, string $idKey, string $enabledKey, string $postType ): ?array
    {
        $layoutId = (int) get_post_meta( $templateId, $idKey, true );
        $post     = $layoutId > 0 ? get_post( $layoutId ) : null;
        if ( ! $post || $postType !== $post->post_type || 'publish' !== $post->post_status ) {
            return null;
        }

        return [
            'layout_id' => $layoutId,
            'title'     => (string) $post->post_title,
            'enabled'   => '1' === (string) get_post_meta( $templateId, $enabledKey, true ),
        ];
    }

    /** @return list<string> */
    private static function strings( mixed $raw ): array
    {
        return array_values( array_filter(
            array_map( 'strval', (array) $raw ),
            static fn ( string $s ): bool => '' !== $s
        ) );
    }
}
```

- [ ] **Step 6: Create `wp-plugin/src/EditTarget.php`**

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one rule for "which post may the plugin read and edit": a page (exactly as before) or a
 * header/footer layout that a template of the LIVE Divi Theme Builder uses. Anything else is
 * reported like a missing page, so nothing leaks about other post types.
 */
final class EditTarget
{
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';

    /**
     * @return array{post: ?\WP_Post, kind: ?string, error: ?string} kind is 'page', 'header' or 'footer'
     */
    public static function resolve( int $id ): array
    {
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post ) {
            return self::fail( self::NOT_FOUND );
        }

        if ( 'page' === $post->post_type ) {
            return current_user_can( 'edit_post', $id ) ? self::ok( $post, 'page' ) : self::fail( self::FORBIDDEN );
        }

        $kind = null === ThemeLayouts::kindOf( (string) $post->post_type ) ? null : ( ThemeLayouts::liveLayoutKinds()[ $id ] ?? null );
        if ( null === $kind || 'publish' !== $post->post_status || ThemeLayouts::kindOf( (string) $post->post_type ) !== $kind ) {
            return self::fail( self::NOT_FOUND );
        }
        // Divi's own Theme Builder needs edit_others_posts; the header/footer shows on every page.
        if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'edit_others_posts' ) ) {
            return self::fail( self::FORBIDDEN );
        }

        return self::ok( $post, $kind );
    }

    /** @return array{post: \WP_Post, kind: string, error: null} */
    private static function ok( \WP_Post $post, string $kind ): array
    {
        return [ 'post' => $post, 'kind' => $kind, 'error' => null ];
    }

    /** @return array{post: null, kind: null, error: string} */
    private static function fail( string $error ): array
    {
        return [ 'post' => null, 'kind' => null, 'error' => $error ];
    }
}
```

- [ ] **Step 7: `DiviCompat::clearLayoutCaches()` and the `HistoryService` hook**

In `wp-plugin/src/DiviCompat.php`, add this method directly after `installedVersion()`:

```php
    /**
     * After an edit to a Theme Builder layout: clear Divi's own caches the way Divi's Theme Builder
     * does when it saves (the layout is used by many pages). Each call is guarded, so nothing happens
     * when Divi (or that part of it) is not available.
     */
    public static function clearLayoutCaches(): void
    {
        if ( function_exists( 'et_theme_builder_clear_wp_cache' ) ) {
            et_theme_builder_clear_wp_cache( 'all' );
        }
        if ( class_exists( 'ET_Core_PageResource' ) ) {
            \ET_Core_PageResource::remove_static_resources( 'all', 'all' );
        }
    }
```

In `wp-plugin/src/HistoryService.php`:

(a) In `write()`, replace

```php
        if ( is_wp_error( $updated ) ) {
            return [ 'ok' => false, 'error' => 'update_failed', 'message' => $updated->get_error_message(), 'snapshot' => $snapshot ];
        }

        return [ 'ok' => true, 'snapshot' => $snapshot ];
```

with

```php
        if ( is_wp_error( $updated ) ) {
            return [ 'ok' => false, 'error' => 'update_failed', 'message' => $updated->get_error_message(), 'snapshot' => $snapshot ];
        }
        self::afterWrite( $post );

        return [ 'ok' => true, 'snapshot' => $snapshot ];
```

(b) In `restore()`, directly after the `if ( is_wp_error( $updated ) ) { return [...]; }` block that follows the restore's `wp_update_post` (and before the `$verdict = ...` line), add:

```php
        self::afterWrite( $post );
```

(c) In `recent()`, change the row construction line

```php
            $rows[] = $row + [ 'title' => $post ? (string) ( $post->post_title ?? '' ) : '' ];
```

to

```php
            $rows[] = $row + [
                'title' => $post ? (string) ( $post->post_title ?? '' ) : '',
                'kind'  => $post ? ( ThemeLayouts::kindOf( (string) $post->post_type ) ?? 'page' ) : 'page',
            ];
```

and update its docblock `@return` to `list<array{page_id:int, title:string, kind:string, version_id:int, saved_at:mixed, tool:mixed}>`.

(d) Add this private method next to `noSnapshot()`:

```php
    /** A header/footer layout appears on every page, so Divi's caches are cleared after it changes. */
    private static function afterWrite( ?object $post ): void
    {
        if ( null !== $post && null !== ThemeLayouts::kindOf( (string) $post->post_type ) ) {
            DiviCompat::clearLayoutCaches();
        }
    }
```

In `wp-plugin/src/autoload.php`, add directly after the line `require_once __DIR__ . '/DiviCompat.php';`:

```php
require_once __DIR__ . '/ThemeLayouts.php';
require_once __DIR__ . '/EditTarget.php';
```

- [ ] **Step 8: Run the tests and the whole suite**

Run: `vendor/bin/phpunit tests/ThemeLayoutsTest.php tests/EditTargetTest.php tests/HistoryServiceTest.php` → Expected: PASS.
Run: `vendor/bin/phpunit` → Expected: all green, output pristine. If any test that loads `HistoryService` fails with `Class "AiEditorDivi5\WP\ThemeLayouts" not found` (or `DiviCompat`), add the missing `require_once` lines to that test file (only the requires).

- [ ] **Step 9: Commit**

```bash
git add wp-plugin/src tests
git commit -m "feat: ThemeLayouts (read-only live Theme Builder view), EditTarget rule, layout cache clear

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Use `EditTarget` at all nine sites, `post_type` in layout payloads, Dashboard label

**Files:**
- Modify: `wp-plugin/src/McpHandler.php`, `wp-plugin/src/RestController.php`, `wp-plugin/src/AdminPage.php`
- Create: `tests/ThemeLayoutHandlersTest.php`

**Interfaces:**
- Consumes: `EditTarget::resolve()`, `EditTarget::NOT_FOUND/FORBIDDEN`, `ThemeLayouts`, `HistoryService::recent()` (`kind`).
- Produces: header/footer layout ids accepted by MCP `get_page_layout`, `update_page_layout`, `edit_page_content`, `list_page_history`, `get_page_history_entry`, `restore_page_version` and REST `GET/PUT /pages/{id}`, `POST /pages/{id}/edit`, `GET /pages/{id}/history`, `GET /pages/{id}/history/{versionId}`, `POST /pages/{id}/restore`, and the Dashboard restore.

- [ ] **Step 1: Write the failing handler test `tests/ThemeLayoutHandlersTest.php`**

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/ThemeLayouts.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/**
 * Header/footer layouts through the REAL REST and MCP handlers: a live layout works exactly like a
 * page (read, preview, edit, history, restore) and every other post is "not found".
 */
class ThemeLayoutHandlersTest extends TestCase
{
    private const HEADER = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    private const SHOP   = '<!-- wp:divi/shop {"builderVersion":"5.14.0"} /-->';
    private const INVALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/not-a-module {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private function post( int $id, string $type, string $status = 'publish', array $meta = [], string $content = '' ): void
    {
        $p               = new \WP_Post();
        $p->ID           = $id;
        $p->post_type    = $type;
        $p->post_status  = $status;
        $p->post_title   = "Post {$id}";
        $p->post_content = $content;
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']        = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_posts']          = [];
        $GLOBALS['__wp_postmeta']       = [];
        $GLOBALS['__wp_update_fail']    = false;
        $GLOBALS['__wp_update_calls']   = 0;
        $GLOBALS['__divi_cache_clears'] = [];
        $GLOBALS['__divi_tb']           = [ 'tb_id' => 100, 'draft_id' => 300, 'template_ids' => [ 200 ], 'draft_template_ids' => [ 301 ] ];
        $GLOBALS['__wp_caps']           = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true ];

        $this->post( 100, 'et_theme_builder' );
        $this->post( 200, 'et_template', 'publish', [ '_et_header_layout_id' => 201, '_et_footer_layout_id' => 202, '_et_body_layout_id' => 203 ] );
        $this->post( 201, 'et_header_layout', 'publish', [], self::HEADER );
        $this->post( 202, 'et_footer_layout', 'publish', [], self::HEADER );
        $this->post( 203, 'et_body_layout', 'publish', [], self::HEADER );
        $this->post( 204, 'et_header_layout', 'publish', [], self::HEADER );   // orphan
        $this->post( 300, 'et_theme_builder', 'auto-draft' );
        $this->post( 301, 'et_template', 'publish', [ '_et_header_layout_id' => 302 ] );
        $this->post( 302, 'et_header_layout', 'publish', [], self::HEADER );   // draft-Theme-Builder only
        $this->post( 5, 'page', 'publish', [], self::HEADER );
        $this->post( 6, 'post', 'publish', [], self::HEADER );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']      = null;
        $GLOBALS['__wp_options'] = [];
        unset( $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can']  = true;
        $GLOBALS['__divi_tb'] = [];
    }

    // ---- helpers ------------------------------------------------------------------------------

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( string $method, int $id, array $body = [], array $extra = [] ): array
    {
        $req = new \WP_REST_Request( $body, [ 'id' => $id ] + $extra );
        $r   = ( new RestController() )->$method( $req );
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array{0:?int,1:string,2:array<string,mixed>} JSON-RPC error code, error message, decoded result */
    private function mcp( string $tool, array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], (string) $data['error']['message'], [] ];
        }

        return [ null, '', (array) json_decode( $data['result']['content'][0]['text'], true ) ];
    }

    private function content( int $id ): string
    {
        return $GLOBALS['__wp_posts'][ $id ]->post_content;
    }

    private function historyRows( int $id ): int
    {
        return count( HistoryStore::summaries( $id ) );
    }

    // ---- a live header/footer works like a page -----------------------------------------------

    public function testReadingALiveHeaderThroughRestAndMcp(): void
    {
        [ $status, $data ] = $this->rest( 'get_page', 201 );
        $this->assertSame( 200, $status );
        $this->assertSame( 201, $data['post_id'] );
        $this->assertSame( 'et_header_layout', $data['post_type'] );
        $this->assertSame( self::HEADER, $data['post_content'] );

        [ $code, , $result ] = $this->mcp( 'get_page_layout', [ 'page_id' => 202 ] );
        $this->assertNull( $code );
        $this->assertSame( 'et_footer_layout', $result['post_type'] );
        $this->assertSame( self::HEADER, $result['post_content'] );
    }

    public function testAPageEnvelopeKeepsItsShapeAndGainsPostType(): void
    {
        [ , $data ] = $this->rest( 'get_page', 5 );
        $this->assertSame( 'page', $data['post_type'] );
        $this->assertSame( 5, $data['post_id'] );
    }

    public function testEditingALiveHeaderSavesSnapshotsAndClearsCaches(): void
    {
        $new = str_replace( self::SHOP, self::SHOP . self::SHOP, self::HEADER );
        [ $status, $data ] = $this->rest( 'edit_page', 201, [ 'find' => self::SHOP, 'replace' => self::SHOP . self::SHOP ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertTrue( $data['history']['stored'] );
        $this->assertSame( $new, $this->content( 201 ) );
        $this->assertSame( 1, $this->historyRows( 201 ) );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'] );

        [ $code, , $result ] = $this->mcp( 'edit_page_content', [ 'page_id' => 202, 'find' => self::SHOP, 'replace' => self::SHOP . self::SHOP ] );
        $this->assertNull( $code );
        $this->assertTrue( $result['saved'] );
        $this->assertCount( 2, $GLOBALS['__divi_cache_clears'] );
    }

    public function testWholeLayoutUpdateStillGoesThroughTheValidatorGate(): void
    {
        [ $status, $data ] = $this->rest( 'update_page', 201, [ 'post_content' => self::INVALID ] );
        $this->assertSame( 422, $status );
        $this->assertFalse( $data['valid'] );
        $this->assertSame( self::HEADER, $this->content( 201 ), 'an invalid layout never reaches a header' );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );

        [ $code, , $result ] = $this->mcp( 'update_page_layout', [ 'page_id' => 201, 'post_content' => self::INVALID ] );
        $this->assertNull( $code );
        $this->assertFalse( $result['saved'] );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testDryRunOnALayoutWritesNothingAndClearsNoCaches(): void
    {
        [ $status, $data ] = $this->rest( 'edit_page', 201, [ 'find' => self::SHOP, 'replace' => self::SHOP . self::SHOP, 'dry_run' => true ] );
        $this->assertSame( 200, $status );
        $this->assertFalse( $data['saved'] );
        $this->assertTrue( $data['dry_run'] );
        [ , , $result ] = $this->mcp( 'update_page_layout', [ 'page_id' => 202, 'post_content' => self::INVALID, 'dry_run' => true ] );
        $this->assertFalse( $result['saved'] );
        $this->assertSame( self::HEADER, $this->content( 201 ) );
        $this->assertSame( self::HEADER, $this->content( 202 ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( 0, $this->historyRows( 201 ) + $this->historyRows( 202 ) );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testHistoryAndRestoreWorkForALayout(): void
    {
        $this->rest( 'edit_page', 201, [ 'find' => self::SHOP, 'replace' => self::SHOP . self::SHOP ] );

        [ $status, $list ] = $this->rest( 'history_list', 201 );
        $this->assertSame( 200, $status );
        $this->assertSame( 1, $list['count'] );
        $vid = (int) $list['versions'][0]['id'];

        [ $status, $entry ] = $this->rest( 'history_entry', 201, [], [ 'version_id' => $vid ] );
        $this->assertSame( 200, $status );
        $this->assertSame( self::HEADER, $entry['post_content'] );

        $GLOBALS['__divi_cache_clears'] = [];
        [ $status, $restored ] = $this->rest( 'history_restore', 201, [ 'version_id' => $vid ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $restored['restored'] );
        $this->assertSame( self::HEADER, $this->content( 201 ) );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'] );

        [ $code, , $m ] = $this->mcp( 'list_page_history', [ 'page_id' => 201 ] );
        $this->assertNull( $code );
        $this->assertGreaterThanOrEqual( 1, $m['count'] );
    }

    // ---- everything else is "not found" ---------------------------------------------------------

    /** @return list<array{int,string}> */
    public static function notFoundIds(): array
    {
        return [
            [ 203, 'a body layout' ],
            [ 204, 'an orphaned header' ],
            [ 302, 'a header of the draft Theme Builder only' ],
            [ 200, 'a live template' ],
            [ 100, 'the Theme Builder post' ],
            [ 6, 'an ordinary post' ],
            [ 9999, 'a post that does not exist' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'notFoundIds' )]
    public function testEveryOtherPostIsNotFoundOnEveryRestEntryPoint( int $id, string $why ): void
    {
        $this->assertSame( 404, $this->rest( 'get_page', $id )[0], "get_page: {$why}" );
        $this->assertSame( 404, $this->rest( 'update_page', $id, [ 'post_content' => self::HEADER ] )[0], "update_page: {$why}" );
        $this->assertSame( 404, $this->rest( 'edit_page', $id, [ 'find' => 'a', 'replace' => 'b' ] )[0], "edit_page: {$why}" );
        $this->assertSame( 404, $this->rest( 'history_list', $id )[0], "history_list: {$why}" );
        $this->assertSame( 404, $this->rest( 'history_restore', $id, [ 'version_id' => 1 ] )[0], "history_restore: {$why}" );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], $why );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'notFoundIds' )]
    public function testEveryOtherPostIsNotFoundOnEveryMcpTool( int $id, string $why ): void
    {
        foreach ( [
            [ 'get_page_layout', [ 'page_id' => $id ] ],
            [ 'update_page_layout', [ 'page_id' => $id, 'post_content' => self::HEADER ] ],
            [ 'edit_page_content', [ 'page_id' => $id, 'find' => 'a', 'replace' => 'b' ] ],
            [ 'list_page_history', [ 'page_id' => $id ] ],
            [ 'get_page_history_entry', [ 'page_id' => $id, 'version_id' => 1 ] ],
            [ 'restore_page_version', [ 'page_id' => $id, 'version_id' => 1 ] ],
        ] as [ $tool, $args ] ) {
            [ $code, $message ] = $this->mcp( $tool, $args );
            $this->assertSame( -32602, $code, "{$tool}: {$why}" );
            $this->assertSame( "Page {$id} not found.", $message, "{$tool}: {$why}" );
        }
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], $why );
    }

    public function testWithoutEditOthersPostsALayoutIsForbiddenButAPageIsStillFine(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_pages' => true ]; // no edit_others_posts
        $this->assertSame( 403, $this->rest( 'get_page', 201 )[0] );
        $this->assertSame( 403, $this->rest( 'edit_page', 201, [ 'find' => 'a', 'replace' => 'b' ] )[0] );
        [ $code, $message ] = $this->mcp( 'edit_page_content', [ 'page_id' => 201, 'find' => 'a', 'replace' => 'b' ] );
        $this->assertSame( -32602, $code );
        $this->assertStringContainsString( 'permission', $message );
        $this->assertSame( 200, $this->rest( 'get_page', 5 )[0], 'pages never needed edit_others_posts' );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testPageBehaviourIsUnchanged(): void
    {
        [ $status, $data ] = $this->rest( 'edit_page', 5, [ 'find' => self::SHOP, 'replace' => self::SHOP . self::SHOP ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'], 'a page save leaves Divi caches alone' );
        $this->assertSame( 404, $this->rest( 'get_page', 6 )[0], 'an ordinary post is still not a page' );
        [ $code, $message ] = $this->mcp( 'get_page_layout', [ 'page_id' => 9999 ] );
        $this->assertSame( [ -32602, 'Page 9999 not found.' ], [ $code, $message ] );
    }
}
```

NOTE for the implementer: `RestController::history_entry` reads the version id with `$request->get_param('version_id')` — the test passes it as an extra param; if the method name or param name differs, use the real ones. The REST `history_restore` reads `get_json_params()['version_id']`. Adapt only the test's call shapes, never the handlers' behaviour, and report any such adaptation.

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/ThemeLayoutHandlersTest.php`
Expected: many failures (a layout id is rejected as "not found" everywhere; `post_type` missing).

- [ ] **Step 3: MCP handler edits (`wp-plugin/src/McpHandler.php`)**

(a) `toolGetLayout()`: replace

```php
        $pageId = (int) ($args['page_id'] ?? 0);
        $post   = $pageId ? get_post($pageId) : null;

        if (!$post || $post->post_type !== 'page') {
            UsageTracker::log('get_layout', $pageId ?: null, 'error');
            return $this->rpcError($id, -32602, "Page {$pageId} not found.");
        }

        if (!current_user_can('edit_post', $pageId)) {
            UsageTracker::log('get_layout', $pageId, 'error');
            return $this->rpcError($id, -32602, "You do not have permission to read page {$pageId}.");
        }
```

with

```php
        $pageId = (int) ($args['page_id'] ?? 0);
        $target = EditTarget::resolve($pageId);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            UsageTracker::log('get_layout', $pageId ?: null, 'error');
            return $this->rpcError($id, -32602, "Page {$pageId} not found.");
        }

        if ($target['error'] === EditTarget::FORBIDDEN) {
            UsageTracker::log('get_layout', $pageId, 'error');
            return $this->rpcError($id, -32602, "You do not have permission to read page {$pageId}.");
        }
        $post = $target['post'];
```

and add `'post_type'    => $post->post_type,` to the returned JSON array of that method (after `'post_id'`).

(b) `toolUpdate()`: replace

```php
        if (!$post || $post->post_type !== 'page') {
            return $this->rpcError($id, -32602, "Page {$pageId} not found.");
        }

        if (!current_user_can('edit_post', $pageId)) {
            return $this->rpcError($id, -32602, "You do not have permission to edit page {$pageId}.");
        }

        if ($content === '') {
```

with

```php
        $target = EditTarget::resolve($pageId);
        if ($target['error'] === EditTarget::NOT_FOUND) {
            return $this->rpcError($id, -32602, "Page {$pageId} not found.");
        }

        if ($target['error'] === EditTarget::FORBIDDEN) {
            return $this->rpcError($id, -32602, "You do not have permission to edit page {$pageId}.");
        }
        $post = $target['post'];

        if ($content === '') {
```

and delete the now-unused earlier line `$post    = $pageId ? get_post($pageId) : null;` in `toolUpdate()` only.

(c) `toolEditContent()`: delete the line `$post = $pageId ? get_post($pageId) : null;` and replace

```php
        if (!$post || $post->post_type !== 'page') {
            return $this->rpcError($id, -32602, "Page {$pageId} not found.");
        }
        if (!current_user_can('edit_post', $pageId)) {
            return $this->rpcError($id, -32602, "You do not have permission to edit page {$pageId}.");
        }

        $edit = PageEditor::apply(
```

with

```php
        $target = EditTarget::resolve($pageId);
        if ($target['error'] === EditTarget::NOT_FOUND) {
            return $this->rpcError($id, -32602, "Page {$pageId} not found.");
        }
        if ($target['error'] === EditTarget::FORBIDDEN) {
            return $this->rpcError($id, -32602, "You do not have permission to edit page {$pageId}.");
        }
        $post = $target['post'];

        $edit = PageEditor::apply(
```

(the `PageEditor::apply(` call continues with its existing arguments on that same line).

(d) `historyPage()`: replace its body's check

```php
        $pageId = (int) ($args['page_id'] ?? 0);
        $post   = $pageId ? get_post($pageId) : null;

        if (!$post || $post->post_type !== 'page') {
            return $this->rpcError($id, -32602, "Page {$pageId} not found.");
        }
        if (!current_user_can('edit_post', $pageId)) {
            return $this->rpcError($id, -32602, "You do not have permission to access page {$pageId}.");
        }

        return $post;
```

with

```php
        $pageId = (int) ($args['page_id'] ?? 0);
        $target = EditTarget::resolve($pageId);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return $this->rpcError($id, -32602, "Page {$pageId} not found.");
        }
        if ($target['error'] === EditTarget::FORBIDDEN) {
            return $this->rpcError($id, -32602, "You do not have permission to access page {$pageId}.");
        }

        return $target['post'];
```

(e) Update the descriptions of `get_page_layout`, `update_page_layout`, `edit_page_content`, `list_page_history`, `get_page_history_entry`, `restore_page_version` and the `page_id` property descriptions to say a header or footer layout id from `list_theme_layouts` is accepted, e.g. `get_page_layout`: `Get the full Divi 5 layout (Gutenberg block HTML) for a specific page by ID. A header or footer layout id from list_theme_layouts also works.` and the `page_id` property: `WordPress page ID (or a header/footer layout id from list_theme_layouts)`. Keep every existing substring other tests look for (`dry_run=true`, tool names).

- [ ] **Step 4: REST handler edits (`wp-plugin/src/RestController.php`)**

(a) `get_page()`: replace

```php
        $id   = (int) $request->get_param('id');
        $post = get_post($id);

        if (!$post || $post->post_type !== 'page') {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }

        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('forbidden', "You do not have permission to read page $id.", ['status' => 403]);
        }

        UsageTracker::log('get_page', $id, 'read');
```

with

```php
        $id     = (int) $request->get_param('id');
        $target = EditTarget::resolve($id);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }

        if ($target['error'] === EditTarget::FORBIDDEN) {
            return new WP_Error('forbidden', "You do not have permission to read page $id.", ['status' => 403]);
        }
        $post = $target['post'];

        UsageTracker::log('get_page', $id, 'read');
```

(b) `update_page()`: replace

```php
        $id   = (int) $request->get_param('id');
        $post = get_post($id);

        if (!$post || $post->post_type !== 'page') {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }

        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('forbidden', "You do not have permission to edit page $id.", ['status' => 403]);
        }

        $body    = (array) $request->get_json_params();
        $content = PageAccess::stringArg($body, 'post_content');
```

with

```php
        $id     = (int) $request->get_param('id');
        $target = EditTarget::resolve($id);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }

        if ($target['error'] === EditTarget::FORBIDDEN) {
            return new WP_Error('forbidden', "You do not have permission to edit page $id.", ['status' => 403]);
        }
        $post = $target['post'];

        $body    = (array) $request->get_json_params();
        $content = PageAccess::stringArg($body, 'post_content');
```

(c) `edit_page()`: replace

```php
        $id   = (int) $request->get_param('id');
        $post = get_post($id);

        if (!$post || $post->post_type !== 'page') {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }
        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('forbidden', "You do not have permission to edit page $id.", ['status' => 403]);
        }

        $body    = (array) $request->get_json_params();
        $find    = PageAccess::stringArg($body, 'find');
```

with

```php
        $id     = (int) $request->get_param('id');
        $target = EditTarget::resolve($id);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }
        if ($target['error'] === EditTarget::FORBIDDEN) {
            return new WP_Error('forbidden', "You do not have permission to edit page $id.", ['status' => 403]);
        }
        $post = $target['post'];

        $body    = (array) $request->get_json_params();
        $find    = PageAccess::stringArg($body, 'find');
```

(d) `history_page()`: replace

```php
        $id   = (int) $request->get_param('id');
        $post = get_post($id);

        if (!$post || $post->post_type !== 'page') {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }
        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('forbidden', "You do not have permission to access page $id.", ['status' => 403]);
        }

        return $post;
```

with

```php
        $id     = (int) $request->get_param('id');
        $target = EditTarget::resolve($id);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }
        if ($target['error'] === EditTarget::FORBIDDEN) {
            return new WP_Error('forbidden', "You do not have permission to access page $id.", ['status' => 403]);
        }

        return $target['post'];
```

(e) `build_layout_envelope()`: add `'post_type'    => $post->post_type,` right after the `'post_id'` entry. If the OpenAPI `getPageLayout` response schema lists properties, add `'post_type' => ['type' => 'string', 'description' => 'page, et_header_layout or et_footer_layout']` there too.

- [ ] **Step 5: Dashboard (`wp-plugin/src/AdminPage.php`)**

(a) In `handleRestorePage()` replace

```php
        $post = $pageId ? get_post( $pageId ) : null;
        if ( ! $post || 'page' !== $post->post_type || ! current_user_can( 'edit_post', $pageId ) ) {
            $this->redirect( 'dashboard', 'restore_failed' );
        }
```

with

```php
        if ( null !== EditTarget::resolve( $pageId )['error'] ) {
            $this->redirect( 'dashboard', 'restore_failed' );
        }
```

(b) In `historySection()` replace the title line

```php
                    $title = '' === $row['title'] ? __( '(no title)', 'jhmg-ai-editor-for-divi-5' ) : $row['title']; ?>
```

with

```php
                    $title = '' === $row['title'] ? __( '(no title)', 'jhmg-ai-editor-for-divi-5' ) : $row['title'];
                    if ( 'header' === $row['kind'] ) {
                        $title = __( 'Header', 'jhmg-ai-editor-for-divi-5' ) . ': ' . $title;
                    } elseif ( 'footer' === $row['kind'] ) {
                        $title = __( 'Footer', 'jhmg-ai-editor-for-divi-5' ) . ': ' . $title;
                    } ?>
```

- [ ] **Step 6: Run the new test and the whole suite**

Run: `vendor/bin/phpunit tests/ThemeLayoutHandlersTest.php` → Expected: PASS.
Run: `vendor/bin/phpunit` → Expected: all green, output pristine (existing `DryRunHandlersTest`, `HistoryLockstepTest`, `AdminMarkupTest`, `WpOrgComplianceTest` included). If an existing test asserted the old source text of a replaced check, update only that assertion to the new equivalent and say so in the report; never loosen a behaviour assertion.

- [ ] **Step 7: Commit**

```bash
git add wp-plugin/src tests
git commit -m "feat: header/footer layouts through the page tools via EditTarget (MCP, REST, Dashboard restore)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The read-only `list_theme_layouts` tool (MCP, REST, OpenAPI)

**Files:**
- Modify: `wp-plugin/src/McpHandler.php`, `wp-plugin/src/RestController.php`, `wp-plugin/src/OpenApiSpec.php`, `tests/WpOrgComplianceTest.php`, `tests/DryRunLockstepTest.php`
- Create: `tests/ThemeLayoutsLockstepTest.php`

**Interfaces:**
- Consumes: `ThemeLayouts::listing()`, `UsageTracker::log()`.
- Produces: MCP tool `list_theme_layouts`, REST `GET /theme-layouts` (handler `theme_layouts`), OpenAPI operation `listThemeLayouts` with schemas `ThemeTemplate` and `ThemeLayoutRef`. Tool count and operation count become 16.

- [ ] **Step 1: Write the failing tests**

Create `tests/ThemeLayoutsLockstepTest.php`:

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
require_once __DIR__ . '/DiviThemeBuilderShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/ThemeLayouts.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** list_theme_layouts must exist, with the same meaning, in MCP, REST and OpenAPI, and be read-only. */
class ThemeLayoutsLockstepTest extends TestCase
{
    private function post( int $id, string $type, array $meta = [] ): void
    {
        $p              = new \WP_Post();
        $p->ID          = $id;
        $p->post_type   = $type;
        $p->post_status = 'publish';
        $p->post_title  = "Post {$id}";
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [ 'tb_id' => 100, 'draft_id' => 0, 'template_ids' => [ 200 ], 'draft_template_ids' => [] ];
        $GLOBALS['__divi_tb_calls'] = [];
        $GLOBALS['__wp_caps']     = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true ];
        $this->post( 100, 'et_theme_builder' );
        $this->post( 200, 'et_template', [ '_et_default' => '1', '_et_enabled' => '1', '_et_use_on' => [], '_et_header_layout_id' => 201, '_et_header_layout_enabled' => '1', '_et_body_layout_id' => 203, '_et_body_layout_enabled' => '1' ] );
        $this->post( 201, 'et_header_layout' );
        $this->post( 203, 'et_body_layout' );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        unset( $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can']  = true;
        $GLOBALS['__divi_tb'] = [];
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    public function testMcpDeclaresAndDispatchesTheTool(): void
    {
        $mcp = $this->src( 'McpHandler.php' );
        $this->assertStringContainsString( "'name'        => 'list_theme_layouts'", $mcp );
        $this->assertMatchesRegularExpression( "/'list_theme_layouts'\s*=>\s*\\\$this->toolListThemeLayouts/", $mcp );
    }

    public function testRestRegistersTheRouteAsReadOnly(): void
    {
        $rest = $this->src( 'RestController.php' );
        $this->assertStringContainsString( "'/theme-layouts'", $rest );
        $this->assertMatchesRegularExpression( "/'\/theme-layouts',\s*\[\s*'methods'\s*=>\s*WP_REST_Server::READABLE/", $rest );
    }

    public function testOpenApiDescribesTheOperationAndItsSchemas(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $op   = $spec['paths']['/theme-layouts']['get'] ?? null;
        $this->assertIsArray( $op );
        $this->assertSame( 'listThemeLayouts', $op['operationId'] );
        $this->assertLessThanOrEqual( 300, strlen( (string) $op['description'] ) );
        $schema = $op['responses']['200']['content']['application/json']['schema'];
        $this->assertSame( '#/components/schemas/ThemeTemplate', $schema['properties']['templates']['items']['$ref'] );
        $this->assertSame( 'integer', $schema['properties']['count']['type'] );
        $this->assertSame( 'string', $schema['properties']['note']['type'] );
        foreach ( [ 'ThemeTemplate', 'ThemeLayoutRef' ] as $name ) {
            $this->assertArrayHasKey( $name, $spec['components']['schemas'] );
        }
        $this->assertSame( '#/components/schemas/ThemeLayoutRef', $spec['components']['schemas']['ThemeTemplate']['properties']['header']['$ref'] );
        $this->assertSame( 'boolean', $spec['components']['schemas']['ThemeLayoutRef']['properties']['editable']['type'] );
    }

    public function testThereAreNowSixteenOperations(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $ops  = [];
        foreach ( $spec['paths'] as $methods ) {
            foreach ( $methods as $op ) {
                $ops[] = $op['operationId'] ?? '';
            }
        }
        $this->assertCount( 16, $ops );
        $this->assertContains( 'listThemeLayouts', $ops );
    }

    public function testRestListingReturnsTheTemplatesAndNeverCreatesAThemeBuilderPost(): void
    {
        $r = ( new RestController() )->theme_layouts( new \WP_REST_Request() );
        $this->assertSame( 200, $r->get_status() );
        $d = (array) $r->get_data();
        $this->assertSame( 1, $d['count'] );
        $this->assertSame( 200, $d['templates'][0]['template_id'] );
        $this->assertSame( [ 'layout_id' => 201, 'title' => 'Post 201', 'enabled' => true, 'editable' => true ], $d['templates'][0]['header'] );
        $this->assertFalse( $d['templates'][0]['body']['editable'] );
        $this->assertArrayNotHasKey( 'footer', $d['templates'][0] );
        foreach ( $GLOBALS['__divi_tb_calls'] as $call ) {
            if ( 'post_id' === $call[0] ) {
                $this->assertFalse( $call[2] );
            }
        }
    }

    public function testMcpListingMatchesRestAndRefusesWithoutEditOthersPosts(): void
    {
        $call = static function (): array {
            $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'list_theme_layouts', 'arguments' => new \stdClass() ] ] );

            return (array) ( new McpHandler() )->handle( $req )->get_data();
        };
        $ok = $call();
        $this->assertArrayNotHasKey( 'error', $ok );
        $decoded = json_decode( $ok['result']['content'][0]['text'], true );
        $rest    = (array) ( new RestController() )->theme_layouts( new \WP_REST_Request() )->get_data();
        $this->assertSame( $rest, $decoded );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_pages' => true ];
        $denied = $call();
        $this->assertSame( -32602, $denied['error']['code'] );
        $this->assertStringContainsString( 'permission', $denied['error']['message'] );

        $r = ( new RestController() )->theme_layouts( new \WP_REST_Request() );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 403, $r->data['status'] );
    }

    public function testTheListingToolWritesNothing(): void
    {
        $before = [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        ( new RestController() )->theme_layouts( new \WP_REST_Request() );
        $this->assertSame( $before[1], $GLOBALS['__wp_postmeta'] );
        $this->assertCount( count( $before[0] ), $GLOBALS['__wp_posts'] );
    }
}
```

Edit `tests/WpOrgComplianceTest.php` in `testCreatePageIsFreeAndTheMcpToolListIsExactlyTheFreeTools`: replace the expected list and count with (the only change allowed there — a new READ-ONLY tool, nothing licence-gated, nothing that writes settings):

```php
        $this->assertSame([
            'create_page', 'edit_page_content', 'get_image_guide', 'get_landing_guide', 'get_page_history_entry',
            'get_page_layout', 'get_section_recipes', 'get_site_guide', 'get_style_guide', 'list_divi_pages',
            'list_media_images', 'list_page_history', 'list_theme_layouts', 'restore_page_version', 'update_page_layout', 'validate_layout',
        ], $tools);
        $this->assertCount(16, $tools);
```

Edit `tests/DryRunLockstepTest.php`: in the operation-count test change `$this->assertCount( 15, $ops, 'dry_run is a flag, not a new operation' );` to `$this->assertCount( 16, $ops, 'dry_run is a flag, not a new operation (the 16th operation is list_theme_layouts)' );`.

- [ ] **Step 2: Run to see them fail**

Run: `vendor/bin/phpunit tests/ThemeLayoutsLockstepTest.php tests/WpOrgComplianceTest.php tests/DryRunLockstepTest.php`
Expected: FAIL (tool/route/operation missing; pinned list expects 16).

- [ ] **Step 3: MCP tool**

In `wp-plugin/src/McpHandler.php`:

(a) Add this definition to the tool list directly after the `list_divi_pages` definition:

```php
            [
                'name'        => 'list_theme_layouts',
                'description' => 'List the header and footer layouts of the live Divi Theme Builder that you can edit (read-only), with the templates that use them (title, default, enabled, where they apply). Edit a header or footer with the page tools, passing its layout_id as page_id; use dry_run first because it appears on every page. Body layouts are listed but cannot be edited. Creating or assigning templates is not possible here: that is done in Divi\'s Theme Builder.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
```

(b) In the dispatch `match`, add directly after the `'list_media_images'  => $this->toolListMedia($id, $arguments),` line:

```php
            'list_theme_layouts' => $this->toolListThemeLayouts($id),
```

(c) Add the method next to `toolListMedia()`:

```php
    private function toolListThemeLayouts(mixed $id): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP) || !ThemeLayouts::userMay()) {
            UsageTracker::log('list_theme_layouts', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to list theme layouts.');
        }
        $result = ThemeLayouts::listing();
        UsageTracker::log('list_theme_layouts', null, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($result)]],
        ]);
    }
```

- [ ] **Step 4: REST route**

In `wp-plugin/src/RestController.php`: register the route directly after the `/pages` GET registration block:

```php
        // GET /theme-layouts — read-only list of the live Theme Builder's header/footer layouts
        register_rest_route(self::NS, '/theme-layouts', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'theme_layouts'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);
```

and add the handler next to `list_media()`:

```php
    public function theme_layouts(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can(PageAccess::CAP) || !ThemeLayouts::userMay()) {
            UsageTracker::log('list_theme_layouts', null, 'error');
            return new WP_Error('forbidden', 'You do not have permission to list theme layouts.', ['status' => 403]);
        }
        $result = ThemeLayouts::listing();
        UsageTracker::log('list_theme_layouts', null, 'read');

        return new WP_REST_Response($result, 200);
    }
```

- [ ] **Step 5: OpenAPI**

In `wp-plugin/src/OpenApiSpec.php` add the path (next to `/media`):

```php
                '/theme-layouts' => [
                    'get' => [
                        'operationId' => 'listThemeLayouts',
                        'summary'     => 'List editable header and footer layouts',
                        'description' => 'Lists the header and footer layouts of the live Divi Theme Builder that you can edit (read-only), with the templates that use them. Edit one with the page tools, passing its layout_id as the page id. Body layouts are listed but not editable.',
                        'responses'   => [
                            '200' => ['description' => 'Live templates and their layouts', 'content' => ['application/json' => ['schema' => [
                                'type'       => 'object',
                                'properties' => [
                                    'templates' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ThemeTemplate']],
                                    'count'     => ['type' => 'integer'],
                                    'note'      => ['type' => 'string', 'description' => 'Present when there is no live Theme Builder with templates'],
                                ],
                            ]]]],
                            '403' => ['description' => 'The user cannot edit other users\' content'],
                        ],
                    ],
                ],
```

and the schemas next to `HistoryEntry`:

```php
                    'ThemeLayoutRef' => [
                        'type'       => 'object',
                        'properties' => [
                            'layout_id' => ['type' => 'integer', 'description' => 'Pass this as the page id to the page tools'],
                            'title'     => ['type' => 'string'],
                            'enabled'   => ['type' => 'boolean', 'description' => 'Whether the template shows this layout'],
                            'editable'  => ['type' => 'boolean', 'description' => 'false for body layouts and for layouts the caller cannot edit'],
                        ],
                    ],
                    'ThemeTemplate' => [
                        'type'       => 'object',
                        'properties' => [
                            'template_id'  => ['type' => 'integer'],
                            'title'        => ['type' => 'string'],
                            'default'      => ['type' => 'boolean', 'description' => 'true when this is the default template (applies everywhere nothing else matches)'],
                            'enabled'      => ['type' => 'boolean'],
                            'use_on'       => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Where the template applies (Divi condition strings)'],
                            'exclude_from' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'header'       => ['$ref' => '#/components/schemas/ThemeLayoutRef'],
                            'footer'       => ['$ref' => '#/components/schemas/ThemeLayoutRef'],
                            'body'         => ['$ref' => '#/components/schemas/ThemeLayoutRef'],
                        ],
                    ],
```

- [ ] **Step 6: Run the tests and the whole suite**

Run: `vendor/bin/phpunit tests/ThemeLayoutsLockstepTest.php tests/WpOrgComplianceTest.php tests/DryRunLockstepTest.php tests/OpenApiSpecTest.php` → Expected: PASS.
Run: `vendor/bin/phpunit` → Expected: all green, output pristine. (The readme still says "15 tools" until Task 5; if a readme check fails on the count, leave it for Task 5 only if the failing assertion is that one — otherwise fix the cause.)

- [ ] **Step 7: Commit**

```bash
git add wp-plugin/src tests
git commit -m "feat: list_theme_layouts, a read-only tool for the live header/footer layouts (MCP, REST, OpenAPI)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Real-site check (`make verify-theme-layouts`)

**Files:**
- Create: `scripts/verify-theme-layouts.php`
- Modify: `Makefile`

**Interfaces:**
- Consumes: the finished plugin code (mounted into the Docker site), Divi's real Theme Builder functions `et_theme_builder_get_theme_builder_post_id`, `et_theme_builder_insert_layout`, `et_theme_builder_store_template`, `et_theme_builder_clear_wp_cache`, and `rest_do_request()` against `/ai-editor-divi5/v1/theme-layouts`, `/pages/{id}`, `/pages/{id}/edit`, `/pages/{id}/history`, `/pages/{id}/restore`, `/mcp`.
- Produces: `make verify-theme-layouts` (exit 0 = every check passed).

- [ ] **Step 1: Create `scripts/verify-theme-layouts.php`**

Model it on `scripts/verify-dry-run.php` (same `$check`, `$rest`, `$mcp` helpers — the MCP helper sends `Authorization: Bearer <ai_editor_divi5_api_key option>` for that call only, unsets it afterwards and resets user 1; REST uses the logged-in user from `--user=1`). It must do exactly this, and record every post/option it creates so a `finally` can remove them and nothing else:

1. **Guard + baseline.** `class_exists( HistoryStore::class )` else `WP_CLI::error`. Require Divi's functions (`function_exists` for the five named above) else `WP_CLI::error( 'Divi Theme Builder functions not available' )`. Count posts of the five types (`et_theme_builder`, `et_template`, `et_header_layout`, `et_body_layout`, `et_footer_layout`) with `$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN (...)" )` as `$baselineCount`. Record `$liveTbBefore = et_theme_builder_get_theme_builder_post_id( true, false )` (0 if none) and `$draftTbBefore = et_theme_builder_get_theme_builder_post_id( false, false )`. Snapshot `$tbTemplatesBefore = get_post_meta( $liveTbBefore, '_et_template', false )` when a live TB exists.
2. **Listing must not create anything.** Before building fixtures, call REST `GET /theme-layouts` and MCP `list_theme_layouts` as `--user=1`; check the Theme Builder post counts for `et_theme_builder` are unchanged and that `$liveTbBefore` is unchanged afterwards (`et_theme_builder_get_theme_builder_post_id( true, false )` again) — "listing never creates a Theme Builder post".
3. **Build fixtures with Divi's own functions** (as in the spike): `$tb = et_theme_builder_get_theme_builder_post_id( true, true )` and remember `$createdTb = ( 0 === $liveTbBefore )`; header `$H` and footer `$F` via `et_theme_builder_insert_layout( [ 'post_type' => 'et_header_layout'|'et_footer_layout', 'post_title' => 'aied verify header|footer', 'post_content' => wp_slash( $layout( 'AIED-HEADER-V1' ) ) ] )` (the same single-heading Divi 5 layout used in `verify-dry-run.php`, which the checker accepts) with `_et_pb_use_divi_5=on` and `_et_pb_use_builder=on`; a body layout `$B` (`et_body_layout`); an **orphan** header `$O` (not in any template); a live template `$T` via `et_theme_builder_store_template( $tb, [ 'title' => 'aied verify template', 'default' => '0', 'enabled' => '1', 'autogenerated_title' => '0', 'layouts' => [ 'header' => [ 'id' => $H, 'enabled' => true ], 'body' => [ 'id' => $B, 'enabled' => true ], 'footer' => [ 'id' => $F, 'enabled' => true ] ], 'use_on' => [], 'exclude_from' => [] ], true )` with `default` `'0'` and **empty `use_on`** so the throwaway template applies to nothing on the real site; then `add_post_meta( $tb, '_et_template', $T )` (append, never replace). For the draft-only case create `$draftTb = et_theme_builder_get_theme_builder_post_id( false, true )` (remember whether it pre-existed), a draft layout `$DL` (`et_header_layout`), a template `$DT` via `et_theme_builder_store_template( $draftTb, ... header $DL ... )` and `add_post_meta( $draftTb, '_et_template', $DT )`. Create one ordinary draft `post` `$P`. Call `et_theme_builder_clear_wp_cache( 'all' )`. Record every id created in `$created[]`.
4. **Snapshot assignment state:** `$metaSnapshot = static fn ( int $t ): array => get_post_meta( $t )` for `$T`; `$tbSnapshot = get_post_meta( $tb, '_et_template', false )`; also the same for `$DT`.
5. **Checks (all must PASS):**
   - REST `GET /theme-layouts` and MCP `list_theme_layouts` list `$T` with `header.layout_id === $H`, `footer.layout_id === $F`, `body.layout_id === $B` with `body.editable === false` and `header.editable === true`; the orphan `$O` and the draft layout `$DL` do NOT appear anywhere in the response; REST and MCP responses are identical.
   - REST `GET /pages/{H}` and MCP `get_page_layout` return the layout content and `post_type === 'et_header_layout'`.
   - `dry_run` edit (REST `POST /pages/{H}/edit` find `AIED-HEADER-V1` replace `AIED-HEADER-V2`, `dry_run:true`) returns 200 `saved:false`, and `post_content`, `post_modified_gmt` (after `sleep( 1 )` and `clean_post_cache`) and history rows of `$H` are unchanged.
   - A real REST edit saves (`saved:true`, `history.stored:true`), the stored content contains `AIED-HEADER-V2`, one history row exists, and the **front end renders it**: fetch the site front page from inside the container (the Apache service is reachable as `http://wordpress/`; send a `Host` header equal to `home_url()`'s host plus port) and confirm it contains `AIED-HEADER-V2` only while the template applies — because `$T` applies to nothing, instead assert the rendered output through Divi: skip the HTTP fetch and verify the stored content only. (Do NOT make the throwaway template apply anywhere on the real site. The real front-end proof was already obtained in the spike; this script proves the data path.)
   - A real MCP `edit_page_content` on the footer `$F` saves and snapshots; `list_page_history` (MCP and REST) shows the versions; `restore_page_version` puts the exact previous content back; after restore the stored content equals the original byte for byte.
   - Cache hook: `$GLOBALS` cannot observe it, so assert instead that a real layout edit does not fatal and returns saved:true (the cache-clear functions exist on this site).
   - **Refusals, REST (404 `not_found`) and MCP (-32602 `Page N not found.`) for each of:** `$O` (orphan), `$DL` (draft-only), `$B` (body layout), `$T` (template), `$tb` (live Theme Builder post), `$P` (ordinary post) — on `get_page`, `edit_page` (find/replace), `update_page` and `history_list`/`restore`. None of them changes (`post_content` identical afterwards, no history rows).
   - **No `edit_others_posts`:** add `add_filter( 'user_has_cap', $f = static fn ( $caps ) => array_merge( $caps, [ 'edit_others_posts' => false ] ) )`; REST `GET /pages/{H}` → 403, MCP `edit_page_content` on `$H` → error mentioning permission, REST `GET /theme-layouts` → 403, but REST `GET /pages/{aPageId}` for an existing Divi page or a freshly inserted throwaway **page** still 200 (create `$PG` draft page with the layout content, record and delete it in `finally`); remove the filter afterwards. The layouts are authored by user 1, so `edit_post` alone would have allowed the edit — this proves the extra capability check is what refuses it.
   - **Divi's Role Editor:** Divi stores per-role permissions in the option read by `et_pb_get_role_settings()` (option name `et_pb_role_settings`). Save its exact current value (`get_option( 'et_pb_role_settings', null )` and whether the option existed), set `administrator` → `theme_builder` => `'off'` in a copy of the array, `update_option` it, `wp_cache_flush()`, then check that REST `GET /pages/{H}` → 403, MCP `edit_page_content` on `$H` → permission error, REST `GET /theme-layouts` → 403, while REST `GET /pages/{aPageId}` still 200; RESTORE the option to exactly its previous state (`delete_option` if it did not exist) in a `finally` of its own, and check it was restored. This temporarily changes a Divi option on the DEV site only; the plugin itself never writes it.
   - **Assignment untouched:** after all of the above `get_post_meta( $T )` equals `$metaSnapshot( $T )` exactly (same keys and values: no `_et_use_on`, `_et_default`, `_et_enabled`, `*_layout_id`, `*_layout_enabled` change), `get_post_meta( $tb, '_et_template', false )` equals `$tbSnapshot`, the draft template `$DT`'s meta is unchanged, and the live/draft Theme Builder post ids are the same as after fixture creation.
6. **Cleanup in `finally` (always):** delete exactly what this run created, newest first, with `wp_delete_post( $id, true )`: `$P`, `$PG`, `$DT`, `$DL`, `$T`, `$B`, `$O`, `$F`, `$H`, the draft Theme Builder post only if `$createdDraftTb`, the live Theme Builder post only if `$createdTb`; if the live Theme Builder pre-existed, remove only this run's `$T` link (`delete_post_meta( $tb, '_et_template', $T )`) and restore its `_et_template` list to `$tbTemplatesBefore`; `delete_option( 'et_tb_templates_backup_' . $tb )` if it did not exist before; then `et_theme_builder_clear_wp_cache( 'all' )` and `ET_Core_PageResource::remove_static_resources( 'all', 'all' )`. After cleanup check (as a final PASS/FAIL line, outside the `try`) that the count of the five Theme Builder post types equals `$baselineCount` and that no post titled `aied verify …` remains. A caught `\Throwable` becomes a FAIL line and the script still exits non-zero after cleanup.

End with the same `WP_CLI::error(...)` / `WP_CLI::success( 'Theme layouts verified on the real REST and MCP handlers.' )` pattern as `verify-dry-run.php`.

- [ ] **Step 2: Makefile target**

Add `verify-theme-layouts` to the `.PHONY` list on line 1 and append after the `verify-dry-run` target (the recipe line starts with a TAB):

```make

# ---------------------------------------------------------------
# verify-theme-layouts — real-site check of header/footer editing (Docker site; builds and removes its own Theme Builder fixtures)
# ---------------------------------------------------------------
verify-theme-layouts:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-theme-layouts.php --user=1
```

- [ ] **Step 3: Run it on the real site**

Run: `make verify-theme-layouts`
Expected: every line `PASS`, ending `Success: Theme layouts verified on the real REST and MCP handlers.` Exit 0. A failing check is a real defect or a wrong assumption: read the real response, find the cause, fix the smallest wrong thing (a plugin defect gets a unit/handler test where the unit tests can reproduce it and `vendor/bin/phpunit` green; WpOrgComplianceTest untouched). Never silence a check.

Afterwards confirm the site is clean: `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp post list --post_type=et_theme_builder,et_template,et_header_layout,et_footer_layout,et_body_layout --post_status=any --format=count` equals the baseline the script printed (0 on a fresh dev site), and `--post_type=page,post --s="aied verify" --format=count` is 0.

- [ ] **Step 4: Run it a second time, then commit**

Run `make verify-theme-layouts` again → Expected: PASS again (idempotent, leaves nothing behind).

```bash
git add scripts/verify-theme-layouts.php Makefile
git commit -m "test: real-site verification of header/footer editing (make verify-theme-layouts)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Guides, readme, version 4.3.0, zip, Plugin Check

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php`, `wp-plugin/src/SiteGuide.php`, `wp-plugin/readme.txt`, `wp-plugin/jhmg-ai-editor-for-divi-5.php`, `tests/WpOrgNamingTest.php`, `tests/StyleGuideTest.php`, `tests/SiteGuideTest.php`, `CLAUDE.md`
- Regenerate: `jhmg-ai-editor-for-divi-5.zip`

**Interfaces:** consumes everything above; produces the 4.3.0 branch, committed, **not merged**.

- [ ] **Step 1: Guides**

In `wp-plugin/src/StyleGuide.php`, directly after the "## Preview before saving" section (immediately before the line `MD;`), add:

```

## Header and footer
The owner's existing Theme Builder header and footer can be edited like pages. Call
`list_theme_layouts` to find them (each template lists its header and footer with a
`layout_id`), then pass that `layout_id` as `page_id` to `get_page_layout`,
`edit_page_content`, `update_page_layout`, `dry_run` and the history tools. A header or
footer appears on EVERY page, so always preview with `dry_run: true` first and prefer a
small `edit_page_content` change. Only the layouts of the live Theme Builder can be
edited, and body layouts cannot. You cannot create templates or choose where they apply;
that is the owner's job in Divi's Theme Builder. If the owner has unsaved Theme Builder
changes open in Divi, ask them to publish or discard those first, because publishing
them could overwrite your edit.
```

In `wp-plugin/src/SiteGuide.php`, replace the two lines

```
- Header/footer are currently the active theme's (the nav menu drives the header).
  Global Divi Theme Builder header/footer templates are a later phase.
```

with

```
- Header/footer: the owner's existing Theme Builder header and footer can be previewed and
  edited (list_theme_layouts, then pass the layout_id as page_id; always `dry_run` first,
  they appear on every page). Creating them and choosing where they apply is done by the
  owner in Divi's Theme Builder. Otherwise they are the active theme's (the nav menu
  drives the header).
```

In `tests/StyleGuideTest.php` and `tests/SiteGuideTest.php` add one pin each (matching the existing style): the guide mentions `list_theme_layouts` and `layout_id`.

- [ ] **Step 2: Version, readme, naming-test pins**

In `wp-plugin/jhmg-ai-editor-for-divi-5.php`: ` * Version:           4.2.0` → ` * Version:           4.3.0` and `define('AI_EDITOR_DIVI5_VERSION', '4.2.0');` → `define('AI_EDITOR_DIVI5_VERSION', '4.3.0');`. In `wp-plugin/readme.txt`: `Stable tag:        4.2.0` → `Stable tag:        4.3.0`. In `tests/WpOrgNamingTest.php` update the three version pins `4.2.0` → `4.3.0` (number only, never loosened).

Readme edits (`wp-plugin/readme.txt`) — remember the compliance rules for the readme (no `menu`, `front page`, `site tools` outside the one FAQ sentence; no `upgrade`, `premium`, `unlock`, `locked`, `license key`):
- Heading `= What your AI assistant can do (15 tools) =` → `= What your AI assistant can do (16 tools) =`.
- Under "Read and check:" add this bullet after `get_page_layout`:

```
* `list_theme_layouts`: list the header and footer of your Divi Theme Builder that the AI is allowed to edit (read-only).
```

- Under "= What makes it smart =" insert after the **Preview before saving.** bullet:

```
* **Header and footer too.** Your AI can preview and edit the content of your existing Theme Builder header and footer, for example a phone number or a link, with the same checker, preview and undo as for pages. It cannot create, delete, assign or publish templates: that stays in Divi's Theme Builder.
```

- FAQ answer under "= Does the AI get administrator access to my site? =": replace the sentence `The tools can only list, read, check, edit, create (as a draft) and undo page content, and read Media Library images (read-only).` with `The tools can only list, read, check, edit and undo the content of pages and of your existing Theme Builder header and footer, create pages (as drafts), and read Media Library images (read-only). Editing a header or footer needs a user whom Divi allows to use its Theme Builder (by default, administrators), and it cannot create, delete, assign or publish Theme Builder templates.` Keep the next sentence (`They cannot install plugins, change users, settings, menus or the front page, or run PHP or other server-side code.`) exactly as it is.
- Changelog: insert before `= 4.2.0 =`:

```
= 4.3.0 =
* New: your AI assistant can now preview and edit the content of your existing Theme Builder header and footer, with the same checker, preview and undo as pages. A new read-only tool, `list_theme_layouts`, shows which header and footer it can edit.
* It cannot create, delete, assign or publish Theme Builder templates, and body layouts are not editable.
```

- Upgrade Notice: insert before `= 4.2.0 =`:

```
= 4.3.0 =
Adds editing of your existing Theme Builder header and footer by your AI assistant (with preview and undo). Nothing changes unless your AI assistant uses it.
```

Then run `vendor/bin/phpunit tests/WpOrgComplianceTest.php` — if a readme check flags a word, reword the text; never edit the test.

- [ ] **Step 3: CLAUDE.md**

(a) Replace the line `**15 MCP tools, all free:**` and its continuation list so it reads **16 MCP tools, all free** and includes `list_theme_layouts` (read-only, `ThemeLayouts`) in the list; keep the rest of the paragraph.

(b) After the "Dry run (v4.2.0)" paragraph add:

```
Theme layouts (v4.3.0): the existing, LIVE Theme Builder header and footer layouts
(`et_header_layout` / `et_footer_layout`, content in `post_content`) can be read, previewed,
edited and restored with the page tools by passing their id as `page_id`. `EditTarget::resolve()`
is the single rule for "which post may the plugin touch" (9 call sites): a page as before; a
layout only if a template of the live Theme Builder uses it (`ThemeLayouts`, read through
Divi's own guarded functions with `$create = false`, never creating a Theme Builder post),
status `publish`, and the user has `edit_post` + `edit_others_posts` + Divi's Role Editor Theme Builder permission (`ThemeLayouts::userMay()`, fails closed). Body layouts, templates,
the Theme Builder post, orphan/draft-only layouts and every other post type are "not found".
`list_theme_layouts` (MCP/REST/OpenAPI) is read-only. After a layout write `HistoryService`
calls `DiviCompat::clearLayoutCaches()`. The free plugin NEVER creates/deletes/assigns/publishes
templates or writes `_et_use_on`/`_et_default`/`_et_enabled`/`*_layout_id`/`_et_template` (site
settings: Pro add-on territory). Unverified: that a REAL builder-made header passes the validator
(see the spec); until a real export exists the readme claims only what the checker accepts.
```

(c) In the "Entry points" table add after the `make verify-dry-run` row:

```
| `make verify-theme-layouts` | Real-site check of header/footer editing: builds its own Theme Builder fixtures with Divi's functions, drives the REAL handlers, removes only what it created |
```

(d) In "Current state" add a bullet at the top:

```
- **4.3.0 = header/footer release** (branch `feat/theme-layouts`, NOT merged to main on purpose): edit the
  existing live Theme Builder header/footer (spec `docs/superpowers/specs/2026-10-01-theme-layouts-design.md`).
  Held: ships as its own update after the first WordPress.org approval; `main` (4.2.0 + 4.1.0) is the upload candidate.
```

- [ ] **Step 4: Full test run**

Run: `vendor/bin/phpunit` → Expected: all green, no warnings or risky tests.

- [ ] **Step 5: Rebuild the zip and verify it**

Run: `bash scripts/build-plugin-zip.sh` → Expected `[build] Packaging jhmg-ai-editor-for-divi-5 version 4.3.0`. Then:

```bash
S=$(mktemp -d) && unzip -q jhmg-ai-editor-for-divi-5.zip -d $S && diff -r $S/jhmg-ai-editor-for-divi-5 wp-plugin -x .DS_Store && echo "ZIP == wp-plugin"; rm -rf $S
unzip -l jhmg-ai-editor-for-divi-5.zip | grep -E "ThemeLayouts|EditTarget"
```

Expected: `ZIP == wp-plugin` and both new classes listed.

- [ ] **Step 6: Plugin Check — dev copy and extracted zip**

```bash
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin check jhmg-ai-editor-for-divi-5 --format=table
SCRATCH="/private/tmp/claude-501/-Users-Lucas-Documents-JHMG-Local-Divi-5-Deterministic-Validator/7b64f78d-b27d-45c7-bebc-c50841ece09a/scratchpad"
rm -rf "$SCRATCH/zipcheck" && mkdir -p "$SCRATCH/zipcheck"
unzip -q jhmg-ai-editor-for-divi-5.zip -d "$SCRATCH/zipcheck"
docker compose cp "$SCRATCH/zipcheck/jhmg-ai-editor-for-divi-5" wordpress:/var/www/html/wp-content/plugins/jhmg-ai-editor-for-divi-5-zipcheck
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin check jhmg-ai-editor-for-divi-5-zipcheck --slug=jhmg-ai-editor-for-divi-5 --format=table
docker compose exec -T -u root wordpress rm -rf /var/www/html/wp-content/plugins/jhmg-ai-editor-for-divi-5-zipcheck
```

Expected: `Success: Checks complete. No errors found.` for both. Do NOT activate the zip copy and NEVER use `wp plugin delete`. The new classes make no database calls; if Plugin Check flags anything, fix the code (not by weakening anything), then re-run phpunit, rebuild the zip and re-check.

- [ ] **Step 7: Re-run the real-site checks against the final code**

Run `make verify-theme-layouts`, `make verify-dry-run` and `make verify-history` → Expected: all PASS.

- [ ] **Step 8: Commit on the branch (no merge)**

```bash
git add wp-plugin tests CLAUDE.md jhmg-ai-editor-for-divi-5.zip
git commit -m "release: 4.3.0 — edit your existing Theme Builder header and footer (held; not merged)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

Do NOT checkout `main` or merge.

---

## Self-Review

**1. Spec coverage**

| Spec requirement | Task |
|---|---|
| Header/footer layouts of the LIVE Theme Builder only; `edit_post` + `edit_others_posts`; everything else "not found" | 1 (`EditTarget`, `ThemeLayouts`), 2 (nine sites), 4 (real site) |
| One resolver replacing the hard-coded `page` checks (5 MCP + 5 REST + Dashboard restore) | 2 (4 MCP + 4 REST + Dashboard: the history tools share one helper per transport, so nine physical sites cover all eleven entry points) |
| Divi read functions only, `$create = false`, `function_exists` guards, degrade to empty | 1 (seam tests), 4 (counts) |
| `list_theme_layouts` read-only tool in MCP/REST/OpenAPI, 16 tools / 16 operations | 3 |
| Existing page tools take a layout id (`get`, `update`, `edit`, `dry_run`, history, restore) | 2 |
| Cache clear after a layout write (shared write path) | 1 (`HistoryService::afterWrite`, `DiviCompat`) |
| Dashboard restore + Header/Footer labels | 2 |
| Guides; readme claims; CLAUDE.md; compliance pins | 3, 5 |
| Never create/delete/assign/publish templates; assignment meta untouched | Global Constraints; 4 (meta snapshots) |
| Real-site check building/removing its own fixtures | 4 |
| Held release: not merged, version 4.3.0, zip, Plugin Check | 5 + Global Constraints |
| Additive fields (`post_type`, `kind`) | 2, 1 |

Gaps: none.

**2. Placeholder scan:** none; every code step has code or exact commands. Task 4's script is specified step by step (the helpers are copied from `verify-dry-run.php`, which already exists and is the model).

**3. Type consistency:** `EditTarget::resolve()` returns `['post','kind','error']` and the handlers read exactly `error`/`post`; `EditTarget::NOT_FOUND/FORBIDDEN` strings match `EditTargetTest`; `ThemeLayouts::liveLayoutKinds()` (id → kind) is what `EditTarget` indexes; `ThemeLayouts::listing()` keys (`templates`, `count`, `note`) match `theme_layouts()`/`toolListThemeLayouts()` and the OpenAPI schema; `HistoryService::recent()`'s `kind` is what `AdminPage` reads; `DiviCompat::clearLayoutCaches()` is what `HistoryService::afterWrite()` calls; the shim globals (`__divi_tb`, `__divi_tb_calls`, `__divi_cache_clears`, `__divi_static_clears`, `__wp_caps`) are the same in the shims and every test.

**4. Review Focus:** 1 → `EditTargetTest` (every non-editable post type/state), `ThemeLayoutHandlersTest` data providers across all REST/MCP entry points, Task 4 refusals; 2 → `ThemeLayoutsTest`/`EditTargetTest` assert `create === false`, Task 4 counts; 3 → `EditTargetTest`, `ThemeLayoutHandlersTest`, Task 4 `user_has_cap` filter; 4 → Task 4 meta snapshots; 5 → `ThemeLayoutsTest::testWithoutDiviFunctionsTheLiveSetIsEmpty` and the no-live-Theme-Builder tests; 6 → `ThemeLayoutHandlersTest::testPageBehaviourIsUnchanged` plus the whole existing suite.
