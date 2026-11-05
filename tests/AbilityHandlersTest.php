<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AbilityHandlers;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/autoload.php';

/** The abilities that have no MCP tool of their own: design languages, recipe filters, variety advice and check-design. */
class AbilityHandlersTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_can']     = true;
        unset( $GLOBALS['__wp_caps'] );
        $post                    = new \WP_Post();
        $post->ID                = 5;
        $post->post_content      = '';
        $GLOBALS['__wp_posts']   = [ 5 => $post ];
    }

    public function testAllTenDesignLanguagesWithContrastCheckedPairings(): void
    {
        $r = AbilityHandlers::run( 'get-design-languages', [] );
        $this->assertSame( 10, $r['count'] );
        foreach ( $r['languages'] as $lang ) {
            foreach ( [ 'id', 'colors', 'fonts', 'radius', 'section_padding', 'shadow', 'avoid', 'pairings' ] as $key ) {
                $this->assertArrayHasKey( $key, $lang, $lang['id'] );
            }
            $this->assertNotEmpty( $lang['pairings'] );
            foreach ( $lang['pairings'] as $pair ) {
                $this->assertGreaterThanOrEqual( 1.0, $pair['ratio'] );
                $this->assertSame( $pair['ratio'] >= 4.5, $pair['passes_aa'] );
            }
            $text = array_values( array_filter( $lang['pairings'], static fn ( $p ) => 'text' === $p['text'] && 'bg' === $p['on'] ) );
            $this->assertTrue( $text[0]['passes_aa'], $lang['id'] . ': body text on the page background is readable' );
        }
    }

    public function testOneLanguageByIdAndAnUnknownIdListsTheValidOnes(): void
    {
        $one = AbilityHandlers::run( 'get-design-languages', [ 'id' => 'editorial' ] );
        $this->assertSame( 1, $one['count'] );
        $this->assertSame( 'editorial', $one['languages'][0]['id'] );
        $bad = AbilityHandlers::run( 'get-design-languages', [ 'id' => 'nope' ] );
        $this->assertInstanceOf( \WP_Error::class, $bad );
        $this->assertStringContainsString( 'editorial', $bad->message );
    }

    public function testRecipesFilterByRoleDensityAndStage(): void
    {
        $all = AbilityHandlers::run( 'get-section-recipes', [] );
        $this->assertSame( 71, $all['count'] );
        $airyHero = AbilityHandlers::run( 'get-section-recipes', [ 'role' => 'hero', 'density' => 'airy' ] );
        $this->assertGreaterThan( 0, $airyHero['count'] );
        $this->assertLessThan( $all['count'], $airyHero['count'] );
        foreach ( $airyHero['recipes'] as $row ) {
            $this->assertSame( 'hero', $row['role'] );
            $this->assertSame( 'airy', $row['density'] );
        }
        $pricing = AbilityHandlers::run( 'get-section-recipes', [ 'stage' => 'pricing' ] );
        $this->assertGreaterThan( 0, $pricing['count'] );
        foreach ( $pricing['recipes'] as $row ) {
            $this->assertStringContainsStringIgnoringCase( 'pricing', $row['stage'] );
        }
        $this->assertArrayNotHasKey( 'markup', $all['recipes'][0], 'a list never carries the markup' );
    }

    public function testBadRecipeFiltersAreRefusedWithTheValidValues(): void
    {
        foreach ( [ [ 'role' => 'nope' ], [ 'layout' => 'nope' ], [ 'mood' => 'nope' ], [ 'density' => 'nope' ] ] as $args ) {
            $r = AbilityHandlers::run( 'get-section-recipes', $args );
            $this->assertInstanceOf( \WP_Error::class, $r, json_encode( $args ) );
            $this->assertStringContainsString( 'Valid', $r->message );
        }
    }

    public function testANamedRecipeReturnsItsMarkupThroughTheTool(): void
    {
        $r = AbilityHandlers::run( 'get-section-recipes', [ 'name' => 'hero-cta' ] );
        $this->assertIsArray( $r );
        $this->assertStringContainsString( 'wp:divi/section', $r['content'] );
    }

    private function section( string $bg, string $inner ): string
    {
        return '<!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"' . $bg . '"}}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"builderVersion":"5.14.0"} -->' . $inner . '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
    }

    private function repetitive(): string
    {
        $cards = '<!-- wp:divi/blurb {"builderVersion":"5.14.0"} /-->';
        $page  = '';
        for ( $i = 0; $i < 4; ++$i ) {
            $page .= $this->section( '#ffffff', $cards );
        }

        return '<!-- wp:divi/placeholder -->' . $page . '<!-- /wp:divi/placeholder -->';
    }

    public function testVarietyAdviceFlagsARepeatedPageAndStaysSilentOnAShortOne(): void
    {
        $r = AbilityHandlers::run( 'get-variety-advice', [ 'post_content' => $this->repetitive() ] );
        $this->assertTrue( $r['applicable'] );
        $codes = array_column( $r['findings'], 'code' );
        $this->assertContains( 'REPEATED_LAYOUT', $codes );

        $short = '<!-- wp:divi/placeholder -->' . $this->section( '#ffffff', '' ) . '<!-- /wp:divi/placeholder -->';
        $r = AbilityHandlers::run( 'get-variety-advice', [ 'post_content' => $short ] );
        $this->assertFalse( $r['applicable'] );
        $this->assertSame( [], $r['findings'] );
    }

    public function testExactlyOneOfPostContentAndPageIdIsRequired(): void
    {
        foreach ( [ 'get-variety-advice', 'check-design' ] as $name ) {
            $this->assertInstanceOf( \WP_Error::class, AbilityHandlers::run( $name, [] ), $name );
            $this->assertInstanceOf( \WP_Error::class, AbilityHandlers::run( $name, [ 'post_content' => 'x', 'page_id' => 5 ] ), $name );
        }
    }

    public function testCheckDesignReadsAStoredPageAndHonoursEditPost(): void
    {
        $GLOBALS['__wp_posts'][5]->post_content = $this->section( '#ffffff', '<!-- wp:divi/text {"content":{"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"#fafafa"}}}}}}},"builderVersion":"5.14.0"} /-->' );
        $r = AbilityHandlers::run( 'check-design', [ 'page_id' => 5 ] );
        $this->assertSame( 'problems', $r['checks']['contrast']['status'] );

        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'edit_post' => true, 'edit_post:5' => false ];
        $denied = AbilityHandlers::run( 'check-design', [ 'page_id' => 5 ] );
        $this->assertInstanceOf( \WP_Error::class, $denied, 'the handler checks the page itself, not only the ability layer' );

        $missing = AbilityHandlers::run( 'check-design', [ 'page_id' => 404 ] );
        $this->assertInstanceOf( \WP_Error::class, $missing );
    }

    public function testCheckDesignNeverWritesAnything(): void
    {
        $before = $GLOBALS['__wp_posts'][5]->post_content = $this->repetitive();
        AbilityHandlers::run( 'check-design', [ 'page_id' => 5 ] );
        AbilityHandlers::run( 'get-variety-advice', [ 'page_id' => 5 ] );
        $this->assertSame( $before, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] ?? 0 );
    }

    public function testTooLargeContentIsRefused(): void
    {
        $r = AbilityHandlers::run( 'check-design', [ 'post_content' => str_repeat( 'x', AbilityHandlers::MAX_CONTENT_BYTES + 1 ) ] );
        $this->assertInstanceOf( \WP_Error::class, $r );
    }
}
