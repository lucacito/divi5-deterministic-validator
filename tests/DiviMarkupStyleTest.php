<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CodePolicy;
use AiEditorDivi5\WP\DiviMarkup;
use Divi5Validator\Tests\Support\ProvenOverride;
use AiEditorDivi5\WP\LayoutGate;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Support/ProvenOverride.php';

/**
 * The style writer of DiviMarkup (plan 2026-10-22-html-to-divi-styles, Task 7): optional trailing style records, written at
 * the paths the probe measured, validated before writing, and byte-identical to today's markup when no style is given.
 */
final class DiviMarkupStyleTest extends HtmlTestCase
{
    private const H = 'title.decoration.font.font.';
    private const B = 'content.decoration.bodyFont.body.font.';

    /** The attributes of the first (or $nth) block of a kind. @return array<string,mixed> */
    public static function attrs( string $markup, string $block, int $nth = 0 ): array
    {
        preg_match_all( '/<!-- wp:divi\/' . $block . ' (\{.*?\}) \/?-->/s', $markup, $m );
        $json = $m[1][ $nth ] ?? null;
        self::assertNotNull( $json, 'the markup holds a ' . $block );
        $attrs = json_decode( $json, true );
        self::assertIsArray( $attrs );

        return $attrs;
    }

    /** The value at a dotted path, or the marker ABSENT. */
    public static function at( array $attrs, string $path ): mixed
    {
        foreach ( explode( '.', $path ) as $key ) {
            if ( ! is_array( $attrs ) || ! array_key_exists( $key, $attrs ) ) {
                return 'ABSENT';
            }
            $attrs = $attrs[ $key ];
        }

        return $attrs;
    }

    /** @return array<string,array{0:\Closure,1:string}> a call with an empty style, and the markup of the same call without one */
    public static function plainCalls(): array
    {
        return [
            'section'        => [ static fn () => DiviMarkup::section( 'Hero', '', [] ), DiviMarkup::section( 'Hero', '' ) ],
            'section bare'   => [ static fn () => DiviMarkup::section( '', 'x', [] ), DiviMarkup::section( '', 'x' ) ],
            'row 1'          => [ static fn () => DiviMarkup::row( [ '' ], [], [] ), DiviMarkup::row( [ '' ] ) ],
            'row 2'          => [ static fn () => DiviMarkup::row( [ 'a', 'b' ], [], [ [], [] ] ), DiviMarkup::row( [ 'a', 'b' ] ) ],
            'row 3'          => [ static fn () => DiviMarkup::row( [ '', '', '' ], [] ), DiviMarkup::row( [ '', '', '' ] ) ],
            'row 4'          => [ static fn () => DiviMarkup::row( [ '', '', '', '' ] ), DiviMarkup::row( [ '', '', '', '' ] ) ],
            'heading'        => [ static fn () => DiviMarkup::heading( 'Title', 2, [] ), DiviMarkup::heading( 'Title', 2 ) ],
            'heading clamp'  => [ static fn () => DiviMarkup::heading( 'T <b> & "q"', 9, [] ), DiviMarkup::heading( 'T <b> & "q"', 9 ) ],
            'text'           => [ static fn () => DiviMarkup::text( '<p>Hi</p>', [] ), DiviMarkup::text( '<p>Hi</p>' ) ],
            'button'         => [ static fn () => DiviMarkup::button( 'Go', 'https://example.com', true, [] ), DiviMarkup::button( 'Go', 'https://example.com', true ) ],
            'button bare'    => [ static fn () => DiviMarkup::button( 'Go', null, false, [] ), DiviMarkup::button( 'Go', null, false ) ],
        ];
    }

    #[DataProvider('plainCalls')]
    public function testAnEmptyStyleIsTodaysMarkup( \Closure $withEmptyStyle, string $without ): void
    {
        $this->assertSame( $without, $withEmptyStyle() );
    }

    public function testTodaysMarkupIsByteForByteWhatPhaseOneWrote(): void
    {
        // md5 of the phase-one outputs, recorded on the unmodified writer (1df11d5) before the style parameters existed.
        $o = [ DiviMarkup::section( 'Hero', '' ), DiviMarkup::section( '', 'x' ) ];
        for ( $i = 0; $i <= 5; $i++ ) {
            $o[] = DiviMarkup::row( array_fill( 0, $i, 'm' ) );
        }
        array_push( $o, DiviMarkup::heading( 'Title', 2 ), DiviMarkup::heading( 'T <b> & "q"', 9 ), DiviMarkup::text( '<p>Hi</p>' ),
            DiviMarkup::image( 'https://e.com/a.png', 'Alt', '/go' ), DiviMarkup::button( 'Go', 'https://e.com', true ), DiviMarkup::button( 'Go', null, false ), DiviMarkup::divider(),
            DiviMarkup::page( [ DiviMarkup::section( 'S', DiviMarkup::row( [ DiviMarkup::heading( 'a', 1 ) . DiviMarkup::text( 'b' ) ] ) ) ] ) );
        $this->assertSame( '013e3aff32989a38fdf9d1dd2985e08f', md5( implode( "\n--\n", $o ) ) );
    }

