# Pro global colors (`manage_global_colors`, Pro 0.7.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One new Pro tool, `manage_global_colors`, that creates, recolors, renames and deactivates the site's Divi global colors, previewed, token-bound, validated and undoable.

**Architecture:** A Divi-guarded adapter (`GlobalColors`) is the only code that talks to Divi's `GlobalData`; a pure planner (`GlobalColorPlanner`) turns the request into an all-or-nothing change list; `ManageGlobalColors` runs preview/real-run with the existing `PreviewToken`, writes the COMPLETE color map once, reads back what Divi really stored and records that as a normal batch of `global_color` items (`GlobalColorKind`) that `undo_batch` already knows how to walk back. The tool is MCP + REST only (the ChatGPT importer's 30 operations are used up).

**Tech Stack:** PHP 8.1 (no standalone `null` return types), PHPUnit, the Pro add-on's shims, Docker WP-CLI for the live gate.

**Spec:** `docs/superpowers/specs/2026-10-05-pro-global-colors-design.md` (approved by the owner 2026-10-05). Read it first, then this plan.

## Global Constraints

- All work is in `pro-addon/`, `scripts/`, `Makefile` and `docs/`. **Nothing under `wp-plugin/` or `src/` changes** (the free plugin and the validator are untouched; the free readme/UI get no Pro mention).
- Nothing in `pro-addon/src/` may contain the literals `et_global_data`, `et_divi_global_variables`, `et_divi_builder_global_presets_d5` or `GlobalPreset::` (`ProSafetyTest` bans them). Divi is reached only through its class `ET\Builder\Packages\GlobalData\GlobalData` (`get_global_colors`, `set_global_colors`, the public static `$customizer_colors`) and the function `et_get_option`, never through option names.
- Capability: `edit_theme_options` (the registry checks it through `ToolRunner`). Licence gate: the existing registry, like every Pro tool.
- Arguments are type-checked, never cast (`Args::*`); a wrong type is a 400 `InvalidArgumentException` whose message is safe to show.
- `colors`: list of 1 to 50 entries `{ id?, label?, color?, status? }`; `dry_run`, `allow_theme_colors` strict booleans; `preview_token` string. No `id` = create (needs `label` and `color`; `status` defaults `active`); `id` = update (needs at least one of `label`, `color`, `status`). `status` is `active` or `inactive`. **No delete.**
- `color`: hex `#rgb`/`#rgba`/`#rrggbb`/`#rrggbbaa`, or `rgb()/rgba()/hsl()/hsla()` of plain numbers (digits, `.`, `%`, `,`, spaces, `/`). Everything else is refused (CSS-injection guard). `label`: 1 to 60 characters, no control characters or angle brackets, then `sanitize_text_field`. `id`: `^gcid-[A-Za-z0-9-]{3,64}$`.
- The five theme (Customizer) ids `gcid-primary-color`, `gcid-secondary-color`, `gcid-heading-color`, `gcid-body-color`, `gcid-link-color` are refused unless `allow_theme_colors: true`; that flag is part of the preview token's argument hash.
- A palette is applied atomically or not at all: any bad entry refuses the whole call with one message listing every problem.
- `set_global_colors()` REPLACES the whole custom map: always send the complete map once, never `$already_sanitized = true` (that merges and skips sanitising). Untouched entries are passed back exactly as `get_global_colors()` returned them. Divi writes only a theme color's `color` (into the Customizer option), so a theme entry may change `color` only.
- Preview token: `PreviewToken` (30 minutes, single use, bound to user and arguments). The per-entry "changed since the preview" hash covers `color`, `label`, `status`, `folder` only, never `lastUpdated`.
- Pro tool count becomes 15, but the ChatGPT spec keeps its 14 Pro operations: `ProOpenApi::MCP_REST_ONLY = [ 'manage_global_colors' ]`; the 16 + 14 = 30 budget stays exact.
- Add-on version 0.7.0 (header, constant, readme.txt Stable tag + changelog, `pro-addon/README.md`, `tests/bootstrap.php`).
- Commit messages end with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Work on branch `feat/pro-global-colors` (already created, spec committed). Never commit `CLAUDE.md`. Never push.

## Deviations from the spec (all narrowing, all deliberate)

1. A **theme color may change `color` only**: Divi stores nothing else for the five Customizer colors (label and status are generated on every read), so a `label`/`status` on a theme id is refused with a reason.
2. The spec's step "re-read just before writing, recompute once" is implemented as **re-read once and abort** (`InvalidArgumentException`, nothing written) if any color differs from what the call planned against. Simpler and fail-closed; the AI just calls again.
3. A created color's `gcid` is generated at each run, so a dry run shows `id: null` for creates and the real run reports the real id.
4. `GlobalColors::read()` returns the **live** Customizer value for the five theme ids (via Divi's own public `$customizer_colors` map and `et_get_option`), because Divi caches the Customizer colors in a static for the whole request and would otherwise report a stale value right after a theme-color write (verified in the shipped `GlobalData.php`: `get_customizer_colors()` keeps a `static $formatted_colors`).
5. `read()` returns `null` (the tool refuses) if any entry is not an array: Divi's replace-all write would silently lose it.

## Review Focus

Failure modes the spec implies but a straight reading of the tasks would not test; each has a named test in the task that owns the code.

1. A pre-existing entry with an EMPTY `color` is dropped by Divi's sanitiser on any replace-all save. The tool must say so (`unexpected_changes`, reason `removed_by_divi`) instead of claiming a clean write. Task 4 `testAPreExistingEntryDiviDropsIsReportedNotHidden`.
2. A theme-color write is read back through Divi's request-long cache: the result must show the NEW color, not the stale one. Task 1 `testReadUsesTheLiveThemeColorNotTheStaleRequestCache`, Task 4 `testAThemeColorNeedsTheFlagAndIsWrittenAndUndone`.
3. Divi stores something other than what was asked (here: a mutated value). The result carries `differs_from_request` and the batch records what Divi REALLY stored, so undo still works. Task 4 `testWhenDiviStoresSomethingElseTheBatchRecordsWhatItStored`.
4. Divi throws while saving, or cannot be read: nothing is recorded, nothing pretends to have worked. Task 1 (`testWriteReportsAThrow...`), Task 4 (`testWhenDiviCannotSaveNothingIsRecorded`, `testWhenDiviCannotBeReadTheCallIsRefused`).
5. Boundaries a person will hit: label of exactly 60 vs 61 characters (counted in characters, not bytes), two new colors whose names differ only in case, an empty `colors` list, 51 entries, `colors` sent as an object. Task 1 `testCleanLabel`, Task 2 `testTwoNewColorsWithTheSameNameAreRefused`, Task 4 `testArgumentsAreTypeChecked`.

## File structure

| File | Responsibility |
|---|---|
| `pro-addon/src/Settings/GlobalColors.php` (new) | Divi adapter + pure helpers: availability, `read()`, `state()`, `customMap()`, `themeEntry()`, `write()`, `stamp()`, color/label/id validators. The ONLY file that names Divi's class. |
| `pro-addon/src/Settings/GlobalColorPlanner.php` (new) | Pure: `normalize()` (argument shapes) and `plan()` (all-or-nothing change list). No Divi, no WordPress state except `sanitize_text_field` via `GlobalColors`. |
| `pro-addon/src/Kinds/GlobalColorKind.php` (new) | `ItemKind` for undo: `itemFor()`, `current()`, `allowed()`, `restore()`. |
| `pro-addon/src/Tools/ManageGlobalColors.php` (new) | The `ProTool`: argument reading, preview/real run, token, write, read-back, batch. |
| `pro-addon/src/autoload.php`, `src/Tools.php`, `src/Batch/Batches.php`, `src/Rest/ProOpenApi.php` (modify) | Wiring. |
| `pro-addon/tests/DiviGlobalDataShims.php`, `tests/GlobalColorsFixture.php` (new) | A faithful stand-in for Divi 5.14's colors API and the shared fixture. |
| `pro-addon/tests/GlobalColorsTest.php`, `GlobalColorPlannerTest.php`, `GlobalColorKindTest.php`, `ManageGlobalColorsTest.php` (new) | Unit tests per task. |
| `pro-addon/tests/ToolsTest.php`, `ProParityTest.php`, `ProOpenApiTest.php`, `ExpiryKeepsAllToolsTest.php` (modify) | Counts, lists, the recorded ChatGPT exception. |
| `scripts/verify-pro-global-colors.php`, `Makefile` (new/modify) | The live gate `make verify-pro-global-colors`. |
| version/readme files (modify) | 0.7.0. |

Run the Pro suite with `make test-pro` (or one class: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter ClassName`). Run the free suite with `make test`.

---

### Task 1: The Divi adapter, validators and test shims

**Files:**
- Create: `pro-addon/tests/DiviGlobalDataShims.php`, `pro-addon/tests/GlobalColorsFixture.php`, `pro-addon/src/Settings/GlobalColors.php`
- Modify: `pro-addon/src/autoload.php`
- Test: `pro-addon/tests/GlobalColorsTest.php`

**Interfaces:**
- Consumes: `AiEditorDivi5\Pro\Canon` (not needed here), WordPress `sanitize_text_field`, Divi `GlobalData`, `et_get_option`.
- Produces (all `public static` on `AiEditorDivi5\Pro\Settings\GlobalColors`):
  - `const DATA_CLASS`, `const THEME_IDS` (list of the five ids), `const MAX_LABEL = 60`
  - `available( ?string $class = null ): bool`
  - `isThemeId( string $id ): bool`
  - `read(): ?array` — `array<string,array<string,mixed>>` id => entry exactly as Divi returned it (theme ids carry the LIVE color); `null` when Divi is missing, throws, or an entry is not an array
  - `state( ?array $entry ): ?array` — `null` or `['color'=>string,'label'=>string,'status'=>string,'folder'=>string]` (this order)
  - `customMap( array $all ): array` — `$all` without the five theme ids
  - `themeEntry( string $color, string $label, string $stamp ): array`
  - `write( array $map ): array` — `['threw'=>bool,'after'=>?array]`; calls `set_global_colors( $map )` exactly once (default sanitising), then `read()`
  - `stamp( int $ts ): string` — `Y-m-d\TH:i:s.000\Z` in UTC
  - `validColor( string ): bool`, `cleanLabel( string ): ?string`, `validId( string ): bool`

- [ ] **Step 1: Write the shims (test support, no production code)**

Create `pro-addon/tests/DiviGlobalDataShims.php`:

```php
<?php

declare(strict_types=1);

/**
 * A stand-in for Divi 5.14's global colors API, with the rules of the shipped source
 * (includes/builder-5/server/Packages/GlobalData/GlobalData.php) that matter here:
 *  - get_global_colors() = the five Customizer colors (rebuilt from theme options, CACHED for the whole request in a
 *    static, with a fresh lastUpdated) merged with the stored custom colors; the result is cached until a save;
 *  - set_global_colors($data, false) sanitises (drops entries with an empty color or a non-gcid id, runs
 *    sanitize_text_field over every value, usedInPosts through array_map), writes a Customizer color into its theme
 *    option and removes it from the map, and REPLACES the whole custom map; it does NOT reset the Customizer cache;
 *  - set_global_colors($data, true) would MERGE and skip sanitising (the add-on never calls it that way).
 * State lives in $GLOBALS['__divi_gc'] (see resetForTests); switches: throw_get, throw_set, mutate (callable applied
 * to the sanitised map), on_get (callable called with the get counter before every read).
 */

namespace ET\Builder\Packages\GlobalData {
    class GlobalData
    {
        /** @var array<string,array<string,string>> */
        public static $customizer_colors = [
            'gcid-primary-color'   => [ 'label' => 'Primary Color', 'option_name' => 'accent_color', 'default' => '#2ea3f2' ],
            'gcid-secondary-color' => [ 'label' => 'Secondary Color', 'option_name' => 'secondary_accent_color', 'default' => '#2ea3f2' ],
            'gcid-heading-color'   => [ 'label' => 'Heading Text Color', 'option_name' => 'header_color', 'default' => '#666666' ],
            'gcid-body-color'      => [ 'label' => 'Body Text Color', 'option_name' => 'font_color', 'default' => '#666666' ],
            'gcid-link-color'      => [ 'label' => 'Link Color', 'option_name' => 'link_color', 'default' => '#2ea3f2' ],
        ];

        /**
         * @param array<string,array<string,mixed>> $custom stored custom colors
         * @param array<string,string> $options theme options by name (accent_color, ...)
         */
        public static function resetForTests( array $custom = [], array $options = [] ): void
        {
            $GLOBALS['__divi_gc'] = [
                'custom' => $custom, 'options' => $options, 'cache' => null, 'formatted' => null, 'clock' => 0,
                'set_calls' => [], 'set_flags' => [], 'gets' => 0,
                'throw_get' => false, 'throw_set' => false, 'mutate' => null, 'on_get' => null,
            ];
            $GLOBALS['__divi_static_clears'] = [];
        }

        /** @return array<string,array<string,mixed>> */
        public static function get_global_colors(): array
        {
            ++$GLOBALS['__divi_gc']['gets'];
            if ( is_callable( $GLOBALS['__divi_gc']['on_get'] ) ) {
                ( $GLOBALS['__divi_gc']['on_get'] )( $GLOBALS['__divi_gc']['gets'] );
            }
            if ( $GLOBALS['__divi_gc']['throw_get'] ) {
                throw new \RuntimeException( 'Divi could not read the colors' );
            }
            if ( null === $GLOBALS['__divi_gc']['cache'] ) {
                $custom = $GLOBALS['__divi_gc']['custom'];
                foreach ( array_keys( self::$customizer_colors ) as $id ) {
                    unset( $custom[ $id ] );
                }
                $GLOBALS['__divi_gc']['cache'] = array_merge( self::get_customizer_colors(), $custom );
            }

            return $GLOBALS['__divi_gc']['cache'];
        }

        /** @return array<string,array<string,mixed>> */
        public static function get_customizer_colors(): array
        {
            if ( null !== $GLOBALS['__divi_gc']['formatted'] ) {
                return $GLOBALS['__divi_gc']['formatted'];
            }
            $out = [];
            foreach ( self::$customizer_colors as $id => $def ) {
                $out[ $id ] = [
                    'color'       => sanitize_text_field( (string) et_get_option( $def['option_name'], $def['default'] ) ),
                    'folder'      => 'customizer',
                    'label'       => $def['label'],
                    'lastUpdated' => '2026-02-01T00:00:00.' . sprintf( '%03d', ++$GLOBALS['__divi_gc']['clock'] ) . 'Z',
                    'status'      => 'active',
                    'usedInPosts' => [],
                ];
            }

            return $GLOBALS['__divi_gc']['formatted'] = $out;
        }

        /**
         * @param array<string,mixed> $data
         * @return array<string,array<string,mixed>>
         */
        public static function sanitize_global_colors_data( array $data ): array
        {
            $out = [];
            foreach ( $data as $id => $item ) {
                if ( 'undefined' === $id || empty( $item ) || empty( $item['color'] ) || 'gcid-' !== substr( (string) $id, 0, 5 ) ) {
                    continue;
                }
                $gid = sanitize_text_field( (string) $id );
                foreach ( $item as $k => $v ) {
                    $out[ $gid ][ sanitize_text_field( (string) $k ) ] = 'usedInPosts' === $k ? array_map( 'sanitize_text_field', $v ) : sanitize_text_field( $v );
                }
            }

            return $out;
        }

        /** @param array<string,mixed> $data */
        public static function set_global_colors( array $data, $already_sanitized = false ): void
        {
            $s = &$GLOBALS['__divi_gc'];
            $s['set_calls'][] = $data;
            $s['set_flags'][] = $already_sanitized;
            if ( $s['throw_set'] ) {
                throw new \RuntimeException( 'Divi refused the save' );
            }
            $s['cache'] = null; // NOT 'formatted': Divi's Customizer cache survives a save, within the request
            if ( ! $already_sanitized ) {
                $data = self::sanitize_global_colors_data( $data );
            }
            if ( is_callable( $s['mutate'] ) ) {
                $data = ( $s['mutate'] )( $data );
            }
            foreach ( self::$customizer_colors as $id => $def ) {
                if ( isset( $data[ $id ]['color'] ) ) {
                    et_update_option( $def['option_name'], $data[ $id ]['color'] );
                }
                unset( $data[ $id ] );
            }
            $s['custom'] = $data; // replace all
            \ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
        }
    }
}

namespace {
    if ( ! class_exists( 'ET_Core_PageResource' ) ) {
        class ET_Core_PageResource
        {
            public static function remove_static_resources( $a, $b, $c = false ) { $GLOBALS['__divi_static_clears'][] = [ $a, $b ]; }
        }
    }
    if ( ! function_exists( 'et_get_option' ) ) {
        function et_get_option( $name, $default = false ) { return $GLOBALS['__divi_gc']['options'][ $name ] ?? $default; }
    }
    if ( ! function_exists( 'et_update_option' ) ) {
        function et_update_option( $name, $value ) { $GLOBALS['__divi_gc']['options'][ $name ] = $value; }
    }
    if ( ! function_exists( 'wp_generate_uuid4' ) ) {
        function wp_generate_uuid4() { return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( random_bytes( 16 ) ), 4 ) ); }
    }
}
```

Create `pro-addon/tests/GlobalColorsFixture.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use ET\Builder\Packages\GlobalData\GlobalData;

