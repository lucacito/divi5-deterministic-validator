# Pro `manage_global_presets` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an AI assistant create and update the site's Divi module presets from the Pro add-on (previewed, custom-code-guarded, locked, undoable; MCP + REST only), inside the pending Pro 0.8.0.

**Architecture:** The colours and variables pattern again. `GlobalPresets` is the only code that talks to Divi's preset functions; `GlobalPresetPlanner` is a pure change planner; `GlobalPresetKind` (a `SiteLockingKind`) records and undoes each item under the site-wide `GlobalPresetsLock`; `ManageGlobalPresets` is the tool. Creates go through Divi's additive import function; updates and undo go through the complete-data save.

**Tech Stack:** PHP 8 (strict types), PHPUnit (`make test-pro`), WP-CLI + Docker for the live gate, Divi 5.14.

**Spec:** `docs/superpowers/specs/2026-10-06-pro-global-presets-design.md` (read it first). Evidence: `docs/global-presets-evidence-5.14.md`. Templates: `pro-addon/src/Settings/GlobalVariables*.php`, `pro-addon/src/Kinds/GlobalVariableKind.php`, `pro-addon/src/Tools/ManageGlobalVariables.php` and their tests.

## Global Constraints

- Pro add-on only (`pro-addon/`), plus docs and scripts. `wp-plugin/` and the validator are NOT touched.
- Divi is reached ONLY through the class named by `GlobalPresets::PRESET_CLASS` (`ET\Builder\Packages\GlobalData\GlobalPreset`) with `call_user_func( [ self::PRESET_CLASS, '<method>' ], ... )`, each behind `is_callable` guards; never the option name. **Do not write the literal text `GlobalPreset::` or any raw option name in any file under `pro-addon/src/`**: `pro-addon/tests/ProSafetyScanner.php` bans those strings in Pro source, and the colours and variables accessors already follow this convention. Do NOT edit the safety scanner or its test.
- Tool `manage_global_presets`: Pro capability `edit_theme_options`, checked by the registry AND again by `GlobalPresets::permitted()` (Divi's own PHP functions check nothing); 17th Pro tool; MCP + REST only (`ProOpenApi::MCP_REST_ONLY` gets a third name; the ChatGPT spec stays 16 free + 14 Pro = 30 operations). Description at most 300 characters. `presets` 1 to 20 entries.
- Create = no `id` (`module`, `name`, `attrs` required; never becomes the module's default). Update = `id` plus at least one of `name`/`attrs`; `attrs` REPLACES the preset's style tree (`attrs` and `styleAttrs`), `renderAttrs`/`version`/`type`/`created` are kept, `updated` is set to now; `module` on an update is refused. Updating a module's DEFAULT preset needs `allow_default_presets=true` (bound into the preview token). No delete. Atomic refusal of the whole call with one reason per bad entry.
- Validation: `module` matches `^divi/[a-z0-9-]+$` and `SchemaRules::isKnownType()`; `name` 1 to 60 characters via `GlobalColors::cleanLabel()`; `id` matches `^[A-Za-z0-9-]{8,64}$`; `attrs` a non-empty associative object tree of at most 20000 bytes of JSON and depth 12, and `CodePolicy::violations()` over the synthetic block `<!-- wp:<module> {attrs} /-->` must return nothing (custom CSS, scripts and unsafe style values refused). Legacy data (a module key that is not `divi/...`, an item without a matching `moduleName`) is carried untouched and refused as a target.
- Preview token: `PreviewToken` (30 minutes, single use, bound to user and arguments incl. `allow_default_presets`); spend it only right before the first write. Real runs and real undos hold `GlobalPresetsLock` (prefix `aied_pro_buildlock_gpresets_`, 30 s expiry) from before the first read; a dry run takes no lock.
- Every refusal throws `\InvalidArgumentException` with a plain message and changes nothing. Never weaken or delete an existing assertion; the tests named in Task 5 and Task 6 change only because of the new tool count.
- Add-on version stays 0.8.0 (no bump): add the changelog and README entries inside the existing 0.8.0 sections.
- Commit trailer on every commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Branch `feat/pro-global-presets` (the spec and this plan are already committed). Never push, deploy or release; never stage `CLAUDE.md`.
- Test commands: one file `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/<File>.php`; `make test-pro` (1369 tests before this plan); `make test` (815, must stay green).

## Review Focus

1. A stored data set holding legacy oddities (module key `0`, an item with no `moduleName`) is carried through every write byte-identical and never offered as a target (Tasks 1, 2, 4).
2. Custom code in a preset's attrs, in any `css` family (`freeForm`, `mainElement`), a `<script>` in a text, an over-long or too-deep tree: refused before any write, on create AND on update (Tasks 1, 2).
3. A create for a module with no presets: Divi adds a default preset; the tool reports it and undo removes it and the module entry, but only while untouched (Tasks 3, 4).
4. Update undo and create undo after a later edit: `changed_since`, never a silent overwrite; the lock is held from before the first read of a real run and of a real undo; busy fails with no read or write (Tasks 3, 4).
5. Divi unavailable, a method missing or throwing, a save Divi did not store, or other presets changing during the write: refused or reported honestly, nothing recorded for what was not stored (Task 4).

---

### Task 1: The Divi preset shim, the fixture and the `GlobalPresets` accessor

**Files:**
- Modify: `pro-addon/tests/DiviGlobalDataShims.php` (add a guarded `GlobalPreset` class inside the existing `namespace ET\Builder\Packages\GlobalData { ... }` block)
- Create: `pro-addon/tests/GlobalPresetsFixture.php`
- Create: `pro-addon/src/Settings/GlobalPresets.php`
- Modify: `pro-addon/src/autoload.php`
- Test: `pro-addon/tests/GlobalPresetsTest.php`

**Interfaces:**
- Consumes: `GlobalColors::cleanLabel( string ): ?string`, `\Divi5Validator\SchemaRules::isKnownType( string ): bool`, `\AiEditorDivi5\WP\CodePolicy::violations( string ): list<Violation>` (each with `->code()`), `current_user_can`.
- Produces (all `public static` on `AiEditorDivi5\Pro\Settings\GlobalPresets`):
  - `const PRESET_CLASS`, `const MAX_ATTRS_BYTES = 20000`, `const MAX_DEPTH = 12`
  - `available( ?string $class = null ): bool`; `permitted(): bool`; `read(): ?array`
  - `unsafe( array $data ): list<string>` (shape problems that make a save unsafe)
  - `find( array $data, string $id ): ?array{module:string,item:array}`; `managed( string $module, array $item ): bool`
  - `state( ?array $item ): ?array{name:string,moduleName:string,attrs:array}`
  - `defaultId( array $data, string $module ): string`; `itemIds( array $data, string $module ): list<string>`
  - `validId( string ): bool`; `validModule( string ): bool`
  - `attrsProblems( string $module, array $attrs ): list<string>` (empty list = acceptable)
  - `newItem( string $module, string $id, string $name, array $attrs, int $now ): array`
  - `import( string $module, array $item, string $defaultId ): array{threw:bool, after:?array}`; `save( array $data ): array{threw:bool, after:?array}`
- Test helpers produced: shim state `$GLOBALS['__divi_gp']` (keys `data, save_calls, import_calls, gets, throw_get, throw_save, throw_import, mutate, on_get, now`); `GlobalPresetsFixture::{H_DEFAULT,H1,T_DEFAULT,data(),reset( array $extra = [] ),clear()}`.

- [ ] **Step 1: Add the Divi preset shim**

In `pro-addon/tests/DiviGlobalDataShims.php`, inside the existing `namespace ET\Builder\Packages\GlobalData {` block, directly after the closing `}` of `class GlobalData` (the block's last `}` of that class), add:

```php
    if ( ! class_exists( GlobalPreset::class ) ) {
        /**
         * A stand-in for Divi 5.14's preset storage (state in $GLOBALS['__divi_gp']): get_data() returns the stored data;
         * save_data() REPLACES it; process_presets_for_import() is ADDITIVE: an item whose id already exists with the same
         * name and attrs is skipped, with other content it is stored as a copy under a NEW id, and a module with no presets
         * gets a default preset created by Divi. Divi's PHP functions check no capability.
         */
        class GlobalPreset
        {
            /** @param array<string,mixed> $data */
            public static function resetForTests( array $data = [] ): void
            {
                $GLOBALS['__divi_gp'] = [
                    'data' => $data, 'save_calls' => [], 'import_calls' => [], 'gets' => 0,
                    'throw_get' => false, 'throw_save' => false, 'throw_import' => false, 'mutate' => null, 'on_get' => null, 'now' => 1790000000,
                ];
            }

            public static function option_name(): string
            {
                return 'builder_global_presets_d5';
            }

            /** @return array<string,mixed> */
            public static function get_data(): array
            {
                if ( ! isset( $GLOBALS['__divi_gp'] ) ) {
                    self::resetForTests();
                }
                $s = &$GLOBALS['__divi_gp'];
                ++$s['gets'];
                if ( is_callable( $s['on_get'] ) ) {
                    ( $s['on_get'] )( $s['gets'] );
                }
                if ( $s['throw_get'] ) {
                    throw new \RuntimeException( 'Divi could not read the presets' );
                }

                return $s['data'];
            }

            /**
             * @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            public static function save_data( array $data, bool $skip_external_cache = false ): array
            {
                if ( ! isset( $GLOBALS['__divi_gp'] ) ) {
                    self::resetForTests();
                }
                $s = &$GLOBALS['__divi_gp'];
                $s['save_calls'][] = $data;
                if ( $s['throw_save'] ) {
                    throw new \RuntimeException( 'Divi refused the save' );
                }
                if ( is_callable( $s['mutate'] ) ) {
                    $data = ( $s['mutate'] )( $data );
                }
                $s['data'] = $data;
                \ET_Core_PageResource::remove_static_resources( 'all', 'all', true );

                return $s['data'];
            }

            /**
             * @param array<string,mixed> $presets
             * @return array<string,mixed>
             */
            public static function process_presets_for_import( array $presets, bool $auto_save = true, bool $skip_external_cache = false ): array
            {
                if ( ! isset( $GLOBALS['__divi_gp'] ) ) {
                    self::resetForTests();
                }
                $s = &$GLOBALS['__divi_gp'];
                $s['import_calls'][] = $presets;
                if ( $s['throw_import'] ) {
                    throw new \RuntimeException( 'Divi refused the import' );
                }
                $data     = $s['data'];
                $mappings = [ 'module' => [], 'group' => [] ];
                foreach ( (array) ( $presets['module'] ?? [] ) as $module => $entry ) {
                    $existed = [] !== (array) ( $data['module'][ $module ]['items'] ?? [] );
                    if ( ! isset( $data['module'][ $module ] ) ) {
                        $data['module'][ $module ] = [ 'default' => '', 'items' => [] ];
                    }
                    foreach ( (array) ( $entry['items'] ?? [] ) as $id => $item ) {
                        $exists = $data['module'][ $module ]['items'][ $id ] ?? null;
                        if ( null !== $exists ) {
                            if ( ( $exists['name'] ?? null ) === ( $item['name'] ?? null ) && ( $exists['attrs'] ?? null ) === ( $item['attrs'] ?? null ) ) {
                                continue;
                            }
                            $new                     = wp_generate_uuid4();
                            $mappings['module'][ $id ] = $new;
                            $item['id']              = $new;
                            $id                      = $new;
                        }
                        $item['created']                              = $s['now'];
                        $item['updated']                              = $s['now'];
                        $data['module'][ $module ]['items'][ $id ] = $item;
                    }
                    if ( ! $existed ) {
                        $did                                       = wp_generate_uuid4();
                        $data['module'][ $module ]['items'][ $did ] = [ 'id' => $did, 'moduleName' => $module, 'name' => ucfirst( substr( (string) $module, 5 ) ) . ' 1', 'attrs' => [], 'styleAttrs' => [], 'version' => '5.14.0', 'type' => 'module', 'created' => $s['now'], 'updated' => $s['now'] ];
                        $data['module'][ $module ]['default']      = $did;
                    }
                }
                if ( $auto_save ) {
                    if ( is_callable( $s['mutate'] ) ) {
                        $data = ( $s['mutate'] )( $data );
                    }
                    $s['data'] = $data;
                    \ET_Core_PageResource::remove_static_resources( 'all', 'all', true );
                }

                return [ 'presets' => $data, 'preset_id_mappings' => $mappings, 'defaultImportedModulePresetIds' => [], 'defaultImportedGroupPresetIds' => [] ];
            }
        }
    }
```

(If the file's `GlobalData` class closes the namespace block directly, put the new `if` block before the block's closing brace. `ET_Core_PageResource` and `wp_generate_uuid4` are already shimmed in the file's global namespace block.)

- [ ] **Step 2: Add the fixture**

Create `pro-addon/tests/GlobalPresetsFixture.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use ET\Builder\Packages\GlobalData\GlobalPreset;

require_once __DIR__ . '/DiviGlobalDataShims.php';

/** Stored presets as Divi stores them, including the legacy oddities seen on a real site. */
final class GlobalPresetsFixture
{
    public const H_DEFAULT = 'c78db57d-4ac7-4849-b5b4-1797b4b1eb8f';
    public const H1        = '1e32dbff-cc61-4cda-b68a-39243c406d98';
    public const T_DEFAULT = '0afc9131-1111-4222-8333-444444444444';
    public const LEGACY    = 's2s-test-1785336674';

    /** @return array<string,array<string,mixed>> */
    public static function data(): array
    {
        $item = static fn ( string $id, string $module, string $name, array $attrs ): array => [
            'id' => $id, 'moduleName' => $module, 'name' => $name, 'attrs' => $attrs, 'styleAttrs' => $attrs, 'version' => '5.8.1', 'type' => 'module', 'created' => 1782593353, 'updated' => 1782593353,
        ];
        $size = static fn ( string $px ): array => [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => $px ] ] ] ] ] ] ];

        return [
            'module' => [
                'divi/heading' => [
                    'default' => self::H_DEFAULT,
                    'items'   => [
                        self::H_DEFAULT => $item( self::H_DEFAULT, 'divi/heading', 'Heading default', $size( '30px' ) ),
                        self::H1        => $item( self::H1, 'divi/heading', 'Heading 1', $size( '72px' ) ),
                    ],
                ],
                'divi/text'    => [
                    'default' => self::T_DEFAULT,
                    'items'   => [ self::T_DEFAULT => $item( self::T_DEFAULT, 'divi/text', 'Text default', [ 'content' => [ 'decoration' => [ 'bodyFont' => [ 'body' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '16px' ] ] ] ] ] ] ] ] ) ],
                ],
                '0'            => [
                    'default' => 'legacy-default',
                    'items'   => [
                        self::LEGACY => [ 'id' => self::LEGACY, 'name' => 'S2S Test Preset', 'attrs' => [ 'x' => '1' ], 'type' => 'module' ],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $replace replaces the stored data entirely when given */
    public static function reset( array $replace = [] ): void
    {
        GlobalPreset::resetForTests( [] === $replace ? self::data() : $replace );
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_options']         = [];
        $GLOBALS['__wp_caps']            = [ 'edit_theme_options' => true ];
        $GLOBALS['__wp_current_user_id'] = 7;
    }

    public static function clear(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__divi_gp'] );
    }
}
```

- [ ] **Step 3: Write the failing test**

Create `pro-addon/tests/GlobalPresetsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalPresets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalPresetsFixture.php';
require_once __DIR__ . '/WpBuildShims.php';

class GlobalPresetsTest extends TestCase
{
    private const H1 = GlobalPresetsFixture::H1;

    protected function setUp(): void
    {
        GlobalPresetsFixture::reset();
    }

    protected function tearDown(): void
    {
        GlobalPresetsFixture::clear();
    }

    public function testAvailableNeedsAllThreeDiviMethods(): void
    {
        $this->assertTrue( GlobalPresets::available() );
        $this->assertFalse( GlobalPresets::available( 'No\\Such\\Class' ) );
    }

    public function testPermittedIsTheThemeOptionsCapability(): void
    {
        $this->assertTrue( GlobalPresets::permitted() );
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $this->assertFalse( GlobalPresets::permitted() );
    }

    public function testReadReturnsDivisDataAsIsAndFailsClosed(): void
    {
        $this->assertSame( GlobalPresetsFixture::data(), GlobalPresets::read() );
        $GLOBALS['__divi_gp']['throw_get'] = true;
        $this->assertNull( GlobalPresets::read() );
    }

    public function testUnsafeNamesShapeProblemsButAcceptsTheFixtureAndAnEmptyStore(): void
    {
        $this->assertSame( [], GlobalPresets::unsafe( GlobalPresetsFixture::data() ) );
        $this->assertSame( [], GlobalPresets::unsafe( [] ) );
        $this->assertNotSame( [], GlobalPresets::unsafe( [ 'module' => [ 'divi/heading' => [ 'default' => 'x' ] ] ] ), 'an entry without an items list' );
        $this->assertNotSame( [], GlobalPresets::unsafe( [ 'module' => 'x' ] ) );
        $this->assertNotSame( [], GlobalPresets::unsafe( [ 'mystery' => [] ] ), 'an unknown top-level key would be erased by a save we cannot reproduce' );
    }

    public function testFindStateAndManaged(): void
    {
        $data  = GlobalPresetsFixture::data();
        $found = GlobalPresets::find( $data, self::H1 );
        $this->assertSame( 'divi/heading', $found['module'] );
        $this->assertTrue( GlobalPresets::managed( $found['module'], $found['item'] ) );
        $this->assertSame( [ 'name' => 'Heading 1', 'moduleName' => 'divi/heading', 'attrs' => $found['item']['attrs'] ], GlobalPresets::state( $found['item'] ) );
        $this->assertNull( GlobalPresets::state( null ) );
        $this->assertNull( GlobalPresets::find( $data, 'nope-nope-nope' ) );

        $legacy = GlobalPresets::find( $data, GlobalPresetsFixture::LEGACY );
        $this->assertSame( '0', $legacy['module'] );
        $this->assertFalse( GlobalPresets::managed( $legacy['module'], $legacy['item'] ), 'a legacy module key and an item without moduleName are never a target' );
        $this->assertFalse( GlobalPresets::managed( 'divi/text', [ 'moduleName' => 'divi/heading', 'attrs' => [], 'name' => 'x' ] ), 'the item must name the module that holds it' );
    }

    public function testDefaultIdAndItemIds(): void
    {
        $data = GlobalPresetsFixture::data();
        $this->assertSame( GlobalPresetsFixture::H_DEFAULT, GlobalPresets::defaultId( $data, 'divi/heading' ) );
        $this->assertSame( '', GlobalPresets::defaultId( $data, 'divi/image' ) );
        $this->assertSame( [ GlobalPresetsFixture::H_DEFAULT, self::H1 ], GlobalPresets::itemIds( $data, 'divi/heading' ) );
        $this->assertSame( [], GlobalPresets::itemIds( $data, 'divi/image' ) );
    }

    public function testIdAndModuleRules(): void
    {
        foreach ( [ '1e32dbff-cc61-4cda-b68a-39243c406d98', 'abcdefgh', 'A1-b2-C3-d4' ] as $ok ) {
            $this->assertTrue( GlobalPresets::validId( $ok ), $ok );
        }
        foreach ( [ 'short', 'has space here', 'a;b-cdefgh', str_repeat( 'a', 65 ), "id\n12345678", '' ] as $bad ) {
            $this->assertFalse( GlobalPresets::validId( $bad ), $bad );
        }
        $this->assertTrue( GlobalPresets::validModule( 'divi/heading' ) );
        $this->assertTrue( GlobalPresets::validModule( 'divi/contact-form' ) );
        foreach ( [ 'heading', 'divi/', 'divi/not-a-real-module', 'divi/Heading', '0', 'divi/heading ' ] as $bad ) {
            $this->assertFalse( GlobalPresets::validModule( $bad ), $bad );
        }
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function badAttrs(): array
    {
        $deep = [];
        $node = &$deep;
        for ( $i = 0; $i < 14; $i++ ) {
            $node['a'] = [];
            $node      = &$node['a'];
        }
        $node = 'x';
        unset( $node );

        return [
            'an empty tree'              => [ [], 'must not be empty' ],
            'a list, not an object'      => [ [ 'a', 'b' ], 'must be an object' ],
            'custom css freeForm'        => [ [ 'module' => [ 'advanced' => [ 'css' => [ 'desktop' => [ 'value' => [ 'freeForm' => '.x{outline:9px solid red}' ] ] ] ] ] ], 'CUSTOM_CSS_NOT_ALLOWED' ],
            'custom css mainElement'     => [ [ 'module' => [ 'advanced' => [ 'css' => [ 'desktop' => [ 'value' => [ 'mainElement' => 'outline:7px solid blue' ] ] ] ] ] ], 'CUSTOM_CSS_NOT_ALLOWED' ],
            'a script in a text'         => [ [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<script>alert(1)</script>' ] ] ] ], 'SCRIPT_CONTENT_NOT_ALLOWED' ],
            'too deep'                   => [ $deep, 'deep' ],
            'too large'                  => [ [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => str_repeat( 'a', 20001 ) ] ] ] ], 'large' ],
        ];
    }

    /** @param array<string,mixed> $attrs */
    #[DataProvider( 'badAttrs' )]
    public function testAttrsProblemsRefusesCodeAndOversizeAttrs( array $attrs, string $needle ): void
    {
        $problems = GlobalPresets::attrsProblems( 'divi/heading', $attrs );
        $this->assertNotSame( [], $problems );
        $this->assertStringContainsString( $needle, implode( ' ', $problems ) );
    }

    public function testANormalStyleTreeIsAccepted(): void
    {
        $attrs = [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '57px', 'weight' => '700' ] ] ] ] ] ], 'module' => [ 'decoration' => [ 'spacing' => [ 'desktop' => [ 'value' => [ 'margin' => [ 'top' => '', 'bottom' => '39px', 'syncVertical' => 'off' ] ] ] ] ] ] ];
        $this->assertSame( [], GlobalPresets::attrsProblems( 'divi/heading', $attrs ) );
    }

    public function testNewItemHasTheShapeOfARealStoredPreset(): void
    {
        $attrs = [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '57px' ] ] ] ] ] ] ];
        $item  = GlobalPresets::newItem( 'divi/heading', 'aaaaaaaa-0000-4000-8000-000000000001', 'Big', $attrs, 1790000000 );
        $this->assertSame( [ 'id', 'moduleName', 'name', 'attrs', 'styleAttrs', 'version', 'type', 'created', 'updated' ], array_keys( $item ) );
        $this->assertSame( 'module', $item['type'] );
        $this->assertSame( $attrs, $item['attrs'] );
        $this->assertSame( $attrs, $item['styleAttrs'] );
        $this->assertSame( 1790000000, $item['created'] );
        $this->assertNotSame( '', $item['version'] );
    }

    public function testImportIsAdditiveAndSaveReplacesTheWholeData(): void
    {
        $item = GlobalPresets::newItem( 'divi/heading', 'aaaaaaaa-0000-4000-8000-000000000001', 'Big', [ 'title' => [ 'x' => '1' ] ], 1790000000 );
        $res  = GlobalPresets::import( 'divi/heading', $item, GlobalPresetsFixture::H_DEFAULT );
        $this->assertFalse( $res['threw'] );
        $this->assertArrayHasKey( 'aaaaaaaa-0000-4000-8000-000000000001', $res['after']['module']['divi/heading']['items'] );
        $this->assertArrayHasKey( self::H1, $res['after']['module']['divi/heading']['items'], 'existing presets are kept' );
        $this->assertSame( GlobalPresetsFixture::H_DEFAULT, $res['after']['module']['divi/heading']['default'] );

        $only = [ 'module' => [ 'divi/text' => GlobalPresetsFixture::data()['module']['divi/text'] ] ];
        $res  = GlobalPresets::save( $only );
        $this->assertSame( $only, $res['after'], 'save replaces everything' );
    }

    public function testAnImportForAModuleWithNoPresetsMakesDiviAddADefaultPreset(): void
    {
        $item = GlobalPresets::newItem( 'divi/image', 'aaaaaaaa-0000-4000-8000-000000000002', 'Rounded', [ 'module' => [ 'x' => '1' ] ], 1790000000 );
        $res  = GlobalPresets::import( 'divi/image', $item, '' );
        $this->assertCount( 2, $res['after']['module']['divi/image']['items'] );
        $this->assertNotSame( '', $res['after']['module']['divi/image']['default'] );
    }

    public function testImportAndSaveReportAThrowingDivi(): void
    {
        $GLOBALS['__divi_gp']['throw_import'] = true;
        $GLOBALS['__divi_gp']['throw_save']   = true;
        $item = GlobalPresets::newItem( 'divi/heading', 'aaaaaaaa-0000-4000-8000-000000000001', 'Big', [ 'a' => '1' ], 1790000000 );
        $this->assertTrue( GlobalPresets::import( 'divi/heading', $item, '' )['threw'] );
        $this->assertTrue( GlobalPresets::save( [] )['threw'] );
    }
}
```

- [ ] **Step 4: Run it to see it fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalPresetsTest.php`
Expected: errors (class `GlobalPresets` not found).

- [ ] **Step 5: Implement the accessor**

Create `pro-addon/src/Settings/GlobalPresets.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

use AiEditorDivi5\WP\CodePolicy;
use Divi5Validator\SchemaRules;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ONLY code that talks to Divi's module presets, and only through Divi's own class (never its option name). Divi's PHP
 * functions check no capability, so permitted() is enforced here. A create goes through Divi's ADDITIVE import; an update or a
 * removal needs the complete data and Divi's replace-all save. Anything missing, throwing or of an unexpected shape fails closed.
 */
final class GlobalPresets
{
    public const PRESET_CLASS = 'ET\\Builder\\Packages\\GlobalData\\GlobalPreset';

    public const MAX_ATTRS_BYTES = 20000;
    public const MAX_DEPTH       = 12;

    public static function available( ?string $class = null ): bool
    {
        $class ??= self::PRESET_CLASS;
        foreach ( [ 'get_data', 'save_data', 'process_presets_for_import' ] as $method ) {
            if ( ! is_callable( [ $class, $method ] ) ) {
                return false;
            }
        }

        return true;
    }

    /** Divi's own functions check nothing, so this is the bar. */
    public static function permitted(): bool
    {
        return current_user_can( 'edit_theme_options' );
    }

    /** @return array<string,mixed>|null Divi's data as returned; null when unreadable */
    public static function read(): ?array
    {
        if ( ! self::available() ) {
            return null;
        }
        try {
            $data = call_user_func( [ self::PRESET_CLASS, 'get_data' ] );
        } catch ( \Throwable $e ) {
            return null;
        }

        return is_array( $data ) ? $data : null;
    }

    /**
     * Shape problems that make a replace-all save unsafe: a save writes back exactly what it is given, so it must be given something
     * it can reproduce faithfully.
     *
     * @param array<mixed> $data
     * @return list<string>
     */
    public static function unsafe( array $data ): array
    {
        $out = [];
        foreach ( $data as $kind => $modules ) {
            if ( ! in_array( $kind, [ 'module', 'group' ], true ) ) {
                $out[] = 'an unknown top-level key "' . $kind . '"';
                continue;
            }
            if ( ! is_array( $modules ) ) {
                $out[] = '"' . $kind . '" is not a list';
                continue;
            }
            foreach ( $modules as $name => $entry ) {
                if ( ! is_array( $entry ) || ! is_array( $entry['items'] ?? null ) ) {
                    $out[] = '"' . $kind . '/' . $name . '" has no items list';
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{module:string,item:array<string,mixed>}|null
     */
    public static function find( array $data, string $id ): ?array
    {
        foreach ( (array) ( $data['module'] ?? [] ) as $module => $entry ) {
            $item = is_array( $entry ) && is_array( $entry['items'] ?? null ) ? ( $entry['items'][ $id ] ?? null ) : null;
            if ( is_array( $item ) ) {
                return [ 'module' => (string) $module, 'item' => $item ];
            }
        }

        return null;
    }

    /** A preset this tool may target: a normal `divi/...` module key whose item names that same module and has a name and an attrs tree. @param array<string,mixed> $item */
    public static function managed( string $module, array $item ): bool
    {
        return self::validModule( $module )
            && ( $item['moduleName'] ?? null ) === $module
            && is_string( $item['name'] ?? null )
            && is_array( $item['attrs'] ?? null );
    }

    /** What counts as "this preset changed": its name, its module and its style tree. @param array<string,mixed>|null $item */
    public static function state( ?array $item ): ?array
    {
        if ( null === $item ) {
            return null;
        }

        return [
            'name'       => is_scalar( $item['name'] ?? null ) ? (string) $item['name'] : '',
            'moduleName' => is_scalar( $item['moduleName'] ?? null ) ? (string) $item['moduleName'] : '',
            'attrs'      => is_array( $item['attrs'] ?? null ) ? $item['attrs'] : [],
        ];
    }

    /** @param array<string,mixed> $data */
    public static function defaultId( array $data, string $module ): string
    {
        $default = $data['module'][ $module ]['default'] ?? '';

        return is_string( $default ) ? $default : '';
    }

    /** @param array<string,mixed> $data @return list<string> */
    public static function itemIds( array $data, string $module ): array
    {
        $items = $data['module'][ $module ]['items'] ?? [];

        return is_array( $items ) ? array_map( 'strval', array_keys( $items ) ) : [];
    }

    public static function validId( string $id ): bool
    {
        return 1 === preg_match( '/^[A-Za-z0-9-]{8,64}$/D', $id );
    }

    public static function validModule( string $module ): bool
    {
        return 1 === preg_match( '#^divi/[a-z0-9-]+$#D', $module ) && ( new SchemaRules() )->isKnownType( $module );
    }

    /**
     * Why an attribute tree may not be stored, as short problem texts (an empty list = acceptable). Custom CSS and code are refused by
     * the same policy the layout gate uses, run over the tree as the attributes of one block of this module; the validator's
     * required-attribute rules do not apply (a preset holds partial attributes).
     *
     * @param array<mixed> $attrs
     * @return list<string>
     */
    public static function attrsProblems( string $module, array $attrs ): array
    {
        if ( [] === $attrs ) {
            return [ 'attrs must not be empty' ];
        }
        if ( array_is_list( $attrs ) ) {
            return [ 'attrs must be an object, not a list' ];
        }
        $json = json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
        if ( false === $json ) {
            return [ 'attrs are not valid JSON text (invalid UTF-8?)' ];
        }
        if ( strlen( $json ) > self::MAX_ATTRS_BYTES ) {
            return [ 'attrs are too large (at most ' . self::MAX_ATTRS_BYTES . ' bytes)' ];
        }
        if ( self::depth( $attrs ) > self::MAX_DEPTH ) {
            return [ 'attrs are nested too deep (at most ' . self::MAX_DEPTH . ' levels)' ];
        }
        $codes = [];
        foreach ( CodePolicy::violations( '<!-- wp:' . $module . ' ' . $json . ' /-->' ) as $violation ) {
            $codes[ $violation->code() ] = true;
        }

        return array_keys( $codes );
    }

    /**
     * The stored item for a new preset, the keys and order of a real one; Divi stamps created and updated again on import.
     *
     * @param array<string,mixed> $attrs
     * @return array<string,mixed>
     */
    public static function newItem( string $module, string $id, string $name, array $attrs, int $now ): array
    {
        return [
            'id'         => $id,
            'moduleName' => $module,
            'name'       => $name,
            'attrs'      => $attrs,
            'styleAttrs' => $attrs,
            'version'    => self::version(),
            'type'       => 'module',
            'created'    => $now,
            'updated'    => $now,
        ];
    }

    /**
     * Divi's additive import of ONE preset (it merges, never erases; for a module with no presets Divi also adds a default preset).
     *
     * @param array<string,mixed> $item
     * @return array{threw:bool, after:?array<string,mixed>}
     */
    public static function import( string $module, array $item, string $defaultId ): array
    {
        $threw = false;
        try {
            call_user_func(
                [ self::PRESET_CLASS, 'process_presets_for_import' ],
                [ 'module' => [ $module => [ 'default' => $defaultId, 'items' => [ (string) $item['id'] => $item ] ] ] ],
                true
            );
        } catch ( \Throwable $e ) {
            $threw = true;
        }

        return [ 'threw' => $threw, 'after' => self::read() ];
    }

    /**
     * Divi's replace-all save of the COMPLETE data, then a read-back.
     *
     * @param array<string,mixed> $data
     * @return array{threw:bool, after:?array<string,mixed>}
     */
    public static function save( array $data ): array
    {
        $threw = false;
        if ( self::available() ) {
            try {
                call_user_func( [ self::PRESET_CLASS, 'save_data' ], $data );
            } catch ( \Throwable $e ) {
                $threw = true;
            }
        } else {
            $threw = true;
        }

        return [ 'threw' => $threw, 'after' => self::read() ];
    }

    private static function version(): string
    {
        if ( defined( 'ET_BUILDER_VERSION' ) ) {
            return (string) ET_BUILDER_VERSION;
        }

        return '5.14.0';
    }

    /** @param array<mixed> $node */
    private static function depth( array $node ): int
    {
        $max = 1;
        foreach ( $node as $value ) {
            if ( is_array( $value ) ) {
                $max = max( $max, 1 + self::depth( $value ) );
            }
        }

        return $max;
    }
}
```

In `pro-addon/src/autoload.php`, after the `Kinds/GlobalVariableKind.php` line add:

```php
require_once __DIR__ . '/Settings/GlobalPresets.php';
```

- [ ] **Step 6: Run the test and the whole suite**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalPresetsTest.php` (all pass); `make test-pro` (green, 1369 plus the new tests; `ProSafetyTest` must pass for the new src file: it must contain no literal `GlobalPreset` followed by two colons and no raw option name outside comments).
If a case of `badAttrs` fails because `CodePolicy` reports a different code for a form, assert on what it really returns (read `wp-plugin/src/CodePolicy.php`), never remove the case.

- [ ] **Step 7: Commit**

```bash
git add pro-addon/src/Settings/GlobalPresets.php pro-addon/src/autoload.php pro-addon/tests/DiviGlobalDataShims.php pro-addon/tests/GlobalPresetsFixture.php pro-addon/tests/GlobalPresetsTest.php
git commit -m "feat(pro): GlobalPresets accessor over Divi's preset functions, with a faithful shim

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `GlobalPresetPlanner`

**Files:**
- Create: `pro-addon/src/Settings/GlobalPresetPlanner.php`
- Modify: `pro-addon/src/autoload.php`
- Test: `pro-addon/tests/GlobalPresetPlannerTest.php`

**Interfaces:**
- Consumes: `GlobalPresets::{validId,validModule,attrsProblems,managed,find,state,defaultId,itemIds,newItem}`, `GlobalColors::cleanLabel`, `GlobalColors::MAX_LABEL`, `Canon::hash`.
- Produces: `GlobalPresetPlanner::MAX_ENTRIES` (20);
  `normalize( array $entries ): list<array{id:?string,module:?string,name:?string,attrs:?array}>` (throws `\InvalidArgumentException` naming every bad entry);
  `plan( array $data, array $rows, bool $allowDefault, int $now, \Closure $newId ): array{changes:list<array>, unchanged:list<string>}`; each change: `['index'=>int,'id'=>string,'module'=>string,'action'=>'create'|'update','before'=>?array,'after'=>array,'item'=>array,'is_default'=>bool,'default_created'=>bool,'module_existed'=>bool]` (`item` is the complete stored item to write; `before`/`after` are `GlobalPresets::state()` shapes).

- [ ] **Step 1: Write the failing test**

Create `pro-addon/tests/GlobalPresetPlannerTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalPresetPlanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalPresetsFixture.php';
require_once __DIR__ . '/WpBuildShims.php';

class GlobalPresetPlannerTest extends TestCase
{
    private const H_DEFAULT = GlobalPresetsFixture::H_DEFAULT;
    private const H1        = GlobalPresetsFixture::H1;

    private int $seq = 0;

    protected function setUp(): void
    {
        GlobalPresetsFixture::reset();
        $this->seq = 0;
    }

    protected function tearDown(): void
    {
        GlobalPresetsFixture::clear();
    }

    private function size( string $px ): array
    {
        return [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => $px ] ] ] ] ] ] ];
    }

    /** @param list<mixed> $entries */
    private function plan( array $entries, bool $allowDefault = false, ?array $data = null ): array
    {
        return GlobalPresetPlanner::plan( $data ?? GlobalPresetsFixture::data(), GlobalPresetPlanner::normalize( $entries ), $allowDefault, 1790000000, function (): string {
            return 'aaaaaaaa-0000-4000-8000-00000000000' . ++$this->seq;
        } );
    }

    private function refused( array $entries, string $needle, bool $allowDefault = false ): void
    {
        try {
            $this->plan( $entries, $allowDefault );
            $this->fail( 'expected a refusal containing: ' . $needle );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $needle, $e->getMessage() );
            $this->assertStringContainsString( 'Nothing was changed', $e->getMessage() );
        }
    }

    public function testNormalizeKeepsTheKnownFieldsAndCleansTheName(): void
    {
        $rows = GlobalPresetPlanner::normalize( [ [ 'module' => 'divi/heading', 'name' => ' Big heading ', 'attrs' => $this->size( '57px' ) ], [ 'id' => self::H1, 'name' => 'Renamed' ] ] );
        $this->assertSame( [ 'id' => null, 'module' => 'divi/heading', 'name' => 'Big heading', 'attrs' => $this->size( '57px' ) ], $rows[0] );
        $this->assertSame( [ 'id' => self::H1, 'module' => null, 'name' => 'Renamed', 'attrs' => null ], $rows[1] );
    }

    /** @return array<string,array{list<mixed>,string}> */
    public static function badEntries(): array
    {
        $ok = [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '57px' ] ] ] ] ] ] ];

        return [
            'not an object'              => [ [ 'x' ], 'must be an object' ],
            'unknown field'              => [ [ [ 'module' => 'divi/heading', 'name' => 'A', 'attrs' => $ok, 'default' => true ] ], 'unknown field "default"' ],
            'a non-string name'          => [ [ [ 'module' => 'divi/heading', 'name' => 5, 'attrs' => $ok ] ], '"name" must be a string' ],
            'attrs not an object'        => [ [ [ 'module' => 'divi/heading', 'name' => 'A', 'attrs' => 'x' ] ], '"attrs" must be an object' ],
            'create without module'      => [ [ [ 'name' => 'A', 'attrs' => $ok ] ], 'creating a preset needs' ],
            'create without name'        => [ [ [ 'module' => 'divi/heading', 'attrs' => $ok ] ], 'creating a preset needs' ],
            'create without attrs'       => [ [ [ 'module' => 'divi/heading', 'name' => 'A' ] ], 'creating a preset needs' ],
            'a module Divi has no block for' => [ [ [ 'module' => 'divi/not-a-module', 'name' => 'A', 'attrs' => $ok ] ], '"module" must be' ],
            'a bad name'                 => [ [ [ 'module' => 'divi/heading', 'name' => '<b>', 'attrs' => $ok ] ], '"name" must be 1 to 60' ],
            'a bad id'                   => [ [ [ 'id' => 'x', 'name' => 'A' ] ], '"id" must be' ],
            'module on an update'        => [ [ [ 'id' => self::H1, 'module' => 'divi/text', 'name' => 'A' ] ], 'never moves to another module' ],
            'an update changing nothing' => [ [ [ 'id' => self::H1 ] ], 'at least one of name, attrs' ],
            'custom css in attrs'        => [ [ [ 'module' => 'divi/heading', 'name' => 'A', 'attrs' => [ 'module' => [ 'advanced' => [ 'css' => [ 'desktop' => [ 'value' => [ 'freeForm' => '.x{color:red}' ] ] ] ] ] ] ] ], 'CUSTOM_CSS_NOT_ALLOWED' ],
        ];
    }

    /** @param list<mixed> $entries */
    #[DataProvider( 'badEntries' )]
    public function testNormalizeRefusesTheWholeRequestNamingTheEntry( array $entries, string $needle ): void
    {
        $this->refused( $entries, $needle );
        $this->refused( $entries, 'presets[0]' );
    }

    public function testCreateBuildsTheItemAndNeverTouchesTheDefault(): void
    {
        $p = $this->plan( [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->size( '57px' ) ] ] );
        $c = $p['changes'][0];
        $this->assertSame( 'create', $c['action'] );
        $this->assertSame( 'aaaaaaaa-0000-4000-8000-000000000001', $c['id'] );
        $this->assertNull( $c['before'] );
        $this->assertSame( 'Big', $c['after']['name'] );
        $this->assertSame( 'divi/heading', $c['item']['moduleName'] );
        $this->assertSame( $this->size( '57px' ), $c['item']['styleAttrs'] );
        $this->assertFalse( $c['is_default'] );
        $this->assertFalse( $c['default_created'], 'the module already has presets' );
        $this->assertTrue( $c['module_existed'] );
    }

    public function testACreateForAModuleWithNoPresetsSaysDiviWillAddADefaultPreset(): void
    {
        $p = $this->plan( [ [ 'module' => 'divi/image', 'name' => 'Rounded', 'attrs' => [ 'module' => [ 'decoration' => [ 'border' => [ 'desktop' => [ 'value' => [ 'radius' => '8px' ] ] ] ] ] ] ] ] );
        $this->assertTrue( $p['changes'][0]['default_created'] );
        $this->assertFalse( $p['changes'][0]['module_existed'] );
    }

    public function testUpdateReplacesTheTreeAndKeepsEverythingElse(): void
    {
        $p = $this->plan( [ [ 'id' => self::H1, 'attrs' => $this->size( '61px' ), 'name' => 'Heading 1 v2' ] ] );
        $c = $p['changes'][0];
        $this->assertSame( 'update', $c['action'] );
        $this->assertSame( 'Heading 1', $c['before']['name'] );
        $this->assertSame( '72px', $c['before']['attrs']['title']['decoration']['font']['font']['desktop']['value']['size'] );
        $this->assertSame( $this->size( '61px' ), $c['item']['attrs'] );
        $this->assertSame( $this->size( '61px' ), $c['item']['styleAttrs'] );
        $this->assertSame( 'Heading 1 v2', $c['item']['name'] );
        $this->assertSame( 1790000000, $c['item']['updated'] );
        $this->assertSame( 1782593353, $c['item']['created'] );
        $this->assertSame( '5.8.1', $c['item']['version'] );
        $this->assertSame( array_keys( GlobalPresetsFixture::data()['module']['divi/heading']['items'][ self::H1 ] ), array_keys( $c['item'] ), 'no key added or lost' );
    }

    public function testUpdatingAModulesDefaultPresetNeedsTheFlag(): void
    {
        $entries = [ [ 'id' => self::H_DEFAULT, 'attrs' => $this->size( '31px' ) ] ];
        $this->refused( $entries, 'default preset' );
        $p = $this->plan( $entries, true );
        $this->assertTrue( $p['changes'][0]['is_default'] );
    }

    public function testAnUpdateThatChangesNothingIsUnchanged(): void
    {
        $p = $this->plan( [ [ 'id' => self::H1, 'name' => 'Heading 1', 'attrs' => GlobalPresetsFixture::data()['module']['divi/heading']['items'][ self::H1 ]['attrs'] ] ] );
        $this->assertSame( [], $p['changes'] );
        $this->assertSame( [ self::H1 ], $p['unchanged'] );
    }

    public function testRefusalsForUnknownLegacyAndDuplicateTargets(): void
    {
        $this->refused( [ [ 'id' => 'nope-nope-nope', 'name' => 'A' ] ], 'no preset with the id' );
        $this->refused( [ [ 'id' => GlobalPresetsFixture::LEGACY, 'name' => 'A' ] ], 'cannot be changed here' );
        $this->refused( [ [ 'id' => self::H1, 'name' => 'A' ], [ 'id' => self::H1, 'name' => 'B' ] ], 'already in this call' );
        $this->refused( [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->size( '1px' ) ], [ 'module' => 'divi/heading', 'name' => 'BIG', 'attrs' => $this->size( '2px' ) ] ], 'already named' );
    }

    public function testCustomCodeInAnUpdateIsRefusedToo(): void
    {
        $this->refused( [ [ 'id' => self::H1, 'attrs' => [ 'module' => [ 'advanced' => [ 'css' => [ 'desktop' => [ 'value' => [ 'freeForm' => '.x{color:red}' ] ] ] ] ] ] ] ], 'CUSTOM_CSS_NOT_ALLOWED' );
    }

    public function testTheSameNameInAnotherModuleIsFine(): void
    {
        $p = $this->plan( [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->size( '1px' ) ], [ 'module' => 'divi/text', 'name' => 'Big', 'attrs' => [ 'content' => [ 'x' => '1' ] ] ] ] );
        $this->assertCount( 2, $p['changes'] );
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalPresetPlannerTest.php`
Expected: errors (class not found).

- [ ] **Step 3: Implement the planner**

Create `pro-addon/src/Settings/GlobalPresetPlanner.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

