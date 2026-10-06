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

    private function sized( string $px ): array
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
        $rows = GlobalPresetPlanner::normalize( [ [ 'module' => 'divi/heading', 'name' => ' Big heading ', 'attrs' => $this->sized( '57px' ) ], [ 'id' => self::H1, 'name' => 'Renamed' ] ] );
        $this->assertSame( [ 'id' => null, 'module' => 'divi/heading', 'name' => 'Big heading', 'attrs' => $this->sized( '57px' ) ], $rows[0] );
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
        $p = $this->plan( [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->sized( '57px' ) ] ] );
        $c = $p['changes'][0];
        $this->assertSame( 'create', $c['action'] );
        $this->assertSame( 'aaaaaaaa-0000-4000-8000-000000000001', $c['id'] );
        $this->assertNull( $c['before'] );
        $this->assertSame( 'Big', $c['after']['name'] );
        $this->assertSame( 'divi/heading', $c['item']['moduleName'] );
        $this->assertSame( $this->sized( '57px' ), $c['item']['styleAttrs'] );
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
        $p = $this->plan( [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ), 'name' => 'Heading 1 v2' ] ] );
        $c = $p['changes'][0];
        $this->assertSame( 'update', $c['action'] );
        $this->assertSame( 'Heading 1', $c['before']['name'] );
        $this->assertSame( '72px', $c['before']['attrs']['title']['decoration']['font']['font']['desktop']['value']['size'] );
        $this->assertSame( $this->sized( '61px' ), $c['item']['attrs'] );
        $this->assertSame( $this->sized( '61px' ), $c['item']['styleAttrs'] );
        $this->assertSame( 'Heading 1 v2', $c['item']['name'] );
        $this->assertSame( 1790000000, $c['item']['updated'] );
        $this->assertSame( 1782593353, $c['item']['created'] );
        $this->assertSame( '5.8.1', $c['item']['version'] );
        $this->assertSame( array_keys( GlobalPresetsFixture::data()['module']['divi/heading']['items'][ self::H1 ] ), array_keys( $c['item'] ), 'no key added or lost' );
    }

    public function testUpdatingAModulesDefaultPresetNeedsTheFlag(): void
    {
        $entries = [ [ 'id' => self::H_DEFAULT, 'attrs' => $this->sized( '31px' ) ] ];
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
        $this->refused( [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->sized( '1px' ) ], [ 'module' => 'divi/heading', 'name' => 'BIG', 'attrs' => $this->sized( '2px' ) ] ], 'already named' );
    }

    public function testCustomCodeInAnUpdateIsRefusedToo(): void
    {
        $this->refused( [ [ 'id' => self::H1, 'attrs' => [ 'module' => [ 'advanced' => [ 'css' => [ 'desktop' => [ 'value' => [ 'freeForm' => '.x{color:red}' ] ] ] ] ] ] ] ], 'CUSTOM_CSS_NOT_ALLOWED' );
    }

    public function testTheSameNameInAnotherModuleIsFine(): void
    {
        $p = $this->plan( [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->sized( '1px' ) ], [ 'module' => 'divi/text', 'name' => 'Big', 'attrs' => [ 'content' => [ 'x' => '1' ] ] ] ] );
        $this->assertCount( 2, $p['changes'] );
    }

    public function testUpdatingAnEmptyDefaultShellNeedsTheFlagAndAddsBothTreesKeepingTheRest(): void
    {
        $id   = GlobalPresetsFixture::T_DEFAULT;
        $rows = [ [ 'id' => $id, 'attrs' => [ 'content' => [ 'x' => '1' ] ] ] ];
        $this->refused( $rows, 'is the default preset of divi/text' );
        $p = $this->plan( $rows, true );
        $c = $p['changes'][0];
        $this->assertSame( 'update', $c['action'] );
        $this->assertTrue( $c['is_default'] );
        $this->assertSame( [], $c['before']['attrs'] );
        $this->assertSame( [ 'content' => [ 'x' => '1' ] ], $c['after']['attrs'] );
        $this->assertSame( [ 'content' => [ 'x' => '1' ] ], $c['item']['attrs'] );
        $this->assertSame( [ 'content' => [ 'x' => '1' ] ], $c['item']['styleAttrs'] );
        $this->assertSame( 1790000000, $c['item']['updated'] );
        $orig = GlobalPresetsFixture::data()['module']['divi/text']['items'][ $id ];
        $got  = $c['item'];
        unset( $got['attrs'], $got['styleAttrs'], $got['updated'], $orig['updated'] );
        $this->assertSame( $orig, $got, 'every other key of the shell is kept' );
    }
}
