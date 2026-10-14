# Change an existing page's title Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The AI can change the WordPress title of an existing page or Divi Project (alone or together with a layout) through the existing `update_page_layout` / `PUT /pages/{id}` write path, with `dry_run`, approval mode and honest "not undoable" reporting.

**Architecture:** A new pure class `PageTitle` validates the strict-string `title`. `HistoryService` gets an optional `$newTitle` on `write()` and a new `writeTitle()`; `DryRun` and `ApprovalMode` learn about the title (backward compatible: no title = byte-identical to 4.10.0). The MCP and REST handlers parse the title after their existing checks and branch into title-only or combined. No new tool, route or operation.

**Tech Stack:** PHP 8.3, PHPUnit (`make test`), WordPress shims in `tests/bootstrap.php`, Docker dev site for `make verify-page-title`.

**Spec:** `docs/superpowers/specs/2026-10-14-change-page-title-design.md` (decisions D1-D12). Read it first.

## Global Constraints

- Work on branch `feat/change-page-title`. Run tests with `make test` (PHPUnit); a single file with `vendor/bin/phpunit tests/<File>.php`.
- Free plugin only. Nothing in `wp-plugin/` may be licence-gated, fetch remotely or save custom code; do NOT weaken `tests/WpOrgComplianceTest.php`.
- The validator (`src/`, `wp-plugin/validator/`) is not touched.
- No new MCP tool, no new REST route, no new OpenAPI operation (importer budget of 30 stays used up). Tool count stays 21.
- `title`: strict string, never cast; trimmed; non-empty; no `<` or `>`; no control characters; valid UTF-8; max 200 characters (characters, not bytes). Failure = 400 `invalid_field` (REST) / -32602 (MCP).
- `title` is accepted only by `update_page_layout` / `PUT /pages/{id}`, NOT by `edit_page_content` / `POST /pages/{id}/edit`.
- `post_content` absent or null + `title` given = title-only. Explicit `post_content: ""` is still refused. Neither given = 400 / -32602 "Give post_content, title, or both."
- A title change is NOT restorable: results carry `title: {changed, before, after, restorable:false}` and a message with the old title; restore results carry `title_restored:false` and `title_note`.
- Without `title`, every response and every approval-mode key is byte-identical to 4.10.0.
- `HistoryService::write()` keeps its first three parameters and meaning (Pro add-on calls it).
- `wp_update_post` calls carry `wp_slash()`ed arrays and ONLY `ID` plus the fields being changed (never `post_name`, `post_status`).
- Commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Do not touch the untracked `docs/competitor-respira-and-extra-2026-10.md`.
- Every new file in `wp-plugin/src/` must be `require_once`d in `wp-plugin/src/autoload.php` (`PluginAutoloadCoversEverySourceFileTest`).

## Review Focus