use AiEditorDivi5\Pro\Canon;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns a manage_global_presets request into a change list, or refuses the WHOLE request (presets are applied atomically or not at
 * all) with one message naming every bad entry. Pure: it reads nothing from Divi itself, the caller passes the current data.
 */
final class GlobalPresetPlanner
{
    public const MAX_ENTRIES = 20;

    private const KEYS = [ 'id', 'module', 'name', 'attrs' ];

    /**
     * @param list<mixed> $entries
     * @return list<array{id:?string,module:?string,name:?string,attrs:?array<mixed>}>
     */
    public static function normalize( array $entries ): array
    {
        $out    = [];
        $errors = [];
        foreach ( $entries as $i => $e ) {
            $where = "presets[{$i}]";
            if ( ! is_array( $e ) || ( [] !== $e && array_is_list( $e ) ) ) {
                $errors[] = "{$where} must be an object like {\"module\":\"divi/heading\",\"name\":\"Big heading\",\"attrs\":{...}}.";
                continue;
            }
            foreach ( array_keys( $e ) as $k ) {
                if ( ! in_array( $k, self::KEYS, true ) ) {
                    $errors[] = "{$where}: unknown field \"{$k}\" (allowed: id, module, name, attrs).";
                }
            }
            $row = [ 'id' => null, 'module' => null, 'name' => null, 'attrs' => null ];
            foreach ( [ 'id', 'module', 'name' ] as $k ) {
                if ( ! array_key_exists( $k, $e ) || null === $e[ $k ] ) {
                    continue;
                }
                if ( ! is_string( $e[ $k ] ) ) {
                    $errors[] = "{$where}: \"{$k}\" must be a string.";
                    continue;
                }
                $row[ $k ] = $e[ $k ];
            }
            if ( array_key_exists( 'attrs', $e ) && null !== $e['attrs'] ) {
                if ( ! is_array( $e['attrs'] ) ) {
                    $errors[] = "{$where}: \"attrs\" must be an object (the style tree).";
                } else {
                    $row['attrs'] = $e['attrs'];
                }
            }
            if ( null !== $row['id'] && ! GlobalPresets::validId( $row['id'] ) ) {
                $errors[] = "{$where}: \"id\" must be 8 to 64 letters, digits and hyphens (as list_global_styles shows it).";
            }
            if ( null !== $row['module'] && ! GlobalPresets::validModule( $row['module'] ) ) {
                $errors[] = "{$where}: \"module\" must be a Divi 5 module such as divi/heading.";
            }
            if ( null !== $row['name'] ) {
                $clean = GlobalColors::cleanLabel( $row['name'] );
                if ( null === $clean ) {
                    $errors[] = "{$where}: \"name\" must be 1 to " . GlobalColors::MAX_LABEL . ' characters, without angle brackets or control characters.';
                } else {
                    $row['name'] = $clean;
                }
            }
            if ( null === $row['id'] ) {
                if ( null === $row['module'] || null === $row['name'] || null === $row['attrs'] ) {
                    $errors[] = "{$where}: creating a preset needs \"module\", \"name\" and \"attrs\" (or give \"id\" to change an existing one).";
                } elseif ( GlobalPresets::validModule( $row['module'] ) ) {
                    foreach ( GlobalPresets::attrsProblems( $row['module'], $row['attrs'] ) as $problem ) {
                        $errors[] = "{$where}: {$problem}.";
                    }
                }
            } else {
                if ( null !== $row['module'] ) {
                    $errors[] = "{$where}: a preset never moves to another module; leave \"module\" out when you give an \"id\".";
                }
                if ( null === $row['name'] && null === $row['attrs'] ) {
                    $errors[] = "{$where}: changing a preset needs at least one of name, attrs.";
                }
            }
            $out[] = $row;
        }
        if ( [] !== $errors ) {
            throw new \InvalidArgumentException( 'Nothing was changed. ' . implode( ' ', $errors ) );
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $data GlobalPresets::read()
     * @param list<array{id:?string,module:?string,name:?string,attrs:?array<mixed>}> $rows normalize() output
     * @param \Closure():string $newId makes the id of a created preset
     * @return array{changes:list<array<string,mixed>>, unchanged:list<string>}
     */
    public static function plan( array $data, array $rows, bool $allowDefault, int $now, \Closure $newId ): array
    {
        $changes   = [];
        $unchanged = [];
        $errors    = [];
        $seen      = [];
        $names     = [];
        foreach ( $rows as $i => $r ) {
            $where = "presets[{$i}]";
            if ( null === $r['id'] ) {
                $module = (string) $r['module'];
                $key    = $module . '|' . mb_strtolower( (string) $r['name'] );
                if ( isset( $names[ $key ] ) ) {
                    $errors[] = "{$where}: another new {$module} preset in this call is already named \"{$r['name']}\".";
                    continue;
                }
                $names[ $key ] = true;
                $id            = (string) $newId();
                $item          = GlobalPresets::newItem( $module, $id, (string) $r['name'], (array) $r['attrs'], $now );
                $existing      = GlobalPresets::itemIds( $data, $module );
                $changes[]     = [
                    'index' => $i, 'id' => $id, 'module' => $module, 'action' => 'create', 'before' => null, 'after' => GlobalPresets::state( $item ),
                    'item' => $item, 'is_default' => false, 'default_created' => [] === $existing, 'module_existed' => [] !== $existing,
                ];
                continue;
            }
            $id = $r['id'];
            if ( isset( $seen[ $id ] ) ) {
                $errors[] = "{$where}: the id {$id} is already in this call.";
                continue;
            }
            $seen[ $id ] = true;
            $found       = GlobalPresets::find( $data, $id );
            if ( null === $found ) {
                $errors[] = "{$where}: no preset with the id {$id} (list_global_styles shows the ids; to add a preset leave \"id\" out).";
                continue;
            }
            if ( ! GlobalPresets::managed( $found['module'], $found['item'] ) ) {
                $errors[] = "{$where}: {$id} cannot be changed here (it is not a regular Divi 5 module preset).";
                continue;
            }
            $isDefault = GlobalPresets::defaultId( $data, $found['module'] ) === $id;
            if ( $isDefault && ! $allowDefault ) {
                $errors[] = "{$where}: {$id} is the default preset of {$found['module']}; changing it restyles every {$found['module']} that has no preset of its own. Pass allow_default_presets=true to do that on purpose.";
                continue;
            }
            if ( null !== $r['attrs'] ) {
                foreach ( GlobalPresets::attrsProblems( $found['module'], $r['attrs'] ) as $problem ) {
                    $errors[] = "{$where}: {$problem}.";
                }
            }
            $before = GlobalPresets::state( $found['item'] );
            $item   = $found['item'];
            if ( null !== $r['name'] ) {
                $item['name'] = $r['name'];
            }
            if ( null !== $r['attrs'] ) {
                $item['attrs']      = $r['attrs'];
                $item['styleAttrs'] = $r['attrs'];
            }
            $after = GlobalPresets::state( $item );
            if ( Canon::hash( $before ) === Canon::hash( $after ) ) {
                $unchanged[] = $id;
                continue;
            }
            $item['updated'] = $now;
            $changes[]       = [
                'index' => $i, 'id' => $id, 'module' => $found['module'], 'action' => 'update', 'before' => $before, 'after' => $after,
                'item' => $item, 'is_default' => $isDefault, 'default_created' => false, 'module_existed' => true,
            ];
        }
        if ( [] !== $errors ) {
            throw new \InvalidArgumentException( 'Nothing was changed. ' . implode( ' ', $errors ) );
        }

        return [ 'changes' => $changes, 'unchanged' => $unchanged ];
    }
}
```

In `pro-addon/src/autoload.php`, after the `Settings/GlobalPresets.php` line add `require_once __DIR__ . '/Settings/GlobalPresetPlanner.php';`.

- [ ] **Step 4: Run it**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalPresetPlannerTest.php` (all pass), then `make test-pro` (green).
Notes: the error text for an unknown module contains `"module" must be`; the legacy preset's text contains `cannot be changed here`; the default-preset refusal contains `default preset`; adjust only your own message wording if a needle does not match, never the assertion's intent.

- [ ] **Step 5: Commit**

```bash
git add pro-addon/src/Settings/GlobalPresetPlanner.php pro-addon/src/autoload.php pro-addon/tests/GlobalPresetPlannerTest.php
git commit -m "feat(pro): GlobalPresetPlanner (atomic change plan for module presets)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The lock and the undo kind

**Files:**
- Create: `pro-addon/src/Settings/GlobalPresetsLock.php`
- Create: `pro-addon/src/Kinds/GlobalPresetKind.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Batch/Batches.php`, `pro-addon/tests/ToolsTest.php` (kinds list only)
- Test: `pro-addon/tests/GlobalPresetsLockTest.php`, `pro-addon/tests/GlobalPresetKindTest.php`

**Interfaces:**
- Consumes: `SiteLockingKind`, `Item::make`, `Canon::hash`, `BatchLog`, `GlobalPresets::*`.
- Produces:
  - `GlobalPresetsLock` (`PREFIX = 'aied_pro_buildlock_gpresets_'`, `__construct( int $waitMs = 5000, int $expirySeconds = 30 )`, `acquire(): bool`, `release(): void`).
  - `GlobalPresetKind` (`NAME = 'global_preset'`), `__construct( ?GlobalPresetsLock $lock = null )`, and two static item makers used by the tool and by undo:
    - `itemForCreate( string $id, string $module, array $after, bool $moduleExisted, ?array $autoDefault ): array` where `$autoDefault` is `null` or `['id'=>string,'hash'=>string]` (the default preset Divi added);
    - `itemForUpdate( string $id, string $module, array $before, array $after ): array`.
  - Item `before` payload shapes (all arrays): create `['module','created'=>true,'module_existed'=>bool,'auto_default'=>?array]`; update `['module','created'=>false,'state'=>previous state]`; recreate (the inverse of removing a created preset) `['module','created'=>false,'recreate'=>['items'=>[id=>full item,...],'default'=>string,'module_existed'=>bool],'state'=>removed preset state]` with `after_hash` of `null`.
  - restore reasons: `divi_unavailable`, `not_found`, `changed_since`, `unsafe_to_save`, `divi_refused`.

- [ ] **Step 1: Write the lock test**

Create `pro-addon/tests/GlobalPresetsLockTest.php` by copying `pro-addon/tests/GlobalVariablesLockTest.php` and replacing `GlobalVariablesLock` with `GlobalPresetsLock`, the class name with `GlobalPresetsLockTest`, the prefix assertion with `aied_pro_buildlock_gpresets_`, and the independence test with one that proves it is independent of BOTH `GlobalColorsLock` and `GlobalVariablesLock` (take all three; each `acquire()` is true). Keep every other test of that file.

- [ ] **Step 2: Write the kind test**

Create `pro-addon/tests/GlobalPresetKindTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\SiteLockingKind;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\GlobalPresetKind;
use AiEditorDivi5\Pro\Settings\GlobalPresets;
use AiEditorDivi5\Pro\Settings\GlobalPresetsLock;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalPresetsFixture.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';

class GlobalPresetKindTest extends TestCase
{
    private const H1        = GlobalPresetsFixture::H1;
    private const H_DEFAULT = GlobalPresetsFixture::H_DEFAULT;
    private const NEW       = 'aaaaaaaa-0000-4000-8000-000000000001';

    private InMemoryBatchRepository $repo;
    private BatchLog $log;

    protected function setUp(): void
    {
        GlobalPresetsFixture::reset();
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
    }

    protected function tearDown(): void
    {
        GlobalPresetsFixture::clear();
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ GlobalPresetKind::NAME => new GlobalPresetKind( new GlobalPresetsLock( 0 ) ) ], $this->log );
    }

