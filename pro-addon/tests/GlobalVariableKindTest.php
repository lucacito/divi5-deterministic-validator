<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\SiteLockingKind;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\GlobalVariableKind;
use AiEditorDivi5\Pro\Settings\GlobalVariables;
use AiEditorDivi5\Pro\Settings\GlobalVariablesLock;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalVariablesFixture.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';

class GlobalVariableKindTest extends TestCase
{
    private const N1  = GlobalVariablesFixture::N1;
    private const N2  = GlobalVariablesFixture::N2;
    private const S1  = GlobalVariablesFixture::S1;
    private const IMG = GlobalVariablesFixture::IMG;

    private InMemoryBatchRepository $repo;
    private BatchLog $log;

    protected function setUp(): void
    {
        GlobalVariablesFixture::reset();
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
    }

    protected function tearDown(): void
    {
        GlobalVariablesFixture::clear();
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ GlobalVariableKind::NAME => new GlobalVariableKind( new GlobalVariablesLock( 0 ) ) ], $this->log );
    }

    /** Change fields of one variable the way the tool does (full map, one write) and record the batch. @param array<string,string> $fields */
    private function change( string $id, string $type, array $fields ): int
    {
        $all    = (array) GlobalVariables::read();
        $found  = GlobalVariables::find( $all, $id );
        $before = GlobalVariables::state( $found['entry'] ?? null );
        $entry  = $found['entry'] ?? [ 'label' => 'x', 'value' => '1px', 'order' => (string) GlobalVariables::nextOrder( $all[ $type ] ?? [] ), 'status' => 'active' ];
        $map    = GlobalVariables::sendMap( $all );
        $map[ $type ][ $id ] = array_merge( $entry, $fields );
        $res    = GlobalVariables::write( $map );
        $after  = GlobalVariables::state( $res['after'][ $type ][ $id ] ?? null );
        $batch  = $this->log->record( 'manage_global_variables', 'test', [ GlobalVariableKind::itemFor( $id, $type, $before, $after ) ] );

        return (int) $batch['id'];
    }

    public function testItIsASiteLockingKind(): void
    {
        $this->assertInstanceOf( SiteLockingKind::class, new GlobalVariableKind() );
        $this->assertSame( 'global_variable', ( new GlobalVariableKind() )->name() );
    }

    public function testItemForRecordsTheChangedFieldsTheTypeAndHashesTheAfterState(): void
    {
        $before = [ 'label' => 'Radius', 'value' => '12px', 'status' => 'active' ];
        $after  = [ 'label' => 'Radius', 'value' => '16px', 'status' => 'archived' ];
        $item   = GlobalVariableKind::itemFor( self::N1, 'numbers', $before, $after );
        $this->assertSame( 'global_variable', $item['kind'] );
        $this->assertSame( self::N1, $item['ref'] );
        $this->assertSame( $before + [ '_fields' => [ 'value', 'status' ], '_type' => 'numbers' ], $item['before'] );
        $this->assertSame( Canon::hash( $after ), $item['after_hash'] );
        $this->assertTrue( $item['undo']['available'] );

        $create = GlobalVariableKind::itemFor( 'gvid-new-0001', 'strings', null, $after );
        $this->assertNull( $create['before'] );
        $this->assertStringContainsString( 'gvid-new-0001', $create['label'] );
        $this->assertStringContainsString( 'strings', $create['label'] );
    }

    public function testCurrentIsTheStateOrNull(): void
    {
        $kind = new GlobalVariableKind();
        $this->assertSame( [ 'label' => 'Phone', 'value' => '555 0100', 'status' => 'active' ], $kind->current( [ 'ref' => self::S1 ] ) );
        $this->assertNull( $kind->current( [ 'ref' => 'gvid-nothing-0000' ] ) );
        $GLOBALS['__divi_gv']['throw_get'] = true;
        $this->expectException( \RuntimeException::class );
        $kind->current( [ 'ref' => self::S1 ] );
    }

    public function testAllowedNeedsTheCapabilityAndTheVariablesManagerRole(): void
    {
        $kind = new GlobalVariableKind();
        $this->assertTrue( $kind->allowed( [ 'ref' => self::N1 ] ) );
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $this->assertFalse( $kind->allowed( [ 'ref' => self::N1 ] ) );
        GlobalVariablesFixture::reset();
        $GLOBALS['__divi_role_allowed'] = false;
        $this->assertFalse( $kind->allowed( [ 'ref' => self::N1 ] ) );
    }

    public function testUndoOfAnEditPutsTheValueBackAndLeavesEverythingElseIdentical(): void
    {
        $startingPoint = GlobalVariables::read();
        $id            = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $r             = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $now = GlobalVariables::read();
        $this->assertSame( [ 'id' => self::N1, 'variableType' => 'numbers' ], array_intersect_key( $now['numbers'][ self::N1 ], [ 'id' => 1, 'variableType' => 1 ] ), 'the restored entry is repaired to the builder shape' );
        $this->assertNotSame( '', (string) $now['numbers'][ self::N1 ]['lastUpdated'] );
        unset( $now['numbers'][ self::N1 ]['id'], $now['numbers'][ self::N1 ]['variableType'], $now['numbers'][ self::N1 ]['lastUpdated'] );
        $this->assertSame( $startingPoint, $now, 'every group, including the unmanaged images, is identical to before (apart from the three keys undo adds to the entry it restores)' );
        $this->assertCount( 2, $GLOBALS['__divi_gv']['set_calls'], 'the change and its undo: one complete write each' );
        $this->assertArrayNotHasKey( 'colors', $GLOBALS['__divi_gv']['set_calls'][1], 'no empty group and no customizer font is handed to Divi' );
        $this->assertSame( [ 'numbers', 'strings', 'images' ], array_keys( $GLOBALS['__divi_gv']['set_calls'][1] ) );
    }

    public function testAVariableChangedSinceIsNotOverwrittenUnlessForcedAndForceOnlyRevertsWhatTheBatchChanged(): void
    {
        $id = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $this->change( self::N1, 'numbers', [ 'label' => 'Renamed later' ] );

        $r = $this->undo()->undo( $id );
        $this->assertSame( 'changed_since', $r['items'][0]['result'] );
        $this->assertSame( '99px', GlobalVariables::read()['numbers'][ self::N1 ]['value'] );

        $r = $this->undo()->undo( $id, true );
        $this->assertSame( 'restored', $r['items'][0]['result'] );
        $now = GlobalVariables::read()['numbers'][ self::N1 ];
        $this->assertSame( '12px', $now['value'], 'the value the batch changed is back' );
        $this->assertSame( 'Renamed later', $now['label'], 'the later rename is kept' );
    }

    private const OLD = '2020-01-01T00:00:00.000Z';

    public function testUndoOfAnEditRefreshesLastUpdatedAndAddsTheIdAndTypeAnOlderWriteLacked(): void
    {
        $id = $this->change( self::N1, 'numbers', [ 'value' => '99px', 'lastUpdated' => self::OLD ] );
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'] );
        $e = GlobalVariables::read()['numbers'][ self::N1 ];
        $this->assertSame( '12px', $e['value'] );
        $this->assertSame( self::N1, $e['id'] );
        $this->assertSame( 'numbers', $e['variableType'] );
        $this->assertNotSame( self::OLD, $e['lastUpdated'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/', $e['lastUpdated'] );
        $this->assertGreaterThanOrEqual( strtotime( '2026-01-01T00:00:00Z' ), strtotime( $e['lastUpdated'] ), 'the stamp of now' );
    }

    public function testUndoOfAnEditKeepsADifferentStoredIdAndTheTypeItAlreadyHas(): void
    {
        $id = $this->change( self::S1, 'strings', [ 'value' => 'changed', 'id' => 'gvid-other', 'variableType' => 'strings', 'lastUpdated' => self::OLD ] );
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'] );
        $e = GlobalVariables::read()['strings'][ self::S1 ];
        $this->assertSame( 'gvid-other', $e['id'], 'an existing id is never overwritten' );
        $this->assertSame( 'strings', $e['variableType'] );
        $this->assertSame( '555 0100', $e['value'] );
        $this->assertNotSame( self::OLD, $e['lastUpdated'] );
    }

    public function testUndoOfAnArchiveRestoresTheStatus(): void
    {
        $id = $this->change( self::S1, 'strings', [ 'status' => 'archived' ] );
        $this->assertSame( 'archived', GlobalVariables::read()['strings'][ self::S1 ]['status'] );
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'] );
        $this->assertSame( 'active', GlobalVariables::read()['strings'][ self::S1 ]['status'] );
    }

    /** @return array<string,array{bool}> Divi 5.14 replaced the whole map on save; 5.15.1 refuses a save that omits an existing id. */
    public static function diviSaveRules(): array
    {
        return [ 'Divi 5.14 (replace-all save)' => [ false ], 'Divi 5.15.1 (a save may not omit an id)' => [ true ] ];
    }

    /** @dataProvider diviSaveRules */
    public function testUndoOfACreateArchivesTheEntryAndTheUndoIsItselfUndoable( bool $strict ): void
    {
        $GLOBALS['__divi_gv']['strict_ids'] = $strict;
        $id = $this->change( 'gvid-new-0001', 'numbers', [ 'value' => '7px', 'label' => 'Seven' ] );
        $this->assertArrayHasKey( 'gvid-new-0001', GlobalVariables::read()['numbers'] );

        $r = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'], 'the undo completes on every Divi version' );
        $gone = GlobalVariables::read()['numbers']['gvid-new-0001'];
        $this->assertSame( 'archived', $gone['status'], "Divi's own delete is archive: nothing is removed from the stored list" );
        $this->assertSame( [ 'Seven', '7px' ], [ $gone['label'], $gone['value'] ], 'label and value are kept' );
        $this->assertSame( '3', $gone['order'], 'and so is its place' );
        $this->assertSame( [ self::N1, self::N2, 'gvid-new-0001' ], array_keys( GlobalVariables::read()['numbers'] ) );

        $again = $this->undo()->undo( (int) $r['undo_batch']['id'] );
        $this->assertTrue( $again['fully_undone'], 'undoing the undo makes it active again' );
        $back = GlobalVariables::read()['numbers']['gvid-new-0001'];
        $this->assertSame( [ 'Seven', '7px', 'active' ], [ $back['label'], $back['value'], $back['status'] ] );
        $this->assertSame( '3', $back['order'] );
        $this->assertSame( 'gvid-new-0001', $back['id'], 'carries the builder shape' );
        $this->assertSame( 'numbers', $back['variableType'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/', $back['lastUpdated'] );
    }

    public function testUndoOfACreateNeverOmitsAnIdSoDivi515AcceptsEveryWriteOfAnUndo(): void
    {
        $GLOBALS['__divi_gv']['strict_ids'] = true;
        $id = $this->change( 'gvid-new-0001', 'numbers', [ 'value' => '7px', 'label' => 'Seven' ] );
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'] );
        foreach ( $GLOBALS['__divi_gv']['set_calls'] as $i => $call ) {
            foreach ( [ 'numbers' => [ self::N1, self::N2 ], 'strings' => [ self::S1 ], 'images' => [ self::IMG ] ] as $type => $ids ) {
                foreach ( $ids as $existing ) {
                    $this->assertArrayHasKey( $existing, $call[ $type ], "write {$i} keeps {$existing}" );
                }
            }
        }
    }

    public function testWhenDiviRefusesTheSaveTheUndoFailsAndSaysWhy(): void
    {
        $id = $this->change( 'gvid-new-0001', 'numbers', [ 'value' => '7px', 'label' => 'Seven' ] );
        $GLOBALS['__divi_gv']['strict_ids'] = true;
        $GLOBALS['__divi_gv']['mutate']     = static fn ( array $d ): array => $d; // keeps the shim from changing anything
        $GLOBALS['__divi_gv']['refuse']     = new \WP_Error( 'global_variable_invalid', 'Divi says no.' );
        $r = $this->undo()->undo( $id );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'divi_refused', $r['items'][0]['reason'] );
        $this->assertSame( 'Divi says no.', $r['items'][0]['detail'] ?? null, 'Divi\'s own message is passed on' );
    }

    public function testForcedUndoOfAnEditWhoseVariableWasRemovedMeanwhileRecreatesItAtTheNextFreeOrder(): void
    {
        $id  = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $all = (array) GlobalVariables::read();
        unset( $all['numbers'][ self::N1 ] );
        GlobalVariables::write( GlobalVariables::sendMap( $all ) ); // someone removed it in Divi after our edit
        $this->assertArrayNotHasKey( self::N1, GlobalVariables::read()['numbers'] );

        $r = $this->undo()->undo( $id, true );
        $this->assertSame( 'restored', $r['items'][0]['result'] );
        $back = GlobalVariables::read()['numbers'][ self::N1 ];
        $this->assertSame( '12px', $back['value'] );
        $this->assertSame( '3', $back['order'], 'highest existing order (2) + 1, not 1, which would collide with another variable' );
        $this->assertSame( self::N1, $back['id'] );
        $this->assertSame( 'numbers', $back['variableType'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.000Z$/', $back['lastUpdated'] );
        $this->assertSame( [ 'id', 'label', 'value', 'order', 'status', 'lastUpdated', 'variableType' ], array_keys( $back ), 'same seven keys, same order as a create' );
    }

    public function testUndoOfACreateRefusesWhenTheEntryChangedSinceEvenWhenForced(): void
    {
        $id = $this->change( 'gvid-new-0001', 'numbers', [ 'value' => '7px', 'label' => 'Seven' ] );
        $this->change( 'gvid-new-0001', 'numbers', [ 'value' => '8px' ] );
        foreach ( [ false, true ] as $force ) {
            $r = $this->undo()->undo( $id, $force );
            $this->assertFalse( $r['fully_undone'] );
            $this->assertArrayHasKey( 'gvid-new-0001', GlobalVariables::read()['numbers'], 'a variable someone changed after our write is never removed' );
        }
    }

    public function testWithoutThePermissionNothingIsRestored(): void
    {
        $id                             = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $GLOBALS['__divi_role_allowed'] = false;
        $r                              = $this->undo()->undo( $id );
        $this->assertSame( 'forbidden', $r['items'][0]['result'] );
        $this->assertSame( '99px', GlobalVariables::read()['numbers'][ self::N1 ]['value'] );
    }

    public function testWhenDiviCannotBeReadTheItemFailsAndNothingIsClaimed(): void
    {
        $id                                = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $GLOBALS['__divi_gv']['throw_get'] = true;
        $r                                 = $this->undo()->undo( $id );
        $this->assertSame( 'failed', $r['items'][0]['result'] );
        $this->assertFalse( $r['fully_undone'] );
    }

    public function testAnUndoHoldsTheSiteLockFromBeforeItsFirstReadAndReleasesIt(): void
    {
        $id   = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $seen = [];
        $GLOBALS['__divi_gv']['gets']   = 0;
        $GLOBALS['__divi_gv']['on_get'] = static function ( int $n ) use ( &$seen ): void {
            $seen[ $n ] = count( $GLOBALS['__wp_options'] );
        };
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'] );
        $this->assertSame( [ 1 ], array_values( array_unique( $seen ) ), 'the lock row exists at every read of the undo, the first included' );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'released at the end' );
    }

    public function testAnUndoWhileAnotherVariableChangeIsInProgressIsBusyAndLeavesEverything(): void
    {
        $id    = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $other = new GlobalVariablesLock( 0 );
        $this->assertTrue( $other->acquire() );
        $writes = count( $GLOBALS['__divi_gv']['set_calls'] );

        $r = $this->undo()->undo( $id );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'busy', $r['items'][0]['reason'] );
        $this->assertCount( $writes, $GLOBALS['__divi_gv']['set_calls'], 'nothing was written' );

        $other->release();
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'], 'the same undo works once the other change is done' );
    }

    public function testAnUndoThatWouldMakeDiviEraseAColorsGroupIsRefusedAndWritesNothing(): void
    {
        $id = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $GLOBALS['__divi_gv']['stored']['colors'] = [ 'gvid-cccccccc-0000-4000-8000-000000000001' => [ 'label' => 'Brand', 'value' => '#fff', 'order' => '1', 'status' => 'active' ] ];
        $GLOBALS['__divi_gv']['cache']            = null;
        $writes                                   = count( $GLOBALS['__divi_gv']['set_calls'] );

        $r = $this->undo()->undo( $id );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'unsafe_to_save', $r['items'][0]['reason'] );
        $this->assertCount( $writes, $GLOBALS['__divi_gv']['set_calls'], 'Divi was not asked to write anything' );
        $this->assertArrayHasKey( 'gvid-cccccccc-0000-4000-8000-000000000001', GlobalVariables::read()['colors'], 'the colors group is still there' );
    }

    public function testADiviThatKeepsADifferentStateThanRequestedIsReportedAsRefused(): void
    {
        $id                             = $this->change( self::N1, 'numbers', [ 'value' => '99px' ] );
        $GLOBALS['__divi_gv']['mutate'] = static function ( array $data ): array {
            $data['numbers'][ self::N1 ]['value'] = 'something else';

            return $data;
        };
        $r = $this->undo()->undo( $id );
        $this->assertSame( 'divi_refused', $r['items'][0]['reason'] );
    }
}
