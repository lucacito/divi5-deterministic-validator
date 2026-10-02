# Global Styles (4.4.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an AI assistant reuse the site's own Divi 5 presets and global colors: a read-only `list_global_styles` tool, plus a non-blocking advisory when a layout cites a preset or color id the site does not have.

**Architecture:** Two new pure, read-only classes in `wp-plugin/src/`. `GlobalStyles` reads the site's presets, colors and variables through Divi's own public read functions, each guarded (a missing or throwing reader means an empty section plus a note). `StyleReferences` walks a layout with the bundled validator's `BlockParser` and reports ids the site does not have. The tool is added to MCP, REST and OpenAPI in lockstep; the advisory is attached to the layout tools' responses only when non-empty. The validator in `src/` is untouched.

**Tech Stack:** PHP 8.1+, PHPUnit 11 (`./vendor/bin/phpunit`), WordPress REST API shims in `tests/`, Docker dev site (Divi 5.14) for the real-site check, Plugin Check 2.0.0.

**Spec:** `docs/superpowers/specs/2026-10-01-global-styles-design.md` (approved by the owner on 2026-10-01). Read it first: this plan argues from it.

**Working branch:** `feat/global-styles` (already created; the spec is committed on it). Work from the repository root. Local commits only.

## How this plan was checked

Every task's code was replayed in a scratch git repository starting from this branch's tree: each task's tests were red before its implementation and the full suite was green after it (baseline `OK (519 tests, 3635 assertions)`; the counts are printed at the end of each task). The final tree also passed Plugin Check (as an inactive copy with `--slug`) and the real-site check in Task 7 against the Docker site's real Divi data (25 of 25 checks). The patches below are the exact diffs between those measured states, and each one was re-applied onto the previous state to prove it applies cleanly.

## Global Constraints

- **Read-only.** The free plugin never creates, edits, deletes, imports or exports presets, global colors or variables, and never writes the options behind them. `tests/WpOrgComplianceTest.php` guards this (Task 6) and the existing guards stay as they are. Never weaken a compliance test to make it pass.
- **No licence-gated, remote-fetching or custom-code-saving code in `wp-plugin/`.** The compliance scanner rejects the words `upgrade`, `premium`, `Pro` (as a whole word) and `licence key` in any plugin text file, comments included. The readme may not say `menu` or `front page` outside its one pinned FAQ sentence, and keeps exactly one sentence about the separate add-on.
- **The validator is untouched and deterministic.** Do not edit `src/` or `wp-plugin/validator/`; an unknown preset or color id is NOT a violation (Divi renders the layout).
- **Read Divi only through its own public read functions**, behind `is_callable`: `GlobalPreset::get_data()`, `GlobalData::get_global_colors()`, `GlobalData::get_global_variables()`. Never read the option names directly in plugin code.
- **Caps (verbatim from the spec):** at most 300 presets, 200 colors and 100 variables per type; every value cut to 200 characters; at most 20 ids per advisory list; a value that is not valid UTF-8 is replaced, never returned.
- **Authorisation:** the plugin-wide `PageAccess::CAP` (`edit_pages`), not Divi's stricter `edit_theme_options`. String arguments are type-checked (non-strings are rejected 400 / -32602, never cast).
- **Advisory rules:** only when non-empty; never changes `valid`; never blocks a save; never rewrites content; a refused save carries violations only; a clean layout's response stays byte-identical to today's.
- **Lockstep:** one tool means MCP (`McpHandler`), REST (`RestController`) and OpenAPI (`OpenApiSpec`) together. After this release: 17 MCP tools and 17 OpenAPI operations (the cap is 30). OpenAPI operation descriptions must be at most 300 characters and no response object schema may be a bare `type: object`.
- **Version:** `4.4.0` in the plugin header, `AI_EDITOR_DIVI5_VERSION` and the readme's Stable tag (Task 6). New release also means a rebuilt zip and Plugin Check clean on the dev copy and the extracted zip (Task 7).
- **Never push, upload or send anything.** Local commits on `feat/global-styles` only. Commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- **Dev environment:** the container wp-cli needs `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp ...`. The MCP route accepts only the plugin API key as a Bearer header. Never run `wp plugin delete` on anything (it runs `uninstall.php` against the shared dev database); remove test copies with `rm -rf` as in Task 7.
- **Tool-parameter quirk (do not skip).** The tools you type code with (Write, Edit, Bash) decode a backslash followed by `u` and four hex digits into the character it names. The literal backslash-`u0022` therefore cannot be typed. The plan avoids it everywhere except the guide text, where the diff uses the placeholder `<Q>` and Task 5 has an explicit step that replaces it. Do not "fix" `<Q>` by hand.

## How to apply the code in this plan

- **New files** are shown whole: create the file with exactly that content.
- **Modified files** are shown as unified diffs. Save a diff verbatim to a temporary file outside the repo (for example `/tmp/aied-patch.diff`), then run `git apply --ignore-whitespace --check /tmp/aied-patch.diff && git apply --ignore-whitespace /tmp/aied-patch.diff`, then delete the temporary file. If a diff does not apply, STOP and report it; do not improvise a different edit.
- Run every command from the repository root. Expected outputs are measured; a different number of tests means something is off.

## Review Focus

The inputs and conditions most likely to bite a person using this feature that no happy-path test would catch. Each line names the test that pins it, in the task that owns the code.

1. **Divi returns data that is not shaped as documented** (a stray `"0"` module entry exists on the real dev site, items that are not arrays, variables as objects): it is skipped, never a fatal error. `GlobalStylesTest::testStrayEntriesAreSkippedNeverAnError`, `testVariablesAcceptObjectsAndSkipInactiveAndEmptyTypes` (Task 1).
2. **A preset name, color value or cited id that is very long or invalid UTF-8** must not make the whole JSON response fail to encode. `GlobalStylesTest::testLongAndInvalidUtf8ValuesNeverBreakJsonEncoding` (Task 1), `StyleReferencesTest::testAtMostTwentyIdsPerListAndLongIdsAreCut` (Task 2).
3. **Divi's classes are missing or throw** (Divi 4, another theme, a future Divi change): the tool answers with notes instead of an error, and the layout tools still work with no advisory. `GlobalStylesLockstepTest::testWithoutDiviTheToolAnswersWithNotesNotAnError` (Task 3), `StyleAdvisoryHandlersTest::testWithoutDiviDataEverythingWorksAndNothingIsFlagged` (Task 4).
4. **A layout that cannot be parsed, or is refused by the checker**, must never be broken further by the advisory, and a clean layout's response must stay byte-identical for existing clients. `StyleReferencesTest::testUnparseableContentGivesNullAndNeverThrows`, `testAttachLeavesAPayloadUntouchedWhenCleanAndAddsTheAdvisoryWhenNot` (Task 2), `StyleAdvisoryHandlersTest::testARefusedSaveReportsViolationsOnlyNoAdvisory` (Task 4).
5. **A site with no presets, colors or variables, or an unknown `module` filter** gets a clear note, not a silent empty result. `GlobalStylesTest::testModuleFilterAndEmptyNotes` (Task 1).

## File Structure

| File | Responsibility |
|---|---|
| `wp-plugin/src/GlobalStyles.php` (new) | The read-only catalog and the known-id sets; the only code that touches Divi's three read functions |
| `wp-plugin/src/StyleReferences.php` (new) | Finds unknown preset/color ids in a layout; builds and attaches the advisory |
| `wp-plugin/src/McpHandler.php`, `RestController.php`, `OpenApiSpec.php` | The tool in three transports; the advisory attached to six responses; the schemas |
| `wp-plugin/src/StyleGuide.php`, `SectionRecipes.php` | What the AI is taught about site styles |
| `wp-plugin/readme.txt`, plugin header, `AdminPage.php` | Release identity and the user-facing description |
| `tests/DiviGlobalDataShims.php`, `WpInsertPostShims.php` (new) | Test stand-ins for Divi's read functions and `wp_insert_post` |
| `tests/GlobalStylesTest.php`, `StyleReferencesTest.php`, `GlobalStylesLockstepTest.php`, `StyleAdvisoryHandlersTest.php` (new) | Unit, lockstep and handler-level proof |
| `scripts/verify-global-styles.php`, `Makefile` | Real-site proof on the Docker site |

## Rulings made while planning (the owner can overturn any of these)

1. A refused save or create carries violations only, no advisory (the advisory goes on `validate_layout`, previews, successful saves and `create_page`).
2. A preset's `renders` is a list of `key=value` strings (for example `headingLevel=h3`) rather than a prose sentence: deterministic and cheap.
3. A preset id counts as known if it exists under ANY module (as the spec says); a color id counts as known if it exists at all, active or not.
4. The Divi theme's own font variables (`--et_global_heading_font`, `--et_global_body_font`) appear in the variables list under `fonts`, because Divi's read function returns them; they are listed for information only like every non-color variable.
5. The `get_section_recipes {"name": ...}` markup output is unchanged; the demo-site warning lives in the catalog text and the guide, and the advisory catches anything that slips through at save time.
6. The REST `validate` endpoint adds the advisory only for the `post_content` form, not the full layout-envelope form.

---


### Task 1: GlobalStyles: the read-only catalog

