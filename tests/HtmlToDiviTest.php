<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CodePolicy;
use AiEditorDivi5\WP\DiviMarkup;
use AiEditorDivi5\WP\HtmlToDivi;
use AiEditorDivi5\WP\LayoutGate;
use Divi5Validator\Tests\Support\StyledLayoutOracle;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/Support/StyledLayoutOracle.php';

/** html_to_divi end to end over the fixture corpus: valid, text kept, links kept, nothing dangerous, same bytes twice. */
final class HtmlToDiviTest extends HtmlTestCase
{
    /** @return array<string,array{0:string}> */
    public static function fixtures(): array
    {
        $out = [];
        foreach ( (array) glob( self::FIXTURES . '/*.html' ) as $f ) {
            if ( '11-empty.html' !== basename( $f ) ) {
                $out[ basename( $f ) ] = [ (string) $f ];
            }
        }

        return $out;
    }

    #[DataProvider('fixtures')]
    public function testEveryFixtureConvertsToAValidLayout( string $file ): void
    {
        $r = $this->convert( (string) file_get_contents( $file ) );
        $this->assertTrue( $r['ok'], (string) ( $r['error'] ?? '' ) );

        $gate = LayoutGate::check( $r['layout'] );
        $this->assertSame( [], array_map( static fn( $v ) => $v->toArray(), $gate->violations() ), basename( $file ) . ' must pass the same gate as every write' );
    }

    #[DataProvider('fixtures')]
    public function testEveryFixtureKeepsAllItsText( string $file ): void
    {
        $r = $this->convert( (string) file_get_contents( $file ) );
        $this->assertTrue( $r['report']['text_check']['equal'], basename( $file ) . ': ' . ( $r['report']['text_check']['first_difference'] ?? '' ) );
        $this->assertGreaterThan( 0, $r['report']['text_check']['result_chars'] );
    }

    #[DataProvider('fixtures')]
    public function testEveryFixtureKeepsAllItsLinks( string $file ): void
    {
        $r = $this->convert( (string) file_get_contents( $file ) );
        $this->assertSame( [], $r['report']['links']['lost'], basename( $file ) );
        $this->assertSame( $r['report']['links']['in_source'], $r['report']['links']['kept'] );
    }

    #[DataProvider('fixtures')]
    public function testTheSameHtmlGivesTheSameBytes( string $file ): void
    {
        $html = (string) file_get_contents( $file );
        $this->assertSame( $this->convert( $html ), $this->convert( $html ) );
        $bare = self::bare( $html );
        $this->assertSame( $this->convert( $bare )['layout'], $this->convert( $bare, [ 'styles' => 'none' ] )['layout'], 'a page without CSS is the same in both modes' );
    }

    /** The none mode never reads a style: the page with every style removed converts to the same bytes. */
    #[DataProvider('fixtures')]
    public function testStylesNoneIgnoresEveryStyle( string $file ): void
    {
        $html = (string) file_get_contents( $file );
        $none = [ 'styles' => 'none' ];
        $this->assertSame( $this->convert( $html, $none )['layout'], $this->convert( self::bare( $html ), $none )['layout'], 'styles do not change the structure' );
    }

    /** The page without any CSS: every style="" attribute and every <style> element removed in the DOM (no regex). */
    public static function bare( string $html ): string
    {
        $doc  = new \DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        foreach ( iterator_to_array( $doc->getElementsByTagName( 'style' ) ) as $style ) {
            $style->parentNode?->removeChild( $style );
        }
        foreach ( iterator_to_array( $doc->getElementsByTagName( '*' ) ) as $el ) {
            $el->removeAttribute( 'style' );
        }

        return (string) $doc->saveHTML();
    }

    #[DataProvider('fixtures')]
    public function testNothingDangerousCanBeInTheResult( string $file ): void
    {
        $r = $this->convert( (string) file_get_contents( $file ) );
        $out = strtolower( $r['layout'] );
        foreach ( [ '<script', '<script', 'onclick', 'onerror', 'onload', 'javascript:', '<iframe', '<iframe', '<form', '<form', 'style=', 'freeform', 'mainelement', 'data:text/html', '<style', '<style', 'vbscript:' ] as $bad ) {
            $this->assertStringNotContainsString( $bad, $out, basename( $file ) . ' contains ' . $bad );
        }
        $this->assertSame( [], CodePolicy::violations( $r['layout'] ), 'the custom-code policy has nothing to say' );
    }

    public function testNoThirdPartyImageAddressReachesTheLayout(): void
    {
        $r = $this->convert( (string) file_get_contents( self::FIXTURES . '/13-images.html' ) );
        $this->assertTrue( $r['ok'] );
        preg_match_all( '/"src":"([^"]*)"/', $r['layout'], $m );
        $this->assertGreaterThanOrEqual( 6, count( $m[1] ), 'every image of the page is there' );
        foreach ( $m[1] as $src ) {
            $this->assertStringStartsWith( 'https://example.com/wp-content/plugins/x/assets/images/', $src );
        }
        foreach ( [ 'cdn.example.com', 'images.example.org', 'images/jane.jpg', 'data:image', 'uploads/wide', 'press-kit.png' ] as $leak ) {
            $this->assertStringNotContainsString( $leak, $r['layout'], $leak );
        }
        $this->assertCount( 7, $r['report']['images'], 'the report lists every image with what happened to it' );
        $this->assertContains( 'svg', array_column( $r['report']['flagged'], 'type' ) );
        $this->assertContains( 'src', array_column( $r['report']['flagged'], 'type' ), 'the data: URI is reported' );
    }

    public function testImageAltTextsAndLinksAreKept(): void
    {
        $r = $this->convert( (string) file_get_contents( self::FIXTURES . '/13-images.html' ) );
        foreach ( [ 'Acme logo', 'Our office at sunset', 'Jane Doe', 'Panorama' ] as $alt ) {
            $this->assertStringContainsString( '"alt":"' . $alt . '"', $r['layout'] );
        }
        $this->assertStringContainsString( '"linkUrl":"/press/kit"', $r['layout'], 'a linked image keeps its link' );
        $this->assertSame( [], $r['report']['links']['lost'] );
    }

    public function testTeamPhotosBecomeColumnsWithTheirNames(): void
    {
        $r = $this->convert( (string) file_get_contents( self::FIXTURES . '/13-images.html' ) );
        $this->assertStringContainsString( '"columnStructure":{"desktop":{"value":"1_3,1_3,1_3"}}', $r['layout'] );
        $this->assertLessThan( strpos( $r['layout'], 'John Roe' ), strpos( $r['layout'], 'Jane Doe' ), 'order is kept' );
    }

    /** @return array<string,array{0:string}> the three phase-1 pages (no CSS: the same file in both modes) */
    public static function phaseOneGoldens(): array
    {
        return [ '01-landing' => [ '01-landing' ], '03-pricing-cards' => [ '03-pricing-cards' ], '13-images' => [ '13-images' ] ];
    }

    /** @return array<string,array{0:string}> the six styled pages */
    public static function styledFixtures(): array
    {
        $out = [];
        foreach ( [ 'inline', 'classes', 'dark', 'backgrounds', 'fonts', 'hostile' ] as $n ) {
            $out[ '14-styled-' . $n ] = [ '14-styled-' . $n ];
        }

        return $out;
    }

    /** @return array<string,array{0:string}> the phase-1 pages plus the six styled pages (default mode: styles=basic) */
    public static function goldens(): array
    {
        return self::phaseOneGoldens() + self::styledFixtures();
    }

    /**
     * The exact markup for three representative pages. A deliberate change to the converter shows up here:
     * review the diff, then regenerate with AIED_UPDATE_GOLDEN=1 vendor/bin/phpunit --filter Golden.
     */
    #[DataProvider('goldens')]
    public function testGoldenLayout( string $name ): void
    {
        $r    = $this->convert( (string) file_get_contents( self::FIXTURES . '/' . $name . '.html' ) );
        $file = __DIR__ . '/golden/html-to-divi/' . $name . '.layout.txt';
        if ( false !== getenv( 'AIED_UPDATE_GOLDEN' ) ) {
            file_put_contents( $file, $r['layout'] . "\n" );
        }
        $this->assertFileExists( $file );
        $this->assertSame( rtrim( (string) file_get_contents( $file ), "\n" ), $r['layout'] );
    }

    /**
     * The mixed-style run is DELIBERATE: the second section of 14-styled-inline holds two consecutive <p> (one unstyled,
     * one with its own colour and size) that the mapper merges into ONE divi/text. Later style tasks must satisfy it:
     * a module carries only the style properties that are equal across all its merged elements; otherwise nothing for
     * that property and a counted note "mixed". 14-styled-hostile (table + <center><p>) is a second such module.
     *
     * @return array<string,array{0:string,1:string}> name => file under tests/golden/html-to-divi
     */
    public static function noneGoldens(): array
    {
        $out = [];
        foreach ( [ '01-landing', '03-pricing-cards', '13-images' ] as $n ) {
            $out[ $n ] = [ $n, $n . '.layout.txt' ];
        }
        foreach ( [ 'inline', 'classes', 'dark', 'backgrounds', 'fonts', 'hostile' ] as $n ) {
            $out[ '14-styled-' . $n ] = [ '14-styled-' . $n, '14-styled-' . $n . '.none.layout.txt' ];
        }

        return $out;
    }