    /** @return array<string,array{0:string,1:string,2:array<string,mixed>,3:string,4:mixed}> */
    public static function keys(): array
    {
        $D = 'desktop.value.';
        $V = static fn ( string $k ) => $k;

        return [
            'heading color'        => [ 'heading', 'h', [ 'color' => '#112233' ], self::H . $D . 'color', '#112233' ],
            'heading size'         => [ 'heading', 'h', [ 'size' => 24 ], self::H . $D . 'size', '24px' ],
            'heading weight'       => [ 'heading', 'h', [ 'weight' => '700' ], self::H . $D . 'weight', '700' ],
            'heading align center' => [ 'heading', 'h', [ 'align' => 'center' ], self::H . $D . 'textAlign', 'center' ],
            'heading align right'  => [ 'heading', 'h', [ 'align' => 'right' ], self::H . $D . 'textAlign', 'right' ],
            'heading justify'      => [ 'heading', 'h', [ 'align' => 'justify' ], self::H . $D . 'textAlign', 'justify' ],
            'heading family'       => [ 'heading', 'h', [ 'family' => 'Playfair Display' ], self::H . $D . 'family', 'Playfair Display' ],
            'heading websafe'      => [ 'heading', 'h', [ 'family' => 'Georgia' ], self::H . $D . 'family', 'Georgia' ],
            'heading level kept'   => [ 'heading', 'h', [ 'color' => '#112233' ], self::H . $D . 'headingLevel', 'h2' ],
            'text color'           => [ 'text', 't', [ 'color' => '#112233' ], self::B . $D . 'color', '#112233' ],
            'text size'            => [ 'text', 't', [ 'size' => 18 ], self::B . $D . 'size', '18px' ],
            'text weight'          => [ 'text', 't', [ 'weight' => '500' ], self::B . $D . 'weight', '500' ],
            'text family'          => [ 'text', 't', [ 'family' => 'Inter' ], self::B . $D . 'family', 'Inter' ],
            'text websafe'         => [ 'text', 't', [ 'family' => 'Courier New' ], self::B . $D . 'family', 'Courier New' ],
            'text align center'    => [ 'text', 't', [ 'align' => 'center' ], 'module.advanced.text.text.' . $D . 'orientation', 'center' ],
            'text align right'     => [ 'text', 't', [ 'align' => 'right' ], 'module.advanced.text.text.' . $D . 'orientation', 'right' ],
            'text justify'         => [ 'text', 't', [ 'align' => 'justify' ], self::B . $D . 'textAlign', 'justify' ],
            'text content kept'    => [ 'text', 't', [ 'color' => '#112233' ], 'content.innerContent.desktop.value', '<p>Hi</p>' ],
            'button color'         => [ 'button', 'b', [ 'color' => '#ffffff' ], 'button.decoration.font.font.' . $D . 'color', '#ffffff' ],
            'button background'    => [ 'button', 'b', [ 'background' => '#0a0b0c' ], 'button.decoration.background.' . $D . 'color', '#0a0b0c' ],
            'button transparent'   => [ 'button', 'b', [ 'background' => 'rgba(255,255,255,0)' ], 'button.decoration.background.' . $D . 'color', 'rgba(255,255,255,0)' ],
            'button radius tl'     => [ 'button', 'b', [ 'radius' => 12 ], 'button.decoration.border.' . $D . 'radius.topLeft', '12px' ],
            'button radius tr'     => [ 'button', 'b', [ 'radius' => 12 ], 'button.decoration.border.' . $D . 'radius.topRight', '12px' ],
            'button radius br'     => [ 'button', 'b', [ 'radius' => 0 ], 'button.decoration.border.' . $D . 'radius.bottomRight', '0px' ],
            'button radius bl'     => [ 'button', 'b', [ 'radius' => 999 ], 'button.decoration.border.' . $D . 'radius.bottomLeft', '999px' ],
            'button text kept'     => [ 'button', 'b', [ 'radius' => 4 ], 'button.innerContent.desktop.value.text', 'Go' ],
            'section background'   => [ 'section', 's', [ 'background' => '#101010' ], 'module.decoration.background.' . $D . 'color', '#101010' ],
            'section padding top'  => [ 'section', 's', [ 'padding' => [ 'top' => '64px', 'right' => '8px', 'bottom' => '32px', 'left' => '0px' ] ], 'module.decoration.spacing.' . $D . 'padding.top', '64px' ],
            'section padding right' => [ 'section', 's', [ 'padding' => [ 'top' => '64px', 'right' => '8px', 'bottom' => '32px', 'left' => '0px' ] ], 'module.decoration.spacing.' . $D . 'padding.right', '8px' ],
            'section padding bottom' => [ 'section', 's', [ 'padding' => [ 'top' => '64px', 'right' => '8px', 'bottom' => '32px', 'left' => '0px' ] ], 'module.decoration.spacing.' . $D . 'padding.bottom', '32px' ],
            'section padding left' => [ 'section', 's', [ 'padding' => [ 'top' => '64px', 'right' => '8px', 'bottom' => '32px', 'left' => '0px' ] ], 'module.decoration.spacing.' . $D . 'padding.left', '0px' ],
            'section label kept'   => [ 'section', 's', [ 'background' => '#101010' ], 'module.meta.adminLabel.desktop.value', 'Hero' ],
            'row background'       => [ 'row', 'r', [ 'background' => '#202020' ], 'module.decoration.background.' . $D . 'color', '#202020' ],
            'row padding'          => [ 'row', 'r', [ 'padding' => [ 'top' => '10px', 'bottom' => '20px' ] ], 'module.decoration.spacing.' . $D . 'padding.bottom', '20px' ],
            'row max width'        => [ 'row', 'r', [ 'max_width' => '720px' ], 'module.decoration.sizing.' . $D . 'maxWidth', '720px' ],
            'row layout kept'      => [ 'row', 'r', [ 'max_width' => '720px' ], 'module.decoration.layout.desktop.value.flexWrap', 'nowrap' ],
        ];
    }