- Title with `"`, `\`, `&`, emoji and accents must round-trip through MCP and REST (tests in Tasks 3, 4, 5).
- A combined call with an invalid layout must NOT save the title (Tasks 4, 5).
- A preview of title A must not authorise saving title B, or a layout-only save (Task 2, 4, 5).
- A page whose existing layout is invalid or not Divi must still be renameable title-only (Tasks 4, 5).
- Core changing the stored title (kses for users without `unfiltered_html`): the response reports the re-read stored title, not the submitted one (Task 3).
- `post_content: ""` with a title must still be refused, not read as title-only (Tasks 4, 5).

---

### Task 1: `PageTitle` (pure validation and report)

**Files:**
- Create: `wp-plugin/src/PageTitle.php`
- Modify: `wp-plugin/src/autoload.php` (add `require_once __DIR__ . '/PageTitle.php';` next to the other pure-class requires)
- Test: `tests/PageTitleTest.php`

**Interfaces:**
- Produces:
  - `PageTitle::MAX_LENGTH = 200`
  - `PageTitle::parse(array $args, string $key = 'title'): array{ok:bool, present:bool, title:?string, error:?string}`: `present:false, ok:true` when the key is absent or null; `ok:false` with an `error` sentence for any rule failure; on success `title` is the trimmed string.
  - `PageTitle::report(string $before, string $after, bool $changed): array` -> `['changed'=>bool,'before'=>string,'after'=>string]` plus `'restorable'=>false` when `$changed`.
  - `PageTitle::notRestorableMessage(string $before): string`
  - `PageTitle::unchangedMessage(string $title): string`
  - `PageTitle::restoreNote(string $currentTitle): string`

- [ ] **Step 1: Write the failing test** (`tests/PageTitleTest.php`)

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\PageTitle;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/PageTitle.php';

class PageTitleTest extends TestCase
{
    public function testAbsentAndNullMeanNotGiven(): void
    {
        $this->assertSame( [ 'ok' => true, 'present' => false, 'title' => null, 'error' => null ], PageTitle::parse( [] ) );
        $this->assertSame( [ 'ok' => true, 'present' => false, 'title' => null, 'error' => null ], PageTitle::parse( [ 'title' => null ] ) );
    }

    public function testTrimsAndAccepts(): void
    {
        $r = PageTitle::parse( [ 'title' => "  About us \u{00A0}" ] );
        $this->assertTrue( $r['ok'] );
        $this->assertTrue( $r['present'] );
        $this->assertSame( 'About us', $r['title'] );
    }

    public function testQuotesBackslashesAmpersandEmojiAccentsAreKept(): void
    {
        foreach ( [ 'Tom & "Jerry" \\ Café', 'Sale 🎉 now', "O'Neil’s" ] as $t ) {
            $this->assertSame( $t, PageTitle::parse( [ 'title' => $t ] )['title'] );
        }
    }

    /** @return array<string,array{mixed}> */
    public static function nonStrings(): array
    {
        return [ 'int' => [ 5 ], 'float' => [ 1.5 ], 'true' => [ true ], 'false' => [ false ], 'array' => [ [ 'a' ] ], 'empty array' => [ [] ], 'object' => [ new \stdClass() ] ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'nonStrings' )]
    public function testNonStringsAreRejectedNeverCast( mixed $v ): void
    {
        $r = PageTitle::parse( [ 'title' => $v ] );
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'string', (string) $r['error'] );
    }

    public function testEmptyAndWhitespaceOnlyAreRejected(): void
    {
        foreach ( [ '', '   ', "\u{00A0}\u{00A0}", " \t " ] as $t ) {
            $r = PageTitle::parse( [ 'title' => $t ] );
            $this->assertFalse( $r['ok'], json_encode( $t ) );
            $this->assertStringContainsString( 'empty', (string) $r['error'] );
        }
    }

    public function testMarkupIsRejected(): void
    {
        foreach ( [ '<b>Hi</b>', 'a < b', 'a > b', '<script>x</script>' ] as $t ) {
            $r = PageTitle::parse( [ 'title' => $t ] );
            $this->assertFalse( $r['ok'], $t );
            $this->assertStringContainsString( 'plain text', (string) $r['error'] );
        }
    }

    public function testControlCharactersAreRejected(): void
    {
        foreach ( [ "Line one\nLine two", "a\tb", "a\0b", "a\x7Fb", "a\u{0085}b" ] as $t ) {
            $r = PageTitle::parse( [ 'title' => $t ] );
            $this->assertFalse( $r['ok'], json_encode( $t ) );
            $this->assertStringContainsString( 'one line', (string) $r['error'] );
        }
    }

    public function testInvalidUtf8IsRejected(): void
    {
        $r = PageTitle::parse( [ 'title' => "bad \xC3\x28 bytes" ] );
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'UTF-8', (string) $r['error'] );
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        $this->assertTrue( PageTitle::parse( [ 'title' => str_repeat( 'a', 200 ) ] )['ok'] );
        $this->assertFalse( PageTitle::parse( [ 'title' => str_repeat( 'a', 201 ) ] )['ok'] );
        $this->assertTrue( PageTitle::parse( [ 'title' => str_repeat( 'é', 200 ) ] )['ok'], '200 two-byte characters are fine' );
        $this->assertFalse( PageTitle::parse( [ 'title' => str_repeat( 'é', 201 ) ] )['ok'] );
        $this->assertStringContainsString( '200', (string) PageTitle::parse( [ 'title' => str_repeat( 'a', 201 ) ] )['error'] );
    }

    public function testTrimHappensBeforeTheLengthCount(): void
    {
        $this->assertTrue( PageTitle::parse( [ 'title' => ' ' . str_repeat( 'a', 200 ) . ' ' ] )['ok'] );
    }

    public function testReportAndMessages(): void
    {
        $this->assertSame( [ 'changed' => true, 'before' => 'Old', 'after' => 'New', 'restorable' => false ], PageTitle::report( 'Old', 'New', true ) );
        $this->assertSame( [ 'changed' => false, 'before' => 'Same', 'after' => 'Same' ], PageTitle::report( 'Same', 'Same', false ) );
        $this->assertStringContainsString( '"Old"', PageTitle::notRestorableMessage( 'Old' ) );
        $this->assertStringContainsString( 'restore_page_version will not bring the old title back', PageTitle::notRestorableMessage( 'Old' ) );
        $this->assertStringContainsString( 'already "Same"', PageTitle::unchangedMessage( 'Same' ) );
        $this->assertStringContainsString( 'left as it is: "Now"', PageTitle::restoreNote( 'Now' ) );
    }
}
```

- [ ] **Step 2: Run it, expect failure**

Run: `vendor/bin/phpunit tests/PageTitleTest.php`. Expected: error "Class PageTitle not found".

- [ ] **Step 3: Implement** (`wp-plugin/src/PageTitle.php`)

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The rules for the optional `title` argument of update_page_layout / PUT /pages/{id}: a plain-text, one-line
 * WordPress page title. Pure (no WordPress calls). A failure is an input error (400 / -32602), never a validator verdict.
 * WordPress's own sanitising still runs when the title is saved; callers report the re-read stored value.
 */
final class PageTitle
{
    public const MAX_LENGTH = 200;

    /** @return array{ok:bool, present:bool, title:?string, error:?string} */
    public static function parse( array $args, string $key = 'title' ): array
    {
        if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
            return [ 'ok' => true, 'present' => false, 'title' => null, 'error' => null ];
        }
        $raw = $args[ $key ];
        if ( ! is_string( $raw ) ) {
            return self::fail( "{$key} must be a string." );
        }
        if ( 1 !== preg_match( '//u', $raw ) ) {
            return self::fail( "{$key} must be valid UTF-8 text." );
        }
        // Trim ASCII whitespace and the Unicode space separators (including NBSP) at both ends.
        $title = preg_replace( '/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $raw );
        if ( null === $title || '' === $title ) {
            return self::fail( "{$key} must not be empty." );
        }
        if ( 1 === preg_match( '/[\p{Cc}]/u', $title ) ) {
            return self::fail( "{$key} must be one line of text: no line breaks, tabs or control characters." );
        }
        if ( false !== strpbrk( $title, '<>' ) ) {
            return self::fail( "{$key} is plain text: no HTML or markup (the characters < and > are not allowed)." );
        }
        $length = preg_match_all( '/./us', $title );
        if ( $length > self::MAX_LENGTH ) {
            return self::fail( sprintf( '%s is too long: %d characters (the limit is %d).', $key, $length, self::MAX_LENGTH ) );
        }

        return [ 'ok' => true, 'present' => true, 'title' => $title, 'error' => null ];
    }