    private function size( string $px ): array
    {
        return [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => $px ] ] ] ] ] ] ];
    }

    /** Create a preset the way the tool does (Divi's additive import) and record the batch. */
    private function create( string $module, string $id, string $name, array $attrs ): int
    {
        $before = GlobalPresets::read();
        $existed = [] !== GlobalPresets::itemIds( $before, $module );
        $item   = GlobalPresets::newItem( $module, $id, $name, $attrs, 1790000000 );
        $res    = GlobalPresets::import( $module, $item, GlobalPresets::defaultId( $before, $module ) );
        $auto   = null;
        foreach ( array_diff( GlobalPresets::itemIds( $res['after'], $module ), GlobalPresets::itemIds( $before, $module ), [ $id ] ) as $extra ) {
            $auto = [ 'id' => $extra, 'hash' => Canon::hash( GlobalPresets::state( $res['after']['module'][ $module ]['items'][ $extra ] ) ) ];
        }
        $after = GlobalPresets::state( $res['after']['module'][ $module ]['items'][ $id ] );
        $batch = $this->log->record( 'manage_global_presets', 'test', [ GlobalPresetKind::itemForCreate( $id, $module, $after, $existed, $auto ) ] );

        return (int) $batch['id'];
    }

    /** Update a preset the way the tool does (complete-data save) and record the batch. */
    private function update( string $id, array $attrs, ?string $name = null ): int
    {
        $data  = GlobalPresets::read();
        $found = GlobalPresets::find( $data, $id );
        $item  = $found['item'];
        $item['attrs'] = $item['styleAttrs'] = $attrs;
        if ( null !== $name ) {
            $item['name'] = $name;
        }
        $item['updated'] = 1790000500;
        $data['module'][ $found['module'] ]['items'][ $id ] = $item;
        $res   = GlobalPresets::save( $data );
        $batch = $this->log->record( 'manage_global_presets', 'test', [ GlobalPresetKind::itemForUpdate( $id, $found['module'], (array) GlobalPresets::state( $found['item'] ), (array) GlobalPresets::state( $res['after']['module'][ $found['module'] ]['items'][ $id ] ) ) ] );

        return (int) $batch['id'];
    }

    public function testItIsASiteLockingKindWithAName(): void
    {
        $this->assertInstanceOf( SiteLockingKind::class, new GlobalPresetKind() );
        $this->assertSame( 'global_preset', ( new GlobalPresetKind() )->name() );
    }

    public function testCurrentIsTheStateOrNullAndAllowedNeedsTheCapability(): void
    {
        $kind = new GlobalPresetKind();
        $this->assertSame( 'Heading 1', $kind->current( [ 'ref' => self::H1 ] )['name'] );
        $this->assertNull( $kind->current( [ 'ref' => 'nope-nope-nope' ] ) );
        $this->assertTrue( $kind->allowed( [ 'ref' => self::H1 ] ) );
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $this->assertFalse( $kind->allowed( [ 'ref' => self::H1 ] ) );
        GlobalPresetsFixture::reset();
        $GLOBALS['__divi_gp']['throw_get'] = true;
        $this->expectException( \RuntimeException::class );
        $kind->current( [ 'ref' => self::H1 ] );
    }

    public function testUndoOfAnUpdatePutsNameAndAttrsBackAndNothingElseChanges(): void
    {
        $start = GlobalPresets::read();
        $id    = $this->update( self::H1, $this->size( '61px' ), 'Heading 1 v2' );
        $r     = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $now = GlobalPresets::read();
        $this->assertSame( $start['module']['divi/heading']['items'][ self::H1 ]['attrs'], $now['module']['divi/heading']['items'][ self::H1 ]['attrs'] );
        $this->assertSame( $start['module']['divi/heading']['items'][ self::H1 ]['styleAttrs'], $now['module']['divi/heading']['items'][ self::H1 ]['styleAttrs'] );
        $this->assertSame( 'Heading 1', $now['module']['divi/heading']['items'][ self::H1 ]['name'] );
        $restored = $now['module']['divi/heading']['items'][ self::H1 ];
        unset( $restored['updated'] );
        $original = $start['module']['divi/heading']['items'][ self::H1 ];
        unset( $original['updated'] );
        $this->assertSame( $original, $restored, 'every other key of the preset is back as it was' );
        unset( $now['module']['divi/heading']['items'][ self::H1 ]['updated'], $start['module']['divi/heading']['items'][ self::H1 ]['updated'] );
        $this->assertSame( $start, $now, 'every other preset, the legacy ones included, is untouched' );
    }

    public function testAnUpdateChangedSinceIsNotOverwrittenUnlessForced(): void
    {
        $id = $this->update( self::H1, $this->size( '61px' ) );
        $this->update( self::H1, $this->size( '99px' ) );
        $r = $this->undo()->undo( $id );
        $this->assertSame( 'changed_since', $r['items'][0]['result'] );
        $this->assertSame( '99px', GlobalPresets::read()['module']['divi/heading']['items'][ self::H1 ]['attrs']['title']['decoration']['font']['font']['desktop']['value']['size'] );
        $r = $this->undo()->undo( $id, true );
        $this->assertSame( 'restored', $r['items'][0]['result'] );
        $this->assertSame( '72px', GlobalPresets::read()['module']['divi/heading']['items'][ self::H1 ]['attrs']['title']['decoration']['font']['font']['desktop']['value']['size'] );
    }

    public function testUndoOfACreateInAModuleThatHadPresetsRemovesOnlyThatPreset(): void
    {
        $start = GlobalPresets::read();
        $id    = $this->create( 'divi/heading', self::NEW, 'Big', $this->size( '57px' ) );
        $this->assertArrayHasKey( self::NEW, GlobalPresets::read()['module']['divi/heading']['items'] );
        $r = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( $start, GlobalPresets::read() );
    }

    public function testUndoOfACreateInAModuleWithNoPresetsAlsoRemovesTheDefaultPresetDiviAddedAndTheModuleEntry(): void
    {
        $start = GlobalPresets::read();
        $id    = $this->create( 'divi/image', self::NEW, 'Rounded', [ 'module' => [ 'x' => '1' ] ] );
        $this->assertCount( 2, GlobalPresets::read()['module']['divi/image']['items'], 'Divi added a default preset' );
        $r = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( $start, GlobalPresets::read(), 'the module entry is gone again' );
    }

    public function testUndoOfThatUndoBringsTheCreatedPresetAndTheDefaultBackWhereTheyWere(): void
    {
        $id    = $this->create( 'divi/image', self::NEW, 'Rounded', [ 'module' => [ 'x' => '1' ] ] );
        $built = GlobalPresets::read();
        $r     = $this->undo()->undo( $id );
        $again = $this->undo()->undo( (int) $r['undo_batch']['id'] );
        $this->assertTrue( $again['fully_undone'] );
        $this->assertSame( $built, GlobalPresets::read(), 'identical, including the auto-created default preset and its id' );
        $third = $this->undo()->undo( (int) $again['undo_batch']['id'] );
        $this->assertTrue( $third['fully_undone'], 'and it can be removed once more' );
        $this->assertArrayNotHasKey( 'divi/image', GlobalPresets::read()['module'] );
    }

    public function testUndoOfACreateRefusesWhenThePresetChangedSinceEvenWhenForced(): void
    {
        $id = $this->create( 'divi/heading', self::NEW, 'Big', $this->size( '57px' ) );
        $this->update( self::NEW, $this->size( '58px' ) );
        foreach ( [ false, true ] as $force ) {
            $r = $this->undo()->undo( $id, $force );
            $this->assertFalse( $r['fully_undone'] );
            $this->assertArrayHasKey( self::NEW, GlobalPresets::read()['module']['divi/heading']['items'], 'a preset someone changed after our write is never removed' );
        }
    }

    public function testUndoOfACreateRefusesWhenSomeoneAddedAPresetToTheNewModuleMeanwhile(): void
    {
        $id   = $this->create( 'divi/image', self::NEW, 'Rounded', [ 'module' => [ 'x' => '1' ] ] );
        $data = GlobalPresets::read();
        $data['module']['divi/image']['items']['bbbbbbbb-0000-4000-8000-000000000009'] = GlobalPresets::newItem( 'divi/image', 'bbbbbbbb-0000-4000-8000-000000000009', 'Theirs', [ 'a' => '1' ], 1790000100 );
        GlobalPresets::save( $data );
        $r = $this->undo()->undo( $id );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'changed_since', $r['items'][0]['reason'] ?? $r['items'][0]['result'] );
        $this->assertArrayHasKey( self::NEW, GlobalPresets::read()['module']['divi/image']['items'] );
    }

    public function testWithoutThePermissionNothingIsRestored(): void
    {
        $id                   = $this->update( self::H1, $this->size( '61px' ) );
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $this->assertSame( 'forbidden', $this->undo()->undo( $id )['items'][0]['result'] );
    }

    public function testAnUndoHoldsTheSiteLockFromBeforeItsFirstReadAndReleasesIt(): void
    {
        $id   = $this->update( self::H1, $this->size( '61px' ) );
        $seen = [];
        $GLOBALS['__divi_gp']['gets']   = 0;
        $GLOBALS['__divi_gp']['on_get'] = static function ( int $n ) use ( &$seen ): void {
            $seen[ $n ] = count( $GLOBALS['__wp_options'] );
        };
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'] );
        $this->assertSame( [ 1 ], array_values( array_unique( $seen ) ) );
        $this->assertSame( [], $GLOBALS['__wp_options'] );
    }

    public function testAnUndoWhileAnotherPresetChangeIsInProgressIsBusyAndWritesNothing(): void
    {
        $id    = $this->update( self::H1, $this->size( '61px' ) );
        $other = new GlobalPresetsLock( 0 );
        $this->assertTrue( $other->acquire() );
        $saves = count( $GLOBALS['__divi_gp']['save_calls'] );
        $r     = $this->undo()->undo( $id );
        $this->assertSame( 'busy', $r['items'][0]['reason'] );
        $this->assertCount( $saves, $GLOBALS['__divi_gp']['save_calls'] );
        $other->release();
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'] );
    }

    public function testADiviThatKeepsADifferentStateThanRequestedIsReportedAsRefused(): void
    {
        $id = $this->update( self::H1, $this->size( '61px' ) );
        $GLOBALS['__divi_gp']['mutate'] = static function ( array $data ): array {
            $data['module']['divi/heading']['items'][ self::H1 ]['name'] = 'something else';

            return $data;
        };
        $this->assertSame( 'divi_refused', $this->undo()->undo( $id )['items'][0]['reason'] );
    }

    public function testAStoredShapeThatCannotBeReproducedMakesTheUndoRefuseWithoutWriting(): void
    {
        $id = $this->update( self::H1, $this->size( '61px' ) );
        $GLOBALS['__divi_gp']['data']['mystery'] = [ 'x' => 1 ];
        $saves = count( $GLOBALS['__divi_gp']['save_calls'] );
        $r     = $this->undo()->undo( $id );
        $this->assertSame( 'unsafe_to_save', $r['items'][0]['reason'] );
        $this->assertCount( $saves, $GLOBALS['__divi_gp']['save_calls'] );
    }
}
```

- [ ] **Step 3: Run them to see them fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalPresetsLockTest.php pro-addon/tests/GlobalPresetKindTest.php`
Expected: errors (classes not found).

