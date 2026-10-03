# Pro Add-on 0.3.0: Theme Builder Layout and Template Tools — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Pro add-on four tools that work on the live Divi Theme Builder: `list_theme_layouts`, `update_theme_layout`, `edit_theme_layout` (header and footer content) and `manage_theme_template` (enable, disable or re-target one existing template), each with the narrowest real capability, the shared layout gate, one-step undo, and a live proof on the real Divi.

**Architecture:** A small `ThemeBuilder/` read model (`ThemeLayouts`, moved from the parked 4.3.0 code; `LayoutTarget`, the one rule for "which post may a layout tool touch"; `LayoutCaches`) feeds one shared write path (`LayoutWriter`: resolve → build content from the stored content → `LayoutGate::check($new, $stored)` → free `HistoryService::write` → clear Divi caches → one undoable batch). Two new batch item kinds (`theme_layout_content`, `theme_template`) restore through the existing `BatchUndo` engine, each re-checking the live-layout/template rule and the tools' own capability at undo time. Templates are written ONLY through Divi's own `et_theme_builder_store_template()` with the template's complete current state (that function resets every field it is not given). Tools register through the existing licence-gated `ToolRegistry`; the free plugin is touched only through pinned classes (`LayoutGate` is added to the pins).

**Tech Stack:** PHP 8.1+, PHPUnit (`make test-pro`, shims in `pro-addon/tests/`), WordPress core functions, Divi 5.14's Theme Builder functions on the Docker site for the live scripts. No new dependencies. Nothing in `wp-plugin/` changes.

**Spec:** `docs/superpowers/specs/2026-10-02-pro-addon-design.md` (tool table rows 6–9, "Batches and undo", "Verification", "Risks" 1). Plan 4 of the roadmap in `pro-addon-roadmap`. The parked 4.3.0 source is `pro-addon/staged-4.3.0/` (kept as reference; Task 1 ports `ThemeLayouts` and the layout half of `EditTarget` out of it).

**Baseline:** `main` at `334ce76`; `make test-pro` = 411 tests, `make test` = 726 tests, both green before Task 1. End state: `make test-pro` = 561, `make test` = 726 (this plan touches nothing the main suite runs).

## What the research settled (decisions already made — do not re-litigate)

1. **Registry capability = `edit_theme_options`** for all four tools, PLUS Divi's own bar re-checked inside every tool (`edit_others_posts` and the Role Editor's `theme_builder`, via `ThemeLayouts::userMay()`) and `edit_post` on the specific layout/template. All of them must hold. This is stricter than Divi's own bar on purpose: a header/footer/template change affects every page, and the free plugin's `ExtensionGuard`/WP.org review history says a key owner must not get admin-type actions from a weaker check. Consequence: an editor who was granted Theme Builder through Divi's Role Editor cannot use these tools over MCP.
2. **`undo_batch` only needs `edit_pages`**, so every item kind re-checks for itself: `ThemeLayoutKind::allowed()` and `ThemeTemplateKind::allowed()` require `edit_theme_options` + `edit_post` + the live rule + Divi's bar. Undo is never weaker than the write.
3. **`manage_theme_template` v1 = `assign`, `enable`, `disable` on an EXISTING live template. `create` and `duplicate` are CUT from v1, `delete` was never in v1.** Evidence from the shipped Divi 5.14 source: `et_theme_builder_store_template()` creates or updates ONE template post and its metas, but the mapping of templates onto the live Theme Builder post (`_et_template` meta, the `et_tb_templates_backup_*` option, and `et_theme_builder_trash_draft_and_unused_posts()`) is done inline inside Divi's own AJAX handler `et_theme_builder_api_save()`, not in any public function. So create/duplicate would need a raw meta write, which the spec forbids ("written only via Divi's own functions, never raw meta"). The 4.3.0 spike built its fixtures with a raw `add_post_meta` mapping; that is test setup, not a product path. The spec's tool table and the roadmap are updated in Task 8.
4. **Every real `manage_theme_template` run needs a preview token** (stricter than the roadmap, which asked for it on `assign` only: enabling or disabling a template is just as site-wide). The token is stateless: `wp_hash` over the template id, action, rules and the template's CURRENT state, so a template changed after the preview refuses the old token.
5. **Phase B is gated on live evidence** (Task 7, `make verify-pro-theme-templates`): if any check there FAILS, `manage_theme_template` is cut with the explicit steps in Task 7 and the release ships three tools. The scripts in this plan were already run once against the real Divi 5.14 site while the plan was replayed: Task 5's script and Task 7's script both passed (the only extra meta Divi's own function adds is `_et_onboarding_created`, set to `0`).
6. **Header/footer content writes use the free plugin's `HistoryService`** (same shared history as page edits; 10 snapshots per layout). Divi's caches are cleared after every layout write, template write and layout/template undo, as Divi's own Theme Builder does after a save.
7. **Out of scope (later plans):** REST routes and OpenAPI entries for these tools (Plan 5, with the ChatGPT budget: free 16 + Pro 11 after this plan = 27 of 29; `find_replace_site` and `audit_site` then bring it to exactly 29), the admin "Recent Pro changes" labels for the new kinds beyond the generic item label, `find_replace_site`, `audit_site`.

## Global Constraints

Copied from the spec and `CLAUDE.md`; every task implicitly includes them.

- **The validator stays deterministic and untouched.** Every layout write goes through `\AiEditorDivi5\WP\LayoutGate::check( $content, $storedContent )` with the STORED content as the baseline (docs/EXTENDING.md rule 4). Nothing in this plan edits `src/` or `wp-plugin/`.
- **No Divi schema from memory.** Divi storage facts used here (meta keys `_et_default`, `_et_enabled`, `_et_*_layout_id`, `_et_*_layout_enabled`, `_et_use_on`, `_et_exclude_from`, `_et_autogenerated_title`; the signature and reset-to-defaults behaviour of `et_theme_builder_store_template`) were read from the shipped Divi 5.14 theme source and proven by the live scripts.
- **Extension contract.** The add-on attaches only through the `jhmg_aied_*` hooks (`ToolRegistry`); it checks capabilities itself; it never overrides a built-in tool name.
- **Every tool type-checks arguments** (a non-string, non-int, non-boolean, non-list is rejected with a safe message, never cast) and returns the built-in response shapes. Tool descriptions are at most 300 characters (ChatGPT importer cap; `ToolsTest` enforces it).
- **Every write is a batch**, recorded in `{prefix}aied_pro_batches` (last 20 kept). Undo is honest: changed-since rule, `no_undo_point`, `forbidden`, never "fully undone" unless every item restored.
- **No PHP execution, no custom CSS/JS/PHP saved, no licence gating or remote calls in `wp-plugin/`.** Nothing is pushed, uploaded or released by this plan.
- Namespace `AiEditorDivi5\Pro`; the add-on supports PHP 8.1 (no standalone `null` return types, no `readonly` classes).

## Review Focus

Failure modes the spec implies but a happy-path test would miss, most likely first. Each has a test in the task named in brackets.

1. **A user with only `edit_pages` (all `undo_batch` needs) must not be able to undo a header, footer or template change**, and a layout that stopped being a live header/footer (or a template that stopped being live) must not be restorable. [Task 3, Task 6]
2. **Custom CSS or code smuggled into a header/footer** (`css.freeForm`) must be refused by the shared gate, while code that is ALREADY stored (the baseline) must not block an unrelated edit. [Task 3, Task 4, Task 5]
3. **A template write that silently damages the template**: Divi's `et_theme_builder_store_template()` resets every field it is not given (default flag → 1, layouts → 0, title, lists). The tool must always hand it the complete current state and the live check must prove every other meta is byte-identical afterwards. [Task 6, Task 7]
4. **Undo after the person edited the thing in Divi's own builder**: skipped and reported (`changed_since`), never overwritten; a missing snapshot says `no_undo_point`, never "fully undone". [Task 3, Task 6, Task 5, Task 7]
5. **Addressing the wrong post by id**: a page, a body layout, an orphan header (no live template uses it), a header used only by the draft Theme Builder, a template, the Theme Builder post, a missing id, 0 and negatives must all read as "not an editable header or footer" and change nothing. A stale or replayed template token must be refused. [Task 1, Task 3, Task 5, Task 6]

## Execution notes (read before Task 1)

- **Run the whole plan in the MAIN checkout on a branch**, not in a worktree: `git switch -c feat/pro-theme-layouts` from `main`. The Docker site mounts `./pro-addon` and `./scripts` from the checkout where `docker compose` runs, so the live tasks (5 and 7) only see this branch's code if it is checked out there.
- **Live tasks (5, 7):** the Pro add-on is INACTIVE on the dev site. Activate it for the run and deactivate it afterwards, always with enough memory (Divi 5 exhausts the default 128 MB under plain `wp`): `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin activate jhmg-ai-editor-for-divi-5-pro` … `plugin deactivate …`. The scripts are self-cleaning and run on the dev site only (they evict old batch rows).
- **Commit trailer, exactly:** `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Subagents must not substitute their own model name. Check `git log -1 --format=%B` after every implementer.
- zsh: do not `cd` into subfolders in the Bash tool (the working directory persists); use absolute paths. Do not push, upload or release anything.
- **Final whole-branch review on the most capable model (opus)** after Task 8, then the owner decides about merging.

---

## File Structure

Create (all under `pro-addon/` unless noted):

| Path | Responsibility |
|---|---|
| `src/ThemeBuilder/ThemeLayouts.php` | read-only view of the live Theme Builder: templates, layouts, `userMay()` (Divi's bar), `liveLayoutKinds()` (ported from `staged-4.3.0`, namespace changed) |
| `src/ThemeBuilder/LayoutTarget.php` | the one rule for which post a layout tool may touch; `mustResolve()` throws client-safe messages; `assertMay()` is the tool-level gate |
| `src/ThemeBuilder/LayoutCaches.php` | clears Divi's Theme Builder and static-resource caches (guarded) |
| `src/ThemeBuilder/LayoutWriter.php` | the one write path for a header/footer (gate, history, caches, batch) |
| `src/ThemeBuilder/TemplateStore.php` | reads/writes ONE live template's full state through Divi's `et_theme_builder_store_template()` |
| `src/Kinds/ThemeLayoutKind.php` | batch item kind `theme_layout_content` |
| `src/Kinds/ThemeTemplateKind.php` | batch item kind `theme_template` |
| `src/Tools/ListThemeLayouts.php`, `UpdateThemeLayout.php`, `EditThemeLayout.php`, `ManageThemeTemplate.php` | the four tools |
| `tests/DiviThemeBuilderShims.php`, `tests/ThemeLayoutFixture.php` | Divi function shims (incl. a faithful `et_theme_builder_store_template`) and the shared fake live Theme Builder |
| `tests/ThemeLayoutsTest.php`, `LayoutTargetTest.php`, `ListThemeLayoutsTest.php`, `ThemeLayoutKindTest.php`, `UpdateThemeLayoutTest.php`, `EditThemeLayoutTest.php`, `ThemeLayoutPermissionTest.php`, `ManageThemeTemplateTest.php` | the suites |
| `../scripts/verify-pro-theme-layouts.php`, `../scripts/verify-pro-theme-templates.php` | live, self-cleaning checks on the Docker site |

Modify: `src/autoload.php`, `src/Tools.php`, `src/Batch/Batches.php`, `src/Compat.php`, `src/Kinds/PostContentKind.php` (`undoPoint` becomes public), `tests/ToolsTest.php`, `tests/PluginTest.php`, `tests/ReadmeTest.php`, `tests/FreeApiCompatTest.php`, `tests/bootstrap.php`, `readme.txt`, `jhmg-ai-editor-for-divi-5-pro.php`, `README.md`, `../Makefile`, `../CLAUDE.md`, the spec, and (Task 8) nothing else.

---

### Task 0: Branch

- [ ] **Step 1: Create the branch from main**

```bash
git switch main && git status --short && git switch -c feat/pro-theme-layouts
```
Expected: no output from `git status --short` (clean tree: this plan file is already committed on `main`), then `Switched to a new branch 'feat/pro-theme-layouts'`.

- [ ] **Step 2: Confirm the baseline**

Run: `make test-pro 2>&1 | tail -3` → `OK (411 tests, 1421 assertions)`; `make test 2>&1 | tail -2` → `OK (726 tests, 5241 assertions)`.

### Task 1: The Theme Builder read model (`ThemeLayouts`, `LayoutTarget`, `LayoutCaches`) and the compat pins

**Files:**
- Create: `pro-addon/src/ThemeBuilder/ThemeLayouts.php`, `pro-addon/src/ThemeBuilder/LayoutTarget.php`, `pro-addon/src/ThemeBuilder/LayoutCaches.php`
- Create: `pro-addon/tests/DiviThemeBuilderShims.php`, `pro-addon/tests/ThemeLayoutsTest.php`, `pro-addon/tests/LayoutTargetTest.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Compat.php`, `pro-addon/src/Kinds/PostContentKind.php`, `pro-addon/tests/FreeApiCompatTest.php`

**Interfaces:**
- Produces: `AiEditorDivi5\Pro\ThemeBuilder\ThemeLayouts` (`kindOf(string): ?string`, `userMayWith(bool, ?callable): bool`, `userMay(): bool`, `liveTemplateIdsWith`, `liveTemplateIds(): list<int>`, `liveTemplates(): list<array>`, `liveLayoutKinds(): array<int,string>`, `listing(): array`); `LayoutTarget` (`NOT_FOUND`, `FORBIDDEN`, `resolve(int): array{post,kind,error}`, `mustResolve(int): array{post,kind}` throws `\InvalidArgumentException`, `assertMay(): void`); `LayoutCaches::clear(): void`; `PostContentKind::undoPoint(array): array` becomes `public static` (Task 3 reuses it).
- Consumes: the free plugin's `get_post`, `current_user_can`, Divi's `et_theme_builder_get_theme_builder_post_id`, `et_theme_builder_get_theme_builder_template_ids`, `et_pb_is_allowed` (all guarded by `function_exists`).

This task moves the proven 4.3.0 read model out of `staged-4.3.0/` (leave the staged copy where it is) into the add-on, and adds `LayoutTarget`, which is the layout half of the old free `EditTarget` rewritten for Pro: pages are NOT its job (a page id is "not found" here), so the free page tools and the Pro layout tools can never address each other's posts.

- [ ] **Step 1: Write the shims and the two failing suites**

Create `pro-addon/tests/DiviThemeBuilderShims.php`:

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
if ( ! function_exists( 'et_pb_is_allowed' ) ) {
    function et_pb_is_allowed( $capabilities, $role = '' ) {
        $GLOBALS['__divi_tb_calls'][] = [ 'allowed', $capabilities ];

        return $GLOBALS['__divi_role_allowed'] ?? true;
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

/**
 * A stand-in for Divi's et_theme_builder_store_template(): it follows the real function's rules (read from the shipped
 * Divi 5.14 source): every field it is not given falls back to Divi's DEFAULTS (default '1', enabled '1', layouts 0,
 * autogenerated_title '1', empty lists); a layout id of the wrong post type or unpublished becomes 0; `default` is only
 * honoured when $allow_default is true; the post is updated in place when `id` is a published et_template.
 * Records every call in $GLOBALS['__divi_tb_store_calls']; $GLOBALS['__divi_store_fail'] makes it return false.
 */
if ( ! function_exists( 'et_theme_builder_store_template' ) ) {
    function et_theme_builder_store_template( $theme_builder_id, $template, $allow_default ) {
        $GLOBALS['__divi_tb_store_calls'][] = [ (int) $theme_builder_id, $template, (bool) $allow_default ];
        if ( ! empty( $GLOBALS['__divi_store_fail'] ) ) {
            return false;
        }
        $id     = (int) ( $template['id'] ?? 0 );
        $post   = $GLOBALS['__wp_posts'][ $id ] ?? null;
        if ( ! $post || 'et_template' !== $post->post_type || 'publish' !== $post->post_status ) {
            return false; // the real function would insert a new template here; the tool never asks for that
        }
        $layout = static function ( string $kind ) use ( $template ): array {
            $want  = [ 'header' => 'et_header_layout', 'body' => 'et_body_layout', 'footer' => 'et_footer_layout' ][ $kind ];
            $lid   = (int) ( $template['layouts'][ $kind ]['id'] ?? 0 );
            $lpost = $GLOBALS['__wp_posts'][ $lid ] ?? null;
            $ok    = $lpost && $want === $lpost->post_type && 'publish' === $lpost->post_status;

            return [ $ok ? $lid : 0, (bool) ( $template['layouts'][ $kind ]['enabled'] ?? true ) ];
        };
        $default = $allow_default && '1' === (string) ( $template['default'] ?? '1' );
        $title   = trim( strip_tags( (string) ( $template['title'] ?? '' ) ) );
        if ( $post->post_title !== $title ) {
            $post->post_title = $title;
        }
        $m = &$GLOBALS['__wp_postmeta'][ $id ];
        $m['_et_autogenerated_title'] = '1' === (string) ( $template['autogenerated_title'] ?? '1' ) ? '1' : '0';
        $m['_et_default']             = $default ? '1' : '0';
        $m['_et_enabled']             = '1' === (string) ( $template['enabled'] ?? '1' ) ? '1' : '0';
        foreach ( [ 'header', 'body', 'footer' ] as $kind ) {
            [ $lid, $on ]                      = $layout( $kind );
            $m[ "_et_{$kind}_layout_id" ]      = $lid;
            $m[ "_et_{$kind}_layout_enabled" ] = $on ? '1' : '0';
        }
        $m['_et_use_on']       = array_values( array_unique( array_map( 'strval', (array) ( $template['use_on'] ?? [] ) ) ) );
        $m['_et_exclude_from'] = array_values( array_unique( array_map( 'strval', (array) ( $template['exclude_from'] ?? [] ) ) ) );
        $m['_et_onboarding_created'] = '0';

        return $id;
    }
}
```

(The last function in the shims, `et_theme_builder_store_template`, is used from Task 6; it mirrors the real Divi 5.14 function, read from the shipped source.)

Create `pro-addon/tests/ThemeLayoutsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ThemeBuilder\ThemeLayouts;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';

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
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_role_allowed'] );
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

    public function testUserMayWithFailsClosedAndAsksForTheThemeBuilderCapability(): void
    {
        $this->assertFalse( ThemeLayouts::userMayWith( true, null ), 'a missing Divi function fails closed' );
        $this->assertFalse( ThemeLayouts::userMayWith( true, static fn () => false ) );
        $asked = [];
        $this->assertTrue( ThemeLayouts::userMayWith( true, static function ( $cap ) use ( &$asked ) { $asked[] = $cap; return true; } ) );
        $this->assertSame( [ 'theme_builder' ], $asked );
        $this->assertFalse( ThemeLayouts::userMayWith( false, static fn () => true ) );
    }

    public function testListingIsNotEditableWhenDiviRoleEditorDeniesTheThemeBuilder(): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $l = ThemeLayouts::listing();
        $this->assertFalse( $l['templates'][0]['header']['editable'] );
        $this->assertFalse( $l['templates'][0]['footer']['editable'] );
        $this->assertFalse( $l['templates'][0]['body']['editable'] );
        $this->assertFalse( $l['templates'][1]['header']['editable'] );
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

Create `pro-addon/tests/LayoutTargetTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';

class LayoutTargetTest extends TestCase
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
        $GLOBALS['__wp_posts']      = [];
        $GLOBALS['__wp_postmeta']   = [];
        $GLOBALS['__divi_tb']       = [ 'tb_id' => 100, 'draft_id' => 300, 'template_ids' => [ 200 ], 'draft_template_ids' => [ 301 ] ];
        $GLOBALS['__divi_tb_calls'] = [];
        $GLOBALS['__wp_caps']       = [ 'edit_post' => true, 'edit_others_posts' => true ];
        unset( $GLOBALS['__divi_role_allowed'] );

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
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_role_allowed'] );
        $GLOBALS['__wp_can']      = true;
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [];
    }

    public function testALiveHeaderAndFooterAreResolved(): void
    {
        $h = LayoutTarget::resolve( 201 );
        $this->assertSame( [ 201, 'header', null ], [ $h['post']->ID, $h['kind'], $h['error'] ] );
        $f = LayoutTarget::resolve( 202 );
        $this->assertSame( [ 202, 'footer', null ], [ $f['post']->ID, $f['kind'], $f['error'] ] );
    }

    public function testALiveLayoutNeedsBothEditPostAndEditOthersPosts(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => false ];
        $this->assertSame( LayoutTarget::FORBIDDEN, LayoutTarget::resolve( 201 )['error'] );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => false, 'edit_others_posts' => true ];
        $this->assertSame( LayoutTarget::FORBIDDEN, LayoutTarget::resolve( 201 )['error'] );
        $GLOBALS['__wp_caps'] = [];
        $this->assertSame( LayoutTarget::FORBIDDEN, LayoutTarget::resolve( 202 )['error'] );
    }

    /** @return list<array{int,string}> */
    public static function neverEditable(): array
    {
        return [
            [ 5, 'a page (the free plugin\'s job)' ],
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

    #[DataProvider( 'neverEditable' )]
    public function testEverythingElseIsReportedAsNotFound( int $id, string $why ): void
    {
        $r = LayoutTarget::resolve( $id );
        $this->assertSame( LayoutTarget::NOT_FOUND, $r['error'], $why );
        $this->assertNull( $r['post'], $why );
        $this->assertNull( $r['kind'], $why );
    }

    public function testNotFoundEvenForAnAdministratorWhoCanDoEverything(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'manage_options' => true, 'edit_theme_options' => true ];
        $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( 203 )['error'] );
        $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( 204 )['error'] );
        $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( 5 )['error'] );
    }

    public function testAnUnpublishedOrTrashedLiveLayoutIsNotFound(): void
    {
        foreach ( [ 'draft', 'trash', 'pending', 'private' ] as $status ) {
            $this->post( 201, 'et_header_layout', $status );
            $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( 201 )['error'], $status );
        }
    }

    public function testWithoutALiveThemeBuilderEveryLayoutIsNotFound(): void
    {
        $GLOBALS['__divi_tb']['tb_id'] = 0;
        $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( 201 )['error'] );
    }

    public function testResolvingNeverCreatesAThemeBuilderPost(): void
    {
        $postsBefore = count( $GLOBALS['__wp_posts'] );
        LayoutTarget::resolve( 201 );
        LayoutTarget::resolve( 204 );
        $this->assertNotEmpty( $GLOBALS['__divi_tb_calls'] );
        foreach ( $GLOBALS['__divi_tb_calls'] as $call ) {
            if ( 'post_id' === $call[0] ) {
                $this->assertFalse( $call[2], 'create must always be false' );
            }
        }
        $this->assertCount( $postsBefore, $GLOBALS['__wp_posts'], 'no post was created while resolving' );
    }

    public function testALiveLayoutIsForbiddenWhenDiviDeniesTheThemeBuilderCapability(): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $this->assertSame( LayoutTarget::FORBIDDEN, LayoutTarget::resolve( 201 )['error'] );
        $this->assertSame( LayoutTarget::FORBIDDEN, LayoutTarget::resolve( 202 )['error'] );
        foreach ( [ 203, 204, 302, 200 ] as $id ) {
            $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( $id )['error'], (string) $id );
        }
    }

    public function testThePerIdEditPostCheckIsHonoured(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_post:202' => false ];
        $this->assertNull( LayoutTarget::resolve( 201 )['error'] );
        $this->assertSame( LayoutTarget::FORBIDDEN, LayoutTarget::resolve( 202 )['error'] );
    }

    public function testAFooterLayoutInTheHeaderSlotIsNotFound(): void
    {
        $this->post( 205, 'et_footer_layout' );
        $this->post( 206, 'et_template', 'publish', [ '_et_header_layout_id' => 205 ] );
        $GLOBALS['__divi_tb']['template_ids'] = [ 200, 206 ];
        $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( 205 )['error'] );
    }

    public function testMustResolveThrowsSafeMessages(): void
    {
        $this->assertSame( 'header', LayoutTarget::mustResolve( 201 )['kind'] );

        try {
            LayoutTarget::mustResolve( 5 );
            $this->fail( 'a page must be refused' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'list_theme_layouts', $e->getMessage() );
        }

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_post:201' => false ];
        try {
            LayoutTarget::mustResolve( 201 );
            $this->fail( 'a layout the user may not edit must be refused' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'permission', $e->getMessage() );
        }
    }

    public function testAssertMayPassesForAUserWithTheThemeBuilderBar(): void
    {
        LayoutTarget::assertMay();
        $this->addToAssertionCount( 1 );
    }

    public function testAssertMayFailsClosedWithoutTheThemeBuilderPermission(): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Theme Builder permission' );
        LayoutTarget::assertMay();
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter 'ThemeLayoutsTest|LayoutTargetTest' 2>&1 | tail -8`
Expected: errors such as `Class "AiEditorDivi5\Pro\ThemeBuilder\ThemeLayouts" not found`.

- [ ] **Step 3: Write the three source files**

Create `pro-addon/src/ThemeBuilder/ThemeLayouts.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\ThemeBuilder;

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
     * Pure seam for userMay(): the user may touch Theme Builder content only with edit_others_posts AND
     * Divi's own 'theme_builder' capability. Fails closed when Divi's capability function is missing.
     */
    public static function userMayWith( bool $canEditOthers, ?callable $isAllowed ): bool
    {
        return $canEditOthers && null !== $isAllowed && (bool) $isAllowed( 'theme_builder' );
    }

    /**
     * The single definition of "may this user touch Theme Builder content": it mirrors Divi's own bar
     * (edit_others_posts plus the Divi Role Editor's 'theme_builder' capability).
     */
    public static function userMay(): bool
    {
        return self::userMayWith(
            current_user_can( 'edit_others_posts' ),
            function_exists( 'et_pb_is_allowed' ) ? 'et_pb_is_allowed' : null
        );
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
                $template[ $kind ]['editable'] = 'body' !== $kind && current_user_can( 'edit_post', (int) $template[ $kind ]['layout_id'] ) && self::userMay();
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

Create `pro-addon/src/ThemeBuilder/LayoutTarget.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\ThemeBuilder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one rule for "which post may a Pro layout tool read and edit": a header or footer layout that a template of
 * the LIVE Divi Theme Builder uses, and only for a user who passes Divi's own Theme Builder bar. Pages are the free
 * plugin's job, so a page id is reported like any other post: not found. Nothing leaks about other post types.
 */
final class LayoutTarget
{
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';

    public const NO_THEME_BUILDER_PERMISSION = 'You need Divi\'s Theme Builder permission (and the right to edit other people\'s posts) to use this tool.';

    /**
     * @return array{post: ?\WP_Post, kind: ?string, error: ?string} kind is 'header' or 'footer'
     */
    public static function resolve( int $id ): array
    {
        $post = $id > 0 ? get_post( $id ) : null;
        if ( ! $post ) {
            return self::fail( self::NOT_FOUND );
        }
        $postKind = ThemeLayouts::kindOf( (string) $post->post_type );
        $kind     = null === $postKind ? null : ( ThemeLayouts::liveLayoutKinds()[ $id ] ?? null );
        if ( null === $kind || $postKind !== $kind || 'publish' !== $post->post_status ) {
            return self::fail( self::NOT_FOUND );
        }
        // Divi's own bar (edit_others_posts and the Role Editor's 'theme_builder'); a header or footer shows on every page.
        if ( ! current_user_can( 'edit_post', $id ) || ! ThemeLayouts::userMay() ) {
            return self::fail( self::FORBIDDEN );
        }

        return [ 'post' => $post, 'kind' => $kind, 'error' => null ];
    }

    /**
     * Like resolve(), but throws a message that is safe to show the client.
     *
     * @return array{post: \WP_Post, kind: string}
     * @throws \InvalidArgumentException
     */
    public static function mustResolve( int $id ): array
    {
        self::assertMay();
        $r = self::resolve( $id );
        if ( self::NOT_FOUND === $r['error'] ) {
            throw new \InvalidArgumentException( "Layout {$id} is not an editable header or footer of the live Theme Builder. Call list_theme_layouts to see the layout ids." );
        }
        if ( null !== $r['error'] || null === $r['post'] || null === $r['kind'] ) {
            throw new \InvalidArgumentException( "You do not have permission to edit layout {$id}." );
        }

        return [ 'post' => $r['post'], 'kind' => $r['kind'] ];
    }

    /** The tool-level gate, in addition to the registry's WordPress capability. */
    public static function assertMay(): void
    {
        if ( ! ThemeLayouts::userMay() ) {
            throw new \InvalidArgumentException( self::NO_THEME_BUILDER_PERMISSION );
        }
    }

    /** @return array{post: null, kind: null, error: string} */
    private static function fail( string $error ): array
    {
        return [ 'post' => null, 'kind' => null, 'error' => $error ];
    }
}
```

Create `pro-addon/src/ThemeBuilder/LayoutCaches.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\ThemeBuilder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * After a header, footer or template changes: clear Divi's own caches the way Divi's Theme Builder does when it saves
 * (a layout is used by many pages). Each call is guarded, so nothing happens when Divi, or that part of it, is missing.
 */
final class LayoutCaches
{
    public static function clear(): void
    {
        if ( function_exists( 'et_theme_builder_clear_wp_cache' ) ) {
            et_theme_builder_clear_wp_cache( 'all' );
        }
        if ( class_exists( 'ET_Core_PageResource' ) ) {
            \ET_Core_PageResource::remove_static_resources( 'all', 'all' );
        }
    }
}
```

- [ ] **Step 4: Register them in the autoloader, pin `LayoutGate`, make `undoPoint` public**

In `pro-addon/src/autoload.php`, after the line `require_once __DIR__ . '/Kinds/PostContentKind.php';` add:

```php
require_once __DIR__ . '/ThemeBuilder/ThemeLayouts.php';
require_once __DIR__ . '/ThemeBuilder/LayoutTarget.php';
require_once __DIR__ . '/ThemeBuilder/LayoutCaches.php';
```

In `pro-addon/src/Compat.php`, in `REQUIRED_CLASSES`, after the `ExtensionGuard` line add:

```php
        'AiEditorDivi5\\WP\\LayoutGate',
```

In `pro-addon/tests/FreeApiCompatTest.php`, in `signatures()`, directly before the `DryRun`/`preview` row add:

```php
            [ $w . 'LayoutGate', 'check', [ 'content' => 'string', 'baseline' => '?string' ], 'Divi5Validator\\ValidationResult' ],
```

In `pro-addon/src/Kinds/PostContentKind.php` change `private static function undoPoint(` to `public static function undoPoint(` (the body is unchanged).

- [ ] **Step 5: Run the whole Pro suite**

Run: `make test-pro 2>&1 | tail -3`
Expected: `OK (455 tests, 1556 assertions)` (411 + the two new suites + one new compat row).

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src pro-addon/tests
git commit -q -F - <<'EOF'
feat(pro): Theme Builder read model (ThemeLayouts, LayoutTarget, LayoutCaches) and LayoutGate pin

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).

