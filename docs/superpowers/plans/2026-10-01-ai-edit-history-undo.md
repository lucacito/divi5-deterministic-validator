# AI Edit History / Undo (3.5.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every AI write snapshots the page's previous content so any AI edit can be listed and undone (MCP, REST, ChatGPT OpenAPI, and an admin panel), shipped as 3.5.0.

**Architecture:** A pure `PageHistory` class owns the snapshot list rules (retention, size cap, dedup, ids). `HistoryStore` is the only code touching post meta. `HistoryService` is the single entry point both transports use: `write()` (snapshot-then-save), `listFor()`, `entry()`, `restore()`. The two existing write paths in `McpHandler` and `RestController` call `HistoryService::write` instead of `wp_update_post` directly. Restore deliberately bypasses the reject-invalid gate (it restores the person's own earlier content) but returns the validator's verdict as information.

**Tech Stack:** PHP 8.1+, PHPUnit 11 (WordPress shims in `tests/bootstrap.php`), WordPress post meta, Docker WP + WP-CLI for live checks.

**Spec:** `docs/superpowers/specs/2026-10-01-ai-edit-history-undo-design.md` (approved)

## Global Constraints

- Validator core (`src/`, `wp-plugin/validator/`) is NOT modified by this release.
- Plugin slug / text domain: `ai-editor-for-divi-5`; REST namespace `ai-editor-divi5/v1`; menu slug and MCP server name stay `ai-editor-divi5`.
- Retention = **10** snapshots per page; per-snapshot cap = **524288** bytes (512 KB); both are class constants pinned by tests.
- History tools are **free tier** (no `Licensing::isPremium()` gate).
- Same logic exposed three ways in lockstep: MCP (`McpHandler`), REST (`RestController`), ChatGPT OpenAPI (`OpenApiSpec`); every OpenAPI operation `description` ≤ 300 characters and no bare `type: object` response schema (existing `OpenApiSpecTest`).
- Content written to the database must go through `wp_slash()` (WordPress `wp_update_post`/`update_post_meta` call `wp_unslash`); backslash round-trips must be byte-exact.
- Capability: every history/restore endpoint requires `current_user_can('edit_post', $pageId)` and `post_type === 'page'`.
- The plugin must pass WordPress Plugin Check with no errors under slug `ai-editor-for-divi-5` (escape all output, nonces on admin-post handlers, no `Tested up to` in the plugin header — it lives only in `readme.txt`).
- PHP `>=8.1`; PHPUnit `failOnWarning` + `failOnRisky` (attribute `#[DataProvider]`, never docblock annotations; every test must assert).
- `make test` exit 0 after every task; commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Never upload to WordPress.org, push, or merge to `main` as part of this plan.

## Review Focus

1. Page content containing backslashes / escaped HTML (`<`, `\"`) must round-trip byte-for-byte through snapshot + restore (Task 2 test).
2. A page larger than the 512 KB cap must still be written; the result says no snapshot was stored and why (Task 2 test).
3. Restoring a snapshot the validator rejects must still succeed and report `validator.valid=false` with violations (Task 2 test).
4. Corrupt / non-JSON / wrong-shape `_aied_history` meta must never fatal or block a write; it is treated as empty history (Task 1 + 2 tests).
5. Unknown version id, non-page id, or a user lacking `edit_post` must produce a clean error and change nothing (Tasks 2 and 4).
6. Snapshot ids must never be reused after old snapshots are trimmed (Task 1 test).

---

### Task 1: `PageHistory` — pure snapshot rules

**Files:**
- Create: `wp-plugin/src/PageHistory.php`
- Create: `tests/PageHistoryTest.php`

**Interfaces:**
- Produces (all `public static`, namespace `AiEditorDivi5\WP`):
  - `const RETENTION = 10;` `const MAX_BYTES = 524288;`
  - `empty(): array` → `['next' => 1, 'items' => []]`
  - `normalize(mixed $raw): array` — coerces any decoded value into a valid history (`['next'=>int>=1, 'items'=>list]`), dropping malformed items; never throws.
  - `record(array $history, string $content, string $tool, int $actor, string $savedAt, ?string $label = null): array` → `['history'=>array, 'stored'=>bool, 'version_id'=>?int, 'reason'=>?string]`; `reason` is `null | 'duplicate' | 'too_large'`.
  - `summaries(array $history): array` — newest first, each item WITHOUT `content`: `id, saved_at, tool, actor, bytes, label`.
  - `find(array $history, int $id): ?array` — the full item (with `content`) or null.
  - `recent(array $byPage, int $limit): array` — `$byPage` is `[pageId => history]`; returns up to `$limit` rows `['page_id','version_id','saved_at','tool']`, one per page (that page's newest snapshot), newest first across pages.

- [ ] **Step 1: Write the failing test** — `tests/PageHistoryTest.php`

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\PageHistory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';

class PageHistoryTest extends TestCase
{
    private function rec(array $h, string $content, string $at = '2026-10-01T00:00:00Z', string $tool = 'update_page_layout'): array
    {
        return PageHistory::record($h, $content, $tool, 7, $at, null);
    }

    public function testConstantsArePinned(): void
    {
        $this->assertSame(10, PageHistory::RETENTION);
        $this->assertSame(524288, PageHistory::MAX_BYTES);
    }

    public function testRecordStoresNewestFirstWithMetadata(): void
    {
        $r = $this->rec(PageHistory::empty(), 'A');
        $this->assertTrue($r['stored']);
        $this->assertSame(1, $r['version_id']);
        $this->assertNull($r['reason']);
        $r2 = $this->rec($r['history'], 'B', '2026-10-01T01:00:00Z');
        $this->assertSame(2, $r2['version_id']);
        $items = $r2['history']['items'];
        $this->assertSame(['B', 'A'], array_column($items, 'content'));
        $this->assertSame(hash('sha256', 'B'), $items[0]['sha256']);
        $this->assertSame(1, $items[0]['bytes']);
        $this->assertSame(7, $items[0]['actor']);
        $this->assertSame('update_page_layout', $items[0]['tool']);
    }

    public function testDuplicateOfNewestIsNotStoredButPointsAtIt(): void
    {
        $r = $this->rec(PageHistory::empty(), 'same');
        $d = $this->rec($r['history'], 'same');
        $this->assertFalse($d['stored']);
        $this->assertSame('duplicate', $d['reason']);
        $this->assertSame(1, $d['version_id']);
        $this->assertCount(1, $d['history']['items']);
    }

    public function testNonAdjacentRepeatIsStoredAgain(): void
    {
        $h = $this->rec(PageHistory::empty(), 'A')['history'];
        $h = $this->rec($h, 'B')['history'];
        $r = $this->rec($h, 'A');
        $this->assertTrue($r['stored']);
        $this->assertCount(3, $r['history']['items']);
    }

    public function testOversizedContentIsNotStored(): void
    {
        $big = str_repeat('x', PageHistory::MAX_BYTES + 1);
        $r = $this->rec(PageHistory::empty(), $big);
        $this->assertFalse($r['stored']);
        $this->assertNull($r['version_id']);
        $this->assertSame('too_large', $r['reason']);
        $this->assertSame([], $r['history']['items']);
    }

    public function testContentExactlyAtTheCapIsStored(): void
    {
        $r = $this->rec(PageHistory::empty(), str_repeat('x', PageHistory::MAX_BYTES));
        $this->assertTrue($r['stored']);
    }

    public function testRetentionTrimsOldestAndIdsAreNeverReused(): void
    {
        $h = PageHistory::empty();
        for ($i = 1; $i <= PageHistory::RETENTION + 3; $i++) {
            $h = $this->rec($h, "v{$i}")['history'];
        }
        $this->assertCount(PageHistory::RETENTION, $h['items']);
        $this->assertSame(PageHistory::RETENTION + 3, $h['items'][0]['id']);
        $this->assertSame('v4', end($h['items'])['content']);
        $next = $this->rec($h, 'later');
        $this->assertSame(PageHistory::RETENTION + 4, $next['version_id']);
    }

    public function testSummariesOmitContentAndKeepOrder(): void
    {
        $h = $this->rec($this->rec(PageHistory::empty(), 'A')['history'], 'B')['history'];
        $s = PageHistory::summaries($h);
        $this->assertSame([2, 1], array_column($s, 'id'));
        foreach ($s as $row) {
            $this->assertArrayNotHasKey('content', $row);
            $this->assertSame(['id', 'saved_at', 'tool', 'actor', 'bytes', 'label'], array_keys($row));
        }
    }

    public function testFind(): void
    {
        $h = $this->rec(PageHistory::empty(), 'A')['history'];
        $this->assertSame('A', PageHistory::find($h, 1)['content']);
        $this->assertNull(PageHistory::find($h, 99));
    }

    /** @param mixed $raw */
    #[\PHPUnit\Framework\Attributes\DataProvider('garbage')]
    public function testNormalizeNeverThrowsAndReturnsAValidHistory(mixed $raw): void
    {
        $h = PageHistory::normalize($raw);
        $this->assertSame(['next', 'items'], array_keys($h));
        $this->assertGreaterThanOrEqual(1, $h['next']);
        $this->assertIsArray($h['items']);
    }

    public static function garbage(): array
    {
        return [[null], [''], ['text'], [42], [[]], [['items' => 'no']], [['items' => [1, 'x', null]]], [['items' => [['id' => 'a', 'content' => 1]]]]];
    }

    public function testNormalizeKeepsGoodItemsDropsBadAndRepairsNext(): void
    {
        $h = PageHistory::normalize(['next' => 1, 'items' => [
            ['id' => 5, 'content' => 'ok', 'sha256' => 'x', 'saved_at' => 't', 'tool' => 't', 'actor' => 1, 'bytes' => 2, 'label' => null],
            ['id' => 'bad', 'content' => 'no'],
        ]]);
        $this->assertCount(1, $h['items']);
        $this->assertSame(6, $h['next']);
    }

    public function testRecentTakesNewestPerPageAcrossPages(): void
    {
        $a = $this->rec(PageHistory::empty(), 'a1', '2026-10-01T01:00:00Z')['history'];
        $a = $this->rec($a, 'a2', '2026-10-01T05:00:00Z')['history'];
        $b = $this->rec(PageHistory::empty(), 'b1', '2026-10-01T03:00:00Z', 'edit_page_content')['history'];
        $rows = PageHistory::recent([10 => $a, 20 => $b, 30 => PageHistory::empty()], 5);
        $this->assertSame([10, 20], array_column($rows, 'page_id'));
        $this->assertSame(2, $rows[0]['version_id']);
        $this->assertSame('edit_page_content', $rows[1]['tool']);
        $this->assertCount(1, PageHistory::recent([10 => $a, 20 => $b], 1));
    }
}
```

- [ ] **Step 2: Run, expect FAIL** — `vendor/bin/phpunit tests/PageHistoryTest.php` → `Class "AiEditorDivi5\WP\PageHistory" not found` (the `require_once` fatals on the missing file).

- [ ] **Step 3: Implement** — `wp-plugin/src/PageHistory.php`

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pure rules for a page's AI-edit snapshot history. No WordPress calls.
 * A history is ['next' => int, 'items' => list<item>] (newest item first).
 */
final class PageHistory
{
    public const RETENTION = 10;
    public const MAX_BYTES = 524288; // 512 KB per snapshot

    /** @return array{next:int, items:list<array<string,mixed>>} */
    public static function empty(): array
    {
        return [ 'next' => 1, 'items' => [] ];
    }

    /** @return array{next:int, items:list<array<string,mixed>>} */
    public static function normalize( mixed $raw ): array
    {
        if ( ! is_array( $raw ) || ! isset( $raw['items'] ) || ! is_array( $raw['items'] ) ) {
            return self::empty();
        }
        $items = [];
        $max   = 0;
        foreach ( $raw['items'] as $item ) {
            if ( is_array( $item ) && isset( $item['id'], $item['content'] ) && is_int( $item['id'] ) && is_string( $item['content'] ) ) {
                $items[] = $item;
                $max     = max( $max, $item['id'] );
            }
        }
        $next = max( 1, (int) ( $raw['next'] ?? 1 ), $max + 1 );

        return [ 'next' => $next, 'items' => $items ];
    }

    /**
     * @param array{next:int, items:list<array<string,mixed>>} $history
     * @return array{history:array{next:int, items:list<array<string,mixed>>}, stored:bool, version_id:?int, reason:?string}
     */
    public static function record( array $history, string $content, string $tool, int $actor, string $savedAt, ?string $label = null ): array
    {
        $history = self::normalize( $history );
        $bytes   = strlen( $content );

        if ( $bytes > self::MAX_BYTES ) {
            return [ 'history' => $history, 'stored' => false, 'version_id' => null, 'reason' => 'too_large' ];
        }

        $sha = hash( 'sha256', $content );
        if ( $history['items'] !== [] && ( $history['items'][0]['sha256'] ?? null ) === $sha ) {
            return [ 'history' => $history, 'stored' => false, 'version_id' => (int) $history['items'][0]['id'], 'reason' => 'duplicate' ];
        }

        $id = $history['next'];
        array_unshift( $history['items'], [
            'id'       => $id,
            'saved_at' => $savedAt,
            'tool'     => $tool,
            'actor'    => $actor,
            'bytes'    => $bytes,
            'sha256'   => $sha,
            'label'    => $label,
            'content'  => $content,
        ] );
        $history['items'] = array_slice( $history['items'], 0, self::RETENTION );
        $history['next']  = $id + 1;

        return [ 'history' => $history, 'stored' => true, 'version_id' => $id, 'reason' => null ];
    }

    /** @return list<array{id:int, saved_at:mixed, tool:mixed, actor:mixed, bytes:mixed, label:mixed}> */
    public static function summaries( array $history ): array
    {
        $out = [];
        foreach ( self::normalize( $history )['items'] as $item ) {
            $out[] = [
                'id'       => (int) $item['id'],
                'saved_at' => $item['saved_at'] ?? null,
                'tool'     => $item['tool'] ?? null,
                'actor'    => $item['actor'] ?? null,
                'bytes'    => $item['bytes'] ?? null,
                'label'    => $item['label'] ?? null,
            ];
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function find( array $history, int $id ): ?array
    {
        foreach ( self::normalize( $history )['items'] as $item ) {
            if ( (int) $item['id'] === $id ) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @param array<int, array> $byPage page id => history
     * @return list<array{page_id:int, version_id:int, saved_at:mixed, tool:mixed}>
     */
    public static function recent( array $byPage, int $limit ): array
    {
        $rows = [];
        foreach ( $byPage as $pageId => $history ) {
            $newest = self::normalize( $history )['items'][0] ?? null;
            if ( $newest !== null ) {
                $rows[] = [
                    'page_id'    => (int) $pageId,
                    'version_id' => (int) $newest['id'],
                    'saved_at'   => $newest['saved_at'] ?? null,
                    'tool'       => $newest['tool'] ?? null,
                ];
            }
        }
        usort( $rows, static fn ( array $a, array $b ): int => strcmp( (string) $b['saved_at'], (string) $a['saved_at'] ) );

        return array_slice( $rows, 0, max( 0, $limit ) );
    }
}
```

- [ ] **Step 4: Run, expect PASS** — `vendor/bin/phpunit tests/PageHistoryTest.php` → `OK`.

- [ ] **Step 5: Full suite and commit** — `make test` exit 0, then:

```bash
git add wp-plugin/src/PageHistory.php tests/PageHistoryTest.php
git commit -m "feat(history): PageHistory pure snapshot rules (retention, cap, dedup, ids)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `HistoryStore` + `HistoryService` (+ WordPress shims)

**Files:**
- Create: `wp-plugin/src/HistoryStore.php`
- Create: `wp-plugin/src/HistoryService.php`
- Modify: `wp-plugin/src/autoload.php` (require the three history classes before `McpHandler`)
- Modify: `tests/bootstrap.php` (shims — each wrapped in `function_exists`/`class_exists`)
- Create: `tests/HistoryServiceTest.php`

**Interfaces:**
- Consumes: `PageHistory` (Task 1).
- Produces `HistoryStore` (`final`, all static): `META_KEY = '_aied_history'`; `load(int $pageId): array` (normalized, never throws); `save(int $pageId, array $history): void`; `deleteAll(): void` (`delete_post_meta_by_key`).
- Produces `HistoryService` (`final`, all static); every method returns plain arrays, never `WP_Error`:
  - `write(int $pageId, string $newContent, string $tool): array` → `['ok'=>true,'snapshot'=>array{stored:bool,version_id:?int,reason:?string}]` or `['ok'=>false,'error'=>'update_failed','message'=>string,'snapshot'=>array]`.
  - `listFor(int $pageId): array` → `PageHistory::summaries` result.
  - `entry(int $pageId, int $versionId): ?array` → full item or null.
  - `restore(int $pageId, int $versionId): array` → `['ok'=>true,'restored_version'=>int,'snapshot'=>array,'validator'=>['valid'=>bool,'violations'=>list]]` or `['ok'=>false,'error'=>'version_not_found'|'update_failed','message'=>string]`.
  - `recent(int $limit = 5): array` — added in Task 5.
  - Tool name constants: `TOOL_RESTORE = 'restore'`.

- [ ] **Step 1: Add the shims** to `tests/bootstrap.php` (append before the final line / after the existing shims; each guarded):

```php
// ---- Post / post-meta shims for history tests -------------------------------------------
$GLOBALS['__wp_posts'] = [];      // id => object{ID, post_type, post_content}
$GLOBALS['__wp_postmeta'] = [];   // id => [key => value]
$GLOBALS['__wp_update_fail'] = false;

if ( ! function_exists( 'wp_slash' ) ) {
    function wp_slash( $value ) {
        if ( is_array( $value ) ) {
            return array_map( 'wp_slash', $value );
        }
        return is_string( $value ) ? addslashes( $value ) : $value;
    }
}
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        if ( is_array( $value ) ) {
            return array_map( 'wp_unslash', $value );
        }
        return is_string( $value ) ? stripslashes( $value ) : $value;
    }
}
if ( ! function_exists( 'get_post' ) ) {
    function get_post( $id ) { return $GLOBALS['__wp_posts'][ (int) $id ] ?? null; }
}
if ( ! function_exists( 'wp_update_post' ) ) {
    // Mirrors core: the incoming array is treated as slashed and unslashed once.
    function wp_update_post( $arr, $wp_error = false ) {
        if ( $GLOBALS['__wp_update_fail'] ) {
            return new WP_Error( 'db_update_error', 'simulated failure' );
        }
        $arr = wp_unslash( $arr );
        $id  = (int) $arr['ID'];
        if ( ! isset( $GLOBALS['__wp_posts'][ $id ] ) ) {
            return new WP_Error( 'invalid_post', 'Invalid post ID.' );
        }
        $GLOBALS['__wp_posts'][ $id ]->post_content = (string) $arr['post_content'];
        return $id;
    }
}
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__wp_postmeta'][ (int) $id ][ $key ] ?? ''; }
}
if ( ! function_exists( 'update_post_meta' ) ) {
    // Mirrors core: the value is unslashed on write.
    function update_post_meta( $id, $key, $value ) { $GLOBALS['__wp_postmeta'][ (int) $id ][ $key ] = wp_unslash( $value ); return true; }
}
if ( ! function_exists( 'delete_post_meta_by_key' ) ) {
    function delete_post_meta_by_key( $key ) { foreach ( $GLOBALS['__wp_postmeta'] as $id => $m ) { unset( $GLOBALS['__wp_postmeta'][ $id ][ $key ] ); } return true; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() { return 7; }
}
```

Also extend the existing `WP_Error` shim so it can carry a message: change `class WP_Error { public function __construct( public string $code = 'http_request_failed' ) {} }` to
`class WP_Error { public function __construct( public string $code = 'http_request_failed', public string $message = '' ) {} public function get_error_message(): string { return $this->message; } }`.

- [ ] **Step 2: Write the failing test** — `tests/HistoryServiceTest.php`

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\PageHistory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';

class HistoryServiceTest extends TestCase
{
    private const VALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    protected function setUp(): void
    {
        $GLOBALS['__wp_posts']       = [ 5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_content' => 'ORIGINAL' ] ];
        $GLOBALS['__wp_postmeta']    = [];
        $GLOBALS['__wp_update_fail'] = false;
    }

    public function testWriteSnapshotsPreviousContentThenSavesNew(): void
    {
        $r = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertTrue( $r['snapshot']['stored'] );
        $this->assertSame( 'NEW', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 'ORIGINAL', HistoryService::entry( 5, $r['snapshot']['version_id'] )['content'] );
    }

    public function testBackslashesAndEscapedHtmlRoundTripByteForByte(): void
    {
        $tricky = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Hi \"there\" \\\\ back</p>"}}}} /-->';
        $GLOBALS['__wp_posts'][5]->post_content = $tricky;
        $w = HistoryService::write( 5, 'SOMETHING ELSE', 'edit_page_content' );
        $this->assertSame( $tricky, HistoryService::entry( 5, $w['snapshot']['version_id'] )['content'] );

        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( $tricky, $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testOversizedPageIsStillWrittenAndResultSaysNoSnapshot(): void
    {
        $GLOBALS['__wp_posts'][5]->post_content = str_repeat( 'x', PageHistory::MAX_BYTES + 1 );
        $r = HistoryService::write( 5, 'SMALL', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertFalse( $r['snapshot']['stored'] );
        $this->assertSame( 'too_large', $r['snapshot']['reason'] );
        $this->assertNull( $r['snapshot']['version_id'] );
        $this->assertSame( 'SMALL', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testFailedSaveReportsErrorAndKeepsContent(): void
    {
        $GLOBALS['__wp_update_fail'] = true;
        $r = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'update_failed', $r['error'] );
        $this->assertSame( 'simulated failure', $r['message'] );
        $this->assertSame( 'ORIGINAL', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testRestoreSnapshotsCurrentContentFirstSoRestoreIsUndoable(): void
    {
        $w = HistoryService::write( 5, 'AI VERSION', 'update_page_layout' );          // snapshot #1 = ORIGINAL
        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );              // back to ORIGINAL
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'ORIGINAL', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 1, $r['restored_version'] );
        $this->assertTrue( $r['snapshot']['stored'] );
        $this->assertSame( 'AI VERSION', HistoryService::entry( 5, $r['snapshot']['version_id'] )['content'] );
    }

    public function testRestoreOfContentTheValidatorRejectsStillSucceedsAndReportsVerdict(): void
    {
        $GLOBALS['__wp_posts'][5]->post_content = '<!-- wp:divi/not-a-module /-->';
        $w = HistoryService::write( 5, self::VALID, 'update_page_layout' );
        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( '<!-- wp:divi/not-a-module /-->', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertFalse( $r['validator']['valid'] );
        $this->assertNotEmpty( $r['validator']['violations'] );
    }

    public function testRestoreOfValidContentReportsValid(): void
    {
        $GLOBALS['__wp_posts'][5]->post_content = self::VALID;
        $w = HistoryService::write( 5, 'junk', 'update_page_layout' );
        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );
        $this->assertTrue( $r['validator']['valid'] );
        $this->assertSame( [], $r['validator']['violations'] );
    }

    public function testUnknownVersionIsACleanErrorAndChangesNothing(): void
    {
        $r = HistoryService::restore( 5, 99 );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'version_not_found', $r['error'] );
        $this->assertSame( 'ORIGINAL', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( [], HistoryService::listFor( 5 ) );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'corruptMeta' )]
    public function testCorruptHistoryMetaNeverBlocksAWrite( mixed $junk ): void
    {
        $GLOBALS['__wp_postmeta'][5][ HistoryStore::META_KEY ] = $junk;
        $r = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'NEW', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertTrue( $r['snapshot']['stored'] );
    }

    public static function corruptMeta(): array
    {
        return [ [ 'not json at all' ], [ '{"items":"nope"}' ], [ '[1,2,3]' ], [ 12345 ], [ '' ] ];
    }

    public function testDuplicateWriteDoesNotAddASnapshot(): void
    {
        HistoryService::write( 5, 'A', 'update_page_layout' );              // snapshot ORIGINAL
        $r = HistoryService::write( 5, 'A', 'update_page_layout' );          // pre-write content is now 'A'
        $this->assertTrue( $r['ok'] );
        $this->assertTrue( $r['snapshot']['stored'] );                        // 'A' is new vs ORIGINAL
        $r2 = HistoryService::write( 5, 'A', 'update_page_layout' );          // pre-write 'A' == newest snapshot 'A'
        $this->assertFalse( $r2['snapshot']['stored'] );
        $this->assertSame( 'duplicate', $r2['snapshot']['reason'] );
        $this->assertNotNull( $r2['snapshot']['version_id'] );
    }

    public function testListForReturnsSummariesWithoutContent(): void
    {
        HistoryService::write( 5, 'A', 'update_page_layout' );
        $list = HistoryService::listFor( 5 );
        $this->assertCount( 1, $list );
        $this->assertArrayNotHasKey( 'content', $list[0] );
        $this->assertSame( 'update_page_layout', $list[0]['tool'] );
    }
}
```

- [ ] **Step 3: Run, expect FAIL** — `vendor/bin/phpunit tests/HistoryServiceTest.php` → fatal: failed opening `HistoryStore.php`.

- [ ] **Step 4: Implement**

`wp-plugin/src/HistoryStore.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The only code that touches the _aied_history post meta. */
final class HistoryStore
{
    public const META_KEY = '_aied_history';

    /** @return array{next:int, items:list<array<string,mixed>>} */
    public static function load( int $pageId ): array
    {
        $raw     = get_post_meta( $pageId, self::META_KEY, true );
        $decoded = ( is_string( $raw ) && '' !== $raw ) ? json_decode( $raw, true ) : null;

        return PageHistory::normalize( $decoded );
    }

    public static function save( int $pageId, array $history ): void
    {
        // wp_slash: update_post_meta runs wp_unslash on the value; without it the
        // backslashes in the JSON (and in escaped Divi HTML) would be stripped.
        update_post_meta( $pageId, self::META_KEY, wp_slash( (string) wp_json_encode( $history ) ) );
    }

    public static function deleteAll(): void
    {
        delete_post_meta_by_key( self::META_KEY );
    }
}
```

`wp-plugin/src/HistoryService.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Validator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Single entry point for page history, shared by the MCP and REST transports.
 * Returns plain arrays (never WP_Error) so each transport maps them its own way.
 */
final class HistoryService
{
    public const TOOL_RESTORE = 'restore';

    /** @return array{ok:bool, snapshot:array, error?:string, message?:string} */
    public static function write( int $pageId, string $newContent, string $tool ): array
    {
        $post     = get_post( $pageId );
        $snapshot = self::snapshot( $pageId, $post ? (string) $post->post_content : '', $tool );

        // wp_slash: wp_update_post runs wp_unslash internally, which would strip
        // backslashes from escaped HTML (e.g. <) and corrupt the content.
        $updated = wp_update_post( wp_slash( [ 'ID' => $pageId, 'post_content' => $newContent ] ), true );
        if ( is_wp_error( $updated ) ) {
            return [ 'ok' => false, 'error' => 'update_failed', 'message' => $updated->get_error_message(), 'snapshot' => $snapshot ];
        }

        return [ 'ok' => true, 'snapshot' => $snapshot ];
    }

    /** @return list<array<string,mixed>> */
    public static function listFor( int $pageId ): array
    {
        return PageHistory::summaries( HistoryStore::load( $pageId ) );
    }

    /** @return array<string,mixed>|null */
    public static function entry( int $pageId, int $versionId ): ?array
    {
        return PageHistory::find( HistoryStore::load( $pageId ), $versionId );
    }

    /** @return array<string,mixed> */
    public static function restore( int $pageId, int $versionId ): array
    {
        $target = self::entry( $pageId, $versionId );
        if ( null === $target ) {
            return [ 'ok' => false, 'error' => 'version_not_found', 'message' => "Version {$versionId} not found for page {$pageId}." ];
        }

        $post     = get_post( $pageId );
        $snapshot = self::snapshot( $pageId, $post ? (string) $post->post_content : '', self::TOOL_RESTORE, 'before restore of #' . $versionId );

        $updated = wp_update_post( wp_slash( [ 'ID' => $pageId, 'post_content' => (string) $target['content'] ] ), true );
        if ( is_wp_error( $updated ) ) {
            return [ 'ok' => false, 'error' => 'update_failed', 'message' => $updated->get_error_message() ];
        }

        // Restore is NOT a validated write: it returns the person's own earlier content.
        // The validator's verdict is reported as information only.
        $verdict = ( new Validator() )->validateContent( (string) $target['content'] );

        return [
            'ok'               => true,
            'restored_version' => $versionId,
            'snapshot'         => $snapshot,
            'validator'        => [
                'valid'      => $verdict->isValid(),
                'violations' => array_map( static fn ( $v ) => $v->toArray(), $verdict->violations() ),
            ],
        ];
    }

    /** @return array{stored:bool, version_id:?int, reason:?string} */
    private static function snapshot( int $pageId, string $before, string $tool, ?string $label = null ): array
    {
        $result = PageHistory::record(
            HistoryStore::load( $pageId ),
            $before,
            $tool,
            (int) get_current_user_id(),
            gmdate( 'Y-m-d\TH:i:s\Z' ),
            $label
        );
        if ( $result['stored'] ) {
            HistoryStore::save( $pageId, $result['history'] );
        }

        return [ 'stored' => $result['stored'], 'version_id' => $result['version_id'], 'reason' => $result['reason'] ];
    }
}
```

In `wp-plugin/src/autoload.php`, add (after the `PageEditor.php` line, before `RestController.php`):

```php
require_once __DIR__ . '/PageHistory.php';
require_once __DIR__ . '/HistoryStore.php';
require_once __DIR__ . '/HistoryService.php';
```

Note: in `tests/HistoryServiceTest.php` the validator (`Divi5Validator\Validator`) is autoloaded by Composer; the shim `WP_Error` must exist before `is_wp_error` is used — it does (bootstrap).

- [ ] **Step 5: Run, expect PASS** — `vendor/bin/phpunit tests/HistoryServiceTest.php tests/PageHistoryTest.php` → `OK`. If the oversize test is slow, that is expected (512 KB strings only).

- [ ] **Step 6: Full suite and commit** — `make test` exit 0, then:

```bash
git add wp-plugin/src tests
git commit -m "feat(history): HistoryStore + HistoryService (snapshot-before-write, restore) + WP shims

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Route every AI write through `HistoryService::write`

**Files:**
- Modify: `wp-plugin/src/McpHandler.php` (`toolUpdate`, `toolEditContent`)
- Modify: `wp-plugin/src/RestController.php` (`update_page`, `edit_page`)

**Interfaces:**
- Consumes: `HistoryService::write(int $pageId, string $newContent, string $tool): array` (Task 2). Tool names to pass: `'update_page_layout'` (MCP update), `'edit_page_content'` (MCP edit), `'update_page'` (REST update), `'edit_page'` (REST edit) — the same strings the code already passes to `UsageTracker::log`.
- Produces: the saved response of all four paths gains a `history` object `{stored, version_id, reason}` (additive).

- [ ] **Step 1: MCP `toolUpdate`** — replace

```php
        // wp_slash: wp_update_post runs wp_unslash internally, which would
        // otherwise strip backslashes from escaped HTML (e.g. <) and corrupt content.
        $updated = wp_update_post(wp_slash(['ID' => $pageId, 'post_content' => $content]), true);

        if (is_wp_error($updated)) {
            UsageTracker::log('update_layout', $pageId, 'error');
            return $this->rpcError($id, -32603, $updated->get_error_message());
        }
```

with

```php
        // Snapshots the previous content, then saves (wp_slash is applied inside HistoryService).
        $write = HistoryService::write($pageId, $content, 'update_page_layout');

        if (!$write['ok']) {
            UsageTracker::log('update_layout', $pageId, 'error');
            return $this->rpcError($id, -32603, (string) $write['message']);
        }
```

and in its success JSON add `'history' => $write['snapshot'],` next to `'saved' => true`.

- [ ] **Step 2: MCP `toolEditContent`** — replace the block

```php
        // wp_slash: wp_update_post runs wp_unslash internally (see toolUpdate).
        $updated = wp_update_post(wp_slash(['ID' => $pageId, 'post_content' => $edit['content']]), true);
        if (is_wp_error($updated)) {
            UsageTracker::log('edit_content', $pageId, 'error');
            return $this->rpcError($id, -32603, $updated->get_error_message());
        }
```

with

```php
        $write = HistoryService::write($pageId, $edit['content'], 'edit_page_content');
        if (!$write['ok']) {
            UsageTracker::log('edit_content', $pageId, 'error');
            return $this->rpcError($id, -32603, (string) $write['message']);
        }
```

and add `'history' => $write['snapshot'],` to its success JSON (next to `'replaced'`).

- [ ] **Step 3: REST `update_page`** — replace

```php
        // Validation passed — safe to save. wp_slash because wp_update_post runs
        // wp_unslash internally and would otherwise strip backslashes from escaped HTML.
        $updated = wp_update_post(wp_slash([
            'ID'           => $id,
            'post_content' => $body['post_content'],
        ]), true);

        if (is_wp_error($updated)) {
            return new WP_Error('update_failed', $updated->get_error_message(), ['status' => 500]);
        }
```

with

```php
        // Validation passed — snapshot the previous content, then save (wp_slash inside HistoryService).
        $write = HistoryService::write($id, $body['post_content'], 'update_page');

        if (!$write['ok']) {
            return new WP_Error('update_failed', (string) $write['message'], ['status' => 500]);
        }
```

and add `'history' => $write['snapshot'],` to the success `WP_REST_Response` array.

- [ ] **Step 4: REST `edit_page`** — replace

```php
        // wp_slash because wp_update_post runs wp_unslash internally (see update_page).
        $updated = wp_update_post(wp_slash(['ID' => $id, 'post_content' => $edit['content']]), true);
        if (is_wp_error($updated)) {
            return new WP_Error('update_failed', $updated->get_error_message(), ['status' => 500]);
        }
```

with

```php
        $write = HistoryService::write($id, $edit['content'], 'edit_page');
        if (!$write['ok']) {
            return new WP_Error('update_failed', (string) $write['message'], ['status' => 500]);
        }
```

and add `'history' => $write['snapshot'],` to its success response array (next to `'replaced'`).

Both files already `use` the plugin namespace `AiEditorDivi5\WP`, so `HistoryService` resolves; `create_page` is intentionally unchanged (a new page has no prior state).

- [ ] **Step 5: Lint + suite** — `php -l wp-plugin/src/McpHandler.php && php -l wp-plugin/src/RestController.php`; `make test` exit 0.

- [ ] **Step 6: Live check in Docker** (the plugin directory is mounted; no rebuild). Run exactly:

```bash
docker compose exec -T wpcli wp eval '
wp_set_current_user(1);
$v="\"builderVersion\":\"5.14.0\"";
$col="<!-- wp:divi/column {\"module\":{\"advanced\":{\"type\":{\"desktop\":{\"value\":\"4_4\"}}}},$v} -->";
$mk=fn($t)=>"<!-- wp:divi/placeholder --><!-- wp:divi/section {".$v."} --><!-- wp:divi/row {".$v."} -->$col<!-- wp:divi/heading {\"title\":{\"innerContent\":{\"desktop\":{\"value\":\"$t\"}}},$v} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->";
$id=wp_insert_post(["post_type"=>"page","post_status"=>"draft","post_title"=>"aied-history-test","post_content"=>wp_slash($mk("One"))]);
update_post_meta($id,"_et_pb_use_divi_5","on");
$call=function($m,$p,$b)use($id){$r=new WP_REST_Request($m,"/ai-editor-divi5/v1".$p);$r->set_header("content-type","application/json");$r->set_body(json_encode($b));$x=rest_do_request($r);return [$x->get_status(),$x->get_data()];};
[$s1,$d1]=$call("PUT","/pages/$id",["post_content"=>$mk("Two")]);
echo "update: $s1 history=",json_encode($d1["history"]??null),"\n";
[$s2,$d2]=$call("POST","/pages/$id/edit",["find"=>"Two","replace"=>"Three"]);
echo "edit: $s2 history=",json_encode($d2["history"]??null),"\n";
echo "snapshots in meta: ",count(json_decode(get_post_meta($id,"_aied_history",true),true)["items"]),"\n";
wp_delete_post($id,true);'
```

Expected: `update: 200 history={"stored":true,"version_id":1,"reason":null}`, `edit: 200 history={"stored":true,"version_id":2,...}`, `snapshots in meta: 2`. (If `edit` reports `422`, the heading markup in the helper is not accepted by the validator — adjust `$mk` to a shape from `fixtures/valid` and re-run; the point of the check is the `history` field and the meta count.)

- [ ] **Step 7: Commit**

```bash
git add wp-plugin/src/McpHandler.php wp-plugin/src/RestController.php
git commit -m "feat(history): snapshot previous content on every AI write (MCP + REST)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: The three history tools in MCP, REST and OpenAPI (lockstep)

**Files:**
- Modify: `wp-plugin/src/McpHandler.php` (tool definitions, `match` arms, three `tool*` methods)
- Modify: `wp-plugin/src/RestController.php` (three routes, three handlers)
- Modify: `wp-plugin/src/OpenApiSpec.php` (schemas + three paths)
- Create: `tests/HistoryLockstepTest.php`

**Interfaces:**
- Consumes: `HistoryService::listFor / entry / restore` (Task 2).
- Produces (names that must match across all three transports):
  - MCP tools: `list_page_history{page_id}`, `get_page_history_entry{page_id, version_id}`, `restore_page_version{page_id, version_id}`.
  - REST: `GET /pages/{id}/history`, `GET /pages/{id}/history/{version_id}`, `POST /pages/{id}/restore` (body `{"version_id": int}`).
  - OpenAPI operationIds: `listPageHistory`, `getPageHistoryEntry`, `restorePageVersion`; component schema `HistoryEntry`.
  - `UsageTracker::log` endpoint names: `list_history`, `get_history`, `restore_version`.

- [ ] **Step 1: Write the failing lockstep test** — `tests/HistoryLockstepTest.php`

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OpenApiSpec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';

/** The history tools must exist, under matching names, in all three transports. */
class HistoryLockstepTest extends TestCase
{
    private function src(string $file): string
    {
        return (string) file_get_contents(__DIR__ . '/../wp-plugin/src/' . $file);
    }

    public function testMcpExposesTheThreeToolsInListAndDispatch(): void
    {
        $mcp = $this->src('McpHandler.php');
        foreach (['list_page_history', 'get_page_history_entry', 'restore_page_version'] as $tool) {
            $this->assertStringContainsString("'name'        => '{$tool}'", $mcp, "$tool missing from tools/list");
            $this->assertStringContainsString("'{$tool}'", $mcp);
            $this->assertMatchesRegularExpression("/'{$tool}'\s*=>\s*\\\$this->tool/", $mcp, "$tool missing from the dispatch match");
        }
    }

    public function testRestExposesTheThreeRoutes(): void
    {
        $rest = $this->src('RestController.php');
        $this->assertStringContainsString("'/pages/(?P<id>\\d+)/history'", $rest);
        $this->assertStringContainsString("'/pages/(?P<id>\\d+)/history/(?P<version_id>\\d+)'", $rest);
        $this->assertStringContainsString("'/pages/(?P<id>\\d+)/restore'", $rest);
    }

    public function testOpenApiDeclaresTheThreeOperations(): void
    {
        $spec = OpenApiSpec::spec('https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9');
        $ops  = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $op) {
                $ops[$op['operationId'] ?? ''] = [$method, $path];
            }
        }
        $this->assertSame(['get', '/pages/{id}/history'], $ops['listPageHistory'] ?? null);
        $this->assertSame(['get', '/pages/{id}/history/{versionId}'], $ops['getPageHistoryEntry'] ?? null);
        $this->assertSame(['post', '/pages/{id}/restore'], $ops['restorePageVersion'] ?? null);
        $this->assertArrayHasKey('HistoryEntry', $spec['components']['schemas']);
    }

    public function testHistoryToolsAreNotPremiumGated(): void
    {
        $mcp = $this->src('McpHandler.php');
        foreach (['toolListHistory', 'toolGetHistoryEntry', 'toolRestoreVersion'] as $m) {
            $start = strpos($mcp, "function {$m}(");
            $this->assertNotFalse($start, "$m not found");
            $end  = strpos($mcp, "\n    private function", (int) $start + 10);
            $body = substr($mcp, (int) $start, ($end === false ? strlen($mcp) : $end) - (int) $start);
            $this->assertStringNotContainsString('isPremium', $body, "$m must be free tier");
        }
    }
}
```

(The existing `OpenApiSpecTest` already enforces ≤300-char descriptions and no bare object schemas on the new operations.)

- [ ] **Step 2: Run, expect FAIL** — `vendor/bin/phpunit tests/HistoryLockstepTest.php` → assertions fail (tools absent).

- [ ] **Step 3: MCP** — in `onToolsList()`'s array (after the `edit_page_content` entry, before `create_page`) add:

```php
            [
                'name'        => 'list_page_history',
                'description' => 'List the saved previous versions of a page (newest first). Every AI save snapshots the page\'s prior content, so any AI edit can be undone. Returns id, saved_at, tool, actor, bytes, label for each version (no content). Use restore_page_version to undo.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id' => ['type' => 'integer', 'description' => 'WordPress page ID'],
                    ],
                    'required' => ['page_id'],
                ],
            ],
            [
                'name'        => 'get_page_history_entry',
                'description' => 'Get the full saved content of one previous version of a page (from list_page_history), e.g. to compare it with the current layout before restoring.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id'    => ['type' => 'integer', 'description' => 'WordPress page ID'],
                        'version_id' => ['type' => 'integer', 'description' => 'Version id from list_page_history'],
                    ],
                    'required' => ['page_id', 'version_id'],
                ],
            ],
            [
                'name'        => 'restore_page_version',
                'description' => 'Undo: restore a page to a previous saved version. The current content is snapshotted first, so the restore itself can be undone. Restore does not block on validation (it returns the person\'s own earlier content); the result reports whether that content passes the validator.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id'    => ['type' => 'integer', 'description' => 'WordPress page ID'],
                        'version_id' => ['type' => 'integer', 'description' => 'Version id from list_page_history'],
                    ],
                    'required' => ['page_id', 'version_id'],
                ],
            ],