**Files:**
- Create: `wp-plugin/src/GlobalStyles.php`
- Create: `tests/DiviGlobalDataShims.php` (stand-ins for Divi's three read functions, used by several later tests)
- Create: `tests/GlobalStylesTest.php`
- Modify: `wp-plugin/src/autoload.php` (one `require_once`)

**Interfaces:**
- Consumes: nothing from earlier tasks. At runtime it calls Divi's own read functions `ET\Builder\Packages\GlobalData\GlobalPreset::get_data()` and `ET\Builder\Packages\GlobalData\GlobalData::get_global_colors()` / `get_global_variables()`, only when `is_callable`.
- Produces (later tasks rely on these exact names):
  - `GlobalStyles::catalog( ?string $module = null ): array` returning `{presets, colors, variables, counts, truncated, notes}`
  - `GlobalStyles::catalogWith( ?callable $presets, ?callable $colors, ?callable $variables, ?string $module = null ): array` (pure seam)
  - `GlobalStyles::knownIds(): array{presets: ?array<string,true>, colors: ?array<string,true>}` and `GlobalStyles::knownIdsWith( ?callable $presets, ?callable $colors ): array` (null = Divi cannot be read)
  - `GlobalStyles::readers(): array{0:?callable,1:?callable,2:?callable}`
  - constants `MAX_PRESETS` (300), `MAX_COLORS` (200), `MAX_VARIABLES` (100), `MAX_VALUE_CHARS` (200)
  - test helper: `$GLOBALS['__divi_presets']`, `['__divi_colors']`, `['__divi_variables']` drive the shim classes; setting one to `null` makes that Divi method throw.

- [ ] **Step 1: Write the failing tests**

**Create** `tests/DiviGlobalDataShims.php` with exactly this content:

```php
<?php

declare(strict_types=1);

/**
 * Stand-ins for the three Divi 5 read functions behind GlobalStyles, driven by
 *   $GLOBALS['__divi_presets']   the presets data  ({module: {<name>: {default, items}}}), or null = the method throws
 *   $GLOBALS['__divi_colors']    the global colors (id => {color, label, status, folder}), or null = the method throws
 *   $GLOBALS['__divi_variables'] the global variables (type => {id => {...}}), or null = the method throws
 * Required only by tests that need them; guarded like every other shim.
 */

namespace ET\Builder\Packages\GlobalData {
    if ( ! class_exists( GlobalPreset::class ) ) {
        class GlobalPreset {
            public static function get_data(): array {
                if ( ! array_key_exists( '__divi_presets', $GLOBALS ) ) {
                    return [];
                }
                if ( null === $GLOBALS['__divi_presets'] ) {
                    throw new \RuntimeException( 'presets unavailable' );
                }

                return $GLOBALS['__divi_presets'];
            }
        }
    }
    if ( ! class_exists( GlobalData::class ) ) {
        class GlobalData {
            public static function get_global_colors(): array {
                if ( ! array_key_exists( '__divi_colors', $GLOBALS ) ) {
                    return [];
                }
                if ( null === $GLOBALS['__divi_colors'] ) {
                    throw new \RuntimeException( 'colors unavailable' );
                }

                return $GLOBALS['__divi_colors'];
            }

            public static function get_global_variables(): array {
                if ( ! array_key_exists( '__divi_variables', $GLOBALS ) ) {
                    return [];
                }
                if ( null === $GLOBALS['__divi_variables'] ) {
                    throw new \RuntimeException( 'variables unavailable' );
                }

                return $GLOBALS['__divi_variables'];
            }
        }
    }
}```

**Create** `tests/GlobalStylesTest.php` with exactly this content:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\GlobalStyles;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DiviGlobalDataShims.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';

class GlobalStylesTest extends TestCase
{
    private const H1 = '1e32dbff-cc61-4cda-b68a-39243c406d98';
    private const H3 = 'b5bdfe4f-ac6a-4338-974b-680f73f018f9';

    /** A trimmed copy of the real shape found on the dev site, including its stray "0" entry. */
    private function presets(): array
    {
        return [ 'module' => [
            'divi/heading' => [ 'default' => self::H1, 'items' => [
                self::H3 => [ 'id' => self::H3, 'moduleName' => 'divi/heading', 'name' => 'Heading 3',
                    'attrs' => [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '30px' ] ] ] ] ] ] ],
                    'renderAttrs' => [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'headingLevel' => 'h3' ] ] ] ] ] ] ] ],
                self::H1 => [ 'id' => self::H1, 'moduleName' => 'divi/heading', 'name' => 'Heading 1',
                    'attrs' => [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '72px' ] ] ] ] ] ], 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [] ] ] ] ] ],
            ] ],
            'divi/button' => [ 'default' => 'b-1', 'items' => [ 'b-1' => [ 'id' => 'b-1', 'name' => 'Primary', 'attrs' => [] ] ] ],
            '0'           => [ 'items' => [ [ 'id' => 's2s-test-1', 'name' => 'S2S Test Preset' ] ] ],
        ] ];
    }

    private function colors(): array
    {
        return [
            'gcid-3e93bdc5' => [ 'color' => '#ff4d00', 'label' => '', 'status' => 'active', 'folder' => '' ],
            'gcid-primary-color' => [ 'color' => '#2ea3f2', 'label' => 'Primary Color', 'status' => 'active', 'folder' => 'customizer' ],
            'gcid-old' => [ 'color' => '#000000', 'label' => 'Old', 'status' => 'inactive', 'folder' => '' ],
        ];
    }

    private function catalog( ?array $p = null, ?array $c = null, ?array $v = null, ?string $module = null ): array
    {
        return GlobalStyles::catalogWith(
            null === $p ? null : fn () => $p,
            null === $c ? null : fn () => $c,
            null === $v ? null : fn () => $v,
            $module
        );
    }

    public function testListsPresetsSortedWithDefaultFlagAndSummaries(): void
    {
        $cat = $this->catalog( $this->presets(), [], [] );
        $this->assertSame( [ 'Primary', 'Heading 1', 'Heading 3' ], array_column( $cat['presets'], 'name' ) );
        $h3 = $cat['presets'][2];
        $this->assertSame( [ 'id' => self::H3, 'module' => 'divi/heading', 'name' => 'Heading 3', 'default' => false, 'controls' => [ 'title.decoration.font' ], 'renders' => [ 'headingLevel=h3' ] ], $h3 );
        $this->assertTrue( $cat['presets'][1]['default'] );
        $this->assertSame( [ 'module.decoration.spacing', 'title.decoration.font' ], $cat['presets'][1]['controls'] );
        $this->assertSame( 3, $cat['counts']['presets'] );
    }

    public function testStrayEntriesAreSkippedNeverAnError(): void
    {
        $data = $this->presets();
        $data['module']['divi/text'] = 'not an array';
        $data['module']['divi/blurb'] = [ 'items' => 'nope' ];
        $data['module']['divi/cta'] = [ 'default' => 5, 'items' => [ 'x' => 'str', 'y' => [ 'id' => 7, 'name' => 'n' ], 'z' => [ 'id' => 'z-1' ] ] ];
        $cat = $this->catalog( $data, [], [] );
        $this->assertCount( 3, $cat['presets'] );
    }

    public function testModuleFilterAndEmptyNotes(): void
    {
        $cat = $this->catalog( $this->presets(), [], [], 'divi/button' );
        $this->assertSame( [ 'Primary' ], array_column( $cat['presets'], 'name' ) );
        $none = $this->catalog( $this->presets(), [], [], 'divi/nope' );
        $this->assertSame( [], $none['presets'] );
        $this->assertContains( "This site has no presets for the module 'divi/nope'.", $none['notes'] );
        $empty = $this->catalog( [], [], [] );
        $this->assertContains( 'This site has no module presets yet.', $empty['notes'] );
    }

    public function testColorsAreActiveOnlyThemeFirstAndLabelled(): void
    {
        $cat = $this->catalog( [], $this->colors(), [] );
        $this->assertSame( [
            [ 'id' => 'gcid-primary-color', 'label' => 'Primary Color', 'color' => '#2ea3f2', 'source' => 'theme' ],
            [ 'id' => 'gcid-3e93bdc5', 'label' => '', 'color' => '#ff4d00', 'source' => 'custom' ],
        ], $cat['colors'] );
        $this->assertSame( 2, $cat['counts']['colors'] );
    }

    public function testVariablesAcceptObjectsAndSkipInactiveAndEmptyTypes(): void
    {
        $vars = [
            'numbers' => (object) [ 'gvid-1' => [ 'label' => 'Radius', 'value' => '12px', 'status' => 'active' ], 'gvid-2' => [ 'label' => 'Gone', 'value' => '1', 'status' => 'archived' ] ],
            'strings' => (object) [],
            'fonts'   => [ '--et_global_body_font' => [ 'id' => '--et_global_body_font', 'label' => 'Body', 'value' => 'Open Sans', 'status' => 'active' ] ],
        ];
        $cat = $this->catalog( [], [], $vars );
        $this->assertSame( [ 'fonts', 'numbers' ], array_keys( $cat['variables'] ) );
        $this->assertSame( [ [ 'id' => 'gvid-1', 'label' => 'Radius', 'value' => '12px' ] ], $cat['variables']['numbers'] );
        $this->assertSame( 2, $cat['counts']['variables'] );
    }

    public function testUnavailableReadersGiveEmptySectionsAndNotes(): void
    {
        $cat = $this->catalog( null, null, null );
        $this->assertSame( [], $cat['presets'] );
        $this->assertSame( [], $cat['colors'] );
        $this->assertSame( [], $cat['variables'] );
        $this->assertContains( "Divi's preset data is not available on this site.", $cat['notes'] );
        $this->assertContains( "Divi's global colors are not available on this site.", $cat['notes'] );
        $this->assertContains( "Divi's global variables are not available on this site.", $cat['notes'] );
    }

    public function testAThrowingReaderIsTreatedAsUnavailable(): void
    {
        $cat = GlobalStyles::catalogWith( function () { throw new \RuntimeException( 'boom' ); }, fn () => $this->colors(), null );
        $this->assertSame( [], $cat['presets'] );
        $this->assertCount( 2, $cat['colors'] );
        $this->assertContains( "Divi's preset data is not available on this site.", $cat['notes'] );
    }

    public function testCapsMarkTruncationAndKeepTrueCounts(): void
    {
        $items = [];
        for ( $i = 0; $i < 305; $i++ ) {
            $items[ "p{$i}" ] = [ 'id' => "p{$i}", 'name' => sprintf( 'P%03d', $i ) ];
        }
        $cat = $this->catalog( [ 'module' => [ 'divi/text' => [ 'default' => 'p0', 'items' => $items ] ] ], [], [] );
        $this->assertCount( GlobalStyles::MAX_PRESETS, $cat['presets'] );
        $this->assertSame( 305, $cat['counts']['presets'] );
        $this->assertTrue( $cat['truncated'] );
        $this->assertStringContainsString( 'cut to their limit', implode( ' ', $cat['notes'] ) );
    }

    public function testLongAndInvalidUtf8ValuesNeverBreakJsonEncoding(): void
    {
        $long = str_repeat( 'é', 250 );
        $data = [ 'module' => [ 'divi/text' => [ 'default' => '', 'items' => [
            'a' => [ 'id' => 'a', 'name' => $long ],
            'b' => [ 'id' => 'b', 'name' => "bad\xB1utf8" ],
        ] ] ] ];
        $cat = $this->catalog( $data, [ 'gcid-x' => [ 'color' => "\xC3\x28", 'label' => 'x', 'status' => 'active' ] ], [] );
        $names = array_column( $cat['presets'], 'name' );
        $this->assertContains( str_repeat( 'é', 200 ) . '…', $names );
        $this->assertContains( '[value is not valid UTF-8]', $names );
        $this->assertSame( '[value is not valid UTF-8]', $cat['colors'][0]['color'] );
        $this->assertNotFalse( json_encode( $cat ) );
    }

    public function testAlwaysEndsWithTheReadOnlyNote(): void
    {
        $cat = $this->catalog( $this->presets(), $this->colors(), [] );
        $this->assertSame( "Read-only. To create or change presets, colors or variables, use Divi's Visual Builder.", end( $cat['notes'] ) );
        $this->assertFalse( $cat['truncated'] );
    }

    public function testKnownIdsCoverEveryPresetAndEveryColorIncludingInactive(): void
    {
        $known = GlobalStyles::knownIdsWith( fn () => $this->presets(), fn () => $this->colors() );
        $this->assertArrayHasKey( self::H1, $known['presets'] );
        $this->assertArrayHasKey( 'b-1', $known['presets'] );
        $this->assertArrayHasKey( 'gcid-old', $known['colors'] );
        $this->assertArrayHasKey( 'gcid-primary-color', $known['colors'] );
        $this->assertArrayNotHasKey( 'nope', $known['presets'] );
    }

    public function testKnownIdsAreNullForAnUnavailableReader(): void
    {
        $known = GlobalStyles::knownIdsWith( null, function () { throw new \RuntimeException( 'x' ); } );
        $this->assertSame( [ 'presets' => null, 'colors' => null ], $known );
    }

    public function testRealReadersPointAtDiviClassesOnlyWhenTheyExist(): void
    {
        $expect = static fn ( string $class, string $method ): ?array => is_callable( [ $class, $method ] ) ? [ $class, $method ] : null;
        $this->assertSame(
            [ $expect( GlobalStyles::PRESET_CLASS, 'get_data' ), $expect( GlobalStyles::DATA_CLASS, 'get_global_colors' ), $expect( GlobalStyles::DATA_CLASS, 'get_global_variables' ) ],
            GlobalStyles::readers()
        );
    }

    public function testCatalogAndKnownIdsReadThroughTheDiviClasses(): void
    {
        $GLOBALS['__divi_presets']   = $this->presets();
        $GLOBALS['__divi_colors']    = $this->colors();
        $GLOBALS['__divi_variables'] = [ 'numbers' => [ 'gvid-1' => [ 'label' => 'R', 'value' => '1px' ] ] ];
        try {
            $cat = GlobalStyles::catalog();
            $this->assertSame( 3, $cat['counts']['presets'] );
            $this->assertSame( 2, $cat['counts']['colors'] );
            $this->assertSame( 1, $cat['counts']['variables'] );
            $this->assertCount( 1, GlobalStyles::catalog( 'divi/button' )['presets'] );
            $this->assertArrayHasKey( self::H1, GlobalStyles::knownIds()['presets'] );

            $GLOBALS['__divi_presets'] = null; // the shim now throws
            $this->assertSame( [], GlobalStyles::catalog()['presets'] );
            $this->assertNull( GlobalStyles::knownIds()['presets'] );
            $this->assertIsArray( GlobalStyles::knownIds()['colors'] );
        } finally {
            unset( $GLOBALS['__divi_presets'], $GLOBALS['__divi_colors'], $GLOBALS['__divi_variables'] );
        }
    }
}```

- [ ] **Step 2: Run them and watch them fail**

Run: `./vendor/bin/phpunit tests/GlobalStylesTest.php`

Expected: FAIL. `wp-plugin/src/GlobalStyles.php` does not exist yet, so the test file cannot load it (PHP "Failed opening required") (measured: `PHP Warning:  require_once(…/work/tests/../wp-plugin/src/GlobalStyles.php): Failed to open stream: No such file or directory in …/work/tests/GlobalStylesTest.php on line 11`)

- [ ] **Step 3: Create the implementation**

**Create** `wp-plugin/src/GlobalStyles.php` with exactly this content:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A READ-ONLY view of the site's Divi 5 module presets, global colors and global variables.
 * Everything is read through Divi's own public read functions (never through option names), each
 * behind a guard: when Divi's class or method is missing, or throws, that part is simply empty.
 * Nothing here writes, creates or changes anything.
 */
final class GlobalStyles
{
    public const MAX_PRESETS    = 300;
    public const MAX_COLORS     = 200;
    public const MAX_VARIABLES  = 100;
    public const MAX_CONTROLS   = 12;
    public const MAX_RENDERS    = 5;
    public const MAX_VALUE_CHARS = 200;

    public const PRESET_CLASS = 'ET\\Builder\\Packages\\GlobalData\\GlobalPreset';
    public const DATA_CLASS   = 'ET\\Builder\\Packages\\GlobalData\\GlobalData';

    /** The `folder` Divi gives the five colors it generates from the theme's own settings. */
    private const THEME_FOLDER = 'customizer';

    /** Depth of the dotted attribute paths shown in a preset's `controls`. */
    private const CONTROL_DEPTH = 3;

    /** @return array{0: ?callable, 1: ?callable, 2: ?callable} presets, colors, variables readers */
    public static function readers(): array
    {
        $pick = static fn ( string $class, string $method ): ?callable => is_callable( [ $class, $method ] ) ? [ $class, $method ] : null;

        return [
            $pick( self::PRESET_CLASS, 'get_data' ),
            $pick( self::DATA_CLASS, 'get_global_colors' ),
            $pick( self::DATA_CLASS, 'get_global_variables' ),
        ];
    }

    /** What `list_global_styles` returns, read from the real Divi functions. @return array<string,mixed> */
    public static function catalog( ?string $module = null ): array
    {
        [ $presets, $colors, $variables ] = self::readers();

        return self::catalogWith( $presets, $colors, $variables, $module );
    }

    /**
     * @param ?string $module only presets of this module name (e.g. divi/button); null or '' = all
     * @return array{presets: list<array<string,mixed>>, colors: list<array<string,string>>, variables: array<string,list<array<string,string>>>, counts: array<string,int>, truncated: bool, notes: list<string>}
     */
    public static function catalogWith( ?callable $presets, ?callable $colors, ?callable $variables, ?string $module = null ): array
    {
        $module = ( null === $module || '' === $module ) ? null : $module;
        $notes  = [];

        $presetData = self::read( $presets );
        $colorData  = self::read( $colors );
        $varData    = self::read( $variables );

        $presetList = null === $presetData ? [] : self::presetList( $presetData, $module );
        $colorList  = null === $colorData ? [] : self::colorList( $colorData );
        $varLists   = null === $varData ? [] : self::variableLists( $varData );

        $varTotal  = array_sum( array_map( 'count', $varLists ) );
        $truncated = count( $presetList ) > self::MAX_PRESETS || count( $colorList ) > self::MAX_COLORS;
        foreach ( $varLists as $list ) {
            $truncated = $truncated || count( $list ) > self::MAX_VARIABLES;
        }
        $counts = [ 'presets' => count( $presetList ), 'colors' => count( $colorList ), 'variables' => $varTotal ];

        if ( null === $presetData ) {
            $notes[] = "Divi's preset data is not available on this site.";
        } elseif ( [] === $presetList ) {
            $notes[] = null === $module ? 'This site has no module presets yet.' : "This site has no presets for the module '{$module}'.";
        }
        if ( null === $colorData ) {
            $notes[] = "Divi's global colors are not available on this site.";
        }
        if ( null === $varData ) {
            $notes[] = "Divi's global variables are not available on this site.";
        }
        if ( $truncated ) {
            $notes[] = 'Some lists were cut to their limit; pass "module" to narrow the presets.';
        }
        $notes[] = "Read-only. To create or change presets, colors or variables, use Divi's Visual Builder.";

        return [
            'presets'   => array_slice( $presetList, 0, self::MAX_PRESETS ),
            'colors'    => array_slice( $colorList, 0, self::MAX_COLORS ),
            'variables' => array_map( static fn ( array $l ): array => array_slice( $l, 0, self::MAX_VARIABLES ), $varLists ),
            'counts'    => $counts,
            'truncated' => $truncated,
            'notes'     => $notes,
        ];
    }

    /**
     * Every preset id and global color id that exists on this site, uncapped, for checking a layout's
     * references. A part is null when Divi cannot be read, so the caller never flags what it cannot know.
     *
     * @return array{presets: ?array<string,true>, colors: ?array<string,true>}
     */
    public static function knownIds(): array
    {
        [ $presets, $colors ] = self::readers();

        return self::knownIdsWith( $presets, $colors );
    }

    /** @return array{presets: ?array<string,true>, colors: ?array<string,true>} */
    public static function knownIdsWith( ?callable $presets, ?callable $colors ): array
    {
        $presetData = self::read( $presets );
        $colorData  = self::read( $colors );

        $presetIds = null;
        if ( null !== $presetData ) {
            $presetIds = [];
            foreach ( self::presetEntries( $presetData ) as [ , , $items ] ) {
                foreach ( $items as $key => $item ) {
                    if ( is_array( $item ) && is_string( $item['id'] ?? null ) && '' !== $item['id'] ) {
                        $presetIds[ $item['id'] ] = true;
                    }
                    if ( is_string( $key ) && '' !== $key ) {
                        $presetIds[ $key ] = true;
                    }
                }
            }
        }

        $colorIds = null;
        if ( null !== $colorData ) {
            $colorIds = [];
            foreach ( array_keys( $colorData ) as $id ) {
                if ( is_string( $id ) && '' !== $id ) {
                    $colorIds[ $id ] = true;
                }
            }
        }

        return [ 'presets' => $presetIds, 'colors' => $colorIds ];
    }

    /** Calls a Divi reader; null when it is missing, throws, or does not return an array. @return ?array<mixed> */
    private static function read( ?callable $reader ): ?array
    {
        if ( null === $reader ) {
            return null;
        }
        try {
            $data = $reader();
        } catch ( \Throwable ) {
            return null;
        }

        return is_array( $data ) ? $data : null;
    }

    /**
     * The usable module entries of the presets data as [module name, default id, items]. Anything
     * that is not a string module name with an array of items (for example the stray "0" entry seen
     * on a real site) is skipped, never an error.
     *
     * @param array<mixed> $data
     * @return list<array{0: string, 1: string, 2: array<mixed>}>
     */
    private static function presetEntries( array $data ): array
    {
        $out = [];
        $mods = $data['module'] ?? null;
        if ( ! is_array( $mods ) ) {
            return $out;
        }
        foreach ( $mods as $name => $entry ) {
            if ( ! is_string( $name ) || ! is_array( $entry ) || ! is_array( $entry['items'] ?? null ) ) {
                continue;
            }
            $out[] = [ $name, is_string( $entry['default'] ?? null ) ? $entry['default'] : '', $entry['items'] ];
        }

        return $out;
    }

    /** @param array<mixed> $data @return list<array<string,mixed>> */
    private static function presetList( array $data, ?string $module ): array
    {
        $out = [];
        foreach ( self::presetEntries( $data ) as [ $name, $default, $items ] ) {
            if ( null !== $module && $module !== $name ) {
                continue;
            }
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) || ! is_string( $item['id'] ?? null ) || '' === $item['id'] || ! is_string( $item['name'] ?? null ) ) {
                    continue;
                }
                $out[] = [
                    'id'       => $item['id'],
                    'module'   => $name,
                    'name'     => self::clip( $item['name'] ),
                    'default'  => $item['id'] === $default,
                    'controls' => self::controls( $item['attrs'] ?? null ),
                    'renders'  => self::renders( $item['renderAttrs'] ?? null ),
                ];
            }
        }
        usort( $out, static fn ( array $a, array $b ): int => [ $a['module'], $a['name'], $a['id'] ] <=> [ $b['module'], $b['name'], $b['id'] ] );

        return $out;
    }

    /** The distinct attribute areas a preset sets, as short dotted paths (depth 3, at most 12). @return list<string> */
    private static function controls( mixed $attrs ): array
    {
        $paths = [];
        if ( is_array( $attrs ) ) {
            self::collectPaths( $attrs, '', 0, $paths );
        }
        $paths = array_values( array_unique( $paths ) );
        sort( $paths );

        return array_map( [ self::class, 'clip' ], array_slice( $paths, 0, self::MAX_CONTROLS ) );
    }

    /** @param array<mixed> $node @param list<string> $paths */
    private static function collectPaths( array $node, string $prefix, int $depth, array &$paths ): void
    {
        foreach ( $node as $key => $value ) {
            if ( ! is_string( $key ) ) {
                continue;
            }
            $path = '' === $prefix ? $key : $prefix . '.' . $key;
            if ( is_array( $value ) && [] !== $value && $depth + 1 < self::CONTROL_DEPTH ) {
                self::collectPaths( $value, $path, $depth + 1, $paths );
            } else {
                $paths[] = $path;
            }
        }
    }

    /** The scalar settings of a preset's renderAttrs as `key=value` (at most 5), e.g. headingLevel=h3. @return list<string> */
    private static function renders( mixed $renderAttrs ): array
    {
        $out = [];
        if ( is_array( $renderAttrs ) ) {
            $walk = static function ( array $node ) use ( &$walk, &$out ): void {
                foreach ( $node as $key => $value ) {
                    if ( is_array( $value ) ) {
                        $walk( $value );
                    } elseif ( is_string( $key ) && ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) ) ) {
                        $out[] = $key . '=' . ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value );
                    }
                }
            };
            $walk( $renderAttrs );
        }

        return array_map( [ self::class, 'clip' ], array_slice( array_values( array_unique( $out ) ), 0, self::MAX_RENDERS ) );
    }

    /** @param array<mixed> $data @return list<array<string,string>> */
    private static function colorList( array $data ): array
    {
        $out = [];
        foreach ( $data as $id => $color ) {
            if ( ! is_string( $id ) || ! is_array( $color ) || ! is_string( $color['color'] ?? null ) ) {
                continue;
            }
            if ( 'active' !== ( $color['status'] ?? 'active' ) ) {
                continue;
            }
            $out[] = [
                'id'     => $id,
                'label'  => self::clip( is_string( $color['label'] ?? null ) ? $color['label'] : '' ),
                'color'  => self::clip( $color['color'] ),
                'source' => self::THEME_FOLDER === ( $color['folder'] ?? '' ) ? 'theme' : 'custom',
            ];
        }
        usort( $out, static fn ( array $a, array $b ): int => [ 'theme' === $a['source'] ? 0 : 1, $a['label'], $a['id'] ] <=> [ 'theme' === $b['source'] ? 0 : 1, $b['label'], $b['id'] ] );

        return $out;
    }

    /** @param array<mixed> $data @return array<string,list<array<string,string>>> */
    private static function variableLists( array $data ): array
    {
        $out = [];
        foreach ( $data as $type => $items ) {
            $items = is_object( $items ) ? get_object_vars( $items ) : $items;
            if ( ! is_string( $type ) || ! is_array( $items ) ) {
                continue;
            }
            $list = [];
            foreach ( $items as $id => $item ) {
                if ( ! is_string( $id ) || ! is_array( $item ) || ! is_scalar( $item['value'] ?? null ) || 'active' !== ( $item['status'] ?? 'active' ) ) {
                    continue;
                }
                $list[] = [
                    'id'    => $id,
                    'label' => self::clip( is_string( $item['label'] ?? null ) ? $item['label'] : '' ),
                    'value' => self::clip( (string) $item['value'] ),
                ];
            }
            if ( [] !== $list ) {
                usort( $list, static fn ( array $a, array $b ): int => [ $a['label'], $a['id'] ] <=> [ $b['label'], $b['id'] ] );
                $out[ $type ] = $list;
            }
        }
        ksort( $out );

        return $out;
    }

    /** Cuts a value to MAX_VALUE_CHARS characters (not bytes); a value that is not valid UTF-8 is replaced, because it would make the whole JSON response fail. */
    private static function clip( string $v ): string
    {
        if ( 1 !== preg_match( '//u', $v ) ) {
            return '[value is not valid UTF-8]';
        }
        if ( strlen( $v ) <= self::MAX_VALUE_CHARS ) {
            return $v; // at most 200 bytes is at most 200 characters
        }
        $chars = preg_split( '//u', $v, self::MAX_VALUE_CHARS + 1, PREG_SPLIT_NO_EMPTY );
        if ( false === $chars || count( $chars ) <= self::MAX_VALUE_CHARS ) {
            return $v;
        }

        return implode( '', array_slice( $chars, 0, self::MAX_VALUE_CHARS ) ) . '…';
    }
}```