### Task 2: `list_theme_layouts`

**Files:**
- Create: `pro-addon/src/Tools/ListThemeLayouts.php`, `pro-addon/tests/ListThemeLayoutsTest.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Tools.php`, `pro-addon/tests/ToolsTest.php`, `pro-addon/tests/PluginTest.php`, `pro-addon/readme.txt`

**Interfaces:**
- Consumes: `LayoutTarget::assertMay()`, `LayoutTarget::mustResolve(int)`, `ThemeLayouts::listing()`, `Args::int()`.
- Produces: `AiEditorDivi5\Pro\Tools\ListThemeLayouts` (`NAME = 'list_theme_layouts'`, capability `edit_theme_options`, no constructor arguments). Result without `layout_id` = `ThemeLayouts::listing()`; with it = `['layout' => ['layout_id','kind','title','content']]`.

- [ ] **Step 1: Write the failing test**

Create `pro-addon/tests/ListThemeLayoutsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\ListThemeLayouts;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';

class ListThemeLayoutsTest extends TestCase
{
    private ListThemeLayouts $tool;

    /** @param array<string,mixed> $meta */
    private function post( int $id, string $type, array $meta = [], string $content = '' ): void
    {
        $p               = new \WP_Post();
        $p->ID           = $id;
        $p->post_type    = $type;
        $p->post_status  = 'publish';
        $p->post_title   = "Post {$id}";
        $p->post_content = $content;
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    protected function setUp(): void
    {
        $this->tool                 = new ListThemeLayouts();
        $GLOBALS['__wp_posts']      = [];
        $GLOBALS['__wp_postmeta']   = [];
        $GLOBALS['__divi_tb']       = [ 'tb_id' => 100, 'draft_id' => 0, 'template_ids' => [ 200 ], 'draft_template_ids' => [] ];
        $GLOBALS['__divi_tb_calls'] = [];
        $GLOBALS['__wp_caps']       = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_theme_options' => true ];
        unset( $GLOBALS['__divi_role_allowed'] );
        $this->post( 100, 'et_theme_builder' );
        $this->post( 200, 'et_template', [ '_et_default' => '1', '_et_enabled' => '1', '_et_header_layout_id' => 201, '_et_header_layout_enabled' => '1', '_et_body_layout_id' => 203, '_et_body_layout_enabled' => '1' ] );
        $this->post( 201, 'et_header_layout', [], '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->' );
        $this->post( 203, 'et_body_layout' );
        $this->post( 5, 'page', [], 'PAGE CONTENT' );
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_role_allowed'] );
        $GLOBALS['__wp_can']      = true;
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [];
    }

    public function testWithoutAnIdItListsTemplatesAndTheirLayouts(): void
    {
        $r = $this->tool->run( [] );
        $this->assertSame( 1, $r['count'] );
        $this->assertSame( 201, $r['templates'][0]['header']['layout_id'] );
        $this->assertTrue( $r['templates'][0]['header']['editable'] );
        $this->assertFalse( $r['templates'][0]['body']['editable'], 'a body layout is listed but never editable' );
        $this->assertArrayNotHasKey( 'footer', $r['templates'][0] );
    }

    public function testWithAnIdItAlsoReturnsThatLayoutsContent(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201 ] );
        $this->assertSame( [ 'layout_id' => 201, 'kind' => 'header', 'title' => 'Post 201', 'content' => '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->' ], $r['layout'] );
    }

    public function testAPageABodyLayoutAndAnUnknownIdAreRefusedAndNeverLeakContent(): void
    {
        foreach ( [ 5, 203, 200, 9999 ] as $id ) {
            try {
                $this->tool->run( [ 'layout_id' => $id ] );
                $this->fail( "layout {$id} must be refused" );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringNotContainsString( 'PAGE CONTENT', $e->getMessage() );
                $this->assertStringContainsString( 'not an editable header or footer', $e->getMessage() );
            }
        }
    }

    public function testBadArgumentsAreRejectedNotCast(): void
    {
        foreach ( [ '201', 201.0, true, [ 201 ], 0, -1 ] as $bad ) {
            try {
                $this->tool->run( [ 'layout_id' => $bad ] );
                $this->fail( 'a bad layout_id must be rejected: ' . json_encode( $bad ) );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( 'layout_id', $e->getMessage() );
            }
        }
    }

    public function testWithoutDivisThemeBuilderPermissionNothingIsReturned(): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Theme Builder permission' );
        $this->tool->run( [] );
    }

    public function testReadingNeverWritesOrCreatesAnything(): void
    {
        $before = [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        $this->tool->run( [] );
        $this->tool->run( [ 'layout_id' => 201 ] );
        $this->assertEquals( $before, [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ] );
        foreach ( $GLOBALS['__divi_tb_calls'] as $call ) {
            if ( 'post_id' === $call[0] ) {
                $this->assertFalse( $call[2], 'create must be false' );
            }
        }
    }

    public function testItIsRegisteredAndNeedsEditThemeOptions(): void
    {
        $this->assertContains( 'list_theme_layouts', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $this->tool->definition()['description'] ) );
        $this->assertSame( [], $this->tool->definition()['inputSchema']['required'] );
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter ListThemeLayoutsTest 2>&1 | tail -6`
Expected: `Class "AiEditorDivi5\Pro\Tools\ListThemeLayouts" not found`.

- [ ] **Step 3: Write the tool**

Create `pro-addon/src/Tools/ListThemeLayouts.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use AiEditorDivi5\Pro\ThemeBuilder\ThemeLayouts;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Read-only: the live Theme Builder's templates with their header, footer and body layouts; one header or footer's content on request. */
final class ListThemeLayouts implements ProTool
{
    public const NAME = 'list_theme_layouts';

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_theme_options';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'List the live Divi Theme Builder templates and their header, footer and body layouts (read-only). With layout_id of a header or footer, also returns its content. Edit one with update_theme_layout or edit_theme_layout (dry_run first: it shows on every page).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'layout_id' => [ 'type' => 'integer', 'description' => 'Optional. A header or footer layout id from this list; adds its content to the answer' ],
                ],
                'required'   => [],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        LayoutTarget::assertMay();
        $id = Args::int( $arguments, 'layout_id', false );
        if ( null === $id ) {
            return ThemeLayouts::listing();
        }
        $target = LayoutTarget::mustResolve( $id );

        return [
            'layout' => [
                'layout_id' => $id,
                'kind'      => $target['kind'],
                'title'     => (string) $target['post']->post_title,
                'content'   => (string) $target['post']->post_content,
            ],
        ];
    }
}
```

- [ ] **Step 4: Register it and update the pinned tests**

`pro-addon/src/autoload.php`: after `require_once __DIR__ . '/Tools/UndoBatch.php';` add `require_once __DIR__ . '/Tools/ListThemeLayouts.php';`.

`pro-addon/src/Tools.php`: add `use AiEditorDivi5\Pro\Tools\ListThemeLayouts;` after the `ListBatches` import, and in `all()` add `new ListThemeLayouts(),` after `new UndoBatch( $log, Batches::undo() ),`.

`pro-addon/tests/ToolsTest.php`: rename `testThisReleaseShipsExactlyTheSevenSettingsAndBatchTools` to `testThisReleaseShipsExactlyTheseTools` and append `'list_theme_layouts'` to its expected list (after `'undo_batch'`).

`pro-addon/tests/PluginTest.php`: rename `testWhileUnlockedTheSevenProToolsAreAdvertised` to `testWhileUnlockedEveryProToolIsAdvertised` and replace `$this->assertCount( 8, $names );` with:

```php
        $this->assertCount( 1 + count( \AiEditorDivi5\Pro\Tools::all() ), $names, 'the built-in plus every Pro tool' );
```

`pro-addon/readme.txt`: in the `= Tools =` list, before the `list_batches` bullet add:

```
* `list_theme_layouts` - list the live Divi Theme Builder templates and their header, footer and body layouts (read-only); with a header or footer id it also returns that layout's content.
```

- [ ] **Step 5: Run the whole Pro suite**

