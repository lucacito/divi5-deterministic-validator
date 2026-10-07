<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\CustomFieldReader;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/SiteStructureShims.php';
require_once __DIR__ . '/CustomFieldsReadShims.php';
require_once __DIR__ . '/GetPostsShim.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFieldReader.php';

if ( ! function_exists( 'update_meta_cache' ) ) {
    function update_meta_cache( $type, $ids ) { return true; }
}

/** The real discover(): which posts are sampled, and that posts the user cannot edit never leak their keys. */
class CustomFieldDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__ss_post_types'] = [ 'page' => (object) [], 'book' => (object) [], 'attachment' => (object) [] ];
        $GLOBALS['__wp_caps']       = [ 'edit_post' => true ];
        $GLOBALS['__wp_postmeta']   = [
            1 => [ 'isbn' => '1', 'api_key' => 'x', '_edit_lock' => 'y' ],
            2 => [ 'isbn' => '2' ],
            3 => [ 'secret_note' => 'z', 'format' => 'hc' ],
            9 => [ 'media_only' => 'm' ],
        ];
        $GLOBALS['__cfd_posts'] = [ 'book' => [ 1, 2, 3 ], 'page' => [], 'attachment' => [ 9 ] ];
    }

    protected function tearDown(): void
    {
        unset( $GLOBALS['__cfd_posts'], $GLOBALS['__ss_post_types'], $GLOBALS['__wp_caps'], $GLOBALS['__wp_postmeta'], $GLOBALS['__ss_acf_groups'], $GLOBALS['__ss_acf_fields'] );
        $GLOBALS['__wp_can'] = true;
    }

    public function testItSamplesEachPublicTypeExceptAttachmentsAndFiltersKeys(): void
    {
        $d = CustomFieldReader::discover();
        $this->assertSame( [ 'book' ], array_column( $d['post_types'], 'post_type' ), 'empty types and attachments are omitted' );
        $this->assertSame( [ 'isbn', 'format' ], array_column( $d['post_types'][0]['keys'], 'key' ) );
        $this->assertSame( 2, $d['post_types'][0]['keys'][0]['posts'] );
        $this->assertStringNotContainsString( 'api_key', json_encode( $d ) );
    }

    public function testDiscoverySkipsPostsTheUserCannotEdit(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_post:1' => false, 'edit_post:2' => false ];
        $d = CustomFieldReader::discover();
        $this->assertSame( 1, $d['post_types'][0]['sampled'] );
        $this->assertSame( [ 'format' ], array_column( $d['post_types'][0]['keys'], 'key' ) );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => false ];
        $this->assertSame( [], CustomFieldReader::discover()['post_types'], 'a type whose sampled posts are all uneditable is omitted' );
    }

    public function testItNeverReadsValuesIntoTheAnswer(): void
    {
        $this->assertStringNotContainsString( 'hc', json_encode( CustomFieldReader::discover() ) );
    }
}
