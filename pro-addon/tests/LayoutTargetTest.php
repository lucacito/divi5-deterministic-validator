<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ThemeBuilder\LayoutTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';

class LayoutTargetTest extends TestCase
{
    /** @param array<string,mixed> $meta */
    private function post( int $id, string $type, string $status = 'publish', array $meta = [] ): void
    {
        $p              = new \WP_Post();
        $p->ID          = $id;
        $p->post_type   = $type;
        $p->post_status = $status;
        $p->post_title  = "Post {$id}";
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    protected function setUp(): void
    {
        $GLOBALS['__wp_posts']      = [];
        $GLOBALS['__wp_postmeta']   = [];
        $GLOBALS['__divi_tb']       = [ 'tb_id' => 100, 'draft_id' => 300, 'template_ids' => [ 200 ], 'draft_template_ids' => [ 301 ] ];
        $GLOBALS['__divi_tb_calls'] = [];
        $GLOBALS['__wp_caps']       = [ 'edit_post' => true, 'edit_others_posts' => true ];
        unset( $GLOBALS['__divi_role_allowed'] );

        $this->post( 100, 'et_theme_builder' );
        $this->post( 200, 'et_template', 'publish', [ '_et_header_layout_id' => 201, '_et_footer_layout_id' => 202, '_et_body_layout_id' => 203 ] );
        $this->post( 201, 'et_header_layout' );
        $this->post( 202, 'et_footer_layout' );
        $this->post( 203, 'et_body_layout' );
        $this->post( 204, 'et_header_layout' );                       // orphan: no template uses it
        $this->post( 300, 'et_theme_builder', 'auto-draft' );
        $this->post( 301, 'et_template', 'publish', [ '_et_header_layout_id' => 302 ] );
        $this->post( 302, 'et_header_layout' );                        // used only by the draft Theme Builder
        $this->post( 5, 'page' );
        $this->post( 6, 'post' );
        $this->post( 7, 'attachment' );
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_role_allowed'] );
        $GLOBALS['__wp_can']      = true;
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [];
    }

    public function testALiveHeaderAndFooterAreResolved(): void
    {
        $h = LayoutTarget::resolve( 201 );
        $this->assertSame( [ 201, 'header', null ], [ $h['post']->ID, $h['kind'], $h['error'] ] );
        $f = LayoutTarget::resolve( 202 );
        $this->assertSame( [ 202, 'footer', null ], [ $f['post']->ID, $f['kind'], $f['error'] ] );
    }

    public function testALiveLayoutNeedsBothEditPostAndEditOthersPosts(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => false ];
        $this->assertSame( LayoutTarget::FORBIDDEN, LayoutTarget::resolve( 201 )['error'] );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => false, 'edit_others_posts' => true ];
        $this->assertSame( LayoutTarget::FORBIDDEN, LayoutTarget::resolve( 201 )['error'] );
        $GLOBALS['__wp_caps'] = [];
        $this->assertSame( LayoutTarget::FORBIDDEN, LayoutTarget::resolve( 202 )['error'] );
    }

    /** @return list<array{int,string}> */
    public static function neverEditable(): array
    {
        return [
            [ 5, 'a page (the free plugin\'s job)' ],
            [ 203, 'a body layout' ],
            [ 204, 'an orphaned header (no live template uses it)' ],
            [ 302, 'a header used only by the draft Theme Builder' ],
            [ 200, 'a live template' ],
            [ 301, 'a draft-Theme-Builder template' ],
            [ 100, 'the live Theme Builder post' ],
            [ 300, 'the draft Theme Builder post' ],
            [ 6, 'an ordinary post' ],
            [ 7, 'an attachment' ],
            [ 9999, 'a post that does not exist' ],
            [ 0, 'id zero' ],
            [ -5, 'a negative id' ],
        ];
    }

    #[DataProvider( 'neverEditable' )]
    public function testEverythingElseIsReportedAsNotFound( int $id, string $why ): void
    {
        $r = LayoutTarget::resolve( $id );
        $this->assertSame( LayoutTarget::NOT_FOUND, $r['error'], $why );
        $this->assertNull( $r['post'], $why );
        $this->assertNull( $r['kind'], $why );
    }

    public function testNotFoundEvenForAnAdministratorWhoCanDoEverything(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'manage_options' => true, 'edit_theme_options' => true ];
        $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( 203 )['error'] );
        $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( 204 )['error'] );
        $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( 5 )['error'] );
    }

    public function testAnUnpublishedOrTrashedLiveLayoutIsNotFound(): void
    {
        foreach ( [ 'draft', 'trash', 'pending', 'private' ] as $status ) {
            $this->post( 201, 'et_header_layout', $status );
            $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( 201 )['error'], $status );
        }
    }

    public function testWithoutALiveThemeBuilderEveryLayoutIsNotFound(): void
    {
        $GLOBALS['__divi_tb']['tb_id'] = 0;
        $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( 201 )['error'] );
    }

    public function testResolvingNeverCreatesAThemeBuilderPost(): void
    {
        $postsBefore = count( $GLOBALS['__wp_posts'] );
        LayoutTarget::resolve( 201 );
        LayoutTarget::resolve( 204 );
        $this->assertNotEmpty( $GLOBALS['__divi_tb_calls'] );
        foreach ( $GLOBALS['__divi_tb_calls'] as $call ) {
            if ( 'post_id' === $call[0] ) {
                $this->assertFalse( $call[2], 'create must always be false' );
            }
        }
        $this->assertCount( $postsBefore, $GLOBALS['__wp_posts'], 'no post was created while resolving' );
    }

    public function testALiveLayoutIsForbiddenWhenDiviDeniesTheThemeBuilderCapability(): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $this->assertSame( LayoutTarget::FORBIDDEN, LayoutTarget::resolve( 201 )['error'] );
        $this->assertSame( LayoutTarget::FORBIDDEN, LayoutTarget::resolve( 202 )['error'] );
        foreach ( [ 203, 204, 302, 200 ] as $id ) {
            $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( $id )['error'], (string) $id );
        }
    }

    public function testThePerIdEditPostCheckIsHonoured(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_post:202' => false ];
        $this->assertNull( LayoutTarget::resolve( 201 )['error'] );
        $this->assertSame( LayoutTarget::FORBIDDEN, LayoutTarget::resolve( 202 )['error'] );
    }

    public function testAFooterLayoutInTheHeaderSlotIsNotFound(): void
    {
        $this->post( 205, 'et_footer_layout' );
        $this->post( 206, 'et_template', 'publish', [ '_et_header_layout_id' => 205 ] );
        $GLOBALS['__divi_tb']['template_ids'] = [ 200, 206 ];
        $this->assertSame( LayoutTarget::NOT_FOUND, LayoutTarget::resolve( 205 )['error'] );
    }

    public function testMustResolveThrowsSafeMessages(): void
    {
        $this->assertSame( 'header', LayoutTarget::mustResolve( 201 )['kind'] );

        try {
            LayoutTarget::mustResolve( 5 );
            $this->fail( 'a page must be refused' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'list_theme_layouts', $e->getMessage() );
        }

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_post:201' => false ];
        try {
            LayoutTarget::mustResolve( 201 );
            $this->fail( 'a layout the user may not edit must be refused' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'permission', $e->getMessage() );
        }
    }

    public function testAssertMayPassesForAUserWithTheThemeBuilderBar(): void
    {
        LayoutTarget::assertMay();
        $this->addToAssertionCount( 1 );
    }

    public function testAssertMayFailsClosedWithoutTheThemeBuilderPermission(): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Theme Builder permission' );
        LayoutTarget::assertMay();
    }
}
