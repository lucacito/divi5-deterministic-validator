<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\PageScan;
use AiEditorDivi5\WP\BlockAttrs;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

/**
 * Design switch, Task 2: the page scan (spec section 5.1): every opening block comment in document order, the byte span of
 * its attribute JSON, its parent, the band it sits on; unbalanced input never throws.
 */
class PageScanTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function lang(): array
    {
        $lang = DesignLanguages::get( 'editorial' );
        self::assertIsArray( $lang );

        return $lang;
    }

    private static function bg( string $colour ): string
    {
        return (string) json_encode( [ 'module' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => $colour ] ] ] ] ] ] );
    }

    /** @return list<array{0:string,1:string,2:?int}> [name, band, parent] */
    private static function shape( array $scan ): array
    {
        return array_map( static fn ( array $b ): array => [ $b['name'], $b['band'], $b['parent'] ], $scan );
    }

    public function testNestedBlocksGetTheirParentsAndBands(): void
    {
        $c    = self::lang()['colors'];
        $page = '<!-- wp:divi/section ' . self::bg( $c['accent'] ) . ' -->'
            . '<!-- wp:divi/row -->'
            . '<!-- wp:divi/column ' . self::bg( $c['text'] ) . ' -->'
            . '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go"}}}}} /-->'
            . '<!-- wp:divi/group ' . self::bg( 'transparent' ) . ' --><!-- wp:divi/text /--><!-- /wp:divi/group -->'
            . '<!-- /wp:divi/column -->'
            . '<!-- wp:divi/column --><!-- wp:divi/heading {} /--><!-- /wp:divi/column -->'
            . '<!-- /wp:divi/row -->'
            . '<!-- /wp:divi/section -->'
            . "\n<!-- wp:divi/section -->\n<!-- wp:divi/text {\"a\":\"b\"} /-->\n<!-- /wp:divi/section -->";
        $scan = PageScan::scan( $page, self::lang() );
        $this->assertSame( [
            [ 'divi/section', 'light', null ],
            [ 'divi/row', 'accent', 0 ],
            [ 'divi/column', 'accent', 1 ],
            [ 'divi/button', 'panel', 2 ],
            [ 'divi/group', 'panel', 2 ],
            [ 'divi/text', 'panel', 4 ],
            [ 'divi/column', 'accent', 1 ],
            [ 'divi/heading', 'accent', 6 ],
            [ 'divi/section', 'light', null ],
            [ 'divi/text', 'light', 8 ],
        ], self::shape( $scan ) );
        $this->assertTrue( PageScan::balanced( $page ) );
        foreach ( $scan as $b ) {
            if ( null === $b['json'] ) {
                $this->assertSame( [ -1, 0 ], [ $b['offset'], $b['length'] ] );
                continue;
            }
            $this->assertSame( $b['json'], substr( $page, $b['offset'], $b['length'] ), 'the span is the attribute JSON' );
            $this->assertInstanceOf( \stdClass::class, json_decode( $b['json'] ) );
        }
        $this->assertNull( $scan[1]['json'], 'a block without attributes' );
        $this->assertSame( '{}', $scan[7]['json'] );
        $this->assertSame( '{"a":"b"}', $scan[9]['json'] );
    }

    public function testSelfClosingFormsAndWhitespace(): void
    {
        $page = "<!-- wp:divi/divider /-->x<!--\twp:divi/icon\n{\"a\":1}\n/-->y<!--  wp:divi/text  -->z<!-- /wp:divi/text  -->"
            . '<!-- wp:paragraph --><p>core</p><!-- /wp:paragraph --><!-- wp:core/image {"id":1} /-->';
        $scan = PageScan::scan( $page, self::lang() );
        $this->assertSame( [ 'divi/divider', 'divi/icon', 'divi/text', 'paragraph', 'core/image' ], array_column( $scan, 'name' ) );
        $this->assertSame( [ null, null, null, null, null ], array_column( $scan, 'parent' ), 'a self-closing block opens nothing' );
        $this->assertSame( '{"a":1}', $scan[1]['json'] );
        $this->assertSame( '{"id":1}', $scan[4]['json'] );
        $this->assertTrue( PageScan::balanced( $page ) );
    }

    public function testCommentsThatAreNotBlocksAreSkipped(): void
    {
        $page = '<!-- a note --><!--wp:divi/text--><!-- wp:Divi/Text /--><!-- wp:divi/text{"a":1} /--><!-- wp:divi/text {"a":1}/-->'
            . '<!-- wp:divi/text x -->' . '<!-- wp:divi/text /-->' . '<!-- unterminated';
        $scan = PageScan::scan( $page, self::lang() );
        $this->assertSame( [ 'divi/text' ], array_column( $scan, 'name' ) );
        $this->assertTrue( PageScan::balanced( $page ) );
    }

    public function testATerminatorInsideAJsonStringDoesNotEndTheComment(): void
    {
        $attrs = '{"a":{"b":"x --> y } -->","c":"}"},"d":[1,"-->"]}';
        $page  = '<!-- wp:divi/section ' . $attrs . ' --><!-- wp:divi/text {"t":"<!-- wp:divi/fake /-->"} /--><!-- /wp:divi/section -->';
        $scan  = PageScan::scan( $page, self::lang() );
        $this->assertSame( [ 'divi/section', 'divi/text' ], array_column( $scan, 'name' ), 'a block comment inside a string is not a block' );
        $this->assertSame( $attrs, $scan[0]['json'] );
        $this->assertSame( strpos( $page, $attrs ), $scan[0]['offset'] );
        $this->assertSame( '{"t":"<!-- wp:divi/fake /-->"}', $scan[1]['json'] );
        $this->assertSame( 0, $scan[1]['parent'] );
        $this->assertTrue( PageScan::balanced( $page ) );
    }

    public function testInvalidAttributesKeepTheFreeScannersSpan(): void
    {
        $page = '<!-- wp:divi/section {"a":1,} --><!-- wp:divi/text {bad} /--><!-- /wp:divi/section -->';
        $scan = PageScan::scan( $page, self::lang() );
        $this->assertSame( [ 'divi/section', 'divi/text' ], array_column( $scan, 'name' ) );
        $this->assertSame( '{"a":1,}', $scan[0]['json'] );
        $this->assertSame( '{bad}', $scan[1]['json'] );
        $this->assertSame( 0, $scan[1]['parent'] );
        preg_match_all( BlockAttrs::BLOCK, $page, $m );
        $this->assertSame( $m[3], array_column( $scan, 'json' ), 'the same span the free resolvers see' );
    }

    public function testUnbalancedInputIsScannedToTheEnd(): void
    {
        $lang = self::lang();
        $more = '<!-- /wp:divi/section --><!-- wp:divi/section ' . self::bg( $lang['colors']['accent'] ) . ' --><!-- wp:divi/text /--><!-- /wp:divi/section --><!-- /wp:divi/row --><!-- wp:divi/button /-->';
        $scan = PageScan::scan( $more, $lang );
        $this->assertSame( [ [ 'divi/section', 'light', null ], [ 'divi/text', 'accent', 0 ], [ 'divi/button', 'light', null ] ], self::shape( $scan ) );
        $this->assertFalse( PageScan::balanced( $more ) );

        $open = '<!-- wp:divi/section --><!-- wp:divi/row -->';
        $this->assertCount( 2, PageScan::scan( $open, $lang ) );
        $this->assertFalse( PageScan::balanced( $open ), 'an unclosed block' );

        $crossed = '<!-- wp:divi/section ' . self::bg( $lang['colors']['accent'] ) . ' --><!-- wp:divi/row --><!-- /wp:divi/section --><!-- wp:divi/text /--><!-- /wp:divi/row --><!-- /wp:divi/section -->';
        $this->assertSame( [ [ 'divi/section', 'light', null ], [ 'divi/row', 'accent', 0 ], [ 'divi/text', 'accent', 1 ] ], self::shape( PageScan::scan( $crossed, $lang ) ), 'a closer that is not the innermost block is ignored' );
        $this->assertFalse( PageScan::balanced( $crossed ) );

        $this->assertSame( [], PageScan::scan( '', $lang ) );
        $this->assertTrue( PageScan::balanced( '' ) );
        $this->assertSame( [], PageScan::scan( 'plain <p>text</p> <!-- /wp:divi/x -->', $lang ) );
    }

    public function testTheScanFindsWhatTheFreeScannerFindsOnTheLibrary(): void
    {
        $lang  = self::lang();
        $count = 0;
        foreach ( SectionRecipes::names() as $name ) {
            foreach ( [ (string) SectionRecipes::rawMarkup( $name ), (string) SectionRecipes::recipe( $name, 'editorial' ) ] as $page ) {
                $scan = PageScan::scan( $page, $lang );
                $this->assertTrue( PageScan::balanced( $page ), $name );
                preg_match_all( BlockAttrs::BLOCK, $page, $m, PREG_OFFSET_CAPTURE );
                $withAttrs = array_values( array_filter( $scan, static fn ( array $b ): bool => null !== $b['json'] ) );
                $this->assertSame( array_column( $m[3], 1 ), array_column( $withAttrs, 'offset' ), $name );
                $this->assertSame( array_column( $m[3], 0 ), array_column( $withAttrs, 'json' ), $name );
                $this->assertSame( preg_match_all( '/<!--\s+wp:/', $page ), count( $scan ), $name . ': every opening comment' );
                $count += count( $scan );
            }
        }
        $this->assertGreaterThan( 2000, $count );
    }

    public function testTheSameInputGivesTheSameScan(): void
    {
        $page = (string) SectionRecipes::recipe( 'pricing-three-tier', 'luxe' );
        $lang = DesignLanguages::get( 'luxe' );
        $this->assertIsArray( $lang );
        $this->assertSame( serialize( PageScan::scan( $page, $lang ) ), serialize( PageScan::scan( $page, $lang ) ) );
    }
}
