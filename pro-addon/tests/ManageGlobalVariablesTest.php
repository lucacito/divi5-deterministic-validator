<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\GlobalVariableKind;
use AiEditorDivi5\Pro\Settings\GlobalVariables;
use AiEditorDivi5\Pro\Settings\GlobalVariablesLock;
use AiEditorDivi5\Pro\Tools\ManageGlobalVariables;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/GlobalVariablesFixture.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/WpBuildShims.php';

class ManageGlobalVariablesTest extends TestCase
{
    private const N1  = GlobalVariablesFixture::N1;
    private const N2  = GlobalVariablesFixture::N2;
    private const S1  = GlobalVariablesFixture::S1;
    private const IMG = GlobalVariablesFixture::IMG;

    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private ManageGlobalVariables $tool;
    private int $now = 1790000000;
    private int $seq = 0;

    protected function setUp(): void
    {
        GlobalVariablesFixture::reset();
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->seq  = 0;
        $this->tool = new ManageGlobalVariables( $this->log, fn (): int => $this->now, function (): string {
            return 'gvid-new-00000000-0000-4000-8000-00000000000' . ++$this->seq;
        }, new GlobalVariablesLock( 0 ) );
    }

    protected function tearDown(): void
    {
        GlobalVariablesFixture::clear();
    }

    /** Preview, then apply with the returned token. @param array<string,mixed> $args @return array<string,mixed> */
    private function apply( array $args ): array
    {
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertArrayHasKey( 'preview_token', $p );

        return $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
    }

    /** @return array<string,mixed> what a refusal must leave alone */
    private function snapshot(): array
    {
        return [ $GLOBALS['__divi_gv']['stored'], count( $GLOBALS['__divi_gv']['set_calls'] ), count( $this->repo->rows ), $GLOBALS['__wp_options'] ];
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
        return new BatchUndo( [ GlobalVariableKind::NAME => new GlobalVariableKind( new GlobalVariablesLock( 0 ) ) ], $this->log );
    }