    /** @return array{changed:bool, before:string, after:string, restorable?:false} */
    public static function report( string $before, string $after, bool $changed ): array
    {
        $out = [ 'changed' => $changed, 'before' => $before, 'after' => $after ];
        if ( $changed ) {
            $out['restorable'] = false;
        }

        return $out;
    }

    public static function notRestorableMessage( string $before ): string
    {
        return 'The page title is not part of the saved versions: restore_page_version will not bring the old title back. The old title was "'
            . $before . '"; to undo this, call again with that title.';
    }

    public static function unchangedMessage( string $title ): string
    {
        return 'The page title is already "' . $title . '". Nothing was changed.';
    }

    public static function restoreNote( string $currentTitle ): string
    {
        return 'Restoring brings back the page content only. The page title was left as it is: "' . $currentTitle . '".';
    }

    /** @return array{ok:false, present:false, title:null, error:string} */
    private static function fail( string $error ): array
    {
        return [ 'ok' => false, 'present' => false, 'title' => null, 'error' => $error ];
    }
}
```

- [ ] **Step 4: Run it, expect pass.** `vendor/bin/phpunit tests/PageTitleTest.php tests/PluginAutoloadCoversEverySourceFileTest.php` (the second fails until autoload.php has the require: add it first).
- [ ] **Step 5: Commit** `feat: PageTitle, the pure rules for a page title argument`

---

### Task 2: `ApprovalMode::withTitle()` and `DryRun` title previews

**Files:**
- Modify: `wp-plugin/src/ApprovalMode.php`, `wp-plugin/src/DryRun.php`
- Test: `tests/ApprovalModeTest.php` (append), `tests/DryRunTest.php` (append)

**Interfaces:**
- Consumes: `PageTitle::report()` (Task 1).
- Produces:
  - `ApprovalMode::withTitle(string $content, ?string $title): string` returns `$content` unchanged when `$title` is null, else `$content . "\0aied-title\0" . $title`.
  - `DryRun::preview(string $before, string $after, ?array $title = null): array`: when `$title` is not null it is added under key `title` just before `message`. Without it the output is identical to today.
  - `DryRun::previewTitle(array $title): array` returns `['saved'=>false,'dry_run'=>true,'valid'=>true,'violations'=>[],'layout_changed'=>false,'diff'=>null,'title'=>$title,'message'=>DryRun::MESSAGE]`.

- [ ] **Step 1: Failing tests.** In `ApprovalModeTest` add: `withTitle('X', null) === 'X'`; `withTitle('X','A') !== withTitle('X','B')`; `key(1,5,withTitle('C','A'),withTitle('B','Old')) !== key(1,5,'C','B')`; `key(1,5,'C','B')` equals the same value computed with the 4.10.0 formula (assert the literal string for fixed input, computed once and pasted: run the existing `key` and paste the output). Using the existing set_transient shim: `recordPreview(5, withTitle('C','A'), true, withTitle('B','Old'))` then `consume(5, withTitle('C','B'), withTitle('B','Old'))` is false, and `consume(5,'C','B')` is false, and `consume(5, withTitle('C','A'), withTitle('B','Old'))` is true; and a second consume is false (single use). Enable the mode first with `ApprovalMode::set(true)` in the test (shim options).
  In `DryRunTest` add: `preview($a,$b)` has no `title` key; `preview($a,$b,['changed'=>true,'before'=>'o','after'=>'n','restorable'=>false])['title']` equals it and the key order ends `...,'diff','title','message'`; `previewTitle(...)` has exactly the keys listed above and `valid === true`.
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement.** `ApprovalMode`:

```php
    /** The text an approval key is built from when a title is part of the change; null keeps today's key unchanged. */
    public static function withTitle( string $content, ?string $title ): string
    {
        return null === $title ? $content : $content . "\0aied-title\0" . $title;
    }
```

`DryRun`:

```php
    /** @param ?array<string,mixed> $title PageTitle::report() when the call also changes the page title */
    public static function preview( string $before, string $after, ?array $title = null ): array
    {
        $verdict = LayoutGate::check( $after, $before );
        $out     = [
            'saved'      => false,
            'dry_run'    => true,
            'valid'      => $verdict->isValid(),
            'violations' => array_map( static fn ( $v ) => $v->toArray(), $verdict->violations() ),
        ] + LayoutDiff::compare( $before, $after );
        if ( null !== $title ) {
            $out['title'] = $title;
        }

        return $out + [ 'message' => self::MESSAGE ];
    }

    /** A title-only preview: the layout is not part of the change, so it is neither checked nor diffed. @param array<string,mixed> $title */
    public static function previewTitle( array $title ): array
    {
        return [
            'saved'          => false,
            'dry_run'        => true,
            'valid'          => true,
            'violations'     => [],
            'layout_changed' => false,
            'diff'           => null,
            'title'          => $title,
            'message'        => self::MESSAGE,
        ];
    }