Run: `make test-pro 2>&1 | tail -3` → `OK (464 tests, 1597 assertions)`.

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src pro-addon/tests pro-addon/readme.txt
git commit -q -F - <<'EOF'
feat(pro): list_theme_layouts (read-only; edit_theme_options plus Divi's Theme Builder bar)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).


### Task 3: The shared layout write path, the `theme_layout_content` undo kind, and `update_theme_layout`

**Files:**
- Create: `pro-addon/src/Kinds/ThemeLayoutKind.php`, `pro-addon/src/ThemeBuilder/LayoutWriter.php`, `pro-addon/src/Tools/UpdateThemeLayout.php`
- Create: `pro-addon/tests/ThemeLayoutFixture.php`, `pro-addon/tests/ThemeLayoutKindTest.php`, `pro-addon/tests/UpdateThemeLayoutTest.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Tools.php`, `pro-addon/src/Batch/Batches.php`, `pro-addon/tests/ToolsTest.php`, `pro-addon/tests/ReadmeTest.php`, `pro-addon/readme.txt`, `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`

**Interfaces:**
- Consumes (Task 1/2): `LayoutTarget::mustResolve/assertMay/resolve`, `LayoutCaches::clear()`, `PostContentKind::undoPoint()` (public), `Args`, `BatchLog::record/attach`, `Item::make`. From the free plugin (pinned): `HistoryService::write(int,string,string): array`, `HistoryService::restore(int,int): array`, `LayoutGate::check(string,?string): ValidationResult`, `DryRun::preview(string,string): array`, `StyleReferences::attach(array,string): array`.
- Produces: `ThemeLayoutKind` (`NAME = 'theme_layout_content'`; `itemFor(int $layoutId, string $title, string $kind, string $newContent, array $snapshot): array`; constructor `(?\Closure $restorer = null, ?\Closure $afterRestore = null)`); `LayoutWriter` (`MAX_BYTES`, `NOTE`, `DRY_NOTE`; constructor `(BatchLog $log, ?\Closure $write = null)`; `apply(int $layoutId, string $tool, \Closure $make, bool $dry): array` where `$make(string $stored): array{content:string, extra:array}` may throw `\InvalidArgumentException`); `UpdateThemeLayout` (`NAME = 'update_theme_layout'`, constructor `(BatchLog $log, ?LayoutWriter $writer = null)`); the test trait `ThemeLayoutFixture` (`setUpThemeBuilder()`, `tearDownThemeBuilder()`, `undoEngine()`, `layoutContent(int)`, `historyRows(int)`, `header()`, `otherHeader()`, `invalid()`, `withCss(string)`), which Tasks 4 and 6 reuse.

Why the kind re-checks everything: `undo_batch` needs only `edit_pages`, so `ThemeLayoutKind::allowed()` demands `edit_theme_options` AND that the layout is STILL a live header/footer the user may edit under Divi's bar. Undo uses the free `HistoryService::restore`, which by design does not re-validate (it brings back the person's own earlier content).

- [ ] **Step 1: Write the fixture and the failing suites**

Create `pro-addon/tests/ThemeLayoutFixture.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\ThemeLayoutKind;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/../../tests/FakeWpdb.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

/**
 * A fake live Theme Builder (template 200 with header 201, footer 202 and body 203), an orphan header 204, a page 5,
 * the real free history on a fake wpdb, and an in-memory batch log. Use in a TestCase's setUp()/tearDown().
 */
trait ThemeLayoutFixture
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;

    private static function header(): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    /** A valid layout that differs from header(): a search module instead of a shop. */
    private static function otherHeader(): string
    {
        return str_replace( 'wp:divi/shop {"builderVersion":"5.14.0"} /', 'wp:divi/search {"builderVersion":"5.14.0"} /', self::header() );
    }

    private static function invalid(): string
    {
        return str_replace( 'wp:divi/shop', 'wp:divi/not-a-module', self::header() );
    }

    /** header() with a custom-CSS attribute on the shop module. */
    private static function withCss( string $css ): string
    {
        return str_replace( '{"builderVersion":"5.14.0"} /--><!-- /wp:divi/column', '{"builderVersion":"5.14.0","css":{"desktop":{"value":{"freeForm":"' . $css . '"}}}} /--><!-- /wp:divi/column', self::header() );
    }

    /** @param array<string,mixed> $meta */
    private function themePost( int $id, string $type, string $status = 'publish', array $meta = [], string $content = '' ): void
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

    private function setUpThemeBuilder(): void
    {
        $GLOBALS['wpdb'] = new \Divi5Validator\Tests\FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']        = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_posts']          = [];
        $GLOBALS['__wp_postmeta']       = [];
        $GLOBALS['__wp_update_fail']    = false;
        $GLOBALS['__wp_update_calls']   = 0;
        $GLOBALS['__divi_cache_clears'] = [];
        $GLOBALS['__divi_static_clears'] = [];
        $GLOBALS['__divi_tb']           = [ 'tb_id' => 100, 'draft_id' => 0, 'template_ids' => [ 200 ], 'draft_template_ids' => [] ];
        $GLOBALS['__divi_tb_calls']     = [];
        $GLOBALS['__wp_caps']           = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true, 'edit_theme_options' => true ];
        unset( $GLOBALS['__divi_role_allowed'] );

        $this->themePost( 100, 'et_theme_builder' );
        $this->themePost( 200, 'et_template', 'publish', [ '_et_default' => '0', '_et_enabled' => '1', '_et_header_layout_id' => 201, '_et_footer_layout_id' => 202, '_et_body_layout_id' => 203 ] );
        $this->themePost( 201, 'et_header_layout', 'publish', [], self::header() );
        $this->themePost( 202, 'et_footer_layout', 'publish', [], self::header() );
        $this->themePost( 203, 'et_body_layout', 'publish', [], self::header() );
        $this->themePost( 204, 'et_header_layout', 'publish', [], self::header() );   // orphan: no live template uses it
        $this->themePost( 5, 'page', 'publish', [], self::header() );

        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
    }

    private function tearDownThemeBuilder(): void
    {
        $GLOBALS['wpdb']         = null;
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__divi_tb']    = [];
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_role_allowed'] );
        $GLOBALS['__wp_can'] = true;
    }

    private function undoEngine(): BatchUndo
    {
        return new BatchUndo( [ ThemeLayoutKind::NAME => new ThemeLayoutKind() ], $this->log );
    }

    private function layoutContent( int $id ): string
    {
        return $GLOBALS['__wp_posts'][ $id ]->post_content;
    }

    private function historyRows( int $id ): int
    {
        return count( HistoryStore::summaries( $id ) );
    }
}
```

Create `pro-addon/tests/ThemeLayoutKindTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\ThemeLayoutKind;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';

class ThemeLayoutKindTest extends TestCase
{
    use ThemeLayoutFixture;

    /** @var list<array{int,int}> */
    private array $restored = [];
    private int $cleared    = 0;

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $this->restored = [];
        $this->cleared  = 0;
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
    }

    private function kind( bool $ok = true ): ThemeLayoutKind
    {
        return new ThemeLayoutKind(
            function ( int $id, int $version ) use ( $ok ): array {
                $this->restored[] = [ $id, $version ];
                if ( ! $ok ) {
                    return [ 'ok' => false, 'error' => 'version_not_found' ];
                }
                $GLOBALS['__wp_posts'][ $id ]->post_content = 'OLD';

                return [ 'ok' => true, 'snapshot' => [ 'stored' => true, 'version_id' => 9, 'reason' => null ] ];
            },
            function (): void {
                $this->cleared++;
            }
        );
    }

    /** @return array<string,mixed> */
    private function item(): array
    {
        return ThemeLayoutKind::itemFor( 201, 'Main Header', 'header', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] );
    }

    public function testItemForNamesTheKindAndRecordsTheUndoPoint(): void
    {
        $item = $this->item();
        $this->assertSame( 'theme_layout_content', $item['kind'] );
        $this->assertSame( '201', $item['ref'] );
        $this->assertSame( 'Content of the header "Main Header" (#201)', $item['label'] );
        $this->assertSame( Canon::hash( 'NEW' ), $item['after_hash'] );
        $this->assertSame( [ 'available' => true, 'reason' => null, 'version_id' => 3 ], $item['undo'] );
    }

    public function testAllowedNeedsTheToolsCapabilityAndALiveEditableLayout(): void
    {
        $this->assertTrue( $this->kind()->allowed( [ 'ref' => '201' ] ) );
        $this->assertFalse( $this->kind()->allowed( [ 'ref' => '203' ] ), 'a body layout' );
        $this->assertFalse( $this->kind()->allowed( [ 'ref' => '5' ] ), 'a page' );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true ];
        $this->assertFalse( $this->kind()->allowed( [ 'ref' => '201' ] ), 'no edit_theme_options' );
    }

    public function testRestoreUsesTheRecordedVersionClearsCachesAndBuildsTheInverse(): void
    {
        $r = $this->kind()->restore( $this->item() );
        $this->assertSame( [ [ 201, 3 ] ], $this->restored );
        $this->assertSame( 1, $this->cleared );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'theme_layout_content', $r['inverse']['kind'] );
        $this->assertSame( Canon::hash( 'OLD' ), $r['inverse']['after_hash'] );
        $this->assertSame( [ 'available' => true, 'reason' => null, 'version_id' => 9 ], $r['inverse']['undo'] );
    }

    public function testAFailedRestoreClearsNothingAndReportsTheReason(): void
    {
        $this->assertSame( [ 'ok' => false, 'reason' => 'version_not_found' ], $this->kind( false )->restore( $this->item() ) );
        $this->assertSame( 0, $this->cleared );
    }

    public function testWithoutAVersionNothingIsRestored(): void
    {
        $item                       = $this->item();
        $item['undo']['version_id'] = null;
        $this->assertSame( [ 'ok' => false, 'reason' => 'no_version' ], $this->kind()->restore( $item ) );
        $this->assertSame( [], $this->restored );
        $this->assertSame( 0, $this->cleared );
    }
}
```

Create `pro-addon/tests/UpdateThemeLayoutTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Tools\UpdateThemeLayout;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutWriter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';

class UpdateThemeLayoutTest extends TestCase
{
    use ThemeLayoutFixture;

    private UpdateThemeLayout $tool;

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $this->tool = new UpdateThemeLayout( $this->log );
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
    }

    private function refused( array $args, string $message ): void
    {
        $before = [ $this->layoutContent( 201 ), $GLOBALS['__wp_update_calls'], $this->historyRows( 201 ), count( $this->repo->rows ), $GLOBALS['__divi_cache_clears'] ];
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $after = [ $this->layoutContent( 201 ), $GLOBALS['__wp_update_calls'], $this->historyRows( 201 ), count( $this->repo->rows ), $GLOBALS['__divi_cache_clears'] ];
        $this->assertSame( $before, $after, 'a refused call changes, snapshots, records and clears nothing' );
    }

    public function testItSavesAValidLayoutSnapshotsClearsCachesAndRecordsAnUndoableBatch(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ 'layout_id' => 201, 'kind' => 'header', 'title' => 'Post 201' ], $r['layout'] );
        $this->assertSame( self::otherHeader(), $this->layoutContent( 201 ), 'stored byte for byte' );
        $this->assertTrue( $r['snapshot']['stored'] );
        $this->assertSame( 1, $this->historyRows( 201 ) );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'], 'Divi\'s Theme Builder cache cleared' );
        $this->assertSame( [ [ 'all', 'all' ] ], $GLOBALS['__divi_static_clears'], 'Divi\'s static resources cleared' );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( 'update_theme_layout', $this->repo->rows[ $r['batch']['id'] ]['tool'] );
        $this->assertSame( self::header(), $this->layoutContent( 202 ), 'other layouts are untouched' );
    }

    public function testAFooterWorksToo(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 202, 'content' => self::otherHeader() ] );
        $this->assertSame( 'footer', $r['layout']['kind'] );
        $this->assertSame( self::otherHeader(), $this->layoutContent( 202 ) );
    }

    public function testAnInvalidLayoutIsRefusedWithTheViolationsAndNothingChanges(): void
    {
        $this->refused( [ 'layout_id' => 201, 'content' => self::invalid() ], 'The layout was not saved' );
    }

    public function testCustomCssIsRefusedByTheSharedGate(): void
    {
        $this->refused( [ 'layout_id' => 201, 'content' => self::withCss( 'selector{color:red}' ) ], 'CUSTOM_CSS_NOT_ALLOWED' );
    }

    public function testCodeAlreadyStoredIsPreservedNotNewlyForbidden(): void
    {
        // The baseline rule: a layout that already carries CSS can be edited as long as the edit adds no code.
        $css                                      = self::withCss( 'selector{color:red}' );
        $GLOBALS['__wp_posts'][201]->post_content = $css;
        $r                                        = $this->tool->run( [ 'layout_id' => 201, 'content' => str_replace( 'wp:divi/shop', 'wp:divi/search', $css ) ] );
        $this->assertTrue( $r['changed'] );
        $this->assertStringContainsString( 'freeForm', $this->layoutContent( 201 ), 'the CSS that was already there is kept' );
        $this->assertStringContainsString( 'wp:divi/search', $this->layoutContent( 201 ) );
    }

    public function testAnUnchangedLayoutChangesAndRecordsNothing(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::header() ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( 0, $this->historyRows( 201 ) );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testADryRunPreviewsAndTouchesNothing(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader(), 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['saved'] );
        $this->assertTrue( $r['valid'] );
        $this->assertSame( LayoutWriter::DRY_NOTE, $r['note'] );
        $this->assertNotNull( $r['diff'], 'the preview carries the structured diff' );
        $this->assertSame( self::header(), $this->layoutContent( 201 ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( 0, $this->historyRows( 201 ) );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testADryRunOfAnInvalidLayoutIsANormalPreviewWithValidFalse(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::invalid(), 'dry_run' => true ] );
        $this->assertFalse( $r['valid'] );
        $this->assertNotEmpty( $r['violations'] );
        $this->assertSame( self::header(), $this->layoutContent( 201 ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    /** @return list<array{int,string}> */
    public static function notEditable(): array
    {
        return [ [ 5, 'a page' ], [ 203, 'a body layout' ], [ 204, 'an orphaned header' ], [ 200, 'a template' ], [ 100, 'the Theme Builder post' ], [ 9999, 'a missing post' ] ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'notEditable' )]
    public function testOnlyLiveHeadersAndFootersCanBeWritten( int $id, string $why ): void
    {
        $before = $this->layoutContent( 5 );
        try {
            $this->tool->run( [ 'layout_id' => $id, 'content' => self::otherHeader() ] );
            $this->fail( "{$why} must be refused" );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'not an editable header or footer', $e->getMessage(), $why );
        }
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], $why );
        $this->assertSame( $before, $this->layoutContent( 5 ), $why );
    }

    public function testBadArgumentsAreRejectedNotCast(): void
    {
        $this->refused( [ 'content' => self::otherHeader() ], '"layout_id" is required.' );
        $this->refused( [ 'layout_id' => '201', 'content' => self::otherHeader() ], '"layout_id" must be an integer.' );
        $this->refused( [ 'layout_id' => 201 ], '"content" is required.' );
        $this->refused( [ 'layout_id' => 201, 'content' => 5 ], '"content" must be a string.' );
        $this->refused( [ 'layout_id' => 201, 'content' => '   ' ], '"content" must not be empty.' );
        $this->refused( [ 'layout_id' => 201, 'content' => self::otherHeader(), 'dry_run' => 'yes' ], '"dry_run" must be true or false.' );
        $this->refused( [ 'layout_id' => 201, 'content' => str_repeat( 'a', LayoutWriter::MAX_BYTES + 1 ) ], 'at most' );
    }

    public function testAFailedSaveIsAnErrorWithNoBatchAndNoCacheClear(): void
    {
        $GLOBALS['__wp_update_fail'] = true;
        try {
            $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );
            $this->fail( 'expected RuntimeException' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'simulated failure', $e->getMessage() );
        }
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
        $this->assertSame( self::header(), $this->layoutContent( 201 ) );
    }

    public function testAWriteWhoseSnapshotWasNotStoredSaysThereIsNoUndoPoint(): void
    {
        $write = static function ( int $id, string $content ): array {
            $GLOBALS['__wp_posts'][ $id ]->post_content = $content;

            return [ 'ok' => true, 'snapshot' => [ 'stored' => false, 'version_id' => null, 'reason' => 'too_large' ] ];
        };
        $tool = new UpdateThemeLayout( $this->log, new LayoutWriter( $this->log, $write ) );
        $r    = $tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );
        $this->assertTrue( $r['changed'] );
        $this->assertFalse( $r['snapshot']['stored'] );
        $undo = $this->undoEngine()->undo( $r['batch']['id'] );
        $this->assertSame( 'no_undo_point', $undo['items'][0]['result'] );
        $this->assertFalse( $undo['fully_undone'] );
        $this->assertSame( self::otherHeader(), $this->layoutContent( 201 ), 'nothing was invented' );
    }

    public function testABatchThatCannotBeRecordedStillReportsTheWriteHonestly(): void
    {
        $this->repo->failInsert = true;
        $r                      = $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );
        $this->assertTrue( $r['changed'] );
        $this->assertFalse( $r['batch']['undoable'] );
        $this->assertNull( $r['batch']['id'] );
        $this->assertSame( self::otherHeader(), $this->layoutContent( 201 ) );
    }

    public function testUndoRestoresTheOldContentClearsCachesAgainAndIsItselfUndoable(): void
    {
        $r                              = $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );
        $GLOBALS['__divi_cache_clears'] = [];
        $undo                           = $this->undoEngine()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( self::header(), $this->layoutContent( 201 ) );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'], 'undo clears Divi\'s caches too' );
        $this->assertTrue( $undo['undo_batch']['undoable'] );
        $again = $this->undoEngine()->undo( $undo['undo_batch']['id'] );
        $this->assertTrue( $again['fully_undone'] );
        $this->assertSame( self::otherHeader(), $this->layoutContent( 201 ) );
    }

    public function testUndoNeverOverwritesALayoutEditedInDiviLater(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );
        $GLOBALS['__wp_posts'][201]->post_content = 'EDITED IN THE BUILDER';
        $undo = $this->undoEngine()->undo( $r['batch']['id'] );
        $this->assertSame( 'changed_since', $undo['items'][0]['result'] );
        $this->assertSame( 'EDITED IN THE BUILDER', $this->layoutContent( 201 ) );
    }

    public function testUndoIsForbiddenOnceTheLayoutIsNoLongerLiveOrThePermissionIsGone(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'content' => self::otherHeader() ] );

        $GLOBALS['__divi_role_allowed'] = false;
        $this->assertSame( 'forbidden', $this->undoEngine()->undo( $r['batch']['id'] )['items'][0]['result'] );
        unset( $GLOBALS['__divi_role_allowed'] );

        $GLOBALS['__divi_tb']['template_ids'] = [];
        $this->assertSame( 'forbidden', $this->undoEngine()->undo( $r['batch']['id'] )['items'][0]['result'] );
        $GLOBALS['__divi_tb']['template_ids'] = [ 200 ];

        // undo_batch itself only needs edit_pages: the item must still demand the tools' own capability.
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true ];
        $this->assertSame( 'forbidden', $this->undoEngine()->undo( $r['batch']['id'] )['items'][0]['result'] );
        $this->assertSame( self::otherHeader(), $this->layoutContent( 201 ) );
    }

    public function testItIsRegisteredAndNeedsEditThemeOptions(): void
    {
        $this->assertContains( 'update_theme_layout', array_map( static fn ( $t ) => $t->name(), \AiEditorDivi5\Pro\Tools::all() ) );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $this->tool->definition()['description'] ) );
        $this->assertSame( [ 'layout_id', 'content' ], $this->tool->definition()['inputSchema']['required'] );
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter 'ThemeLayoutKindTest|UpdateThemeLayoutTest' 2>&1 | tail -8`
Expected: `Class "AiEditorDivi5\Pro\Kinds\ThemeLayoutKind" not found` / `UpdateThemeLayout` not found.

- [ ] **Step 3: Write the kind, the writer and the tool**