- [ ] **Step 4: Implement the lock**

Create `pro-addon/src/Settings/GlobalPresetsLock.php` as a copy of `pro-addon/src/Settings/GlobalVariablesLock.php` with the class renamed `GlobalPresetsLock`, `PREFIX = 'aied_pro_buildlock_gpresets_'` and the docblock saying presets (the whole preset data is replaced by a save; presets live in a different option from colors and variables).

- [ ] **Step 5: Implement the kind**

Create `pro-addon/src/Kinds/GlobalPresetKind.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\SiteLockingKind;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Settings\GlobalPresets;
use AiEditorDivi5\Pro\Settings\GlobalPresetsLock;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One Divi module preset. A CREATE item remembers whether the module already had presets and the default preset Divi added for it
 * (if any); undo removes what the batch created, only while it is exactly as the batch left it. An UPDATE item remembers the
 * previous name and attrs. The inverse of removing a created preset (a "recreate" item) carries the removed items so that undoing
 * the undo puts them back where they were. restore() runs under the site lock BatchUndo holds (see SiteLockingKind).
 */
final class GlobalPresetKind implements SiteLockingKind
{
    public const NAME = 'global_preset';

    private GlobalPresetsLock $lock;

    public function __construct( ?GlobalPresetsLock $lock = null )
    {
        $this->lock = $lock ?? new GlobalPresetsLock();
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string,mixed> $after GlobalPresets::state() of the created preset as stored
     * @param array{id:string,hash:string}|null $autoDefault the default preset Divi added for the module, if it did
     * @return array<string,mixed>
     */
    public static function itemForCreate( string $id, string $module, array $after, bool $moduleExisted, ?array $autoDefault ): array
    {
        $label  = 'Global preset "' . ( $after['name'] ?? '' ) . "\" ({$module}, {$id})";
        $before = [ 'module' => $module, 'created' => true, 'module_existed' => $moduleExisted, 'auto_default' => $autoDefault ];

        return Item::make( self::NAME, $id, $label, $before, $after );
    }

    /**
     * @param array<string,mixed> $before GlobalPresets::state() before
     * @param array<string,mixed> $after GlobalPresets::state() as stored now
     * @return array<string,mixed>
     */
    public static function itemForUpdate( string $id, string $module, array $before, array $after ): array
    {
        $label = 'Global preset "' . ( $after['name'] ?? '' ) . "\" ({$module}, {$id})";

        return Item::make( self::NAME, $id, $label, [ 'module' => $module, 'created' => false, 'state' => $before ], $after );
    }

    public function current( array $item ): mixed
    {
        $data = GlobalPresets::read();
        if ( null === $data ) {
            throw new \RuntimeException( "Divi's presets cannot be read." );
        }
        $found = GlobalPresets::find( $data, (string) $item['ref'] );

        return GlobalPresets::state( $found['item'] ?? null );
    }

    public function allowed( array $item ): bool
    {
        return GlobalPresets::permitted() && GlobalPresets::available();
    }

    public function lockSite(): bool
    {
        return $this->lock->acquire();
    }

    public function unlockSite(): void
    {
        $this->lock->release();
    }

    public function restore( array $item ): array
    {
        $id     = (string) $item['ref'];
        $before = is_array( $item['before'] ?? null ) ? $item['before'] : [];
        $module = (string) ( $before['module'] ?? '' );
        $data   = GlobalPresets::read();
        if ( null === $data ) {
            return [ 'ok' => false, 'reason' => 'divi_unavailable' ];
        }
        if ( ! empty( $before['created'] ) ) {
            return $this->removeCreated( $data, $id, $module, $before, (string) ( $item['after_hash'] ?? '' ) );
        }
        if ( isset( $before['recreate'] ) && is_array( $before['recreate'] ) ) {
            return $this->recreate( $data, $id, $module, $before );
        }

        return $this->revert( $data, $id, $module, $before );
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $before @return array<string,mixed> */
    private function removeCreated( array $data, string $id, string $module, array $before, string $afterHash ): array
    {
        $found = GlobalPresets::find( $data, $id );
        if ( null === $found || $found['module'] !== $module || ! GlobalPresets::managed( $module, $found['item'] ) ) {
            return [ 'ok' => false, 'reason' => 'not_found' ];
        }
        $state = GlobalPresets::state( $found['item'] );
        if ( Canon::hash( $state ) !== $afterHash || GlobalPresets::defaultId( $data, $module ) === $id ) {
            return [ 'ok' => false, 'reason' => 'changed_since' ]; // even when forced: never remove a preset someone changed after us
        }
        $removed = [ $id => $found['item'] ];
        $auto    = is_array( $before['auto_default'] ?? null ) ? $before['auto_default'] : null;
        if ( null !== $auto ) {
            $autoItem = $data['module'][ $module ]['items'][ $auto['id'] ] ?? null;
            if ( is_array( $autoItem ) ) {
                if ( Canon::hash( GlobalPresets::state( $autoItem ) ) !== (string) $auto['hash'] ) {
                    return [ 'ok' => false, 'reason' => 'changed_since' ];
                }
                $removed[ (string) $auto['id'] ] = $autoItem;
            }
        }
        $default       = GlobalPresets::defaultId( $data, $module );
        $moduleExisted = ! empty( $before['module_existed'] );
        foreach ( array_keys( $removed ) as $rid ) {
            unset( $data['module'][ $module ]['items'][ $rid ] );
        }
        if ( ! $moduleExisted ) {
            if ( [] !== $data['module'][ $module ]['items'] ) {
                return [ 'ok' => false, 'reason' => 'changed_since' ]; // someone added a preset to the module since
            }
            unset( $data['module'][ $module ] );
        }
        $res = $this->write( $data );
        if ( null === $res['after'] || null !== GlobalPresets::find( $res['after'], $id ) ) {
            return [ 'ok' => false, 'reason' => $res['reason'] ?? 'divi_refused' ];
        }
        $recreate = [ 'items' => $removed, 'default' => $moduleExisted ? '' : $default, 'module_existed' => $moduleExisted ];
        $inverse  = Item::make(
            self::NAME,
            $id,
            'Global preset "' . ( $state['name'] ?? '' ) . "\" ({$module}, {$id})",
            [ 'module' => $module, 'created' => false, 'recreate' => $recreate, 'state' => $state ],
            null
        );

        return [ 'ok' => true, 'inverse' => $inverse ];
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $before @return array<string,mixed> */
    private function recreate( array $data, string $id, string $module, array $before ): array
    {
        if ( null !== GlobalPresets::find( $data, $id ) ) {
            return [ 'ok' => false, 'reason' => 'changed_since' ];
        }
        $recreate      = $before['recreate'];
        $moduleExisted = ! empty( $recreate['module_existed'] );
        $items         = is_array( $recreate['items'] ?? null ) ? $recreate['items'] : [];
        if ( $moduleExisted && ! isset( $data['module'][ $module ]['items'] ) ) {
            return [ 'ok' => false, 'reason' => 'not_found' ];
        }
        if ( ! $moduleExisted ) {
            if ( isset( $data['module'][ $module ] ) ) {
                return [ 'ok' => false, 'reason' => 'changed_since' ];
            }
            $data['module'][ $module ] = [ 'default' => (string) ( $recreate['default'] ?? '' ), 'items' => [] ];
        }
        foreach ( $items as $rid => $rItem ) {
            $data['module'][ $module ]['items'][ (string) $rid ] = $rItem;
        }
        $res    = $this->write( $data );
        $stored = null === $res['after'] ? null : GlobalPresets::find( $res['after'], $id );
        if ( null === $stored || Canon::hash( GlobalPresets::state( $stored['item'] ) ) !== Canon::hash( $before['state'] ?? null ) ) {
            return [ 'ok' => false, 'reason' => $res['reason'] ?? 'divi_refused' ];
        }
        $auto = null;
        foreach ( $items as $rid => $rItem ) {
            if ( (string) $rid !== $id ) {
                $auto = [ 'id' => (string) $rid, 'hash' => Canon::hash( GlobalPresets::state( $rItem ) ) ];
            }
        }

        return [ 'ok' => true, 'inverse' => self::itemForCreate( $id, $module, (array) GlobalPresets::state( $stored['item'] ), $moduleExisted, $auto ) ];
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $before @return array<string,mixed> */
    private function revert( array $data, string $id, string $module, array $before ): array
    {
        $found = GlobalPresets::find( $data, $id );
        if ( null === $found || $found['module'] !== $module || ! GlobalPresets::managed( $module, $found['item'] ) || ! is_array( $before['state'] ?? null ) ) {
            return [ 'ok' => false, 'reason' => 'not_found' ];
        }
        $previous = $before['state'];
        $nowState = GlobalPresets::state( $found['item'] );
        $entry    = $found['item'];
        $entry['name']       = (string) $previous['name'];
        $entry['attrs']      = $previous['attrs'];
        $entry['styleAttrs'] = $previous['attrs'];
        $entry['updated']    = time();
        $data['module'][ $module ]['items'][ $id ] = $entry;
        $res    = $this->write( $data );
        $stored = null === $res['after'] ? null : GlobalPresets::state( GlobalPresets::find( $res['after'], $id )['item'] ?? null );
        if ( $stored !== $previous ) {
            return [ 'ok' => false, 'reason' => $res['reason'] ?? 'divi_refused' ];
        }

        return [ 'ok' => true, 'inverse' => self::itemForUpdate( $id, $module, (array) $nowState, (array) $stored ) ];
    }

    /**
     * Pre-flight, then Divi's replace-all save.
     *
     * @param array<string,mixed> $data
     * @return array{after:?array<string,mixed>, reason?:string}
     */
    private function write( array $data ): array
    {
        if ( [] !== GlobalPresets::unsafe( $data ) ) {
            return [ 'after' => null, 'reason' => 'unsafe_to_save' ];
        }
        $res = GlobalPresets::save( $data );

        return [ 'after' => $res['after'] ];
    }
}
```

