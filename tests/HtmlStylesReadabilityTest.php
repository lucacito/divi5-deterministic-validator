<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\DiviMarkup;
use AiEditorDivi5\WP\HtmlMapper;
use AiEditorDivi5\WP\HtmlReadability;
use AiEditorDivi5\WP\HtmlSource;
use AiEditorDivi5\WP\HtmlStyleBlocks;
use AiEditorDivi5\WP\HtmlStyles;
use Divi5Validator\Tests\Support\Colour;

require_once __DIR__ . '/HtmlTestCase.php';
require_once __DIR__ . '/Support/Colour.php';

/**
 * HtmlReadability: paint and ink travel together (plan D1, D2, D3). Small HtmlStyleBlocks arrays built by hand, end-to-end documents
 * through HtmlSource -> HtmlMapper -> HtmlStyles -> HtmlStyleBlocks, and a seeded property test whose oracle judges only the OUTPUT.
 */
final class HtmlStylesReadabilityTest extends HtmlTestCase
{
    private const DEFAULT_SEED = 20261022;

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>} [blocks, decision, plan] */
    private function pipeline( string $html ): array
    {
        $s = HtmlSource::parse( $html );
        $this->assertNull( $s['error'], (string) $s['error'] );
        $plan     = HtmlMapper::map( $s['body'], true );
        $computed = HtmlStyles::resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], HtmlStyles::document( $s ) );
        $blocks   = HtmlStyleBlocks::build( $plan, $computed );

        return [ $blocks, HtmlReadability::decide( $blocks ), $plan ];
    }

    private static function page( string $css, string $body ): string
    {
        return '<!DOCTYPE html><html><head><style>' . $css . '</style></head><body>' . $body . '</body></html>';
    }

    /** @return array{state:string,value:?string} */
    private static function paint( ?string $colour, string $state = 'set' ): array
    {
        return null === $colour ? [ 'state' => $state, 'value' => null ] : [ 'state' => 'set', 'value' => $colour ];
    }

    /** A candidate heading/text module. @param array<string,mixed> $o @return array<string,mixed> */
    private static function mod( array $o = [] ): array
    {
        return $o + [
            'type' => 'text', 'path' => '/html/body/section/p', 'ink' => [ 'state' => 'set', 'value' => '#111111' ], 'size' => null, 'weight' => null,
            'align' => null, 'family' => null, 'large' => false, 'lists' => [], 'uninkable' => [],
            'between' => [ 'column' => false, 'row' => false, 'section' => false ],
        ];
    }

    /** @param array<string,mixed> $o @return array<string,mixed> */
    private static function btn( array $o = [] ): array
    {
        return $o + self::mod( [ 'type' => 'button', 'path' => '/html/body/section/a', 'ink' => [ 'state' => 'unresolved', 'value' => null ] ] ) + [
            'fill' => self::paint( null, 'none' ), 'label' => [ 'state' => 'none', 'value' => null ], 'parent_ink' => [ 'state' => 'unresolved', 'value' => null ], 'radius' => null,
        ];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private static function sec( array $paint, array $rows ): array
    {
        return [ 'anchor' => '/html/body/section', 'paint' => $paint, 'padding' => [], 'rows' => $rows ];
    }

    /** @param list<array<string,mixed>> $columns @return array<string,mixed> */
    private static function row( array $paint, array $columns ): array
    {
        return [ 'anchor' => null, 'paint' => $paint, 'padding' => [], 'max_width' => null, 'columns' => $columns ];
    }

    /** @param list<array<string,mixed>> $modules @return array<string,mixed> */
    private static function col( array $paint, array $modules ): array
    {
        return [ 'anchor' => null, 'paint' => $paint, 'padding' => [], 'modules' => $modules ];
    }

    /** @param list<array<string,mixed>> $sections @return array<string,mixed> */
    private static function blocks( array $sections ): array
    {
        return [ 'sections' => $sections, 'clamped' => 0, 'ignored' => [], 'fonts' => [], 'notes' => [] ];
    }

    /** Every module style of a decided section, flattened in order. @return list<array<string,mixed>> */
    private static function moduleStyles( array $section ): array
    {
        $out = [];
        foreach ( $section['rows'] as $row ) {
            foreach ( $row['columns'] as $col ) {
                foreach ( $col['modules'] as $m ) {
                    $out[] = $m;
                }
            }
        }

        return $out;
    }

    /** @return array<string,mixed> the dropped entry of $path */
    private function droppedAt( array $decision, string $path ): array
    {
        foreach ( $decision['report']['dropped_unreadable'] as $e ) {
            if ( $e['path'] === $path ) {
                return $e;
            }
        }
        $this->fail( 'no dropped entry for ' . $path . ': ' . json_encode( $decision['report']['dropped_unreadable'] ) );
    }

    // ---------------------------------------------------------------
    // The rule
    // ---------------------------------------------------------------

    public function testContrastIsTheWcagFormula(): void
    {
        $this->assertSame( '4.54', sprintf( '%.2F', DesignLanguages::contrast( '#767676', '#ffffff' ) ) );
        $this->assertSame( '4.48', sprintf( '%.2F', DesignLanguages::contrast( '#777777', '#ffffff' ) ) );
        foreach ( [ [ '#767676', '#ffffff' ], [ '#777777', '#ffffff' ], [ '#949494', '#ffffff' ], [ '#475569', '#0b1120' ], [ '#000000', '#ffffff' ] ] as [ $a, $b ] ) {
            $this->assertSame( Colour::contrast( $a, $b ), HtmlReadability::contrast( $a, $b ) );
        }
        $this->assertGreaterThanOrEqual( HtmlReadability::NORMAL, HtmlReadability::contrast( '#767676', '#ffffff' ) );
        $this->assertLessThan( HtmlReadability::NORMAL, HtmlReadability::contrast( '#777777', '#ffffff' ) );
        $this->assertEqualsWithDelta( 21.0, HtmlReadability::contrast( '#000000', '#ffffff' ), 1e-9 );

        // The edge, through decide(): #767676 is carried on white, #777777 is not.
        foreach ( [ '#767676' => true, '#777777' => false ] as $ink => $carried ) {
            $d = HtmlReadability::decide( self::blocks( [ self::sec( self::paint( '#ffffff' ), [ self::row( self::paint( null, 'none' ), [ self::col( self::paint( null, 'none' ), [ self::mod( [ 'ink' => [ 'state' => 'set', 'value' => $ink ] ] ) ] ) ] ) ] ) ] ) );
            $this->assertSame( $carried, isset( $d['sections'][0]['style']['background'] ), $ink );
            $this->assertSame( $carried ? [ 'color' => $ink ] : [], self::moduleStyles( $d['sections'][0] )[0], $ink );
        }
    }

    public function testADeclaredColourOnAnUnpaintedSectionGetsTheCanvas(): void
    {
        [ , $d ] = $this->pipeline( self::page( 'h1{color:#1d4ed8}', '<section><h1>Fresh bread</h1><p>Baked every morning before six.</p></section>' ) );
        $this->assertSame( [ 'background' => HtmlStyleBlocks::CANVAS ], $d['sections'][0]['style'] );
        $this->assertSame( [ [ 'color' => '#1d4ed8' ], [ 'color' => '#000000' ] ], self::moduleStyles( $d['sections'][0] ) );
        $this->assertSame( [], $d['report']['dropped_unreadable'] );
        $this->assertSame( 1, $d['report']['carried']['background'] );
        $this->assertSame( 2, $d['report']['carried']['color'] );
    }

    public function testAPageWithoutColoursStaysUnstyled(): void
    {
        [ $b, $d ] = $this->pipeline( self::page( 'p{font-weight:400}', '<section><h1>Fresh bread</h1><p>Baked every morning.</p><a class="btn" href="/x">Order</a></section><section><h2>Hours</h2><p>Open daily.</p></section>' ) );
        foreach ( $d['sections'] as $section ) {
            $this->assertArrayNotHasKey( 'background', $section['style'] );
            foreach ( self::moduleStyles( $section ) as $m ) {
                $this->assertArrayNotHasKey( 'color', $m );
                $this->assertArrayNotHasKey( 'background', $m );
            }
        }
        $this->assertSame( [], $d['report']['dropped_unreadable'] );
        $this->assertArrayNotHasKey( 'color', $d['report']['carried'] );
        $this->assertSame( 'none', $b['sections'][0]['paint']['state'] );
    }

    public function testTheDarkPageCarriesItsBackgroundIntoEverySection(): void
    {
        [ , $d ] = $this->pipeline( self::page( 'body{background:#0b1120;color:#e2e8f0}', '<section><h1>After dark</h1><p>Late sessions.</p></section><section><h2>Rooms</h2><p>Two rooms.</p></section><section><h2>Visit</h2><p>Foundry Lane.</p></section>' ) );
        $this->assertCount( 3, $d['sections'] );
        foreach ( $d['sections'] as $section ) {
            $this->assertSame( '#0b1120', $section['style']['background'] ?? null );
            foreach ( self::moduleStyles( $section ) as $m ) {
                $this->assertSame( '#e2e8f0', $m['color'] ?? null );
            }
        }
    }

    public function testUnreadableTextDropsThePaintOfItsChain(): void
    {
        [ , $d ] = $this->pipeline( self::page( 'body{background:#0b1120;color:#e2e8f0} .fine{color:#475569}',
            '<section><h2>Rooms</h2><p>Two rooms.</p></section><section><h2>Small print</h2><p class="fine">Prices exclude VAT.</p></section><section><h2>Visit</h2><p>Foundry Lane.</p></section>' ) );
        $this->assertSame( '#0b1120', $d['sections'][0]['style']['background'] ?? null );
        $this->assertSame( '#0b1120', $d['sections'][2]['style']['background'] ?? null );
        $this->assertArrayNotHasKey( 'background', $d['sections'][1]['style'] );
        foreach ( self::moduleStyles( $d['sections'][1] ) as $m ) {
            $this->assertArrayNotHasKey( 'color', $m );
        }
        // The WCAG ratio of #475569 on #0b1120 is 2.4849 (tests/Support/Colour agrees), reported rounded to two decimals: "2.48".
        $this->assertSame( '2.48', sprintf( '%.2F', Colour::contrast( '#475569', '#0b1120' ) ) );
        $p = $this->droppedAt( $d, '/html/body/section[2]/p' );
        $this->assertSame( [ 'path' => '/html/body/section[2]/p', 'block' => 'text', 'reason' => 'unreadable', 'color' => '#475569', 'background' => '#0b1120', 'ratio' => '2.48' ], $p );
        $h = $this->droppedAt( $d, '/html/body/section[2]/h2' );
        $this->assertSame( 'paint_dropped', $h['reason'] );
        $this->assertSame( '#0b1120', $h['background'] );
        $this->assertSame( 1, $d['report']['ignored']['unreadable'] );
        $this->assertSame( 2, $d['report']['ignored']['paint_dropped'] ); // the heading's colour, and nothing else: the canvas-free section paint came from body
        foreach ( [ 0, 2 ] as $i ) {
            foreach ( self::moduleStyles( $d['sections'][ $i ] ) as $m ) {
                $this->assertSame( '#e2e8f0', $m['color'] );
            }
        }
    }

    public function testAnUnknownBackgroundBetweenTextAndPaintFails(): void
    {
        [ , $d ] = $this->pipeline( self::page( '', '<section style="background:#f8fafc"><h2 style="color:#0f172a">Staff picks</h2><p style="background:#000;color:#fff">Free shipping over one hundred dollars.</p></section>' ) );
        $this->assertSame( [], $d['sections'][0]['style'] );
        $this->assertSame( [ [], [] ], self::moduleStyles( $d['sections'][0] ) );
        $p = $this->droppedAt( $d, '/html/body/section/p' );
        $this->assertSame( 'unknown_background', $p['reason'] );
        $this->assertNull( $p['background'] );
        $this->assertNull( $p['ratio'] );
        $this->assertSame( 'paint_dropped', $this->droppedAt( $d, '/html/body/section/h2' )['reason'] );
    }

    public function testAGradientSectionIsNeverPainted(): void
    {
        [ $b, $d ] = $this->pipeline( self::page( '', '<section style="background:linear-gradient(135deg,#1e3a8a,#7c3aed);color:#fff"><h1 style="color:#ffffff">Gear that goes higher</h1></section>' ) );
        $this->assertSame( 'unknown', $b['sections'][0]['paint']['state'] );
        $this->assertSame( [], $d['sections'][0]['style'] );
        $this->assertSame( [ [] ], self::moduleStyles( $d['sections'][0] ) );
        $this->assertSame( [ 'path' => '/html/body/section/h1', 'block' => 'heading', 'reason' => 'unknown_background', 'color' => '#ffffff', 'background' => null, 'ratio' => null ], $d['report']['dropped_unreadable'][0] );
    }

    public function testLargeTextUsesTheThreeToOneFloor(): void
    {
        $this->assertSame( '3.03', sprintf( '%.2F', DesignLanguages::contrast( '#949494', '#ffffff' ) ) );
        $cases = [ 'font-size:24px' => true, 'font-size:23px' => false, 'font-size:19px;font-weight:700' => true, 'font-size:18px;font-weight:700' => false,
            'font-size:19px;font-weight:600' => false ];
        foreach ( $cases as $css => $carried ) {
            [ , $d ] = $this->pipeline( self::page( '', '<section style="background:#ffffff"><p style="color:#949494;' . $css . '">Grey words.</p></section>' ) );
            $this->assertSame( $carried, '#949494' === ( self::moduleStyles( $d['sections'][0] )[0]['color'] ?? null ), $css );
            $this->assertSame( $carried, isset( $d['sections'][0]['style']['background'] ), $css );
        }
        // The writer steps a size above 32 px down on phones (max(20, 55%)): large is judged at that smallest step.
        $this->assertTrue( HtmlStyleBlocks::isLarge( 24, null ) );
        $this->assertFalse( HtmlStyleBlocks::isLarge( 23, null ) );
        $this->assertTrue( HtmlStyleBlocks::isLarge( 19, '700' ) );
        $this->assertFalse( HtmlStyleBlocks::isLarge( 19, '600' ) );
        $this->assertTrue( HtmlStyleBlocks::isLarge( 32, null ) );
        $this->assertFalse( HtmlStyleBlocks::isLarge( 40, null ) ); // phone step 22 px
        $this->assertTrue( HtmlStyleBlocks::isLarge( 44, null ) );  // phone step 24 px
        $this->assertTrue( HtmlStyleBlocks::isLarge( 33, '700' ) ); // phone step 20 px, bold
        $this->assertFalse( HtmlStyleBlocks::isLarge( null, '900' ) );
    }

    public function testAButtonCarriesItsPairOrNothing(): void
    {
        [ , $d ] = $this->pipeline( self::page( '', '<section><a class="btn" href="/x" style="background:#1d4ed8;color:#ffffff;border-radius:8px">Order now</a></section>' ) );
        $this->assertSame( [ [ 'color' => '#ffffff', 'background' => '#1d4ed8', 'radius' => 8 ] ], self::moduleStyles( $d['sections'][0] ) );
        $this->assertSame( [], $d['sections'][0]['style'] );

        [ , $d ] = $this->pipeline( self::page( '', '<section><a class="btn" href="/x" style="background:#1d4ed8">Order now</a></section>' ) );
        $this->assertSame( [ [] ], self::moduleStyles( $d['sections'][0] ) );
        $this->assertSame( 1, $d['report']['ignored']['unproven'] );

        [ , $d ] = $this->pipeline( self::page( '', '<section><a class="btn" href="/x" style="background:#fde047;color:#ffffff">Order now</a></section>' ) );
        $this->assertSame( [ [] ], self::moduleStyles( $d['sections'][0] ) );
        $e = $this->droppedAt( $d, '/html/body/section/a' );
        $this->assertSame( [ 'button', 'unreadable', '#ffffff', '#fde047' ], [ $e['block'], $e['reason'], $e['color'], $e['background'] ] );
    }

    public function testAnUnpairedButtonOnPaintBecomesAGhost(): void
    {
        [ , $d ] = $this->pipeline( self::page( 'body{background:#0b1120;color:#e2e8f0}', '<section><h2>Tour</h2><a class="btn" href="/studio">Tour the studio</a></section>' ) );
        $this->assertSame( '#0b1120', $d['sections'][0]['style']['background'] );
        $this->assertSame( [ [ 'color' => '#e2e8f0' ], [ 'color' => '#e2e8f0', 'background' => DiviMarkup::TRANSPARENT ] ], self::moduleStyles( $d['sections'][0] ) );
        $this->assertSame( 1, $d['report']['ignored']['button_ghosted'] );
        $this->assertSame( [], $d['report']['dropped_unreadable'] ); // the button declared no colour of its own

        // A pair below the floor on paint becomes a ghost and is listed with its own pair.
        [ , $d ] = $this->pipeline( self::page( 'body{background:#0b1120;color:#e2e8f0}', '<section><h2>Tour</h2><a class="btn" href="/s" style="background:#fde047;color:#ffffff">Tour</a></section>' ) );
        $this->assertSame( [ 'color' => '#e2e8f0', 'background' => DiviMarkup::TRANSPARENT ], self::moduleStyles( $d['sections'][0] )[1] );
        $e = $this->droppedAt( $d, '/html/body/section/a' );
        $this->assertSame( [ 'button_ghosted', '#ffffff', '#fde047' ], [ $e['reason'], $e['color'], $e['background'] ] );

        // A button whose ghost would be unreadable fails and takes the paint with it.
        [ , $d, $plan ] = $this->pipeline( self::page( 'body{background:#0b1120;color:#e2e8f0} .dim{color:#1e293b}', '<section><h2>Tour</h2><div class="dim"><a class="btn" href="/s">Tour</a></div></section>' ) );
        $this->assertSame( 'button', $plan['sections'][0]['chunks'][0]['modules'][1]['type'] );
        $this->assertArrayNotHasKey( 'background', $d['sections'][0]['style'] );
        $this->assertSame( [ [], [] ], self::moduleStyles( $d['sections'][0] ) );
        $this->assertSame( 1, $d['report']['ignored']['unreadable'] ); // the cause is counted although the button declared no colour
        $this->assertSame( 'paint_dropped', $this->droppedAt( $d, '/html/body/section/h2' )['reason'] );
    }

    public function testAButtonWithoutAFillKeepsItsOwnLabelAsAGhost(): void
    {
        // The source draws its own label on the backdrop already: the ghost keeps it (judged against the paint like any text).
        [ , $d ] = $this->pipeline( self::page( 'body{background:#0b1120;color:#e2e8f0} .btn{color:#38bdf8}', '<section><h2>Tour</h2><a class="btn" href="/s">Tour</a></section>' ) );
        $this->assertSame( [ 'color' => '#38bdf8', 'background' => DiviMarkup::TRANSPARENT ], self::moduleStyles( $d['sections'][0] )[1] );
        $this->assertSame( [], $d['report']['dropped_unreadable'] );
        // Its own label unreadable on the paint: the button fails, and is listed with the paint it was judged on.
        [ , $d ] = $this->pipeline( self::page( 'body{background:#0b1120;color:#e2e8f0} .btn{color:#1e293b}', '<section><h2>Tour</h2><a class="btn" href="/s">Tour</a></section>' ) );
        $this->assertArrayNotHasKey( 'background', $d['sections'][0]['style'] );
        $e = $this->droppedAt( $d, '/html/body/section/a' );
        $this->assertSame( [ 'button', 'unreadable', '#1e293b', '#0b1120' ], [ $e['block'], $e['reason'], $e['color'], $e['background'] ] );
    }

    public function testCardsArePaintedOnTheirOwnColumns(): void
    {
        [ , $d ] = $this->pipeline( (string) file_get_contents( self::FIXTURES . '/14-styled-classes.html' ) );
        $features = $d['sections'][1];
        $this->assertSame( '#f3f4f6', $features['style']['background'] );
        $this->assertSame( [ 'top' => '48px', 'right' => '24px', 'bottom' => '48px', 'left' => '24px' ], $features['style']['padding'] );
        $this->assertSame( [ [ 'color' => '#111827', 'size' => 36, 'family' => 'Inter' ] ], $features['rows'][0]['columns'][0]['modules'] );
        $cards = $features['rows'][1]['columns'];
        $this->assertCount( 3, $cards );
        foreach ( $cards as $col ) {
            $this->assertSame( [ 'background' => '#ffffff', 'padding' => [ 'top' => '24px', 'right' => '24px', 'bottom' => '24px', 'left' => '24px' ] ], $col['style'] );
            $this->assertSame( '#111827', $col['modules'][0]['color'] );
            $this->assertSame( '#1f2937', $col['modules'][1]['color'] );
            $this->assertGreaterThanOrEqual( 4.5, DesignLanguages::contrast( $col['modules'][1]['color'], '#ffffff' ) );
        }
        // The intro section is on the canvas; the #intro id wins over .lead and p.
        $this->assertSame( HtmlStyleBlocks::CANVAS, $d['sections'][0]['style']['background'] );
        $this->assertSame( '#0f766e', self::moduleStyles( $d['sections'][0] )[1]['color'] );
        $this->assertSame( [ 'color' => '#ffffff', 'background' => '#2563eb', 'radius' => 8 ], self::moduleStyles( $d['sections'][0] )[8] );
    }

    public function testTheDecisionDoesNotDependOnModuleOrder(): void
    {
        $mods = [
            self::mod( [ 'path' => '/a', 'ink' => [ 'state' => 'set', 'value' => '#111111' ] ] ),
            self::mod( [ 'path' => '/b', 'ink' => [ 'state' => 'set', 'value' => '#f3f4f6' ] ] ),                                            // fails on the column
            self::mod( [ 'path' => '/c', 'ink' => [ 'state' => 'initial', 'value' => '#000000' ], 'between' => [ 'column' => true, 'row' => false, 'section' => false ] ] ),
            self::btn( [ 'path' => '/d', 'parent_ink' => [ 'state' => 'set', 'value' => '#222222' ] ] ),
        ];
        $perms = [ [] ];
        foreach ( $mods as $m ) {
            $next = [];
            foreach ( $perms as $p ) {
                for ( $k = 0; $k <= count( $p ); $k++ ) {
                    $q = $p;
                    array_splice( $q, $k, 0, [ $m ] );
                    $next[] = $q;
                }
            }
            $perms = $next;
        }
        $this->assertCount( 24, $perms );
        $seen = null;
        foreach ( $perms as $p ) {
            $other = self::col( self::paint( '#ffffff' ), [ self::mod( [ 'path' => '/e' ] ) ] );
            $d     = HtmlReadability::decide( self::blocks( [ self::sec( self::paint( '#fafafa' ), [ self::row( self::paint( '#f5f5f5' ), [ self::col( self::paint( '#ffffff' ), $p ), $other ] ) ] ) ] ) );
            $s     = $d['sections'][0];
            $kept  = [ isset( $s['style']['background'] ), isset( $s['rows'][0]['style']['background'] ), isset( $s['rows'][0]['columns'][0]['style']['background'] ), isset( $s['rows'][0]['columns'][1]['style']['background'] ) ];
            $seen ??= $kept;
            $this->assertSame( $seen, $kept );
        }
        $this->assertSame( [ false, false, false, true ], $seen ); // the failing column takes the row and the section with it; the other column stays
    }

    public function testItTerminatesWithinTheNumberOfPaints(): void
    {
        $fail = static fn ( string $p, array $between ): array => self::mod( [ 'path' => $p, 'ink' => [ 'state' => 'set', 'value' => '#fefefe' ], 'between' => $between ] );
        $no   = [ 'column' => false, 'row' => false, 'section' => false ];
        $d    = HtmlReadability::decide( self::blocks( [ self::sec( self::paint( '#ffffff' ), [
            self::row( self::paint( '#fdfdfd' ), [
                self::col( self::paint( '#fcfcfc' ), [ $fail( '/1', $no ), self::mod( [ 'path' => '/ok1' ] ) ] ),
                self::col( self::paint( '#fbfbfb' ), [ $fail( '/2', $no ) ] ),
            ] ),
            self::row( self::paint( null, 'none' ), [ self::col( self::paint( null, 'none' ), [ $fail( '/3', $no ), $fail( '/4', [ 'column' => true, 'row' => true, 'section' => true ] ) ] ) ] ),
        ] ) ] ) );
        $this->assertLessThanOrEqual( 4, $d['report']['passes'] );
        $this->assertGreaterThanOrEqual( 2, $d['report']['passes'] ); // one pass that drops, one that confirms
        $s = $d['sections'][0];
        $this->assertSame( [], $s['style'] );
        $this->assertSame( [], $s['rows'][0]['style'] );
        $this->assertSame( [], $s['rows'][0]['columns'][0]['style'] );
        $this->assertSame( [], $s['rows'][0]['columns'][1]['style'] );
        // 4 source paints dropped plus the one readable ink that lost its column (/ok1); /1 /2 /3 unreadable, /4 on an unknown background.
        $this->assertSame( [ 'paint_dropped' => 5, 'unknown_background' => 1, 'unreadable' => 3 ], $d['report']['ignored'] );
        foreach ( self::moduleStyles( $s ) as $m ) {
            $this->assertSame( [], $m );
        }
    }

    // ---------------------------------------------------------------
    // The carry-forward contract
    // ---------------------------------------------------------------

    public function testALinkOrCodeInsideAPaintedTextIsNotInkable(): void
    {
        foreach ( [ '<a href="/r">our rates</a>', '<code>rate()</code>', '<mark>new</mark>' ] as $inner ) {
            [ , $d ] = $this->pipeline( self::page( 'body{background:#0b1120;color:#e2e8f0}', '<section><h2>Rates</h2><p>See ' . $inner . ' today.</p></section><section><p>Other.</p></section>' ) );
            $this->assertArrayNotHasKey( 'background', $d['sections'][0]['style'], $inner );
            $this->assertSame( [ [], [] ], self::moduleStyles( $d['sections'][0] ), $inner );
            $this->assertSame( 'unproven', $this->droppedAt( $d, '/html/body/section[1]/p' )['reason'], $inner );
            $this->assertSame( '#0b1120', $d['sections'][1]['style']['background'], $inner );
        }
        // Lists and quotes are inkable (their decisions are proven).
        [ , $d ] = $this->pipeline( self::page( 'body{background:#0b1120;color:#e2e8f0}', '<section><h2>Rooms</h2><ul><li>A</li><li>B</li></ul><blockquote>Quiet.</blockquote></section>' ) );
        $this->assertSame( '#0b1120', $d['sections'][0]['style']['background'] );
        $this->assertSame( '#e2e8f0', self::moduleStyles( $d['sections'][0] )[1]['color'] );
    }

    public function testAMixedRunCannotBeInkedOnPaint(): void
    {
        [ , $d ] = $this->pipeline( self::page( 'body{background:#0b1120;color:#e2e8f0} .b{color:#f8fafc}', '<section><h2>Rooms</h2><p>One.</p><p class="b">Two.</p></section>' ) );
        $this->assertArrayNotHasKey( 'background', $d['sections'][0]['style'] );
        $this->assertSame( 'paint_dropped', $this->droppedAt( $d, '/html/body/section/h2' )['reason'] );
        $this->assertSame( 1, $d['report']['ignored']['mixed'] );
    }

    public function testWrapperBackgroundsOfAnyShadeSpreadToTheSectionsTheyWrap(): void
    {
        foreach ( [ '#fef3c7' => '#1f2937', '#111827' => '#f9fafb' ] as $bg => $ink ) {
            [ , $d ] = $this->pipeline( self::page( '', '<div style="background:' . $bg . ';color:' . $ink . '"><section><h2>One</h2><p>First.</p></section><section><h2>Two</h2><p>Second.</p></section></div>' ) );
            $this->assertCount( 2, $d['sections'] );
            foreach ( $d['sections'] as $s ) {
                $this->assertSame( $bg, $s['style']['background'] ?? null, $bg );
                foreach ( self::moduleStyles( $s ) as $m ) {
                    $this->assertSame( $ink, $m['color'] ?? null, $bg );
                }
            }
        }
        // The html element's background reaches loose content too.
        [ , $d ] = $this->pipeline( self::page( 'html{background:#123456;color:#e0f2fe}', '<h1>Loose</h1><p>Text.</p>' ) );
        $this->assertSame( '#123456', $d['sections'][0]['style']['background'] );
    }

    public function testNothingIsCarriedWhenTheMarkupIsRepairedOrTheCssIsCut(): void
    {
        $body = '<section style="background:#0b1120;padding:40px"><h1 style="color:#ffffff;font-size:48px">Hi</h1><p style="color:#e2e8f0">Text.</p></section>';
        $cases = [
            'repaired' => [ '<!DOCTYPE html><html><body>' . $body . '</div></body></html>', HtmlStyles::REPAIRED_NOTE ],
            'css_cap'  => [ self::page( str_repeat( 'p{color:#111111}', 7000 ), $body ), HtmlStyleBlocks::NOTE_CSS_CAP ],
        ];
        foreach ( $cases as $name => [ $html, $note ] ) {
            [ $b, $d ] = $this->pipeline( $html );
            $this->assertNotSame( [], $d['report']['notes'], $name );
            $this->assertStringStartsWith( $note, $d['report']['notes'][0], $name );
            foreach ( $d['sections'] as $s ) {
                $this->assertSame( [], $s['style'], $name );
                foreach ( $s['rows'] as $row ) {
                    $this->assertSame( [], $row['style'], $name );
                }
                foreach ( self::moduleStyles( $s ) as $m ) {
                    $this->assertSame( [], $m, $name );
                }
            }
            $this->assertSame( [], $d['report']['carried'], $name );
            $this->assertSame( [], $b['fonts'], $name );
        }
        // And without any of them the same body carries its styles.
        [ , $d ] = $this->pipeline( self::page( '', $body ) );
        $this->assertSame( [], $d['report']['notes'] );
        $this->assertSame( '#0b1120', $d['sections'][0]['style']['background'] );
    }

    public function testExternalStylesheetsAreCountedButDoNotTurnStylesOff(): void
    {
        // Controller ruling: a linked sheet or an @import is counted for the "N stylesheets not read" warning; the CSS in the HTML is still read.
        $this->assertFalse( HtmlStyleBlocks::DEGRADE_ON_EXTERNAL );
        $body  = '<section style="background:#0b1120;padding:40px"><h1 style="color:#ffffff;font-size:48px">Hi</h1><p style="color:#e2e8f0">Text.</p></section>';
        $cases = [
            'link'   => [ '<!DOCTYPE html><html><head><link rel="stylesheet" href="/x.css"></head><body>' . $body . '</body></html>', 1 ],
            'import' => [ self::page( '@import url("/x.css");', $body ), 1 ],
            'both'   => [ '<!DOCTYPE html><html><head><link rel="stylesheet" href="/a.css"><link rel="stylesheet" href="/b.css"><style>@import url("/c.css");</style></head><body>' . $body . '</body></html>', 3 ],
            'none'   => [ self::page( '', $body ), 0 ],
        ];
        foreach ( $cases as $name => [ $html, $external ] ) {
            [ $b, $d ] = $this->pipeline( $html );
            $this->assertSame( $external, $b['external'], $name );
            $this->assertSame( $external, $d['report']['external'], $name );
            $this->assertSame( [], $d['report']['notes'], $name );
            $this->assertSame( '#0b1120', $d['sections'][0]['style']['background'] ?? null, $name );
            $this->assertSame( [ [ 'color' => '#ffffff', 'size' => 48 ], [ 'color' => '#e2e8f0' ] ], self::moduleStyles( $d['sections'][0] ), $name );
        }
    }

    public function testTheFontsFixtureKeepsItsFontFacts(): void
    {
        // 14-styled-fonts imports a web font sheet: counted, and its own <style> is still read.
        [ $b, $d ] = $this->pipeline( (string) file_get_contents( self::FIXTURES . '/14-styled-fonts.html' ) );
        $this->assertSame( 1, $b['external'] );
        $this->assertSame( [], $d['report']['notes'] );
        $mods = self::moduleStyles( $d['sections'][0] );
        $this->assertSame( 'Playfair Display', $mods[0]['family'] ?? null ); // h1
        $this->assertSame( 'Playfair Display', $mods[2]['family'] ?? null ); // h2
        $this->assertSame( 'Inter', $mods[1]['family'] ?? null );            // the first paragraph
        $this->assertContains( [ 'source' => 'Brand Sans', 'action' => 'not available in Divi: used Inter, the next family in the list' ], $b['fonts'] );
        $last = $mods[ count( $mods ) - 1 ];
        $this->assertArrayNotHasKey( 'family', $last ); // the pre's text: its family is the UA sheet's
    }

    public function testTheListOfDroppedColoursIsCapped(): void
    {
        $mods = [];
        for ( $i = 0; $i < 30; $i++ ) {
            $mods[] = self::mod( [ 'path' => '/p' . $i, 'ink' => [ 'state' => 'set', 'value' => '#eeeeee' ] ] );
        }
        $d = HtmlReadability::decide( self::blocks( [ self::sec( self::paint( '#ffffff' ), [ self::row( self::paint( null, 'none' ), [ self::col( self::paint( null, 'none' ), $mods ) ] ) ] ) ] ) );
        $this->assertCount( HtmlReadability::MAX_LISTED, $d['report']['dropped_unreadable'] );
        $this->assertSame( 30, $d['report']['ignored']['unreadable'] );
        $this->assertSame( '/p0', $d['report']['dropped_unreadable'][0]['path'] );
    }

    public function testAnUnprovenBackgroundIsNeverAPaint(): void
    {
        // A level whose background the writer cannot put on that block is unknown, not a paint (here: a hand-made `set` with a bad value).
        $d = HtmlReadability::decide( self::blocks( [ self::sec( self::paint( '#ffffff' ), [ self::row( [ 'state' => 'set', 'value' => 'red' ], [ self::col( self::paint( null, 'none' ), [ self::mod() ] ) ] ) ] ) ] ) );
        $this->assertSame( [], $d['sections'][0]['style'] );
        $this->assertSame( [ [] ], self::moduleStyles( $d['sections'][0] ) );
    }

    public function testEveryDecidedStyleIsAcceptedByTheWriter(): void
    {
        foreach ( glob( self::FIXTURES . '/*.html' ) ?: [] as $file ) {
            if ( null !== HtmlSource::parse( (string) file_get_contents( $file ) )['error'] ) {
                continue; // the empty page
            }
            [ , $d, $plan ] = $this->pipeline( (string) file_get_contents( $file ) );
            $this->writeAll( $plan, $d );
        }
        $this->addToAssertionCount( 1 );
    }

    // ---------------------------------------------------------------
    // The property test
    // ---------------------------------------------------------------

    public function testRandomStyledTreesAreNeverLessReadable(): void
    {
        $seed = (int) ( getenv( 'AIED_SEED' ) ?: self::DEFAULT_SEED );
        mt_srand( $seed );
        $judged  = 0;
        $inked   = 0;
        $painted = 0;
        $pairs   = 0;
        $ghosts  = 0;
        $dropped = 0;
        for ( $case = 0; $case < 320; $case++ ) {
            $html = self::randomPage();
            $s    = HtmlSource::parse( $html );
            if ( null !== $s['error'] ) {
                continue;
            }
            $plan     = HtmlMapper::map( $s['body'], true );
            $computed = HtmlStyles::resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], HtmlStyles::document( $s ) );
            $blocks   = HtmlStyleBlocks::build( $plan, $computed );
            $d        = HtmlReadability::decide( $blocks );
            $again    = HtmlReadability::decide( HtmlStyleBlocks::build( HtmlMapper::map( HtmlSource::parse( $html )['body'], true ), $computed ) );
            $this->assertSame( serialize( $d ), serialize( $again ), 'determinism, seed ' . $seed . ' case ' . $case );

            $problems = self::oracle( $plan, $d, $computed['nodes'] );
            $this->assertSame( [], $problems, 'seed ' . $seed . ' case ' . $case . "\n" . $html );
            $this->writeAll( $plan, $d );
            ++$judged;
            foreach ( $d['sections'] as $sec ) {
                $painted += isset( $sec['style']['background'] ) ? 1 : 0;
                foreach ( self::moduleStyles( $sec ) as $m ) {
                    $inked  += isset( $m['color'] ) && ! isset( $m['background'] ) ? 1 : 0;
                    $pairs  += isset( $m['background'] ) && DiviMarkup::TRANSPARENT !== $m['background'] ? 1 : 0;
                    $ghosts += DiviMarkup::TRANSPARENT === ( $m['background'] ?? null ) ? 1 : 0;
                }
            }
            $dropped += count( $d['report']['dropped_unreadable'] );
        }
        if ( false !== getenv( 'AIED_STATS' ) ) {
            fwrite( STDERR, sprintf( "seed %d: %d trees, %d inked, %d painted sections, %d pairs, %d ghosts, %d dropped\n", $seed, $judged, $inked, $painted, $pairs, $ghosts, $dropped ) );
        }
        $this->assertGreaterThanOrEqual( 300, $judged );
        $this->assertGreaterThan( 5, $pairs, 'the generator must produce button pairs' );
        $this->assertGreaterThan( 5, $ghosts, 'the generator must produce ghost buttons' );
        $this->assertGreaterThan( 50, $dropped, 'the generator must produce dropped colours' );
        $this->assertGreaterThan( 50, $inked, 'the generator must produce carried colours' );
        $this->assertGreaterThan( 20, $painted, 'the generator must produce painted sections' );
    }

    /**
     * The oracle: judged from the decided OUTPUT (style records) and the plan's module kinds only, independent of the decision code.
     * 1 a heading/text colour only under a painted level; 2 every heading/text under a painted level has a colour; 3 that colour meets
     * 4.5:1 (3:1 when large at the writer's smallest step) against the innermost paint; 4 a button has a label iff it has a fill, a
     * solid pair meets 4.5:1, a transparent fill only under paint with its label meeting 4.5:1 there, and every button under paint has a
     * pair or a ghost; 5 an ink is the source's computed colour of the module's element (or #000000 for the CSS initial one).
     *
     * @return list<string>
     */
    private static function oracle( array $plan, array $d, array $nodes ): array
    {
        $problems = [];
        foreach ( $plan['sections'] as $si => $section ) {
            $ds = $d['sections'][ $si ];
            foreach ( $section['chunks'] as $ri => $chunk ) {
                $dr   = $ds['rows'][ $ri ];
                $cols = 'cards' === $chunk['kind'] ? $chunk['columns'] : [ $chunk['modules'] ];
                foreach ( $cols as $ci => $modules ) {
                    $dc    = $dr['columns'][ $ci ];
                    $paint = $dc['style']['background'] ?? $dr['style']['background'] ?? $ds['style']['background'] ?? null;
                    foreach ( $modules as $mi => $m ) {
                        $st    = $dc['modules'][ $mi ];
                        $where = $si . '.' . $ri . '.' . $ci . '.' . $mi . ' ' . $m['type'];
                        if ( 'heading' === $m['type'] || 'text' === $m['type'] ) {
                            if ( isset( $st['color'] ) && null === $paint ) {
                                $problems[] = $where . ': ink without paint';
                            }
                            if ( null !== $paint && ! isset( $st['color'] ) ) {
                                $problems[] = $where . ': paint without ink';
                            }
                            if ( isset( $st['color'] ) && null !== $paint ) {
                                $size  = $st['size'] ?? null;
                                $small = null === $size ? null : ( $size > 32 ? max( 20, (int) round( 0.55 * $size ) ) : $size );
                                $large = null !== $small && ( $small >= 24 || ( $small >= 19 && (int) ( $st['weight'] ?? 0 ) >= 700 ) );
                                $ratio = Colour::contrast( $st['color'], $paint );
                                if ( $ratio < ( $large ? 3.0 : 4.5 ) ) {
                                    $problems[] = sprintf( '%s: %s on %s is %.2F', $where, $st['color'], $paint, $ratio );
                                }
                                $rec = $nodes[ $m['from'][0] ]['color'] ?? null;
                                $src = ( 'set' === ( $rec['state'] ?? null ) || 'initial' === ( $rec['state'] ?? null ) ) ? $rec['value'] : null;
                                if ( $src !== $st['color'] ) {
                                    $problems[] = $where . ': ink ' . $st['color'] . ' is not the source colour ' . json_encode( $rec );
                                }
                            }
                        } elseif ( 'button' === $m['type'] ) {
                            if ( isset( $st['color'] ) !== isset( $st['background'] ) ) {
                                $problems[] = $where . ': half a button pair';
                            }
                            if ( isset( $st['background'] ) ) {
                                $under = DiviMarkup::TRANSPARENT === $st['background'] ? $paint : $st['background'];
                                if ( null === $under ) {
                                    $problems[] = $where . ': ghost without paint';
                                } elseif ( Colour::contrast( $st['color'], $under ) < 4.5 ) {
                                    $problems[] = $where . ': button label unreadable';
                                }
                            } elseif ( null !== $paint ) {
                                $problems[] = $where . ': a button under paint without pair or ghost';
                            }
                        }
                    }
                }
            }
        }

        return $problems;
    }

    /** Every decided style through the real writer (it throws on any value outside its forms). */
    private function writeAll( array $plan, array $d ): void
    {
        foreach ( $plan['sections'] as $si => $section ) {
            $ds   = $d['sections'][ $si ];
            $rows = '';
            foreach ( $section['chunks'] as $ri => $chunk ) {
                $dr   = $ds['rows'][ $ri ];
                $cols = 'cards' === $chunk['kind'] ? $chunk['columns'] : [ $chunk['modules'] ];
                $markup = [];
                $styles = [];
                foreach ( $cols as $ci => $modules ) {
                    $html = '';
                    foreach ( $modules as $mi => $m ) {
                        $st = $dr['columns'][ $ci ]['modules'][ $mi ];
                        switch ( $m['type'] ) {
                            case 'heading':
                                $html .= DiviMarkup::heading( $m['text'], $m['level'], $st );
                                break;
                            case 'text':
                                $html .= DiviMarkup::text( $m['html'], $st );
                                break;
                            case 'button':
                                $html .= DiviMarkup::button( $m['text'], $m['href'], $m['newTab'], $st );
                                break;
                            default:
                                $this->assertSame( [], $st );
                        }
                    }
                    $markup[] = $html;
                    $styles[] = $dr['columns'][ $ci ]['style'];
                }
                // Four columns per Divi row, as the facade splits them.
                foreach ( array_chunk( $markup, 4 ) as $k => $part ) {
                    $rows .= DiviMarkup::row( $part, $dr['style'], array_slice( $styles, 4 * $k, count( $part ) ) );
                }
            }
            DiviMarkup::section( 'S', $rows, $ds['style'] );
        }
    }

    // ---------------------------------------------------------------
    // The generator
    // ---------------------------------------------------------------

    private static function pick( array $a ): mixed
    {
        return $a[ mt_rand( 0, count( $a ) - 1 ) ];
    }

    private static function chance( int $percent ): bool
    {
        return mt_rand( 1, 100 ) <= $percent;
    }

    private const COLOURS = [ '#ffffff', '#000000', '#111827', '#1f2937', '#f9fafb', '#e2e8f0', '#0b1120', '#475569', '#949494', '#767676', '#777777',
        '#fde047', '#2563eb', '#1d4ed8', '#f59e0b', '#0f766e', '#fff', '#FFF', '#abc', 'red', 'white', 'RebeccaPurple', 'navy', 'rgb(30,41,59)',
        'rgba(0,0,0,.5)', 'transparent', 'var(--x)', 'currentColor', 'hsl(0 0% 50%)' ];

    private const BACKGROUNDS = [ '#ffffff', '#0b1120', '#f3f4f6', '#1e293b', '#fef3c7', '#123456', '#000', 'white', 'navy', 'rgba(0,0,0,.5)',
        'transparent', 'none', 'linear-gradient(#000,#fff)', 'url(/x.jpg)', 'var(--bg)' ];

    private static function randomStyle( string $tag ): string
    {
        $decls = [];
        if ( self::chance( 'btn' === $tag ? 70 : 45 ) ) {
            $decls[] = 'color:' . self::pick( self::COLOURS );
        }
        if ( self::chance( 'btn' === $tag ? 70 : ( in_array( $tag, [ 'section', 'div', 'body', 'html', 'a' ], true ) ? 40 : 10 ) ) ) {
            $decls[] = self::pick( [ 'background', 'background-color' ] ) . ':' . self::pick( self::BACKGROUNDS );
        }
        if ( self::chance( 25 ) ) {
            $decls[] = 'font-size:' . self::pick( [ '12px', '16px', '19px', '23px', '24px', '33px', '40px', '56px', '900px', '4px', '1.5em', '2rem', '150%', 'large', 'calc(1rem + 2vw)' ] );
        }
        if ( self::chance( 20 ) ) {
            $decls[] = 'font-weight:' . self::pick( [ '400', '600', '700', '800', 'bold', 'normal', 'bolder' ] );
        }
        if ( self::chance( 15 ) ) {
            $decls[] = 'text-align:' . self::pick( [ 'left', 'center', 'right', 'justify', 'start' ] );
        }
        if ( self::chance( 15 ) ) {
            $decls[] = 'font-family:' . self::pick( [ 'Inter, sans-serif', '"Playfair Display", serif', 'Georgia', '"Brand X", sans-serif', 'sans-serif' ] );
        }
        if ( self::chance( 15 ) ) {
            $decls[] = 'padding:' . self::pick( [ '24px', '40px 16px', '-4px', '10%', '300px', '1em 2em', '0' ] );
        }
        if ( self::chance( 10 ) ) {
            $decls[] = 'max-width:' . self::pick( [ '720px', '100px', '3000px', '50%', 'none' ] );
        }
        if ( self::chance( 10 ) ) {
            $decls[] = 'border-radius:' . self::pick( [ '8px', '999px', '4px 8px', '50%', '2000px' ] );
        }
        if ( self::chance( 4 ) ) {
            $decls[] = 'opacity:' . self::pick( [ '.6', '1' ] );
        }
        foreach ( $decls as $k => $v ) {
            if ( self::chance( 8 ) ) {
                $decls[ $k ] = $v . ' !important';
            }
        }

        return implode( ';', $decls );
    }

    private static function el( string $tag, string $inner, string $class = '' ): string
    {
        $attrs = '' !== $class ? ' class="' . $class . '"' : '';
        if ( self::chance( 50 ) ) {
            $style = self::randomStyle( 'btn' === $class ? 'btn' : $tag );
            if ( '' !== $style ) {
                $attrs .= ' style="' . htmlspecialchars( $style, ENT_QUOTES ) . '"';
            }
        }

        return '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
    }

    private static function words(): string
    {
        return self::pick( [ 'Fresh bread daily', 'Open late on Fridays', 'Tested by guides', 'Free shipping on orders', 'Two rooms, one sound' ] );
    }

    private static function content( int $depth ): string
    {
        $out = '';
        $n   = mt_rand( 1, 4 );
        for ( $i = 0; $i < $n; $i++ ) {
            $r = mt_rand( 1, 11 );
            if ( $r <= 2 ) {
                $out .= self::el( 'h' . mt_rand( 1, 3 ), self::words() );
            } elseif ( $r <= 5 ) {
                $inner = self::words();
                if ( self::chance( 25 ) ) {
                    $inner .= ' ' . self::el( self::pick( [ 'span', 'strong', 'a', 'code' ] ), 'more' );
                }
                $out .= self::el( 'p', $inner, self::chance( 20 ) ? self::pick( [ 'lead', 'fine' ] ) : '' );
            } elseif ( 6 === $r ) {
                $out .= self::el( 'ul', '<li>' . self::words() . '</li><li>' . self::words() . '</li>' );
            } elseif ( 7 === $r ) {
                $a    = self::el( 'a', 'Book now', 'btn' );
                $a    = str_replace( '<a class="btn"', '<a class="btn" href="/go"', $a );
                $out .= self::chance( 30 ) ? self::el( 'div', $a ) : $a;
            } elseif ( 8 === $r ) {
                $card = '';
                for ( $k = 0; $k < 3; $k++ ) {
                    $card .= self::el( 'div', self::el( 'h3', self::words() ) . self::el( 'p', self::words() ), 'card' );
                }
                $out .= $card;
            } elseif ( $depth < 2 ) {
                $out .= self::el( 'div', self::content( $depth + 1 ) );
            } else {
                $out .= self::el( 'p', self::words() );
            }
        }

        return $out;
    }

    private static function randomPage(): string
    {
        $css = '';
        if ( self::chance( 60 ) ) {
            $rules = [];
            for ( $i = 0, $n = mt_rand( 0, 12 ); $i < $n; $i++ ) {
                $sel     = self::pick( [ 'body', 'html', 'section', 'div', 'h1', 'h2', 'h3', 'p', 'a', '.card', '.btn', '.lead', '.fine', '#x', '.hero h1', 'a:hover', 'p.lead' ] );
                $rule    = $sel . '{' . self::randomStyle( ltrim( $sel, '.#' ) ) . '}';
                $rules[] = self::chance( 10 ) ? '@media (min-width:600px){' . $rule . '}' : $rule;
            }
            $css = implode( "\n", $rules );
        }
        $sections = '';
        for ( $i = 0, $n = mt_rand( 1, 4 ); $i < $n; $i++ ) {
            $sections .= self::el( self::pick( [ 'section', 'section', 'div', 'header', 'footer' ] ), self::content( 0 ), self::chance( 15 ) ? 'hero' : '' );
        }
        if ( self::chance( 30 ) ) {
            $sections = self::el( 'div', $sections );
        }
        $body = self::chance( 30 ) ? '<body style="' . htmlspecialchars( self::randomStyle( 'body' ), ENT_QUOTES ) . '">' : '<body>';

        return '<!DOCTYPE html><html><head><style>' . $css . '</style></head>' . $body . $sections . '</body></html>';
    }
}
