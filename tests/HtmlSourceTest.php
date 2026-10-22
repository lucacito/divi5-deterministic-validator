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
                $this->assertSame( [], $s['repaired'], basename( $file ) );
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
}