In `pro-addon/src/autoload.php`, after the `Settings/GlobalPresetPlanner.php` line add `require_once __DIR__ . '/Settings/GlobalPresetsLock.php';` and `require_once __DIR__ . '/Kinds/GlobalPresetKind.php';`. In `pro-addon/src/Batch/Batches.php` add `use AiEditorDivi5\Pro\Kinds\GlobalPresetKind;` and `new GlobalPresetKind(),` after `new GlobalVariableKind(),`. In `pro-addon/tests/ToolsTest.php`, `testEveryKindARecordedItemUsesIsRegisteredForUndo`: append `'global_preset'` to the expected list.

- [ ] **Step 6: Run the tests**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/GlobalPresetsLockTest.php pro-addon/tests/GlobalPresetKindTest.php pro-addon/tests/ToolsTest.php`
Expected: all pass. A failing undo-of-undo test most likely points at the inverse items' key sets; fix the code, never loosen the byte-identical assertions (the shim creates a fresh uuid for the auto default, so identical means the recreate used the RECORDED removed items).
Then `make test-pro` (green).

- [ ] **Step 7: Commit**

```bash
git add pro-addon/src/Settings/GlobalPresetsLock.php pro-addon/src/Kinds/GlobalPresetKind.php pro-addon/src/autoload.php pro-addon/src/Batch/Batches.php pro-addon/tests/GlobalPresetsLockTest.php pro-addon/tests/GlobalPresetKindTest.php pro-addon/tests/ToolsTest.php
git commit -m "feat(pro): GlobalPresetsLock and the global_preset undo kind

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: The tool, registration and parity