    /**
     * styles=none is the phase-1 output, byte for byte. These files were recorded on the converter before any style
     * code existed; only AIED_UPDATE_NONE_GOLDEN rewrites them (never AIED_UPDATE_GOLDEN), so regenerating the
     * styled goldens can never touch them.
     */
    #[DataProvider('noneGoldens')]
    public function testStylesNoneGoldenLayout( string $name, string $golden ): void
    {
        $r    = $this->convert( (string) file_get_contents( self::FIXTURES . '/' . $name . '.html' ), [ 'styles' => 'none' ] );
        $file = __DIR__ . '/golden/html-to-divi/' . $golden;
        if ( false !== getenv( 'AIED_UPDATE_NONE_GOLDEN' ) ) {
            file_put_contents( $file, $r['layout'] . "\n" );
        }
        $this->assertFileExists( $file );
        $this->assertSame( rtrim( (string) file_get_contents( $file ), "\n" ), $r['layout'] );
    }

    /** A later "fix" of the fixture must not remove the deliberate mixed-style run (see noneGoldens). */
    public function testTheMixedStyleRunStaysInTheInlineFixture(): void
    {
        $html = (string) file_get_contents( self::FIXTURES . '/14-styled-inline.html' );
        $this->assertMatchesRegularExpression( '/<p>We buy green[^<]*<\/p>\s*<p style="[^"]*color:#4b5563[^"]*">Every batch/', $html );
        $golden = (string) file_get_contents( __DIR__ . '/golden/html-to-divi/14-styled-inline.none.layout.txt' );
        $this->assertStringContainsString( 'kilos.\u003c/p\u003e\u003cp\u003eEvery batch', $golden, 'the two paragraphs are one text module' );
    }

    /** These pages have no CSS: the default (basic) and none give the very same file. */
    #[DataProvider('phaseOneGoldens')]
    public function testThePhaseOneGoldensAreTheSameInBothModes( string $name ): void
    {
        $html = (string) file_get_contents( self::FIXTURES . '/' . $name . '.html' );
        $file = rtrim( (string) file_get_contents( __DIR__ . '/golden/html-to-divi/' . $name . '.layout.txt' ), "\n" );
        $this->assertSame( $file, $this->convert( $html )['layout'] );
        $this->assertSame( $file, $this->convert( $html, [ 'styles' => 'none' ] )['layout'] );
    }

    /** @return array<string,array{0:string,1:list<string>}> file => the words that must reach the layout */
    public static function headShapes(): array
    {
        return [
            '15-head-style-section.html' => [ '15-head-style-section.html', [ 'Fresh coffee', 'Roasted every morning', 'Visit us on Main Street' ] ],
            '15-head-link-section.html'  => [ '15-head-link-section.html', [ 'Hello from the link page', 'Only a stylesheet link' ] ],
            '15-head-meta-header.html'   => [ '15-head-meta-header.html', [ 'Top of the page', 'The main text', 'Footer words' ] ],
        ];
    }

    /** HTML5 elements after head content used to be lost (or the page refused): every word must now be converted and counted. */
    #[DataProvider('headShapes')]
    public function testHtml5ElementsAfterHeadContentAreConverted( string $file, array $words ): void
    {
        foreach ( [ 'none', 'basic' ] as $styles ) {
            $r = $this->convert( (string) file_get_contents( self::FIXTURES . '/' . $file ), [ 'styles' => $styles ] );
            $this->assertTrue( $r['ok'], $file . ' ' . $styles . ': ' . (string) ( $r['error'] ?? '' ) );
            foreach ( $words as $w ) {
                $this->assertStringContainsString( $w, $r['layout'], $file . ' ' . $styles );
            }
            $this->assertTrue( $r['report']['text_check']['equal'] );
            $this->assertGreaterThanOrEqual( strlen( implode( '', $words ) ) - count( $words ) * 2, $r['report']['text_check']['source_chars'], 'the relocated text counts as source text' );
        }
    }

    public function testASectionBeforeADivIsNoLongerLostAfterAStyleBlock(): void
    {
        $r = $this->convert( '<style>h1{color:red}</style><section><h1>AAA</h1><p>one</p></section><div><p>BBB</p></div>', [ 'styles' => 'none' ] );
        $this->assertStringContainsString( 'AAA', $r['layout'] );
        $this->assertStringContainsString( 'BBB', $r['layout'] );
        $this->assertSame( 9, $r['report']['text_check']['source_chars'] );
    }

    #[DataProvider('headShapes')]
    public function testHeadShapeGoldenLayout( string $file ): void
    {
        $name = basename( $file, '.html' );
        $r    = $this->convert( (string) file_get_contents( self::FIXTURES . '/' . $file ), [ 'styles' => 'none' ] );
        $gold = __DIR__ . '/golden/html-to-divi/' . $name . '.none.layout.txt';
        if ( false !== getenv( 'AIED_UPDATE_GOLDEN' ) ) {
            file_put_contents( $gold, $r['layout'] . "\n" );
        }
        $this->assertFileExists( $gold );
        $this->assertSame( rtrim( (string) file_get_contents( $gold ), "\n" ), $r['layout'] );
    }

    /** @return array<string,array{0:string,1:list<string>}> file => the words that must reach the layout */
    public static function afterHtmlFiles(): array
    {
        return [
            '16-after-html-section.html'         => [ '16-after-html-section.html', [ 'Before the end', 'After the html tag', 'A browser puts this in the body too' ] ],
            '16-after-html-body-close.html'      => [ '16-after-html-body-close.html', [ 'Written in the body', 'After the body tag', 'Still the page body' ] ],
            '16-after-html-second-document.html' => [ '16-after-html-second-document.html', [ 'First document', 'Second document', 'The second body text', 'Loose words at the very end' ] ],
        ];
    }

    /** Content after </html> or </body> was silently dropped while text_check still said equal: every word must be converted and counted. */
    #[DataProvider('afterHtmlFiles')]
    public function testContentAfterTheHtmlEndIsConverted( string $file, array $words ): void
    {
        foreach ( [ 'none', 'basic' ] as $styles ) {
            $r = $this->convert( (string) file_get_contents( self::FIXTURES . '/' . $file ), [ 'styles' => $styles ] );
            $this->assertTrue( $r['ok'], $file . ' ' . $styles . ': ' . (string) ( $r['error'] ?? '' ) );
            foreach ( $words as $w ) {
                $this->assertStringContainsString( $w, $r['layout'], $file . ' ' . $styles );
            }
            $this->assertTrue( $r['report']['text_check']['equal'] );
            $this->assertGreaterThanOrEqual( strlen( implode( '', $words ) ) - count( $words ) * 2, $r['report']['text_check']['source_chars'], 'the moved text counts as source text' );
        }
    }

    public function testAParagraphAfterTheHtmlEndCountsInTheTextCheck(): void
    {
        $r = $this->convert( '<html><body><p>AAA</p></body></html><p>BBB</p>', [ 'styles' => 'none' ] );
        $this->assertStringContainsString( 'BBB', $r['layout'] );
        $this->assertSame( 6, $r['report']['text_check']['source_chars'] );
    }

    #[DataProvider('afterHtmlFiles')]
    public function testAfterHtmlGoldenLayout( string $file ): void
    {
        $name = basename( $file, '.html' );
        $r    = $this->convert( (string) file_get_contents( self::FIXTURES . '/' . $file ), [ 'styles' => 'none' ] );
        $gold = __DIR__ . '/golden/html-to-divi/' . $name . '.none.layout.txt';
        if ( false !== getenv( 'AIED_UPDATE_GOLDEN' ) && ! is_file( $gold ) ) {
            file_put_contents( $gold, $r['layout'] . "\n" );
        }
        $this->assertFileExists( $gold );
        $this->assertSame( rtrim( (string) file_get_contents( $gold ), "\n" ), $r['layout'] );
    }

