<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\WpdbBatchRepository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/BatchStubWpdb.php';

class WpdbBatchRepositoryTest extends TestCase
{
    private WpdbBatchRepository $repo;

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new BatchStubWpdb();
        $this->repo      = new WpdbBatchRepository();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
    }

    /** @return array<string,mixed> */
    private function row( string $summary = 's' ): array
    {
        return [ 'user_id' => 7, 'tool' => 't', 'created_at' => '2026-10-02 10:00:00', 'summary' => $summary, 'status' => 'applied', 'items' => [ [ 'kind' => 'k', 'before' => 'é "q" \\ </p>' ] ] ];
    }

    public function testInsertEncodesTheItemsAndReturnsTheId(): void
    {
        $id = $this->repo->insert( $this->row() );
        $this->assertSame( 1, $id );
        $stored = $GLOBALS['wpdb']->rows[1];
        $this->assertSame( [ [ 'kind' => 'k', 'before' => 'é "q" \\ </p>' ] ], json_decode( $stored['items'], true ) );
        $this->assertSame( 'wp_aied_pro_batches', $GLOBALS['wpdb']->calls[0][1] );
    }

    public function testInsertReturnsNullWhenTheDatabaseRefuses(): void
    {
        $GLOBALS['wpdb']->failInsert = true;
        $this->assertNull( $this->repo->insert( $this->row() ) );
    }

    public function testInsertReturnsNullWhenTheItemsCannotBeEncoded(): void
    {
        $row                      = $this->row();
        $row['items'][0]['before'] = "\xB1\x31";   // invalid UTF-8
        $this->assertNull( $this->repo->insert( $row ) );
        $this->assertSame( [], $GLOBALS['wpdb']->rows, 'nothing half-written' );
    }

    public function testFindDecodesTheRowIntoTypedValues(): void
    {
        $this->repo->insert( $this->row( 'hello' ) );
        $b = $this->repo->find( 1 );
        $this->assertSame( 1, $b['id'] );
        $this->assertSame( 7, $b['user_id'] );
        $this->assertSame( 'hello', $b['summary'] );
        $this->assertSame( 'k', $b['items'][0]['kind'] );
        $this->assertNull( $this->repo->find( 99 ) );
    }

    public function testRecentReturnsNewestFirst(): void
    {
        $this->repo->insert( $this->row( 'one' ) );
        $this->repo->insert( $this->row( 'two' ) );
        $this->assertSame( [ 'two', 'one' ], array_column( $this->repo->recent( 10 ), 'summary' ) );
    }

    public function testUpdateWritesStatusAndItems(): void
    {
        $this->repo->insert( $this->row() );
        $this->assertTrue( $this->repo->update( 1, 'undone', [ [ 'state' => 'undone' ] ] ) );
        $b = $this->repo->find( 1 );
        $this->assertSame( 'undone', $b['status'] );
        $this->assertSame( 'undone', $b['items'][0]['state'] );
        $this->assertFalse( $this->repo->update( 99, 'undone', [] ) );
    }

    public function testTrimDeletesOnlyTheRowsBeyondTheNewestN(): void
    {
        $GLOBALS['wpdb']->col = [ '5', '3', '1' ];
        $this->repo->trim( 20 );
        $queries = array_values( array_filter( $GLOBALS['wpdb']->calls, static fn ( array $c ): bool => 'query' === $c[0] ) );
        $this->assertSame( 'DELETE FROM wp_aied_pro_batches WHERE id IN (5,3,1)', $queries[0][1] );
        $this->assertStringContainsString( 'OFFSET 20', $GLOBALS['wpdb']->calls[0][1] );
    }

    public function testTrimWithNothingToTrimRunsNoDelete(): void
    {
        $GLOBALS['wpdb']->col = [];
        $this->repo->trim( 20 );
        $this->assertSame( [], array_filter( $GLOBALS['wpdb']->calls, static fn ( array $c ): bool => 'query' === $c[0] ) );
    }

    public function testAppendAddsToTheStoredItemsAndKeepsTheStatus(): void
    {
        $row           = $this->row();
        $row['status'] = 'building';
        $this->repo->insert( $row );
        $this->assertTrue( $this->repo->append( 1, [ [ 'kind' => 'second' ], [ 'kind' => 'third' ] ] ) );
        $b = $this->repo->find( 1 );
        $this->assertSame( 'building', $b['status'] );
        $this->assertSame( [ 'k', 'second', 'third' ], array_column( $b['items'], 'kind' ) );
        $this->assertFalse( $this->repo->append( 99, [ [ 'kind' => 'x' ] ] ) );
    }

    public function testOpenBuildAsksForTheUsersNewestBuildingBatch(): void
    {
        $open           = $this->row( 'older build' );
        $open['status'] = 'building';
        $this->repo->insert( $open );
        $this->repo->insert( $this->row( 'applied' ) );
        $newer           = $this->row( 'newer build' );
        $newer['status'] = 'building';
        $this->repo->insert( $newer );
        $found = $this->repo->openBuild( 7 );
        $this->assertSame( 'newer build', $found['summary'] );
        $last = end( $GLOBALS['wpdb']->calls );
        $this->assertStringContainsString( "status = 'building'", $last[1] );
        $this->assertStringContainsString( 'user_id = 7', $last[1] );
        $this->assertNull( $this->repo->openBuild( 8 ) );
    }

    public function testTrimNeverSelectsAnOpenBuildForDeletion(): void
    {
        $GLOBALS['wpdb']->col = [ '5' ];
        $this->repo->trim( 20 );
        $this->assertStringContainsString( "status <> 'building'", $GLOBALS['wpdb']->calls[0][1] );
    }

    public function testAppendOnlyLandsOnABatchThatIsStillOpen(): void
    {
        $this->repo->insert( $this->row() );   // status applied
        $this->assertFalse( $this->repo->append( 1, [ [ 'kind' => 'x' ] ] ) );
        $this->assertCount( 1, $this->repo->find( 1 )['items'] );

        $open           = $this->row( 'open' );
        $open['status'] = 'building';
        $this->repo->insert( $open );
        $this->assertTrue( $this->repo->append( 2, [ [ 'kind' => 'x' ] ] ) );
        $update = array_values( array_filter( $GLOBALS['wpdb']->calls, static fn ( array $c ): bool => 'update' === $c[0] ) );
        $last   = end( $update );
        $this->assertSame( [ 'id' => 2, 'status' => 'building' ], $last[3], 'the write is conditional on the status still being building, so an undo that closed the build in between wins' );
        $this->assertSame( [ 'items' ], array_keys( $last[2] ), 'and it writes the items only, never a stale status' );
    }

    public function testAnUndoThatClosedTheBuildBetweenTheReadAndTheWriteMakesAppendFail(): void
    {
        $open           = $this->row();
        $open['status'] = 'building';
        $this->repo->insert( $open );
        $GLOBALS['wpdb']->rows[1]['status'] = 'building';
        $stub = $GLOBALS['wpdb'];
        // The row is read as building, then closed by someone else before the conditional UPDATE runs.
        $GLOBALS['wpdb'] = new class( $stub ) {
            public string $prefix = 'wp_';
            public int $insert_id = 0;

            public function __construct( private BatchStubWpdb $inner )
            {
            }

            public function prepare( string $q, mixed ...$a ): string
            {
                return $this->inner->prepare( $q, ...$a );
            }

            public function get_row( string $q, mixed $o = null ): ?array
            {
                $row = $this->inner->get_row( $q, $o );
                $this->inner->rows[1]['status'] = 'undone';

                return $row;
            }

            public function update( string $t, array $d, array $w, array $f = [], array $wf = [] ): int|false
            {
                return $this->inner->update( $t, $d, $w, $f, $wf );
            }
        };
        $this->assertFalse( $this->repo->append( 1, [ [ 'kind' => 'late' ] ] ) );
        $this->assertSame( 'undone', $stub->rows[1]['status'] );
        $this->assertSame( 1, count( json_decode( $stub->rows[1]['items'], true ) ), 'nothing was appended to the closed build' );
    }

    public function testAppendingNothingToAnOpenBuildIsFineAndWritesNothing(): void
    {
        $open           = $this->row();
        $open['status'] = 'building';
        $this->repo->insert( $open );
        $before = count( $GLOBALS['wpdb']->calls );
        $this->assertTrue( $this->repo->append( 1, [] ) );
        $this->assertSame( [], array_filter( array_slice( $GLOBALS['wpdb']->calls, $before ), static fn ( array $c ): bool => 'update' === $c[0] ) );
    }
}