**Files:**
- Create: `pro-addon/src/Tools/ManageGlobalPresets.php`
- Modify: `pro-addon/src/autoload.php`, `pro-addon/src/Tools.php`, `pro-addon/src/Rest/ProOpenApi.php`
- Modify tests: `pro-addon/tests/ToolsTest.php`, `pro-addon/tests/ProOpenApiTest.php`, `pro-addon/tests/ExpiryKeepsAllToolsTest.php` (count and message), and any other test that fails only because there are now 17 tools (update the expected number, never loosen a budget assertion)
- Test: `pro-addon/tests/ManageGlobalPresetsTest.php`

**Interfaces:**
- Consumes: Tasks 1 to 3, `Args::{listOf,bool,string}`, `PreviewToken::{make,open,spend,TTL}`, `BatchLog::{record,attach}`, `Canon::hash`, `ProTool`.
- Produces: `ManageGlobalPresets` (`NAME = 'manage_global_presets'`), `__construct( BatchLog $log, ?\Closure $clock = null, ?\Closure $newId = null, ?GlobalPresetsLock $lock = null )`; `ProOpenApi::MCP_REST_ONLY = [ 'manage_global_colors', 'manage_global_variables', 'manage_global_presets' ]`.
- Result shapes. Dry run: `changed:false, dry_run:true, changes:[{index,id|null,module,action,before,after,is_default,default_preset_created}], preview_token, expires_in, message`. Real run: `changed:true, dry_run:false, changes:[{id,module,action,before,after,default_preset_created}], skipped, note, batch` plus `differs_from_request`, `not_stored`, `unexpected_changes`, `warning` when they apply. No-op: `changed:false, unchanged:[ids]`.

- [ ] **Step 1: Write the failing tool test**

Create `pro-addon/tests/ManageGlobalPresetsTest.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\GlobalPresetKind;
use AiEditorDivi5\Pro\Settings\GlobalPresets;
use AiEditorDivi5\Pro\Settings\GlobalPresetsLock;
use AiEditorDivi5\Pro\Tools\ManageGlobalPresets;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalPresetsFixture.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';

class ManageGlobalPresetsTest extends TestCase
{
    private const H1        = GlobalPresetsFixture::H1;
    private const H_DEFAULT = GlobalPresetsFixture::H_DEFAULT;

    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private ManageGlobalPresets $tool;
    private int $now = 1790000000;
    private int $seq = 0;

    protected function setUp(): void
    {
        GlobalPresetsFixture::reset();
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->seq  = 0;
        $this->tool = new ManageGlobalPresets( $this->log, fn (): int => $this->now, function (): string {
            return 'aaaaaaaa-0000-4000-8000-00000000000' . ++$this->seq;
        }, new GlobalPresetsLock( 0 ) );
    }

    protected function tearDown(): void
    {
        GlobalPresetsFixture::clear();
    }

    private function size( string $px ): array
    {
        return [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => $px ] ] ] ] ] ] ];
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function apply( array $args ): array
    {
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertArrayHasKey( 'preview_token', $p );

        return $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
    }

    private function snapshot(): array
    {
        return [ $GLOBALS['__divi_gp']['data'], count( $GLOBALS['__divi_gp']['save_calls'] ), count( $GLOBALS['__divi_gp']['import_calls'] ), count( $this->repo->rows ), $GLOBALS['__wp_options'] ];
    }

    private function refused( array $args, string $needle ): void
    {
        $before = $this->snapshot();
        try {
            $this->tool->run( $args );
            $this->fail( 'expected a refusal containing: ' . $needle );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $needle, $e->getMessage() );
        }
        $this->assertSame( $before, $this->snapshot(), 'a refusal changes nothing' );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ GlobalPresetKind::NAME => new GlobalPresetKind( new GlobalPresetsLock( 0 ) ) ], $this->log );
    }

    public function testTheDefinitionIsValidForMcpAndRest(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'manage_global_presets', $d['name'] );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'presets' ], $d['inputSchema']['required'] );
        foreach ( [ 'presets', 'dry_run', 'preview_token', 'allow_default_presets' ] as $p ) {
            $this->assertArrayHasKey( $p, $d['inputSchema']['properties'] );
        }
        $this->assertSame( 20, $d['inputSchema']['properties']['presets']['maxItems'] );
        foreach ( [ 'id', 'module', 'name', 'attrs' ] as $k ) {
            $this->assertArrayHasKey( $k, $d['inputSchema']['properties']['presets']['items']['properties'] );
        }
    }

    public function testADryRunReportsTheChangesAndATokenAndWritesNothing(): void
    {
        $r = $this->tool->run( [ 'presets' => [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->size( '57px' ) ], [ 'id' => self::H1, 'attrs' => $this->size( '61px' ) ] ], 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 1800, $r['expires_in'] );
        $this->assertSame( [ 'create', 'update' ], array_column( $r['changes'], 'action' ) );
        $this->assertNull( $r['changes'][0]['id'] );
        $this->assertSame( self::H1, $r['changes'][1]['id'] );
        $this->assertSame( '72px', $r['changes'][1]['before']['attrs']['title']['decoration']['font']['font']['desktop']['value']['size'] );
        $this->assertSame( [], $GLOBALS['__divi_gp']['save_calls'] );
        $this->assertSame( [], $GLOBALS['__divi_gp']['import_calls'] );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'a preview takes no lock' );
    }

    public function testARealCreateGoesThroughTheAdditiveImportAndRecordsAnUndoableBatch(): void
    {
        $start = GlobalPresets::read();
        $r     = $this->apply( [ 'presets' => [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->size( '57px' ) ] ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 'create', $r['changes'][0]['action'] );
        $this->assertTrue( $r['batch']['undoable'] );
        $id    = $r['changes'][0]['id'];
        $after = GlobalPresets::read();
        $this->assertSame( 'Big', $after['module']['divi/heading']['items'][ $id ]['name'] );
        $this->assertSame( $this->size( '57px' ), $after['module']['divi/heading']['items'][ $id ]['styleAttrs'] );
        $this->assertSame( self::H_DEFAULT, $after['module']['divi/heading']['default'], 'a create never changes the default' );
        $this->assertCount( 1, $GLOBALS['__divi_gp']['import_calls'] );
        $this->assertSame( [], $GLOBALS['__divi_gp']['save_calls'], 'an additive import, no replace-all save' );
        $untouched = $after;
        unset( $untouched['module']['divi/heading']['items'][ $id ] );
        $this->assertSame( $start, $untouched, 'every other preset, the legacy ones included, is byte-identical' );
        $this->assertStringContainsString( 'modulePreset', $r['note'] );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'the lock is released' );
    }

    public function testACreateForAModuleWithNoPresetsReportsAndUndoesTheDefaultPresetDiviAdds(): void
    {
        $start = GlobalPresets::read();
        $dry   = $this->tool->run( [ 'presets' => [ [ 'module' => 'divi/image', 'name' => 'Rounded', 'attrs' => [ 'module' => [ 'x' => '1' ] ] ] ], 'dry_run' => true ] );
        $this->assertTrue( $dry['changes'][0]['default_preset_created'] );
        $this->assertStringContainsString( 'default preset', $dry['message'] );
        $r = $this->tool->run( [ 'presets' => [ [ 'module' => 'divi/image', 'name' => 'Rounded', 'attrs' => [ 'module' => [ 'x' => '1' ] ] ] ], 'preview_token' => $dry['preview_token'] ] );
        $this->assertTrue( $r['changes'][0]['default_preset_created'] );
        $this->assertCount( 2, GlobalPresets::read()['module']['divi/image']['items'] );
        $this->assertTrue( $this->undo()->undo( (int) $r['batch']['id'] )['fully_undone'] );
        $this->assertSame( $start, GlobalPresets::read() );
    }

    public function testAnUpdateReplacesTheTreeWithOneCompleteSaveAndKeepsEverythingElse(): void
    {
        $start = GlobalPresets::read();
        $r     = $this->apply( [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->size( '61px' ), 'name' => 'Heading 1 v2' ] ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertCount( 1, $GLOBALS['__divi_gp']['save_calls'] );
        $this->assertSame( [], $GLOBALS['__divi_gp']['import_calls'] );
        $now = GlobalPresets::read();
        $this->assertSame( 'Heading 1 v2', $now['module']['divi/heading']['items'][ self::H1 ]['name'] );
        $this->assertSame( 1790000000, $now['module']['divi/heading']['items'][ self::H1 ]['updated'] );
        $others = $now;
        unset( $others['module']['divi/heading']['items'][ self::H1 ] );
        $expected = $start;
        unset( $expected['module']['divi/heading']['items'][ self::H1 ] );
        $this->assertSame( $expected, $others );
    }

    public function testAMixedCallAppliesUpdatesAndCreatesAndOneUndoPutsEverythingBack(): void
    {
        $start = GlobalPresets::read();
        $r     = $this->apply( [ 'presets' => [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->size( '57px' ) ], [ 'id' => self::H1, 'attrs' => $this->size( '61px' ) ] ] ] );
        $this->assertSame( 2, count( $r['changes'] ) );
        $u = $this->undo()->undo( (int) $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $back = GlobalPresets::read();
        unset( $back['module']['divi/heading']['items'][ self::H1 ]['updated'], $start['module']['divi/heading']['items'][ self::H1 ]['updated'] );
        $this->assertSame( $start, $back );
    }

    public function testUpdatingTheDefaultPresetNeedsTheFlagAndTheFlagIsPartOfTheToken(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H_DEFAULT, 'attrs' => $this->size( '31px' ) ] ] ];
        $this->refused( $args + [ 'dry_run' => true ], 'default preset' );
        $p = $this->tool->run( $args + [ 'dry_run' => true, 'allow_default_presets' => true ] );
        $this->assertTrue( $p['changes'][0]['is_default'] );
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'default preset' );
    }

    public function testARealRunNeedsTheTokenAndItWorksOnce(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->size( '61px' ) ] ] ];
        $this->refused( $args, 'preview_token' );
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'] );
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
    }

    public function testATokenForOtherArgumentsAnotherUserOrAnExpiredOneIsRefused(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->size( '61px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->refused( [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->size( '62px' ) ] ], 'preview_token' => $p['preview_token'] ], 'preview_token' );
        $GLOBALS['__wp_current_user_id'] = 8;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
        $GLOBALS['__wp_current_user_id'] = 7;
        $this->now += 1801;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
    }

    public function testAPresetChangedSinceThePreviewIsSkippedAndReportedNeverOverwritten(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->size( '61px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gp']['data']['module']['divi/heading']['items'][ self::H1 ]['name'] = 'Renamed by someone';
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 'changed_since_preview', $r['skipped'][0]['reason'] );
        $this->assertSame( [], $GLOBALS['__divi_gp']['save_calls'] );
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function refusals(): array
    {
        $ok   = [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '57px' ] ] ] ] ] ] ];
        $many = array_fill( 0, 21, [ 'module' => 'divi/heading', 'name' => 'x', 'attrs' => $ok ] );

        return [
            'no presets'            => [ [], 'presets' ],
            'an empty list'         => [ [ 'presets' => [] ], 'presets' ],
            'more than 20'          => [ [ 'presets' => $many ], 'presets' ],
            'dry_run not a bool'    => [ [ 'presets' => [ [ 'module' => 'divi/heading', 'name' => 'x', 'attrs' => $ok ] ], 'dry_run' => 'yes' ], 'dry_run' ],
            'an unknown id'         => [ [ 'presets' => [ [ 'id' => 'nope-nope-nope', 'name' => 'x' ] ], 'dry_run' => true ], 'no preset with the id' ],
            'a legacy preset'       => [ [ 'presets' => [ [ 'id' => GlobalPresetsFixture::LEGACY, 'name' => 'x' ] ], 'dry_run' => true ], 'cannot be changed here' ],
            'custom css on create'  => [ [ 'presets' => [ [ 'module' => 'divi/heading', 'name' => 'x', 'attrs' => [ 'module' => [ 'advanced' => [ 'css' => [ 'desktop' => [ 'value' => [ 'freeForm' => '.x{color:red}' ] ] ] ] ] ] ] ], 'dry_run' => true ], 'CUSTOM_CSS_NOT_ALLOWED' ],
            'custom css on update'  => [ [ 'presets' => [ [ 'id' => GlobalPresetsFixture::H1, 'attrs' => [ 'module' => [ 'advanced' => [ 'css' => [ 'desktop' => [ 'value' => [ 'mainElement' => 'color:red' ] ] ] ] ] ] ] ], 'dry_run' => true ], 'CUSTOM_CSS_NOT_ALLOWED' ],
            'module on an update'   => [ [ 'presets' => [ [ 'id' => GlobalPresetsFixture::H1, 'module' => 'divi/text', 'name' => 'x' ] ], 'dry_run' => true ], 'never moves' ],
        ];
    }

    /** @param array<string,mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'refusals' )]
    public function testRefusalsChangeNothing( array $args, string $needle ): void
    {
        $this->refused( $args, $needle );
    }

    public function testWithoutThePermissionEvenAPreviewIsRefusedUpFront(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $this->refused( [ 'presets' => [ [ 'id' => self::H1, 'name' => 'x' ] ], 'dry_run' => true ], 'edit_theme_options' );
    }

    public function testItFailsClosedWhenDiviCannotBeRead(): void
    {
        $GLOBALS['__divi_gp']['throw_get'] = true;
        $this->refused( [ 'presets' => [ [ 'id' => self::H1, 'name' => 'x' ] ], 'dry_run' => true ], 'not available' );
    }

    public function testAStoredShapeItCannotReproduceIsRefusedBeforeAnyWrite(): void
    {
        $GLOBALS['__divi_gp']['data']['mystery'] = [ 'x' => 1 ];
        $this->refused( [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->size( '61px' ) ] ], 'dry_run' => true ], 'would be lost' );
    }

    public function testASaveThatThrowsAndStoresNothingIsAnErrorNotASuccess(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->size( '61px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gp']['throw_save'] = true;
        try {
            $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
            $this->fail( 'expected a failure' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'did not save', $e->getMessage() );
        }
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'the lock is released after a failure' );
    }

    public function testASaveDiviIgnoredIsReportedNeverClaimed(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->size( '61px' ) ], [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->size( '57px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $keep = GlobalPresetsFixture::data();
        $GLOBALS['__divi_gp']['mutate'] = static fn ( array $data ): array => $keep; // Divi keeps what was there
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [], $this->repo->rows, 'nothing recorded, nothing to undo' );
        $this->assertNotSame( [], $r['not_stored'] ?? [] );
    }

    public function testAPresetDiviDropsWhileSavingIsReportedAsUnexpected(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->size( '61px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gp']['mutate'] = static function ( array $data ): array {
            unset( $data['module']['divi/text']['items'][ GlobalPresetsFixture::T_DEFAULT ] );

            return $data;
        };
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ [ 'id' => GlobalPresetsFixture::T_DEFAULT, 'reason' => 'removed_by_divi' ] ], $r['unexpected_changes'] );
    }

    public function testADryRunTakesNoLockAndARealRunHoldsItFromBeforeItsFirstReadUntilTheEnd(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->size( '61px' ) ] ] ];
        $seen = [];
        $GLOBALS['__divi_gp']['on_get'] = static function ( int $n ) use ( &$seen ): void {
            $seen[ $n ] = count( $GLOBALS['__wp_options'] );
        };
        $GLOBALS['__divi_gp']['gets'] = 0;
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertSame( [ 1 => 0 ], $seen );

        $seen                         = [];
        $GLOBALS['__divi_gp']['gets'] = 0;
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'] );
        $this->assertSame( 1, $seen[1] );
        $this->assertSame( [], $GLOBALS['__wp_options'] );
    }

    public function testARealRunWhileAnotherChangeIsInProgressIsRefusedWithoutSpendingTheToken(): void
    {
        $args  = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->size( '61px' ) ] ] ];
        $other = new GlobalPresetsLock( 0 );
        $this->assertTrue( $other->acquire() );
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'in progress' );
        $other->release();
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'] );
    }

    public function testTheTokenSurvivesARefusalThatHappensAfterItWasOpened(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->size( '61px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gp']['data']['mystery'] = [ 'x' => 1 ];
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'would be lost' );
        unset( $GLOBALS['__divi_gp']['data']['mystery'] );
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'], 'the refusal did not spend the token' );
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/ManageGlobalPresetsTest.php`
Expected: errors (class not found).