```

In `onToolsCall`'s `match`, before `'create_page'`, add:

```php
            'list_page_history'      => $this->toolListHistory($id, $arguments),
            'get_page_history_entry' => $this->toolGetHistoryEntry($id, $arguments),
            'restore_page_version'   => $this->toolRestoreVersion($id, $arguments),
```

Add the three methods (before `toolCreatePage`):

```php
    /** Resolves a page the caller may edit, or returns an rpcError response. */
    private function historyPage(mixed $id, array $args): \WP_Post|WP_REST_Response
    {
        $pageId = (int) ($args['page_id'] ?? 0);
        $post   = $pageId ? get_post($pageId) : null;

        if (!$post || $post->post_type !== 'page') {
            return $this->rpcError($id, -32602, "Page {$pageId} not found.");
        }
        if (!current_user_can('edit_post', $pageId)) {
            return $this->rpcError($id, -32602, "You do not have permission to access page {$pageId}.");
        }

        return $post;
    }

    private function toolListHistory(mixed $id, array $args): WP_REST_Response
    {
        $post = $this->historyPage($id, $args);
        if ($post instanceof WP_REST_Response) {
            return $post;
        }
        UsageTracker::log('list_history', $post->ID, 'valid');
        $versions = HistoryService::listFor($post->ID);

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(['page_id' => $post->ID, 'versions' => $versions, 'count' => count($versions)])]],
        ]);
    }

    private function toolGetHistoryEntry(mixed $id, array $args): WP_REST_Response
    {
        $post = $this->historyPage($id, $args);
        if ($post instanceof WP_REST_Response) {
            return $post;
        }
        $versionId = (int) ($args['version_id'] ?? 0);
        $entry     = HistoryService::entry($post->ID, $versionId);
        if ($entry === null) {
            UsageTracker::log('get_history', $post->ID, 'error');
            return $this->rpcError($id, -32602, "Version {$versionId} not found for page {$post->ID}.");
        }
        UsageTracker::log('get_history', $post->ID, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(['page_id' => $post->ID, 'version' => array_diff_key($entry, ['content' => 1]), 'post_content' => $entry['content']])]],
        ]);
    }

    private function toolRestoreVersion(mixed $id, array $args): WP_REST_Response
    {
        $post = $this->historyPage($id, $args);
        if ($post instanceof WP_REST_Response) {
            return $post;
        }
        $versionId = (int) ($args['version_id'] ?? 0);
        $result    = HistoryService::restore($post->ID, $versionId);
        if (!$result['ok']) {
            UsageTracker::log('restore_version', $post->ID, 'error');
            return $this->rpcError($id, $result['error'] === 'version_not_found' ? -32602 : -32603, (string) $result['message']);
        }
        UsageTracker::log('restore_version', $post->ID, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode([
                'restored'         => true,
                'restored_version' => $result['restored_version'],
                'history'          => $result['snapshot'],
                'validator'        => $result['validator'],
                'page'             => ['id' => $post->ID, 'title' => get_the_title($post->ID)],
            ])]],
        ]);
    }