**Modify** (one diff per file; apply each with `git apply --ignore-whitespace`, see the note under Global Constraints):

`wp-plugin/src/autoload.php`

```diff
diff --git a/wp-plugin/src/autoload.php b/wp-plugin/src/autoload.php
index 46d823c..4b8b027 100644
--- a/wp-plugin/src/autoload.php
+++ b/wp-plugin/src/autoload.php
@@ -24,6 +24,7 @@ require_once __DIR__ . '/ImageGuide.php';
 require_once __DIR__ . '/UsageTracker.php';
 require_once __DIR__ . '/DiviCompat.php';
 require_once __DIR__ . '/ThemeLayouts.php';
+require_once __DIR__ . '/GlobalStyles.php';
 require_once __DIR__ . '/EditTarget.php';
 require_once __DIR__ . '/PageEditor.php';
 require_once __DIR__ . '/LayoutDiff.php';
```

- [ ] **Step 4: Run the whole suite**

Run: `./vendor/bin/phpunit`

Expected: `OK (533 tests, 3684 assertions)`

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: GlobalStyles, a read-only catalog of the site presets, colors and variables

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```


### Task 2: StyleReferences: the advisory finder

**Files:**
- Create: `wp-plugin/src/StyleReferences.php`
- Create: `tests/StyleReferencesTest.php`
- Modify: `wp-plugin/src/autoload.php` (one `require_once`)

**Interfaces:**
- Consumes: `GlobalStyles::knownIds()` (Task 1); `Divi5Validator\BlockParser` (the bundled validator, read-only use, exactly as `LayoutDiff` does).
- Produces:
  - `StyleReferences::advise( string $content, array $known ): ?array` where `$known` is `array{presets: ?array<string,true>, colors: ?array<string,true>}`; returns `null` when nothing is unknown, otherwise `{unknown_presets: list<{id,count}>, unknown_colors: list<{id,count}>, message}`
  - `StyleReferences::attach( array $payload, string $content ): array` adds `advisories.style_references` only when there is something to report; otherwise returns `$payload` unchanged
  - constants `StyleReferences::MESSAGE`, `StyleReferences::MAX_IDS` (20)

- [ ] **Step 1: Write the failing tests**

**Create** `tests/StyleReferencesTest.php` with exactly this content:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\StyleReferences;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DiviGlobalDataShims.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';

class StyleReferencesTest extends TestCase
{
    private const KNOWN_PRESET  = 'b5bdfe4f-ac6a-4338-974b-680f73f018f9';
    private const UNKNOWN       = '11111111-2222-4333-8444-555555555555';
    private const KNOWN_COLOR   = 'gcid-3e93bdc5-146d-448e-a761-9f1b4dd51113';
    private const UNKNOWN_COLOR = 'gcid-00000000-0000-4000-8000-000000000000';

    private function known(): array
    {
        return [ 'presets' => [ self::KNOWN_PRESET => true ], 'colors' => [ self::KNOWN_COLOR => true, 'gcid-inactive' => true ] ];
    }

    private function heading( array $extra ): string
    {
        $attrs = $extra + [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Hi' ] ] ], 'builderVersion' => '5.14.0' ];

        return '<!-- wp:divi/heading ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . ' /-->';
    }

    private function layout( string ...$blocks ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} -->'
            . implode( '', $blocks )
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    private function colorAttr( string $id ): array
    {
        return [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => '$variable(' . json_encode( [ 'type' => 'color', 'value' => [ 'name' => $id, 'settings' => new \stdClass() ] ] ) . ')$' ] ] ] ] ] ] ];
    }

    public function testCleanLayoutGivesNull(): void
    {
        $content = $this->layout( $this->heading( [ 'modulePreset' => [ self::KNOWN_PRESET ] ] + $this->colorAttr( self::KNOWN_COLOR ) ) );
        $this->assertNull( StyleReferences::advise( $content, $this->known() ) );
    }

    public function testDefaultAndEmptyPresetsAreNeverUnknown(): void
    {
        $content = $this->layout( $this->heading( [ 'modulePreset' => [ 'default' ] ] ), $this->heading( [ 'modulePreset' => '' ] ), $this->heading( [] ) );
        $this->assertNull( StyleReferences::advise( $content, $this->known() ) );
    }

    public function testUnknownPresetListAndStringFormsAreCountedAcrossBlocks(): void
    {
        $content = $this->layout( $this->heading( [ 'modulePreset' => [ self::UNKNOWN ] ] ), $this->heading( [ 'modulePreset' => self::UNKNOWN ] ), $this->heading( [ 'modulePreset' => [ 'default', 'other-id' ] ] ) );
        $advice  = StyleReferences::advise( $content, $this->known() );
        $this->assertSame( [ [ 'id' => self::UNKNOWN, 'count' => 2 ], [ 'id' => 'other-id', 'count' => 1 ] ], $advice['unknown_presets'] );
        $this->assertSame( [], $advice['unknown_colors'] );
        $this->assertSame( StyleReferences::MESSAGE, $advice['message'] );
    }

    public function testUnknownColorInsideAVariableTokenIsFoundButKnownAndInactiveAreNot(): void
    {
        $content = $this->layout(
            $this->heading( $this->colorAttr( self::UNKNOWN_COLOR ) ),
            $this->heading( $this->colorAttr( self::KNOWN_COLOR ) ),
            $this->heading( $this->colorAttr( 'gcid-inactive' ) )
        );
        $advice = StyleReferences::advise( $content, $this->known() );
        $this->assertSame( [ [ 'id' => self::UNKNOWN_COLOR, 'count' => 1 ] ], $advice['unknown_colors'] );
        $this->assertSame( [], $advice['unknown_presets'] );
    }

    public function testABareColorIdAndAVarReferenceAreFoundToo(): void
    {
        $content = $this->layout( $this->heading( [ 'module' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => 'gcid-bare-one', 'other' => 'var(--gcid-var-two)' ] ] ] ] ] ] ) );
        $advice  = StyleReferences::advise( $content, $this->known() );
        $this->assertSame( [ 'gcid-bare-one', 'gcid-var-two' ], array_column( $advice['unknown_colors'], 'id' ) );
    }

    public function testAPartTheSiteCannotReadIsNeverFlagged(): void
    {
        $content = $this->layout( $this->heading( [ 'modulePreset' => [ self::UNKNOWN ] ] + $this->colorAttr( self::UNKNOWN_COLOR ) ) );
        $onlyColors = StyleReferences::advise( $content, [ 'presets' => null, 'colors' => [] ] );
        $this->assertSame( [], $onlyColors['unknown_presets'] );
        $this->assertCount( 1, $onlyColors['unknown_colors'] );
        $onlyPresets = StyleReferences::advise( $content, [ 'presets' => [], 'colors' => null ] );
        $this->assertSame( [], $onlyPresets['unknown_colors'] );
        $this->assertCount( 1, $onlyPresets['unknown_presets'] );
        $this->assertNull( StyleReferences::advise( $content, [ 'presets' => null, 'colors' => null ] ) );
    }

    public function testAtMostTwentyIdsPerListAndLongIdsAreCut(): void
    {
        $blocks = [];
        for ( $i = 0; $i < 25; $i++ ) {
            $blocks[] = $this->heading( [ 'modulePreset' => [ "p{$i}" ] ] );
        }
        $blocks[] = $this->heading( [ 'modulePreset' => [ str_repeat( 'x', 300 ) ] ] );
        $advice = StyleReferences::advise( $this->layout( ...$blocks ), $this->known() );
        $this->assertCount( StyleReferences::MAX_IDS, $advice['unknown_presets'] );

        $only = StyleReferences::advise( $this->layout( $this->heading( [ 'modulePreset' => [ str_repeat( 'é', 300 ) ] ] ) ), $this->known() );
        $this->assertSame( str_repeat( 'é', 100 ), $only['unknown_presets'][0]['id'] );
        $this->assertNotFalse( json_encode( $only ) );
    }

    public function testUnparseableContentGivesNullAndNeverThrows(): void
    {
        $this->assertNull( StyleReferences::advise( '<!-- wp:divi/section {bad json} -->', $this->known() ) );
        $this->assertNull( StyleReferences::advise( '', $this->known() ) );
    }

    public function testAdviseNeverChangesItsInputsAndIsDeterministic(): void
    {
        $content = $this->layout( $this->heading( [ 'modulePreset' => [ self::UNKNOWN ] ] ) );
        $known   = $this->known();
        $copyC   = $content;
        $copyK   = $known;
        $a = StyleReferences::advise( $content, $known );
        $b = StyleReferences::advise( $content, $known );
        $this->assertSame( $a, $b );
        $this->assertSame( $copyC, $content );
        $this->assertSame( $copyK, $known );
    }

    public function testAttachLeavesAPayloadUntouchedWhenCleanAndAddsTheAdvisoryWhenNot(): void
    {
        $GLOBALS['__divi_presets'] = [ 'module' => [ 'divi/heading' => [ 'default' => '', 'items' => [ self::KNOWN_PRESET => [ 'id' => self::KNOWN_PRESET, 'name' => 'H' ] ] ] ] ];
        $GLOBALS['__divi_colors']  = [ self::KNOWN_COLOR => [ 'color' => '#fff', 'status' => 'active' ] ];
        try {
            $payload = [ 'valid' => true, 'violations' => [] ];
            $clean   = $this->layout( $this->heading( [ 'modulePreset' => [ self::KNOWN_PRESET ] ] ) );
            $this->assertSame( $payload, StyleReferences::attach( $payload, $clean ) );
            $this->assertSame( json_encode( $payload ), json_encode( StyleReferences::attach( $payload, $clean ) ) );

            $dirty  = $this->layout( $this->heading( [ 'modulePreset' => [ self::UNKNOWN ] ] ) );
            $result = StyleReferences::attach( $payload, $dirty );
            $this->assertTrue( $result['valid'] );
            $this->assertSame( self::UNKNOWN, $result['advisories']['style_references']['unknown_presets'][0]['id'] );
        } finally {
            unset( $GLOBALS['__divi_presets'], $GLOBALS['__divi_colors'] );
        }
    }
}```

- [ ] **Step 2: Run them and watch them fail**

Run: `./vendor/bin/phpunit tests/StyleReferencesTest.php`

Expected: FAIL. `wp-plugin/src/StyleReferences.php` does not exist yet (PHP "Failed opening required") (measured: `PHP Warning:  require_once(…/work/tests/../wp-plugin/src/StyleReferences.php): Failed to open stream: No such file or directory in …/work/tests/StyleReferencesTest.php on line 12`)

- [ ] **Step 3: Create the implementation**

**Create** `wp-plugin/src/StyleReferences.php` with exactly this content:

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
 * Finds the preset ids and global color ids a layout cites that do not exist on this site. Divi
 * renders such a layout anyway (an unknown preset falls back to the module's default preset; an
 * unknown color is left undefined), so this is an advisory only: it never changes a verdict, blocks
 * a save or alters content. The bundled validator is used read-only and stays site-agnostic.
 */
final class StyleReferences
{
    public const MAX_IDS = 20;

    public const MESSAGE = "These ids do not exist on this site; Divi falls back to the module's default preset (presets) or leaves the color undefined (colors). Call list_global_styles and use this site's own ids.";

    private const COLOR_TOKEN = '/gcid-[A-Za-z0-9_-]+/';

    /**
     * @param array{presets: ?array<string,true>, colors: ?array<string,true>} $known ids that exist on the site; null = cannot tell
     * @return ?array{unknown_presets: list<array{id:string,count:int}>, unknown_colors: list<array{id:string,count:int}>, message: string}
     */
    public static function advise( string $content, array $known ): ?array
    {
        if ( null === $known['presets'] && null === $known['colors'] ) {
            return null;
        }
        $parsed = ( new BlockParser() )->parse( $content );
        if ( ! $parsed->isOk() ) {
            return null;
        }

        $presets = [];
        $colors  = [];
        $parsed->root()->walk( static function ( Block $block ) use ( &$presets, &$colors, $known ): void {
            $attrs = $block->attrs();
            if ( null !== $known['presets'] ) {
                foreach ( (array) ( $attrs['modulePreset'] ?? [] ) as $id ) {
                    if ( is_string( $id ) && '' !== $id && 'default' !== $id && ! isset( $known['presets'][ $id ] ) ) {
                        $presets[ $id ] = ( $presets[ $id ] ?? 0 ) + 1;
                    }
                }
            }
            if ( null !== $known['colors'] ) {
                foreach ( self::colorTokens( $attrs ) as $id ) {
                    if ( ! isset( $known['colors'][ $id ] ) ) {
                        $colors[ $id ] = ( $colors[ $id ] ?? 0 ) + 1;
                    }
                }
            }
        } );

        if ( [] === $presets && [] === $colors ) {
            return null;
        }

        return [
            'unknown_presets' => self::rows( $presets ),
            'unknown_colors'  => self::rows( $colors ),
            'message'         => self::MESSAGE,
        ];
    }

    /**
     * Adds `advisories.style_references` to a response payload, only when the layout cites ids this
     * site does not have. Otherwise the payload is returned unchanged, byte for byte.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function attach( array $payload, string $content ): array
    {
        $advice = self::advise( $content, GlobalStyles::knownIds() );

        return null === $advice ? $payload : $payload + [ 'advisories' => [ 'style_references' => $advice ] ];
    }

    /** Every global color token in any string value of the attributes, in order, repeats included. @param array<mixed> $node @return list<string> */
    private static function colorTokens( array $node ): array
    {
        $out = [];
        foreach ( $node as $value ) {
            if ( is_array( $value ) ) {
                array_push( $out, ...self::colorTokens( $value ) );
            } elseif ( is_string( $value ) && preg_match_all( self::COLOR_TOKEN, $value, $m ) ) {
                array_push( $out, ...$m[0] );
            }
        }

        return $out;
    }

    /** @param array<string,int> $counts @return list<array{id:string,count:int}> */
    private static function rows( array $counts ): array
    {
        $rows = [];
        foreach ( array_slice( $counts, 0, self::MAX_IDS, true ) as $id => $count ) {
            $rows[] = [ 'id' => self::shown( (string) $id ), 'count' => $count ];
        }

        return $rows;
    }

    /** An id as shown back to the AI: cut to 100 characters, and never invalid UTF-8. */
    private static function shown( string $id ): string
    {
        return 1 === preg_match( '/^.{0,100}/su', $id, $m ) ? $m[0] : '[invalid id]';
    }
}```

**Modify** (one diff per file; apply each with `git apply --ignore-whitespace`, see the note under Global Constraints):

`wp-plugin/src/autoload.php`

```diff
diff --git a/wp-plugin/src/autoload.php b/wp-plugin/src/autoload.php
index 4b8b027..5e9e14c 100644
--- a/wp-plugin/src/autoload.php
+++ b/wp-plugin/src/autoload.php
@@ -25,6 +25,7 @@ require_once __DIR__ . '/UsageTracker.php';
 require_once __DIR__ . '/DiviCompat.php';
 require_once __DIR__ . '/ThemeLayouts.php';
 require_once __DIR__ . '/GlobalStyles.php';
+require_once __DIR__ . '/StyleReferences.php';
 require_once __DIR__ . '/EditTarget.php';
 require_once __DIR__ . '/PageEditor.php';
 require_once __DIR__ . '/LayoutDiff.php';
```

- [ ] **Step 4: Run the whole suite**

Run: `./vendor/bin/phpunit`

Expected: `OK (543 tests, 3709 assertions)`

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: StyleReferences, a non-blocking advisory for unknown preset and color ids

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```


### Task 3: The list_global_styles tool (MCP, REST, OpenAPI)

**Files:**
- Modify: `wp-plugin/src/McpHandler.php` (tool definition, dispatch line, handler method)
- Modify: `wp-plugin/src/RestController.php` (route and handler)
- Modify: `wp-plugin/src/OpenApiSpec.php` (the `/global-styles` path and three schemas)
- Create: `tests/GlobalStylesLockstepTest.php`
- Modify: `tests/DryRunLockstepTest.php`, `tests/ThemeLayoutsLockstepTest.php`, `tests/WpOrgComplianceTest.php` (the pinned counts: 16 becomes 17, and `list_global_styles` joins the pinned tool list, each with a one-line justification: a new read-only tool, nothing gated, nothing that writes a setting)

**Interfaces:**
- Consumes: `GlobalStyles::catalog( ?string $module )` (Task 1); `PageAccess::CAP` and `PageAccess::stringArg()` (existing); `UsageTracker::log()` (existing).
- Produces:
  - MCP tool `list_global_styles` (optional string argument `module`), REST `GET /global-styles` handled by `RestController::global_styles( WP_REST_Request ): WP_REST_Response|WP_Error`, OpenAPI operation `listGlobalStyles`
  - OpenAPI schemas `GlobalPreset`, `GlobalColor`, `GlobalVariable`
  - Behaviour: needs `edit_pages` (else MCP -32602 / REST 403); a non-string `module` is rejected (MCP -32602 / REST 400), never cast; logged as endpoint `list_global_styles`, result `read`; writes nothing.
  - 17 MCP tools and 17 OpenAPI operations.

- [ ] **Step 1: Write the failing tests**

