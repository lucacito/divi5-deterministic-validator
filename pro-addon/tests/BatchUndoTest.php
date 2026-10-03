<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Item;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/FakeKind.php';

class BatchUndoTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private BatchUndo $undo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->undo = new BatchUndo( [ 'fake' => new FakeKind() ], $this->log );
        $GLOBALS['__fake_store']   = [];
        $GLOBALS['__fake_writes']  = [];
        $GLOBALS['__fake_allowed'] = true;
        $GLOBALS['__fake_fail']    = false;
    }

    /** Changes the fake site and records the change as a batch of one item. */
    private function change( string $ref, mixed $new, ?array $undoPoint = null ): int
    {
        $old                               = $GLOBALS['__fake_store'][ $ref ] ?? null;
        $GLOBALS['__fake_store'][ $ref ]   = $new;
        $batch                             = $this->log->record( 'fake_tool', "change {$ref}", [ Item::make( 'fake', $ref, "Thing {$ref}", $old, $new, $undoPoint ) ] );

        return $batch['id'];
    }

    public function testItRestoresAnItemThatIsStillAsTheBatchLeftIt(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $r                            = $this->undo->undo( $id );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'one', $GLOBALS['__fake_store']['a'] );
        $this->assertSame( 1, $r['restored'] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( 'restored', $r['items'][0]['result'] );
        $this->assertSame( 'undone', $this->repo->rows[ $id ]['status'] );
        $this->assertSame( 'undone', $this->repo->rows[ $id ]['items'][0]['state'] );
    }

    public function testAnUndoIsItselfABatchSoItCanBeUndone(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $first                        = $this->undo->undo( $id );
        $this->assertSame( 'undo_batch', $this->repo->rows[ $first['undo_batch']['id'] ]['tool'] );
        $this->undo->undo( $first['undo_batch']['id'] );
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'], 'undoing the undo brings the change back' );
    }

    public function testAnItemChangedSinceIsSkippedAndReportedNeverOverwritten(): void
    {
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_store']['a'] = 'the person edited this afterwards';
        $r                            = $this->undo->undo( $id );
        $this->assertSame( 'changed_since', $r['items'][0]['result'] );
        $this->assertSame( 0, $r['restored'] );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'the person edited this afterwards', $GLOBALS['__fake_store']['a'] );
        $this->assertSame( 'applied', $this->repo->rows[ $id ]['status'], 'nothing was undone' );
        $this->assertNull( $r['undo_batch'], 'no undo batch for an undo that restored nothing' );
    }

    public function testForceOverridesOnlyTheChangedSinceRule(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_store']['a'] = 'edited';
        $r                            = $this->undo->undo( $id, true );
        $this->assertSame( 'restored', $r['items'][0]['result'] );
        $this->assertSame( 'one', $GLOBALS['__fake_store']['a'] );
    }

    public function testForceNeverInventsAnUndoPoint(): void
    {
        $id = $this->change( 'a', 'two', [ 'available' => false, 'reason' => 'too_large' ] );
        $r  = $this->undo->undo( $id, true );
        $this->assertSame( 'no_undo_point', $r['items'][0]['result'] );
        $this->assertSame( 'too_large', $r['items'][0]['reason'] );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( [], $GLOBALS['__fake_writes'] );
    }

    public function testPartialUndoRestoresWhatItCanAndSaysSo(): void
    {
        $GLOBALS['__fake_store'] = [ 'a' => 'a1', 'b' => 'b1' ];
        $batch                   = $this->log->record( 'multi', 'two things', [
            Item::make( 'fake', 'a', 'A', 'a1', 'a2' ),
            Item::make( 'fake', 'b', 'B', 'b1', 'b2', [ 'available' => false, 'reason' => 'missing' ] ),
        ] );
        $GLOBALS['__fake_store'] = [ 'a' => 'a2', 'b' => 'b2' ];
        $r                       = $this->undo->undo( $batch['id'] );
        $this->assertSame( 1, $r['restored'] );
        $this->assertFalse( $r['fully_undone'], 'only claims fully undone when every item restored' );
        $this->assertSame( [ 'restored', 'no_undo_point' ], array_column( $r['items'], 'result' ), 'reported in the batch\'s own order' );
        $this->assertSame( 'partly_undone', $this->repo->rows[ $batch['id'] ]['status'] );
    }

    public function testItemsAreRestoredLastFirst(): void
    {
        $GLOBALS['__fake_store'] = [ 'a' => 'a1', 'b' => 'b1' ];
        $batch                   = $this->log->record( 'multi', 'two things', [ Item::make( 'fake', 'a', 'A', 'a1', 'a2' ), Item::make( 'fake', 'b', 'B', 'b1', 'b2' ) ] );
        $GLOBALS['__fake_store'] = [ 'a' => 'a2', 'b' => 'b2' ];
        $this->undo->undo( $batch['id'] );
        $this->assertSame( [ 'b', 'a' ], $GLOBALS['__fake_writes'] );
    }

    public function testAnAlreadyUndoneBatchIsReportedAndNothingIsWrittenOrRecordedAgain(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $this->undo->undo( $id );
        $rowsBefore = count( $this->repo->rows );
        $r          = $this->undo->undo( $id );
        $this->assertSame( 'already_undone', $r['items'][0]['result'] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( 0, $r['restored'] );
        $this->assertCount( $rowsBefore, $this->repo->rows );
    }

    public function testAnItemTheUserMayNotTouchIsSkipped(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_allowed']    = false;
        $r                            = $this->undo->undo( $id );
        $this->assertSame( 'forbidden', $r['items'][0]['result'] );
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'] );
    }

    public function testAnUnknownKindAndAFailedWriteAreReportedNotSwallowed(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $this->repo->rows[ $id ]['items'][0]['kind'] = 'alien';
        $this->assertSame( 'unknown_kind', $this->undo->undo( $id )['items'][0]['result'] );

        $this->repo->rows[ $id ]['items'][0]['kind'] = 'fake';
        $GLOBALS['__fake_fail']                      = true;
        $r                                           = $this->undo->undo( $id );
        $this->assertSame( 'failed', $r['items'][0]['result'] );
        $this->assertSame( 'applied', $this->repo->rows[ $id ]['items'][0]['state'], 'a failed restore leaves the item undoable' );
    }

    public function testAKindThatThrowsIsReportedAsFailed(): void
    {
        $thrower = new class() extends \AiEditorDivi5\Pro\Batch\ValueKind {
            public function name(): string { return 'fake'; }
            public function current( array $item ): mixed { return 'two'; }
            public function allowed( array $item ): bool { return true; }
            protected function write( array $item, mixed $value ): bool { throw new \RuntimeException( 'secret path /var/www' ); }
        };
        $undo  = new BatchUndo( [ 'fake' => $thrower ], $this->log );
        $batch = $this->log->record( 't', 's', [ Item::make( 'fake', 'a', 'A', 'one', 'two' ) ] );
        $r     = $undo->undo( $batch['id'] );
        $this->assertSame( 'failed', $r['items'][0]['result'] );
        $this->assertStringNotContainsString( 'secret', (string) json_encode( $r ) );
    }

    public function testDryRunWritesAndRecordsNothing(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $rows                         = count( $this->repo->rows );
        $r                            = $this->undo->undo( $id, false, true );
        $this->assertTrue( $r['dry_run'] );
        $this->assertSame( 'would_restore', $r['items'][0]['result'] );
        $this->assertSame( 1, $r['would_restore'] );
        $this->assertSame( 0, $r['restored'] );
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'] );
        $this->assertCount( $rows, $this->repo->rows );
        $this->assertSame( 'applied', $this->repo->rows[ $id ]['status'] );
    }

    public function testAnUnknownBatchIsNotFound(): void
    {
        $r = $this->undo->undo( 99 );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'not_found', $r['error'] );
    }

    public function testUndoingTheOldestBatchStillSucceedsWhenRecordingTheUndoTrimsIt(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $first                        = $this->change( 'a', 'two' );
        for ( $i = 0; $i < 19; $i++ ) {
            $this->log->record( 't', 'filler', [ Item::make( 'fake', 'z', 'Z', 1, 2 ) ] );
        }
        $r = $this->undo->undo( $first );
        $this->assertSame( 'one', $GLOBALS['__fake_store']['a'] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertNotNull( $r['undo_batch'] );
    }
}