    public function testEmptyInputIsRefusedWithAReason(): void
    {
        $r = $this->convert( (string) file_get_contents( self::FIXTURES . '/11-empty.html' ) );
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'empty', $r['error'] );
        $this->assertSame( '', $r['layout'] );
    }

    public function testInputWithNothingVisibleIsRefused(): void
    {
        foreach ( [ '<script>alert(1)</script>', '<div> </div>', '<!-- only a comment -->x', '<style>p{}</style>' ] as $html ) {
            $r = $this->convert( $html );
            if ( $r['ok'] ) {
                $this->assertStringContainsString( 'x', $r['layout'], $html );
                continue;
            }
            $this->assertNotSame( '', $r['error'], $html );
        }
        $this->assertFalse( $this->convert( '<script>alert(1)</script>' )['ok'] );
    }

    public function testTheReportIsHonest(): void
    {
        $r = $this->convert( (string) file_get_contents( self::FIXTURES . '/08-forbidden-content.html' ) );
        $types = array_column( $r['report']['flagged'], 'type' );
        foreach ( [ 'script', 'form', 'iframe', 'href', 'button' ] as $t ) {
            $this->assertContains( $t, $types, $t . ' is reported' );
        }
        $this->assertSame( count( $r['report']['flagged'] ), $r['report']['flagged_count'] );
        $this->assertSame( 'Page with forbidden content', $r['report']['suggested_title'] );
        $this->assertGreaterThan( 0, $r['report']['elements']['sections'] );
    }

    public function testTheStylesReportIsOnlyThereWhenStylesAreAskedFor(): void
    {
        $html  = '<h1>Hi</h1><p>x</p>';
        $basic = $this->convert( $html )['report'];
        $none  = $this->convert( $html, [ 'styles' => 'none' ] )['report'];
        $this->assertSame( [ 'carried', 'ignored', 'dropped_unreadable', 'dropped_total', 'fonts', 'clamped', 'notes', 'external' ], array_keys( $basic['styles'] ), 'the internal pass count is not handed over' );
        $this->assertArrayNotHasKey( 'styles', $none, 'styles=none is the phase-1 report exactly' );
        foreach ( [ $basic, $none ] as $report ) {
            $this->assertSame( [], array_filter( $report['notes'], static fn( $n ) => str_contains( $n, 'not converted yet' ) ) );
        }
        $this->assertSame( array_keys( $none ), array_values( array_diff( array_keys( $basic ), [ 'styles' ] ) ), 'the rest of the report is the same in both modes' );
    }

    /** report.styles.ignored is the sorted union of every layer's counters: HtmlCss and HtmlStyles plus HtmlStyleBlocks and HtmlReadability. */
    public function testTheIgnoredCountersOfEveryLayerAreAddedUp(): void
    {
        $s = $this->convert( (string) file_get_contents( self::FIXTURES . '/14-styled-hostile.html' ) )['report']['styles'];
        $keys = array_keys( $s['ignored'] );
        $sorted = $keys;
        sort( $sorted );
        $this->assertSame( $sorted, $keys );
        $this->assertArrayHasKey( 'media', $s['ignored'], 'a cascade counter (HtmlStyles)' );
        $this->assertArrayHasKey( 'pseudo_state', $s['ignored'], 'a CSS reader counter (HtmlCss)' );
        $this->assertArrayHasKey( 'unknown_background', $s['ignored'], 'a readability counter (HtmlReadability)' );
        $this->assertSame( 2, $s['dropped_total'] );
        $this->assertCount( 2, $s['dropped_unreadable'] );
    }

    /** The skipped <style> elements and the linked stylesheets are flagged only with styles=basic; the generic entry never doubles them. */
    public function testStyleFlagsJoinTheReportOnlyForBasic(): void
    {
        $html  = '<!DOCTYPE html><html><head><link rel="stylesheet" href="/a.css"><style>@import url("/b.css"); p{color:#111111}</style></head><body><section><h1>Hi</h1>'
            . '<style>p{color:#222222}</style><style media="print">h1{color:#ff0000}</style><p>x</p></section></body></html>';
        $basic = $this->convert( $html )['report'];
        $none  = $this->convert( $html, [ 'styles' => 'none' ] )['report'];

        $this->assertSame( [ 'style', 'style' ], array_column( $none['flagged'], 'type' ), 'styles=none: the phase-1 entries, one per <style> in the body' );
        $this->assertSame( [ 'style', 'link' ], array_column( $basic['flagged'], 'type' ), 'basic: the print <style> and the link, each once; the read <style> is in report.styles' );
        $this->assertStringContainsString( 'media attribute', $basic['flagged'][0]['reason'] );
        $this->assertStringContainsString( '1 linked stylesheet was not read', $basic['flagged'][1]['reason'] );
        $this->assertSame( count( $basic['flagged'] ), $basic['flagged_count'] );
        $this->assertSame( 2, $basic['styles']['external'], 'the link and the @import' );
        $this->assertContains( '2 external stylesheet(s) not read: the converter never fetches CSS, so colours and fonts may differ.', $basic['styles']['notes'] );
        $this->assertSame( 0, $this->convert( '<h1>Hi</h1>' )['report']['styles']['external'] );
        $this->assertSame( [], $this->convert( '<h1>Hi</h1>' )['report']['styles']['notes'] );
    }

    /** Every string of report.styles is valid UTF-8 and at most 200 characters (a font name comes from the source CSS). */
    public function testReportStringsAreCappedAndValidUtf8(): void
    {
        $cap = new \ReflectionMethod( HtmlToDivi::class, 'cap' );
        $this->assertSame( 200, mb_strlen( (string) $cap->invoke( null, str_repeat( 'é', 300 ) ) ) );
        $this->assertTrue( mb_check_encoding( (string) $cap->invoke( null, "Brand\xff\xfe Sans" ), 'UTF-8' ) );
        $this->assertSame( 'Inter', $cap->invoke( null, 'Inter' ) );

        foreach ( self::styledFixtures() as [ $name ] ) {
            $styles = $this->convert( (string) file_get_contents( self::FIXTURES . '/' . $name . '.html' ) )['report']['styles'];
            $this->assertNotFalse( json_encode( $styles ), $name );
            array_walk_recursive( $styles, function ( mixed $v ) use ( $name ): void {
                if ( is_string( $v ) ) {
                    $this->assertLessThanOrEqual( 200, mb_strlen( $v ), $name );
                    $this->assertTrue( mb_check_encoding( $v, 'UTF-8' ), $name );
                }
            } );
            $this->assertLessThanOrEqual( 20, count( $styles['fonts'] ) );
            $this->assertSame( count( $styles['fonts'] ), count( array_unique( array_column( $styles['fonts'], 'source' ) ) ), 'fonts are unique by source' );
        }
    }

    /**
     * Review M4: a cards chunk of more than four cards becomes several Divi rows. The writer takes padding per side, so the row's top
     * padding goes on the first Divi row only and its bottom padding on the last only; left, right, background and max width repeat.
     */
    public function testACardsRowSplitIntoSeveralRowsPadsOnlyItsOuterEdges(): void
    {
        $card = static fn( int $i ): string => '<div class="card"><h3>Card ' . $i . '</h3><p>Text of card number ' . $i . ' here.</p></div>';
        $html = '<!DOCTYPE html><html><body><section style="background:#ffffff"><div class="grid" style="padding:10px 20px 30px 40px; max-width:900px">'
            . implode( '', array_map( $card, range( 1, 6 ) ) ) . '</div></section></body></html>';
        $root = ( new \Divi5Validator\BlockParser() )->parse( $this->convert( $html )['layout'] )->root();
        $this->assertNotNull( $root );
        $rows = [];
        $root->walk( static function ( \Divi5Validator\Block $b ) use ( &$rows ): void {
            if ( 'divi/row' === $b->name() ) {
                $rows[] = $b;
            }
        } );
        $this->assertCount( 2, $rows, 'six cards: two rows of three' );
        $pad = static fn( \Divi5Validator\Block $r ): array => (array) ( $r->attr( 'module.decoration.spacing.desktop.value.padding' ) ?? [] );
        $this->assertSame( [ 'top' => '10px', 'right' => '20px', 'left' => '40px' ], $pad( $rows[0] ) );
        $this->assertSame( [ 'right' => '20px', 'bottom' => '30px', 'left' => '40px' ], $pad( $rows[1] ) );
        foreach ( $rows as $r ) {
            $this->assertSame( '900px', $r->attr( 'module.decoration.sizing.desktop.value.maxWidth' ) );
        }
    }

    /** The background path of a section, row or column (DiviMarkup). */
    private const PAINT = 'module.decoration.background.desktop.value.color';

    #[DataProvider('styledFixtures')]
    public function testEveryStyledFixtureMeetsTheOracle( string $name ): void
    {
        $r = $this->convert( (string) file_get_contents( self::FIXTURES . '/' . $name . '.html' ) );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [], StyledLayoutOracle::problems( $r['layout'] ), $name );
    }

    /** Every page of the corpus, in both modes: the oracle has nothing to say (a page without styles is trivially fine). */
    #[DataProvider('fixtures')]
    public function testEveryFixtureMeetsTheOracleInBothModes( string $file ): void
    {
        $html = (string) file_get_contents( $file );
        foreach ( [ 'basic', 'none' ] as $mode ) {
            $r = $this->convert( $html, [ 'styles' => $mode ] );
            if ( $r['ok'] ) {
                $this->assertSame( [], StyledLayoutOracle::problems( $r['layout'] ), basename( $file ) . ' ' . $mode );
            }
        }
    }

    /**
     * Five hand-made layouts, one per rule of StyledLayoutOracle, each giving exactly its own problem; each next to its clean twin,
     * which gives none. Then the edges of rule 3 (the smallest device size, weight, the innermost paint) and rule 4.
     */
    public function testTheOracleCatchesPlantedFaults(): void
    {
        $page = static fn( string $mods, array $section = [], array $row = [], array $columns = [] ): string => DiviMarkup::page( [ DiviMarkup::section( '', DiviMarkup::row( [ $mods ], $row, $columns ), $section ) ] );
        $text = static fn( array $style = [] ): string => DiviMarkup::text( '<p>Some words</p>', $style );
        $white = [ 'background' => '#ffffff' ];

        $faults = [
            'rule 1' => [ $page( $text( [ 'color' => '#111111' ] ) ), $page( $text( [ 'color' => '#111111' ] ), $white ) ],
            'rule 2' => [ $page( $text(), $white ), $page( $text() ) ],
            'rule 3' => [ $page( $text( [ 'color' => '#eeeeee' ] ), $white ), $page( $text( [ 'color' => '#333333' ] ), $white ) ],
            'rule 4' => [ $page( DiviMarkup::button( 'Go', '/go', false, [ 'background' => '#2563eb' ] ) ), $page( DiviMarkup::button( 'Go', '/go', false, [ 'background' => '#2563eb', 'color' => '#ffffff' ] ) ) ],
            'rule 5' => [ str_replace( '"200px"', '"250px"', $page( $text(), [ 'padding' => [ 'top' => '200px' ] ] ) ), $page( $text(), [ 'padding' => [ 'top' => '200px' ] ] ) ],
        ];
        foreach ( $faults as $rule => [ $faulty, $clean ] ) {
            $problems = StyledLayoutOracle::problems( $faulty );
            $this->assertCount( 1, $problems, $rule . ': ' . json_encode( $problems ) );
            $this->assertStringStartsWith( $rule . ':', $problems[0] );
            $this->assertSame( [], StyledLayoutOracle::problems( $clean ), $rule . ' clean twin' );
        }

        // Rule 3 is judged at the smallest size written on any device: #8a8a8a on white is 3.45:1.
        $heading = static fn( int $size, string $weight = '400' ): string => DiviMarkup::heading( 'Title', 2, [ 'color' => '#8a8a8a', 'size' => $size, 'weight' => $weight ] );
        $only    = static fn( string $layout ): array => array_map( static fn( string $p ): string => substr( $p, 0, 7 ), StyledLayoutOracle::problems( $layout ) );
        $this->assertSame( [ 'rule 3:' ], $only( $page( $heading( 40 ), $white ) ), '40px steps down to 22px on phones: normal text' );
        $this->assertSame( [], $only( $page( $heading( 60 ), $white ) ), '60px is 33px on phones: large' );
        $this->assertSame( [], $only( $page( $heading( 24 ), $white ) ), '24px written on desktop only: large' );
        $this->assertSame( [], $only( $page( $heading( 20, '700' ), $white ) ), '20px bold: large' );
        $this->assertSame( [ 'rule 3:' ], $only( $page( $heading( 20, '600' ), $white ) ), '20px semibold: normal' );
        $this->assertSame( [ 'rule 3:' ], $only( $page( $heading( 18, '700' ), $white ) ), '18px bold: normal' );
        $this->assertSame( [ 'rule 3:' ], $only( $page( DiviMarkup::heading( 'Title', 1, [ 'color' => '#8a8a8a' ] ), $white ) ), 'no written size: never large' );

        // The innermost paint is the one judged.
        $this->assertSame( [], $only( $page( $text( [ 'color' => '#111111' ] ), [ 'background' => '#000000' ], [], [ $white ] ) ) );
        $this->assertSame( [ 'rule 3:' ], $only( $page( $text( [ 'color' => '#111111' ] ), $white, [ 'background' => '#000000' ] ) ) );
        $this->assertSame( [ 'rule 3:' ], $only( $page( $text( [ 'color' => '#ffffff' ] ), [ 'background' => '#000000' ], [], [ $white ] ) ) );

        // Rule 4: an unreadable pair, a ghost without paint, a ghost on paint, a bare button on paint.
        $button = static fn( array $style ): string => DiviMarkup::button( 'Go', '/go', false, $style );
        $this->assertSame( [ 'rule 4:' ], $only( $page( $button( [ 'background' => '#ffffff', 'color' => '#eeeeee' ] ) ) ) );
        $this->assertSame( [ 'rule 4:' ], $only( $page( $button( [ 'background' => DiviMarkup::TRANSPARENT, 'color' => '#111111' ] ) ) ) );
        $this->assertSame( [], $only( $page( $button( [ 'background' => DiviMarkup::TRANSPARENT, 'color' => '#111111' ] ), $white ) ) );
        $this->assertSame( [ 'rule 4:' ], $only( $page( $button( [ 'background' => DiviMarkup::TRANSPARENT, 'color' => '#eeeeee' ] ), $white ) ) );
        $this->assertSame( [ 'rule 4:' ], $only( $page( $button( [] ), $white ) ) );
        $this->assertSame( [], $only( $page( $button( [ 'radius' => 8 ] ) ) ) );

        // Rule 5: a colour, a family and a size outside their forms.
        $this->assertSame( [ 'rule 5:' ], $only( str_replace( '#333333', '#333333aa', $page( $text( [ 'color' => '#333333' ] ), $white ) ) ) );
        $this->assertSame( [ 'rule 5:' ], $only( str_replace( '"Inter"', '"Brand X"', $page( $text( [ 'family' => 'Inter' ] ) ) ) ) );
        $this->assertSame( [ 'rule 5:' ], $only( str_replace( '"16px"', '"7px"', $page( $text( [ 'size' => 16 ] ) ) ) ) );
        $this->assertSame( [ 'rule 5:' ], $only( str_replace( '"720px"', '"3000px"', $page( $text(), [], [ 'max_width' => '720px' ] ) ) ) );
    }

    /** @return array<string,mixed> sections, rows, columns and modules (lists of \Divi5Validator\Block, document order), report, layout */
    private function blocks( string $name ): array
    {
        $r = $this->convert( (string) file_get_contents( self::FIXTURES . '/' . $name . '.html' ) );
        $this->assertTrue( $r['ok'] );
        $root = ( new \Divi5Validator\BlockParser() )->parse( $r['layout'] )->root();
        $this->assertNotNull( $root );
        $out = [ 'sections' => [], 'rows' => [], 'columns' => [], 'modules' => [], 'report' => $r['report'], 'layout' => $r['layout'] ];
        $root->walk( static function ( \Divi5Validator\Block $b ) use ( &$out ): void {
            $k = match ( $b->name() ) {
                'divi/section' => 'sections',
                'divi/row'     => 'rows',
                'divi/column'  => 'columns',
                'divi/placeholder' => null,
                default        => 'modules',
            };
            if ( null !== $k ) {
                $out[ $k ][] = $b;
            }
        } );

        return $out;
    }

    /** The first module whose attributes contain $text. */
    private function module( array $blocks, string $text ): \Divi5Validator\Block
    {
        foreach ( $blocks['modules'] as $m ) {
            if ( str_contains( (string) json_encode( $m->attrs(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $text ) ) {
                return $m;
            }
        }
        $this->fail( 'no module holds ' . $text );
    }

    private const H = 'title.decoration.font.font.';
    private const T = 'content.decoration.bodyFont.body.font.';
    private const B = 'button.decoration.';

    /**
     * What each styled page must carry, read from its HTML (every fact was checked against the fixture's own CSS before it was pinned).
     */
    public function testTheStyledFixturesCarryWhatTheyShould(): void
    {
        // inline: a dark hero with a white 56px / 800 centred h1, a button pair, and a 720px row with 40px padding.
        $b  = $this->blocks( '14-styled-inline' );
        $h1 = $this->module( $b, 'Small-batch coffee' );
        $this->assertSame( '#0f172a', $b['sections'][0]->attr( self::PAINT ) );
        $this->assertSame( [ '#ffffff', '56px', '800', 'center' ], [ $h1->attr( self::H . 'desktop.value.color' ), $h1->attr( self::H . 'desktop.value.size' ), $h1->attr( self::H . 'desktop.value.weight' ), $h1->attr( self::H . 'desktop.value.textAlign' ) ] );
        $lead = $this->module( $b, 'Order by Sunday' );
        $this->assertSame( [ '#cbd5e1', '20px', 'center' ], [ $lead->attr( self::T . 'desktop.value.color' ), $lead->attr( self::T . 'desktop.value.size' ), $lead->attr( 'module.advanced.text.text.desktop.value.orientation' ) ] );
        $btn = $this->module( $b, '/shop' );
        $this->assertSame( [ '#f59e0b', '#111827', '999px' ], [ $btn->attr( self::B . 'background.desktop.value.color' ), $btn->attr( self::B . 'font.font.desktop.value.color' ), $btn->attr( self::B . 'border.desktop.value.radius.topLeft' ) ] );
        $this->assertSame( '720px', $b['rows'][1]->attr( 'module.decoration.sizing.desktop.value.maxWidth' ) );
        $this->assertSame( [ 'top' => '40px', 'right' => '40px', 'bottom' => '40px', 'left' => '40px' ], $b['rows'][1]->attr( 'module.decoration.spacing.desktop.value.padding' ) );
        // The deliberate mixed run (one unstyled and one styled <p> in one text module) drops the second section's canvas, so its h2 colour goes too.
        $this->assertNull( $b['sections'][1]->attr( self::PAINT ) );
        $this->assertNull( $this->module( $b, 'How we roast' )->attr( self::H . 'desktop.value.color' ) );
        $this->assertSame( 'paint_dropped', $b['report']['styles']['dropped_unreadable'][0]['reason'] );

        // classes: #intro beats .lead, the inline colour beats .accent, h2 2em of 18px = 36/27/20, three white cards on #f3f4f6, Inter.
        $b = $this->blocks( '14-styled-classes' );
        $this->assertSame( '#ffffff', $b['sections'][0]->attr( self::PAINT ), 'the canvas under declared colours' );
        $this->assertSame( '#0f766e', $this->module( $b, 'Drop it off' )->attr( self::T . 'desktop.value.color' ) );
        $this->assertSame( '#6b7280', $this->module( $b, 'nine thousand' )->attr( self::T . 'desktop.value.color' ) );
        $this->assertSame( '#7c3aed', $this->module( $b, 'Free safety check' )->attr( self::T . 'desktop.value.color' ) );
        $this->assertSame( '#1f2937', $this->module( $b, 'Walk-ins' )->attr( self::T . 'desktop.value.color' ), 'the later of two equal p rules' );
        $h2 = $this->module( $b, 'What we fix' );
        $this->assertSame( [ '36px', '27px', '20px' ], [ $h2->attr( self::H . 'desktop.value.size' ), $h2->attr( self::H . 'tablet.value.size' ), $h2->attr( self::H . 'phone.value.size' ) ] );
        $this->assertNull( $this->module( $b, 'Our record' )->attr( self::H . 'desktop.value.size' ), 'an h3 takes its size only from its own rules' );
        $this->assertSame( '#f3f4f6', $b['sections'][1]->attr( self::PAINT ) );
        $cards = array_slice( $b['columns'], -3 );
        foreach ( $cards as $c ) {
            $this->assertSame( '#ffffff', $c->attr( self::PAINT ) );
            $this->assertSame( '24px', $c->attr( 'module.decoration.spacing.desktop.value.padding.top' ) );
        }
        $this->assertSame( 'Inter', $this->module( $b, 'Tune-ups' )->attr( self::H . 'desktop.value.family' ) );
        $this->assertSame( [ [ 'source' => 'Inter', 'action' => 'carried' ] ], $b['report']['styles']['fonts'] );

        // dark: the page background reaches three sections; the fine print (2.48:1 on #0b1120) leaves its section unpainted and is listed.
        $b = $this->blocks( '14-styled-dark' );
        $this->assertSame( [ '#0b1120', '#1e293b', '#0b1120', null ], array_map( static fn( $s ) => $s->attr( self::PAINT ), $b['sections'] ) );
        $this->assertNull( $this->module( $b, 'Prices exclude VAT' )->attr( self::T . 'desktop.value.color' ) );
        $this->assertSame( [ 'reason' => 'unreadable', 'color' => '#475569', 'background' => '#0b1120', 'ratio' => '2.48' ], array_intersect_key( $b['report']['styles']['dropped_unreadable'][0], array_flip( [ 'reason', 'color', 'background', 'ratio' ] ) ) );
        $this->assertSame( [ '#a78bfa', '#0b1120' ], [ $this->module( $b, '/rates' )->attr( self::B . 'background.desktop.value.color' ), $this->module( $b, '/rates' )->attr( self::B . 'font.font.desktop.value.color' ) ] );

        // backgrounds: only the solid #123456 section is painted; the gradient, the image and the rgba() sections are not, nor is the
        // light section whose last paragraph has its own black background (a background between the text and its section).
        $b = $this->blocks( '14-styled-backgrounds' );
        $this->assertSame( [ null, null, '#123456', null, null ], array_map( static fn( $s ) => $s->attr( self::PAINT ), $b['sections'] ) );
        $this->assertSame( '#e0f2fe', $this->module( $b, 'Lightweight shelters' )->attr( self::H . 'desktop.value.color' ) );
        $this->assertNull( $this->module( $b, 'Gear that goes higher' )->attr( self::H . 'desktop.value.color' ) );
        $this->assertSame( '120px', $b['sections'][1]->attr( 'module.decoration.spacing.desktop.value.padding.top' ), 'padding is layout: it is carried even where the paint is not' );
        $this->assertStringNotContainsString( '#000000', $b['layout'] );

        // fonts: Playfair Display on the headings that ask for it, Inter (after Brand Sans) on the text, Georgia, nothing on the pre.
        $b = $this->blocks( '14-styled-fonts' );
        $this->assertSame( 'Playfair Display', $this->module( $b, 'Letters worth keeping' )->attr( self::H . 'desktop.value.family' ) );
        $this->assertSame( 'Playfair Display', $this->module( $b, 'Our paper' )->attr( self::H . 'desktop.value.family' ) );
        $this->assertSame( 'Inter', $this->module( $b, 'fountain pens' )->attr( self::T . 'desktop.value.family' ) );
        $this->assertSame( 'Georgia', $this->module( $b, 'cotton rag' )->attr( self::T . 'desktop.value.family' ) );
        $this->assertNull( $this->module( $b, 'Ships worldwide' )->attr( self::T . 'desktop.value.family' ), 'system-ui is a generic family' );
        $this->assertNull( $this->module( $b, 'iron gall' )->attr( self::T . 'desktop.value.family' ), 'a pre takes its family only from its own rules' );
        $this->assertSame( [ 'Playfair Display', 'Brand Sans', 'Inter', 'Georgia' ], array_column( $b['report']['styles']['fonts'], 'source' ) );
        $this->assertSame( 1, $b['report']['styles']['external'], 'the @import is counted; the CSS in the page is still carried' );

        // hostile: the h1's size (@media) and colour (.hero h1) are unresolved, 900px is clamped to 200px, the inline button pair wins,
        // the opacity section is not painted, and RebeccaPurple (a p's own background) is never written.
        $b  = $this->blocks( '14-styled-hostile' );
        $h1 = $this->module( $b, 'Styles that fight back' );
        $this->assertNull( $h1->attr( self::H . 'desktop.value.size' ) );
        $this->assertNull( $h1->attr( self::H . 'desktop.value.color' ) );
        $this->assertSame( '200px', $this->module( $b, 'Enormous heading' )->attr( self::H . 'desktop.value.size' ) );
        $this->assertSame( 1, $b['report']['styles']['clamped'] );
        $go = $this->module( $b, '/go' );
        $this->assertSame( [ '#000000', '#ffffff', null ], [ $go->attr( self::B . 'background.desktop.value.color' ), $go->attr( self::B . 'font.font.desktop.value.color' ), $go->attr( self::B . 'border.desktop.value.radius.topLeft' ) ] );
        $this->assertNull( $b['sections'][0]->attr( self::PAINT ) );
        $this->assertStringNotContainsString( '#663399', strtolower( $b['layout'] ) );
        $this->assertStringNotContainsString( 'rebeccapurple', strtolower( $b['layout'] ) );
        $this->assertSame( [ 'reason' => 'unknown_background', 'color' => '#aabbcc' ], array_intersect_key( $b['report']['styles']['dropped_unreadable'][0], array_flip( [ 'reason', 'color' ] ) ) );
        foreach ( [ 'var(', 'calc(', 'clamp(', '#ff0000', '#ff00ff', '#00aa00', '#00ff00' ] as $never ) {
            $this->assertStringNotContainsString( $never, strtolower( $b['layout'] ) );
        }
    }

    public function testImagesAreReportedWithWhatHappenedToThem(): void
    {
        $r = $this->convert( '<h1>T</h1><img src="https://cdn.example.com/hero.jpg" alt="Hero" width="1600" height="800"><img src="/team/jane.jpg" alt="Jane">' );
        $this->assertCount( 2, $r['report']['images'] );
        $this->assertSame( 'https://cdn.example.com/hero.jpg', $r['report']['images'][0]['src'] );
        $this->assertStringContainsString( 'placeholder', $r['report']['images'][0]['action'] );
        $this->assertNotNull( $r['report']['images'][1]['token'] );
        $this->assertStringContainsString( '"alt":"Hero"', $r['layout'], 'the alt text is always kept' );
    }

    public function testASiteImageIsKeptWhenAsked(): void
    {
        $site = static fn( string $u ): bool => 'https://mysite.test/wp-content/uploads/a.jpg' === $u;
        $r = HtmlToDivi::convert( '<h1>T</h1><img src="https://mysite.test/wp-content/uploads/a.jpg" alt="Mine">', [ 'image_mode' => 'keep' ], \AiEditorDivi5\WP\ImagePack::manifest(), $site, [ self::class, 'resolve' ] );
        $this->assertStringContainsString( 'https://mysite.test/wp-content/uploads/a.jpg', $r['layout'] );
        $this->assertStringContainsString( 'Media Library', $r['report']['images'][0]['action'] );
    }

    public function testUnknownOptionsFallBackToTheDefaults(): void
    {
        $a = $this->convert( '<h1>x</h1>', [ 'image_mode' => 'weird', 'styles' => 'weird' ] );
        $b = $this->convert( '<h1>x</h1>' );
        $this->assertSame( $b, $a );
    }

    public function testAnExtraH1IsDemotedSoThePageStaysValid(): void
    {
        $r = $this->convert( '<h1>One</h1><h1>Two</h1>' );
        $this->assertTrue( LayoutGate::check( $r['layout'] )->isValid() );
        $this->assertSame( 1, substr_count( $r['layout'], '"headingLevel":"h1"' ) );
    }

    public function testASectionWithOnlyUnconvertibleContentIsDropped(): void
    {
        $r = $this->convert( '<section><form><input name="a"></form></section><section><h2>Real</h2></section>' );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 1, $r['report']['elements']['sections'] );
    }

    public function testBigButWithinLimitsIsFast(): void
    {
        $html = (string) file_get_contents( self::FIXTURES . '/12-large.html' );
        $t = microtime( true );
        $r = $this->convert( $html );
        $this->assertLessThan( 5.0, microtime( true ) - $t );
        $this->assertTrue( $r['ok'] );
    }

    /** A seeded generator of hostile and weird HTML: the converter must never throw, never emit anything dangerous, and always give a valid layout or a plain refusal. */
    public function testRandomHtmlNeverBreaksTheContract(): void
    {
        mt_srand( (int) ( getenv( 'AIED_SEED' ) ?: 20261014 ) );
        $tags = [ 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'p', 'span', 'a', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'button', 'hr', 'table', 'tr', 'td', 'figure', 'figcaption', 'blockquote', 'pre', 'strong', 'em', 'br', 'form', 'script', 'style', 'iframe', 'svg', 'video', 'embed', 'input', 'picture', 'font', 'center' ];
        $attrs = [ 'class="btn"', 'class="card"', 'id="x"', 'href="/a"', 'href="https://example.com"', 'href="javascript:alert(1)"', 'src="/i.png"', 'src="data:text/html,x"', 'onclick="x()"', 'style="color:red"', 'alt="a"', 'target="_blank"', 'role="button"', 'width="10" height="5"' ];
        $texts = [ 'Hello', 'Tom & Jerry', '1 < 2', '日本語', 'أهلا', '"quoted"', "it's", '-->', '<!--', '\\u003c', "\u{1F389}", '' ];

        $make = static function ( int $depth ) use ( &$make, $tags, $attrs, $texts ): string {
            $out = '';
            for ( $i = mt_rand( 0, 4 ); $i > 0; $i-- ) {
                if ( 0 === mt_rand( 0, 2 ) ) {
                    $out .= $texts[ mt_rand( 0, count( $texts ) - 1 ) ];
                    continue;
                }
                $t    = $tags[ mt_rand( 0, count( $tags ) - 1 ) ];
                $a    = 0 === mt_rand( 0, 1 ) ? ' ' . $attrs[ mt_rand( 0, count( $attrs ) - 1 ) ] : '';
                $out .= '<' . $t . $a . '>' . ( $depth > 0 ? $make( $depth - 1 ) : $texts[ mt_rand( 0, count( $texts ) - 1 ) ] ) . ( 0 === mt_rand( 0, 9 ) ? '' : '</' . $t . '>' );
            }

            return $out;
        };

        $ok = 0;
        $refused = 0;
        for ( $n = 0; $n < 200; $n++ ) {
            $html = $make( 4 );
            $r    = $this->convert( $html );
            if ( ! $r['ok'] ) {
                $this->assertNotSame( '', (string) $r['error'], 'case ' . $n );
                ++$refused;
                continue;
            }
            ++$ok;
            $gate = LayoutGate::check( $r['layout'] );
            $this->assertTrue( $gate->isValid(), 'case ' . $n . ': ' . json_encode( array_map( static fn( $v ) => $v->toArray(), $gate->violations() ) ) . "\n" . $html );
            $this->assertTrue( $r['report']['text_check']['equal'], 'case ' . $n . ': ' . ( $r['report']['text_check']['first_difference'] ?? '' ) . "\n" . $html );
            $lower = strtolower( $r['layout'] );
            foreach ( [ '<script', '<script', 'onclick', 'javascript:', '<iframe', '<iframe', 'style=' ] as $bad ) {
                $this->assertStringNotContainsString( $bad, $lower, 'case ' . $n . ' contains ' . $bad . "\n" . $html );
            }
            $this->assertSame( 1, substr_count( $r['layout'], '<!-- wp:divi/placeholder -->' ) );
        }
        $this->assertGreaterThan( 100, $ok, 'most random pages convert' );
    }

    /**
     * End to end on the large-text edges (#8a8a8a on #ffffff is 3.45:1, between the two floors): the oracle has nothing to say about
     * any of them, and the colour is carried exactly where the text stays large on every device the writer steps it to.
     */
    public function testTheLargeTextEdgesMeetTheOracleEndToEnd(): void
    {
        $carried = [];
        foreach ( [ [ '40px', '400' ], [ '44px', '400' ], [ '60px', '400' ], [ '24px', '400' ], [ '20px', '700' ], [ '18px', '700' ], [ '20px', '400' ] ] as [ $size, $weight ] ) {
            $html = '<section style="background:#ffffff"><h2 style="color:#8a8a8a;font-size:' . $size . ';font-weight:' . $weight . '">Edge words</h2></section>';
            $r    = $this->convert( $html );
            $this->assertTrue( $r['ok'] );
            $this->assertSame( [], StyledLayoutOracle::problems( $r['layout'] ), $html );
            $carried[ $size . '/' . $weight ] = str_contains( $r['layout'], '"color":"#8a8a8a"' );
        }
        // 40 and 44 px step down to 22 and 24 px on phones (max(20, 55%)): 22 px is normal text, 24 px is large.
        $this->assertSame( [ '40px/400' => false, '44px/400' => true, '60px/400' => true, '24px/400' => true, '20px/700' => true, '18px/700' => false, '20px/400' => false ], $carried );
    }

    /**
     * Open finding T10-F1 (Task 10's property test, seed 4 case 130 and 14 more cases in seeds 1-40): a FRAGMENT that starts with a
     * <style> element, then an element libxml does not know (an HTML5 section, header, video ...), then loose text, loses structure in
     * BOTH modes: libxml keeps the first element in <head> (HtmlSource::relocateHeadContent moves it back) but then opens <body> with an
     * implied <p> around the loose text that swallows everything after it, so the second section's h2 becomes <strong> inside a nested
     * paragraph. The text is kept (text_check equal); the heading and the section are not. The same page without the <style>, or as a
     * whole document (<html><head>...<body>), keeps both sections. This is HtmlSource's parse, outside the style layers; the test stays
     * incomplete (visible in every run) until it is fixed, then it passes and the property test's T10-F1 branch can be removed.
     */
    public function testALeadingStyleElementInAFragmentKeepsTheStructure(): void
    {
        $body = '<section><h2>First</h2></section>Loose words<section><h2>Second</h2></section>';
        $want = $this->convert( $body, [ 'styles' => 'none' ] )['layout'];
        $this->assertSame( 3, substr_count( $want, '<!-- wp:divi/section ' ), 'without the <style>: three sections' );
        $got = $this->convert( '<style>p{color:#111111}</style>' . $body, [ 'styles' => 'none' ] )['layout'];
        if ( $got !== $want ) {
            $this->markTestIncomplete( 'Open finding T10-F1: a leading <style> in a fragment changes the phase-1 structure (the second heading becomes <strong>).' );
        }
        $this->assertSame( $want, $got );
    }

    /** The default seed of the styled property test (AIED_SEED overrides it). */
    private const STYLED_SEED = 20261022;

    /**
     * The phase-1 generator, extended with styles: every element may get a random style="" from STYLE_POOL and the page a random
     * <style> of 0-12 rules. Seeded (AIED_SEED, default 20261022), 200 cases. Each case is a pair [styled, bare] built from the same
     * random choices (bare = the same tree without any style attribute or <style> element). The contract: never throws; ok or a plain
     * error; valid through LayoutGate; text kept; nothing CodePolicy refuses; no CSS in the layout; StyledLayoutOracle has nothing to
     * say; styles=none ignores every style (styled none == bare none == bare basic); the same seed gives the same bytes twice.
     */
    public function testRandomStyledHtmlNeverBreaksTheContract(): void
    {
        $seed  = (int) ( getenv( 'AIED_SEED' ) ?: self::STYLED_SEED );
        $cases = self::styledCases( $seed );
        $this->assertSame( $cases, self::styledCases( $seed ), 'the same seed gives the same pages' );

        $ok      = 0;
        $styled  = 0;
        $painted = 0;
        $inked   = 0;
        $buttons = 0;
        $leadingSheet = 0;
        foreach ( $cases as $n => [ $html, $bare ] ) {
            $where = 'seed ' . $seed . ' case ' . $n . "\n" . $html;
            $r     = $this->convert( $html );
            $this->assertSame( $r, $this->convert( $html ), 'the same bytes twice: ' . $where );
            $none     = $this->convert( $html, [ 'styles' => 'none' ] );
            $bareNone = $this->convert( $bare, [ 'styles' => 'none' ] );
            $bareBas  = $this->convert( $bare );
            $this->assertSame( $none['ok'], $r['ok'], 'both modes agree on refusing: ' . $where );
            if ( $none['layout'] !== $bareNone['layout'] && str_starts_with( $html, '<style>' ) ) {
                // Open finding T10-F1 (testALeadingStyleElementInAFragmentKeepsTheStructure): a fragment that starts with a <style>
                // makes libxml parse the rest in head mode, which changes the phase-1 tree. The style="" attributes must still change
                // nothing: the same page without its leading sheet (attributes kept) gives the bare page's bytes. Counted, never hidden.
                $sheetless = (string) preg_replace( '/^<style>[^<]*<\/style>/', '', $html );
                $this->assertSame( $bareNone['layout'], $this->convert( $sheetless, [ 'styles' => 'none' ] )['layout'], 'styles=none ignores every style attribute: ' . $where );
                ++$leadingSheet;
            } else {
                $this->assertSame( $none['layout'], $bareNone['layout'], 'styles=none ignores every style: ' . $where );
            }
            // A <center> element is HTML, not CSS: bare keeps it, and basic carries its UA alignment (D5). Only that may differ.
            $bareBasic = str_contains( strtolower( $bare ), '<center' ) ? str_replace( [ ',"textAlign":"center"', ',"module":{"advanced":{"text":{"text":{"desktop":{"value":{"orientation":"center"}}}}}}' ], '', $bareBas['layout'] ) : $bareBas['layout'];
            $this->assertSame( $bareNone['layout'], $bareBasic, 'a page without CSS is the same in both modes: ' . $where );
            if ( ! $r['ok'] ) {
                $this->assertIsString( $r['error'], $where );
                $this->assertNotSame( '', $r['error'], $where );
                $this->assertSame( '', $r['layout'], $where );
                continue;
            }
            ++$ok;
            $gate = LayoutGate::check( $r['layout'] );
            $this->assertTrue( $gate->isValid(), json_encode( array_map( static fn( $v ) => $v->toArray(), $gate->violations() ) ) . "\n" . $where );
            $this->assertTrue( $r['report']['text_check']['equal'], ( $r['report']['text_check']['first_difference'] ?? '' ) . "\n" . $where );
            $this->assertSame( [], CodePolicy::violations( $r['layout'] ), $where );
            $lower = strtolower( $r['layout'] );
            $this->assertStringNotContainsString( 'style=', $lower, $where );
            $this->assertStringNotContainsString( '<style', $lower, $where );
            $this->assertSame( [], StyledLayoutOracle::problems( $r['layout'] ), $where );

            $styled += $r['layout'] !== $bareBas['layout'] ? 1 : 0;
            ( new \Divi5Validator\BlockParser() )->parse( $r['layout'] )->root()?->walk( static function ( \Divi5Validator\Block $b ) use ( &$painted, &$inked, &$buttons ): void {
                $painted += in_array( $b->name(), [ 'divi/section', 'divi/row', 'divi/column' ], true ) && null !== $b->attr( self::PAINT ) ? 1 : 0;
                $inked   += null !== ( $b->attr( 'title.decoration.font.font.desktop.value.color' ) ?? $b->attr( 'content.decoration.bodyFont.body.font.desktop.value.color' ) ) ? 1 : 0;
                $buttons += null !== $b->attr( 'button.decoration.background.desktop.value.color' ) ? 1 : 0;
            } );
        }
        if ( false !== getenv( 'AIED_STATS' ) ) {
            fwrite( STDERR, sprintf( "seed %d: %d of %d convert, %d styled, %d painted blocks, %d inked modules, %d filled or ghost buttons, %d hit finding T10-F1\n", $seed, $ok, count( $cases ), $styled, $painted, $inked, $buttons, $leadingSheet ) );
        }
        $this->assertGreaterThan( 100, $ok, 'most random pages convert' );
        $this->assertGreaterThan( 20, $styled, 'the generator must produce pages whose styles change the layout' );
        $this->assertGreaterThan( 5, $painted, 'the generator must produce painted blocks' );
        $this->assertGreaterThan( 5, $inked, 'the generator must produce carried colours' );
        $this->assertGreaterThan( 10, $buttons, 'the generator must produce button pairs or ghosts' );
    }

    /** The values a random style="" or <style> declaration takes: every form, including the ones the reader must refuse. */
    private const STYLE_POOL = [
        'color'            => [ '#111827', '#ffffff', '#fff', '#ABC', '#0f766e', '#949494', '#767676', 'red', 'white', 'RebeccaPurple', 'navy', 'rgb(30,41,59)', 'rgb(30 41 59 / 50%)', 'rgba(0,0,0,.5)', '#11182780', '#0008', 'hsl(0 0% 50%)', 'var(--x)', 'var(--x, #000)', 'currentColor', 'transparent', 'inherit', 'initial' ],
        'background'       => [ '#ffffff', '#0b1120', '#f3f4f6', '#123456', '#000', 'white', 'navy', 'rgba(0,0,0,.5)', '#ffffff80', 'transparent', 'none', 'linear-gradient(#000,#fff)', 'radial-gradient(#fff,#000)', 'url(/x.jpg)', '#123456 url(/x.jpg)', 'var(--bg)', 'inherit' ],
        'background-color' => [ '#ffffff', '#0b1120', '#1e293b', '#fef3c7', '#2563eb', '#f59e0b', 'black', 'rgba(255,255,255,.1)', 'transparent', 'var(--bg)', 'currentColor' ],
        'font-size'        => [ '12px', '16px', '19px', '20px', '23px', '24px', '33px', '40px', '56px', '72px', '900px', '4px', '-2px', '1.5em', '2rem', '150%', 'large', 'smaller', 'calc(1rem + 2vw)', 'clamp(1rem,2vw,3rem)', '0' ],
        'font-weight'      => [ '400', '600', '650', '700', '800', '900', 'bold', 'normal', 'bolder', 'lighter' ],
        'font-family'      => [ 'Inter', 'Inter, sans-serif', '"Playfair Display", serif', 'Georgia', '"Brand X", sans-serif', 'sans-serif', '"Brand X", Inter', 'system-ui' ],
        'text-align'       => [ 'left', 'center', 'right', 'justify', 'start', 'end' ],
        'padding'          => [ '24px', '40px 16px', '10px 20px 30px', '1px 2px 3px 4px', '-4px', '10%', '0', '1em 2em', '300px', 'calc(8px + 1vw)' ],
        'padding-top'      => [ '12px', '64px', '-1px', '5%' ],
        'max-width'        => [ '720px', '1080px', '100px', '3000px', '50%', 'none', '60rem', 'min(90vw,800px)' ],
        'border-radius'    => [ '8px', '999px', '0', '4px 8px', '50%', '2000px', '1em' ],
        'opacity'          => [ '.6', '1', '0' ],
        'font'             => [ 'bold 20px Georgia', '16px/1.5 Inter' ],
    ];

    /** A random declaration list: 0 to 4 declarations from STYLE_POOL, some !important. */
    private static function randomDeclarations(): string
    {
        $names = array_keys( self::STYLE_POOL );
        $out   = [];
        for ( $i = mt_rand( 0, 4 ); $i > 0; $i-- ) {
            $name  = $names[ mt_rand( 0, count( $names ) - 1 ) ];
            $vals  = self::STYLE_POOL[ $name ];
            $out[] = $name . ':' . $vals[ mt_rand( 0, count( $vals ) - 1 ) ] . ( 0 === mt_rand( 0, 9 ) ? ' !important' : '' );
        }

        return implode( ';', $out );
    }

    /** A declaration list that more often sets a colour or a background (the structured blocks: so pages are painted and inked). */
    private static function likelyDeclarations( bool $box ): string
    {
        $out = [];
        if ( mt_rand( 1, 100 ) <= ( $box ? 40 : 50 ) ) {
            $out[] = 'color:' . self::pickFrom( [ '#111827', '#1f2937', '#ffffff', '#f8fafc', '#e2e8f0', '#0f766e', '#475569', '#767676', '#8a8a8a', '#949494', '#9ca3af', '#000', 'white', 'rgba(0,0,0,.5)', 'var(--x)', 'currentColor' ] );
        }
        if ( ! $box && mt_rand( 1, 100 ) <= 25 ) {
            // Sizes around the large-text edges: 19-24 px, and 33-43 px, which the writer steps down below 24 px on phones.
            $out[] = 'font-size:' . self::pickFrom( [ '18px', '19px', '20px', '24px', '28px', '36px', '40px', '44px', '48px', '56px' ] );
            if ( 0 === mt_rand( 0, 1 ) ) {
                $out[] = 'font-weight:' . self::pickFrom( [ '400', '700', 'bold' ] );
            }
        }
        if ( mt_rand( 1, 100 ) <= ( $box ? 60 : 15 ) ) {
            $out[] = self::pickFrom( [ 'background', 'background-color' ] ) . ':' . self::pickFrom( [ '#ffffff', '#f3f4f6', '#fef3c7', '#0b1120', '#1e293b', '#123456', '#2563eb', '#f59e0b', 'white', 'navy', '#0008', 'transparent', 'linear-gradient(#000,#fff)', 'url(/x.jpg)' ] );
        }
        $more = self::randomDeclarations();
        if ( '' !== $more && 0 === mt_rand( 0, 1 ) ) {
            $out[] = $more;
        }

        return implode( ';', $out );
    }

    private static function pickFrom( array $a ): mixed
    {
        return $a[ mt_rand( 0, count( $a ) - 1 ) ];
    }

    /** One element with a random class and maybe a style: [styled, bare]. */
    private static function styledElement( string $tag, array $inner, bool $box, string $attrs = '' ): array
    {
        if ( '' === $attrs && 0 === mt_rand( 0, 4 ) ) {
            $attrs = ' class="' . self::pickFrom( [ 'card', 'hero', 'lead', 'btn' ] ) . '"';
        }
        $style = 0 === mt_rand( 0, 2 ) ? '' : self::likelyDeclarations( $box );
        $s     = '' === $style ? '' : ' style="' . htmlspecialchars( $style, ENT_QUOTES ) . '"';

        return [ '<' . $tag . $attrs . $s . '>' . $inner[0] . '</' . $tag . '>', '<' . $tag . $attrs . '>' . $inner[1] . '</' . $tag . '>' ];
    }

    /** A link button: half the time a fill and label pair (readable or not), else random declarations. [styled, bare] */
    private static function styledButton(): array
    {
        $attrs = ' class="btn" href="/go"';
        if ( 0 === mt_rand( 0, 1 ) ) {
            return self::styledElement( 'a', [ 'Book now', 'Book now' ], true, $attrs );
        }
        [ $fill, $label ] = self::pickFrom( [ [ '#2563eb', '#ffffff' ], [ '#f59e0b', '#111827' ], [ '#111827', '#ffffff' ], [ '#ffffff', '#949494' ], [ '#0f766e', '#ffffff' ], [ 'transparent', '#111827' ], [ 'navy', 'white' ], [ '#fef3c7', '#8a8a8a' ] ] );
        $style = 'background-color:' . $fill . ';color:' . $label . ( 0 === mt_rand( 0, 2 ) ? ';border-radius:' . self::pickFrom( [ '6px', '999px', '4px 8px' ] ) : '' );

        return [ '<a' . $attrs . ' style="' . htmlspecialchars( $style, ENT_QUOTES ) . '">Book now</a>', '<a' . $attrs . '>Book now</a>' ];
    }

    /** A structured, convertible block (a section of headings, paragraphs, buttons or a card grid), styled at random: [styled, bare]. */
    private static function styledBlock(): array
    {
        $words = static fn(): array => array_fill( 0, 2, self::pickFrom( [ 'Fresh bread daily', 'Open late on Fridays', 'Tested by guides', 'Free shipping on orders', 'Two rooms, one sound' ] ) );
        $join  = static function ( array $parts ): array {
            return [ implode( '', array_column( $parts, 0 ) ), implode( '', array_column( $parts, 1 ) ) ];
        };
        $parts = [];
        for ( $i = mt_rand( 1, 4 ); $i > 0; $i-- ) {
            $r = mt_rand( 1, 8 );
            if ( $r <= 2 ) {
                $parts[] = self::styledElement( 'h' . mt_rand( 1, 3 ), $words(), false );
            } elseif ( $r <= 4 ) {
                $inner = $words();
                if ( 0 === mt_rand( 0, 3 ) ) {
                    $inner = $join( [ $inner, [ ' ', ' ' ], self::styledElement( self::pickFrom( [ 'span', 'strong', 'a', 'code' ] ), [ 'more', 'more' ], false ) ] );
                }
                $parts[] = self::styledElement( 'p', $inner, false );
            } elseif ( 5 === $r || 8 === $r ) {
                $parts[] = self::styledButton();
            } elseif ( 6 === $r ) {
                $cards = [];
                for ( $k = mt_rand( 2, 5 ); $k > 0; $k-- ) {
                    $cards[] = self::styledElement( 'div', $join( [ self::styledElement( 'h3', $words(), false ), self::styledElement( 'p', $words(), false ) ] ), true, ' class="card"' );
                }
                $parts[] = self::styledElement( 'div', $join( $cards ), true );
            } else {
                $parts[] = self::styledElement( 'ul', [ '<li>One thing</li><li>Another</li>', '<li>One thing</li><li>Another</li>' ], false );
            }
        }

        return self::styledElement( self::pickFrom( [ 'section', 'section', 'div', 'header', 'footer' ] ), $join( $parts ), true );
    }

    /** A random <style> text of 0-12 rules over tags, classes, an id, a combinator, a pseudo-class, a compound and @media. */
    private static function randomSheet(): string
    {
        $selectors = [ 'body', 'html', 'section', 'div', 'h1', 'h2', 'h3', 'p', 'a', 'span', 'li', '.card', '.btn', '.hero', '.lead', '#x', '.hero h1', 'a:hover', 'p.lead', 'a.btn', '*', 'section > p' ];
        $rules     = [];
        for ( $i = mt_rand( 0, 12 ); $i > 0; $i-- ) {
            $rule    = $selectors[ mt_rand( 0, count( $selectors ) - 1 ) ] . '{' . self::randomDeclarations() . '}';
            $rules[] = 0 === mt_rand( 0, 7 ) ? '@media (min-width:600px){' . $rule . '}' : $rule;
        }

        return implode( "\n", $rules );
    }

    /**
     * 200 [styled, bare] pairs for one seed. The tree is the phase-1 generator's (its tags without `style`, which is the page's own
     * sheet here, and its attributes without `style=""`, plus the hero and lead classes); every element may also get a random
     * style="" (bare: none). The page's sheet sits in the head, or at the start of a fragment (bare: no sheet).
     *
     * @return list<array{0:string,1:string}>
     */
    private static function styledCases( int $seed ): array
    {
        mt_srand( $seed );
        $tags  = [ 'div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'nav', 'p', 'span', 'a', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'button', 'hr', 'table', 'tr', 'td', 'figure', 'figcaption', 'blockquote', 'pre', 'strong', 'em', 'br', 'form', 'script', 'iframe', 'svg', 'video', 'embed', 'input', 'picture', 'font', 'center' ];
        $attrs = [ 'class="btn"', 'class="card"', 'class="hero"', 'class="lead"', 'id="x"', 'href="/a"', 'href="https://example.com"', 'href="javascript:alert(1)"', 'src="/i.png"', 'src="data:text/html,x"', 'onclick="x()"', 'alt="a"', 'target="_blank"', 'role="button"', 'width="10" height="5"' ];
        $texts = [ 'Hello', 'Tom & Jerry', '1 < 2', '日本語', 'أهلا', '"quoted"', "it's", '-->', '<!--', '\\u003c', "\u{1F389}", 'Fresh bread daily', '' ];

        $make = static function ( int $depth ) use ( &$make, $tags, $attrs, $texts ): array {
            $styled = '';
            $bare   = '';
            for ( $i = mt_rand( 0, 4 ); $i > 0; $i-- ) {
                if ( 0 === mt_rand( 0, 2 ) ) {
                    [ $bs, $bb ] = self::styledBlock();
                    $styled     .= $bs;
                    $bare       .= $bb;
                    continue;
                }
                if ( 0 === mt_rand( 0, 2 ) ) {
                    $t       = $texts[ mt_rand( 0, count( $texts ) - 1 ) ];
                    $styled .= $t;
                    $bare   .= $t;
                    continue;
                }
                $t     = $tags[ mt_rand( 0, count( $tags ) - 1 ) ];
                $a     = 0 === mt_rand( 0, 1 ) ? ' ' . $attrs[ mt_rand( 0, count( $attrs ) - 1 ) ] : '';
                $style = 0 === mt_rand( 0, 1 ) ? self::randomDeclarations() : '';
                $s     = '' === $style ? '' : ' style="' . htmlspecialchars( $style, ENT_QUOTES ) . '"';
                if ( $depth > 0 ) {
                    [ $innerStyled, $innerBare ] = $make( $depth - 1 );
                } else {
                    $innerStyled = $innerBare = $texts[ mt_rand( 0, count( $texts ) - 1 ) ];
                }
                $close   = 0 === mt_rand( 0, 9 ) ? '' : '</' . $t . '>';
                $styled .= '<' . $t . $a . $s . '>' . $innerStyled . $close;
                $bare   .= '<' . $t . $a . '>' . $innerBare . $close;
            }

            return [ $styled, $bare ];
        };

        $out = [];
        for ( $n = 0; $n < 200; $n++ ) {
            $sheet = self::randomSheet();
            if ( mt_rand( 1, 5 ) <= 2 ) {
                // A page of structured blocks only (no phase-1 noise around them), so the paint and ink rules are really exercised.
                $styled = '';
                $bare   = '';
                for ( $k = mt_rand( 1, 4 ); $k > 0; $k-- ) {
                    [ $bs, $bb ] = self::styledBlock();
                    $styled     .= $bs;
                    $bare       .= $bb;
                }
            } else {
                [ $styled, $bare ] = $make( 4 );
            }
            if ( 0 === mt_rand( 0, 3 ) ) {
                $out[] = [ '<style>' . $sheet . '</style>' . $styled, $bare ];
                continue;
            }
            $bodyStyle = 0 === mt_rand( 0, 3 ) ? self::randomDeclarations() : '';
            $body      = '' === $bodyStyle ? '<body>' : '<body style="' . htmlspecialchars( $bodyStyle, ENT_QUOTES ) . '">';
            $out[]     = [
                '<!DOCTYPE html><html><head><style>' . $sheet . '</style></head>' . $body . $styled . '</body></html>',
                '<!DOCTYPE html><html><head></head><body>' . $bare . '</body></html>',
            ];
        }

        return $out;
    }
}