    /** @param array<string,mixed> $style */
    #[DataProvider('keys')]
    public function testEveryStyleKeyLandsOnItsPath( string $block, string $kind, array $style, string $path, mixed $expected ): void
    {
        $markup = match ( $kind ) {
            'h'     => DiviMarkup::heading( 'Title', 2, $style ),
            't'     => DiviMarkup::text( '<p>Hi</p>', $style ),
            'b'     => DiviMarkup::button( 'Go', 'https://example.com', false, $style ),
            's'     => DiviMarkup::section( 'Hero', '', $style ),
            default => DiviMarkup::row( [ '' ], $style ),
        };
        $this->assertSame( $expected, self::at( self::attrs( $markup, $block ), $path ), $path );
    }

    public function testColumnStylesLandOnTheirColumnsOnly(): void
    {
        $markup = DiviMarkup::row( [ 'a', 'b', 'c' ], [], [
            [ 'background' => '#aabbcc', 'padding' => [ 'top' => '24px', 'right' => '24px', 'bottom' => '24px', 'left' => '24px' ] ],
            [],
            [ 'background' => '#ddeeff' ],
        ] );
        $one = self::attrs( $markup, 'column', 0 );
        $two = self::attrs( $markup, 'column', 1 );
        $tre = self::attrs( $markup, 'column', 2 );
        $this->assertSame( '#aabbcc', self::at( $one, 'module.decoration.background.desktop.value.color' ) );
        $this->assertSame( '24px', self::at( $one, 'module.decoration.spacing.desktop.value.padding.left' ) );
        $this->assertSame( 'ABSENT', self::at( $two, 'module.decoration.background' ) );
        $this->assertSame( 'ABSENT', self::at( $two, 'module.decoration.spacing' ) );
        $this->assertSame( '#ddeeff', self::at( $tre, 'module.decoration.background.desktop.value.color' ) );
        $this->assertSame( 'ABSENT', self::at( $tre, 'module.decoration.spacing' ) );
        foreach ( [ $one, $two, $tre ] as $col ) {
            $this->assertSame( '8_24', self::at( $col, 'module.decoration.sizing.desktop.value.flexType' ), 'the column structure is kept' );
            $this->assertSame( 'ABSENT', self::at( $col, 'module.decoration.sizing.desktop.value.maxWidth' ), 'no max width on a column' );
        }
    }

    public function testTheKeysOfEveryObjectAreInTheDocumentedOrder(): void
    {
        $h = self::attrs( DiviMarkup::heading( 'T', 3, [ 'family' => 'Inter', 'align' => 'center', 'weight' => '800', 'size' => 40, 'color' => '#010203' ] ), 'heading' );
        $this->assertSame( [ 'headingLevel', 'color', 'size', 'weight', 'textAlign', 'family' ], array_keys( $h['title']['decoration']['font']['font']['desktop']['value'] ) );
        $this->assertSame( [ 'desktop', 'tablet', 'phone' ], array_keys( $h['title']['decoration']['font']['font'] ) );
        $this->assertSame( [ 'innerContent', 'decoration' ], array_keys( $h['title'] ) );
        $t = self::attrs( DiviMarkup::text( '<p>x</p>', [ 'align' => 'justify', 'family' => 'Inter', 'weight' => '400', 'size' => 16, 'color' => '#010203' ] ), 'text' );
        $this->assertSame( [ 'color', 'size', 'weight', 'family', 'textAlign' ], array_keys( $t['content']['decoration']['bodyFont']['body']['font']['desktop']['value'] ) );
        $t = self::attrs( DiviMarkup::text( '<p>x</p>', [ 'align' => 'center', 'color' => '#010203' ] ), 'text' );
        $this->assertSame( [ 'content', 'module', 'builderVersion' ], array_keys( $t ) );
        $b = self::attrs( DiviMarkup::button( 'x', '/a', false, [ 'radius' => 3, 'background' => '#010203', 'color' => '#ffffff' ] ), 'button' );
        $this->assertSame( [ 'innerContent', 'decoration' ], array_keys( $b['button'] ) );
        $this->assertSame( [ 'background', 'border', 'font' ], array_keys( $b['button']['decoration'] ) );
        $this->assertSame( [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ], array_keys( $b['button']['decoration']['border']['desktop']['value']['radius'] ) );
        $s = self::attrs( DiviMarkup::section( 'L', '', [ 'padding' => [ 'left' => '1px', 'top' => '2px', 'bottom' => '3px', 'right' => '4px' ], 'background' => '#010203' ] ), 'section' );
        $this->assertSame( [ 'module', 'builderVersion' ], array_keys( $s ) );
        $this->assertSame( [ 'meta', 'decoration' ], array_keys( $s['module'] ) );
        $this->assertSame( [ 'background', 'spacing' ], array_keys( $s['module']['decoration'] ) );
        $this->assertSame( [ 'top', 'right', 'bottom', 'left' ], array_keys( $s['module']['decoration']['spacing']['desktop']['value']['padding'] ) );
        $r = self::attrs( DiviMarkup::row( [ '' ], [ 'max_width' => '700px', 'padding' => [ 'top' => '1px' ], 'background' => '#010203' ] ), 'row' );
        $this->assertSame( [ 'layout', 'background', 'spacing', 'sizing' ], array_keys( $r['module']['decoration'] ) );
        $c = self::attrs( DiviMarkup::row( [ '' ], [], [ [ 'padding' => [ 'top' => '1px' ], 'background' => '#010203' ] ] ), 'column' );
        $this->assertSame( [ 'sizing', 'background', 'spacing' ], array_keys( $c['module']['decoration'] ) );
    }

