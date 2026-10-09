<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Fields\PostMetaStore;
use AiEditorDivi5\Pro\Kinds\PostMetaKind;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../../tests/CustomFieldsReadShims.php';
require_once __DIR__ . '/../src/autoload.php';

class PostMetaKindTest extends TestCase
{
    private PostMetaKind $kind;

    protected function setUp(): void
    {
        $this->kind               = new PostMetaKind( new PostMetaStore() );
        $GLOBALS['__wp_postmeta'] = [ 5 => [ 'headline' => 'Old' ] ];
        $GLOBALS['__wp_posts']    = [ 5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'P', 'post_content' => '' ] ];
        $GLOBALS['__cf_types']    = [ 'page' => true ];
        $GLOBALS['__wp_can']      = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function item( array $before, array $after, ?string $acf = null ): array
    {
        $item = Item::make( PostMetaKind::NAME, '5:headline', 'Custom field headline on #5', $before, $after );
        if ( null !== $acf ) {
            $item['meta'] = [ 'acf' => $acf ];
        }

        return $item;
    }

    public function testItIsNamedAndReadsTheCanonicalValue(): void
    {
        $this->assertSame( 'post_meta', $this->kind->name() );
        $this->assertSame( [ 'exists' => true, 'value' => 'Old' ], $this->kind->current( $this->item( [ 'exists' => true, 'value' => 'Old' ], [ 'exists' => true, 'value' => 'Old' ] ) ) );
        unset( $GLOBALS['__wp_postmeta'][5]['headline'] );
        $this->assertSame( [ 'exists' => false, 'value' => null ], $this->kind->current( $this->item( [], [] ) ) );
    }

    public function testRestoreBringsBackTheOldValueAndReturnsAnInverseThatUndoesTheUndo(): void
    {
        $GLOBALS['__wp_postmeta'][5]['headline'] = 'New';
        $item = $this->item( [ 'exists' => true, 'value' => 'Old' ], [ 'exists' => true, 'value' => 'New' ] );
        $r    = $this->kind->restore( $item );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'Old', $GLOBALS['__wp_postmeta'][5]['headline'] );
        $this->assertSame( Canon::hash( [ 'exists' => true, 'value' => 'Old' ] ), $r['inverse']['after_hash'] );
        $this->assertSame( [ 'exists' => true, 'value' => 'New' ], $r['inverse']['before'] );