```

- [ ] **Step 4: REST** — in `register_routes()` after the `/pages/{id}/edit` route add:

```php
        // GET /pages/{id}/history — saved previous versions (no content)
        register_rest_route(self::NS, '/pages/(?P<id>\d+)/history', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'history_list'],
            'permission_callback' => [$this, 'require_edit_posts'],
            'args'                => ['id' => ['validate_callback' => fn($v) => is_numeric($v)]],
        ]);

        // GET /pages/{id}/history/{version_id} — one version's full content
        register_rest_route(self::NS, '/pages/(?P<id>\d+)/history/(?P<version_id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'history_entry'],
            'permission_callback' => [$this, 'require_edit_posts'],
            'args'                => [
                'id'         => ['validate_callback' => fn($v) => is_numeric($v)],
                'version_id' => ['validate_callback' => fn($v) => is_numeric($v)],
            ],
        ]);

        // POST /pages/{id}/restore — undo: restore a saved version
        register_rest_route(self::NS, '/pages/(?P<id>\d+)/restore', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'history_restore'],
            'permission_callback' => [$this, 'require_edit_posts'],
            'args'                => ['id' => ['validate_callback' => fn($v) => is_numeric($v)]],
        ]);
```

and the handlers (next to `edit_page`):

```php
    /** @return \WP_Post|WP_Error */
    private function history_page(WP_REST_Request $request): \WP_Post|WP_Error
    {
        $id   = (int) $request->get_param('id');
        $post = get_post($id);

        if (!$post || $post->post_type !== 'page') {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }
        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('forbidden', "You do not have permission to access page $id.", ['status' => 403]);
        }

        return $post;
    }

    public function history_list(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = $this->history_page($request);
        if (is_wp_error($post)) {
            return $post;
        }
        UsageTracker::log('list_history', $post->ID, 'valid');
        $versions = HistoryService::listFor($post->ID);

        return new WP_REST_Response(['page_id' => $post->ID, 'versions' => $versions, 'count' => count($versions)], 200);
    }

    public function history_entry(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = $this->history_page($request);
        if (is_wp_error($post)) {
            return $post;
        }
        $versionId = (int) $request->get_param('version_id');
        $entry     = HistoryService::entry($post->ID, $versionId);
        if ($entry === null) {
            UsageTracker::log('get_history', $post->ID, 'error');
            return new WP_Error('not_found', "Version $versionId not found for page {$post->ID}.", ['status' => 404]);
        }
        UsageTracker::log('get_history', $post->ID, 'valid');

        return new WP_REST_Response(['page_id' => $post->ID, 'version' => array_diff_key($entry, ['content' => 1]), 'post_content' => $entry['content']], 200);
    }

    public function history_restore(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = $this->history_page($request);
        if (is_wp_error($post)) {
            return $post;
        }
        $body = $request->get_json_params();
        if (!isset($body['version_id']) || !is_numeric($body['version_id'])) {
            return new WP_Error('missing_field', 'Request body must include an integer "version_id".', ['status' => 400]);
        }
        $result = HistoryService::restore($post->ID, (int) $body['version_id']);
        if (!$result['ok']) {
            UsageTracker::log('restore_version', $post->ID, 'error');
            return new WP_Error($result['error'], (string) $result['message'], ['status' => $result['error'] === 'version_not_found' ? 404 : 500]);
        }
        UsageTracker::log('restore_version', $post->ID, 'valid');

        return new WP_REST_Response([
            'restored'         => true,
            'restored_version' => $result['restored_version'],
            'history'          => $result['snapshot'],
            'validator'        => $result['validator'],
        ], 200);
    }
