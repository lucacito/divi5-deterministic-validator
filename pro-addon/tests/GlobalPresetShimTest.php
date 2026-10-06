<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use ET\Builder\Packages\GlobalData\GlobalPreset;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/GlobalPresetsFixture.php';

/** Pins the stand-in's import and save semantics, so the tests that rely on it rest on a stated behaviour. */
class GlobalPresetShimTest extends TestCase
{
    private const NEW_ID = 'aaaaaaaa-0000-4000-8000-000000000001';

    protected function setUp(): void
    {
        GlobalPresetsFixture::reset();
    }

    protected function tearDown(): void
    {
        GlobalPresetsFixture::clear();
    }

    /** @param array<string,mixed> $attrs @return array<string,mixed> */
    private function payload( string $module, string $id, string $name, array $attrs ): array
    {
        $item = [ 'id' => $id, 'moduleName' => $module, 'name' => $name, 'attrs' => $attrs, 'styleAttrs' => $attrs, 'version' => '5.14.0', 'type' => 'module', 'created' => 1, 'updated' => 1 ];

        return [ 'module' => [ $module => [ 'default' => '', 'items' => [ $id => $item ] ] ] ];
    }

    public function testImportingTheSameIdWithTheSameContentTwiceLeavesTheDataIdentical(): void
    {
        $payload = $this->payload( 'divi/heading', self::NEW_ID, 'Big', [ 'title' => [ 'x' => '1' ] ] );
        GlobalPreset::process_presets_for_import( $payload, true );
        $first = GlobalPreset::get_data();
        GlobalPreset::process_presets_for_import( $payload, true );
        $this->assertSame( $first, GlobalPreset::get_data() );
        $this->assertCount( 3, $first['module']['divi/heading']['items'] );
    }

    public function testTheSameIdWithDifferentAttrsIsStoredAsACopyUnderANewId(): void
    {
        GlobalPreset::process_presets_for_import( $this->payload( 'divi/heading', self::NEW_ID, 'Big', [ 'title' => [ 'x' => '1' ] ] ), true );
        $before = GlobalPreset::get_data()['module']['divi/heading']['items'];
        $result = GlobalPreset::process_presets_for_import( $this->payload( 'divi/heading', self::NEW_ID, 'Big', [ 'title' => [ 'x' => '2' ] ] ), true );
        $after  = GlobalPreset::get_data()['module']['divi/heading']['items'];

        $this->assertCount( count( $before ) + 1, $after );
        $this->assertSame( $before[ self::NEW_ID ], $after[ self::NEW_ID ], 'the original is untouched' );
        $newId = $result['preset_id_mappings']['module'][ self::NEW_ID ] ?? null;
        $this->assertIsString( $newId );
        $this->assertNotSame( self::NEW_ID, $newId );
        $this->assertSame( $newId, $after[ $newId ]['id'] );
        $this->assertSame( [ 'title' => [ 'x' => '2' ] ], $after[ $newId ]['attrs'] );
    }

    public function testAnImportedItemIsStampedWithTheShimsNow(): void
    {
        $GLOBALS['__divi_gp']['now'] = 1795551234;
        GlobalPreset::process_presets_for_import( $this->payload( 'divi/heading', self::NEW_ID, 'Big', [ 'title' => [ 'x' => '1' ] ] ), true );
        $item = GlobalPreset::get_data()['module']['divi/heading']['items'][ self::NEW_ID ];
        $this->assertSame( 1795551234, $item['created'] );
        $this->assertSame( 1795551234, $item['updated'] );
    }

    public function testAnImportForAModuleWithNoPresetsAddsADefaultPresetAndSetsDefault(): void
    {
        GlobalPreset::process_presets_for_import( $this->payload( 'divi/image', self::NEW_ID, 'Rounded', [ 'module' => [ 'x' => '1' ] ] ), true );
        $entry = GlobalPreset::get_data()['module']['divi/image'];
        $this->assertCount( 2, $entry['items'] );
        $this->assertArrayHasKey( $entry['default'], $entry['items'] );
        $this->assertNotSame( self::NEW_ID, $entry['default'], 'the default is Divi\'s own preset, not the imported one' );
        $this->assertSame( 'divi/image', $entry['items'][ $entry['default'] ]['moduleName'] );
    }

    public function testAnImportForAModuleThatHasPresetsKeepsItsDefault(): void
    {
        GlobalPreset::process_presets_for_import( $this->payload( 'divi/heading', self::NEW_ID, 'Big', [ 'title' => [ 'x' => '1' ] ] ), true );
        $this->assertSame( GlobalPresetsFixture::H_DEFAULT, GlobalPreset::get_data()['module']['divi/heading']['default'] );
    }

    public function testSaveDataReplacesEverythingAndLeavesUpdatedAlone(): void
    {
        $only = [ 'module' => [ 'divi/text' => GlobalPresetsFixture::data()['module']['divi/text'] ] ];
        $only['module']['divi/text']['items'][ GlobalPresetsFixture::T_DEFAULT ]['updated'] = 1234;
        $GLOBALS['__divi_gp']['now'] = 1799999999;
        GlobalPreset::save_data( $only );
        $this->assertSame( $only, GlobalPreset::get_data(), 'everything not given is gone and nothing is re-stamped' );
        $this->assertSame( 1234, GlobalPreset::get_data()['module']['divi/text']['items'][ GlobalPresetsFixture::T_DEFAULT ]['updated'] );
    }
}
