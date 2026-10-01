# Dry-run Diff (Preview Before Save) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an AI client call `update_page_layout` / `edit_page_content` (MCP) and `PUT /pages/{id}` / `POST /pages/{id}/edit` (REST + OpenAPI) with `dry_run: true` to get the checker's verdict plus a capped, structured diff of what would change, without saving anything. Ships as 4.2.0 (local merge only).

**Architecture:** Two small pure plugin classes do the work: `LayoutDiff` (parses both layouts with the bundled `Divi5Validator\BlockParser` and walks the trees by child index) and `DryRun` (assembles the shared response body: validator verdict + diff). The four handlers parse a strict boolean `dry_run`, run their normal checks, and return `DryRun::preview()` before `HistoryService::write()` is ever reachable. `PageAccess::boolArg()` rejects mistyped values so a typo can never turn a preview into a write. The tool list stays at 15 MCP tools / 15 OpenAPI operations.

**Tech Stack:** PHP 8.1+, WordPress REST API, PHPUnit, Docker WP + wp-cli (real-site check).

**Spec:** `docs/superpowers/specs/2026-10-01-dry-run-diff-design.md` (decisions delegated to Claude's recommendation by the owner on 2026-10-01; this plan is reviewed by the owner before execution).

## Spec refinement found while planning (tell the owner at handoff)

The spec caps the diff at 50 changes and truncates values to 200 characters, but one *changed* block could still carry hundreds of attribute differences (a restyled module) and flood the AI's context. This plan adds a per-block cap: at most **25** attribute differences per changed block, plus `attrs_omitted` (the number left out) when any were dropped. Nothing else deviates from the spec.

## Global Constraints

- PHP `>= 8.1`, WordPress `>= 6.0`; `declare(strict_types=1)` and the `ABSPATH` guard in every new plugin file; no `mb_*` functions (use `preg_split('//u')` for characters).
- The validator in `src/` and `wp-plugin/validator/` is **not touched** (no sync work); `LayoutDiff` and `DryRun` live in `wp-plugin/src/` and use the bundled `Divi5Validator\BlockParser` / `Block` / `Validator`.
- `dry_run` never writes: every dry-run branch returns **before** `HistoryService::write()`; the page, its history rows, its meta and its `post_modified` stay untouched.
- A mistyped `dry_run` (anything that is not a JSON boolean: `"false"`, `0`, `"yes"`, arrays) is rejected: REST 400 / MCP -32602. Absent or `null` means `false`.
- A dry run that produced a preview is a **successful** call even when `valid` is false: REST 200, MCP `isError` false. Real errors (page not found, forbidden, bad arguments, find/replace not matching exactly once or `expect_count` mismatch) keep exactly the responses of a real write.
- Response body (all transports): `saved:false, dry_run:true, valid, violations, diff` (+ `diff_error` when a side cannot be parsed, then `diff:null`) `, message:"Preview only. Nothing was saved."`; `edit` previews also carry `replaced`.
- Diff limits (constants, tested): 50 changes (`truncated:true`, `summary` keeps the true totals), 25 attribute diffs per changed block (`attrs_omitted`), values truncated to 200 characters (then `…`); `builderVersion` differences are ignored.
- Usage log: dry runs are logged as endpoint `dry_run`, result `UsageTracker::RESULT_READ`.
- OpenAPI: every operation description ≤ 300 characters; no response object schema without `properties`/`additionalProperties`; OpenAPI is 3.1.0 (type arrays such as `['string','null']` are valid).
- 15 MCP tools and 15 OpenAPI operations unchanged. `tests/WpOrgComplianceTest.php` is **never weakened** (it needs no edit in this plan). Words the compliance scanner rejects in `wp-plugin/` text files: `upgrade`, `premium`, `Pro` (standalone), `licence key` (case-insensitive), plus the substrings `update_plugins`, `Licensing`, `PREMIUM`; in `readme.txt` also `unlock`, `locked`, `upgrade to`, `license key`. Do not use them in any comment, string or text you add.
- Work on branch `feat/dry-run-diff` in the main checkout (the Docker site mounts `./wp-plugin` from it). Local merge only; **never push, upload or send anything**. Every commit message ends with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Container wp-cli: use `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp ...` (the 128M default kills plain `wp` in Divi). Never `wp plugin delete` anything on the dev site (it runs `uninstall.php` against the shared dev DB).
- Run the suite with `vendor/bin/phpunit` (`failOnWarning`/`failOnRisky`).

## Review Focus

Failure modes the spec implies that a person is most likely to hit; each has a pinning test in the task named.

1. **`dry_run` never writes, on any path:** valid layout, invalid layout, find/replace that fails, a page whose current content cannot be parsed. (Task 2 structural guard on all four handlers; Task 3 real-site check on the real handlers.)
2. **A mistyped `dry_run` must never silently become a real save:** `"false"`, `"true"`, `0`, `1`, `"yes"`, `[]` are rejected on both transports. (Task 1 `boolArg` tests; Task 3 real-site check.)
3. **Huge diffs cannot flood the AI's context:** a 3000-block rewrite returns 50 changes, a block with 40 changed attributes returns 25 plus `attrs_omitted`, a 500-character value is cut to 200. (Task 1.)
4. **Hand-edited or legacy page content that does not parse** still returns the checker's verdict with `diff:null` and a readable `diff_error`, never an exception. (Task 1.)
5. **Preview and real write agree:** the same validator verdict, `replaced` reported for edits, an invalid-layout preview is a normal response (not an error), and a real write afterwards still works and snapshots. (Task 2, Task 3.)

---

## File Structure

| File | Responsibility |
|---|---|
| `wp-plugin/src/LayoutDiff.php` (new) | Pure: `compare($before,$after)` → capped structured diff or `diff:null`+`diff_error` |
| `wp-plugin/src/DryRun.php` (new) | `preview($before,$after)`: verdict + diff, the one response body all transports return |
| `wp-plugin/src/PageAccess.php` (edit) | `boolArg()` strict optional-boolean parsing |
| `wp-plugin/src/McpHandler.php`, `RestController.php`, `OpenApiSpec.php` (edit) | `dry_run` on the four write entry points + OpenAPI request/response/schemas |
| `wp-plugin/src/StyleGuide.php`, `SiteGuide.php` (edit) | One short "preview before saving" section/bullet |
| `wp-plugin/src/autoload.php` (edit) | Require the two new classes |
| `tests/LayoutDiffTest.php`, `tests/DryRunTest.php`, `tests/DryRunLockstepTest.php` (new) | Unit, response-shape and lockstep/structural tests |
| `scripts/verify-dry-run.php` (new), `Makefile` (edit) | Real-site check, `make verify-dry-run` |
| `wp-plugin/readme.txt`, `jhmg-ai-editor-for-divi-5.php`, `tests/WpOrgNamingTest.php`, `CLAUDE.md`, `jhmg-ai-editor-for-divi-5.zip` | 4.2.0 release |

---

### Task 1: `PageAccess::boolArg`, `LayoutDiff`, `DryRun` (pure units)

**Files:**
- Create: `wp-plugin/src/LayoutDiff.php`, `wp-plugin/src/DryRun.php`, `tests/LayoutDiffTest.php`, `tests/DryRunTest.php`
- Modify: `wp-plugin/src/PageAccess.php` (add one method), `wp-plugin/src/autoload.php`

**Interfaces:**
- Produces: `PageAccess::boolArg(array $args, string $key): ?bool` (false when absent/null, the bool when a bool, `null` for any other type).
- Produces: `LayoutDiff::compare(string $before, string $after): array` returning `['diff' => array|null] + ['diff_error' => string]` (the error key only when `diff` is null). A non-null `diff` is `['summary' => ['added'=>int,'removed'=>int,'changed'=>int,'unchanged'=>int], 'changes' => list<array>, 'truncated' => bool]`; each change is `['path'=>string,'block'=>string,'kind'=>'added'|'removed'|'changed']`, and `changed` also has `'attrs' => list<['path'=>string,'before'=>?string,'after'=>?string]>` and, only when some were dropped, `'attrs_omitted' => int`. Constants `LayoutDiff::MAX_CHANGES = 50`, `MAX_ATTRS_PER_BLOCK = 25`, `MAX_VALUE_CHARS = 200`.
- Produces: `DryRun::preview(string $before, string $after): array` (the response body), `DryRun::ENDPOINT = 'dry_run'`, `DryRun::MESSAGE = 'Preview only. Nothing was saved.'`.
- Path convention (tests pin it): a block's path is its parent's path + `/` + its name + `[index]`, starting from the children of the parser's synthetic root, e.g. `divi/placeholder[0]/divi/section[0]/divi/heading[1]`.

- [ ] **Step 1: Create the branch**

```bash
cd "/Users/Lucas/Documents/JHMG-Local/Divi 5 Deterministic Validator"
git checkout -b feat/dry-run-diff
```

- [ ] **Step 2: Write the failing tests**

Create `tests/LayoutDiffTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\LayoutDiff;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';

class LayoutDiffTest extends TestCase
{
    private const SECTION_PATH = 'divi/placeholder[0]/divi/section[0]';

    private function page( string ...$inner ): string
    {
        return '<!-- wp:divi/placeholder -->' . implode( '', $inner ) . '<!-- /wp:divi/placeholder -->';
    }

    private function section( string ...$children ): string
    {
        return '<!-- wp:divi/section {"builderVersion":"5.14.0"} -->' . implode( '', $children ) . '<!-- /wp:divi/section -->';
    }

    /** @param array<string,mixed> $extra */
    private function heading( string $text, string $ver = '5.14.0', array $extra = [] ): string
    {
        return '<!-- wp:divi/heading ' . json_encode( [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $text ] ] ] ] + $extra + [ 'builderVersion' => $ver ] ) . ' /-->';
    }

    /** @return array<string,mixed> */
    private function diff( string $before, string $after ): array
    {
        $r = LayoutDiff::compare( $before, $after );
        $this->assertIsArray( $r['diff'], (string) ( $r['diff_error'] ?? '' ) );

        return $r['diff'];
    }

    public function testConstantsArePinned(): void
    {
        $this->assertSame( 50, LayoutDiff::MAX_CHANGES );
        $this->assertSame( 25, LayoutDiff::MAX_ATTRS_PER_BLOCK );
        $this->assertSame( 200, LayoutDiff::MAX_VALUE_CHARS );
    }

    public function testIdenticalLayoutsHaveNoChanges(): void
    {
        $a = $this->page( $this->section( $this->heading( 'A' ), $this->heading( 'B' ) ) );
        $d = $this->diff( $a, $a );
        $this->assertSame( [ 'added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 4 ], $d['summary'] );
        $this->assertSame( [], $d['changes'] );
        $this->assertFalse( $d['truncated'] );
    }

    public function testOneChangedHeadingIsExactlyOneChangeWithTheRightAttributePath(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A' ), $this->heading( 'B' ) ) ),
            $this->page( $this->section( $this->heading( 'A' ), $this->heading( 'C' ) ) )
        );
        $this->assertSame( [ 'added' => 0, 'removed' => 0, 'changed' => 1, 'unchanged' => 3 ], $d['summary'] );
        $this->assertSame( [ [
            'path'  => self::SECTION_PATH . '/divi/heading[1]',
            'block' => 'divi/heading',
            'kind'  => 'changed',
            'attrs' => [ [ 'path' => 'title.innerContent.desktop.value', 'before' => 'B', 'after' => 'C' ] ],
        ] ], $d['changes'] );
    }

    public function testBlockAddedAtTheEnd(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A' ) ) ),
            $this->page( $this->section( $this->heading( 'A' ), $this->heading( 'B' ) ) )
        );
        $this->assertSame( 1, $d['summary']['added'] );
        $this->assertSame( [ [ 'path' => self::SECTION_PATH . '/divi/heading[1]', 'block' => 'divi/heading', 'kind' => 'added' ] ], $d['changes'] );
    }

    public function testBlockRemoved(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A' ), $this->heading( 'B' ) ) ),
            $this->page( $this->section( $this->heading( 'A' ) ) )
        );
        $this->assertSame( 1, $d['summary']['removed'] );
        $this->assertSame( [ [ 'path' => self::SECTION_PATH . '/divi/heading[1]', 'block' => 'divi/heading', 'kind' => 'removed' ] ], $d['changes'] );
    }

    public function testBlockTypeSwappedAtAnIndexIsRemovedThenAdded(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A' ) ) ),
            $this->page( $this->section( '<!-- wp:divi/text {"builderVersion":"5.14.0"} /-->' ) )
        );
        $this->assertSame( [ 'removed', 'added' ], array_column( $d['changes'], 'kind' ) );
        $this->assertSame( [ self::SECTION_PATH . '/divi/heading[0]', self::SECTION_PATH . '/divi/text[0]' ], array_column( $d['changes'], 'path' ) );
    }

    public function testBuilderVersionOnlyChangeIsNotAChange(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A', '5.8.0' ) ) ),
            $this->page( $this->section( $this->heading( 'A', '5.14.0' ) ) )
        );
        $this->assertSame( 0, $d['summary']['changed'] );
        $this->assertSame( [], $d['changes'] );
    }

    public function testAnAttributeAddedOrRemovedShowsNullOnTheMissingSide(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A', '5.14.0', [ 'gone' => 'x' ] ) ) ),
            $this->page( $this->section( $this->heading( 'A', '5.14.0', [ 'fresh' => 'y' ] ) ) )
        );
        $this->assertSame( [
            [ 'path' => 'gone', 'before' => 'x', 'after' => null ],
            [ 'path' => 'fresh', 'before' => null, 'after' => 'y' ],
        ], $d['changes'][0]['attrs'] );
    }

    public function testChangesAreCappedAtFiftyWhileTheSummaryKeepsTheTrueCounts(): void
    {
        $mk = fn ( string $p ): string => $this->page( $this->section( ...array_map( fn ( int $i ): string => $this->heading( $p . $i ), range( 1, 3000 ) ) ) );
        $d  = $this->diff( $mk( 'A' ), $mk( 'B' ) );
        $this->assertSame( 3000, $d['summary']['changed'] );
        $this->assertCount( LayoutDiff::MAX_CHANGES, $d['changes'] );
        $this->assertTrue( $d['truncated'] );
        $this->assertLessThan( 30000, strlen( (string) json_encode( $d ) ), 'a huge rewrite must stay small' );
    }

    public function testExactlyFiftyChangesIsNotTruncated(): void
    {
        $mk = fn ( string $p ): string => $this->page( $this->section( ...array_map( fn ( int $i ): string => $this->heading( $p . $i ), range( 1, 50 ) ) ) );
        $d  = $this->diff( $mk( 'A' ), $mk( 'B' ) );
        $this->assertCount( 50, $d['changes'] );
        $this->assertFalse( $d['truncated'] );
    }

    public function testAttributeDifferencesPerBlockAreCappedAtTwentyFive(): void
    {
        $x = $y = [];
        for ( $i = 0; $i < 40; $i++ ) {
            $x[ "x{$i}" ] = "v{$i}";
            $y[ "x{$i}" ] = "w{$i}";
        }
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A', '5.14.0', $x ) ) ),
            $this->page( $this->section( $this->heading( 'A', '5.14.0', $y ) ) )
        );
        $c = $d['changes'][0];
        $this->assertCount( 25, $c['attrs'] );
        $this->assertSame( 15, $c['attrs_omitted'] );
        $this->assertSame( 'x0', $c['attrs'][0]['path'] );
    }

    public function testNoAttrsOmittedKeyWhenNothingWasDropped(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'A' ) ) ),
            $this->page( $this->section( $this->heading( 'B' ) ) )
        );
        $this->assertArrayNotHasKey( 'attrs_omitted', $d['changes'][0] );
    }

    public function testLongValuesAreCutToTwoHundredCharactersAndMarked(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'a' ) ) ),
            $this->page( $this->section( $this->heading( str_repeat( 'x', 500 ) ) ) )
        );
        $this->assertSame( str_repeat( 'x', 200 ) . '…', $d['changes'][0]['attrs'][0]['after'] );

        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'a' ) ) ),
            $this->page( $this->section( $this->heading( str_repeat( 'é', 250 ) ) ) )
        );
        $this->assertSame( str_repeat( 'é', 200 ) . '…', $d['changes'][0]['attrs'][0]['after'], 'cut by characters, not bytes' );
    }

    public function testAValueOfExactlyTwoHundredCharactersIsKeptWhole(): void
    {
        $d = $this->diff(
            $this->page( $this->section( $this->heading( 'a' ) ) ),
            $this->page( $this->section( $this->heading( str_repeat( 'x', 200 ) ) ) )
        );
        $this->assertSame( str_repeat( 'x', 200 ), $d['changes'][0]['attrs'][0]['after'] );
    }

    public function testUnparseableCurrentContentGivesNullDiffAndAReadableError(): void
    {
        $r = LayoutDiff::compare( '<!-- wp:divi/section {"builderVersion"', $this->page( $this->section() ) );
        $this->assertNull( $r['diff'] );
        $this->assertStringContainsString( 'current page content could not be parsed', $r['diff_error'] );
    }

    public function testUnparseableNewLayoutGivesNullDiffAndAReadableError(): void
    {
        $r = LayoutDiff::compare( $this->page( $this->section() ), '<!-- wp:divi/section {"builderVersion"' );
        $this->assertNull( $r['diff'] );
        $this->assertStringContainsString( 'new layout could not be parsed', $r['diff_error'] );
    }

    public function testEmptyContentOnEitherSideDoesNotThrow(): void
    {
        $r = LayoutDiff::compare( '', $this->page( $this->section() ) );
        $this->assertArrayHasKey( 'diff', $r );
        $r = LayoutDiff::compare( $this->page( $this->section() ), '' );
        $this->assertArrayHasKey( 'diff', $r );
    }

    // ---- real exported layout -------------------------------------------------------------

    private function fixture(): string
    {
        $j = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/valid/page-7-home.json' ), true );

        return (string) $j['post_content'];
    }

    public function testRealFixtureAgainstItselfIsAllUnchanged(): void
    {
        $c = $this->fixture();
        $d = $this->diff( $c, $c );
        $this->assertSame( 0, $d['summary']['changed'] + $d['summary']['added'] + $d['summary']['removed'] );
        $this->assertGreaterThan( 20, $d['summary']['unchanged'] );
    }

    public function testRealFixtureRestampedWithAnotherBuilderVersionHasNoChanges(): void
    {
        $c = $this->fixture();
        $this->assertStringContainsString( '"builderVersion":"5.8.0"', $c );
        $d = $this->diff( $c, str_replace( '"builderVersion":"5.8.0"', '"builderVersion":"5.14.0"', $c ) );
        $this->assertSame( [], $d['changes'] );
    }

    public function testRealFixtureWithOneTitleEditedIsExactlyOneChange(): void
    {
        $c     = $this->fixture();
        $after = (string) preg_replace( '/Your Title Goes Here/', 'Brand new title', $c, 1 );
        $d     = $this->diff( $c, $after );
        $this->assertSame( 1, $d['summary']['changed'] );
        $this->assertSame( 0, $d['summary']['added'] + $d['summary']['removed'] );
        $this->assertSame( 'divi/slide', $d['changes'][0]['block'] );
        $this->assertSame( [ [ 'path' => 'title.innerContent.desktop.value', 'before' => 'Your Title Goes Here', 'after' => 'Brand new title' ] ], $d['changes'][0]['attrs'] );
    }
}
```

Create `tests/DryRunTest.php`:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DryRun;
use AiEditorDivi5\WP\PageAccess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/PageAccess.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';

class DryRunTest extends TestCase
{
    private function fixture(): string
    {
        $j = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/valid/page-7-home.json' ), true );

        return (string) $j['post_content'];
    }

    public function testBoolArgDefaultsToFalseWhenAbsentOrNull(): void
    {
        $this->assertFalse( PageAccess::boolArg( [], 'dry_run' ) );
        $this->assertFalse( PageAccess::boolArg( [ 'dry_run' => null ], 'dry_run' ) );
    }

    public function testBoolArgReturnsRealBooleans(): void
    {
        $this->assertTrue( PageAccess::boolArg( [ 'dry_run' => true ], 'dry_run' ) );
        $this->assertFalse( PageAccess::boolArg( [ 'dry_run' => false ], 'dry_run' ) );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'notBooleans' )]
    public function testBoolArgRejectsEveryNonBoolean( mixed $value ): void
    {
        $this->assertNull( PageAccess::boolArg( [ 'dry_run' => $value ], 'dry_run' ), 'a typo must never be read as false and turn a preview into a save' );
    }

    /** @return list<array{mixed}> */
    public static function notBooleans(): array
    {
        return [ [ 'false' ], [ 'true' ], [ 0 ], [ 1 ], [ 'yes' ], [ '' ], [ [] ], [ 0.0 ] ];
    }

    public function testPreviewOfAValidEditCarriesVerdictDiffAndMessage(): void
    {
        $before = $this->fixture();
        $after  = (string) preg_replace( '/Your Title Goes Here/', 'Brand new title', $before, 1 );
        $r      = DryRun::preview( $before, $after );

        $this->assertSame( [ 'saved', 'dry_run', 'valid', 'violations', 'diff', 'message' ], array_keys( $r ) );
        $this->assertFalse( $r['saved'] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertTrue( $r['valid'] );
        $this->assertSame( [], $r['violations'] );
        $this->assertSame( 1, $r['diff']['summary']['changed'] );
        $this->assertSame( 'Preview only. Nothing was saved.', $r['message'] );
        $this->assertSame( 'dry_run', DryRun::ENDPOINT );
    }

    public function testPreviewOfAnInvalidLayoutStillReturnsTheVerdictAndTheDiff(): void
    {
        $r = DryRun::preview( $this->fixture(), '<!-- wp:divi/placeholder --><!-- wp:divi/not-a-module /--><!-- /wp:divi/placeholder -->' );
        $this->assertFalse( $r['saved'] );
        $this->assertFalse( $r['valid'] );
        $this->assertNotEmpty( $r['violations'] );
        $this->assertIsArray( $r['diff'] );
        $this->assertGreaterThan( 0, $r['diff']['summary']['added'] + $r['diff']['summary']['removed'] );
    }

    public function testPreviewWhenTheCurrentPageDoesNotParseKeepsTheVerdictAndExplainsWhyThereIsNoDiff(): void
    {
        $r = DryRun::preview( '<!-- wp:divi/section {"x"', $this->fixture() );
        $this->assertTrue( $r['valid'] );
        $this->assertNull( $r['diff'] );
        $this->assertStringContainsString( 'could not be parsed', $r['diff_error'] );
        $this->assertSame( [ 'saved', 'dry_run', 'valid', 'violations', 'diff', 'diff_error', 'message' ], array_keys( $r ) );
    }

    public function testPreviewResultIsJsonEncodable(): void
    {
        $r = DryRun::preview( $this->fixture(), (string) preg_replace( '/Your Title Goes Here/', "Caf\u{e9} \u{1F600}", $this->fixture(), 1 ) );
        $this->assertIsString( json_encode( $r ) );
    }
}
```

- [ ] **Step 3: Run the tests to see them fail**

Run: `vendor/bin/phpunit tests/LayoutDiffTest.php tests/DryRunTest.php`
Expected: errors — `Class "AiEditorDivi5\WP\LayoutDiff" not found` / `DryRun` / `PageAccess::boolArg` undefined.

- [ ] **Step 4: Add `PageAccess::boolArg`**

In `wp-plugin/src/PageAccess.php`, add this method directly after `stringArg()` (before the class's closing brace):

```php

    /**
     * Optional boolean argument. Absent or null reads as false; a real boolean is returned as is;
     * any other type (e.g. the string "false", 0, "yes") yields null so the caller rejects it
     * instead of guessing (a typo must never turn a preview into a save).
     */
    public static function boolArg(array $args, string $key): ?bool
    {
        if (!array_key_exists($key, $args) || $args[$key] === null) {
            return false;
        }
        return is_bool($args[$key]) ? $args[$key] : null;
    }
```

- [ ] **Step 5: Create `wp-plugin/src/LayoutDiff.php`**

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Describes what an edit would change, block by block. Information only: it never affects what a
 * real write does. Blocks are compared by child index under the same parent, so an insertion in
 * the middle of a list is reported as changes at the shifted indices (no move detection).
 */
final class LayoutDiff
{
    public const MAX_CHANGES         = 50;
    public const MAX_ATTRS_PER_BLOCK = 25;
    public const MAX_VALUE_CHARS     = 200;

    /** Attributes the AI re-stamps on every layout: a difference there is not a change. */
    private const IGNORED_ATTRS = [ 'builderVersion' ];

    /**
     * @return array{diff: ?array<string,mixed>, diff_error?: string}
     */
    public static function compare( string $before, string $after ): array
    {
        $parser = new BlockParser();

        $a = $parser->parse( $before );
        if ( ! $a->isOk() ) {
            return [ 'diff' => null, 'diff_error' => 'The current page content could not be parsed: ' . self::firstError( $a->errors() ) ];
        }
        $b = $parser->parse( $after );
        if ( ! $b->isOk() ) {
            return [ 'diff' => null, 'diff_error' => 'The new layout could not be parsed: ' . self::firstError( $b->errors() ) ];
        }

        $state = [ 'added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0, 'changes' => [] ];
        self::compareChildren( $a->root(), $b->root(), '', $state );

        $total = $state['added'] + $state['removed'] + $state['changed'];

        return [
            'diff' => [
                'summary'   => [
                    'added'     => $state['added'],
                    'removed'   => $state['removed'],
                    'changed'   => $state['changed'],
                    'unchanged' => $state['unchanged'],
                ],
                'changes'   => $state['changes'],
                'truncated' => $total > self::MAX_CHANGES,
            ],
        ];
    }

    /** @param array<string,mixed> $state */
    private static function compareChildren( Block $a, Block $b, string $parentPath, array &$state ): void
    {
        $ca = $a->children();
        $cb = $b->children();
        $n  = max( count( $ca ), count( $cb ) );

        for ( $i = 0; $i < $n; $i++ ) {
            $x = $ca[ $i ] ?? null;
            $y = $cb[ $i ] ?? null;

            if ( null !== $x && null === $y ) {
                self::record( $state, 'removed', $x->name(), self::path( $parentPath, $x->name(), $i ) );
                continue;
            }
            if ( null === $x && null !== $y ) {
                self::record( $state, 'added', $y->name(), self::path( $parentPath, $y->name(), $i ) );
                continue;
            }
            if ( null === $x || null === $y ) {
                continue; // unreachable: $i < max(count) means at least one side exists
            }
            if ( $x->name() !== $y->name() ) {
                self::record( $state, 'removed', $x->name(), self::path( $parentPath, $x->name(), $i ) );
                self::record( $state, 'added', $y->name(), self::path( $parentPath, $y->name(), $i ) );
                continue;
            }

            $path  = self::path( $parentPath, $x->name(), $i );
            $attrs = self::attrDifferences( $x->attrs(), $y->attrs() );
            if ( [] === $attrs['diffs'] ) {
                ++$state['unchanged'];
            } else {
                $extra = [ 'attrs' => $attrs['diffs'] ];
                if ( $attrs['omitted'] > 0 ) {
                    $extra['attrs_omitted'] = $attrs['omitted'];
                }
                self::record( $state, 'changed', $x->name(), $path, $extra );
            }
            self::compareChildren( $x, $y, $path, $state );
        }
    }

    /**
     * Counts every change, but keeps the details of only the first MAX_CHANGES so a rewrite of a
     * huge page cannot flood the AI's context.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $extra
     */
    private static function record( array &$state, string $kind, string $block, string $path, array $extra = [] ): void
    {
        ++$state[ $kind ];
        if ( count( $state['changes'] ) < self::MAX_CHANGES ) {
            $state['changes'][] = [ 'path' => $path, 'block' => $block, 'kind' => $kind ] + $extra;
        }
    }

    private static function path( string $parentPath, string $name, int $index ): string
    {
        return ( '' === $parentPath ? '' : $parentPath . '/' ) . $name . '[' . $index . ']';
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array{diffs: list<array{path:string, before:?string, after:?string}>, omitted:int}
     */
    private static function attrDifferences( array $a, array $b ): array
    {
        $fa = [];
        $fb = [];
        self::flatten( self::withoutIgnored( $a ), '', $fa );
        self::flatten( self::withoutIgnored( $b ), '', $fb );

        $paths   = array_values( array_unique( array_map( 'strval', array_merge( array_keys( $fa ), array_keys( $fb ) ) ) ) );
        $diffs   = [];
        $omitted = 0;
        foreach ( $paths as $path ) {
            $va = $fa[ $path ] ?? null;
            $vb = $fb[ $path ] ?? null;
            if ( $va === $vb ) {
                continue;
            }
            if ( count( $diffs ) < self::MAX_ATTRS_PER_BLOCK ) {
                $diffs[] = [ 'path' => $path, 'before' => self::clip( $va ), 'after' => self::clip( $vb ) ];
            } else {
                ++$omitted;
            }
        }

        return [ 'diffs' => $diffs, 'omitted' => $omitted ];
    }

    /**
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>
     */
    private static function withoutIgnored( array $attrs ): array
    {
        return array_diff_key( $attrs, array_flip( self::IGNORED_ATTRS ) );
    }

    /**
     * Flattens nested attributes to dot-paths with string leaves.
     *
     * @param array<mixed> $node
     * @param array<string,string> $out
     */
    private static function flatten( array $node, string $prefix, array &$out ): void
    {
        if ( [] === $node && '' !== $prefix ) {
            $out[ $prefix ] = '[]';

            return;
        }
        foreach ( $node as $key => $value ) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
            if ( is_array( $value ) ) {
                self::flatten( $value, $path, $out );
            } else {
                $out[ $path ] = self::scalar( $value );
            }
        }
    }

    private static function scalar( mixed $v ): string
    {
        if ( is_string( $v ) ) {
            return $v;
        }
        if ( null === $v ) {
            return 'null';
        }
        if ( is_bool( $v ) ) {
            return $v ? 'true' : 'false';
        }

        return (string) $v;
    }

    /** Cuts a value to MAX_VALUE_CHARS characters (not bytes), marking the cut with an ellipsis. */
    private static function clip( ?string $v ): ?string
    {
        if ( null === $v || strlen( $v ) <= self::MAX_VALUE_CHARS ) {
            return $v; // at most 200 bytes is at most 200 characters
        }
        $chars = preg_split( '//u', $v, self::MAX_VALUE_CHARS + 1, PREG_SPLIT_NO_EMPTY );
        if ( false === $chars ) {
            return '[value is not valid UTF-8]'; // cannot happen for json_decode()d attributes
        }
        if ( count( $chars ) <= self::MAX_VALUE_CHARS ) {
            return $v;
        }

        return implode( '', array_slice( $chars, 0, self::MAX_VALUE_CHARS ) ) . '…';
    }

    /** @param list<string> $errors */
    private static function firstError( array $errors ): string
    {
        return (string) ( $errors[0] ?? 'unknown error' );
    }
}
```

- [ ] **Step 6: Create `wp-plugin/src/DryRun.php`**

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Validator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one response body a dry run returns, shared by the MCP and REST transports: the checker's
 * verdict on the would-be content plus a diff against the current content. It saves nothing and
 * has no side effects.
 */
final class DryRun
{
    /** Usage-log endpoint name for previews. */
    public const ENDPOINT = 'dry_run';

    public const MESSAGE = 'Preview only. Nothing was saved.';

    /** @return array<string,mixed> */
    public static function preview( string $before, string $after ): array
    {
        $verdict = ( new Validator() )->validateContent( $after );

        return [
            'saved'      => false,
            'dry_run'    => true,
            'valid'      => $verdict->isValid(),
            'violations' => array_map( static fn ( $v ) => $v->toArray(), $verdict->violations() ),
        ] + LayoutDiff::compare( $before, $after ) + [ 'message' => self::MESSAGE ];
    }
}
```

- [ ] **Step 7: Require the classes in `wp-plugin/src/autoload.php`**

Add directly after the line `require_once __DIR__ . '/PageEditor.php';`:

```php
require_once __DIR__ . '/LayoutDiff.php';
require_once __DIR__ . '/DryRun.php';
```

- [ ] **Step 8: Run the new tests and the whole suite**

Run: `vendor/bin/phpunit tests/LayoutDiffTest.php tests/DryRunTest.php` → Expected: PASS.
Run: `vendor/bin/phpunit` → Expected: all green, output pristine. (If a test of the real fixture fails because the fixture's first `Your Title Goes Here` is not inside a `divi/slide` title, adjust only the asserted `block` name to what the real fixture gives — the first raw occurrence at offset 832 is a `divi/slide` title at the time of writing.)

- [ ] **Step 9: Commit**

```bash
git add wp-plugin/src/LayoutDiff.php wp-plugin/src/DryRun.php wp-plugin/src/PageAccess.php wp-plugin/src/autoload.php tests/LayoutDiffTest.php tests/DryRunTest.php
git commit -m "feat: LayoutDiff, DryRun preview body and strict boolean argument parsing

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `dry_run` on the MCP tools, REST routes and OpenAPI spec

**Files:**
- Modify: `wp-plugin/src/McpHandler.php`, `wp-plugin/src/RestController.php`, `wp-plugin/src/OpenApiSpec.php`
- Create: `tests/DryRunLockstepTest.php`

**Interfaces:**
- Consumes: `PageAccess::boolArg`, `DryRun::preview()`, `DryRun::ENDPOINT`, `UsageTracker::log(string $endpoint, ?int $pageId, string $result, int $violations = 0)`, `UsageTracker::RESULT_READ`.
- Produces: `dry_run` (boolean) accepted by MCP `update_page_layout` / `edit_page_content` and REST `PUT /pages/{id}` / `POST /pages/{id}/edit`; OpenAPI request property + 200-response properties (`dry_run`, `diff` → `#/components/schemas/DryRunDiff`, `diff_error`, `message`) and the new component schemas `DryRunDiff`, `DryRunChange`, `DryRunAttr`.

- [ ] **Step 1: Write the failing lockstep test `tests/DryRunLockstepTest.php`**

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OpenApiSpec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';

/** dry_run must exist, with the same meaning, in MCP, REST and OpenAPI, and must never reach a write. */
class DryRunLockstepTest extends TestCase
{
    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    /** The source of one method: from its declaration to the next method declaration. */
    private function body( string $src, string $method ): string
    {
        $start = strpos( $src, "function {$method}(" );
        $this->assertNotFalse( $start, "{$method} not found" );
        $rest = substr( $src, $start + 10 );
        $end  = preg_match( '/\n    (?:public |private |protected )?(?:static )?function /', $rest, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] : strlen( $rest );

        return substr( $rest, 0, $end );
    }

    /** @return list<array{string,string,string}> file, method, argument variable */
    public static function handlers(): array
    {
        return [
            [ 'McpHandler.php', 'toolUpdate', '$args' ],
            [ 'McpHandler.php', 'toolEditContent', '$args' ],
            [ 'RestController.php', 'update_page', '$body' ],
            [ 'RestController.php', 'edit_page', '$body' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'handlers' )]
    public function testEveryWriteHandlerParsesDryRunStrictlyAndReturnsBeforeAnyWrite( string $file, string $method, string $var ): void
    {
        $b = $this->body( $this->src( $file ), $method );
        $this->assertStringContainsString( "PageAccess::boolArg({$var}, 'dry_run')", $b );
        $this->assertStringContainsString( 'DryRun::preview(', $b );
        $this->assertStringContainsString( 'UsageTracker::log(DryRun::ENDPOINT', $b );
        $this->assertStringContainsString( 'HistoryService::write(', $b );
        $this->assertLessThan(
            strpos( $b, 'HistoryService::write(' ),
            strpos( $b, 'DryRun::preview(' ),
            "{$method}: the dry-run return must come before HistoryService::write()"
        );
        $this->assertStringContainsString( 'if ($dryRun === null)', $b, "{$method}: a mistyped dry_run must be rejected" );
    }

    public function testMcpDeclaresDryRunAsABooleanOnBothWriteTools(): void
    {
        $mcp = $this->src( 'McpHandler.php' );
        $this->assertSame( 2, substr_count( $mcp, "'dry_run' => ['type' => 'boolean'" ) );
        $this->assertStringContainsString( 'dry_run=true', $mcp, 'the tool descriptions explain dry_run' );
    }

    public function testRestPassesNothingExtraToTheWriteRoutes(): void
    {
        $rest = $this->src( 'RestController.php' );
        $this->assertStringContainsString( "'/pages/(?P<id>\\d+)'", $rest );
        $this->assertStringContainsString( "'/pages/(?P<id>\\d+)/edit'", $rest );
    }

    public function testOpenApiDeclaresDryRunOnBothWriteOperationsAndTheResponseShape(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );

        $putBody  = $spec['paths']['/pages/{id}']['put']['requestBody']['content']['application/json']['schema']['properties'] ?? [];
        $editBody = $spec['paths']['/pages/{id}/edit']['post']['requestBody']['content']['application/json']['schema']['properties'] ?? [];
        $this->assertSame( 'boolean', $putBody['dry_run']['type'] ?? null );
        $this->assertSame( 'boolean', $editBody['dry_run']['type'] ?? null );
        $this->assertNotContains( 'dry_run', $spec['paths']['/pages/{id}']['put']['requestBody']['content']['application/json']['schema']['required'] ?? [] );

        $validateBody = $spec['paths']['/validate']['post']['requestBody']['content']['application/json']['schema']['properties'] ?? [];
        $this->assertArrayNotHasKey( 'dry_run', $validateBody, 'validate_layout never saves, it has no dry_run' );

        foreach ( [ $spec['paths']['/pages/{id}']['put'], $spec['paths']['/pages/{id}/edit']['post'] ] as $op ) {
            $props = $op['responses']['200']['content']['application/json']['schema']['properties'] ?? [];
            $this->assertSame( 'boolean', $props['dry_run']['type'] ?? null );
            $this->assertSame( '#/components/schemas/DryRunDiff', $props['diff']['$ref'] ?? null );
            $this->assertSame( 'string', $props['diff_error']['type'] ?? null );
            $this->assertSame( 'string', $props['message']['type'] ?? null );
        }

        $schemas = $spec['components']['schemas'];
        foreach ( [ 'DryRunDiff', 'DryRunChange', 'DryRunAttr' ] as $name ) {
            $this->assertArrayHasKey( $name, $schemas );
        }
        $this->assertSame( [ 'added', 'removed', 'changed' ], $schemas['DryRunChange']['properties']['kind']['enum'] ?? null );
        $this->assertSame( [ 'string', 'null' ], $schemas['DryRunAttr']['properties']['before']['type'] ?? null );
    }

    public function testTheOperationCountIsUnchanged(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $ops  = [];
        foreach ( $spec['paths'] as $methods ) {
            foreach ( $methods as $op ) {
                $ops[] = $op['operationId'] ?? '';
            }
        }
        $this->assertCount( 15, $ops, 'dry_run is a flag, not a new operation' );
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `vendor/bin/phpunit tests/DryRunLockstepTest.php`
Expected: FAIL — the handlers contain no `dry_run` yet and the OpenAPI spec lacks the property/schemas.

- [ ] **Step 3: MCP — `update_page_layout` and `edit_page_content`**

In `wp-plugin/src/McpHandler.php`:

(a) Tool definitions. In the `update_page_layout` definition, append to the `description` string (at its very end, before the closing `'`): ` Pass dry_run=true to preview what would change (a diff plus the checker's verdict) without saving anything.` and add this property to its `inputSchema.properties` after `post_content`:

```php
                        'dry_run' => ['type' => 'boolean', 'description' => 'Optional. true = preview only: returns the checker verdict and a diff of what would change, and saves nothing.'],
```

In the `edit_page_content` definition append to its `description` (end of string): ` Pass dry_run=true to preview the change (a diff plus the checker's verdict) without saving.` and add after `expect_count`:

```php
                        'dry_run' => ['type' => 'boolean', 'description' => 'Optional. true = preview only: returns the checker verdict and a diff of what would change, and saves nothing.'],
```

(Keep the single space in `'dry_run' => ['type' => 'boolean'`: the lockstep test counts that exact string, once per tool.)

(b) `toolUpdate()`. Replace

```php
        $pageId  = (int) ($args['page_id'] ?? 0);
        $content = PageAccess::stringArg($args, 'post_content');
        $post    = $pageId ? get_post($pageId) : null;

        if ($content === null) {
            return $this->rpcError($id, -32602, 'post_content must be a string.');
        }
```

with

```php
        $pageId  = (int) ($args['page_id'] ?? 0);
        $content = PageAccess::stringArg($args, 'post_content');
        $dryRun  = PageAccess::boolArg($args, 'dry_run');
        $post    = $pageId ? get_post($pageId) : null;

        if ($content === null) {
            return $this->rpcError($id, -32602, 'post_content must be a string.');
        }

        if ($dryRun === null) {
            return $this->rpcError($id, -32602, 'dry_run must be a boolean.');
        }
```

and replace

```php
        if ($content === '') {
            return $this->rpcError($id, -32602, 'post_content is required.');
        }

        $result = (new Validator())->validateContent($content);
```

with

```php
        if ($content === '') {
            return $this->rpcError($id, -32602, 'post_content is required.');
        }

        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $pageId, UsageTracker::RESULT_READ);

            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(DryRun::preview((string) $post->post_content, $content))]],
            ]);
        }

        $result = (new Validator())->validateContent($content);
```

(c) `toolEditContent()`. Replace

```php
        $expect  = (array_key_exists('expect_count', $args) && $args['expect_count'] !== null)
            ? (int) $args['expect_count']
            : null;
        $post = $pageId ? get_post($pageId) : null;
```

with

```php
        $expect  = (array_key_exists('expect_count', $args) && $args['expect_count'] !== null)
            ? (int) $args['expect_count']
            : null;
        $dryRun  = PageAccess::boolArg($args, 'dry_run');
        if ($dryRun === null) {
            return $this->rpcError($id, -32602, 'dry_run must be a boolean.');
        }
        $post = $pageId ? get_post($pageId) : null;
```

and insert, directly before the comment `// A find/replace still has to clear the deterministic validator — the` :

```php
        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $pageId, UsageTracker::RESULT_READ);

            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(DryRun::preview((string) $post->post_content, $edit['content']) + ['replaced' => $edit['count']])]],
            ]);
        }

```

- [ ] **Step 4: REST — `update_page` and `edit_page`**

In `wp-plugin/src/RestController.php`:

(a) `update_page()`: directly after the existing

```php
        if ($content === null || trim($content) === '') {
            return new WP_Error('missing_field', 'Request body must include a non-empty string "post_content".', ['status' => 400]);
        }
```

insert

```php

        $dryRun = PageAccess::boolArg($body, 'dry_run');
        if ($dryRun === null) {
            return new WP_Error('invalid_field', '"dry_run" must be a boolean.', ['status' => 400]);
        }
```

and replace

```php
        // Validate before saving — this is the safety gate
        $result = (new Validator())->validateContent($content);
```

with

```php
        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $id, UsageTracker::RESULT_READ);

            return new WP_REST_Response(DryRun::preview((string) $post->post_content, $content), 200);
        }

        // Validate before saving — this is the safety gate
        $result = (new Validator())->validateContent($content);
```

(b) `edit_page()`: replace

```php
        $expect = (isset($body['expect_count']) && $body['expect_count'] !== null && $body['expect_count'] !== '')
            ? (int) $body['expect_count']
            : null;

        $edit = PageEditor::apply($post->post_content, $find, $replace, $expect);
```

with

```php
        $expect = (isset($body['expect_count']) && $body['expect_count'] !== null && $body['expect_count'] !== '')
            ? (int) $body['expect_count']
            : null;

        $dryRun = PageAccess::boolArg($body, 'dry_run');
        if ($dryRun === null) {
            return new WP_Error('invalid_field', '"dry_run" must be a boolean.', ['status' => 400]);
        }

        $edit = PageEditor::apply($post->post_content, $find, $replace, $expect);
```

and insert, directly before the comment `// Same safety gate as update_page — the edited content must still validate.`:

```php
        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $id, UsageTracker::RESULT_READ);

            return new WP_REST_Response(DryRun::preview((string) $post->post_content, $edit['content']) + ['replaced' => $edit['count']], 200);
        }

```

- [ ] **Step 5: OpenAPI**

In `wp-plugin/src/OpenApiSpec.php`:

(a) Make the shared request body accept the flag. Replace the method `postContentBody()` with:

```php
    private static function postContentBody(bool $dryRun = false): array
    {
        $properties = ['post_content' => ['type' => 'string', 'description' => 'Divi 5 Gutenberg block HTML']];
        if ($dryRun) {
            $properties['dry_run'] = self::dryRunProperty();
        }

        return [
            'required' => true,
            'content'  => ['application/json' => ['schema' => [
                'type'       => 'object',
                'required'   => ['post_content'],
                'properties' => $properties,
            ]]],
        ];
    }

    /** @return array<string,mixed> */
    private static function dryRunProperty(): array
    {
        return ['type' => 'boolean', 'description' => 'Optional. true = preview only: returns the checker verdict and a diff of what would change, and saves nothing.'];
    }

    /** @return array<string,array<string,mixed>> the response properties a dry run adds to a save response */
    private static function dryRunResponseProperties(): array
    {
        return [
            'dry_run'    => ['type' => 'boolean', 'description' => 'true when this was a preview and nothing was saved'],
            'diff'       => ['$ref' => '#/components/schemas/DryRunDiff'],
            'diff_error' => ['type' => 'string', 'description' => 'Why no diff could be built (diff is then null); the verdict is still valid'],
            'message'    => ['type' => 'string'],
        ];
    }
```

(b) In the `updatePageLayout` operation: change `'requestBody' => self::postContentBody(),` (the one under `'operationId' => 'updatePageLayout'` only — leave the `validateLayout` one alone) to `'requestBody' => self::postContentBody(true),`; extend its `description` to `'Validates the submitted layout and saves it only if all checks pass. Returns 422 with violations if the layout is invalid — the page is NOT updated. Set dry_run to true to preview the changes without saving.'`; and in its `'200'` response `properties` add `] + self::dryRunResponseProperties(),` — i.e. change

```php
                                'properties' => [
                                    'saved'      => ['type' => 'boolean'],
                                    'valid'      => ['type' => 'boolean'],
                                    'history'    => ['$ref' => '#/components/schemas/SnapshotInfo'],
                                ],
```

to

```php
                                'properties' => [
                                    'saved'      => ['type' => 'boolean'],
                                    'valid'      => ['type' => 'boolean'],
                                    'history'    => ['$ref' => '#/components/schemas/SnapshotInfo'],
                                ] + self::dryRunResponseProperties(),
```

(c) In the `editPageContent` operation: add `'dry_run' => self::dryRunProperty(),` after the `expect_count` property of its request body, and extend the 200 response properties the same way:

```php
                                'properties' => [
                                    'saved'      => ['type' => 'boolean'],
                                    'replaced'   => ['type' => 'integer'],
                                    'history'    => ['$ref' => '#/components/schemas/SnapshotInfo'],
                                ] + self::dryRunResponseProperties(),
```

Do NOT lengthen `editPageContent`'s operation `description` (it is already close to the 300-character ChatGPT limit; the `dry_run` property description explains the flag).

(d) Add the three component schemas next to `HistoryEntry` (inside `components.schemas`):

```php
                    'DryRunAttr' => [
                        'type'       => 'object',
                        'properties' => [
                            'path'   => ['type' => 'string', 'description' => 'Attribute path, dot-separated'],
                            'before' => ['type' => ['string', 'null'], 'description' => 'Value now (null if absent), cut to 200 characters'],
                            'after'  => ['type' => ['string', 'null'], 'description' => 'Value after the change (null if removed), cut to 200 characters'],
                        ],
                    ],
                    'DryRunChange' => [
                        'type'       => 'object',
                        'properties' => [
                            'path'          => ['type' => 'string', 'description' => 'Where the block sits, e.g. divi/placeholder[0]/divi/section[0]/divi/heading[1]'],
                            'block'         => ['type' => 'string'],
                            'kind'          => ['type' => 'string', 'enum' => ['added', 'removed', 'changed']],
                            'attrs'         => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DryRunAttr'], 'description' => 'For changed blocks: the attributes that differ (at most 25)'],
                            'attrs_omitted' => ['type' => 'integer', 'description' => 'How many more differing attributes were left out'],
                        ],
                    ],
                    'DryRunDiff' => [
                        'type'       => 'object',
                        'properties' => [
                            'summary'   => [
                                'type'       => 'object',
                                'properties' => [
                                    'added'     => ['type' => 'integer'],
                                    'removed'   => ['type' => 'integer'],
                                    'changed'   => ['type' => 'integer'],
                                    'unchanged' => ['type' => 'integer'],
                                ],
                            ],
                            'changes'   => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DryRunChange'], 'description' => 'At most 50 changes'],
                            'truncated' => ['type' => 'boolean', 'description' => 'true when there were more than 50 changes; summary has the true totals'],
                        ],
                    ],
```

- [ ] **Step 6: Run the lockstep test, then the whole suite**

Run: `vendor/bin/phpunit tests/DryRunLockstepTest.php` → Expected: PASS.
Run: `vendor/bin/phpunit` → Expected: all green (this includes `OpenApiSpecTest`'s 300-character and no-bare-object checks, `HistoryLockstepTest`, `WpOrgComplianceTest`). If `OpenApiSpecTest` reports a description over 300 characters, shorten the sentence you added to that operation; never raise the limit.

- [ ] **Step 7: Commit**

```bash
git add wp-plugin/src/McpHandler.php wp-plugin/src/RestController.php wp-plugin/src/OpenApiSpec.php tests/DryRunLockstepTest.php
git commit -m "feat: dry_run preview on update/edit tools and routes (MCP, REST, OpenAPI)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Real-site check (`make verify-dry-run`)

**Files:**
- Create: `scripts/verify-dry-run.php`
- Modify: `Makefile`

**Interfaces:**
- Consumes: the finished plugin code (mounted into the Docker site), `rest_do_request()` against the real routes `/ai-editor-divi5/v1/pages/{id}` (PUT), `/ai-editor-divi5/v1/pages/{id}/edit` (POST) and `/ai-editor-divi5/v1/mcp` (POST JSON-RPC `tools/call`).
- Produces: `make verify-dry-run` (exit 0 = every check passed).

- [ ] **Step 1: Create `scripts/verify-dry-run.php`**

```php
<?php
/**
 * Real-site check for dry_run. The unit tests cover the diff and the wiring; this drives the REAL
 * REST and MCP handlers on the Docker site and proves a preview leaves the page, its post_modified
 * and its history rows untouched, that mistyped flags are rejected, and that a real save still works.
 *
 * Run: make verify-dry-run   (needs `make up` and the plugin active on the dev site)
 * It creates ONE throwaway draft page, exercises it, and deletes it again.
 */

use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\UsageTracker;
use Divi5Validator\Validator;

if ( ! class_exists( HistoryStore::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 on this site first.' );
}

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
$rest = static function ( string $method, string $route, array $body ): array {
    $req = new WP_REST_Request( $method, '/ai-editor-divi5/v1' . $route );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( $body ) );
    $res = rest_do_request( $req );

    return [ 'status' => $res->get_status(), 'data' => (array) $res->get_data() ];
};
$mcp = static function ( string $tool, array $args ): array {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] ) );
    $res  = rest_do_request( $req );
    $data = (array) $res->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => $data['error'], 'isError' => true, 'result' => null ];
    }
    $text = $data['result']['content'][0]['text'] ?? '';

    return [ 'error' => null, 'isError' => (bool) ( $data['result']['isError'] ?? false ), 'result' => json_decode( (string) $text, true ) ];
};