    /** @return array<string,array{0:int,1:?array{0:string,1:string}}> */
    public static function sizes(): array
    {
        return [ '56' => [ 56, [ '42px', '31px' ] ], '33' => [ 33, [ '25px', '20px' ] ], '32' => [ 32, null ], '8' => [ 8, null ], '200' => [ 200, [ '150px', '110px' ] ], '40' => [ 40, [ '30px', '22px' ] ] ];
    }

    /** @param ?array{0:string,1:string} $steps */
    #[DataProvider('sizes')]
    public function testLargeSizesGetTabletAndPhoneSteps( int $size, ?array $steps ): void
    {
        $h = self::attrs( DiviMarkup::heading( 'Big', 1, [ 'size' => $size ] ), 'heading' );
        $t = self::attrs( DiviMarkup::text( '<p>Big</p>', [ 'size' => $size ] ), 'text' );
        foreach ( [ [ $h, self::H ], [ $t, self::B ] ] as [ $attrs, $base ] ) {
            $this->assertSame( $size . 'px', self::at( $attrs, $base . 'desktop.value.size' ) );
            if ( null === $steps ) {
                $this->assertSame( 'ABSENT', self::at( $attrs, $base . 'tablet' ), $base . ' no tablet step' );
                $this->assertSame( 'ABSENT', self::at( $attrs, $base . 'phone' ), $base . ' no phone step' );
            } else {
                $this->assertSame( [ 'value' => [ 'size' => $steps[0] ] ], self::at( $attrs, $base . 'tablet' ), $base . ' tablet' );
                $this->assertSame( [ 'value' => [ 'size' => $steps[1] ] ], self::at( $attrs, $base . 'phone' ), $base . ' phone' );
            }
        }
    }

