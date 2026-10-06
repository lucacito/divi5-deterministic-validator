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
