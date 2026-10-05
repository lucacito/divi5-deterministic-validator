<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\GlobalColorKind;
use AiEditorDivi5\Pro\Settings\GlobalColors;
use AiEditorDivi5\Pro\Settings\GlobalColorsLock;
use AiEditorDivi5\Pro\Tools\ManageGlobalColors;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalColorsFixture.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';

class ManageGlobalColorsTest extends TestCase
{
    private const A = GlobalColorsFixture::A;
    private const B = GlobalColorsFixture::B;

    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private ManageGlobalColors $tool;
    private int $now = 1790000000;
    private int $seq = 0;

    protected function setUp(): void
    {
        GlobalColorsFixture::reset();
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->seq  = 0;
        $GLOBALS['__wp_options'] = [];
        $this->tool = new ManageGlobalColors( $this->log, fn (): int => $this->now, function (): string {
            return 'gcid-new-00000000-0000-4000-8000-00000000000' . ++$this->seq;
        }, new GlobalColorsLock( 0 ) );
    }

    protected function tearDown(): void
    {
        GlobalColorsFixture::clear();
    }

    /** Preview, then apply with the returned token. @param array<string,mixed> $args @return array<string,mixed> */
    private function apply( array $args ): array
    {
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertArrayHasKey( 'preview_token', $p );

        return $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
    }

