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
        return [ 'hex3' => [ '#fff' ], 'hex4' => [ '#fff8' ], 'hex6' => [ '#1A2b3C' ], 'hex8' => [ '#11223344' ], 'rgb' => [ 'rgb(0, 0, 0)' ], 'rgba' => [ 'rgba(0,0,0,.5)' ], 'hsl' => [ 'hsl(120, 50%, 50%)' ], 'hsla' => [ 'hsla(120,50%,50%,0.5)' ], 'space syntax' => [ 'rgb(0 0 0 / 50%)' ], 'hsl spaces' => [ 'hsl(120 100% 50%)' ], 'leading dot' => [ 'rgb(.5,.5,.5)' ], 'decimals' => [ 'rgba(10.5, 20.25, 30, 0.75)' ], 'inner padding' => [ 'rgb( 1 , 2 , 3 )' ] ];
    }

    /** @return array<string,array{string}> */
    public static function invalidColors(): array
    {
        return [
            'named' => [ 'red' ], 'var' => [ 'var(--x)' ], 'brace' => [ '#fff; }' ], 'url' => [ 'url(x)' ], 'semicolon' => [ 'rgb(0,0,0);' ],
            'newline' => [ "#fff\n" ], 'nine digits' => [ '#123456789' ], 'five digits' => [ '#12345' ], 'calc' => [ 'calc(1px)' ], 'empty' => [ '' ],
            'quote' => [ 'rgb(0,0,"0")' ], 'backslash' => [ 'rgb(0,0,\\0)' ], 'angle' => [ '<b>' ], 'too long' => [ 'rgb(' . str_repeat( '1,', 40 ) . '1)' ], 'two args' => [ 'rgb(1,2)' ], 'five args' => [ 'rgb(1,2,3,4,5)' ],
            'newline in args' => [ "rgb(1\n,2,3)" ], 'vertical tab' => [ "rgb(1\x0B,2,3)" ], 'form feed' => [ "rgb(\f1,2,3)" ], 'tab' => [ "rgb(1\t,2,3)" ], 'carriage return' => [ "rgb(1\r,2,3)" ],
            'dots only' => [ 'rgb(.,.,.)' ], 'double dot' => [ 'rgb(1..2,0,0)' ], 'trailing dot' => [ 'rgb(1.,2,3)' ], 'two dots' => [ 'rgb(1.2.3,2,3)' ], 'percent only' => [ 'rgb(%,%,%)' ],
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

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function unsafeMaps(): array
    {
        $ok = [ 'color' => '#112233', 'folder' => '', 'label' => 'Brand', 'lastUpdated' => '2026-01-01T00:00:00.000Z', 'status' => 'active', 'usedInPosts' => [ '12' ] ];

        return [
            'empty color'            => [ [ 'gcid-aaa-1' => [ 'color' => '' ] + $ok ], 'empty_color' ],
            'zero color'             => [ [ 'gcid-aaa-1' => [ 'color' => '0' ] + $ok ], 'empty_color' ],
            'missing color'          => [ [ 'gcid-aaa-1' => array_diff_key( $ok, [ 'color' => 1 ] ) ], 'empty_color' ],
            'undefined color'        => [ [ 'gcid-aaa-1' => [ 'color' => 'undefined' ] + $ok ], 'undefined_color' ],
            'id without gcid prefix' => [ [ 'brand-1' => $ok ], 'bad_id' ],
            'id undefined'           => [ [ 'undefined' => $ok ], 'bad_id' ],
            'array field'            => [ [ 'gcid-aaa-1' => [ 'label' => [ 'x' ] ] + $ok ], 'non_scalar_field' ],
            'null field'             => [ [ 'gcid-aaa-1' => [ 'folder' => null ] + $ok ], 'non_scalar_field' ],
            'usedInPosts a string'   => [ [ 'gcid-aaa-1' => [ 'usedInPosts' => '12' ] + $ok ], 'bad_used_in_posts' ],
            'usedInPosts null'       => [ [ 'gcid-aaa-1' => [ 'usedInPosts' => null ] + $ok ], 'bad_used_in_posts' ],
            'usedInPosts nested'     => [ [ 'gcid-aaa-1' => [ 'usedInPosts' => [ [ '12' ] ] ] + $ok ], 'bad_used_in_posts' ],
            'usedInPosts with null'  => [ [ 'gcid-aaa-1' => [ 'usedInPosts' => [ null ] ] + $ok ], 'bad_used_in_posts' ],
            'entry not an array'     => [ [ 'gcid-aaa-1' => 'x' ], 'not_an_entry' ],
        ];
    }

    /** @param array<string,mixed> $map */
    #[DataProvider( 'unsafeMaps' )]
    public function testUnsafeEntriesNamesWhatDiviWouldDropOrDamage( array $map, string $reason ): void
    {
        $this->assertSame( [ [ 'id' => (string) array_key_first( $map ), 'reason' => $reason ] ], GlobalColors::unsafeEntries( $map ) );
    }

    public function testASafeMapHasNoUnsafeEntries(): void
    {
        $map = GlobalColorsFixture::custom() + [ 'gcid-primary-color' => GlobalColors::themeEntry( '#ff0000', 'Primary Color', '2026-10-05T10:00:00.000Z' ) ];
        $map[ GlobalColorsFixture::A ]['usedInPosts'] = [ '1', 2, 3.5, true ];
        $this->assertSame( [], GlobalColors::unsafeEntries( $map ) );
        $this->assertSame( [], GlobalColors::unsafeEntries( [] ) );
    }

    public function testEveryUnsafeEntryIsListedInOrder(): void
    {
        $bad = GlobalColorsFixture::custom()[ GlobalColorsFixture::A ];
        $map = [ 'gcid-x-1' => [ 'color' => '' ] + $bad, GlobalColorsFixture::B => GlobalColorsFixture::custom()[ GlobalColorsFixture::B ], 'nope' => $bad ];
        $this->assertSame( [ [ 'id' => 'gcid-x-1', 'reason' => 'empty_color' ], [ 'id' => 'nope', 'reason' => 'bad_id' ] ], GlobalColors::unsafeEntries( $map ) );
    }
}
