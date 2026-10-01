<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\EditTarget;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/DiviThemeBuilderShims.php';
require_once __DIR__ . '/../wp-plugin/src/ThemeLayouts.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';

class EditTargetTest extends TestCase
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
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [ 'tb_id' => 100, 'draft_id' => 300, 'template_ids' => [ 200 ], 'draft_template_ids' => [ 301 ] ];
        $GLOBALS['__wp_caps']     = [ 'edit_post' => true, 'edit_others_posts' => true ];

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
        unset( $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can']      = true;
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [];
    }

    public function testAPageBehavesExactlyAsBefore(): void
    {
        $r = EditTarget::resolve( 5 );
        $this->assertSame( [ 5, 'page', null ], [ $r['post']->ID, $r['kind'], $r['error'] ] );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => false, 'edit_others_posts' => true ];
        $r = EditTarget::resolve( 5 );
        $this->assertSame( EditTarget::FORBIDDEN, $r['error'] );
        $this->assertNull( $r['post'] );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => false ];
        $this->assertNull( EditTarget::resolve( 5 )['error'], 'pages never needed edit_others_posts and still do not' );
    }

    public function testALiveHeaderAndFooterAreEditable(): void
    {
        $h = EditTarget::resolve( 201 );
        $this->assertSame( [ 201, 'header', null ], [ $h['post']->ID, $h['kind'], $h['error'] ] );
        $f = EditTarget::resolve( 202 );
        $this->assertSame( [ 202, 'footer', null ], [ $f['post']->ID, $f['kind'], $f['error'] ] );
    }

    public function testALiveLayoutNeedsBothEditPostAndEditOthersPosts(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => false ];
        $this->assertSame( EditTarget::FORBIDDEN, EditTarget::resolve( 201 )['error'] );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => false, 'edit_others_posts' => true ];
        $this->assertSame( EditTarget::FORBIDDEN, EditTarget::resolve( 201 )['error'] );
        $GLOBALS['__wp_caps'] = [];
        $this->assertSame( EditTarget::FORBIDDEN, EditTarget::resolve( 202 )['error'] );
    }

    /** @return list<array{int,string}> */
    public static function neverEditable(): array
    {
        return [
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

    #[\PHPUnit\Framework\Attributes\DataProvider( 'neverEditable' )]
    public function testEverythingElseIsReportedAsNotFound( int $id, string $why ): void
    {
        $r = EditTarget::resolve( $id );
        $this->assertSame( EditTarget::NOT_FOUND, $r['error'], $why );
        $this->assertNull( $r['post'], $why );
        $this->assertNull( $r['kind'], $why );
    }

    public function testNotFoundEvenForAnAdministratorWhoCanDoEverything(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_others_posts' => true, 'manage_options' => true ];
        $this->assertSame( EditTarget::NOT_FOUND, EditTarget::resolve( 203 )['error'] );
        $this->assertSame( EditTarget::NOT_FOUND, EditTarget::resolve( 204 )['error'] );
    }

    public function testAnUnpublishedOrTrashedLiveLayoutIsNotFound(): void
    {
        foreach ( [ 'draft', 'trash', 'pending', 'private' ] as $status ) {
            $this->post( 201, 'et_header_layout', $status );
            $this->assertSame( EditTarget::NOT_FOUND, EditTarget::resolve( 201 )['error'], $status );
        }
    }

    public function testWithoutALiveThemeBuilderEveryLayoutIsNotFoundButPagesStillWork(): void
    {
        $GLOBALS['__divi_tb']['tb_id'] = 0;
        $this->assertSame( EditTarget::NOT_FOUND, EditTarget::resolve( 201 )['error'] );
        $this->assertNull( EditTarget::resolve( 5 )['error'] );
    }

    public function testResolvingNeverCreatesAThemeBuilderPost(): void
    {
        $GLOBALS['__divi_tb_calls'] = [];
        $postsBefore                = count( $GLOBALS['__wp_posts'] );
        EditTarget::resolve( 201 );
        EditTarget::resolve( 204 );
        $this->assertNotEmpty( $GLOBALS['__divi_tb_calls'] );
        foreach ( $GLOBALS['__divi_tb_calls'] as $call ) {
            if ( 'post_id' === $call[0] ) {
                $this->assertFalse( $call[2], 'create must always be false' );
            }
        }
        $this->assertCount( $postsBefore, $GLOBALS['__wp_posts'], 'no post was created while resolving' );
    }

    public function testAPageNeverTouchesTheThemeBuilder(): void
    {
        $GLOBALS['__divi_tb_calls'] = [];
        EditTarget::resolve( 5 );
        $this->assertSame( [], $GLOBALS['__divi_tb_calls'] );
    }
}
