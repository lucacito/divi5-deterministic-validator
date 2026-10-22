<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HtmlMapper;
use AiEditorDivi5\WP\HtmlSource;
use PHPUnit\Framework\Attributes\DataProvider;

/** The rules that decide sections, rows and columns. */
final class HtmlMapperTest extends HtmlTestCase
{
    /** @return array<string,array{0:int,1:list<int>}> */
    public static function widths(): array
    {
        return [
            '1' => [ 1, [ 1 ] ], '2' => [ 2, [ 2 ] ], '3' => [ 3, [ 3 ] ], '4' => [ 4, [ 4 ] ],
            '5' => [ 5, [ 3, 2 ] ], '6' => [ 6, [ 3, 3 ] ], '7' => [ 7, [ 4, 3 ] ], '8' => [ 8, [ 4, 4 ] ],
            '9' => [ 9, [ 3, 3, 3 ] ], '10' => [ 10, [ 4, 3, 3 ] ], '11' => [ 11, [ 4, 4, 3 ] ], '12' => [ 12, [ 4, 4, 4 ] ],
        ];
    }

    #[DataProvider('widths')]
    public function testRowWidths( int $n, array $expected ): void
    {
        $this->assertSame( $expected, HtmlMapper::rowWidths( $n ) );
        $this->assertSame( $n, array_sum( HtmlMapper::rowWidths( $n ) ) );
        $this->assertLessThanOrEqual( 4, max( HtmlMapper::rowWidths( $n ) ) );
    }

    public function testLandmarksBecomeSections(): void
    {
        $plan = $this->plan( '<header><h1>Site</h1></header><section><h2>One</h2><p>a</p></section><section><h2>Two</h2><p>b</p></section><footer><p>foot</p></footer>' );
        $this->assertSame( [ 'Header', 'One', 'Two', 'Footer' ], array_column( $plan['sections'], 'label' ) );
    }

    public function testWrappersAroundSectionsAreLookedThrough(): void
    {
        $plan = $this->plan( '<div id="app"><div class="wrap"><main><section><h2>A</h2></section><section><h2>B</h2></section></main></div></div>' );
        $this->assertSame( [ 'A', 'B' ], array_column( $plan['sections'], 'label' ) );
    }

    public function testASingleSectionIsNotUnwrapped(): void
    {
        $plan = $this->plan( '<div><h1>T</h1><p>x</p></div>' );
        $this->assertCount( 1, $plan['sections'] );
        $this->assertSame( [ 'heading', 'text' ], $this->types( $plan ) );
    }

    public function testLooseTopLevelContentFormsOneSection(): void
    {
        $plan = $this->plan( '<h1>Title</h1><p>one</p><p>two</p><img src="/a.png" alt="a"><a href="/go" class="btn">Go</a>' );
        $this->assertCount( 1, $plan['sections'] );
        $this->assertSame( [ 'heading', 'text', 'image', 'button' ], $this->types( $plan ) );
        $this->assertStringContainsString( '<p>one</p><p>two</p>', $plan['sections'][0]['chunks'][0]['modules'][1]['html'], 'consecutive paragraphs are one text module' );
    }

    public function testNavigationIsFlaggedNotConverted(): void
    {
        $plan = $this->plan( '<nav><a href="/a">A</a><a href="/b">B</a></nav><section><h2>Body</h2></section>' );
        $this->assertSame( [ 'Body' ], array_column( $plan['sections'], 'label' ) );
        $this->assertSame( 'nav', $plan['flagged'][0]['type'] );
        $this->assertStringContainsString( '2 link(s)', $plan['flagged'][0]['reason'] );
    }

    public function testNavInsideASectionIsFlaggedToo(): void
    {
        $plan = $this->plan( '<section><nav><a href="/a">A</a></nav><h2>Body</h2></section>' );
        $this->assertSame( [ 'heading' ], $this->types( $plan ) );
        $this->assertSame( 'nav', $plan['flagged'][0]['type'] );
    }