HistoryTable::ensureReady( true );
$check( ( new Validator() )->validateContent( $layout( 'x' ) )->isValid(), 'the fixture layout used by this script passes the checker' );

$pageId = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied dry-run check', 'post_content' => $layout( 'Original' ) ], true );
if ( is_wp_error( $pageId ) ) {
    WP_CLI::error( $pageId->get_error_message() );
}

try {
    $before = $state( $pageId );
    $check( 0 === $before['history'], 'the throwaway page starts with no history' );
    sleep( 1 ); // post_modified has one-second resolution: a real write would be visible

    // ---- 1. the four dry runs --------------------------------------------------------------
    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $layout( 'Changed' ), 'dry_run' => true ] );
    $check( 200 === $r['status'] && false === $r['data']['saved'] && true === $r['data']['dry_run'] && true === $r['data']['valid'], 'REST PUT dry_run: 200, saved false, dry_run true, valid true' );
    $check( 1 === ( $r['data']['diff']['summary']['changed'] ?? null ), 'REST PUT dry_run: the diff reports exactly one changed block' );
    $check( 'title.innerContent.desktop.value' === ( $r['data']['diff']['changes'][0]['attrs'][0]['path'] ?? null ) && 'Original' === ( $r['data']['diff']['changes'][0]['attrs'][0]['before'] ?? null ), 'REST PUT dry_run: the diff names the attribute and the old value' );

    $r = $rest( 'POST', "/pages/{$pageId}/edit", [ 'find' => 'Original', 'replace' => 'Edited', 'dry_run' => true ] );
    $check( 200 === $r['status'] && false === $r['data']['saved'] && 1 === ( $r['data']['replaced'] ?? null ) && 1 === ( $r['data']['diff']['summary']['changed'] ?? null ), 'REST edit dry_run: 200, saved false, replaced 1, one changed block' );

    $m = $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => $layout( 'Changed' ), 'dry_run' => true ] );
    $check( ! $m['isError'] && false === ( $m['result']['saved'] ?? null ) && true === ( $m['result']['dry_run'] ?? null ) && 1 === ( $m['result']['diff']['summary']['changed'] ?? null ), 'MCP update_page_layout dry_run: not an error, saved false, one changed block' );

    $m = $mcp( 'edit_page_content', [ 'page_id' => $pageId, 'find' => 'Original', 'replace' => 'Edited', 'dry_run' => true ] );
    $check( ! $m['isError'] && false === ( $m['result']['saved'] ?? null ) && 1 === ( $m['result']['replaced'] ?? null ), 'MCP edit_page_content dry_run: not an error, saved false, replaced 1' );

    // ---- 2. an invalid layout is a normal preview, never a save ------------------------------
    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => '<!-- wp:divi/placeholder --><!-- wp:divi/not-a-module /--><!-- /wp:divi/placeholder -->', 'dry_run' => true ] );
    $check( 200 === $r['status'] && false === $r['data']['valid'] && [] !== $r['data']['violations'] && false === $r['data']['saved'], 'REST dry_run of an invalid layout: 200, valid false, violations listed, saved false' );
    $m = $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => '<!-- wp:divi/placeholder --><!-- wp:divi/not-a-module /--><!-- /wp:divi/placeholder -->', 'dry_run' => true ] );
    $check( ! $m['isError'] && false === ( $m['result']['valid'] ?? null ), 'MCP dry_run of an invalid layout: isError false, valid false' );

    // ---- 3. failures of a real edit stay failures ---------------------------------------------
    $r = $rest( 'POST', "/pages/{$pageId}/edit", [ 'find' => 'does not exist anywhere', 'replace' => 'x', 'dry_run' => true ] );
    $check( 422 === $r['status'] && false === $r['data']['saved'], 'REST edit dry_run with a find that does not match: the same 422 as a real edit' );

    // ---- 4. mistyped dry_run is rejected, never read as false ----------------------------------
    foreach ( [ 'false', 'yes', 0, 1, [] ] as $bad ) {
        $label = json_encode( $bad );
        $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $layout( 'Changed' ), 'dry_run' => $bad ] );
        $check( 400 === $r['status'], "REST PUT with dry_run={$label} is rejected (400)" );
        $m = $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => $layout( 'Changed' ), 'dry_run' => $bad ] );
        $check( true === $m['isError'] && -32602 === ( $m['error']['code'] ?? null ), "MCP update_page_layout with dry_run={$label} is rejected (-32602)" );
    }

    // ---- 5. none of that touched the page --------------------------------------------------------
    $after = $state( $pageId );
    $check( $after['content'] === $before['content'], 'post_content is byte-identical after every dry run and rejected call' );
    $check( $after['modified'] === $before['modified'], 'post_modified is unchanged' );
    $check( $after['history'] === $before['history'], 'no history rows were written' );

    // ---- 6. previews are logged as dry_run / read --------------------------------------------------
    global $wpdb;
    $usage = UsageTracker::tableName();
    $n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$usage} WHERE endpoint = %s AND result = %s AND page_id = %d", 'dry_run', 'read', $pageId ) ); // phpcs:ignore
    $check( $n >= 7, "previews appear in the usage log as dry_run/read ({$n} rows)" );

    // ---- 7. a real save afterwards still works and snapshots -----------------------------------------
    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $layout( 'Changed' ) ] );
    $check( 200 === $r['status'] && true === $r['data']['saved'] && true === ( $r['data']['history']['stored'] ?? null ), 'a real save without dry_run still saves and snapshots' );
    $check( 1 === $rows( $pageId ) && get_post( $pageId )->post_content === $layout( 'Changed' ), 'the real save wrote the content and exactly one history row' );
} catch ( \Throwable $e ) {
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    if ( null !== get_post( $pageId ) ) {
        wp_delete_post( $pageId, true );
    }
}

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'dry_run verified on the real REST and MCP handlers.' );
```

If a route refuses a plain logged-in user (the script runs as `--user=1`), authenticate with the plugin API key instead: read `src/ApiKey.php` for how `ApiKey::authenticateRequest()` reads the Bearer header and set `$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . <the key>` before the calls. If `divi/heading` inside that column is not accepted by the validator (the first check fails), swap the `$layout` fixture for a layout copied from `fixtures/valid/` that contains one editable text value, keeping the checks' intent unchanged.

- [ ] **Step 2: Add the Makefile target**

In `Makefile` add `verify-dry-run` to the `.PHONY` list on line 1 and append after the `verify-history` target:

```make

