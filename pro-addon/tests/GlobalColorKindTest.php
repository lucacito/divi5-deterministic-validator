<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\GlobalColorKind;
use AiEditorDivi5\Pro\Settings\GlobalColors;
use AiEditorDivi5\Pro\Settings\GlobalColorsLock;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalColorsFixture.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';

class GlobalColorKindTest extends TestCase
{
    private const A = GlobalColorsFixture::A;
    private const B = GlobalColorsFixture::B;

    private InMemoryBatchRepository $repo;
    private BatchLog $log;

    protected function setUp(): void
    {
        GlobalColorsFixture::reset();
        $GLOBALS['__wp_options'] = [];
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
    }

    protected function tearDown(): void
    {
        GlobalColorsFixture::clear();
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ GlobalColorKind::NAME => new GlobalColorKind() ], $this->log );
    }

    /** Change fields of one color the way the tool does (full map, one write) and record the batch. @param array<string,string> $fields */
    private function change( string $id, array $fields ): int
    {
        $all    = (array) GlobalColors::read();
        $before = GlobalColors::state( $all[ $id ] ?? null );
        $map    = GlobalColors::customMap( $all );
        if ( GlobalColors::isThemeId( $id ) ) {
            $map[ $id ] = GlobalColors::themeEntry( $fields['color'], (string) $before['label'], '2026-10-05T10:00:00.000Z' );
        } else {
            $map[ $id ] = array_merge( $map[ $id ] ?? [ 'color' => '#000000', 'folder' => '', 'label' => 'x', 'status' => 'active', 'usedInPosts' => [] ], $fields, [ 'lastUpdated' => '2026-10-05T10:00:00.000Z' ] );
        }
        $res   = GlobalColors::write( $map );
        $after = GlobalColors::state( $res['after'][ $id ] ?? null );
        $batch = $this->log->record( 'manage_global_colors', 'test', [ GlobalColorKind::itemFor( $id, $before, $after ) ] );

        return (int) $batch['id'];
    }

    public function testItemForRecordsTheFieldsAChangeTouchedAndHashesTheAfterState(): void
    {
        $before = GlobalColors::state( GlobalColorsFixture::custom()[ self::A ] );
        $after  = [ 'color' => '#abcdef', 'label' => 'Brand', 'status' => 'inactive', 'folder' => '' ];
        $item   = GlobalColorKind::itemFor( self::A, $before, $after );
        $this->assertSame( 'global_color', $item['kind'] );
        $this->assertSame( self::A, $item['ref'] );
        $this->assertSame( $before + [ '_fields' => [ 'color', 'status' ] ], $item['before'] );
        $this->assertSame( Canon::hash( $after ), $item['after_hash'] );
        $this->assertTrue( $item['undo']['available'] );

        $create = GlobalColorKind::itemFor( 'gcid-new-1', null, $after );
        $this->assertNull( $create['before'] );
        $this->assertStringContainsString( 'gcid-new-1', $create['label'] );
    }

    public function testCurrentIsTheStateOrNull(): void
    {
        $kind = new GlobalColorKind();
        $this->assertSame( GlobalColors::state( GlobalColorsFixture::custom()[ self::B ] ), $kind->current( [ 'ref' => self::B ] ) );
        $this->assertNull( $kind->current( [ 'ref' => 'gcid-nothing-0000' ] ) );
        $GLOBALS['__divi_gc']['throw_get'] = true;
        $this->expectException( \RuntimeException::class );
        $kind->current( [ 'ref' => self::B ] );
    }

    public function testAllowedNeedsEditThemeOptions(): void
    {
        $kind = new GlobalColorKind();
        $this->assertTrue( $kind->allowed( [ 'ref' => self::A ] ) );
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $this->assertFalse( $kind->allowed( [ 'ref' => self::A ] ) );
    }

    public function testUndoOfARecolorPutsTheColorBackAndKeepsEverythingElse(): void
    {
        $id = $this->change( self::A, [ 'color' => '#abcdef' ] );
        $r  = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $now = GlobalColors::read()[ self::A ];
        $this->assertSame( '#112233', $now['color'] );
        $this->assertSame( [ '12', '15' ], $now['usedInPosts'] );
        $this->assertSame( GlobalColors::state( GlobalColorsFixture::custom()[ self::B ] ), GlobalColors::state( GlobalColors::read()[ self::B ] ), 'the other color is untouched' );
        $this->assertCount( 2, $GLOBALS['__divi_gc']['set_calls'], 'the change and its undo: one complete write each' );
        $this->assertSame( [ false, false ], $GLOBALS['__divi_gc']['set_flags'] );
    }

    public function testAColorChangedSinceIsNotOverwrittenUnlessForcedAndForceOnlyRevertsWhatTheBatchChanged(): void
    {
        $id = $this->change( self::A, [ 'color' => '#abcdef' ] );
        $this->change( self::A, [ 'label' => 'Renamed later' ] );

        $r = $this->undo()->undo( $id );
        $this->assertSame( 'changed_since', $r['items'][0]['result'] );
        $this->assertSame( '#abcdef', GlobalColors::read()[ self::A ]['color'] );

        $r = $this->undo()->undo( $id, true );
        $this->assertSame( 'restored', $r['items'][0]['result'] );
        $now = GlobalColors::read()[ self::A ];
        $this->assertSame( '#112233', $now['color'], 'the color the batch changed is back' );
        $this->assertSame( 'Renamed later', $now['label'], 'the later rename is kept' );
    }

    public function testUndoOfACreateRemovesTheEntryAndTheUndoIsItselfUndoable(): void
    {
        $id = $this->change( 'gcid-new-0001', [ 'color' => '#00aaff', 'label' => 'Sky' ] );
        $this->assertArrayHasKey( 'gcid-new-0001', (array) GlobalColors::read() );

        $r = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertArrayNotHasKey( 'gcid-new-0001', (array) GlobalColors::read() );
        $this->assertSame( [ self::A, self::B ], array_keys( GlobalColors::customMap( (array) GlobalColors::read() ) ) );

        $again = $this->undo()->undo( (int) $r['undo_batch']['id'] );
        $this->assertTrue( $again['fully_undone'], 'undoing the undo brings the color back' );
        $this->assertSame( 'Sky', GlobalColors::read()['gcid-new-0001']['label'] );
        $this->assertSame( '#00aaff', GlobalColors::read()['gcid-new-0001']['color'] );
    }

    public function testUndoOfACreateRefusesWhenTheEntryChangedSinceEvenWhenForced(): void
    {
        $id = $this->change( 'gcid-new-0001', [ 'color' => '#00aaff', 'label' => 'Sky' ] );
        $this->change( 'gcid-new-0001', [ 'color' => '#ffffff' ] );
        foreach ( [ false, true ] as $force ) {
            $r = $this->undo()->undo( $id, $force );
            $this->assertFalse( $r['fully_undone'] );
            $this->assertArrayHasKey( 'gcid-new-0001', (array) GlobalColors::read(), 'a color someone changed after our write is never removed' );
        }
    }

    public function testAThemeColorUndoWritesTheThemeOptionBack(): void
    {
        $id = $this->change( 'gcid-primary-color', [ 'color' => '#ff0000' ] );
        $this->assertSame( '#ff0000', $GLOBALS['__divi_gc']['options']['accent_color'] );
        $r = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( '#2ea3f2', $GLOBALS['__divi_gc']['options']['accent_color'] );
        $this->assertSame( '#2ea3f2', GlobalColors::read()['gcid-primary-color']['color'] );
    }

    public function testWithoutThePermissionNothingIsRestored(): void
    {
        $id                   = $this->change( self::A, [ 'color' => '#abcdef' ] );
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $r                    = $this->undo()->undo( $id );
        $this->assertSame( 'forbidden', $r['items'][0]['result'] );
        $this->assertSame( '#abcdef', GlobalColors::read()[ self::A ]['color'] );
    }

    public function testWhenDiviCannotBeReadTheItemFailsAndNothingIsClaimed(): void
    {
        $id                                = $this->change( self::A, [ 'color' => '#abcdef' ] );
        $GLOBALS['__divi_gc']['throw_get'] = true;
        $r                                 = $this->undo()->undo( $id );
        $this->assertSame( 'failed', $r['items'][0]['result'] );
        $this->assertFalse( $r['fully_undone'] );
    }

    public function testAnUndoWhileAnotherColorChangeIsInProgressIsBusyAndLeavesEverything(): void
    {
        $id    = $this->change( self::A, [ 'color' => '#abcdef' ] );
        $other = new GlobalColorsLock( 0 );
        $this->assertTrue( $other->acquire() );
        $writes = count( $GLOBALS['__divi_gc']['set_calls'] );

        $r = ( new BatchUndo( [ GlobalColorKind::NAME => new GlobalColorKind( new GlobalColorsLock( 0 ) ) ], $this->log ) )->undo( $id );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'busy', $r['items'][0]['reason'] );
        $this->assertCount( $writes, $GLOBALS['__divi_gc']['set_calls'], 'nothing was written' );
        $this->assertSame( '#abcdef', GlobalColors::read()[ self::A ]['color'] );

        $other->release();
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'], 'the same undo works once the other change is done' );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'the undo released its lock' );
    }

    public function testAnUndoThatWouldMakeDiviDeleteAnotherColorIsRefusedAndWritesNothing(): void
    {
        $id = $this->change( self::A, [ 'color' => '#abcdef' ] );
        $GLOBALS['__divi_gc']['custom']['gcid-empty-0000'] = [ 'color' => '', 'folder' => '', 'label' => 'Junk', 'lastUpdated' => '2026-01-01T00:00:00.000Z', 'status' => 'active', 'usedInPosts' => [] ];
        $GLOBALS['__divi_gc']['cache']                     = null;
        $writes = count( $GLOBALS['__divi_gc']['set_calls'] );

        $r = $this->undo()->undo( $id );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'unsafe_to_save', $r['items'][0]['reason'] );
        $this->assertCount( $writes, $GLOBALS['__divi_gc']['set_calls'], 'Divi was not asked to write anything' );
        $this->assertSame( '#abcdef', GlobalColors::read()[ self::A ]['color'] );
        $this->assertArrayHasKey( 'gcid-empty-0000', (array) GlobalColors::read(), 'the odd entry is still there' );
        $this->assertSame( [], $GLOBALS['__wp_options'] );
    }
}
