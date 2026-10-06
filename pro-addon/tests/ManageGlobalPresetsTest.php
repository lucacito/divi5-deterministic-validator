<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\GlobalPresetKind;
use AiEditorDivi5\Pro\Settings\GlobalPresets;
use AiEditorDivi5\Pro\Settings\GlobalPresetsLock;
use AiEditorDivi5\Pro\Tools\ManageGlobalPresets;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalPresetsFixture.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';

class ManageGlobalPresetsTest extends TestCase
{
    private const H1        = GlobalPresetsFixture::H1;
    private const H_DEFAULT = GlobalPresetsFixture::H_DEFAULT;

    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private ManageGlobalPresets $tool;
    private int $now = 1790000000;
    private int $seq = 0;

    protected function setUp(): void
    {
        GlobalPresetsFixture::reset();
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->seq  = 0;
        $this->tool = new ManageGlobalPresets( $this->log, fn (): int => $this->now, function (): string {
            return 'aaaaaaaa-0000-4000-8000-00000000000' . ++$this->seq;
        }, new GlobalPresetsLock( 0 ) );
    }

    protected function tearDown(): void
    {
        GlobalPresetsFixture::clear();
    }

    private function sized( string $px ): array
    {
        return [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => $px ] ] ] ] ] ] ];
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    private function apply( array $args ): array
    {
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertArrayHasKey( 'preview_token', $p );

        return $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
    }

    private function snapshot(): array
    {
        return [ $GLOBALS['__divi_gp']['data'], count( $GLOBALS['__divi_gp']['save_calls'] ), count( $GLOBALS['__divi_gp']['import_calls'] ), count( $this->repo->rows ), $GLOBALS['__wp_options'] ];
    }

    private function refused( array $args, string $needle ): void
    {
        $before = $this->snapshot();
        try {
            $this->tool->run( $args );
            $this->fail( 'expected a refusal containing: ' . $needle );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $needle, $e->getMessage() );
        }
        $this->assertSame( $before, $this->snapshot(), 'a refusal changes nothing' );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ GlobalPresetKind::NAME => new GlobalPresetKind( new GlobalPresetsLock( 0 ) ) ], $this->log );
    }

    public function testTheDefinitionIsValidForMcpAndRest(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'manage_global_presets', $d['name'] );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'presets' ], $d['inputSchema']['required'] );
        foreach ( [ 'presets', 'dry_run', 'preview_token', 'allow_default_presets' ] as $p ) {
            $this->assertArrayHasKey( $p, $d['inputSchema']['properties'] );
        }
        $this->assertSame( 20, $d['inputSchema']['properties']['presets']['maxItems'] );
        foreach ( [ 'id', 'module', 'name', 'attrs' ] as $k ) {
            $this->assertArrayHasKey( $k, $d['inputSchema']['properties']['presets']['items']['properties'] );
        }
    }

    public function testADryRunReportsTheChangesAndATokenAndWritesNothing(): void
    {
        $r = $this->tool->run( [ 'presets' => [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->sized( '57px' ) ], [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ], 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 1800, $r['expires_in'] );
        $this->assertSame( [ 'create', 'update' ], array_column( $r['changes'], 'action' ) );
        $this->assertNull( $r['changes'][0]['id'] );
        $this->assertSame( self::H1, $r['changes'][1]['id'] );
        $this->assertSame( '72px', $r['changes'][1]['before']['attrs']['title']['decoration']['font']['font']['desktop']['value']['size'] );
        $this->assertSame( [], $GLOBALS['__divi_gp']['save_calls'] );
        $this->assertSame( [], $GLOBALS['__divi_gp']['import_calls'] );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'a preview takes no lock' );
    }

    public function testARealCreateGoesThroughTheAdditiveImportAndRecordsAnUndoableBatch(): void
    {
        $start = GlobalPresets::read();
        $r     = $this->apply( [ 'presets' => [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->sized( '57px' ) ] ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 'create', $r['changes'][0]['action'] );
        $this->assertTrue( $r['batch']['undoable'] );
        $id    = $r['changes'][0]['id'];
        $after = GlobalPresets::read();
        $this->assertSame( 'Big', $after['module']['divi/heading']['items'][ $id ]['name'] );
        $this->assertSame( $this->sized( '57px' ), $after['module']['divi/heading']['items'][ $id ]['styleAttrs'] );
        $this->assertSame( self::H_DEFAULT, $after['module']['divi/heading']['default'], 'a create never changes the default' );
        $this->assertCount( 1, $GLOBALS['__divi_gp']['import_calls'] );
        $this->assertSame( [], $GLOBALS['__divi_gp']['save_calls'], 'an additive import, no replace-all save' );
        $untouched = $after;
        unset( $untouched['module']['divi/heading']['items'][ $id ] );
        $this->assertSame( $start, $untouched, 'every other preset, the legacy ones included, is byte-identical' );
        $this->assertStringContainsString( 'modulePreset', $r['note'] );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'the lock is released' );
    }

    public function testACreateForAModuleWithNoPresetsReportsAndUndoesTheDefaultPresetDiviAdds(): void
    {
        $start = GlobalPresets::read();
        $dry   = $this->tool->run( [ 'presets' => [ [ 'module' => 'divi/image', 'name' => 'Rounded', 'attrs' => [ 'module' => [ 'x' => '1' ] ] ] ], 'dry_run' => true ] );
        $this->assertTrue( $dry['changes'][0]['default_preset_created'] );
        $this->assertStringContainsString( 'default preset', $dry['message'] );
        $r = $this->tool->run( [ 'presets' => [ [ 'module' => 'divi/image', 'name' => 'Rounded', 'attrs' => [ 'module' => [ 'x' => '1' ] ] ] ], 'preview_token' => $dry['preview_token'] ] );
        $this->assertTrue( $r['changes'][0]['default_preset_created'] );
        $this->assertCount( 2, GlobalPresets::read()['module']['divi/image']['items'] );
        $this->assertTrue( $this->undo()->undo( (int) $r['batch']['id'] )['fully_undone'] );
        $this->assertSame( $start, GlobalPresets::read() );
    }

    public function testAnUpdateReplacesTheTreeWithOneCompleteSaveAndKeepsEverythingElse(): void
    {
        $start = GlobalPresets::read();
        $r     = $this->apply( [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ), 'name' => 'Heading 1 v2' ] ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertCount( 1, $GLOBALS['__divi_gp']['save_calls'] );
        $this->assertSame( [], $GLOBALS['__divi_gp']['import_calls'] );
        $now = GlobalPresets::read();
        $this->assertSame( 'Heading 1 v2', $now['module']['divi/heading']['items'][ self::H1 ]['name'] );
        $this->assertSame( 1790000000, $now['module']['divi/heading']['items'][ self::H1 ]['updated'] );
        $others = $now;
        unset( $others['module']['divi/heading']['items'][ self::H1 ] );
        $expected = $start;
        unset( $expected['module']['divi/heading']['items'][ self::H1 ] );
        $this->assertSame( $expected, $others );
    }

    public function testAMixedCallAppliesUpdatesAndCreatesAndOneUndoPutsEverythingBack(): void
    {
        $start = GlobalPresets::read();
        $r     = $this->apply( [ 'presets' => [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->sized( '57px' ) ], [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ] ] );
        $this->assertSame( 2, count( $r['changes'] ) );
        $u = $this->undo()->undo( (int) $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $back = GlobalPresets::read();
        unset( $back['module']['divi/heading']['items'][ self::H1 ]['updated'], $start['module']['divi/heading']['items'][ self::H1 ]['updated'] );
        $this->assertSame( $start, $back );
    }

    public function testUpdatingTheDefaultPresetNeedsTheFlagAndTheFlagIsPartOfTheToken(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H_DEFAULT, 'attrs' => $this->sized( '31px' ) ] ] ];
        $this->refused( $args + [ 'dry_run' => true ], 'default preset' );
        $p = $this->tool->run( $args + [ 'dry_run' => true, 'allow_default_presets' => true ] );
        $this->assertTrue( $p['changes'][0]['is_default'] );
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'default preset' );
    }

    public function testARealRunNeedsTheTokenAndItWorksOnce(): void
    {
        $args = [ 'presets' => [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->sized( '57px' ) ] ] ];
        $this->refused( $args, 'preview_token' );
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'] );
        // A create still plans a change the second time, so the token check is reached and the spent token is refused.
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
    }

    public function testReplayingAnAlreadyAppliedUpdateAnswersNothingToChangeWithoutATokenAndWritesNothing(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ] ];
        $this->assertTrue( $this->apply( $args )['changed'] );
        $before = $this->snapshot();
        foreach ( [ [], [ 'dry_run' => true ] ] as $extra ) {
            $r = $this->tool->run( $args + $extra );
            $this->assertFalse( $r['changed'] );
            $this->assertSame( [ self::H1 ], $r['unchanged'] );
            $this->assertArrayNotHasKey( 'preview_token', $r );
        }
        $this->assertSame( $before, $this->snapshot(), 'nothing is written, recorded or locked' );
    }

    public function testATokenForOtherArgumentsAnotherUserOrAnExpiredOneIsRefused(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->refused( [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '62px' ) ] ], 'preview_token' => $p['preview_token'] ], 'preview_token' );
        $GLOBALS['__wp_current_user_id'] = 8;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
        $GLOBALS['__wp_current_user_id'] = 7;
        $this->now += 1801;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
    }

    public function testAPresetChangedSinceThePreviewIsSkippedAndReportedNeverOverwritten(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gp']['data']['module']['divi/heading']['items'][ self::H1 ]['name'] = 'Renamed by someone';
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 'changed_since_preview', $r['skipped'][0]['reason'] );
        $this->assertSame( [], $GLOBALS['__divi_gp']['save_calls'] );
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function refusals(): array
    {
        $ok   = [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '57px' ] ] ] ] ] ] ];
        $many = array_fill( 0, 21, [ 'module' => 'divi/heading', 'name' => 'x', 'attrs' => $ok ] );

        return [
            'no presets'            => [ [], 'presets' ],
            'an empty list'         => [ [ 'presets' => [] ], 'presets' ],
            'more than 20'          => [ [ 'presets' => $many ], 'presets' ],
            'dry_run not a bool'    => [ [ 'presets' => [ [ 'module' => 'divi/heading', 'name' => 'x', 'attrs' => $ok ] ], 'dry_run' => 'yes' ], 'dry_run' ],
            'an unknown id'         => [ [ 'presets' => [ [ 'id' => 'nope-nope-nope', 'name' => 'x' ] ], 'dry_run' => true ], 'no preset with the id' ],
            'a legacy preset'       => [ [ 'presets' => [ [ 'id' => GlobalPresetsFixture::LEGACY, 'name' => 'x' ] ], 'dry_run' => true ], 'cannot be changed here' ],
            'custom css on create'  => [ [ 'presets' => [ [ 'module' => 'divi/heading', 'name' => 'x', 'attrs' => [ 'module' => [ 'advanced' => [ 'css' => [ 'desktop' => [ 'value' => [ 'freeForm' => '.x{color:red}' ] ] ] ] ] ] ] ], 'dry_run' => true ], 'CUSTOM_CSS_NOT_ALLOWED' ],
            'custom css on update'  => [ [ 'presets' => [ [ 'id' => GlobalPresetsFixture::H1, 'attrs' => [ 'module' => [ 'advanced' => [ 'css' => [ 'desktop' => [ 'value' => [ 'mainElement' => 'color:red' ] ] ] ] ] ] ] ], 'dry_run' => true ], 'CUSTOM_CSS_NOT_ALLOWED' ],
            'module on an update'   => [ [ 'presets' => [ [ 'id' => GlobalPresetsFixture::H1, 'module' => 'divi/text', 'name' => 'x' ] ], 'dry_run' => true ], 'never moves' ],
        ];
    }

    /** @param array<string,mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'refusals' )]
    public function testRefusalsChangeNothing( array $args, string $needle ): void
    {
        $this->refused( $args, $needle );
    }

    public function testWithoutThePermissionEvenAPreviewIsRefusedUpFront(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_theme_options' => false ];
        $this->refused( [ 'presets' => [ [ 'id' => self::H1, 'name' => 'x' ] ], 'dry_run' => true ], 'edit_theme_options' );
    }

    public function testItFailsClosedWhenDiviCannotBeRead(): void
    {
        $GLOBALS['__divi_gp']['throw_get'] = true;
        $this->refused( [ 'presets' => [ [ 'id' => self::H1, 'name' => 'x' ] ], 'dry_run' => true ], 'not available' );
    }

    public function testAStoredShapeItCannotReproduceIsRefusedBeforeAnyWrite(): void
    {
        $GLOBALS['__divi_gp']['data']['mystery'] = [ 'x' => 1 ];
        $this->refused( [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ], 'dry_run' => true ], 'would be lost' );
    }

    public function testASaveThatThrowsAndStoresNothingIsAnErrorNotASuccess(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gp']['throw_save'] = true;
        try {
            $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
            $this->fail( 'expected a failure' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'did not save', $e->getMessage() );
        }
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'the lock is released after a failure' );
    }

    public function testASaveDiviIgnoredIsReportedNeverClaimed(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ], [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->sized( '57px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $keep = GlobalPresetsFixture::data();
        $GLOBALS['__divi_gp']['mutate'] = static fn ( array $data ): array => $keep; // Divi keeps what was there
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [], $this->repo->rows, 'nothing recorded, nothing to undo' );
        $this->assertNotSame( [], $r['not_stored'] ?? [] );
    }

    public function testAPresetDiviDropsWhileSavingIsReportedAsUnexpected(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gp']['mutate'] = static function ( array $data ): array {
            unset( $data['module']['divi/text']['items'][ GlobalPresetsFixture::T_DEFAULT ] );

            return $data;
        };
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ [ 'id' => GlobalPresetsFixture::T_DEFAULT, 'reason' => 'removed_by_divi' ] ], $r['unexpected_changes'] );
    }

    public function testADryRunTakesNoLockAndARealRunHoldsItFromBeforeItsFirstReadUntilTheEnd(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ] ];
        $seen = [];
        $GLOBALS['__divi_gp']['on_get'] = static function ( int $n ) use ( &$seen ): void {
            $seen[ $n ] = count( $GLOBALS['__wp_options'] );
        };
        $GLOBALS['__divi_gp']['gets'] = 0;
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertSame( [ 1 => 0 ], $seen );

        $seen                         = [];
        $GLOBALS['__divi_gp']['gets'] = 0;
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'] );
        $this->assertSame( 1, $seen[1] );
        $this->assertSame( [], $GLOBALS['__wp_options'] );
    }

    public function testARealRunWhileAnotherChangeIsInProgressIsRefusedWithoutSpendingTheToken(): void
    {
        $args  = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ] ];
        $other = new GlobalPresetsLock( 0 );
        $this->assertTrue( $other->acquire() );
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'in progress' );
        $other->release();
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'] );
    }

    public function testARefusalBeforeTheTokenIsOpenedLeavesItUsable(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gp']['data']['mystery'] = [ 'x' => 1 ];
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'would be lost' );
        unset( $GLOBALS['__divi_gp']['data']['mystery'] );
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'], 'the refusal did not spend the token' );
    }

    public function testAnUpdateOfAPresetWhoseStyleAttrsDifferFromAttrsUndoesBothTreesByteIdentically(): void
    {
        $differing = $this->sized( '45px' );
        $GLOBALS['__divi_gp']['data']['module']['divi/heading']['items'][ self::H1 ]['styleAttrs'] = $differing;
        $this->assertNotSame( $GLOBALS['__divi_gp']['data']['module']['divi/heading']['items'][ self::H1 ]['attrs'], $differing );
        $start = GlobalPresets::read();
        $r     = $this->apply( [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( $this->sized( '61px' ), GlobalPresets::read()['module']['divi/heading']['items'][ self::H1 ]['styleAttrs'] );
        $this->assertTrue( $this->undo()->undo( (int) $r['batch']['id'] )['fully_undone'] );
        $back = GlobalPresets::read()['module']['divi/heading']['items'][ self::H1 ];
        $this->assertSame( $start['module']['divi/heading']['items'][ self::H1 ]['attrs'], $back['attrs'] );
        $this->assertSame( $differing, $back['styleAttrs'], 'styleAttrs comes back as it was, not as attrs' );
    }

    public function testTwoCreatesForAModuleWithNoPresetsAreOneUndoableBatchAndOnlyTheFirstReportsTheDefault(): void
    {
        $start = GlobalPresets::read();
        $args  = [ 'presets' => [
            [ 'module' => 'divi/image', 'name' => 'Rounded', 'attrs' => [ 'module' => [ 'x' => '1' ] ] ],
            [ 'module' => 'divi/image', 'name' => 'Square', 'attrs' => [ 'module' => [ 'x' => '2' ] ] ],
        ] ];
        $dry = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertSame( [ true, false ], array_column( $dry['changes'], 'default_preset_created' ) );
        $r = $this->tool->run( $args + [ 'preview_token' => $dry['preview_token'] ] );
        $this->assertCount( 2, $r['changes'] );
        $this->assertSame( [ true, false ], array_column( $r['changes'], 'default_preset_created' ) );
        $this->assertCount( 3, GlobalPresets::read()['module']['divi/image']['items'] );
        $u = $this->undo()->undo( (int) $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( $start, GlobalPresets::read() );
    }

    public function testAllowDefaultPresetsIsBoundIntoTheToken(): void
    {
        // A token made with the flag on is no use for a real run without it (which is refused as a default-preset change)...
        $def = [ 'presets' => [ [ 'id' => self::H_DEFAULT, 'attrs' => $this->sized( '31px' ) ] ] ];
        $p   = $this->tool->run( $def + [ 'dry_run' => true, 'allow_default_presets' => true ] );
        $this->refused( $def + [ 'preview_token' => $p['preview_token'] ], 'default preset' );
        // ...and a token made without the flag does not open for a call that adds it, even for an ordinary preset.
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ] ];
        $q    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->refused( $args + [ 'allow_default_presets' => true, 'preview_token' => $q['preview_token'] ], 'preview_token' );
        // The flagged token still works for the flagged call.
        $this->assertTrue( $this->tool->run( $def + [ 'allow_default_presets' => true, 'preview_token' => $p['preview_token'] ] )['changed'] );
    }

    public function testAPresetThatVanishesDuringTheUpdateSaveIsNotStoredAndNotRecorded(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gp']['mutate'] = static function ( array $data ): array {
            unset( $data['module']['divi/heading']['items'][ GlobalPresetsFixture::H1 ] );

            return $data;
        };
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ [ 'index' => 0, 'id' => self::H1 ] ], $r['not_stored'] );
        $this->assertArrayNotHasKey( 'differs_from_request', $r );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testANewPresetDiviAddedThatWeDidNotAskForIsReportedAsUnexpected(): void
    {
        $args = [ 'presets' => [ [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->sized( '57px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gp']['mutate'] = static function ( array $data ): array {
            $data['module']['divi/heading']['items']['stray-0000-0000'] = [ 'id' => 'stray-0000-0000', 'moduleName' => 'divi/heading', 'name' => 'Stray', 'attrs' => [ 'a' => '1' ], 'styleAttrs' => [ 'a' => '1' ], 'type' => 'module' ];

            return $data;
        };
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ [ 'id' => 'stray-0000-0000', 'reason' => 'added_by_divi' ] ], $r['unexpected_changes'] );
        $this->assertFalse( $r['changes'][0]['default_preset_created'] );
    }

    public function testACreateFollowingSavedUpdatesRecordsTheUpdatesWhenTheReadFailsAndSaysSo(): void
    {
        $args = [ 'presets' => [ [ 'id' => self::H1, 'attrs' => $this->sized( '61px' ) ], [ 'module' => 'divi/heading', 'name' => 'Big', 'attrs' => $this->sized( '57px' ) ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gp']['gets']   = 0;
        $GLOBALS['__divi_gp']['on_get'] = static function ( int $n ): void {
            if ( $n >= 3 ) { // read 1 = before, 2 = read-back of the update save, 3 = the read before the first create
                $GLOBALS['__divi_gp']['throw_get'] = true;
            }
        };
        try {
            $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
            $this->fail( 'expected a failure' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'recorded', $e->getMessage() );
            $this->assertStringContainsString( 'undo_batch', $e->getMessage() );
        }
        $GLOBALS['__divi_gp']['throw_get'] = false;
        $GLOBALS['__divi_gp']['on_get']    = null;
        $this->assertCount( 1, $this->repo->rows );
        $row = array_values( $this->repo->rows )[0];
        $this->assertCount( 1, $row['items'] );
        $this->assertSame( '61px', GlobalPresets::read()['module']['divi/heading']['items'][ self::H1 ]['attrs']['title']['decoration']['font']['font']['desktop']['value']['size'] );
        $this->assertTrue( $this->undo()->undo( (int) array_key_first( $this->repo->rows ) )['fully_undone'] );
    }
}
