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
    private static function layout( string $headingExtra = '', string $between = '' ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} -->'
            . '<!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} -->'
            . '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Hello"}}},"builderVersion":"5.14.0"' . $headingExtra . '} /-->'
            . $between
            . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
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
