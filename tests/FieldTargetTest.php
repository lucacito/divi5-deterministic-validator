<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\FieldTarget;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/CustomFieldsReadShims.php';
require_once __DIR__ . '/../wp-plugin/src/FieldTarget.php';

/** Which post's custom fields may be read: any public post type the user can edit. Everything else looks missing. */
class FieldTargetTest extends TestCase
{
    private function post( int $id, string $type ): void
    {
        $p             = new \WP_Post();
        $p->ID         = $id;
        $p->post_type  = $type;
        $GLOBALS['__wp_posts'][ $id ] = $p;
    }

    protected function setUp(): void
    {
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_caps']  = [ 'edit_post' => true ];
        $GLOBALS['__cf_types'] = [ 'page' => true, 'post' => true, 'project' => true, 'et_header_layout' => false ];
        $this->post( 5, 'page' );
        $this->post( 6, 'project' );
        $this->post( 7, 'et_header_layout' );
        $this->post( 8, 'ghost' );
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__cf_types'] );
        $GLOBALS['__wp_can']   = true;
        $GLOBALS['__wp_posts'] = [];
    }

    public function testAPageAndADiviProjectCanBeRead(): void
    {
        foreach ( [ 5, 6 ] as $id ) {
            $r = FieldTarget::resolve( $id );
            $this->assertNull( $r['error'] );
            $this->assertSame( $id, $r['post']->ID );
        }
    }

    public function testMissingNonPublicAndUnknownTypesAreNotFound(): void
    {
        foreach ( [ 0, -3, 999, 7, 8 ] as $id ) {
            $r = FieldTarget::resolve( $id );
            $this->assertSame( FieldTarget::NOT_FOUND, $r['error'], "post {$id}" );
            $this->assertNull( $r['post'] );
        }
    }

    public function testAPublicPostTheUserCannotEditLooksMissing(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_post:6' => false ];
        $this->assertNull( FieldTarget::resolve( 5 )['error'] );
        $r = FieldTarget::resolve( 6 );
        $this->assertSame( FieldTarget::NOT_FOUND, $r['error'], 'the same answer as a missing post' );
        $this->assertNull( $r['post'] );
    }

    public function testANonPublicTypeIsNotFoundEvenWhenTheUserCannotEditIt(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => false ];
        $this->assertSame( FieldTarget::NOT_FOUND, FieldTarget::resolve( 7 )['error'], 'nothing leaks about non-public posts' );
    }
}