**Create** `tests/GlobalStylesLockstepTest.php` with exactly this content:

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
require_once __DIR__ . '/DiviGlobalDataShims.php';
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
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** list_global_styles must exist, with the same meaning, in MCP, REST and OpenAPI, and be read-only. */
class GlobalStylesLockstepTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_posts']      = [];
        $GLOBALS['__wp_postmeta']   = [];
        $GLOBALS['__wp_caps']       = [ 'edit_pages' => true ];
        $GLOBALS['__divi_presets']  = [ 'module' => [ 'divi/button' => [ 'default' => 'b-1', 'items' => [ 'b-1' => [ 'id' => 'b-1', 'name' => 'Primary', 'attrs' => [ 'button' => [ 'decoration' => [ 'font' => [] ] ] ] ], 'b-2' => [ 'id' => 'b-2', 'name' => 'Ghost' ] ] ], 'divi/text' => [ 'default' => '', 'items' => [ 't-1' => [ 'id' => 't-1', 'name' => 'Body' ] ] ] ] ];
        $GLOBALS['__divi_colors']   = [ 'gcid-primary-color' => [ 'color' => '#2ea3f2', 'label' => 'Primary Color', 'status' => 'active', 'folder' => 'customizer' ] ];
        $GLOBALS['__divi_variables'] = [ 'numbers' => [ 'gvid-1' => [ 'label' => 'Radius', 'value' => '12px', 'status' => 'active' ] ] ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_presets'], $GLOBALS['__divi_colors'], $GLOBALS['__divi_variables'] );
        $GLOBALS['__wp_can'] = true;
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    public function testMcpDeclaresAndDispatchesTheTool(): void
    {
        $mcp = $this->src( 'McpHandler.php' );
        $this->assertStringContainsString( "'name'        => 'list_global_styles'", $mcp );
        $this->assertMatchesRegularExpression( "/'list_global_styles'\s*=>\s*\\\$this->toolListGlobalStyles/", $mcp );
    }

    public function testRestRegistersTheRouteAsReadOnly(): void
    {
        $rest = $this->src( 'RestController.php' );
        $this->assertMatchesRegularExpression( "/'\/global-styles',\s*\[\s*'methods'\s*=>\s*WP_REST_Server::READABLE/", $rest );
    }

    public function testOpenApiDescribesTheOperationAndItsSchemas(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $op   = $spec['paths']['/global-styles']['get'] ?? null;
        $this->assertIsArray( $op );
        $this->assertSame( 'listGlobalStyles', $op['operationId'] );
        $this->assertLessThanOrEqual( 300, strlen( (string) $op['description'] ) );
        $this->assertArrayNotHasKey( 'post', $spec['paths']['/global-styles'] );
        $this->assertArrayNotHasKey( 'put', $spec['paths']['/global-styles'] );
        $schema = $op['responses']['200']['content']['application/json']['schema'];
        $this->assertSame( '#/components/schemas/GlobalPreset', $schema['properties']['presets']['items']['$ref'] );
        $this->assertSame( '#/components/schemas/GlobalColor', $schema['properties']['colors']['items']['$ref'] );
        $this->assertSame( 'module', $op['parameters'][0]['name'] );
        foreach ( [ 'GlobalPreset', 'GlobalColor', 'GlobalVariable' ] as $name ) {
            $this->assertArrayHasKey( $name, $spec['components']['schemas'] );
        }
    }

    private function mcpCall( array $args = [] ): array
    {
        $req = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'list_global_styles', 'arguments' => $args ?: new \stdClass() ] ] );

        return (array) ( new McpHandler() )->handle( $req )->get_data();
    }

    public function testRestAndMcpReturnTheSameCatalog(): void
    {
        $rest = ( new RestController() )->global_styles( new \WP_REST_Request() );
        $this->assertSame( 200, $rest->get_status() );
        $d = (array) $rest->get_data();
        $this->assertSame( [ 'Ghost', 'Primary', 'Body' ], array_column( $d['presets'], 'name' ) );
        $this->assertSame( 'theme', $d['colors'][0]['source'] );
        $this->assertSame( 1, $d['counts']['variables'] );

        $ok = $this->mcpCall();
        $this->assertArrayNotHasKey( 'error', $ok );
        $this->assertSame( $d, json_decode( $ok['result']['content'][0]['text'], true ) );
    }

    public function testModuleFilterWorksOnBothSurfaces(): void
    {
        $rest = ( new RestController() )->global_styles( new \WP_REST_Request( [], [ 'module' => 'divi/text' ] ) );
        $this->assertSame( [ 'Body' ], array_column( ( (array) $rest->get_data() )['presets'], 'name' ) );
        $mcp = json_decode( $this->mcpCall( [ 'module' => 'divi/text' ] )['result']['content'][0]['text'], true );
        $this->assertSame( [ 'Body' ], array_column( $mcp['presets'], 'name' ) );
    }

    public function testANonStringModuleIsRejectedNeverCast(): void
    {
        $r = ( new RestController() )->global_styles( new \WP_REST_Request( [], [ 'module' => [ 'divi/text' ] ] ) );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 400, $r->data['status'] );
        foreach ( [ [ 'divi/text' ], 7, true ] as $bad ) {
            $e = $this->mcpCall( [ 'module' => $bad ] );
            $this->assertSame( -32602, $e['error']['code'] );
        }
    }

    public function testBothSurfacesRefuseAUserWhoCannotEditPages(): void
    {
        $GLOBALS['__wp_caps'] = [];
        $denied = $this->mcpCall();
        $this->assertSame( -32602, $denied['error']['code'] );
        $this->assertStringContainsString( 'permission', $denied['error']['message'] );
        $r = ( new RestController() )->global_styles( new \WP_REST_Request() );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 403, $r->data['status'] );
    }

    public function testTheToolWritesNothing(): void
    {
        $before = [ $GLOBALS['__divi_presets'], $GLOBALS['__divi_colors'], $GLOBALS['__divi_variables'], $GLOBALS['__wp_options'], $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        ( new RestController() )->global_styles( new \WP_REST_Request() );
        $this->mcpCall();
        $after = [ $GLOBALS['__divi_presets'], $GLOBALS['__divi_colors'], $GLOBALS['__divi_variables'], $GLOBALS['__wp_options'], $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        $this->assertSame( $before, $after );
    }

    public function testWithoutDiviTheToolAnswersWithNotesNotAnError(): void
    {
        $GLOBALS['__divi_presets'] = null;
        $GLOBALS['__divi_colors']  = null;
        $d = (array) ( new RestController() )->global_styles( new \WP_REST_Request() )->get_data();
        $this->assertSame( [], $d['presets'] );
        $this->assertSame( [], $d['colors'] );
        $this->assertNotEmpty( $d['notes'] );
    }
}```

**Modify** (one diff per file; apply each with `git apply --ignore-whitespace`, see the note under Global Constraints):

`tests/DryRunLockstepTest.php`

```diff
diff --git a/tests/DryRunLockstepTest.php b/tests/DryRunLockstepTest.php
index 7510042..2df0cb2 100644
--- a/tests/DryRunLockstepTest.php
+++ b/tests/DryRunLockstepTest.php
@@ -117,6 +117,6 @@ class DryRunLockstepTest extends TestCase
                 $ops[] = $op['operationId'] ?? '';
             }
         }
-        $this->assertCount( 16, $ops, 'dry_run is a flag, not a new operation (the 16th operation is list_theme_layouts)' );
+        $this->assertCount( 17, $ops, 'dry_run is a flag, not a new operation (the 16th and 17th operations are the read-only list_theme_layouts and list_global_styles)' );
     }
 }
```

`tests/ThemeLayoutsLockstepTest.php`

```diff
diff --git a/tests/ThemeLayoutsLockstepTest.php b/tests/ThemeLayoutsLockstepTest.php
index f17d9f8..3ff25c2 100644
--- a/tests/ThemeLayoutsLockstepTest.php
+++ b/tests/ThemeLayoutsLockstepTest.php
@@ -103,7 +103,7 @@ class ThemeLayoutsLockstepTest extends TestCase
         $this->assertSame( 'boolean', $spec['components']['schemas']['ThemeLayoutRef']['properties']['editable']['type'] );
     }
 
-    public function testThereAreNowSixteenOperations(): void
+    public function testThereAreNowSeventeenOperationsIncludingTheThemeLayoutsListing(): void
     {
         $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
         $ops  = [];
@@ -112,7 +112,7 @@ class ThemeLayoutsLockstepTest extends TestCase
                 $ops[] = $op['operationId'] ?? '';
             }
         }
-        $this->assertCount( 16, $ops );
+        $this->assertCount( 17, $ops );
         $this->assertContains( 'listThemeLayouts', $ops );
     }
 
```

`tests/WpOrgComplianceTest.php`

```diff
diff --git a/tests/WpOrgComplianceTest.php b/tests/WpOrgComplianceTest.php
index f7c2fe5..e1dc432 100644
--- a/tests/WpOrgComplianceTest.php
+++ b/tests/WpOrgComplianceTest.php
@@ -375,9 +375,10 @@ class WpOrgComplianceTest extends TestCase
         $this->assertSame([
             'create_page', 'edit_page_content', 'get_image_guide', 'get_landing_guide', 'get_page_history_entry',
             'get_page_layout', 'get_section_recipes', 'get_site_guide', 'get_style_guide', 'list_divi_pages',
-            'list_media_images', 'list_page_history', 'list_theme_layouts', 'restore_page_version', 'update_page_layout', 'validate_layout',
+            'list_global_styles', 'list_media_images', 'list_page_history', 'list_theme_layouts', 'restore_page_version', 'update_page_layout', 'validate_layout',
         ], $tools);
-        $this->assertCount(16, $tools);
+        // 4.4.0 adds one READ-ONLY tool (list_global_styles: the site's own presets and colors); nothing is gated, nothing writes a setting.
+        $this->assertCount(17, $tools);
     }
 
     public function testVisibleProductNameIsTheFullJhmgName(): void
```

- [ ] **Step 2: Run them and watch them fail**

Run: `./vendor/bin/phpunit tests/GlobalStylesLockstepTest.php tests/DryRunLockstepTest.php tests/ThemeLayoutsLockstepTest.php tests/WpOrgComplianceTest.php`

Expected: FAIL. the tool does not exist yet, and the pinned counts now say 17 (measured: `Tests: 42, Assertions: 297, Errors: 5, Failures: 7.`)

- [ ] **Step 3: Add the tool**

**Modify** (one diff per file; apply each with `git apply --ignore-whitespace`, see the note under Global Constraints):

`wp-plugin/src/McpHandler.php`

```diff
diff --git a/wp-plugin/src/McpHandler.php b/wp-plugin/src/McpHandler.php
index 0f23187..caea431 100644
--- a/wp-plugin/src/McpHandler.php
+++ b/wp-plugin/src/McpHandler.php
@@ -107,6 +107,15 @@ final class McpHandler
                 'description' => 'List the header and footer layouts of the live Divi Theme Builder that you can edit (read-only), with the templates that use them (title, default, enabled, where they apply). Edit a header or footer with the page tools, passing its layout_id as page_id; use dry_run first because it appears on every page. Body layouts are listed but cannot be edited. Creating or assigning templates is not possible here: that is done in Divi\'s Theme Builder.',
                 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
             ],
+            [
+                'name'        => 'list_global_styles',
+                'description' => 'List this site\'s own Divi 5 module presets, global colors and global variables (read-only), so layouts can reuse them instead of hard-coding colors. Call it before styling. Reference a color by its id (see get_style_guide); a module with no modulePreset already gets the site\'s default preset. Filter presets with "module" (e.g. divi/button). Creating or changing presets and colors is done in Divi\'s Visual Builder, not here.',
+                'inputSchema' => [
+                    'type'       => 'object',
+                    'properties' => ['module' => ['type' => 'string', 'description' => 'Optional module name to list presets for, e.g. divi/button']],
+                    'required'   => [],
+                ],
+            ],
             [
                 'name'        => 'get_page_layout',
                 'description' => 'Get the full Divi 5 layout (Gutenberg block HTML) for a specific page by ID. A header or footer layout id from list_theme_layouts also works.',
@@ -275,6 +284,7 @@ final class McpHandler
             'restore_page_version'   => $this->toolRestoreVersion($id, $arguments),
             'list_media_images'  => $this->toolListMedia($id, $arguments),
             'list_theme_layouts' => $this->toolListThemeLayouts($id),
+            'list_global_styles' => $this->toolListGlobalStyles($id, $arguments),
             'create_page'        => $this->toolCreatePage($id, $arguments),
             default              => $this->extensionCall($id, $name, $arguments),
         };
@@ -630,6 +640,24 @@ final class McpHandler
         ]);
     }
 
+    private function toolListGlobalStyles(mixed $id, array $args): WP_REST_Response
+    {
+        if (!current_user_can(PageAccess::CAP)) {
+            UsageTracker::log('list_global_styles', null, 'error');
+            return $this->rpcError($id, -32602, 'You do not have permission to read the site styles.');
+        }
+        $module = PageAccess::stringArg($args, 'module');
+        if ($module === null) {
+            return $this->rpcError($id, -32602, 'module must be a string.');
+        }
+        $result = GlobalStyles::catalog($module);
+        UsageTracker::log('list_global_styles', null, 'read');
+
+        return $this->rpcResult($id, [
+            'content' => [['type' => 'text', 'text' => wp_json_encode($result)]],
+        ]);
+    }
+
     private function toolListMedia(mixed $id, array $args): WP_REST_Response
     {
         if (!current_user_can('upload_files')) {
```

`wp-plugin/src/OpenApiSpec.php`

```diff
diff --git a/wp-plugin/src/OpenApiSpec.php b/wp-plugin/src/OpenApiSpec.php
index bbf5c8b..26cd644 100644
--- a/wp-plugin/src/OpenApiSpec.php
+++ b/wp-plugin/src/OpenApiSpec.php
@@ -87,6 +87,34 @@ final class OpenApiSpec
                             'violations' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Violation']],
                         ],
                     ],
+                    'GlobalPreset' => [
+                        'type'       => 'object',
+                        'properties' => [
+                            'id'       => ['type' => 'string', 'description' => 'Preset id, used as modulePreset'],
+                            'module'   => ['type' => 'string', 'description' => 'Module name, e.g. divi/button'],
+                            'name'     => ['type' => 'string'],
+                            'default'  => ['type' => 'boolean', 'description' => 'true when this is the default preset of its module (applied to every module without a modulePreset)'],
+                            'controls' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The attribute areas the preset sets (dotted paths)'],
+                            'renders'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Structural settings, e.g. headingLevel=h3'],
+                        ],
+                    ],
+                    'GlobalColor' => [
+                        'type'       => 'object',
+                        'properties' => [
+                            'id'     => ['type' => 'string', 'description' => 'Global color id (gcid-...)'],
+                            'label'  => ['type' => 'string'],
+                            'color'  => ['type' => 'string'],
+                            'source' => ['type' => 'string', 'enum' => ['theme', 'custom']],
+                        ],
+                    ],
+                    'GlobalVariable' => [
+                        'type'       => 'object',
+                        'properties' => [
+                            'id'    => ['type' => 'string'],
+                            'label' => ['type' => 'string'],
+                            'value' => ['type' => 'string'],
+                        ],
+                    ],
                     'HistoryEntry' => [
                         'type'       => 'object',
                         'properties' => [
@@ -423,6 +451,29 @@ final class OpenApiSpec
                         ],
                     ],
                 ],
+                '/global-styles' => [
+                    'get' => [
+                        'operationId' => 'listGlobalStyles',
+                        'summary'     => 'List the site\'s presets and colors',
+                        'description' => 'Lists this site\'s own Divi 5 module presets, global colors and variables (read-only) so layouts reuse them. Filter presets with ?module=divi/button. Presets and colors are created in Divi\'s Visual Builder, not here.',
+                        'parameters'  => [['name' => 'module', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'description' => 'Module name to list presets for, e.g. divi/button']]],
+                        'responses'   => [
+                            '200' => ['description' => 'Presets, colors and variables', 'content' => ['application/json' => ['schema' => [
+                                'type'       => 'object',
+                                'properties' => [
+                                    'presets'   => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GlobalPreset']],
+                                    'colors'    => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GlobalColor']],
+                                    'variables' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/GlobalVariable']], 'description' => 'Active variables grouped by type (numbers, strings, fonts...)'],
+                                    'counts'    => ['type' => 'object', 'properties' => ['presets' => ['type' => 'integer'], 'colors' => ['type' => 'integer'], 'variables' => ['type' => 'integer']]],
+                                    'truncated' => ['type' => 'boolean', 'description' => 'true when a list was cut to its limit; narrow presets with module'],
+                                    'notes'     => ['type' => 'array', 'items' => ['type' => 'string']],
+                                ],
+                            ]]]],
+                            '400' => ['description' => 'module is not a string'],
+                            '403' => ['description' => 'The user cannot edit pages'],
+                        ],
+                    ],
+                ],
                 '/media' => [
                     'get' => [
                         'operationId' => 'listMediaImages',
```

`wp-plugin/src/RestController.php`

```diff
diff --git a/wp-plugin/src/RestController.php b/wp-plugin/src/RestController.php
index 7c57981..8835346 100644
--- a/wp-plugin/src/RestController.php
+++ b/wp-plugin/src/RestController.php
@@ -43,6 +43,16 @@ final class RestController
             'permission_callback' => [$this, 'require_edit_posts'],
         ]);
 
