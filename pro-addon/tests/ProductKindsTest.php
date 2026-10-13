<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\PageCreatedKind;
use AiEditorDivi5\Pro\Kinds\ProductCreatedKind;
use AiEditorDivi5\Pro\Kinds\ProductTrashedKind;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';
require_once __DIR__ . '/FakeProductWriter.php';

/** A product create_product made, and how an undo takes it away again (and brings it back). */
class ProductKindsTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private FakeProductWriter $writer;

    protected function setUp(): void
    {
        $this->repo   = new InMemoryBatchRepository();
        $this->log    = new BatchLog( $this->repo );
        $this->writer = new FakeProductWriter();
        $this->writer->products[50] = $this->snapshot();
        $GLOBALS['__wp_posts'] = [ 50 => (object) [ 'ID' => 50, 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Blue Hat', 'post_content' => '', 'post_name' => 'blue-hat' ] ];
        $GLOBALS['__wp_caps']  = [ 'edit_post' => true, 'delete_post' => true ];
        $GLOBALS['__wp_trash_fail']   = false;
        $GLOBALS['__wp_untrash_fail'] = false;
        $GLOBALS['__wp_untrash_vanish'] = false;
        PageCreatedKind::$trashEnabled = null;
    }

    protected function tearDown(): void
    {
        PageCreatedKind::$trashEnabled = null;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_trash_fail'], $GLOBALS['__wp_untrash_fail'], $GLOBALS['__wp_untrash_vanish'] );
    }

    /** @return array<string,mixed> */
    private function snapshot( array $over = [] ): array
    {
        return $over + [
            'status' => 'draft', 'name' => 'Blue Hat', 'description' => '<p>Warm</p>', 'short_description' => 'Warm', 'sku' => 'HAT-1',
            'regular_price' => '20', 'sale_price' => '', 'manage_stock' => true, 'stock_quantity' => 12, 'stock_status' => 'instock', 'backorders' => 'no',
            'category_ids' => [ 3 ], 'tag_ids' => [], 'image_id' => 0, 'gallery_image_ids' => [], 'weight' => '', 'length' => '', 'width' => '', 'height' => '',
            'tax_status' => 'taxable', 'virtual' => false, 'sold_individually' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function item(): array
    {
        return ProductCreatedKind::itemFor( 50, $this->snapshot(), 'Blue Hat' );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ 'product_created' => new ProductCreatedKind( $this->writer ), 'product_trashed' => new ProductTrashedKind( $this->writer ) ], $this->log );
    }

    public function testTheItemRecordsTheProductAndAHashOfWhatMattersForUndo(): void
    {
        $i = $this->item();
        $this->assertSame( [ 'product_created', '50', null, 'applied' ], [ $i['kind'], $i['ref'], $i['before'], $i['state'] ] );
        $this->assertStringContainsString( 'Blue Hat', $i['label'] );
        $this->assertStringContainsString( '#50', $i['label'] );
        $this->assertSame( [ 'available' => true ], $i['undo'] );
        $this->assertSame( Canon::hash( ProductCreatedKind::hashed( $this->snapshot() ) ), $i['after_hash'] );
        $this->assertSame( ProductCreatedKind::FIELDS, array_keys( ProductCreatedKind::hashed( $this->snapshot() ) ) );
        // Every read-back field except the status (which current() checks on its own).
        $this->assertSame( array_values( array_diff( array_keys( $this->snapshot() ), [ 'status' ] ) ), ProductCreatedKind::FIELDS );
    }

    /** @return array<string,array{string,mixed}> */
    public static function ownerEdits(): array
    {
        return [
            'a tag added'          => [ 'tag_ids', [ 9 ] ],
            'the weight'           => [ 'weight', '2' ],
            'the image'            => [ 'image_id', 91 ],
            'the gallery'          => [ 'gallery_image_ids', [ 91 ] ],
            'a dimension'          => [ 'height', '3' ],
            'the tax status'       => [ 'tax_status', 'none' ],
            'virtual'              => [ 'virtual', true ],
            'sold individually'    => [ 'sold_individually', true ],
            'backorders'           => [ 'backorders', 'notify' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'ownerEdits' )]
    public function testAnyOwnerEditIsSkippedByANormalUndo( string $field, mixed $value ): void
    {
        $batch = $this->log->record( 'create_product', 'Created 1', [ $this->item() ] );
        $this->writer->products[50][ $field ] = $value;
        $r = $this->undo()->undo( (int) $batch['id'], false );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][50]->post_status, "{$field} edited: not trashed" );
        $this->assertSame( 'changed_since', $r['items'][0]['result'] );
        $this->assertSame( 0, $r['restored'] );
    }

    public function testCurrentIsTheHashedSnapshotOfADraftAndNullOtherwise(): void
    {
        $kind = new ProductCreatedKind( $this->writer );
        $item = $this->item();
        $this->assertSame( $item['after_hash'], Canon::hash( $kind->current( $item ) ) );

        $this->writer->products[50]['regular_price'] = '25'; // the owner changed the price
        $this->assertNotSame( $item['after_hash'], Canon::hash( $kind->current( $item ) ) );

        $GLOBALS['__wp_posts'][50]->post_status = 'publish';
        $this->assertNull( $kind->current( $item ), 'a published product is not the draft the call left' );
        $GLOBALS['__wp_posts'][50]->post_status = 'draft';
        unset( $this->writer->products[50] );
        $this->assertNull( $kind->current( $item ), 'a gone (or not simple) product' );
    }

    public function testUndoTrashesAnUnchangedDraftAndTheInverseBringsItBackAsADraft(): void
    {
        $batch = $this->log->record( 'create_product', 'Created 1', [ $this->item() ] );
        $r     = $this->undo()->undo( (int) $batch['id'], false );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][50]->post_status );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 1, $r['restored'] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( [ [ 'kind' => 'product_created', 'label' => $this->item()['label'], 'result' => 'restored' ] ], $r['items'] );

        // The original batch is marked undone and the undo batch holds the inverse: a product_trashed item for the same product.
        $this->assertSame( 'undone', $this->repo->rows[ (int) $batch['id'] ]['status'] );
        $this->assertSame( 'undone', $this->repo->rows[ (int) $batch['id'] ]['items'][0]['state'] );
        $inverse = $this->repo->rows[ (int) $r['undo_batch']['id'] ]['items'][0];
        $this->assertSame( [ 'product_trashed', '50', 'applied' ], [ $inverse['kind'], $inverse['ref'], $inverse['state'] ] );
        $this->assertSame( Canon::hash( 'trash' ), $inverse['after_hash'] );

        // Undoing the undo brings the product back as a draft, as an item that can be undone again.
        $back = $this->undo()->undo( (int) $r['undo_batch']['id'], false );
        $this->assertTrue( $back['fully_undone'] );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][50]->post_status );
        $again = $this->repo->rows[ (int) $back['undo_batch']['id'] ]['items'][0];
        $this->assertSame( [ 'product_created', '50', $this->item()['after_hash'] ], [ $again['kind'], $again['ref'], $again['after_hash'] ] );
    }

    public function testAnEditedProductIsSkippedNotTrashed(): void
    {
        $batch = $this->log->record( 'create_product', 'Created 1', [ $this->item() ] );
        $this->writer->products[50]['name'] = 'Renamed by the owner';
        $r = $this->undo()->undo( (int) $batch['id'], false );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][50]->post_status, 'changed since: not trashed' );
        $this->assertSame( 0, $r['restored'] );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertNull( $r['undo_batch'] );
        $this->assertSame( [ [ 'kind' => 'product_created', 'label' => $this->item()['label'], 'result' => 'changed_since' ] ], $r['items'] );
    }

    public function testAPublishedProductIsNeverTrashedNotEvenForced(): void
    {
        $batch = $this->log->record( 'create_product', 'Created 1', [ $this->item() ] );
        $GLOBALS['__wp_posts'][50]->post_status = 'publish';

        $r = $this->undo()->undo( (int) $batch['id'], false );
        $this->assertSame( 'changed_since', $r['items'][0]['result'] );

        $r = $this->undo()->undo( (int) $batch['id'], true );
        $this->assertSame( 0, $r['restored'] );
        $this->assertFalse( $r['fully_undone'], 'force overrides the changed-since rule only' );
        $this->assertSame( [ [ 'kind' => 'product_created', 'label' => $this->item()['label'], 'result' => 'failed', 'reason' => 'not_draft' ] ], $r['items'] );
        $this->assertSame( 'publish', $GLOBALS['__wp_posts'][50]->post_status );
    }

    public function testRestoreReasonsAreExplicit(): void
    {
        $kind = new ProductCreatedKind( $this->writer );
        $item = $this->item();

        $GLOBALS['__wp_posts'][50]->post_status = 'pending';
        $this->assertSame( [ 'ok' => false, 'reason' => 'not_draft' ], $kind->restore( $item ) );
        $GLOBALS['__wp_posts'][50]->post_status = 'draft';

        $GLOBALS['__wp_trash_fail'] = true;
        $this->assertSame( [ 'ok' => false, 'reason' => 'trash_failed' ], $kind->restore( $item ) );
        $GLOBALS['__wp_trash_fail'] = false;

        unset( $this->writer->products[50] );
        $this->assertSame( [ 'ok' => false, 'reason' => 'missing' ], $kind->restore( $item ) );
    }

    public function testWithTheTrashDisabledAChangedProductIsNeverDeletedPermanently(): void
    {
        PageCreatedKind::$trashEnabled = static fn (): bool => false;
        $kind = new ProductCreatedKind( $this->writer );
        $item = $this->item();
        $this->writer->products[50]['description'] = 'changed';
        $this->assertSame( [ 'ok' => false, 'reason' => 'would_delete_permanently' ], $kind->restore( $item ) );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][50]->post_status );

        $this->writer->products[50] = $this->snapshot(); // unchanged again: allowed, but the inverse has no undo point
        $r = $kind->restore( $item );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( [ 'available' => false, 'reason' => 'trash_disabled' ], $r['inverse']['undo'] );
    }

    public function testThePerItemCapabilityIsCheckedAtUndoTime(): void
    {
        $kind = new ProductCreatedKind( $this->writer );
        $this->assertTrue( $kind->allowed( $this->item() ) );
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'delete_post' => false ];
        $this->assertFalse( $kind->allowed( $this->item() ) );
        $this->assertFalse( ( new ProductTrashedKind( $this->writer ) )->allowed( $this->item() ) );
    }

    public function testTheTrashedKindUntrashesAsADraftAndReturnsAProductCreatedItem(): void
    {
        $created = new ProductCreatedKind( $this->writer );
        $r       = $created->restore( $this->item() );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'product_trashed', $r['inverse']['kind'] );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][50]->post_status );

        $trashed = new ProductTrashedKind( $this->writer );
        $this->assertSame( 'trash', $trashed->current( $r['inverse'] ) );
        $back = $trashed->restore( $r['inverse'] );
        $this->assertTrue( $back['ok'] );
        $this->assertSame( 'product_created', $back['inverse']['kind'] );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][50]->post_status );
        $this->assertSame( Canon::hash( ProductCreatedKind::hashed( $this->snapshot() ) ), $back['inverse']['after_hash'] );

        $GLOBALS['__wp_untrash_fail'] = true;
        $GLOBALS['__wp_posts'][50]->post_status = 'trash';
        $this->assertSame( [ 'ok' => false, 'reason' => 'untrash_failed' ], $trashed->restore( $r['inverse'] ) );
        unset( $this->writer->products[50] );
        $this->assertSame( [ 'ok' => false, 'reason' => 'missing' ], $trashed->restore( $r['inverse'] ) );
    }
}
