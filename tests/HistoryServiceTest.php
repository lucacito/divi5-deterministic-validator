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
    }

    public function testListForReturnsSummariesWithoutContent(): void
    {
        HistoryService::write( 5, 'A', 'update_page_layout' );
        $list = HistoryService::listFor( 5 );
        $this->assertCount( 1, $list );
        $this->assertArrayNotHasKey( 'content', $list[0] );
        $this->assertSame( 'update_page_layout', $list[0]['tool'] );
    }
}
