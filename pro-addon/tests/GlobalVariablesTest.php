<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Settings\GlobalVariables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalVariablesFixture.php';
require_once __DIR__ . '/WpBuildShims.php';

class GlobalVariablesTest extends TestCase
{
    private const N1  = GlobalVariablesFixture::N1;
    private const S1  = GlobalVariablesFixture::S1;
    private const IMG = GlobalVariablesFixture::IMG;

    protected function setUp(): void
    {
        GlobalVariablesFixture::reset();
    }

    protected function tearDown(): void
    {
        GlobalVariablesFixture::clear();
    }

    public function testAvailableNeedsBothDiviFunctions(): void
    {
        $this->assertTrue( GlobalVariables::available() );
        $this->assertFalse( GlobalVariables::available( 'No\\Such\\Class' ) );
    }

    public function testPermittedNeedsTheCapabilityAndDivisVariablesManagerRole(): void
    {
        $this->assertTrue( GlobalVariables::permitted() );
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $this->assertFalse( GlobalVariables::permitted() );
        GlobalVariablesFixture::reset();
        $GLOBALS['__divi_role_allowed'] = false;
        $this->assertFalse( GlobalVariables::permitted() );
    }

    public function testReadReturnsEveryGroupWithoutDivisExtras(): void
    {
        $all = GlobalVariables::read();
        $this->assertSame( [ 'numbers', 'strings', 'images', 'links', 'colors', 'fonts', 'gradients' ], array_keys( (array) $all ) );
        $this->assertSame( [ self::N1, GlobalVariablesFixture::N2 ], array_keys( $all['numbers'] ) );
        $this->assertSame( [ 'label' => 'Radius', 'value' => '12px', 'order' => '1', 'status' => 'active' ], $all['numbers'][ self::N1 ], 'allowedActions is stripped' );
        $this->assertSame( [], $all['fonts'], "Divi's built-in customizer fonts are never part of what is stored" );
        $this->assertSame( [], $all['colors'] );
    }

    public function testAStoredFontStaysButTheCustomizerFontsAreDropped(): void
    {
        $font = 'gvid-ffffffff-0000-4000-8000-000000000001';
        GlobalVariablesFixture::reset( [ 'fonts' => [ $font => [ 'label' => 'Display', 'value' => 'Lora', 'order' => '3', 'status' => 'active' ] ] ] );
        $this->assertSame( [ $font ], array_keys( GlobalVariables::read()['fonts'] ) );
    }

    public function testReadFailsClosed(): void
    {
        $GLOBALS['__divi_gv']['throw_get'] = true;
        $this->assertNull( GlobalVariables::read() );
    }

    public function testStateKeepsLabelValueAndStatusAsStrings(): void
    {
        $this->assertNull( GlobalVariables::state( null ) );
        $this->assertSame( [ 'label' => 'Radius', 'value' => '12px', 'status' => 'active' ], GlobalVariables::state( GlobalVariablesFixture::stored()['numbers'][ self::N1 ] ) );
        $this->assertSame( [ 'label' => '5', 'value' => '', 'status' => 'active' ], GlobalVariables::state( [ 'label' => 5, 'value' => [ 'x' ] ] ), 'a non-scalar value becomes an empty string, a missing status is active' );
    }

    public function testFindLocatesAnIdInAnyGroup(): void
    {
        $all = (array) GlobalVariables::read();
        $this->assertSame( 'strings', GlobalVariables::find( $all, self::S1 )['type'] );
        $this->assertSame( 'images', GlobalVariables::find( $all, self::IMG )['type'] );
        $this->assertNull( GlobalVariables::find( $all, 'gvid-nothing-0000' ) );
    }

    public function testNextOrderIsOneMoreThanTheHighest(): void
    {
        $this->assertSame( 1, GlobalVariables::nextOrder( [] ) );
        $this->assertSame( 6, GlobalVariables::nextOrder( [ 'a' => [ 'order' => '1' ], 'b' => [ 'order' => '5' ], 'c' => [ 'label' => 'no order' ] ] ) );
        $this->assertSame( 3, GlobalVariables::nextOrder( [ 'a' => [ 'order' => 2 ] ] ) );
    }