```

- [ ] **Step 5: OpenAPI** — in `OpenApiSpec::spec()`: add to `components.schemas`:

```php
                    'HistoryEntry' => [
                        'type'       => 'object',
                        'properties' => [
                            'id'       => ['type' => 'integer', 'description' => 'Version id (use with restore)'],
                            'saved_at' => ['type' => 'string',  'description' => 'UTC time the snapshot was taken'],
                            'tool'     => ['type' => 'string',  'description' => 'Which action replaced this version'],
                            'actor'    => ['type' => 'integer', 'description' => 'WordPress user id'],
                            'bytes'    => ['type' => 'integer'],
                            'label'    => ['type' => 'string'],
                        ],
                    ],
```

and add three entries to `paths` (after `'/pages/{id}/edit'`). Use `self::idParam()` for `{id}`; for `{versionId}` define a private static `versionParam()` copying the exact shape of `idParam()` but with `name` = `versionId`... **NOTE:** the REST route's regex group is `version_id`, the OpenAPI path template is `{versionId}`; ChatGPT builds the URL from the template, WordPress matches by position — both resolve to the same path segment, so this is fine.

```php
                '/pages/{id}/history' => [
                    'get' => [
                        'operationId' => 'listPageHistory',
                        'summary'     => 'List a page\'s saved previous versions',
                        'description' => 'Every AI save snapshots the page\'s prior content. Returns the saved versions newest first (id, time, tool, size) so an edit can be undone with restorePageVersion.',
                        'parameters'  => [self::idParam()],
                        'responses'   => [
                            '200' => ['description' => 'Versions', 'content' => ['application/json' => ['schema' => [
                                'type'       => 'object',
                                'properties' => [
                                    'page_id'  => ['type' => 'integer'],
                                    'count'    => ['type' => 'integer'],
                                    'versions' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/HistoryEntry']],
                                ],
                            ]]]],
                            '404' => ['description' => 'Page not found'],
                        ],
                    ],
                ],
                '/pages/{id}/history/{versionId}' => [
                    'get' => [
                        'operationId' => 'getPageHistoryEntry',
                        'summary'     => 'Get one saved version\'s content',
                        'description' => 'Returns the full saved content of one previous version (from listPageHistory), e.g. to compare it with the current layout before restoring.',
                        'parameters'  => [self::idParam(), self::versionParam()],
                        'responses'   => [
                            '200' => ['description' => 'Version content', 'content' => ['application/json' => ['schema' => [
                                'type'       => 'object',
                                'properties' => [
                                    'page_id'      => ['type' => 'integer'],
                                    'version'      => ['$ref' => '#/components/schemas/HistoryEntry'],
                                    'post_content' => ['type' => 'string', 'description' => 'Divi 5 Gutenberg block HTML'],
                                ],
                            ]]]],
                            '404' => ['description' => 'Page or version not found'],
                        ],
                    ],
                ],
                '/pages/{id}/restore' => [
                    'post' => [
                        'operationId' => 'restorePageVersion',
                        'summary'     => 'Undo: restore a saved version',
                        'description' => 'Restores a page to a previous saved version. The current content is snapshotted first, so a restore can itself be undone. Not blocked by validation (it is the owner\'s own earlier content); the response reports whether it passes the validator.',
                        'parameters'  => [self::idParam()],
                        'requestBody' => [
                            'required' => true,
                            'content'  => ['application/json' => ['schema' => [
                                'type'       => 'object',
                                'required'   => ['version_id'],
                                'properties' => ['version_id' => ['type' => 'integer', 'description' => 'Version id from listPageHistory']],
                            ]]],
                        ],
                        'responses'   => [
                            '200' => ['description' => 'Restored', 'content' => ['application/json' => ['schema' => [
                                'type'       => 'object',
                                'properties' => [
                                    'restored'         => ['type' => 'boolean'],
                                    'restored_version' => ['type' => 'integer'],
                                    'validator'        => ['$ref' => '#/components/schemas/ValidationResult'],
                                ],
                            ]]]],
                            '400' => ['description' => 'Missing version_id'],
                            '404' => ['description' => 'Page or version not found'],
                        ],
                    ],
                ],