    /** @return array<string,array{0:\Closure}> */
    public static function badValues(): array
    {
        $bad = static fn ( string $name, \Closure $f ) => [ $name => [ $f ] ];

        return array_merge(
            $bad( 'short hex', static fn () => DiviMarkup::heading( 'x', 1, [ 'color' => '#fff' ] ) ),
            $bad( 'named colour', static fn () => DiviMarkup::heading( 'x', 1, [ 'color' => 'red' ] ) ),
            $bad( 'upper hex', static fn () => DiviMarkup::text( 'x', [ 'color' => '#AABBCC' ] ) ),
            $bad( 'rgb()', static fn () => DiviMarkup::text( 'x', [ 'color' => 'rgb(1,2,3)' ] ) ),
            $bad( 'colour with a tail', static fn () => DiviMarkup::section( '', '', [ 'background' => "#aabbcc\n" ] ) ),
            $bad( 'transparent off a button', static fn () => DiviMarkup::section( '', '', [ 'background' => 'rgba(255,255,255,0)' ] ) ),
            $bad( 'em padding', static fn () => DiviMarkup::section( '', '', [ 'padding' => [ 'top' => '12em' ] ] ) ),
            $bad( 'negative padding', static fn () => DiviMarkup::section( '', '', [ 'padding' => [ 'top' => '-1px' ] ] ) ),
            $bad( 'padding too big', static fn () => DiviMarkup::section( '', '', [ 'padding' => [ 'top' => '201px' ] ] ) ),
            $bad( 'padding no unit', static fn () => DiviMarkup::row( [ '' ], [ 'padding' => [ 'top' => '10' ] ] ) ),
            $bad( 'padding side unknown', static fn () => DiviMarkup::row( [ '' ], [ 'padding' => [ 'middle' => '10px' ] ] ) ),
            $bad( 'column padding em', static fn () => DiviMarkup::row( [ '' ], [], [ [ 'padding' => [ 'left' => '1.5em' ] ] ] ) ),
            $bad( 'column colour', static fn () => DiviMarkup::row( [ '' ], [], [ [ 'background' => 'blue' ] ] ) ),
            $bad( 'width 1000 as a number', static fn () => DiviMarkup::row( [ '' ], [ 'max_width' => 1000 ] ) ),
            $bad( 'width em', static fn () => DiviMarkup::row( [ '' ], [ 'max_width' => '40em' ] ) ),
            $bad( 'width below 200', static fn () => DiviMarkup::row( [ '' ], [ 'max_width' => '199px' ] ) ),
            $bad( 'width above 2400', static fn () => DiviMarkup::row( [ '' ], [ 'max_width' => '2401px' ] ) ),
            $bad( 'width five digits', static fn () => DiviMarkup::row( [ '' ], [ 'max_width' => '10000px' ] ) ),
            $bad( 'size 7', static fn () => DiviMarkup::heading( 'x', 1, [ 'size' => 7 ] ) ),
            $bad( 'size 201', static fn () => DiviMarkup::text( 'x', [ 'size' => 201 ] ) ),
            $bad( 'size 1000', static fn () => DiviMarkup::heading( 'x', 1, [ 'size' => 1000 ] ) ),
            $bad( 'size as a string', static fn () => DiviMarkup::heading( 'x', 1, [ 'size' => '12px' ] ) ),
            $bad( 'size 12em', static fn () => DiviMarkup::heading( 'x', 1, [ 'size' => '12em' ] ) ),
            $bad( 'size negative', static fn () => DiviMarkup::heading( 'x', 1, [ 'size' => -1 ] ) ),
            $bad( 'weight 450', static fn () => DiviMarkup::heading( 'x', 1, [ 'weight' => '450' ] ) ),
            $bad( 'weight bold', static fn () => DiviMarkup::text( 'x', [ 'weight' => 'bold' ] ) ),
            $bad( 'weight as an int', static fn () => DiviMarkup::text( 'x', [ 'weight' => 700 ] ) ),
            $bad( 'family with ;}', static fn () => DiviMarkup::heading( 'x', 1, [ 'family' => 'Inter;}' ] ) ),
            $bad( 'family with a quote', static fn () => DiviMarkup::text( 'x', [ 'family' => 'In"ter' ] ) ),
            $bad( 'family empty', static fn () => DiviMarkup::text( 'x', [ 'family' => '' ] ) ),
            $bad( 'family 61 characters', static fn () => DiviMarkup::text( 'x', [ 'family' => str_repeat( 'a', 61 ) ] ) ),
            $bad( 'family with a newline', static fn () => DiviMarkup::text( 'x', [ 'family' => "Inter\n" ] ) ),
            $bad( 'family Comic Sans MS', static fn () => DiviMarkup::heading( 'x', 1, [ 'family' => 'Comic Sans MS' ] ) ),
            $bad( 'family GEORGIA', static fn () => DiviMarkup::heading( 'x', 1, [ 'family' => 'GEORGIA' ] ) ),
            $bad( 'family georgia', static fn () => DiviMarkup::text( 'x', [ 'family' => 'georgia' ] ) ),
            $bad( 'family spaces only', static fn () => DiviMarkup::heading( 'x', 1, [ 'family' => '   ' ] ) ),
            $bad( 'family leading space', static fn () => DiviMarkup::heading( 'x', 1, [ 'family' => ' Inter' ] ) ),
            $bad( 'family trailing space', static fn () => DiviMarkup::heading( 'x', 1, [ 'family' => 'Inter ' ] ) ),
            $bad( 'family double space', static fn () => DiviMarkup::heading( 'x', 1, [ 'family' => 'Times  New Roman' ] ) ),
            $bad( 'family 0', static fn () => DiviMarkup::text( 'x', [ 'family' => '0' ] ) ),
            $bad( 'family lowercase google', static fn () => DiviMarkup::text( 'x', [ 'family' => 'inter' ] ) ),
            $bad( 'family not an Inter', static fn () => DiviMarkup::text( 'x', [ 'family' => 'Interr' ] ) ),
            $bad( 'family with a zero-width space', static fn () => DiviMarkup::text( 'x', [ 'family' => "Inter\u{200B}" ] ) ),
            $bad( 'family a hyphen', static fn () => DiviMarkup::text( 'x', [ 'family' => 'Inter-Bold' ] ) ),
            $bad( 'family an int', static fn () => DiviMarkup::text( 'x', [ 'family' => 0 ] ) ),
            $bad( 'padding leading zero', static fn () => DiviMarkup::row( [ '' ], [ 'padding' => [ 'top' => '0005px' ] ] ) ),
            $bad( 'padding 00px', static fn () => DiviMarkup::section( '', '', [ 'padding' => [ 'top' => '00px' ] ] ) ),
            $bad( 'max width leading zero', static fn () => DiviMarkup::row( [ '' ], [ 'max_width' => '0720px' ] ) ),
            $bad( 'column styles with a string key', static fn () => DiviMarkup::row( [ 'a' ], [], [ 'x' => [ 'background' => '#aabbcc' ] ] ) ),
            $bad( 'column styles starting at 1', static fn () => DiviMarkup::row( [ 'a', 'b' ], [], [ 1 => [ 'background' => '#aabbcc' ] ] ) ),
            $bad( 'column styles with a gap', static fn () => DiviMarkup::row( [ 'a', 'b', 'c' ], [], [ 0 => [], 2 => [ 'background' => '#aabbcc' ] ] ) ),
            $bad( 'column styles more than columns', static fn () => DiviMarkup::row( [ 'a' ], [], [ [], [ 'background' => '#aabbcc' ] ] ) ),
            $bad( 'column styles for a fifth column', static fn () => DiviMarkup::row( [ 'a', 'b', 'c', 'd', 'e' ], [], [ [], [], [], [], [ 'background' => '#aabbcc' ] ] ) ),
            $bad( 'column style null', static fn () => DiviMarkup::row( [ 'a' ], [], [ null ] ) ),
            $bad( 'column style a string', static fn () => DiviMarkup::row( [ 'a' ], [], [ 'background' ] ) ),
            $bad( 'column style an object', static fn () => DiviMarkup::row( [ 'a' ], [], [ (object) [ 'background' => '#aabbcc' ] ] ) ),
            $bad( 'column styles on an empty row', static fn () => DiviMarkup::row( [], [], [ [], [] ] ) ),
            $bad( 'align left', static fn () => DiviMarkup::heading( 'x', 1, [ 'align' => 'left' ] ) ),
            $bad( 'align junk', static fn () => DiviMarkup::text( 'x', [ 'align' => 'start' ] ) ),
            $bad( 'radius 1000', static fn () => DiviMarkup::button( 'x', null, false, [ 'radius' => 1000 ] ) ),
            $bad( 'radius negative', static fn () => DiviMarkup::button( 'x', null, false, [ 'radius' => -1 ] ) ),
            $bad( 'radius as a string', static fn () => DiviMarkup::button( 'x', null, false, [ 'radius' => '12px' ] ) ),
            $bad( 'button colour', static fn () => DiviMarkup::button( 'x', null, false, [ 'color' => 'white' ] ) ),
            $bad( 'button fill rgba other', static fn () => DiviMarkup::button( 'x', null, false, [ 'background' => 'rgba(0,0,0,0)' ] ) ),
            $bad( 'unknown heading key', static fn () => DiviMarkup::heading( 'x', 1, [ 'css' => 'color:red' ] ) ),
            $bad( 'unknown text key', static fn () => DiviMarkup::text( 'x', [ 'background' => '#aabbcc' ] ) ),
            $bad( 'unknown button key', static fn () => DiviMarkup::button( 'x', null, false, [ 'size' => 12 ] ) ),
            $bad( 'unknown section key', static fn () => DiviMarkup::section( '', '', [ 'max_width' => '700px' ] ) ),
            $bad( 'unknown row key', static fn () => DiviMarkup::row( [ '' ], [ 'color' => '#aabbcc' ] ) ),
            $bad( 'unknown column key', static fn () => DiviMarkup::row( [ '' ], [], [ [ 'max_width' => '700px' ] ] ) ),
        );
    }