Create `pro-addon/src/Kinds/ThemeLayoutKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutCaches;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use AiEditorDivi5\WP\HistoryService;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The content of a header or footer layout. Like `PostContentKind`, the previous content lives in the free plugin's
 * history and the item records the version to restore; unlike it, undo re-checks that the layout is STILL a live
 * header or footer the user may edit under Divi's Theme Builder bar, and clears Divi's caches after restoring.
 */
final class ThemeLayoutKind implements ItemKind
{
    public const NAME = 'theme_layout_content';

    /**
     * @param (\Closure(int,int):array<string,mixed>)|null $restorer fn(layoutId, versionId): HistoryService::restore()'s result
     * @param (\Closure():void)|null $afterRestore called after a successful restore (default: clear Divi's caches)
     */
    public function __construct( private ?\Closure $restorer = null, private ?\Closure $afterRestore = null )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * The batch item for a write made through `HistoryService::write()`.
     *
     * @param array{stored?:bool, version_id?:?int, reason?:?string} $snapshot that call's `snapshot`
     * @return array<string,mixed>
     */
    public static function itemFor( int $layoutId, string $title, string $kind, string $newContent, array $snapshot ): array
    {
        return Item::make(
            self::NAME,
            (string) $layoutId,
            "Content of the {$kind} \"{$title}\" (#{$layoutId})",
            null,
            $newContent,
            PostContentKind::undoPoint( $snapshot )
        );
    }

    public function current( array $item ): mixed
    {
        $post = get_post( (int) $item['ref'] );

        return $post ? (string) $post->post_content : null;
    }

    /** Undo is never weaker than the write: the tools' own capability AND the live-layout / Theme Builder bar. */
    public function allowed( array $item ): bool
    {
        return current_user_can( 'edit_theme_options' ) && null === LayoutTarget::resolve( (int) $item['ref'] )['error'];
    }

    public function restore( array $item ): array
    {
        $layoutId  = (int) $item['ref'];
        $versionId = (int) ( $item['undo']['version_id'] ?? 0 );
        if ( $versionId < 1 ) {
            return [ 'ok' => false, 'reason' => 'no_version' ];
        }
        $restorer = $this->restorer ?? static fn ( int $p, int $v ): array => HistoryService::restore( $p, $v );
        $r        = $restorer( $layoutId, $versionId );
        if ( empty( $r['ok'] ) ) {
            return [ 'ok' => false, 'reason' => (string) ( $r['error'] ?? 'restore_failed' ) ];
        }
        ( $this->afterRestore ?? [ LayoutCaches::class, 'clear' ] )();

        return [
            'ok'      => true,
            'inverse' => Item::make(
                self::NAME,
                (string) $layoutId,
                (string) $item['label'],
                null,
                $this->current( $item ),
                PostContentKind::undoPoint( is_array( $r['snapshot'] ?? null ) ? $r['snapshot'] : [] )
            ),
        ];
    }
}
```

Create `pro-addon/src/ThemeBuilder/LayoutWriter.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\ThemeBuilder;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Kinds\ThemeLayoutKind;
use AiEditorDivi5\WP\DryRun;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\StyleReferences;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one write path for a header or footer: resolve the target, build the new content from the stored content,
 * run the shared gate (validator + no-custom-code policy, with the stored content as the baseline), preview or save
 * through the free history, clear Divi's caches, record one undoable batch. Both layout tools use it, so they cannot
 * behave differently.
 */
final class LayoutWriter
{
    /** The shared gate rejects anything larger; this only bounds the argument before it is read. */
    public const MAX_BYTES = 1000000;

    public const NOTE = 'A header or footer is shown on every page that uses its template. Divi\'s caches were cleared.';

    public const DRY_NOTE = 'A header or footer is shown on every page that uses its template. Nothing was saved.';

    /** @param (\Closure(int,string,string):array<string,mixed>)|null $write fn(layoutId, content, tool): HistoryService::write()'s result */
    public function __construct( private BatchLog $log, private ?\Closure $write = null )
    {
    }

    /**
     * @param \Closure(string):array{content:string, extra:array<string,mixed>} $make builds the new content from the stored content; throws \InvalidArgumentException for a bad request
     * @return array<string,mixed>
     */
    public function apply( int $layoutId, string $tool, \Closure $make, bool $dry ): array
    {
        $target = LayoutTarget::mustResolve( $layoutId );
        $post   = $target['post'];
        $kind   = $target['kind'];
        $title  = (string) $post->post_title;
        $stored = (string) $post->post_content;
        $built  = $make( $stored );
        $new    = $built['content'];
        $extra  = $built['extra'];
        $layout = [ 'layout_id' => $layoutId, 'kind' => $kind, 'title' => $title ];

        if ( $new === $stored ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'layout' => $layout, 'message' => 'The layout already holds exactly this content; nothing changed.' ] + $extra;
        }
        if ( $dry ) {
            // DryRun::preview runs the same gate and returns valid:false as a normal preview; it never writes.
            return StyleReferences::attach( DryRun::preview( $stored, $new ), $new ) + [ 'layout' => $layout, 'note' => self::DRY_NOTE ] + $extra;
        }

        $verdict = LayoutGate::check( $new, $stored );
        if ( ! $verdict->isValid() ) {
            throw new \InvalidArgumentException( self::refusal( $verdict->violations() ) );
        }

        $write  = $this->write ?? static fn ( int $id, string $content, string $t ): array => HistoryService::write( $id, $content, $t );
        $result = $write( $layoutId, $new, $tool );
        if ( empty( $result['ok'] ) ) {
            throw new \RuntimeException( (string) ( $result['message'] ?? 'The layout could not be saved.' ) );
        }
        LayoutCaches::clear();

        // Hash what is stored now, not what was sent: WordPress may normalise content on save.
        $now      = get_post( $layoutId );
        $snapshot = is_array( $result['snapshot'] ?? null ) ? $result['snapshot'] : [];
        $batch    = $this->log->record(
            $tool,
            ucfirst( $kind ) . " \"{$title}\" (#{$layoutId}) edited",
            [ ThemeLayoutKind::itemFor( $layoutId, $title, $kind, $now ? (string) $now->post_content : $new, $snapshot ) ]
        );

        return BatchLog::attach(
            StyleReferences::attach(
                [ 'changed' => true, 'dry_run' => false, 'layout' => $layout, 'snapshot' => $snapshot, 'note' => self::NOTE ] + $extra,
                $new
            ),
            $batch
        );
    }

    /** @param list<\Divi5Validator\Violation> $violations */
    private static function refusal( array $violations ): string
    {
        $lines = [];
        foreach ( array_slice( $violations, 0, 10 ) as $v ) {
            $a       = $v->toArray();
            $lines[] = $a['code'] . ' at ' . $a['path'] . ': ' . $a['message'];
        }
        $more = count( $violations ) > 10 ? ' (and ' . ( count( $violations ) - 10 ) . ' more)' : '';

        return 'The layout was not saved: it failed the checks. ' . implode( '; ', $lines ) . $more;
    }
}
```

Create `pro-addon/src/Tools/UpdateThemeLayout.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutWriter;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Replace the whole content of a live header or footer. Validated, snapshotted, undoable; dry_run previews. */
final class UpdateThemeLayout implements ProTool
{
    public const NAME = 'update_theme_layout';

    private LayoutWriter $writer;

    public function __construct( BatchLog $log, ?LayoutWriter $writer = null )
    {
        $this->writer = $writer ?? new LayoutWriter( $log );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_theme_options';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Replace the content of a live Divi header or footer (layout_id from list_theme_layouts) with a new Divi 5 layout. Rejected with the exact violations unless it passes every check. It shows on every page: use dry_run=true first. Undoable.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'layout_id' => [ 'type' => 'integer', 'description' => 'A header or footer layout id from list_theme_layouts' ],
                    'content'   => [ 'type' => 'string', 'description' => 'The full Divi 5 layout (block markup) for this header or footer' ],
                    'dry_run'   => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'layout_id', 'content' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        LayoutTarget::assertMay();
        $id      = (int) Args::int( $arguments, 'layout_id' );
        $content = (string) Args::string( $arguments, 'content', true, LayoutWriter::MAX_BYTES );
        $dry     = Args::bool( $arguments, 'dry_run' );

        return $this->writer->apply( $id, self::NAME, static fn ( string $stored ): array => [ 'content' => $content, 'extra' => [] ], $dry );
    }
}
```

- [ ] **Step 4: Wire it in**

`pro-addon/src/autoload.php`: after the `LayoutCaches.php` require add
```php
require_once __DIR__ . '/Kinds/ThemeLayoutKind.php';
require_once __DIR__ . '/ThemeBuilder/LayoutWriter.php';
```
and after the `Tools/ListThemeLayouts.php` require add `require_once __DIR__ . '/Tools/UpdateThemeLayout.php';`.

`pro-addon/src/Tools.php`: add `use AiEditorDivi5\Pro\Tools\UpdateThemeLayout;` after the `UndoBatch` import and `new UpdateThemeLayout( $log ),` after `new ListThemeLayouts(),`.

`pro-addon/src/Batch/Batches.php`: add `use AiEditorDivi5\Pro\Kinds\ThemeLayoutKind;` after the `PostStatusKind` import and `new ThemeLayoutKind(),` after `new PostContentKind(),` in `kinds()`.

`pro-addon/tests/ToolsTest.php`: append `'update_theme_layout'` to the expected tool list (after `'list_theme_layouts'`) and `'theme_layout_content'` to the expected kind list in `testEveryKindARecordedItemUsesIsRegisteredForUndo`.

`pro-addon/readme.txt`: before the `list_batches` bullet add
```
* `update_theme_layout` - replace the content of a live Divi header or footer with a new, checked Divi 5 layout. It shows on every page, so preview with `dry_run` first; undo restores the previous content.
```
and change the short description line `Lets your AI assistant set the front page and menu, publish pages and add CSS, and lets you undo each change in one step.` to `Lets your AI assistant set the front page and menu, publish pages, add CSS and edit the live Divi header and footer, and lets you undo each change in one step.`

`pro-addon/jhmg-ai-editor-for-divi-5-pro.php`: in the header `Description:` line insert `edit the live Divi header and footer, ` after `add managed custom CSS, `.

`pro-addon/tests/ReadmeTest.php`: in `testTheHeaderDescriptionClaimsNothingThatIsNotShipped`, remove `'Theme Builder', ` from the list of unshipped phrases (header/footer editing now ships; `site-wide`, `audit`, `find and replace` and `find/replace` stay banned until Plan 5).

- [ ] **Step 5: Run the whole Pro suite**

Run: `make test-pro 2>&1 | tail -3` → all green (`OK (…)`, no failures, no risky, no deprecations).

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src pro-addon/tests pro-addon/readme.txt pro-addon/jhmg-ai-editor-for-divi-5-pro.php
git commit -q -F - <<'EOF'
feat(pro): update_theme_layout through one shared layout write path, with the theme_layout_content undo kind

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).


### Task 4: `edit_theme_layout` and the registry-level permission tests

**Files:**
- Create: `pro-addon/src/Tools/EditThemeLayout.php`, `pro-addon/tests/EditThemeLayoutTest.php`, `pro-addon/tests/ThemeLayoutPermissionTest.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Tools.php`, `pro-addon/tests/ToolsTest.php`, `pro-addon/readme.txt`

**Interfaces:**
- Consumes: `LayoutWriter::apply()` (Task 3), `PageEditor::apply(string $content, string $find, string $replace, ?int $expectCount): array{ok,content,count,error}` (free plugin, pinned), `Args`.
- Produces: `EditThemeLayout` (`NAME = 'edit_theme_layout'`, constructor `(BatchLog $log, ?LayoutWriter $writer = null)`; extra result field `replaced`). `ThemeLayoutPermissionTest`: the standing rule for every Pro tool, run through the REAL `ToolRegistry`: the registry refuses a user without the capability BEFORE the tool runs, and each further layer (Divi's Role Editor bar, `edit_others_posts`, `edit_post` on the layout) refuses inside the tool, with nothing changed.

- [ ] **Step 1: Write the failing suites**

Create `pro-addon/tests/EditThemeLayoutTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Tools\EditThemeLayout;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';

class EditThemeLayoutTest extends TestCase
{
    use ThemeLayoutFixture;

    private EditThemeLayout $tool;

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $this->tool = new EditThemeLayout( $this->log );
        // a header with visible text to edit
        $text = self::header();
        $GLOBALS['__wp_posts'][201]->post_content = str_replace( 'wp:divi/shop {"builderVersion":"5.14.0"} /', 'wp:divi/search {"builderVersion":"5.14.0","note":"AIED-OLD"} /', $text );
        $GLOBALS['__wp_posts'][202]->post_content = $GLOBALS['__wp_posts'][201]->post_content;
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
    }

    private function refused( array $args, string $message ): void
    {
        $before = [ $this->layoutContent( 201 ), $GLOBALS['__wp_update_calls'], $this->historyRows( 201 ), count( $this->repo->rows ) ];
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( $before, [ $this->layoutContent( 201 ), $GLOBALS['__wp_update_calls'], $this->historyRows( 201 ), count( $this->repo->rows ) ] );
    }

    public function testItReplacesTheTextAndLeavesTheRestUntouched(): void
    {
        $before = $this->layoutContent( 201 );
        $r      = $this->tool->run( [ 'layout_id' => 201, 'find' => 'AIED-OLD', 'replace' => 'AIED-NEW' ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 1, $r['replaced'] );
        $this->assertSame( str_replace( 'AIED-OLD', 'AIED-NEW', $before ), $this->layoutContent( 201 ) );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'] );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( 1, $this->historyRows( 201 ) );
        $this->assertSame( $before, $this->layoutContent( 202 ), 'the footer is a different layout and is untouched' );
    }

    public function testAnEmptyReplacementIsAllowed(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'find' => ',"note":"AIED-OLD"', 'replace' => '' ] );
        $this->assertTrue( $r['changed'] );
        $this->assertStringNotContainsString( 'AIED-OLD', $this->layoutContent( 201 ) );
    }

    public function testTheFreePluginsAmbiguityRulesApply(): void
    {
        $this->refused( [ 'layout_id' => 201, 'find' => 'builderVersion', 'replace' => 'x' ], 'ambiguous' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'NOT-THERE', 'replace' => 'x' ], 'was not found' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'AIED-OLD', 'replace' => 'AIED-OLD' ], 'identical' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'AIED-OLD', 'replace' => 'x', 'expect_count' => 2 ], 'Expected 2' );
    }

    public function testExpectCountReplacesAllOfExactlyThatMany(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201, 'find' => '"builderVersion":"5.14.0"', 'replace' => '"builderVersion":"5.14.1"', 'expect_count' => 4 ] );
        $this->assertSame( 4, $r['replaced'] );
    }

    public function testAnEditThatBreaksTheLayoutIsRefusedByTheGate(): void
    {
        $this->refused( [ 'layout_id' => 201, 'find' => 'wp:divi/search', 'replace' => 'wp:divi/not-a-module', 'expect_count' => 1 ], 'The layout was not saved' );
    }

    public function testAnEditThatAddsCustomCssIsRefused(): void
    {
        $this->refused( [ 'layout_id' => 201, 'find' => '"note":"AIED-OLD"', 'replace' => '"css":{"desktop":{"value":{"freeForm":"a{b:c}"}}}' ], 'CUSTOM_CSS_NOT_ALLOWED' );
    }

    public function testADryRunPreviewsWithTheReplacedCountAndTouchesNothing(): void
    {
        $before = $this->layoutContent( 201 );
        $r      = $this->tool->run( [ 'layout_id' => 201, 'find' => 'AIED-OLD', 'replace' => 'AIED-NEW', 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['saved'] );
        $this->assertSame( 1, $r['replaced'] );
        $this->assertSame( $before, $this->layoutContent( 201 ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testOnlyLiveHeadersAndFootersCanBeEdited(): void
    {
        foreach ( [ 5, 203, 204, 200, 9999 ] as $id ) {
            try {
                $this->tool->run( [ 'layout_id' => $id, 'find' => 'builderVersion', 'replace' => 'x', 'expect_count' => 99 ] );
                $this->fail( "{$id} must be refused" );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( 'not an editable header or footer', $e->getMessage(), (string) $id );
            }
        }
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testBadArgumentsAreRejectedNotCast(): void
    {
        $this->refused( [ 'find' => 'a', 'replace' => 'b' ], '"layout_id" is required.' );
        $this->refused( [ 'layout_id' => 201, 'replace' => 'b' ], '"find" is required.' );
        $this->refused( [ 'layout_id' => 201, 'find' => 5, 'replace' => 'b' ], '"find" must be a string.' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'a' ], '"replace" is required' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'a', 'replace' => 5 ], '"replace" must be a string.' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'a', 'replace' => 'b', 'expect_count' => '1' ], '"expect_count" must be an integer.' );
        $this->refused( [ 'layout_id' => 201, 'find' => 'a', 'replace' => 'b', 'dry_run' => 1 ], '"dry_run" must be true or false.' );
    }

    public function testUndoRestoresTheEditedLayout(): void
    {
        $before = $this->layoutContent( 201 );
        $r      = $this->tool->run( [ 'layout_id' => 201, 'find' => 'AIED-OLD', 'replace' => 'AIED-NEW' ] );
        $undo   = $this->undoEngine()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( $before, $this->layoutContent( 201 ) );
    }

    public function testItIsRegisteredAndNeedsEditThemeOptions(): void
    {
        $this->assertContains( 'edit_theme_layout', array_map( static fn ( $t ) => $t->name(), \AiEditorDivi5\Pro\Tools::all() ) );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $this->tool->definition()['description'] ) );
        $this->assertSame( [ 'layout_id', 'find', 'replace' ], $this->tool->definition()['inputSchema']['required'] );
    }
}
```

Create `pro-addon/tests/ThemeLayoutPermissionTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools\EditThemeLayout;
use AiEditorDivi5\Pro\Tools\ListThemeLayouts;
use AiEditorDivi5\Pro\Tools\UpdateThemeLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';

/**
 * Standing rule for every Pro tool: the narrowest real capability, checked by the REGISTRY, and a user without it is
 * refused with nothing changed. These tests go through the real ToolRegistry, not the tool's run().
 */
class ThemeLayoutPermissionTest extends TestCase
{
    use ThemeLayoutFixture;

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
    }

    /** @return array<string,array{string,array<string,mixed>}> */
    public static function calls(): array
    {
        return [
            'list_theme_layouts'  => [ ListThemeLayouts::NAME, [ 'layout_id' => 201 ] ],
            'update_theme_layout' => [ UpdateThemeLayout::NAME, [ 'layout_id' => 201, 'content' => '__OTHER__' ] ],
            'edit_theme_layout'   => [ EditThemeLayout::NAME, [ 'layout_id' => 201, 'find' => '"builderVersion":"5.14.0"', 'replace' => '"builderVersion":"5.14.1"', 'expect_count' => 4 ] ],
        ];
    }

    /** @return list<ProTool> */
    private function tools(): array
    {
        return [ new ListThemeLayouts(), new UpdateThemeLayout( $this->log ), new EditThemeLayout( $this->log ) ];
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function call( string $name, array $args ): array
    {
        if ( ( $args['content'] ?? null ) === '__OTHER__' ) {
            $args['content'] = self::otherHeader();
        }
        $r = ( new ToolRegistry( $this->tools(), static fn (): bool => true ) )->claim( null, $name, $args, 1 );

        return (array) $r->get_data();
    }

    private function assertRefusedAndUnchanged( array $data, string $why ): void
    {
        $this->assertArrayHasKey( 'error', $data, $why );
        $this->assertSame( -32602, $data['error']['code'], $why );
        $this->assertSame( self::header(), $this->layoutContent( 201 ), $why );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], $why );
        $this->assertSame( [], $this->repo->rows, $why );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testAnAdministratorIsAllowed( string $name, array $args ): void
    {
        $data = $this->call( $name, $args );
        $this->assertArrayHasKey( 'result', $data, $name );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testWithoutEditThemeOptionsTheRegistryRefusesBeforeTheToolRuns( string $name, array $args ): void
    {
        // An editor: may edit others' posts and has Divi's permission, but not edit_theme_options.
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true, 'manage_options' => false ];
        $data                 = $this->call( $name, $args );
        $this->assertRefusedAndUnchanged( $data, $name );
        $this->assertStringContainsString( 'permission', $data['error']['message'] );
        $this->assertSame( [], $GLOBALS['__divi_tb_calls'], 'the tool never ran: Divi was not even asked' );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testWithoutDivisThemeBuilderPermissionTheToolRefuses( string $name, array $args ): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $this->assertRefusedAndUnchanged( $this->call( $name, $args ), $name );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testWithoutEditOthersPostsTheToolRefuses( string $name, array $args ): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => false, 'edit_theme_options' => true ];
        $this->assertRefusedAndUnchanged( $this->call( $name, $args ), $name );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testWithoutEditPostOnThatLayoutOrTemplateTheToolRefuses( string $name, array $args ): void
    {
        $id                   = (int) ( $args['layout_id'] ?? $args['template_id'] );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, "edit_post:{$id}" => false, 'edit_others_posts' => true, 'edit_theme_options' => true ];
        $this->assertRefusedAndUnchanged( $this->call( $name, $args ), $name );
    }

    public function testLockedLicenceRegistersNothingAndClaimsNothing(): void
    {
        $r = new ToolRegistry( $this->tools(), static fn (): bool => false );
        $this->assertSame( [ [ 'name' => 'x' ] ], $r->definitions( [ [ 'name' => 'x' ] ] ) );
        $this->assertNull( $r->claim( null, 'update_theme_layout', [ 'layout_id' => 201, 'content' => self::otherHeader() ], 1 ) );
        $this->assertSame( self::header(), $this->layoutContent( 201 ) );
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter 'EditThemeLayoutTest|ThemeLayoutPermissionTest' 2>&1 | tail -8`
Expected: `EditThemeLayout` not found (the permission suite fails on the `edit_theme_layout` data row for the same reason).

- [ ] **Step 3: Write the tool**

Create `pro-addon/src/Tools/EditThemeLayout.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutWriter;
use AiEditorDivi5\WP\PageEditor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Find and replace literal text inside a live header or footer, with the free plugin's exact-replace rules. Same gate, history and undo as update_theme_layout. */
final class EditThemeLayout implements ProTool
{
    public const NAME = 'edit_theme_layout';

    private LayoutWriter $writer;

    public function __construct( BatchLog $log, ?LayoutWriter $writer = null )
    {
        $this->writer = $writer ?? new LayoutWriter( $log );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_theme_options';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Find and replace literal text inside a live Divi header or footer (layout_id from list_theme_layouts), leaving the rest untouched. The result must pass every layout check. It shows on every page: use dry_run=true first. Undoable.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'layout_id'    => [ 'type' => 'integer', 'description' => 'A header or footer layout id from list_theme_layouts' ],
                    'find'         => [ 'type' => 'string', 'description' => 'Exact text to find (must match one place, unless expect_count says otherwise)' ],
                    'replace'      => [ 'type' => 'string', 'description' => 'Text to put in its place (may be empty)' ],
                    'expect_count' => [ 'type' => 'integer', 'description' => 'Optional. Replace all of exactly this many matches' ],
                    'dry_run'      => [ 'type' => 'boolean', 'description' => 'Preview only; nothing is changed' ],
                ],
                'required'   => [ 'layout_id', 'find', 'replace' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        LayoutTarget::assertMay();
        $id      = (int) Args::int( $arguments, 'layout_id' );
        $find    = (string) Args::string( $arguments, 'find', true, LayoutWriter::MAX_BYTES );
        $replace = Args::string( $arguments, 'replace', false, LayoutWriter::MAX_BYTES );
        if ( null === $replace ) {
            throw new \InvalidArgumentException( '"replace" is required (it may be an empty string).' );
        }
        $expect = Args::int( $arguments, 'expect_count', false );
        $dry    = Args::bool( $arguments, 'dry_run' );

        return $this->writer->apply(
            $id,
            self::NAME,
            static function ( string $stored ) use ( $find, $replace, $expect ): array {
                $edit = PageEditor::apply( $stored, $find, $replace, $expect );
                if ( ! $edit['ok'] ) {
                    throw new \InvalidArgumentException( $edit['error'] );
                }

                return [ 'content' => $edit['content'], 'extra' => [ 'replaced' => $edit['count'] ] ];
            },
            $dry
        );
    }
}
```

- [ ] **Step 4: Wire it in**

`pro-addon/src/autoload.php`: after the `Tools/UpdateThemeLayout.php` require add `require_once __DIR__ . '/Tools/EditThemeLayout.php';`.

`pro-addon/src/Tools.php`: add `use AiEditorDivi5\Pro\Tools\EditThemeLayout;` before the `ListBatches` import and `new EditThemeLayout( $log ),` after `new UpdateThemeLayout( $log ),`.

`pro-addon/tests/ToolsTest.php`: append `'edit_theme_layout'` to the expected tool list (after `'update_theme_layout'`).

`pro-addon/readme.txt`: before the `list_batches` bullet add
```
* `edit_theme_layout` - find and replace literal text inside a live Divi header or footer, with the same checks, `dry_run` preview and undo as `update_theme_layout`.
```

- [ ] **Step 5: Run the whole Pro suite**

Run: `make test-pro 2>&1 | tail -3` → all green.

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src pro-addon/tests pro-addon/readme.txt
git commit -q -F - <<'EOF'
feat(pro): edit_theme_layout, and registry-level permission tests for the Theme Builder tools

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).