require_once __DIR__ . '/DiviGlobalDataShims.php';

/** Two custom colors, an untouched theme option, and the usual WordPress shims state. */
final class GlobalColorsFixture
{
    public const A = 'gcid-aaaaaaaa-0000-4000-8000-000000000001';
    public const B = 'gcid-bbbbbbbb-0000-4000-8000-000000000002';

    /** @return array<string,array<string,mixed>> */
    public static function custom(): array
    {
        return [
            self::A => [ 'color' => '#112233', 'folder' => '', 'label' => 'Brand', 'lastUpdated' => '2026-01-01T00:00:00.000Z', 'status' => 'active', 'usedInPosts' => [ '12', '15' ] ],
            self::B => [ 'color' => '#445566', 'folder' => 'brand', 'label' => 'Accent', 'lastUpdated' => '2026-01-02T00:00:00.000Z', 'status' => 'inactive', 'usedInPosts' => [] ],
        ];
    }

    /** @param array<string,array<string,mixed>> $extraCustom stored in addition to A and B */
    public static function reset( array $extraCustom = [] ): void
    {
        GlobalData::resetForTests( self::custom() + $extraCustom, [ 'accent_color' => '#2ea3f2' ] );
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_caps']            = [ 'edit_theme_options' => true ];
        $GLOBALS['__wp_current_user_id'] = 7;
    }

    public static function clear(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__divi_gc'] );
    }
}
```

- [ ] **Step 2: Write the failing tests**

Create `pro-addon/tests/GlobalColorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalColors;
use ET\Builder\Packages\GlobalData\GlobalData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalColorsFixture.php';

class GlobalColorsTest extends TestCase
{
    protected function setUp(): void
    {
        GlobalColorsFixture::reset();
    }

    protected function tearDown(): void
    {
        GlobalColorsFixture::clear();
    }

    public function testAvailableNeedsBothDiviMethods(): void
    {
        $this->assertTrue( GlobalColors::available() );
        $this->assertFalse( GlobalColors::available( 'No\\Such\\Divi\\Class' ) );
    }

    public function testReadReturnsTheCustomColorsAndTheFiveThemeColors(): void
    {
        $all = GlobalColors::read();
        $this->assertNotNull( $all );
        $this->assertCount( 7, $all );
        foreach ( GlobalColors::THEME_IDS as $id ) {
            $this->assertSame( 'customizer', $all[ $id ]['folder'] );
        }
        $this->assertSame( GlobalColorsFixture::custom()[ GlobalColorsFixture::A ], $all[ GlobalColorsFixture::A ], 'a custom entry comes back exactly as Divi returned it' );
    }

    public function testReadUsesTheLiveThemeColorNotTheStaleRequestCache(): void
    {
        $this->assertSame( '#2ea3f2', GlobalColors::read()['gcid-primary-color']['color'] );
        // Divi's Customizer cache is a static: a theme option written later in the same request is not seen by Divi's own read.
        $GLOBALS['__divi_gc']['options']['accent_color'] = '#ff0000';
        $this->assertSame( '#2ea3f2', GlobalData::get_global_colors()['gcid-primary-color']['color'], 'the shim reproduces Divi\'s stale cache' );
        $this->assertSame( '#ff0000', GlobalColors::read()['gcid-primary-color']['color'] );
    }

    public function testReadFailsClosedWhenDiviThrowsOrHoldsAnEntryItCannotRead(): void
    {
        $GLOBALS['__divi_gc']['throw_get'] = true;
        $this->assertNull( GlobalColors::read() );

        GlobalColorsFixture::reset( [ 'gcid-junk-0000' => 'not an array' ] );
        $this->assertNull( GlobalColors::read(), 'Divi\'s replace-all save would lose an entry we cannot read faithfully' );
    }

    public function testStateKeepsTheFourFieldsAndIgnoresLastUpdatedAndUsage(): void
    {
        $a = GlobalColorsFixture::custom()[ GlobalColorsFixture::A ];
        $this->assertSame( [ 'color' => '#112233', 'label' => 'Brand', 'status' => 'active', 'folder' => '' ], GlobalColors::state( $a ) );
        $b = $a;
        $b['lastUpdated'] = '2030-01-01T00:00:00.000Z';
        $b['usedInPosts'] = [ '99' ];
        $this->assertSame( GlobalColors::state( $a ), GlobalColors::state( $b ) );
        $this->assertNull( GlobalColors::state( null ) );
        $this->assertSame( [ 'color' => '#abc', 'label' => '', 'status' => 'active', 'folder' => '' ], GlobalColors::state( [ 'color' => '#abc' ] ), 'a missing label or folder is empty, a missing status is active' );
    }

    public function testCustomMapDropsTheThemeEntriesAndKeepsTheRestByteForByte(): void
    {
        $map = GlobalColors::customMap( GlobalColors::read() );
        $this->assertSame( [ GlobalColorsFixture::A, GlobalColorsFixture::B ], array_keys( $map ) );
        $this->assertSame( GlobalColorsFixture::custom(), $map );
    }

    public function testThemeEntryHasWhatDiviNeeds(): void
    {
        $e = GlobalColors::themeEntry( '#abcdef', 'Primary Color', '2026-10-05T10:00:00.000Z' );
        $this->assertSame( [ 'color' => '#abcdef', 'folder' => 'customizer', 'label' => 'Primary Color', 'lastUpdated' => '2026-10-05T10:00:00.000Z', 'status' => 'active', 'usedInPosts' => [] ], $e );
    }

    public function testStampUsesDivisFormatInUtc(): void
    {
        $this->assertSame( '2026-10-05T10:20:30.000Z', GlobalColors::stamp( gmmktime( 10, 20, 30, 10, 5, 2026 ) ) );
    }

    public function testWritePassesTheCompleteMapOnceWithDefaultSanitisingAndReadsBack(): void
    {
        $map                  = GlobalColors::customMap( GlobalColors::read() );
        $map['gcid-new-0001'] = [ 'color' => '#abcdef', 'folder' => '', 'label' => 'New', 'lastUpdated' => '2026-10-05T10:00:00.000Z', 'status' => 'active', 'usedInPosts' => [] ];
        $res                  = GlobalColors::write( $map );
        $this->assertFalse( $res['threw'] );
        $this->assertCount( 1, $GLOBALS['__divi_gc']['set_calls'] );
        $this->assertSame( [ false ], $GLOBALS['__divi_gc']['set_flags'], 'never $already_sanitized = true (that would merge and skip sanitising)' );
        $this->assertSame( $map, $GLOBALS['__divi_gc']['set_calls'][0] );
        $this->assertSame( '#abcdef', $res['after']['gcid-new-0001']['color'] );
        $this->assertSame( [ [ 'all', 'all' ] ], $GLOBALS['__divi_static_clears'], 'Divi cleared the page caches itself' );
    }

    public function testWriteReportsAThrowWithoutPretendingItWorked(): void
    {
        $GLOBALS['__divi_gc']['throw_set'] = true;
        $res = GlobalColors::write( GlobalColors::customMap( GlobalColors::read() ) );
        $this->assertTrue( $res['threw'] );
        $this->assertSame( GlobalColorsFixture::custom(), GlobalColors::customMap( $res['after'] ), 'what is stored now is read back, unchanged' );
    }

    /** @return array<string,array{string}> */
    public static function validColors(): array
    {
        return [ 'hex3' => [ '#fff' ], 'hex4' => [ '#fff8' ], 'hex6' => [ '#1A2b3C' ], 'hex8' => [ '#11223344' ], 'rgb' => [ 'rgb(0, 0, 0)' ], 'rgba' => [ 'rgba(0,0,0,.5)' ], 'hsl' => [ 'hsl(120, 50%, 50%)' ], 'hsla' => [ 'hsla(120,50%,50%,0.5)' ], 'space syntax' => [ 'rgb(0 0 0 / 50%)' ], 'hsl spaces' => [ 'hsl(120 100% 50%)' ] ];
    }

    /** @return array<string,array{string}> */
    public static function invalidColors(): array
    {
        return [
            'named' => [ 'red' ], 'var' => [ 'var(--x)' ], 'brace' => [ '#fff; }' ], 'url' => [ 'url(x)' ], 'semicolon' => [ 'rgb(0,0,0);' ],
            'newline' => [ "#fff\n" ], 'nine digits' => [ '#123456789' ], 'five digits' => [ '#12345' ], 'calc' => [ 'calc(1px)' ], 'empty' => [ '' ],
            'quote' => [ 'rgb(0,0,"0")' ], 'backslash' => [ 'rgb(0,0,\\0)' ], 'angle' => [ '<b>' ], 'too long' => [ 'rgb(' . str_repeat( '1,', 40 ) . '1)' ], 'two args' => [ 'rgb(1,2)' ], 'five args' => [ 'rgb(1,2,3,4,5)' ],
        ];
    }

    #[DataProvider( 'validColors' )]
    public function testValidColor( string $c ): void
    {
        $this->assertTrue( GlobalColors::validColor( $c ), $c );
    }

    #[DataProvider( 'invalidColors' )]
    public function testInvalidColorIsRefused( string $c ): void
    {
        $this->assertFalse( GlobalColors::validColor( $c ), $c );
    }

    public function testCleanLabel(): void
    {
        $this->assertSame( 'Brand', GlobalColors::cleanLabel( 'Brand' ) );
        $this->assertSame( 'Brand', GlobalColors::cleanLabel( '  Brand  ' ) );
        $this->assertSame( str_repeat( 'a', 60 ), GlobalColors::cleanLabel( str_repeat( 'a', 60 ) ) );
        $this->assertNull( GlobalColors::cleanLabel( str_repeat( 'a', 61 ) ), '61 characters is too long' );
        $this->assertSame( str_repeat( 'é', 60 ), GlobalColors::cleanLabel( str_repeat( 'é', 60 ) ), 'counted in characters, not bytes' );
        $this->assertNull( GlobalColors::cleanLabel( '' ) );
        $this->assertNull( GlobalColors::cleanLabel( '   ' ) );
        $this->assertNull( GlobalColors::cleanLabel( '<b>x</b>' ) );
        $this->assertNull( GlobalColors::cleanLabel( "a\nb" ) );
        $this->assertNull( GlobalColors::cleanLabel( "a\x00b" ) );
        $this->assertNull( GlobalColors::cleanLabel( "\xB1\x31" ), 'invalid UTF-8' );
    }

