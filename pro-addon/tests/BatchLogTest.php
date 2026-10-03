<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class BatchLogTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
    }

    /** @return array<string,mixed> */
    private function item( bool $available = true, string $state = 'applied' ): array
    {
        return [ 'kind' => 'fake', 'ref' => 'a', 'label' => 'A', 'before' => 1, 'after_hash' => 'h', 'undo' => [ 'available' => $available ], 'state' => $state ];
    }

    public function testRecordStoresTheBatchAndReturnsItsDescription(): void
    {
        $d = $this->log->record( 'set_front_page', 'Set front page', [ $this->item(), $this->item( false ) ] );
        $this->assertSame( 1, $d['id'] );
        $this->assertSame( 'set_front_page', $d['tool'] );
        $this->assertSame( 'applied', $d['status'] );
        $this->assertSame( 2, $d['items'] );
        $this->assertSame( 1, $d['undo_points'], 'only an item with an undo point counts' );
        $this->assertTrue( $d['undoable'] );

        $row = $this->repo->rows[1];
        $this->assertSame( 7, $row['user_id'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['created_at'] );
        $this->assertSame( 'applied', $row['status'] );
    }

    public function testABatchWithNoUndoPointIsNotUndoable(): void
    {
        $d = $this->log->record( 't', 's', [ $this->item( false ) ] );
        $this->assertSame( 0, $d['undo_points'] );
        $this->assertFalse( $d['undoable'] );
    }

    public function testAnEmptyBatchIsNeverStored(): void
    {
        $this->assertNull( $this->log->record( 't', 's', [] ) );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testAFailedStoreReturnsNull(): void
    {
        $this->repo->failInsert = true;
        $this->assertNull( $this->log->record( 't', 's', [ $this->item() ] ) );
    }

    public function testOnlyTheLast20BatchesAreKept(): void
    {
        for ( $i = 1; $i <= 25; $i++ ) {
            $this->log->record( 't', "batch {$i}", [ $this->item() ] );
        }
        $this->assertCount( 20, $this->repo->rows );
        $this->assertSame( 6, array_key_first( $this->repo->rows ) );
        $this->assertSame( 25, array_key_last( $this->repo->rows ) );
    }

    public function testALongSummaryIsCutTo255Characters(): void
    {
        $this->log->record( 't', str_repeat( 'x', 400 ), [ $this->item() ] );
        $this->assertSame( 255, mb_strlen( $this->repo->rows[1]['summary'] ) );
    }

    public function testFindRecentAndMarkUndone(): void
    {
        $this->log->record( 't', 'one', [ $this->item() ] );
        $this->log->record( 't', 'two', [ $this->item() ] );
        $this->assertSame( 'one', $this->log->find( 1 )['summary'] );
        $this->assertNull( $this->log->find( 99 ) );
        $this->assertSame( [ 2, 1 ], array_column( $this->log->recent(), 'id' ), 'newest first' );
        $this->assertSame( [ 2 ], array_column( $this->log->recent( 1 ), 'id' ) );

        $this->assertTrue( $this->log->markUndone( 1, 'undone', [ $this->item( true, 'undone' ) ] ) );
        $this->assertSame( 'undone', $this->log->find( 1 )['status'] );
        $this->assertSame( 'undone', $this->log->find( 1 )['items'][0]['state'] );
        $this->assertFalse( $this->log->markUndone( 99, 'undone', [] ) );
    }

    public function testAnUndoneItemIsNotAnUndoPointAnyMore(): void
    {
        $d = BatchLog::describe( [ 'id' => 1, 'tool' => 't', 'summary' => 's', 'created_at' => 'x', 'status' => 'undone', 'items' => [ $this->item( true, 'undone' ) ] ] );
        $this->assertSame( 0, $d['undo_points'] );
    }

    public function testAttachAddsTheDescribedBatchOrAnHonestNote(): void
    {
        $d = $this->log->record( 't', 's', [ $this->item() ] );
        $this->assertSame( $d, BatchLog::attach( [ 'changed' => true ], $d )['batch'] );
        $out = BatchLog::attach( [ 'changed' => true ], null );
        $this->assertTrue( $out['changed'] );
        $this->assertNull( $out['batch']['id'] );
        $this->assertFalse( $out['batch']['undoable'] );
        $this->assertStringContainsString( 'could not be recorded', $out['batch']['note'] );
    }
}