- [ ] **Step 3: Implement the tool**

Create `pro-addon/src/Tools/ManageGlobalPresets.php`:

```php
<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tools;

use AiEditorDivi5\Pro\Args;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\GlobalPresetKind;
use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Settings\GlobalPresetPlanner;
use AiEditorDivi5\Pro\Settings\GlobalPresets;
use AiEditorDivi5\Pro\Settings\GlobalPresetsLock;
use AiEditorDivi5\Pro\Site\PreviewToken;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Create or update the site's Divi module presets (never deletes). A preset restyles every module that cites it
 * (`modulePreset: ["<id>"]`), and a module's default preset restyles every module of that type without a preset of its own.
 * Every real run needs the token from a dry run of the same arguments. Creates go through Divi's additive import; updates save
 * Divi's COMPLETE preset data once. The result is read back and THAT is recorded as one undoable batch. Custom code in the
 * attributes is refused. A real run holds the site-wide GlobalPresetsLock from before its first read until the read-back.
 * MCP and REST only: the ChatGPT spec has no room.
 */
final class ManageGlobalPresets implements ProTool
{
    public const NAME = 'manage_global_presets';

    private const UNAVAILABLE = "Divi's preset functions are not available on this site (is the Divi 5 theme active?), so nothing was changed.";

    private \Closure $clock;
    private \Closure $newId;
    private GlobalPresetsLock $lock;

    /**
     * @param (\Closure():int)|null $clock the time in seconds (the tests move it)
     * @param (\Closure():string)|null $newId makes the id of a created preset
     */
    public function __construct( private BatchLog $log, ?\Closure $clock = null, ?\Closure $newId = null, ?GlobalPresetsLock $lock = null )
    {
        $this->lock  = $lock ?? new GlobalPresetsLock();
        $this->clock = $clock ?? static fn (): int => time();
        $this->newId = $newId ?? static fn (): string => wp_generate_uuid4();
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
            'description' => 'Create or update the site\'s Divi module presets (never deletes). Pages use one with modulePreset ["<id>"], so a change restyles every module citing it. Call with dry_run=true first, then repeat with the preview_token. Undoable.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'presets'              => [
                        'type'        => 'array',
                        'minItems'    => 1,
                        'maxItems'    => GlobalPresetPlanner::MAX_ENTRIES,
                        'description' => 'The changes. No id = create a preset (module, name and attrs needed). With an id (from list_global_styles) = change that preset: name and/or attrs (attrs REPLACES its whole style tree).',
                        'items'       => [
                            'type'       => 'object',
                            'properties' => [
                                'id'     => [ 'type' => 'string', 'description' => 'Id of an existing preset; leave out to create one' ],
                                'module' => [ 'type' => 'string', 'description' => 'Needed to create, e.g. divi/heading; never changes afterwards' ],
                                'name'   => [ 'type' => 'string', 'description' => 'Name, 1 to 60 characters' ],
                                'attrs'  => [ 'type' => 'object', 'description' => 'The style attributes, in the same shape a layout block uses (for example title.decoration.font.font.desktop.value.size). No custom CSS or code.' ],
                            ],
                        ],
                    ],
                    'dry_run'              => [ 'type' => 'boolean', 'description' => 'Preview only; returns the preview_token a real run needs' ],
                    'preview_token'        => [ 'type' => 'string', 'description' => 'The token from your dry run of the same arguments; it works once, within 30 minutes' ],
                    'allow_default_presets' => [ 'type' => 'boolean', 'description' => 'Needed to change a module\'s DEFAULT preset: that restyles every module of that type without a preset of its own' ],
                ],
                'required'   => [ 'presets' ],
            ],
        ];
    }

    public function run( array $arguments ): array
    {
        $rows  = GlobalPresetPlanner::normalize( Args::listOf( $arguments, 'presets', GlobalPresetPlanner::MAX_ENTRIES ) );
        $dry   = Args::bool( $arguments, 'dry_run' );
        $token = Args::string( $arguments, 'preview_token', false, 8000 );
        $allow = Args::bool( $arguments, 'allow_default_presets' );

        // Divi's own preset functions check no capability, so this is the bar (even for a preview).
        if ( ! GlobalPresets::permitted() ) {
            throw new \InvalidArgumentException( 'Changing presets needs the edit_theme_options capability, which your user does not have, so nothing was changed.' );
        }
        if ( $dry ) {
            return $this->execute( $rows, true, $token, $allow ); // a preview writes nothing, so it needs no lock
        }
        if ( ! $this->lock->acquire() ) {
            throw new \InvalidArgumentException( 'Another preset change is in progress on this site; nothing was changed. Try again in a moment.' );
        }
        try {
            return $this->execute( $rows, false, $token, $allow );
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function execute( array $rows, bool $dry, ?string $token, bool $allow ): array
    {
        $before = GlobalPresets::read();
        if ( null === $before || ! GlobalPresets::available() ) {
            throw new \InvalidArgumentException( self::UNAVAILABLE );
        }
        self::refuseUnsafe( $before );
        $now  = ( $this->clock )();
        $plan = GlobalPresetPlanner::plan( $before, $rows, $allow, $now, $this->newId );
        if ( [] === $plan['changes'] ) {
            return [ 'changed' => false, 'dry_run' => $dry, 'unchanged' => $plan['unchanged'], 'message' => 'Every preset already is exactly like that; nothing changed.' ];
        }

        $argsHash = Canon::hash( [ 'presets' => $rows, 'allow_default_presets' => $allow ] );
        $userId   = (int) get_current_user_id();
        if ( $dry ) {
            $items   = [];
            $default = false;
            foreach ( $plan['changes'] as $c ) {
                $items[ $c['index'] ] = Canon::hash( $c['before'] );
                $default              = $default || $c['default_created'];
            }

            return [
                'changed'       => false,
                'dry_run'       => true,
                'changes'       => array_map( static fn ( array $c ): array => [ 'index' => $c['index'], 'id' => 'create' === $c['action'] ? null : $c['id'], 'module' => $c['module'], 'action' => $c['action'], 'before' => $c['before'], 'after' => $c['after'], 'is_default' => $c['is_default'], 'default_preset_created' => $c['default_created'] ], $plan['changes'] ),
                'preview_token' => PreviewToken::make( $argsHash, $items, $now, $userId ),
                'expires_in'    => PreviewToken::TTL,
                'message'       => 'Preview only. Nothing was changed. Repeat the call without dry_run and with this preview_token to apply it. Every module that cites a changed preset is restyled.' . ( $default ? ' For a module with no presets yet Divi also adds a default preset, which undo_batch removes again.' : '' ),
            ];
        }

        $map = null === $token ? null : PreviewToken::open( $token, $argsHash, $now, $userId );
        if ( null === $map ) {
            throw new \InvalidArgumentException( 'A real run needs the preview_token from a dry run of the same arguments (including allow_default_presets), made by you in the last 30 minutes and not used before. Call again with dry_run=true.' );
        }

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
            PreviewToken::spend( (string) $token );

            return [ 'changed' => false, 'dry_run' => false, 'skipped' => $skipped, 'message' => 'Every preset changed since the preview, so nothing was written. Call again with dry_run=true.' ];
        }

        PreviewToken::spend( (string) $token ); // everything that can still refuse has run; the first write follows

        $touched = [];  // id => true, presets this call changed or created
        $recorded = [];
        $applied  = [];
        $differs  = [];
        $notStored = [];
        $threw    = false;

        // 1. Updates: ONE replace-all save of the complete current data.
        $updates = array_values( array_filter( $kept, static fn ( array $c ): bool => 'update' === $c['action'] ) );
        if ( [] !== $updates ) {
            $data = $before;
            foreach ( $updates as $c ) {
                $data['module'][ $c['module'] ]['items'][ $c['id'] ] = $c['item'];
            }
            $res   = GlobalPresets::save( $data );
            $threw = $threw || $res['threw'];
            if ( null === $res['after'] ) {
                throw new \RuntimeException( "Divi's presets could not be read back after the write, so nothing is recorded. Check list_global_styles for their current state." );
            }
            foreach ( $updates as $c ) {
                $touched[ $c['id'] ] = true;
                $stored = GlobalPresets::state( GlobalPresets::find( $res['after'], $c['id'] )['item'] ?? null );
                if ( $stored === $c['before'] ) {
                    $notStored[] = [ 'index' => $c['index'], 'id' => $c['id'] ];
                    continue;
                }
                if ( $stored !== $c['after'] ) {
                    $differs[] = [ 'id' => $c['id'], 'requested' => $c['after'], 'stored' => $stored ];
                }
                $recorded[] = GlobalPresetKind::itemForUpdate( $c['id'], $c['module'], (array) $c['before'], (array) $stored );
                $applied[]  = [ 'id' => $c['id'], 'module' => $c['module'], 'action' => 'update', 'before' => $c['before'], 'after' => $stored, 'default_preset_created' => false ];
            }
        }

        // 2. Creates: Divi's additive import, one preset at a time.
        foreach ( $kept as $c ) {
            if ( 'create' !== $c['action'] ) {
                continue;
            }
            $current = GlobalPresets::read();
            if ( null === $current ) {
                throw new \RuntimeException( "Divi's presets could not be read, so the remaining creates were not attempted. Check list_global_styles for their current state." );
            }
            $idsBefore = GlobalPresets::itemIds( $current, $c['module'] );
            $res       = GlobalPresets::import( $c['module'], $c['item'], GlobalPresets::defaultId( $current, $c['module'] ) );
            $threw     = $threw || $res['threw'];
            if ( null === $res['after'] ) {
                throw new \RuntimeException( "Divi's presets could not be read back after the write, so nothing further is recorded. Check list_global_styles for their current state." );
            }
            $newIds   = array_values( array_diff( GlobalPresets::itemIds( $res['after'], $c['module'] ), $idsBefore ) );
            $created  = null;
            $auto     = null;
            foreach ( $newIds as $nid ) {
                $nItem = $res['after']['module'][ $c['module'] ]['items'][ $nid ] ?? null;
                $nSt   = GlobalPresets::state( is_array( $nItem ) ? $nItem : null );
                if ( null === $created && null !== $nSt && $nSt['name'] === $c['after']['name'] && Canon::hash( $nSt['attrs'] ) === Canon::hash( $c['after']['attrs'] ) ) {
                    $created = [ 'id' => (string) $nid, 'state' => $nSt ];
                } else {
                    $auto = [ 'id' => (string) $nid, 'hash' => Canon::hash( $nSt ) ];
                }
            }
            if ( null === $created ) {
                $notStored[] = [ 'index' => $c['index'], 'name' => $c['after']['name'] ];
                continue;
            }
            $touched[ $created['id'] ] = true;
            if ( null !== $auto ) {
                $touched[ $auto['id'] ] = true;
            }
            $recorded[] = GlobalPresetKind::itemForCreate( $created['id'], $c['module'], $created['state'], $c['module_existed'], $auto );
            $applied[]  = [ 'id' => $created['id'], 'module' => $c['module'], 'action' => 'create', 'before' => null, 'after' => $created['state'], 'default_preset_created' => null !== $auto ];
        }

        // 3. Read back: every preset this call did not touch must be exactly as it was.
        $final      = GlobalPresets::read();
        $unexpected = [];
        if ( null !== $final ) {
            foreach ( (array) ( $before['module'] ?? [] ) as $module => $entry ) {
                foreach ( (array) ( is_array( $entry ) ? ( $entry['items'] ?? [] ) : [] ) as $id => $oldItem ) {
                    if ( isset( $touched[ (string) $id ] ) || ! is_array( $oldItem ) ) {
                        continue;
                    }
                    $newItem = $final['module'][ $module ]['items'][ $id ] ?? null;
                    if ( null === $newItem ) {
                        $unexpected[] = [ 'id' => (string) $id, 'reason' => 'removed_by_divi' ];
                    } elseif ( GlobalPresets::state( $newItem ) !== GlobalPresets::state( $oldItem ) ) {
                        $unexpected[] = [ 'id' => (string) $id, 'reason' => 'changed' ];
                    }
                }
            }
        }

        if ( [] === $recorded ) {
            if ( $threw ) {
                throw new \RuntimeException( 'Divi did not save the presets, so nothing is recorded. Check list_global_styles for their current state.' );
            }

            return [ 'changed' => false, 'dry_run' => false, 'skipped' => $skipped, 'not_stored' => $notStored, 'unexpected_changes' => $unexpected, 'message' => 'Divi accepted the call, but none of the requested changes is stored; nothing is recorded.' ];
        }

        $batch  = $this->log->record( self::NAME, 'Global presets: ' . count( $recorded ) . ' change(s)', $recorded );
        $result = [
            'changed' => true,
            'dry_run' => false,
            'changes' => $applied,
            'skipped' => $skipped,
            'note'    => "A preset only takes effect on modules that cite it: set \"modulePreset\":[\"<id>\"] on a module in a layout (list_global_styles shows the ids). Divi's caches were cleared.",
        ];
        foreach ( [ 'differs_from_request' => $differs, 'not_stored' => $notStored, 'unexpected_changes' => $unexpected ] as $key => $list ) {
            if ( [] !== $list ) {
                $result[ $key ] = $list;
            }
        }
        if ( $threw ) {
            $result['warning'] = 'Divi reported an error while saving; what is stored now is what was recorded, and undo_batch can put the previous state back.';
        }

        return BatchLog::attach( $result, $batch );
    }

    /** @param array<string,mixed> $data */
    private static function refuseUnsafe( array $data ): void
    {
        $unsafe = GlobalPresets::unsafe( $data );
        if ( [] === $unsafe ) {
            return;
        }
        throw new \InvalidArgumentException( 'Divi stores its presets in a shape this tool cannot save back faithfully (' . implode( '; ', $unsafe ) . '), so something would be lost and the change could not bring it back. Nothing was changed.' );
    }
}
```

