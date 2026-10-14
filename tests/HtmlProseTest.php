<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HtmlProse;
use AiEditorDivi5\WP\HtmlSource;

/** What a Divi text module may hold: a short allow-list, written by this class and never copied from the source. */
final class HtmlProseTest extends HtmlTestCase
{
    private function prose( string $fragment ): string
    {
        $s = HtmlSource::parse( '<html><body>' . $fragment . '</body></html>' );
        $notes = [];

        return HtmlProse::inner( $s['body'], $notes );
    }

    public function testAllowedFormattingIsKept(): void
    {
        $this->assertSame( '<p>a <strong>b</strong> <em>c</em> <u>d</u><br>e</p>', $this->prose( '<p>a <strong>b</strong> <em>c</em> <u>d</u><br>e</p>' ) );
        $this->assertSame( '<ul><li>one</li><li>two</li></ul>', $this->prose( '<ul><li>one</li><li>two</li></ul>' ) );
        $this->assertSame( '<ol start="3"><li>x</li></ol>', $this->prose( '<ol start="3"><li>x</li></ol>' ) );
        $this->assertSame( '<blockquote>q</blockquote>', $this->prose( '<blockquote>q</blockquote>' ) );
    }

    public function testUnknownTagsAreUnwrappedAndTheirTextKept(): void
    {
        $this->assertSame( '<p>hello <strong>big</strong> world</p>', $this->prose( '<p><span class="x" style="y">hello</span> <font color="red"><strong>big</strong></font> <label>world</label></p>' ) );
        $this->assertSame( '<p>inside</p>', $this->prose( '<div><section><p>inside</p></section></div>' ) );
    }

    public function testHeadingsInsideTextBecomeBoldParagraphs(): void
    {
        $this->assertSame( '<p><strong>Title</strong></p>', $this->prose( '<h4>Title</h4>' ) );
    }

    public function testLinksKeepOnlyAWrittenHrefTitleAndTarget(): void
    {
        $this->assertSame( '<p><a href="/x" title="T">go</a></p>', $this->prose( '<p><a href="/x" title="T" onclick="x()" rel="evil" data-a="1" style="y">go</a></p>' ) );
        $this->assertSame( '<p><a href="https://example.com" target="_blank" rel="noopener noreferrer">out</a></p>', $this->prose( '<p><a href="https://example.com" target="_blank">out</a></p>' ) );
        $this->assertSame( '<p>no link</p>', $this->prose( '<p><a>no link</a></p>' ), 'an anchor without a target is just its text' );
        $this->assertSame( '<p>text</p>', $this->prose( '<p><a href="javascript:alert(1)">text</a></p>' ) );
    }

    public function testQuotesInHrefsAreEscaped(): void
    {
        $out = $this->prose( '<p><a href="/a?x=1&amp;y=&quot;z&quot;">q</a></p>' );
        $this->assertStringNotContainsString( '"z"', $out );
        $this->assertStringContainsString( '&amp;', $out );
    }

    public function testTextIsEscapedNeverRawMarkup(): void
    {
        $out = $this->prose( '<p>1 &lt; 2 &amp;&amp; 3 &gt; 2 &lt;script&gt;alert(1)&lt;/script&gt;</p>' );
        $this->assertStringNotContainsString( '<script', $out );
        $this->assertStringContainsString( '&lt;script&gt;', $out );
        $this->assertSame( '<p>1 &lt; 2 &amp;&amp; 3 &gt; 2 &lt;script&gt;alert(1)&lt;/script&gt;</p>', $out );
    }

    public function testTablesKeepTheirStructureAndSpans(): void
    {
        $out = $this->prose( '<table style="x"><caption>Cap</caption><thead><tr><th colspan="2" style="y">H</th></tr></thead><tbody><tr><td rowspan="3">a</td><td onclick="x">b</td></tr></tbody></table>' );
        $this->assertSame( '<table><caption>Cap</caption><thead><tr><th colspan="2">H</th></tr></thead><tbody><tr><td rowspan="3">a</td><td>b</td></tr></tbody></table>', $out );
    }

    public function testNonsenseSpansAreIgnored(): void
    {
        $out = $this->prose( '<table><tr><td colspan="999">a</td><td colspan="x">b</td><td colspan="1">c</td></tr></table>' );
        $this->assertStringNotContainsString( 'colspan', $out );
    }

    public function testImagesInsideTextAreDroppedWithANote(): void
    {
        $s = HtmlSource::parse( '<p>look <img src="/a.png" alt="x"> here</p>' );
        $notes = [];
        $out = HtmlProse::inner( $s['body'], $notes );
        $this->assertSame( '<p>look  here</p>', $out );
        $this->assertCount( 1, $notes );
    }

    public function testEmptyElementsDisappear(): void
    {
        $this->assertSame( '', $this->prose( '<p>   </p>' ) );
        $this->assertSame( '', $this->prose( '<ul><li> </li></ul>' ) );
        $this->assertSame( '', $this->prose( '<ul></ul>' ) );
        $this->assertSame( '', $this->prose( '<p></p><p> </p>' ) );
    }

    public function testPreKeepsItsWhitespace(): void
    {
        $this->assertSame( "<pre>a\n  b</pre>", $this->prose( "<pre>a\n  b</pre>" ) );
    }

    public function testPlainTextForComparison(): void
    {
        $this->assertSame( 'a b c d', HtmlProse::plain( '<p>a</p><p>b <strong>c</strong></p><ul><li>d</li></ul>' ) );
        $this->assertSame( 'Tom & Jerry <3', HtmlProse::plain( '<p>Tom &amp; Jerry &lt;3</p>' ) );
    }

    public function testNothingDangerousSurvivesAnyOfTheseInputs(): void
    {
        foreach ( [
            '<p onclick="x()">a</p>',
            '<p><a href="javascript:alert(1)" onmouseover="x()">a</a></p>',
            '<p><img src="x" onerror="alert(1)"></p>',
            '<div style="background:url(javascript:alert(1))">a</div>',
            '<p><svg onload="alert(1)"></svg>a</p>',
            '<p><iframe src="javascript:alert(1)"></iframe>a</p>',
            '<math><mtext><script>alert(1)</script></mtext></math>a',
            '<p><a href="&#106;avascript:alert(1)">a</a></p>',
            '<p><a href="java&#x09;script:alert(1)">a</a></p>',
        ] as $attack ) {
            $out = $this->prose( $attack );
            foreach ( [ 'onclick', 'onerror', 'onload', 'onmouseover', 'javascript:', '<script', '<iframe', '<svg', 'style=' ] as $bad ) {
                $this->assertStringNotContainsString( $bad, $out, $attack );
            }
        }
    }
}