    #[DataProvider('badValues')]
    public function testStyleValuesAreValidatedBeforeWriting( \Closure $call ): void
    {
        $this->expectException( \InvalidArgumentException::class );
        $call();
    }

    public function testEdgesOfTheClampsAreAccepted(): void
    {
        $page = DiviMarkup::heading( 'x', 1, [ 'size' => 8 ] ) . DiviMarkup::heading( 'x', 1, [ 'size' => 200 ] )
            . DiviMarkup::row( [ '' ], [ 'max_width' => '200px' ] ) . DiviMarkup::row( [ '' ], [ 'max_width' => '2400px' ] )
            . DiviMarkup::section( '', '', [ 'padding' => [ 'top' => '0px', 'bottom' => '200px' ] ] )
            . DiviMarkup::button( 'x', null, false, [ 'radius' => 0 ] ) . DiviMarkup::button( 'x', null, false, [ 'radius' => 999 ] )
            . DiviMarkup::text( 'x', [ 'weight' => '100', 'family' => 'Maven Pro' ] ) . DiviMarkup::text( 'x', [ 'weight' => '900' ] );
        $this->assertStringContainsString( '"maxWidth":"2400px"', $page );
    }

    /** A page of fully styled blocks. */
    public static function fullyStyledPage(): string
    {
        $pad  = [ 'top' => '64px', 'right' => '24px', 'bottom' => '64px', 'left' => '24px' ];
        $text = static fn ( string $html, string $align ) => DiviMarkup::text( $html, [ 'color' => '#334155', 'size' => 18, 'weight' => '400', 'align' => $align, 'family' => 'Inter' ] );

        return DiviMarkup::page( [
            DiviMarkup::section( 'Hero', DiviMarkup::row( [
                DiviMarkup::heading( 'Big', 1, [ 'color' => '#0f172a', 'size' => 56, 'weight' => '800', 'align' => 'center', 'family' => 'Playfair Display' ] )
                    . "\n" . $text( '<p>Hello</p>', 'center' ) . "\n" . DiviMarkup::button( 'Go', '/go', false, [ 'color' => '#ffffff', 'background' => '#0f172a', 'radius' => 8 ] ),
                $text( '<ul><li>One</li></ul>', 'right' ) . "\n" . $text( '<ol><li>One</li></ol>', 'justify' ) . "\n" . $text( '<blockquote><p>Q</p></blockquote>', 'center' )
                    . "\n" . DiviMarkup::heading( 'Sub', 2, [ 'color' => '#0f172a', 'size' => 24, 'weight' => '600', 'align' => 'justify', 'family' => 'Georgia' ] )
                    . "\n" . DiviMarkup::button( 'Ghost', null, false, [ 'color' => '#e2e8f0', 'background' => 'rgba(255,255,255,0)', 'radius' => 999 ] ),
            ], [ 'background' => '#ffffff', 'padding' => $pad, 'max_width' => '1080px' ], [
                [ 'background' => '#f1f5f9', 'padding' => $pad ],
                [ 'background' => '#e2e8f0', 'padding' => [ 'left' => '8px' ] ],
            ] ), [ 'background' => '#f8fafc', 'padding' => $pad ] ),
        ] );
    }