### Task 5: Live proof of the layout tools on the real Divi (`make verify-pro-theme-layouts`)

**Files:**
- Create: `scripts/verify-pro-theme-layouts.php`
- Modify: `Makefile`

**Interfaces:**
- Consumes: the three layout tools and the real `ToolRegistry`, `Batches`, `BatchTable`, the free `HistoryStore`/`HistoryTable`, Divi's `et_theme_builder_*` functions on the Docker site.
- Produces: a self-cleaning check (exit 0 = every check passed). It builds throwaway Theme Builder fixtures (a template that applies to nothing, header, footer, body, an orphan header, a draft-Theme-Builder layout and template, an ordinary post and page), proves the behaviour through the real registry including the three permission layers, and removes exactly what it created.

This script was already run against the real Divi 5.14 dev site while this plan was replayed, and passed with no FAIL line. If a check fails on your run, treat that as a real finding: fix the code (not the check) unless the failure is a script defect.

- [ ] **Step 1: Write the script**

Create `scripts/verify-pro-theme-layouts.php`:

```php
<?php
/**
 * Real-site check of the Pro Theme Builder layout tools (list_theme_layouts, update_theme_layout, edit_theme_layout),
 * driven through the REAL ToolRegistry (so the WordPress capability gate is part of what is proven), the real batch
 * table, the real free history table and Divi's own Theme Builder posts on the Docker site. It proves: listing never
 * creates anything; a live header/footer reads, previews (dry_run touches nothing), updates, edits, snapshots and
 * undoes byte for byte; every other Theme Builder post (orphan, draft-only, body, template, the Theme Builder post)
 * and ordinary posts and pages are refused; an invalid layout and custom CSS are refused with nothing changed; undo
 * never overwrites a layout changed since; the three permission layers (edit_theme_options, edit_others_posts and
 * Divi's Role Editor 'theme_builder') each refuse; and the template assignment is never touched.
 *
 * Run: make verify-pro-theme-layouts   (needs `make up`, the free plugin and the Pro add-on active on the dev site;
 * no licence is needed: the registry is built with the unlock forced)
 *
 * It builds throwaway Theme Builder fixtures with Divi's own functions (the throwaway template has default 0 and an
 * empty use_on, so it applies to nothing on the site) and removes exactly what it created, even when a check fails or
 * throws. The Divi Role Editor option is changed temporarily as DEV-SITE test setup and put back exactly.
 * WARNING: it records about 9 batches, and the batch store keeps only the newest 20, so on a site that already has
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

    // ---- 5a. listing -----------------------------------------------------------------------------------
    $l = $call( 'list_theme_layouts', [] );
    $t = null;
    foreach ( (array) ( $l['result']['templates'] ?? [] ) as $row ) {
        if ( (int) ( $row['template_id'] ?? 0 ) === $T ) {
            $t = (array) $row;
        }
    }
    $check( null !== $t, 'list_theme_layouts lists the live template' );
    $check( null !== $t && $H === ( $t['header']['layout_id'] ?? null ) && $F === ( $t['footer']['layout_id'] ?? null ) && $B === ( $t['body']['layout_id'] ?? null ), 'header, footer and body layout ids are the fixtures\'' );
    $check( null !== $t && true === ( $t['header']['editable'] ?? null ) && true === ( $t['footer']['editable'] ?? null ) && false === ( $t['body']['editable'] ?? null ), 'header and footer are editable, the body layout is listed but not editable' );
    $check( null !== $t && false === $t['default'] && [] === $t['use_on'], 'the throwaway template is not the default and applies to nothing' );
    $json = (string) wp_json_encode( $l['result'] );
    $check( false === strpos( $json, 'aied verify orphan' ) && false === strpos( $json, 'aied verify draft' ), 'the orphan header and the draft-only layout appear nowhere in the listing' );
    $c = $call( 'list_theme_layouts', [ 'layout_id' => $H ] );
    $check( null === $c['error'] && 'header' === ( $c['result']['layout']['kind'] ?? null ) && $layout( 'AIED-HEADER-V1' ) === ( $c['result']['layout']['content'] ?? null ), 'list_theme_layouts(layout_id) returns the header\'s content byte for byte' );

    // ---- 5b. dry run touches nothing -------------------------------------------------------------------------
    $before = $state( $H );
    $nBatches = count( $batchIds );
    sleep( 1 ); // post_modified has one-second resolution: a real write would be visible
    $d = $call( 'edit_theme_layout', [ 'layout_id' => $H, 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-V2', 'dry_run' => true ] );
    $check( null === $d['error'] && true === ( $d['result']['dry_run'] ?? null ) && false === ( $d['result']['saved'] ?? null ) && true === ( $d['result']['valid'] ?? null ) && 1 === ( $d['result']['replaced'] ?? null ), 'edit_theme_layout dry_run: a valid preview, saved false, replaced 1' );
    $after = $state( $H );
    $check( $after === $before && 0 === $after['history'] && count( $batchIds ) === $nBatches, 'the dry run left post_content, post_modified, history and the batch log untouched' );
    $d = $call( 'update_theme_layout', [ 'layout_id' => $H, 'content' => $layout( 'AIED-HEADER-V2' ), 'dry_run' => true ] );
    $check( null === $d['error'] && false === ( $d['result']['saved'] ?? null ) && $state( $H ) === $before, 'update_theme_layout dry_run: preview only, state untouched' );

    // ---- 5c. edit the header, undo it ------------------------------------------------------------------------
    $e = $call( 'edit_theme_layout', [ 'layout_id' => $H, 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-V2' ] );
    $check( null === $e['error'] && true === ( $e['result']['changed'] ?? null ) && true === ( $e['result']['snapshot']['stored'] ?? null ) && true === ( $e['result']['batch']['undoable'] ?? null ), 'edit_theme_layout: changed, snapshot stored, batch undoable (the cache-clear path did not fatal)' );
    clean_post_cache( $H );
    $check( false !== strpos( get_post( $H )->post_content, 'AIED-HEADER-V2' ) && false === strpos( get_post( $H )->post_content, 'AIED-HEADER-V1' ), 'the stored header now contains AIED-HEADER-V2' );
    $check( 1 === $rows( $H ), 'exactly one history row exists for the header' );
    $u = $call( 'undo_batch', [ 'batch_id' => (int) $e['result']['batch']['id'] ] );
    clean_post_cache( $H );
    $check( null === $u['error'] && true === ( $u['result']['fully_undone'] ?? null ) && get_post( $H )->post_content === $layout( 'AIED-HEADER-V1' ), 'undo_batch puts the header back byte for byte' );
    $check( true === ( $u['result']['undo_batch']['undoable'] ?? null ), 'the undo is itself undoable' );

    // ---- 5d. replace the footer, byte-exact, undo ------------------------------------------------------------
    $new = $layout( 'AIED-FOOTER-V2' );
    $up  = $call( 'update_theme_layout', [ 'layout_id' => $F, 'content' => $new ] );
    clean_post_cache( $F );
    $check( null === $up['error'] && 'footer' === ( $up['result']['layout']['kind'] ?? null ) && get_post( $F )->post_content === $new, 'update_theme_layout on the footer: stored byte for byte' );
    $uf = $call( 'undo_batch', [ 'batch_id' => (int) $up['result']['batch']['id'] ] );
    clean_post_cache( $F );
    $check( true === ( $uf['result']['fully_undone'] ?? null ) && get_post( $F )->post_content === $layout( 'AIED-FOOTER-V1' ), 'undo_batch puts the footer back byte for byte' );

    // ---- 5e. an invalid layout and custom CSS are refused, nothing changes -----------------------------------
    $hb  = $state( $H );
    $bad = $call( 'update_theme_layout', [ 'layout_id' => $H, 'content' => str_replace( 'wp:divi/heading', 'wp:divi/not-a-module', $layout( 'x' ) ) ] );
    $check( $errHas( $bad, 'The layout was not saved' ), 'an unknown module is refused with the violations' );
    $css = str_replace( '"builderVersion":"5.14.0"} /--><!-- /wp:divi/column', '"builderVersion":"5.14.0","css":{"desktop":{"value":{"freeForm":"selector{color:red}"}}}} /--><!-- /wp:divi/column', $layout( 'x' ) );
    $bad = $call( 'update_theme_layout', [ 'layout_id' => $H, 'content' => $css ] );
    $check( $errHas( $bad, 'CUSTOM_CSS_NOT_ALLOWED' ), 'custom CSS in a layout is refused by the shared gate' );
    $check( $state( $H ) === $hb, 'the refused writes changed nothing (content, post_modified, history)' );

    // ---- 5f. everything else is refused ----------------------------------------------------------------------
    $PG = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied verify page', 'post_content' => wp_slash( $layout( 'AIED-PAGE-V1' ) ) ] );
    $created[] = $PG;
    $refused = [ 'orphan header' => $O, 'draft-only layout' => $DL, 'body layout' => $B, 'template' => $T, 'live Theme Builder post' => $tb, 'ordinary post' => $P, 'page' => $PG ];
    foreach ( $refused as $name => $rid ) {
        $b = $state( $rid );
        foreach ( [
            'list_theme_layouts'  => [ 'layout_id' => $rid ],
            'update_theme_layout' => [ 'layout_id' => $rid, 'content' => $layout( 'Hijack' ) ],
            'edit_theme_layout'   => [ 'layout_id' => $rid, 'find' => 'AIED', 'replace' => 'X', 'expect_count' => 1 ],
        ] as $tool => $args ) {
            $check( $errHas( $call( $tool, $args ), 'not an editable header or footer' ), "{$tool} on the {$name} is refused as not an editable header or footer" );
        }
        $check( $state( $rid ) === $b && 0 === $state( $rid )['history'], "the {$name} is unchanged: same content, same post_modified, no history rows" );
    }

    // ---- 5g. undo never overwrites a layout changed since ----------------------------------------------------
    $e2 = $call( 'edit_theme_layout', [ 'layout_id' => $F, 'find' => 'AIED-FOOTER-V1', 'replace' => 'AIED-FOOTER-V3' ] );
    wp_update_post( wp_slash( [ 'ID' => $F, 'post_content' => $layout( 'EDITED-IN-THE-BUILDER' ) ] ) );
    $u2 = $call( 'undo_batch', [ 'batch_id' => (int) $e2['result']['batch']['id'] ] );
    clean_post_cache( $F );
    $check( 'changed_since' === ( $u2['result']['items'][0]['result'] ?? null ) && get_post( $F )->post_content === $layout( 'EDITED-IN-THE-BUILDER' ), 'undo skips a footer that was edited afterwards and never overwrites it' );

    // ---- 5h. the three permission layers -----------------------------------------------------------------------
    $headerBefore = $state( $H );
    $probe        = static function ( string $why ) use ( $call, $H, $layout, $errHas, $check, $state, $headerBefore ): void {
        foreach ( [
            'list_theme_layouts'  => [],
            'update_theme_layout' => [ 'layout_id' => $H, 'content' => $layout( 'NOPE' ) ],
            'edit_theme_layout'   => [ 'layout_id' => $H, 'find' => 'AIED-HEADER-V1', 'replace' => 'AIED-HEADER-NOPE' ],
        ] as $tool => $args ) {
            $check( $errHas( $call( $tool, $args ), 'permission' ), "{$why}: {$tool} is refused with a permission error" );
        }
        $check( $state( $H ) === $headerBefore, "{$why}: the header is unchanged" );
    };
    // layer 1: no edit_theme_options (the registry refuses before the tool runs)
    $strip = static fn ( $caps ) => array_merge( (array) $caps, [ 'edit_theme_options' => false ] );
    add_filter( 'user_has_cap', $strip );
    try {
        $check( ! current_user_can( 'edit_theme_options' ) && current_user_can( 'edit_others_posts' ) && et_pb_is_allowed( 'theme_builder' ), 'setup: only edit_theme_options is gone' );
        $probe( 'without edit_theme_options' );
    } finally {
        remove_filter( 'user_has_cap', $strip );
    }
    // layer 2: no edit_others_posts
    $strip = static fn ( $caps ) => array_merge( (array) $caps, [ 'edit_others_posts' => false ] );
    add_filter( 'user_has_cap', $strip );
    try {
        $check( ! current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_theme_options' ), 'setup: edit_others_posts is gone, edit_theme_options still holds' );
        $probe( 'without edit_others_posts' );
    } finally {
        remove_filter( 'user_has_cap', $strip );
    }
    $check( current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_theme_options' ), 'the capability filters were removed again' );
    // layer 3: Divi's Role Editor denies theme_builder
    try {
        $settings                                   = is_array( $roleExisted ? $roleBefore : [] ) ? ( $roleExisted ? $roleBefore : [] ) : [];
        $settings['administrator']                  = isset( $settings['administrator'] ) && is_array( $settings['administrator'] ) ? $settings['administrator'] : [];
        $settings['administrator']['theme_builder'] = 'off';
        update_option( $roleOption, $settings );
        unset( $GLOBALS['et_pb_role_settings'] ); // Divi caches the option in this global
        wp_cache_flush();
        $check( ! et_pb_is_allowed( 'theme_builder' ) && current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_theme_options' ), 'setup: Divi now denies theme_builder while the WordPress capabilities still hold' );
        $probe( 'Role Editor theme_builder off' );
    } finally {
        if ( $roleExisted ) {
            update_option( $roleOption, $roleBefore );
        } else {
            delete_option( $roleOption );
        }
        unset( $GLOBALS['et_pb_role_settings'] );
        wp_cache_flush();
        $now = get_option( $roleOption, $roleSentinel );
        $check( ( $roleExisted ? $now === $roleBefore : $roleSentinel === $now ) && et_pb_is_allowed( 'theme_builder' ), "the Divi Role Editor option {$roleOption} is back to " . ( $roleExisted ? 'its previous value' : 'not existing' ) . ' and Divi allows theme_builder again' );
    }

    // ---- 5i. the template assignment is untouched -------------------------------------------------------------
    clean_post_cache( $T );
    $check( $metaSnapshot( $T ) === $metaT, 'the live template meta is identical (no _et_use_on, _et_default, _et_enabled, *_layout_id or *_layout_enabled change)' );
    $check( $metaSnapshot( $DT ) === $metaDT, 'the draft template meta is identical' );
    $check( get_post_meta( $tb, '_et_template', false ) === $tbSnapshot && get_post_meta( $draftTb, '_et_template', false ) === $draftTbSnap, 'the Theme Builder posts still link exactly the same templates' );
    $check( (int) et_theme_builder_get_theme_builder_post_id( true, false ) === $liveTbAfterFix && (int) et_theme_builder_get_theme_builder_post_id( false, false ) === $draftTbAfterFix, 'the live and draft Theme Builder post ids are the same as after fixture creation' );
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
WP_CLI::success( 'Pro theme layout tools verified through the real registry.' );
```

- [ ] **Step 2: Add the Makefile target**

In `Makefile`, add `verify-pro-theme-layouts` (and, for Task 7, `verify-pro-theme-templates`) to the `.PHONY` line, and add before the `# validate` banner:

```make
# ---------------------------------------------------------------
# verify-pro-theme-layouts — real-site check of the Pro header/footer tools through the real registry (self-cleaning; free + Pro plugins must be active; dev site only: it evicts old batch rows)
# ---------------------------------------------------------------
verify-pro-theme-layouts:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-theme-layouts.php --user=1
```
(Recipe lines start with a TAB.)

- [ ] **Step 3: Lint, activate Pro, run it live, deactivate Pro**

```bash
php -l scripts/verify-pro-theme-layouts.php
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin activate jhmg-ai-editor-for-divi-5-pro
make verify-pro-theme-layouts 2>&1 | tail -25
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin deactivate jhmg-ai-editor-for-divi-5-pro
```
Expected: no `FAIL` line anywhere, the last line `Success: Pro theme layout tools verified through the real registry.`, and the cleanup lines all `PASS` (Theme Builder post count back to its baseline, no `aied verify` posts left, no history rows, no batch rows).

- [ ] **Step 4: Confirm the site is as it was**

Run: `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin list --fields=name,status | grep jhmg-ai` → the free plugin `active`, the Pro add-on `inactive`.