+        // GET /global-styles — read-only list of the site's Divi presets, global colors and variables
+        register_rest_route(self::NS, '/global-styles', [
+            'methods'             => WP_REST_Server::READABLE,
+            'callback'            => [$this, 'global_styles'],
+            'permission_callback' => [$this, 'require_edit_posts'],
+            'args'                => [
+                'module' => ['type' => 'string', 'validate_callback' => fn($v) => is_string($v)],
+            ],
+        ]);
+
         // POST /pages — validate + create a new page (always a draft)
         register_rest_route(self::NS, '/pages', [
             'methods'             => WP_REST_Server::CREATABLE,
@@ -584,6 +594,22 @@ final class RestController
         return new WP_REST_Response($result, 200);
     }
 
+    public function global_styles(WP_REST_Request $request): WP_REST_Response|WP_Error
+    {
+        if (!current_user_can(PageAccess::CAP)) {
+            UsageTracker::log('list_global_styles', null, 'error');
+            return new WP_Error('forbidden', 'You do not have permission to read the site styles.', ['status' => 403]);
+        }
+        $module = PageAccess::stringArg(['module' => $request->get_param('module')], 'module');
+        if ($module === null) {
+            return new WP_Error('invalid_field', '"module" must be a string.', ['status' => 400]);
+        }
+        $result = GlobalStyles::catalog($module);
+        UsageTracker::log('list_global_styles', null, 'read');
+
+        return new WP_REST_Response($result, 200);
+    }
+
     public function list_media(WP_REST_Request $request): WP_REST_Response|WP_Error
     {
         if (!current_user_can('upload_files')) {
```

- [ ] **Step 4: Run the whole suite**

Run: `./vendor/bin/phpunit`

Expected: `OK (552 tests, 3748 assertions)`

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: list_global_styles, a read-only tool in MCP, REST and OpenAPI (17 tools)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```


### Task 4: The style-reference advisory on the layout tools

**Files:**
- Modify: `wp-plugin/src/McpHandler.php` (wrap the response of `validate_layout`, `update_page_layout` (preview and save), `edit_page_content` (preview and save) and `create_page` in `StyleReferences::attach`)
- Modify: `wp-plugin/src/RestController.php` (the same six REST responses plus the `post_content` branch of `validate`)
- Modify: `wp-plugin/src/OpenApiSpec.php` (schemas `StyleRefCount`, `StyleAdvisory`, `Advisories`, and `advisories` on the responses that can carry it)
- Create: `tests/WpInsertPostShims.php` (just enough `wp_insert_post` for the `create_page` handlers)
- Create: `tests/StyleAdvisoryHandlersTest.php`
- Modify: `tests/GlobalStylesLockstepTest.php` (one more test: the advisory is documented on every response that can carry it)

**Interfaces:**
- Consumes: `StyleReferences::attach( array $payload, string $content ): array` (Task 2).
- Produces:
  - `advisories.style_references` on `validate_layout`, `update_page_layout`, `edit_page_content`, `create_page`, every `dry_run` preview, and the REST equivalents, **only** when a layout cites an id the site does not have.
  - Rule (a ruling made while planning, consistent with the spec): a REFUSED save or create (violations) carries violations only, no advisory. A clean layout's response is byte-identical to today's. The advisory never changes `valid`, never blocks a save and never rewrites content.
  - The REST `validate` advisory is added only for the `post_content` form, not for the full layout-envelope form.

- [ ] **Step 1: Write the failing tests**

**Create** `tests/StyleAdvisoryHandlersTest.php` with exactly this content:

```php
<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/WpInsertPostShims.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';
require_once __DIR__ . '/DiviGlobalDataShims.php';
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
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/**
 * The style-reference advisory through the REAL REST and MCP handlers: present on validate, update,
 * edit, create and every dry-run preview exactly when a layout cites an id the site does not have;
 * absent (response unchanged) otherwise; and never able to change a verdict or a save.
 */
class StyleAdvisoryHandlersTest extends TestCase
{
    private const KNOWN   = 'b5bdfe4f-ac6a-4338-974b-680f73f018f9';
    private const UNKNOWN = '11111111-2222-4333-8444-555555555555';
    private const COLOR   = 'gcid-3e93bdc5-146d-448e-a761-9f1b4dd51113';
    private const NO_COLOR = 'gcid-00000000-0000-4000-8000-000000000000';

    private function layout( string $text, array $extra = [] ): string
    {
        $attrs = $extra + [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $text ] ] ], 'builderVersion' => '5.14.0' ];

        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading '
            . json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP )
            . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    private function clean(): string
    {
        return $this->layout( 'Hello', [ 'modulePreset' => [ self::KNOWN ] ] );
    }

    private function dirty( string $text = 'Hello' ): string
    {
        return $this->layout( $text, [ 'modulePreset' => [ self::UNKNOWN ] ] );
    }

    private function invalid(): string
    {
        return str_replace( 'wp:divi/heading', 'wp:divi/not-a-module', $this->dirty() );
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']      = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $post                         = new \WP_Post();
        $post->ID                     = 5;
        $post->post_content           = $this->dirty( 'Hi' );
        $GLOBALS['__wp_posts']        = [ 5 => $post ];
        $GLOBALS['__wp_postmeta']     = [];
        $GLOBALS['__wp_update_fail']  = false;
        $GLOBALS['__wp_update_calls'] = 0;
        $GLOBALS['__wp_inserts']      = [];
        $GLOBALS['__wp_caps']         = [ 'edit_post' => true, 'edit_pages' => true, 'publish_pages' => true ];
        $GLOBALS['__divi_presets']    = [ 'module' => [ 'divi/heading' => [ 'default' => '', 'items' => [ self::KNOWN => [ 'id' => self::KNOWN, 'name' => 'Heading 3' ] ] ] ] ];
        $GLOBALS['__divi_colors']     = [ self::COLOR => [ 'color' => '#ff4d00', 'status' => 'active' ] ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        $GLOBALS['__wp_options'] = [];
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_presets'], $GLOBALS['__divi_colors'] );
        $GLOBALS['__wp_can'] = true;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( string $method, array $body, array $params = [] ): array
    {
        $r = ( new RestController() )->$method( new \WP_REST_Request( $body, $params ) );
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array<string,mixed> */
    private function mcp( string $tool, array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        $this->assertArrayNotHasKey( 'error', $data );

        return (array) json_decode( $data['result']['content'][0]['text'], true );
    }

    private function assertAdvisory( array $payload, string $id ): void
    {
        $this->assertArrayHasKey( 'advisories', $payload );
        $this->assertSame( $id, $payload['advisories']['style_references']['unknown_presets'][0]['id'] );
        $this->assertSame( \AiEditorDivi5\WP\StyleReferences::MESSAGE, $payload['advisories']['style_references']['message'] );
    }

    public function testValidateAddsTheAdvisoryOnlyForUnknownIdsAndKeepsTheVerdict(): void
    {
        [ $st, $rest ] = $this->rest( 'validate', [ 'post_content' => $this->dirty() ] );
        $this->assertSame( 200, $st );
        $this->assertTrue( $rest['valid'] );
        $this->assertAdvisory( $rest, self::UNKNOWN );
        $mcp = $this->mcp( 'validate_layout', [ 'post_content' => $this->dirty() ] );
        $this->assertTrue( $mcp['valid'] );
        $this->assertAdvisory( $mcp, self::UNKNOWN );

        [ , $clean ] = $this->rest( 'validate', [ 'post_content' => $this->clean() ] );
        $this->assertArrayNotHasKey( 'advisories', $clean );
        $this->assertArrayNotHasKey( 'advisories', $this->mcp( 'validate_layout', [ 'post_content' => $this->clean() ] ) );
    }

    public function testAnUnknownColorIsReportedToo(): void
    {
        $content = $this->layout( 'Hello', [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Hello' ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => '$variable({"type":"color","value":{"name":"' . self::NO_COLOR . '","settings":{}}})$' ] ] ] ] ] ] ] );
        $mcp = $this->mcp( 'validate_layout', [ 'post_content' => $content ] );
        $this->assertTrue( $mcp['valid'] );
        $this->assertSame( self::NO_COLOR, $mcp['advisories']['style_references']['unknown_colors'][0]['id'] );
    }

    public function testDryRunPreviewsCarryTheAdvisoryAndSaveNothing(): void
    {
        $before = $GLOBALS['__wp_posts'][5]->post_content;
        [ , $rest ] = $this->rest( 'update_page', [ 'post_content' => $this->dirty( 'New' ), 'dry_run' => true ], [ 'id' => 5 ] );
        $this->assertTrue( $rest['dry_run'] );
        $this->assertAdvisory( $rest, self::UNKNOWN );
        $mcp = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $this->dirty( 'New' ), 'dry_run' => true ] );
        $this->assertAdvisory( $mcp, self::UNKNOWN );

        [ , $edit ] = $this->rest( 'edit_page', [ 'find' => 'Hi', 'replace' => 'Yo', 'dry_run' => true ], [ 'id' => 5 ] );
        $this->assertAdvisory( $edit, self::UNKNOWN );
        $mcpEdit = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => 'Hi', 'replace' => 'Yo', 'dry_run' => true ] );
        $this->assertAdvisory( $mcpEdit, self::UNKNOWN );

        $this->assertSame( $before, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testAnAdvisoryNeverBlocksOrChangesARealSave(): void
    {
        [ $st, $rest ] = $this->rest( 'update_page', [ 'post_content' => $this->dirty( 'Saved via REST' ) ], [ 'id' => 5 ] );
        $this->assertSame( 200, $st );
        $this->assertTrue( $rest['saved'] );
        $this->assertTrue( $rest['valid'] );
        $this->assertAdvisory( $rest, self::UNKNOWN );
        $this->assertStringContainsString( 'Saved via REST', $GLOBALS['__wp_posts'][5]->post_content );

        $mcp = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $this->dirty( 'Saved via MCP' ) ] );
        $this->assertTrue( $mcp['saved'] );
        $this->assertAdvisory( $mcp, self::UNKNOWN );
        $this->assertStringContainsString( 'Saved via MCP', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testEditsThatSaveCarryTheAdvisory(): void
    {
        [ , $rest ] = $this->rest( 'edit_page', [ 'find' => 'Hi', 'replace' => 'Yo' ], [ 'id' => 5 ] );
        $this->assertTrue( $rest['saved'] );
        $this->assertAdvisory( $rest, self::UNKNOWN );
        $mcp = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => 'Yo', 'replace' => 'Ha' ] );
        $this->assertTrue( $mcp['saved'] );
        $this->assertAdvisory( $mcp, self::UNKNOWN );
    }

    public function testARefusedSaveReportsViolationsOnlyNoAdvisory(): void
    {
        [ $st, $rest ] = $this->rest( 'update_page', [ 'post_content' => $this->invalid() ], [ 'id' => 5 ] );
        $this->assertSame( 422, $st );
        $this->assertFalse( $rest['saved'] );
        $this->assertArrayNotHasKey( 'advisories', $rest );
        $mcp = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $this->invalid() ] );
        $this->assertFalse( $mcp['saved'] );
        $this->assertArrayNotHasKey( 'advisories', $mcp );
    }

    public function testCreatePageCarriesTheAdvisoryOnlyWhenNeeded(): void
    {
        [ $st, $rest ] = $this->rest( 'create_page', [ 'title' => 'T', 'post_content' => $this->dirty() ] );
        $this->assertSame( 201, $st );
        $this->assertAdvisory( $rest, self::UNKNOWN );
        $mcp = $this->mcp( 'create_page', [ 'title' => 'T2', 'post_content' => $this->dirty() ] );
        $this->assertTrue( $mcp['created'] );
        $this->assertAdvisory( $mcp, self::UNKNOWN );

        [ , $clean ] = $this->rest( 'create_page', [ 'title' => 'T3', 'post_content' => $this->clean() ] );
        $this->assertArrayNotHasKey( 'advisories', $clean );
        $this->assertArrayNotHasKey( 'advisories', $this->mcp( 'create_page', [ 'title' => 'T4', 'post_content' => $this->clean() ] ) );
    }

    public function testWithoutDiviDataEverythingWorksAndNothingIsFlagged(): void
    {
        $GLOBALS['__divi_presets'] = null;
        $GLOBALS['__divi_colors']  = null;
        $mcp = $this->mcp( 'validate_layout', [ 'post_content' => $this->dirty() ] );
        $this->assertTrue( $mcp['valid'] );
        $this->assertArrayNotHasKey( 'advisories', $mcp );
        $save = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $this->dirty( 'Still saves' ) ] );
        $this->assertTrue( $save['saved'] );
        $this->assertArrayNotHasKey( 'advisories', $save );
    }
}```

**Create** `tests/WpInsertPostShims.php` with exactly this content:

```php
<?php

declare(strict_types=1);

/**
 * Just enough of wp_insert_post() for the create_page handlers: stores the post in $GLOBALS['__wp_posts']
 * and returns its id. Guarded like every other shim.
 */
if ( ! function_exists( 'wp_insert_post' ) ) {
    function wp_insert_post( $arr, $wp_error = false ) {
        $arr          = wp_unslash( $arr );
        $id           = max( array_merge( [ 100 ], array_keys( $GLOBALS['__wp_posts'] ?? [] ) ) ) + 1;
        $p            = new WP_Post();
        $p->ID        = $id;
        $p->post_type = (string) ( $arr['post_type'] ?? 'page' );
        $p->post_status  = (string) ( $arr['post_status'] ?? 'draft' );
        $p->post_title   = (string) ( $arr['post_title'] ?? '' );
        $p->post_content = (string) ( $arr['post_content'] ?? '' );
        $GLOBALS['__wp_posts'][ $id ] = $p;
        $GLOBALS['__wp_inserts'][]    = $id;

        return $id;
    }
}
if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $s ), '-' ) ); }
}
if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $id = 0 ) { return 'https://example.com/?page_id=' . (int) ( is_object( $id ) ? $id->ID : $id ); }
}```

**Modify** (one diff per file; apply each with `git apply --ignore-whitespace`, see the note under Global Constraints):

`tests/GlobalStylesLockstepTest.php`

```diff
diff --git a/tests/GlobalStylesLockstepTest.php b/tests/GlobalStylesLockstepTest.php
index 4dea66c..ed30d89 100644
--- a/tests/GlobalStylesLockstepTest.php
+++ b/tests/GlobalStylesLockstepTest.php
@@ -89,6 +89,19 @@ class GlobalStylesLockstepTest extends TestCase
         }
     }
 
+    public function testTheAdvisoryIsDocumentedOnEveryResponseThatCanCarryIt(): void
+    {
+        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
+        $ref  = '#/components/schemas/Advisories';
+        foreach ( [ 'StyleAdvisory', 'StyleRefCount', 'Advisories' ] as $name ) {
+            $this->assertArrayHasKey( $name, $spec['components']['schemas'] );
+        }
+        $this->assertSame( $ref, $spec['components']['schemas']['ValidationResult']['properties']['advisories']['$ref'] );
+        $this->assertSame( $ref, $spec['paths']['/pages/{id}']['put']['responses']['200']['content']['application/json']['schema']['properties']['advisories']['$ref'] );
+        $this->assertSame( $ref, $spec['paths']['/pages/{id}/edit']['post']['responses']['200']['content']['application/json']['schema']['properties']['advisories']['$ref'] );
+        $this->assertSame( $ref, $spec['paths']['/pages']['post']['responses']['201']['content']['application/json']['schema']['properties']['advisories']['$ref'] );
+    }
+
     private function mcpCall( array $args = [] ): array
     {
         $req = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'list_global_styles', 'arguments' => $args ?: new \stdClass() ] ] );
```

- [ ] **Step 2: Run them and watch them fail**

Run: `./vendor/bin/phpunit tests/StyleAdvisoryHandlersTest.php tests/GlobalStylesLockstepTest.php`

Expected: FAIL. the handlers do not attach any advisory yet and the OpenAPI schemas are missing (measured: `Tests: 18, Assertions: 65, Failures: 7, Warnings: 2.`)

- [ ] **Step 3: Wire the advisory into the handlers and the OpenAPI document**

**Modify** (one diff per file; apply each with `git apply --ignore-whitespace`, see the note under Global Constraints):

`wp-plugin/src/McpHandler.php`

```diff
diff --git a/wp-plugin/src/McpHandler.php b/wp-plugin/src/McpHandler.php
index caea431..c550dc6 100644
--- a/wp-plugin/src/McpHandler.php
+++ b/wp-plugin/src/McpHandler.php
@@ -397,7 +397,7 @@ final class McpHandler
         UsageTracker::log('validate', null, $result->isValid() ? 'read' : 'invalid', count($result->violations()));
 
         return $this->rpcResult($id, [
-            'content' => [['type' => 'text', 'text' => json_encode($result->toArray())]],
+            'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach($result->toArray(), $content))]],
         ]);
     }
 
@@ -433,7 +433,7 @@ final class McpHandler
             UsageTracker::log(DryRun::ENDPOINT, $pageId, UsageTracker::RESULT_READ);
 
             return $this->rpcResult($id, [
-                'content' => [['type' => 'text', 'text' => json_encode(DryRun::preview((string) $post->post_content, $content))]],
+                'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach(DryRun::preview((string) $post->post_content, $content), $content))]],
             ]);
         }
 
@@ -462,12 +462,12 @@ final class McpHandler
         UsageTracker::log('update_layout', $pageId, 'valid');
 
         return $this->rpcResult($id, [
-            'content' => [['type' => 'text', 'text' => json_encode([
+            'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach([
                 'saved'   => true,
                 'valid'   => true,
                 'history' => $write['snapshot'],
                 'page'    => ['id' => $pageId, 'title' => get_the_title($pageId)],
-            ])]],
+            ], $content))]],
         ]);
     }
 
@@ -514,7 +514,7 @@ final class McpHandler
             UsageTracker::log(DryRun::ENDPOINT, $pageId, UsageTracker::RESULT_READ);
 
             return $this->rpcResult($id, [
-                'content' => [['type' => 'text', 'text' => json_encode(DryRun::preview((string) $post->post_content, $edit['content']) + ['replaced' => $edit['count']])]],
+                'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach(DryRun::preview((string) $post->post_content, $edit['content']) + ['replaced' => $edit['count']], $edit['content']))]],
             ]);
         }
 
@@ -542,13 +542,13 @@ final class McpHandler
         UsageTracker::log('edit_content', $pageId, 'valid');
 
         return $this->rpcResult($id, [
-            'content' => [['type' => 'text', 'text' => json_encode([
+            'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach([
                 'saved'    => true,
                 'valid'    => true,
                 'replaced' => $edit['count'],
                 'history'  => $write['snapshot'],
                 'page'     => ['id' => $pageId, 'title' => get_the_title($pageId)],
-            ])]],
+            ], $edit['content']))]],
         ]);
     }
 
@@ -737,7 +737,7 @@ final class McpHandler
         UsageTracker::log('create_page', (int) $pageId, 'valid');
 
         return $this->rpcResult($id, [
-            'content' => [['type' => 'text', 'text' => json_encode([
+            'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach([
                 'created' => true,
                 'valid'   => true,
                 'page'    => [
@@ -746,7 +746,7 @@ final class McpHandler
                     'status' => 'draft',
                     'link'   => get_permalink((int) $pageId),
                 ],
-            ])]],
+            ], $content))]],
         ]);
     }
 
```

`wp-plugin/src/OpenApiSpec.php`

```diff
diff --git a/wp-plugin/src/OpenApiSpec.php b/wp-plugin/src/OpenApiSpec.php
index 26cd644..3799205 100644
--- a/wp-plugin/src/OpenApiSpec.php
+++ b/wp-plugin/src/OpenApiSpec.php
@@ -85,6 +85,29 @@ final class OpenApiSpec
                         'properties' => [
                             'valid'      => ['type' => 'boolean'],
                             'violations' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Violation']],
+                            'advisories' => ['$ref' => '#/components/schemas/Advisories'],
+                        ],
+                    ],
+                    'StyleRefCount' => [
+                        'type'       => 'object',
+                        'properties' => [
+                            'id'    => ['type' => 'string', 'description' => 'The id the layout cites'],
+                            'count' => ['type' => 'integer', 'description' => 'How many times the layout cites it'],
+                        ],
+                    ],
+                    'StyleAdvisory' => [
+                        'type'       => 'object',
+                        'properties' => [
+                            'unknown_presets' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StyleRefCount'], 'description' => 'Preset ids that do not exist on this site (at most 20)'],
+                            'unknown_colors'  => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StyleRefCount'], 'description' => 'Global color ids that do not exist on this site (at most 20)'],
+                            'message'         => ['type' => 'string'],
+                        ],
+                    ],
+                    'Advisories' => [
+                        'type'        => 'object',
+                        'description' => 'Present only when there is something to report. Advisories never change valid or the save.',
+                        'properties'  => [
+                            'style_references' => ['$ref' => '#/components/schemas/StyleAdvisory'],
                         ],
                     ],
                     'GlobalPreset' => [
@@ -246,7 +269,15 @@ final class OpenApiSpec
                             ]]],
                         ],
                         'responses'   => [
-                            '201' => ['description' => 'Page created'],
+                            '201' => ['description' => 'Page created (advisories lists unknown preset or color ids, if any)', 'content' => ['application/json' => ['schema' => [
+                                'type'       => 'object',
+                                'properties' => [
+                                    'created'    => ['type' => 'boolean'],
+                                    'valid'      => ['type' => 'boolean'],
+                                    'advisories' => ['$ref' => '#/components/schemas/Advisories'],
+                                ],
+                                'additionalProperties' => true,
+                            ]]]],
                             '400' => ['description' => 'Missing title or post_content, or a field that is not a string'],
                             '422' => ['description' => 'Validation failed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationResult']]]],
                         ],
@@ -586,6 +617,7 @@ final class OpenApiSpec
             'diff'       => ['$ref' => '#/components/schemas/DryRunDiff'],
             'diff_error' => ['type' => 'string', 'description' => 'Why no diff could be built (diff is then null); the verdict is still valid'],
             'message'    => ['type' => 'string'],
+            'advisories' => ['$ref' => '#/components/schemas/Advisories'],
         ];
     }
 }
```

`wp-plugin/src/RestController.php`

```diff
diff --git a/wp-plugin/src/RestController.php b/wp-plugin/src/RestController.php
index 8835346..0937a11 100644
--- a/wp-plugin/src/RestController.php
+++ b/wp-plugin/src/RestController.php
@@ -285,7 +285,7 @@ final class RestController
         if ($dryRun) {
             UsageTracker::log(DryRun::ENDPOINT, $id, UsageTracker::RESULT_READ);
 
-            return new WP_REST_Response(DryRun::preview((string) $post->post_content, $content), 200);
+            return new WP_REST_Response(StyleReferences::attach(DryRun::preview((string) $post->post_content, $content), $content), 200);
         }
 
         // Validate before saving — this is the safety gate
@@ -312,13 +312,13 @@ final class RestController
 
         $post->post_content = $content;
 
-        return new WP_REST_Response([
+        return new WP_REST_Response(StyleReferences::attach([
             'saved'      => true,
             'valid'      => true,
             'violations' => [],
             'history'    => $write['snapshot'],
             'page'       => $this->build_layout_envelope($post),
-        ], 200);
+        ], $content), 200);
     }
 
     /** @return \WP_Post|WP_Error */
@@ -433,7 +433,7 @@ final class RestController
         if ($dryRun) {
             UsageTracker::log(DryRun::ENDPOINT, $id, UsageTracker::RESULT_READ);
 
-            return new WP_REST_Response(DryRun::preview((string) $post->post_content, $edit['content']) + ['replaced' => $edit['count']], 200);
+            return new WP_REST_Response(StyleReferences::attach(DryRun::preview((string) $post->post_content, $edit['content']) + ['replaced' => $edit['count']], $edit['content']), 200);
         }
 
         // Same safety gate as update_page — the edited content must still validate.
@@ -457,14 +457,14 @@ final class RestController
 
         $post->post_content = $edit['content'];
 
-        return new WP_REST_Response([
+        return new WP_REST_Response(StyleReferences::attach([
             'saved'      => true,
             'valid'      => true,
             'replaced'   => $edit['count'],
             'violations' => [],
             'history'    => $write['snapshot'],
             'page'       => $this->build_layout_envelope($post),
-        ], 200);
+        ], $edit['content']), 200);
     }
 
     public function create_page(WP_REST_Request $request): WP_REST_Response|WP_Error
@@ -528,11 +528,11 @@ final class RestController
 
         UsageTracker::log('create_page', (int) $pageId, 'valid');
 
-        return new WP_REST_Response([
+        return new WP_REST_Response(StyleReferences::attach([
             'created' => true,
             'valid'   => true,
             'page'    => $this->build_layout_envelope(get_post((int) $pageId)),
-        ], 201);
+        ], $content), 201);
     }
 
     public function validate(WP_REST_Request $request): WP_REST_Response|WP_Error
@@ -544,6 +544,7 @@ final class RestController
         }
 
         // Accept either {post_content:...} or a full layout envelope
+        $content = null;
         if (isset($body['post_content'])) {
             $content = PageAccess::stringArg($body, 'post_content');
             if ($content === null) {
@@ -556,7 +557,12 @@ final class RestController
 
         UsageTracker::log('validate', null, $result->isValid() ? 'read' : 'invalid', count($result->violations()));
 
-        return new WP_REST_Response($result->toArray(), $result->isValid() ? 200 : 422);
+        $payload = $result->toArray();
+        if ($content !== null) {
+            $payload = StyleReferences::attach($payload, $content);
+        }
+
+        return new WP_REST_Response($payload, $result->isValid() ? 200 : 422);
     }
 
     // ---------------------------------------------------------------
```

- [ ] **Step 4: Run the whole suite**

Run: `./vendor/bin/phpunit`

Expected: `OK (561 tests, 3835 assertions)`

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: style-reference advisory on validate, save, edit, create and dry-run previews

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```


### Task 5: Guides: Match the site, and the recipes warning

**Files:**
- Modify: `wp-plugin/src/StyleGuide.php` (a new section at the end of the guide)
- Modify: `wp-plugin/src/SectionRecipes.php` (three lines in the served catalog)
- Modify: `tests/StyleGuideTest.php`, `tests/SectionRecipesTest.php`

**Interfaces:**
- Consumes: the tool and advisory names from Tasks 3 and 4 (`list_global_styles`, `advisories.style_references`).
- Produces: the guide section `## Match the site (colors and presets)`; the catalog sentence "come from a demo site and may not exist". The only reference shape the guide teaches is the color variable form `"color":"$variable({<Q>type<Q>:<Q>color<Q>,...})$"` that appears verbatim in the real exports under `fixtures/valid/` (a test proves it); non-color variables are deliberately not taught.

- [ ] **Step 1: Write the failing tests**

**Modify** (one diff per file; apply each with `git apply --ignore-whitespace`, see the note under Global Constraints):

`tests/SectionRecipesTest.php`

```diff
diff --git a/tests/SectionRecipesTest.php b/tests/SectionRecipesTest.php
index fe10a68..ddabca9 100644
--- a/tests/SectionRecipesTest.php
+++ b/tests/SectionRecipesTest.php
@@ -244,4 +244,11 @@ class SectionRecipesTest extends TestCase
         $this->assertNotSame('', trim((string) ($value['alt'] ?? '')), 'the portrait has meaningful alt text');
         $this->assertStringContainsString('/assets/images/avatar-1.svg', (string) SectionRecipes::recipe('testimonial'), 'the token resolves to the local pack');
     }
+
+    public function testCatalogWarnsThatRecipeIdsComeFromADemoSite(): void
+    {
+        $catalog = SectionRecipes::catalog();
+        $this->assertStringContainsString('come from a demo site and may not exist', $catalog);
+        $this->assertStringContainsString('list_global_styles', $catalog);
+    }
 }
```

`tests/StyleGuideTest.php`

```diff
diff --git a/tests/StyleGuideTest.php b/tests/StyleGuideTest.php
index 98b5765..7edc054 100644
--- a/tests/StyleGuideTest.php
+++ b/tests/StyleGuideTest.php
@@ -88,4 +88,35 @@ class StyleGuideTest extends TestCase
         $this->assertStringContainsString('Only entries marked `editable: true` can be edited.', $md);
         $this->assertStringContainsString('`update_page_layout` (with `dry_run: true` first)', $md);
     }
+
+    public function testTeachesTheSiteStylesToolWithTheColorShapeSeenInRealExports(): void
+    {
+        $md = StyleGuide::markdown();
+        $this->assertStringContainsString('## Match the site (colors and presets)', $md);
+        $this->assertStringContainsString('list_global_styles', $md);
+        $this->assertStringContainsString('advisories.style_references', $md);
+
+        // The one reference shape the guide teaches must appear verbatim in a real export.
+        $q     = '\\' . 'u0022'; // a quote as written inside a block comment: backslash, u, 0, 0, 2, 2
+        $shape = '$variable({' . $q . 'type' . $q . ':' . $q . 'color' . $q . ',' . $q . 'value' . $q . ':{' . $q . 'name' . $q . ':' . $q . 'gcid-';
+        $this->assertStringContainsString($shape, $md);
+        $found = false;
+        foreach (glob(__DIR__ . '/../fixtures/valid/page-*.json') ?: [] as $file) {
+            $export = json_decode((string) file_get_contents($file), true);
+            if (is_array($export) && str_contains((string) ($export['post_content'] ?? ''), $shape)) {
+                $found = true;
+                break;
+            }
+        }
+        $this->assertTrue($found, 'no real export in fixtures/valid contains the color-variable shape the guide teaches');
+    }
+
+    public function testSiteStylesSectionDoesNotTeachUngroundedVariableTypes(): void
+    {
+        $md = StyleGuide::markdown();
+        $this->assertStringContainsString('Variables other than colors are listed for information', $md);
+        $q = '\\' . 'u0022';
+        $this->assertStringNotContainsString($q . 'type' . $q . ':' . $q . 'number', $md);
+        $this->assertStringNotContainsString('gvid-XXXX', $md);
+    }
 }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `./vendor/bin/phpunit tests/StyleGuideTest.php tests/SectionRecipesTest.php`

Expected: FAIL. the guide section and the catalog warning do not exist yet (measured: `Tests: 27, Assertions: 607, Failures: 3.`)

- [ ] **Step 3: Add the guide section and the catalog warning**

**Modify** (one diff per file; apply each with `git apply --ignore-whitespace`, see the note under Global Constraints):

`wp-plugin/src/SectionRecipes.php`

```diff
diff --git a/wp-plugin/src/SectionRecipes.php b/wp-plugin/src/SectionRecipes.php
index 4212693..2515f0c 100644
--- a/wp-plugin/src/SectionRecipes.php
+++ b/wp-plugin/src/SectionRecipes.php
@@ -41,6 +41,10 @@ final class SectionRecipes
             'Place sections between the root',
             '<!-- wp:divi/placeholder --> ... <!-- /wp:divi/placeholder --> wrapper.',
             '',
+            'The preset and color ids inside the recipe markup come from a demo site and may not exist',
+            'on this site. Call list_global_styles and swap them for this site\'s own ids, or remove the',
+            '"modulePreset" attribute (a module without one gets this site\'s default preset).',
+            '',
             'Each recipe lists the persuasion _Stage_ it serves. Assemble a landing page by',
             'following the conversion flow in get_landing_guide and picking a recipe per stage.',
             '',
```

`wp-plugin/src/StyleGuide.php`

```diff
diff --git a/wp-plugin/src/StyleGuide.php b/wp-plugin/src/StyleGuide.php
index 0f547e7..be7b515 100644
--- a/wp-plugin/src/StyleGuide.php
+++ b/wp-plugin/src/StyleGuide.php
@@ -296,6 +296,24 @@ Only the layouts of the live Theme Builder can be edited, and body layouts canno
 that is the owner's job in Divi's Theme Builder. If the owner has unsaved Theme Builder
 changes open in Divi, ask them to publish or discard those first, because publishing
 them could overwrite your edit.
+
+## Match the site (colors and presets)
+Call `list_global_styles` before styling. It lists the site's own global colors (ids like
+`gcid-…`), module presets and variables. Reuse a site color instead of hard-coding a hex:
+write it as a color variable, exactly like this (inside a block comment the quotes are
+written as <Q>):
+`"color":"$variable({<Q>type<Q>:<Q>color<Q>,<Q>value<Q>:{<Q>name<Q>:<Q>gcid-XXXX<Q>,<Q>settings<Q>:{}}})$"`
+with a real id from the tool in place of `gcid-XXXX`. The five theme colors
+(`gcid-primary-color`, `gcid-secondary-color`, `gcid-heading-color`, `gcid-body-color`,
+`gcid-link-color`) exist on every Divi 5 site. Never invent an id: an id the site does not
+have leaves the color undefined, so the text silently loses its color.
+A module with no `modulePreset` (or `"modulePreset":["default"]`) already gets the site's
+default preset for its type, so most modules need nothing. Use a named preset only when its
+name and `controls` fit, as `"modulePreset":["<id from the tool>"]`; an unknown id silently
+falls back to the default preset. Variables other than colors are listed for information
+only: do not reference them. The tool is read-only: presets and colors are created in
+Divi's Visual Builder. The save, create and preview tools add an `advisories.style_references`
+note when a layout cites an id the site does not have; replace those ids with the site's own.
 MD;
     }
 }
