<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CodePolicy;
use AiEditorDivi5\WP\LayoutDiff;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\StyleReferences;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';

/** The plugin never saves custom CSS, JavaScript or PHP through a layout. */
class CodePolicyTest extends TestCase
{
    private const CSS = ',"css":{"desktop":{"value":{"freeForm":"selector{color:red}"}}}';

    /** A real-shaped, validator-clean layout; %ATTRS% is spliced into the heading block's attributes. */
    private static function layout( string $headingExtra = '', string $between = '', string $beforeRow = '' ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} -->' . $beforeRow . '<!-- wp:divi/row {"builderVersion":"5.14.0"} -->'
            . '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} -->'
            . '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Hello"}}},"builderVersion":"5.14.0"' . $headingExtra . '} /-->'
            . $between
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    /** A heading attribute holding one string value, as the AI would write rich text. */
    private static function text( string $value, string $field = 'content' ): string
    {
        return ',"' . $field . '":{"innerContent":{"desktop":{"value":' . json_encode( $value ) . '}}}';
    }

    /** @return list<string> */
    private static function codes( string $content, ?string $baseline = null ): array
    {
        return array_map( static fn ( $v ) => $v->code(), CodePolicy::violations( $content, $baseline ) );
    }

    public function testACleanLayoutHasNoViolationsAndTheValidatorAcceptsIt(): void
    {
        $this->assertTrue( ( new Validator() )->validateContent( self::layout() )->isValid() );
        $this->assertSame( [], self::codes( self::layout() ) );
    }

    public function testCustomCssIsRefusedEvenThoughTheValidatorAcceptsIt(): void
    {
        $content = self::layout( self::CSS );
        $this->assertTrue( ( new Validator() )->validateContent( $content )->isValid(), 'the structure validator alone would accept it' );
        $v = CodePolicy::violations( $content );
        $this->assertCount( 1, $v );
        $this->assertSame( CodePolicy::E_CUSTOM_CSS, $v[0]->code() );
        $this->assertStringContainsString( 'css.desktop.value.freeForm', $v[0]->path() );
    }

