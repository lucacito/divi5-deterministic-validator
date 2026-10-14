<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HtmlSource;
use PHPUnit\Framework\Attributes\DataProvider;

/** The safety boundary: what survives parsing, and what is written down as removed. */
final class HtmlSourceTest extends HtmlTestCase
{
    private function parse( string $body ): array
    {
        return HtmlSource::parse( '<html><body>' . $body . '</body></html>' );
    }

    private function bodyHtml( array $s ): string
    {
        return (string) $s['body']->ownerDocument->saveHTML( $s['body'] );
    }

    public function testRefusesEmptyOversizedAndNonUtf8Input(): void
    {
        $this->assertStringContainsString( 'empty', (string) HtmlSource::parse( '' )['error'] );
        $this->assertStringContainsString( 'empty', (string) HtmlSource::parse( " \n\t " )['error'] );
        $this->assertStringContainsString( 'larger than', (string) HtmlSource::parse( str_repeat( 'a', HtmlSource::MAX_BYTES + 1 ) )['error'] );
        $this->assertStringContainsString( 'UTF-8', (string) HtmlSource::parse( "<p>caf\xe9</p>" )['error'] );
        $this->assertNull( HtmlSource::parse( str_repeat( 'a', HtmlSource::MAX_BYTES ) )['error'], 'exactly at the cap is fine' );
    }