    /** @return array<string,array{0:int}> */
    public static function counts(): array
    {
        return [ 'two' => [ 2 ], 'three' => [ 3 ], 'four' => [ 4 ], 'five' => [ 5 ], 'six' => [ 6 ], 'twelve' => [ 12 ] ];
    }

    #[DataProvider('counts')]
    public function testSimilarCardsBecomeColumns( int $n ): void
    {
        $cards = '';
        for ( $i = 1; $i <= $n; $i++ ) {
            $cards .= '<div class="c"><h3>Card ' . $i . '</h3><p>Text ' . $i . '</p></div>';
        }
        $plan  = $this->plan( '<section><h2>Features</h2>' . $cards . '</section>' );
        $chunk = $plan['sections'][0]['chunks'][1];
        $this->assertSame( 'cards', $chunk['kind'] );
        $this->assertCount( $n, $chunk['columns'] );
        foreach ( $chunk['columns'] as $i => $col ) {
            $this->assertSame( [ 'heading', 'text' ], array_column( $col, 'type' ) );
            $this->assertSame( 'Card ' . ( $i + 1 ), $col[0]['text'], 'order is kept' );
        }
    }

    public function testCardsInsideAWrapperAreFoundToo(): void
    {
        $plan = $this->plan( '<section><h2>F</h2><div class="grid"><div><h3>A</h3><p>a</p></div><div><h3>B</h3><p>b</p></div></div></section>' );
        $this->assertSame( 'cards', $plan['sections'][0]['chunks'][1]['kind'] );
    }

    public function testThirteenSiblingsAreNotAGrid(): void
    {
        $cards = str_repeat( '<div><h3>T</h3><p>x</p></div>', 13 );
        $plan  = $this->plan( '<section>' . $cards . '</section>' );
        $this->assertSame( 'single', $plan['sections'][0]['chunks'][0]['kind'] );
    }

    /** @return array<string,array{0:string}> */
    public static function notCards(): array
    {
        return [
            'one card'                    => [ '<div><h3>A</h3><p>a</p></div>' ],
            'text only divs'              => [ '<div>one</div><div>two</div><div>three</div>' ],
            'paragraph siblings'          => [ '<p>one</p><p>two</p><p>three</p>' ],
            'different tags'              => [ '<div><h3>A</h3><p>a</p></div><section><h3>B</h3><p>b</p></section>' ],
            'unrelated blocks'            => [ '<div><h3>A</h3><p>a</p></div><div><img src="/x.png" alt=""></div><div><a href="/y" class="btn">Go</a></div>' ],
            'empty divs'                  => [ '<div></div><div></div>' ],
        ];
    }

    #[DataProvider('notCards')]
    public function testThingsThatAreNotGridsStayInOneColumn( string $html ): void
    {
        $plan = $this->plan( '<section>' . $html . '</section>' );
        foreach ( $plan['sections'][0]['chunks'] ?? [] as $chunk ) {
            $this->assertSame( 'single', $chunk['kind'], $html );
        }
    }

    public function testAGalleryOfImagesBecomesColumns(): void
    {
        $plan = $this->plan( '<section><div><img src="/a.png" alt="a"></div><div><img src="/b.png" alt="b"></div><div><img src="/c.png" alt="c"></div></section>' );
        $this->assertSame( 'cards', $plan['sections'][0]['chunks'][0]['kind'] );
        $this->assertCount( 3, $plan['sections'][0]['chunks'][0]['columns'] );
    }

    public function testAListOfFeaturesBecomesColumnsButAPlainListStaysAList(): void
    {
        $cards = $this->plan( '<ul><li><h3>A</h3><p>a</p></li><li><h3>B</h3><p>b</p></li></ul>' );
        $this->assertSame( 'cards', $cards['sections'][0]['chunks'][0]['kind'] );

        $list = $this->plan( '<ul><li>one</li><li>two</li><li>three</li></ul>' );
        $this->assertSame( [ 'text' ], $this->types( $list ) );
        $this->assertStringContainsString( '<ul><li>one</li>', $list['sections'][0]['chunks'][0]['modules'][0]['html'] );
    }