    public function testValidId(): void
    {
        $this->assertTrue( GlobalColors::validId( 'gcid-373c75a2-4440-44da-8d3f-57b75310d4c7' ) );
        $this->assertTrue( GlobalColors::validId( 'gcid-primary-color' ) );
        foreach ( [ 'primary', 'gcid-', 'gcid-ab', 'gcid-a b c', "gcid-abc\n", 'GCID-abc', 'gcid-' . str_repeat( 'a', 65 ), 'gcid-abc;' ] as $bad ) {
            $this->assertFalse( GlobalColors::validId( $bad ), $bad );
        }
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter GlobalColorsTest`
Expected: FAIL — `Class "AiEditorDivi5\Pro\Settings\GlobalColors" not found` (every test errors).

- [ ] **Step 4: Write the adapter**

Create `pro-addon/src/Settings/GlobalColors.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ONLY code that talks to Divi's global colors, and only through Divi's own class (never its option names).
 * Divi's save REPLACES the whole custom map, so a write always carries the complete map; every read goes through
 * `GlobalData::get_global_colors()`, except that the five theme (Customizer) colors report their LIVE value, because
 * Divi caches those in a static for the whole request. Anything missing, throwing or unreadable fails closed.
 */
final class GlobalColors
{
    public const DATA_CLASS = 'ET\\Builder\\Packages\\GlobalData\\GlobalData';

    /** The five colors Divi generates from the theme's own settings. */
    public const THEME_IDS = [ 'gcid-primary-color', 'gcid-secondary-color', 'gcid-heading-color', 'gcid-body-color', 'gcid-link-color' ];

    public const MAX_LABEL = 60;

    public static function available( ?string $class = null ): bool
    {
        $class ??= self::DATA_CLASS;

        return is_callable( [ $class, 'get_global_colors' ] ) && is_callable( [ $class, 'set_global_colors' ] );
    }

    public static function isThemeId( string $id ): bool
    {
        return in_array( $id, self::THEME_IDS, true );
    }

    /**
     * @return array<string,array<string,mixed>>|null id => the entry as Divi returned it (a theme color carries its live value); null when unreadable
     */
    public static function read(): ?array
    {
        if ( ! self::available() ) {
            return null;
        }
        try {
            $all = call_user_func( [ self::DATA_CLASS, 'get_global_colors' ] );
        } catch ( \Throwable $e ) {
            return null;
        }
        if ( ! is_array( $all ) ) {
            return null;
        }
        $out = [];
        foreach ( $all as $id => $entry ) {
            if ( ! is_string( $id ) || ! is_array( $entry ) ) {
                return null; // Divi's replace-all save would silently lose an entry we cannot read faithfully
            }
            if ( self::isThemeId( $id ) ) {
                $live = self::liveThemeColor( $id );
                if ( null !== $live ) {
                    $entry['color'] = $live;
                }
            }
            $out[ $id ] = $entry;
        }

        return $out;
    }

    /** What counts as "this color changed": never lastUpdated (a theme color gets a fresh one on every read) or usage. */
    public static function state( ?array $entry ): ?array
    {
        if ( null === $entry ) {
            return null;
        }

        return [
            'color'  => (string) ( $entry['color'] ?? '' ),
            'label'  => (string) ( $entry['label'] ?? '' ),
            'status' => (string) ( $entry['status'] ?? 'active' ),
            'folder' => (string) ( $entry['folder'] ?? '' ),
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $all
     * @return array<string,array<string,mixed>> the custom entries only: Divi stores a theme color in the theme's own settings
     */
    public static function customMap( array $all ): array
    {
        $out = [];
        foreach ( $all as $id => $entry ) {
            if ( ! self::isThemeId( (string) $id ) ) {
                $out[ $id ] = $entry;
            }
        }

        return $out;
    }

    /**
     * The entry Divi takes to change a theme color (Divi uses only its `color`).
     *
     * @return array<string,mixed>
     */
    public static function themeEntry( string $color, string $label, string $stamp ): array
    {
        return [ 'color' => $color, 'folder' => 'customizer', 'label' => $label, 'lastUpdated' => $stamp, 'status' => 'active', 'usedInPosts' => [] ];
    }

    /**
     * Hands Divi the COMPLETE map once (Divi replaces the whole custom map; sanitising stays on) and reads back what is stored now.
     *
     * @param array<string,array<string,mixed>> $map
     * @return array{threw:bool, after:?array<string,array<string,mixed>>}
     */
    public static function write( array $map ): array
    {
        $threw = false;
        if ( self::available() ) {
            try {
                call_user_func( [ self::DATA_CLASS, 'set_global_colors' ], $map );
            } catch ( \Throwable $e ) {
                $threw = true;
            }
        } else {
            $threw = true;
        }

        return [ 'threw' => $threw, 'after' => self::read() ];
    }

    public static function stamp( int $ts ): string
    {
        return gmdate( 'Y-m-d\TH:i:s', $ts ) . '.000Z';
    }

    /** A color value that ends up in the site's CSS: a hex value or rgb/rgba/hsl/hsla of plain numbers, nothing else. */
    public static function validColor( string $color ): bool
    {
        if ( strlen( $color ) > 64 ) {
            return false;
        }

        return 1 === preg_match( '/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/Di', $color )
            || 1 === preg_match( '/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.]+%?(?:\s*[,\/ ]\s*[0-9.]+%?){2,3}\s*\)$/Di', $color );
    }

    /** @return string|null the label as it will be stored, or null when it is not acceptable */
    public static function cleanLabel( string $label ): ?string
    {
        if ( 1 !== preg_match( '//u', $label ) || 1 === preg_match( '/[\x00-\x1F\x7F<>]/', $label ) ) {
            return null;
        }
        $clean = sanitize_text_field( $label );
        if ( '' === $clean || mb_strlen( $clean ) > self::MAX_LABEL ) {
            return null;
        }

        return $clean;
    }

    public static function validId( string $id ): bool
    {
        return 1 === preg_match( '/^gcid-[A-Za-z0-9-]{3,64}$/D', $id );
    }

    private static function liveThemeColor( string $id ): ?string
    {
        $class = self::DATA_CLASS;
        if ( ! property_exists( $class, 'customizer_colors' ) || ! function_exists( 'et_get_option' ) ) {
            return null;
        }
        $defs = $class::$customizer_colors;
        $def  = is_array( $defs ) ? ( $defs[ $id ] ?? null ) : null;
        if ( ! is_array( $def ) || ! isset( $def['option_name'], $def['default'] ) ) {
            return null;
        }

        return sanitize_text_field( (string) et_get_option( $def['option_name'], $def['default'] ) );
    }
}
```

Modify `pro-addon/src/autoload.php`: add this line right after `require_once __DIR__ . '/Kinds/ThemeTemplateKind.php';`'s neighbours, i.e. directly before `require_once __DIR__ . '/Tools/ListBatches.php';`:

```php
require_once __DIR__ . '/Settings/GlobalColors.php';
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter GlobalColorsTest`
Expected: PASS (all). If `testValidColor`/`testInvalidColorIsRefused` fails for one value, fix the regex, never the test table.

- [ ] **Step 6: Confirm the safety tripwire still passes**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter ProSafetyTest`
Expected: PASS (the new file contains none of the banned literals).

- [ ] **Step 7: Commit**

```bash
git add pro-addon/src/Settings/GlobalColors.php pro-addon/src/autoload.php pro-addon/tests/DiviGlobalDataShims.php pro-addon/tests/GlobalColorsFixture.php pro-addon/tests/GlobalColorsTest.php
git commit -m "feat(pro): GlobalColors, the Divi-guarded adapter and validators for manage_global_colors

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: The planner (argument shapes and the all-or-nothing change list)

**Files:**
- Create: `pro-addon/src/Settings/GlobalColorPlanner.php`
- Modify: `pro-addon/src/autoload.php`
- Test: `pro-addon/tests/GlobalColorPlannerTest.php`

**Interfaces:**
- Consumes: `GlobalColors::{validId,cleanLabel,validColor,state,isThemeId,themeEntry}`, `GlobalColorsFixture`.
- Produces on `AiEditorDivi5\Pro\Settings\GlobalColorPlanner`:
  - `const MAX_ENTRIES = 50`
  - `normalize( array $entries ): array` — `list<array{id:?string,label:?string,color:?string,status:?string}>` (label cleaned, color trimmed); throws `\InvalidArgumentException` (message starts `Nothing was changed.`, lists every problem)
  - `plan( array $state, array $entries, bool $allowTheme, string $now, \Closure $newId ): array` where `$entries` is the output of `normalize()`, `$state` is `GlobalColors::read()`, returning `['changes'=>list<change>,'unchanged'=>list<string>,'theme_touched'=>bool]`; a `change` is `['index'=>int,'id'=>string,'action'=>'create'|'update'|'deactivate'|'reactivate','fields'=>list<string>,'before'=>?array,'after'=>array,'write'=>array,'theme'=>bool]` (`before`/`after` are `state()` arrays; `write` is the full Divi-shaped entry to put in the map). Throws `\InvalidArgumentException` for any bad entry (nothing planned).

- [ ] **Step 1: Write the failing tests**

Create `pro-addon/tests/GlobalColorPlannerTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalColorPlanner;
use AiEditorDivi5\Pro\Settings\GlobalColors;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalColorsFixture.php';

class GlobalColorPlannerTest extends TestCase
{
    private const A = GlobalColorsFixture::A;
    private const B = GlobalColorsFixture::B;
    private const NOW = '2026-10-05T10:00:00.000Z';

    private int $seq = 0;

    protected function setUp(): void
    {
        GlobalColorsFixture::reset();
        $this->seq = 0;
    }

    protected function tearDown(): void
    {
        GlobalColorsFixture::clear();
    }

    /** @param list<mixed> $entries @return array<string,mixed> */
    private function plan( array $entries, bool $allowTheme = false ): array
    {
        return GlobalColorPlanner::plan( (array) GlobalColors::read(), GlobalColorPlanner::normalize( $entries ), $allowTheme, self::NOW, function (): string {
            return 'gcid-new-' . ++$this->seq;
        } );
    }

    private function refused( array $entries, string $needle, bool $allowTheme = false ): void
    {
        try {
            $this->plan( $entries, $allowTheme );
            $this->fail( 'expected a refusal containing: ' . $needle );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'Nothing was changed.', $e->getMessage() );
            $this->assertStringContainsString( $needle, $e->getMessage() );
        }
    }

    public function testNormalizeKeepsTheFourKeysCleansTheLabelAndTrimsTheColor(): void
    {
        $n = GlobalColorPlanner::normalize( [ [ 'label' => '  Brand  ', 'color' => ' #ABCDEF ' ], [ 'id' => self::A, 'status' => 'inactive' ] ] );
        $this->assertSame( [
            [ 'id' => null, 'label' => 'Brand', 'color' => '#ABCDEF', 'status' => null ],
            [ 'id' => self::A, 'label' => null, 'color' => null, 'status' => 'inactive' ],
        ], $n );
    }

    public function testNormalizeRefusesBadShapesAndReportsEveryProblemAtOnce(): void
    {
        $bad = [
            'a string'        => [ [ 'x' ], 'colors[0] must be an object' ],
            'a list'          => [ [ [ 'a', 'b' ] ], 'colors[0] must be an object' ],
            'unknown field'   => [ [ [ 'label' => 'x', 'color' => '#fff', 'delete' => true ] ], 'unknown field "delete"' ],
            'id not a string' => [ [ [ 'id' => 5, 'color' => '#fff' ] ], '"id" must be a string' ],
            'bad id'          => [ [ [ 'id' => 'primary', 'color' => '#fff' ] ], '"id" must look like gcid-' ],
            'create no color' => [ [ [ 'label' => 'x' ] ], 'needs "label" and "color"' ],
            'create no label' => [ [ [ 'color' => '#fff' ] ], 'needs "label" and "color"' ],
            'update nothing'  => [ [ [ 'id' => self::A ] ], 'at least one of label, color, status' ],
            'bad status'      => [ [ [ 'id' => self::A, 'status' => 'temporary' ] ], '"status" must be active or inactive' ],
            'bad color'       => [ [ [ 'label' => 'x', 'color' => 'red' ] ], '"color" must be a hex value' ],
            'bad label'       => [ [ [ 'label' => '<b>', 'color' => '#fff' ] ], '"label" must be 1 to 60 characters' ],
            'empty object'    => [ [ [] ], 'needs "label" and "color"' ],
        ];
        foreach ( $bad as $name => [ $entries, $needle ] ) {
            try {
                GlobalColorPlanner::normalize( $entries );
                $this->fail( "expected a refusal for: {$name}" );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( $needle, $e->getMessage(), $name );
            }
        }
        try {
            GlobalColorPlanner::normalize( [ [ 'label' => 'x', 'color' => 'red' ], [ 'id' => 'primary', 'color' => '#fff' ] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'colors[0]', $e->getMessage() );
            $this->assertStringContainsString( 'colors[1]', $e->getMessage(), 'both problems are in the one message' );
        }
    }

    public function testACreateBuildsAFullDiviEntryAndTouchesNothingElse(): void
    {
        $p = $this->plan( [ [ 'label' => 'Sky', 'color' => '#00aaff' ] ] );
        $this->assertSame( [], $p['unchanged'] );
        $this->assertFalse( $p['theme_touched'] );
        $c = $p['changes'][0];
        $this->assertSame( [ 'index' => 0, 'id' => 'gcid-new-1', 'action' => 'create', 'fields' => [ 'color', 'label', 'status' ], 'theme' => false ], array_diff_key( $c, [ 'before' => 1, 'after' => 1, 'write' => 1 ] ) );
        $this->assertNull( $c['before'] );
        $this->assertSame( [ 'color' => '#00aaff', 'label' => 'Sky', 'status' => 'active', 'folder' => '' ], $c['after'] );
        $this->assertSame( [ 'color' => '#00aaff', 'folder' => '', 'label' => 'Sky', 'lastUpdated' => self::NOW, 'status' => 'active', 'usedInPosts' => [] ], $c['write'] );
    }

    public function testAnUpdateChangesOnlyTheFieldsGivenAndKeepsUsageAndFolder(): void
    {
        $p = $this->plan( [ [ 'id' => self::A, 'color' => '#abcdef' ] ] );
        $c = $p['changes'][0];
        $this->assertSame( 'update', $c['action'] );
        $this->assertSame( [ 'color' ], $c['fields'] );
        $this->assertSame( [ 'color' => '#112233', 'label' => 'Brand', 'status' => 'active', 'folder' => '' ], $c['before'] );
        $this->assertSame( [ 'color' => '#abcdef', 'label' => 'Brand', 'status' => 'active', 'folder' => '' ], $c['after'] );
        $expected                = GlobalColorsFixture::custom()[ self::A ];
        $expected['color']       = '#abcdef';
        $expected['lastUpdated'] = self::NOW;
        $this->assertSame( $expected, $c['write'], 'usedInPosts and folder are exactly as stored; only the changed field and lastUpdated differ' );
    }

    public function testStatusOnlyChangesAreNamedDeactivateAndReactivate(): void
    {
        $this->assertSame( 'deactivate', $this->plan( [ [ 'id' => self::A, 'status' => 'inactive' ] ] )['changes'][0]['action'] );
        $this->assertSame( 'reactivate', $this->plan( [ [ 'id' => self::B, 'status' => 'active' ] ] )['changes'][0]['action'] );
        $this->assertSame( 'update', $this->plan( [ [ 'id' => self::A, 'status' => 'inactive', 'label' => 'Old brand' ] ] )['changes'][0]['action'] );
    }

    public function testAnUpdateThatChangesNothingIsReportedAsUnchangedNotPlanned(): void
    {
        $p = $this->plan( [ [ 'id' => self::A, 'color' => '#112233', 'label' => 'Brand', 'status' => 'active' ] ] );
        $this->assertSame( [], $p['changes'] );
        $this->assertSame( [ self::A ], $p['unchanged'] );
    }

    public function testTheIndexOfEachChangeIsItsPositionInTheRequest(): void
    {
        $p = $this->plan( [ [ 'id' => self::A, 'color' => '#112233' ], [ 'label' => 'Sky', 'color' => '#00aaff' ], [ 'id' => self::B, 'color' => '#000000' ] ] );
        $this->assertSame( [ 1, 2 ], array_column( $p['changes'], 'index' ) );
        $this->assertSame( [ self::A ], $p['unchanged'] );
    }

    public function testBadReferencesRefuseTheWholeCallAndSayWhy(): void
    {
        $this->refused( [ [ 'id' => 'gcid-unknown-0000', 'color' => '#fff' ] ], 'colors[0]: no color with the id gcid-unknown-0000' );
        $this->refused( [ [ 'id' => self::A, 'color' => '#fff' ], [ 'id' => self::A, 'label' => 'again' ] ], 'colors[1]: the id ' . self::A . ' is already in this call' );
        $this->refused( [ [ 'id' => self::A, 'color' => '#fff' ], [ 'id' => 'gcid-unknown-0000', 'color' => '#fff' ] ], 'no color with the id', false );
    }

    public function testATemporaryColorIsLeftAlone(): void
    {
        GlobalColorsFixture::reset( [ 'gcid-temp-0001' => [ 'color' => '#010101', 'folder' => '', 'label' => 'Tmp', 'lastUpdated' => '2026-01-01T00:00:00.000Z', 'status' => 'temporary', 'usedInPosts' => [] ] ] );
        $this->refused( [ [ 'id' => 'gcid-temp-0001', 'color' => '#fff' ] ], 'temporary' );
    }

    public function testTwoNewColorsWithTheSameNameAreRefusedWhateverTheCase(): void
    {
        $this->refused( [ [ 'label' => 'Sky', 'color' => '#00aaff' ], [ 'label' => 'sKY', 'color' => '#00bbff' ] ], 'colors[1]: another new color in this call is already named "sKY"' );
    }

    public function testANewColorMayShareANameWithAnExistingOne(): void
    {
        $p = $this->plan( [ [ 'label' => 'Brand', 'color' => '#00aaff' ] ] );
        $this->assertSame( 'create', $p['changes'][0]['action'] );
    }

    public function testThemeColorsNeedTheFlagAndOnlyChangeTheirColor(): void
    {
        $this->refused( [ [ 'id' => 'gcid-primary-color', 'color' => '#ff0000' ] ], 'allow_theme_colors' );
        $this->refused( [ [ 'id' => 'gcid-primary-color', 'color' => '#ff0000', 'label' => 'Mine' ] ], 'theme colors can only change their color', true );
        $this->refused( [ [ 'id' => 'gcid-primary-color', 'status' => 'inactive' ] ], 'theme colors can only change their color', true );

        $p = $this->plan( [ [ 'id' => 'gcid-primary-color', 'color' => '#ff0000' ] ], true );
        $this->assertTrue( $p['theme_touched'] );
        $c = $p['changes'][0];
        $this->assertTrue( $c['theme'] );
        $this->assertSame( [ 'color' ], $c['fields'] );
        $this->assertSame( [ 'color' => '#2ea3f2', 'label' => 'Primary Color', 'status' => 'active', 'folder' => 'customizer' ], $c['before'] );
        $this->assertSame( GlobalColors::themeEntry( '#ff0000', 'Primary Color', self::NOW ), $c['write'] );
    }

    public function testOneBadEntryRefusesTheWholePalette(): void
    {
        $this->refused( [ [ 'label' => 'Sky', 'color' => '#00aaff' ], [ 'id' => 'gcid-unknown-0000', 'color' => '#fff' ] ], 'colors[1]' );
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter GlobalColorPlannerTest`
Expected: FAIL — `Class "...GlobalColorPlanner" not found`.

- [ ] **Step 3: Write the planner**

Create `pro-addon/src/Settings/GlobalColorPlanner.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns a manage_global_colors request into a change list, or refuses the WHOLE request (a palette is applied
 * atomically or not at all) with one message naming every bad entry. Pure: it reads nothing from Divi itself, the
 * caller passes the current state, so it can be tested without Divi.
 */
final class GlobalColorPlanner
{
    public const MAX_ENTRIES = 50;

    private const KEYS     = [ 'id', 'label', 'color', 'status' ];
    private const STATUSES = [ 'active', 'inactive' ];

    /**
     * @param list<mixed> $entries
     * @return list<array{id:?string,label:?string,color:?string,status:?string}>
     */
    public static function normalize( array $entries ): array
    {
        $out    = [];
        $errors = [];
        foreach ( $entries as $i => $e ) {
            $where = "colors[{$i}]";
            if ( ! is_array( $e ) || ( [] !== $e && array_is_list( $e ) ) ) {
                $errors[] = "{$where} must be an object like {\"label\":\"Brand\",\"color\":\"#112233\"}.";
                continue;
            }
            foreach ( array_keys( $e ) as $k ) {
                if ( ! in_array( $k, self::KEYS, true ) ) {
                    $errors[] = "{$where}: unknown field \"{$k}\" (allowed: id, label, color, status).";
                }
            }
            $row = [ 'id' => null, 'label' => null, 'color' => null, 'status' => null ];
            foreach ( self::KEYS as $k ) {
                if ( ! array_key_exists( $k, $e ) || null === $e[ $k ] ) {
                    continue;
                }
                if ( ! is_string( $e[ $k ] ) ) {
                    $errors[] = "{$where}: \"{$k}\" must be a string.";
                    continue;
                }
                $row[ $k ] = $e[ $k ];
            }
            if ( null !== $row['id'] && ! GlobalColors::validId( $row['id'] ) ) {
                $errors[] = "{$where}: \"id\" must look like gcid-... (letters, digits and hyphens, as list_global_styles shows it).";
            }
            if ( null !== $row['label'] ) {
                $clean = GlobalColors::cleanLabel( $row['label'] );
                if ( null === $clean ) {
                    $errors[] = "{$where}: \"label\" must be 1 to " . GlobalColors::MAX_LABEL . ' characters, without angle brackets or control characters.';
                } else {
                    $row['label'] = $clean;
                }
            }
            if ( null !== $row['color'] ) {
                $row['color'] = trim( $row['color'] );
                if ( ! GlobalColors::validColor( $row['color'] ) ) {
                    $errors[] = "{$where}: \"color\" must be a hex value (#rgb, #rgba, #rrggbb, #rrggbbaa) or rgb()/rgba()/hsl()/hsla() with plain numbers; named colors, var(), url() and anything else are refused.";
                }
            }
            if ( null !== $row['status'] && ! in_array( $row['status'], self::STATUSES, true ) ) {
                $errors[] = "{$where}: \"status\" must be active or inactive.";
            }
            if ( null === $row['id'] ) {
                if ( null === $row['label'] || null === $row['color'] ) {
                    $errors[] = "{$where}: creating a color needs \"label\" and \"color\" (or give \"id\" to change an existing one).";
                }
            } elseif ( null === $row['label'] && null === $row['color'] && null === $row['status'] ) {
                $errors[] = "{$where}: changing a color needs at least one of label, color, status.";
            }
            $out[] = $row;
        }
        if ( [] !== $errors ) {
            throw new \InvalidArgumentException( 'Nothing was changed. ' . implode( ' ', $errors ) );
        }

        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $state GlobalColors::read()
     * @param list<array{id:?string,label:?string,color:?string,status:?string}> $entries normalize() output
     * @param \Closure():string $newId makes the id of a created color
     * @return array{changes:list<array<string,mixed>>, unchanged:list<string>, theme_touched:bool}
     */
    public static function plan( array $state, array $entries, bool $allowTheme, string $now, \Closure $newId ): array
    {
        $changes   = [];
        $unchanged = [];
        $errors    = [];
        $seen      = [];
        $labels    = [];
        foreach ( $entries as $i => $e ) {
            $where = "colors[{$i}]";
            if ( null === $e['id'] ) {
                $key = mb_strtolower( (string) $e['label'] );
                if ( isset( $labels[ $key ] ) ) {
                    $errors[] = "{$where}: another new color in this call is already named \"{$e['label']}\".";
                    continue;
                }
                $labels[ $key ] = true;
                $entry          = [ 'color' => (string) $e['color'], 'folder' => '', 'label' => (string) $e['label'], 'lastUpdated' => $now, 'status' => $e['status'] ?? 'active', 'usedInPosts' => [] ];
                $changes[]      = [ 'index' => $i, 'id' => (string) $newId(), 'action' => 'create', 'fields' => [ 'color', 'label', 'status' ], 'before' => null, 'after' => GlobalColors::state( $entry ), 'write' => $entry, 'theme' => false ];
                continue;
            }
            $id = $e['id'];
            if ( isset( $seen[ $id ] ) ) {
                $errors[] = "{$where}: the id {$id} is already in this call.";
                continue;
            }
            $seen[ $id ] = true;
            if ( ! isset( $state[ $id ] ) ) {
                $errors[] = "{$where}: no color with the id {$id} (list_global_styles shows the ids; to add a color leave \"id\" out).";
                continue;
            }
            $current = $state[ $id ];
            $theme   = GlobalColors::isThemeId( $id );
            if ( $theme ) {
                if ( ! $allowTheme ) {
                    $errors[] = "{$where}: {$id} is one of the five theme colors. Changing it restyles the whole theme: pass allow_theme_colors=true to do that on purpose.";
                    continue;
                }
                if ( null !== $e['label'] || null !== $e['status'] ) {
                    $errors[] = "{$where}: theme colors can only change their color (Divi generates their name and status).";
                    continue;
                }
            }
            if ( 'temporary' === ( $current['status'] ?? '' ) ) {
                $errors[] = "{$where}: {$id} is a temporary color that belongs to Divi's builder session; it is left alone.";
                continue;
            }
            $before = GlobalColors::state( $current );
            $after  = $before;
            $fields = [];
            foreach ( [ 'color', 'label', 'status' ] as $f ) {
                if ( null !== $e[ $f ] && $e[ $f ] !== $before[ $f ] ) {
                    $after[ $f ] = $e[ $f ];
                    $fields[]    = $f;
                }
            }
            if ( [] === $fields ) {
                $unchanged[] = $id;
                continue;
            }
            if ( $theme ) {
                $write = GlobalColors::themeEntry( $after['color'], $before['label'], $now );
            } else {
                $write = $current;
                foreach ( $fields as $f ) {
                    $write[ $f ] = $after[ $f ];
                }
                $write['lastUpdated'] = $now;
            }
            $action    = [ 'status' ] === $fields ? ( 'inactive' === $after['status'] ? 'deactivate' : 'reactivate' ) : 'update';
            $changes[] = [ 'index' => $i, 'id' => $id, 'action' => $action, 'fields' => $fields, 'before' => $before, 'after' => $after, 'write' => $write, 'theme' => $theme ];
        }
        if ( [] !== $errors ) {
            throw new \InvalidArgumentException( 'Nothing was changed. ' . implode( ' ', $errors ) );
        }

        return [ 'changes' => $changes, 'unchanged' => $unchanged, 'theme_touched' => [] !== array_filter( $changes, static fn ( array $c ): bool => $c['theme'] ) ];
    }
}
```

Modify `pro-addon/src/autoload.php`: add directly after the `Settings/GlobalColors.php` line:

```php
require_once __DIR__ . '/Settings/GlobalColorPlanner.php';
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter GlobalColorPlannerTest`
Expected: PASS. (One check to make while reading failures: `testBadReferencesRefuseTheWholeCallAndSayWhy`'s third case uses the substring "no color with the id" only; it must pass because the first entry is valid and the second is unknown.)

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src/Settings/GlobalColorPlanner.php pro-addon/src/autoload.php pro-addon/tests/GlobalColorPlannerTest.php
git commit -m "feat(pro): GlobalColorPlanner, atomic validation and the change list for manage_global_colors

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `GlobalColorKind`, undo of color changes

**Files:**
- Create: `pro-addon/src/Kinds/GlobalColorKind.php`
- Modify: `pro-addon/src/Batch/Batches.php`, `pro-addon/src/autoload.php`, `pro-addon/tests/ToolsTest.php` (the kinds list only)
- Test: `pro-addon/tests/GlobalColorKindTest.php`

**Interfaces:**
- Consumes: `Item::make`, `ItemKind`, `Canon::hash`, `GlobalColors::{read,state,customMap,themeEntry,write,stamp,isThemeId,available}`, `BatchLog`, `BatchUndo`, `InMemoryBatchRepository`.
- Produces on `AiEditorDivi5\Pro\Kinds\GlobalColorKind`:
  - `const NAME = 'global_color'`
  - `static itemFor( string $id, ?array $before, ?array $after ): array` — `$before`/`$after` are `GlobalColors::state()` arrays or `null` (= the color does not exist); `ref` = the id; `before` = `null` for a create, else the state plus `_fields` (the fields this change touched); `after_hash` = hash of `$after`
  - the four `ItemKind` methods. `restore()` puts back only the fields the batch changed (onto the entry as it is now, with a fresh `lastUpdated`); undo of a create removes the entry and refuses (`changed_since`, even when forced) unless the entry is exactly what the batch wrote.

- [ ] **Step 1: Write the failing tests**

Create `pro-addon/tests/GlobalColorKindTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\GlobalColorKind;
use AiEditorDivi5\Pro\Settings\GlobalColors;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalColorsFixture.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class GlobalColorKindTest extends TestCase
{
    private const A = GlobalColorsFixture::A;
    private const B = GlobalColorsFixture::B;

    private InMemoryBatchRepository $repo;
    private BatchLog $log;

    protected function setUp(): void
    {
        GlobalColorsFixture::reset();
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
    }

    protected function tearDown(): void
    {
        GlobalColorsFixture::clear();
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ GlobalColorKind::NAME => new GlobalColorKind() ], $this->log );
    }

    /** Change fields of one color the way the tool does (full map, one write) and record the batch. @param array<string,string> $fields */
    private function change( string $id, array $fields ): int
    {
        $all    = (array) GlobalColors::read();
        $before = GlobalColors::state( $all[ $id ] ?? null );
        $map    = GlobalColors::customMap( $all );
        if ( GlobalColors::isThemeId( $id ) ) {
            $map[ $id ] = GlobalColors::themeEntry( $fields['color'], (string) $before['label'], '2026-10-05T10:00:00.000Z' );
        } else {
            $map[ $id ] = array_merge( $map[ $id ] ?? [ 'color' => '#000000', 'folder' => '', 'label' => 'x', 'status' => 'active', 'usedInPosts' => [] ], $fields, [ 'lastUpdated' => '2026-10-05T10:00:00.000Z' ] );
        }
        $res   = GlobalColors::write( $map );
        $after = GlobalColors::state( $res['after'][ $id ] ?? null );
        $batch = $this->log->record( 'manage_global_colors', 'test', [ GlobalColorKind::itemFor( $id, $before, $after ) ] );

        return (int) $batch['id'];
    }

    public function testItemForRecordsTheFieldsAChangeTouchedAndHashesTheAfterState(): void
    {
        $before = GlobalColors::state( GlobalColorsFixture::custom()[ self::A ] );
        $after  = [ 'color' => '#abcdef', 'label' => 'Brand', 'status' => 'inactive', 'folder' => '' ];
        $item   = GlobalColorKind::itemFor( self::A, $before, $after );
        $this->assertSame( 'global_color', $item['kind'] );
        $this->assertSame( self::A, $item['ref'] );
        $this->assertSame( $before + [ '_fields' => [ 'color', 'status' ] ], $item['before'] );
        $this->assertSame( Canon::hash( $after ), $item['after_hash'] );
        $this->assertTrue( $item['undo']['available'] );

        $create = GlobalColorKind::itemFor( 'gcid-new-1', null, $after );
        $this->assertNull( $create['before'] );
        $this->assertStringContainsString( 'gcid-new-1', $create['label'] );
    }

    public function testCurrentIsTheStateOrNull(): void
    {
        $kind = new GlobalColorKind();
        $this->assertSame( GlobalColors::state( GlobalColorsFixture::custom()[ self::B ] ), $kind->current( [ 'ref' => self::B ] ) );
        $this->assertNull( $kind->current( [ 'ref' => 'gcid-nothing-0000' ] ) );
        $GLOBALS['__divi_gc']['throw_get'] = true;
        $this->expectException( \RuntimeException::class );
        $kind->current( [ 'ref' => self::B ] );
    }

    public function testAllowedNeedsEditThemeOptions(): void
    {
        $kind = new GlobalColorKind();
        $this->assertTrue( $kind->allowed( [ 'ref' => self::A ] ) );
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $this->assertFalse( $kind->allowed( [ 'ref' => self::A ] ) );
    }

    public function testUndoOfARecolorPutsTheColorBackAndKeepsEverythingElse(): void
    {
        $id = $this->change( self::A, [ 'color' => '#abcdef' ] );
        $r  = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $now = GlobalColors::read()[ self::A ];
        $this->assertSame( '#112233', $now['color'] );
        $this->assertSame( [ '12', '15' ], $now['usedInPosts'] );
        $this->assertSame( GlobalColors::state( GlobalColorsFixture::custom()[ self::B ] ), GlobalColors::state( GlobalColors::read()[ self::B ] ), 'the other color is untouched' );
        $this->assertCount( 2, $GLOBALS['__divi_gc']['set_calls'], 'the change and its undo: one complete write each' );
        $this->assertSame( [ false, false ], $GLOBALS['__divi_gc']['set_flags'] );
    }

    public function testAColorChangedSinceIsNotOverwrittenUnlessForcedAndForceOnlyRevertsWhatTheBatchChanged(): void
    {
        $id = $this->change( self::A, [ 'color' => '#abcdef' ] );
        $this->change( self::A, [ 'label' => 'Renamed later' ] );

        $r = $this->undo()->undo( $id );
        $this->assertSame( 'changed_since', $r['items'][0]['result'] );
        $this->assertSame( '#abcdef', GlobalColors::read()[ self::A ]['color'] );

        $r = $this->undo()->undo( $id, true );
        $this->assertSame( 'restored', $r['items'][0]['result'] );
        $now = GlobalColors::read()[ self::A ];
        $this->assertSame( '#112233', $now['color'], 'the color the batch changed is back' );
        $this->assertSame( 'Renamed later', $now['label'], 'the later rename is kept' );
    }

    public function testUndoOfACreateRemovesTheEntryAndTheUndoIsItselfUndoable(): void
    {
        $id = $this->change( 'gcid-new-0001', [ 'color' => '#00aaff', 'label' => 'Sky' ] );
        $this->assertArrayHasKey( 'gcid-new-0001', (array) GlobalColors::read() );

        $r = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertArrayNotHasKey( 'gcid-new-0001', (array) GlobalColors::read() );
        $this->assertSame( [ self::A, self::B ], array_keys( GlobalColors::customMap( (array) GlobalColors::read() ) ) );

        $again = $this->undo()->undo( (int) $r['undo_batch']['id'] );
        $this->assertTrue( $again['fully_undone'], 'undoing the undo brings the color back' );
        $this->assertSame( 'Sky', GlobalColors::read()['gcid-new-0001']['label'] );
        $this->assertSame( '#00aaff', GlobalColors::read()['gcid-new-0001']['color'] );
    }

    public function testUndoOfACreateRefusesWhenTheEntryChangedSinceEvenWhenForced(): void
    {
        $id = $this->change( 'gcid-new-0001', [ 'color' => '#00aaff', 'label' => 'Sky' ] );
        $this->change( 'gcid-new-0001', [ 'color' => '#ffffff' ] );
        foreach ( [ false, true ] as $force ) {
            $r = $this->undo()->undo( $id, $force );
            $this->assertFalse( $r['fully_undone'] );
            $this->assertArrayHasKey( 'gcid-new-0001', (array) GlobalColors::read(), 'a color someone changed after our write is never removed' );
        }
    }

    public function testAThemeColorUndoWritesTheThemeOptionBack(): void
    {
        $id = $this->change( 'gcid-primary-color', [ 'color' => '#ff0000' ] );
        $this->assertSame( '#ff0000', $GLOBALS['__divi_gc']['options']['accent_color'] );
        $r = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( '#2ea3f2', $GLOBALS['__divi_gc']['options']['accent_color'] );
        $this->assertSame( '#2ea3f2', GlobalColors::read()['gcid-primary-color']['color'] );
    }

    public function testWithoutThePermissionNothingIsRestored(): void
    {
        $id                   = $this->change( self::A, [ 'color' => '#abcdef' ] );
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $r                    = $this->undo()->undo( $id );
        $this->assertSame( 'forbidden', $r['items'][0]['result'] );
        $this->assertSame( '#abcdef', GlobalColors::read()[ self::A ]['color'] );
    }

    public function testWhenDiviCannotBeReadTheItemFailsAndNothingIsClaimed(): void
    {
        $id                                = $this->change( self::A, [ 'color' => '#abcdef' ] );
        $GLOBALS['__divi_gc']['throw_get'] = true;
        $r                                 = $this->undo()->undo( $id );
        $this->assertSame( 'failed', $r['items'][0]['result'] );
        $this->assertFalse( $r['fully_undone'] );
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter GlobalColorKindTest`
Expected: FAIL — `Class "...GlobalColorKind" not found`.

- [ ] **Step 3: Write the kind**

Create `pro-addon/src/Kinds/GlobalColorKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Settings\GlobalColors;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One Divi global color. The item stores the previous state (null = the color did not exist, so undo removes it);
 * undo writes the complete map back through GlobalColors, puts back only the fields the batch changed, and only while
 * the color still looks the way the batch left it. A color the batch created is never removed once it has changed since,
 * not even with force.
 */
final class GlobalColorKind implements ItemKind
{
    public const NAME = 'global_color';

    /** The only fields a change ever touches. */
    private const FIELDS = [ 'color', 'label', 'status' ];

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string,string>|null $before GlobalColors::state() before the change, or null when the color did not exist
     * @param array<string,string>|null $after GlobalColors::state() as Divi stores it now, or null when it no longer exists
     * @return array<string,mixed>
     */
    public static function itemFor( string $id, ?array $before, ?array $after ): array
    {
        $fields = [];
        foreach ( self::FIELDS as $field ) {
            if ( ( $before[ $field ] ?? null ) !== ( $after[ $field ] ?? null ) ) {
                $fields[] = $field;
            }
        }
        $name  = (string) ( $after['label'] ?? $before['label'] ?? '' );
        $label = 'Global color' . ( '' !== $name ? " \"{$name}\"" : '' ) . " ({$id})";

        return Item::make( self::NAME, $id, $label, null === $before ? null : $before + [ '_fields' => $fields ], $after );
    }

    public function current( array $item ): mixed
    {
        $all = GlobalColors::read();
        if ( null === $all ) {
            throw new \RuntimeException( "Divi's global colors cannot be read." );
        }

        return GlobalColors::state( $all[ (string) $item['ref'] ] ?? null );
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'edit_theme_options' ) && GlobalColors::available();
    }

    public function restore( array $item ): array
    {
        $id     = (string) $item['ref'];
        $before = is_array( $item['before'] ?? null ) ? $item['before'] : null;
        $all    = GlobalColors::read();
        if ( null === $all ) {
            return [ 'ok' => false, 'reason' => 'divi_unavailable' ];
        }
        $current  = $all[ $id ] ?? null;
        $nowState = GlobalColors::state( $current );
        $map      = GlobalColors::customMap( $all );
        $stamp    = GlobalColors::stamp( time() );

        if ( null === $before ) {
            if ( null === $current ) {
                return [ 'ok' => false, 'reason' => 'not_found' ];
            }
            if ( Canon::hash( $nowState ) !== (string) ( $item['after_hash'] ?? '' ) ) {
                return [ 'ok' => false, 'reason' => 'changed_since' ]; // even when forced: never remove a color someone changed after us
            }
            unset( $map[ $id ] );
            $expected = null;
        } else {
            $fields = is_array( $before['_fields'] ?? null ) ? $before['_fields'] : self::FIELDS;
            if ( GlobalColors::isThemeId( $id ) ) {
                if ( null === $nowState ) {
                    return [ 'ok' => false, 'reason' => 'not_found' ];
                }
                $color      = in_array( 'color', $fields, true ) ? (string) $before['color'] : $nowState['color'];
                $map[ $id ] = GlobalColors::themeEntry( $color, $nowState['label'], $stamp );
                $expected   = [ 'color' => $color ] + $nowState;
            } else {
                $entry = $current ?? [ 'color' => (string) $before['color'], 'folder' => (string) ( $before['folder'] ?? '' ), 'label' => (string) $before['label'], 'status' => (string) $before['status'], 'lastUpdated' => $stamp, 'usedInPosts' => [] ];
                foreach ( $fields as $field ) {
                    if ( in_array( $field, self::FIELDS, true ) && array_key_exists( $field, $before ) ) {
                        $entry[ $field ] = $before[ $field ];
                    }
                }
                $entry['lastUpdated'] = $stamp;
                $map[ $id ]           = $entry;
                $expected             = GlobalColors::state( $entry );
            }
        }

        $res = GlobalColors::write( $map );
        if ( null === $res['after'] ) {
            return [ 'ok' => false, 'reason' => 'divi_refused' ];
        }
        $stored = GlobalColors::state( $res['after'][ $id ] ?? null );
        if ( $stored !== $expected ) {
            return [ 'ok' => false, 'reason' => 'divi_refused' ];
        }

        return [ 'ok' => true, 'inverse' => self::itemFor( $id, $nowState, $stored ) ];
    }
}
```

Modify `pro-addon/src/autoload.php`: add directly after the `Settings/GlobalColorPlanner.php` line:

```php
require_once __DIR__ . '/Kinds/GlobalColorKind.php';
```

Modify `pro-addon/src/Batch/Batches.php`: add `use AiEditorDivi5\Pro\Kinds\GlobalColorKind;` to the use list (alphabetical, after `FrontPageKind`), and `new GlobalColorKind(),` as the last entry of the `$kinds` array (after `new ThemeTemplateKind(),`).

Modify `pro-addon/tests/ToolsTest.php`, in `testEveryKindARecordedItemUsesIsRegisteredForUndo`, append `'global_color'` to the expected list so it reads:

```php
[ 'front_page', 'custom_css', 'menu', 'post_status', 'page_created', 'page_trashed', 'post_content', 'theme_layout_content', 'theme_template', 'global_color' ],
```

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter "GlobalColorKindTest|ToolsTest|BatchUndoTest"`
Expected: PASS. (`ToolsTest::testThisReleaseShipsExactlyTheseTools` still passes: the tool is not registered yet.)

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src/Kinds/GlobalColorKind.php pro-addon/src/Batch/Batches.php pro-addon/src/autoload.php pro-addon/tests/GlobalColorKindTest.php pro-addon/tests/ToolsTest.php
git commit -m "feat(pro): GlobalColorKind, honest undo of global color changes

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: The `manage_global_colors` tool

**Files:**
- Create: `pro-addon/src/Tools/ManageGlobalColors.php`
- Modify: `pro-addon/src/Tools.php`, `pro-addon/src/autoload.php`, `pro-addon/tests/ToolsTest.php`, `pro-addon/tests/ExpiryKeepsAllToolsTest.php`
- Test: `pro-addon/tests/ManageGlobalColorsTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1 to 3, plus `Args::{listOf,bool,string}`, `Canon::hash`, `PreviewToken::{make,open,spend,TTL}`, `BatchLog::{record,attach}`, `ProTool`.
- Produces: `AiEditorDivi5\Pro\Tools\ManageGlobalColors implements ProTool` with `const NAME = 'manage_global_colors'`, `__construct( BatchLog $log, ?\Closure $clock = null, ?\Closure $newId = null )` (`$clock(): int` seconds, `$newId(): string`), `capability()` = `edit_theme_options`. Result shapes:
  - dry run: `['changed'=>false,'dry_run'=>true,'changes'=>list<['index','id'(null for create),'action','before','after']>,'theme_colors_touched'=>bool,'preview_token'=>string,'expires_in'=>1800,'message'=>string]`
  - real run applied: `['changed'=>true,'dry_run'=>false,'changes'=>list<['id','action','before','after']>,'skipped'=>list,'theme_colors_touched'=>bool,'note'=>string, 'batch'=>…]` plus optional `differs_from_request`, `not_stored`, `not_changed`, `unexpected_changes`, `warning`
  - nothing to do: `['changed'=>false,'dry_run'=>bool,'unchanged'=>list<string>,'message'=>string]` (or with `skipped` after a real run whose entries all went stale)

- [ ] **Step 1: Write the failing tests**

Create `pro-addon/tests/ManageGlobalColorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\GlobalColorKind;
use AiEditorDivi5\Pro\Settings\GlobalColors;
use AiEditorDivi5\Pro\Tools\ManageGlobalColors;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalColorsFixture.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class ManageGlobalColorsTest extends TestCase
{
    private const A = GlobalColorsFixture::A;
    private const B = GlobalColorsFixture::B;

    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private ManageGlobalColors $tool;
    private int $now = 1790000000;
    private int $seq = 0;

    protected function setUp(): void
    {
        GlobalColorsFixture::reset();
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->seq  = 0;
        $this->tool = new ManageGlobalColors( $this->log, fn (): int => $this->now, function (): string {
            return 'gcid-new-00000000-0000-4000-8000-00000000000' . ++$this->seq;
        } );
    }

    protected function tearDown(): void
    {
        GlobalColorsFixture::clear();
    }

    /** Preview, then apply with the returned token. @param array<string,mixed> $args @return array<string,mixed> */
    private function apply( array $args ): array
    {
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertArrayHasKey( 'preview_token', $p );

        return $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
    }

    private function refused( array $args, string $needle ): void
    {
        $before = [ $GLOBALS['__divi_gc']['custom'], $GLOBALS['__divi_gc']['options'], count( $GLOBALS['__divi_gc']['set_calls'] ), count( $this->repo->rows ) ];
        try {
            $this->tool->run( $args );
            $this->fail( 'expected a refusal containing: ' . $needle );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $needle, $e->getMessage() );
        }
        $this->assertSame( $before, [ $GLOBALS['__divi_gc']['custom'], $GLOBALS['__divi_gc']['options'], count( $GLOBALS['__divi_gc']['set_calls'] ), count( $this->repo->rows ) ], 'a refusal changes nothing' );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ GlobalColorKind::NAME => new GlobalColorKind() ], $this->log );
    }

    public function testTheDefinitionIsValidForMcpAndRest(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'manage_global_colors', $d['name'] );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'colors' ], $d['inputSchema']['required'] );
        foreach ( [ 'colors', 'dry_run', 'preview_token', 'allow_theme_colors' ] as $p ) {
            $this->assertArrayHasKey( $p, $d['inputSchema']['properties'] );
        }
        $this->assertSame( 50, $d['inputSchema']['properties']['colors']['maxItems'] );
    }

