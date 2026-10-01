<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryService;
use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\PageHistory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';

class HistoryServiceTest extends TestCase
{
    private const VALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    protected function setUp(): void
    {
        $GLOBALS['__wp_posts']       = [ 5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_content' => 'ORIGINAL' ] ];
        $GLOBALS['__wp_postmeta']    = [];
        $GLOBALS['__wp_update_fail'] = false;
        $GLOBALS['__wp_meta_fail']   = false;
    }

    public function testWriteSnapshotsPreviousContentThenSavesNew(): void
    {
        $r = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertTrue( $r['snapshot']['stored'] );
        $this->assertSame( 'NEW', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 'ORIGINAL', HistoryService::entry( 5, $r['snapshot']['version_id'] )['content'] );
    }

    public function testBackslashesAndEscapedHtmlRoundTripByteForByte(): void
    {
        $tricky = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Hi \"there\" \\\\ back</p>"}}}} /-->';
        $GLOBALS['__wp_posts'][5]->post_content = $tricky;
        $w = HistoryService::write( 5, 'SOMETHING ELSE', 'edit_page_content' );
        $this->assertSame( $tricky, HistoryService::entry( 5, $w['snapshot']['version_id'] )['content'] );

        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( $tricky, $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testOversizedPageIsStillWrittenAndResultSaysNoSnapshot(): void
    {
        $GLOBALS['__wp_posts'][5]->post_content = str_repeat( 'x', PageHistory::MAX_BYTES + 1 );
        $r = HistoryService::write( 5, 'SMALL', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertFalse( $r['snapshot']['stored'] );
        $this->assertSame( 'too_large', $r['snapshot']['reason'] );
        $this->assertNull( $r['snapshot']['version_id'] );
        $this->assertSame( 'SMALL', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testFailedSaveReportsErrorAndKeepsContent(): void
    {
        $GLOBALS['__wp_update_fail'] = true;
        $r = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'update_failed', $r['error'] );
        $this->assertSame( 'simulated failure', $r['message'] );
        $this->assertSame( 'ORIGINAL', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testRestoreSnapshotsCurrentContentFirstSoRestoreIsUndoable(): void
    {
        $w = HistoryService::write( 5, 'AI VERSION', 'update_page_layout' );          // snapshot #1 = ORIGINAL
        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );              // back to ORIGINAL
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'ORIGINAL', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 1, $r['restored_version'] );
        $this->assertTrue( $r['snapshot']['stored'] );
        $this->assertSame( 'AI VERSION', HistoryService::entry( 5, $r['snapshot']['version_id'] )['content'] );
    }

    public function testRestoreOfContentTheValidatorRejectsStillSucceedsAndReportsVerdict(): void
    {
        $GLOBALS['__wp_posts'][5]->post_content = '<!-- wp:divi/not-a-module /-->';
        $w = HistoryService::write( 5, self::VALID, 'update_page_layout' );
        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( '<!-- wp:divi/not-a-module /-->', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertFalse( $r['validator']['valid'] );
        $this->assertNotEmpty( $r['validator']['violations'] );
    }

    public function testRestoreOfValidContentReportsValid(): void
    {
        $GLOBALS['__wp_posts'][5]->post_content = self::VALID;
        $w = HistoryService::write( 5, 'junk', 'update_page_layout' );
        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );
        $this->assertTrue( $r['validator']['valid'] );
        $this->assertSame( [], $r['validator']['violations'] );
    }

    public function testUnknownVersionIsACleanErrorAndChangesNothing(): void
    {
        $r = HistoryService::restore( 5, 99 );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'version_not_found', $r['error'] );
        $this->assertSame( 'ORIGINAL', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( [], HistoryService::listFor( 5 ) );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'corruptMeta' )]
    public function testCorruptHistoryMetaNeverBlocksAWrite( mixed $junk ): void
    {
        $GLOBALS['__wp_postmeta'][5][ HistoryStore::META_KEY ] = $junk;
        $r = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'NEW', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertTrue( $r['snapshot']['stored'] );
    }

    public static function corruptMeta(): array
    {
        return [ [ 'not json at all' ], [ '{"items":"nope"}' ], [ '[1,2,3]' ], [ 12345 ], [ '' ] ];
    }

    public function testDuplicateWriteDoesNotAddASnapshot(): void
    {
        HistoryService::write( 5, 'A', 'update_page_layout' );              // snapshot ORIGINAL
        $r = HistoryService::write( 5, 'A', 'update_page_layout' );          // pre-write content is now 'A'
        $this->assertTrue( $r['ok'] );
        $this->assertTrue( $r['snapshot']['stored'] );                        // 'A' is new vs ORIGINAL
        $r2 = HistoryService::write( 5, 'A', 'update_page_layout' );          // pre-write 'A' == newest snapshot 'A'
        $this->assertFalse( $r2['snapshot']['stored'] );
        $this->assertSame( 'duplicate', $r2['snapshot']['reason'] );
        $this->assertNotNull( $r2['snapshot']['version_id'] );
        $this->assertSame( 2, $r2['snapshot']['version_id'] );
        $this->assertCount( 2, HistoryService::listFor( 5 ) );
    }

    public function testListForReturnsSummariesWithoutContent(): void
    {
        HistoryService::write( 5, 'A', 'update_page_layout' );
        $list = HistoryService::listFor( 5 );
        $this->assertCount( 1, $list );
        $this->assertArrayNotHasKey( 'content', $list[0] );
        $this->assertSame( 'update_page_layout', $list[0]['tool'] );
    }

    public function testMetaWriteFailureIsReportedAsStoreFailedAndWriteStillSucceeds(): void
    {
        HistoryService::write( 5, 'B', 'update_page_layout' );               // snapshot #1 = ORIGINAL
        $before = $GLOBALS['__wp_postmeta'][5][ HistoryStore::META_KEY ];
        $GLOBALS['__wp_meta_fail'] = true;
        $r = HistoryService::write( 5, 'C', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'C', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertFalse( $r['snapshot']['stored'] );
        $this->assertNull( $r['snapshot']['version_id'] );
        $this->assertSame( 'store_failed', $r['snapshot']['reason'] );
        $this->assertSame( $before, $GLOBALS['__wp_postmeta'][5][ HistoryStore::META_KEY ] );
        $this->assertCount( 1, HistoryService::listFor( 5 ) );
    }

    public function testInvalidUtf8PreWriteContentKeepsExistingHistoryAndIds(): void
    {
        HistoryService::write( 5, 'B', 'update_page_layout' );               // #1 = ORIGINAL
        HistoryService::write( 5, 'C', 'update_page_layout' );               // #2 = B
        $GLOBALS['__wp_posts'][5]->post_content = "bad \xC3\x28 bytes";
        $r = HistoryService::write( 5, 'D', 'update_page_layout' );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'D', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertFalse( $r['snapshot']['stored'] );
        $this->assertSame( 'invalid_encoding', $r['snapshot']['reason'] );
        $this->assertSame( [ 2, 1 ], array_column( HistoryService::listFor( 5 ), 'id' ) );
        $this->assertSame( 'B', HistoryService::entry( 5, 2 )['content'] );

        $next = HistoryService::write( 5, 'E', 'update_page_layout' );       // pre-write 'D' -> id 3, not reused
        $this->assertSame( 3, $next['snapshot']['version_id'] );
    }

    public function testStoreSaveReturnsFalseAndWritesNothingWhenEncodeFails(): void
    {
        $this->assertFalse( HistoryStore::save( 5, [ 'next' => 2, 'items' => [ [ 'id' => 1, 'content' => "\xC3\x28" ] ] ] ) );
        $this->assertArrayNotHasKey( HistoryStore::META_KEY, $GLOBALS['__wp_postmeta'][5] ?? [] );
        $this->assertTrue( HistoryStore::save( 5, [ 'next' => 1, 'items' => [] ] ) );
    }

    public function testWriteToMissingPageFailsWithoutCreatingMeta(): void
    {
        $r = HistoryService::write( 999, 'X', 'update_page_layout' );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'update_failed', $r['error'] );
        $this->assertSame( 'Page 999 not found.', $r['message'] );
        $this->assertSame( [ 'stored' => false, 'version_id' => null, 'reason' => null ], $r['snapshot'] );
        $this->assertArrayNotHasKey( 999, $GLOBALS['__wp_postmeta'] );
    }

    public function testRestoreFailureShapesCarryASnapshotKey(): void
    {
        $nf = HistoryService::restore( 5, 99 );
        $this->assertSame( [ 'stored' => false, 'version_id' => null, 'reason' => null ], $nf['snapshot'] );

        $w = HistoryService::write( 5, 'NEW', 'update_page_layout' );
        $GLOBALS['__wp_update_fail'] = true;
        $r = HistoryService::restore( 5, $w['snapshot']['version_id'] );
        $this->assertFalse( $r['ok'] );
        $this->assertSame( 'update_failed', $r['error'] );
        $this->assertArrayHasKey( 'snapshot', $r );
        $this->assertSame( 'NEW', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testThirteenWritesKeepNewestTenWithMonotonicIds(): void
    {
        for ( $i = 1; $i <= 13; $i++ ) {
            $r = HistoryService::write( 5, "V{$i}", 'update_page_layout' );
            $this->assertSame( $i, $r['snapshot']['version_id'] );
        }
        $this->assertSame( [ 13, 12, 11, 10, 9, 8, 7, 6, 5, 4 ], array_column( HistoryService::listFor( 5 ), 'id' ) );
        $this->assertSame( 14, HistoryService::write( 5, 'V14', 'update_page_layout' )['snapshot']['version_id'] );
    }

    public function testRestoringTheOldestVersionWhenHistoryIsFullSucceeds(): void
    {
        for ( $i = 1; $i <= 10; $i++ ) {
            HistoryService::write( 5, "V{$i}", 'update_page_layout' );       // ids 1..10, #1 = ORIGINAL
        }
        $this->assertCount( PageHistory::RETENTION, HistoryService::listFor( 5 ) );
        $oldest = HistoryService::entry( 5, 1 );
        $this->assertSame( 'ORIGINAL', $oldest['content'] );

        $r = HistoryService::restore( 5, 1 );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'ORIGINAL', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertTrue( $r['snapshot']['stored'] );
    }

    public function testRecentListsNewestSnapshotPerPageWithTitles(): void
    {
        $GLOBALS['__wp_posts'][6] = (object) [ 'ID' => 6, 'post_type' => 'page', 'post_title' => 'About', 'post_content' => 'B0' ];
        $GLOBALS['__wp_posts'][5]->post_title = 'Home';
        HistoryService::write( 5, 'A1', 'update_page_layout' );
        HistoryService::write( 6, 'B1', 'edit_page_content' );
        $rows = HistoryService::recent( 5, [ 5, 6 ] );
        $this->assertCount( 2, $rows );
        $titles = array_column( $rows, 'title' );
        sort( $titles );
        $this->assertSame( [ 'About', 'Home' ], $titles );
        $this->assertSame( [ 1 ], array_unique( array_column( $rows, 'version_id' ) ) );
    }

    private function seedHistory( int $pageId, string $savedAt, string $content = 'C', int $id = 1 ): void
    {
        HistoryStore::save( $pageId, [
            'next'  => $id + 1,
            'items' => [ [ 'id' => $id, 'saved_at' => $savedAt, 'tool' => 'update_page', 'actor' => 1, 'bytes' => strlen( $content ), 'sha256' => hash( 'sha256', $content ), 'label' => null, 'content' => $content ] ],
        ] );
    }

    public function testRecentHonoursLimitAndOrdersNewestFirst(): void
    {
        foreach ( [ 6, 7 ] as $id ) {
            $GLOBALS['__wp_posts'][ $id ] = (object) [ 'ID' => $id, 'post_type' => 'page', 'post_title' => "P{$id}", 'post_content' => 'x' ];
        }
        $this->seedHistory( 5, '2026-01-01T00:00:00Z' );
        $this->seedHistory( 6, '2026-03-01T00:00:00Z' );
        $this->seedHistory( 7, '2026-02-01T00:00:00Z' );
        $rows = HistoryService::recent( 2, [ 5, 6, 7 ] );
        $this->assertCount( 2, $rows );
        $this->assertSame( [ 6, 7 ], array_column( $rows, 'page_id' ) );
    }

    public function testRecentMissingPostYieldsEmptyTitleWithoutWarning(): void
    {
        $this->seedHistory( 99, '2026-01-01T00:00:00Z' );
        $rows = HistoryService::recent( 5, [ 99 ] );
        $this->assertCount( 1, $rows );
        $this->assertSame( '', $rows[0]['title'] );
        $this->assertSame( 99, $rows[0]['page_id'] );
    }

    public function testRecentIgnoresGarbageMeta(): void
    {
        $GLOBALS['__wp_postmeta'][5][ HistoryStore::META_KEY ] = '{not json';
        $this->assertSame( [], HistoryService::recent( 5, [ 5 ] ) );
        $GLOBALS['__wp_postmeta'][5][ HistoryStore::META_KEY ] = '{"items":"nope"}';
        $this->assertSame( [], HistoryService::recent( 5, [ 5 ] ) );
    }

    public function testRecentRowsNeverCarryContentEvenWhenLarge(): void
    {
        $big = str_repeat( 'A', 400 * 1024 );
        $GLOBALS['__wp_posts'][5]->post_title = 'Big';
        $this->seedHistory( 5, '2026-01-01T00:00:00Z', $big );
        $rows = HistoryService::recent( 5, [ 5 ] );
        $this->assertCount( 1, $rows );
        $this->assertArrayNotHasKey( 'content', $rows[0] );
        $this->assertLessThan( 1000, strlen( serialize( $rows ) ) );
        // The stored history itself is untouched.
        $this->assertSame( $big, HistoryService::entry( 5, 1 )['content'] );
    }
}