```

- [ ] **Step 4: Run both test files + `DryRunLockstepTest`, expect pass.**
- [ ] **Step 5: Commit** `feat: approval keys and dry-run previews can carry a title`

---

### Task 3: `HistoryService::write(.., ?$newTitle)`, `writeTitle()`, restore notes

**Files:**
- Modify: `wp-plugin/src/HistoryService.php`
- Test: `tests/HistoryServiceTest.php` (append)

**Interfaces:**
- Consumes: `PageTitle::restoreNote()` (Task 1). `require_once __DIR__ . '/PageTitle.php';` at the top of `HistoryService.php` next to the CodePolicy require (like CodePolicy, so the class is available in isolation).
- Produces:
  - `HistoryService::write(int $pageId, string $newContent, string $tool, ?string $newTitle = null): array` : unchanged result, plus `'title_after' => string` (the re-read stored `post_title`) when `$newTitle !== null`.
  - `HistoryService::writeTitle(int $pageId, string $title, string $tool): array` -> `['ok'=>true,'snapshot'=>['stored'=>false,'version_id'=>null,'reason'=>'title_only'],'title_after'=>string]` or `['ok'=>false,'error'=>'update_failed','message'=>string,'snapshot'=>same noSnapshot-with-title_only]`. Calls `wp_update_post(wp_slash(['ID'=>$pageId,'post_title'=>$title]), true)` and nothing else, no snapshot.
  - `restore()` success result gains `'title_restored'=>false` and `'title_note'=>PageTitle::restoreNote(<current post_title after the restore>)`.

- [ ] **Step 1: Failing tests** (add to `HistoryServiceTest`; set `post_title` on the post object in these tests, e.g. `$GLOBALS['__wp_posts'][5]->post_title = 'Old'` in each test):
  - `testWriteWithTitleSavesBothInOneUpdateAndSnapshotsContent`: `$GLOBALS['__wp_update_calls']` is 1 after `write(5,'NEW','update_page_layout','New title')`; `post_content==='NEW'`, `post_title==='New title'`, `snapshot.stored===true`, `title_after==='New title'`.
  - `testWriteWithoutTitleHasNoTitleKeyAndNeverTouchesTheTitle`: result has no `title_after`; `post_title` still `'Old'`.
  - `testWriteTitleChangesOnlyTheTitle`: content stays `'ORIGINAL'`; `snapshot` equals `['stored'=>false,'version_id'=>null,'reason'=>'title_only']`; `HistoryStore::summaries(5)===[]`; exactly one `wp_update_post` call; use `$GLOBALS['__wp_update_hook'] = function($id,$arr) use (&$seen){ $seen=$arr; }` and assert `array_keys($seen)===['ID','post_title']`.
  - `testWriteTitleRoundTripsQuotesAndBackslashes`: `'Say "hi" \\ there & more'` comes back identical in `title_after` and in the post.
  - `testStoredTitleIsReportedNotTheSubmittedOne`: `__wp_update_hook` sets `$GLOBALS['__wp_posts'][$id]->post_title = 'Tom &amp; Jerry'` (simulating core's kses); `writeTitle(5,'Tom & Jerry',..)['title_after']==='Tom &amp; Jerry'`.
  - `testWriteTitleFailureReportsAndKeepsTheOldTitle`: `$GLOBALS['__wp_update_fail']=true` -> `ok===false`, `error==='update_failed'`, title still `'Old'`.
  - `testWriteTitleOnMissingPage`: `writeTitle(999,'X','t')['ok']===false`.
  - `testRestoreReportsThatTheTitleWasNotRestored`: do `write(5,'NEW','t')` (snapshot id), change `post_title` to `'Now'`, `restore(5,id)` -> `title_restored===false` and `title_note` contains `left as it is: "Now"`.
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement.**

```php
    public static function write( int $pageId, string $newContent, string $tool, ?string $newTitle = null ): array
    {
        $post = get_post( $pageId );
        if ( ! $post ) {
            return [ 'ok' => false, 'error' => 'update_failed', 'message' => "Page {$pageId} not found.", 'snapshot' => self::noSnapshot() ];
        }
        $snapshot = self::snapshot( $pageId, (string) $post->post_content, $tool );

        // wp_slash: wp_update_post runs wp_unslash internally, which would strip
        // backslashes from escaped HTML (e.g. <) and corrupt the content.
        $fields = [ 'ID' => $pageId, 'post_content' => $newContent ];
        if ( null !== $newTitle ) {
            $fields['post_title'] = $newTitle;
        }
        $updated = wp_update_post( wp_slash( $fields ), true );
        if ( is_wp_error( $updated ) ) {
            return [ 'ok' => false, 'error' => 'update_failed', 'message' => $updated->get_error_message(), 'snapshot' => $snapshot ];
        }

        $out = [ 'ok' => true, 'snapshot' => $snapshot ];
        if ( null !== $newTitle ) {
            $out['title_after'] = self::storedTitle( $pageId );
        }

        return $out;
    }

    /**
     * Changes only the page title. Passes nothing but the id and the title to wp_update_post, so the content, slug and
     * status are carried over by core, and takes no snapshot: no layout changes, and the history keeps content only.
     *
     * @return array{ok:bool, snapshot:array, title_after?:string, error?:string, message?:string}
     */
    public static function writeTitle( int $pageId, string $title, string $tool ): array
    {
        $none = [ 'stored' => false, 'version_id' => null, 'reason' => 'title_only' ];
        if ( ! get_post( $pageId ) ) {
            return [ 'ok' => false, 'error' => 'update_failed', 'message' => "Page {$pageId} not found.", 'snapshot' => $none ];
        }
        $updated = wp_update_post( wp_slash( [ 'ID' => $pageId, 'post_title' => $title ] ), true );
        if ( is_wp_error( $updated ) ) {
            return [ 'ok' => false, 'error' => 'update_failed', 'message' => $updated->get_error_message(), 'snapshot' => $none ];
        }

        return [ 'ok' => true, 'snapshot' => $none, 'title_after' => self::storedTitle( $pageId ) ];
    }

    /** The title as stored now (core may have filtered what was submitted). */
    private static function storedTitle( int $pageId ): string
    {
        $post = get_post( $pageId );

        return $post ? (string) $post->post_title : '';
    }
