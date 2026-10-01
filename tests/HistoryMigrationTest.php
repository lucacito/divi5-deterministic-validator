<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryMigration;
use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryMigration.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';

class HistoryMigrationTest extends TestCase
{
    private const TRICKY = "<p class=\"a\">C:\\path \\\\ \\u003Cp\\u003E \n\u{1F600}</p>";

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']     = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_TABLE ];
        $GLOBALS['__wp_posts']       = [ 5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_content' => 'ORIGINAL' ] ];
        $GLOBALS['__wp_postmeta']    = [];
        $GLOBALS['__wp_update_fail'] = false;
        $GLOBALS['__wp_transients']  = [];
        $GLOBALS['__wp_dbdelta_sql'] = [];
        $GLOBALS['__wp_filters']     = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']          = null;
        $GLOBALS['__wp_options']  = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__wp_filters']  = [];
    }

    /** The 4.0.0 on-disk format: a JSON blob, newest item first. */
    private function legacyJson( int ...$ids ): string
    {
        $items = [];
        foreach ( $ids as $id ) {
            $content = 2 === $id ? self::TRICKY : "content {$id}";
            $items[] = [ 'id' => $id, 'saved_at' => "2026-10-01T0{$id}:00:00Z", 'tool' => 'update_page_layout', 'actor' => 7, 'bytes' => strlen( $content ), 'sha256' => hash( 'sha256', $content ), 'label' => null, 'content' => $content ];
        }

        return (string) json_encode( [ 'next' => max( $ids ) + 1, 'items' => array_reverse( $items ) ] );
    }

    private function seedLegacy( int $pageId, string $json ): void
    {
        $GLOBALS['__wp_postmeta'][ $pageId ][ HistoryStore::LEGACY_META_KEY ] = $json;
    }

    private function hasLegacy( int $pageId ): bool
    {
        return isset( $GLOBALS['__wp_postmeta'][ $pageId ][ HistoryStore::LEGACY_META_KEY ] );
    }

    public function testImportPageCopiesRowsKeepsIdsAndBytesAndRemovesTheMeta(): void
    {
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        $this->assertTrue( HistoryMigration::importPage( 5 ) );
        $this->assertSame( [ 2, 1 ], array_column( HistoryStore::summaries( 5 ), 'id' ) );
        $this->assertSame( self::TRICKY, HistoryStore::entry( 5, 2 )['content'] );
        $this->assertSame( 'content 1', HistoryStore::entry( 5, 1 )['content'] );
        $this->assertFalse( $this->hasLegacy( 5 ) );
    }

