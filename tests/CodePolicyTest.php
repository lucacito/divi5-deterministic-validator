<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CodePolicy;
use AiEditorDivi5\WP\LayoutGate;
use Divi5Validator\Validator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';

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
        $ok = ',"content":{"innerContent":{"desktop":{"value":"<p>Join online = fun, on Monday=best. <a href="/about" class="x">More</a></p>"}}}';
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