```

Add the helper next to `idParam()` (read `idParam()` first and mirror its exact array shape):

```php
    private static function versionParam(): array
    {
        $p         = self::idParam();
        $p['name'] = 'versionId';
        $p['description'] = 'Version id from listPageHistory';

        return $p;
    }
```

(If `idParam()` has no `description` key, the line above still works.)

- [ ] **Step 6: Run, expect PASS** — `vendor/bin/phpunit tests/HistoryLockstepTest.php tests/OpenApiSpecTest.php` → `OK`; `php -l` the three edited files; `make test` exit 0.

- [ ] **Step 7: Live check over real HTTP + MCP.** Fetch the API key with `docker compose exec -T wpcli wp option get ai_editor_divi5_api_key` (see `ApiKey.php` for the exact option name if that is empty) and run, against `http://localhost:8181/wp-json/ai-editor-divi5/v1`: (a) `tools/list` via `POST /mcp` with `Authorization: Bearer <key>` and body `{"jsonrpc":"2.0","id":1,"method":"tools/list"}` — expect the three new tool names among 18; (b) create a scratch page (Task 3's `wp eval` snippet without the delete), do two REST saves, then `GET /pages/<id>/history` (expect 2 versions), `GET /pages/<id>/history/1` (expect `post_content`), `POST /pages/<id>/restore {"version_id":1}` (expect `restored:true`, a `validator` object), and the same three through MCP `tools/call`; (c) `GET /pages/<id>/history/999` → 404; an unknown page id → 404; (d) as a user WITHOUT edit rights (create a temporary subscriber with `wp user create aied-sub sub@example.test --role=subscriber`, `wp_set_current_user` to it in a `wp eval` REST call) every history/restore route is refused (403/rest_forbidden) and nothing changes — delete that user afterwards; then delete the scratch page. Paste the status codes in the report.