# ---------------------------------------------------------------
# verify-dry-run — real-site check of dry_run on the REST and MCP handlers (Docker site)
# ---------------------------------------------------------------
verify-dry-run:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-dry-run.php --user=1
```

(the recipe line starts with a TAB.)

- [ ] **Step 3: Run it against the real site**

Run: `make verify-dry-run`
Expected: every line `PASS`, ending `Success: dry_run verified on the real REST and MCP handlers.` Exit 0.

If a check fails it is a real defect (or a wrong assumption in the script): read the actual failure, find the cause, fix the smallest thing that is wrong. A defect in plugin code gets a unit/lockstep test where the unit tests can reproduce it, then `vendor/bin/phpunit` green. Never silence a check.

Afterwards confirm no leftover page: `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp post list --post_type=page --s="aied dry-run check" --format=count` → `0`.

- [ ] **Step 4: Run it a second time, then commit**

Run `make verify-dry-run` again → Expected: PASS again.

```bash
git add scripts/verify-dry-run.php Makefile
git commit -m "test: real-site verification of dry_run (make verify-dry-run)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Guides, readme, version 4.2.0, zip, Plugin Check

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php`, `wp-plugin/src/SiteGuide.php`, `wp-plugin/readme.txt`, `wp-plugin/jhmg-ai-editor-for-divi-5.php`, `tests/WpOrgNamingTest.php`, `CLAUDE.md`
- Regenerate: `jhmg-ai-editor-for-divi-5.zip`

**Interfaces:** consumes everything above; produces the 4.2.0 branch ready for review (no merge in this task).

- [ ] **Step 1: Guides**

In `wp-plugin/src/StyleGuide.php`, directly after the paragraph that ends `` `edit_page_content` edits over rewriting a `` / `whole page.` (the end of the "## Undo" section, immediately before the line `MD;`), add:

```

