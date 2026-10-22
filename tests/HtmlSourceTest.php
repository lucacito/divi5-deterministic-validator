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

    // ── styles hand-over (html_to_divi styles, task 4) ─────────────────────

    public function testStyleTextAndInlineStylesAreHandedOverButNeverKeptInTheDom(): void
    {
        $s = HtmlSource::parse( '<html><head><style>h1{color:red}</style></head><body style="color:#111"><h1 style="font-size:40px">x</h1><style>p{}</style></body></html>' );
        $this->assertNull( $s['error'] );
        $this->assertSame( "h1{color:red}\np{}", $s['css'] );
        $this->assertSame( 'color:#111', $s['inline']['/html/body'] );
        $this->assertSame( 'font-size:40px', $s['inline']['/html/body/h1'] );
        $this->assertStringNotContainsString( 'style=', $this->bodyHtml( $s ) );
        // The paths belong to the returned DOM.
        $xp = new \DOMXPath( $s['body']->ownerDocument );
        $this->assertSame( 'x', $xp->query( '/html/body/h1' )->item( 0 )->textContent );
    }

    public function testTheHtmlElementsOwnStyleAndAttributesAreHandedOver(): void
    {
        $s = HtmlSource::parse( '<html style="color:#222" bgcolor="#fff"><body><p>x</p></body></html>' );
        $this->assertSame( 'color:#222', $s['inline']['/html'] );
        $this->assertSame( [ 'bgcolor' ], $s['presentational']['/html'] );
    }

    public function testPresentationalAttributesAreRecorded(): void
    {
        $s = $this->parse( '<table bgcolor="#000"><tr><td align="center">x</td></tr></table><font color="red" face="Arial">y</font>' );
        $this->assertSame( [ 'bgcolor' ], $s['presentational']['/html/body/table'] );
        $this->assertSame( [ 'align' ], $s['presentational']['/html/body/table/tr/td'] );
        $this->assertSame( [ 'color', 'face' ], $s['presentational']['/html/body/font'] );
        $this->assertStringNotContainsString( 'bgcolor', $this->bodyHtml( $s ) );
        $this->assertStringNotContainsString( 'align', $this->bodyHtml( $s ) );
        $this->assertStringNotContainsString( 'face', $this->bodyHtml( $s ) );
    }

    public function testAnInlineStyleOfARemovedElementIsNotHandedOver(): void
    {
        $s = $this->parse( '<form action="/x" style="color:red"><div style="color:blue">a</div></form><p style="color:green">keep</p>' );
        $this->assertSame( [ '/html/body/p' => 'color:green' ], $s['inline'] );
    }

    /** The seam and the CSS reader must agree on the cap, or the reader would never see that the text was cut. */
    public function testTheSeamAndTheCssReaderShareTheSameByteCap(): void
    {
        $this->assertSame( \AiEditorDivi5\WP\HtmlCss::MAX_CSS_BYTES, HtmlSource::MAX_CSS_BYTES );
    }

    public function testTheStyleTextIsHandedOverOneByteOverTheCapSoTheReaderSeesItWasCut(): void
    {
        $s = $this->parse( '<style>' . str_repeat( 'é', 60000 ) . '</style><p>x</p>' );
        $this->assertNull( $s['error'] );
        $this->assertSame( HtmlSource::MAX_CSS_BYTES + 1, strlen( $s['css'] ), 'plain substr, possibly inside a character' );

        $exact = $this->parse( '<style>' . str_repeat( 'a', HtmlSource::MAX_CSS_BYTES ) . '</style><p>x</p>' );
        $this->assertSame( HtmlSource::MAX_CSS_BYTES, strlen( $exact['css'] ), 'a text of exactly the cap is not cut' );

        // A colour cut by the cap must never look complete: the text is longer than the cap, so the reader notices.
        $css = '<style>' . str_repeat( ' ', 10 ) . 'p{x:y}' . str_repeat( 'a{b:c}', 20000 ) . '</style><p>x</p>';
        $this->assertGreaterThan( HtmlSource::MAX_CSS_BYTES, strlen( $this->parse( $css )['css'] ) );
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function styleBlocks(): array
    {
        return [
            'plain'                 => [ '<style>p{color:red}</style>', true ],
            'media screen'          => [ '<style media="screen">p{color:red}</style>', true ],
            'media all'             => [ '<style media=" ALL ">p{color:red}</style>', true ],
            'type text/css'         => [ '<style type="Text/CSS">p{color:red}</style>', true ],
            'type with a space'     => [ '<style type=" text/css">p{color:red}</style>', false ],
            'type with a tail'      => [ '<style type="text/css ">p{color:red}</style>', false ],
            'type with a charset'   => [ '<style type="text/css;charset=utf-8">p{color:red}</style>', false ],
            'media with spaces'     => [ '<style media=" screen ">p{color:red}</style>', true ],
            'media print'           => [ '<style media="print">p{color:red}</style>', false ],
            'media query'           => [ '<style media="(min-width:600px)">p{color:red}</style>', false ],
            'type text/less'        => [ '<style type="text/less">p{color:red}</style>', false ],
            'disabled'              => [ '<style disabled>p{color:red}</style>', true ], // measured: Chromium applies it (only <link> has disabled)
            'inside template'       => [ '<template><style>p{color:red}</style></template>', false ],
            'inside noscript'       => [ '<noscript><style>p{color:red}</style></noscript>', false ],
            'inside textarea'       => [ '<textarea><style>p{color:red}</style></textarea>', false ],
            'inside svg'            => [ '<svg><style>p{color:red}</style></svg>', true ],
            'inside form'           => [ '<form><style>p{color:red}</style></form>', true ],
            'inside object'         => [ '<object><style>p{color:red}</style></object>', true ],
            'inside video fallback' => [ '<video><style>p{color:red}</style></video>', true ],
        ];
    }

    #[DataProvider('styleBlocks')]
    public function testOnlyStyleBlocksThatApplyAreCollected( string $block, bool $kept ): void
    {
        $s = $this->parse( $block . '<p>x</p>' );
        $this->assertNull( $s['error'] );
        $this->assertSame( $kept ? 'p{color:red}' : '', $s['css'] );
        $this->assertCount( $kept ? 0 : 1, $s['style_flags'] );
        if ( ! $kept ) {
            $this->assertSame( 'style', $s['style_flags'][0]['type'] );
            $this->assertStringContainsString( 'not read', $s['style_flags'][0]['reason'] );
            $this->assertNotSame( '', $s['style_flags'][0]['path'] );
        }
    }

    public function testASkippedHeadStyleDoesNotChangeTheFlaggedList(): void
    {
        $with = HtmlSource::parse( '<html><head><style media="print">p{}</style><link rel="stylesheet" href="a.css"></head><body><p>x</p></body></html>' );
        $without = HtmlSource::parse( '<html><head></head><body><p>x</p></body></html>' );
        $this->assertSame( $without['flagged'], $with['flagged'], 'styles=none reports stay phase 1' );
    }

    public function testLinkedStylesheetsAreCountedAndFlagged(): void
    {
        $s = HtmlSource::parse( '<html><head><link rel="stylesheet" href="a.css"><link rel="Stylesheet preload" href="b.css"><link rel="alternate stylesheet" href="c.css"><link rel="icon" href="i.png"><noscript><link rel="stylesheet" href="d.css"></noscript></head><body><p>x</p></body></html>' );
        $this->assertSame( 2, $s['external_css'] );
        $flags = array_values( array_filter( $s['style_flags'], static fn ( array $f ): bool => 'link' === $f['type'] ) );
        $this->assertCount( 1, $flags );
        $this->assertSame( '2 linked stylesheets were not read: colours and fonts may differ.', $flags[0]['reason'] );

        $one = HtmlSource::parse( '<html><head><link rel="stylesheet" href="a.css"></head><body><p>x</p></body></html>' );
        $this->assertSame( 1, $one['external_css'] );
        $this->assertSame( '1 linked stylesheet was not read: colours and fonts may differ.', $one['style_flags'][0]['reason'] );

        $none = $this->parse( '<p>x</p>' );
        $this->assertSame( 0, $none['external_css'] );
        $this->assertSame( [], $none['style_flags'] );
    }

    /** @return array<string,array{0:string}> */
    public static function presentationalNames(): array
    {
        $out = [];
        foreach ( [ 'bgcolor', 'background', 'color', 'text', 'link', 'vlink', 'alink', 'face', 'size', 'align', 'valign' ] as $n ) {
            $out[ $n ] = [ $n ];
        }

        return $out;
    }

    #[DataProvider('presentationalNames')]
    public function testEveryPresentationalAttributeIsRecordedAndNeverKept( string $name ): void
    {
        $s = HtmlSource::parse( '<html><body><div ' . $name . '="x"><p>t</p></div></body></html>' );
        $this->assertSame( [ $name ], $s['presentational']['/html/body/div'] );
        $this->assertStringNotContainsString( $name . '=', $this->bodyHtml( $s ) );
        $b = HtmlSource::parse( '<html><body ' . $name . '="x"><p>t</p></body></html>' );
        $this->assertSame( [ $name ], $b['presentational']['/html/body'] );
        $this->assertStringNotContainsString( $name . '=', $this->bodyHtml( $b ) );
    }
    public function testNoStylesMeansEmptyHandOvers(): void
    {
        $s = $this->parse( '<p>x</p>' );
        $this->assertSame( '', $s['css'] );
        $this->assertSame( [], $s['inline'] );
        $this->assertSame( [], $s['presentational'] );
        $e = HtmlSource::parse( '' );
        $this->assertSame( [ '', [], [] ], [ $e['css'], $e['inline'], $e['presentational'] ] );
    }

    public function testAMovedChildKeepsItsStyleUnderItsFinalPath(): void
    {
        // libxml2 may nest what follows a void <embed>; the children are moved out, so the path is read after the move.
        $s  = $this->parse( '<div><embed src="x.swf"><p style="color:red">after</p></div>' );
        $xp = new \DOMXPath( $s['body']->ownerDocument );
        foreach ( array_keys( $s['inline'] ) as $path ) {
            $this->assertSame( 1, $xp->query( $path )->length, $path );
        }
        $this->assertSame( [ 'color:red' ], array_values( $s['inline'] ) );
    }

    // ── document facts for the cascade (html_to_divi styles, task 6) ───────

    /** @return array<string,array{0:string,1:bool}> */
    public static function doctypes(): array
    {
        return [
            'html5'             => [ '<!DOCTYPE html><html><body><p>x</p></body></html>', true ],
            'lower case, space' => [ "  \n<!doctype   html >\n<html><body><p>x</p></body></html>", true ],
            'with a BOM'        => [ "\xEF\xBB\xBF<!DOCTYPE html><p>x</p>", true ],
            'none'              => [ '<html><body><p>x</p></body></html>', false ],
            'html 4 legacy'     => [ '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"><p>x</p>', false ],
            'after text'        => [ 'hello <!DOCTYPE html><p>x</p>', false ],
            'after a comment'   => [ '<!-- a --!> b --><!DOCTYPE html><p>x</p>', false ],
        ];
    }

    /** Without a standards-mode doctype a browser matches class and id selectors case-insensitively (quirks mode): the cascade must know. */
    #[DataProvider('doctypes')]
    public function testTheDoctypeIsReportedOnlyForStandardsMode( string $html, bool $standards ): void
    {
        $s = HtmlSource::parse( $html );
        $this->assertNull( $s['error'] );
        $this->assertSame( $standards, $s['doctype'] );
    }

    public function testAColorSchemeMetaIsReported(): void
    {
        $this->assertTrue( HtmlSource::parse( '<html><head><meta name="color-scheme" content="light dark"></head><body><p>x</p></body></html>' )['color_scheme'] );
        $this->assertTrue( HtmlSource::parse( '<html><head><meta name="Color-Scheme" content="dark"></head><body><p>x</p></body></html>' )['color_scheme'] );
        $this->assertFalse( HtmlSource::parse( '<html><head><meta name="color-scheme" content="light"></head><body><p>x</p></body></html>' )['color_scheme'] );
        $this->assertFalse( HtmlSource::parse( '<html><head><meta name="color-scheme" content=" only light "></head><body><p>x</p></body></html>' )['color_scheme'] );
        $this->assertFalse( HtmlSource::parse( '<html><head><meta name="viewport" content="width=device-width"></head><body><p>x</p></body></html>' )['color_scheme'] );
        $this->assertFalse( $this->parse( '<p>x</p>' )['color_scheme'] );
    }

    /** The DOM keeps at most 300 characters of a class or id (phase 1); the cascade gets the whole value, by final path. */
    public function testALongClassOrIdIsHandedOverWhole(): void
    {
        $long = trim( str_repeat( 'utility-class-name ', 20 ) ) . ' text-white';
        $id   = str_repeat( 'i', 310 );
        $s    = $this->parse( '<div class="' . $long . '" id="' . $id . '"><p class="short">x</p></div>' );
        $this->assertSame( [ '/html/body/div' => [ 'class' => $long, 'id' => $id ] ], $s['cut_attrs'] );
        $this->assertSame( 300, strlen( $s['body']->getElementsByTagName( 'div' )->item( 0 )->getAttribute( 'class' ) ), 'the DOM is unchanged (phase 1)' );
        $this->assertSame( [], $this->parse( '<p class="short">x</p>' )['cut_attrs'] );
    }

    /** A <style media="(min-width:...)"> applies at some screen widths: it is not dropped silently but handed over to poison what it sets. */
    public function testStyleBlocksForSomeScreensAreHandedOverApart(): void
    {
        $s = $this->parse( '<style media="(min-width:600px)">p{color:red}</style><style media="print">h1{color:blue}</style><style media="print, (min-width:1px)">h2{color:green}</style><p>x</p>' );
        $this->assertSame( '', $s['css'] );
        $this->assertSame( "p{color:red}\nh2{color:green}", $s['css_media'] );
        $this->assertCount( 3, $s['style_flags'] );
        $this->assertSame( '', $this->parse( '<style>p{}</style><p>x</p>' )['css_media'] );
    }

    public function testTheDocumentFactsDoNotChangeAnythingElse(): void
    {
        $s = $this->parse( '<p>x</p>' );
        $this->assertSame( [ false, false, [], '' ], [ $s['doctype'], $s['color_scheme'], $s['cut_attrs'], $s['css_media'] ] );
        $e = HtmlSource::parse( '' );
        $this->assertSame( [ false, false, [], '' ], [ $e['doctype'], $e['color_scheme'], $e['cut_attrs'], $e['css_media'] ] );
    }

    // ── markup a browser repairs differently from libxml2 (Task 6 review rulings, round 2: fail closed) ──

    /** @return array<string,array{0:string,1:string}> body markup => the REPAIRS key reported */
    public static function repairedMarkup(): array
    {
        $r = 'style="color:#ff0000;font-size:30px"';
        return [
            // round 1, kept
            'formatting end tag over a block' => [ '<b><p>x</b>y</p>', 'autoclose' ],
            'font over a paragraph'           => [ '<font color="red"><p>x</font>y</p>', 'misnest' ],
            'em over a div'                   => [ '<em><div>x</em>y</div>', 'misnest' ],
            'span closed inside a div'        => [ '<span><div>x</span>y</div>', 'misnest' ],
            'an end tag that matches nothing' => [ '<div>x</section></div>', 'misnest' ],
            'a stray end div'                 => [ '<div><p>x</p></div></div>', 'misnest' ],
            'an img end tag'                  => [ '<div><img src="a.png"></img><p>x</p></div>', 'misnest' ],
            'a div closed over a b'           => [ '<div><b>x</div>y', 'misnest' ],
            'button in a button'              => [ '<button>a<button>b</button></button>', 'button' ],
            'nobr in a nobr'                  => [ '<nobr>a<nobr>b</nobr></nobr>', 'nobr' ],
            'link in a link'                  => [ '<a href="#">a<a href="#">b</a></a>', 'a' ],
            'dd through a div'                => [ '<dl><dt>a<div><dd>b</dd></div></dl>', 'list' ],
            'li through a div'                => [ '<ul><li>a<div><li>b</li></div></ul>', 'list' ],
            'second body with attributes'     => [ '<p>x</p><body style="color:red"><p>y</p>', 'twice' ],
            'second html with attributes'     => [ '<p>x</p><html style="color:red"></html>', 'twice' ],
            'head in the body'                => [ '<div><head><span>y</span></div>', 'head' ],
            'declarative shadow root'         => [ '<div><template shadowrootmode="open"><slot></slot></template>x</div>', 'shadow' ],
            'popover'                         => [ '<div popover>x</div>', 'popover' ],
            'style inside math'               => [ '<math><style>p{color:red}</style></math><p>x</p>', 'math' ],
            // (1) self-closing syntax on non-void elements
            'div/'                            => [ '<div ' . $r . '/><p>x</p>', 'selfclose' ],
            'div / with a space'              => [ '<div ' . $r . ' / ><p>x</p>', 'tag' ],
            'p/'                              => [ '<p ' . $r . '/><span>x</span>', 'selfclose' ],
            'span/'                           => [ '<span ' . $r . '/><span>x</span>', 'selfclose' ],
            'a/'                              => [ '<a href="#" ' . $r . '/><span>x</span>', 'selfclose' ],
            'section/'                        => [ '<section ' . $r . '/><span>x</span>', 'selfclose' ],
            'li/'                             => [ '<ul><li ' . $r . '/><span>x</span></ul>', 'selfclose' ],
            'td/'                             => [ '<table><tr><td ' . $r . '/><span>x</span></td></tr></table>', 'selfclose' ],
            // (2) foreign content: a self-closed svg opens nothing, so what follows is still checked
            'svg/ then a misnest'             => [ '<svg/><b ' . $r . '><p>x</b>y<span>s</span></p>', 'autoclose' ],
            'math/ then a misnest'            => [ '<math/><b ' . $r . '><p>x</b>y</p>', 'autoclose' ],
            'svg end tag in a comment'        => [ '<svg><!-- </svg> --></svg><b ' . $r . '><p>x</b>y</p>', 'autoclose' ],
            'svg in an attribute'             => [ '<p title="<svg>">a</p><b ' . $r . '><p>x</b>y</p>', 'autoclose' ],
            'svg in a script'                 => [ '<script>"<svg>"</script><b ' . $r . '><p>x</b>y</p>', 'autoclose' ],
            'b breaks out of svg'             => [ '<div><svg><b ' . $r . '></svg><p>x</p></div>', 'foreign' ],
            'p breaks out of svg'             => [ '<div ' . $r . '><svg><p>x</p></svg></div>', 'foreign' ],
            'span breaks out of svg'          => [ '<svg><span ' . $r . '></svg><p>x</p>', 'foreign' ],
            'font color breaks out of svg'    => [ '<div><svg><font color="#ff0000" size="7"></svg><p>x</p></div>', 'foreign' ],
            'div breaks out of math'          => [ '<div><math><div ' . $r . '></math><p>x</p></div>', 'foreign' ],
            'b breaks out of math'            => [ '<div><math><b ' . $r . '></math><p>x</p></div>', 'foreign' ],
            'div in foreignObject'            => [ '<svg><foreignObject><div ' . $r . '></svg><p>x</p>', 'foreign' ],
            'an html end tag in svg'          => [ '<div ' . $r . '><svg></div><p>x</p></svg>', 'foreign' ],
            // (3) presence
            'image'                           => [ '<image ' . $r . '><span>y</span>', 'obsolete' ],
            'xmp'                             => [ '<xmp><div ' . $r . '></xmp><p>y</p>', 'obsolete' ],
            'noembed'                         => [ '<noembed><div ' . $r . '></noembed><p>y</p>', 'obsolete' ],
            'noframes'                        => [ '<noframes><div ' . $r . '></noframes><p>y</p>', 'obsolete' ],
            'plaintext'                       => [ '<div><plaintext><p>y</p>', 'obsolete' ],
            'listing'                         => [ '<listing ' . $r . '><span>y</span></listing>', 'obsolete' ],
            'isindex'                         => [ '<div><isindex><span>y</span></div>', 'obsolete' ],
            'marquee'                         => [ '<p><marquee><span>y</span></marquee></p>', 'obsolete' ],
            'nextid, bgsound, keygen'         => [ '<div><bgsound src="a.wav"></div>', 'obsolete' ],
            'menuitem'                        => [ '<menu><menuitem>a</menuitem></menu>', 'obsolete' ],
            'frameset'                        => [ '<div><frameset></frameset><span>y</span></div>', 'obsolete' ],
            // (4) character references libxml2 2.9 does not know, in an attribute the converter reads
            'colon in style'                  => [ '<p style="color&colon;#ff0000">x</p>', 'entity' ],
            'colon in class'                  => [ '<p class="a&colon;b">x</p>', 'entity' ],
            'period in id'                    => [ '<p id="a&period;b">x</p>', 'entity' ],
            'sol in type'                     => [ '<style type="text&sol;css">p{}</style><p>x</p>', 'entity' ],
            'comma in media'                  => [ '<style media="print&comma;screen">p{}</style><p>x</p>', 'entity' ],
            'numeric in style'                => [ '<p style="color:&#35;ff0000">x</p>', 'entity' ],
            'no semicolon'                    => [ '<p style="color:#ff0000;font-size:30px&ampx">x</p>', 'entity' ],
            // tokenizer corners
            'abrupt comment'                  => [ '<!--><div ' . $r . '><p>x</p></div><!-- -->', 'comment' ],
            'bang comment end'                => [ '<!-- a --!><div ' . $r . '><p>x</p></div><!-- -->', 'comment' ],
            'cdata'                           => [ '<![CDATA[ <div ' . $r . '> ]]><p>x</p>', 'bogus' ],
            'processing instruction'          => [ '<?x <div ' . $r . '> ?><p>x</p>', 'bogus' ],
            'unquoted lt'                     => [ '<div title=a<b ' . $r . '><p>x</p></div>', 'tag' ],
            'slash between attributes'        => [ '<p/role="k1"/style="color:#ff0000">x</p>', 'tag' ],
            'glued name'                      => [ '<pstyle="color:#ff0000">x</p>', 'tag' ],
            'duplicate style'                 => [ '<p style="color:#ff0000" STYLE="color:#0000ff">x</p>', 'duplicate' ],
            'end tag inside a script'         => [ '<script>var s="</div><div ' . $r . '>";</script><p>y</p>', 'rawtext' ],
            'end tag inside a style'          => [ '<style>.q::after{content:"</p>"} p{color:#ff0000}</style><p>y</p>', 'rawtext' ],
            'end tag inside a title'          => [ '<title>a</b><div ' . $r . '></title><p>y</p>', 'rawtext' ],
            'end tag inside a textarea'       => [ '<textarea></b><div ' . $r . '></textarea><p>y</p>', 'rawtext' ],
            'content after html'              => [ '<div><p>x</p></div></body></html><div>y</div>', 'after' ],
            // round 3: libxml2's HTML 4 auto-close (N1)
            'b around a p'                    => [ '<b ' . $r . '><p>x</p></b>', 'autoclose' ],
            'small around a p'                => [ '<small><p>x</p></small>', 'autoclose' ],
            'font around a center'            => [ '<font color="red"><center>x</center></font>', 'autoclose' ],
            'h2 around a p'                   => [ '<h2 ' . $r . '><p>x</p></h2>', 'autoclose' ],
            'a around a table'                => [ '<a href="#"><table><tr><td>x</td></tr></table></a>', 'autoclose' ],
            'ol directly in ul'               => [ '<ul><li>a</li><ol><li>b</li></ol></ul>', 'autoclose' ],
            'ul in an address'                => [ '<address><ul><li>a</li></ul></address>', 'autoclose' ],
            'ul in a pre'                     => [ '<pre><ul><li>a</li></ul></pre>', 'autoclose' ],
            'dl in a dt'                      => [ '<dl><dt><dl><dt>a</dt></dl></dt></dl>', 'autoclose' ],
            'span around a stray td'          => [ '<span><td>x</td></span>', 'autoclose' ],
            // N4 noscript, N5 integration points, N6 dialog, attributes, BOM, head
            'noscript end in an attribute'    => [ '<noscript><p title="</noscript>">x</p></noscript><p>y</p>', 'noscript' ],
            'noscript end in a comment'       => [ '<noscript><!-- </noscript> --></noscript><p>y</p>', 'noscript' ],
            'style in an svg title'           => [ '<svg><title><style>p{}</style></title></svg><p>x</p>', 'foreign' ],
            'script in foreignObject'         => [ '<svg><foreignObject><script>a</script></foreignObject></svg>', 'foreign' ],
            'dialog'                          => [ '<p>a<dialog>b</dialog>c</p>', 'dialog' ],
            'too many attributes'             => [ '<p ' . implode( ' ', array_map( static fn ( int $i ): string => 'a' . $i, range( 1, 201 ) ) ) . '>x</p>', 'attrs' ],
            'a doctype after content'         => [ '<p>x</p><!DOCTYPE html>', 'doctype' ],
        ];
    }

    #[DataProvider('repairedMarkup')]
    public function testMarkupABrowserRepairsDifferentlyIsReported( string $body, string $key ): void
    {
        $s = HtmlSource::parse( '<!DOCTYPE html><html><body>' . $body . '</body></html>' );
        $this->assertSame( [ HtmlSource::REPAIRS[ $key ] ], $s['repaired'], 'one fixed sentence, never the input' );
    }

    /** @return array<string,array{0:string}> */
    public static function wellFormedMarkup(): array
    {
        return [
            'implied ends'       => [ '<ul><li>a<li>b</ul><dl><dt>a<dd>b<dt>c</dl><p>a<p>b<table><tr><td>a<td>b<tr><th>c</table><select><option>a<option>b</select>' ],
            'nested lists'       => [ '<ul><li>a<ul><li>b</li></ul></li></ul>' ],
            'formatting in order'=> [ '<p><b>a <i>b</i></b> <a href="#">c <strong>d</strong></a></p><div><span>x</span></div>' ],
            'voids and raw text' => [ '<p>a<br>b<br/>c<img src="x.png" alt=""><hr/><input></p><script>if (a < b) { x = "<svg>" }</script><textarea>a < b</textarea><style>p{}</style>' ],
            'comments'           => [ '<!-- <div> <b> --><p>x</p><!-- - -->' ],
            'svg content'        => [ '<a href="#"><svg viewBox="0 0 10 10"><g><path d="M0 0"/></g><text>t</text><style>.a{}</style></svg> Go</a><svg/><math><mi>x</mi></math>' ],
            'p around a block'   => [ '<p>a<div>b</div></p>' ],
            'entities'           => [ '<p class="a" style="font-family:&quot;Open Sans&quot;" title="&copy; &colon;">a &amp; b &mdash; c</p>' ],
            'quoted brackets'    => [ '<p title="a > b < c" data-x=\'<b>\'>x</p>' ],
            'noscript'           => [ '<noscript><img src="a.png"></noscript>' ],
            'gtm noscript'       => [ '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-X" height="0" width="0" style="display:none"></iframe></noscript>' ],
            'svg style'          => [ '<svg viewBox="0 0 10 10"><defs><style>.cls-1{fill:#000}</style></defs><path class="cls-1" d="M0 0"/></svg>' ],
            'b span p'           => [ '<b><span><p>x</p></span></b>' ],
            'p closed by div'    => [ '<div><p>a<div>b</div></p></div>' ],
            'cells in a table'   => [ '<table><tr><td><p>a<td><b>b<td>c</table>' ],
            'nested list in li'  => [ '<ul><li>a<ol><li>b</li></ol></li></ul>' ],
            'empty'              => [ '' ],
        ];
    }

    #[DataProvider('wellFormedMarkup')]
    public function testWellFormedMarkupIsNotReported( string $body ): void
    {
        $s = HtmlSource::parse( '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>T</title><style>p{}</style></head><body class="home">' . $body . '<p>end</p></body></html>' );
        $this->assertNull( $s['error'] );
        $this->assertSame( [], $s['repaired'] );
    }

    public function testNoFixtureIsReportedAsRepaired(): void
    {
        foreach ( glob( self::FIXTURES . '/*.html' ) ?: [] as $file ) {
            $s = HtmlSource::parse( (string) file_get_contents( $file ) );
            if ( null === $s['error'] ) {
                // The 16-after-html fixtures carry content after the end of the document: the scan keeps its note (styles stay failed closed).
                if ( str_starts_with( basename( $file ), '16-after-html' ) ) {
                    $this->assertContains( $s['repaired'], [ [], [ HtmlSource::REPAIRS['after'] ] ], basename( $file ) );
                } elseif ( str_starts_with( basename( $file ), '20-script-end-tag' ) ) {
                    $this->assertSame( [ HtmlSource::REPAIRS['rawtext'] ], $s['repaired'], 'an end tag in script or style text: the scan keeps its note' );
                } elseif ( '18-after-body-html-text-section.html' === basename( $file ) ) {
                    $this->assertSame( [ HtmlSource::REPAIRS['after'] ], $s['repaired'], 'content after </html>: the note stays' );
                } else {
                    $this->assertSame( [], $s['repaired'], basename( $file ) );
                }
            }
        }
    }

    /** The analysis was built against libxml2 2.9.x; a newer parser (2.14 rewrote the tokenizer) is reported, injected through the seam. */
    public function testANewerParserIsReported(): void
    {
        $this->assertSame( 21399, HtmlSource::LIBXML_MAX_TESTED );
        $this->assertSame( [ HtmlSource::REPAIRS['parser'] ], HtmlSource::repairs( '<p>x</p>', 21400 ) );
        $this->assertSame( [], HtmlSource::repairs( '<p>x</p>', 20913 ) );
        $this->assertLessThanOrEqual( HtmlSource::LIBXML_MAX_TESTED, LIBXML_VERSION, 'this machine runs the tested parser' );
    }

    /** @return array<string,array{0:string}> the shapes that were quadratic, and other worst cases, at the 400 KB input cap */
    public static function worstShapes(): array
    {
        $cap = 399000;
        return [
            'svg in an attribute'  => [ substr( '<svg></svg><p>x</p><svg><p title="' . str_repeat( '<svg', intdiv( $cap, 4 ) ) . '">y</p>', 0, $cap ) ],
            'math in an attribute' => [ substr( '<math><p title="' . str_repeat( '<math', intdiv( $cap, 5 ) ) . '">y</p>', 0, $cap ) ],
            'svg after html'       => [ substr( '<p>x</p></body></html>' . str_repeat( '<svg>', intdiv( $cap, 5 ) ), 0, $cap ) ],
            'many closed math'     => [ str_repeat( '<math></math>', 4900 ) . '<p>' . str_repeat( 'x', $cap - 4900 * 13 - 20 ) . '</p>' ],
            'many comments'        => [ substr( str_repeat( '<!-- a -->', intdiv( $cap, 10 ) ), 0, $cap ) . '<p>x</p>' ],
            'many scripts'         => [ substr( str_repeat( '<script>a</script>', intdiv( $cap, 18 ) ), 0, $cap - 10 ) . '<p>x</p>' ],
            'many attributes'      => [ '<p ' . str_repeat( 'a' . 'b ', intdiv( $cap, 3 ) - 5 ) . '>x</p>' ],
            'lt soup'              => [ '<p>x</p>' . str_repeat( '<', $cap - 10 ) ],
            'deep svg'             => [ '<svg>' . str_repeat( '<g>', 600 ) . str_repeat( '</g>', 600 ) . '</svg>' . str_repeat( '<p>x</p>', 40000 ) ],
            // I8: a block still open at the page's own </p> costs paragraphEnds() a parse round each (within its work budget).
            'blocks open at </p>'  => [ str_repeat( '<section><p>Call us<search><h2>H</h2> after</p><div>d</div></search></section>', 990 ) ],
            // Review round 1: deep chains times many rounds (each was 1-7 s before the work bound counted the chain walks).
            'deep open navs'       => [ str_repeat( '<blockquote>', 120 ) . str_repeat( '<p><p></b><b><nav>', 900 ) ],
            'open navs at 40'      => [ str_repeat( '<blockquote>', 40 ) . str_repeat( '<p><p></b><b><nav>', 600 ) ],
            'stray ends and navs'  => [ str_repeat( '<p><p></b></div><b><nav></span>', 1200 ) ],
            'open navs at cap'     => [ substr( str_repeat( '<blockquote>', 45 ) . str_repeat( '<p><p></b><b><nav>', 21000 ), 0, $cap ) ],
            'open p before blocks' => [ str_repeat( '<p>Some words<section><h2>Head</h2>x</section>', 1650 ) ],
        ];
    }

    #[DataProvider('worstShapes')]
    public function testTheRepairScanIsLinear( string $html ): void
    {
        $start = hrtime( true );
        $r     = HtmlSource::repairs( $html, 20913 );
        $this->assertLessThan( 300, ( hrtime( true ) - $start ) / 1e6, 'scan' );
        foreach ( $r as $sentence ) {
            $this->assertContains( $sentence, HtmlSource::REPAIRS );
        }
        $start = hrtime( true );
        $s     = HtmlSource::parse( $html );
        if ( null === $s['error'] ) {
            \AiEditorDivi5\WP\HtmlStyles::resolve( $s['body'], $s['css'], $s['inline'], $s['presentational'], \AiEditorDivi5\WP\HtmlStyles::document( $s ) );
        }
        $this->assertLessThan( 1500, ( hrtime( true ) - $start ) / 1e6, 'parse + resolve' );
    }

    public function testTheRepairScanNeverThrows(): void
    {
        $frag  = [ '<', '</', '<a', '"', "'", '=', '>', '/', '<!--', '-->', '--!>', '<math>', '</math>', '<svg>', '</svg>', '<svg/>', '<style>', '</style>', '<b>', '</b>',
            '<p>', '</div>', '<li>', '<body x>', '<html y>', ' ', 'x', '&', '&colon;', '<!', '<?', '?>', '<textarea>', '<script>', '</script>', '<template shadowrootmode=open>', '<div popover>', '<image>', '<![CDATA[' ];
        $state = 7;
        for ( $k = 0; $k < 3000; $k++ ) {
            $s = '';
            for ( $j = 0, $len = 1 + $state % 40; $j < $len; $j++ ) {
                $state = ( $state * 1103515245 + 12345 ) & 0x7fffffff;
                $s    .= $frag[ $state % count( $frag ) ];
            }
            $r = HtmlSource::repairs( '<p>a</p>' . $s, 20913 );
            $this->assertLessThanOrEqual( 1, count( $r ) );
        }
        $this->assertSame( [], HtmlSource::parse( '' )['repaired'] );
        $this->assertSame( [ HtmlSource::REPAIRS['deep'] ], HtmlSource::repairs( str_repeat( 'a', HtmlSource::MAX_BYTES + 1 ), 20913 ) );
    }

    /** N3: control characters are removed for libxml2 but kept by a browser: reported; the doctype is read on the HTML as given. */
    public function testControlCharactersAreReported(): void
    {
        $s = HtmlSource::parse( "<!DOCTYPE html><html><body><p class=\"a\x01b\">x</p></body></html>" );
        $this->assertSame( [ HtmlSource::REPAIRS['control'] ], $s['repaired'] );
        $v = HtmlSource::parse( "\x0b<!DOCTYPE html><html><body><p>x</p></body></html>" );
        $this->assertFalse( $v['doctype'], 'a vertical tab first: quirks mode in a browser' );
        $this->assertSame( [ HtmlSource::REPAIRS['control'] ], $v['repaired'] );
    }

    /** A byte order mark is skipped by the scan and the doctype check (a BOM-saved file keeps its styles); the parse is unchanged. */
    public function testAByteOrderMarkIsNotContent(): void
    {
        $s = HtmlSource::parse( "\xEF\xBB\xBF<!DOCTYPE html>\n<html lang=\"en\"><body class=\"a\" style=\"color:red\"><p>x</p></body></html>" );
        $this->assertNull( $s['error'] );
        $this->assertTrue( $s['doctype'] );
        $this->assertSame( [], $s['repaired'] );
    }

    /** libxml2's parse is quadratic in the attributes of one tag: beyond MAX_ATTRIBUTES the input is refused before it runs. */
    public function testATagWithTooManyAttributesIsRefusedQuickly(): void
    {
        $attrs = implode( ' ', array_map( static fn ( int $i ): string => 'a' . $i, range( 1, 40000 ) ) );
        $start = hrtime( true );
        $s     = HtmlSource::parse( '<p ' . $attrs . '>x</p>' );
        $this->assertLessThan( 300, ( hrtime( true ) - $start ) / 1e6 );
        $this->assertStringContainsString( 'more than ' . HtmlSource::MAX_ATTRIBUTES . ' attributes', (string) $s['error'] );
        $ok = HtmlSource::parse( '<p ' . implode( ' ', array_map( static fn ( int $i ): string => 'a' . $i, range( 1, 999 ) ) ) . ' title="x > y">x</p>' );
        $this->assertNull( $ok['error'] );
        $this->assertSame( [ HtmlSource::REPAIRS['attrs'] ], $ok['repaired'] );
    }

    /**
     * N1: the pinned auto-close table is exactly what libxml2 does on THIS machine: every pinned pair is not nested by libxml2 (and gives
     * the recorded tree), every other pair of the matrix's tags is nested; the recorded Chromium outcomes give the pinned classification.
     */
    public function testTheAutoCloseTableMatchesThisLibxmlAndTheRecordedBrowser(): void
    {
        $m     = require __DIR__ . '/Support/html-autoclose-matrix.php';
        $table = ( new \ReflectionClassConstant( HtmlSource::class, 'AUTOCLOSE' ) )->getValue();
        $void  = [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr', 'param', 'keygen', 'frame', 'basefont', 'bgsound', 'image' ];
        $parts = [ 'caption', 'col', 'colgroup', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr' ];
        $pinned = 0;
        foreach ( $m['tags'] as $x ) {
            if ( in_array( $x, [ 'html', 'body', 'head' ], true ) || in_array( $x, $void, true ) || in_array( $x, $parts, true ) ) {
                continue;
            }
            foreach ( $m['tags'] as $y ) {
                if ( in_array( $y, [ 'html', 'body', 'head' ], true ) ) {
                    continue;
                }
                $d = new \DOMDocument();
                $prev = libxml_use_internal_errors( true );
                $d->loadHTML( '<?xml encoding="UTF-8"><!DOCTYPE html><body><div id="r"><' . $x . ' id="a"><' . $y . ' id="b">t</' . $y . '>u</' . $x . '></div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD );
                libxml_clear_errors();
                libxml_use_internal_errors( $prev );
                $b = $d->getElementById( 'b' );
                $a = $d->getElementById( 'a' );
                $got = ( $b ? $b->parentNode->nodeName . '#' . ( $b->parentNode instanceof \DOMElement ? $b->parentNode->getAttribute( 'id' ) : '' ) : 'MISSING' ) . '|' . ( $a ? $a->parentNode->nodeName : 'MISSING' );
                $nests = str_starts_with( $got, $x . '#a|' );
                $rec   = $m['pairs'][ $x . '>' . $y ] ?? null;
                if ( $nests ) {
                    $this->assertNull( $rec, $x . '>' . $y . ' nests in this libxml2 but is pinned' );
                    $this->assertArrayNotHasKey( $y, $table[ $x ] ?? [], $x . '>' . $y );
                    continue;
                }
                ++$pinned;
                $this->assertNotNull( $rec, $x . '>' . $y . ' is not nested by this libxml2 but not pinned' );
                $this->assertSame( $rec[0], $got, $x . '>' . $y . ': libxml2 changed' );
                $want = $rec[1] !== $rec[0] ? 'flag' : ( $rec[2] !== $rec[0] ? 'quirks' : 'agree' );
                $this->assertSame( $want, $table[ $x ][ $y ] ?? null, $x . '>' . $y );
            }
        }
        $this->assertSame( 129, $pinned );
        $this->assertSame( 129, array_sum( array_map( 'count', $table ) ) );
    }
    /** @return array<string,array{0:string,1:list<string>}> */
    public static function html5AfterHeadContent(): array
    {
        return [
            'style then section'  => [ '<style>a{}</style><section><h1>A</h1></section><div><p>B</p></div>', [ 'A', 'B' ] ],
            'link then section'   => [ '<link rel="stylesheet" href="x.css"><section><h1>A</h1></section>', [ 'A' ] ],
            'meta then header'    => [ '<meta charset="utf-8"><header>Top</header><main><p>Body</p></main>', [ 'Top', 'Body' ] ],
            'title then nav'      => [ '<title>t</title><nav>N</nav><p>x</p>', [ 'N', 'x' ] ],
            'explicit head'       => [ '<html><head><style>a{}</style><section>S</section></head><body><p>x</p></body></html>', [ 'S', 'x' ] ],
        ];
    }

    /** libxml2 keeps an HTML5 element that follows head content inside <head>; a browser starts the body there. */
    #[DataProvider('html5AfterHeadContent')]
    public function testHtml5ElementsAfterHeadContentMoveIntoTheBody( string $html, array $texts ): void
    {
        $s = HtmlSource::parse( $html );
        $this->assertNull( $s['error'], (string) $s['error'] );
        $body = $this->bodyHtml( $s );
        $last = -1;
        foreach ( $texts as $t ) {
            $pos = strpos( $body, $t );
            $this->assertNotFalse( $pos, $t . ' is in the body' );
            $this->assertGreaterThan( $last, $pos, 'document order is kept' );
            $last = $pos;
        }
        $head = $s['body']->ownerDocument->getElementsByTagName( 'head' )->item( 0 );
        foreach ( $head ? iterator_to_array( $head->childNodes ) : [] as $child ) {
            if ( $child instanceof \DOMElement ) {
                $this->assertContains( strtolower( $child->tagName ), [ 'title', 'meta', 'link', 'style', 'script', 'base', 'noscript', 'template' ], 'only head elements stay in head' );
            }
        }
    }

    public function testRelocatedElementsComeBeforeTheBodysOwnContent(): void
    {
        $s    = HtmlSource::parse( '<html><head><style>a{}</style><section>FIRST</section></head><body><p>SECOND</p></body></html>' );
        $body = $this->bodyHtml( $s );
        $this->assertLessThan( strpos( $body, 'SECOND' ), strpos( $body, 'FIRST' ) );
    }

    public function testANormalHeadIsLeftAlone(): void
    {
        $s = HtmlSource::parse( '<!DOCTYPE html><html><head><meta charset="utf-8"><title>T</title><link rel="stylesheet" href="x.css"><style>a{}</style><script>1</script></head><body><section><h1>A</h1></section></body></html>' );
        $this->assertNull( $s['error'] );
        $head = $s['body']->ownerDocument->getElementsByTagName( 'head' )->item( 0 );
        $this->assertSame( [ 'meta', 'title', 'link', 'style', 'script' ], array_map( static fn( $n ) => $n->nodeName, array_values( array_filter( iterator_to_array( $head->childNodes ), static fn( $n ) => $n instanceof \DOMElement ) ) ) );
        $this->assertSame( 'T', $s['title'] );
        $this->assertSame( [], $s['repaired'], 'a relocation is what a browser does: no repair note' );
    }

    /** @return array<string,array{0:string,1:list<string>}> */
    public static function afterHtmlShapes(): array
    {
        return [
            'paragraph after html'      => [ '<html><body><p>AAA</p></body></html><p>BBB</p>', [ 'AAA', 'BBB' ] ],
            'paragraph after body'      => [ '<html><body><p>AAA</p></body><p>BBB</p></html>', [ 'AAA', 'BBB' ] ],
            'text after html'           => [ '<html><body><p>AAA</p></body></html>BBB tail', [ 'AAA', 'BBB tail' ] ],
            'sections and text'         => [ '<html><body><p>AAA</p></body></html><section><h1>BBB</h1></section>CCC', [ 'AAA', 'BBB', 'CCC' ] ],
            'second full document'      => [ '<html><head><title>x</title></head><body><p>AAA</p></body></html><html><head><title>y</title></head><body><p>BBB</p></body></html><p>CCC</p>', [ 'AAA', 'BBB', 'CCC' ] ],
            'two things after'          => [ '<html><body><p>AAA</p></body></html><p>BBB</p><p>CCC</p><div>DDD</div>', [ 'AAA', 'BBB', 'CCC', 'DDD' ] ],
            'html without a body first' => [ '<html></html><p>AAA</p>', [ 'AAA' ] ],
        ];
    }

    /** libxml2 makes extra top-level nodes (or a sibling of <body>) that nothing read; a browser appends them to the body. */
    #[DataProvider('afterHtmlShapes')]
    public function testContentAfterTheHtmlOrBodyEndIsAppendedToTheBody( string $html, array $texts ): void
    {
        $s = HtmlSource::parse( $html );
        $this->assertNull( $s['error'], (string) $s['error'] );
        $body = $this->bodyHtml( $s );
        $last = -1;
        foreach ( $texts as $t ) {
            $pos = strpos( $body, $t );
            $this->assertNotFalse( $pos, $t . ' is in the body' );
            $this->assertGreaterThan( $last, $pos, 'document order is kept' );
            $last = $pos;
        }
        // The scan's existing note for content after </html> is kept on purpose (styles stay failed closed for such a page, which the
        // differential corpus pins); the content itself is no longer lost. The relocation adds no note of its own.
        $this->assertContains( $s['repaired'], [ [], [ HtmlSource::REPAIRS['after'] ] ] );
        $this->assertSame( 1, $s['body']->ownerDocument->getElementsByTagName( 'body' )->length, 'one body, nothing left in a stray node' );
    }

    public function testASecondDocumentsTitleIsNotUsed(): void
    {
        $s = HtmlSource::parse( '<html><head><title>First</title></head><body><p>A</p></body></html><html><head><title>Second</title></head><body><p>B</p></body></html>' );
        $this->assertSame( 'First', $s['title'] );
    }

    public function testCommentsAfterTheHtmlEndAddNothing(): void
    {
        $s = HtmlSource::parse( '<html><body><p>AAA</p></body></html><!-- note -->' );
        $this->assertNull( $s['error'] );
        $this->assertStringNotContainsString( 'note', $this->bodyHtml( $s ) );
        $this->assertSame( 'AAA', trim( $s['body']->textContent ) );
    }

    public function testWhitespaceAfterTheHtmlEndChangesNothing(): void
    {
        $plain = HtmlSource::parse( '<html><body><p>AAA</p></body></html>' );
        $ws    = HtmlSource::parse( "<html><body><p>AAA</p></body></html>\n\n  \n" );
        $this->assertSame( $this->bodyHtml( $plain ), $this->bodyHtml( $ws ) );
    }

    public function testAStyleInASecondDocumentStillReachesTheStyleReader(): void
    {
        $s = HtmlSource::parse( '<html><body><p class="x">AAA</p></body></html><html><head><style>.x{color:red}</style></head><body><p>BBB</p></body></html>' );
        $this->assertNull( $s['error'] );
        $this->assertStringContainsString( 'BBB', $this->bodyHtml( $s ) );
    }

    /** Head content a fragment may start with (and the starts that put libxml2 in the same state: a comment, <html>, an empty head). */
    private const LEADING = [
        'style'          => '<style>p{color:#111111}</style>',
        'link'           => '<link rel="stylesheet" href="x.css">',
        'meta'           => '<meta charset="utf-8">',
        'script'         => '<script>var a = 1;</script>',
        'title'          => '<title>T</title>',
        'base'           => '<base href="/">',
        'noscript'       => '<noscript>n</noscript>',
        'template'       => '<template><p>t</p></template>',
        'comment'        => '<!-- c -->',
        'html'           => '<html>',
        'empty head'     => '<head></head>',
        'head with style' => '<html><head><style>p{}</style></head>',
        'several'        => "<meta charset=\"utf-8\">\n<title>T</title>\n<style>p{}</style>\n",
    ];

    /** Bodies with loose text, inline elements, references and comments between HTML5 blocks. */
    private const LOOSE_BODIES = [
        'text'     => '<section><h2>A</h2></section>Loose<section><h2>B</h2></section>',
        'inline'   => '<section><h2>A</h2></section><b>x</b> tail<section><h2>B</h2></section>',
        'comment'  => '<section><h2>A</h2></section><!--c-->Loose<section><h2>B</h2></section>',
        'first'    => 'Loose first<section><h2>B</h2></section>',
        'landmarks' => '<header>H</header>Loose<main><p>M</p></main>more<footer>F</footer>',
        'entity'   => '<article><h2>A</h2></article> &amp; <aside>B</aside>',
        'span'     => '<section>A</section><span>i</span><section>B</section>',
        'divs'     => '<div><h2>A</h2></div>Loose<div><h2>B</h2></div>',
    ];

    /** @return array<string,array{0:string,1:string}> */
    public static function leadingHeadContent(): array
    {
        $out = [];
        foreach ( self::LEADING as $lead => $prefix ) {
            foreach ( self::LOOSE_BODIES as $shape => $body ) {
                $out[ $lead . ' + ' . $shape ] = [ $prefix, $body ];
            }
        }

        return $out;
    }

    /**
     * libxml2 stays in head mode after head content (and before any body tag), so the first loose text opened an implied <p> that swallowed
     * the blocks after it (a section inside a p). A browser starts the body there and keeps the text a bare run. The page with its leading
     * head content must give the same body as the page without it, with no <p> holding a block and no repair note of its own.
     */
    #[DataProvider('leadingHeadContent')]
    public function testLeadingHeadContentKeepsTheBodysStructure( string $prefix, string $body ): void
    {
        $bare = HtmlSource::parse( $body );
        $s    = HtmlSource::parse( $prefix . $body );
        $this->assertNull( $s['error'], (string) $s['error'] );
        $this->assertSame( $this->bodyHtml( $bare ), $this->bodyHtml( $s ) );
        $this->assertSame( $bare['repaired'], $s['repaired'], 'no repair note: the result is the browser\'s tree' );
        foreach ( iterator_to_array( $s['body']->getElementsByTagName( 'p' ) ) as $p ) {
            foreach ( [ 'section', 'header', 'main', 'footer', 'article', 'aside', 'div', 'h2' ] as $block ) {
                $this->assertSame( 0, $p->getElementsByTagName( $block )->length, 'no ' . $block . ' inside a p' );
            }
        }
    }

    /** The browser's tree (Chromium DOMParser, written out by hand): loose text is a bare run in the body, no block sits in a p. */
    public function testLooseTextAfterHeadContentIsTheBrowsersTree(): void
    {
        $cases = [
            '<style>p{}</style><section><h2>A</h2></section>Loose<section><h2>B</h2></section>' => '<body><section><h2>A</h2></section>Loose<section><h2>B</h2></section></body>',
            'Loose first<section><h2>B</h2></section>'                                       => '<body>Loose first<section><h2>B</h2></section></body>',
            '<!-- c -->Loose<header>H</header>'                                               => '<body>Loose<header>H</header></body>',
            '<html><head><title>T</title></head>Loose<main>M</main></html>'                    => '<body>Loose<main>M</main></body>',
            '<meta charset="utf-8"><section>A</section><b>x</b> tail<section>B</section>'     => '<body><section>A</section><b>x</b> tail<section>B</section></body>',
        ];
        foreach ( $cases as $html => $want ) {
            $s = HtmlSource::parse( $html );
            $this->assertSame( $want, $this->bodyHtml( $s ), $html );
            $this->assertSame( [], $s['repaired'], $html );
        }
    }

    /** The body offset helper: where a browser starts the body when libxml2 would still be in head, else null. */
    public function testWhereTheBodyStartsIsFoundOnlyBeforeAnExplicitBody(): void
    {
        $this->assertSame( 15, HtmlSource::bodyStart( '<style></style>Loose' ) );
        $this->assertSame( 18, HtmlSource::bodyStart( "<style></style>\n  Loose" ), 'white space stays in head' );
        $this->assertSame( 24, HtmlSource::bodyStart( '<title>a</title><!--x--><section>' ), 'a comment stays in head, a section starts the body' );
        $this->assertSame( 13, HtmlSource::bodyStart( '<head></head><p>x</p>' ) );
        $this->assertSame( 0, HtmlSource::bodyStart( 'Loose' ) );
        $this->assertNull( HtmlSource::bodyStart( '<style></style><body>Loose' ), 'an explicit body: libxml2 opens it itself' );
        $this->assertNull( HtmlSource::bodyStart( "<style></style>\n" ), 'no content at all' );
        $this->assertNull( HtmlSource::bodyStart( '<style>a</b></style>Loose' ), 'raw text libxml2 may end early: not modelled, left alone' );
        $this->assertNull( HtmlSource::bodyStart( '<!-->Loose' ), 'a comment the parsers end differently: left alone' );
        $this->assertNull( HtmlSource::bodyStart( '<frameset></frameset>' ) );
    }

    /**
     * Content after </body> or </html>: libxml2 closed everything at the end tag and opened an implied <p> for loose text after it, which
     * swallowed the next HTML5 section. A browser processes what follows "in body" with the open elements unchanged. The trees below are
     * Chromium's (DOMParser, 2026-10-22), written out by hand.
     */
    public function testContentAfterABodyOrHtmlEndIsTheBrowsersTree(): void
    {
        $cases = [
            '<body><section>A</section></body>Loose<section>B</section>'                                   => '<body><section>A</section>Loose<section>B</section></body>',
            '<html><body><section>A</section></body></html>Loose<section>B</section>'                      => '<body><section>A</section>Loose<section>B</section></body>',
            '<body><section>A</section></html>Loose<section>B</section>'                                   => '<body><section>A</section>Loose<section>B</section></body>',
            '<body><p>A</p></body>Loose <b>bold</b> tail'                                                   => '<body><p>A</p>Loose <b>bold</b> tail</body>',
            '<body><section>A</section></body><body>Loose<section>B</section></body>'                      => '<body><section>A</section>Loose<section>B</section></body>',
            '<body><div>A</body>B'                                                                         => '<body><div>AB</div></body>',
            '<body><table><tr><td>A</body>B</td></tr></table>'                                             => '<body><table><tr><td>AB</td></tr></table></body>',
            '<body><p>A</body><span>y</span>'                                                              => '<body><p>A<span>y</span></p></body>',
            '<body><p title="</body>">A</p><!-- </body> --></body>Loose<section>B</section>'                => '<body><p>A</p>Loose<section>B</section></body>', // Chromium keeps the title and the comment; the cleaner drops both
        ];
        foreach ( $cases as $html => $want ) {
            $s = HtmlSource::parse( $html );
            $this->assertNull( $s['error'], $html );
            $this->assertSame( $want, $this->bodyHtml( $s ), $html );
            $this->assertSame( 1, $s['body']->ownerDocument->getElementsByTagName( 'body' )->length, $html );
        }
    }

    /** The scan's note for content after </html> stays (styles stay failed closed there); content after </body> alone never had one. */
    public function testTheAfterNoteIsUnchanged(): void
    {
        $this->assertSame( [ HtmlSource::REPAIRS['after'] ], HtmlSource::parse( '<body><section>A</section></html>Loose<section>B</section>' )['repaired'] );
        $this->assertSame( [], HtmlSource::parse( '<body><section>A</section></body>Loose<section>B</section>' )['repaired'] );
        $this->assertSame( [ HtmlSource::REPAIRS['twice'] ], HtmlSource::parse( '<body><p>A</p></body><body class="x"><p>B</p></body>' )['repaired'] );
    }

    /** The end tags removed before parsing: only real ones in markup context, and only when content follows them. */
    public function testOnlyRealEndTagsThatContentFollowsAreStray(): void
    {
        $this->assertSame( [ [ 26, 7 ] ], HtmlSource::strayEndTags( '<body><section>A</section></body>Loose' ) );
        $this->assertSame( [ [ 8, 7 ], [ 15, 7 ] ], HtmlSource::strayEndTags( '<p>A</p></body></html><p>B</p>' ) );
        $this->assertSame( [ [ 8, 7 ] ], HtmlSource::strayEndTags( '<p>A</p></body><b>x</b></html>  ' ), 'the trailing </html> stays' );
        $this->assertSame( [ [ 8, 15 ] ], HtmlSource::strayEndTags( '<p>A</p></BODY class=x>B' ), 'any case, attributes on an end tag' );
        $this->assertSame( [ [ 38, 7 ] ], HtmlSource::strayEndTags( '<p title="</body>">A</p><!--</body>--></body>B' ), 'an attribute value and a comment are not markup' );
        $this->assertSame( [ [ 27, 7 ] ], HtmlSource::strayEndTags( '<style>p{color:red}</style></body>B' ), 'raw text skipped' );
        $this->assertNull( HtmlSource::strayEndTags( "<html><body><p>A</p></body></html>\n" ), 'nothing after: the HTML stays as given' );
        $this->assertNull( HtmlSource::strayEndTags( '<p>A</p></body><!-- c --></html> ' ), 'only a comment after' );
        $this->assertNull( HtmlSource::strayEndTags( '<p>A</p></body></html><html><head></head><body></body></html>' ), 'only empty document tags after' );
        $this->assertNull( HtmlSource::strayEndTags( '<p>A</p>' ) );
        // Fail closed: anything the two parsers might tokenize differently leaves the HTML as given.
        $this->assertNull( HtmlSource::strayEndTags( '<script>var s="</body>"</script></body>B' ), 'raw text with an end-tag-like run' );
        $this->assertNull( HtmlSource::strayEndTags( '<textarea></body></textarea></body>B' ) );
        $this->assertNull( HtmlSource::strayEndTags( '<title></html></title></body>B' ) );
        $this->assertNull( HtmlSource::strayEndTags( '<noscript></body></noscript></body>B' ) );
        $this->assertNull( HtmlSource::strayEndTags( '<script/></body>B' ), 'a self-closing raw-text tag' );
        $this->assertNull( HtmlSource::strayEndTags( '<svg></body></svg>B' ), 'an end tag inside svg' );
        $this->assertNull( HtmlSource::strayEndTags( '<svg><style>x</style></svg></body>B' ), 'raw text inside svg' );
        $this->assertNull( HtmlSource::strayEndTags( '<!-->x</body>B' ), 'an odd comment' );
        $this->assertNull( HtmlSource::strayEndTags( '<![CDATA[</body>]]>B' ) );
        $this->assertNull( HtmlSource::strayEndTags( '<?x </body> ?>B' ) );
        $this->assertNull( HtmlSource::strayEndTags( '<xmp></body></xmp>B' ) );
        $this->assertNull( HtmlSource::strayEndTags( '<p>A</p></body/>B' ), 'a self-closing end tag' );
        $this->assertNull( HtmlSource::strayEndTags( '<p a=1 a=2>A</p></body>B' ), 'a tag the scan does not model' );
        $this->assertSame( [ [ 14, 7 ] ], HtmlSource::strayEndTags( '<svg></svg><p></body>B' ), 'svg closed before' );
    }

    /** Markup that only looks like </body> (a comment, a script, an attribute) is never touched: the page parses as before. */
    public function testEndTagLookalikesAreLeftAlone(): void
    {
        foreach ( [
            '<body><p>A</p><!-- </body> --><p>B</p></body>',
            '<body><p data-x="</body>">A</p><p>B</p></body>',
            '<body><p>A</p><script>if(a</body>)x()</script><p>B</p></body>',
            "<body><p>A</p><textarea>\n</html>\n</textarea><p>B</p></body>",
        ] as $html ) {
            $this->assertNull( HtmlSource::strayEndTags( $html ), $html );
            $s = HtmlSource::parse( $html );
            $this->assertNull( $s['error'], $html );
            $this->assertStringContainsString( 'B</p></body>', $this->bodyHtml( $s ), $html );
        }
    }

    /** @return array<string,array{0:string,1:string,2:string}> name => [HTML, Chromium's body, the block] */
    public static function paragraphCloseCases(): array
    {
        $out = [];
        foreach ( require __DIR__ . '/Support/html-paragraph-close-cases.php' as $name => [ $html, $chromium ] ) {
            $out[ $name ] = [ $html, $chromium, explode( '/', $name )[0] ];
        }

        return $out;
    }

    /**
     * An open <p> before a block (I8): a browser closes the p ("close a p element" when a p is in button scope) at section, article, nav,
     * header ... and at every block when a phrasing element is still open in the p; libxml2 2.9 kept the block inside the p, so a heading
     * became bold text in a paragraph. The body must now be Chromium's (pinned in Support/html-paragraph-close-cases.php), except for
     * two differences that are written down: libxml2 adds no tbody, and a </p> that matches nothing stays ignored (a browser makes an
     * empty p of it; the scan treats that alike for every block, see the div precedent). With an em still open a browser opens it
     * again inside the block: the tree is the browser's once the em tags are left out, and the page carries the `reopen` note.
     */
    #[DataProvider('paragraphCloseCases')]
    public function testAnOpenParagraphBeforeABlockIsTheBrowsersTree( string $html, string $chromium, string $block ): void
    {
        $s = HtmlSource::parse( $html );
        $this->assertNull( $s['error'], $html );
        $got  = (string) preg_replace( '#^<body>|</body>$#', '', $this->bodyHtml( $s ) );
        $want = str_replace( [ '<tbody>', '</tbody>', '<p></p>' ], '', $chromium );
        if ( str_contains( $html, '<em>' ) ) {
            $this->assertSame( str_replace( [ '<em>', '</em>' ], '', $want ), str_replace( [ '<em>', '</em>' ], '', $got ), $html );
            $this->assertNotSame( [], $s['repaired'], 'a browser opens the em again inside the block: styles stay off' );
        } else {
            $this->assertSame( $want, $got, $html );
            // No note of its own: only what the scan already said (a <p> in a <p> closed later is its `misnest`), none for an HTML5 block.
            $this->assertSame( HtmlSource::repairs( $html ), $s['repaired'], $html );
            if ( 'p' !== $block ) {
                $this->assertSame( [], $s['repaired'], $html );
            }
        }
    }

    /** The two repros of the finding, and the note for a formatting element that a browser opens again. */
    public function testTheParagraphRepros(): void
    {
        $this->assertSame( '<body><section><p>para start</p><section><h2>B</h2></section></section></body>', $this->bodyHtml( HtmlSource::parse( '<section><p>para start<section><h2>B</h2></section></section>' ) ) );
        $this->assertSame( '<body><p>Intro</p><section><h2>Head</h2><p>x</p></section></body>', $this->bodyHtml( HtmlSource::parse( '<p>Intro<section><h2>Head</h2><p>x</p></section>' ) ) );
        $this->assertSame( [ HtmlSource::REPAIRS['reopen'] ], HtmlSource::parse( '<p>a<b>x<section>s</section>y</b>z</p>' )['repaired'] );
        $this->assertSame( [], HtmlSource::parse( '<p>a<span>x<section>s</section>y</span>z</p>' )['repaired'] );
    }

    /** The </p> written before the block: only where a browser closes the p and libxml2 does not, as offsets into the HTML. */
    public function testParagraphEndsAreWrittenOnlyWhereABrowserClosesTheParagraph(): void
    {
        $this->assertSame( [ 'ends' => [ 4 ], 'reopen' => false ], HtmlSource::paragraphEnds( '<p>a<section>b</section>' ) );
        $this->assertSame( [ 'ends' => [ 11, 34 ], 'reopen' => false ], HtmlSource::paragraphEnds( '<p>a<span>b<div>c</div></span><p>d<nav>e</nav>' ) );
        $this->assertSame( [ 'ends' => [ 8 ], 'reopen' => true ], HtmlSource::paragraphEnds( '<p>a<b>x<aside>s</aside>' ) );
        $none = [ 'ends' => [], 'reopen' => false ];
        foreach ( [
            '<p>a<div>b</div>'                                   => 'libxml2 closes the p itself',
            '<p>a</p><section>b</section>'                       => 'nothing open',
            '<p>a<span>b</span><br><img src="x.png">c<em>d</em></p>' => 'no block at all',
            '<p>a<button><section>b</section></button>c'         => 'a button is a scope boundary: a browser keeps the section in it',
            '<p>a<svg><section>b</section></svg>c'               => 'a section in svg is foreign content',
            '<p>a<textarea><section></textarea>b'                => 'textarea text',
            '<p>a<title><section></title>b'                      => 'title text',
            '<p>a<!--<section>-->b'                              => 'a comment',
            '<p title="<section>">a'                             => 'an attribute value',
            '<p>a<script>"<section>"</script>b'                  => 'script text',
            '<p>a<object><section>b</section></object>'          => 'an object is a scope boundary',
            '<p>a<select><section>b</section></select>'          => 'not a phrasing element between: left alone',
            '<table><tr><td><section>b</section></td></tr></table>' => 'no p',
            '<p>a<!-->b<section>c</section>'                     => 'an odd comment: not modelled',
            '<p>a<![CDATA[x]]><section>c</section>'              => 'CDATA: not modelled',
            '<p>a<xmp></xmp><section>c</section>'                => 'xmp: not modelled',
        ] as $html => $why ) {
            $this->assertSame( $none, HtmlSource::paragraphEnds( $html ), $why );
        }
    }

    /** Nesting: a p in a section in a p, each p closed where its block starts; the text never moves out of document order. */
    public function testNestedParagraphsCloseInOrder(): void
    {
        $s = HtmlSource::parse( '<p>a<section><p>b<section>c</section></p></section></p>' );
        $this->assertSame( '<body><p>a</p><section><p>b</p><section>c</section></section></body>', $this->bodyHtml( $s ) );
        $s = HtmlSource::parse( '<div><p>a<figure><figcaption>c</figcaption><p>x<figcaption>d</figcaption></figure></div>' );
        $this->assertSame( '<body><div><p>a</p><figure><figcaption>c</figcaption><p>x</p><figcaption>d</figcaption></figure></div></body>', $this->bodyHtml( $s ) );
        $s = HtmlSource::parse( '<p>a<section>b</p>c</section>d' );
        $this->assertSame( '<body><p>a</p><section>bc</section>d</body>', $this->bodyHtml( $s ), 'the </p> inside the section matches nothing: ignored (a browser makes an empty p there)' );
        // A block still open at the page's own </p>: that </p> now matches nothing, so the block stays open (as in a browser) and the next
        // div lands in it; the first parse round could not foresee that, the second settles it (Chromium 149, the empty p left out).
        $html = '<section><p>Call us<search><h2>H</h2> after</p><div>d</div></search></section><section><p>Write<search><h2>K</h2> more</p><div>e</div></search></section>';
        $this->assertSame( [ 'ends' => [ 19, 95 ], 'reopen' => false ], HtmlSource::paragraphEnds( $html ) );
        $this->assertSame( '<body><section><p>Call us</p><search><h2>H</h2> after<div>d</div></search></section><section><p>Write</p><search><h2>K</h2> more<div>e</div></search></section></body>', $this->bodyHtml( HtmlSource::parse( $html ) ) );
    }

    /**
     * I9: a script whose text holds an end tag. libxml2 2.9 ends script and style text at the first </ and a letter, a browser only at
     * </script (or </style) followed by a space, / or >. The rest of the script became page text and the next heading bold text. The
     * </ inside script and style text is now written <\/ before parsing (the same value in a JavaScript or CSS string), so libxml2 ends
     * the element where a browser does. The script is still dropped and reported exactly as before.
     */
    public function testAnEndTagInsideScriptTextNoLongerLeaks(): void
    {
        $plain = HtmlSource::parse( '<body><section><h2>A</h2><script>var s=\'\';</script><p>x</p></section></body>Loose<section><h2>H</h2></section>' );
        foreach ( [ "'</body>'", "'</html>'", "'</BODY>'", "'</scriptx>'", "'</script'+'>'", '/* </body> */', "// </html>\n", "'</div></section>'" ] as $js ) {
            $html = '<body><section><h2>A</h2><script>var s=' . $js . ';</script><p>x</p></section></body>Loose<section><h2>H</h2></section>';
            $s    = HtmlSource::parse( $html );
            $this->assertSame( '<body><section><h2>A</h2><p>x</p></section>Loose<section><h2>H</h2></section></body>', $this->bodyHtml( $s ), $js );
            $this->assertSame( $plain['flagged'], $s['flagged'], 'the script report is unchanged: ' . $js );
            $this->assertSame( $plain['stats'], $s['stats'], $js );
        }
        $s = HtmlSource::parse( '<html><head><style>a::after{content:"</body>"}</style></head><body><section><h2>A</h2></section></body>Loose' );
        $this->assertSame( '<body><section><h2>A</h2></section>Loose</body>', $this->bodyHtml( $s ), 'a style with an end tag in a string' );
        $this->assertStringContainsString( 'content:"<\/body>"', $s['css'], 'the same CSS string value' );
        $this->assertSame( [ HtmlSource::REPAIRS['rawtext'] ], $s['repaired'], 'the scan still reports the raw text: styles stay off' );
    }

    /** Review round 1: the marker text in the page does not switch the fix off (another marker is picked, the same one every time). */
    public function testTheMarkerTextInThePageIsNoObstacle(): void
    {
        $this->assertSame( [ 'ends' => [ 15 ], 'reopen' => false ], HtmlSource::paragraphEnds( '<p>aied-p-end-0<section>c</section>' ) );
        $this->assertSame( '<body><p>aied-p-end-0 aied-p-end-x</p><section>c</section></body>', $this->bodyHtml( HtmlSource::parse( '<p>aied-p-end-0 aied-p-end-x<section>c</section>' ) ) );
    }

    /**
     * Review round 1: a script holding "<!--" made every pre-parse fix give up, and content after it was lost without a note. In a browser
     * "<!--" in script text changes where the script ends only when a "<script" follows it (the double-escaped state); otherwise the
     * script ends at the first </script. Only that case is left alone, and the scan's `rawtext` note says so.
     */
    public function testACommentOpenerInAScriptNoLongerSwitchesTheFixesOff(): void
    {
        $html = '<body><section><h2>A</h2><script>var s="</section>"; x="<!--y";</script><p>after words</p></section><section><h2>Bee</h2></section>';
        $this->assertSame( '<body><section><h2>A</h2><p>after words</p></section><section><h2>Bee</h2></section></body>', $this->bodyHtml( HtmlSource::parse( $html ) ) );
        $legacy = "<body><section><h2>A</h2><script><!--\nvar s='</div>';\n//--></script><p>after words</p></section></body>Loose<section><h2>Bee</h2></section>";
        $this->assertSame( '<body><section><h2>A</h2><p>after words</p></section>Loose<section><h2>Bee</h2></section></body>', $this->bodyHtml( HtmlSource::parse( $legacy ) ), 'the legacy comment wrapper' );
        $this->assertSame( '<script>a="<p>a<\/b><!-- x --><\/p>"</script>', HtmlSource::inertRawText( '<script>a="<p>a</b><!-- x --></p>"</script>' ) );
        $this->assertSame( [ [ 27, 7 ] ], HtmlSource::strayEndTags( '<script><!-- x --></script></body>B' ), 'a comment opener in script text: the end tag is found' );
        $this->assertSame( 29, HtmlSource::bodyStart( '<script><!-- x //--></script>Loose' ) );
        // "<!--" then "<script": a browser does not end the script at the first </script (double-escaped): left as given, with the note.
        $double = '<body><section><h2>A</h2><script><!-- document.write("<script>a()</script>"); --></script><p>after words</p></section>';
        $this->assertSame( $double, HtmlSource::inertRawText( $double ) );
        $this->assertNull( HtmlSource::strayEndTags( '<script><!-- <script> --></script></body>B' ) );
        $this->assertSame( [ HtmlSource::REPAIRS['rawtext'] ], HtmlSource::parse( $double )['repaired'] );
    }

    /** Review round 1: only "</" and a letter ends raw text in libxml2, so only that is written "<\/"; a style holding '</' alone is untouched. */
    public function testOnlyAnEndTagRunIsRewritten(): void
    {
        $this->assertSame( '<style>a::after{content:"</"} b::after{content:"<\/b"}</style>', HtmlSource::inertRawText( '<style>a::after{content:"</"} b::after{content:"</b"}</style>' ) );
        $this->assertSame( '<style>a::after{content:"</ 1"}</style>', HtmlSource::inertRawText( '<style>a::after{content:"</ 1"}</style>' ) );
    }

    /** The neutralised raw text: only </ in script and style text, only when the markup around it is modelled. */
    public function testOnlyScriptAndStyleTextIsMadeInert(): void
    {
        $this->assertSame( '<script>a="<\/b>"</script><p>x</p>', HtmlSource::inertRawText( '<script>a="</b>"</script><p>x</p>' ) );
        $this->assertSame( '<STYLE>a{b:"<\/x>"}</STYLE >', HtmlSource::inertRawText( '<STYLE>a{b:"</x>"}</STYLE >' ) );
        $this->assertSame( "<script>a='<\/scriptx>'</script>", HtmlSource::inertRawText( "<script>a='</scriptx>'</script>" ), 'a browser does not end the script at </scriptx' );
        foreach ( [
            '<p>a</p>',
            '<textarea></body></textarea><p>B</p>',
            '<title></html></title><p>B</p>',
            '<script><!-- <script>a="</b>"</script> --></script>',
            '<svg><script>a="</b>"</script></svg>',
            '<script/>a="</b>"</script>',
            '<!-->x<script>a="</b>"</script>',
            '<p title="<script>">a="</b>"',
            '<!-- <script> -->a="</b>"',
            '<script>a="</b>"',
        ] as $html ) {
            $this->assertSame( $html, HtmlSource::inertRawText( $html ), $html );
        }
        $this->assertSame( '<noscript><iframe src="/x"></iframe></noscript><script>a="<\/b>"</script>', HtmlSource::inertRawText( '<noscript><iframe src="/x"></iframe></noscript><script>a="</b>"</script>' ), 'plain markup in a noscript is skipped' );
        $this->assertSame( '<noscript><script>x</script></noscript><script>a="</b>"</script>', HtmlSource::inertRawText( '<noscript><script>x</script></noscript><script>a="</b>"</script>' ), 'a script in a noscript: libxml2 reads it as a script, not modelled' );
    }

    /**
     * I8 property test (seeded, AIED_SEED overrides the default): random pages of the open-paragraph family. After the parse no block a
     * browser closes a p for sits in a p through phrasing elements only, and the text is exactly libxml2's text of the page as given, in
     * the same order (a </p> adds and moves no text).
     */
    public function testRandomOpenParagraphsNeverHoldABlock(): void
    {
        mt_srand( (int) ( getenv( 'AIED_SEED' ) ?: 20261022 ) );
        $blocks  = [ 'section', 'article', 'aside', 'header', 'footer', 'main', 'figure', 'figcaption', 'details', 'summary', 'hgroup', 'search', 'div', 'h2', 'ul', 'blockquote', 'p', 'pre', 'hr' ];
        $phrase  = [ 'b', 'span', 'a', 'em', 'label', 'strong', 'my-tag', 'code' ];
        for ( $n = 0; $n < 150; $n++ ) {
            $html = HtmlToDiviTest::openParagraphPage( 3 );
            $s    = HtmlSource::parse( $html );
            $this->assertNull( $s['error'], $html );
            $raw  = new \DOMDocument();
            $prev = libxml_use_internal_errors( true );
            $raw->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD );
            libxml_clear_errors();
            libxml_use_internal_errors( $prev );
            $this->assertSame( $raw->documentElement->textContent, $s['body']->textContent, 'the same text in the same order: ' . $html );
            foreach ( $blocks as $b ) {
                foreach ( iterator_to_array( $s['body']->getElementsByTagName( $b ) ) as $el ) {
                    for ( $up = $el->parentNode; $up instanceof \DOMElement && in_array( strtolower( $up->tagName ), $phrase, true ); $up = $up->parentNode ) {
                    }
                    $this->assertFalse( $up instanceof \DOMElement && 'p' === strtolower( $up->tagName ), 'a ' . $b . ' in a p: ' . $html );
                }
            }
        }
    }
}
