<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HtmlCss;
use AiEditorDivi5\WP\HtmlMapper;
use AiEditorDivi5\WP\HtmlSource;
use AiEditorDivi5\WP\HtmlStyles;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The cascade and inheritance (html_to_divi styles, Task 6). The soundness rule: a value the resolver calls `set` or `initial` (with a
 * value) must equal what a browser computes for that element at desktop width; everything else must be `unresolved`. The browser cases
 * (testTheBrowserAgrees) were measured in Chromium with the same HTML (see the task report).
 */
final class HtmlStylesTest extends HtmlTestCase
{
    /** The big generated pages (thousands of DOM nodes) must not stay in the shared PHPUnit process: make test runs at 128 MB. */
    protected function tearDown(): void
    {
        gc_collect_cycles();
        gc_mem_caches();
        parent::tearDown();
    }

    /** @return array{nodes: array<string,array<string,mixed>>, ignored: array<string,int>, unread: bool} */
    private function styles( string $html ): array
    {
        $s = HtmlSource::parse( $html );
        $this->assertNull( $s['error'], (string) $s['error'] );

        return HtmlStyles::resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], HtmlStyles::document( $s ) );
    }

    /** A standards-mode page with one <style> block. */
    private function page( string $css, string $body ): array
    {
        return $this->styles( '<!DOCTYPE html><html><head><style>' . $css . '</style></head><body>' . $body . '</body></html>' );
    }

    /** @param array<string,mixed> $r @return array{state:string, value:mixed, reason:?string} */
    private function at( array $r, string $path, string $prop ): mixed
    {
        $this->assertArrayHasKey( $path, $r['nodes'], 'no record for ' . $path . ' in ' . implode( ', ', array_keys( $r['nodes'] ) ) );

        return $r['nodes'][ $path ][ $prop ];
    }

    private function assertSet( mixed $value, array $record, string $message = '' ): void
    {
        $this->assertSame( [ 'state' => 'set', 'value' => $value, 'reason' => null ], $record, $message );
    }

    private function assertUnresolved( array $record, ?string $reason = null, string $message = '' ): void
    {
        $this->assertSame( 'unresolved', $record['state'], $message . ' ' . json_encode( $record ) );
        $this->assertNull( $record['value'], $message );
        if ( null !== $reason ) {
            $this->assertSame( $reason, $record['reason'], $message );
        }
        $this->assertContains( $record['reason'], HtmlCss::REASONS, $message );
    }

    // ── the brief ───────────────────────────────────────────────────────────

    public function testSpecificityThenOrderThenInline(): void
    {
        $r = $this->page(
            'p{color:#111111} .b{color:#222222} #a{color:#333333} .c{color:#444444} .c{color:#555555} #a{color:#333333}',
            '<p id="a" class="b">1</p><p class="b">2</p><p>3</p><p class="c">4</p><p id="a" class="b" style="color:#666666">5</p>'
        );
        $this->assertSet( '#333333', $this->at( $r, '/html/body/p[1]', 'color' ), '#a beats .b' );
        $this->assertSet( '#222222', $this->at( $r, '/html/body/p[2]', 'color' ), '.b beats p' );
        $this->assertSet( '#111111', $this->at( $r, '/html/body/p[3]', 'color' ) );
        $this->assertSet( '#555555', $this->at( $r, '/html/body/p[4]', 'color' ), 'the later of two equal rules' );
        $this->assertSet( '#666666', $this->at( $r, '/html/body/p[5]', 'color' ), 'inline beats #a' );
    }

    public function testImportantBeatsNormalAndInlineBeatsRules(): void
    {
        $r = $this->page( 'p{color:red !important} #x{color:#0000ff}', '<p id="x" style="color:blue">1</p><p style="color:#00ff00 !important">2</p>' );
        $this->assertSet( '#ff0000', $this->at( $r, '/html/body/p[1]', 'color' ) );
        $this->assertSet( '#00ff00', $this->at( $r, '/html/body/p[2]', 'color' ) );
    }

    public function testInheritanceAndRelativeSizes(): void
    {
        $r = $this->page( 'body{font-size:18px;color:#111} div{font-size:20px} .e{font-size:1.5em} .r{font-size:1.5rem}', '<p>a</p><div><p class="e">b</p><p class="r">c</p></div>' );
        $this->assertSet( 18.0, $this->at( $r, '/html/body/p', 'size' ) );
        $this->assertSet( '#111111', $this->at( $r, '/html/body/p', 'color' ) );
        $this->assertSet( 30.0, $this->at( $r, '/html/body/div/p[1]', 'size' ) );
        $this->assertSet( 24.0, $this->at( $r, '/html/body/div/p[2]', 'size' ), 'rem ignores the parent' );
    }

    public function testHeadingsTakeSizeAndWeightOnlyFromTheirOwnRules(): void
    {
        $r = $this->page( 'body{font-size:18px;font-weight:300}', '<h1>a</h1><p>b</p>' );
        $this->assertSame( [ 'state' => 'initial', 'value' => null, 'reason' => null ], $this->at( $r, '/html/body/h1', 'size' ) );
        $this->assertSame( [ 'state' => 'initial', 'value' => null, 'reason' => null ], $this->at( $r, '/html/body/h1', 'weight' ) );
        $this->assertSet( 300, $this->at( $r, '/html/body/p', 'weight' ) );
        $r = $this->page( 'body{font-size:18px} h1{font-size:2em}', '<h1>a</h1>' );
        $this->assertSet( 36.0, $this->at( $r, '/html/body/h1', 'size' ) );
    }

    public function testLinksAndButtonsTakeColourOnlyFromTheirOwnRules(): void
    {
        $r = $this->page( 'body{color:#111} .btn{color:#fff}', '<a href="/x">a</a><a class="btn" href="/y">b</a><button>c</button><button class="btn">d</button>' );
        $this->assertSame( [ 'state' => 'initial', 'value' => null, 'reason' => null ], $this->at( $r, '/html/body/a[1]', 'color' ), 'the UA link colour is not #000000' );
        $this->assertSet( '#ffffff', $this->at( $r, '/html/body/a[2]', 'color' ) );
        $this->assertSame( 'initial', $this->at( $r, '/html/body/button[1]', 'color' )['state'] );
        $this->assertSet( '#ffffff', $this->at( $r, '/html/body/button[2]', 'color' ) );
        $this->assertSame( [ 'state' => 'unknown', 'value' => null, 'reason' => 'unresolved' ], $this->at( $r, '/html/body/button[1]', 'background' ), 'the UA button face' );
    }

    public function testAPropertyAnUnreadableRuleMightSetIsUnresolved(): void
    {
        $r = $this->page( '.hero h1{color:#fff} h1{color:#000} p{color:var(--x)} .f{font: 20px Inter}', '<section class="hero"><h1>a</h1><h2>b</h2><p>c <span>d</span></p><p class="f">e</p></section>' );
        $this->assertUnresolved( $this->at( $r, '/html/body/section/h1', 'color' ), 'selector' );
        $this->assertSame( [ 'state' => 'initial', 'value' => '#000000', 'reason' => null ], $this->at( $r, '/html/body/section/h2', 'color' ), 'no rule matches the h2' );
        $this->assertUnresolved( $this->at( $r, '/html/body/section/p[1]', 'color' ), 'function' );
        $this->assertUnresolved( $this->at( $r, '/html/body/section/p[1]/span', 'color' ), 'function', 'inherited' );
        foreach ( [ 'size', 'weight', 'family' ] as $prop ) {
            $this->assertUnresolved( $this->at( $r, '/html/body/section/p[2]', $prop ), 'shorthand', $prop );
        }
    }

    public function testMediaRulesPoisonButDoNotApply(): void
    {
        $r = $this->page( 'h1{font-size:40px} @media (min-width:768px){h1{font-size:72px}} h2{font-size:30px} @media (min-width:768px){h3{font-size:10px}}', '<h1>a</h1><h2>b</h2>' );
        $this->assertUnresolved( $this->at( $r, '/html/body/h1', 'size' ), 'media' );
        $this->assertSet( 30.0, $this->at( $r, '/html/body/h2', 'size' ) );
        $r = $this->page( '@media (min-width:768px){h1{font-size:72px}} h1{font-size:40px}', '<h1>a</h1>' );
        $this->assertSet( 40.0, $this->at( $r, '/html/body/h1', 'size' ), 'a later rule of the same rank beats the media rule whether it applies or not' );
    }

    public function testStatesNeverPoison(): void
    {
        $r = $this->page( 'a{color:#123456} a:hover{color:red} a:focus-visible{color:blue}', '<a href="/x">a</a>' );
        $this->assertSet( '#123456', $this->at( $r, '/html/body/a', 'color' ) );
    }

    public function testBackgroundsAreOwnOnly(): void
    {
        $r = $this->page( 'section{background:#123} .i{background-image:url(x.png);background-color:#fff}', '<section><p>a</p></section><div class="i">b</div><table bgcolor="#000"><tr><td>c</td></tr></table>' );
        $this->assertSame( [ 'state' => 'set', 'value' => '#112233', 'reason' => null ], $this->at( $r, '/html/body/section', 'background' ) );
        $this->assertSame( [ 'state' => 'none', 'value' => null, 'reason' => null ], $this->at( $r, '/html/body/section/p', 'background' ) );
        $this->assertSame( [ 'state' => 'unknown', 'value' => null, 'reason' => 'image_or_gradient' ], $this->at( $r, '/html/body/div', 'background' ) );
        $this->assertSame( [ 'state' => 'unknown', 'value' => null, 'reason' => 'presentational' ], $this->at( $r, '/html/body/table', 'background' ) );
    }

    public function testEffectsPoisonColourBelow(): void
    {
        $r = $this->page( 'section{opacity:.6} p{color:#111111} .s{background:#fff}', '<section><h2>a</h2><p>b <span class="s">c</span></p></section><p>d</p>' );
        foreach ( [ '/html/body/section', '/html/body/section/h2', '/html/body/section/p', '/html/body/section/p/span' ] as $path ) {
            $this->assertUnresolved( $this->at( $r, $path, 'color' ), 'effect', $path );
        }
        $this->assertSame( [ 'state' => 'unknown', 'value' => null, 'reason' => 'effect' ], $this->at( $r, '/html/body/section/p/span', 'background' ) );
        $this->assertSet( '#111111', $this->at( $r, '/html/body/p', 'color' ), 'outside the subtree' );
    }

    public function testCenterAlignsAtTheLowestPriority(): void
    {
        $r = $this->page( '.l{text-align:left}', '<center><p>a</p><p class="l">b</p></center>' );
        $this->assertSet( 'center', $this->at( $r, '/html/body/center/p[1]', 'align' ) );
        $this->assertSet( 'left', $this->at( $r, '/html/body/center/p[2]', 'align' ) );
        $r = $this->page( 'center{text-align:right}', '<center><p>a</p></center>' );
        $this->assertSet( 'right', $this->at( $r, '/html/body/center/p', 'align' ) );
    }

    /** @return array<string,array{0:string}> */
    public static function styledFixtures(): array
    {
        $out = [];
        foreach ( glob( self::FIXTURES . '/14-styled-*.html' ) ?: [] as $file ) {
            $out[ basename( $file, '.html' ) ] = [ $file ];
        }

        return $out;
    }

    #[DataProvider('styledFixtures')]
    public function testTheSameInputGivesTheSameRecords( string $file ): void
    {
        $html = (string) file_get_contents( $file );
        $a    = $this->styles( $html );
        $b    = $this->styles( $html );
        $this->assertSame( $a, $b );
        // Keys in document order: html, body, then every element of the body in pre-order.
        $s     = HtmlSource::parse( $html );
        $order = [ $s['body']->ownerDocument->documentElement->getNodePath() ];
        $walk  = static function ( \DOMElement $el ) use ( &$walk, &$order ): void {
            $order[] = $el->getNodePath();
            foreach ( $el->childNodes as $child ) {
                if ( $child instanceof \DOMElement ) {
                    $walk( $child );
                }
            }
        };
        $walk( $s['body'] );
        $this->assertSame( $order, array_keys( $a['nodes'] ) );
        $this->assertSame( json_encode( $a ), json_encode( $b ), 'byte-identical' );
    }

    public function testABigStyledPageIsFast(): void
    {
        $css = '';
        for ( $i = 0; $i < 1500; $i++ ) {
            switch ( $i % 5 ) {
                case 0: $css .= '.c' . $i . '{color:#' . sprintf( '%06x', $i * 997 % 0xffffff ) . ';padding:' . ( $i % 40 ) . 'px}'; break;
                case 1: $css .= '#i' . $i . '{font-size:' . ( 10 + $i % 30 ) . 'px}'; break;
                case 2: $css .= 'p.k' . $i . '{font-weight:700}'; break;
                case 3: $css .= '.a' . ( $i % 50 ) . ' .b' . $i . '{text-align:center}'; break;
                default: $css .= 'h' . ( 1 + $i % 6 ) . ', .x' . $i . '{background:#fff}'; break;
            }
        }
        $body = '';
        for ( $i = 0; $i < 1000; $i++ ) {
            $body .= '<div class="c' . ( $i * 5 % 1500 ) . ' a' . ( $i % 50 ) . '"><p id="i' . ( ( $i * 5 + 1 ) % 1500 ) . '" class="k' . ( $i % 1500 ) . ' b' . ( ( $i * 5 + 3 ) % 1500 ) . '">t <span>u</span></p><h3 class="x' . ( $i % 1500 ) . '">h</h3></div>';
        }
        $s = HtmlSource::parse( '<!DOCTYPE html><html><head><style>' . $css . '</style></head><body>' . $body . '</body></html>' );
        $this->assertNull( $s['error'] );
        $start = hrtime( true );
        $r     = HtmlStyles::resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], HtmlStyles::document( $s ) );
        $ms    = ( hrtime( true ) - $start ) / 1e6;
        $this->assertGreaterThanOrEqual( 4000, count( $r['nodes'] ) );
        $this->assertLessThan( 2000, $ms, 'resolved in ' . round( $ms ) . ' ms' );
    }

    // ── the additional contract of the last review round ─────────────────────

    /** (a) Quirks mode matches class and id selectors ASCII case-insensitively; such a match is never carried, only poisons. */
    public function testQuirksModeCaseOnlyMatchesAreUnresolved(): void
    {
        $body = '<p class="lead">a</p><p class="Lead">b</p><p id="foo">c</p><p class="other">d</p>';
        $css  = '<style>.Lead{color:#111111} #FOO{color:#222222}</style>';
        $q    = $this->styles( '<html><head>' . $css . '</head><body>' . $body . '</body></html>' );
        $this->assertUnresolved( $this->at( $q, '/html/body/p[1]', 'color' ), 'selector' );
        $this->assertSet( '#111111', $this->at( $q, '/html/body/p[2]', 'color' ) );
        $this->assertUnresolved( $this->at( $q, '/html/body/p[3]', 'color' ), 'selector' );
        $this->assertSame( '#000000', $this->at( $q, '/html/body/p[4]', 'color' )['value'] );
        $s = $this->styles( '<!DOCTYPE html><html><head>' . $css . '</head><body>' . $body . '</body></html>' );
        $this->assertSame( [ 'state' => 'initial', 'value' => '#000000', 'reason' => null ], $this->at( $s, '/html/body/p[1]', 'color' ), 'standards mode: case-sensitive' );
        $this->assertSame( '#000000', $this->at( $s, '/html/body/p[3]', 'color' )['value'] );
        // With empty document facts, standards mode is not assumed.
        $p = HtmlSource::parse( '<!DOCTYPE html><html><head>' . $css . '</head><body>' . $body . '</body></html>' );
        $this->assertUnresolved( HtmlStyles::resolve( $p['body'], $p['css'], $p['inline'], $p['presentational'], [] )['nodes']['/html/body/p[1]']['color'], 'selector' );
    }

    /** Quirks mode: a table does not inherit font size, weight or alignment (Chromium's quirks sheet), but it does inherit the colour. */
    public function testQuirksModeTablesDoNotInheritFonts(): void
    {
        $q = $this->styles( '<html><body style="color:#ff0000;font-size:20px;font-weight:300;text-align:right"><table><tr><td>x</td></tr></table></body></html>' );
        $this->assertSet( '#ff0000', $this->at( $q, '/html/body/table/tr/td', 'color' ) );
        foreach ( [ 'size', 'weight', 'align' ] as $prop ) {
            $this->assertNotSame( 'set', $this->at( $q, '/html/body/table/tr/td', $prop )['state'], $prop );
        }
        $s = $this->styles( '<!DOCTYPE html><html><body style="font-size:20px"><table><tr><td>x</td></tr></table></body></html>' );
        $this->assertSet( 20.0, $this->at( $s, '/html/body/table/tr/td', 'size' ) );
    }

    /** (b) id is one token; class splits on ASCII whitespace only. */
    public function testAnIdIsOneTokenAndClassesSplitOnAsciiWhitespace(): void
    {
        $r = $this->page( '#a{color:#111111} .b{color:#222222} .c{font-size:30px}', "<p id=\"a b\">1</p><p id=\"a\">2</p><p class=\"x\tb\">3</p><p class=\"x\u{00A0}b\">4</p><p class=\"b\nc\">5</p>" );
        $this->assertSame( 'initial', $this->at( $r, '/html/body/p[1]', 'color' )['state'], 'id "a b" is not #a' );
        $this->assertSet( '#111111', $this->at( $r, '/html/body/p[2]', 'color' ) );
        $this->assertSet( '#222222', $this->at( $r, '/html/body/p[3]', 'color' ), 'a tab separates classes' );
        $this->assertSame( 'initial', $this->at( $r, '/html/body/p[4]', 'color' )['state'], 'a no-break space does not' );
        $this->assertSet( 30.0, $this->at( $r, '/html/body/p[5]', 'size' ) );
    }

    /** (c) Elements whose property the UA sheet sets do not inherit it, and what sits inside them is not known either. */
    public function testWhatTheUaSheetSetsIsNeverCarried(): void
    {
        $r = $this->page( 'body{font-size:18px;color:#111111;font-weight:300;font-family:Inter}', '<h2>a <span>b</span></h2><a href="/x">c <span>d</span></a><p><strong>e</strong> <small>f</small></p><pre>g <span>h</span></pre><mark>i</mark><table><tr><th>j</th></tr></table>' );
        $this->assertSet( '#111111', $this->at( $r, '/html/body/h2', 'color' ), 'headings inherit the colour' );
        $this->assertUnresolved( $this->at( $r, '/html/body/h2/span', 'size' ) );
        $this->assertUnresolved( $this->at( $r, '/html/body/a/span', 'color' ) );
        $this->assertSame( 'initial', $this->at( $r, '/html/body/p/strong', 'weight' )['state'] );
        $this->assertNull( $this->at( $r, '/html/body/p/strong', 'weight' )['value'] );
        $this->assertNull( $this->at( $r, '/html/body/p/small', 'size' )['value'] );
        $this->assertNull( $this->at( $r, '/html/body/pre', 'family' )['value'] );
        $this->assertUnresolved( $this->at( $r, '/html/body/pre/span', 'family' ) );
        $this->assertNull( $this->at( $r, '/html/body/mark', 'color' )['value'] );
        $this->assertSame( 'unknown', $this->at( $r, '/html/body/mark', 'background' )['state'] );
        $this->assertNull( $this->at( $r, '/html/body/table/tr/th', 'align' )['value'] );
    }

    /** The monospace keyword-size quirk: a family of exactly "monospace" on a size that comes from the default keyword is 13px, not 16. */
    public function testMonospaceSizesAreOnlyCarriedWhenAbsolute(): void
    {
        $r = $this->page( '.m{font-family:monospace} .mm{font-family:monospace, monospace} .px{font-size:14px} .em{font-size:1em} .rem{font-size:1rem} div{font-size:16px}', '<pre>a</pre><pre class="px">b</pre><p class="m">c</p><p class="m em">d</p><p class="m rem">e</p><p class="mm">f</p><div><p class="m">g</p></div><code>h</code>' );
        $this->assertUnresolved( $this->at( $r, '/html/body/pre[1]', 'size' ) );
        $this->assertSet( 14.0, $this->at( $r, '/html/body/pre[2]', 'size' ) );
        $this->assertUnresolved( $this->at( $r, '/html/body/p[1]', 'size' ) );
        $this->assertUnresolved( $this->at( $r, '/html/body/p[2]', 'size' ), null, 'em keeps the keyword' );
        $this->assertSet( 16.0, $this->at( $r, '/html/body/p[3]', 'size' ) );
        $this->assertSame( 16.0, $this->at( $r, '/html/body/p[4]', 'size' )['value'] );
        $this->assertSet( 16.0, $this->at( $r, '/html/body/div/p', 'size' ), 'an absolute parent size' );
        $this->assertUnresolved( $this->at( $r, '/html/body/code', 'size' ) );
        $r = $this->page( 'p{font-family:var(--f)}', '<p>a</p>' );
        $this->assertUnresolved( $this->at( $r, '/html/body/p', 'size' ), null, 'an unknown family may be monospace' );
    }

    public function testRemFollowsTheRootSize(): void
    {
        $r = $this->page( 'html{font-size:62.5%} p{font-size:2rem} .e{padding:1rem}', '<p class="e">a</p>' );
        $this->assertSet( 10.0, $this->at( $r, '/html', 'size' ) );
        $this->assertSet( 20.0, $this->at( $r, '/html/body/p', 'size' ) );
        $this->assertSame( 10, $this->at( $r, '/html/body/p', 'padding' )['top'] );
        $r = $this->page( 'html{font-size:var(--root)} p{font-size:2rem}', '<p>a</p>' );
        $this->assertUnresolved( $this->at( $r, '/html/body/p', 'size' ) );
    }

    /** (d) A text module made of several pieces carries only what every piece agrees on (fixture 14-styled-inline). */
    public function testMergedRunsCarryOnlyWhatEveryPieceAgreesOn(): void
    {
        $html = (string) file_get_contents( self::FIXTURES . '/14-styled-inline.html' );
        $s    = HtmlSource::parse( $html );
        $r    = HtmlStyles::resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], HtmlStyles::document( $s ) );
        $plan = HtmlMapper::map( $s['body'], true );
        $text = null;
        foreach ( $plan['sections'][1]['chunks'] as $chunk ) {
            foreach ( $chunk['modules'] ?? [] as $m ) {
                if ( 'text' === $m['type'] ) {
                    $text = $m;
                }
            }
        }
        $this->assertNotNull( $text );
        $this->assertCount( 2, $text['from'], 'two merged paragraphs' );
        $ignored = [];
        $c       = HtmlStyles::common( $r['nodes'], $text['from'], $ignored );
        $this->assertSame( [ 'state' => 'mixed', 'value' => null, 'reason' => 'mixed' ], $c['color'] );
        $this->assertSame( [ 'state' => 'mixed', 'value' => null, 'reason' => 'mixed' ], $c['size'] );
        $this->assertSame( $r['nodes'][ $text['from'][0] ]['weight'], $c['weight'], 'both inherit the same weight' );
        $this->assertSame( [ 'mixed' => 2 ], $ignored );
        $one = HtmlStyles::common( $r['nodes'], [ $text['from'][1] ], $ignored );
        $this->assertSet( '#4b5563', $one['color'] );
        $this->assertSame( 'unresolved', HtmlStyles::common( $r['nodes'], [ '/html/body/nothing' ], $ignored )['color']['state'] );
    }

    /** (e) A linked sheet, an @import or a cut stylesheet means some CSS was not read: the result says so. */
    public function testSheetsThatWereNotReadAreReported(): void
    {
        $this->assertFalse( $this->page( 'p{color:red}', '<p>a</p>' )['unread'] );
        $l = $this->styles( '<!DOCTYPE html><html><head><link rel="stylesheet" href="/a.css"></head><body><p>a</p></body></html>' );
        $this->assertTrue( $l['unread'] );
        $this->assertSame( 1, $l['ignored']['external'] );
        $this->assertTrue( $this->page( '@import url(x.css); p{color:red}', '<p>a</p>' )['unread'] );
        $big = $this->page( str_repeat( '.x{color:red}', 8000 ), '<p>a</p>' );
        $this->assertTrue( $big['unread'] );
        $this->assertUnresolved( $this->at( $big, '/html/body/p', 'color' ), null, 'the cover poison' );
    }

    /** (f) Nothing of the CSS text is ever echoed: only states, normalised values, paths and reason counts leave the class. */
    public function testNoRawCssIsEverEchoed(): void
    {
        $s   = HtmlSource::parse( '<!DOCTYPE html><html><body><p class="secret-class">a</p><div>b</div></body></html>' );
        $css = ".secret-class{color:#123456;font-family:\"Fam\xff\"} div{font-family:\"Brand Sans\"} .zzz-\xfe{color:red} p{--leak:\xc3(}";
        $r   = HtmlStyles::resolve( $s['body'], $css, [ '/html/body/div' => "color:\xff;secret-inline:1" ], [], HtmlStyles::document( $s ) );
        $json = json_encode( $r );
        $this->assertIsString( $json, 'valid UTF-8 throughout' );
        foreach ( [ 'secret', 'zzz', 'leak', 'Fam' ] as $needle ) {
            $this->assertStringNotContainsString( $needle, $json );
        }
        $this->assertSet( [ 'Brand Sans' ], $r['nodes']['/html/body/div']['family'], 'a normalised family name is a value' );
        foreach ( array_keys( $r['ignored'] ) as $reason ) {
            $this->assertContains( $reason, HtmlCss::REASONS );
        }
    }

    // ── the tree a browser builds ────────────────────────────────────────────

    public function testATableWithoutTbodyInheritsThroughTheOneTheBrowserAdds(): void
    {
        $r = $this->page( 'tbody{color:#123456} tr{font-size:20px}', '<table><tr><td>a</td></tr></table><table><td>b</td></table>' );
        $this->assertSet( '#123456', $this->at( $r, '/html/body/table[1]/tr/td', 'color' ) );
        $this->assertSet( '#123456', $this->at( $r, '/html/body/table[2]/td', 'color' ) );
        $this->assertSet( 20.0, $this->at( $r, '/html/body/table[2]/td', 'size' ), 'and the row it adds' );
        $this->assertSame( 'initial', $this->at( $r, '/html/body/table[1]', 'color' )['state'] );
    }

    public function testContentFosteredOutOfATableInheritsFromTheTablesParent(): void
    {
        $r = $this->page( 'table{color:#111111} section{color:#222222}', '<section><table><div>a</div><tr><td>b</td></tr></table></section>' );
        $this->assertSet( '#222222', $this->at( $r, '/html/body/section/table/div', 'color' ) );
        $this->assertSet( '#111111', $this->at( $r, '/html/body/section/table/tr/td', 'color' ) );
    }

    /**
     * libxml2 nests HTML5 blocks inside an open <p> (and a heading inside a heading); a browser closes it first. Since I8 HtmlSource writes
     * the </p> a browser implies (HtmlSource::paragraphEnds()), so the tree read here is the browser's and the block inherits from the body;
     * the model below stays the safety net for a page HtmlSource leaves as given (here: a comment in a textarea, which it does not model).
     */
    public function testElementsABrowserMovesOutOfAParagraphAreNotInheritedFromIt(): void
    {
        $r = $this->page( 'p{color:#111111} body{color:#444444} .own{color:#333333}', '<p>a<section><span>b</span><em class="own">c</em></section><span>d</span></p>' );
        $this->assertSet( '#111111', $this->at( $r, '/html/body/p', 'color' ) );
        $this->assertSet( '#444444', $this->at( $r, '/html/body/section', 'color' ), 'the browser\'s tree: the section is the body\'s child' );
        $this->assertSet( '#444444', $this->at( $r, '/html/body/section/span', 'color' ) );
        $this->assertSet( '#333333', $this->at( $r, '/html/body/section/em', 'color' ) );
        $this->assertSet( '#444444', $this->at( $r, '/html/body/span', 'color' ) );
        $r = $this->page( 'p{color:#111111} h1{color:#222222} .own{color:#333333}', '<textarea><!-- x --></textarea><p>a<section><span>b</span><em class="own">c</em></section><span>d</span></p><h1>e<h2>f</h2></h1>' );
        $this->assertSame( [], $r['repaired'], 'the scan has nothing to say' );
        $this->assertSet( '#111111', $this->at( $r, '/html/body/p', 'color' ) );
        $this->assertUnresolved( $this->at( $r, '/html/body/p/section', 'color' ) );
        $this->assertUnresolved( $this->at( $r, '/html/body/p/section/span', 'color' ) );
        $this->assertSet( '#333333', $this->at( $r, '/html/body/p/section/em', 'color' ), 'an own value does not depend on the parent' );
        $this->assertUnresolved( $this->at( $r, '/html/body/p/span', 'color' ), null, 'after the block, still outside the paragraph' );
        $this->assertUnresolved( $this->at( $r, '/html/body/h1/h2', 'color' ) );
        // A button is a scope boundary: a section inside a button inside a paragraph does not close the paragraph.
        $r = $this->page( 'button{color:#111111}', '<p><button>a<section>b</section></button></p>' );
        $this->assertSet( '#111111', $this->at( $r, '/html/body/p/button/section', 'color' ) );
    }

    /** A browser ignores a table part outside a table: the element does not exist there, and its children belong to its parent. */
    public function testATablePartOutsideATableIsNotAnElement(): void
    {
        $r = $this->page( 'td{color:#111111;padding:4px} div{color:#222222}', '<div><td>a<p>b</p></td></div><tr><p>c</p></tr>' );
        $this->assertUnresolved( $this->at( $r, '/html/body/div/td', 'color' ) );
        $this->assertSame( 'unresolved', $this->at( $r, '/html/body/div/td', 'padding' )['top'] );
        $this->assertSame( 'unknown', $this->at( $r, '/html/body/div/td', 'background' )['state'] );
        $this->assertSet( '#222222', $this->at( $r, '/html/body/div/td/p', 'color' ), 'the paragraph is the div\'s child in the browser' );
        $this->assertSame( [ 'state' => 'initial', 'value' => '#000000', 'reason' => null ], $this->at( $r, '/html/body/tr/p', 'color' ) );
        // Inside a table, a cell start tag in a cell's content closes the cell: what follows has an unknown parent.
        $r = $this->page( 'div{color:#222222} .own{color:#333333}', '<table><tr><td><div>a<td><p>b</p></td><p>c</p><p class="own">d</p></div></td></tr></table>' );
        $this->assertSet( '#222222', $this->at( $r, '/html/body/table/tr/td/div', 'color' ) );
        $this->assertUnresolved( $this->at( $r, '/html/body/table/tr/td/div/td/p', 'color' ) );
        $this->assertUnresolved( $this->at( $r, '/html/body/table/tr/td/div/p[1]', 'color' ) );
        $this->assertSet( '#333333', $this->at( $r, '/html/body/table/tr/td/div/p[2]', 'color' ) );
    }

    public function testStyleBlocksForSomeScreensPoison(): void
    {
        $r = $this->styles( '<!DOCTYPE html><html><head><style>p{color:#0000ff} h2{color:#00ff00}</style><style media="(min-width:600px)">p{color:#ff0000}</style><style media="print">h2{color:#ff0000}</style></head><body><p>a</p><h2>b</h2></body></html>' );
        $this->assertUnresolved( $this->at( $r, '/html/body/p', 'color' ), 'media' );
        $this->assertSet( '#00ff00', $this->at( $r, '/html/body/h2', 'color' ), 'a print block never applies on screen' );
    }

    public function testALongClassListUsesTheWholeAttribute(): void
    {
        $long = trim( str_repeat( 'utility-class-name ', 20 ) );
        $r    = $this->page( '.text-white{color:#ffffff}', '<p class="' . $long . ' text-white">a</p>' );
        $this->assertSet( '#ffffff', $this->at( $r, '/html/body/p', 'color' ) );
    }

    public function testAColorSchemeMetaMakesTheDefaultsUnknown(): void
    {
        $r = $this->styles( '<!DOCTYPE html><html><head><meta name="color-scheme" content="light dark"><style>.c{color:#111111}</style></head><body><p>a</p><p class="c">b</p></body></html>' );
        $this->assertUnresolved( $this->at( $r, '/html/body/p[1]', 'color' ), 'effect' );
        $this->assertSame( 'unknown', $this->at( $r, '/html', 'background' )['state'] );
    }

    public function testPresentationalHintsLoseToEveryRule(): void
    {
        $r = $this->styles( '<!DOCTYPE html><html><head><style>.l{text-align:left}</style></head><body text="#ffffff"><table><tr><td align="center">a</td><td align="center" class="l">b</td></tr></table><font color="red" size="5" face="Arial">c</font></body></html>' );
        $this->assertUnresolved( $this->at( $r, '/html/body', 'color' ), 'presentational' );
        $this->assertUnresolved( $this->at( $r, '/html/body/table/tr/td[1]', 'align' ), 'presentational' );
        $this->assertSet( 'left', $this->at( $r, '/html/body/table/tr/td[2]', 'align' ) );
        foreach ( [ 'color', 'size', 'family' ] as $prop ) {
            $this->assertUnresolved( $this->at( $r, '/html/body/font', $prop ), 'presentational', $prop );
        }
        $this->assertGreaterThanOrEqual( 5, $r['ignored']['presentational'] );
    }

    public function testBoxValues(): void
    {
        $r = $this->page( '.a{padding:1px 2px 3px} .b{font-size:20px;padding:2em 0.5em;max-width:720px;border-radius:4px 8px} .c{padding-top:5px;max-width:none;border-top-left-radius:6px} .d{padding:10%;border-radius:var(--r)} .e{padding-left:-1px}', '<div class="a">a</div><div class="b">b</div><div class="c">c</div><div class="d">d</div><div class="e">e</div><div>f</div>' );
        $this->assertSame( [ 'top' => 1, 'right' => 2, 'bottom' => 3, 'left' => 2 ], $this->at( $r, '/html/body/div[1]', 'padding' ) );
        $this->assertSame( [ 'top' => 40, 'right' => 10, 'bottom' => 40, 'left' => 10 ], $this->at( $r, '/html/body/div[2]', 'padding' ) );
        $this->assertSame( 720, $this->at( $r, '/html/body/div[2]', 'max_width' ) );
        $this->assertSame( [ 4, 8, 4, 8 ], $this->at( $r, '/html/body/div[2]', 'radius' ) );
        $this->assertSame( [ 'top' => 5, 'right' => null, 'bottom' => null, 'left' => null ], $this->at( $r, '/html/body/div[3]', 'padding' ) );
        $this->assertNull( $this->at( $r, '/html/body/div[3]', 'max_width' ) );
        $this->assertSame( [ 6, 0, 0, 0 ], $this->at( $r, '/html/body/div[3]', 'radius' ) );
        $this->assertSame( [ 'top' => 'unresolved', 'right' => 'unresolved', 'bottom' => 'unresolved', 'left' => 'unresolved' ], $this->at( $r, '/html/body/div[4]', 'padding' ) );
        $this->assertSame( 'unresolved', $this->at( $r, '/html/body/div[4]', 'radius' ) );
        $this->assertSame( 'unresolved', $this->at( $r, '/html/body/div[5]', 'padding' )['left'] );
        $this->assertSame( [ 'top' => null, 'right' => null, 'bottom' => null, 'left' => null ], $this->at( $r, '/html/body/div[6]', 'padding' ) );
        $this->assertNull( $this->at( $r, '/html/body/div[6]', 'radius' ) );
    }

    public function testTheRecordShape(): void
    {
        $r = $this->page( '', '<p>a</p>' );
        $this->assertSame( [ 'color', 'family', 'size', 'weight', 'align', 'background', 'padding', 'max_width', 'radius' ], array_keys( $r['nodes']['/html/body/p'] ) );
        $this->assertSame( [ 'state' => 'initial', 'value' => '#000000', 'reason' => null ], $r['nodes']['/html/body/p']['color'] );
        $this->assertSame( [ 'state' => 'initial', 'value' => null, 'reason' => null ], $r['nodes']['/html/body/p']['family'] );
        $this->assertSame( [ 'state' => 'initial', 'value' => 16.0, 'reason' => null ], $r['nodes']['/html/body/p']['size'] );
        $this->assertSame( [ 'state' => 'initial', 'value' => 400, 'reason' => null ], $r['nodes']['/html/body/p']['weight'] );
        $this->assertSame( [ 'state' => 'initial', 'value' => null, 'reason' => null ], $r['nodes']['/html/body/p']['align'], 'start: its side depends on the direction' );
        $this->assertSame( [ 'state' => 'none', 'value' => null, 'reason' => null ], $r['nodes']['/html/body/p']['background'] );
        $this->assertSame( [ 'nodes', 'ignored', 'unread', 'work_cap', 'repaired' ], array_keys( $r ) );
    }

    // ── the browser agrees (differential corpus, measured in Chromium) ──────

    /** @return array<string,array{0:string,1:array<string,array<string,mixed>>}> */
    public static function browserCases(): array
    {
        return require __DIR__ . '/Support/html-styles-browser-cases.php';
    }

    /**
     * Every resolved value equals Chromium's computed value; the expectations hold the browser's value for what the resolver claims and
     * null where the resolver must stay unresolved (or claims nothing), so a regression in either direction fails.
     *
     * @param array<string,array<string,mixed>> $expect role => prop => expected (value, or 'unresolved' / 'none' / 'unknown' / 'no-value')
     */
    #[DataProvider('browserCases')]
    public function testTheBrowserAgrees( string $html, array $expect ): void
    {
        $s  = HtmlSource::parse( $html );
        $this->assertNull( $s['error'] );
        $r  = HtmlStyles::resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], HtmlStyles::document( $s ) );
        $xp = new \DOMXPath( $s['body']->ownerDocument );
        foreach ( $expect as $role => $props ) {
            $el = $xp->query( '//*[@role="' . $role . '"]' )->item( 0 );
            $this->assertInstanceOf( \DOMElement::class, $el, $role );
            $rec = $r['nodes'][ $el->getNodePath() ];
            foreach ( $props as $prop => $want ) {
                $got = $rec[ $prop ];
                $this->assertSame( $want, self::summary( $prop, $got ), $role . ' ' . $prop );
            }
        }
    }

    /** The comparable form of one property of a record (the differential script uses the same). */
    public static function summary( string $prop, mixed $got ): mixed
    {
        if ( in_array( $prop, [ 'padding' ], true ) ) {
            return $got;
        }
        if ( in_array( $prop, [ 'max_width', 'radius' ], true ) ) {
            return $got;
        }
        if ( 'background' === $prop ) {
            return 'set' === $got['state'] ? $got['value'] : $got['state'];
        }
        if ( 'unresolved' === $got['state'] ) {
            return 'unresolved';
        }

        return null === $got['value'] ? 'no-value' : $got['value'];
    }

    // ── review round 1 ───────────────────────────────────────────────────────

    /** I1: the document facts are a required argument, so a caller cannot drop css_media, cut_attrs, external_css or repaired by accident. */
    public function testTheDocumentFactsAreRequired(): void
    {
        $m = new \ReflectionMethod( HtmlStyles::class, 'resolve' );
        $this->assertSame( 5, $m->getNumberOfRequiredParameters() );
        $this->assertSame( 'document', $m->getParameters()[4]->getName() );
    }

    /** The ruling "fail closed": when a browser would repair the markup in a way libxml2 does not, nothing is resolved. */
    public function testRepairedMarkupResolvesNothing(): void
    {
        $r = $this->page( 'p{color:#111111;padding:4px} .b{background:#fff}', '<b><p class="b">x</b>y</p><p>z</p>' );
        $this->assertSame( [ \AiEditorDivi5\WP\HtmlSource::REPAIRS['autoclose'] ], $r['repaired'] );
        $this->assertTrue( $r['unread'] );
        foreach ( $r['nodes'] as $path => $rec ) {
            foreach ( [ 'color', 'family', 'size', 'weight', 'align' ] as $prop ) {
                $this->assertSame( 'unresolved', $rec[ $prop ]['state'], $path . ' ' . $prop );
            }
            $this->assertSame( 'unknown', $rec['background']['state'], $path );
            $this->assertSame( [ 'top' => 'unresolved', 'right' => 'unresolved', 'bottom' => 'unresolved', 'left' => 'unresolved' ], $rec['padding'], $path );
        }
        $this->assertStringStartsWith( 'the markup would be repaired by a browser in a way this converter does not model: styles were not carried: ', HtmlStyles::repairedNote( $r['repaired'] ) );
        $this->assertSame( '', HtmlStyles::repairedNote( [] ) );
        $this->assertSame( [], $this->page( 'p{color:#111111}', '<p>x</p>' )['repaired'] );
    }

    /** C8: an option outside a select resets its weight (Chromium's UA sheet). */
    public function testAnOptionDoesNotInheritTheWeight(): void
    {
        $r = $this->page( 'div{font-weight:700}', '<div><option>o</option></div>' );
        $this->assertNull( $this->at( $r, '/html/body/div/option', 'weight' )['value'] );
    }

    /** I3: zoom, scale and a scaling transform render text and boxes at another size than computed: sizes and boxes are unresolved, colours not. */
    public function testScaleMakesSizesAndBoxesUnresolvedBelow(): void
    {
        $r = $this->page( 'body{zoom:1.5} p{font-size:20px;color:#000000;padding:4px;max-width:500px;border-radius:3px}', '<p>x <span>y</span></p>' );
        $this->assertUnresolved( $this->at( $r, '/html/body/p', 'size' ), 'effect' );
        $this->assertUnresolved( $this->at( $r, '/html/body/p/span', 'size' ), 'effect' );
        $this->assertSet( '#000000', $this->at( $r, '/html/body/p', 'color' ) );
        $this->assertSame( 'unresolved', $this->at( $r, '/html/body/p', 'padding' )['top'] );
        $this->assertSame( 'unresolved', $this->at( $r, '/html/body/p', 'max_width' ) );
        $this->assertSame( 'unresolved', $this->at( $r, '/html/body/p', 'radius' ) );
        // Round 2: presence, whatever the value: no "safe" transform (the reviewer's table).
        $p = '<p style="font-size:20px;padding:10px;max-width:200px;border-radius:4px">x</p>';
        foreach ( [ '<div style="transform:scale(2)">' . $p . '</div>', '<div style="transform:rotateX(60deg)">' . $p . '</div>',
            '<div style="transform:rotate3d(1,0,0,60deg)">' . $p . '</div>', '<div style="rotate:x 60deg">' . $p . '</div>',
            '<div style="perspective:100px"><div style="transform:translateZ(50px)">' . $p . '</div></div>',
            '<div style="perspective:100px"><div style="transform:translate3d(0,0,50px)">' . $p . '</div></div>',
            '<div style="perspective:100px"><div style="translate:0 0 50px">' . $p . '</div></div>',
            '<div style="transform:translateY(-50%)">' . $p . '</div>', '<div style="transform:none">' . $p . '</div>',
            '<div style="font-size-adjust:1">' . $p . '</div>', '<p style="font-size:20px;font-size-adjust:1">x</p>' ] as $html ) {
            $r    = $this->page( '', $html );
            $path = array_key_last( $r['nodes'] );
            $this->assertUnresolved( $r['nodes'][ $path ]['size'], 'effect', $html );
        }
    }

    /**
     * I2a: rules with the same subject are merged at reading time, keeping the best-ranked declaration per property. Ranks are static, so
     * the merged cascade must equal the unmerged one exactly: proven over seeded random stylesheets and documents, both doctype modes.
     */
    public function testMergingRulesOfTheSameSubjectChangesNothing(): void
    {
        $state = 20261022;
        $next  = static function ( int $max ) use ( &$state ): int {
            $state = ( $state * 1103515245 + 12345 ) & 0x7fffffff;

            return $state % $max;
        };
        $sels  = [ 'p', 'div', '.a', '.b', '.A', '#x', '#X', '.a.b', 'p.a', '.hero p', '*', ':root', 'div p', '[data-x]', 'p:hover', '#x.a' ];
        $decls = [ 'color:#111111', 'color:#222222', 'color:var(--c)', 'color:#333333 !important', 'font-size:20px', 'font-size:1.5em', 'font-size:2rem !important',
            'font-weight:700', 'text-align:center', 'padding:4px', 'padding:1em 2px', 'max-width:600px', 'border-radius:3px', 'background:#fff', 'background:url(x)',
            'opacity:.5', 'zoom:2', 'font-family:Inter', 'color:inherit', 'font:12px Inter' ];
        $tags  = [ 'p', 'div', 'section', 'span', 'h2', 'a' ];
        for ( $case = 0; $case < 150; $case++ ) {
            $css = '';
            for ( $k = 0, $rules = 1 + $next( 14 ); $k < $rules; $k++ ) {
                $body = [];
                for ( $d = 0, $nd = 1 + $next( 3 ); $d < $nd; $d++ ) {
                    $body[] = $decls[ $next( count( $decls ) ) ];
                }
                $rule = $sels[ $next( count( $sels ) ) ] . '{' . implode( ';', $body ) . '}';
                $css .= 0 === $next( 6 ) ? '@media (min-width:1px){' . $rule . '}' : $rule;
            }
            $html = '';
            for ( $e = 0, $ne = 2 + $next( 8 ); $e < $ne; $e++ ) {
                $tag   = $tags[ $next( count( $tags ) ) ];
                $attrs = [ '', ' class="a"', ' class="b A"', ' class="a b"', ' id="x"', ' id="X" class="a"' ][ $next( 6 ) ];
                $style = 0 === $next( 4 ) ? ' style="' . $decls[ $next( count( $decls ) ) ] . '"' : '';
                $html .= '<' . $tag . $attrs . $style . '>t<span class="a">u</span></' . $tag . '>';
            }
            foreach ( [ '<!DOCTYPE html>', '' ] as $doctype ) {
                $s   = HtmlSource::parse( $doctype . '<html><head><style>' . $css . '</style></head><body>' . $html . '</body></html>' );
                $doc = HtmlStyles::document( $s );
                $a   = HtmlStyles::resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], $doc );
                $b   = HtmlStyles::resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], [ 'merge_rules' => false ] + $doc );
                $this->assertSame( $b, $a, 'case ' . $case . ' ' . $css );
            }
        }
    }

    /** I2: the worst shapes the review measured stay fast, and memory stays under the default limit. */
    public function testTheReviewedWorstShapesAreFast(): void
    {
        $shapes = [
            'identical rules' => '<!DOCTYPE html><html><head><style>' . str_repeat( ".a{color:#123456;font-size:12px;padding:1px 2px 3px 4px;border-radius:1px}\n", 1300 ) . '</style></head><body>' . str_repeat( '<p class="a">x</p>', 4990 ) . '</body></html>',
            'quirks rules'    => '<html><head><style>' . str_repeat( ".A{color:#000}\n", 2000 ) . '</style></head><body>' . str_repeat( '<p class="a b c d e f g h">x</p>', 4990 ) . '</body></html>',
        ];
        foreach ( $shapes as $name => $html ) {
            $s = HtmlSource::parse( $html );
            $this->assertNull( $s['error'], $name );
            $start = hrtime( true );
            $r     = HtmlStyles::resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], HtmlStyles::document( $s ) );
            $ms    = ( hrtime( true ) - $start ) / 1e6;
            $this->assertLessThan( 1500, $ms, $name . ' resolved in ' . round( $ms ) . ' ms' );
            $this->assertFalse( $r['work_cap'], $name );
            unset( $r, $s );
        }
        $this->assertLessThan( 128 * 1048576, memory_get_peak_usage() );
    }

    /** I2b: past a fixed work budget the rest of the page is unresolved and the result says so; deterministic. */
    public function testTheWorkBudgetTripsDeterministically(): void
    {
        $css = '';
        for ( $i = 0; $i < 2000; $i++ ) {
            $css .= '.a.x' . $i . '{color:#000}';
        }
        $html = '<!DOCTYPE html><html><head><style>' . $css . '</style></head><body>' . str_repeat( '<p class="a">x</p>', 4000 ) . '</body></html>';
        $s    = HtmlSource::parse( $html );
        $this->assertNull( $s['error'] );
        $start = hrtime( true );
        $r     = HtmlStyles::resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], HtmlStyles::document( $s ) );
        $this->assertLessThan( 1500, ( hrtime( true ) - $start ) / 1e6 );
        $this->assertTrue( $r['work_cap'] );
        $this->assertTrue( $r['unread'] );
        $this->assertSame( 'initial', $r['nodes']['/html/body/p[1]']['color']['state'], 'the first elements are within the budget' );
        $this->assertSame( 'unresolved', $r['nodes']['/html/body/p[4000]']['color']['state'], 'the rest is not' );
        $hash = md5( serialize( $r ) );
        unset( $r );
        $this->assertSame( $hash, md5( serialize( HtmlStyles::resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], HtmlStyles::document( $s ) ) ) ) );
        $this->assertFalse( $this->page( '.a{color:#000}', '<p class="a">x</p>' )['work_cap'] );
    }

    /** N2: in a page that may be in quirks mode a table takes the body's colour (-internal-quirk-inherit), not its parent's. */
    public function testAQuirksTableTakesTheBodysColour(): void
    {
        $body = '<body style="color:#00ff00"><div style="color:#ff0000"><table><tr><td>a</td></tr></table></div><table><tr><td>b<table><tr><td>c</td></tr></table></td></tr></table><div style="color:#ff0000"><table style="color:#0000ff"><tr><td>d</td></tr></table></div></body>';
        foreach ( [ '', '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN">', '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 3.2 Final//EN">', '<!DOCTYPE html foo>' ] as $doctype ) {
            $r = $this->styles( $doctype . '<html>' . $body . '</html>' );
            $this->assertUnresolved( $r['nodes']['/html/body/div[1]/table/tr/td']['color'], null, $doctype . ' under a red div' );
            $this->assertSet( '#00ff00', $r['nodes']['/html/body/table/tr/td']['color'], $doctype . ' directly in the body' );
            $this->assertSet( '#00ff00', $r['nodes']['/html/body/table/tr/td/table/tr/td']['color'], $doctype . ' a nested table' );
            $this->assertSet( '#0000ff', $r['nodes']['/html/body/div[2]/table/tr/td']['color'], $doctype . ' its own colour' );
        }
        $r = $this->styles( '<!DOCTYPE html><html>' . $body . '</html>' );
        $this->assertSet( '#ff0000', $r['nodes']['/html/body/div[1]/table/tr/td']['color'], 'standards mode: the parent' );
    }

    /** The children of meter and progress are not rendered and inherit nothing from them (Chromium: initial values); own values still count. */
    public function testMeterAndProgressChildrenHaveAnUnknownParent(): void
    {
        foreach ( [ 'meter', 'progress' ] as $tag ) {
            $r = $this->page( '', '<div style="color:#ff0000;font-size:30px"><' . $tag . ' style="color:#00ff00;font-size:20px"><span>x</span><b style="color:#0000ff">y</b></' . $tag . '></div>' );
            $this->assertUnresolved( $r['nodes'][ '/html/body/div/' . $tag . '/span' ]['color'], null, $tag );
            $this->assertUnresolved( $r['nodes'][ '/html/body/div/' . $tag . '/span' ]['size'], null, $tag );
            $this->assertSet( '#0000ff', $r['nodes'][ '/html/body/div/' . $tag . '/b' ]['color'], $tag );
        }
    }

    /** Review round 4, D1: in math, inherited size, weight, family and align are unknown (MathML UA sheet, math-depth); colour inherits. */
    public function testMathInheritsOnlyTheColour(): void
    {
        $r = $this->page( '', '<div style="color:#ff0000;font-size:30px;font-weight:700;font-family:Georgia;text-align:right"><math><msup><mi>x</mi><mn>2</mn></msup></math></div><math style="font-family:Arial"><mi style="font-size:20px">y</mi></math>' );
        foreach ( [ 'size', 'weight', 'family', 'align' ] as $prop ) {
            $this->assertUnresolved( $r['nodes']['/html/body/div/math'][ $prop ], null, 'math ' . $prop );
            $this->assertUnresolved( $r['nodes']['/html/body/div/math/msup/mn'][ $prop ], null, 'mn ' . $prop );
        }
        $this->assertSet( '#ff0000', $r['nodes']['/html/body/div/math/msup/mn']['color'] );
        $this->assertSet( [ 'Arial' ], $r['nodes']['/html/body/math']['family'], 'its own declaration' );
        $this->assertSet( 20.0, $r['nodes']['/html/body/math/mi']['size'], 'its own declaration' );
    }

    /** D2: ruby > rt takes text-align from the UA sheet (start); rt in rtc and rt outside ruby inherit. */
    public function testARubyTextAlignsAtTheStart(): void
    {
        $r = $this->page( '', '<div style="text-align:right"><ruby>a<rt>b<span>c</span></rt><rtc><rt>d</rt></rtc></ruby><p><rt>e</rt></p></div>' );
        $this->assertSame( [ 'state' => 'initial', 'value' => null, 'reason' => null ], $r['nodes']['/html/body/div/ruby/rt']['align'] );
        $this->assertUnresolved( $r['nodes']['/html/body/div/ruby/rt/span']['align'] );
        $this->assertSet( 'right', $r['nodes']['/html/body/div/ruby/rtc/rt']['align'] );
        $this->assertSet( 'right', $r['nodes']['/html/body/div/p/rt']['align'] );
        $r = $this->page( 'rt{text-align:center}', '<ruby>a<rt>b</rt></ruby>' );
        $this->assertSet( 'center', $r['nodes']['/html/body/ruby/rt']['align'], 'its own declaration' );
    }
}