    public function testTheDefinitionIsValidForMcpAndRest(): void
    {
        $d = $this->tool->definition();
        $this->assertSame( 'manage_global_variables', $d['name'] );
        $this->assertSame( 'edit_theme_options', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'variables' ], $d['inputSchema']['required'] );
        foreach ( [ 'variables', 'dry_run', 'preview_token' ] as $p ) {
            $this->assertArrayHasKey( $p, $d['inputSchema']['properties'] );
        }
        $this->assertSame( 50, $d['inputSchema']['properties']['variables']['maxItems'] );
        $this->assertSame( [ 'numbers', 'strings' ], $d['inputSchema']['properties']['variables']['items']['properties']['type']['enum'] );
        $this->assertSame( [ 'active', 'archived' ], $d['inputSchema']['properties']['variables']['items']['properties']['status']['enum'] );
    }

    public function testADryRunReportsTheChangesAndATokenAndWritesNothing(): void
    {
        $r = $this->tool->run( [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ], [ 'id' => self::S1, 'value' => 'Call now' ] ], 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 1800, $r['expires_in'] );
        $this->assertNotSame( '', $r['preview_token'] );
        $this->assertSame( [ 'create', 'update' ], array_column( $r['changes'], 'action' ) );
        $this->assertNull( $r['changes'][0]['id'], 'the id of a new variable is assigned when it is applied' );
        $this->assertSame( 'numbers', $r['changes'][0]['type'] );
        $this->assertSame( self::S1, $r['changes'][1]['id'] );
        $this->assertSame( '555 0100', $r['changes'][1]['before']['value'] );
        $this->assertSame( 'Call now', $r['changes'][1]['after']['value'] );
        $this->assertSame( [], $GLOBALS['__divi_gv']['set_calls'], 'a preview writes nothing' );
        $this->assertSame( [], $this->repo->rows );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'and takes no lock' );
    }

    public function testARealRunCreatesBothTypesInOneCompleteWriteAndRecordsAnUndoableBatch(): void
    {
        $startImages = GlobalVariables::read()['images'];
        $r           = $this->apply( [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ], [ 'type' => 'strings', 'label' => 'Tagline', 'value' => 'Built with care' ] ] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ 'create', 'create' ], array_column( $r['changes'], 'action' ) );
        $this->assertTrue( $r['batch']['undoable'] );
        $all = GlobalVariables::read();
        $stamp = '2026-09-21T14:13:20.000Z'; // gmdate of 1790000000
        $this->assertSame( [ 'id' => $r['changes'][0]['id'], 'label' => 'Pad', 'value' => '8px', 'order' => '3', 'status' => 'active', 'lastUpdated' => $stamp, 'variableType' => 'numbers' ], $all['numbers'][ $r['changes'][0]['id'] ] );
        $this->assertSame( [ 'id' => $r['changes'][1]['id'], 'label' => 'Tagline', 'value' => 'Built with care', 'order' => '2', 'status' => 'active', 'lastUpdated' => $stamp, 'variableType' => 'strings' ], $all['strings'][ $r['changes'][1]['id'] ] );
        $this->assertSame( $startImages, $all['images'], 'the unmanaged group is untouched' );
        $this->assertCount( 1, $GLOBALS['__divi_gv']['set_calls'], 'exactly one complete write' );
        $sent = $GLOBALS['__divi_gv']['set_calls'][0];
        $this->assertSame( [ 'numbers', 'strings', 'images' ], array_keys( $sent ), 'no empty group, no colors, no customizer fonts' );
        $this->assertArrayNotHasKey( 'allowedActions', $sent['numbers'][ self::N1 ] );
        $this->assertSame( GlobalVariablesFixture::stored()['numbers'][ self::N1 ], $sent['numbers'][ self::N1 ], 'an untouched entry goes back exactly as stored' );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'the lock is released' );
        $this->assertStringContainsString( 'gvid id', $r['note'] );
    }

    public function testEditArchiveAndRestoreAreNamedForWhatTheyDo(): void
    {
        $r = $this->apply( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ], [ 'id' => self::S1, 'status' => 'archived' ], [ 'id' => self::N2, 'status' => 'active' ] ] ] );
        $this->assertSame( [ 'update', 'archive', 'restore' ], array_column( $r['changes'], 'action' ) );
        $all = GlobalVariables::read();
        $this->assertSame( '16px', $all['numbers'][ self::N1 ]['value'] );
        $this->assertSame( 'archived', $all['strings'][ self::S1 ]['status'] );
        $this->assertSame( 'active', $all['numbers'][ self::N2 ]['status'] );
    }

    public function testAVariableThatAlreadyIsLikeThatChangesNothing(): void
    {
        $r = $this->tool->run( [ 'variables' => [ [ 'id' => self::N1, 'value' => '12px' ] ] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ self::N1 ], $r['unchanged'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testARealRunNeedsTheTokenAndItWorksOnce(): void
    {
        $args = [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ] ] ];
        $this->refused( $args, 'preview_token' );
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'] );
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
    }

    public function testATokenForOtherArgumentsAnotherUserOrAnExpiredOneIsRefused(): void
    {
        $args = [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->refused( [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '9px' ] ], 'preview_token' => $p['preview_token'] ], 'preview_token' );
        $GLOBALS['__wp_current_user_id'] = 8;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
        $GLOBALS['__wp_current_user_id'] = 7;
        $this->now += 1801;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'preview_token' );
    }

    public function testAVariableChangedSinceThePreviewIsSkippedAndReportedNeverOverwritten(): void
    {
        $args = [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gv']['stored']['numbers'][ self::N1 ]['value'] = '99px';
        $GLOBALS['__divi_gv']['cache']                                  = null;
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( 'changed_since_preview', $r['skipped'][0]['reason'] );
        $this->assertSame( '99px', GlobalVariables::read()['numbers'][ self::N1 ]['value'] );
        $this->assertSame( [], $GLOBALS['__divi_gv']['set_calls'] );
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function refusals(): array
    {
        $many = array_fill( 0, 51, [ 'type' => 'numbers', 'label' => 'x', 'value' => '1px' ] );

        return [
            'no variables'         => [ [], 'variables' ],
            'an empty list'        => [ [ 'variables' => [] ], 'variables' ],
            'more than 50'         => [ [ 'variables' => $many ], 'variables' ],
            'variables not a list' => [ [ 'variables' => 'x' ], 'variables' ],
            'dry_run not a bool'   => [ [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'x', 'value' => '1px' ] ], 'dry_run' => 'yes' ], 'dry_run' ],
            'unknown id'           => [ [ 'variables' => [ [ 'id' => 'gvid-doesnotexist-0000', 'value' => '1px' ] ], 'dry_run' => true ], 'no variable with the id' ],
            'an images id'         => [ [ 'variables' => [ [ 'id' => self::IMG, 'label' => 'x' ] ], 'dry_run' => true ], 'only numbers and strings' ],
            'a bad number'         => [ [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'x', 'value' => 'calc(1px; x)' ] ], 'dry_run' => true ], 'numbers variable' ],
            'a bad string'         => [ [ 'variables' => [ [ 'type' => 'strings', 'label' => 'x', 'value' => '}body{' ] ], 'dry_run' => true ], 'strings variable' ],
            'type on an update'    => [ [ 'variables' => [ [ 'id' => self::N1, 'type' => 'strings', 'value' => 'x' ] ], 'dry_run' => true ], 'never changes type' ],
        ];
    }

    /** @param array<string,mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'refusals' )]
    public function testRefusalsChangeNothing( array $args, string $needle ): void
    {
        $this->refused( $args, $needle );
    }

    public function testWithoutDivisVariablesManagerPermissionEvenAPreviewIsRefusedUpFront(): void
    {
        $GLOBALS['__divi_role_allowed'] = false;
        $this->refused( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ], 'dry_run' => true ], 'Variables Manager' );
        $GLOBALS['__divi_role_allowed'] = true;
        $GLOBALS['__wp_caps']           = [ 'edit_theme_options' => false ];
        $this->refused( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ], 'dry_run' => true ], 'Variables Manager' );
    }

    public function testItFailsClosedWhenDiviCannotBeRead(): void
    {
        $GLOBALS['__divi_gv']['throw_get'] = true;
        $this->refused( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ], 'dry_run' => true ], 'not available' );
    }

    public function testASaveThatThrowsAndStoresNothingIsAnErrorNotASuccess(): void
    {
        $args = [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gv']['throw_set'] = true;
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
        $args                           = [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ], [ 'id' => self::N1, 'value' => '16px' ] ] ];
        $p                              = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gv']['mutate'] = static fn ( array $data ): array => GlobalVariablesFixture::stored(); // Divi keeps what was there
        $r                              = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [], $this->repo->rows, 'nothing recorded, nothing to undo' );
        $this->assertSame( [ 'Pad' ], array_column( $r['not_stored'], 'label' ) );
        $this->assertSame( [ self::N1 ], $r['not_changed'] );
        $this->assertStringContainsString( 'Variables Manager', $r['message'] );
    }

    public function testAnEntryDiviDropsWhileSavingIsReportedAsUnexpected(): void
    {
        $args = [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );
        $GLOBALS['__divi_gv']['mutate'] = static function ( array $data ): array {
            unset( $data['strings'][ self::S1 ] );

            return $data;
        };
        $r = $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ [ 'id' => self::S1, 'reason' => 'removed_by_divi' ] ], $r['unexpected_changes'] );
    }

    public function testAStoredColorsGroupIsRefusedBeforeAnythingIsWritten(): void
    {
        GlobalVariablesFixture::reset( [ 'colors' => [ 'gvid-cccccccc-0000-4000-8000-000000000001' => [ 'label' => 'Brand', 'value' => '#fff', 'order' => '1', 'status' => 'active' ] ] ] );
        $this->refused( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ], 'dry_run' => true ], 'would delete or damage' );
        $this->refused( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ], 'dry_run' => true ], 'colors' );
    }

    public function testAStoredEntryDiviWouldDropIsRefusedNamingItsId(): void
    {
        GlobalVariablesFixture::reset( [ 'strings' => [ self::S1 => [ 'label' => '', 'value' => 'x', 'order' => '1', 'status' => 'active' ] ] ] );
        $this->refused( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ], 'dry_run' => true ], self::S1 );
    }

    public function testADryRunTakesNoLockAndARealRunHoldsItFromBeforeItsFirstReadUntilTheEnd(): void
    {
        $args = [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ] ];
        $seen = [];
        $GLOBALS['__divi_gv']['on_get'] = static function ( int $n ) use ( &$seen ): void {
            $seen[ $n ] = count( $GLOBALS['__wp_options'] );
        };
        $GLOBALS['__divi_gv']['gets'] = 0;
        $p = $this->tool->run( $args + [ 'dry_run' => true ] );
        $this->assertSame( [ 1 => 0 ], $seen, 'a dry run writes nothing, so it takes no lock' );

        $seen                         = [];
        $GLOBALS['__divi_gv']['gets'] = 0;
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'] );
        $this->assertSame( 1, $seen[1], 'the lock is already held at the very first read of the request' );
        $this->assertSame( [], $GLOBALS['__wp_options'], 'it is released at the end' );
    }

    public function testARealRunRefusedByThePreflightDoesNotSpendTheToken(): void
    {
        $args = [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ] ];
        $p    = $this->tool->run( $args + [ 'dry_run' => true ] );

        // after the preview a colors group appears (Divi's save would erase it), and the cached read is dropped
        $GLOBALS['__divi_gv']['stored']['colors'] = [ 'gvid-cccccccc-0000-4000-8000-000000000001' => [ 'label' => 'Brand', 'value' => '#fff', 'order' => '1', 'status' => 'active' ] ];
        $GLOBALS['__divi_gv']['cache']            = null;
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'would delete or damage' );

        unset( $GLOBALS['__divi_gv']['stored']['colors'] );
        $GLOBALS['__divi_gv']['cache'] = null;
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'], 'the refusal did not burn the token' );
    }

    public function testARealRunWhileAnotherChangeIsInProgressIsRefusedWithoutSpendingTheToken(): void
    {
        $args  = [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ] ];
        $other = new GlobalVariablesLock( 0 );
        $this->assertTrue( $other->acquire() );
        $p = $this->tool->run( $args + [ 'dry_run' => true ] ); // a preview needs no lock
        $this->refused( $args + [ 'preview_token' => $p['preview_token'] ], 'in progress' );
        $this->assertCount( 1, $GLOBALS['__wp_options'], 'the other request still holds its lock' );
        $other->release();
        $this->assertTrue( $this->tool->run( $args + [ 'preview_token' => $p['preview_token'] ] )['changed'], 'the token was not spent by the refused attempt' );
    }

    public function testUndoPutsEverythingBackAndEveryOtherEntryIsByteIdentical(): void
    {
        $start = GlobalVariables::read();
        $r     = $this->apply( [ 'variables' => [ [ 'type' => 'numbers', 'label' => 'Pad', 'value' => '8px' ], [ 'id' => self::S1, 'status' => 'archived' ], [ 'id' => self::N1, 'value' => '16px' ] ] ] );
        $this->assertNotSame( $start, GlobalVariables::read() );
        $u = $this->undo()->undo( (int) $r['batch']['id'] );
        $this->assertTrue( $u['fully_undone'] );
        $now = GlobalVariables::read();
        foreach ( [ [ 'numbers', self::N1 ], [ 'strings', self::S1 ] ] as [ $type, $id ] ) { // the two entries undo restores are repaired to the builder shape
            $this->assertSame( $id, $now[ $type ][ $id ]['id'] );
            $this->assertSame( $type, $now[ $type ][ $id ]['variableType'] );
            $this->assertArrayHasKey( 'lastUpdated', $now[ $type ][ $id ] );
            unset( $now[ $type ][ $id ]['id'], $now[ $type ][ $id ]['variableType'], $now[ $type ][ $id ]['lastUpdated'] );
        }
        $this->assertSame( $start, $now, 'every other entry, and the restored ones apart from those three keys, is byte-identical' );
    }

    public function testAnEditRefreshesOnlyTheEditedEntriesLastUpdatedAndUntouchedEntriesGoBackByteIdentical(): void
    {
        $r = $this->apply( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ] ] );
        $this->assertTrue( $r['changed'] );
        $sent = $GLOBALS['__divi_gv']['set_calls'][0];
        $this->assertSame( GlobalVariablesFixture::stored()['numbers'][ self::N2 ], $sent['numbers'][ self::N2 ], 'an untouched entry keeps its stored form: no lastUpdated is added' );
        $this->assertSame( GlobalVariablesFixture::stored()['strings'][ self::S1 ], $sent['strings'][ self::S1 ] );
        $this->assertSame( GlobalVariablesFixture::stored()['images'][ self::IMG ], $sent['images'][ self::IMG ] );
        $e = $sent['numbers'][ self::N1 ];
        $this->assertSame( '2026-09-21T14:13:20.000Z', $e['lastUpdated'], 'the tool clock drives the stamp' );
        $this->assertSame( self::N1, $e['id'] );
        $this->assertSame( 'numbers', $e['variableType'] );
        $this->assertSame( '16px', $e['value'] );
        $this->assertSame( '1', $e['order'] );
    }

    public function testTheStampFollowsTheClockOfTheCall(): void
    {
        $this->now = 1790003600; // one hour later
        $r         = $this->apply( [ 'variables' => [ [ 'type' => 'strings', 'label' => 'Tagline', 'value' => 'Hi' ] ] ] );
        $this->assertSame( '2026-09-21T15:13:20.000Z', GlobalVariables::read()['strings'][ $r['changes'][0]['id'] ]['lastUpdated'] );
    }

    public function testTheCreatedAndEditedEntriesAreNotSeenAsChangedByTheStateComparison(): void
    {
        $r = $this->apply( [ 'variables' => [ [ 'id' => self::N1, 'value' => '16px' ] ] ] );
        $this->assertSame( [ 'label' => 'Radius', 'value' => '16px', 'status' => 'active' ], $r['changes'][0]['after'] );
        $this->assertArrayNotHasKey( 'differs_from_request', $r );
        $this->assertArrayNotHasKey( 'unexpected_changes', $r );
    }
}
