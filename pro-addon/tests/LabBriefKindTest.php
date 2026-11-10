<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\LabBriefKind;
use AiEditorDivi5\WP\StudioStore;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/LabFixture.php';

final class LabBriefKindTest extends TestCase
{
    use LabFixture;

    protected function setUp(): void
    {
        $this->setUpLab();
    }

    protected function tearDown(): void
    {
        $this->tearDownLab();
    }

    /**
     * A page 21 built under editorial; returns [id, old row, new row] where the new row is the same brief restyled to luxe
     * and relinked to a new content hash (what lab_restyle does).
     *
     * @return array{int, array<string,mixed>, array<string,mixed>}
     */
    private function restyled(): array
    {
        $id  = $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ), 1 );
        $old = StudioStore::get( 1, $id );
        $this->assertNotNull( $old );
        $this->assertTrue( StudioStore::save( 1, $this->labBrief( 'luxe' ), $id, $old['brief_hash'] )['ok'] );
        $this->assertTrue( StudioStore::link( 1, $id, 21, hash( 'sha256', 'restyled markup' ) ) );
        $new = StudioStore::get( 1, $id );
        $this->assertNotNull( $new );

        return [ $id, $old, $new ];
    }

    public function testItemRecordsTheOldRowAndHashesTheNewState(): void
    {
        [ $id, $old, $new ] = $this->restyled();
        $item               = LabBriefKind::itemFor( 1, $old, $new );

        $this->assertSame( 'lab_brief', $item['kind'] );
        $this->assertSame( LabBriefKind::NAME, $item['kind'] );
        $this->assertSame( (string) $id, $item['ref'] );
        $this->assertSame( 'Lab brief of page #21', $item['label'] );
        $this->assertSame(
            [ 'user' => 1, 'page_id' => 21, 'brief' => $old['brief'], 'brief_hash' => $old['brief_hash'], 'content_hash' => $old['content_hash'] ],
            $item['before']
        );
        $this->assertSame( Canon::hash( LabBriefKind::state( $new ) ), $item['after_hash'] );
        $this->assertSame( [ 'brief_hash' => $new['brief_hash'], 'content_hash' => $new['content_hash'], 'page_id' => 21 ], LabBriefKind::state( $new ) );
        $this->assertNull( LabBriefKind::state( null ) );
        $this->assertSame( [ 'available' => true ], $item['undo'] );
    }

    public function testRestorePutsBackBriefJsonAndTheRecordedContentHashWithoutReadingThePage(): void
    {
        [ $id, $old, $new ] = $this->restyled();
        $item               = LabBriefKind::itemFor( 1, $old, $new );
        // The page changes afterwards; the kind must not look at it.
        $GLOBALS['__wp_posts'][21]->post_content = 'EDITED BY HAND';

        $r = ( new LabBriefKind() )->restore( $item );

        $this->assertTrue( $r['ok'] );
        $back = StudioStore::get( 1, $id );
        $this->assertSame( $old['brief'], $back['brief'] );
        $this->assertSame( $old['brief_hash'], $back['brief_hash'] );
        $this->assertSame( $old['content_hash'], $back['content_hash'] );
        $this->assertSame( 21, $back['page_id'] );
        $this->assertSame( 'lab_brief', $r['inverse']['kind'] );
    }

    public function testAChangedBriefIsChangedSince(): void
    {
        [ $id, $old, $new ] = $this->restyled();
        $item               = LabBriefKind::itemFor( 1, $old, $new );
        $kind               = new LabBriefKind();

        $this->assertSame( $item['after_hash'], Canon::hash( $kind->current( $item ) ) );

        $this->assertTrue( StudioStore::save( 1, $this->labBrief( 'organic-warm' ), $id )['ok'] );
        $this->assertNotSame( $item['after_hash'], Canon::hash( $kind->current( $item ) ) );
    }

    public function testADeletedBriefIsMissing(): void
    {
        [ $id, $old, $new ] = $this->restyled();
        $item               = LabBriefKind::itemFor( 1, $old, $new );
        $kind               = new LabBriefKind();
        $this->assertTrue( StudioStore::delete( 1, $id ) );

        $this->assertNull( $kind->current( $item ) );
        $this->assertSame( [ 'ok' => false, 'reason' => 'missing' ], $kind->restore( $item ) );
    }

    public function testARestoreOverABriefEditedMeanwhileIsAConflict(): void
    {
        [ $id, $old, $new ] = $this->restyled();
        $item               = LabBriefKind::itemFor( 1, $old, $new );
        // A race cannot be staged in one process; the store's refusal is the same path (compare-and-set not ok): a brief
        // too large to save back is refused and the row is left as it is.
        $big                    = $item;
        $big['before']['brief'] = [ 'blob' => str_repeat( 'x', 300000 ) ];
        $r = ( new LabBriefKind() )->restore( $big );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'conflict', $r['reason'] );
        $this->assertSame( $new['brief_hash'], StudioStore::get( 1, $id )['brief_hash'], 'nothing changed' );
    }

    public function testAnUnlinkedOldRowIsRefusedBeforeAnythingIsWritten(): void
    {
        [ $id, $old, $new ] = $this->restyled();
        $kind               = new LabBriefKind();
        $before             = $this->rowSnapshot( $id );
        foreach ( [ [ 'content_hash', null ], [ 'content_hash', '' ], [ 'content_hash', 'not-a-hash' ], [ 'page_id', 0 ] ] as [ $key, $value ] ) {
            $item                   = LabBriefKind::itemFor( 1, $old, $new );
            $item['before'][ $key ] = $value;
            $this->assertSame( [ 'ok' => false, 'reason' => 'link_failed' ], $kind->restore( $item ), "{$key}" );
            $this->assertSame( $before, $this->rowSnapshot( $id ), "{$key}: the row must be untouched" );
        }
    }

    public function testALinkFailureAfterTheSavePutsTheBriefBack(): void
    {
        [ $id, $old, $new ] = $this->restyled();
        $before             = $this->rowSnapshot( $id );
        $kind               = new LabBriefKind( static fn (): bool => false );

        $this->assertSame( [ 'ok' => false, 'reason' => 'link_failed' ], $kind->restore( LabBriefKind::itemFor( 1, $old, $new ) ) );
        $this->assertSame( $before, $this->rowSnapshot( $id ) );
    }

    public function testAnItemWithoutABriefArrayIsInvalid(): void
    {
        [ , $old, $new ] = $this->restyled();
        $item            = LabBriefKind::itemFor( 1, $old, $new );
        $item['before']['brief'] = 'oops';
        $this->assertSame( [ 'ok' => false, 'reason' => 'invalid_item' ], ( new LabBriefKind() )->restore( $item ) );
    }

    /** @return array<string,mixed> */
    private function rowSnapshot( int $id ): array
    {
        $row = StudioStore::get( 1, $id );
        $this->assertNotNull( $row );
        unset( $row['updated_at'] );

        return $row;
    }

    public function testTheInverseRestoresTheNewRow(): void
    {
        [ $id, $old, $new ] = $this->restyled();
        $kind               = new LabBriefKind();

        $first = $kind->restore( LabBriefKind::itemFor( 1, $old, $new ) );
        $this->assertTrue( $first['ok'] );
        $this->assertSame( $old['brief_hash'], StudioStore::get( 1, $id )['brief_hash'] );

        $second = $kind->restore( $first['inverse'] );
        $this->assertTrue( $second['ok'] );
        $back = StudioStore::get( 1, $id );
        $this->assertSame( $new['brief'], $back['brief'] );
        $this->assertSame( $new['brief_hash'], $back['brief_hash'] );
        $this->assertSame( $new['content_hash'], $back['content_hash'] );
    }

    public function testAnAdministratorWhoIsNotTheOwnerRestoresUnderTheOwnersId(): void
    {
        $id  = $this->labPage( 21, 'draft', $this->labBrief( 'editorial' ), 5 );
        $old = StudioStore::get( 5, $id );
        $this->assertTrue( StudioStore::save( 5, $this->labBrief( 'luxe' ), $id, $old['brief_hash'] )['ok'] );
        $this->assertTrue( StudioStore::link( 5, $id, 21, hash( 'sha256', 'restyled' ) ) );
        $new  = StudioStore::get( 5, $id );
        $item = LabBriefKind::itemFor( 5, $old, $new );

        $GLOBALS['__wp_current_user_id'] = 1;
        $GLOBALS['__wp_caps']            = [ 'manage_options' => true, 'edit_post' => true ];
        $kind                            = new LabBriefKind();
        $this->assertTrue( $kind->allowed( $item ) );

        $r = $kind->restore( $item );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( $old['brief_hash'], StudioStore::get( 5, $id )['brief_hash'] );
        $this->assertNull( StudioStore::get( 1, $id ), 'no copy appears under the administrator' );
        $this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \AiEditorDivi5\WP\StudioTable::name() . ' WHERE created_by = 5' ) );
        $this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \AiEditorDivi5\WP\StudioTable::name() . ' WHERE created_by = 1' ) );
    }

    public function testAnotherNonAdminUserIsNotAllowed(): void
    {
        [ , $old, $new ] = $this->restyled();
        $item            = LabBriefKind::itemFor( 1, $old, $new );

        $GLOBALS['__wp_current_user_id'] = 9;
        $GLOBALS['__wp_caps']            = [ 'edit_post' => true, 'edit_pages' => true ];
        $this->assertFalse( ( new LabBriefKind() )->allowed( $item ) );

        $GLOBALS['__wp_current_user_id'] = 1;
        $this->assertTrue( ( new LabBriefKind() )->allowed( $item ), 'the owner who can edit the page' );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => false ];
        $this->assertFalse( ( new LabBriefKind() )->allowed( $item ), 'the owner who can no longer edit the page' );
    }

    public function testBatchesRegistersTheKind(): void
    {
        $this->assertInstanceOf( LabBriefKind::class, Batches::kinds()['lab_brief'] );
    }

    public function testTheKindWorksThroughTheUndoEngine(): void
    {
        [ $id, $old, $new ] = $this->restyled();
        $batch              = $this->log->record( 'tool', 'Restyled', [ LabBriefKind::itemFor( 1, $old, $new ) ] );
        $engine             = new BatchUndo( [ LabBriefKind::NAME => new LabBriefKind() ], $this->log );

        $r = $engine->undo( $batch['id'] ?? $batch );
        $this->assertTrue( (bool) ( $r['fully_undone'] ?? false ), json_encode( $r ) );
        $this->assertSame( $old['brief_hash'], StudioStore::get( 1, $id )['brief_hash'] );
    }
}