## Preview before saving
update_page_layout and edit_page_content accept `dry_run: true`. The call then runs the
same checks as a real save but saves nothing: it returns whether the result would pass
the checker plus a diff of what would change (blocks added, removed or changed, with the
before and after value of each changed setting, at most 50 changes). Use it for a big
rewrite or any edit you are not sure about, confirm that only the intended blocks
changed, then repeat the same call without `dry_run` to save.
```

In `wp-plugin/src/SiteGuide.php`, directly after the bullet that ends `whole page.` (the end of the "- Undo:" bullet, immediately before `MD;`), add:

```
- Preview: update_page_layout and edit_page_content accept `dry_run: true` to return the
  checker's verdict and a diff of what would change without saving anything. Preview big
  or risky edits first, then repeat the call without `dry_run` to save.
```

- [ ] **Step 2: Version, readme, naming-test pins**

In `wp-plugin/jhmg-ai-editor-for-divi-5.php`: ` * Version:           4.1.0` → ` * Version:           4.2.0`; `define('AI_EDITOR_DIVI5_VERSION', '4.1.0');` → `define('AI_EDITOR_DIVI5_VERSION', '4.2.0');`.

In `wp-plugin/readme.txt`:
- `Stable tag:        4.1.0` → `Stable tag:        4.2.0`.
- In `= What makes it smart =`, insert after the `**Small changes stay small.**` bullet:

```
* **Preview before saving.** Your AI can ask what a change would do before it saves anything. It gets a list of the blocks and settings that would change, and the checker's verdict. Nothing is saved until it repeats the request for real.
```

- In the tools list, change the two bullets to:

```
* `edit_page_content`: change one piece of text (an email, phone number, price or sentence) without rebuilding the page. Add `dry_run` to preview the change without saving it.
* `update_page_layout`: save a new layout for a page, after the checker approves it. Add `dry_run` to preview what would change without saving it.
```

- Under `== Changelog ==`, insert before `= 4.1.0 =`:

```
= 4.2.0 =
* New: preview before saving. `update_page_layout` and `edit_page_content` accept `dry_run`: they return the checker's verdict and a short list of the blocks and settings that would change, and save nothing.
```

- Under `== Upgrade Notice ==`, insert before `= 4.1.0 =`:

```
= 4.2.0 =
Adds an optional preview (`dry_run`) to the two tools that save pages. Nothing changes unless your AI assistant uses it.
```

In `tests/WpOrgNamingTest.php` update the three version pins from `4.1.0` to `4.2.0` (the plugin-header `Version` regex, the `AI_EDITOR_DIVI5_VERSION` constant string and the readme `Stable tag` regex). Update only the version number in those pins; do not loosen them.

- [ ] **Step 3: CLAUDE.md**

(a) After the "Undo (v3.5.0, table storage since 4.1.0)" paragraph add:

```
Dry run (v4.2.0): `update_page_layout`, `edit_page_content`, `PUT /pages/{id}` and
`POST /pages/{id}/edit` accept a strict boolean `dry_run` (a non-boolean is rejected 400 /
-32602, never read as false). It runs the same checks, then returns `DryRun::preview()`
(validator verdict + `LayoutDiff` diff: max 50 changes, 25 attribute diffs per block, values
cut to 200 characters, `builderVersion` ignored) BEFORE `HistoryService::write()` is reachable;
the page, its history and `post_modified` are never touched. A preview of an invalid layout
is a normal 200 / `isError: false` response carrying `valid: false`. Logged as endpoint
`dry_run`, result `read`. It is a flag, not a tool: the list stays at 15 tools / 15 operations.
```

(b) In the "Entry points" table add after the `make verify-history` row:

```
| `make verify-dry-run` | Real-site check of `dry_run` on the REST and MCP handlers (creates and deletes one throwaway draft page) |
```

(c) In "Current state" add a bullet at the top:

```
- **4.2.0 = preview-before-save release** (branch `feat/dry-run-diff`): `dry_run` on the two write
  tools/routes with a capped structured diff; spec `docs/superpowers/specs/2026-10-01-dry-run-diff-design.md`.
  Not uploaded; ships in one upload together with 4.1.0 after WordPress.org answers the 4.0.0 review.