```

- [ ] **Step 4: Turn the `<Q>` placeholder into the real escape**

The guide must contain the six characters backslash, `u`, `0`, `0`, `2`, `2` (a quote as it is written inside a block comment in a real export). Your tools decode that sequence if you type it, so the diff above uses the placeholder `<Q>`. Replace it with:

```bash
python3 - <<'PY'
p = 'wp-plugin/src/StyleGuide.php'
s = open(p).read()
s = s.replace('<Q>', chr(92) + 'u0022')
open(p, 'w').write(s)
PY
grep -c '<Q>' wp-plugin/src/StyleGuide.php
```

Expected: the `grep -c` prints `0`. (`tests/StyleGuideTest.php` builds the same sequence from pieces, so it never needs the literal.)

- [ ] **Step 5: Run the whole suite**

Run: `./vendor/bin/phpunit`

Expected: `OK (564 tests, 3845 assertions)`

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: guides teach reusing the site colors and presets; recipes warn their ids are from a demo site

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```


### Task 6: Compliance guard, readme, Features card, version 4.4.0

**Files:**
- Modify: `tests/WpOrgComplianceTest.php` (a scanner that bans Divi's save functions and storage names, with planted-violation proof, and a read-only check of the tool, the OpenAPI path and the readme)
- Modify: `tests/WpOrgNamingTest.php` (version pins 4.3.0 becomes 4.4.0)
- Modify: `wp-plugin/readme.txt` (Stable tag, 17 tools, the tool line, "Matches your site" bullet, FAQ sentence, Changelog and Upgrade Notice for 4.4.0)
- Modify: `wp-plugin/jhmg-ai-editor-for-divi-5.php` (Version header and `AI_EDITOR_DIVI5_VERSION`)
- Modify: `wp-plugin/src/AdminPage.php` (one Features card row; comment says 17 tools)

**Interfaces:**
- Consumes: everything above.
- Produces: the release identity `4.4.0`; the compliance guard `WpOrgComplianceTest::testNothingInThePluginCanWriteDiviPresetsColorsOrVariables`. Readme rules to respect (the existing compliance tests enforce them): no words upgrade-to / premium / locked / unlock / licence key, the one Pro add-on sentence stays the only one, "menu" and "front page" appear only in the one pinned FAQ sentence, the readme must say plainly `It cannot create or change presets or colors`.

- [ ] **Step 1: Write the failing tests**

**Modify** (one diff per file; apply each with `git apply --ignore-whitespace`, see the note under Global Constraints):

`tests/WpOrgComplianceTest.php`

```diff
diff --git a/tests/WpOrgComplianceTest.php b/tests/WpOrgComplianceTest.php
index e1dc432..6239bff 100644
--- a/tests/WpOrgComplianceTest.php
+++ b/tests/WpOrgComplianceTest.php
@@ -486,4 +486,64 @@ class WpOrgComplianceTest extends TestCase
         $this->assertLessThanOrEqual(150, strlen($short), "short description is too long: {$short}");
         $this->assertMatchesRegularExpression('/^Tags:\s*([^,\n]+,){0,4}[^,\n]+$/m', $r, 'at most 5 tags');
     }
+
+    // ---------------------------------------------------------------
+    // 4.4.0: the plugin READS the site's Divi presets, global colors and variables; it must never write them.
+    // ---------------------------------------------------------------
+
+    /** Divi functions that save presets, colors or variables, plus the storage names behind them. */
+    private const DIVI_STYLE_WRITES = '/\b(?:save_data|process_presets(?:_for_import)?|delete_data|merge_new_presets_with_existing|save_is_legacy_presets_imported|set_global_colors|set_global_variables|import_global_variables|et_update_option|et_global_data)\b|builder_global_presets/';
+
+    /** @param array<string,string> $files @return list<string> */
+    private function diviStyleWriteHits(array $files): array
+    {
+        $hits = [];
+        foreach ($files as $rel => $src) {
+            if (str_starts_with($rel, 'validator/') || str_ends_with($rel, 'readme.txt') || !in_array(pathinfo($rel, PATHINFO_EXTENSION), ['php', 'js'], true)) {
+                continue;
+            }
+            if (preg_match_all(self::DIVI_STYLE_WRITES, $src, $m)) {
+                foreach (array_unique($m[0]) as $hit) {
+                    $hits[] = "$rel: {$hit}";
+                }
+            }
+        }
+
+        return $hits;
+    }
+
+    public function testNothingInThePluginCanWriteDiviPresetsColorsOrVariables(): void
+    {
+        $this->assertSame([], $this->diviStyleWriteHits($this->files()));
+    }
+
+    public function testTheStyleWriteScanCatchesPlantedViolations(): void
+    {
+        $planted = [
+            'src/a.php' => "<?php \\ET\\Builder\\Packages\\GlobalData\\GlobalPreset::save_data(\$d);",
+            'src/b.php' => "<?php GlobalData::set_global_colors(\$c);",
+            'src/c.php' => "<?php GlobalData::set_global_variables(\$v); GlobalData::import_global_variables(\$v);",
+            'src/d.php' => "<?php et_update_option('x', 1);",
+            'src/e.php' => "<?php update_option('et_global_data', \$x);",
+            'src/f.php' => "<?php GlobalPreset::process_presets(\$p);",
+            'src/g.php' => "<?php get_option('et_divi_builder_global_presets_d5');",
+        ];
+        $hits = $this->diviStyleWriteHits($planted);
+        foreach (array_keys($planted) as $rel) {
+            $this->assertNotEmpty(array_filter($hits, fn($h) => str_starts_with($h, $rel)), "style-write scan missed {$rel}");
+        }
+        $this->assertSame([], $this->diviStyleWriteHits(['src/ok.php' => "<?php GlobalPreset::get_data(); GlobalData::get_global_colors(); GlobalData::get_global_variables();"]));
+    }
+
+    public function testTheStylesToolIsReadOnlyEverywhere(): void
+    {
+        $rest = (string) file_get_contents(self::ROOT . '/src/RestController.php');
+        $this->assertMatchesRegularExpression("/'\/global-styles',\s*\[\s*'methods'\s*=>\s*WP_REST_Server::READABLE/", $rest);
+        $spec = \AiEditorDivi5\WP\OpenApiSpec::spec('https://s.example/wp-json/ai-editor-divi5/v1', '4.4.0');
+        $this->assertSame(['get'], array_keys($spec['paths']['/global-styles']));
+        $r = (string) file_get_contents(self::ROOT . '/readme.txt');
+        $this->assertStringContainsString('`list_global_styles`', $r);
+        $this->assertStringContainsString('(17 tools)', $r);
+        $this->assertStringContainsString('It cannot create or change presets or colors', $r, 'the readme must say plainly that presets and colors are read-only');
+    }
 }
```

`tests/WpOrgNamingTest.php`

```diff
diff --git a/tests/WpOrgNamingTest.php b/tests/WpOrgNamingTest.php
index 8df8f27..a5b5264 100644
--- a/tests/WpOrgNamingTest.php
+++ b/tests/WpOrgNamingTest.php
@@ -41,8 +41,8 @@ class WpOrgNamingTest extends TestCase
         $h = (string) file_get_contents($this->root . '/' . self::SLUG . '.php');
         $this->assertMatchesRegularExpression('/^\s*\*\s*Plugin Name:\s+JHMG AI Editor for Divi 5\s*$/m', $h);
         $this->assertMatchesRegularExpression('/^\s*\*\s*Text Domain:\s+' . preg_quote(self::SLUG, '/') . '\s*$/m', $h);
-        $this->assertMatchesRegularExpression('/^\s*\*\s*Version:\s+4\.3\.0\s*$/m', $h);
-        $this->assertStringContainsString("define('AI_EDITOR_DIVI5_VERSION', '4.3.0');", $h);
+        $this->assertMatchesRegularExpression('/^\s*\*\s*Version:\s+4\.4\.0\s*$/m', $h);
+        $this->assertStringContainsString("define('AI_EDITOR_DIVI5_VERSION', '4.4.0');", $h);
         $this->assertDoesNotMatchRegularExpression('/Tested up to/i', $h, 'Tested up to belongs only in readme.txt');
     }
 
@@ -73,6 +73,6 @@ class WpOrgNamingTest extends TestCase
         $r = (string) file_get_contents($this->root . '/readme.txt');
         $this->assertStringStartsWith('=== JHMG AI Editor for Divi 5 ===', $r);
         $this->assertMatchesRegularExpression('/^Contributors:\s+lucaslopvet\s*$/m', $r);
-        $this->assertMatchesRegularExpression('/^Stable tag:\s+4\.3\.0\s*$/m', $r);
+        $this->assertMatchesRegularExpression('/^Stable tag:\s+4\.4\.0\s*$/m', $r);
     }
 }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `./vendor/bin/phpunit tests/WpOrgComplianceTest.php tests/WpOrgNamingTest.php`

Expected: FAIL. the readme and plugin header still say 4.3.0 / 16 tools and do not mention the tool (measured: `Tests: 24, Assertions: 221, Failures: 3.`)

- [ ] **Step 3: Update the readme, the plugin header and the Features card**

**Modify** (one diff per file; apply each with `git apply --ignore-whitespace`, see the note under Global Constraints):

`wp-plugin/jhmg-ai-editor-for-divi-5.php`

```diff
diff --git a/wp-plugin/jhmg-ai-editor-for-divi-5.php b/wp-plugin/jhmg-ai-editor-for-divi-5.php
index f3a9817..37b8e9b 100644
--- a/wp-plugin/jhmg-ai-editor-for-divi-5.php
+++ b/wp-plugin/jhmg-ai-editor-for-divi-5.php
@@ -2,7 +2,7 @@
 /**
  * Plugin Name:       JHMG AI Editor for Divi 5
  * Description:       Let your AI assistant (Claude, ChatGPT, Cursor) read and edit Divi 5 pages with natural language. Every AI edit is checked before it is saved, so a broken page is never saved by an AI edit.
- * Version:           4.3.0
+ * Version:           4.4.0
  * Requires at least: 6.0
  * Requires PHP:      8.1
  * Author:            JHMG
@@ -19,7 +19,7 @@ if (!defined('ABSPATH')) {
     exit;
 }
 
-define('AI_EDITOR_DIVI5_VERSION', '4.3.0');
+define('AI_EDITOR_DIVI5_VERSION', '4.4.0');
 define('AI_EDITOR_DIVI5_MIN_PHP', '8.1');
 define('AI_EDITOR_DIVI5_MIN_WP',  '6.0');
 define('AI_EDITOR_DIVI5_FILE',    __FILE__);
```

`wp-plugin/readme.txt`

```diff
diff --git a/wp-plugin/readme.txt b/wp-plugin/readme.txt
index f4335e5..818ead8 100644
--- a/wp-plugin/readme.txt
+++ b/wp-plugin/readme.txt
@@ -3,7 +3,7 @@ Contributors:      lucaslopvet
 Tags:              divi, divi 5, ai, mcp, editor
 Requires at least: 6.0
 Tested up to:      7.1
-Stable tag:        4.3.0
+Stable tag:        4.4.0
 Requires PHP:      8.1
 License:           GPL-2.0-or-later
 License URI:       https://www.gnu.org/licenses/gpl-2.0.html
@@ -28,6 +28,7 @@ Tell your AI assistant what you want changed on your Divi 5 website, in your own
 * **Rules come from real Divi 5, not guesses.** The rules come from real Divi 5 page exports and, for 24 newer modules (and their child items), from Divi's own module definitions, verified by rendering them in real Divi 5.14.
 * **Small changes stay small.** Changing one phone number replaces just that text. The rest of the page is not rebuilt or touched.
 * **Preview before saving.** Your AI can ask what a change would do before it saves anything. It gets a list of the blocks and settings that would change, and the checker's verdict. Nothing is saved until it repeats the request for real.
+* **Matches your site.** Your AI can look up your site's own Divi colors and presets and reuse them, and it is told when a page uses one your site does not have. It cannot create or change presets or colors: that stays in Divi's Visual Builder.
 * **Header and footer too.** Your AI can preview and edit the content of your existing Theme Builder header and footer, for example a phone number or a link, with the same checker, preview and undo as for pages. The checker still has to accept the result, so some headers may not be editable yet. It cannot create, delete, assign or publish templates: that stays in Divi's Theme Builder.
 * **Proven building blocks.** 17 ready-made section patterns (heroes, feature grids, testimonials, contact forms and more), each one tested against the checker, plus landing-page guidance on the order of sections, headlines and calls to action.
 * **Sensible images.** The AI looks in your Media Library first. When nothing there fits, it uses a built-in pack of 44 original images that ships with the plugin.
@@ -43,13 +44,14 @@ Tell your AI assistant what you want changed on your Divi 5 website, in your own
 * "Add a section with our opening hours under the hero on the About page."
 * "Undo the last change you made to the Services page."
 
-= What your AI assistant can do (16 tools) =
+= What your AI assistant can do (17 tools) =
 
 Read and check:
 
 * `list_divi_pages`: list the pages built with Divi 5 that you can edit.
 * `get_page_layout`: read a page's current layout.
 * `list_theme_layouts`: list the header and footer of your Divi Theme Builder that the AI is allowed to edit (read-only).
+* `list_global_styles`: list your site's own Divi colors and presets so the AI can reuse them (read-only).
 * `validate_layout`: check a layout without saving anything.
 
 Edit and create:
@@ -125,7 +127,7 @@ This plugin does not connect to any external service. Your AI assistant connects
 
 = Does the AI get administrator access to my site? =
 
-No. The API key works as the WordPress user who created it, and the AI can only use this plugin's tools. Every request checks that this user can still edit pages, and every page listing, read or change, every new page and every Media Library lookup re-checks that user's WordPress permissions (pages they cannot edit are not even listed). The tools can only list, read, check, edit and undo the content of pages and of your existing Theme Builder header and footer, create pages (as drafts), and read Media Library images (read-only). Editing a header or footer needs a user whom Divi allows to use its Theme Builder (by default, administrators), and the tools cannot create, delete, assign or publish Theme Builder templates. They cannot install plugins, change users, settings, menus or the front page, or run PHP or other server-side code.
+No. The API key works as the WordPress user who created it, and the AI can only use this plugin's tools. Every request checks that this user can still edit pages, and every page listing, read or change, every new page and every Media Library lookup re-checks that user's WordPress permissions (pages they cannot edit are not even listed). The tools can only list, read, check, edit and undo the content of pages and of your existing Theme Builder header and footer, create pages (as drafts), read Media Library images (read-only), and read your site's Divi presets and colors (read-only). Editing a header or footer needs a user whom Divi allows to use its Theme Builder (by default, administrators), and the tools cannot create, delete, assign or publish Theme Builder templates. They cannot install plugins, change users, settings, menus or the front page, or run PHP or other server-side code.
 
 = Where do images come from? =
 
@@ -163,6 +165,11 @@ No. The plugin adds no scripts or styles to your public pages. It only adds its
 
 == Changelog ==
 
+= 4.4.0 =
+* New: your AI assistant can look up your site's own Divi colors and presets, so the pages it builds can match your site. A new read-only tool, `list_global_styles`, lists them.
+* New: when a page uses a color or preset your site does not have, the save and preview results now say so. The save still goes ahead, and Divi shows that part of the page with its default look.
+* It cannot create or change presets or colors.
+
 = 4.3.0 =
 * New: your AI assistant can now preview and edit the content of your existing Theme Builder header and footer, with the same checker, preview and undo as pages. The checker still has to accept the result. A new read-only tool, `list_theme_layouts`, shows which header and footer it can edit.
 * It cannot create, delete, assign or publish Theme Builder templates, and body layouts are not editable.
@@ -210,6 +217,9 @@ No. The plugin adds no scripts or styles to your public pages. It only adds its
 
 == Upgrade Notice ==
 
+= 4.4.0 =
+Adds a read-only look-up of your site's Divi colors and presets for your AI assistant, and a note when a page uses one your site does not have. Nothing changes unless your AI assistant uses it.
+
 = 4.3.0 =
 Adds editing of your existing Theme Builder header and footer by your AI assistant (with preview and undo). Nothing changes unless your AI assistant uses it.
 
```

`wp-plugin/src/AdminPage.php`

```diff
diff --git a/wp-plugin/src/AdminPage.php b/wp-plugin/src/AdminPage.php
index 1bf7495..c246560 100644
--- a/wp-plugin/src/AdminPage.php
+++ b/wp-plugin/src/AdminPage.php
@@ -576,12 +576,13 @@ final class AdminPage
 
     private function viewFeatures(): void
     {
-        // [title, description, tools it covers] — the 16 tools your AI assistant can call.
+        // [title, description, tools it covers] — the 17 tools your AI assistant can call.
         $features = [
             [ __( 'Edit pages in plain English', 'jhmg-ai-editor-for-divi-5' ), __( 'Tell your AI what to change and it updates the live Divi 5 layout — no builder, no copy-paste.', 'jhmg-ai-editor-for-divi-5' ), 'update_page_layout, edit_page_content' ],
             [ __( 'Validated, safe saves', 'jhmg-ai-editor-for-divi-5' ), __( 'Every change is checked against fixed rules for more than 80 Divi 5 block types before saving. A broken layout is refused with the exact reason, so it is never saved.', 'jhmg-ai-editor-for-divi-5' ), 'validate_layout' ],
             [ __( 'Read & understand any page', 'jhmg-ai-editor-for-divi-5' ), __( 'Your AI can list and read existing pages to make precise, context-aware edits.', 'jhmg-ai-editor-for-divi-5' ), 'list_divi_pages, get_page_layout' ],
             [ __( 'Edit your existing header and footer', 'jhmg-ai-editor-for-divi-5' ), __( 'Your AI can preview and edit the content of your live Theme Builder header and footer, with the same checker, preview and undo as for pages. It cannot create, delete, assign or publish templates.', 'jhmg-ai-editor-for-divi-5' ), 'list_theme_layouts' ],
+            [ __( 'Match your site\'s colors and presets', 'jhmg-ai-editor-for-divi-5' ), __( 'Your AI can look up your site\'s own Divi colors and presets (read-only) and reuse them, and it is told when a page uses one your site does not have.', 'jhmg-ai-editor-for-divi-5' ), 'list_global_styles' ],
             [ __( 'Undo AI edits', 'jhmg-ai-editor-for-divi-5' ), __( 'Every AI save keeps the previous version, so you or your AI can browse the history and restore an earlier one.', 'jhmg-ai-editor-for-divi-5' ), 'list_page_history, get_page_history_entry, restore_page_version' ],
             [ __( 'Create new pages (as drafts)', 'jhmg-ai-editor-for-divi-5' ), __( 'Generate brand-new pages from a prompt — always saved as a draft, validated, ready for you to review and publish.', 'jhmg-ai-editor-for-divi-5' ), 'create_page' ],
             [ __( 'Build entire websites', 'jhmg-ai-editor-for-divi-5' ), __( 'A built-in blueprint helps your AI plan a cohesive multi-page site (home, about, services, contact) with shared styling and links between the pages, then tells you how to finish setting it up yourself.', 'jhmg-ai-editor-for-divi-5' ), 'get_site_guide' ],
```

- [ ] **Step 4: Run the whole suite**

Run: `./vendor/bin/phpunit`

Expected: `OK (567 tests, 3859 assertions)`

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "release: 4.4.0, read-only global styles (readme, Features card, compliance guard)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```


### Task 7: Real-site verification, docs, zip, Plugin Check

**Files:**
- Create: `scripts/verify-global-styles.php`
- Modify: `Makefile` (target `verify-global-styles`)
- Modify: `CLAUDE.md` (17 tools, a "Global styles (v4.4.0)" paragraph, the make-target row, Current state)
- Rebuild: `jhmg-ai-editor-for-divi-5.zip` (tracked at the repo root)

**Interfaces:**
- Consumes: the finished plugin code. The Docker dev site mounts the working tree's `./wp-plugin` (so stay on branch `feat/global-styles`) and `./scripts`; it therefore already runs the 4.4.0 code. The Docker environment must be up (`docker compose ps` shows wordpress and wpcli running).
- Produces: `make verify-global-styles` (real-site proof; read-only; creates and deletes one throwaway draft page); the rebuilt zip; Plugin Check results for the dev copy and the extracted zip.

- [ ] **Step 1: Add the verification script, the Makefile target and the docs**

**Create** `scripts/verify-global-styles.php` with exactly this content:

```php
<?php
/**
 * Real-site check for list_global_styles and the style-reference advisory. The unit tests cover the
 * logic against shims; this drives the REAL REST and MCP handlers on the Docker site with Divi's real
 * preset, color and variable data, and proves the plugin only READS them: the stored presets, global
 * colors and variables are byte-identical before and after.
 *
 * Run: make verify-global-styles   (needs `make up` and the plugin active on the dev site)
 * It creates ONE throwaway draft page, exercises it, and deletes it again.
 */

use AiEditorDivi5\WP\GlobalStyles;
use AiEditorDivi5\WP\UsageTracker;
use Divi5Validator\Validator;
use ET\Builder\Packages\GlobalData\GlobalData;
use ET\Builder\Packages\GlobalData\GlobalPreset;

if ( ! class_exists( GlobalStyles::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 (4.4.0 or later) on this site first.' );
}
if ( ! class_exists( GlobalPreset::class ) || ! class_exists( GlobalData::class ) ) {
    WP_CLI::error( "Divi 5's GlobalData classes are not loaded: activate the Divi theme on this site first." );
}

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
};
$layout = static function ( string $title, array $extra = [] ): string {
    $attrs = $extra + [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => $title ] ] ], 'builderVersion' => '5.14.0' ];

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
$mcp = static function ( string $tool, array $args ): array {
    $req = new WP_REST_Request( 'POST', '/ai-editor-divi5/v1/mcp' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => (object) $args ] ] ) );
    // The MCP endpoint accepts only the plugin API key (Bearer), never a plain logged-in user.
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) get_option( 'ai_editor_divi5_api_key', '' );
    $res                           = rest_do_request( $req );
    unset( $_SERVER['HTTP_AUTHORIZATION'] );
    wp_set_current_user( 1 ); // authenticateRequest() switched to the key owner; back to --user=1 for the REST calls
    $data = (array) $res->get_data();
    if ( isset( $data['error'] ) ) {
        return [ 'error' => $data['error'], 'isError' => true, 'result' => null ];
    }
    $text = $data['result']['content'][0]['text'] ?? '';

    return [ 'error' => null, 'isError' => (bool) ( $data['result']['isError'] ?? false ), 'result' => json_decode( (string) $text, true ) ];
};
/** A fingerprint of everything Divi stores about presets, global colors and variables. */
$fingerprint = static function (): string {
    $divi = (array) get_option( 'et_divi', [] );

    return md5( serialize( [
        get_option( 'et_divi_builder_global_presets_d5', null ),
        get_option( 'et_divi_global_variables', null ),
        $divi['et_global_data'] ?? null,
    ] ) );
};

