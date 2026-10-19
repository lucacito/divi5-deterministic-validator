<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DiviMarkup;
use Divi5Validator\Validator;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';

/** The emitter: serialisation like WordPress's, and every attribute path grounded in a real export or recipe. */
final class DiviMarkupTest extends HtmlTestCase
{
    /** @var ?array<string,array<string,true>> block name => attribute paths seen in real exports and recipes */
    private static ?array $known = null;

    /** @return array<string,array<string,true>> */
    private static function known(): array
    {
        if ( null !== self::$known ) {
            return self::$known;
        }
        $sources = [];
        foreach ( (array) glob( __DIR__ . '/../fixtures/valid/*.json' ) as $f ) {
            $d = json_decode( (string) file_get_contents( $f ), true );
            if ( is_array( $d ) && is_string( $d['post_content'] ?? null ) ) {
                $sources[] = $d['post_content'];
            }
        }
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $r ) {
            // As served: every style kit's markers resolved (a kit marker is not a Divi attribute and grounds nothing).
            foreach ( \AiEditorDivi5\WP\StyleKits::ids() as $kit ) {
                $sources[] = \AiEditorDivi5\WP\KitResolver::resolve( (string) ( $r['markup'] ?? '' ), (array) \AiEditorDivi5\WP\StyleKits::get( $kit ) );
            }
        }
        $known = [];
        foreach ( $sources as $content ) {
            if ( 0 < preg_match_all( '/<!-- wp:divi\/([a-z0-9-]+) (\{.*?\}) \/?-->/s', $content, $m, PREG_SET_ORDER ) ) {
                foreach ( $m as $block ) {
                    $attrs = json_decode( $block[2], true );
                    if ( is_array( $attrs ) ) {
                        foreach ( self::paths( $attrs ) as $path ) {
                            $known[ $block[1] ][ $path ] = true;
                        }
                    }
                }
            }
        }

