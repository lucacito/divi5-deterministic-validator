<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HtmlFonts;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/HtmlFonts.php';

final class HtmlFontsTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/shapes/google-fonts-names.txt';
    private const SHIPPED = __DIR__ . '/../wp-plugin/data/google-fonts-names.txt';

    /** The ' Pro' family names Divi's Google list contains (the only lines the commercial-word scan matches). */
    private const PRO_FAMILIES = [
        'Advent Pro', 'Andada Pro', 'Anonymous Pro', 'Be Vietnam Pro', 'Crimson Pro', 'Kantumruy Pro', 'Kdam Thmor Pro',
        'Maven Pro', 'Readex Pro', 'SN Pro', 'Source Code Pro', 'Tai Heritage Pro',
    ];

    public function testTheShippedFontListEqualsTheFixture(): void
    {
        $this->assertFileExists( self::SHIPPED );
        $this->assertSame( sha1_file( self::FIXTURE ), sha1_file( self::SHIPPED ) );
        $lines = explode( "\n", rtrim( (string) file_get_contents( self::SHIPPED ), "\n" ) );
        $this->assertCount( 1945, $lines );
        $this->assertNotContains( '', $lines, 'no empty line' );
        $sorted = $lines;
        sort( $sorted, SORT_STRING );
        $this->assertSame( $sorted, $lines, 'sorted' );
        $this->assertCount( 1945, HtmlFonts::google() );
    }

    public function testIsKnownIsAnExactCaseGoogleMembership(): void
    {
        foreach ( [ 'Inter', 'Maven Pro', 'Playfair Display', 'Noto Sans JP' ] as $name ) {
            $this->assertTrue( HtmlFonts::isKnown( $name ), $name );
        }
        foreach ( [ 'inter', 'INTER', 'Inter ', ' Inter', '', '0', 'Comic Sans MS', 'Georgia', 'Brand Sans', 'maven pro' ] as $name ) {
            $this->assertFalse( HtmlFonts::isKnown( $name ), json_encode( $name ) );
        }
    }

    public function testOnlyGoogleOrProvenWebSafeFamiliesAreChosen(): void
    {
        $r = HtmlFonts::choose( [ 'Brand Sans', 'inter', 'sans-serif' ] );
        $this->assertSame( 'Inter', $r['family'] );
        $this->assertSame( [
            [ 'source' => 'Brand Sans', 'action' => 'not available in Divi: used Inter, the next family in the list' ],
            [ 'source' => 'Inter', 'action' => 'carried' ],
        ], $r['fonts'] );

        $r = HtmlFonts::choose( [ 'PLAYFAIR DISPLAY' ] );
        $this->assertSame( 'Playfair Display', $r['family'] );
        $this->assertSame( [ [ 'source' => 'Playfair Display', 'action' => 'carried' ] ], $r['fonts'] );

        $r = HtmlFonts::choose( [ 'sans-serif' ] );
        $this->assertNull( $r['family'] );
        $this->assertSame( [], $r['fonts'] );
        $this->assertSame( 'generic_font', $r['reason'] );

        // The web-safe set is used only when its decision is proven.
        $off = HtmlFonts::choose( [ 'Georgia' ], false );
        $this->assertNull( $off['family'] );
        $this->assertSame( [ [ 'source' => 'Georgia', 'action' => 'not available in Divi: kept the theme font' ] ], $off['fonts'] );
        $on = HtmlFonts::choose( [ 'georgia', 'serif' ], true );
        $this->assertSame( 'Georgia', $on['family'] );
        $this->assertSame( [ [ 'source' => 'Georgia', 'action' => 'carried' ] ], $on['fonts'] );
        $this->assertSame( 'Times New Roman', HtmlFonts::choose( [ 'times new roman' ], true )['family'] );

        // With no second argument the proven decision of DiviMarkup decides (false while that constant does not exist).
        $proven = false;
        if ( defined( 'AiEditorDivi5\WP\DiviMarkup::STYLE_PROVEN' ) ) {
            $proven = ( \constant( 'AiEditorDivi5\WP\DiviMarkup::STYLE_PROVEN' )['websafe-family'] ?? false ) === true;
        }
        $this->assertSame( $proven ? 'Georgia' : null, HtmlFonts::choose( [ 'Georgia' ] )['family'] );
    }

    public function testGenericKeywordsAreNeverCarriedOrListedAndNothingChosenKeepsTheThemeFont(): void
    {
        foreach ( HtmlFonts::GENERIC as $keyword ) {
            $r = HtmlFonts::choose( [ strtoupper( $keyword ) ], true );
            $this->assertNull( $r['family'], $keyword );
            $this->assertSame( [], $r['fonts'], $keyword );
        }
        $r = HtmlFonts::choose( [ 'Brand Sans', 'Other Brand', 'serif', '' ], true );
        $this->assertNull( $r['family'] );
        $this->assertSame( [
            [ 'source' => 'Brand Sans', 'action' => 'not available in Divi: kept the theme font' ],
            [ 'source' => 'Other Brand', 'action' => 'not available in Divi: kept the theme font' ],
        ], $r['fonts'] );
        $this->assertSame( 'generic_font', $r['reason'] );
        $this->assertSame( [ 'family' => null, 'fonts' => [], 'reason' => 'generic_font' ], HtmlFonts::choose( [] ) );
        $r = HtmlFonts::choose( [ 'Brand Sans' ] );
        $this->assertSame( 'font_unavailable', $r['reason'] );
    }

    public function testAGenericOrSystemKeywordEndsTheSearch(): void
    {
        // A browser always matches a generic keyword (a system one on the systems that have it): a later family is never shown.
        $bootstrap = HtmlFonts::choose( [ 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif' ], true );
        $this->assertSame( [ 'family' => null, 'fonts' => [], 'reason' => 'generic_font' ], $bootstrap );
        $this->assertSame( [ 'family' => null, 'fonts' => [], 'reason' => 'generic_font' ], HtmlFonts::choose( [ 'sans-serif', 'Roboto' ], true ) );
        $this->assertSame( [ 'family' => null, 'fonts' => [], 'reason' => 'generic_font' ], HtmlFonts::choose( [ 'BlinkMacSystemFont', 'Inter' ] ) );
        $r = HtmlFonts::choose( [ 'Brand Sans', 'serif', 'Montserrat' ] );
        $this->assertNull( $r['family'] );
        $this->assertSame( 'generic_font', $r['reason'] );
        $this->assertSame( [ [ 'source' => 'Brand Sans', 'action' => 'not available in Divi: kept the theme font' ] ], $r['fonts'] );

        // A loadable family BEFORE the keyword still wins, and so does the next loadable one after an unknown name.
        $r = HtmlFonts::choose( [ 'Inter', 'sans-serif' ] );
        $this->assertSame( 'Inter', $r['family'] );
        $this->assertNull( $r['reason'] );
        $r = HtmlFonts::choose( [ 'Brand Sans', 'Montserrat' ] );
        $this->assertSame( 'Montserrat', $r['family'] );
        $this->assertNull( $r['reason'] );
    }

    public function testACssWideKeywordIsUnresolvedNotAFamily(): void
    {
        foreach ( HtmlFonts::CSS_WIDE as $keyword ) {
            foreach ( [ [ $keyword ], [ 'Inter', strtoupper( $keyword ) ], [ $keyword, 'Roboto' ] ] as $list ) {
                $this->assertSame( [ 'family' => null, 'fonts' => [], 'reason' => 'unresolved' ], HtmlFonts::choose( $list, true ), json_encode( $list ) );
            }
        }
        foreach ( [ 'inherit', 'initial', 'unset', 'revert', 'revert-layer' ] as $keyword ) {
            $this->assertContains( $keyword, HtmlFonts::CSS_WIDE );
            $this->assertNotContains( $keyword, HtmlFonts::GENERIC );
        }
    }

    public function testTheFirstLoadableFamilyWinsAndLaterOnesAreNotListed(): void
    {
        $r = HtmlFonts::choose( [ 'Roboto', 'Open Sans', 'Brand' ] );
        $this->assertSame( 'Roboto', $r['family'] );
        $this->assertSame( [ [ 'source' => 'Roboto', 'action' => 'carried' ] ], $r['fonts'] );
    }

    public function testAMissingListNeverFailsTheConverter(): void
    {
        $r = HtmlFonts::choose( [ 'Inter', 'Arial' ], true, HtmlFonts::parse( @file_get_contents( '/nonexistent/google-fonts-names.txt' ) ) );
        $this->assertSame( 'Arial', $r['family'] );
        $this->assertSame( [ [ 'source' => 'Inter', 'action' => 'not available in Divi: used Arial, the next family in the list' ], [ 'source' => 'Arial', 'action' => 'carried' ] ], $r['fonts'] );
        $this->assertSame( [], HtmlFonts::parse( false ) );
    }

    public function testTheFontListIsTheOnlyNewCommercialWordException(): void
    {
        $src = (string) file_get_contents( __DIR__ . '/WpOrgComplianceTest.php' );
        $this->assertSame( 1, preg_match_all( "/^\s*\[\s*'data\/google-fonts-names\.txt',\s*' Pro',/m", $src ), 'exactly one COMMERCIAL_EXCEPTIONS row names the font list, scoped to " Pro"' );

        preg_match( "/COMMERCIAL_WORDS = '([^']+)'/", $src, $m );
        $this->assertNotEmpty( $m );
        $hits = [];
        foreach ( explode( "\n", rtrim( (string) file_get_contents( self::SHIPPED ), "\n" ) ) as $line ) {
            if ( preg_match( $m[1], $line ) ) {
                $hits[] = $line;
                $this->assertStringEndsWith( ' Pro', $line, 'a commercial-word hit that is not a " Pro" family name' );
            }
        }
        $this->assertSame( self::PRO_FAMILIES, $hits );
    }
}