    public function testNoGridsInsideCards(): void
    {
        $plan = $this->plan( '<section><div><h3>A</h3><div><h4>x</h4><p>1</p></div><div><h4>y</h4><p>2</p></div></div><div><h3>B</h3><p>b</p></div></section>' );
        $chunk = $plan['sections'][0]['chunks'][0];
        $this->assertSame( 'cards', $chunk['kind'] );
        $this->assertSame( [ 'heading', 'heading', 'text', 'heading', 'text' ], array_column( $chunk['columns'][0], 'type' ), 'the inner group is flattened into the card' );
    }

    public function testOnlyTheFirstH1KeepsItsLevel(): void
    {
        $plan = $this->plan( '<h1>One</h1><h1>Two</h1><h1>Three</h1>' );
        $levels = array_column( $plan['sections'][0]['chunks'][0]['modules'], 'level' );
        $this->assertSame( [ 1, 2, 2 ], $levels );
        $this->assertStringContainsString( '2 extra h1', implode( ' ', $plan['notes'] ) );
    }

    public function testHeadingLevelsAreKeptAndEmptyHeadingsSkipped(): void
    {
        $plan = $this->plan( '<h2>A</h2><h3>B</h3><h6>C</h6><h2> </h2>' );
        $this->assertSame( [ 2, 3, 6 ], array_column( $plan['sections'][0]['chunks'][0]['modules'], 'level' ) );
    }