- [ ] **Step 6: Commit**

```bash
git add scripts/verify-pro-theme-layouts.php Makefile
git commit -q -F - <<'EOF'
test(pro): live verification of the Theme Builder layout tools (make verify-pro-theme-layouts)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).


### Task 6: `manage_theme_template` (assign, enable, disable) — code and unit tests

**Files:**
- Create: `pro-addon/src/ThemeBuilder/TemplateStore.php`, `pro-addon/src/Kinds/ThemeTemplateKind.php`, `pro-addon/src/Tools/ManageThemeTemplate.php`, `pro-addon/tests/ManageThemeTemplateTest.php`
- Modify: `pro-addon/tests/ThemeLayoutPermissionTest.php` (final form), `pro-addon/src/autoload.php`, `pro-addon/src/Tools.php`, `pro-addon/src/Batch/Batches.php`, `pro-addon/tests/ToolsTest.php`, `pro-addon/readme.txt`

**Interfaces:**
- Consumes: `ThemeLayouts::liveTemplateIds()/userMay()`, `LayoutTarget::assertMay()`, `LayoutCaches::clear()`, `Canon::hash()`, `Item::make`, `BatchLog`, the `et_theme_builder_store_template` shim (Task 1). Real Divi: `et_theme_builder_store_template( int $themeBuilderId, array $template, bool $allowDefault ): int|false` and `et_theme_builder_get_theme_builder_post_id( true, false )`.
- Produces: `TemplateStore::read(int): ?array` (the complete state in the shape Divi's function takes, or null when not a published template of the LIVE Theme Builder) and `TemplateStore::write(array): ?array` (writes through Divi, clears caches, returns the state read back, or null); `ThemeTemplateKind` (`NAME = 'theme_template'`, `itemFor(array $before, array $after): array`); `ManageThemeTemplate` (`NAME = 'manage_theme_template'`, constructor `(BatchLog $log)`; actions `assign|enable|disable`; `template_id`, `use_on`, `exclude_from`, `dry_run`, `token`).

Design rules this task encodes (tested): the tool never creates, duplicates or deletes (the `action` enum has no such value); it always passes Divi the COMPLETE state with one field changed; the default website template cannot be `assign`ed (it applies everywhere) but can be enabled/disabled; every real run needs the preview token, bound to the arguments and the template's current state; a no-op records nothing; a Divi refusal records nothing and is reported; undo restores the whole previous state only while the template still looks the way the batch left it.

- [ ] **Step 1: Write the failing suite and extend the permission suite**

Create `pro-addon/tests/ManageThemeTemplateTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\ThemeTemplateKind;
use AiEditorDivi5\Pro\Tools\ManageThemeTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';

class ManageThemeTemplateTest extends TestCase
{
    use ThemeLayoutFixture;

    private ManageThemeTemplate $tool;

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $GLOBALS['__divi_tb_store_calls'] = [];
        $GLOBALS['__divi_store_fail']     = false;
        $this->tool                       = new ManageThemeTemplate( $this->log );
        // Template 200: not the default, enabled, applies to pages. Template 210: the default website template.
        $GLOBALS['__wp_postmeta'][200] += [
            '_et_default' => '0', '_et_enabled' => '1', '_et_autogenerated_title' => '0',
            '_et_header_layout_enabled' => '1', '_et_footer_layout_enabled' => '0', '_et_body_layout_enabled' => '1',
            '_et_use_on' => [ 'singular:post_type:page:all' ], '_et_exclude_from' => [],
        ];
        $GLOBALS['__wp_posts'][200]->post_title = 'Pages template';
        $this->themePost( 210, 'et_template', 'publish', [
            '_et_default' => '1', '_et_enabled' => '1', '_et_autogenerated_title' => '0', '_et_header_layout_id' => 201, '_et_header_layout_enabled' => '1',
            '_et_footer_layout_id' => 0, '_et_body_layout_id' => 0, '_et_use_on' => [], '_et_exclude_from' => [],
        ] );
        $GLOBALS['__wp_posts'][210]->post_title = 'Default website template';
        $GLOBALS['__divi_tb']['template_ids']   = [ 200, 210 ];
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
        $GLOBALS['__divi_store_fail'] = false;
    }

    /** @return array<string,mixed> */
    private function meta( int $id ): array
    {
        return $GLOBALS['__wp_postmeta'][ $id ];
    }

    /** Preview, then apply with the returned token. @param array<string,mixed> $args @return array<string,mixed> */
    private function apply( array $args ): array
    {
        $preview = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertArrayHasKey( 'token', $preview );

        return $this->tool->run( $args + [ 'token' => $preview['token'] ] );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ ThemeTemplateKind::NAME => new ThemeTemplateKind() ], $this->log );
    }

    private function refused( array $args, string $message ): void
    {
        $before = [ $this->meta( 200 ), $this->meta( 210 ), count( $GLOBALS['__divi_tb_store_calls'] ), count( $this->repo->rows ) ];
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( $before, [ $this->meta( 200 ), $this->meta( 210 ), count( $GLOBALS['__divi_tb_store_calls'] ), count( $this->repo->rows ) ] );
    }

    public function testADryRunReportsTheChangeAndATokenAndTouchesNothing(): void
    {
        $before = $this->meta( 200 );
        $r      = $this->tool->run( [ 'template_id' => 200, 'action' => 'disable', 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ 'enabled' => [ 'from' => '1', 'to' => '0' ] ], $r['would_change'] );
        $this->assertNotSame( '', $r['token'] );
        $this->assertSame( $before, $this->meta( 200 ) );
        $this->assertSame( [], $GLOBALS['__divi_tb_store_calls'], 'Divi was not asked to write anything' );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testDisableThenEnableChangesOnlyTheEnabledFlag(): void
    {
        $before = $this->meta( 200 );
        $r      = $this->apply( [ 'template_id' => 200, 'action' => 'disable' ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ 'enabled' => [ 'from' => '1', 'to' => '0' ] ], $r['changed_fields'] );
        $after = $this->meta( 200 );
        $this->assertSame( '0', $after['_et_enabled'] );
        unset( $after['_et_enabled'], $before['_et_enabled'], $after['_et_onboarding_created'] );
        $this->assertSame( $before, $after, 'every other meta is exactly as it was (title, default, layouts, where it applies)' );
        $this->assertSame( 'Pages template', $GLOBALS['__wp_posts'][200]->post_title );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'] );
        $this->assertTrue( $r['batch']['undoable'] );

        $this->apply( [ 'template_id' => 200, 'action' => 'enable' ] );
        $this->assertSame( '1', $this->meta( 200 )['_et_enabled'] );
    }

    public function testDivisFunctionIsAlwaysGivenTheCompleteTemplateState(): void
    {
        $this->apply( [ 'template_id' => 200, 'action' => 'disable' ] );
        [ $tb, $state, $allowDefault ] = $GLOBALS['__divi_tb_store_calls'][0];
        $this->assertSame( 100, $tb, 'the LIVE Theme Builder post' );
        $this->assertTrue( $allowDefault );
        $this->assertSame( 200, $state['id'] );
        $this->assertSame( 'Pages template', $state['title'] );
        $this->assertSame( '0', $state['default'] );
        $this->assertSame( [ 'id' => 201, 'enabled' => true ], $state['layouts']['header'] );
        $this->assertSame( [ 'id' => 202, 'enabled' => false ], $state['layouts']['footer'] );
        $this->assertSame( [ 'id' => 203, 'enabled' => true ], $state['layouts']['body'] );
        $this->assertSame( [ 'singular:post_type:page:all' ], $state['use_on'] );
    }

    public function testADefaultTemplateStaysTheDefaultWhenItIsDisabled(): void
    {
        $this->apply( [ 'template_id' => 210, 'action' => 'disable' ] );
        $this->assertSame( '1', $this->meta( 210 )['_et_default'] );
        $this->assertSame( '0', $this->meta( 210 )['_et_enabled'] );
        $this->assertSame( 201, $this->meta( 210 )['_et_header_layout_id'] );
    }

    public function testAssignReplacesWhereItAppliesAndKeepsTheRest(): void
    {
        $r = $this->apply( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => [ 'singular:post_type:post:all' ], 'exclude_from' => [ 'singular:post_type:post:id:5' ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ 'singular:post_type:post:all' ], $this->meta( 200 )['_et_use_on'] );
        $this->assertSame( [ 'singular:post_type:post:id:5' ], $this->meta( 200 )['_et_exclude_from'] );
        $this->assertSame( '1', $this->meta( 200 )['_et_enabled'] );
        $this->assertSame( 202, $this->meta( 200 )['_et_footer_layout_id'] );
    }

    public function testAssigningOnlyUseOnLeavesExcludeFromAlone(): void
    {
        $GLOBALS['__wp_postmeta'][200]['_et_exclude_from'] = [ 'archive:all' ];
        $this->apply( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => [ 'singular:all' ] ] );
        $this->assertSame( [ 'archive:all' ], $this->meta( 200 )['_et_exclude_from'] );
    }

    public function testAnEmptyListClearsThatList(): void
    {
        $this->apply( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => [] ] );
        $this->assertSame( [], $this->meta( 200 )['_et_use_on'] );
    }

    public function testTheDefaultTemplateCannotBeAssigned(): void
    {
        $this->refused( [ 'template_id' => 210, 'action' => 'assign', 'use_on' => [ 'singular:all' ], 'dry_run' => true ], 'default website template' );
    }

    public function testARealRunWithoutOrWithAWrongTokenIsRefused(): void
    {
        $this->refused( [ 'template_id' => 200, 'action' => 'disable' ], 'needs the token' );
        $this->refused( [ 'template_id' => 200, 'action' => 'disable', 'token' => 'nope' ], 'needs the token' );
    }

    public function testATokenIsBoundToTheArgumentsAndToTheTemplatesState(): void
    {
        $preview = $this->tool->run( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => [ 'singular:all' ], 'dry_run' => true ] );
        $this->refused( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => [ 'archive:all' ], 'token' => $preview['token'] ], 'needs the token' );
        $this->refused( [ 'template_id' => 210, 'action' => 'disable', 'token' => $preview['token'] ], 'needs the token' );

        // The template changed after the preview (edited in Divi): the old token no longer works.
        $GLOBALS['__wp_postmeta'][200]['_et_exclude_from'] = [ 'archive:all' ];
        $this->refused( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => [ 'singular:all' ], 'token' => $preview['token'] ], 'needs the token' );
    }

    public function testNoChangeChangesAndRecordsNothing(): void
    {
        $r = $this->tool->run( [ 'template_id' => 200, 'action' => 'enable' ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__divi_tb_store_calls'] );
    }

    /** @return list<array{int,string}> */
    public static function notLiveTemplates(): array
    {
        return [ [ 5, 'a page' ], [ 201, 'a layout' ], [ 100, 'the Theme Builder post' ], [ 9999, 'a missing post' ], [ 301, 'a template of the draft Theme Builder' ] ];
    }

    #[DataProvider( 'notLiveTemplates' )]
    public function testOnlyTemplatesOfTheLiveThemeBuilderCanBeManaged( int $id, string $why ): void
    {
        $this->themePost( 301, 'et_template', 'publish', [ '_et_default' => '0' ] );
        $this->refused( [ 'template_id' => $id, 'action' => 'disable', 'dry_run' => true ], 'not a template of the live Theme Builder' );
    }

    public function testBadArgumentsAreRejectedNotCast(): void
    {
        $this->refused( [ 'action' => 'enable' ], '"template_id" is required.' );
        $this->refused( [ 'template_id' => '200', 'action' => 'enable' ], '"template_id" must be an integer.' );
        $this->refused( [ 'template_id' => 200 ], '"action" is required.' );
        $this->refused( [ 'template_id' => 200, 'action' => 5 ], '"action" must be a string.' );
        $this->refused( [ 'template_id' => 200, 'action' => 'delete' ], '"action" must be one of: assign, enable, disable.' );
        $this->refused( [ 'template_id' => 200, 'action' => 'create' ], '"action" must be one of');
        $this->refused( [ 'template_id' => 200, 'action' => 'duplicate' ], '"action" must be one of');
        $this->refused( [ 'template_id' => 200, 'action' => 'enable', 'dry_run' => 'yes' ], '"dry_run" must be true or false.' );
        $this->refused( [ 'template_id' => 200, 'action' => 'enable', 'token' => 5 ], '"token" must be a string.' );
        $this->refused( [ 'template_id' => 200, 'action' => 'enable', 'use_on' => [ 'a' ] ], 'only used with action=assign');
        $this->refused( [ 'template_id' => 200, 'action' => 'assign' ], 'needs "use_on" and/or "exclude_from"');
        $this->refused( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => 'singular:all' ], 'must be a list');
        $this->refused( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => [ 5 ] ], 'condition ids');
        $this->refused( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => [ 'a b' ] ], 'condition ids');
        $this->refused( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => [ '<script>' ] ], 'condition ids');
        $this->refused( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => [ str_repeat( 'a', 201 ) ] ], 'condition ids');
        $this->refused( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => array_fill( 0, 51, 'a' ) ], 'at most 50');
    }

    public function testWhenDiviRefusesNothingIsRecordedAndTheFailureIsReported(): void
    {
        $preview = $this->tool->run( [ 'template_id' => 200, 'action' => 'disable', 'dry_run' => true ] );
        $GLOBALS['__divi_store_fail'] = true;
        $before                       = $this->meta( 200 );
        try {
            $this->tool->run( [ 'template_id' => 200, 'action' => 'disable', 'token' => $preview['token'] ] );
            $this->fail( 'expected RuntimeException' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'Divi did not save', $e->getMessage() );
        }
        $this->assertSame( $before, $this->meta( 200 ) );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testTemplateStoreNeverWritesATemplateThatIsNotLive(): void
    {
        $before = $this->meta( 200 );
        $this->assertNull( \AiEditorDivi5\Pro\ThemeBuilder\TemplateStore::write( [ 'id' => 9999 ] + ( \AiEditorDivi5\Pro\ThemeBuilder\TemplateStore::read( 200 ) ?? [] ) ), 'a template that is not live is never written' );
        $this->assertSame( $before, $this->meta( 200 ) );
    }

    public function testUndoPutsTheWholeStateBackAndIsItselfUndoable(): void
    {
        $before = $this->meta( 200 );
        $r      = $this->apply( [ 'template_id' => 200, 'action' => 'assign', 'use_on' => [ 'singular:all' ], 'exclude_from' => [ 'archive:all' ] ] );
        $undo   = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( [ 'singular:post_type:page:all' ], $this->meta( 200 )['_et_use_on'] );
        $this->assertSame( [], $this->meta( 200 )['_et_exclude_from'] );
        $this->assertSame( $before['_et_enabled'], $this->meta( 200 )['_et_enabled'] );
        $this->assertSame( [ 'all' ], array_values( array_unique( $GLOBALS['__divi_cache_clears'] ) ) );
        $this->assertTrue( $undo['undo_batch']['undoable'] );
        $again = $this->undo()->undo( $undo['undo_batch']['id'] );
        $this->assertTrue( $again['fully_undone'] );
        $this->assertSame( [ 'singular:all' ], $this->meta( 200 )['_et_use_on'] );
    }

    public function testUndoNeverOverwritesATemplateChangedInDiviLater(): void
    {
        $r = $this->apply( [ 'template_id' => 200, 'action' => 'disable' ] );
        $GLOBALS['__wp_postmeta'][200]['_et_use_on'] = [ 'archive:all' ];
        $undo = $this->undo()->undo( $r['batch']['id'] );
        $this->assertSame( 'changed_since', $undo['items'][0]['result'] );
        $this->assertSame( '0', $this->meta( 200 )['_et_enabled'] );
    }

    public function testUndoIsForbiddenWithoutTheCapabilityOrOnceTheTemplateIsNotLive(): void
    {
        $r = $this->apply( [ 'template_id' => 200, 'action' => 'disable' ] );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true ];
        $this->assertSame( 'forbidden', $this->undo()->undo( $r['batch']['id'] )['items'][0]['result'] );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true, 'edit_theme_options' => true ];

        $GLOBALS['__divi_role_allowed'] = false;
        $this->assertSame( 'forbidden', $this->undo()->undo( $r['batch']['id'] )['items'][0]['result'] );
        unset( $GLOBALS['__divi_role_allowed'] );

        $GLOBALS['__divi_tb']['template_ids'] = [ 210 ];
        $this->assertSame( 'forbidden', $this->undo()->undo( $r['batch']['id'] )['items'][0]['result'] );
        $this->assertSame( '0', $this->meta( 200 )['_et_enabled'] );
    }

    public function testItNeedsEditPostOnTheTemplateItselfForTheToolAndForUndo(): void
    {
        $GLOBALS['__wp_caps']['edit_post:200'] = false;
        $this->refused( [ 'template_id' => 200, 'action' => 'disable', 'dry_run' => true ], 'permission to change template 200' );
        $GLOBALS['__wp_caps']['edit_post:200'] = true;

        $r                                     = $this->apply( [ 'template_id' => 200, 'action' => 'disable' ] );
        $GLOBALS['__wp_caps']['edit_post:200'] = false;
        $this->assertSame( 'forbidden', $this->undo()->undo( $r['batch']['id'] )['items'][0]['result'] );
        $this->assertSame( '0', $this->meta( 200 )['_et_enabled'] );
    }

    public function testItIsRegisteredAndNeedsEditThemeOptions(): void
    {
        $this->assertContains( 'manage_theme_template', array_map( static fn ( $t ) => $t->name(), \AiEditorDivi5\Pro\Tools::all() ) );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $d = $this->tool->definition();
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'template_id', 'action' ], $d['inputSchema']['required'] );
        $this->assertSame( [ 'assign', 'enable', 'disable' ], $d['inputSchema']['properties']['action']['enum'], 'no create, duplicate or delete' );
    }
}
```

Replace `pro-addon/tests/ThemeLayoutPermissionTest.php` with its final form (it now also drives `manage_theme_template` through the registry, and checks the template and Divi's store function are untouched when a call is refused):

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools\EditThemeLayout;
use AiEditorDivi5\Pro\Tools\ListThemeLayouts;
use AiEditorDivi5\Pro\Tools\ManageThemeTemplate;
use AiEditorDivi5\Pro\Tools\UpdateThemeLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ThemeLayoutFixture.php';

/**
 * Standing rule for every Pro tool: the narrowest real capability, checked by the REGISTRY, and a user without it is
 * refused with nothing changed. These tests go through the real ToolRegistry, not the tool's run().
 */
class ThemeLayoutPermissionTest extends TestCase
{
    use ThemeLayoutFixture;

    protected function setUp(): void
    {
        $this->setUpThemeBuilder();
        $GLOBALS['__divi_tb_store_calls'] = [];
    }

    protected function tearDown(): void
    {
        $this->tearDownThemeBuilder();
    }

    /** @return array<string,array{string,array<string,mixed>}> */
    public static function calls(): array
    {
        return [
            'list_theme_layouts'  => [ ListThemeLayouts::NAME, [ 'layout_id' => 201 ] ],
            'update_theme_layout' => [ UpdateThemeLayout::NAME, [ 'layout_id' => 201, 'content' => '__OTHER__' ] ],
            'manage_theme_template' => [ ManageThemeTemplate::NAME, [ 'template_id' => 200, 'action' => 'disable', 'dry_run' => true ] ],
            'edit_theme_layout'   => [ EditThemeLayout::NAME, [ 'layout_id' => 201, 'find' => '"builderVersion":"5.14.0"', 'replace' => '"builderVersion":"5.14.1"', 'expect_count' => 4 ] ],
        ];
    }

    /** @return list<ProTool> */
    private function tools(): array
    {
        return [ new ListThemeLayouts(), new UpdateThemeLayout( $this->log ), new EditThemeLayout( $this->log ), new ManageThemeTemplate( $this->log ) ];
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function call( string $name, array $args ): array
    {
        if ( ( $args['content'] ?? null ) === '__OTHER__' ) {
            $args['content'] = self::otherHeader();
        }
        $r = ( new ToolRegistry( $this->tools(), static fn (): bool => true ) )->claim( null, $name, $args, 1 );

        return (array) $r->get_data();
    }

    private function assertRefusedAndUnchanged( array $data, string $why ): void
    {
        $this->assertArrayHasKey( 'error', $data, $why );
        $this->assertSame( -32602, $data['error']['code'], $why );
        $this->assertSame( self::header(), $this->layoutContent( 201 ), $why );
        $this->assertSame( '1', $GLOBALS['__wp_postmeta'][200]['_et_enabled'], $why );
        $this->assertSame( [], $GLOBALS['__divi_tb_store_calls'] ?? [], $why );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], $why );
        $this->assertSame( [], $this->repo->rows, $why );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testAnAdministratorIsAllowed( string $name, array $args ): void
    {
        $data = $this->call( $name, $args );
        $this->assertArrayHasKey( 'result', $data, $name );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testWithoutEditThemeOptionsTheRegistryRefusesBeforeTheToolRuns( string $name, array $args ): void
    {
        // An editor: may edit others' posts and has Divi's permission, but not edit_theme_options.
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true, 'manage_options' => false ];
        $data                 = $this->call( $name, $args );
        $this->assertRefusedAndUnchanged( $data, $name );
        $this->assertStringContainsString( 'permission', $data['error']['message'] );
        $this->assertSame( [], $GLOBALS['__divi_tb_calls'], 'the tool never ran: Divi was not even asked' );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testWithoutDivisThemeBuilderPermissionTheToolRefuses( string $name, array $args ): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $this->assertRefusedAndUnchanged( $this->call( $name, $args ), $name );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testWithoutEditOthersPostsTheToolRefuses( string $name, array $args ): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => false, 'edit_theme_options' => true ];
        $this->assertRefusedAndUnchanged( $this->call( $name, $args ), $name );
    }

    /** @param array<string,mixed> $args */
    #[DataProvider( 'calls' )]
    public function testWithoutEditPostOnThatLayoutOrTemplateTheToolRefuses( string $name, array $args ): void
    {
        $id                   = (int) ( $args['layout_id'] ?? $args['template_id'] );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, "edit_post:{$id}" => false, 'edit_others_posts' => true, 'edit_theme_options' => true ];
        $this->assertRefusedAndUnchanged( $this->call( $name, $args ), $name );
    }

    public function testLockedLicenceRegistersNothingAndClaimsNothing(): void
    {
        $r = new ToolRegistry( $this->tools(), static fn (): bool => false );
        $this->assertSame( [ [ 'name' => 'x' ] ], $r->definitions( [ [ 'name' => 'x' ] ] ) );
        $this->assertNull( $r->claim( null, 'update_theme_layout', [ 'layout_id' => 201, 'content' => self::otherHeader() ], 1 ) );
        $this->assertSame( self::header(), $this->layoutContent( 201 ) );
    }
}
```