```

- [ ] **Step 4: Full test run**

Run: `vendor/bin/phpunit`
Expected: all green, no warnings or risky tests. (The readme and guide text must pass `WpOrgComplianceTest`; if it flags a word, reword the text — never edit the test.)

- [ ] **Step 5: Rebuild the zip and verify it**

Run: `bash scripts/build-plugin-zip.sh`
Expected: `[build] Packaging jhmg-ai-editor-for-divi-5 version 4.2.0`. Then:

```bash
S=$(mktemp -d) && unzip -q jhmg-ai-editor-for-divi-5.zip -d $S && diff -r $S/jhmg-ai-editor-for-divi-5 wp-plugin -x .DS_Store && echo "ZIP == wp-plugin"; rm -rf $S
unzip -l jhmg-ai-editor-for-divi-5.zip | grep -E "LayoutDiff|DryRun"
```

Expected: `ZIP == wp-plugin` and both new classes listed.

- [ ] **Step 6: Plugin Check — dev copy and extracted zip**

```bash
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin check jhmg-ai-editor-for-divi-5 --format=table
```
Expected: `Success: Checks complete. No errors found.`

```bash
SCRATCH="/private/tmp/claude-501/-Users-Lucas-Documents-JHMG-Local-Divi-5-Deterministic-Validator/7b64f78d-b27d-45c7-bebc-c50841ece09a/scratchpad"
rm -rf "$SCRATCH/zipcheck" && mkdir -p "$SCRATCH/zipcheck"
unzip -q jhmg-ai-editor-for-divi-5.zip -d "$SCRATCH/zipcheck"
docker compose cp "$SCRATCH/zipcheck/jhmg-ai-editor-for-divi-5" wordpress:/var/www/html/wp-content/plugins/jhmg-ai-editor-for-divi-5-zipcheck
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin check jhmg-ai-editor-for-divi-5-zipcheck --slug=jhmg-ai-editor-for-divi-5 --format=table
docker compose exec -T -u root wordpress rm -rf /var/www/html/wp-content/plugins/jhmg-ai-editor-for-divi-5-zipcheck
```
Expected: `Success: Checks complete. No errors found.` for both. Do NOT activate the zip copy and NEVER use `wp plugin delete`. Fix any warning with code changes (these new classes make no DB calls, so no `phpcs` ignores should be needed), then re-run phpunit, rebuild the zip and re-check.

- [ ] **Step 7: Re-run the real-site checks against the final code**

Run: `make verify-dry-run` and `make verify-history` → Expected: all PASS.

- [ ] **Step 8: Commit on the branch (no merge)**

```bash
git add wp-plugin tests CLAUDE.md jhmg-ai-editor-for-divi-5.zip
git commit -m "release: 4.2.0 — preview before save (dry_run) on the two write tools

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