Wiring: in `pro-addon/src/autoload.php` after the `Tools/ManageGlobalVariables.php` line add `require_once __DIR__ . '/Tools/ManageGlobalPresets.php';`; in `pro-addon/src/Tools.php` add `use AiEditorDivi5\Pro\Tools\ManageGlobalPresets;` and `new ManageGlobalPresets( $log ),` after `new ManageGlobalVariables( $log ),`; in `pro-addon/src/Rest/ProOpenApi.php` set `public const MCP_REST_ONLY = [ 'manage_global_colors', 'manage_global_variables', 'manage_global_presets' ];` and adjust its comment to "the 16 free + 14 Pro operations already use all 30".

- [ ] **Step 4: Update the parity and count tests**

- `pro-addon/tests/ToolsTest.php`: append `'manage_global_presets'` to `testThisReleaseShipsExactlyTheseTools`; in `testTheChatGptOperationBudgetIsRespected` update the comment (the 15th to 17th Pro tools are MCP + REST only) and the last assertion to the three-name list.
- `pro-addon/tests/ProOpenApiTest.php`: add `$this->assertArrayNotHasKey( '/pro/manage_global_presets', $paths, 'MCP + REST only: no room in the 30-operation ChatGPT spec' );` after the variables line (the `assertCount( 14, $paths )` stays).
- `pro-addon/tests/ExpiryKeepsAllToolsTest.php`: the tool count assertion and its message become 17.
- `pro-addon/tests/ProParityTest.php` needs no edit (it iterates `ProOpenApi::MCP_REST_ONLY`); run it.
- `pro-addon/readme.txt`: `ReadmeTest::testItNamesEveryShippedTool` requires every shipped tool to be named. Add a bullet for `manage_global_presets` to the `= Tools =` list (create and update module presets; never deletes; preview token; undoable), next to the variables bullet.

- [ ] **Step 5: Run the tool test and both suites**

Run: `./vendor/bin/phpunit -c pro-addon/phpunit.xml pro-addon/tests/ManageGlobalPresetsTest.php` (all pass), then `make test-pro` (green) and `make test` (815, green).
If another test fails only because the tool count is now 17, update that expected number; never loosen a 30-operation budget assertion. `ProSafetyTest` must still pass (no literal `GlobalPreset` plus two colons and no raw option name in any `src/` file).

- [ ] **Step 6: Commit**

```bash
git add pro-addon/src/Tools/ManageGlobalPresets.php pro-addon/src/autoload.php pro-addon/src/Tools.php pro-addon/src/Rest/ProOpenApi.php pro-addon/readme.txt pro-addon/tests
git commit -m "feat(pro): manage_global_presets (17th Pro tool, MCP + REST only)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: The live gate `make verify-pro-global-presets`

**Files:**
- Create: `scripts/verify-pro-global-presets.php`
- Modify: `Makefile` (`.PHONY` list and the target), `pro-addon/tests/DevGuardTest.php` (the script count, from 10 to 11, and any comment naming the number)

**Interfaces:**
- Consumes: the Docker dev site with the free plugin and the Pro add-on active; `ToolRegistry`, `Tools::all()`, `GlobalPresets`, `BatchTable`, `HistoryTable`, `scripts/dev-guard.php`, and as templates `scripts/verify-pro-global-variables.php` (its helpers `$check`, `$call`, `$errHas`, `$newRequest`, `$fetch`, `$render`, `$cite` style, the batch accounting and `finally` cleanup) and `scripts/spike-global-presets.php`.
- Produces: a self-cleaning script printing `PASS`/`FAIL` lines and `Success: ...`.

- [ ] **Step 1: Write the script**

Create `scripts/verify-pro-global-presets.php` by following `scripts/verify-pro-global-variables.php` (guard first, `$registry = new ToolRegistry( Tools::all(), static fn (): bool => true )`, `$call`, batch ids, fingerprint, `finally` cleanup, final identical-to-start and batch-row accounting) with these differences and checks:
- Fingerprint = `md5( serialize( GlobalPresets::read() ) )`; also keep `$dataStart = GlobalPresets::read()` and take the raw safety net like the spike: snapshot through Divi's own `get_data()` only; if the final fingerprint differs after the undo chain, restore with `GlobalPresets::save( $dataStart )` in the `finally` and FAIL.
- Baseline checks: `GlobalPresets::available()` and `permitted()`; log the preset count.
- (a) A dry run of `[ module divi/heading, name 'AIED verify big', attrs size 57px + bottom margin 39px ]` changes nothing (fingerprint equal) and returns a token and `id` null; a real run without the token is refused.
- (b) The real create stores the preset (name, `styleAttrs` equal to the attrs sent, `type` module, `version` non-empty), the module's `default` is unchanged, and every other preset is byte-identical (compare `GlobalPresets::read()` minus the new id with the start).
- (c) **Render proof:** publish ONE throwaway page titled `aied verify gp probe` (meta `_et_pb_use_builder` on, `_et_pb_use_divi_5` on; layout = placeholder/section/row/column with one `divi/heading` block whose attrs are `{"modulePreset":["<new id>"],"title":{"innerContent":{"desktop":{"value":"AIEDPRESETGATE"}}},"builderVersion":"5.14.0"}` written with `serialize_block_attributes`), fetch its HTML plus same-site stylesheets (the spike's `$render`, `AIED_LOOPBACK_BASE`, `$newRequest()` before every tool call and every render) and PASS-check: the HTML contains `preset--module--divi-heading--<id>`; the CSS contains a rule for that class with `font-size:57px` and one with `margin-bottom:39px`.
- (d) Update the preset (attrs size 61px, name `AIED verify big v2`) with dry run + token: stored name and attrs changed, `updated` is a recent integer, `created` unchanged, the page now renders `font-size:61px` for the class and no longer `57px` for it, other presets untouched.
- (e) A module with no presets: if `GlobalPresets::read()['module']['divi/image']` is absent, create `[ divi/image, name 'AIED verify image', attrs module.decoration.spacing margin bottom 12px ]`; assert the dry run says `default_preset_created`, the real run stores 2 items for the module, then undo that batch and assert the module entry is gone; otherwise `WP_CLI::warning( 'this site already has divi/image presets: the default-preset side-effect check was skipped' )`.
- (f) Refusals change nothing: custom CSS in `attrs` on a create and on an update (`css.desktop.value.freeForm`), an unknown id, a legacy-looking target if the site has a module key `0` (skip otherwise), updating the module's default preset without `allow_default_presets`; fingerprint identical after each refusal batch.
- (g) The token works once.
- (h) Undo the update batch then the create batch (newest first) and assert `fully_undone` true for each; the final `GlobalPresets::read()` serializes identically to the start; the probe page is deleted (also in `finally`); `post_title LIKE 'aied verify gp%'` leaves nothing; the batch table accounting equals baseline (copy the colours/variables gate's logic).
Reuse the exact helper code from the variables gate for `$fetch`/`$render`/`$newRequest`; use `$wpdb`, `BatchTable`, `HistoryTable` as it does. End with `WP_CLI::error(...)` on any failure and `WP_CLI::success( 'Pro manage_global_presets verified on the real Divi presets.' )`.

- [ ] **Step 2: Makefile and the guard-count test**

Add `verify-pro-global-presets` to the `.PHONY:` list and, after the `verify-pro-global-variables` target, add:

```make
# ---------------------------------------------------------------
# verify-pro-global-presets — real-site evidence gate for manage_global_presets (self-cleaning; free + Pro plugins must be active; dev site only)
# ---------------------------------------------------------------
verify-pro-global-presets:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-global-presets.php --user=1
```

In `pro-addon/tests/DevGuardTest.php` update the number of `verify-pro-*.php` scripts the test expects (10 to 11; read the test to find it) and any comment that names it.

- [ ] **Step 3: Run the gate**

The free plugin and the Pro add-on are active on the Docker dev site (check with `docker compose exec -T wpcli wp --allow-root plugin list --fields=name,status`; if the Pro add-on is inactive activate it for the run and restore it). Run: `make verify-pro-global-presets`
Expected: every line `PASS`, ending with the Success line, 0 FAIL, and the site exactly as before (the script proves it). A FAIL is a real defect: find out whether the tool or the gate is wrong, fix it, rerun until clean. A check that cannot fail is a defect of the gate.
Then `make verify-pro-global-variables` and `make verify-pro-global-colors` once (they must still be all PASS), and `make test-pro` (green).

- [ ] **Step 4: Commit**

```bash
git add scripts/verify-pro-global-presets.php Makefile pro-addon/tests/DevGuardTest.php
git commit -m "test(pro): live gate verify-pro-global-presets (create, render, update, default-preset side effect, refusals, undo to identical)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Docs and the full verification

**Files:**
- Modify: `pro-addon/readme.txt` (inside the existing `= 0.8.0 =` changelog section; the Tools bullet was added in Task 4), `pro-addon/README.md` (counts and a paragraph), `docs/superpowers/specs/2026-10-06-pro-global-presets-design.md` (status line and an "Implementation notes" section), `docs/feature-requests-2026-10-04.md` (item 1 status)

**Interfaces:**
- Consumes: Tasks 1 to 5.
- Produces: a releasable state of the pending Pro 0.8.0 (not released).

- [ ] **Step 1: Docs**

- `pro-addon/readme.txt`, 0.8.0 changelog: add one bullet: "New: `manage_global_presets` creates and updates your site's Divi module presets (for example a heading style) so an assistant can set the look of a whole kind of module before it builds pages. Creating never changes an existing preset; changing a preset or a module's default preset restyles every module that uses it, so each run needs the single-use preview token of a dry run, custom CSS and code in a preset are refused, and one `undo_batch` puts everything back (a default preset Divi adds for a module that had none goes too). It never deletes a preset." and update the sentence that says how many tools are MCP + REST only (now three: `manage_global_colors`, `manage_global_variables` and `manage_global_presets`).
- `pro-addon/README.md`: change "sixteen tools" to "seventeen" (both places), append `manage_global_presets` to the tools list, add `src/Settings/GlobalPreset*.php` and the `global_preset` kind to the table rows that describe `src/Settings/` and `src/Kinds/`, add a paragraph after the global-variables paragraph (how creates use Divi's additive import and updates the replace-all save; the default-preset side effect; the custom-code policy; legacy data carried untouched; no delete; the three MCP-only tools in the budget paragraph), and update the "all ten refuse to run" count if it names the number of verify scripts.
- Spec: set the first status line to "implemented on branch feat/pro-global-presets (in the pending Pro 0.8.0, not released)" and append an "Implementation notes" section recording: the safety scanner convention (class-name constant plus `call_user_func`, no literal `GlobalPreset` call and no raw option name), that new items are located by the set difference of item ids around the import (the id-mapping format on a collision was not relied on), that a create's auto-created default preset is identified the same way, and that the undo of a create is refused when anyone added a preset to a module the batch created.
- `docs/feature-requests-2026-10-04.md` item 1: append "Presets (create and update module presets) built as `manage_global_presets` in the pending Pro 0.8.0 (2026-10-06)."

- [ ] **Step 2: Full verification (report counts)**

`make test-pro` (green), `make test` (815, green), `make verify-pro-global-presets` (all PASS), `make verify-pro-global-variables` (all PASS), `make verify-pro-global-colors` (45 PASS), `make verify-pro-rest` (all PASS; the live spec still has 14 `/pro/` operations and none of the three MCP-only tools), `make build-pro` and `unzip -l dist/jhmg-ai-editor-for-divi-5-pro.zip | grep -i GlobalPreset` shows `GlobalPresets.php`, `GlobalPresetPlanner.php`, `GlobalPresetsLock.php`, `GlobalPresetKind.php`, `ManageGlobalPresets.php` and no `tests/` or `scripts/`. `git status` shows only intended files; `CLAUDE.md`, `dist/` and `.superpowers/` are not staged.

- [ ] **Step 3: Commit**

```bash
git add pro-addon/readme.txt pro-addon/README.md docs
git commit -m "docs(pro): manage_global_presets in the pending 0.8.0 (changelog, README, spec notes)

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Self-review (done while writing this plan)

- **Spec coverage:** accessor with capability, shape pre-flight, code policy and legacy handling (Task 1); atomic planner with default-preset flag, create/update rules and refusals (2); lock, undo kind incl. default-preset side effect and undo-of-undo (3); tool with dry run, token, additive import vs replace-all, read-back, batch, wiring, parity, readme tool naming (4); live gate with render proof (5); docs and verification, no version bump (6).
- **Placeholder scan:** none; the gate script (Task 5) is described check by check against two existing templates in the repo rather than reprinted, because it is a mechanical adaptation of `scripts/verify-pro-global-variables.php`; every other step carries its code.
- **Type consistency:** `GlobalPresets::state()` shape `{name,moduleName,attrs}` is used identically by the planner, the kind and the tool; `itemForCreate( id, module, after, moduleExisted, ?autoDefault )` and `itemForUpdate( id, module, before, after )` have the same signatures in Task 3 tests, Task 3 code and Task 4; plan change keys (`index,id,module,action,before,after,item,is_default,default_created,module_existed`) match their use in the tool.
