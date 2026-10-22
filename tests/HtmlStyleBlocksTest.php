<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HtmlMapper;
use AiEditorDivi5\WP\HtmlSource;
use AiEditorDivi5\WP\HtmlStyleBlocks;
use AiEditorDivi5\WP\HtmlStyles;

require_once __DIR__ . '/HtmlTestCase.php';

/** HtmlStyleBlocks: anchors, padding, max width, clamps and the per-module candidate values (no colour decision here). */
final class HtmlStyleBlocksTest extends HtmlTestCase
{
    /** @return array{0:array<string,mixed>,1:array<string,mixed>} [blocks, computed] */
    private function blocks( string $html ): array
    {
        $s = HtmlSource::parse( $html );
        $this->assertNull( $s['error'], (string) $s['error'] );
        $computed = HtmlStyles::resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], HtmlStyles::document( $s ) );

        return [ HtmlStyleBlocks::build( HtmlMapper::map( $s['body'], true ), $computed ), $computed ];
    }

    private static function page( string $css, string $body ): string
    {
        return '<!DOCTYPE html><html><head><style>' . $css . '</style></head><body>' . $body . '</body></html>';
    }

    /** @return array<string,mixed> */
    private static function module( array $blocks, int $section, int $row = 0, int $column = 0, int $index = 0 ): array
    {
        return $blocks['sections'][ $section ]['rows'][ $row ]['columns'][ $column ]['modules'][ $index ];
    }

    public function testAnchorsMaxWidthAndPadding(): void
    {
        [ $b ] = $this->blocks( (string) file_get_contents( self::FIXTURES . '/14-styled-inline.html' ) );
        $hero = $b['sections'][0];
        $this->assertSame( '/html/body/section[1]', $hero['anchor'] );
        $this->assertSame( [ 'state' => 'set', 'value' => '#0f172a' ], $hero['paint'] );
        $this->assertSame( [ 'top' => 96, 'right' => 24, 'bottom' => 96, 'left' => 24 ], $hero['padding'] );
        $this->assertNull( $hero['rows'][0]['anchor'] ); // the modules sit directly in the section

        $row = $b['sections'][1]['rows'][0];
        $this->assertSame( '/html/body/section[2]/div', $row['anchor'] );
        $this->assertSame( 720, $row['max_width'] );
        $this->assertSame( [ 'top' => 40, 'right' => 40, 'bottom' => 40, 'left' => 40 ], $row['padding'] );
        $this->assertSame( [ 'state' => 'none', 'value' => null ], $row['paint'] );
        $this->assertSame( [], $b['sections'][1]['padding'] );

        $h1 = self::module( $b, 0 );
        $this->assertSame( [ 'heading', 56, '800', 'center', true ], [ $h1['type'], $h1['size'], $h1['weight'], $h1['align'], $h1['large'] ] );
        $this->assertSame( [ 'state' => 'set', 'value' => '#ffffff' ], $h1['ink'] );
        $btn = self::module( $b, 0, 0, 0, 2 );
        $this->assertSame( [ 'state' => 'set', 'value' => '#f59e0b' ], $btn['fill'] );
        $this->assertSame( [ 'state' => 'set', 'value' => '#111827' ], $btn['label'] );
        $this->assertSame( 999, $btn['radius'] );
        $this->assertSame( [ 'column' => true, 'row' => true, 'section' => false ], $btn['between'] );

        // A lone heading directly in a section: no row anchor.
        [ $b ] = $this->blocks( self::page( '', '<section style="background:#eeeeee"><h2>Alone</h2></section>' ) );
        $this->assertSame( [ 'anchor' => null, 'paint' => [ 'state' => 'none', 'value' => null ], 'padding' => [], 'max_width' => null ], array_diff_key( $b['sections'][0]['rows'][0], [ 'columns' => 1 ] ) );
    }

    public function testClampsAreAppliedAndCounted(): void
    {
        [ $b ] = $this->blocks( self::page( '', '<section><h2 style="font-size:900px">Huge</h2></section>' ) );
        $this->assertSame( 120, self::module( $b, 0 )['size'] );
        $this->assertSame( 1, $b['clamped'] );

        // NOTE (brief vs code): the brief says the -4px padding is counted `unit`. HtmlCss puts that reason on the declaration, but
        // HtmlStyles' box record keeps only 'unresolved' (no reason) and counts nothing, so this layer can only count it `unresolved`.
        [ $b, $c ] = $this->blocks( self::page( '.neg{padding:-4px}', '<section class="neg"><h2>Pad</h2><p>Text.</p></section>' ) );
        $this->assertSame( [], $b['sections'][0]['padding'] );
        $this->assertSame( [ 'top' => 'unresolved', 'right' => 'unresolved', 'bottom' => 'unresolved', 'left' => 'unresolved' ], $c['nodes']['/html/body/section']['padding'] );
        $this->assertSame( 4, $b['ignored']['unresolved'] ); // the four sides
        $this->assertSame( 0, $b['clamped'] );

        [ $b ] = $this->blocks( self::page( '', '<section><div style="max-width:100px;padding:300px 8px"><h2>Narrow</h2><p>Text.</p></div></section>' ) );
        $this->assertSame( 200, $b['sections'][0]['rows'][0]['max_width'] );
        $this->assertSame( [ 'top' => 200, 'right' => 8, 'bottom' => 200, 'left' => 8 ], $b['sections'][0]['rows'][0]['padding'] );
        $this->assertSame( 3, $b['clamped'] );

        [ $b ] = $this->blocks( self::page( '', '<section><div style="max-width:9000px"><h2 style="font-size:2px">x</h2><a class="btn" href="/" style="border-radius:5000px">Go</a></div></section>' ) );
        $this->assertSame( 2400, $b['sections'][0]['rows'][0]['max_width'] );
        $this->assertSame( 8, self::module( $b, 0 )['size'] );
        $this->assertSame( 999, self::module( $b, 0, 0, 0, 1 )['radius'] );
        $this->assertSame( 3, $b['clamped'] );
    }

    public function testMixedTextPiecesDropOnlyTheDisagreeingProperties(): void
    {
        [ $b ] = $this->blocks( self::page( 'p{font-size:18px}', '<section><p style="color:#111111">One.</p><p style="color:#222222">Two.</p></section>' ) );
        $m = self::module( $b, 0 );
        $this->assertSame( [ '/html/body/section/p[1]', 18, [ 'state' => 'mixed', 'value' => null ] ], [ $m['path'], $m['size'], $m['ink'] ] );
        $this->assertSame( 1, $b['ignored']['mixed'] );

        // Same colour, different sizes: the colour stays, the size goes.
        [ $b ] = $this->blocks( self::page( 'p{color:#111111}', '<section><p style="font-size:18px">One.</p><p style="font-size:20px">Two.</p></section>' ) );
        $m = self::module( $b, 0 );
        $this->assertSame( [ null, [ 'state' => 'set', 'value' => '#111111' ] ], [ $m['size'], $m['ink'] ] );
    }

    public function testInlineDescendantsThatDifferAreCountedAsALoss(): void
    {
        [ $b ] = $this->blocks( self::page( '', '<section><p style="color:#111111">One <span style="color:#ff0000">red</span> word.</p></section>' ) );
        $this->assertSame( [ 'state' => 'set', 'value' => '#111111' ], self::module( $b, 0 )['ink'] );
        $this->assertSame( 1, $b['ignored']['mixed'] );
    }

    public function testTextHoldingContentTheWriterDoesNotInkIsMarked(): void
    {
        [ $b ] = $this->blocks( self::page( '', '<section><p>See <a href="/r">rates</a> and <code>x</code>.</p><ul><li>A</li></ul><blockquote>Q</blockquote><table><tr><td>1</td></tr></table></section>' ) );
        $m = self::module( $b, 0 );
        $this->assertSame( [ 'a', 'code', 'table' ], $m['uninkable'] );
        $this->assertSame( [ 'ul', 'blockquote' ], $m['lists'] );
        // <abbr> is not <a>, <p> is not <pre>.
        [ $b ] = $this->blocks( self::page( '', '<section><p>An <abbr>HTML</abbr> page.</p></section>' ) );
        $this->assertSame( [], self::module( $b, 0 )['uninkable'] );
    }

    public function testWrapperRowsAndColumnsAreTheirOwnElements(): void
    {
        [ $b ] = $this->blocks( self::page( '.card{background:#ffffff;padding:24px;max-width:300px}', '<section style="background:#f3f4f6"><div style="background:#fafafa"><h2>Plans</h2><p>Pick one.</p><a class="btn" href="/x">Buy</a></div></section>'
            . '<section><div class="card"><h3>A</h3><p>One.</p></div><div class="card"><h3>B</h3><p>Two.</p></div></section>' ) );
        $row = $b['sections'][0]['rows'][0];
        $this->assertSame( [ '/html/body/section[1]/div', [ 'state' => 'set', 'value' => '#fafafa' ] ], [ $row['anchor'], $row['paint'] ] );
        $h2 = self::module( $b, 0 );
        $this->assertSame( [ 'column' => true, 'row' => false, 'section' => true ], $h2['between'] ); // the div is between it and the section
        $btn = self::module( $b, 0, 0, 0, 2 );
        $this->assertSame( [ 'column' => true, 'row' => false, 'section' => true ], $btn['between'] );

        $cards = $b['sections'][1]['rows'][0];
        $this->assertNull( $cards['anchor'] ); // the cards' parent is the section itself
        $this->assertCount( 2, $cards['columns'] );
        $this->assertSame( '/html/body/section[2]/div[1]', $cards['columns'][0]['anchor'] );
        $this->assertSame( [ 'state' => 'set', 'value' => '#ffffff' ], $cards['columns'][0]['paint'] );
        $this->assertSame( [ 'top' => 24, 'right' => 24, 'bottom' => 24, 'left' => 24 ], $cards['columns'][1]['padding'] );
        $this->assertSame( [ 'column' => false, 'row' => true, 'section' => true ], self::module( $b, 1, 0, 1, 1 )['between'] );
        $this->assertNull( $cards['max_width'] );
        $this->assertSame( 2, $b['ignored']['not_on_block'] ); // a max width on a card is not carried (plan D6)
    }

    public function testTheSectionPaintWalksUpThroughWrappers(): void
    {
        [ $b ] = $this->blocks( self::page( 'body{background:#0b1120}', '<div><section><h2>A</h2></section><section style="background:url(/x.png)"><h2>B</h2></section><section style="background:#123456"><h2>C</h2></section></div>' ) );
        $this->assertSame( [ 'set', 'unknown', 'set' ], array_map( static fn ( array $s ): string => $s['paint']['state'], $b['sections'] ) );
        $this->assertSame( [ '#0b1120', null, '#123456' ], array_map( static fn ( array $s ): ?string => $s['paint']['value'], $b['sections'] ) );
    }

    public function testRowMaxWidthIsTheSmallestOnTheChainAndUnknownMeansNone(): void
    {
        [ $b ] = $this->blocks( self::page( 'body{max-width:900px}', '<section style="max-width:1100px"><div style="max-width:720px"><h2>A</h2><p>B</p></div></section><section><h2>C</h2><p style="max-width:300px">D</p></section>' ) );
        $this->assertSame( 720, $b['sections'][0]['rows'][0]['max_width'] );
        $this->assertSame( 900, $b['sections'][1]['rows'][0]['max_width'] ); // the section anchor's chain (the p's own max width is not on a block)
        $this->assertSame( 1, $b['ignored']['not_on_block'] );

        [ $b ] = $this->blocks( self::page( 'body{max-width:50%}', '<section style="max-width:720px"><h2>A</h2><p>B</p></section>' ) );
        $this->assertNull( $b['sections'][0]['rows'][0]['max_width'] );
    }

    public function testValuesAreOnlyWhatTheSourceComputes(): void
    {
        [ $b ] = $this->blocks( self::page( 'h1{text-align:left} h2{text-align:justify} p{text-align:right;font-weight:bold}',
            '<section><h1>A</h1><h2>B</h2><p>C</p><h3>D</h3></section>' ) );
        $this->assertSame( [ null, 'justify', 'right', null ], [ self::module( $b, 0, 0, 0, 0 )['align'], self::module( $b, 0, 0, 0, 1 )['align'], self::module( $b, 0, 0, 0, 2 )['align'], self::module( $b, 0, 0, 0, 3 )['align'] ] );
        $this->assertSame( '700', self::module( $b, 0, 0, 0, 2 )['weight'] );
        // UA-decided sizes and weights of a heading are no claim; the initial ink is #000000 with state initial.
        $h3 = self::module( $b, 0, 0, 0, 3 );
        $this->assertSame( [ null, null, [ 'state' => 'initial', 'value' => '#000000' ] ], [ $h3['size'], $h3['weight'], $h3['ink'] ] );
        // A link-button's own UA colour is no label; its parent's ink is.
        [ $b ] = $this->blocks( self::page( 'section{color:#222222}', '<section><a class="btn" href="/">Go</a></section>' ) );
        $btn = self::module( $b, 0 );
        $this->assertSame( [ [ 'state' => 'none', 'value' => null ], [ 'state' => 'set', 'value' => '#222222' ] ], [ $btn['label'], $btn['parent_ink'] ] );
        // A radius with different corners is not carried (the writer sets one radius).
        [ $b ] = $this->blocks( self::page( '', '<section><a class="btn" href="/" style="border-radius:4px 8px">Go</a></section>' ) );
        $this->assertNull( self::module( $b, 0 )['radius'] );
    }

    public function testFamiliesComeFromHtmlFontsOnly(): void
    {
        [ $b ] = $this->blocks( self::page( 'body{font-family:"Brand Sans", Inter, sans-serif} h1{font-family:"Playfair Display", serif} .sys{font-family:system-ui, sans-serif}',
            '<section><h1>A</h1><p>B</p><p class="sys">C</p><pre>x = 1</pre></section>' ) );
        $this->assertSame( 'Playfair Display', self::module( $b, 0, 0, 0, 0 )['family'] );
        $this->assertNull( self::module( $b, 0, 0, 0, 1 )['family'] ); // p, .sys and pre merged into one text: the families differ
        [ $b ] = $this->blocks( self::page( 'body{font-family:"Brand Sans", Inter, sans-serif}', '<section><h1>A</h1><p>B</p></section><section><pre>x = 1</pre></section><section><p style="font-family:system-ui">C</p></section>' ) );
        $this->assertSame( 'Inter', self::module( $b, 0, 0, 0, 1 )['family'] );
        $this->assertNull( self::module( $b, 1 )['family'] ); // pre takes its family from the UA sheet only
        $this->assertNull( self::module( $b, 2 )['family'] );
        $this->assertSame( [ [ 'source' => 'Brand Sans', 'action' => 'not available in Divi: used Inter, the next family in the list' ], [ 'source' => 'Inter', 'action' => 'carried' ] ], $b['fonts'] );
        $this->assertSame( 1, $b['ignored']['generic_font'] );
    }

    public function testImagesAndDividersAreNeutral(): void
    {
        [ $b ] = $this->blocks( self::page( '', '<section style="background:#000"><img src="/a.png" alt="A"><hr><p style="color:#fff">T</p></section>' ) );
        $this->assertSame( [ 'image', 'divider', 'text' ], array_map( static fn ( array $m ): string => $m['type'], $b['sections'][0]['rows'][0]['columns'][0]['modules'] ) );
        $this->assertNull( self::module( $b, 0 )['path'] );
    }

    public function testNothingIsReadWhenTheFeatureDegrades(): void
    {
        [ $b ] = $this->blocks( '<!DOCTYPE html><html><head><style>.x{padding:8px}</style></head><body><section class="x" style="background:#000"><h1 style="color:#fff;font-size:40px">A</h1></section></div></body></html>' );
        $this->assertCount( 1, $b['notes'] );
        $this->assertStringStartsWith( HtmlStyles::REPAIRED_NOTE, $b['notes'][0] );
        $s = $b['sections'][0];
        $this->assertSame( [ null, [ 'state' => 'none', 'value' => null ], [] ], [ $s['anchor'], $s['paint'], $s['padding'] ] );
        $this->assertSame( [ null, 'unresolved' ], [ self::module( $b, 0 )['size'], self::module( $b, 0 )['ink']['state'] ] );
        $this->assertSame( [], $b['ignored'] );
    }

    public function testTheSameInputGivesTheSameBlocks(): void
    {
        foreach ( glob( self::FIXTURES . '/14-styled-*.html' ) ?: [] as $file ) {
            $html = (string) file_get_contents( $file );
            $this->assertSame( serialize( $this->blocks( $html )[0] ), serialize( $this->blocks( $html )[0] ), basename( $file ) );
        }
    }
}