    public function testADryRunReportsTheChangeAndATokenAndWritesNothing(): void
    {
        $r = $this->tool->run( [ 'colors' => [ [ 'label' => 'Sky', 'color' => '#00aaff' ], [ 'id' => self::A, 'color' => '#abcdef' ] ], 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertFalse( $r['theme_colors_touched'] );
        $this->assertSame( 1800, $r['expires_in'] );
        $this->assertNotSame( '', $r['preview_token'] );
        $this->assertSame( [ 'create', 'update' ], array_column( $r['changes'], 'action' ) );
        $this->assertNull( $r['changes'][0]['id'], 'the id of a new color is assigned when it is applied' );
        $this->assertSame( self::A, $r['changes'][1]['id'] );
        $this->assertSame( '#112233', $r['changes'][1]['before']['color'] );
        $this->assertSame( '#abcdef', $r['changes'][1]['after']['color'] );
        $this->assertSame( [], $GLOBALS['__divi_gc']['set_calls'], 'Divi was not asked to write anything' );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( GlobalColorsFixture::custom(), GlobalColors::customMap( (array) GlobalColors::read() ) );
    }

    public function testACreateAddsTheColorAndPassesEveryOtherEntryBackByteForByte(): void
    {
        $r = $this->apply( [ 'colors' => [ [ 'label' => 'Sky', 'color' => '#00aaff' ] ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 'create', $r['changes'][0]['action'] );
        $id = $r['changes'][0]['id'];
        $this->assertStringStartsWith( 'gcid-new-', $id );
        $this->assertSame( [ 'color' => '#00aaff', 'label' => 'Sky', 'status' => 'active', 'folder' => '' ], $r['changes'][0]['after'] );
        $this->assertCount( 1, $GLOBALS['__divi_gc']['set_calls'], 'exactly one save' );
        $this->assertSame( [ false ], $GLOBALS['__divi_gc']['set_flags'] );
        $sent = $GLOBALS['__divi_gc']['set_calls'][0];
        $this->assertSame( GlobalColorsFixture::custom()[ self::A ], $sent[ self::A ], 'an untouched entry keeps usedInPosts, folder and lastUpdated' );
        $this->assertSame( GlobalColorsFixture::custom()[ self::B ], $sent[ self::B ] );
        $this->assertSame( [ self::A, self::B, $id ], array_keys( $sent ) );
        $this->assertArrayNotHasKey( 'gcid-primary-color', $sent, 'the theme colors are never sent unless they are being changed' );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( [ 'global_color' ], array_column( $this->repo->rows[1]['items'], 'kind' ) );
        $this->assertStringContainsString( 'gcid', $r['note'] );
    }

    public function testRecolorRenameAndDeactivateChangeOnlyWhatWasAsked(): void
    {
        $r = $this->apply( [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef', 'label' => 'Brand 2' ], [ 'id' => self::B, 'status' => 'active' ] ] ] );
        $this->assertSame( [ 'update', 'reactivate' ], array_column( $r['changes'], 'action' ) );
        $all = (array) GlobalColors::read();
        $this->assertSame( [ '#abcdef', 'Brand 2', 'active', '' ], array_values( (array) GlobalColors::state( $all[ self::A ] ) ) );
        $this->assertSame( [ '12', '15' ], $all[ self::A ]['usedInPosts'] );
        $this->assertSame( [ '#445566', 'Accent', 'active', 'brand' ], array_values( (array) GlobalColors::state( $all[ self::B ] ) ) );
        $r = $this->apply( [ 'colors' => [ [ 'id' => self::A, 'status' => 'inactive' ] ] ] );
        $this->assertSame( 'deactivate', $r['changes'][0]['action'] );
        $this->assertSame( 'inactive', GlobalColors::read()[ self::A ]['status'] );
    }

    public function testNothingToDoIsReportedAndWritesNothing(): void
    {
        $r = $this->tool->run( [ 'colors' => [ [ 'id' => self::A, 'color' => '#112233' ] ] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ self::A ], $r['unchanged'] );
        $this->assertSame( [], $GLOBALS['__divi_gc']['set_calls'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testARealRunNeedsAGenuineTokenForTheSameArgumentsByTheSameUserOnce(): void
    {
        $args = [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ];
        $this->refused( $args, 'preview_token' );
        $this->refused( $args + [ 'preview_token' => 'garbage.token' ], 'preview_token' );

        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->refused( [ 'colors' => [ [ 'id' => self::A, 'color' => '#fedcba' ] ], 'preview_token' => $p['preview_token'] ], 'preview_token' );

        $GLOBALS['__wp_current_user_id'] = 8;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
        $GLOBALS['__wp_current_user_id'] = 7;

        $this->now += 1801;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
        $this->now -= 1801;

        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'] );
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
    }

    public function testATokenMadeWithoutTheThemeFlagDoesNotOpenARunWithIt(): void
    {
        $entries = [ [ 'id' => self::A, 'color' => '#abcdef' ] ];
        $p       = $this->tool->run( [ 'colors' => $entries, 'dry_run' => true ] );
        $this->refused( [ 'colors' => $entries, 'allow_theme_colors' => true, 'preview_token' => $p['preview_token'] ], 'preview_token' );
    }

    public function testAnEntryChangedSinceThePreviewIsSkippedAndNeverOverwritten(): void
    {
        $args = [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ], [ 'id' => self::B, 'color' => '#fedcba' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gc']['custom'][ self::A ]['color'] = '#999999';
        $GLOBALS['__divi_gc']['cache']                       = null;

        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ self::B ], array_column( $r['changes'], 'id' ) );
        $this->assertSame( [ [ 'index' => 0, 'id' => self::A, 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
        $this->assertSame( '#999999', GlobalColors::read()[ self::A ]['color'], 'the color changed behind our back is left as it is' );
        $this->assertSame( '#fedcba', GlobalColors::read()[ self::B ]['color'] );
    }

    public function testWhenEveryEntryWentStaleNothingIsWritten(): void
    {
        $args = [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gc']['custom'][ self::A ]['color'] = '#999999';
        $GLOBALS['__divi_gc']['cache']                       = null;
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertCount( 1, $r['skipped'] );
        $this->assertSame( [], $GLOBALS['__divi_gc']['set_calls'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testIfTheColorsChangeWhileTheCallRunsNothingIsWritten(): void
    {
        $args = [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gc']['gets']   = 0;
        $GLOBALS['__divi_gc']['on_get'] = static function ( int $n ): void {
            if ( 2 === $n ) { // the second read of the real run is the re-read just before the write
                $GLOBALS['__divi_gc']['custom'][ GlobalColorsFixture::B ]['color'] = '#777777';
                $GLOBALS['__divi_gc']['cache']                                    = null;
            }
        };
        // Not refused(): the hook itself changes the colors, so the "nothing changed" snapshot of that helper does not apply.
        try {
            $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'changed while this call was running', $e->getMessage() );
        }
        $this->assertSame( [], $GLOBALS['__divi_gc']['set_calls'], 'Divi was not asked to write anything' );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( '#112233', GlobalColors::read()[ self::A ]['color'], 'the requested change was not applied' );
    }

    public function testAThemeColorNeedsTheFlagAndIsWrittenAndUndone(): void
    {
        $args = [ 'colors' => [ [ 'id' => 'gcid-primary-color', 'color' => '#ff0000' ] ] ];
        $this->refused( $args + [ 'dry_run' => true ], 'allow_theme_colors' );

        $p = $this->tool->run( $args + [ 'allow_theme_colors' => true, 'dry_run' => true ] );
        $this->assertTrue( $p['theme_colors_touched'] );
        $this->assertStringContainsString( 'whole theme', $p['message'] );
        $this->assertSame( '#2ea3f2', $GLOBALS['__divi_gc']['options']['accent_color'], 'a preview changes nothing' );

        $r = $this->tool->run( $args + [ 'allow_theme_colors' => true, 'preview_token' => $p['preview_token'] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertTrue( $r['theme_colors_touched'] );
        $this->assertSame( '#ff0000', $GLOBALS['__divi_gc']['options']['accent_color'] );
        $this->assertSame( '#ff0000', $r['changes'][0]['after']['color'], 'the read-back shows the NEW color even though Divi caches the theme colors for the request' );
        $sent = $GLOBALS['__divi_gc']['set_calls'][0];
        $this->assertSame( [ self::A, self::B, 'gcid-primary-color' ], array_keys( $sent ), 'only the theme color being changed is sent, after the complete custom map' );

        $u = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( '#2ea3f2', $GLOBALS['__divi_gc']['options']['accent_color'] );
    }

    public function testWhenDiviStoresSomethingElseTheBatchRecordsWhatItStored(): void
    {
        $GLOBALS['__divi_gc']['mutate'] = static fn ( array $d ): array => isset( $d[ GlobalColorsFixture::A ] ) ? [ GlobalColorsFixture::A => [ 'color' => '#000000' ] + $d[ GlobalColorsFixture::A ] ] + $d : $d;
        $r = $this->apply( [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( '#000000', $r['changes'][0]['after']['color'] );
        $this->assertSame( self::A, $r['differs_from_request'][0]['id'] );
        $this->assertSame( '#abcdef', $r['differs_from_request'][0]['requested']['color'] );
        $this->assertSame( '#000000', $r['differs_from_request'][0]['stored']['color'] );
        $GLOBALS['__divi_gc']['mutate'] = null;
        $u = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'], 'what Divi really stored was recorded, so undo matches' );
        $this->assertSame( '#112233', GlobalColors::read()[ self::A ]['color'] );
    }

    public function testAPreExistingEntryDiviDropsIsReportedNotHidden(): void
    {
        GlobalColorsFixture::reset( [ 'gcid-empty-0000' => [ 'color' => '', 'folder' => '', 'label' => 'Junk', 'lastUpdated' => '2026-01-01T00:00:00.000Z', 'status' => 'active', 'usedInPosts' => [] ] ] );
        $r = $this->apply( [ 'colors' => [ [ 'label' => 'Sky', 'color' => '#00aaff' ] ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ [ 'id' => 'gcid-empty-0000', 'reason' => 'removed_by_divi' ] ], $r['unexpected_changes'] );
    }

    public function testWhenDiviCannotSaveNothingIsRecorded(): void
    {
        $args = [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gc']['throw_set'] = true;
        try {
            $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
            $this->fail( 'expected a failure' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'nothing is recorded', $e->getMessage() );
        }
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( '#112233', GlobalColors::read()[ self::A ]['color'] );
    }

    public function testWhenDiviCannotBeReadTheCallIsRefused(): void
    {
        $GLOBALS['__divi_gc']['throw_get'] = true;
        $this->refused( [ 'colors' => [ [ 'label' => 'Sky', 'color' => '#00aaff' ] ], 'dry_run' => true ], 'not available' );
    }

    public function testArgumentsAreTypeChecked(): void
    {
        $ok = [ 'label' => 'Sky', 'color' => '#00aaff' ];
        $this->refused( [], '"colors" is required' );
        $this->refused( [ 'colors' => [] ], 'must not be empty' );
        $this->refused( [ 'colors' => 'red' ], 'must be a list' );
        $this->refused( [ 'colors' => [ 'label' => 'Sky', 'color' => '#00aaff' ] ], 'must be a list' );
        $this->refused( [ 'colors' => array_fill( 0, 51, $ok ) ], 'at most 50 items' );
        $this->refused( [ 'colors' => [ $ok ], 'dry_run' => 'yes' ], '"dry_run" must be true or false' );
        $this->refused( [ 'colors' => [ $ok ], 'allow_theme_colors' => 1 ], '"allow_theme_colors" must be true or false' );
        $this->refused( [ 'colors' => [ $ok ], 'preview_token' => [ 'x' ] ], '"preview_token" must be a string' );
        $this->refused( [ 'colors' => [ [ 'label' => 'Sky', 'color' => 'rgb(0,0,0);' ] ], 'dry_run' => true ], '"color" must be a hex value' );
        $this->assertTrue( $this->tool->run( [ 'colors' => array_map( fn ( int $i ): array => [ 'label' => "C{$i}", 'color' => '#00aaff' ], range( 1, 50 ) ), 'dry_run' => true ] )['dry_run'], 'exactly 50 is allowed' );
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter ManageGlobalColorsTest`
Expected: FAIL — `Class "...ManageGlobalColors" not found`.

- [ ] **Step 3: Write the tool**

Create `pro-addon/src/Tools/ManageGlobalColors.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\GlobalColorKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Settings\GlobalColorPlanner;
use AiEditorDivi5\Pro\Settings\GlobalColors;
use AiEditorDivi5\Pro\Site\PreviewToken;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create, recolor, rename or deactivate the site's Divi global colors. It never deletes one (a page that cites a missing
 * color silently loses it). Every real run needs the token from a dry run of the same arguments; it writes Divi's
 * COMPLETE color map once, reads back what Divi really stored and records THAT as one undoable batch. The five theme
 * colors change only with allow_theme_colors, and only their color. MCP and REST only: the ChatGPT spec has no room.
 */
final class ManageGlobalColors implements ProTool
{
    public const NAME = 'manage_global_colors';

    private const UNAVAILABLE = "Divi's global color functions are not available on this site (is the Divi 5 theme active?), so nothing was changed.";

    private \Closure $clock;
    private \Closure $newId;

    /**
     * @param (\Closure():int)|null $clock the time in seconds (the tests move it)
     * @param (\Closure():string)|null $newId makes the id of a created color
     */
    public function __construct( private BatchLog $log, ?\Closure $clock = null, ?\Closure $newId = null )
    {
        $this->clock = $clock ?? static fn (): int => time();
        $this->newId = $newId ?? static fn (): string => 'gcid-' . wp_generate_uuid4();
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
            'description' => 'Create, recolor, rename or deactivate the site\'s Divi global colors (never deletes). The five theme colors need allow_theme_colors=true. Affects every page using a color: call with dry_run=true first, then repeat with the preview_token. Undoable.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'colors'             => [
                        'type'        => 'array',
                        'minItems'    => 1,
                        'maxItems'    => GlobalColorPlanner::MAX_ENTRIES,
                        'description' => 'The changes. No id = create a color (label and color needed). With an id (from list_global_styles) = change that color: any of label, color, status.',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'id'     => [ 'type' => 'string', 'description' => 'gcid-... of an existing color; leave out to create one' ],
                                'label'  => [ 'type' => 'string', 'description' => 'Name, 1 to 60 characters' ],
                                'color'  => [ 'type' => 'string', 'description' => 'Hex (#rgb, #rgba, #rrggbb, #rrggbbaa) or rgb()/rgba()/hsl()/hsla() with plain numbers' ],
                                'status' => [ 'type' => 'string', 'enum' => [ 'active', 'inactive' ], 'description' => 'inactive retires a color without deleting it' ],
                            ],
                        ],
                    ],
                    'dry_run'            => [ 'type' => 'boolean', 'description' => 'Preview only; returns the preview_token a real run needs' ],
                    'preview_token'      => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                    'allow_theme_colors' => [ 'type' => 'boolean', 'description' => 'Needed to change one of the five theme colors (primary, secondary, heading, body, link): that restyles the whole theme' ],
                ],
                'required'   => [ 'colors' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $entries = GlobalColorPlanner::normalize( Args::listOf( $arguments, 'colors', GlobalColorPlanner::MAX_ENTRIES ) );
        $dry     = Args::bool( $arguments, 'dry_run' );
        $token   = Args::string( $arguments, 'preview_token', false, 8000 );
        $allow   = Args::bool( $arguments, 'allow_theme_colors' );

        $before = GlobalColors::read();
        if ( null === $before ) {
            throw new \InvalidArgumentException( self::UNAVAILABLE );
        }
        $now  = ( $this->clock )();
        $plan = GlobalColorPlanner::plan( $before, $entries, $allow, GlobalColors::stamp( $now ), $this->newId );
        if ( [] === $plan['changes'] ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'unchanged' => $plan['unchanged'], 'message' => 'Every color already is exactly like that; nothing changed.' ];
        }

        $argsHash = Canon::hash( [ 'colors' => $entries, 'allow_theme_colors' => $allow ] );
        $userId   = (int) get_current_user_id();
        if ( $dry ) {
            $items = [];
            foreach ( $plan['changes'] as $c ) {
                $items[ $c['index'] ] = Canon::hash( $c['before'] );
            }

            return [
                'changed'              => false,
                'dry_run'              => true,
                'changes'              => array_map( static fn ( array $c ): array => [ 'index' => $c['index'], 'id' => 'create' === $c['action'] ? null : $c['id'], 'action' => $c['action'], 'before' => $c['before'], 'after' => $c['after'] ], $plan['changes'] ),
                'theme_colors_touched' => $plan['theme_touched'],
                'preview_token'        => PreviewToken::make( $argsHash, $items, $now, $userId ),
                'expires_in'           => PreviewToken::TTL,
                'message'              => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this preview_token to apply it.' . ( $plan['theme_touched'] ? ' This changes a theme color, which restyles the whole theme.' : '' ),
            ];
        }

        $map = null === $token ? null : PreviewToken::open( $token, $argsHash, $now, $userId );
        if ( null === $map ) {
            throw new \InvalidArgumentException( 'A real run needs the preview_token from a dry run of the same arguments (including allow_theme_colors), made by you in the last 30 minutes and not used before. Call again with dry_run=true.' );
        }
        PreviewToken::spend( (string) $token );

        $kept    = [];
        $skipped = [];
        foreach ( $plan['changes'] as $c ) {
            if ( isset( $map[ $c['index'] ] ) && hash_equals( $map[ $c['index'] ], Canon::hash( $c['before'] ) ) ) {
                $kept[] = $c;
            } else {
                $skipped[] = [ 'index' => $c['index'], 'id' => 'create' === $c['action'] ? null : $c['id'], 'reason' => isset( $map[ $c['index'] ] ) ? 'changed_since_preview' : 'not_in_preview' ];
            }
        }
        if ( [] === $kept ) {
            return [ 'changed' => false, 'dry_run' => false, 'skipped' => $skipped, 'message' => 'Every color changed since the preview, so nothing was written. Call again with dry_run=true.' ];
        }

        $fresh = GlobalColors::read();
        if ( null === $fresh || self::fingerprint( $fresh ) !== self::fingerprint( $before ) ) {
            throw new \InvalidArgumentException( "The site's global colors changed while this call was running; nothing was written. Call again with dry_run=true." );
        }

        $send = GlobalColors::customMap( $before );
        foreach ( $kept as $c ) {
            $send[ $c['id'] ] = $c['write'];
        }
        $res   = GlobalColors::write( $send );
        $after = $res['after'];
        if ( null === $after ) {
            throw new \RuntimeException( "Divi's global colors could not be read back after the write, so nothing is recorded. Check list_global_styles for their current state." );
        }

        $recorded   = [];
        $applied    = [];
        $notStored  = [];
        $notChanged = [];
        $differs    = [];
        foreach ( $kept as $c ) {
            $stored = GlobalColors::state( $after[ $c['id'] ] ?? null );
            if ( 'create' === $c['action'] && null === $stored ) {
                $notStored[] = [ 'index' => $c['index'], 'label' => $c['after']['label'] ];
                continue;
            }
            if ( 'create' !== $c['action'] && $stored === $c['before'] ) {
                $notChanged[] = $c['id'];
                continue;
            }
            if ( $stored !== $c['after'] ) {
                $differs[] = [ 'id' => $c['id'], 'requested' => $c['after'], 'stored' => $stored ];
            }
            $recorded[] = GlobalColorKind::itemFor( $c['id'], $c['before'], $stored );
            $applied[]  = [ 'id' => $c['id'], 'action' => $c['action'], 'before' => $c['before'], 'after' => $stored ];
        }
        $changedIds = array_column( $kept, 'id' );
        $unexpected = [];
        foreach ( $before as $id => $entry ) {
            if ( in_array( $id, $changedIds, true ) ) {
                continue;
            }
            $now_ = $after[ $id ] ?? null;
            if ( null === $now_ ) {
                $unexpected[] = [ 'id' => $id, 'reason' => 'removed_by_divi' ];
            } elseif ( GlobalColors::state( $now_ ) !== GlobalColors::state( $entry ) ) {
                $unexpected[] = [ 'id' => $id, 'reason' => 'changed' ];
            }
        }

        if ( [] === $recorded ) {
            if ( $res['threw'] ) {
                throw new \RuntimeException( 'Divi did not save the colors, so nothing is recorded. Check list_global_styles for their current state.' );
            }

            return [ 'changed' => false, 'dry_run' => false, 'skipped' => $skipped, 'not_stored' => $notStored, 'not_changed' => $notChanged, 'unexpected_changes' => $unexpected, 'message' => 'Divi accepted the write, but none of the requested changes is stored; nothing is recorded.' ];
        }

        $batch  = $this->log->record( self::NAME, 'Global colors: ' . count( $recorded ) . ' change(s)', $recorded );
        $result = [
            'changed'              => true,
            'dry_run'              => false,
            'changes'              => $applied,
            'skipped'              => $skipped,
            'theme_colors_touched' => [] !== array_filter( $kept, static fn ( array $c ): bool => $c['theme'] ),
            'note'                 => "A color only shows on pages that cite its gcid id in a layout (list_global_styles shows the ids, the style guide the reference shape). Divi's caches were cleared.",
        ];
        foreach ( [ 'differs_from_request' => $differs, 'not_stored' => $notStored, 'not_changed' => $notChanged, 'unexpected_changes' => $unexpected ] as $key => $list ) {
            if ( [] !== $list ) {
                $result[ $key ] = $list;
            }
        }
        if ( $res['threw'] ) {
            $result['warning'] = 'Divi reported an error while saving; what is stored now is what was recorded, and undo_batch can put the previous state back.';
        }

        return BatchLog::attach( $result, $batch );
    }

    /** @param array<string,array<string,mixed>> $all */
    private static function fingerprint( array $all ): string
    {
        return Canon::hash( array_map( [ GlobalColors::class, 'state' ], $all ) );
    }
}
```

Modify `pro-addon/src/autoload.php`: add directly after `require_once __DIR__ . '/Tools/ManageThemeTemplate.php';`:

```php
require_once __DIR__ . '/Tools/ManageGlobalColors.php';
```

Modify `pro-addon/src/Tools.php`: add `use AiEditorDivi5\Pro\Tools\ManageGlobalColors;` (alphabetical, before `ManageThemeTemplate`) and append `new ManageGlobalColors( $log ),` as the last entry of the array in `all()` (after `new BuildSite( $log ),`).

Modify `pro-addon/tests/ToolsTest.php`, `testThisReleaseShipsExactlyTheseTools`: append `'manage_global_colors'` to the expected list (after `'build_site'`).

Modify `pro-addon/tests/ExpiryKeepsAllToolsTest.php`: change the class doc comment "all 14 Pro tools" to "all 15 Pro tools", `assertCount( 14, $names )` to `assertCount( 15, $names )` and the message `'all 14 Pro tools are advertised'` to `'all 15 Pro tools are advertised'`.

- [ ] **Step 4: Run to verify pass**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter "ManageGlobalColorsTest|GlobalColorKindTest|GlobalColorPlannerTest|GlobalColorsTest|ProSafetyTest|ExpiryKeepsAllToolsTest"`
Expected: PASS for those. `ToolsTest::testTheChatGptOperationBudgetIsRespected` and `ProParityTest` now FAIL (31 operations; the spec would include the new tool) — that is Task 5's job; do not touch them here.

If `testIfTheColorsChangeWhileTheCallRunsNothingIsWritten` fails: count the reads. A real run reads Divi exactly twice before writing (the initial `read()`, then the re-read), so the hook on `n === 2` must hit the re-read. Do not weaken the test; fix the call order in `run()`.

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src/Tools/ManageGlobalColors.php pro-addon/src/Tools.php pro-addon/src/autoload.php pro-addon/tests/ManageGlobalColorsTest.php pro-addon/tests/ToolsTest.php pro-addon/tests/ExpiryKeepsAllToolsTest.php
git commit -m "feat(pro): manage_global_colors, the 15th Pro tool (preview, token, one complete write, honest read-back, undoable)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: MCP and REST only: the recorded ChatGPT exception

**Files:**
- Modify: `pro-addon/src/Rest/ProOpenApi.php`, `pro-addon/tests/ProParityTest.php`, `pro-addon/tests/ProOpenApiTest.php`, `pro-addon/tests/ToolsTest.php`

**Interfaces:**
- Consumes: `ProOpenApi`, `Tools::all()`, `OpenApiSpec`.
- Produces: `ProOpenApi::MCP_REST_ONLY` (`list<string>`, currently `['manage_global_colors']`); `ProOpenApi::paths()` skips those tools; the parity tests pin that the tool is in MCP and REST and NOT in the spec, and the 30-operation budget counts `Tools::all()` minus `MCP_REST_ONLY`.

- [ ] **Step 1: Write/adjust the failing tests**

In `pro-addon/tests/ToolsTest.php`: add `use AiEditorDivi5\Pro\Rest\ProOpenApi;` to the use list, add `require_once __DIR__ . '/../src/Rest/ProOpenApi.php';` after the existing `require_once` lines, and replace the last assertion of `testTheChatGptOperationBudgetIsRespected` (and extend its comment) with:

```php
        // The 15th Pro tool, manage_global_colors, is MCP + REST only by the owner's decision of 2026-10-05: it is not an
        // operation of the spec, so the budget counts the Pro tools that ARE in it (16 free + 14 Pro = 30, exactly).
        $inSpec = count( Tools::all() ) - count( ProOpenApi::MCP_REST_ONLY );
        $this->assertLessThanOrEqual( 30, $free + $inSpec );
        $this->assertSame( [ 'manage_global_colors' ], ProOpenApi::MCP_REST_ONLY, 'a new MCP-only tool is an owner decision: change this list on purpose' );
```

In `pro-addon/tests/ProParityTest.php`:

1. Add `use AiEditorDivi5\Pro\Rest\ProOpenApi;` is already imported. Replace the body of `testMcpRestAndOpenApiExposeExactlyTheSameTools` after computing `$mcp`, `$rest`, `$api` with:

```php
        $inSpec = array_values( array_diff( $mcp, ProOpenApi::MCP_REST_ONLY ) );
        $this->assertSame( $mcp, $rest );
        $this->assertSame( $inSpec, $api, 'the spec has every Pro tool except the MCP + REST only ones' );
        $this->assertCount( count( Tools::all() ), $mcp );
```

2. In `testEveryOperationCarriesTheToolsOwnArguments`, at the top of the `foreach` body add:

```php
            if ( in_array( $def['name'], ProOpenApi::MCP_REST_ONLY, true ) ) {
                $this->assertArrayNotHasKey( '/pro/' . $def['name'], $this->spec['paths'], $def['name'] );
                continue;
            }
```

3. Add a new test:

```php
    public function testMcpRestOnlyToolsAreOverMcpAndRestButNotInTheChatGptSpec(): void
    {
        $mcp  = array_column( $this->registry->definitions( [] ), 'name' );
        $rest = array_map( static fn ( array $r ): string => substr( $r[1], strlen( '/pro/' ) ), $GLOBALS['__wp_rest_routes'] );
        foreach ( ProOpenApi::MCP_REST_ONLY as $name ) {
            $this->assertContains( $name, $mcp, "{$name} over MCP" );
            $this->assertContains( $name, $rest, "{$name} over REST" );
            $this->assertArrayNotHasKey( '/pro/' . $name, $this->spec['paths'], "{$name} is not in the ChatGPT spec" );
        }
        $this->assertCount( 14, $this->proOperations(), '16 free + 14 Pro operations = the importer\'s 30' );
    }
```

In `pro-addon/tests/ProOpenApiTest.php`, `testEveryOperationDocumentsTheStatusesAClientCanGet`: keep `assertCount( 14, $paths )` (it must still hold) and add right after it:

```php
        $this->assertArrayNotHasKey( '/pro/manage_global_colors', $paths, 'MCP + REST only: no room in the 30-operation ChatGPT spec' );
```

- [ ] **Step 2: Run to verify failure**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter "ToolsTest|ProParityTest|ProOpenApiTest"`
Expected: FAIL — `Undefined constant ...ProOpenApi::MCP_REST_ONLY`.

- [ ] **Step 3: Implement**

In `pro-addon/src/Rest/ProOpenApi.php`:

1. Replace the class doc comment's first paragraph sentence "One operation per tool (the importer caps a spec at 30 operations in total; the free plugin has 16), every description at most 300 characters, and no object schema without properties." with: "One operation per tool, except the tools listed in MCP_REST_ONLY (the importer caps a spec at 30 operations in total; the free plugin has 16 and the first 14 Pro tools use the rest), every description at most 300 characters, and no object schema without properties."

2. Add the constant right after `private const SUMMARY_MAX = 120;`:

```php
    /** Tools that exist over MCP and REST only: the 16 free + 14 Pro operations already use all 30 the ChatGPT importer accepts. */
    public const MCP_REST_ONLY = [ 'manage_global_colors' ];
```

3. In `paths()`, right after `$def    = $tool->definition();` insert:

```php
            if ( in_array( $def['name'], self::MCP_REST_ONLY, true ) ) {
                continue;
            }
```

- [ ] **Step 4: Run to verify pass, then the whole Pro suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml --filter "ToolsTest|ProParityTest|ProOpenApiTest"` → PASS.
Run: `make test-pro` → all green (the previous count was 993; expect that plus the new tests, no failures).

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src/Rest/ProOpenApi.php pro-addon/tests/ProParityTest.php pro-addon/tests/ProOpenApiTest.php pro-addon/tests/ToolsTest.php
git commit -m "feat(pro): manage_global_colors is MCP + REST only (ChatGPT spec stays at 30 operations, exception pinned by the parity tests)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: The live gate `make verify-pro-global-colors`

**Files:**
- Create: `scripts/verify-pro-global-colors.php`
- Modify: `Makefile`

**Interfaces:**
- Consumes: the real `Tools::all()` through `ToolRegistry::claim()`, `GlobalColors`, `BatchTable`, `scripts/dev-guard.php`, Divi's real `GlobalData`, WordPress on the Docker dev site (`make up`; free and Pro plugins active).
- Produces: a self-cleaning WP-CLI script that prints `PASS`/`FAIL` lines and exits non-zero on any failure.

There is no unit test for a live script; its evidence is a green run on the dev site (Step 3).

- [ ] **Step 1: Write the script**

Create `scripts/verify-pro-global-colors.php`:

```php
<?php
/**
 * Real-site check of the Pro manage_global_colors tool on Divi's own global colors: the evidence gate for that tool.
 * Through the real ToolRegistry it proves: a dry run changes nothing; create / recolor / deactivate change exactly one
 * entry each and every other stored color (including usedInPosts and lastUpdated) is untouched; a throwaway PUBLISHED page
 * that cites the new color gets that color's value in Divi's generated CSS, and the new value after the recolor; undo
 * walks it all back (the stored colors and the five theme options are identical to the start); a theme color is refused
 * without allow_theme_colors and its preview with the flag changes nothing; the token is required and single use; a color
 * changed since a batch is not removed by undoing the create.
 *
 * Run: make verify-pro-global-colors   (needs `make up`, the free plugin and the Pro add-on active on the dev site)
 * The page is fetched through the Docker network (http://wordpress, Host header = the site's host); set AIED_LOOPBACK_BASE to
 * change that base. Dev sites only. WARNING: it records about 6 batches and deletes them at the end.
 */

use AiEditorDivi5\Pro\Batch\BatchTable;
use AiEditorDivi5\Pro\Settings\GlobalColors;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\WP\HistoryTable;
use ET\Builder\Packages\GlobalData\GlobalData;

if ( ! class_exists( \AiEditorDivi5\Pro\Plugin::class ) || ! class_exists( HistoryTable::class ) ) {
    WP_CLI::error( 'Activate jhmg-ai-editor-for-divi-5 and jhmg-ai-editor-for-divi-5-pro on this site first.' );
}
if ( ! class_exists( GlobalData::class ) || ! function_exists( 'et_get_option' ) ) {
    WP_CLI::error( "Divi 5's GlobalData class is not loaded: activate the Divi theme on this site first." );
}

global $wpdb;

require_once __DIR__ . '/dev-guard.php';
aied_require_dev_site();

$failures = [];
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
    WP_CLI::log( ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label );
    if ( ! $ok ) {
        $failures[] = $label;
    }
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

/** Every stored color (usage compared as strings: Divi's sanitiser stores them as strings) plus the five theme options. */
$fingerprint = static function (): string {
    $norm = [];
    foreach ( (array) GlobalColors::read() as $id => $e ) {
        $norm[ $id ] = [ (string) ( $e['color'] ?? '' ), (string) ( $e['folder'] ?? '' ), (string) ( $e['label'] ?? '' ), GlobalColors::isThemeId( $id ) ? '' : (string) ( $e['lastUpdated'] ?? '' ), (string) ( $e['status'] ?? '' ), array_map( 'strval', (array) ( $e['usedInPosts'] ?? [] ) ) ];
    }
    $opts = [];
    foreach ( GlobalData::$customizer_colors as $def ) {
        $opts[ $def['option_name'] ] = et_get_option( $def['option_name'], $def['default'] );
    }

    return md5( serialize( [ $norm, $opts ] ) );
};
$fetch = static function ( string $url ) use ( &$fetch ): string {
    $p    = wp_parse_url( $url );
    $host = ( $p['host'] ?? 'localhost' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
    $path = ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
    $base = rtrim( (string) ( getenv( 'AIED_LOOPBACK_BASE' ) ?: 'http://wordpress' ), '/' );
    $res  = wp_remote_get( $base . $path, [ 'headers' => [ 'Host' => $host ], 'timeout' => 30, 'redirection' => 0, 'sslverify' => false ] );
    if ( is_wp_error( $res ) ) {
        return '';
    }
    $code = (int) wp_remote_retrieve_response_code( $res );
    if ( in_array( $code, [ 301, 302 ], true ) && '' !== (string) wp_remote_retrieve_header( $res, 'location' ) ) {
        return $fetch( (string) wp_remote_retrieve_header( $res, 'location' ) );
    }

    return (string) wp_remote_retrieve_body( $res );
};
/** The page HTML plus every same-site stylesheet it links (Divi may put the variables in a static file). */
$render = static function ( int $pageId ) use ( $fetch ): string {
    $html = $fetch( add_query_arg( 'page_id', $pageId, home_url( '/' ) ) );
    $all  = $html;
    if ( preg_match_all( '/<link[^>]+href=[\'"]([^\'"]+\.css[^\'"]*)[\'"]/i', $html, $m ) ) {
        $site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        foreach ( array_slice( array_unique( $m[1] ), 0, 20 ) as $href ) {
            if ( $site === (string) wp_parse_url( $href, PHP_URL_HOST ) ) {
                $all .= "\n" . $fetch( $href );
            }
        }
    }

    return $all;
};
$defines = static fn ( string $css, string $id, string $value ): bool => 1 === preg_match( '/--' . preg_quote( $id, '/' ) . '\s*:\s*' . preg_quote( $value, '/' ) . '/i', $css );
$layout  = static function ( string $id ): string {
    $color = '$variable(' . wp_json_encode( [ 'type' => 'color', 'value' => [ 'name' => $id, 'settings' => new stdClass() ] ] ) . ')$';
    $attrs = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'AIED colour probe' ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => $color ] ] ] ] ] ], 'builderVersion' => '5.14.0' ];

    return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/heading ' . serialize_block_attributes( $attrs ) . ' /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
};

HistoryTable::ensureReady( true );
BatchTable::ensureReady( true );

// ---- 1. baseline --------------------------------------------------------------------------------------------------
$fpBefore   = $fingerprint();
$countStart = count( (array) GlobalColors::read() );
$themeOpts  = [];
foreach ( GlobalData::$customizer_colors as $def ) {
    $themeOpts[ $def['option_name'] ] = et_get_option( $def['option_name'], $def['default'] );
}
WP_CLI::log( "baseline: {$countStart} color(s) (five of them theme colors)" );
$pageId = 0;
$newId  = '';
try {
    // ---- 2. a dry run changes nothing -------------------------------------------------------------------------------
    $new = [ [ 'label' => 'AIED verify sky', 'color' => '#00aaff' ] ];
    $d   = $call( 'manage_global_colors', [ 'colors' => $new, 'dry_run' => true ] );
    $check( null === $d['error'] && true === $d['result']['dry_run'] && false === $d['result']['changed'] && is_string( $d['result']['preview_token'] ?? null ) && null === $d['result']['changes'][0]['id'], 'dry run: a preview, a token, no id yet, changed=false' );
    $check( $fingerprint() === $fpBefore, 'the dry run changed nothing' );
    $r = $call( 'manage_global_colors', [ 'colors' => $new ] );
    $check( $errHas( $r, 'preview_token' ), 'a real run without the token is refused' );
    $check( $fingerprint() === $fpBefore, 'the refused run changed nothing' );

    // ---- 3. create ----------------------------------------------------------------------------------------------------
    $c = $call( 'manage_global_colors', [ 'colors' => $new, 'preview_token' => $d['result']['preview_token'] ] );
    $check( null === $c['error'] && true === $c['result']['changed'] && 'create' === $c['result']['changes'][0]['action'] && true === $c['result']['batch']['undoable'], 'create: applied and undoable' );
    $newId   = (string) ( $c['result']['changes'][0]['id'] ?? '' );
    $createB = (int) ( $c['result']['batch']['id'] ?? 0 );
    $after   = (array) GlobalColors::read();
    $check( str_starts_with( $newId, 'gcid-' ) && '#00aaff' === ( $after[ $newId ]['color'] ?? '' ) && 'AIED verify sky' === ( $after[ $newId ]['label'] ?? '' ) && 'active' === ( $after[ $newId ]['status'] ?? '' ), 'the color is stored through Divi: ' . $newId );
    $check( count( $after ) === $countStart + 1, 'exactly one entry was added' );
    $reuse = $call( 'manage_global_colors', [ 'colors' => $new, 'preview_token' => $d['result']['preview_token'] ] );
    $check( $errHas( $reuse, 'preview_token' ), 'the token works once' );

    // ---- 4. a real page that cites it gets the color in Divi's CSS ----------------------------------------------------
    $pageId = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'aied verify gc probe', 'post_content' => wp_slash( $layout( $newId ) ), 'meta_input' => [ '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ] ], true );
    if ( is_wp_error( $pageId ) ) {
        throw new \RuntimeException( $pageId->get_error_message() );
    }
    $css = $render( (int) $pageId );
    $check( '' !== $css, 'the probe page rendered (if this FAILS: set AIED_LOOPBACK_BASE to a URL the wpcli container can reach)' );
    $check( $defines( $css, $newId, '#00aaff' ), "Divi's generated CSS defines --{$newId} as #00aaff on the page that cites it" );

    // ---- 5. recolor, then the page shows the new value ----------------------------------------------------------------
    $recolor = [ [ 'id' => $newId, 'color' => '#ff8800' ] ];
    $d2      = $call( 'manage_global_colors', [ 'colors' => $recolor, 'dry_run' => true ] );
    $c2      = $call( 'manage_global_colors', [ 'colors' => $recolor, 'preview_token' => $d2['result']['preview_token'] ?? '' ] );
    $recB    = (int) ( $c2['result']['batch']['id'] ?? 0 );
    $check( true === ( $c2['result']['changed'] ?? false ) && '#ff8800' === ( GlobalColors::read()[ $newId ]['color'] ?? '' ), 'recolor: stored' );
    $css2 = $render( (int) $pageId );
    $check( $defines( $css2, $newId, '#ff8800' ) && ! $defines( $css2, $newId, '#00aaff' ), 'the page now gets the new value (Divi cleared its caches)' );

    // ---- 6. deactivate ------------------------------------------------------------------------------------------------
    $off  = [ [ 'id' => $newId, 'status' => 'inactive' ] ];
    $d3   = $call( 'manage_global_colors', [ 'colors' => $off, 'dry_run' => true ] );
    $c3   = $call( 'manage_global_colors', [ 'colors' => $off, 'preview_token' => $d3['result']['preview_token'] ?? '' ] );
    $offB = (int) ( $c3['result']['batch']['id'] ?? 0 );
    $check( 'deactivate' === ( $c3['result']['changes'][0]['action'] ?? '' ) && 'inactive' === ( GlobalColors::read()[ $newId ]['status'] ?? '' ), 'deactivate: stored, nothing deleted' );

    // ---- 7. the token is bound to the arguments ----------------------------------------------------------------------------
    $d4 = $call( 'manage_global_colors', [ 'colors' => [ [ 'id' => $newId, 'color' => '#123456' ] ], 'dry_run' => true ] );
    $x  = $call( 'manage_global_colors', [ 'colors' => [ [ 'id' => $newId, 'color' => '#654321' ] ], 'preview_token' => $d4['result']['preview_token'] ?? '' ] );
    $check( $errHas( $x, 'preview_token' ) && 'inactive' === ( GlobalColors::read()[ $newId ]['status'] ?? '' ) && '#ff8800' === GlobalColors::read()[ $newId ]['color'], 'a token for other arguments does not open a run' );

    // ---- 8. refusals ------------------------------------------------------------------------------------------------------
    foreach ( [ 'red', 'var(--x)', '#fff; }', 'url(x)', "#fff\n" ] as $bad ) {
        $r = $call( 'manage_global_colors', [ 'colors' => [ [ 'label' => 'x', 'color' => $bad ] ], 'dry_run' => true ] );
        $check( $errHas( $r, '"color" must be a hex value' ), 'refused color: ' . json_encode( $bad ) );
    }
    $r = $call( 'manage_global_colors', [ 'colors' => [ [ 'id' => 'gcid-00000000-0000-4000-8000-000000000000', 'color' => '#fff' ] ], 'dry_run' => true ] );
    $check( $errHas( $r, 'no color with the id' ), 'an unknown id is refused' );
    $r = $call( 'manage_global_colors', [ 'colors' => [ [ 'label' => 'ok', 'color' => '#fff' ], [ 'id' => 'gcid-00000000-0000-4000-8000-000000000000', 'color' => '#fff' ] ], 'dry_run' => true ] );
    $check( $errHas( $r, 'Nothing was changed' ), 'one bad entry refuses the whole palette' );

    // ---- 9. theme colors ------------------------------------------------------------------------------------------------------
    $theme = [ [ 'id' => 'gcid-primary-color', 'color' => '#ff0000' ] ];
    $r     = $call( 'manage_global_colors', [ 'colors' => $theme, 'dry_run' => true ] );
    $check( $errHas( $r, 'allow_theme_colors' ), 'a theme color is refused without allow_theme_colors' );
    $fpMid = $fingerprint();
    $r     = $call( 'manage_global_colors', [ 'colors' => $theme, 'allow_theme_colors' => true, 'dry_run' => true ] );
    $check( null === $r['error'] && true === $r['result']['theme_colors_touched'] && $fingerprint() === $fpMid, 'a theme color preview with the flag reports it and changes nothing' );
    $themeNow = [];
    foreach ( GlobalData::$customizer_colors as $def ) {
        $themeNow[ $def['option_name'] ] = et_get_option( $def['option_name'], $def['default'] );
    }
    $check( $themeOpts === $themeNow, 'the five theme options are untouched' );

    // ---- 10. undo walks it all back; undo of a create refuses once the color changed since ------------------------
    $u = $call( 'undo_batch', [ 'batch_id' => $createB ] );
    $check( null === $u['error'] && false === ( $u['result']['fully_undone'] ?? true ) && isset( GlobalColors::read()[ $newId ] ), 'undoing the create while later batches changed the color does not remove it' );
    foreach ( [ $offB, $recB ] as $b ) {
        $u = $call( 'undo_batch', [ 'batch_id' => $b ] );
        $check( true === ( $u['result']['fully_undone'] ?? false ), "undo of batch {$b}" );
    }
    $check( '#00aaff' === ( GlobalColors::read()[ $newId ]['color'] ?? '' ) && 'active' === ( GlobalColors::read()[ $newId ]['status'] ?? '' ), 'the color is exactly as the create left it' );
    $u = $call( 'undo_batch', [ 'batch_id' => $createB ] );
    $check( true === ( $u['result']['fully_undone'] ?? false ) && ! isset( GlobalColors::read()[ $newId ] ), 'now the undo of the create removes the color' );
} catch ( \Throwable $e ) {
    $failures[] = 'aborted by ' . get_class( $e ) . ': ' . $e->getMessage();
    WP_CLI::log( 'FAIL  aborted by ' . get_class( $e ) . ': ' . $e->getMessage() );
} finally {
    if ( $pageId > 0 && null !== get_post( (int) $pageId ) ) {
        wp_delete_post( (int) $pageId, true );
    }
    if ( '' !== $newId && isset( ( (array) GlobalColors::read() )[ $newId ] ) ) { // cleanup after an abort: remove the throwaway color directly
        GlobalColors::write( GlobalColors::customMap( array_diff_key( (array) GlobalColors::read(), [ $newId => 1 ] ) ) );
    }
}

// ---- 11. the site is exactly as it was ---------------------------------------------------------------------------------------
$leftover = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'aied verify gc%'" ); // phpcs:ignore
$check( $fingerprint() === $fpBefore, 'every stored color (including usedInPosts and lastUpdated) and the five theme options are identical to the start' );
$check( count( (array) GlobalColors::read() ) === $countStart, 'the color count equals the baseline' );
$check( 0 === $leftover, "no throwaway page remains ({$leftover})" );
if ( [] !== $batchIds ) {
    $wpdb->query( 'DELETE FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
}
$left = [] === $batchIds ? 0 : (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BatchTable::name() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $batchIds ) ) . ')' ); // phpcs:ignore
$check( 0 === $left, "none of the batch rows this run created remain ({$left} left)" );

if ( [] !== $failures ) {
    WP_CLI::error( count( $failures ) . ' check(s) failed: ' . implode( '; ', $failures ) );
}
WP_CLI::success( 'Pro manage_global_colors verified on the real Divi global colors.' );
```

Modify `Makefile`:

1. Add `verify-pro-global-colors` to the `.PHONY` line (after `verify-pro-theme-templates`).
2. Add this block directly after the `verify-pro-theme-templates` target (copy its comment-then-target style and indentation, which is a TAB before `@docker`):

```make
# verify-pro-global-colors — real-site evidence gate for manage_global_colors (self-cleaning; free + Pro plugins must be active; dev site only)
#   (the probe page is fetched through the Docker network; set AIED_LOOPBACK_BASE if http://wordpress is not reachable)
.PHONY: verify-pro-global-colors
verify-pro-global-colors:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-global-colors.php --user=1
```

(If the existing targets do not repeat `.PHONY` per target, drop that single line and rely on the top `.PHONY` edit.)

- [ ] **Step 2: Syntax-check the script**

Run: `php -l scripts/verify-pro-global-colors.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Run the gate on the Docker dev site**

Prerequisites: `make up` done; the free plugin and the Pro add-on are active on the dev site (the Pro add-on reflects `pro-addon/` as mounted; if the container serves a copy, sync it the way the other `verify-pro-*` gates require; see `docs/pricing-launch-guide.md` and the headers of the existing `scripts/verify-pro-*.php`).

Run: `make verify-pro-global-colors`
Expected: every line `PASS`, ending `Success: Pro manage_global_colors verified on the real Divi global colors.`

If a check FAILS, decide honestly which side is wrong, never loosen a check to make it green:
- The probe page does not render or the loopback fetch is empty → fix the fetch (`AIED_LOOPBACK_BASE`, Host header, redirects); do not remove the render checks.
- Divi emits the variable differently from `--gcid-…:value` (for example inside a `:root{}` block with different spacing, or only in a static file the fetch missed) → read the real CSS once (`$css`), adapt the `$defines` regex to what Divi really emits, and say so in the commit message. This is the gate's job: it records what Divi really does.
- The final fingerprint differs → that is a real finding (the tool left a trace); report it, do not paper over it.

- [ ] **Step 4: Commit**

```bash
git add scripts/verify-pro-global-colors.php Makefile
git commit -m "test(pro): live gate verify-pro-global-colors (create, recolor, deactivate, render proof on a real page, theme-color refusal, undo to byte-identical)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Release 0.7.0: version, readme, docs, final checks

**Files:**
- Modify: `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`, `pro-addon/tests/bootstrap.php`, `pro-addon/readme.txt`, `pro-addon/README.md`, `docs/superpowers/specs/2026-10-05-pro-global-colors-design.md`, `docs/feature-requests-2026-10-04.md`

**Interfaces:** none new; `ReadmeTest` and `MainFileTest` already pin that the readme Stable tag equals `AIED_PRO_VERSION` and that header and constant agree.

- [ ] **Step 1: Bump the version everywhere**

- `pro-addon/jhmg-ai-editor-for-divi-5-pro.php`: header ` * Version:           0.6.0` → `0.7.0`, and `define( 'AIED_PRO_VERSION', '0.6.0' );` → `'0.7.0'`.
- `pro-addon/tests/bootstrap.php`: `define( 'AIED_PRO_VERSION', '0.6.0' );` → `'0.7.0'`.
- `pro-addon/readme.txt`: `Stable tag:        0.6.0` → `0.7.0`, and add this block at the top of `== Changelog ==` (above `= 0.6.0 =`):

```
= 0.7.0 =
* New: `manage_global_colors` creates, recolors, renames and deactivates your site's Divi global colors, so an assistant can set the palette before it builds pages. It never deletes a color. Every real run needs the single-use preview token of a dry run of the same arguments; colors that changed since the preview are skipped and reported, never overwritten. The five theme colors (primary, secondary, heading, body, link) change only when you pass `allow_theme_colors`, and only their color. Color values are checked strictly (hex or rgb/rgba/hsl/hsla numbers only). One `undo_batch` puts it all back; undoing a created color removes it, unless it changed since.
* Over MCP and REST only: this is the 15th Pro tool, and the ChatGPT importer's 30 operations are used up by the first 14 (16 free + 14 Pro).
```

- [ ] **Step 2: Update `pro-addon/README.md`**

- Heading `## What exists (0.6.0)` → `## What exists (0.7.0)`.
- In the `src/ProTool.php ...` row: `lists the fourteen tools` → `lists the fifteen tools`.
- In the `src/Kinds/` row, append `, a Divi global color` to the list of kinds.
- In the `src/Settings/` row: `` `CustomCss` (managed block), `MenuBuilder`, `PhpProposals` `` → append `` , `GlobalColors` (the only code that talks to Divi's global colors), `GlobalColorPlanner` (the all-or-nothing change list) ``.
- In the `src/Tools/` row: `the fourteen tools:` → `the fifteen tools:` and append `` , `manage_global_colors` `` after `` `build_site` ``.
- Replace the limit line `- The ChatGPT importer allows 30 operations in total: 16 free + 14 Pro = 30, so the budget is used up: a 15th Pro operation means merging two tools.` with: `- The ChatGPT importer allows 30 operations in total: 16 free + 14 Pro = 30, so the budget is used up. The 15th Pro tool, `manage_global_colors`, is MCP + REST only on purpose (`ProOpenApi::MCP_REST_ONLY`; `ProParityTest` and `ToolsTest` pin the exception). Any further tool needs the same owner decision or a merge of two tools.`
- Add `` `make verify-pro-global-colors` `` to the list of real-site checks in the paragraph that starts "`make test-pro` runs the Pro tests".
- Add a section before `## Known limits`:

```markdown
## Global colors (`manage_global_colors`)

Divi's `GlobalData::set_global_colors()` REPLACES the whole custom color map, so the tool always sends the complete map once (untouched entries exactly as Divi returned them) and then reads back what Divi really stored; that read-back, not the request, is what the batch records. The five theme (Customizer) colors live in the theme's own settings: they are sent only when being changed (needs `allow_theme_colors`, bound into the preview token), only their `color` can change, and they are read through their live option because Divi caches them for the whole request. There is no delete (a page citing a missing color silently loses it); `temporary` colors belong to Divi's builder session and are refused. An entry Divi's own sanitiser cannot keep (an empty color) is dropped by any replace-all save: the result lists it as `unexpected_changes`. Spec: `docs/superpowers/specs/2026-10-05-pro-global-colors-design.md`; plan: `docs/superpowers/plans/2026-10-05-pro-global-colors.md`.
```

- [ ] **Step 3: Record the implementation notes in the spec and the backlog**

Append to `docs/superpowers/specs/2026-10-05-pro-global-colors-design.md`:

```markdown

## Implementation notes (2026-10-05, from the plan; all narrow the spec)

1. A theme color changes `color` only: Divi stores nothing else for it. A `label` or `status` on a theme id is refused.
2. Instead of "recompute once", the tool re-reads once just before writing and aborts (nothing written) if any color differs from what it planned against.
3. A created color's id is generated at each run: a dry run shows `id: null` for creates; the real run reports the id.
4. `read()` returns a theme color's LIVE option value (Divi caches the Customizer colors in a static for the whole request, so its own read is stale right after a write); an entry that is not an array makes `read()` return null and the tool refuses.
5. Undo of a created color removes it and is refused (`changed_since`, even with `force`) unless the entry is exactly what the batch wrote.
```

In `docs/feature-requests-2026-10-04.md`, find the item 1 entry (global colors / presets / variables) and add one line under it: `Status 2026-10-05: global COLORS built as Pro 0.7.0 (manage_global_colors, MCP + REST only). Variables and presets remain open (separate specs; presets need real-export grounding).` (If item 1 is written as a table row or bullet, add the sentence to the same cell/bullet instead.)

- [ ] **Step 4: Full verification**

Run each and report the real numbers:
- `make test-pro` → all pass, 0 failures (the previous count was 993; the new total is higher).
- `make test` → all pass (the free suite is unchanged: 805).
- `php -l scripts/verify-pro-global-colors.php` → no syntax errors.
- `make build-pro` → builds `dist/jhmg-ai-editor-for-divi-5-pro.zip`; then run `unzip -l dist/jhmg-ai-editor-for-divi-5-pro.zip | grep -E "GlobalColor|ManageGlobalColors"` and confirm the four new `src/` files are inside and no `tests/` files are.
- `git diff main --stat -- wp-plugin src` → empty (the free plugin and validator are untouched).

If `make verify-pro-global-colors` was not run in Task 6 Step 3 because the Docker site was unavailable, say so plainly in the hand-off; the release is NOT ready until it has passed once.

- [ ] **Step 5: Commit**

```bash
git add pro-addon/jhmg-ai-editor-for-divi-5-pro.php pro-addon/tests/bootstrap.php pro-addon/readme.txt pro-addon/README.md docs/superpowers/specs/2026-10-05-pro-global-colors-design.md docs/feature-requests-2026-10-04.md
git commit -m "release(pro): 0.7.0, manage_global_colors (readme, changelog, notes)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Self-review (done against the spec)

- **Spec coverage:** tool, arguments, create/update/no-delete, atomic refusals (Tasks 2, 4); validation incl. the CSS-injection table (Task 1); safe write, complete map, read-back, token, per-entry stale hash excluding `lastUpdated`, re-read guard (Task 4); batch kind, undo of create/update, capability re-check (Task 3); MCP + REST, ChatGPT exception pinned, 15th tool, 0.7.0 (Tasks 4, 5, 7); Divi shim, unit tests, parity, lapse behaviour (`ExpiryKeepsAllToolsTest` updated to 15; the lapse logic is unchanged); live gate with render proof, theme refusal, byte-identical cleanup (Task 6). The spec's "Risks and decisions" are carried: undo of a create removes (owner approved), `temporary` refused, labels required on create, "not applied until a layout cites it" is in the result `note`, replay limit unchanged (`PreviewToken`).
- **Placeholder scan:** none; every code step has the code.
- **Type consistency:** `GlobalColors::{read,state,customMap,themeEntry,write,stamp,isThemeId,validColor,cleanLabel,validId,available}`, `GlobalColorPlanner::{normalize,plan}` and the change array keys (`index,id,action,fields,before,after,write,theme`) are used identically in Tasks 2, 3 and 4; `GlobalColorKind::itemFor( id, ?before, ?after )` is the same in Tasks 3 and 4; `ProOpenApi::MCP_REST_ONLY` is the same in Task 5 tests and code.
- **Known soft spot to watch during execution:** the live gate's CSS check depends on how Divi 5.14 really emits `--gcid-…` (Task 6 Step 3 says what to do); the unit tests do not.