    public function testImportIsIdempotentAndNothingToImportIsFine(): void
    {
        $this->assertTrue( HistoryMigration::importPage( 5 ), 'no meta at all' );
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        HistoryMigration::importPage( 5 );
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) ); // same blob appears again
        $this->assertTrue( HistoryMigration::importPage( 5 ) );
        $this->assertCount( 2, HistoryStore::summaries( 5 ) );
        $this->assertFalse( $this->hasLegacy( 5 ) );
    }

    public function testPartiallyImportedPageOnlyAddsTheMissingRows(): void
    {
        HistoryStore::save( 5, [ 'next' => 3, 'items' => [ [ 'id' => 2, 'saved_at' => '2026-10-02T00:00:00Z', 'tool' => 'update_page_layout', 'actor' => 7, 'bytes' => strlen( self::TRICKY ), 'sha256' => hash( 'sha256', self::TRICKY ), 'label' => null, 'content' => self::TRICKY ] ] ] );
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        $this->assertTrue( HistoryMigration::importPage( 5 ) );
        $this->assertSame( [ 2, 1 ], array_column( HistoryStore::summaries( 5 ), 'id' ) );
    }

    public function testRowThatClashesWithDifferentContentKeepsTheMetaForARetry(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ [ 'id' => 1, 'saved_at' => 't', 'tool' => 't', 'actor' => 1, 'bytes' => 5, 'sha256' => hash( 'sha256', 'other' ), 'label' => null, 'content' => 'other' ] ] ] );
        $this->seedLegacy( 5, $this->legacyJson( 1 ) );
        $this->assertFalse( HistoryMigration::importPage( 5 ) );
        $this->assertTrue( $this->hasLegacy( 5 ) );
        $this->assertSame( 'other', HistoryStore::entry( 5, 1 )['content'] );
    }

    public function testInsertFailureKeepsTheMeta(): void
    {
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        $GLOBALS['wpdb']->failInserts = true;
        $this->assertFalse( HistoryMigration::importPage( 5 ) );
        $this->assertTrue( $this->hasLegacy( 5 ) );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'garbage' )]
    public function testGarbageLegacyMetaIsDroppedNotKeptAndNeverBlocks( string $junk ): void
    {
        $this->seedLegacy( 5, $junk );
        $this->assertTrue( HistoryMigration::importPage( 5 ) );
        $this->assertFalse( $this->hasLegacy( 5 ), 'a value with no valid snapshot would block the migration from ever finishing' );
        $this->assertSame( [], HistoryStore::summaries( 5 ) );
        $r = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertTrue( $r['snapshot']['stored'] );
    }

    /** @return list<array{string}> */
    public static function garbage(): array
    {
        return [ [ 'not json at all' ], [ '{"items":"nope"}' ], [ '[1,2,3]' ], [ '{"items":[]}' ], [ '12345' ] ];
    }

    public function testAFailedImportDoesNotLetANewSnapshotReuseALegacyId(): void
    {
        // Page 5 already has a different row 1, so importing the legacy 1 and 2 clashes and fails.
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ [ 'id' => 1, 'saved_at' => 't', 'tool' => 't', 'actor' => 1, 'bytes' => 5, 'sha256' => hash( 'sha256', 'other' ), 'label' => null, 'content' => 'other' ] ] ] );
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        $w = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertTrue( $w['ok'] );
        $this->assertSame( 3, $w['snapshot']['version_id'], 'the new id must be above the legacy ids that are still waiting to be imported' );
        $this->assertTrue( $this->hasLegacy( 5 ) );
    }

    public function testAnEmptyStringLegacyValueIsRemovedSoItIsNotListedEveryBatch(): void
    {
        $this->seedLegacy( 5, '' );
        $this->assertTrue( HistoryMigration::importPage( 5 ) );
        $this->assertFalse( $this->hasLegacy( 5 ) );
    }

    public function testRunRespectsTheBatchLimitAndReportsWhenDone(): void
    {
        foreach ( [ 11, 12, 13, 14, 15 ] as $id ) {
            $this->seedLegacy( $id, $this->legacyJson( 1 ) );
        }
        $this->assertFalse( HistoryMigration::run( 2 ) );  // 2 imported, a full batch: maybe more
        $this->assertFalse( HistoryMigration::run( 2 ) );  // 2 more
        $this->assertTrue( HistoryMigration::run( 2 ) );   // the last one, short batch: done
        foreach ( [ 11, 12, 13, 14, 15 ] as $id ) {
            $this->assertCount( 1, HistoryStore::summaries( $id ) );
            $this->assertFalse( $this->hasLegacy( $id ) );
        }
        $this->assertTrue( HistoryMigration::run( 2 ), 'a finished migration stays finished' );
    }

    public function testAFailingPageBacksTheMigrationOffInsteadOfRetryingEveryRequest(): void
    {
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        $GLOBALS['wpdb']->failInserts = true;

        $this->assertFalse( HistoryMigration::run( 20 ) );
        $this->assertArrayHasKey( HistoryTable::BACKOFF_TRANSIENT, $GLOBALS['__wp_transients'] );
        $this->assertTrue( $this->hasLegacy( 5 ) );

        $GLOBALS['wpdb']->queries = [];
        HistoryTable::ensureReady();
        $this->assertSame( [], $GLOBALS['wpdb']->queries, 'backed off: the database is not touched' );
        $this->assertSame( HistoryTable::STAGE_TABLE, (int) $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ] );

        HistoryTable::ensureReady( true );
        $this->assertNotSame( [], $GLOBALS['wpdb']->queries, 'activation ignores the backoff' );

        $GLOBALS['wpdb']->queries = [];
        unset( $GLOBALS['__wp_transients'][ HistoryTable::BACKOFF_TRANSIENT ] );
        $GLOBALS['wpdb']->failInserts = false;
        HistoryTable::ensureReady();
        $this->assertNotSame( [], $GLOBALS['wpdb']->queries, 'once the backoff is over the migration runs again' );
        $this->assertSame( HistoryTable::STAGE_MIGRATED, (int) $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ] );
        $this->assertFalse( $this->hasLegacy( 5 ) );
    }

    public function testAHealthyFullBatchDoesNotSetTheBackoff(): void
    {
        foreach ( [ 11, 12, 13 ] as $id ) {
            $this->seedLegacy( $id, $this->legacyJson( 1 ) );
        }
        $this->assertFalse( HistoryMigration::run( 2 ), 'a full batch: more pages may remain' );
        $this->assertArrayNotHasKey( HistoryTable::BACKOFF_TRANSIENT, $GLOBALS['__wp_transients'] );
    }

    public function testLazyImportShowsAndRestoresOldHistoryBeforeTheBatchRuns(): void
    {
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        $this->assertSame( [ 2, 1 ], array_column( HistoryService::listFor( 5 ), 'id' ) );
        $r = HistoryService::restore( 5, 2 );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( self::TRICKY, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 3, $r['snapshot']['version_id'], 'the restore snapshots the current content: its id continues after the imported 1 and 2' );
    }

    public function testNextSnapshotIdContinuesAfterTheImportedIds(): void
    {
        $this->seedLegacy( 5, $this->legacyJson( 1, 2 ) );
        $w = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertSame( 3, $w['snapshot']['version_id'] );
        $this->assertCount( 3, HistoryService::listFor( 5 ) );
    }

    public function testNoLazyImportOnceTheMigrationIsDone(): void
    {
        $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ] = HistoryTable::STAGE_MIGRATED;
        $this->seedLegacy( 5, $this->legacyJson( 1 ) );
        $this->assertSame( [], HistoryService::listFor( 5 ) );
        $this->assertTrue( $this->hasLegacy( 5 ), 'after the migration stage the legacy meta is no longer consulted' );
    }

    public function testEnsureReadyCreatesTheTableThenMigratesInBatches(): void
    {
        $GLOBALS['wpdb']         = new FakeWpdb(); // no table yet
        $GLOBALS['__wp_options'] = [];
        for ( $id = 100; $id < 125; $id++ ) {      // 25 pages > one batch of 20
            $this->seedLegacy( $id, $this->legacyJson( 1 ) );
        }

        HistoryTable::ensureReady();
        $this->assertTrue( HistoryTable::exists() );
        $this->assertSame( HistoryTable::STAGE_TABLE, (int) $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ], '25 pages need a second batch' );

        HistoryTable::ensureReady();
        $this->assertSame( HistoryTable::STAGE_MIGRATED, (int) $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ] );
        $this->assertCount( 1, HistoryStore::summaries( 124 ) );
        $this->assertCount( 1, $GLOBALS['__wp_dbdelta_sql'], 'the table is created once, not per request' );
    }

    public function testEnsureReadyDoesNothingOnceMigrated(): void
    {
        $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ] = HistoryTable::STAGE_MIGRATED;
        $GLOBALS['wpdb']->queries = [];
        HistoryTable::ensureReady();
        $this->assertSame( [], $GLOBALS['wpdb']->queries );
        $this->assertSame( [], $GLOBALS['__wp_dbdelta_sql'] );
    }

    public function testHostThatForbidsCreateTableBacksOffAndNeverFatals(): void
    {
        $GLOBALS['wpdb']              = new FakeWpdb();
        $GLOBALS['wpdb']->denyCreate  = true;
        $GLOBALS['__wp_options']      = [];

        HistoryTable::ensureReady();
        $this->assertCount( 1, $GLOBALS['__wp_dbdelta_sql'] );
        $this->assertArrayNotHasKey( HistoryTable::DB_VERSION_OPTION, $GLOBALS['__wp_options'] );

        HistoryTable::ensureReady();
        HistoryTable::ensureReady();
        $this->assertCount( 1, $GLOBALS['__wp_dbdelta_sql'], 'backed off: no dbDelta on every request' );

        HistoryTable::ensureReady( true );
        $this->assertCount( 2, $GLOBALS['__wp_dbdelta_sql'], 'activation retries immediately' );

        // A page write still succeeds and honestly says undo is unavailable.
        $r = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'store_failed', $r['snapshot']['reason'] );
        $this->assertSame( 'NEW', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testRegisterWiresUpgradeCleanupAndMultisiteDropHooks(): void
    {
        HistoryTable::register();

        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ [ 'id' => 1, 'saved_at' => 't', 'tool' => 't', 'actor' => 1, 'bytes' => 1, 'sha256' => hash( 'sha256', 'a' ), 'label' => null, 'content' => 'a' ] ] ] );
        do_action( 'delete_post', 5 );
        $this->assertSame( [], HistoryStore::summaries( 5 ), 'permanently deleting a page removes its history' );

        $this->assertContains( 'wp_ai_editor_divi5_history', apply_filters( 'wpmu_drop_tables', [] ) );

        $this->seedLegacy( 6, $this->legacyJson( 1 ) );
        do_action( 'plugins_loaded' );
        $this->assertCount( 1, HistoryStore::summaries( 6 ) );
        $this->assertSame( HistoryTable::STAGE_MIGRATED, (int) $GLOBALS['__wp_options'][ HistoryTable::DB_VERSION_OPTION ] );
    }
}