    public function testAHeadingWithALinkIsFlaggedBecauseTheLinkIsLost(): void
    {
        $plan = $this->plan( '<h2><a href="/x">Linked</a></h2>' );
        $this->assertSame( 'link', $plan['flagged'][0]['type'] );
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function buttons(): array
    {
        return [
            'btn class'          => [ '<p>x</p><a href="/a" class="btn">Go</a>', 'button' ],
            'button class'       => [ '<p>x</p><a href="/a" class="button primary">Go</a>', 'button' ],
            'cta class'          => [ '<p>x</p><a href="/a" class="hero-cta">Go</a>', 'button' ],
            'btn-primary'        => [ '<p>x</p><a href="/a" class="btn-primary">Go</a>', 'button' ],
            'role button'        => [ '<p>x</p><a href="/a" role="button">Go</a>', 'button' ],
            'button element'     => [ '<p>x</p><button>Go</button>', 'button' ],
            'plain link in text' => [ '<p>read <a href="/a">this</a> now</p>', 'text' ],
            'link next to text'  => [ '<p>x</p><a href="/a">Go</a> and more words', 'text' ],
            'unrelated class'    => [ '<p>x</p><a href="/a" class="abutton-ish">Go</a><a href="/b">Two</a>', 'text' ],
        ];
    }

    #[DataProvider('buttons')]
    public function testButtonDetection( string $html, string $expectedLastType ): void
    {
        $types = $this->types( $this->plan( $html ) );
        $this->assertSame( $expectedLastType, end( $types ) );
    }

    public function testALoneLinkInABlockIsAButton(): void
    {
        $plan = $this->plan( '<section><h2>T</h2><div><a href="/start">Start now</a></div></section>' );
        $this->assertSame( [ 'heading', 'button' ], $this->types( $plan ) );
    }

    public function testAButtonWithoutALinkIsFlagged(): void
    {
        $plan = $this->plan( '<button>Buy</button>' );
        $this->assertSame( 'button', $plan['flagged'][0]['type'] );
        $this->assertNull( $plan['sections'][0]['chunks'][0]['modules'][0]['href'] );
    }

    public function testImagesPicturesAndLinkedImages(): void
    {
        $plan = $this->plan( '<img src="/a.png" alt="A" width="800" height="400"><picture><source srcset="x"><img src="/b.png" alt="B"></picture><a href="/big"><img src="/c.png" alt="C"></a>' );
        $mods = $plan['sections'][0]['chunks'][0]['modules'];
        $this->assertSame( [ 'image', 'image', 'image' ], array_column( $mods, 'type' ) );
        $this->assertSame( [ 'A', 'B', 'C' ], array_column( $mods, 'alt' ) );
        $this->assertSame( 800, $mods[0]['width'] );
        $this->assertSame( '/big', $mods[2]['href'] );
    }

    public function testAnImageInsideAParagraphStaysAnImage(): void
    {
        $plan = $this->plan( '<p><img src="/a.png" alt="A"></p><p>text</p>' );
        $this->assertSame( [ 'image', 'text' ], $this->types( $plan ) );
    }

    public function testLooseTextAndInlineRunsBecomeParagraphs(): void
    {
        $plan = $this->plan( '<div>Hello <strong>bold</strong> and <a href="/x">link</a><span> tail</span></div>' );
        $this->assertSame( [ 'text' ], $this->types( $plan ) );
        $this->assertSame( '<p>Hello <strong>bold</strong> and <a href="/x">link</a> tail</p>', $plan['sections'][0]['chunks'][0]['modules'][0]['html'] );
    }

    public function testDividersAndFigures(): void
    {
        $plan = $this->plan( '<p>a</p><hr><figure><img src="/f.png" alt="F"><figcaption>Caption</figcaption></figure>' );
        $this->assertSame( [ 'text', 'divider', 'image', 'text' ], $this->types( $plan ) );
    }

    public function testEmptyAndWhitespaceOnlyContentMakesNoSections(): void
    {
        $this->assertSame( [], $this->plan( '<div> </div><section></section><p> </p>' )['sections'] );
    }

    public function testTheSameInputGivesTheSamePlan(): void
    {
        $html = '<section><h2>F</h2><div><h3>A</h3><p>a</p></div><div><h3>B</h3><p>b</p></div></section><footer><p>f</p></footer>';
        $this->assertSame( $this->plan( $html ), $this->plan( $html ) );
    }

    // ── sources (html_to_divi styles, task 4) ──────────────────────────────

    private function body( string $bodyHtml ): \DOMElement
    {
        $s = HtmlSource::parse( '<html><body>' . $bodyHtml . '</body></html>' );
        $this->assertNull( $s['error'], (string) $s['error'] );

        return $s['body'];
    }

    /** The source keys removed. An image module's own `src` (its URL) is not one: a source key is an array (a module) or a section's. */
    private static function withoutSources( array $v ): array
    {
        $out = [];
        foreach ( $v as $k => $x ) {
            $isImageUrl = 'src' === $k && is_string( $x ) && 'image' === ( $v['type'] ?? '' );
            if ( in_array( $k, [ 'src', 'ctx', 'column_src', 'container' ], true ) && ! $isImageUrl ) {
                continue;
            }
            $out[ $k ] = is_array( $x ) ? self::withoutSources( $x ) : $x;
        }

        return $out;
    }

    public function testSourcesAreOnlyAddedWhenAskedFor(): void
    {
        $files = glob( self::FIXTURES . '/*.html' );
        $this->assertNotEmpty( $files );
        $withSources = 0;
        foreach ( $files as $file ) {
            $s = HtmlSource::parse( (string) file_get_contents( $file ) );
            if ( null !== $s['error'] ) {
                continue;
            }
            $plain = HtmlMapper::map( $s['body'] );
            $this->assertSame( $plain, HtmlMapper::map( $s['body'], false ), basename( $file ) );
            $with = HtmlMapper::map( $s['body'], true );
            $this->assertSame( $plain, self::withoutSources( $with ), basename( $file ) );
            if ( array() !== $with['sections'] ) {
                ++$withSources;
                $this->assertArrayHasKey( 'src', $with['sections'][0], basename( $file ) );
                $this->assertArrayNotHasKey( 'src', $plain['sections'][0], basename( $file ) );
                $this->assertArrayNotHasKey( 'ctx', $plain['sections'][0], basename( $file ) );
            }
        }
        $this->assertGreaterThan( 5, $withSources );
    }

    public function testTextModulesRecordEveryMergedPiece(): void
    {
        $plan = HtmlMapper::map( $this->body( '<div><p>a</p>text <em>b</em><ul><li>c</li></ul></div>' ), true );
        $mods = $plan['sections'][0]['chunks'][0]['modules'];
        $this->assertCount( 1, $mods );
        $this->assertSame( [ '/html/body/div/p', '/html/body/div', '/html/body/div/ul' ], $mods[0]['src'] );
        $this->assertSame( [ '/html/body/div', '/html/body/div', '/html/body/div' ], $mods[0]['container'] );
    }

    public function testASectionAndItsModulesRecordTheirElements(): void
    {
        $plan = HtmlMapper::map( $this->body( '<section><h2>T</h2><a class="btn" href="/x">Go</a><img src="/a.png" alt="A"><hr></section><p>loose</p>' ), true );
        $this->assertSame( '/html/body/section', $plan['sections'][0]['src'] );
        $this->assertSame( '/html/body/section', $plan['sections'][0]['ctx'] );
        $mods = $plan['sections'][0]['chunks'][0]['modules'];
        $this->assertSame( [ 'heading', 'button', 'image', 'divider' ], array_column( $mods, 'type' ) );
        $this->assertSame( [ '/html/body/section/h2' ], $mods[0]['src'] );
        $this->assertSame( [ '/html/body/section' ], $mods[0]['container'] );
        $this->assertSame( [ '/html/body/section/a' ], $mods[1]['src'] );
        $this->assertSame( [ '/html/body/section' ], $mods[1]['container'] );
        $this->assertSame( '/a.png', $mods[2]['src'], 'an image keeps its URL in src and gets no source' );
        $this->assertArrayNotHasKey( 'container', $mods[2] );
        $this->assertSame( [ 'type' => 'divider' ], $mods[3] );
        $this->assertNull( $plan['sections'][1]['src'], 'a run of loose nodes has no section element' );
        $this->assertSame( '/html/body', $plan['sections'][1]['ctx'] );
        $this->assertSame( [ '/html/body/p' ], $plan['sections'][1]['chunks'][0]['modules'][0]['src'] );
    }

    public function testCardsRecordTheirContainerAndEachCard(): void
    {
        $html = '<section><div class="grid"><div class="card"><h3>A</h3><p>a</p></div><div class="card"><h3>B</h3><p>b</p></div><div class="card"><h3>C</h3><p>c</p></div></div></section>';
        $plan = HtmlMapper::map( $this->body( $html ), true );
        $chunk = $plan['sections'][0]['chunks'][0];
        $this->assertSame( 'cards', $chunk['kind'] );
        $this->assertSame( '/html/body/section/div', $chunk['src'] );
        $this->assertSame( [ '/html/body/section/div/div[1]', '/html/body/section/div/div[2]', '/html/body/section/div/div[3]' ], $chunk['column_src'] );
        $this->assertCount( count( $chunk['columns'] ), $chunk['column_src'] );
    }

    public function testAListOfCardsRecordsTheListAsItsContainer(): void
    {
        $html = '<section><h2>P</h2><ul><li><h3>A</h3><p>a</p></li><li><h3>B</h3><p>b</p></li></ul></section>';
        $plan = HtmlMapper::map( $this->body( $html ), true );
        $cards = array_values( array_filter( $plan['sections'][0]['chunks'], static fn ( array $c ): bool => 'cards' === $c['kind'] ) );
        $this->assertCount( 1, $cards );
        $this->assertSame( '/html/body/section/ul', $cards[0]['src'] );
        $this->assertSame( [ '/html/body/section/ul/li[1]', '/html/body/section/ul/li[2]' ], $cards[0]['column_src'] );
    }

    public function testSourcePathsAreDeterministic(): void
    {
        $html = '<header><h1>S</h1></header><section><h2>F</h2><div><h3>A</h3><p>a</p></div><div><h3>B</h3><p>b</p></div></section><footer><p>f <b>x</b></p></footer>Loose <i>run</i>';
        $this->assertSame( HtmlMapper::map( $this->body( $html ), true ), HtmlMapper::map( $this->body( $html ), true ) );
    }

    public function testEveryRecordedPathResolvesToExactlyOneElement(): void
    {
        foreach ( glob( self::FIXTURES . '/*.html' ) as $file ) {
            $s = HtmlSource::parse( (string) file_get_contents( $file ) );
            if ( null !== $s['error'] ) {
                continue;
            }
            $xp   = new \DOMXPath( $s['body']->ownerDocument );
            $plan = HtmlMapper::map( $s['body'], true );
            $paths = [];
            foreach ( $plan['sections'] as $sec ) {
                $paths[] = $sec['ctx'];
                if ( null !== $sec['src'] ) {
                    $paths[] = $sec['src'];
                }
                foreach ( $sec['chunks'] as $chunk ) {
                    if ( 'cards' === $chunk['kind'] ) {
                        $paths[] = $chunk['src'];
                        array_push( $paths, ...$chunk['column_src'] );
                        $mods = array_merge( ...$chunk['columns'] ?: [ [] ] );
                    } else {
                        $mods = $chunk['modules'];
                    }
                    foreach ( $mods as $m ) {
                        array_push( $paths, ...( 'image' === $m['type'] ? [] : $m['src'] ), ...( $m['container'] ?? [] ) );
                    }
                }
            }
            foreach ( array_unique( $paths ) as $path ) {
                $this->assertSame( 1, $xp->query( $path )->length, basename( $file ) . ' ' . $path );
            }
        }
    }

    /** Hostile, seeded pages: sources never change the plan, never throw, and every recorded path is one element of the returned DOM. */
    public function testRandomPagesKeepThePlanAndTheirPathsHonest(): void
    {
        mt_srand( (int) ( getenv( 'AIED_SEED' ) ?: 20261022 ) );
        $tags  = [ 'div', 'section', 'article', 'header', 'footer', 'main', 'p', 'span', 'a', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'img', 'button', 'hr', 'table', 'tr', 'td', 'figure', 'blockquote', 'pre', 'strong', 'em', 'br', 'form', 'script', 'style', 'embed', 'input', 'font', 'center', 'nav' ];
        $attrs = [ 'class="btn"', 'class="card"', 'style="color:red"', 'style="a:b;;"', 'bgcolor="#000"', 'align="center"', 'color="red"', 'href="/a"', 'src="/i.png"', 'onclick="x()"' ];
        $texts = [ 'Hello', 'Tom & Jerry', '1 < 2', '日本語', '-->', '' ];
        $make  = static function ( int $depth ) use ( &$make, $tags, $attrs, $texts ): string {
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

        $planned = 0;
        for ( $n = 0; $n < 150; $n++ ) {
            $s = HtmlSource::parse( '<html><body style="color:#111">' . $make( 4 ) . '</body></html>' );
            if ( null !== $s['error'] ) {
                continue;
            }
            $xp = new \DOMXPath( $s['body']->ownerDocument );
            foreach ( array_merge( array_keys( $s['inline'] ), array_keys( $s['presentational'] ) ) as $path ) {
                $this->assertSame( 1, $xp->query( $path )->length, "case $n inline $path" );
            }
            $plain = HtmlMapper::map( $s['body'] );
            $with  = HtmlMapper::map( $s['body'], true );
            $this->assertSame( $plain, self::withoutSources( $with ), "case $n" );
            $planned += count( $with['sections'] );
            foreach ( $with['sections'] as $sec ) {
                foreach ( array_filter( [ $sec['src'], $sec['ctx'] ] ) as $path ) {
                    $this->assertSame( 1, $xp->query( $path )->length, "case $n section $path" );
                }
                foreach ( $sec['chunks'] as $chunk ) {
                    foreach ( 'cards' === $chunk['kind'] ? array_merge( [ $chunk['src'] ], $chunk['column_src'] ) : [] as $path ) {
                        $this->assertSame( 1, $xp->query( $path )->length, "case $n cards $path" );
                    }
                }
            }
        }
        $this->assertGreaterThan( 50, $planned );
    }
}