    public function testWriteHandsDiviTheMapOnceAndReadsBackWhatIsStored(): void
    {
        $map                                = GlobalVariables::sendMap( (array) GlobalVariables::read() );
        $map['numbers']['gvid-new-0000']    = [ 'label' => 'New', 'value' => '2px', 'order' => '3', 'status' => 'active' ];
        $res                                = GlobalVariables::write( $map );
        $this->assertFalse( $res['threw'] );
        $this->assertCount( 1, $GLOBALS['__divi_gv']['set_calls'] );
        $this->assertSame( '2px', $res['after']['numbers']['gvid-new-0000']['value'] );
        $this->assertSame( [ 'label' => 'Logo', 'value' => 'https://example.com/logo.png', 'order' => '1', 'status' => 'active' ], $res['after']['images'][ self::IMG ], 'an unmanaged group comes back as it was' );
    }

    public function testWriteReportsASaveThatThrew(): void
    {
        $GLOBALS['__divi_gv']['throw_set'] = true;
        $res                               = GlobalVariables::write( [] );
        $this->assertTrue( $res['threw'] );
        $this->assertNotNull( $res['after'] );
    }

    public function testSendMapKeepsOnlyTheGroupsThatHoldEntries(): void
    {
        $this->assertSame( [ 'numbers', 'strings', 'images' ], array_keys( GlobalVariables::sendMap( (array) GlobalVariables::read() ) ) );
    }

    /** @return array<string,array{array<string,mixed>,string,string}> map, expected id, expected reason */
    public static function unsafeMaps(): array
    {
        $ok = [ 'label' => 'Radius', 'value' => '12px', 'order' => '1', 'status' => 'active' ];

        return [
            'empty label'          => [ [ 'numbers' => [ 'gvid-aaa-1' => [ 'label' => '' ] + $ok ] ], 'gvid-aaa-1', 'empty_label' ],
            'label zero'           => [ [ 'numbers' => [ 'gvid-aaa-1' => [ 'label' => '0' ] + $ok ] ], 'gvid-aaa-1', 'empty_label' ],
            'missing label'        => [ [ 'numbers' => [ 'gvid-aaa-1' => array_diff_key( $ok, [ 'label' => 1 ] ) ] ], 'gvid-aaa-1', 'empty_label' ],
            'id without gvid'      => [ [ 'numbers' => [ 'radius-1' => $ok ] ], 'radius-1', 'bad_id' ],
            'id undefined'         => [ [ 'strings' => [ 'undefined' => $ok ] ], 'undefined', 'bad_id' ],
            'array field'          => [ [ 'numbers' => [ 'gvid-aaa-1' => [ 'value' => [ 'x' ] ] + $ok ] ], 'gvid-aaa-1', 'non_scalar_field' ],
            'null field'           => [ [ 'numbers' => [ 'gvid-aaa-1' => [ 'status' => null ] + $ok ] ], 'gvid-aaa-1', 'non_scalar_field' ],
            'entry not an array'   => [ [ 'numbers' => [ 'gvid-aaa-1' => 'x' ] ], 'gvid-aaa-1', 'not_an_entry' ],
            'a colors group'       => [ [ 'colors' => [ 'gvid-ccc-1' => $ok ] ], 'colors', 'unsupported_type' ],
            'a group not an array' => [ [ 'numbers' => 'x' ], 'numbers', 'bad_group' ],
        ];
    }

    /** @param array<string,mixed> $map */
    #[DataProvider( 'unsafeMaps' )]
    public function testUnsafeEntriesNamesWhatDiviWouldDropOrDamage( array $map, string $id, string $reason ): void
    {
        $this->assertSame( [ [ 'id' => $id, 'reason' => $reason ] ], GlobalVariables::unsafeEntries( $map ) );
    }

    public function testAnEmptyColorsGroupAndTheStoredFixtureAreSafe(): void
    {
        $this->assertSame( [], GlobalVariables::unsafeEntries( GlobalVariablesFixture::stored() + [ 'colors' => [] ] ) );
        $this->assertSame( [], GlobalVariables::unsafeEntries( GlobalVariables::sendMap( (array) GlobalVariables::read() ) ) );
    }

    public function testValidId(): void
    {
        $this->assertTrue( GlobalVariables::validId( 'gvid-98eb727a-9088-4709-8ec8-2fee0213c5c3' ) );
        $this->assertTrue( GlobalVariables::validId( 'gvid-3cf7c9305a' ) );
        foreach ( [ 'gcid-abc', 'gvid-', 'gvid-ab', 'gvid-a b c', "gvid-abc\n", 'GVID-abc', 'gvid-' . str_repeat( 'a', 65 ), 'gvid-abc;', '--et_global_heading_font' ] as $bad ) {
            $this->assertFalse( GlobalVariables::validId( $bad ), $bad );
        }
    }
}