- [ ] **Step 8: Commit**

```bash
git add wp-plugin/src tests/HistoryLockstepTest.php
git commit -m "feat(history): list/get/restore tools in MCP, REST and ChatGPT OpenAPI (free tier)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Admin "Recent AI edits" panel with one-click restore

**Files:**
- Modify: `wp-plugin/src/HistoryService.php` (add `recent()`)
- Modify: `wp-plugin/src/AdminPage.php` (handler, notices, panel)
- Modify: `tests/HistoryServiceTest.php` (one test for `recent`)

**Interfaces:**
- Consumes: `PageHistory::recent` (Task 1), `HistoryStore::load`, `HistoryService::restore` (Task 2).
- Produces: `HistoryService::recent(int $limit = 5): array` → list of `['page_id','title','version_id','saved_at','tool']`; admin-post action `ai_editor_divi5_restore_page` (nonce action `ai_editor_divi5_restore_page`, POST fields `page_id`, `version_id`).

- [ ] **Step 1: Failing test** — add to `tests/HistoryServiceTest.php`:

```php
    public function testRecentListsNewestSnapshotPerPageWithTitles(): void
    {
        $GLOBALS['__wp_posts'][6] = (object) [ 'ID' => 6, 'post_type' => 'page', 'post_title' => 'About', 'post_content' => 'B0' ];
        $GLOBALS['__wp_posts'][5]->post_title = 'Home';
        HistoryService::write( 5, 'A1', 'update_page_layout' );
        HistoryService::write( 6, 'B1', 'edit_page_content' );
        $rows = HistoryService::recent( 5, [ 5, 6 ] );
        $this->assertCount( 2, $rows );
        $titles = array_column( $rows, 'title' );
        sort( $titles );
        $this->assertSame( [ 'About', 'Home' ], $titles );
        $this->assertSame( [ 1 ], array_unique( array_column( $rows, 'version_id' ) ) );
    }
