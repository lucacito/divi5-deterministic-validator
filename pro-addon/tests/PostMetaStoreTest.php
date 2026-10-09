<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Fields\PostMetaStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../tests/CustomFieldsReadShims.php';
require_once __DIR__ . '/../src/autoload.php';

class PostMetaStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_postmeta'] = [ 5 => [ 'headline' => 'Old', 'list' => [ 1, 2 ] ], 6 => [] ];
    }

    public function testReadReportsExistenceValueAndStructure(): void
    {
        $s = new PostMetaStore();
        $this->assertSame( [ 'exists' => true, 'value' => 'Old', 'rows' => 1, 'structured' => false ], $s->read( 5, 'headline' ) );
        $this->assertSame( [ 'exists' => false, 'value' => null, 'rows' => 0, 'structured' => false ], $s->read( 5, 'missing' ) );
        $list = $s->read( 5, 'list' );
        $this->assertTrue( $list['exists'] );
        $this->assertTrue( $list['structured'] );
        $this->assertNull( $list['value'] );
    }

    public function testAnEmptyStringValueStillExists(): void
    {
        $GLOBALS['__wp_postmeta'][6]['blank'] = '';
        $this->assertSame( [ 'exists' => true, 'value' => '', 'rows' => 1, 'structured' => false ], ( new PostMetaStore() )->read( 6, 'blank' ) );
    }

    public function testWriteStoresTheValueByteExactlyIncludingBackslashesAndQuotes(): void
    {
        $s = new PostMetaStore();
        foreach ( [ 'New', 'C:\\path "quoted" \'single\'', "tab\tand\nnewline", 'Café ✓ 日本語', '' ] as $value ) {
            $this->assertTrue( $s->write( 5, 'headline', $value ), $value );
            $this->assertSame( $value, $GLOBALS['__wp_postmeta'][5]['headline'] );
        }
    }

    public function testWriteCreatesAMissingKey(): void
    {
        $this->assertTrue( ( new PostMetaStore() )->write( 6, 'fresh', 'v' ) );
        $this->assertSame( 'v', $GLOBALS['__wp_postmeta'][6]['fresh'] );
    }

    public function testWriteReportsFailureWhenTheReadBackDiffers(): void
    {
        $GLOBALS['__wp_meta_fail'] = true; // the update_post_meta shim then refuses to store anything
        try {
            $this->assertFalse( ( new PostMetaStore() )->write( 5, 'headline', 'Changed' ) );
            $this->assertSame( 'Old', $GLOBALS['__wp_postmeta'][5]['headline'] );
        } finally {
            $GLOBALS['__wp_meta_fail'] = false;
        }
    }

    public function testDeleteRemovesTheKeyAndSucceedsWhenItIsAlreadyGone(): void
    {
        $s = new PostMetaStore();
        $this->assertTrue( $s->delete( 5, 'headline' ) );
        $this->assertArrayNotHasKey( 'headline', $GLOBALS['__wp_postmeta'][5] );
        $this->assertTrue( $s->delete( 5, 'headline' ) );
    }

    public function testAnAcfSelectorRoutesTheWriteAndTheDeleteThroughTheAcfCallables(): void
    {
        $calls = [];
        $s     = new PostMetaStore(
            static function ( string $selector, string $value, int $postId ) use ( &$calls ): void {
                $calls[] = [ 'write', $selector, $value, $postId ];
                $GLOBALS['__wp_postmeta'][ $postId ]['acf_text'] = $value;
            },
            static function ( string $selector, int $postId ) use ( &$calls ): void {
                $calls[] = [ 'delete', $selector, $postId ];
                unset( $GLOBALS['__wp_postmeta'][ $postId ]['acf_text'] );
            }
        );
        $this->assertTrue( $s->write( 5, 'acf_text', 'Hi', 'field_abc' ) );
        $this->assertTrue( $s->delete( 5, 'acf_text', 'field_abc' ) );
        $this->assertSame( [ [ 'write', 'field_abc', 'Hi', 5 ], [ 'delete', 'field_abc', 5 ] ], $calls );

        // without an ACF selector the plain path is used even if ACF callables exist
        $calls = [];
        $this->assertTrue( $s->write( 5, 'headline', 'Plain' ) );
        $this->assertSame( [], $calls );
    }

    public function testAnAcfWriteThatStoresSomethingElseIsReportedAsFailure(): void
    {
        $s = new PostMetaStore( static function ( string $selector, string $value, int $postId ): void {
            $GLOBALS['__wp_postmeta'][ $postId ]['acf_text'] = $value . ' (mangled)';
        } );
        $this->assertFalse( $s->write( 5, 'acf_text', 'Hi', 'field_abc' ) );
    }
}