- [ ] **Step 2: Run them to see them fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter 'ManageThemeTemplateTest|ThemeLayoutPermissionTest' 2>&1 | tail -8`
Expected: `ManageThemeTemplate` / `TemplateStore` not found.

- [ ] **Step 3: Write the store, the kind and the tool**

Create `pro-addon/src/ThemeBuilder/TemplateStore.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\ThemeBuilder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reads and writes the state of ONE template of the LIVE Divi Theme Builder, and nothing else. Writing goes through
 * Divi's own `et_theme_builder_store_template()` with the template's COMPLETE current state and one field changed:
 * that function resets every field it is not given (the default flag, the layouts, the title), so a partial array
 * would silently damage the template. It never creates a template and never touches the Theme Builder's mapping of
 * templates, which Divi only changes inside its own save request.
 */
final class TemplateStore
{
    /**
     * The template's full state in the shape `et_theme_builder_store_template()` takes, or null when it is not a
     * published template of the live Theme Builder.
     *
     * @return array{id:int, title:string, default:string, enabled:string, autogenerated_title:string, layouts:array<string,array{id:int,enabled:bool}>, use_on:list<string>, exclude_from:list<string>}|null
     */
    public static function read( int $templateId ): ?array
    {
        if ( ! in_array( $templateId, ThemeLayouts::liveTemplateIds(), true ) ) {
            return null;
        }
        $post = get_post( $templateId );
        if ( ! $post || 'et_template' !== $post->post_type || 'publish' !== $post->post_status ) {
            return null;
        }
        $layout = static fn ( string $kind ): array => [
            'id'      => (int) get_post_meta( $templateId, "_et_{$kind}_layout_id", true ),
            'enabled' => '1' === (string) get_post_meta( $templateId, "_et_{$kind}_layout_enabled", true ),
        ];

        return [
            'id'                  => $templateId,
            'title'               => (string) $post->post_title,
            'default'             => '1' === (string) get_post_meta( $templateId, '_et_default', true ) ? '1' : '0',
            'enabled'             => '1' === (string) get_post_meta( $templateId, '_et_enabled', true ) ? '1' : '0',
            'autogenerated_title' => '1' === (string) get_post_meta( $templateId, '_et_autogenerated_title', true ) ? '1' : '0',
            'layouts'             => [ 'header' => $layout( 'header' ), 'body' => $layout( 'body' ), 'footer' => $layout( 'footer' ) ],
            'use_on'              => self::strings( get_post_meta( $templateId, '_et_use_on', false ) ),
            'exclude_from'        => self::strings( get_post_meta( $templateId, '_et_exclude_from', false ) ),
        ];
    }

    /**
     * Write a full template state through Divi, then clear Divi's caches. Returns the state read back, or null when
     * Divi refused (or its functions are missing), in which case nothing is known to have changed.
     *
     * @param array<string,mixed> $state a complete state as returned by read()
     * @return array<string,mixed>|null
     */
    public static function write( array $state ): ?array
    {
        if ( ! function_exists( 'et_theme_builder_store_template' ) || ! function_exists( 'et_theme_builder_get_theme_builder_post_id' ) ) {
            return null;
        }
        $themeBuilderId = (int) et_theme_builder_get_theme_builder_post_id( true, false );
        if ( $themeBuilderId <= 0 || null === self::read( (int) $state['id'] ) ) {
            return null;
        }
        // allow_default true: the state carries its own default flag, so a default stays default and a non-default stays non-default.
        $saved = et_theme_builder_store_template( $themeBuilderId, $state, true );
        if ( ! $saved || (int) $saved !== (int) $state['id'] ) {
            return null;
        }
        LayoutCaches::clear();

        return self::read( (int) $state['id'] );
    }

    /** @return list<string> */
    private static function strings( mixed $raw ): array
    {
        return array_values( array_filter( array_map( 'strval', (array) $raw ), static fn ( string $s ): bool => '' !== $s ) );
    }
}
```

Create `pro-addon/src/Kinds/ThemeTemplateKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\ThemeBuilder\TemplateStore;
use AiEditorDivi5\Pro\ThemeBuilder\ThemeLayouts;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One live Theme Builder template's state (enabled flag and where it applies). The item stores the previous full state;
 * undo writes it back through Divi's own function, and only while the template still looks the way the batch left it.
 */
final class ThemeTemplateKind implements ItemKind
{
    public const NAME = 'theme_template';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string,mixed> $before the state before the change
     * @param array<string,mixed> $after the state read back after the change
     * @return array<string,mixed>
     */
    public static function itemFor( array $before, array $after ): array
    {
        return Item::make( self::NAME, (string) $after['id'], 'Theme Builder template "' . (string) $after['title'] . '" (#' . (string) $after['id'] . ')', $before, $after );
    }

    public function current( array $item ): mixed
    {
        return TemplateStore::read( (int) $item['ref'] );
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'edit_theme_options' ) && current_user_can( 'edit_post', (int) $item['ref'] ) && ThemeLayouts::userMay() && null !== TemplateStore::read( (int) $item['ref'] );
    }

    public function restore( array $item ): array
    {
        $before = $item['before'] ?? null;
        $now    = TemplateStore::read( (int) $item['ref'] );
        if ( ! is_array( $before ) || null === $now ) {
            return [ 'ok' => false, 'reason' => 'no_previous_state' ];
        }
        $after = TemplateStore::write( $before );
        if ( null === $after ) {
            return [ 'ok' => false, 'reason' => 'divi_refused' ];
        }

        return [ 'ok' => true, 'inverse' => self::itemFor( $now, $after ) ];
    }
}
```

Create `pro-addon/src/Tools/ManageThemeTemplate.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\ThemeTemplateKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use AiEditorDivi5\Pro\ThemeBuilder\TemplateStore;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enable, disable or re-target (where it applies) ONE existing template of the live Theme Builder. It never creates,
 * duplicates or deletes a template and never changes which layouts a template uses. Every real run needs the token
 * from a dry run of the same arguments, and the token is bound to the template's current state, so a template that
 * changed since the preview is refused.
 */
final class ManageThemeTemplate implements ProTool
{
    public const NAME = 'manage_theme_template';

    private const ACTIONS   = [ 'assign', 'enable', 'disable' ];
    private const MAX_RULES = 50;
    private const CONDITION = '/^[A-Za-z0-9_:\-]{1,200}$/';

    public function __construct( private BatchLog $log )
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capability(): string
    {
        return 'edit_theme_options';
    }

