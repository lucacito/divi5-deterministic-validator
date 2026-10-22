<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CodePolicy;
use AiEditorDivi5\WP\HtmlToDivi;
use AiEditorDivi5\WP\LayoutGate;
use PHPUnit\Framework\Attributes\DataProvider;

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

    /** The colour paths of the writer, per module (DiviMarkup). */
    private const INK = [ 'divi/heading' => 'title.decoration.font.font.desktop.value.color', 'divi/text' => 'content.decoration.bodyFont.body.font.desktop.value.color' ];
    private const PAINT = 'module.decoration.background.desktop.value.color';

    /**
     * A minimal output-only oracle for rules (1) and (2) of the plan's StyledLayoutOracle (Task 10 replaces it with the class):
     * (1) ink only on paint: every heading or text colour has a painted section, row or column around it;
     * (2) paint implies ink: every heading or text under a painted block has a colour.
     *
     * @return list<string>
     */
    private static function inkPaintProblems( string $layout ): array
    {
        $root = ( new \Divi5Validator\BlockParser() )->parse( $layout )->root();
        if ( null === $root ) {
            return [ 'the layout does not parse' ];
        }
        $out  = [];
        $seen = 0;
        $walk = static function ( \Divi5Validator\Block $b, bool $painted ) use ( &$walk, &$out, &$seen ): void {
            $name = $b->name();
            if ( in_array( $name, [ 'divi/section', 'divi/row', 'divi/column' ], true ) && null !== $b->attr( self::PAINT ) ) {
                $painted = true;
            }
            if ( isset( self::INK[ $name ] ) ) {
                $ink = $b->attr( self::INK[ $name ] );
                ++$seen;
                if ( null !== $ink && ! $painted ) {
                    $out[] = "$name #$seen: ink $ink without paint";
                }
                if ( null === $ink && $painted ) {
                    $out[] = "$name #$seen: on paint without ink";
                }
            }
            foreach ( $b->children() as $c ) {
                $walk( $c, $painted );
            }
        };
        $walk( $root, false );

        return $out;
    }

    #[DataProvider('styledFixtures')]
    public function testEveryStyledFixtureMeetsTheOracle( string $name ): void
    {
        $r = $this->convert( (string) file_get_contents( self::FIXTURES . '/' . $name . '.html' ) );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [], self::inkPaintProblems( $r['layout'] ), $name );
    }

    public function testTheInlineOracleCatchesBothFaults(): void
    {
        $text = \AiEditorDivi5\WP\DiviMarkup::text( '<p>x</p>', [ 'color' => '#111111' ] );
        $bare = \AiEditorDivi5\WP\DiviMarkup::text( '<p>x</p>' );
        $page = static fn( string $mod, array $sectionStyle ): string => \AiEditorDivi5\WP\DiviMarkup::page( [ \AiEditorDivi5\WP\DiviMarkup::section( '', \AiEditorDivi5\WP\DiviMarkup::row( [ $mod ] ), $sectionStyle ) ] );
        $this->assertSame( [ 'divi/text #1: ink #111111 without paint' ], self::inkPaintProblems( $page( $text, [] ) ) );
        $this->assertSame( [ 'divi/text #1: on paint without ink' ], self::inkPaintProblems( $page( $bare, [ 'background' => '#ffffff' ] ) ) );
        $this->assertSame( [], self::inkPaintProblems( $page( $text, [ 'background' => '#ffffff' ] ) ) );
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
}