    public function testEveryCssFieldAndBreakpointIsFound(): void
    {
        $css = ',"css":{"desktop":{"value":{"mainElement":"a:b"}},"phone":{"value":{"freeForm":"x"}},"tablet":{"hover":{"after":"y"}}}';
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS, CodePolicy::E_CUSTOM_CSS, CodePolicy::E_CUSTOM_CSS ], self::codes( self::layout( $css ) ) );
    }

    public function testAFreeFormOrMainElementFieldIsRefusedWhereverItSits(): void
    {
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( self::layout( ',"module":{"decoration":{"freeForm":"a{}"}}' ) ) );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( self::layout( ',"mainElement":"a{}"' ) ) );
    }

    public function testEmptyCssStringsAreNotCode(): void
    {
        $this->assertSame( [], self::codes( self::layout( ',"css":{"desktop":{"value":{"freeForm":"  "}}}' ) ) );
    }

    public function testDiviCodeModulesAreRefused(): void
    {
        foreach ( [ 'divi/code', 'divi/fullwidth-code' ] as $module ) {
            $content = self::layout( '', '<!-- wp:' . $module . ' {"builderVersion":"5.14.0"} /-->' );
            $this->assertContains( CodePolicy::E_CODE_MODULE, self::codes( $content ), $module );
        }
    }

    public function testScriptMarkupHiddenByJsonEscapingIsFound(): void
    {
        $text = ',"content":{"innerContent":{"desktop":{"value":"<script>alert(1)</script>"}}}';
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( $text ) ) );
        foreach ( [ '<style>a{}</style>', '<iframe src=x></iframe>', '<object data=x>', '<embed src=x>' ] as $evil ) {
            $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( ',"content":{"innerContent":{"desktop":{"value":"' . $evil . '"}}}' ) ), $evil );
        }
    }

    public function testAJavascriptUrlIsFound(): void
    {
        $btn = ',"button":{"innerContent":{"desktop":{"value":{"text":"Go","linkUrl":"javascript:alert(1)"}}}}';
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( $btn ) ) );
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( str_replace( 'javascript:', 'JaVaScRiPt :', $btn ) ) ) );
    }

    public function testAnEventHandlerAttributeIsFoundInATagAndAsACustomAttributeName(): void
    {
        $img = ',"content":{"innerContent":{"desktop":{"value":"<img src=x onerror=alert(1)>"}}}';
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( $img ) ) );
        $attr = ',"module":{"decoration":{"attributes":{"desktop":{"value":{"attributes":[{"id":"a1","name":"onclick","value":"x()"}]}}}}}';
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( $attr ) ) );
    }

    public function testScriptAndPhpBetweenTheBlocksIsFound(): void
    {
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( '', '<script>alert(1)</script>' ) ) );
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( '', '<?php echo 1; ?>' ) ) );
    }

    public function testOrdinaryHtmlAndProseAreNotFalsePositives(): void
    {
        $ok = self::text( '<p>Join online = fun, on Monday=best. <a href="/about" class="x">More</a></p>' );
        $this->assertSame( [], self::codes( self::layout( $ok ) ) );
        $attrs = ',"module":{"decoration":{"attributes":{"desktop":{"value":{"attributes":[{"id":"a1","name":"class","value":"hero"},{"id":"a2","name":"title","value":"Hi"}]}}}}}';
        $this->assertSame( [], self::codes( self::layout( $attrs ) ) );
    }

    public function testGarbageContentDoesNotThrow(): void
    {
        $this->assertSame( [], self::codes( 'not a layout at all' ) );
        $this->assertSame( [], self::codes( '' ) );
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( '<script>x</script> broken <!-- wp:divi/section {' ) );
    }

    // ---- baseline: code that is already on the page is preserved, never counted ---------------

    public function testCodeAlreadyOnThePageIsAllowedWhenItIsLeftUntouched(): void
    {
        $page = self::layout( self::CSS );
        $this->assertSame( [], self::codes( $page, $page ) );
        $edited = str_replace( '"Hello"', '"Hello again"', $page );
        $this->assertSame( [], self::codes( $edited, $page ), 'editing other text keeps the existing CSS' );
    }

    public function testChangedOrAdditionalCodeIsRefusedEvenWhenThePageAlreadyHasSome(): void
    {
        $page = self::layout( self::CSS );
        $changed = str_replace( 'color:red', 'color:blue', $page );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( $changed, $page ) );
        $twice = self::layout( self::CSS, '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"B"}}},"builderVersion":"5.14.0"' . self::CSS . '} /-->' );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( $twice, $page ), 'a second identical copy is new code' );
    }

    public function testNoBaselineMeansNothingIsAllowed(): void
    {
        $this->assertCount( 1, CodePolicy::violations( self::layout( self::CSS ), null ) );
        $this->assertCount( 1, CodePolicy::violations( self::layout( self::CSS ), '' ) );
        $this->assertCount( 1, CodePolicy::violations( self::layout( self::CSS ), self::layout() ) );
    }

    // ---- baseline: a unit of code is the WHOLE offending text -----------------------------------

    public function testSwappingCodeForDifferentCodeIsNewCodeButAnUnchangedUnitStaysAllowedAnywhere(): void
    {
        $pairs = [
            'script'     => [ '<script>safe</script>', '<script src=https://evil/x.js></script>' ],
            'javascript' => [ '<a href="javascript:a()">x</a>', '<a href="javascript:evil()">x</a>' ],
            'onclick'    => [ '<a onclick=a()>x</a>', '<a onclick=evil()>x</a>' ],
            'js value'   => [ 'javascript:a()', 'javascript:evil()' ],
        ];
        foreach ( $pairs as $label => [ $old, $new ] ) {
            $page = self::layout( self::text( $old ) );
            $this->assertSame( [], self::codes( $page, $page ), $label . ': unchanged' );
            $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( self::text( $new ) ), $page ), $label . ': substituted' );
            // The identical unit moved to another field of the same page is still the same code.
            $moved = self::layout( self::text( $old, 'title' ) );
            $this->assertSame( [], self::codes( $moved, $page ), $label . ': moved' );
        }
        $raw = self::layout( '', '<script>safe</script>' );
        $this->assertSame( [], self::codes( $raw, $raw ), 'unchanged raw script' );
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( '', '<script src=https://evil/x.js></script>' ), $raw ) );
    }

    public function testUnchangedCssSurvivesInsertingASectionBeforeIt(): void
    {
        $page   = self::layout( self::CSS );
        $before = '<!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"builderVersion":"5.14.0"} --><!-- wp:divi/heading {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row -->';
        $this->assertSame( [], self::codes( self::layout( self::CSS, '', $before ), $page ) );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( self::layout( self::CSS, '<!-- wp:divi/heading {"builderVersion":"5.14.0"' . self::CSS . '} /-->', $before ), $page ), 'a second identical copy is still new' );
    }

    // ---- raw text: only block delimiters are removed, never other comments ----------------------

    public function testHtml5CommentTricksCannotHideScripts(): void
    {
        foreach ( [ '<!-- x --!><script>alert(1)</script>-->', '<!--><script>alert(1)</script><!-- -->', '<!---><script>alert(1)</script><!-- -->' ] as $trick ) {
            $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( '', $trick ) ), $trick );
        }
    }

    // ---- obfuscation ----------------------------------------------------------------------------

    public function testObfuscatedScriptsAreFound(): void
    {
        $values = [
            '<img/src=x/onerror=alert(1)>',
            '<svg/onload=x>',
            '<a href="x"onclick=x>go</a>',
            '<img src=x onerror=alert(1)>',
            "<img src=x\nonerror=alert(1)>",
            '<a href="&#106;avascript:x">go</a>',
            '<a href="&amp;#106;avascript:x">go</a>',
            '<a href="java' . "\t" . 'script:x">go</a>',
            'jav&#x61;script:x',
            "java\tscript:x",
            'java&Tab;script:x',
            '<scr' . 'ipt>x</script>',
            '&#106;avascript:x',
        ];
        foreach ( $values as $v ) {
            $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( self::text( $v ) ) ), $v );
        }
        // A script URL in a URL-like field may use any whitespace in the scheme.
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( ',"button":{"innerContent":{"desktop":{"value":{"linkUrl":" java script:x"}}}}' ) ) );
    }

    public function testProseAndAttributeValuesAreNotCode(): void
    {
        $values = [
            'JavaScript: the good parts',
            'Learn JavaScript : fast',
            '<h2>Why JavaScript: a primer</h2>',
            '<img alt="Sign up online = easy" src="a.jpg">',
            '<a href="/x" title="Book online = save 10%">Go</a>',
            '<a href="https://example.com/?online=1&once=2">x</a>',
            'x < script writing',
            'a < object then',
            '<p>one &lt;script&gt; mention</p>',
        ];
        foreach ( $values as $v ) {
            $this->assertSame( [], self::codes( self::layout( self::text( $v ) ) ), $v );
        }
    }

    // ---- fail closed ----------------------------------------------------------------------------

    public function testContentThatCannotBeScannedIsRefused(): void
    {
        $jit = (string) ini_get( 'pcre.jit' );
        $lim = (string) ini_get( 'pcre.backtrack_limit' );
        try {
            ini_set( 'pcre.jit', '0' );
            ini_set( 'pcre.backtrack_limit', '1' );
            $v = CodePolicy::violations( self::layout( '', str_repeat( '<b x ', 200 ) . 'plain text' ) );
        } finally {
            ini_set( 'pcre.jit', $jit );
            ini_set( 'pcre.backtrack_limit', $lim );
        }
        $this->assertNotSame( [], $v, 'a regex failure must not mean "allowed"' );
        $this->assertSame( CodePolicy::E_SCRIPT, $v[0]->code() );
        $this->assertStringContainsString( 'could not be scanned', $v[0]->message() );
    }

    // ---- the raw backstop: the parser and the browser disagree about comments and delimiters --------

    /** @return array<string, array{0:string, 1:string}> label => [ content, expected code ] */
    public static function parserDifferentials(): array
    {
        $heading = '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"B"}}},"builderVersion":"5.14.0"';

        return [
            'code module after an unclosed comment opener' => [ self::layout( '', '<!-- x <!-- wp:divi/code {"a":1} /-->' ), CodePolicy::E_CODE_MODULE ],
            'css after an unclosed comment opener'         => [ self::layout( '', '<!-- x ' . $heading . self::CSS . '} /-->' ), CodePolicy::E_CUSTOM_CSS ],
            'code module with --> inside its JSON'         => [ self::layout( '', '<!-- wp:divi/code {"x":"-->"} /-->' ), CodePolicy::E_CODE_MODULE ],
            'css after --> inside a JSON string'           => [ self::layout( '', '<!-- wp:divi/heading {"x":"-->"' . self::CSS . '} /-->' ), CodePolicy::E_CUSTOM_CSS ],
            'entity-encoded code module in raw text'       => [ self::layout( '', '&lt;!-- wp:divi/code {"a":1} /--&gt;' ), CodePolicy::E_CODE_MODULE ],
            'fullwidth code, mixed case, spaced'           => [ self::layout( '', '<!--   wp:divi/FULLWIDTH-code   {"a":1} /-->' ), CodePolicy::E_CODE_MODULE ],
            'script after --!> in a wp comment'            => [ self::layout( '', '<!-- wp:foo --!><script>alert(1)</script> -->' ), CodePolicy::E_SCRIPT ],
            'unicode-escaped css key'                      => [ self::layout( '', '<!-- x <!-- wp:divi/heading {"css":{"desktop":{"value":{"freeForm":"a{}"}}}} /-->' ), CodePolicy::E_CUSTOM_CSS ],
            'script URL in a plain JSON value'             => [ self::layout( '', '<!-- x <!-- wp:divi/button {"linkUrl":"javascript:alert(1)"} /-->' ), CodePolicy::E_SCRIPT ],
            'onclick attribute entry in a hidden block'    => [ self::layout( '', '<!-- x <!-- wp:divi/text {"attributes":[{"name":"onclick","value":"x()"}]} /-->' ), CodePolicy::E_SCRIPT ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'parserDifferentials' )]
    public function testWhatTheParserDoesNotSeeIsStillRefused( string $content, string $code ): void
    {
        $this->assertContains( $code, self::codes( $content ), 'CodePolicy' );
        $gate = array_map( static fn ( $v ) => $v->code(), LayoutGate::check( $content )->violations() );
        $this->assertContains( $code, $gate, 'LayoutGate' );
        $this->assertFalse( LayoutGate::check( $content )->isValid() );
    }

    public function testAnUnchangedPageWithCodeIsCleanAndEditsNextToItAreToo(): void
    {
        $code = '<!-- wp:divi/code {"builderVersion":"5.14.0","content":{"innerContent":{"desktop":{"value":"<script>run()<\/script>"}}}} /-->';
        $page = self::layout( self::CSS . self::text( '<style>a{}</style><a onclick=go()>x</a>', 'text' ), $code . '<script>raw()</script>' );
        $this->assertSame( [], self::codes( $page, $page ), 'identical content is clean' );
        $this->assertSame( [], self::codes( str_replace( '"Hello"', '"Hello, edited"', $page ), $page ), 'editing text beside the code is clean' );
        $moved = self::layout( str_replace( 'desktop', 'phone', self::CSS ) );
        $this->assertSame( [], self::codes( $moved, self::layout( self::CSS ) ), 'the same CSS on another breakpoint is the same CSS' );
        $this->assertSame( [], self::codes( self::layout( self::text( '<script>safe()</script>' ) ), self::layout( '', '<script>safe()</script>' ) ), 'the same script moved between an attribute and the text between blocks' );
    }

    public function testNewCodeNextToUnchangedCodeIsStillRefused(): void
    {
        $page = self::layout( self::CSS );
        $this->assertContains( CodePolicy::E_CUSTOM_CSS, self::codes( self::layout( self::CSS, '<!-- x <!-- wp:divi/heading {"css":{"desktop":{"value":{"freeForm":"b{}"}}}} /-->' ), $page ) );
        $this->assertContains( CodePolicy::E_CODE_MODULE, self::codes( self::layout( self::CSS, '<!-- x <!-- wp:divi/code {"a":1} /-->' ), $page ) );
    }

    public function testProseAboutScriptsAndOrdinaryAttributesStayClean(): void
    {
        $this->assertSame( [], self::codes( self::layout( self::text( '<p>Never paste &lt;script&gt;alert(1)&lt;/script&gt; into a page.</p>' ) ) ) );
        $this->assertSame( [], self::codes( self::layout( ',"module":{"decoration":{"background":{"desktop":{"value":{"color":"#fff"}}}},"advanced":{"link":{"desktop":{"value":{"url":"https://example.com/?a=1&b=2","target":"_blank"}}}}},"css":{},"x":{"freeForm":""}' ) ) );
        // An ordinary HTML comment between blocks is a stray comment: refused when new, kept when already there.
        $withComment = self::layout( '', '<!-- an ordinary comment -->' );
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( $withComment ) );
        $this->assertSame( [], self::codes( $withComment, $withComment ) );
    }

    public function testRealExportsOtherThanThePinnedFourStayClean(): void
    {
        $n = 0;
        foreach ( glob( __DIR__ . '/../fixtures/valid/*.json' ) ?: [] as $file ) {
            if ( in_array( basename( $file ), self::EXPECTED_FLAGGED_FIXTURES, true ) ) {
                continue;
            }
            $j = json_decode( (string) file_get_contents( $file ), true );
            if ( isset( $j['post_content'] ) && is_string( $j['post_content'] ) ) {
                $this->assertSame( [], self::codes( $j['post_content'] ), basename( $file ) );
                ++$n;
            }
        }
        $this->assertGreaterThan( 10, $n );
    }

    public function testAHugeNumberOfFindingsIsRefusedNotTruncated(): void
    {
        $many = str_repeat( '<script>x</script>', 2100 );
        $page = self::layout( '', $many );
        $this->assertSame( [], self::codes( $page, $page ), 'unchanged' );
        $this->assertNotSame( [], self::codes( self::layout( '', $many . '<script>y</script>' ), $page ), 'one more is new code' );
        $this->assertNotSame( [], self::codes( $page ), 'without a baseline it is refused' );
    }

    // ---- tag tokenizing, entities and attribute names -----------------------------------------

    public function testQuoteConfusionCannotHideAnEventHandler(): void
    {
        foreach ( [
            '<a x=a"b onclick=alert(1) c="d">z</a>',
            "<img alt=a'b onerror=alert(1) c='d' src=x>",
            '<a x" onclick=alert(1) y="z">q</a>',
        ] as $tag ) {
            $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( self::text( $tag ) ) ), $tag );
        }
        $this->assertSame( [], self::codes( self::layout( self::text( '<a title="a b" data-x=\'c d\' href="/x">ok</a>' ) ) ) );
    }

    public function testNumericEntitiesNeedNoSemicolonAndAnyNumberOfZeros(): void
    {
        foreach ( [ '&#106avascript:alert(1)', '&#000000000106;avascript:alert(1)', '&#x0000006a;avascript:alert(1)', 'java&#10script:alert(1)', 'java&#9script:alert(1)', 'java&#x0A;script:alert(1)' ] as $url ) {
            $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( self::text( '<a href="' . $url . '">x</a>' ) ) ), $url );
        }
    }

    public function testPaddedCustomAttributeNamesAreStillEventHandlers(): void
    {
        foreach ( [ ' onclick', 'onclick ', "\tonclick", 'ONCLICK', "onclick\n" ] as $name ) {
            $attr = ',"module":{"decoration":{"attributes":{"desktop":{"value":{"attributes":[{"id":"a1","name":' . json_encode( $name ) . ',"value":"x()"}]}}}}}';
            $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( $attr ) ), json_encode( $name ) );
        }
    }

    public function testAScriptUrlInAUrlLikeAncestorOrNamedAttributeIsFound(): void
    {
        $ancestor = ',"x":{"url":{"desktop":{"value":"javascript: alert(1)"}}}';
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( $ancestor ) ) );
        $named = ',"module":{"decoration":{"attributes":{"desktop":{"value":{"attributes":[{"id":"a1","name":"href","value":"javascript: alert(1)"}]}}}}}';
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( $named ) ) );
        $this->assertSame( [], self::codes( self::layout( self::text( 'JavaScript: the good parts' ) ) ), 'prose stays prose' );
        $this->assertSame( [], self::codes( self::layout( ',"foo":"JavaScript: the good parts"' ) ) );
    }

    // ---- the structural differential guard (WordPress core's delimiter grammar as a second parser) --

    private const GUARD = 'malformed or ambiguous';

    /** @return array<string, array{0:string, 1:string, 2:bool}> label => [ block name, JSON attribute members, isScript ] */
    public static function disguisedPayloads(): array
    {
        $long = str_repeat( 'x', 2500 );

        return [
            'code module'                    => [ 'divi/code', '"a":1', false ],
            'css freeForm'                   => [ 'divi/heading', '"css":{"desktop":{"value":{"freeForm":"a{}"}}}', false ],
            'I-1 value before name'          => [ 'divi/heading', '"attributes":[{"value":"alert(1)","name":"onclick"}]', true ],
            'I-2 css field behind a brace'   => [ 'divi/heading', '"css":{"desktop":{"value":{}},"tablet":{"value":{"headingContainer":"display:none"}}}', false ],
            'I-3 quoted css, appended'       => [ 'divi/heading', '"css":{"desktop":{"value":{"freeForm":"a{font-family:\"Open Sans\"}body{display:none}"}}}', false ],
            'I-3 long css, appended'         => [ 'divi/heading', '"css":{"desktop":{"value":{"freeForm":"a{' . $long . '}body{display:none}"}}}', false ],
        ];
    }

    /** A page that already holds legitimate quoted and very long CSS and a Code module, all visible to both parsers. */
    private static function pageWithLegitCode(): string
    {
        $quoted = ',"css":{"desktop":{"value":{"freeForm":"a{font-family:\"Open Sans\"}"}}}';
        $long   = ',"css":{"tablet":{"value":{"freeForm":"a{' . str_repeat( 'x', 2500 ) . '}"}}}';

        return self::layout( $quoted . $long, '<!-- wp:divi/code {"a":1} /-->' );
    }

    /** @return list<array{0:string, 1:string}> [ label, content ] the same block in the two disguises */
    private static function disguises( string $name, string $members ): array
    {
        return [
            [ 'after an unclosed comment opener', self::layout( '', '<!-- x <!-- wp:' . $name . ' {' . $members . '} /-->' ) ],
            [ 'with --> inside its JSON', self::layout( '', '<!-- wp:' . $name . ' {"x":"-->",' . $members . '} /-->' ) ],
        ];
    }


    #[\PHPUnit\Framework\Attributes\DataProvider( 'disguisedPayloads' )]
    public function testAHiddenBlockIsRefusedWithAndWithoutALegitimateBaseline( string $name, string $members, bool $isScript ): void
    {
        foreach ( self::disguises( $name, $members ) as [ $how, $content ] ) {
            foreach ( [ null, self::pageWithLegitCode() ] as $baseline ) {
                $policy = CodePolicy::violations( $content, $baseline );
                $this->assertNotSame( [], $policy, $how . ( null === $baseline ? '' : ' (with a baseline)' ) );
                $messages = implode( ' | ', array_map( static fn ( $v ) => $v->message(), $policy ) );
                $this->assertStringContainsString( self::GUARD, $messages, $how );
            }
            $gate = LayoutGate::check( $content, self::pageWithLegitCode() );
            $this->assertFalse( $gate->isValid(), $how );
            $this->assertContains( CodePolicy::E_SCRIPT, array_map( static fn ( $v ) => $v->code(), $gate->violations() ), $how );
            $this->assertSame( [], self::codes( $content, $content ), $how . ': an identical situation already on the page is kept' );
        }
    }

    public function testTheI3AttackIsRefusedWhenTheBaselineHasTheSameQuotedCss(): void
    {
        $baseline = self::layout( ',"css":{"desktop":{"value":{"freeForm":"a{font-family:\"Open Sans\"}"}}}' );
        $attack   = self::layout( '', '<!-- x <!-- wp:divi/heading {"css":{"desktop":{"value":{"freeForm":"a{font-family:\"Open Sans\"}body{display:none}"}}}} /-->' );
        $this->assertSame( [], self::codes( $baseline, $baseline ) );
        $this->assertNotSame( [], self::codes( $attack, $baseline ) );
        $this->assertFalse( LayoutGate::check( $attack, $baseline )->isValid() );
    }

    public function testScriptAfterAMalformedWpCommentIsRefusedThroughTheGateToo(): void
    {
        $content = self::layout( '', '<!-- wp:foo --!><script>x</script> -->' );
        $this->assertContains( CodePolicy::E_SCRIPT, self::codes( $content ) );
        $this->assertContains( CodePolicy::E_SCRIPT, array_map( static fn ( $v ) => $v->code(), LayoutGate::check( $content )->violations() ) );
    }

    public function testStrayAndMalformedCommentsAreRefusedWhenNewAndKeptWhenAlreadyThere(): void
    {
        foreach ( [ '<!-- a normal html comment -->', '--!>', 'text --> more', '<!-- wp:divi/text {"a":1}-->' ] as $stray ) {
            $page = self::layout( '', $stray );
            $this->assertNotSame( [], self::codes( $page ), $stray );
            $this->assertSame( [], self::codes( $page, $page ), $stray . ' (identical in the baseline)' );
            $this->assertNotSame( [], self::codes( self::layout( '', $stray . '<!-- another -->' ), $page ), $stray . ' (a different comment is new)' );
        }
        $this->assertSame( [], self::codes( self::layout() ) );
    }

    public function testNoRealExportAndNoBundledRecipeTripsTheGuard(): void
    {
        $contents = [];
        foreach ( glob( __DIR__ . '/../fixtures/valid/*.json' ) ?: [] as $file ) {
            $j = json_decode( (string) file_get_contents( $file ), true );
            if ( isset( $j['post_content'] ) && is_string( $j['post_content'] ) ) {
                $contents[ basename( $file ) ] = $j['post_content'];
            }
        }
        $fixtures = count( $contents );
        $recipes  = json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        array_walk_recursive( $recipes, static function ( $v, $k ) use ( &$contents ): void {
            if ( is_string( $v ) && str_contains( $v, '<!-- wp:' ) ) {
                $contents[ 'recipe-' . count( $contents ) ] = $v;
            }
        } );
        $this->assertGreaterThanOrEqual( 38, $fixtures );
        $this->assertGreaterThanOrEqual( 17, count( $contents ) - $fixtures );
        foreach ( $contents as $label => $content ) {
            $messages = implode( ' | ', array_map( static fn ( $v ) => $v->message(), CodePolicy::violations( $content ) ) );
            $this->assertStringNotContainsString( self::GUARD, $messages, $label );
        }
    }

    public function testOnlyRealEventHandlerNamesAreFlaggedAsCustomAttributes(): void
    {
        foreach ( [ 'onclick', 'onerror', 'onload', 'onfocus', ' OnMouseOver ', "\u{00a0}onclick" ] as $name ) {
            $attr = ',"attributes":[{"value":"x()","name":' . json_encode( $name ) . '}]';
            $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( $attr ) ), $name );
        }
        foreach ( [ 'one', 'only', 'onion', 'online', 'on', 'once' ] as $name ) {
            $attr = ',"attributes":[{"name":' . json_encode( $name ) . ',"value":"x"}]';
            $this->assertSame( [], self::codes( self::layout( $attr ) ), $name );
        }
    }

    // ---- bounded work: crafted input must be refused quickly, never scanned quadratically -----------

    public function testContentOverTheSizeOrMarkerLimitIsRefusedWithoutScanning(): void
    {
        $tooLong   = self::layout( '', str_repeat( 'a', 1000001 ) );
        $tooMany   = self::layout( '', str_repeat( '<!-- a -->', 10001 ) );
        $atTheEdge = self::layout( '', str_repeat( '<p>a</p>', 100000 ) ); // 800 KB, 9 markers: not too big
        foreach ( [ 'bytes' => $tooLong, 'markers' => $tooMany ] as $limit => $content ) {
            $violations = CodePolicy::violations( $content );
            $this->assertCount( 1, $violations, $limit );
            $this->assertSame( CodePolicy::E_SCRIPT, $violations[0]->code(), $limit );
            $this->assertStringContainsString( 'too large or has too many block comments', $violations[0]->message(), $limit );
            $gate = LayoutGate::check( $content );
            $this->assertFalse( $gate->isValid(), $limit );
            $this->assertContains( CodePolicy::E_SCRIPT, array_map( static fn ( $v ) => $v->code(), $gate->violations() ), $limit );
        }
        $this->assertSame( [], self::codes( $atTheEdge ) );
    }

    /** @return array<string, array{0:string}> */
    public static function crafted(): array
    {
        return [
            '10,000 unterminated openers'            => [ str_repeat( '<!-- wp:a {', 10000 ) ],
            '10,000 openers then 10,000 closers'     => [ str_repeat( '<!-- wp:a {', 10000 ) . str_repeat( '-->', 10000 ) ],
            '9,999 openers each closed at once'      => [ str_repeat( '<!-- wp:a { -->', 9999 ) ],
            '9,000 unterminated attrs with }'        => [ str_repeat( '<!-- wp:a {"x":"}"} ', 9000 ) ],
            '9,999 unterminated openers, ~1 MB'      => [ str_repeat( '<!-- wp:a {' . str_repeat( 'x', 90 ), 9999 ) ],
            '2 MB of unterminated openers'           => [ str_repeat( '<!-- wp:a {', 180000 ) ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'crafted' )]
    public function testCraftedUnterminatedOpenersAreRefusedQuickly( string $content ): void
    {
        $start      = microtime( true );
        $violations = CodePolicy::violations( $content );
        CodePolicy::violations( $content, $content ); // with a baseline too: never slower than refusing
        $gate    = LayoutGate::check( $content, $content );
        $elapsed = microtime( true ) - $start;
        $this->assertNotSame( [], $violations, 'refused' );
        $this->assertFalse( $gate->isValid(), 'never accepted' );
        $this->assertLessThan( 5.0, $elapsed, 'the bounded scan must not be quadratic' );
    }

    public function testALegitimateLargeLayoutUnderTheLimitsIsNotRefusedForItsSize(): void
    {
        $j = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/valid/page-85-layout-4.json' ), true );
        $this->assertIsString( $j['post_content'] );
        $big = str_repeat( $j['post_content'], 9 );
        $this->assertGreaterThan( 500000, strlen( $big ) );
        $this->assertLessThan( 1000000, strlen( $big ) );
        $this->assertLessThan( 10000, substr_count( $big, '<!--' ) );
        $start = microtime( true );
        $this->assertSame( [], CodePolicy::violations( $big, $big ) );
        $this->assertLessThan( 5.0, microtime( true ) - $start );
    }

    public function testABaselineOverTheLimitsIsTreatedAsEmpty(): void
    {
        $small = self::layout( self::CSS );
        $huge  = self::layout( self::CSS, str_repeat( 'a', 1000001 ) );
        $this->assertSame( [], self::codes( $small, $small ), 'control: a normal baseline preserves its CSS' );
        $start = microtime( true );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( $small, $huge ), 'a baseline too big to scan preserves nothing' );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], array_map( static fn ( $v ) => $v->code(), LayoutGate::check( $small, $huge )->violations() ) );
        $this->assertLessThan( 5.0, microtime( true ) - $start );
        $manyMarkers = self::layout( self::CSS, str_repeat( '<!-- x -->', 10001 ) );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( $small, $manyMarkers ) );
    }

    // ---- an unterminated opener already on the page must not excuse a changed block list -------------

    private static function textBlock( string $text ): string
    {
        return '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"' . $text . '"}}},"builderVersion":"5.14.0"} /-->';
    }

    public function testAnUnterminatedOpenerInTheBaselineDoesNotHideAChangedBlockList(): void
    {
        $hidden = '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Hello"}}},"css":{"desktop":{"value":{}},"tablet":{"value":{"headingContainer":"display:none"}}},"builderVersion":"5.14.0"} /-->';
        $base   = self::layout( '', '<!-- TODO ' . self::textBlock( 'B' ) . self::textBlock( 'A' ) );
        $new    = self::layout( '', '<!-- TODO ' . $hidden . self::textBlock( 'A' ) ); // same opener and closer counts
        $this->assertSame( substr_count( $base, '<!--' ), substr_count( $new, '<!--' ) );
        $this->assertSame( substr_count( $base, '-->' ), substr_count( $new, '-->' ) );
        $this->assertNotSame( [], self::codes( $new, $base ), 'CodePolicy' );
        $gate = LayoutGate::check( $new, $base );
        $this->assertContains( CodePolicy::E_SCRIPT, array_map( static fn ( $v ) => $v->code(), $gate->violations() ), 'LayoutGate' );
        $this->assertFalse( $gate->isValid() );

        // The same disguise, moving the hidden CSS.
        $moved = self::layout( '', '<!-- x ' . str_replace( 'headingContainer', 'x1', $hidden ) );
        $this->assertNotSame( [], self::codes( $moved, self::layout( '', '<!-- x ' . $hidden ) ) );
        $this->assertFalse( LayoutGate::check( $moved, self::layout( '', '<!-- x ' . $hidden ) )->isValid() );
    }

    public function testEditingOrdinaryTextBesideAnUnchangedStrayCommentIsAllowed(): void
    {
        $base   = self::layout( '', '<!-- TODO ' . self::textBlock( 'B' ) . self::textBlock( 'A' ) );
        $edited = self::layout( '', '<!-- TODO ' . self::textBlock( 'B' ) . self::textBlock( 'A edited' ) );
        $this->assertSame( [], self::codes( $edited, $base ) );
        $this->assertTrue( LayoutGate::check( $edited, $base )->isValid() === LayoutGate::check( $base, $base )->isValid(), 'the gate adds nothing of its own' );
    }

    // ---- oversized content never reaches the validator, and the message names the limit -------------

    public function testOversizedContentNeverReachesTheValidatorAndTheMessageNamesTheLimit(): void
    {
        $bytes = str_repeat( 'x', 1234567 ); // not a layout: the validator alone would report several violations
        $r     = LayoutGate::check( $bytes );
        $this->assertCount( 1, $r->violations() );
        $this->assertSame( CodePolicy::E_SCRIPT, $r->violations()[0]->code() );
        $this->assertStringContainsString( '1,234,567 bytes (limit 1,000,000)', $r->violations()[0]->message() );

        $markers = str_repeat( '<!-- a -->', 12345 );
        $r       = LayoutGate::check( $markers, $markers );
        $this->assertCount( 1, $r->violations() );
        $this->assertStringContainsString( '12,345 comment markers (limit 10,000)', $r->violations()[0]->message() );

        $json = (string) json_encode( [ 'post_content' => $bytes ] );
        $r    = LayoutGate::checkJson( $json );
        $this->assertCount( 1, $r->violations() );
        $this->assertStringContainsString( 'bytes (limit 1,000,000)', $r->violations()[0]->message() );

        $this->assertTrue( CodePolicy::tooLarge( $bytes ) );
        $this->assertTrue( CodePolicy::tooLarge( $markers ) );
        $this->assertFalse( CodePolicy::tooLarge( self::layout() ) );
    }

    // ---- raw-text elements, SVG animation values, external-resource tags ---------------------------------

    /** @return array<string, array{0:string}> */
    public static function rawTextElementTricks(): array
    {
        $out = [];
        foreach ( [ 'noscript', 'textarea', 'title', 'xmp' ] as $el ) {
            $out[ $el ] = [ '<' . $el . '><p title="</' . $el . '><img src=x onerror=alert(1)>"></' . $el . '>' ];
        }
        $out['single quotes'] = [ "<noscript><p title='</noscript><img src=x onerror=alert(1)>'></noscript>" ];
        $out['javascript url inside a value'] = [ '<noscript><p title="</noscript><a href=javascript:alert(1)>x</a>"></noscript>' ];

        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'rawTextElementTricks' )]
    public function testARawTextElementCannotHideAHandlerInsideAQuotedValue( string $html ): void
    {
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( self::text( $html ) ) ), 'in an attribute string' );
        $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( '', $html ) ), 'between blocks' );
    }

    public function testAScriptUrlInAnyAttributeValueIsFoundIncludingSvgAnimation(): void
    {
        foreach ( [
            '<svg><a><animate attributeName=href values=javascript:alert(1) /><text>Click</text></a></svg>',
            '<svg><a><set attributeName="href" to="javascript:alert(1)"/><text>Click</text></a></svg>',
            '<svg><a><animate attributeName="href" values="#a;java&#9;script:alert(1)" /></a></svg>',
            '<div data-go="JAVASCRIPT:alert(1)">x</div>',
        ] as $html ) {
            $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( self::text( $html ) ) ), $html );
            $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( '', $html ) ), $html . ' (between blocks)' );
        }
    }

    public function testLinkMetaAndBaseTagsAreRefused(): void
    {
        foreach ( [ '<link rel=stylesheet href=https://x/y.css>', '<meta http-equiv=refresh content="0;url=x">', '<base href=//evil>', 'use the <meta> tag' ] as $html ) {
            $this->assertSame( [ CodePolicy::E_SCRIPT ], self::codes( self::layout( self::text( $html ) ) ), $html );
        }
        $page = self::layout( self::text( '<meta name="x" content="y">' ) );
        $this->assertSame( [], self::codes( $page, $page ), 'identical in the baseline' );
    }

    public function testQuotedValuesThatMerelyLookLikeCodeStayClean(): void
    {
        foreach ( [
            '<p title="Book online = save">x</p>',
            '<img alt="Sign up online = easy" src="/a.png">',
            '<p title="a <b>bold</b> idea">x</p>',
            'JavaScript: the good parts; javascript is fun',
            '<a title="learn it" href="/x">go</a>',
        ] as $html ) {
            $this->assertSame( [], self::codes( self::layout( self::text( $html ) ) ), $html );
        }
    }

    // ---- nesting depth cap -------------------------------------------------------------------------------

    private static function nested( int $pairs ): string
    {
        return str_repeat( '<!-- wp:divi/group {"builderVersion":"5.14.0"} -->', $pairs ) . str_repeat( '<!-- /wp:divi/group -->', $pairs );
    }

    public function testDeeplyNestedBlocksAreRefusedQuicklyAndWithLittleMemory(): void
    {
        foreach ( [ 101, 3000 ] as $pairs ) {
            $content = self::nested( $pairs );
            $memory  = memory_get_peak_usage();
            $start   = microtime( true );
            $policy  = CodePolicy::violations( $content );
            $gate    = LayoutGate::check( $content, $content );
            $elapsed = microtime( true ) - $start;
            $this->assertCount( 1, $policy, (string) $pairs );
            $this->assertCount( 1, $gate->violations(), $pairs . ': the validator is never reached' );
            $this->assertStringContainsString( sprintf( '%s nested blocks (limit 100)', number_format( $pairs ) ), $policy[0]->message() );
            $this->assertLessThan( 2.0, $elapsed, (string) $pairs );
            $this->assertLessThan( 64 * 1024 * 1024, memory_get_peak_usage() - $memory, (string) $pairs );
        }
    }

    public function testNestingUpToTheLimitIsNotRefused(): void
    {
        $content = self::nested( 100 );
        $this->assertFalse( CodePolicy::tooLarge( $content ) );
        $this->assertNull( CodePolicy::tooLargeViolation( $content ) );
        $this->assertFalse( CodePolicy::tooLarge( self::layout() ) );
        // Self-closing blocks do not nest.
        $this->assertFalse( CodePolicy::tooLarge( '<!-- wp:divi/section -->' . str_repeat( '<!-- wp:divi/heading {"a":1} /-->', 500 ) . '<!-- /wp:divi/section -->' ) );
    }

    public function testABaselineOverTheDepthLimitIsTreatedAsEmpty(): void
    {
        $deep = self::layout( self::CSS, self::nested( 101 ) );
        $this->assertSame( [], self::codes( self::layout( self::CSS ), self::layout( self::CSS ) ) );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( self::layout( self::CSS ), $deep ) );
    }

    // ---- the depth guard is exact: it measures the validator's own parse tree ------------------------------

    /** @return array<string, array{0:string, 1:int}> label => [ shape, units ] */
    public static function deepShapes(): array
    {
        $out = [];
        foreach ( [ 3000, 4900 ] as $n ) {
            $out[ "mismatched closers x$n" ]     = [ 'mism', $n ];
            $out[ "block name ending in / x$n" ] = [ 'slash', $n ];
            $out[ "<!--> prefixes x$n" ]         = [ 'gt', $n ];
            $out[ "closer with junk x$n" ]       = [ 'junk', $n ];
        }
        foreach ( [ 3000, 5000 ] as $n ) {
            $out[ "well-formed pairs x$n" ] = [ 'ok', $n ];
        }

        return $out;
    }

    private static function deepContent( string $shape, int $n ): string
    {
        return match ( $shape ) {
            'mism'  => str_repeat( '<!-- wp:divi/group --><!-- /wp:divi/x -->', $n ),
            'slash' => str_repeat( '<!-- wp:a/ -->', $n ),
            'gt'    => str_repeat( '<!--><!-- wp:divi/group -->', $n ),
            'junk'  => str_repeat( '<!-- wp:divi/group --><!-- /wp:divi/group y -->', $n ),
            default => str_repeat( '<!-- wp:divi/group {"builderVersion":"5.14.0"} -->', $n ) . str_repeat( '<!-- /wp:divi/group -->', $n ),
        };
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'deepShapes' )]
    public function testEveryDeepShapeIsRefusedBeforeAnyWalkAndWithLittleMemory( string $shape, int $n ): void
    {
        $content = self::deepContent( $shape, $n );
        $this->assertLessThanOrEqual( 10000, substr_count( $content, '<!--' ), 'under the marker limit: only the depth guard can refuse it' );
        $this->assertTrue( CodePolicy::tooDeep( $content ) );
        foreach ( [
            'CodePolicy'      => static fn () => count( CodePolicy::violations( $content ) ),
            'LayoutGate'      => static fn () => count( LayoutGate::check( $content )->violations() ),
            'StyleReferences' => static fn () => null === StyleReferences::advise( $content, [ 'presets' => [], 'colors' => [] ] ) ? 1 : 0,
            'LayoutDiff'      => static fn () => null === LayoutDiff::compare( self::layout(), $content )['diff'] ? 1 : 0,
        ] as $label => $run ) {
            $memory  = memory_get_peak_usage();
            $start   = microtime( true );
            $result  = $run();
            $elapsed = microtime( true ) - $start;
            $this->assertSame( 1, $result, $label );
            $this->assertLessThan( 2.0, $elapsed, $label );
            $this->assertLessThan( 64 * 1024 * 1024, memory_get_peak_usage() - $memory, $label );
        }
    }

    public function testExactly100NestedRealShapedPairsAreNotRefusedAndOneMoreIs(): void
    {
        $nest = static fn ( int $n ): string => '<!-- wp:divi/placeholder -->' . str_repeat( '<!-- wp:divi/group {"builderVersion":"5.14.0"} -->', $n )
            . '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Hi"}}},"builderVersion":"5.14.0"} /-->'
            . str_repeat( '<!-- /wp:divi/group -->', $n ) . '<!-- /wp:divi/placeholder -->';
        // The placeholder is level 1 and the heading one level below the innermost group: 98 groups put it at level 100.
        $this->assertFalse( CodePolicy::tooDeep( $nest( 98 ) ) );
        $this->assertNull( CodePolicy::tooLargeViolation( $nest( 98 ) ) );
        $this->assertTrue( CodePolicy::tooDeep( $nest( 99 ) ) );
        $this->assertNotNull( CodePolicy::tooLargeViolation( $nest( 99 ) ) );
        // Bare nested pairs: 100 deep is allowed, 101 is not.
        $this->assertFalse( CodePolicy::tooDeep( self::nested( 100 ) ) );
        $this->assertTrue( CodePolicy::tooDeep( self::nested( 101 ) ) );
        $this->assertFalse( CodePolicy::tooDeep( self::layout() ) );
    }

    // ---- CSS injection through ordinary style values (live proof 2026-10-02) ---------------------------------

    private const INJECT = '0px;} body{display:none} .x{';

    /** @return array<string, array{0:string}> real attribute paths (copied from fixtures/valid) with %V% for the value */
    public static function styleValueShapes(): array
    {
        $shapes = [
            'padding.top'            => '"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":%V%,"bottom":"10px","syncVertical":"off"}}}}}}',
            'padding.top (phone)'    => '"module":{"decoration":{"spacing":{"phone":{"value":{"padding":{"top":%V%}}}}}}',
            'margin.top'             => '"module":{"decoration":{"spacing":{"desktop":{"value":{"margin":{"top":%V%}}}}}}',
            'font size'              => '"title":{"decoration":{"font":{"font":{"desktop":{"value":{"size":%V%}}}}}}',
            'font colour'            => '"title":{"decoration":{"font":{"font":{"desktop":{"value":{"color":%V%}}}}}}',
            'border radius'          => '"module":{"decoration":{"border":{"desktop":{"value":{"radius":{"topLeft":%V%}}}}}}',
            'box shadow blur'        => '"module":{"decoration":{"boxShadow":{"desktop":{"value":{"horizontal":"6px","blur":%V%}}}}}',
            'background colour'      => '"module":{"decoration":{"background":{"desktop":{"value":{"color":%V%}}}}}',
            'background colour (hover)' => '"module":{"decoration":{"background":{"desktop":{"hover":{"color":%V%}}}}}',
            'advanced setting'       => '"module":{"advanced":{"link":{"desktop":{"value":{"target":%V%}}}}}',
        ];
        $out = [];
        foreach ( $shapes as $label => $shape ) {
            $out[ $label ] = [ $shape ];
        }

        return $out;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'styleValueShapes' )]
    public function testAStyleValueWithCssControlCharactersIsRefused( string $shape ): void
    {
        foreach ( [ self::INJECT, 'red;color:blue', '10px}', 'a<b', 'a>b', '1px/* x */', "1px\\\n2px" ] as $payload ) {
            $content = self::layout( ',' . str_replace( '%V%', json_encode( $payload ), $shape ) );
            $v       = CodePolicy::violations( $content );
            $this->assertCount( 1, $v, $payload );
            $this->assertSame( CodePolicy::E_CUSTOM_CSS, $v[0]->code(), $payload );
            $this->assertMatchesRegularExpression( '/\.(?:decoration|advanced)\./', $v[0]->path(), 'the exact path' );
            $this->assertStringContainsString( 'CSS control characters', $v[0]->message() );
        }
        $content = self::layout( ',' . str_replace( '%V%', json_encode( self::INJECT ), $shape ) );
        $gate    = LayoutGate::check( $content );
        $this->assertFalse( $gate->isValid() );
        $this->assertContains( CodePolicy::E_CUSTOM_CSS, array_map( static fn ( $x ) => $x->code(), $gate->violations() ) );
    }

    public function testPlainStyleValuesAreClean(): void
    {
        $shape = '"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":%V%}}}}}}';
        foreach ( [
            '13.37px',
            '0px 10px 20px rgba(0,0,0,.5)',
            'linear-gradient(90deg,#fff 0%,#000 100%)',
            '$variable({"type":"color","value":{"name":"gcid-x","settings":{}}})$',
            '$variable({"type":"color","value":{"name":"gcid-x","settings":{"opacity":"0.5"}}})$',
            'url("https://example.com/a.png")',
            'https://example.com/wp-content/uploads/a.jpg?x=1&y=2',
            '&#xf658;',
            'calc(100% - 20px)',
            'var(--my-gap)',
            "'Open Sans', sans-serif",
        ] as $value ) {
            $this->assertSame( [], self::codes( self::layout( ',' . str_replace( '%V%', json_encode( $value ), $shape ) ) ), $value );
        }
        // Text and content fields are not style values.
        $this->assertSame( [], self::codes( self::layout( self::text( '<p>a { b; c } d</p>' ) ) ) );
        $this->assertSame( [], self::codes( self::layout( ',"button":{"innerContent":{"desktop":{"value":{"text":"Go; now {x}","linkUrl":"https://example.com/?a=1;b=2"}}}}' ) ) );
    }

    /** @return array<string, array{0:string}> */
    public static function styleValueBypasses(): array
    {
        return [
            'variable token with )$ inside a junk key'  => [ '$variable({"type":"color","value":{"name":"a","settings":{}},"junk":"y)$;} body{display:none} .x{"})$' ],
            'variable token with a payload in its name' => [ '$variable({"type":"color","value":{"name":"x;} body{display:none}","settings":{}}})$' ],
            'variable token with a payload in settings' => [ '$variable({"type":"color","value":{"name":"x","settings":{"a":"1;} body{display:none}"}}})$' ],
            'a leftover $variable('                     => [ '$variable(x' ],
            'a leftover )$'                             => [ 'x)$' ],
            'quote parity, double quotes'               => [ 'x url(" ;} body{display:none} .y{ ")' ],
            'quote parity, single quotes'               => [ "x url(' ;} body{display:none} .y{ ')" ],
            'unquoted url swallowing'                   => [ 'url(a ;} body{display:none} .y{ )' ],
            'identifier before url'                     => [ 'xurl(" ;} body{display:none} .y{ ")' ],
            'a data: URI is not excluded any more'      => [ 'url(data:image/png;base64,AAAA)' ],
            'an image placeholder is not excluded'      => [ '{{aied:image:hero-blue-1}}' ],
            'an entity in front of a payload'           => [ '&#x24;;} body{display:none}' ],
            'an entity behind a payload'                => [ '1px;} body{display:none} .x{&#x24;' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'styleValueBypasses' )]
    public function testKnownBypassShapesOfTheStyleValueRuleAreRefused( string $value ): void
    {
        $shape = '"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":%V%}}}}}}';
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( self::layout( ',' . str_replace( '%V%', json_encode( $value ), $shape ) ) ), $value );
    }

    public function testModulesThatWriteContentFieldsIntoCssAreCovered(): void
    {
        $P = self::INJECT;
        $between = static fn ( string $block ): string => self::layout( '', $block );
        // Real shapes from fixtures/valid (barProgress "50", icon unicode/type/weight), with the payload in the CSS-bearing leaf.
        foreach ( [
            'counter bar width'      => '<!-- wp:divi/counter {"barProgress":{"innerContent":{"desktop":{"value":' . json_encode( '50%;} body{display:none} .x{' ) . '}}}} /-->',
            'icon weight'            => '<!-- wp:divi/icon {"icon":{"innerContent":{"desktop":{"value":{"unicode":"&#xf10d;","type":"fa","weight":' . json_encode( $P ) . '}}}}} /-->',
            'icon colour'            => '<!-- wp:divi/icon {"icon":{"innerContent":{"desktop":{"value":{"unicode":"&#xf10d;","type":"fa","weight":"900","color":' . json_encode( $P ) . '}}}}} /-->',
            'icon size'              => '<!-- wp:divi/icon {"icon":{"innerContent":{"desktop":{"hover":{"value":{"size":' . json_encode( $P ) . '}}}}}} /-->',
            'icon code quote break'  => '<!-- wp:divi/icon {"icon":{"innerContent":{"desktop":{"value":{"unicode":' . json_encode( "x';} body{display:none} .x{content:'" ) . ',"type":"fa","weight":"900"}}}}} /-->',
            'blurb image icon weight' => '<!-- wp:divi/blurb {"imageIcon":{"innerContent":{"desktop":{"value":{"useIcon":"on","icon":{"unicode":"&#xf10d;","type":"fa","weight":' . json_encode( $P ) . '}}}}}} /-->',
            'gallery overlay'        => '<!-- wp:divi/gallery {"overlay":{"innerContent":{"desktop":{"value":{"icon":{"weight":' . json_encode( $P ) . '}}}}}} /-->',
            'a style setting name'   => '<!-- wp:divi/heading {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top;} body{display:none}":"1px"}}}}}}} /-->',
        ] as $label => $block ) {
            $v = CodePolicy::violations( $between( $block ) );
            $this->assertNotSame( [], $v, $label );
            $this->assertSame( CodePolicy::E_CUSTOM_CSS, $v[0]->code(), $label );
        }
        // The real, harmless shapes stay clean.
        foreach ( [
            '<!-- wp:divi/counter {"barProgress":{"innerContent":{"desktop":{"value":"50"}}}} /-->',
            '<!-- wp:divi/icon {"icon":{"innerContent":{"desktop":{"value":{"unicode":"&#xf10d;","type":"fa","weight":"900","color":"#ff0000","size":"32px"}}}}} /-->',
            '<!-- wp:divi/blurb {"imageIcon":{"innerContent":{"desktop":{"value":{"useIcon":"on","icon":{"unicode":"%%45%%","type":"divi","weight":"400"},"src":"data:image/svg+xml;base64,AAAA","width":"66"}}}}} /-->',
        ] as $block ) {
            $this->assertSame( [], CodePolicy::violations( $between( $block ) ), $block );
        }
    }

    public function testAnOwnersStyleValueIsKeptWhenUnchangedAndRefusedWhenChanged(): void
    {
        $shape = '"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":%V%}}}}}}';
        $page  = self::layout( ',' . str_replace( '%V%', json_encode( self::INJECT ), $shape ) );
        $this->assertSame( [], self::codes( $page, $page ) );
        $this->assertSame( [], self::codes( str_replace( '"Hello"', '"Hello again"', $page ), $page ) );
        $changed = self::layout( ',' . str_replace( '%V%', json_encode( self::INJECT . 'a{}' ), $shape ) );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], self::codes( $changed, $page ) );
    }

    public function testAStyleValuePayloadHiddenBehindAParserDifferentialIsStillRefused(): void
    {
        $members = '"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":' . json_encode( self::INJECT ) . '}}}}}}';
        foreach ( self::disguises( 'divi/heading', $members ) as [ $how, $content ] ) {
            $this->assertNotSame( [], self::codes( $content ), $how );
            $this->assertFalse( LayoutGate::check( $content )->isValid(), $how );
        }
    }

    // ---- the gate ------------------------------------------------------------------------------

    public function testTheGateAddsPolicyViolationsToTheValidatorsAndKeepsTheValidatorFirst(): void
    {
        $r = LayoutGate::check( self::layout( self::CSS ) );
        $this->assertFalse( $r->isValid() );
        $this->assertSame( [ CodePolicy::E_CUSTOM_CSS ], array_map( static fn ( $v ) => $v->code(), $r->violations() ) );

        $broken = LayoutGate::check( '<!-- wp:divi/section {"css":{"desktop":{"value":{"freeForm":"x"}}}} /-->' );
        $codes  = array_map( static fn ( $v ) => $v->code(), $broken->violations() );
        $this->assertContains( CodePolicy::E_CUSTOM_CSS, $codes, 'policy findings are reported even when the structure is also invalid' );
        $this->assertGreaterThan( 1, count( $codes ) );
        $this->assertNotSame( CodePolicy::E_CUSTOM_CSS, $codes[0], 'validator violations come first' );
    }

    public function testACleanLayoutPassesTheGateUnchanged(): void
    {
        $this->assertTrue( LayoutGate::check( self::layout() )->isValid() );
        $this->assertTrue( LayoutGate::check( self::layout( self::CSS ), self::layout( self::CSS ) )->isValid() );
    }

    public function testTheJsonEnvelopeFormIsGatedToo(): void
    {
        $json = (string) json_encode( [ 'post_content' => self::layout( self::CSS ) ] );
        $r    = LayoutGate::checkJson( $json );
        $this->assertContains( CodePolicy::E_CUSTOM_CSS, array_map( static fn ( $v ) => $v->code(), $r->violations() ) );
        $this->assertTrue( LayoutGate::checkJson( (string) json_encode( [ 'post_content' => self::layout() ] ) )->isValid() );
        $this->assertFalse( LayoutGate::checkJson( 'not json' )->isValid() );
    }

    // ---- no false positives on real exports ----------------------------------------------------

    public function testRealExportsAreOnlyFlaggedWhenTheyGenuinelyContainCode(): void
    {
        $flagged = [];
        foreach ( glob( __DIR__ . '/../fixtures/valid/*.json' ) ?: [] as $file ) {
            $j = json_decode( (string) file_get_contents( $file ), true );
            if ( ! isset( $j['post_content'] ) || ! is_string( $j['post_content'] ) ) {
                continue;
            }
            if ( [] !== CodePolicy::violations( $j['post_content'] ) ) {
                $flagged[] = basename( $file );
            }
        }
        sort( $flagged );
        $this->assertSame( self::EXPECTED_FLAGGED_FIXTURES, $flagged );
    }

    /** The real exports that genuinely contain custom CSS, a Code module, or script/style markup (checked by hand). */
    private const EXPECTED_FLAGGED_FIXTURES = [
        'page-17-newpage.json',
        'themebuilder-footer-224970.json',
        'themebuilder-header-224959.json',
        'verified-fullwidth-code-column.json',
    ];
}