    private function refused( array $args, string $needle ): void
    {
        $before = [ $GLOBALS['__divi_gc']['custom'], $GLOBALS['__divi_gc']['options'], count( $GLOBALS['__divi_gc']['set_calls'] ), count( $this->repo->rows ) ];
        try {
            $this->tool->run( $args );
            $this->fail( 'expected a refusal containing: ' . $needle );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $needle, $e->getMessage() );
        }
        $this->assertSame( $before, [ $GLOBALS['__divi_gc']['custom'], $GLOBALS['__divi_gc']['options'], count( $GLOBALS['__divi_gc']['set_calls'] ), count( $this->repo->rows ) ], 'a refusal changes nothing' );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ GlobalColorKind::NAME => new GlobalColorKind() ], $this->log );
    }

    public function testTheDefinitionIsValidForMcpAndRest(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'manage_global_colors', $d['name'] );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'colors' ], $d['inputSchema']['required'] );
        foreach ( [ 'colors', 'dry_run', 'preview_token', 'allow_theme_colors' ] as $p ) {
            $this->assertArrayHasKey( $p, $d['inputSchema']['properties'] );
        }
        $this->assertSame( 50, $d['inputSchema']['properties']['colors']['maxItems'] );
    }

    public function testADryRunReportsTheChangeAndATokenAndWritesNothing(): void
    {
        $r = $this->tool->run( [ 'colors' => [ [ 'label' => 'Sky', 'color' => '#00aaff' ], [ 'id' => self::A, 'color' => '#abcdef' ] ], 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertFalse( $r['theme_colors_touched'] );
        $this->assertSame( 1800, $r['expires_in'] );
        $this->assertNotSame( '', $r['preview_token'] );
        $this->assertSame( [ 'create', 'update' ], array_column( $r['changes'], 'action' ) );
        $this->assertNull( $r['changes'][0]['id'], 'the id of a new color is assigned when it is applied' );
        $this->assertSame( self::A, $r['changes'][1]['id'] );
        $this->assertSame( '#112233', $r['changes'][1]['before']['color'] );
        $this->assertSame( '#abcdef', $r['changes'][1]['after']['color'] );
        $this->assertSame( [], $GLOBALS['__divi_gc']['set_calls'], 'Divi was not asked to write anything' );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( GlobalColorsFixture::custom(), GlobalColors::customMap( (array) GlobalColors::read() ) );
    }

    public function testACreateAddsTheColorAndPassesEveryOtherEntryBackByteForByte(): void
    {
        $r = $this->apply( [ 'colors' => [ [ 'label' => 'Sky', 'color' => '#00aaff' ] ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 'create', $r['changes'][0]['action'] );
        $id = $r['changes'][0]['id'];
        $this->assertStringStartsWith( 'gcid-new-', $id );
        $this->assertSame( [ 'color' => '#00aaff', 'label' => 'Sky', 'status' => 'active', 'folder' => '' ], $r['changes'][0]['after'] );
        $this->assertCount( 1, $GLOBALS['__divi_gc']['set_calls'], 'exactly one save' );
        $this->assertSame( [ false ], $GLOBALS['__divi_gc']['set_flags'] );
        $sent = $GLOBALS['__divi_gc']['set_calls'][0];
        $this->assertSame( GlobalColorsFixture::custom()[ self::A ], $sent[ self::A ], 'an untouched entry keeps usedInPosts, folder and lastUpdated' );
        $this->assertSame( GlobalColorsFixture::custom()[ self::B ], $sent[ self::B ] );
        $this->assertSame( [ self::A, self::B, $id ], array_keys( $sent ) );
        $this->assertArrayNotHasKey( 'gcid-primary-color', $sent, 'the theme colors are never sent unless they are being changed' );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( [ 'global_color' ], array_column( $this->repo->rows[1]['items'], 'kind' ) );
        $this->assertStringContainsString( 'gcid', $r['note'] );
    }

    public function testRecolorRenameAndDeactivateChangeOnlyWhatWasAsked(): void
    {
        $r = $this->apply( [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef', 'label' => 'Brand 2' ], [ 'id' => self::B, 'status' => 'active' ] ] ] );
        $this->assertSame( [ 'update', 'reactivate' ], array_column( $r['changes'], 'action' ) );
        $all = (array) GlobalColors::read();
        $this->assertSame( [ '#abcdef', 'Brand 2', 'active', '' ], array_values( (array) GlobalColors::state( $all[ self::A ] ) ) );
        $this->assertSame( [ '12', '15' ], $all[ self::A ]['usedInPosts'] );
        $this->assertSame( [ '#445566', 'Accent', 'active', 'brand' ], array_values( (array) GlobalColors::state( $all[ self::B ] ) ) );
        $r = $this->apply( [ 'colors' => [ [ 'id' => self::A, 'status' => 'inactive' ] ] ] );
        $this->assertSame( 'deactivate', $r['changes'][0]['action'] );
        $this->assertSame( 'inactive', GlobalColors::read()[ self::A ]['status'] );
    }

    public function testNothingToDoIsReportedAndWritesNothing(): void
    {
        $r = $this->tool->run( [ 'colors' => [ [ 'id' => self::A, 'color' => '#112233' ] ] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ self::A ], $r['unchanged'] );
        $this->assertSame( [], $GLOBALS['__divi_gc']['set_calls'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testARealRunNeedsAGenuineTokenForTheSameArgumentsByTheSameUserOnce(): void
    {
        $args = [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ];
        $this->refused( $args, 'preview_token' );
        $this->refused( $args + [ 'preview_token' => 'garbage.token' ], 'preview_token' );

        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->refused( [ 'colors' => [ [ 'id' => self::A, 'color' => '#fedcba' ] ], 'preview_token' => $p['preview_token'] ], 'preview_token' );

        $GLOBALS['__wp_current_user_id'] = 8;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
        $GLOBALS['__wp_current_user_id'] = 7;

        $this->now += 1801;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
        $this->now -= 1801;

        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'] );
        // Put the color back, so the replay still has something to change and only the single-use rule can refuse it.
        $GLOBALS['__divi_gc']['custom'][ self::A ]['color'] = '#112233';
        $GLOBALS['__divi_gc']['cache']                       = null;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
    }

    public function testATokenMadeWithoutTheThemeFlagDoesNotOpenARunWithIt(): void
    {
        $entries = [ [ 'id' => self::A, 'color' => '#abcdef' ] ];
        $p       = $this->tool->run( [ 'colors' => $entries, 'dry_run' => true ] );
        $this->refused( [ 'colors' => $entries, 'allow_theme_colors' => true, 'preview_token' => $p['preview_token'] ], 'preview_token' );
    }

    public function testAnEntryChangedSinceThePreviewIsSkippedAndNeverOverwritten(): void
    {
        $args = [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ], [ 'id' => self::B, 'color' => '#fedcba' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gc']['custom'][ self::A ]['color'] = '#999999';
        $GLOBALS['__divi_gc']['cache']                       = null;

        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ self::B ], array_column( $r['changes'], 'id' ) );
        $this->assertSame( [ [ 'index' => 0, 'id' => self::A, 'reason' => 'changed_since_preview' ] ], $r['skipped'] );
        $this->assertSame( '#999999', GlobalColors::read()[ self::A ]['color'], 'the color changed behind our back is left as it is' );
        $this->assertSame( '#fedcba', GlobalColors::read()[ self::B ]['color'] );
    }

    public function testWhenEveryEntryWentStaleNothingIsWritten(): void
    {
        $args = [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gc']['custom'][ self::A ]['color'] = '#999999';
        $GLOBALS['__divi_gc']['cache']                       = null;
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertCount( 1, $r['skipped'] );
        $this->assertSame( [], $GLOBALS['__divi_gc']['set_calls'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testIfTheColorsChangeWhileTheCallRunsNothingIsWritten(): void
    {
        $args = [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gc']['gets']   = 0;
        $GLOBALS['__divi_gc']['on_get'] = static function ( int $n ): void {
            if ( 2 === $n ) { // the second read of the real run is the re-read just before the write
                $GLOBALS['__divi_gc']['custom'][ GlobalColorsFixture::B ]['color'] = '#777777';
                $GLOBALS['__divi_gc']['cache']                                    = null;
            }
        };
        // Not refused(): the hook itself changes the colors, so the "nothing changed" snapshot of that helper does not apply.
        try {
            $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
            $this->fail( 'expected a refusal' );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( 'changed while this call was running', $e->getMessage() );
        }
        $this->assertSame( [], $GLOBALS['__divi_gc']['set_calls'], 'Divi was not asked to write anything' );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( '#112233', GlobalColors::read()[ self::A ]['color'], 'the requested change was not applied' );
    }

    public function testAThemeColorNeedsTheFlagAndIsWrittenAndUndone(): void
    {
        $args = [ 'colors' => [ [ 'id' => 'gcid-primary-color', 'color' => '#ff0000' ] ] ];
        $this->refused( $args + [ 'dry_run' => true ], 'allow_theme_colors' );

        $p = $this->tool->run( $args + [ 'allow_theme_colors' => true, 'dry_run' => true ] );
        $this->assertTrue( $p['theme_colors_touched'] );
        $this->assertStringContainsString( 'whole theme', $p['message'] );
        $this->assertSame( '#2ea3f2', $GLOBALS['__divi_gc']['options']['accent_color'], 'a preview changes nothing' );

        $r = $this->tool->run( $args + [ 'allow_theme_colors' => true, 'preview_token' => $p['preview_token'] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertTrue( $r['theme_colors_touched'] );
        $this->assertSame( '#ff0000', $GLOBALS['__divi_gc']['options']['accent_color'] );
        $this->assertSame( '#ff0000', $r['changes'][0]['after']['color'], 'the read-back shows the NEW color even though Divi caches the theme colors for the request' );
        $sent = $GLOBALS['__divi_gc']['set_calls'][0];
        $this->assertSame( [ self::A, self::B, 'gcid-primary-color' ], array_keys( $sent ), 'only the theme color being changed is sent, after the complete custom map' );

        $u = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $this->assertSame( '#2ea3f2', $GLOBALS['__divi_gc']['options']['accent_color'] );
    }

    public function testWhenDiviStoresSomethingElseTheBatchRecordsWhatItStored(): void
    {
        $GLOBALS['__divi_gc']['mutate'] = static fn ( array $d ): array => isset( $d[ GlobalColorsFixture::A ] ) ? [ GlobalColorsFixture::A => [ 'color' => '#000000' ] + $d[ GlobalColorsFixture::A ] ] + $d : $d;
        $r = $this->apply( [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( '#000000', $r['changes'][0]['after']['color'] );
        $this->assertSame( self::A, $r['differs_from_request'][0]['id'] );
        $this->assertSame( '#abcdef', $r['differs_from_request'][0]['requested']['color'] );
        $this->assertSame( '#000000', $r['differs_from_request'][0]['stored']['color'] );
        $GLOBALS['__divi_gc']['mutate'] = null;
        $u = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'], 'what Divi really stored was recorded, so undo matches' );
        $this->assertSame( '#112233', GlobalColors::read()[ self::A ]['color'] );
    }

    private const JUNK = [ 'color' => '', 'folder' => '', 'label' => 'Junk', 'lastUpdated' => '2026-01-01T00:00:00.000Z', 'status' => 'active', 'usedInPosts' => [] ];

    public function testAPreExistingEntryDiviWouldDropIsRefusedInThePreviewAndWritesNothing(): void
    {
        GlobalColorsFixture::reset( [ 'gcid-empty-0000' => self::JUNK ] );
        $args = [ 'colors' => [ [ 'label' => 'Sky', 'color' => '#00aaff' ] ] ];
        $this->refused( $args + [ 'dry_run' => true ], 'gcid-empty-0000' );
        $this->refused( $args + [ 'dry_run' => true ], 'Divi would delete or damage' );
        $this->refused( $args + [ 'dry_run' => true ], "Divi's builder" );
        $this->assertSame( [], $GLOBALS['__divi_gc']['set_calls'] );
    }

    public function testAnEntryThatTurnsUnsafeAfterThePreviewIsRefusedBeforeTheWrite(): void
    {
        $args = [ 'colors' => [ [ 'label' => 'Sky', 'color' => '#00aaff' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gc']['custom']['gcid-empty-0000'] = self::JUNK;
        $GLOBALS['__divi_gc']['cache']                     = null;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'gcid-empty-0000' );
        $this->assertSame( [], $GLOBALS['__divi_gc']['set_calls'], 'Divi was not asked to write anything' );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testADropThePreflightCannotPredictIsStillReportedInUnexpectedChanges(): void
    {
        $GLOBALS['__divi_gc']['mutate'] = static function ( array $d ): array {
            unset( $d[ GlobalColorsFixture::B ] );

            return $d;
        };
        $r = $this->apply( [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ [ 'id' => self::B, 'reason' => 'removed_by_divi' ] ], $r['unexpected_changes'] );
    }

    public function testADryRunTakesNoLockAndARealRunHoldsItFromBeforeItsFirstReadUntilTheEnd(): void
    {
        $args = [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ];
        $seen = [];
        $GLOBALS['__divi_gc']['on_get'] = static function ( int $n ) use ( &$seen ): void {
            $seen[ $n ] = count( $GLOBALS['__wp_options'] );
        };
        $GLOBALS['__divi_gc']['gets'] = 0;
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertSame( [ 1 => 0 ], $seen, 'a dry run writes nothing, so it takes no lock' );

        $seen                         = [];
        $GLOBALS['__divi_gc']['gets'] = 0;
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'] );
        $this->assertSame( 1, $seen[1], 'the lock is already held at the very first read of the request' );
        $this->assertSame( 1, $seen[2], 'and at the re-read' );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'it is released at the end' );
    }

    public function testARealRunWhileAnotherChangeIsInProgressIsRefusedAndTheTokenSurvives(): void
    {
        $args  = [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ];
        $other = new GlobalColorsLock( 0 );
        $this->assertTrue( $other->acquire() );

        $p = $this->tool->run( $args + [ 'dry_run' => true ] ); // a preview needs no lock
        $this->assertArrayHasKey( 'preview_token', $p );
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'Another color change is in progress' );
        $this->assertCount( 1, $GLOBALS['__wp_options'], 'the other request still holds its lock' );

        $other->release();
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'], 'the token was not spent by the refusal' );
    }

    public function testTheLockIsReleasedWhenARealRunFails(): void
    {
        $args = [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gc']['throw_set'] = true;
        try {
            $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
            $this->fail( 'expected a failure' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( [], $GLOBALS['__wp_options'] );
        }
        $this->refused( $args + [ 'preview_token' => 'garbage.token' ], 'preview_token' );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'a refusal releases it too' );
    }

    public function testWhenDiviCannotSaveNothingIsRecorded(): void
    {
        $args = [ 'colors' => [ [ 'id' => self::A, 'color' => '#abcdef' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gc']['throw_set'] = true;
        try {
            $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
            $this->fail( 'expected a failure' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'nothing is recorded', $e->getMessage() );
        }
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( '#112233', GlobalColors::read()[ self::A ]['color'] );
    }

    public function testWhenDiviCannotBeReadTheCallIsRefused(): void
    {
        $GLOBALS['__divi_gc']['throw_get'] = true;
        $this->refused( [ 'colors' => [ [ 'label' => 'Sky', 'color' => '#00aaff' ] ], 'dry_run' => true ], 'not available' );
    }

    public function testArgumentsAreTypeChecked(): void
    {
        $ok = [ 'label' => 'Sky', 'color' => '#00aaff' ];
        $this->refused( [], '"colors" is required' );
        $this->refused( [ 'colors' => [] ], 'must not be empty' );
        $this->refused( [ 'colors' => 'red' ], 'must be a list' );
        $this->refused( [ 'colors' => [ 'label' => 'Sky', 'color' => '#00aaff' ] ], 'must be a list' );
        $this->refused( [ 'colors' => array_fill( 0, 51, $ok ) ], 'at most 50 items' );
        $this->refused( [ 'colors' => [ $ok ], 'dry_run' => 'yes' ], '"dry_run" must be true or false' );
        $this->refused( [ 'colors' => [ $ok ], 'allow_theme_colors' => 1 ], '"allow_theme_colors" must be true or false' );
        $this->refused( [ 'colors' => [ $ok ], 'preview_token' => [ 'x' ] ], '"preview_token" must be a string' );
        $this->refused( [ 'colors' => [ [ 'label' => 'Sky', 'color' => 'rgb(0,0,0);' ] ], 'dry_run' => true ], '"color" must be a hex value' );
        $this->assertTrue( $this->tool->run( [ 'colors' => array_map( fn ( int $i ): array => [ 'label' => "C{$i}", 'color' => '#00aaff' ], range( 1, 50 ) ), 'dry_run' => true ] )['dry_run'], 'exactly 50 is allowed' );
    }
}
