<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\EditTarget;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';

/** The one rule for "which post may the plugin read and edit": a page the user can edit. Nothing else exists. */
class EditTargetTest extends TestCase
{
    private function post( int $id, string $type, string $status = 'publish' ): void
    {
        $p              = new \WP_Post();
        $p->ID          = $id;
        $p->post_type   = $type;
        $p->post_status = $status;
        $p->post_title  = "Post {$id}";
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = [];
    }

    protected function setUp(): void
    {
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__wp_caps']     = [ 'edit_post' => true ];

        $this->post( 5, 'page' );
        $this->post( 6, 'post' );
        $this->post( 7, 'attachment' );
        $this->post( 201, 'et_header_layout' );
        $this->post( 202, 'et_footer_layout' );
        $this->post( 203, 'et_body_layout' );
        $this->post( 200, 'et_template' );
        $this->post( 100, 'et_theme_builder' );
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can']      = true;
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
    }

    public function testAPageTheUserCanEditResolves(): void
    {
        $r = EditTarget::resolve( 5 );
        $this->assertSame( [ 5, 'page', null ], [ $r['post']->ID, $r['kind'], $r['error'] ] );
    }

    public function testAPageTheUserCannotEditIsForbidden(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => false ];
        $r = EditTarget::resolve( 5 );
        $this->assertSame( EditTarget::FORBIDDEN, $r['error'] );
        $this->assertNull( $r['post'] );
        $this->assertNull( $r['kind'] );
    }

    /** @return list<array{int,string}> */
    public static function neverEditable(): array
    {
        return [
            [ 0, 'id zero' ],
            [ -3, 'negative id' ],
            [ 999, 'unknown id' ],
            [ 6, 'a blog post' ],
            [ 7, 'an attachment' ],
            [ 201, 'a header layout id is just another non-page post' ],
            [ 202, 'a footer layout id' ],
            [ 203, 'a body layout id' ],
            [ 200, 'a template' ],
            [ 100, 'the theme builder post' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'neverEditable' )]
    public function testEverythingThatIsNotAPageIsNotFoundNeverForbidden( int $id, string $why ): void
    {
        $r = EditTarget::resolve( $id );
        $this->assertSame( EditTarget::NOT_FOUND, $r['error'], $why );
        $this->assertNull( $r['post'], $why );
    }

    public function testANonPageIsNotFoundEvenWhenTheUserCannotEditIt(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => false ];
        $this->assertSame( EditTarget::NOT_FOUND, EditTarget::resolve( 201 )['error'], 'nothing is leaked about other post types' );
    }
}
