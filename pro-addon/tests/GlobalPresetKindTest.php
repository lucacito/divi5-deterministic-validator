<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\SiteLockingKind;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\GlobalPresetKind;
use AiEditorDivi5\Pro\Settings\GlobalPresets;
use AiEditorDivi5\Pro\Settings\GlobalPresetsLock;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalPresetsFixture.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';

class GlobalPresetKindTest extends TestCase
{
    private const H1        = GlobalPresetsFixture::H1;
    private const H_DEFAULT = GlobalPresetsFixture::H_DEFAULT;
    private const NEW       = 'aaaaaaaa-0000-4000-8000-000000000001';

    private InMemoryBatchRepository $repo;
    private BatchLog $log;

    protected function setUp(): void
    {
        GlobalPresetsFixture::reset();
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
    }

    protected function tearDown(): void
    {
        GlobalPresetsFixture::clear();
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ GlobalPresetKind::NAME => new GlobalPresetKind( new GlobalPresetsLock( 0 ) ) ], $this->log );
    }

    private function sized( string $px ): array
    {
        return [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => $px ] ] ] ] ] ] ];
    }

    /** Create a preset the way the tool does (Divi's additive import) and record the batch. */
    private function create( string $module, string $id, string $name, array $attrs ): int
    {
        $before = GlobalPresets::read();
        $existed = [] !== GlobalPresets::itemIds( $before, $module );
        $item   = GlobalPresets::newItem( $module, $id, $name, $attrs, 1790000000 );
        $res    = GlobalPresets::import( $module, $item, GlobalPresets::defaultId( $before, $module ) );
        $auto   = null;
        foreach ( array_diff( GlobalPresets::itemIds( $res['after'], $module ), GlobalPresets::itemIds( $before, $module ), [ $id ] ) as $extra ) {
            $auto = [ 'id' => $extra, 'hash' => Canon::hash( GlobalPresets::state( $res['after']['module'][ $module ]['items'][ $extra ] ) ) ];
        }
        $after = GlobalPresets::state( $res['after']['module'][ $module ]['items'][ $id ] );
        $batch = $this->log->record( 'manage_global_presets', 'test', [ GlobalPresetKind::itemForCreate( $id, $module, $after, $existed, $auto ) ] );

        return (int) $batch['id'];
    }

    /** Update a preset the way the tool does (complete-data save) and record the batch. */
    private function update( string $id, array $attrs, ?string $name = null ): int
    {
        $data  = GlobalPresets::read();
        $found = GlobalPresets::find( $data, $id );
        $item  = $found['item'];
        $item['attrs'] = $item['styleAttrs'] = $attrs;
        if ( null !== $name ) {
            $item['name'] = $name;
        }
        $item['updated'] = 1790000500;
        $data['module'][ $found['module'] ]['items'][ $id ] = $item;
        $res   = GlobalPresets::save( $data );
        $batch = $this->log->record( 'manage_global_presets', 'test', [ GlobalPresetKind::itemForUpdate( $id, $found['module'], (array) GlobalPresets::state( $found['item'] ), (array) GlobalPresets::state( $res['after']['module'][ $found['module'] ]['items'][ $id ] ), $found['item']['styleAttrs'] ?? null ) ] );

        return (int) $batch['id'];
    }

    public function testItIsASiteLockingKindWithAName(): void
    {
        $this->assertInstanceOf( SiteLockingKind::class, new GlobalPresetKind() );
        $this->assertSame( 'global_preset', ( new GlobalPresetKind() )->name() );
    }

    public function testCurrentIsTheStateOrNullAndAllowedNeedsTheCapability(): void
    {
        $kind = new GlobalPresetKind();
        $this->assertSame( 'Heading 1', $kind->current( [ 'ref' => self::H1 ] )['name'] );
        $this->assertNull( $kind->current( [ 'ref' => 'nope-nope-nope' ] ) );
        $this->assertTrue( $kind->allowed( [ 'ref' => self::H1 ] ) );
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $this->assertFalse( $kind->allowed( [ 'ref' => self::H1 ] ) );
        GlobalPresetsFixture::reset();
        $GLOBALS['__divi_gp']['throw_get'] = true;
        $this->expectException( \RuntimeException::class );
        $kind->current( [ 'ref' => self::H1 ] );
    }

    public function testUndoOfAnUpdatePutsNameAndAttrsBackAndNothingElseChanges(): void
    {
        $start = GlobalPresets::read();
        $id    = $this->update( self::H1, $this->sized( '61px' ), 'Heading 1 v2' );
        $r     = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $now = GlobalPresets::read();
        $this->assertSame( $start['module']['divi/heading']['items'][ self::H1 ]['attrs'], $now['module']['divi/heading']['items'][ self::H1 ]['attrs'] );
        $this->assertSame( $start['module']['divi/heading']['items'][ self::H1 ]['styleAttrs'], $now['module']['divi/heading']['items'][ self::H1 ]['styleAttrs'] );
        $this->assertSame( 'Heading 1', $now['module']['divi/heading']['items'][ self::H1 ]['name'] );
        $restored = $now['module']['divi/heading']['items'][ self::H1 ];
        unset( $restored['updated'] );
        $original = $start['module']['divi/heading']['items'][ self::H1 ];
        unset( $original['updated'] );
        $this->assertSame( $original, $restored, 'every other key of the preset is back as it was' );
        unset( $now['module']['divi/heading']['items'][ self::H1 ]['updated'], $start['module']['divi/heading']['items'][ self::H1 ]['updated'] );
        $this->assertSame( $start, $now, 'every other preset, the legacy ones included, is untouched' );
    }

    public function testAnUpdateChangedSinceIsNotOverwrittenUnlessForced(): void
    {
        $id = $this->update( self::H1, $this->sized( '61px' ) );
        $this->update( self::H1, $this->sized( '99px' ) );
        $r = $this->undo()->undo( $id );
        $this->assertSame( 'changed_since', $r['items'][0]['result'] );
        $this->assertSame( '99px', GlobalPresets::read()['module']['divi/heading']['items'][ self::H1 ]['attrs']['title']['decoration']['font']['font']['desktop']['value']['size'] );
        $r = $this->undo()->undo( $id, true );
        $this->assertSame( 'restored', $r['items'][0]['result'] );
        $this->assertSame( '72px', GlobalPresets::read()['module']['divi/heading']['items'][ self::H1 ]['attrs']['title']['decoration']['font']['font']['desktop']['value']['size'] );
    }

    public function testUndoOfACreateInAModuleThatHadPresetsRemovesOnlyThatPreset(): void
    {
        $start = GlobalPresets::read();
        $id    = $this->create( 'divi/heading', self::NEW, 'Big', $this->sized( '57px' ) );
        $this->assertArrayHasKey( self::NEW, GlobalPresets::read()['module']['divi/heading']['items'] );
        $r = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( $start, GlobalPresets::read() );
    }

    public function testUndoOfACreateInAModuleWithNoPresetsAlsoRemovesTheDefaultPresetDiviAddedAndTheModuleEntry(): void
    {
        $start = GlobalPresets::read();
        $id    = $this->create( 'divi/image', self::NEW, 'Rounded', [ 'module' => [ 'x' => '1' ] ] );
        $this->assertCount( 2, GlobalPresets::read()['module']['divi/image']['items'], 'Divi added a default preset' );
        $r = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( $start, GlobalPresets::read(), 'the module entry is gone again' );
    }

    public function testUndoOfThatUndoBringsTheCreatedPresetAndTheDefaultBackWhereTheyWere(): void
    {
        $id    = $this->create( 'divi/image', self::NEW, 'Rounded', [ 'module' => [ 'x' => '1' ] ] );
        $built = GlobalPresets::read();
        $r     = $this->undo()->undo( $id );
        $again = $this->undo()->undo( (int) $r['undo_batch']['id'] );
        $this->assertTrue( $again['fully_undone'] );
        $this->assertSame( $built, GlobalPresets::read(), 'identical, including the auto-created default preset and its id' );
        $third = $this->undo()->undo( (int) $again['undo_batch']['id'] );
        $this->assertTrue( $third['fully_undone'], 'and it can be removed once more' );
        $this->assertArrayNotHasKey( 'divi/image', GlobalPresets::read()['module'] );
    }

    public function testUndoOfACreateRefusesWhenThePresetChangedSinceEvenWhenForced(): void
    {
        $id = $this->create( 'divi/heading', self::NEW, 'Big', $this->sized( '57px' ) );
        $this->update( self::NEW, $this->sized( '58px' ) );
        foreach ( [ false, true ] as $force ) {
            $r = $this->undo()->undo( $id, $force );
            $this->assertFalse( $r['fully_undone'] );
            $this->assertArrayHasKey( self::NEW, GlobalPresets::read()['module']['divi/heading']['items'], 'a preset someone changed after our write is never removed' );
        }
    }

    public function testUndoOfACreateRefusesWhenSomeoneAddedAPresetToTheNewModuleMeanwhile(): void
    {
        $id   = $this->create( 'divi/image', self::NEW, 'Rounded', [ 'module' => [ 'x' => '1' ] ] );
        $data = GlobalPresets::read();
        $data['module']['divi/image']['items']['bbbbbbbb-0000-4000-8000-000000000009'] = GlobalPresets::newItem( 'divi/image', 'bbbbbbbb-0000-4000-8000-000000000009', 'Theirs', [ 'a' => '1' ], 1790000100 );
        GlobalPresets::save( $data );
        $r = $this->undo()->undo( $id );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'changed_since', $r['items'][0]['reason'] ?? $r['items'][0]['result'] );
        $this->assertArrayHasKey( self::NEW, GlobalPresets::read()['module']['divi/image']['items'] );
    }

    public function testWithoutThePermissionNothingIsRestored(): void
    {
        $id                   = $this->update( self::H1, $this->sized( '61px' ) );
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $this->assertSame( 'forbidden', $this->undo()->undo( $id )['items'][0]['result'] );
    }

    public function testAnUndoHoldsTheSiteLockFromBeforeItsFirstReadAndReleasesIt(): void
    {
        $id   = $this->update( self::H1, $this->sized( '61px' ) );
        $seen = [];
        $GLOBALS['__divi_gp']['gets']   = 0;
        $GLOBALS['__divi_gp']['on_get'] = static function ( int $n ) use ( &$seen ): void {
            $seen[ $n ] = count( $GLOBALS['__wp_options'] );
        };
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'] );
        $this->assertSame( [ 1 ], array_values( array_unique( $seen ) ) );
        $this->assertSame( [], $GLOBALS['__wp_options'] );
    }

    public function testAnUndoWhileAnotherPresetChangeIsInProgressIsBusyAndWritesNothing(): void
    {
        $id    = $this->update( self::H1, $this->sized( '61px' ) );
        $other = new GlobalPresetsLock( 0 );
        $this->assertTrue( $other->acquire() );
        $saves = count( $GLOBALS['__divi_gp']['save_calls'] );
        $r     = $this->undo()->undo( $id );
        $this->assertSame( 'busy', $r['items'][0]['reason'] );
        $this->assertCount( $saves, $GLOBALS['__divi_gp']['save_calls'] );
        $other->release();
        $this->assertTrue( $this->undo()->undo( $id )['fully_undone'] );
    }

    public function testADiviThatKeepsADifferentStateThanRequestedIsReportedAsRefused(): void
    {
        $id = $this->update( self::H1, $this->sized( '61px' ) );
        $GLOBALS['__divi_gp']['mutate'] = static function ( array $data ): array {
            $data['module']['divi/heading']['items'][ self::H1 ]['name'] = 'something else';

            return $data;
        };
        $this->assertSame( 'divi_refused', $this->undo()->undo( $id )['items'][0]['reason'] );
    }

    public function testAStoredShapeThatCannotBeReproducedMakesTheUndoRefuseWithoutWriting(): void
    {
        $id = $this->update( self::H1, $this->sized( '61px' ) );
        $GLOBALS['__divi_gp']['data']['mystery'] = [ 'x' => 1 ];
        $saves = count( $GLOBALS['__divi_gp']['save_calls'] );
        $r     = $this->undo()->undo( $id );
        $this->assertSame( 'unsafe_to_save', $r['items'][0]['reason'] );
        $this->assertCount( $saves, $GLOBALS['__divi_gp']['save_calls'] );
    }

    /** A preset whose styleAttrs differ from its attrs, as a real site can have after a builder edit. */
    private function withDivergentStyleAttrs(): array
    {
        $data = GlobalPresets::read();
        $data['module']['divi/heading']['items'][ self::H1 ]['styleAttrs'] = $this->sized( '55px' );
        GlobalPresets::save( $data );

        return $data['module']['divi/heading']['items'][ self::H1 ];
    }

    public function testUndoOfAnUpdateRestoresAStyleAttrsThatDifferedFromAttrsAndUndoOfThatUndoBringsTheUpdateBack(): void
    {
        $original = $this->withDivergentStyleAttrs();
        $start    = GlobalPresets::read();
        $id       = $this->update( self::H1, $this->sized( '61px' ), 'Heading 1 v2' );
        $updated  = GlobalPresets::read();
        $r        = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $now = GlobalPresets::read()['module']['divi/heading']['items'][ self::H1 ];
        $this->assertSame( $original['attrs'], $now['attrs'] );
        $this->assertSame( $this->sized( '55px' ), $now['styleAttrs'], 'the style tree is back as it was, not a copy of attrs' );
        unset( $now['updated'], $original['updated'] );
        $this->assertSame( $original, $now );
        $again = $this->undo()->undo( (int) $r['undo_batch']['id'] );
        $this->assertTrue( $again['fully_undone'] );
        $back = GlobalPresets::read();
        $this->assertSame( $updated['module']['divi/heading']['items'][ self::H1 ]['attrs'], $back['module']['divi/heading']['items'][ self::H1 ]['attrs'] );
        $this->assertSame( $updated['module']['divi/heading']['items'][ self::H1 ]['styleAttrs'], $back['module']['divi/heading']['items'][ self::H1 ]['styleAttrs'] );
        $this->assertSame( 'Heading 1 v2', $back['module']['divi/heading']['items'][ self::H1 ]['name'] );
        $this->assertNotSame( $start, $back );
    }

    public function testAnUpdateUndoWhoseStyleAttrsDiviDoesNotKeepIsRefused(): void
    {
        $this->withDivergentStyleAttrs();
        $id = $this->update( self::H1, $this->sized( '61px' ) );
        $GLOBALS['__divi_gp']['mutate'] = static function ( array $data ): array {
            $data['module']['divi/heading']['items'][ self::H1 ]['styleAttrs'] = [ 'other' => '1' ];

            return $data;
        };
        $this->assertSame( 'divi_refused', $this->undo()->undo( $id )['items'][0]['reason'] );
    }

    public function testAnUpdateRecordedWithoutNamedAttrsIsNotFoundAndWritesNothing(): void
    {
        $id    = $this->update( self::H1, $this->sized( '61px' ) );
        $this->repo->rows[ $id ]['items'][0]['before']['state'] = [ 'name' => 'x' ];
        $saves = count( $GLOBALS['__divi_gp']['save_calls'] );
        $this->assertSame( 'not_found', $this->undo()->undo( $id, true )['items'][0]['reason'] );
        $this->assertCount( $saves, $GLOBALS['__divi_gp']['save_calls'] );
    }

    public function testAForcedUndoOfAnUpdateWhosePresetVanishedIsNotFoundAndWritesNothing(): void
    {
        $id = $this->update( self::H1, $this->sized( '61px' ) );
        unset( $GLOBALS['__divi_gp']['data']['module']['divi/heading']['items'][ self::H1 ] );
        $saves = count( $GLOBALS['__divi_gp']['save_calls'] );
        $r     = $this->undo()->undo( $id, true );
        $this->assertFalse( $r['fully_undone'] );
        $this->assertSame( 'not_found', $r['items'][0]['reason'] );
        $this->assertCount( $saves, $GLOBALS['__divi_gp']['save_calls'] );
    }

    public function testAChangedAutoCreatedDefaultBlocksTheRemovalOfACreateAndWritesNothing(): void
    {
        $id   = $this->create( 'divi/image', self::NEW, 'Rounded', [ 'module' => [ 'x' => '1' ] ] );
        $auto = array_values( array_diff( GlobalPresets::itemIds( GlobalPresets::read(), 'divi/image' ), [ self::NEW ] ) )[0];
        $GLOBALS['__divi_gp']['data']['module']['divi/image']['items'][ $auto ]['attrs'] = [ 'edited' => 'later' ];
        $saves = count( $GLOBALS['__divi_gp']['save_calls'] );
        foreach ( [ false, true ] as $force ) {
            $r = $this->undo()->undo( $id, $force );
            $this->assertFalse( $r['fully_undone'] );
            $this->assertSame( 'changed_since', $r['items'][0]['reason'] ?? $r['items'][0]['result'] );
        }
        $this->assertCount( $saves, $GLOBALS['__divi_gp']['save_calls'] );
        $this->assertArrayHasKey( self::NEW, GlobalPresets::read()['module']['divi/image']['items'] );
    }

    public function testRemovingACreatedPresetRefusesAnUnreproducibleShapeWithoutWriting(): void
    {
        $id = $this->create( 'divi/heading', self::NEW, 'Big', $this->sized( '57px' ) );
        $GLOBALS['__divi_gp']['data']['mystery'] = [ 'x' => 1 ];
        $saves = count( $GLOBALS['__divi_gp']['save_calls'] );
        $this->assertSame( 'unsafe_to_save', $this->undo()->undo( $id )['items'][0]['reason'] );
        $this->assertCount( $saves, $GLOBALS['__divi_gp']['save_calls'] );
    }

    public function testRemovingACreatedPresetThatDiviKeepsAnywayIsReportedAsRefused(): void
    {
        $id   = $this->create( 'divi/heading', self::NEW, 'Big', $this->sized( '57px' ) );
        $keep = GlobalPresets::read()['module']['divi/heading']['items'][ self::NEW ];
        $GLOBALS['__divi_gp']['mutate'] = static function ( array $data ) use ( $keep ): array {
            $data['module']['divi/heading']['items'][ self::NEW ] = $keep;

            return $data;
        };
        $this->assertSame( 'divi_refused', $this->undo()->undo( $id )['items'][0]['reason'] );
    }

    public function testRecreatingARemovedPresetRefusesAnUnreproducibleShapeWithoutWriting(): void
    {
        $id = $this->create( 'divi/heading', self::NEW, 'Big', $this->sized( '57px' ) );
        $r  = $this->undo()->undo( $id );
        $this->assertTrue( $r['fully_undone'] );
        $GLOBALS['__divi_gp']['data']['mystery'] = [ 'x' => 1 ];
        $saves = count( $GLOBALS['__divi_gp']['save_calls'] );
        $this->assertSame( 'unsafe_to_save', $this->undo()->undo( (int) $r['undo_batch']['id'] )['items'][0]['reason'] );
        $this->assertCount( $saves, $GLOBALS['__divi_gp']['save_calls'] );
    }

    public function testRecreatingARemovedPresetThatDiviStoresDifferentlyIsReportedAsRefused(): void
    {
        $id = $this->create( 'divi/heading', self::NEW, 'Big', $this->sized( '57px' ) );
        $r  = $this->undo()->undo( $id );
        $GLOBALS['__divi_gp']['mutate'] = static function ( array $data ): array {
            if ( isset( $data['module']['divi/heading']['items'][ self::NEW ] ) ) {
                $data['module']['divi/heading']['items'][ self::NEW ]['name'] = 'something else';
            }

            return $data;
        };
        $this->assertSame( 'divi_refused', $this->undo()->undo( (int) $r['undo_batch']['id'] )['items'][0]['reason'] );
    }

    public function testUndoOfAShellUpdateRemovesTheTreesAgainAndUndoOfThatUndoBringsThemBack(): void
    {
        $id    = GlobalPresetsFixture::T_DEFAULT;
        $start = GlobalPresets::read();
        $this->assertArrayNotHasKey( 'attrs', $start['module']['divi/text']['items'][ $id ] );
        $data  = $start;
        $item  = $data['module']['divi/text']['items'][ $id ];
        $item['attrs'] = $item['styleAttrs'] = [ 'content' => [ 'x' => '1' ] ];
        $item['updated'] = 1790000500;
        $data['module']['divi/text']['items'][ $id ] = $item;
        $res   = GlobalPresets::save( $data );
        $batch = $this->log->record( 'manage_global_presets', 'test', [ GlobalPresetKind::itemForUpdate( $id, 'divi/text', (array) GlobalPresets::state( $start['module']['divi/text']['items'][ $id ] ), (array) GlobalPresets::state( $res['after']['module']['divi/text']['items'][ $id ] ), null, true ) ] );
        $updated = GlobalPresets::read();
        $this->assertSame( [ 'content' => [ 'x' => '1' ] ], $updated['module']['divi/text']['items'][ $id ]['styleAttrs'] );

        $r = $this->undo()->undo( (int) $batch['id'] );
        $this->assertTrue( $r['fully_undone'] );
        $back = GlobalPresets::read();
        $this->assertArrayNotHasKey( 'attrs', $back['module']['divi/text']['items'][ $id ] );
        $this->assertArrayNotHasKey( 'styleAttrs', $back['module']['divi/text']['items'][ $id ] );
        unset( $back['module']['divi/text']['items'][ $id ]['updated'], $start['module']['divi/text']['items'][ $id ]['updated'] );
        $this->assertSame( $start, $back, 'the shell is back exactly (apart from its updated stamp)' );

        $again = $this->undo()->undo( (int) $r['undo_batch']['id'] );
        $this->assertTrue( $again['fully_undone'] );
        $final = GlobalPresets::read()['module']['divi/text']['items'][ $id ];
        $this->assertSame( [ 'content' => [ 'x' => '1' ] ], $final['attrs'] );
        $this->assertSame( [ 'content' => [ 'x' => '1' ] ], $final['styleAttrs'] );
    }
}
