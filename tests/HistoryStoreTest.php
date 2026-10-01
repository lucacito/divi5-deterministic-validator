<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\PageHistory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';

class HistoryStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']  = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_postmeta'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']          = null;
        $GLOBALS['__wp_options']  = [];
        $GLOBALS['__wp_postmeta'] = [];
    }

    /** @return array<string,mixed> */
    private function item( int $id, string $content, string $at = '2026-10-01T00:00:00Z', ?string $label = null ): array
    {
        return [ 'id' => $id, 'saved_at' => $at, 'tool' => 'update_page_layout', 'actor' => 7, 'bytes' => strlen( $content ), 'sha256' => hash( 'sha256', $content ), 'label' => $label, 'content' => $content ];
    }

    private function countQueries( string $prefix ): int
    {
        return count( array_filter( $GLOBALS['wpdb']->queries, static fn ( string $q ): bool => str_starts_with( $q, $prefix ) ) );
    }

    public function testSaveThenLoadRoundTripsEveryFieldNewestFirst(): void
    {
        $items = [ $this->item( 2, 'two', '2026-10-01T02:00:00Z', 'before restore of #1' ), $this->item( 1, 'one' ) ];
        $this->assertTrue( HistoryStore::save( 5, [ 'next' => 3, 'items' => $items ] ) );
        $h = HistoryStore::load( 5 );
        $this->assertSame( $items, $h['items'] );
        $this->assertSame( 3, $h['next'] );
    }

    public function testContentRoundTripsBackslashesQuotesNewlinesAndEmoji(): void
    {
        $tricky = "<p class=\"a\">C:\\path \\\\ \\u003Cp\\u003E \n\t \u{1F600} \u{e9}</p>";
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, $tricky ) ] ] );
        $this->assertSame( $tricky, HistoryStore::entry( 5, 1 )['content'] );
        $this->assertSame( $tricky, HistoryStore::load( 5 )['items'][0]['content'] );
    }

    public function testSaveInsertsOnlyNewRowsAndDeletesTrimmedOnes(): void
    {
        HistoryStore::save( 5, [ 'next' => 4, 'items' => [ $this->item( 3, 'c' ), $this->item( 2, 'b' ), $this->item( 1, 'a' ) ] ] );
        $this->assertSame( 3, $this->countQueries( 'INSERT' ) );

        HistoryStore::save( 5, [ 'next' => 5, 'items' => [ $this->item( 4, 'd' ), $this->item( 3, 'c' ), $this->item( 2, 'b' ) ] ] );
        $this->assertSame( 4, $this->countQueries( 'INSERT' ), 'only version 4 is new' );
        $this->assertSame( [ 4, 3, 2 ], array_column( HistoryStore::summaries( 5 ), 'id' ), 'version 1 was trimmed' );
    }

    public function testNextIdSurvivesTrimmingThroughThirteenSnapshots(): void
    {
        for ( $i = 1; $i <= 13; $i++ ) {
            $r = PageHistory::record( HistoryStore::load( 5 ), "V{$i}", 'update_page_layout', 7, '2026-10-01T00:00:00Z' );
            $this->assertTrue( HistoryStore::save( 5, $r['history'] ) );
            $this->assertSame( $i, $r['version_id'] );
        }
        $h = HistoryStore::load( 5 );
        $this->assertSame( [ 13, 12, 11, 10, 9, 8, 7, 6, 5, 4 ], array_column( $h['items'], 'id' ) );
        $this->assertSame( 14, $h['next'] );
    }

    public function testSaveReturnsFalseOnInsertFailureAndKeepsExistingRows(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'a' ) ] ] );
        $GLOBALS['wpdb']->failInserts = true;
        $this->assertFalse( HistoryStore::save( 5, [ 'next' => 3, 'items' => [ $this->item( 2, 'b' ) ] ] ), 'would also trim version 1' );
        $GLOBALS['wpdb']->failInserts = false;
        $this->assertSame( [ 1 ], array_column( HistoryStore::summaries( 5 ), 'id' ), 'a failed save never deletes' );
    }

    public function testRacingWriterWithDifferentContentIsAClashNotASilentOverwrite(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'writer B state' ) ] ] );
        $this->assertFalse( HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'writer A state' ) ] ] ) );
        $this->assertSame( 'writer B state', HistoryStore::entry( 5, 1 )['content'] );
    }

    public function testRacingWriterWithTheSameContentIsHarmless(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'same' ) ] ] );
        $this->assertTrue( HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'same' ) ] ] ) );
        $this->assertCount( 1, HistoryStore::summaries( 5 ) );
    }

    public function testSummariesHaveNoContentOrShaAndEntryHasEverything(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'secret page body', '2026-10-01T00:00:00Z', 'lbl' ) ] ] );
        $GLOBALS['wpdb']->queries = [];
        $s = HistoryStore::summaries( 5 );
        $this->assertSame( [ [ 'id' => 1, 'saved_at' => '2026-10-01T00:00:00Z', 'tool' => 'update_page_layout', 'actor' => 7, 'bytes' => 16, 'label' => 'lbl' ] ], $s );
        foreach ( $GLOBALS['wpdb']->queries as $q ) {
            $this->assertStringNotContainsString( 'content', $q );
        }
        $e = HistoryStore::entry( 5, 1 );
        $this->assertSame( [ 'id', 'saved_at', 'tool', 'actor', 'bytes', 'sha256', 'label', 'content' ], array_keys( $e ) );
    }

    public function testEntryIsScopedToItsPage(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'page five' ) ] ] );
        HistoryStore::save( 6, [ 'next' => 2, 'items' => [ $this->item( 1, 'page six' ) ] ] );
        $this->assertSame( 'page six', HistoryStore::entry( 6, 1 )['content'] );
        $this->assertNull( HistoryStore::entry( 5, 99 ) );
        $this->assertNull( HistoryStore::entry( 7, 1 ) );
    }

    public function testRecentTakesTheNewestPerPageOrdersNewestFirstAndHonoursTheLimit(): void
    {
        HistoryStore::save( 5, [ 'next' => 3, 'items' => [ $this->item( 2, 'a2', '2026-01-02T00:00:00Z' ), $this->item( 1, 'a1', '2026-01-01T00:00:00Z' ) ] ] );
        HistoryStore::save( 6, [ 'next' => 2, 'items' => [ $this->item( 1, 'b1', '2026-03-01T00:00:00Z' ) ] ] );
        HistoryStore::save( 7, [ 'next' => 2, 'items' => [ $this->item( 1, 'c1', '2026-02-01T00:00:00Z' ) ] ] );

        $rows = HistoryStore::recent( 5 );
        $this->assertSame( [ 6, 7, 5 ], array_column( $rows, 'page_id' ) );
        $this->assertSame( 2, $rows[2]['version_id'], 'page 5 contributes only its newest version' );
        $this->assertSame( [ 'page_id', 'version_id', 'saved_at', 'tool' ], array_keys( $rows[0] ) );
        $this->assertSame( [ 6, 7 ], array_column( HistoryStore::recent( 2 ), 'page_id' ) );
        $this->assertSame( [], HistoryStore::recent( 0 ) );
    }

    public function testDeleteForPageRemovesOnlyThatPage(): void
    {
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'a' ) ] ] );
        HistoryStore::save( 6, [ 'next' => 2, 'items' => [ $this->item( 1, 'b' ) ] ] );
        HistoryStore::deleteForPage( 5 );
        $this->assertSame( [], HistoryStore::summaries( 5 ) );
        $this->assertCount( 1, HistoryStore::summaries( 6 ) );
    }

    public function testNothingTouchesTheDatabaseWhenThereIsNoTable(): void
    {
        $GLOBALS['wpdb']         = new FakeWpdb(); // no table
        $GLOBALS['__wp_options'] = [];
        $this->assertSame( [ 'next' => 1, 'items' => [] ], HistoryStore::load( 5 ) );
        $this->assertFalse( HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'a' ) ] ] ) );
        $this->assertSame( [], HistoryStore::summaries( 5 ) );
        $this->assertNull( HistoryStore::entry( 5, 1 ) );
        $this->assertSame( [], HistoryStore::recent( 5 ) );
        HistoryStore::deleteForPage( 5 );
        $this->assertSame( [], $GLOBALS['wpdb']->queries );
    }

    public function testDeleteAllDropsTheTableTheOptionAndAnyLegacyMeta(): void
    {
        $GLOBALS['__wp_postmeta'][5][ HistoryStore::LEGACY_META_KEY ] = '{"items":[]}';
        HistoryStore::save( 5, [ 'next' => 2, 'items' => [ $this->item( 1, 'a' ) ] ] );
        HistoryStore::deleteAll();
        $this->assertFalse( HistoryTable::exists() );
        $this->assertArrayNotHasKey( HistoryTable::DB_VERSION_OPTION, $GLOBALS['__wp_options'] );
        $this->assertArrayNotHasKey( HistoryStore::LEGACY_META_KEY, $GLOBALS['__wp_postmeta'][5] ?? [] );
    }
}
