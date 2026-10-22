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

    // ── markup a browser repairs differently from libxml2 (Task 6 review ruling) ──

    /** @return array<string,array{0:string,1:string}> */
    public static function repairedMarkup(): array
    {
        return [
            'formatting end tag over a block' => [ '<b><p>x</b>y</p>', 'tags that close out of order' ],
            'font over a paragraph'           => [ '<font color="red"><p>x</font>y</p>', 'tags that close out of order' ],
            'em over a div'                   => [ '<em><div>x</em>y</div>', 'tags that close out of order' ],
            'span closed inside a div'        => [ '<span><div>x</span>y</div>', 'tags that close out of order' ],
            'an end tag that matches nothing' => [ '<div>x</section></div>', 'tags that close out of order' ],
            'a div closed over a b'           => [ '<div><b>x</div>y', 'tags that close out of order' ],
            'button in a button'              => [ '<button>a<button>b</button></button>', 'a button inside a button' ],
            'nobr in a nobr'                  => [ '<nobr>a<nobr>b</nobr></nobr>', 'a nobr inside a nobr' ],
            'link in a link'                  => [ '<a href="#">a<a href="#">b</a></a>', 'a link inside a link' ],
            'dd through a div'                => [ '<dl><dt>a<div><dd>b</dd></div></dl>', 'a list item closed through another element' ],
            'li through a div'                => [ '<ul><li>a<div><li>b</li></div></ul>', 'a list item closed through another element' ],
            'second body with attributes'     => [ '<p>x</p><body style="color:red"><p>y</p>', 'a second body or html tag with attributes' ],
            'second html with attributes'     => [ '<p>x</p><html style="color:red"></html>', 'a second body or html tag with attributes' ],
            'declarative shadow root'         => [ '<div><template shadowrootmode="open"><slot></slot></template>x</div>', 'a declarative shadow root' ],
            'popover'                         => [ '<div popover>x</div>', 'a popover' ],
            'style inside math'               => [ '<math><style>p{color:red}</style></math><p>x</p>', 'a style element inside math' ],
        ];
    }

    #[DataProvider('repairedMarkup')]
    public function testMarkupABrowserRepairsDifferentlyIsReported( string $body, string $reason ): void
    {
        $s = HtmlSource::parse( '<!DOCTYPE html><html><body>' . $body . '</body></html>' );
        $this->assertNull( $s['error'] );
        $this->assertContains( $reason, $s['repaired'] );
        foreach ( $s['repaired'] as $r ) {
            $this->assertContains( $r, HtmlSource::REPAIRS, 'a fixed sentence, never the input' );
        }
    }

    /** @return array<string,array{0:string}> */
    public static function wellFormedMarkup(): array
    {
        return [
            'implied ends'       => [ '<ul><li>a<li>b</ul><dl><dt>a<dd>b<dt>c</dl><p>a<p>b<table><tr><td>a<td>b<tr><th>c</table><select><option>a<option>b</select>' ],
            'nested lists'       => [ '<ul><li>a<ul><li>b</li></ul></li></ul>' ],
            'formatting in order'=> [ '<p><b>a <i>b</i></b> <a href="#">c <strong>d</strong></a></p><div><span>x</span></div>' ],
            'voids and raw text' => [ '<p>a<br>b<img src="x.png" alt=""><hr><input></p><script>if (a < b) { document.write("</div>") }</script><textarea></b></textarea><style>p{}</style>' ],
            'comments'           => [ '<!-- </div> <b> --><p>x</p><!---->' ],
            'svg content'        => [ '<svg><g><path d="M0 0"/></g><style>.a{}</style></svg><p>x</p>' ],
            'p around a block'   => [ '<p>a<div>b</div></p>' ],
            'first body attrs'   => [ '' ],
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
                $this->assertSame( [], $s['repaired'], basename( $file ) );
            }
        }
    }

    public function testTheRepairScanIsLinearAndNeverThrows(): void
    {
        $start = hrtime( true );
        $s     = HtmlSource::parse( '<!DOCTYPE html><body>' . str_repeat( '<div><span>', 20 ) . str_repeat( '<li>x', 2400 ) . str_repeat( '<p><b>a</i></p>', 1200 ) . '</body>' );
        $this->assertNull( $s['error'] );
        $this->assertLessThan( 1500, ( hrtime( true ) - $start ) / 1e6 );
        $this->assertSame( [ 'tags that close out of order' ], $s['repaired'] );
        $big = HtmlSource::parse( '<!DOCTYPE html><body>' . str_repeat( '<p class="a" title="x > y">t <b>u</b> <!-- </p> --></p>', 2400 ) . '</body>' );
        $this->assertNull( $big['error'] );
        $this->assertSame( [], $big['repaired'] );
        foreach ( [ '<', '</', '<a', '<a "', "<a b='", '<!--', '<math>', '<math><style>', '<svg', '<style>', '</ >', '<<<>>>', '<a/b c=d/>' ] as $garbage ) {
            $g = HtmlSource::parse( '<p>x</p>' . $garbage );
            $this->assertIsArray( $g['repaired'] );
        }
        $this->assertSame( [], HtmlSource::parse( '' )['repaired'] );
    }
}