```

`recent()` takes an optional list of page ids so it is testable without `get_posts` (production passes `null` and queries). Run → FAIL (method missing).

- [ ] **Step 2: Implement `recent()`** in `HistoryService`:

```php
    /**
     * @param list<int>|null $pageIds null = discover pages that have history (production)
     * @return list<array{page_id:int, title:string, version_id:int, saved_at:mixed, tool:mixed}>
     */
    public static function recent( int $limit = 5, ?array $pageIds = null ): array
    {
        if ( null === $pageIds ) {
            $pageIds = array_map( 'intval', get_posts( [
                'post_type'      => 'page',
                'post_status'    => 'any',
                'posts_per_page' => 50,
                'fields'         => 'ids',
                'meta_key'       => HistoryStore::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            ] ) );
        }

        $byPage = [];
        foreach ( $pageIds as $pageId ) {
            $byPage[ $pageId ] = HistoryStore::load( (int) $pageId );
        }

        $rows = [];
        foreach ( PageHistory::recent( $byPage, $limit ) as $row ) {
            $post   = get_post( $row['page_id'] );
            $rows[] = $row + [ 'title' => $post ? (string) ( $post->post_title ?? '' ) : '' ];
        }

        return $rows;
    }
```

(`'title'` is placed via array union so the pure row keys stay first.) In production, use `get_the_title()` instead of `post_title` only if the title can be empty — keep `post_title` for the shim-compatible test and fall back in the view: `'' === $title ? __( '(no title)', ... ) : $title`.

Run `vendor/bin/phpunit tests/HistoryServiceTest.php` → PASS.

- [ ] **Step 3: Admin handler + notices** in `AdminPage.php`:
  - In `register()` add: `add_action('admin_post_ai_editor_divi5_restore_page', [$this, 'handleRestorePage']);`
  - Add the handler next to the other handlers:

```php
    public function handleRestorePage(): void
    {
        $this->guard('ai_editor_divi5_restore_page'); // guard() verifies the nonce via check_admin_referer().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
        $pageId    = isset( $_POST['page_id'] ) ? absint( wp_unslash( $_POST['page_id'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
        $versionId = isset( $_POST['version_id'] ) ? absint( wp_unslash( $_POST['version_id'] ) ) : 0;

        $post = $pageId ? get_post( $pageId ) : null;
        if ( ! $post || 'page' !== $post->post_type || ! current_user_can( 'edit_post', $pageId ) ) {
            $this->redirect( 'dashboard', 'restore_failed' );
        }

        $result = HistoryService::restore( $pageId, $versionId );
        $this->redirect( 'dashboard', $result['ok'] ? 'version_restored' : 'restore_failed' );
    }
```

  - In `notice()`: add `'version_restored' => __( 'Previous version restored. The version it replaced was saved too, so you can undo this.', 'ai-editor-for-divi-5' ),` to the success `$map`, and an `elseif ( $notice === 'restore_failed' )` branch printing an error notice `__( 'Could not restore that version.', 'ai-editor-for-divi-5' )` (same `printf` pattern as `license_invalid`).

- [ ] **Step 4: Panel.** Add a private method and call it in `viewDashboard()` immediately before the `<!-- Recommendations -->` heading (`<?php $this->historySection(); ?>`):

```php
    private function historySection(): void
    {
        $rows = HistoryService::recent( 5 );
        if ( [] === $rows ) {
            return;
        }
        ?>
        <h3 class="aied-section-title"><?php esc_html_e( 'Recent AI edits', 'ai-editor-for-divi-5' ); ?></h3>
        <div class="aied-card">
            <p class="aied-muted"><?php esc_html_e( 'Every AI save keeps the previous version. Restore it here if an edit was not what you wanted.', 'ai-editor-for-divi-5' ); ?></p>
            <table class="widefat striped">
                <tbody>
                <?php foreach ( $rows as $row ) :
                    $title = '' === $row['title'] ? __( '(no title)', 'ai-editor-for-divi-5' ) : $row['title']; ?>
                    <tr>
                        <td><strong><?php echo esc_html( $title ); ?></strong></td>
                        <td><?php echo esc_html( (string) $row['saved_at'] ); ?></td>
                        <td><code><?php echo esc_html( (string) $row['tool'] ); ?></code></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                  onsubmit="return confirm('<?php echo esc_js( __( 'Restore the version saved before this AI edit?', 'ai-editor-for-divi-5' ) ); ?>')">
                                <input type="hidden" name="action" value="ai_editor_divi5_restore_page">
                                <input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $row['page_id'] ); ?>">
                                <input type="hidden" name="version_id" value="<?php echo esc_attr( (string) $row['version_id'] ); ?>">
                                <?php wp_nonce_field( 'ai_editor_divi5_restore_page' ); ?>
                                <button type="submit" class="button"><?php esc_html_e( 'Restore previous version', 'ai-editor-for-divi-5' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
```

- [ ] **Step 5: Verify.** `php -l wp-plugin/src/AdminPage.php`; `make test` exit 0; `vendor/bin/phpunit --filter "ConnectCardRenderTest|ConnectClientsTest"` OK. Live: using the scratch page flow from Task 3 (keep the page), log in with Playwright (`/Users/Lucas/Documents/JHMG-Local/layoutlab/node_modules/playwright`, admin/admin at `http://localhost:8181`), open `?page=ai-editor-divi5&tab=dashboard`, screenshot the "Recent AI edits" panel to the scratchpad directory and look at it; click "Restore previous version" (accept the confirm dialog) and assert the success notice appears and the page content equals snapshot #1; then delete the scratch page. Do NOT commit screenshots.

- [ ] **Step 6: Commit**

```bash
git add wp-plugin/src tests
git commit -m "feat(history): admin Recent AI edits panel with restore

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Cleanup, guides, docs and release 3.5.0

**Files:**
- Modify: `wp-plugin/uninstall.php`, `wp-plugin/src/StyleGuide.php`, `wp-plugin/src/SiteGuide.php`
- Modify: `wp-plugin/ai-editor-divi5.php` (Version header + `AI_EDITOR_DIVI5_VERSION`)
- Modify: `wp-plugin/readme.txt`, `CLAUDE.md`
- Modify: `ai-editor-for-divi-5.zip` (rebuilt)

**Interfaces:**
- Consumes: `HistoryStore::deleteAll()` (Task 2).

- [ ] **Step 1: Uninstall** — in `uninstall.php` add after the `PhpProposals::clear();` line: `AiEditorDivi5\WP\HistoryStore::deleteAll();`

- [ ] **Step 2: Guides.** Add one short paragraph to each of `StyleGuide::markdown()` and `SiteGuide::markdown()` (find the existing safety/overview section; keep it to 2-3 lines): "Every save through this plugin snapshots the page's previous content. If an edit was wrong, `list_page_history` then `restore_page_version` undoes it (the restore is itself undoable). Prefer small `edit_page_content` edits over rewriting a whole page." Run the existing guide tests (`vendor/bin/phpunit tests/StyleGuideTest.php tests/SiteGuideTest.php`) — they must stay green; if a test pins an exact string/length, extend it deliberately.

- [ ] **Step 3: Version + readme.**
  - `ai-editor-divi5.php`: `Version: 3.5.0` and `define('AI_EDITOR_DIVI5_VERSION', '3.5.0');`. Do NOT add `Tested up to` to the header.
  - `readme.txt`: `Stable tag: 3.5.0`; in the Description tool list add the three tools (`list_page_history`, `get_page_history_entry`, `restore_page_version` — "undo any AI edit; free"); Privacy section: add the sentence "To make undo possible, the plugin keeps the last 10 previous versions of each page the AI edits in your WordPress database (post meta); nothing is sent anywhere, and the history is removed when you delete the plugin."; Changelog (above `= 3.4.0 =`):

```
= 3.5.0 =
* New: **Undo for AI edits.** Every time your AI saves a page, the plugin keeps the previous version (last 10 per page). Ask your AI to "undo that" — it can list the saved versions and restore one (free, in MCP, the REST API and the ChatGPT action) — or restore from the new "Recent AI edits" panel on the plugin's Dashboard. A restore is itself undoable.
* Restoring brings back your own earlier content and is not blocked by validation; the result tells you whether that content passes the validator.
* Pages larger than 512 KB are still saved normally but no snapshot is kept for that edit (the result says so).
```

  and an Upgrade Notice `= 3.5.0 =` / `Adds undo for AI edits. No reconfiguration needed.`
  - `CLAUDE.md`: in the architecture section add one row/sentence for the history tools (HistoryService is the single write path; restore bypasses the gate by design) and update "Current state" to 3.5.0.

- [ ] **Step 4: Full verification.** Run and report outputs: `make test` (exit 0); `bash scripts/build-plugin-zip.sh`; zip checks (no `tools/ scripts/ build/ docs/ fixtures/`; contains `src/HistoryService.php`, `src/PageHistory.php`, `src/HistoryStore.php`; `unzip -p … ai-editor-divi5.php | grep Version` → 3.5.0; `Tested up to` absent from the header); Plugin Check under the real slug via the swap procedure used for 3.4.0 (extract zip on host → `docker cp` to `/var/www/html/wp-content/plugins/ai-editor-for-divi-5` → deactivate `ai-editor-divi5`, activate `ai-editor-for-divi-5`, `wp plugin check ai-editor-for-divi-5 --format=table`, then deactivate/remove it and re-activate `ai-editor-divi5`) → `Success: Checks complete. No errors found.`; confirm `wp plugin list` shows `ai-editor-divi5` active again. Fix anything Plugin Check reports (most likely: escaping or nonce comments in Task 5) and re-run.

- [ ] **Step 5: Commit**

```bash
git add wp-plugin CLAUDE.md ai-editor-for-divi-5.zip
git commit -m "release(aied): v3.5.0 — undo for AI edits

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```