    public function definition(): array
    {
        return [
            'name'        => self::NAME,
            'description' => 'Enable or disable one live Divi Theme Builder template, or change where it applies (assign: use_on / exclude_from, in the ids list_theme_layouts shows). Affects the whole site: call with dry_run=true first, then repeat with the token it returns. Undoable. Cannot create or delete templates.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'template_id'  => [ 'type' => 'integer', 'description' => 'template_id from list_theme_layouts' ],
                    'action'       => [ 'type' => 'string', 'description' => 'assign, enable or disable', 'enum' => self::ACTIONS ],
                    'use_on'       => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'assign only: where the template applies (replaces the current list)' ],
                    'exclude_from' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'assign only: where it must not apply (replaces the current list)' ],
                    'dry_run'      => [ 'type' => 'boolean', 'description' => 'Preview only; returns the token a real run needs' ],
                    'token'        => [ 'type' => 'string', 'description' => 'The token from the dry run of the same arguments' ],
                ],
                'required'   => [ 'template_id', 'action' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        LayoutTarget::assertMay();
        $id     = (int) Args::int( $arguments, 'template_id' );
        $action = (string) Args::string( $arguments, 'action', true, 20 );
        if ( ! in_array( $action, self::ACTIONS, true ) ) {
            throw new \InvalidArgumentException( '"action" must be one of: ' . implode( ', ', self::ACTIONS ) . '.' );
        }
        $dry   = Args::bool( $arguments, 'dry_run' );
        $token = Args::string( $arguments, 'token', false, 200 );
        $rules = self::rules( $arguments, $action );

        $before = TemplateStore::read( $id );
        if ( null === $before ) {
            throw new \InvalidArgumentException( "Template {$id} is not a template of the live Theme Builder. Call list_theme_layouts to see the template ids." );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            throw new \InvalidArgumentException( "You do not have permission to change template {$id}." );
        }
        $after = self::target( $before, $action, $rules );
        $label = [ 'template_id' => $id, 'title' => $before['title'], 'default' => '1' === $before['default'] ];
        $diff  = self::diff( $before, $after );

        if ( [] === $diff ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'template' => $label, 'message' => 'The template already is exactly like that; nothing changed.' ];
        }
        $expected = self::token( $id, $action, $rules, $before );
        if ( $dry ) {
            return [ 'changed' => false, 'dry_run' => true, 'template' => $label, 'would_change' => $diff, 'token' => $expected, 'message' => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this token to apply it; it affects the whole site.' ];
        }
        if ( null === $token || ! hash_equals( $expected, $token ) ) {
            throw new \InvalidArgumentException( 'A real run needs the token from a dry run of the same arguments, taken while the template was in its current state. Call again with dry_run=true.' );
        }

        $written = TemplateStore::write( $after );
        if ( null === $written || [] !== self::diff( $after, $written ) ) {
            throw new \RuntimeException( 'Divi did not save the template as requested, so nothing is recorded. Check list_theme_layouts for its current state.' );
        }
        $batch = $this->log->record( self::NAME, ucfirst( $action ) . " Theme Builder template \"{$before['title']}\" (#{$id})", [ ThemeTemplateKind::itemFor( $before, $written ) ] );

        return BatchLog::attach( [ 'changed' => true, 'dry_run' => false, 'template' => $label, 'changed_fields' => $diff, 'note' => 'This applies to the whole site. Divi\'s caches were cleared.' ], $batch );
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array{use_on:?list<string>, exclude_from:?list<string>}
     */
    private static function rules( array $arguments, string $action ): array
    {
        $out = [ 'use_on' => null, 'exclude_from' => null ];
        foreach ( [ 'use_on', 'exclude_from' ] as $key ) {
            if ( ! array_key_exists( $key, $arguments ) || null === $arguments[ $key ] ) {
                continue;
            }
            if ( 'assign' !== $action ) {
                throw new \InvalidArgumentException( "\"{$key}\" is only used with action=assign." );
            }
            $list = $arguments[ $key ];
            if ( ! is_array( $list ) || ! array_is_list( $list ) || count( $list ) > self::MAX_RULES ) {
                throw new \InvalidArgumentException( "\"{$key}\" must be a list of at most " . self::MAX_RULES . ' strings.' );
            }
            foreach ( $list as $c ) {
                if ( ! is_string( $c ) || 1 !== preg_match( self::CONDITION, $c ) ) {
                    throw new \InvalidArgumentException( "\"{$key}\" must contain only condition ids as list_theme_layouts shows them (letters, digits, \":\", \"_\", \"-\"; at most 200 characters)." );
                }
            }
            $out[ $key ] = array_values( array_unique( $list ) );
        }
        if ( 'assign' === $action && null === $out['use_on'] && null === $out['exclude_from'] ) {
            throw new \InvalidArgumentException( 'action=assign needs "use_on" and/or "exclude_from".' );
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $state
     * @param array{use_on:?list<string>, exclude_from:?list<string>} $rules
     * @return array<string,mixed>
     */
    private static function target( array $state, string $action, array $rules ): array
    {
        if ( 'enable' === $action ) {
            $state['enabled'] = '1';
        } elseif ( 'disable' === $action ) {
            $state['enabled'] = '0';
        } else {
            if ( '1' === $state['default'] ) {
                throw new \InvalidArgumentException( 'The default website template applies everywhere, so it has no "use_on" list to change. Enable or disable it instead.' );
            }
            $state['use_on']       = $rules['use_on'] ?? $state['use_on'];
            $state['exclude_from'] = $rules['exclude_from'] ?? $state['exclude_from'];
        }

        return $state;
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,array{from:mixed,to:mixed}>
     */
    private static function diff( array $a, array $b ): array
    {
        $out = [];
        foreach ( [ 'enabled', 'use_on', 'exclude_from' ] as $field ) {
            if ( $a[ $field ] !== $b[ $field ] ) {
                $out[ $field ] = [ 'from' => $a[ $field ], 'to' => $b[ $field ] ];
            }
        }

        return $out;
    }

    /**
     * @param array{use_on:?list<string>, exclude_from:?list<string>} $rules
     * @param array<string,mixed> $state
     */
    private static function token( int $id, string $action, array $rules, array $state ): string
    {
        return wp_hash( 'aied-template|' . Canon::hash( [ 'id' => $id, 'action' => $action, 'rules' => $rules, 'state' => $state ] ) );
    }
}
```

- [ ] **Step 4: Wire it in**

`pro-addon/src/autoload.php`: after the `ThemeBuilder/LayoutCaches.php` require add
```php
require_once __DIR__ . '/ThemeBuilder/TemplateStore.php';
require_once __DIR__ . '/Kinds/ThemeTemplateKind.php';
```
and after the `Tools/EditThemeLayout.php` require add `require_once __DIR__ . '/Tools/ManageThemeTemplate.php';`.

`pro-addon/src/Tools.php`: add `use AiEditorDivi5\Pro\Tools\ManageThemeTemplate;` after the `ListThemeLayouts` import and `new ManageThemeTemplate( $log ),` after `new EditThemeLayout( $log ),`.

`pro-addon/src/Batch/Batches.php`: add `use AiEditorDivi5\Pro\Kinds\ThemeTemplateKind;` after the `ThemeLayoutKind` import and `new ThemeTemplateKind(),` after `new ThemeLayoutKind(),`.

`pro-addon/tests/ToolsTest.php`: append `'manage_theme_template'` to the expected tool list and `'theme_template'` to the expected kind list.

`pro-addon/readme.txt`: before the `list_batches` bullet add
```
* `manage_theme_template` - enable or disable one live Divi Theme Builder template, or change where it applies. It affects the whole site, so every real run needs the token from a preview (`dry_run`). It cannot create or delete templates.
```

- [ ] **Step 5: Run both suites**

Run: `make test-pro 2>&1 | tail -3` → `OK (561 tests, 2080 assertions)`; `make test 2>&1 | tail -2` → `OK (726 tests, 5241 assertions)`.

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src pro-addon/tests pro-addon/readme.txt
git commit -q -F - <<'EOF'
feat(pro): manage_theme_template (assign, enable, disable) through Divi's store function with the template's full state

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).


### Task 7: Live evidence gate for `manage_theme_template` (`make verify-pro-theme-templates`)

**Files:**
- Create: `scripts/verify-pro-theme-templates.php`
- Modify: `Makefile`

**Interfaces:**
- Consumes: the tool, `TemplateStore`, the real registry, Divi's own resolver `et_theme_builder_get_template_layouts( ET_Theme_Builder_Request::from_post( $id ), false, false )` (this is how the script proves Divi ITSELF applies or stops applying the template to a throwaway page, not just that a meta changed).
- Produces: the decision for Phase B. **Gate rule: if this script prints any `FAIL`, `manage_theme_template` is cut from 0.3.0** (steps at the end of this task); if it passes, the tool ships.

This script was already run against the real Divi 5.14 dev site while the plan was replayed and passed after one script defect (a per-id `edit_post` probe) was fixed; the version below is the fixed one. Findings it recorded: assign/enable/disable change only the requested meta; Divi's resolver follows the change in both directions; undo returns the template to its exact starting state; the only meta Divi's own function adds is `_et_onboarding_created` = `0`.

- [ ] **Step 1: Write the script**

Create `scripts/verify-pro-theme-templates.php`:

```php
<?php
/**
 * Real-site check of the Pro manage_theme_template tool on Divi's own Theme Builder: the EVIDENCE GATE for that tool.
 * If any check FAILS, the tool is cut from the release (plan Task 8). It proves, through the real ToolRegistry:
 * assign / enable / disable change exactly the field asked for (every other meta, the title and the layouts are
 * byte-identical), Divi's own resolver (et_theme_builder_get_template_layouts) then really applies / stops applying the
 * template to a throwaway page, undo puts the whole state back (the template is identical to the start), the preview
 * token is required and bound to the arguments and the state, a template changed since is never overwritten, only live
 * templates can be managed (not orphans, draft-Theme-Builder templates, layouts, posts), create / duplicate / delete do
 * not exist, the permission layers each refuse, and nothing outside the one template changes.
 *
 * Run: make verify-pro-theme-templates   (needs `make up`, the free plugin and the Pro add-on active on the dev site)
 *
 * Builds throwaway fixtures with Divi's own functions (the throwaway template applies to nothing until the script
 * assigns it to a throwaway page) and removes exactly what it created. WARNING: it records about 8 batches; the batch
 * store keeps only the newest 20, so on a site that already has batches it evicts the oldest real ones. Dev sites only.
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

    // ---- 5. the template tool, through the real registry ------------------------------------------------------
    $store = \AiEditorDivi5\Pro\ThemeBuilder\TemplateStore::class;
    $metaT0 = $metaSnapshot( $T );
    $titleT0 = get_post( $T )->post_title;
    $PGT = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'aied verify target page', 'post_content' => '' ] );
    $created[] = $PGT;
    $cond      = "singular:post_type:page:id:{$PGT}";
    $headerOf  = static function () use ( $PGT ): int {
        $layouts = et_theme_builder_get_template_layouts( ET_Theme_Builder_Request::from_post( $PGT ), false, false );

        return (int) ( $layouts['et_header_layout']['id'] ?? 0 );
    };
    $sameExcept = static function ( array $a, array $b, array $except ): bool {
        foreach ( array_merge( $except, [ '_et_onboarding_created' ] ) as $k ) {
            unset( $a[ $k ], $b[ $k ] );
        }

        return $a === $b;
    };
    $check( function_exists( 'et_theme_builder_get_template_layouts' ) && class_exists( 'ET_Theme_Builder_Request' ), 'setup: Divi\'s own template resolver is available' );
    $check( $headerOf() !== $H, 'before: Divi does not apply the throwaway template to the throwaway page' );

    // token rules
    $d = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ], 'dry_run' => true ] );
    $check( null === $d['error'] && false === ( $d['result']['changed'] ?? null ) && is_string( $d['result']['token'] ?? null ) && [ 'use_on' ] === array_keys( $d['result']['would_change'] ?? [] ), 'assign dry_run: reports would_change use_on and returns a token' );
    $check( $metaSnapshot( $T ) === $metaT0, 'the dry run changed nothing' );
    $check( $errHas( $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ] ] ), 'needs the token' ), 'a real run without a token is refused' );
    $check( $errHas( $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond . '2' ], 'token' => $d['result']['token'] ] ), 'needs the token' ), 'a token for other arguments is refused' );
    $check( $metaSnapshot( $T ) === $metaT0, 'the refused calls changed nothing' );

    // assign
    $a = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'assign', 'use_on' => [ $cond ], 'token' => $d['result']['token'] ] );
    $check( null === $a['error'] && true === ( $a['result']['changed'] ?? null ) && true === ( $a['result']['batch']['undoable'] ?? null ), 'assign: changed, batch undoable' );
    clean_post_cache( $T );
    $metaA = $metaSnapshot( $T );
    $check( [ $cond ] === array_values( (array) ( $metaA['_et_use_on'] ?? [] ) ), 'assign: _et_use_on is exactly the requested condition' );
    $check( $sameExcept( $metaT0, $metaA, [ '_et_use_on' ] ) && get_post( $T )->post_title === $titleT0, 'assign: every other meta (default, enabled, the three layouts and their flags, autogenerated title) and the title are identical' );
    $check( $headerOf() === $H, 'assign: Divi\'s own resolver now applies the template (its header) to the throwaway page' );
    $unusedUndo = (int) $a['result']['batch']['id'];

    // disable / enable
    $d = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'disable', 'dry_run' => true ] );
    $x = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'disable', 'token' => $d['result']['token'] ?? '' ] );
    $check( null === $x['error'] && true === ( $x['result']['changed'] ?? null ), 'disable: changed' );
    clean_post_cache( $T );
    $check( '0' === (string) get_post_meta( $T, '_et_enabled', true ) && $sameExcept( $metaA, $metaSnapshot( $T ), [ '_et_enabled' ] ), 'disable: only _et_enabled changed' );
    $check( $headerOf() !== $H, 'disable: Divi\'s own resolver stops applying the template to the throwaway page' );
    $disableBatch = (int) $x['result']['batch']['id'];

    $d = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'enable', 'dry_run' => true ] );
    $x = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'enable', 'token' => $d['result']['token'] ?? '' ] );
    clean_post_cache( $T );
    $check( null === $x['error'] && '1' === (string) get_post_meta( $T, '_et_enabled', true ) && $headerOf() === $H, 'enable: back on, and Divi\'s resolver applies it again' );
    $check( $metaSnapshot( $T ) !== [] && $sameExcept( $metaA, $metaSnapshot( $T ), [] ), 'disable then enable: the template is identical to after the assign' );
    $enableBatch = (int) $x['result']['batch']['id'];

    // a no-op records nothing
    $n = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'enable' ] );
    $check( null === $n['error'] && false === ( $n['result']['changed'] ?? null ) && ! isset( $n['result']['batch'] ), 'enabling an enabled template changes and records nothing' );

    // undo walks back to the start
    $u = $call( 'undo_batch', [ 'batch_id' => $enableBatch ] );
    $check( true === ( $u['result']['fully_undone'] ?? null ), 'undo of the enable (back to disabled): fully undone' );
    $u = $call( 'undo_batch', [ 'batch_id' => $disableBatch ] );
    $check( true === ( $u['result']['fully_undone'] ?? null ), 'undo of the disable (back to enabled): fully undone' );
    $u = $call( 'undo_batch', [ 'batch_id' => $unusedUndo ] );
    clean_post_cache( $T );
    $check( true === ( $u['result']['fully_undone'] ?? null ) && $sameExcept( $metaT0, $metaSnapshot( $T ), [] ) && get_post( $T )->post_title === $titleT0, 'undo of the assign: the template is identical to its state before this script touched it' );
    $check( $headerOf() !== $H, 'after undo: Divi no longer applies the template to the throwaway page' );
    $diff = array_diff_key( $metaSnapshot( $T ), $metaT0 );
    WP_CLI::log( 'INFO  meta keys Divi\'s own function added that were not there before: ' . ( [] === $diff ? 'none' : implode( ', ', array_keys( $diff ) ) ) );

    // a template changed since is never overwritten
    $d = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'disable', 'dry_run' => true ] );
    $x = $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'disable', 'token' => $d['result']['token'] ?? '' ] );
    delete_post_meta( $T, '_et_use_on' );
    add_post_meta( $T, '_et_use_on', 'archive:all' );
    $u = $call( 'undo_batch', [ 'batch_id' => (int) $x['result']['batch']['id'] ] );
    $check( 'changed_since' === ( $u['result']['items'][0]['result'] ?? null ) && '0' === (string) get_post_meta( $T, '_et_enabled', true ), 'undo skips a template that was edited afterwards and never overwrites it' );
    delete_post_meta( $T, '_et_use_on' );
    foreach ( (array) ( $metaT0['_et_use_on'] ?? [] ) as $v ) {
        add_post_meta( $T, '_et_use_on', $v );
    }
    update_post_meta( $T, '_et_enabled', '1' );
    clean_post_cache( $T );

    // only live templates, and no create / duplicate / delete
    foreach ( [ 'a draft-Theme-Builder template' => $DT, 'a layout' => $H, 'a page' => $PGT, 'the Theme Builder post' => $tb, 'a missing post' => 99999999 ] as $name => $rid ) {
        $b = 99999999 === $rid ? [] : $state( $rid );
        $check( $errHas( $call( 'manage_theme_template', [ 'template_id' => $rid, 'action' => 'disable', 'dry_run' => true ] ), 'not a template of the live Theme Builder' ) && ( 99999999 === $rid || $state( $rid ) === $b ), "{$name} is refused and unchanged" );
    }
    foreach ( [ 'create', 'duplicate', 'delete' ] as $action ) {
        $check( $errHas( $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => $action ] ), 'must be one of' ), "action {$action} does not exist" );
    }

    // permission layers
    $metaNow = $metaSnapshot( $T );
    $probe   = static function ( string $why ) use ( $call, $T, $errHas, $check, $metaSnapshot, $metaNow ): void {
        $check( $errHas( $call( 'manage_theme_template', [ 'template_id' => $T, 'action' => 'disable', 'dry_run' => true ] ), 'permission' ), "{$why}: manage_theme_template is refused with a permission error" );
        $check( $metaSnapshot( $T ) === $metaNow, "{$why}: the template is unchanged" );
    };
    foreach ( [ 'edit_theme_options', 'edit_others_posts' ] as $cap ) {
        $strip = static fn ( $caps ) => array_merge( (array) $caps, [ $cap => false ] );
        add_filter( 'user_has_cap', $strip );
        try {
            $probe( "without {$cap}" );
        } finally {
            remove_filter( 'user_has_cap', $strip );
        }
    }
    try {
        $settings                                   = is_array( $roleExisted ? $roleBefore : [] ) ? ( $roleExisted ? $roleBefore : [] ) : [];
        $settings['administrator']                  = isset( $settings['administrator'] ) && is_array( $settings['administrator'] ) ? $settings['administrator'] : [];
        $settings['administrator']['theme_builder'] = 'off';
        update_option( $roleOption, $settings );
        unset( $GLOBALS['et_pb_role_settings'] );
        wp_cache_flush();
        $probe( 'Role Editor theme_builder off' );
    } finally {
        if ( $roleExisted ) {
            update_option( $roleOption, $roleBefore );
        } else {
            delete_option( $roleOption );
        }
        unset( $GLOBALS['et_pb_role_settings'] );
        wp_cache_flush();
    }
    // edit_post is a META capability: refuse it for this one template through map_meta_cap, as a role plugin would
    $denyOne = static fn ( $caps, $cap, $uid, $args ) => ( 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $T ) ? [ 'do_not_allow' ] : $caps;
    add_filter( 'map_meta_cap', $denyOne, 10, 4 );
    try {
        $check( ! current_user_can( 'edit_post', $T ) && current_user_can( 'edit_post', $H ), 'setup: edit_post is refused for this template only' );
        $probe( 'without edit_post on the template' );
    } finally {
        remove_filter( 'map_meta_cap', $denyOne, 10 );
    }
    $check( current_user_can( 'edit_post', $T ), 'the map_meta_cap filter was removed again' );

    // nothing outside the one template changed
    $check( $metaSnapshot( $DT ) === $metaDT, 'the draft template meta is identical' );
    $check( get_post_meta( $tb, '_et_template', false ) === $tbSnapshot && get_post_meta( $draftTb, '_et_template', false ) === $draftTbSnap, 'the Theme Builder posts still link exactly the same templates' );
    $check( $liveBackupBefore === get_option( $backupOption( $tb ), null ), 'Divi\'s template-mapping backup option was never touched' );
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
WP_CLI::success( 'Pro manage_theme_template verified on the real Divi Theme Builder.' );
```

- [ ] **Step 2: Add the Makefile target**

In `Makefile` (the `.PHONY` line already got the name in Task 5), add before the `# validate` banner:

```make
# ---------------------------------------------------------------
# verify-pro-theme-templates — real-site evidence gate for manage_theme_template (self-cleaning; free + Pro plugins must be active; dev site only)
# ---------------------------------------------------------------
verify-pro-theme-templates:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-theme-templates.php --user=1
```

- [ ] **Step 3: Lint, activate Pro, run it live, deactivate Pro**

```bash
php -l scripts/verify-pro-theme-templates.php
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin activate jhmg-ai-editor-for-divi-5-pro
make verify-pro-theme-templates 2>&1 | tail -60
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin deactivate jhmg-ai-editor-for-divi-5-pro
```
Expected: no `FAIL`; one `INFO  meta keys Divi's own function added …: _et_onboarding_created`; the last line `Success: Pro manage_theme_template verified on the real Divi Theme Builder.`

- [ ] **Step 4: Apply the gate**

- All `PASS` → commit (below) and go on to Task 8.
- Any `FAIL` → STOP and report the failing lines to the owner. Do NOT weaken a check. If the owner confirms the cut (or a failure shows Divi's function cannot be used safely), cut the tool in ONE commit: delete `src/Tools/ManageThemeTemplate.php`, `src/ThemeBuilder/TemplateStore.php`, `src/Kinds/ThemeTemplateKind.php`, `tests/ManageThemeTemplateTest.php`, `scripts/verify-pro-theme-templates.php`; remove their `require_once` lines, the `ManageThemeTemplate` import and entry in `src/Tools.php`, the `ThemeTemplateKind` import and entry in `src/Batch/Batches.php`, `'manage_theme_template'` / `'theme_template'` from `tests/ToolsTest.php`, the `verify-pro-theme-templates` Makefile target and `.PHONY` name, the `manage_theme_template` bullet and changelog line in `readme.txt`, and the template row/lines in `tests/ThemeLayoutPermissionTest.php` (restore the Task 4 form of that file); keep `et_theme_builder_store_template` in the shims (harmless). Then Task 8's docs say "three tools" and the spec row 9 is marked cut. Run `make test-pro` and `make test`.

- [ ] **Step 5: Commit (gate passed)**

```bash
git add scripts/verify-pro-theme-templates.php Makefile
git commit -q -F - <<'EOF'
test(pro): live evidence gate for manage_theme_template (make verify-pro-theme-templates)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).

### Task 8: Documentation, version 0.3.0, final checks

**Files:**
- Modify: `pro-addon/README.md`, `pro-addon/readme.txt` (changelog), `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`, `pro-addon/tests/bootstrap.php`, `CLAUDE.md`, `docs/superpowers/specs/2026-10-02-pro-addon-design.md`

(If Task 7's gate cut `manage_theme_template`, write "three tools" / "10 tools" below and drop every sentence about templates and the changelog line for it.)

- [ ] **Step 1: Bump the version to 0.3.0**

- `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`: ` * Version:           0.2.0` → `0.3.0`; `define( 'AIED_PRO_VERSION', '0.2.0' );` → `'0.3.0'`.
- `pro-addon/tests/bootstrap.php`: `define( 'AIED_PRO_VERSION', '0.2.0' );` → `'0.3.0'`.
- `pro-addon/readme.txt`: `Stable tag:        0.2.0` → `0.3.0`, and before `= 0.2.0 =` in the changelog add:

```
= 0.3.0 =
* New: edit the live Divi header and footer (`list_theme_layouts`, `update_theme_layout`, `edit_theme_layout`): checked like every page write, previewed with `dry_run`, undoable.
* New: `manage_theme_template` enables, disables or re-targets one existing Theme Builder template (preview token required, undoable). It cannot create or delete templates.

```

- [ ] **Step 2: Update `pro-addon/README.md`**

- Heading `## What exists (0.2.0)` → `## What exists (0.3.0)`.
- In the `src/ProTool.php, src/ToolRegistry.php, src/Tools.php` row replace `lists the seven tools` with `lists the eleven tools`.
- In the `src/Kinds/` row replace `front page, Additional CSS block, menu, page status, page content` with `front page, Additional CSS block, menu, page status, page content, header/footer content, Theme Builder template`.
- In the `src/Tools/` row replace the list with: `the eleven tools: set_front_page, set_primary_menu, publish_page, set_custom_css, propose_php_snippet, list_batches, undo_batch, list_theme_layouts, update_theme_layout, edit_theme_layout, manage_theme_template`.
- Add a row after the `src/Kinds/` row: `| src/ThemeBuilder/ | the live Theme Builder read model (ThemeLayouts, LayoutTarget: which post a layout tool may touch), the one layout write path (LayoutWriter), Divi cache clearing, and TemplateStore (reads/writes one template through Divi's own store function) |`.
- In the sentence beginning `` `make test-pro` runs the Pro tests; `` replace `` `make verify-pro-skeleton` and `make verify-pro-settings` are the real-site checks `` with `` `make verify-pro-skeleton`, `make verify-pro-settings`, `make verify-pro-theme-layouts` and `make verify-pro-theme-templates` are the real-site checks ``.
- At the end of the "Undo, in one paragraph" paragraph add: ` Header/footer and template items re-check, at undo time, that the layout or template is still a live Theme Builder one, the user's Theme Builder permission and `edit_theme_options`; a change made in Divi's own builder afterwards is never overwritten.`
- In the `## staged-4.3.0/` section add: `` `ThemeLayouts` and the layout half of `EditTarget` were ported into `src/ThemeBuilder/` in 0.3.0; this folder stays as the record of the 4.3.0 spike. ``

- [ ] **Step 3: Update `CLAUDE.md`**

- In "Three parts, one repo", replace `0.2.0: compat guard, licensing with the updater, a licence-gated tool` / `registry with 7 tools, batches and one-step undo,` with `0.3.0: compat guard, licensing with the updater, a licence-gated tool` / `registry with 11 tools (settings, batches and undo, Theme Builder header/footer and template tools),`.
- Before the `**Pro add-on 0.2.0**` bullet in "Current state" add:

```
- **Pro add-on 0.3.0** (branch `feat/pro-theme-layouts`; NOT merged, NOT released, NOT pushed): four Theme Builder tools
  (`list_theme_layouts`, `update_theme_layout`, `edit_theme_layout`, `manage_theme_template` = assign/enable/disable of an
  EXISTING live template; create/duplicate were cut because Divi maps templates onto the Theme Builder post only inside its own
  AJAX save, so they would need a raw meta write). Registry capability `edit_theme_options` plus Divi's bar (`edit_others_posts` +
  Role Editor `theme_builder`) plus `edit_post` on the target, re-checked by the item kinds at undo. Layout writes go through
  `LayoutGate::check($new, $stored)`, the free `HistoryService`, and a Divi cache clear; templates only through
  `et_theme_builder_store_template()` with the template's complete state; every template run needs a preview token. Plan:
  `docs/superpowers/plans/2026-10-03-pro-theme-builder-tools.md`. Verified: `make test-pro` (561), `make test` (726),
  `make verify-pro-theme-layouts`, `make verify-pro-theme-templates` (real Divi 5.14). Next: Plan 5 (`find_replace_site`,
  `audit_site`, REST/OpenAPI parity: Pro = 13 tools, 29 of 30 ChatGPT operations).
```
- In the `**Pro add-on 0.2.0**` bullet change `Next: Plan 4 (Theme Builder layout tools + templates), Plan 5` to `Next: Plan 5`.
- In the entry-points table add rows `| make verify-pro-theme-layouts | Real-site check of the Pro header/footer tools through the real registry (self-cleaning; free + Pro plugins active; dev site only) |` and `| make verify-pro-theme-templates | Real-site evidence gate for manage_theme_template (self-cleaning; dev site only) |` after the `make verify-pro-settings` row.

- [ ] **Step 4: Update the spec (`docs/superpowers/specs/2026-10-02-pro-addon-design.md`)**

- Tool table row 9: replace `` `create`, `assign`, `duplicate`, `enable`/`disable` a Theme Builder template; `dry_run` `` with `` `assign` (where it applies), `enable`, `disable` an EXISTING live Theme Builder template; every real run needs a preview token (`create`/`duplicate` cut: Divi maps templates onto the Theme Builder post only inside its own save request, so they would need a raw meta write) ``, and rows 6–9's "Needs" cells with `` `edit_theme_options` + Divi's Theme Builder bar + `edit_post` ``.
- In "Template tools:" replace the whole bullet with: `- **Template tools:** `assign`, `enable` and `disable` record the template's previous full state and undo restores it under the changed-since rule. Written only through Divi's own `et_theme_builder_store_template()` with the template's complete current state (that function resets every field it is not given). Hard delete, create and duplicate are not offered in v1.`
- In "Verification" replace the `- Templates: create, assign, duplicate, enable/disable …` bullet with `- Templates: assign, enable, disable and undo on a throwaway template, with Divi's own resolver proving the effect (`make verify-pro-theme-templates`, run 2026-10-03: passed).`
- In "Risks" item 1 replace with: `1. Menu creation is unproven on a real site; a failed live check cuts that tool from v1 rather than shipping a guess. (Template create/duplicate were cut on source evidence; assign/enable/disable were proven live.)`

- [ ] **Step 5: Final checks**

```bash
make test-pro 2>&1 | tail -3
make test 2>&1 | tail -2
make build-pro 2>&1 | tail -2
unzip -l dist/jhmg-ai-editor-for-divi-5-pro.zip | grep -E "ThemeBuilder|staged|tests/" 
git status --short
```
Expected: `OK (561 tests, 2080 assertions)`; `OK (726 tests, 5241 assertions)`; the zip lists `src/ThemeBuilder/*` and NO `staged` or `tests/` entries; a clean tree apart from the docs you just edited; the Pro add-on `inactive` on the dev site (`plugin list`).

- [ ] **Step 6: Commit**

```bash
git add pro-addon CLAUDE.md docs/superpowers/specs/2026-10-02-pro-addon-design.md
git commit -q -F - <<'EOF'
docs(pro): 0.3.0 (Theme Builder tools): version, README, readme.txt changelog, CLAUDE.md, spec

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
git log -1 --format=%B
```
Expected: the last command prints the subject and the `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>` trailer exactly (a subagent must not write its own model name).


- [ ] **Step 7: Final whole-branch review on the most capable model (opus)**

Dispatch one reviewer over `git diff main...feat/pro-theme-layouts` with the Review Focus list above and the "What the research settled" section as the brief. Verify its claims with grep before acting. Fix real findings in small commits; then hand the branch to the owner. Do not merge, push or release.