        return self::$known = $known;
    }

    /** Dot paths of every key, leaf or not; list indexes are folded into []. @return list<string> */
    private static function paths( array $a, string $prefix = '' ): array
    {
        $out = [];
        foreach ( $a as $k => $v ) {
            $key   = is_int( $k ) ? '[]' : (string) $k;
            $path  = '' === $prefix ? $key : $prefix . '.' . $key;
            $out[] = $path;
            if ( is_array( $v ) ) {
                array_push( $out, ...self::paths( $v, $path ) );
            }
        }

        return $out;
    }

    /** @return array<string,array{0:string,1:string}> block name => sample markup */
    public static function samples(): array
    {
        return [
            'section'     => [ 'section', DiviMarkup::section( 'Hero', '' ) ],
            'section bare' => [ 'section', DiviMarkup::section( '', '' ) ],
            'row 1'       => [ 'row', DiviMarkup::row( [ '' ] ) ],
            'row 2'       => [ 'row', DiviMarkup::row( [ '', '' ] ) ],
            'row 3'       => [ 'row', DiviMarkup::row( [ '', '', '' ] ) ],
            'row 4'       => [ 'row', DiviMarkup::row( [ '', '', '', '' ] ) ],
            'column'      => [ 'column', DiviMarkup::row( [ '', '' ] ) ],
            'heading'     => [ 'heading', DiviMarkup::heading( 'Title', 2 ) ],
            'text'        => [ 'text', DiviMarkup::text( '<p>Hi</p>' ) ],
            'image'       => [ 'image', DiviMarkup::image( 'https://example.com/a.png', 'Alt', null ) ],
            'image link'  => [ 'image', DiviMarkup::image( 'https://example.com/a.png', 'Alt', '/go' ) ],
            'button'      => [ 'button', DiviMarkup::button( 'Go', 'https://example.com', false ) ],
            'button tab'  => [ 'button', DiviMarkup::button( 'Go', 'https://example.com', true ) ],
            'button bare' => [ 'button', DiviMarkup::button( 'Go', null, false ) ],
            'divider'     => [ 'divider', DiviMarkup::divider() ],
        ];
    }

    #[DataProvider('samples')]
    public function testEveryAttributePathExistsInARealExportOrRecipe( string $block, string $markup ): void
    {
        $this->assertSame( 1, preg_match_all( '/<!-- wp:divi\/' . $block . ' (\{.*?\}) \/?-->/s', $markup, $m, PREG_SET_ORDER ) >= 1 ? 1 : 0, 'the sample holds a ' . $block );
        $attrs = json_decode( $m[0][1], true );
        $this->assertIsArray( $attrs );
        $known = self::known()[ $block ] ?? [];
        $this->assertNotSame( [], $known, 'no real export or recipe holds a ' . $block . ' to compare with' );
        foreach ( self::paths( $attrs ) as $path ) {
            $this->assertArrayHasKey( $path, $known, 'the path ' . $block . ' ' . $path . ' does not appear in any real export or recipe' );
        }
    }

    public function testTheRootWrapperIsThePlaceholder(): void
    {
        $page = DiviMarkup::page( [ DiviMarkup::section( '', '' ) ] );
        $this->assertStringStartsWith( '<!-- wp:divi/placeholder -->', $page );
        $this->assertStringEndsWith( '<!-- /wp:divi/placeholder -->', $page );
    }

    public function testAttributesAreSerialisedLikeWordPress(): void
    {
        $bs   = '\\'; // one backslash
        $json = DiviMarkup::attributes( [ 'a' => '<p class="x">Tom & Jerry -- “é” / ok</p>' ] );
        $expected = '{"a":"' . $bs . 'u003cp class=' . $bs . 'u0022x' . $bs . 'u0022' . $bs . 'u003eTom ' . $bs . 'u0026 Jerry ' . $bs . 'u002d' . $bs . 'u002d “é” / ok' . $bs . 'u003c/p' . $bs . 'u003e"}';
        $this->assertSame( $expected, $json );
        $this->assertSame( [ 'a' => '<p class="x">Tom & Jerry -- “é” / ok</p>' ], json_decode( $json, true ), 'and it decodes back to the same text' );
        $this->assertStringNotContainsString( '-->', $json );
        $this->assertStringNotContainsString( '<', $json );
    }

    /** @return array<string,array{0:string}> */
    public static function awkwardStrings(): array
    {
        $bs = '\\';

        return [
            'trailing backslash'   => [ 'ends with a backslash' . $bs ],
            'backslash then quote' => [ 'a' . $bs . '"b' ],
            'two backslashes'      => [ $bs . $bs ],
            'only a quote'         => [ '"' ],
            'quote at the end'     => [ 'say "hi"' ],
            'looks like an escape' => [ $bs . 'u0022' . $bs . 'u003c' ],
            'dashes'               => [ 'a -- b --- c ---- d --' ],
            'markup'               => [ '<a href="x">&amp;</a>' ],
            'unicode'              => [ "日本語 🎉 é" ],
            'slashes'              => [ 'a/b//c' ],
            'control characters'   => [ "tab\there\nnewline" ],
        ];
    }

    #[DataProvider('awkwardStrings')]
    public function testAnyStringSurvivesARoundTripThroughABlockComment( string $text ): void
    {
        foreach ( [ 'text' => DiviMarkup::text( $text ), 'heading' => DiviMarkup::heading( $text, 2 ), 'section' => DiviMarkup::section( $text, '' ), 'button' => DiviMarkup::button( $text, $text, false ) ] as $kind => $md ) {
            $this->assertSame( 1, preg_match( '/<!-- wp:divi\/[a-z]+ (\{.*\}) \/?-->/s', $md, $m ), $kind . ': one well-formed comment: ' . $md );
            $decoded = json_decode( $m[1], true );
            $this->assertIsArray( $decoded, $kind . ': the attributes are valid JSON: ' . $m[1] );
            $this->assertContains( $text, self::strings( $decoded ), $kind . ': the text comes back exactly' );
            $this->assertSame( 'section' === $kind ? 2 : 1, substr_count( $md, '-->' ), $kind . ': no extra comment ends' );
        }
        $page = DiviMarkup::page( [ DiviMarkup::section( 'S', DiviMarkup::row( [ DiviMarkup::heading( $text, 2 ) . "\n" . DiviMarkup::text( $text ) ] ) ) ] );
        $r = ( new Validator() )->validateContent( $page );
        $this->assertSame( [], array_map( static fn( $v ) => $v->toArray(), $r->violations() ), 'the validator reads it' );
    }

    /** Every string leaf of a decoded structure. @return list<string> */
    private static function strings( array $a ): array
    {
        $out = [];
        array_walk_recursive( $a, static function ( $v ) use ( &$out ): void {
            if ( is_string( $v ) ) {
                $out[] = $v;
            }
        } );

        return $out;
    }

    public function testNothingCanBreakOutOfTheBlockComment(): void
    {
        foreach ( [ '--> <script>alert(1)</script>', '<!-- x -->', '"}} --> <!-- wp:divi/code {"x":"', "\u{2028}\u{2029}", "\0" ] as $evil ) {
            $md = DiviMarkup::text( $evil );
            $this->assertSame( 1, substr_count( $md, '-->' ), 'one comment end per block, got: ' . $md );
            $this->assertSame( 1, substr_count( $md, '<!--' ) );
            $this->assertStringNotContainsString( '<script', $md );
        }
    }

    public function testTheEmittedMarkupIsValidForTheValidator(): void
    {
        $page = DiviMarkup::page( [
            DiviMarkup::section( 'A', implode( "\n", [
                DiviMarkup::row( [ DiviMarkup::heading( 'Title', 1 ) . "\n" . DiviMarkup::text( '<p>Body</p>' ) ] ),
                DiviMarkup::row( [ DiviMarkup::image( 'https://example.com/a.png', 'a', null ), DiviMarkup::button( 'Go', '/x', true ) ] ),
                DiviMarkup::row( [ DiviMarkup::divider(), DiviMarkup::heading( 'Three', 3 ), DiviMarkup::text( '<p>x</p>' ) ] ),
            ] ) ),
        ] );
        $r = ( new Validator() )->validateContent( $page );
        $this->assertSame( [], array_map( static fn( $v ) => $v->toArray(), $r->violations() ) );
    }

    public function testRowsNeverHaveMoreThanFourOrFewerThanOneColumn(): void
    {
        $this->assertSame( 4, substr_count( DiviMarkup::row( array_fill( 0, 9, '' ) ), '<!-- wp:divi/column ' ) );
        $this->assertSame( 1, substr_count( DiviMarkup::row( [] ), '<!-- wp:divi/column ' ), 'an empty row still has one column' );
    }

    public function testHeadingLevelsAreClamped(): void
    {
        $this->assertStringContainsString( '"headingLevel":"h6"', DiviMarkup::heading( 'x', 9 ) );
        $this->assertStringContainsString( '"headingLevel":"h1"', DiviMarkup::heading( 'x', 0 ) );
    }

    public function testALinkWithoutATargetNeverGetsATab(): void
    {
        $this->assertStringNotContainsString( 'linkTarget', DiviMarkup::button( 'x', null, true ) );
        $this->assertStringContainsString( '"linkTarget":"on"', DiviMarkup::button( 'x', '/a', true ) );
    }
}