    public function testStyledMarkupIsValidAndHasNoCustomCode(): void
    {
        $page = self::fullyStyledPage();
        $r    = LayoutGate::check( $page );
        $this->assertTrue( $r->isValid(), json_encode( array_map( static fn ( $v ) => $v->toArray(), $r->violations() ) ) );
        $this->assertSame( [], CodePolicy::violations( $page ) );
        foreach ( [ '<style', 'style=', 'freeForm', 'mainElement', 'javascript:', 'expression(' ] as $needle ) {
            $this->assertStringNotContainsStringIgnoringCase( $needle, $page );
        }
    }

    public function testStyleIsDeterministic(): void
    {
        $this->assertSame( self::fullyStyledPage(), self::fullyStyledPage() );
    }

    public function testADecisionThatIsFalseIsNotWritten(): void
    {
        $none = array_fill_keys( array_keys( DiviMarkup::STYLE_PROVEN ), false );
        $asks = static fn () => [
            DiviMarkup::text( '<p>x</p>', [ 'align' => 'center' ] ),
            DiviMarkup::text( '<p>x</p>', [ 'align' => 'justify' ] ),
            DiviMarkup::heading( 'x', 1, [ 'align' => 'justify' ] ),
            DiviMarkup::row( [ '' ], [ 'max_width' => '700px', 'background' => '#aabbcc' ], [ [ 'background' => '#aabbcc', 'padding' => [ 'top' => '8px' ] ] ] ),
            DiviMarkup::section( '', '', [ 'padding' => [ 'top' => '8px' ] ] ),
            DiviMarkup::text( '<ul><li>x</li></ul>', [ 'color' => '#aabbcc' ] ),
            DiviMarkup::text( '<ol><li>x</li></ol>', [ 'color' => '#aabbcc' ] ),
            DiviMarkup::text( '<blockquote><p>x</p></blockquote>', [ 'color' => '#aabbcc' ] ),
        ];
        $out = implode( "\n", ProvenOverride::run( $none, $asks ) );
        foreach ( [ 'textAlign', 'orientation', 'family', 'maxWidth', 'background', 'spacing', 'color' ] as $leaf ) {
            $this->assertStringNotContainsString( '"' . $leaf . '"', $out, 'with every decision false, ' . $leaf . ' is not written' );
        }
        // Each decision alone turns on only its own path.
        $only = static fn ( string $key ) => array_merge( $none, [ $key => true ] );
        $text = static fn ( array $p, array $style, string $html = '<p>x</p>' ) => ProvenOverride::run( $p, static fn () => DiviMarkup::text( $html, $style ) );
        $this->assertStringContainsString( '"orientation":"center"', $text( $only( 'text-align-orientation' ), [ 'align' => 'center' ] ) );
        $this->assertStringNotContainsString( 'textAlign', $text( $only( 'text-align-orientation' ), [ 'align' => 'center' ] ) );
        $this->assertStringContainsString( '"textAlign":"center"', $text( $only( 'text-align-bodyfont' ), [ 'align' => 'center' ] ) );
        $this->assertStringNotContainsString( 'orientation', $text( $only( 'text-align-bodyfont' ), [ 'align' => 'center' ] ) );
        $both = array_merge( $none, [ 'text-align-orientation' => true, 'text-align-bodyfont' => true ] );
        $this->assertStringContainsString( 'orientation', $text( $both, [ 'align' => 'right' ] ), 'orientation first' );
        $this->assertStringNotContainsString( 'textAlign', $text( $both, [ 'align' => 'right' ] ) );
        $this->assertStringContainsString( '"textAlign":"justify"', $text( $only( 'text-justify' ), [ 'align' => 'justify' ] ) );
        $this->assertStringNotContainsString( 'orientation', $text( $only( 'text-justify' ), [ 'align' => 'justify' ] ), 'orientation justified is DEAD: never written' );
        $this->assertStringContainsString( '#aabbcc', $text( $only( 'list-ink-ul' ), [ 'color' => '#aabbcc' ], '<ul><li>x</li></ul>' ) );
        $this->assertStringNotContainsString( '#aabbcc', $text( $only( 'list-ink-ol' ), [ 'color' => '#aabbcc' ], '<ul><li>x</li></ul>' ) );
        $this->assertStringContainsString( '#aabbcc', $text( $only( 'quote-ink' ), [ 'color' => '#aabbcc' ], '<blockquote><p>x</p></blockquote>' ) );
        $this->assertStringContainsString( '#aabbcc', $text( $none, [ 'color' => '#aabbcc' ] ), 'a plain paragraph colour needs no decision' );
        $this->assertStringContainsString( '"family":"Georgia"', ProvenOverride::run( $only( 'websafe-family' ), static fn () => DiviMarkup::heading( 'x', 1, [ 'family' => 'Georgia' ] ) ) );
        foreach ( [ 'Georgia', 'Courier New', 'Times New Roman' ] as $family ) {
            foreach ( [ static fn () => DiviMarkup::heading( 'x', 1, [ 'family' => $family ] ), static fn () => DiviMarkup::text( 'x', [ 'family' => $family ] ) ] as $call ) {
                try {
                    ProvenOverride::run( $none, $call );
                    $this->fail( $family . ' is a web-safe family: with websafe-family false it must throw, not be written' );
                } catch ( \InvalidArgumentException ) {
                    $this->addToAssertionCount( 1 );
                }
            }
        }
        $this->assertStringContainsString( '"family":"Inter"', ProvenOverride::run( $none, static fn () => DiviMarkup::heading( 'x', 1, [ 'family' => 'Inter' ] ) ), 'a Google family needs no decision' );
        $this->assertStringContainsString( '"maxWidth":"700px"', ProvenOverride::run( $only( 'row-max-width' ), static fn () => DiviMarkup::row( [ '' ], [ 'max_width' => '700px' ] ) ) );
        $this->assertStringContainsString( '#aabbcc', ProvenOverride::run( $only( 'row-background' ), static fn () => DiviMarkup::row( [ '' ], [ 'background' => '#aabbcc' ] ) ) );
        $col = ProvenOverride::run( $only( 'column-background' ), static fn () => DiviMarkup::row( [ '' ], [], [ [ 'background' => '#aabbcc', 'padding' => [ 'top' => '8px' ] ] ] ) );
        $this->assertStringContainsString( '#aabbcc', $col );
        $this->assertStringNotContainsString( 'spacing', $col, 'column padding is its own decision' );
        $this->assertStringContainsString( '"top":"8px"', ProvenOverride::run( $only( 'column-padding' ), static fn () => DiviMarkup::row( [ '' ], [], [ [ 'padding' => [ 'top' => '8px' ] ] ] ) ) );
        $this->assertStringContainsString( '"top":"8px"', ProvenOverride::run( $only( 'section-padding' ), static fn () => DiviMarkup::section( '', '', [ 'padding' => [ 'top' => '8px' ] ] ) ) );
        $this->assertSame( DiviMarkup::row( [ '' ] ), ProvenOverride::run( $none, static fn () => DiviMarkup::row( [ '' ], [ 'max_width' => '700px', 'background' => '#aabbcc' ] ) ), 'every gate shut gives today\'s row' );
    }