$fpBefore = $fingerprint();

// ---- 1. the catalog matches Divi's own read API -------------------------------------------------
$presetData = GlobalPreset::get_data();
$expected   = 0;
$defaults   = [];
foreach ( (array) ( $presetData['module'] ?? [] ) as $name => $entry ) {
    if ( ! is_string( $name ) || ! is_array( $entry ) || ! is_array( $entry['items'] ?? null ) ) {
        continue;
    }
    foreach ( $entry['items'] as $item ) {
        if ( is_array( $item ) && is_string( $item['id'] ?? null ) && '' !== $item['id'] && is_string( $item['name'] ?? null ) ) {
            ++$expected;
        }
    }
    if ( is_string( $entry['default'] ?? null ) && isset( $entry['items'][ $entry['default'] ] ) ) {
        $defaults[ $name ] = $entry['default'];
    }
}
$activeColors = array_filter( GlobalData::get_global_colors(), static fn ( $c ) => is_array( $c ) && 'active' === ( $c['status'] ?? 'active' ) );

$r = $rest( 'GET', '/global-styles' );
$d = $r['data'];
$check( 200 === $r['status'], 'REST GET /global-styles: 200' );
$check( $expected === count( $d['presets'] ?? [] ) && $expected === ( $d['counts']['presets'] ?? -1 ), "the catalog lists every preset Divi reports ({$expected})" );
$check( count( $activeColors ) === count( $d['colors'] ?? [] ), 'the catalog lists every active global color (' . count( $activeColors ) . ')' );
$themeIds = array_column( array_filter( $d['colors'] ?? [], static fn ( $c ) => 'theme' === $c['source'] ), 'id' );
sort( $themeIds );
$check( [ 'gcid-body-color', 'gcid-heading-color', 'gcid-link-color', 'gcid-primary-color', 'gcid-secondary-color' ] === $themeIds, 'the five theme colors are listed with source "theme"' );
$flagged = [];
foreach ( $d['presets'] ?? [] as $p ) {
    if ( $p['default'] ) {
        $flagged[ $p['module'] ] = $p['id'];
    }
}
ksort( $defaults );
ksort( $flagged );
$check( $defaults === $flagged, 'exactly the default preset of each module is flagged default' );
$check( 0 === count( array_diff_key( $d, array_flip( [ 'presets', 'colors', 'variables', 'counts', 'truncated', 'notes' ] ) ) ), 'the response has exactly the documented keys' );
$check( false !== wp_json_encode( $d ), 'the response encodes as JSON' );