Do NOT checkout `main` or merge in this task; the controller merges after the whole-branch review.

---

## Self-Review

**1. Spec coverage**

| Spec requirement | Task |
|---|---|
| `dry_run` flag on `update_page_layout`/`edit_page_content` (MCP) and `PUT /pages/{id}`/`POST /pages/{id}/edit` (REST) | 2 |
| Strict boolean; non-boolean rejected -32602 / 400 | 1 (`boolArg`), 2, 3 |
| Same checks as a real write, then return before `HistoryService::write`; page/history/meta/`post_modified` untouched | 2 (structural guard), 3 (real site) |
| Response shape `saved:false, dry_run:true, valid, violations, diff, message`; `replaced` for edits; `diff:null`+`diff_error` | 1 (`DryRun`), 2 |
| Successful call even when `valid` is false (200 / `isError:false`); real errors unchanged | 2, 3 |
| Usage log `dry_run` / `read` | 2, 3 |
| `LayoutDiff` in `wp-plugin/src`, bundled `BlockParser`, index-based walk, 50-change cap, 200-char values, `builderVersion` ignored, unparseable → null | 1 |
| OpenAPI request property + response schema, ≤300-char descriptions, no bare objects | 2 |
| Tool descriptions + Style/Site guides, readme, CLAUDE.md | 2, 4 |
| 15 tools / 15 operations unchanged | 2 (lockstep), 4 |
| Version 4.2.0, zip, Plugin Check clean x2 | 4 |
| Real-site check of both tools leaving post/history untouched and a real write still working | 3 |
| Spec refinement: per-block attribute cap | 1 (disclosed at the top) |

Gaps: none.

**2. Placeholder scan:** none (no TBD/TODO; every step has code or an exact command with expected output).

**3. Type consistency:** `PageAccess::boolArg(array,string): ?bool` (Task 1) is the call used in Task 2 handlers and pinned by the lockstep test; `DryRun::preview(string,string): array`, `DryRun::ENDPOINT` match their uses; `LayoutDiff::compare()` return keys (`diff`, `diff_error`) match `DryRun::preview()`'s key order test and the OpenAPI `diff`/`diff_error` properties; `attrs_omitted`/`attrs`/`path`/`before`/`after` names match between `LayoutDiff`, the tests and the `DryRunChange`/`DryRunAttr` schemas.

**4. Review Focus:** items 1 and 5 → `DryRunLockstepTest` (structural) + `verify-dry-run.php`; item 2 → `DryRunTest::testBoolArgRejectsEveryNonBoolean`, the lockstep `if ($dryRun === null)` guard, `verify-dry-run.php` section 4; item 3 → `LayoutDiffTest` (cap, per-block cap, value cut); item 4 → `LayoutDiffTest::testUnparseable…`, `DryRunTest::testPreviewWhenTheCurrentPageDoesNotParse…`.