    public function testRefusesTooManyElementsAndTooDeepNesting(): void
    {
        $many = '<p>' . str_repeat( '<i>x</i>', HtmlSource::MAX_ELEMENTS ) . '</p>';
        $this->assertStringContainsString( 'more than', (string) $this->parse( $many )['error'] );

        $deep = str_repeat( '<div>', HtmlSource::MAX_DEPTH + 5 ) . 'x' . str_repeat( '</div>', HtmlSource::MAX_DEPTH + 5 );
        $this->assertStringContainsString( 'nested deeper', (string) $this->parse( $deep )['error'] );

        $ok = str_repeat( '<div>', 30 ) . 'x' . str_repeat( '</div>', 30 );
        $this->assertNull( $this->parse( $ok )['error'] );
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function dropped(): array
    {
        return [
            'script'   => [ '<script>alert(1)</script><p>keep</p>', 'script' ],
            'style'    => [ '<style>p{color:red}</style><p>keep</p>', 'style' ],
            'iframe'   => [ '<iframe src="https://x.example"></iframe><p>keep</p>', 'iframe' ],
            'form'     => [ '<form action="/x"><input name="a"><button>Send</button></form><p>keep</p>', 'form' ],
            'object'   => [ '<object data="x.swf"></object><p>keep</p>', 'object' ],
            'embed'    => [ '<embed src="x.swf"><p>keep</p>', 'embed' ],
            'svg'      => [ '<svg><script>alert(1)</script><circle r="5"/></svg><p>keep</p>', 'svg' ],
            'video'    => [ '<video src="v.mp4"></video><p>keep</p>', 'video' ],
            'noscript' => [ '<noscript><img src="x"></noscript><p>keep</p>', 'noscript' ],
            'template' => [ '<template><p>hidden</p></template><p>keep</p>', 'template' ],
        ];
    }

    #[DataProvider('dropped')]
    public function testDroppedElementsAreRemovedAndReported( string $body, string $tag ): void
    {
        $s = $this->parse( $body );
        $html = $this->bodyHtml( $s );
        $this->assertStringNotContainsString( '<' . $tag, $html );
        $this->assertStringContainsString( 'keep', $html );
        $this->assertContains( $tag, array_column( $s['flagged'], 'type' ) );
        foreach ( $s['flagged'] as $f ) {
            $this->assertNotSame( '', $f['reason'] );
            $this->assertNotSame( '', $f['path'] );
        }
    }

    public function testContentSwallowedByAVoidElementIsKeptAndStillCleaned(): void
    {
        foreach ( [ 'embed', 'input', 'link', 'meta', 'base' ] as $tag ) {
            $s = $this->parse( '<' . $tag . ' src="x"><p onclick="x()" style="y">kept <a href="javascript:alert(1)" onclick="z()">link</a></p><script>alert(1)</script>' );
            $html = $this->bodyHtml( $s );
            $this->assertStringContainsString( 'kept', $html, $tag );
            foreach ( [ 'onclick', 'style=', 'javascript:', '<script', '<' . $tag ] as $bad ) {
                $this->assertStringNotContainsString( $bad, $html, $tag . ' / ' . $bad );
            }
        }
    }

    public function testCommentsAndProcessingInstructionsAreRemoved(): void
    {
        $html = $this->bodyHtml( $this->parse( '<!-- <script>alert(1)</script> --><p>a</p><?php echo 1; ?>' ) );
        $this->assertStringNotContainsString( '<!--', $html );
        $this->assertStringNotContainsString( 'echo', $html );
    }

    public function testEveryAttributeOutsideTheAllowListIsDropped(): void
    {
        $s = $this->parse( '<div style="background:url(javascript:1)" onclick="x()" data-x="1" onmouseover="y()" class="c" id="i" role="button" contenteditable="true"><a href="/a" onclick="z()" style="x" title="t" target="_blank" rel="evil" data-y="1">go</a><img src="/i.png" alt="a" onerror="x()" srcset="evil 2x" width="10" height="5" loading="lazy"></div>' );
        $html = $this->bodyHtml( $s );
        foreach ( [ 'style=', 'onclick', 'onmouseover', 'onerror', 'data-x', 'data-y', 'srcset', 'contenteditable', 'loading', 'rel="evil"' ] as $bad ) {
            $this->assertStringNotContainsString( $bad, $html, $bad );
        }
        foreach ( [ 'class="c"', 'id="i"', 'role="button"', 'href="/a"', 'title="t"', 'target="_blank"', 'src="/i.png"', 'alt="a"', 'width="10"' ] as $kept ) {
            $this->assertStringContainsString( $kept, $html, $kept );
        }
    }

    /** @return array<string,array{0:string,1:?string}> */
    public static function urls(): array
    {
        return [
            'https'              => [ 'https://example.com/a?b=c#d', 'https://example.com/a?b=c#d' ],
            'http'               => [ 'http://example.com', 'http://example.com' ],
            'mailto'             => [ 'mailto:a@example.com', 'mailto:a@example.com' ],
            'tel'                => [ 'tel:+1555123', 'tel:+1555123' ],
            'relative'           => [ '/pricing', '/pricing' ],
            'relative dot'       => [ '../a/b.html', '../a/b.html' ],
            'anchor'             => [ '#top', '#top' ],
            'query'              => [ '?a=1', '?a=1' ],
            'bare path'          => [ 'about.html', 'about.html' ],
            'javascript'         => [ 'javascript:alert(1)', null ],
            'javascript upper'   => [ 'JaVaScRiPt:alert(1)', null ],
            'javascript tabbed'  => [ "java\tscript:alert(1)", null ],
            'javascript newline' => [ "java\nscript:alert(1)", null ],
            'javascript spaces'  => [ '  javascript:alert(1)', null ],
            'vbscript'           => [ 'vbscript:msgbox(1)', null ],
            'data'               => [ 'data:text/html,<script>alert(1)</script>', null ],
            'file'               => [ 'file:///etc/passwd', null ],
            'ftp'                => [ 'ftp://example.com/x', null ],
            'protocol relative'  => [ '//evil.example/x', null ],
            'backslash'          => [ '\\\\evil.example\\x', null ],
            'control chars only' => [ "\x01\x02", null ],
            'empty'              => [ '', null ],
            'huge'               => [ 'https://example.com/' . str_repeat( 'a', 2100 ), null ],
        ];
    }

    #[DataProvider('urls')]
    public function testSafeUrl( string $in, ?string $expected ): void
    {
        $this->assertSame( $expected, HtmlSource::safeUrl( $in ) );
    }

    public function testAnUnsafeLinkLosesItsHrefButKeepsItsText(): void
    {
        $s = $this->parse( '<p><a href="javascript:alert(1)">click me</a></p><img src="data:image/png;base64,AAAA" alt="x">' );
        $html = $this->bodyHtml( $s );
        $this->assertStringContainsString( 'click me', $html );
        $this->assertStringNotContainsString( 'javascript:', $html );
        $this->assertStringNotContainsString( 'data:image', $html );
        $this->assertSame( 2, $s['stats']['urls_dropped'] );
        $this->assertContains( 'href', array_column( $s['flagged'], 'type' ) );
    }

    public function testExternalEntitiesAreNeverResolved(): void
    {
        $xxe = '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><html><body><p>before &xxe; after</p></body></html>';
        $s = HtmlSource::parse( $xxe );
        $html = (string) ( $s['body'] ? $s['body']->ownerDocument->saveHTML() : '' );
        $this->assertStringNotContainsString( 'root:', $html );
        $this->assertStringNotContainsString( '/bin/', $html );
    }

    public function testTitleAndH1Suggestions(): void
    {
        $s = HtmlSource::parse( '<html><head><title>  My   Page </title></head><body><h1>Headline</h1></body></html>' );
        $this->assertSame( 'My Page', $s['title'] );
        $this->assertSame( [ 'Headline' ], $s['h1s'] );
        $s = HtmlSource::parse( '<body><h1>Only a headline</h1></body>' );
        $this->assertSame( 'Only a headline', $s['title'], 'the first h1 when there is no <title>' );
        $this->assertSame( '', HtmlSource::parse( '<p>x</p>' )['title'] );
        $long = HtmlSource::parse( '<title>' . str_repeat( 'é', 500 ) . '</title><p>x</p>' );
        $this->assertSame( 200, mb_strlen( $long['title'] ) );
    }

    public function testFragmentsWithoutBodyOrHtmlWork(): void
    {
        $s = HtmlSource::parse( '<h2>Just a fragment</h2><p>and text</p>' );
        $this->assertNull( $s['error'] );
        $this->assertSame( 'Just a fragmentand text', HtmlSource::collapse( $s['body']->textContent ), 'textContent joins block text without a space: the converter compares without whitespace' );
    }

    public function testUnicodeAndEntitiesSurvive(): void
    {
        $s = $this->parse( '<p>Café — 日本語 🎉 &amp; &lt;b&gt; &copy; أهلا</p>' );
        $this->assertSame( 'Café — 日本語 🎉 & <b> © أهلا', HtmlSource::collapse( $s['body']->textContent ) );
    }

    public function testControlCharactersAreStripped(): void
    {
        $s = $this->parse( "<p>a\x00b\x07c</p>" );
        $this->assertSame( 'abc', HtmlSource::collapse( $s['body']->textContent ) );
    }

    public function testMalformedHtmlStillParses(): void
    {
        foreach ( [ '<p>unclosed <b>bold', '</div></div><p>x', '<div<p>>x', '<<<>>>', '<a href="x>y</a>', '<table><tr><td>cell' ] as $junk ) {
            $s = $this->parse( $junk );
            $this->assertNull( $s['error'], $junk );
        }
    }

    public function testThePathReadsLikeAPosition(): void
    {
        $s = $this->parse( '<section><div></div><div><script>x</script></div></section>' );
        $this->assertSame( 'section[1] > div[2] > script[1]', $s['flagged'][0]['path'] );
    }

    public function testTheFlaggedListIsCapped(): void
    {
        $s = $this->parse( str_repeat( '<script>x</script>', 300 ) . '<p>keep</p>' );
        $this->assertCount( 100, $s['flagged'] );
        $this->assertSame( 300, $s['stats']['removed'] );
    }
}