```

(`$tool` is accepted for symmetry and future logging; keep it in the signature.) In both `restore()` success returns (the normal one and the too-big one) add `'title_restored' => false, 'title_note' => PageTitle::restoreNote( self::storedTitle( $pageId ) )`.
- [ ] **Step 4: Run `tests/HistoryServiceTest.php` and `tests/HistoryLockstepTest.php`, expect pass.**
- [ ] **Step 5: Commit** `feat: HistoryService can write a title, alone or with the content`

---

### Task 4: MCP `update_page_layout` takes `title`

**Files:**
- Modify: `wp-plugin/src/McpHandler.php` (`toolUpdate` ~line 485; tool definition ~line 222; restore tool result ~line 714)
- Test: `tests/PageTitleHandlersTest.php` (new; set-up copied from `DryRunHandlersTest`: same requires plus `PageTitle.php`, same `setUp`, add `$post->post_title = 'Old title'; $post->post_type = 'page'; $post->post_status='publish'; $post->post_name='old-slug';`)

**Interfaces:**
- Consumes: `PageTitle::parse/report/notRestorableMessage/unchangedMessage`, `ApprovalMode::withTitle`, `DryRun::preview($b,$a,$titleReport)` / `previewTitle()`, `HistoryService::write(..,$title)` / `writeTitle()`.
- Produces (responses of `update_page_layout`):
  - title-only save: `{saved:true, valid:true, layout_changed:false, history:{stored:false,version_id:null,reason:'title_only'}, title:{changed:true,before,after(stored),restorable:false}, page:{id,title}, message:<notRestorableMessage(before)>}`
  - combined save: today's response plus `title` and `message`.
  - title unchanged (title-only): `{saved:false, valid:true, layout_changed:false, title:{changed:false,...}, message:<unchangedMessage>}`, `isError` false, no write, no approval needed.
  - title unchanged inside a combined call: the title is dropped from the write (`$newTitle = null`), the layout saves as before, response carries `title:{changed:false,..}`.
  - bad title: JSON-RPC error -32602 with `PageTitle`'s sentence.
  - neither field: -32602 "Give post_content, title, or both."
  - `restore_page_version` response gains `title_restored:false`, `title_note`.

Handler logic (replace the head of `toolUpdate`; keep everything after the checks as is, parameterised by `$newTitle`):

```php
        $pageId  = (int) ($args['page_id'] ?? 0);
        $hasContent = array_key_exists('post_content', $args) && $args['post_content'] !== null;
        $content = PageAccess::stringArg($args, 'post_content');
        $dryRun  = PageAccess::boolArg($args, 'dry_run');
        if ($content === null) { return $this->rpcError($id, -32602, 'post_content must be a string.'); }
        if ($dryRun === null)  { return $this->rpcError($id, -32602, 'dry_run must be a boolean.'); }
        $titleArg = PageTitle::parse($args);
        if (!$titleArg['ok']) { return $this->rpcError($id, -32602, (string) $titleArg['error']); }
        if (!$hasContent && !$titleArg['present']) { return $this->rpcError($id, -32602, 'Give post_content, title, or both.'); }
        // target resolve (unchanged: NOT_FOUND / FORBIDDEN)
        if ($hasContent && $content === '') { return $this->rpcError($id, -32602, 'post_content is required.'); }
