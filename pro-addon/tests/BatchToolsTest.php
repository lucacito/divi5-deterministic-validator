<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Batches;
use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\ListBatches;
use AiEditorDivi5\Pro\Tools\UndoBatch;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/FakeKind.php';

class BatchToolsTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private ListBatches $list;
    private UndoBatch $undo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->list = new ListBatches( $this->log );
        $this->undo = new UndoBatch( $this->log, new BatchUndo( [ 'fake' => new FakeKind() ] + Batches::kinds(), $this->log ) );
        $GLOBALS['__fake_store']   = [];
        $GLOBALS['__fake_allowed'] = true;
        $GLOBALS['__fake_fail']    = false;
        $GLOBALS['__fake_writes']  = [];
        $GLOBALS['__wp_can']       = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    /** Records a change of the fake site as user $uid (the shim's current user is 7). */
    private function change( string $ref, mixed $new, int $uid = 7 ): int
    {
        $old                             = $GLOBALS['__fake_store'][ $ref ] ?? null;
        $GLOBALS['__fake_store'][ $ref ] = $new;
        $b                               = $this->log->record( 'fake_tool', "change {$ref}", [ Item::make( 'fake', $ref, "Thing {$ref}", $old, $new ) ] );
        $this->repo->rows[ $b['id'] ]['user_id'] = $uid;

        return $b['id'];
    }

    // ---- list_batches ----------------------------------------------------------------------

    public function testItListsRecentBatchesNewestFirstWithWhatEachChanged(): void
    {
        $this->change( 'a', 1 );
        $this->change( 'b', 2 );
        $r = $this->list->run( [] );
        $this->assertSame( [ 2, 1 ], array_column( $r['batches'], 'id' ) );
        $first = $r['batches'][0];
        $this->assertSame( 'fake_tool', $first['tool'] );
        $this->assertSame( 'applied', $first['status'] );
        $this->assertTrue( $first['undoable'] );
        $this->assertSame( [ [ 'kind' => 'fake', 'label' => 'Thing b', 'state' => 'applied', 'undo_available' => true ] ], $first['changes'] );
        $this->assertArrayNotHasKey( 'before', $first['changes'][0], 'stored previous values are never listed' );
    }

    public function testTheLimitIsStrictAndBounded(): void
    {
        for ( $i = 0; $i < 5; $i++ ) {
            $this->change( "k{$i}", $i );
        }
        $this->assertCount( 2, $this->list->run( [ 'limit' => 2 ] )['batches'] );
        foreach ( [ '2', 0, 21, 2.5 ] as $bad ) {
            try {
                $this->list->run( [ 'limit' => $bad ] );
                $this->fail( 'accepted limit ' . json_encode( $bad ) );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( '"limit"', $e->getMessage() );
            }
        }
    }

    public function testAnItemWithNoUndoPointSaysWhy(): void
    {
        $b = $this->log->record( 't', 's', [ Item::make( 'fake', 'a', 'A', 1, 2, [ 'available' => false, 'reason' => 'too_large' ] ) ] );
        $c = $this->list->run( [] )['batches'][0]['changes'][0];
        $this->assertFalse( $c['undo_available'] );
        $this->assertSame( 'too_large', $c['undo_reason'] );
        $this->assertFalse( $this->list->run( [] )['batches'][0]['undoable'] );
        $this->assertSame( 1, $b['id'] );
    }

    public function testANonAdministratorSeesOnlyTheirOwnBatches(): void
    {
        $mine   = $this->change( 'a', 1, 7 );
        $theirs = $this->change( 'b', 2, 99 );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $this->assertSame( [ $mine ], array_column( $this->list->run( [] )['batches'], 'id' ) );
        unset( $GLOBALS['__wp_caps'] );
        $this->assertSame( [ $theirs, $mine ], array_column( $this->list->run( [] )['batches'], 'id' ), 'an administrator sees all' );
    }

    // ---- undo_batch ------------------------------------------------------------------------

    public function testItUndoesABatchAndSaysSo(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $r                            = $this->undo->run( [ 'batch_id' => $id ] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( 'one', $GLOBALS['__fake_store']['a'] );
        $this->assertStringContainsString( 'undone', $r['message'] );
        $this->assertNotNull( $r['undo_batch'] );
    }

    public function testItNeverCallsAPartialUndoFullyUndone(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_store']['a'] = 'edited since';
        $r                            = $this->undo->run( [ 'batch_id' => $id ] );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'changed_since', $r['items'][0]['result'] );
        $this->assertStringContainsString( 'NOT', $r['message'] );
        $this->assertStringContainsString( 'changed_since', $r['message'] );
        $this->assertSame( 'edited since', $GLOBALS['__fake_store']['a'] );
    }

    public function testThePartialUndoMessageExplainsWhyAnItemWasSkipped(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_fail']       = true;
        $r                            = $this->undo->run( [ 'batch_id' => $id ] );
        $this->assertSame( 'failed', $r['items'][0]['result'] );
        $this->assertStringContainsString( 'failed: write_failed', $r['message'], 'the reason is part of the message' );
    }

    public function testForceOverridesTheChangedSinceRule(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_store']['a'] = 'edited since';
        $r                            = $this->undo->run( [ 'batch_id' => $id, 'force' => true ] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( 'one', $GLOBALS['__fake_store']['a'] );
    }

    public function testADryRunWritesNothing(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $r                            = $this->undo->run( [ 'batch_id' => $id, 'dry_run' => true ] );
        $this->assertSame( 'would_restore', $r['items'][0]['result'] );
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'] );
    }

    public function testTheArgumentsAreTypeChecked(): void
    {
        foreach ( [ [ [], '"batch_id" is required.' ], [ [ 'batch_id' => '1' ], '"batch_id" must be an integer.' ], [ [ 'batch_id' => 1, 'force' => 'yes' ], '"force" must be true or false.' ], [ [ 'batch_id' => 1, 'dry_run' => 1 ], '"dry_run" must be true or false.' ] ] as [ $args, $message ] ) {
            try {
                $this->undo->run( $args );
                $this->fail( 'expected: ' . $message );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertSame( $message, $e->getMessage() );
            }
        }
    }

    public function testAnUnknownBatchIsNotFound(): void
    {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Batch 99 not found.' );
        $this->undo->run( [ 'batch_id' => 99 ] );
    }

    public function testAnotherUsersBatchLooksNotFoundToANonAdministrator(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two', 99 );
        $GLOBALS['__wp_caps']         = [ 'edit_pages' => true ];
        try {
            $this->undo->run( [ 'batch_id' => $id ] );
            $this->fail( 'must not reveal or undo another user\'s batch' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertSame( "Batch {$id} not found.", $e->getMessage() );
        }
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'] );

        unset( $GLOBALS['__wp_caps'] );
        $this->assertTrue( $this->undo->run( [ 'batch_id' => $id ] )['fully_undone'], 'an administrator may undo it' );
    }

    public function testThePerItemPermissionIsRecheckedAtUndoTime(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_allowed']    = false;
        $r                            = $this->undo->run( [ 'batch_id' => $id ] );
        $this->assertSame( 'forbidden', $r['items'][0]['result'] );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'] );
    }

    public function testBothToolsAreRegisteredAndNeedEditPages(): void
    {
        $names = array_map( static fn ( $t ) => $t->name(), Tools::all() );
        $this->assertContains( 'list_batches', $names );
        $this->assertContains( 'undo_batch', $names );
        foreach ( [ $this->list, $this->undo ] as $tool ) {
            $this->assertSame( 'edit_pages', $tool->capability() );
            $this->assertLessThanOrEqual( 300, strlen( $tool->definition()['description'] ) );
        }
        $this->assertSame( [ 'batch_id' ], $this->undo->definition()['inputSchema']['required'] );
        $GLOBALS['__wp_caps'] = [ 'read' => true ];
        $registry             = new ToolRegistry( [ $this->list, $this->undo ], static fn (): bool => true );
        $this->assertSame( -32602, ( (array) $registry->claim( null, 'list_batches', [], 1 )->get_data() )['error']['code'] );
    }
}