$m = $mcp( 'list_global_styles', [] );
$check( ! $m['isError'] && $m['result'] === json_decode( wp_json_encode( $d ), true ), 'MCP list_global_styles returns the same catalog as REST' );

$firstModule = $d['presets'][0]['module'] ?? '';
if ( '' !== $firstModule ) {
    $r = $rest( 'GET', '/global-styles', [], [ 'module' => $firstModule ] );
    $check( 200 === $r['status'] && [] !== $r['data']['presets'] && [ $firstModule ] === array_values( array_unique( array_column( $r['data']['presets'], 'module' ) ) ), "?module={$firstModule} lists only that module's presets" );
}
$r = $rest( 'GET', '/global-styles', [], [ 'module' => [ 'divi/text' ] ] );
$check( 400 === $r['status'], 'REST: a non-string module is rejected (400)' );
$m = $mcp( 'list_global_styles', [ 'module' => [ 'divi/text' ] ] );
$check( true === $m['isError'] && -32602 === ( $m['error']['code'] ?? null ), 'MCP: a non-string module is rejected (-32602)' );

// ---- 2. the advisory on the real handlers ---------------------------------------------------------
$knownPreset = '';
foreach ( $d['presets'] ?? [] as $p ) {
    if ( ! $p['default'] ) {
        $knownPreset = $p['id'];
        break;
    }
}
$knownColor   = $d['colors'][0]['id'] ?? 'gcid-primary-color';
$unknownP     = '11111111-2222-4333-8444-555555555555';
$unknownC     = 'gcid-00000000-0000-4000-8000-000000000000';
$colorAttr    = static fn ( string $id ): array => [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Probe' ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => '$variable(' . wp_json_encode( [ 'type' => 'color', 'value' => [ 'name' => $id, 'settings' => new stdClass() ] ] ) . ')$' ] ] ] ] ] ] ];
$clean        = $layout( 'Probe', [ 'modulePreset' => [ $knownPreset ?: 'default' ] ] + $colorAttr( $knownColor ) );
$dirty        = $layout( 'Probe', [ 'modulePreset' => [ $unknownP ] ] + $colorAttr( $unknownC ) );
$check( ( new Validator() )->validateContent( $dirty )->isValid(), 'the probe layout with unknown ids passes the checker (the advisory is not a violation)' );

$pageId = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'aied global-styles check', 'post_content' => $clean ], true );
if ( is_wp_error( $pageId ) ) {
    WP_CLI::error( $pageId->get_error_message() );
}

try {
    $before = [ get_post( $pageId )->post_content, get_post( $pageId )->post_modified_gmt ];

    $r = $rest( 'POST', '/validate', [ 'post_content' => $clean ] );
    $check( 200 === $r['status'] && true === $r['data']['valid'] && ! array_key_exists( 'advisories', $r['data'] ), 'REST /validate of a layout whose ids all exist: no advisories key' );
    $r = $rest( 'POST', '/validate', [ 'post_content' => $dirty ] );
    $adv = $r['data']['advisories']['style_references'] ?? [];
    $check( 200 === $r['status'] && true === $r['data']['valid'], 'REST /validate of the unknown-id layout: still 200 and valid' );
    $check( [ $unknownP ] === array_column( $adv['unknown_presets'] ?? [], 'id' ), 'the advisory names exactly the unknown preset id' );
    $check( [ $unknownC ] === array_column( $adv['unknown_colors'] ?? [], 'id' ), 'the advisory names exactly the unknown color id' );

    $m = $mcp( 'validate_layout', [ 'post_content' => $dirty ] );
    $check( ! $m['isError'] && [ $unknownP ] === array_column( $m['result']['advisories']['style_references']['unknown_presets'] ?? [], 'id' ), 'MCP validate_layout reports the same unknown preset' );

    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $dirty, 'dry_run' => true ] );
    $check( 200 === $r['status'] && false === $r['data']['saved'] && true === $r['data']['dry_run'] && isset( $r['data']['advisories']['style_references'] ), 'REST PUT dry_run carries the advisory and saves nothing' );
    $m = $mcp( 'update_page_layout', [ 'page_id' => $pageId, 'post_content' => $dirty, 'dry_run' => true ] );
    $check( ! $m['isError'] && isset( $m['result']['advisories']['style_references'] ), 'MCP update_page_layout dry_run carries the advisory' );

    $post = get_post( $pageId );
    $check( [ $post->post_content, $post->post_modified_gmt ] === $before, 'the page is byte-identical after the previews' );

    $r = $rest( 'PUT', "/pages/{$pageId}", [ 'post_content' => $dirty ] );
    $check( 200 === $r['status'] && true === $r['data']['saved'] && isset( $r['data']['advisories']['style_references'] ), 'a real save with unknown ids still saves, and carries the advisory' );
    $check( get_post( $pageId )->post_content === $dirty, 'the saved content is exactly what was sent (the advisory never rewrites content)' );

    // ---- 3. usage log ------------------------------------------------------------------------------
    global $wpdb;
    $usage = UsageTracker::tableName();
    $n     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$usage} WHERE endpoint = %s AND result = %s", 'list_global_styles', 'read' ) ); // phpcs:ignore
    $check( $n >= 2, "list_global_styles reads appear in the usage log ({$n})" );
} catch ( \Throwable $e ) {
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    if ( null !== get_post( $pageId ) ) {
        wp_delete_post( $pageId, true );
    }
}

// ---- 4. read-only proof ---------------------------------------------------------------------------------
$check( $fingerprint() === $fpBefore, 'presets, global colors and variables are byte-identical after everything above (read-only)' );
$check( null === get_post( $pageId ), 'the throwaway page is gone' );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'list_global_styles and the style-reference advisory verified on the real REST and MCP handlers.' );```

**Modify** (one diff per file; apply each with `git apply --ignore-whitespace`, see the note under Global Constraints):

`CLAUDE.md`

```diff
diff --git a/CLAUDE.md b/CLAUDE.md
index 02681cc..eceabec 100644
--- a/CLAUDE.md
+++ b/CLAUDE.md
@@ -77,8 +77,8 @@ PHP classes in `wp-plugin/src/`:
 | `get_image_guide` (`ImageGuide`) | which image fits each section: Media Library first, then the built-in pack |
 | `get_section_recipes` (`SectionRecipes` + `data/section-recipes.json`) | 17 proven, validated section patterns, each mapped to a persuasion stage |
 
-**16 MCP tools, all free:** `list_divi_pages`, `get_page_layout`, `list_theme_layouts`
-(read-only, `ThemeLayouts`), `validate_layout`, `update_page_layout`, `edit_page_content` (surgical find-and-replace), `create_page`
+**17 MCP tools, all free:** `list_divi_pages`, `get_page_layout`, `list_theme_layouts`
+(read-only, `ThemeLayouts`), `list_global_styles` (read-only site presets and colors, `GlobalStyles`), `validate_layout`, `update_page_layout`, `edit_page_content` (surgical find-and-replace), `create_page`
 (always a draft), `list_page_history`, `get_page_history_entry`,
 `restore_page_version`, `list_media_images` (read-only Media Library listing,
 `MediaLibrary` + `MediaService`, needs `upload_files` + per-item `read_post`) and the
@@ -137,6 +137,19 @@ templates or writes `_et_use_on`/`_et_default`/`_et_enabled`/`*_layout_id`/`_et_
 settings: Pro add-on territory). Unverified: that a REAL builder-made header passes the validator
 (see the spec); until a real export exists the readme claims only what the checker accepts.
 
+Global styles (v4.4.0): `list_global_styles` (MCP / `GET /global-styles` / OpenAPI `listGlobalStyles`, read-only)
+lists the site's own Divi 5 module presets, global colors and variables, read ONLY through Divi's own public
+read functions (`GlobalPreset::get_data()`, `GlobalData::get_global_colors()` / `get_global_variables()`, each behind
+`class_exists`/`is_callable` guards; a missing or throwing reader means an empty section plus a note) by `GlobalStyles`.
+`StyleReferences` adds a non-blocking `advisories.style_references` to `validate_layout`, `update_page_layout`,
+`edit_page_content`, `create_page` and every `dry_run` preview (and the REST equivalents) when a layout cites a preset id
+or `gcid-` color id the site does not have: Divi silently falls back to the module's default preset for an unknown preset
+and leaves an unknown color undefined (live-render proof, 2026-10-01). It never changes `valid`, never blocks a save and
+never rewrites content; a refused save carries violations only. The free plugin NEVER writes presets, colors or
+variables (`tests/WpOrgComplianceTest.php` bans Divi's save functions and the storage names); non-color variables are
+listed but never taught (no real export uses them). The validator stays site-agnostic. Spec:
+`docs/superpowers/specs/2026-10-01-global-styles-design.md`.
+
 Same logic is exposed three ways, kept in lockstep: MCP (`McpHandler`), REST
 (`RestController`), and the ChatGPT OpenAPI spec (`OpenApiSpec`). Add or change a
 tool → update all three.
@@ -149,7 +162,7 @@ tool → update all three.
 that save CSS/PHP or change site settings (front page, menus, users, plugins). The
 only Pro mention allowed in the plugin UI is the one dismissible Dashboard card in
 `AdminPage::proCard()`. `tests/WpOrgComplianceTest.php` enforces all of this (plus
-the URL-host allowlist, the 16-tool list and the readme's claims, including the
+the URL-host allowlist, the 17-tool list and the readme's claims, including the
 "more than N Divi 5 block types" number checked against `SchemaRules`). Never weaken
 that test to make it pass — fix the plugin, or add a justified explicit exception.
 
@@ -168,6 +181,7 @@ Docker env + validator workflows go through `make`:
 | `make verify-history` | Real-database check of the undo-history table on the Docker site (creates and deletes one throwaway draft page) |
 | `make verify-dry-run` | Real-site check of `dry_run` on the REST and MCP handlers (creates and deletes one throwaway draft page) |
 | `make verify-theme-layouts` | Real-site check of header/footer editing: builds its own Theme Builder fixtures with Divi's functions, drives the REAL handlers, removes only what it created |
+| `make verify-global-styles` | Real-site check of `list_global_styles` and the style-reference advisory on the REST and MCP handlers; proves presets, global colors and variables are byte-identical afterwards (creates and deletes one throwaway draft page) |
 | `make clean` | Destroy volumes (prompts for confirmation) |
 
 Tests run via PHPUnit (`phpunit.xml`) and cover both the validator
@@ -207,6 +221,9 @@ Docker env and `make export-layouts`. Do not attempt to download it. If missing,
 
 ## Current state
 
+- **4.4.0 = global styles release** (branch `feat/global-styles`; NOT uploaded; merge to local main only with the owner's OK):
+  read-only `list_global_styles` (17th tool) + non-blocking style-reference advisory + guide updates (spec
+  `docs/superpowers/specs/2026-10-01-global-styles-design.md`). Ships on top of 4.3.0, after the first WordPress.org approval.
 - **4.3.0 = header/footer release** (merged to LOCAL main, 837b712; NOT uploaded): edit the existing live Theme Builder
   header/footer (spec `docs/superpowers/specs/2026-10-01-theme-layouts-design.md`). Intended to ship as its own update
   AFTER the first WordPress.org approval. 4.1.0 + 4.2.0 alone = main at commit 9e806a3 (the 4.2.0 merge); cut the upload
```

`Makefile`

```diff
diff --git a/Makefile b/Makefile
index 86b8c36..65adabb 100644
--- a/Makefile
+++ b/Makefile
@@ -1,4 +1,4 @@
-.PHONY: up down clean test validate install shell-wp shell-wpcli export-layouts app-password schema-gap verify-modules verify-history verify-dry-run verify-theme-layouts
+.PHONY: up down clean test validate install shell-wp shell-wpcli export-layouts app-password schema-gap verify-modules verify-history verify-dry-run verify-theme-layouts verify-global-styles
 
 # ---------------------------------------------------------------
 # up — bootstrap the full environment (idempotent)
@@ -93,6 +93,12 @@ verify-dry-run:
 verify-theme-layouts:
 	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-theme-layouts.php --user=1
 
+# ---------------------------------------------------------------
+# verify-global-styles — real-site check of list_global_styles and the style-reference advisory (Docker site; read-only; one throwaway draft page)
+# ---------------------------------------------------------------
+verify-global-styles:
+	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-global-styles.php --user=1
+
 # ---------------------------------------------------------------
 # shell helpers
 # ---------------------------------------------------------------
```

- [ ] **Step 2: Run the real-site check**

Run: `make verify-global-styles`

Expected: 25 lines starting with `PASS`, no `FAIL`, ending with `Success: list_global_styles and the style-reference advisory verified on the real REST and MCP handlers.` Then confirm no probe page is left: `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp db query "SELECT COUNT(*) AS leftover FROM wp_posts WHERE post_title LIKE 'aied global-styles%'"` prints `0`.

If a check fails, STOP and report it with the output: do not edit the script to make it pass.

- [ ] **Step 3: Re-run the existing real-site checks (the handlers they drive were touched)**

Run each, expecting `Success`:
`make verify-dry-run`, `make verify-history`, `make verify-theme-layouts`

- [ ] **Step 4: Plugin Check on the dev copy**

Run: `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin check jhmg-ai-editor-for-divi-5`

Expected: `Success: Checks complete. No errors found.`

- [ ] **Step 5: Rebuild the zip and run Plugin Check on the extracted zip**

```bash
bash scripts/build-plugin-zip.sh
rm -rf /tmp/aied-zipcheck && mkdir /tmp/aied-zipcheck && unzip -q jhmg-ai-editor-for-divi-5.zip -d /tmp/aied-zipcheck
docker compose cp /tmp/aied-zipcheck/jhmg-ai-editor-for-divi-5 wpcli:/var/www/html/wp-content/plugins/aied-zipcheck
docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin check aied-zipcheck --slug=jhmg-ai-editor-for-divi-5
docker compose exec -T -u root wpcli rm -rf /var/www/html/wp-content/plugins/aied-zipcheck
rm -rf /tmp/aied-zipcheck
```

Expected: the build prints `Packaging jhmg-ai-editor-for-divi-5 version 4.4.0`; Plugin Check prints `Success: Checks complete. No errors found.` NEVER run `wp plugin delete` on anything (it runs uninstall.php against the shared dev database); the folder is removed with `rm -rf` as shown.

- [ ] **Step 6: Run the whole suite**

Run: `./vendor/bin/phpunit`

Expected: `OK (567 tests, 3859 assertions)`

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "release: 4.4.0 verification script, docs and rebuilt zip

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```


---

## Self-Review (run against the spec)

**Spec coverage**

| Spec requirement | Task |
|---|---|
| `GlobalStyles`: injected readers, guarded Divi reads, presets/colors/variables output shapes, sorting, `controls`/`renders`, `module` filter, caps and `truncated`, notes, invalid UTF-8 | 1 |
| `knownIds` (uncapped, null = cannot tell) | 1 |
| `StyleReferences`: `modulePreset` string/list, `default` never unknown, `gcid-` tokens anywhere in attributes, cap 20, null when clean, never alters input | 2 |
| `list_global_styles` in MCP, REST, OpenAPI; 16 to 17 tools/operations; pinned counts and justification; `edit_pages`; type-checked `module`; logged `list_global_styles`/`read`; writes nothing | 3 |
| Advisory on `validate_layout`, `update_page_layout`, `edit_page_content`, `create_page`, dry-run previews, REST equivalents; documented in OpenAPI; absent when clean | 4 |
| `StyleGuide` section (real color-variable shape, default-preset behaviour, never invent ids, non-color variables not taught) and the recipes warning | 5 |
| Readme (tool line, count, bullet, FAQ sentence, changelog), Features card, version 4.4.0 | 6 |
| Compliance guard: no calls to Divi's save functions or storage names, planted-violation proof, tool read-only everywhere | 6 |
| `make verify-global-styles` (real catalog vs Divi's own API, advisory on real handlers, byte-identical options, usage log), Plugin Check x2, zip, docs | 7 |
| Release held: nothing uploaded, nothing pushed | Handoff below |

**Placeholder scan:** the only placeholder in the plan is `<Q>` (Task 5), with its replacement step and a check that none is left. No TBD/TODO.

**Type consistency:** `GlobalStyles::catalog`/`catalogWith`/`knownIds`/`knownIdsWith`/`readers` (Task 1) are the names used in Tasks 2, 3 and 4; `StyleReferences::advise`/`attach`/`MESSAGE`/`MAX_IDS` (Task 2) are the names used in Tasks 4 and 5; `RestController::global_styles` and `McpHandler::toolListGlobalStyles` (Task 3) match the lockstep tests; the OpenAPI schema names (`GlobalPreset`, `GlobalColor`, `GlobalVariable` in Task 3; `StyleRefCount`, `StyleAdvisory`, `Advisories` in Task 4) match the tests that assert them.

**Review Focus:** all five lines have a named test in the owning task.

## Handoff (the controller's checklist after Task 7)

1. Do not merge. Run the whole-branch review on the most capable model over `git diff main...feat/global-styles`, with the spec and this plan as the contract.
2. Apply one scoped fix wave for what that review confirms, re-run `./vendor/bin/phpunit` and `make verify-global-styles`, and rebuild the zip if anything under `wp-plugin/` changed (then Plugin Check again on the dev copy and the extracted zip).
3. Report to the owner: outcomes faithfully (failures included), every ruling made on the owner's behalf, and the two known leftovers: the Features screenshot in `wporg-assets/` predates the 4.3.0 and 4.4.0 cards, and a real builder-made header/footer export still has not been through `validate_layout` (4.3.0's pre-ship item).
4. Ask the owner before merging. Merge to LOCAL main only, then update `CLAUDE.md` "Current state" (4.4.0 merged locally, the merge commit) and the project memory. Never push, upload or send anything.
