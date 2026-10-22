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

    /** @return array<string,array{0:string}> */
    public static function goldens(): array
    {
        return [ '01-landing' => [ '01-landing' ], '03-pricing-cards' => [ '03-pricing-cards' ], '13-images' => [ '13-images' ] ];
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

    /** @return array<string,array{0:string,1:string}> name => file under tests/golden/html-to-divi */
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

    /** These pages have no CSS: the default (basic) and none give the very same file. */
    #[DataProvider('goldens')]
    public function testThePhaseOneGoldensAreTheSameInBothModes( string $name ): void
    {
        $html = (string) file_get_contents( self::FIXTURES . '/' . $name . '.html' );
        $file = rtrim( (string) file_get_contents( __DIR__ . '/golden/html-to-divi/' . $name . '.layout.txt' ), "\n" );
        $this->assertSame( $file, $this->convert( $html )['layout'] );
        $this->assertSame( $file, $this->convert( $html, [ 'styles' => 'none' ] )['layout'] );
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

    public function testTheStyleNoteIsOnlyThereWhenStylesAreAskedFor(): void
    {
        $html = '<h1>Hi</h1><p>x</p>';
        $this->assertNotEmpty( array_filter( $this->convert( $html )['report']['notes'], static fn( $n ) => str_contains( $n, 'not converted yet' ) ) );
        $this->assertSame( [], array_filter( $this->convert( $html, [ 'styles' => 'none' ] )['report']['notes'], static fn( $n ) => str_contains( $n, 'not converted yet' ) ) );
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
