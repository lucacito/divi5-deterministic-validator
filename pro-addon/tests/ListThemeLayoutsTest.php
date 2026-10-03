<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\ListThemeLayouts;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';

class ListThemeLayoutsTest extends TestCase
{
    private ListThemeLayouts $tool;

    /** @param array<string,mixed> $meta */
    private function post( int $id, string $type, array $meta = [], string $content = '' ): void
    {
        $p               = new \WP_Post();
        $p->ID           = $id;
        $p->post_type    = $type;
        $p->post_status  = 'publish';
        $p->post_title   = "Post {$id}";
        $p->post_content = $content;
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    protected function setUp(): void
    {
        $this->tool                 = new ListThemeLayouts();
        $GLOBALS['__wp_posts']      = [];
        $GLOBALS['__wp_postmeta']   = [];
        $GLOBALS['__divi_tb']       = [ 'tb_id' => 100, 'draft_id' => 0, 'template_ids' => [ 200 ], 'draft_template_ids' => [] ];
        $GLOBALS['__divi_tb_calls'] = [];
        $GLOBALS['__wp_caps']       = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_theme_options' => true ];
        unset( $GLOBALS['__divi_role_allowed'] );
        $this->post( 100, 'et_theme_builder' );
        $this->post( 200, 'et_template', [ '_et_default' => '1', '_et_enabled' => '1', '_et_header_layout_id' => 201, '_et_header_layout_enabled' => '1', '_et_body_layout_id' => 203, '_et_body_layout_enabled' => '1' ] );
        $this->post( 201, 'et_header_layout', [], '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->' );
        $this->post( 203, 'et_body_layout' );
        $this->post( 5, 'page', [], 'PAGE CONTENT' );
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_role_allowed'] );
        $GLOBALS['__wp_can']      = true;
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [];
    }

    public function testWithoutAnIdItListsTemplatesAndTheirLayouts(): void
    {
        $r = $this->tool->run( [] );
        $this->assertSame( 1, $r['count'] );
        $this->assertSame( 201, $r['templates'][0]['header']['layout_id'] );
        $this->assertTrue( $r['templates'][0]['header']['editable'] );
        $this->assertFalse( $r['templates'][0]['body']['editable'], 'a body layout is listed but never editable' );
        $this->assertArrayNotHasKey( 'footer', $r['templates'][0] );
    }

    public function testWithAnIdItAlsoReturnsThatLayoutsContent(): void
    {
        $r = $this->tool->run( [ 'layout_id' => 201 ] );
        $this->assertSame( [ 'layout_id' => 201, 'kind' => 'header', 'title' => 'Post 201', 'content' => '<!-- wp:divi/placeholder --><!-- /wp:divi/placeholder -->' ], $r['layout'] );
    }

    public function testAPageABodyLayoutAndAnUnknownIdAreRefusedAndNeverLeakContent(): void
    {
        foreach ( [ 5, 203, 200, 9999 ] as $id ) {
            try {
                $this->tool->run( [ 'layout_id' => $id ] );
                $this->fail( "layout {$id} must be refused" );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringNotContainsString( 'PAGE CONTENT', $e->getMessage() );
                $this->assertStringContainsString( 'not an editable header or footer', $e->getMessage() );
            }
        }
    }

    public function testBadArgumentsAreRejectedNotCast(): void
    {
        foreach ( [ '201', 201.0, true, [ 201 ], 0, -1 ] as $bad ) {
            try {
                $this->tool->run( [ 'layout_id' => $bad ] );
                $this->fail( 'a bad layout_id must be rejected: ' . json_encode( $bad ) );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( 'layout_id', $e->getMessage() );
            }
        }
    }

    public function testWithoutDivisThemeBuilderPermissionNothingIsReturned(): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Theme Builder permission' );
        $this->tool->run( [] );
    }

    public function testReadingNeverWritesOrCreatesAnything(): void
    {
        $before = [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        $this->tool->run( [] );
        $this->tool->run( [ 'layout_id' => 201 ] );
        $this->assertEquals( $before, [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ] );
        foreach ( $GLOBALS['__divi_tb_calls'] as $call ) {
            if ( 'post_id' === $call[0] ) {
                $this->assertFalse( $call[2], 'create must be false' );
            }
        }
    }

    public function testItIsRegisteredAndNeedsEditThemeOptions(): void
    {
        $this->assertContains( 'list_theme_layouts', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $this->tool->definition()['description'] ) );
        $this->assertSame( [], $this->tool->definition()['inputSchema']['required'] );
    }
}