        $again = $this->kind->restore( $r['inverse'] );
        $this->assertTrue( $again['ok'] );
        $this->assertSame( 'New', $GLOBALS['__wp_postmeta'][5]['headline'] );
    }

    public function testRestoringAKeyTheCallCreatedDeletesIt(): void
    {
        $GLOBALS['__wp_postmeta'][5]['fresh'] = 'created';
        $item = Item::make( PostMetaKind::NAME, '5:fresh', 'Custom field fresh on #5', [ 'exists' => false, 'value' => null ], [ 'exists' => true, 'value' => 'created' ] );
        $r    = $this->kind->restore( $item );
        $this->assertTrue( $r['ok'] );
        $this->assertArrayNotHasKey( 'fresh', $GLOBALS['__wp_postmeta'][5] );
        $this->assertSame( [ 'exists' => true, 'value' => 'created' ], $r['inverse']['before'] );
    }

    public function testTheAcfFieldKeyIsKeptOnTheInverse(): void
    {
        $GLOBALS['__wp_postmeta'][5]['headline'] = 'New';
        $r = $this->kind->restore( $this->item( [ 'exists' => true, 'value' => 'Old' ], [ 'exists' => true, 'value' => 'New' ], 'field_abc' ) );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [ 'acf' => 'field_abc' ], $r['inverse']['meta'] );
    }

    public function testAllowedNeedsAnEditablePostAndTheMetaCapability(): void
    {
        $item = $this->item( [ 'exists' => true, 'value' => 'Old' ], [ 'exists' => true, 'value' => 'New' ] );
        $this->assertTrue( $this->kind->allowed( $item ) );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_post_meta:5' => false ];
        $this->assertFalse( $this->kind->allowed( $item ), 'no edit_post_meta' );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_post:5' => false, 'edit_post_meta' => true ];
        $this->assertFalse( $this->kind->allowed( $item ), 'no edit_post' );

        unset( $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_posts'] = [];
        $this->assertFalse( $this->kind->allowed( $item ), 'the post is gone' );
    }

    public function testARefOfTheWrongShapeIsNeverAllowed(): void
    {
        foreach ( [ 'nope', ':key', '5:', 'x:key', '0:key' ] as $ref ) {
            $item = Item::make( PostMetaKind::NAME, $ref, 'x', [], [] );
            $this->assertFalse( $this->kind->allowed( $item ), $ref );
        }
    }

    public function testARestoreUsesTheAcfFieldKeyAsTheSelectorForAWriteAndForADelete(): void
    {
        $writes  = [];
        $deletes = [];
        $kind    = new PostMetaKind( new PostMetaStore(
            static function ( string $selector, string $value, int $postId ) use ( &$writes ): void {
                $writes[] = [ $selector, $value, $postId ];
                $GLOBALS['__wp_postmeta'][ $postId ][ 'field_fresh' === $selector ? 'fresh' : 'headline' ] = $value; // what ACF does: store under the field's name
            },
            static function ( string $selector, int $postId ) use ( &$deletes ): void {
                $deletes[] = [ $selector, $postId ];
            }
        ) );

        // An edited ACF field: the restore writes the old value through ACF with the field key.
        $GLOBALS['__wp_postmeta'][5]['headline'] = 'New';
        $r = $kind->restore( $this->item( [ 'exists' => true, 'value' => 'Old' ], [ 'exists' => true, 'value' => 'New' ], 'field_abc' ) );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [ [ 'field_abc', 'Old', 5 ] ], $writes );

        // The undo of the undo uses the same selector again.
        $again = $kind->restore( $r['inverse'] );
        $this->assertTrue( $again['ok'] );
        $this->assertSame( [ [ 'field_abc', 'Old', 5 ], [ 'field_abc', 'New', 5 ] ], $writes );
        $this->assertSame( 'New', $GLOBALS['__wp_postmeta'][5]['headline'] );

        // A key the call created: the restore deletes it through ACF with the same field key.
        $GLOBALS['__wp_postmeta'][5]['fresh'] = 'created';
        $created = Item::make( PostMetaKind::NAME, '5:fresh', 'Custom field fresh on #5', [ 'exists' => false, 'value' => null ], [ 'exists' => true, 'value' => 'created' ] );
        $created['meta'] = [ 'acf' => 'field_fresh' ];
        $d = $kind->restore( $created );
        $this->assertTrue( $d['ok'] );
        $this->assertSame( [ [ 'field_fresh', 5 ] ], $deletes );
        $this->assertArrayNotHasKey( 'fresh', $GLOBALS['__wp_postmeta'][5] );

        // And its inverse (put the key back) writes with that same selector.
        $back = $kind->restore( $d['inverse'] );
        $this->assertTrue( $back['ok'] );
        $this->assertSame( 'field_fresh', $writes[2][0] );
        $this->assertSame( 'created', $GLOBALS['__wp_postmeta'][5]['fresh'] );
    }

    public function testAKeyWithAColonRoundTripsBecauseTheRefIsSplitAtTheFirstColon(): void
    {
        $GLOBALS['__wp_postmeta'][5]['5:acf:sub'] = 'New';
        $item = Item::make( PostMetaKind::NAME, '5:5:acf:sub', 'Custom field 5:acf:sub on #5', [ 'exists' => true, 'value' => 'Old' ], [ 'exists' => true, 'value' => 'New' ] );
        $this->assertSame( [ 'exists' => true, 'value' => 'New' ], $this->kind->current( $item ) );
        $this->assertTrue( $this->kind->allowed( $item ) );
        $r = $this->kind->restore( $item );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'Old', $GLOBALS['__wp_postmeta'][5]['5:acf:sub'] );
    }
}