    /** @return array<string,array{0:string}> */
    public static function goodFamilies(): array
    {
        return [ 'Inter' => [ 'Inter' ], 'Georgia' => [ 'Georgia' ], 'Maven Pro' => [ 'Maven Pro' ], 'Playfair Display' => [ 'Playfair Display' ], 'Trebuchet MS' => [ 'Trebuchet MS' ], 'Noto Sans JP' => [ 'Noto Sans JP' ] ];
    }

    #[DataProvider('goodFamilies')]
    public function testACanonicalFamilyIsWritten( string $family ): void
    {
        $this->assertStringContainsString( '"family":"' . $family . '"', DiviMarkup::heading( 'x', 1, [ 'family' => $family ] ) );
        $this->assertStringContainsString( '"family":"' . $family . '"', DiviMarkup::text( 'x', [ 'family' => $family ] ) );
    }

    public function testNoDeadPathIsEverWritten(): void
    {
        // module.advanced.text.text.*.value.orientation = justified is DEAD on divi/text (measured): a justified text uses the body font.
        $none = array_fill_keys( array_keys( DiviMarkup::STYLE_PROVEN ), false );
        $all  = DiviMarkup::STYLE_PROVEN;
        $only = array_merge( $none, [ 'text-align-orientation' => true, 'text-justify' => true ] );
        foreach ( [ $all, $only, array_merge( $all, [ 'text-align-bodyfont' => false ] ), array_merge( $all, [ 'text-align-orientation' => false ] ) ] as $proven ) {
            $out = ProvenOverride::run( $proven, static fn () => DiviMarkup::text( '<p>x</p>', [ 'align' => 'justify' ] ) . DiviMarkup::text( '<p>x</p>', [ 'align' => 'center' ] ) . DiviMarkup::text( '<p>x</p>', [ 'align' => 'right' ] ) );
            $this->assertStringNotContainsString( 'justified', $out );
        }
        $this->assertStringNotContainsString( '"orientation":"justified"', self::fullyStyledPage() );
    }

    public function testTheOverrideIsRestoredEvenWhenTheCallThrows(): void
    {
        $before = DiviMarkup::text( '<p>x</p>', [ 'align' => 'center' ] );
        try {
            ProvenOverride::run( array_fill_keys( array_keys( DiviMarkup::STYLE_PROVEN ), false ), static function (): void {
                throw new \RuntimeException( 'x' );
            } );
        } catch ( \RuntimeException ) {
        }
        $this->assertSame( $before, DiviMarkup::text( '<p>x</p>', [ 'align' => 'center' ] ) );
        $this->expectException( \InvalidArgumentException::class );
        ProvenOverride::run( [ 'text-align-orientation' => true ], static fn () => '' );
    }
}
