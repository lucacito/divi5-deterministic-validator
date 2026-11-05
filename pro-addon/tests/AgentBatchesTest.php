<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Companion\AgentBatches;
use AiEditorDivi5\Pro\Companion\AgentBatchUndo;
use AiEditorDivi5\Pro\Companion\AgentSessions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

/** Batches of changes made by any agent, worked out from the free plugin's history rows, and what undoing one may touch. */
class AgentBatchesTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_options'] = [];
    }

    /** @return array{page_id:int, version_id:int, saved_at:string, tool:string, actor:int, label:?string} */
    private function row( int $page, int $version, string $at, string $tool = 'application_password', int $actor = 1, ?string $label = 'Cursor' ): array
    {
        return [ 'page_id' => $page, 'version_id' => $version, 'saved_at' => $at, 'tool' => $tool, 'actor' => $actor, 'label' => $label ];
    }

    public function testChangesFromOneSourceCloseTogetherAreOneBatchAndTheOldestSnapshotIsTheRestorePoint(): void
    {
        $b = AgentBatches::group( [
            $this->row( 5, 3, '2026-11-05T10:05:00Z' ),
            $this->row( 5, 2, '2026-11-05T10:01:00Z' ),
            $this->row( 6, 1, '2026-11-05T10:03:00Z' ),
        ] );
        $this->assertCount( 1, $b );
        $this->assertSame( 'inferred', $b[0]['kind'] );
        $this->assertSame( 3, $b[0]['changes'] );
        $this->assertSame( 2, $b[0]['page_count'] );
        $this->assertSame( 'application password Cursor', $b[0]['label'] );
        $byPage = array_column( $b[0]['pages'], null, 'page_id' );
        $this->assertSame( 2, $byPage[5]['restore_version_id'], 'page 5 goes back to how it was before the FIRST change of the batch' );
        $this->assertSame( '2026-11-05T10:05:00Z', $byPage[5]['last_saved_at'] );
        $this->assertSame( 1, $byPage[6]['restore_version_id'] );
        $this->assertMatchesRegularExpression( '/^inferred-1-[0-9a-f]{6}-\d+$/', $b[0]['id'] );
    }

    public function testASilenceLongerThanTheWindowStartsANewBatch(): void
    {
        $b = AgentBatches::group( [ $this->row( 5, 1, '2026-11-05T10:00:00Z' ), $this->row( 5, 2, '2026-11-05T10:20:00Z' ) ] );
        $this->assertCount( 2, $b );
        $this->assertNotSame( $b[0]['id'], $b[1]['id'] );
    }

    public function testDifferentUsersAndDifferentSourcesAreDifferentBatches(): void
    {
        $b = AgentBatches::group( [
            $this->row( 5, 1, '2026-11-05T10:00:00Z' ),
            $this->row( 6, 1, '2026-11-05T10:00:30Z', 'application_password', 2 ),
            $this->row( 7, 1, '2026-11-05T10:01:00Z', 'application_password', 1, 'Claude' ),
            $this->row( 8, 1, '2026-11-05T10:01:30Z', 'ability', 1, 'divi/update' ),
        ] );
        $this->assertCount( 4, $b );
        $this->assertContains( 'ability divi/update', array_column( $b, 'label' ) );
    }

    public function testThePluginsOwnToolsAreOneSourceAndRestoresAreNotChanges(): void
    {
        $b = AgentBatches::group( [
            $this->row( 5, 1, '2026-11-05T10:00:00Z', 'update_page_layout', 1, null ),
            $this->row( 6, 1, '2026-11-05T10:02:00Z', 'edit_page_content', 1, null ),
            $this->row( 5, 2, '2026-11-05T10:03:00Z', 'restore', 1, 'before restore of #1' ),
        ] );
        $this->assertCount( 1, $b );
        $this->assertSame( 2, $b[0]['changes'], 'a restore is an undo, not part of what is undone' );
        $this->assertSame( 'JHMG AI Editor tools', $b[0]['label'] );
    }

    public function testAnExplicitSessionTakesEveryChangeOfThatUserWhateverTheSource(): void
    {
        $sessions = [ [ 'token' => 'abc123', 'user' => 1, 'label' => 'Redesign', 'started' => '2026-11-05T09:00:00Z', 'ended' => '2026-11-05T11:00:00Z' ] ];
        $b = AgentBatches::group( [
            $this->row( 5, 1, '2026-11-05T09:10:00Z', 'ability', 1, 'divi/x' ),
            $this->row( 6, 1, '2026-11-05T10:50:00Z', 'rest', 1, null ),
            $this->row( 7, 1, '2026-11-05T09:30:00Z', 'rest', 2, null ),
            $this->row( 8, 1, '2026-11-05T12:00:00Z', 'rest', 1, null ),
        ], $sessions, '2026-11-05T13:00:00Z' );
        $session = array_values( array_filter( $b, static fn ( $x ) => 'session' === $x['kind'] ) )[0];
        $this->assertSame( 'session-abc123', $session['id'] );
        $this->assertSame( 'Redesign', $session['label'] );
        $this->assertSame( [ 5, 6 ], array_column( $session['pages'], 'page_id' ), 'only that user, only inside the session' );
        $this->assertCount( 3, $b, 'the other user and the later change stay inferred batches of their own' );
    }

    public function testAnOpenSessionRunsUntilNow(): void
    {
        $sessions = [ [ 'token' => 'o1', 'user' => 1, 'label' => 'x', 'started' => '2026-11-05T09:00:00Z', 'ended' => null ] ];
        $b = AgentBatches::group( [ $this->row( 5, 1, '2026-11-05T12:00:00Z' ) ], $sessions, '2026-11-05T13:00:00Z' );
        $this->assertSame( 'session', $b[0]['kind'] );
    }

    // ---- undo planning -------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function batch(): array
    {
        return AgentBatches::group( [ $this->row( 5, 2, '2026-11-05T10:00:00Z' ), $this->row( 6, 1, '2026-11-05T10:01:00Z' ) ] )[0];
    }

    private function plan( callable $page, array $rowsAfter = [], ?callable $exists = null ): array
    {
        return AgentBatchUndo::plan( $this->batch(), $page, $rowsAfter, $exists ?? static fn () => true );
    }

    public function testPagesStillAsTheBatchLeftThemAreRestored(): void
    {
        $r = $this->plan( static fn ( int $id ) => [ 'modified_gmt' => 5 === $id ? '2026-11-05 10:00:02' : '2026-11-05 10:01:02', 'can_edit' => true ] );
        $this->assertSame( [ [ 'page_id' => 5, 'version_id' => 2 ], [ 'page_id' => 6, 'version_id' => 1 ] ], $r['restore'] );
        $this->assertSame( [], $r['skipped'] );
    }

    public function testAPageChangedAfterTheBatchIsSkippedNeverOverwritten(): void
    {
        $r = $this->plan( static fn ( int $id ) => [ 'modified_gmt' => 5 === $id ? '2026-11-05 11:30:00' : '2026-11-05 10:01:01', 'can_edit' => true ] );
        $this->assertSame( [ 6 ], array_column( $r['restore'], 'page_id' ) );
        $this->assertSame( [ [ 'page_id' => 5, 'reason' => 'changed_afterwards' ] ], array_map( static fn ( $s ) => [ 'page_id' => $s['page_id'], 'reason' => $s['reason'] ], $r['skipped'] ) );
    }

    public function testANewerSnapshotFromAnotherSourceAlsoMeansChangedAfterwards(): void
    {
        $r = $this->plan(
            static fn ( int $id ) => [ 'modified_gmt' => 5 === $id ? '2026-11-05 10:00:02' : '2026-11-05 10:01:02', 'can_edit' => true ],
            [ [ 'page_id' => 6, 'version_id' => 4, 'saved_at' => '2026-11-05T10:30:00Z' ] ]
        );
        $this->assertSame( [ 5 ], array_column( $r['restore'], 'page_id' ) );
        $this->assertSame( 'changed_afterwards', $r['skipped'][0]['reason'] );
    }

    public function testMissingForbiddenAndLostSnapshotPagesAreSkippedWithTheirReason(): void
    {
        $page = static fn ( int $id ) => 5 === $id ? null : [ 'modified_gmt' => '2026-11-05 10:01:02', 'can_edit' => false ];
        $r    = $this->plan( $page );
        $this->assertSame( [], $r['restore'] );
        $this->assertSame( [ 'not_found', 'forbidden' ], array_column( $r['skipped'], 'reason' ) );

        $r = $this->plan( static fn ( int $id ) => [ 'modified_gmt' => 5 === $id ? '2026-11-05 10:00:02' : '2026-11-05 10:01:02', 'can_edit' => true ], [], static fn ( int $p, int $v ) => 5 !== $p );
        $this->assertSame( 'snapshot_missing', $r['skipped'][0]['reason'] );
        $this->assertSame( [ 6 ], array_column( $r['restore'], 'page_id' ) );
    }

    public function testThePlanOnlyEverRestoresContentItNeverDeletesAPage(): void
    {
        $r = $this->plan( static fn ( int $id ) => null );
        $this->assertSame( [], $r['restore'] );
        foreach ( [ 'AgentBatchUndo.php', 'BatchService.php' ] as $file ) {
            $src = (string) file_get_contents( __DIR__ . '/../src/Companion/' . $file );
            foreach ( [ 'wp_delete_post', 'wp_trash_post', 'wp_untrash_post', 'wp_delete_' ] as $bad ) {
                $this->assertStringNotContainsString( $bad, $src, "{$file} must never delete or trash ({$bad})" );
            }
        }
    }

    // ---- sessions ------------------------------------------------------------------------

    public function testStartingASessionClosesTheUsersOpenOneAndKeepsOnlyTheLast20(): void
    {
        $a = AgentSessions::start( 1, "Redesign\n<b>home</b>", '2026-11-05T09:00:00Z', 'tok1' );
        $this->assertSame( 'Redesign b home /b', $a['label'] );
        AgentSessions::start( 1, '', '2026-11-05T10:00:00Z', 'tok2' );
        $all = AgentSessions::all();
        $this->assertSame( '2026-11-05T10:00:00Z', $all[0]['ended'], 'the first was closed when the second started' );
        $this->assertNull( $all[1]['ended'] );
        $this->assertSame( 'Agent session', $all[1]['label'] );

        for ( $i = 0; $i < 25; ++$i ) {
            AgentSessions::start( 2, 'x', '2026-11-05T11:00:00Z', 'u' . $i );
        }
        $this->assertCount( AgentSessions::KEEP, AgentSessions::all() );
    }

    public function testEndingClosesOnlyTheCallersSession(): void
    {
        AgentSessions::start( 1, 'a', '2026-11-05T09:00:00Z', 't1' );
        AgentSessions::start( 2, 'b', '2026-11-05T09:00:00Z', 't2' );
        $ended = AgentSessions::end( 1, '2026-11-05T09:30:00Z' );
        $this->assertSame( 't1', $ended['token'] );
        $this->assertNull( AgentSessions::end( 1 ), 'nothing left to end' );
        $open = array_values( array_filter( AgentSessions::all(), static fn ( $s ) => null === $s['ended'] ) );
        $this->assertSame( 't2', $open[0]['token'] );
    }
}