```

Then compute `$titleBefore = (string) $post->post_title; $titleNew = $titleArg['title']; $titleChanged = $titleArg['present'] && $titleNew !== $titleBefore; $writeTitle = $titleChanged ? $titleNew : null; $report = $titleArg['present'] ? PageTitle::report($titleBefore, $titleNew, $titleChanged) : null;`

Approval subject/base for both branches: `$subject = ApprovalMode::withTitle($hasContent ? $content : (string) $post->post_content, $writeTitle); $base = ApprovalMode::withTitle((string) $post->post_content, null === $writeTitle ? null : $titleBefore);`. Use `$subject/$base` in `recordPreview` and `consume` instead of the raw content/base (identical values when `$writeTitle` is null, so existing behaviour holds).

Title-only branch (`!$hasContent`) placed right after the computations:
- dry run: log `DryRun::ENDPOINT`; `$preview = DryRun::previewTitle($report)`; `if ($titleChanged) ApprovalMode::recordPreview($pageId, $subject, true, $base)`; return preview (with `message` unchanged if changed; if not changed set `message` to `PageTitle::unchangedMessage`).
- not changed: log `update_layout` valid; return no-op result described above.
- else `ApprovalMode::consume($pageId,$subject,$base)` or refusal exactly like the combined path; then `HistoryService::writeTitle($pageId,$titleNew,'update_page_layout')`; failure -> -32603; success result as above with `title.after = $write['title_after']`; recompute `$report = PageTitle::report($titleBefore, $write['title_after'], true)`; `page.title` = `get_the_title($pageId)` like today.

Combined branch: dry run passes `$report` as the third arg to `DryRun::preview`; the rest unchanged except `HistoryService::write($pageId, $content, 'update_page_layout', $writeTitle)` and adding `title` + `message` (only when `$report !== null`; when changed use `$write['title_after']` for `after`). The layout gate runs BEFORE any write, so an invalid layout refuses the whole call (title not saved).

Tool definition: description appended with: ` To change the WordPress page title (what shows in the page list, the browser tab and menus; NOT the h1 inside the layout), pass title; with only title and page_id the layout is left alone. A title change cannot be undone with restore_page_version, so tell the owner the old title (the result has it).` `post_content` description: `Divi 5 Gutenberg block HTML. Optional when title is given.` Add property `'title' => ['type'=>'string','description'=>'Optional. New WordPress page title: plain text, one line, 1 to 200 characters, no HTML. Changes the page title only, not any heading in the layout.']`. `required` becomes `['page_id']`. Do NOT add `title` to `edit_page_content`.

- [ ] **Step 1: Failing tests** in `PageTitleHandlersTest` (MCP half; Task 5 appends the REST half). Use the `mcp()` helper from `DryRunHandlersTest`. Cases (each a test method):
  1. title-only save: `post_title==='New title'`, `post_content===ORIGINAL`, `post_name==='old-slug'`, `post_status==='publish'`, `__wp_update_calls===1`, history rows 0, response `title.restorable===false`, `history.reason==='title_only'`, `layout_changed===false`.
  2. combined save: both fields changed; history has one snapshot whose content is ORIGINAL; `message` mentions `"Old title"`.
  3. combined with INVALID layout (`NEW_INVALID`) + title: isError result, `post_title` still `'Old title'`, `__wp_update_calls===0`.
  4. title-only on a page whose content is not Divi (`post_content='plain text'`): still saves the title.
  5. strict types: `title` 5 / true / [] / `''` / `'   '` / `'<b>x</b>'` / 201 chars -> error code -32602, nothing written.
  6. neither `post_content` nor `title` -> -32602; `post_content:''` with a title -> -32602 and nothing written.
  7. dry run title-only: nothing written (`assertNothingWritten`-style: title, content, update calls, history), response has `title.changed===true`, `before==='Old title'`, `after==='New title'`, `dry_run===true`, `saved===false`.
  8. dry run combined: diff present, `title` present, nothing written.
  9. unchanged title title-only: `saved===false`, `title.changed===false`, no write, message contains `already "Old title"`; with approval mode on this needs no preview.
  10. unchanged title inside a combined call: layout saved, title untouched, `title.changed===false`.
  11. approval mode on (`ApprovalMode::set(true)`): title-only save with no preview -> `PREVIEW_REQUIRED` and nothing written; after a dry run of `'A'`, saving `'B'` is refused; saving `'A'` works once and a second identical save is refused; a layout-only preview does not authorise layout+title; a title preview does not authorise a layout-only save of the same layout; if the stored title changes between preview and save the save is refused.
  12. permission: `$GLOBALS['__wp_can']=false` -> -32602 "permission", nothing written (title included).
  13. Divi Project (`post_type='project'`) title-only works.
  14. round trip: title `Say "hi" \ there & 🎉 é` comes back byte-identical in `post_title` and in `title.after`.
  15. kses simulation via `__wp_update_hook`: stored `'Tom &amp; Jerry'` is what `title.after` reports.
  16. `edit_page_content` schema has no `title` property; `update_page_layout` schema has `title` and `required===['page_id']`.
  17. restore: `restore_page_version` response contains `title_restored===false` and `title_note`.
- [ ] **Step 2: Run, expect failures.**
- [ ] **Step 3: Implement** as specified above (`restore` MCP tool: add the two keys from `$result` to its JSON).
- [ ] **Step 4: Run** `vendor/bin/phpunit tests/PageTitleHandlersTest.php tests/DryRunHandlersTest.php tests/DryRunLockstepTest.php tests/ApprovalModeHandlersTest.php tests/LayoutGateHandlersTest.php tests/McpProtocolTest.php tests/ProjectLayoutsHandlersTest.php`. Expected: all pass. If `DryRunLockstepTest` asserts source strings that moved, adjust the production code to keep them (it requires `PageAccess::boolArg(... 'dry_run')`, `if ($dryRun === null)`, and the dry-run return before `HistoryService::write(`); do not weaken that test.
- [ ] **Step 5: Commit** `feat: update_page_layout accepts a title (MCP)`

---

### Task 5: REST `PUT /pages/{id}` takes `title`

**Files:**
- Modify: `wp-plugin/src/RestController.php` (`update_page` ~line 300; `history_restore` ~line 420)
- Test: `tests/PageTitleHandlersTest.php` (append the REST mirror of Task 4's cases 1-15 and 17)

**Interfaces:** same helper calls as Task 4. Responses:
- title-only save: 200 `{saved:true, valid:true, violations:[], layout_changed:false, history:{stored:false,version_id:null,reason:'title_only'}, title:{...restorable:false}, page:<build_layout_envelope with post_title = stored title>, message}`
- unchanged title-only: 200 `{saved:false, valid:true, layout_changed:false, title:{changed:false,..}, message}`
- invalid title: `WP_Error('invalid_field', <sentence>, ['status'=>400])`
- neither: `WP_Error('missing_field', 'Give post_content, title, or both.', ['status'=>400])`
- `post_content` present and non-string, or present and blank: `WP_Error('missing_field', 'Request body must include a non-empty string "post_content".', ['status'=>400])` (unchanged text and status)
- invalid layout (combined): 422 exactly as today, title not saved
- approval refusal 428 as today
- `history_restore` 200 body gains `title_restored:false`, `title_note`.

Parsing order in `update_page` (keep the target resolution first, as today): `$body` -> `$hasContent = array_key_exists('post_content',$body) && $body['post_content'] !== null` -> if `$hasContent`: the existing `stringArg` + non-empty check -> `dry_run` strict check -> `PageTitle::parse($body)` (400 `invalid_field`) -> neither check. Then the same `$titleBefore/$titleNew/$titleChanged/$writeTitle/$report/$subject/$base` computation as the MCP handler and the same two branches. Set `$post->post_title = $write['title_after']` before `build_layout_envelope($post)` when a title was written, and keep `$post->post_content = $content` only when content was written.

- [ ] **Step 1: Failing tests** (REST mirror, using `restUpdate()`; statuses as above).
- [ ] **Step 2: Run, expect failures.**
- [ ] **Step 3: Implement.** Keep `PageAccess::boolArg($body, 'dry_run')`, `if ($dryRun === null)`, `$body    = (array) $request->get_json_params();` (two-space alignment as written today, the lockstep test greps for it) and the order "dry-run return before `HistoryService::write(`". Do NOT add a `title` arg to any route registration (`DryRunLockstepTest` forbids `dry_run` there; keep `title` out too so WordPress cannot coerce its type before the strict parser sees it).
- [ ] **Step 4: Run** the same suites as Task 4 plus `tests/RestRoutesTest*.php` if present (`ls tests | grep -i rest`). Expected: pass.
- [ ] **Step 5: Commit** `feat: PUT /pages/{id} accepts a title (REST)`

---

### Task 6: OpenAPI + lockstep test

**Files:**
- Modify: `wp-plugin/src/OpenApiSpec.php`
- Test: `tests/PageTitleLockstepTest.php` (new)

**Interfaces:** `postContentBody()` (used by `PUT /pages/{id}` only; check with grep that no other operation uses it with `$dryRun=true` differently) gets a `title` property and no `required`; the `put` description gains the title sentence; its `400` description becomes `Missing post_content and title, or an invalid title`; `SnapshotInfo.reason` description lists `title_only`. The 200 response for `put` gains `layout_changed` (boolean), `title` (object: changed, before, after, restorable) and the restore response schema gains `title_restored` (boolean) and `title_note` (string). `POST /pages/{id}/edit` is unchanged.

- [ ] **Step 1: Failing lockstep test** `PageTitleLockstepTest`:
  - MCP `update_page_layout` tool: `properties.title.type==='string'`, `required===['page_id']`, description mentions `title`, `h1`, `restore_page_version`; `edit_page_content` has no `title` property.
  - OpenAPI `paths['/pages/{id}']['put']['requestBody']...['properties']['title']['type']==='string'`, no `post_content` in `required`; `paths['/pages/{id}/edit']['post']` request properties have no `title`.
  - Source greps (see `DryRunLockstepTest::body()`): `McpHandler::toolUpdate` and `RestController::update_page` both contain `PageTitle::parse(`, `HistoryService::writeTitle(`, `ApprovalMode::withTitle(`, and `toolEditContent` / `edit_page` contain none of `PageTitle` / `writeTitle`; `register_routes` body does not contain `'title'`.
  - Counts: `count($spec['paths'])` and the number of operations equal the numbers in `tests/OpenApiSpecTest.php` (read them from there; do not hard-code a new number), MCP tool count unchanged (assert against the existing count in `McpProtocolTest`/`WpOrgComplianceTest`).
  - `SnapshotInfo.properties.reason.description` contains `title_only`.
- [ ] **Step 2: Run, expect failure. Step 3: Implement. Step 4: Run `OpenApiSpecTest`, `PageTitleLockstepTest`, `DryRunLockstepTest`, `WpOrgComplianceTest`: pass.**
- [ ] **Step 5: Commit** `feat: OpenAPI and lockstep for the page title`

---

### Task 7: Guides and docs text

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php`, `wp-plugin/readme.txt` (FAQ entry only; the version/changelog are done at release prep), `docs/EXTENDING.md` only if it documents `HistoryService::write` (grep first)
- Test: `tests/StyleGuideTest.php` (append), `tests/WpOrgComplianceTest.php` only if a readme assertion is needed

**Content (StyleGuide):** a short section titled `Page title vs headline` stating exactly the D11 points: page title = WordPress title (page list, browser tab, menus, search); changed with `update_page_layout` + `title` (title-only is fine); headline = heading module in the layout, changed with `edit_page_content` / `update_page_layout`; they are separate and neither changes the other; when the owner says "change the title" and the layout has an `h1` repeating the old title, tell them and ask whether to change both; never report a layout edit as a title change; not supported by this tool (say so, do not improvise): the page address (slug), menu labels that do not follow the page, the browser-tab or SEO title set by an SEO plugin; a title change cannot be undone with `restore_page_version`, tell the owner the old title. Add one sentence about dynamic title modules ONLY after Task 9 has proven it (see Task 9 step "Fill in guide"); until then the section says only "A heading typed into the layout is layout text and does not change when the title changes."

**readme FAQ:** one entry "Can the AI change a page's title?" answering yes, how, the h1 difference, that slugs are not changed, and that a title change is not covered by Undo. No claim about SEO plugins or rendering beyond what Task 9 proves.

- [ ] **Step 1: Failing test** (`StyleGuideTest`): the guide contains `Page title vs headline`, `update_page_layout`, `restore_page_version`, `slug`/`address` wording; contains no mention of Pro. Readme test: the FAQ entry exists and does not contain "premium" / "upgrade".
- [ ] **Step 2-4: Implement, run `StyleGuideTest`, `WpOrgComplianceTest`, `GuideShapesTest`: pass.**
- [ ] **Step 5: Commit** `docs: teach the AI the page title is not the headline`

---

### Task 8: `make verify-page-title` (real-site script)

**Files:**
- Create: `scripts/verify-page-title.php` (model it on `scripts/verify-project-layouts.php`: `aied_require_dev_site()`, `$check`, `$rest`, `$mcp` helpers, a `try/finally` that always deletes throwaway posts and restores settings)
- Modify: `Makefile` (add `verify-page-title` to `.PHONY` and a target after `verify-project-layouts`:
  `verify-page-title:` / tab `@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-page-title.php --user=1`), `CLAUDE.md` entry points table (one row).

Checks (each a `$check(...)` line; all must PASS):
1. Throwaway published page A (valid heading layout, title `aied title verify A`, known slug) and throwaway draft Divi Project P (skip with a note if the `project` type does not exist) and a throwaway draft blog post B.
2. REST title-only: `PUT /pages/{A}` `{title}` -> 200, `post_title` changed, content byte-identical, `post_name` and `post_status` unchanged, `history.reason==='title_only'`, no history row added.
3. MCP title-only on A: same, via `update_page_layout`.
4. Combined (REST and MCP): layout + title saved; the previous layout is snapshotted; `restore` brings the layout back, reports `title_restored:false`, and the title stays as it was.
5. Title round trip with `"`, `\`, `&`, emoji, accent via REST and MCP: `get_post_field('post_title')` after, and what the result reports as `title.after` equals it.
6. Strict inputs over both transports: number, array, empty, whitespace, `<b>`, 201 chars -> 400 / -32602, nothing written; `post_content:""` + title refused; neither given refused.
7. `dry_run` title-only and combined: title/content/`post_modified`/history untouched; the preview shows old and new title.
8. Approval mode (turn on via `ApprovalMode::set(true)`, ALWAYS restore the original value in `finally`): save without preview -> 428 / `PREVIEW_REQUIRED`; preview A then save B refused; preview A then save A works; save used the preview (second identical save refused).
9. Permission: create a throwaway Author user (can edit own pages only; delete the user in `finally`); as that user, `PUT` title on A (owned by user 1) -> 403 / -32602; the title is unchanged. As the Author on their own throwaway draft page: allowed.
10. Core filtering: as a user WITHOUT `unfiltered_html` (an Editor on a non-multisite site HAS it, so on single site use `kses_init_filters()` to simulate or `add_filter('user_has_cap', ...)` removing `unfiltered_html` for the throwaway Author's own page) save `Tom & Jerry`; record what core stored and assert `title.after` equals the stored value. Print the stored value in the log. If this cannot be set up on the site, print `SKIP` with the reason, not PASS.
11. Blog post B is refused (404 / -32602) for a title change.
12. Slug: after every rename, A's `post_name` is unchanged and `get_permalink(A)` is unchanged.
13. Dynamic title (D10): a throwaway published page C whose layout is a `divi/post-title` module (use the real shape from `fixtures/valid/` or from the module verification evidence; grep `fixtures/valid` for `divi/post-title` first, and if there is none use `divi/fullwidth-post-title`; if neither exists in a fixture, SKIP with the reason, do not invent attributes). Fetch the front end with `wp_remote_get(get_permalink(C))` (the dev site is local; this script runs on the dev site only) before and after a rename and report whether the old/new title appears in the HTML. Print both findings. This is the evidence for the spec's "Verified facts".
14. Cleanup: every throwaway post and user deleted (`wp_delete_post(.., true)`, `wp_delete_user`), the approval setting restored, and a final check that none remain (`get_posts` by meta/title prefix `aied title verify`).
15. At the end print `N PASS, M FAIL`, exit non-zero (`WP_CLI::error`) on any FAIL.

- [ ] **Step 1: Write the script. Step 2: `make up` is not needed (stack already running); the plugin on the dev site is the repo's `wp-plugin/` (check how the other scripts get it loaded: `docker compose exec -T wpcli wp plugin list` and the volume mapping in `docker-compose.yml`). Run `make verify-page-title` and read the whole output. Step 3: Fix real failures in production code (not by loosening checks). Step 4: Commit** `feat: make verify-page-title, a real-site check of title changes`

---

### Task 9: Whole-branch verification, release prep

Done by the lead, not a subagent (see the task brief): `make test` green; Plugin Check on the dev copy and the extracted zip; `make verify-page-title` output read; `requesting-code-review` on the whole branch and fixes; "Verified facts" section of the spec filled from the live run and the guide sentence about dynamic modules written ONLY as far as the run proved; version bump 4.11.0 (plugin header + `AI_EDITOR_DIVI5_VERSION` + readme Stable tag + Changelog); `bash scripts/build-plugin-zip.sh`; CLAUDE.md "Current state" and tool descriptions; memory note. Then the release steps authorised in the brief.

## Self-review

- Spec coverage: D1 (Task 4/5/6 `edit_page_content` untouched, pinned in Task 6), D2 (Tasks 4/5), D3 (Task 1), D4 (Tasks 3, 4, 5), D5 (Tasks 2, 4, 5), D6 (Tasks 2, 4, 5), D7 (Tasks 4, 5, 8), D8 (Task 3), D9 (Tasks 3, 8), D10 (Tasks 7, 8, 9), D11 (Task 7), D12 (Task 6). No gap.
- Names used across tasks: `PageTitle::parse/report/notRestorableMessage/unchangedMessage/restoreNote`, `ApprovalMode::withTitle`, `DryRun::preview(.., ?array $title)`, `DryRun::previewTitle`, `HistoryService::write(.., ?string $newTitle)`, `HistoryService::writeTitle`, result keys `title_after`, `title_restored`, `title_note`, `layout_changed`, `reason:'title_only'`. Consistent.
