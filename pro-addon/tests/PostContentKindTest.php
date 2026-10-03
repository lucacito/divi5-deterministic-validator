<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Canon;
use AiEditorDivi5\Pro\Kinds\PostContentKind;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class PostContentKindTest extends TestCase
{
    /** @var list<array{int,int}> */
    private array $restored = [];

    protected function setUp(): void
    {
        $GLOBALS['__wp_posts'] = [ 5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => 'NEW' ] ];
        $GLOBALS['__wp_can']   = true;
        unset( $GLOBALS['__wp_caps'] );
        $this->restored = [];
    }

    /** A stand-in for HistoryService::restore: puts OLD back and reports the snapshot it took of NEW. */
    private function kind( bool $ok = true, ?array $snapshot = null ): PostContentKind
    {
        return new PostContentKind( function ( int $page, int $version ) use ( $ok, $snapshot ): array {
            $this->restored[] = [ $page, $version ];
            if ( ! $ok ) {
                return [ 'ok' => false, 'error' => 'version_not_found' ];
            }
            $GLOBALS['__wp_posts'][ $page ]->post_content = 'OLD';

            return [ 'ok' => true, 'restored_version' => $version, 'snapshot' => $snapshot ?? [ 'stored' => true, 'version_id' => 9, 'reason' => null ] ];
        } );
    }

    public function testItemForRecordsTheFreeHistorySnapshotAsTheUndoPoint(): void
    {
        $item = PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] );
        $this->assertSame( 'post_content', $item['kind'] );
        $this->assertSame( '5', $item['ref'] );
        $this->assertNull( $item['before'] );
        $this->assertSame( Canon::hash( 'NEW' ), $item['after_hash'] );
        $this->assertSame( [ 'available' => true, 'reason' => null, 'version_id' => 3 ], $item['undo'] );
    }

    public function testAWriteWhoseSnapshotWasNotStoredHasNoUndoPoint(): void
    {
        $item = PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => false, 'version_id' => null, 'reason' => 'too_large' ] );
        $this->assertFalse( $item['undo']['available'] );
        $this->assertSame( 'too_large', $item['undo']['reason'] );
    }

    public function testCurrentIsThePostContentAndAMissingPostIsNull(): void
    {
        $k = $this->kind();
        $this->assertSame( 'NEW', $k->current( [ 'ref' => '5' ] ) );
        $this->assertNull( $k->current( [ 'ref' => '99' ] ) );
    }

    public function testItNeedsEditPostOnThatPage(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post:5' => false ];
        $this->assertFalse( $this->kind()->allowed( [ 'ref' => '5' ] ) );
        $GLOBALS['__wp_caps'] = [ 'edit_post:5' => true ];
        $this->assertTrue( $this->kind()->allowed( [ 'ref' => '5' ] ) );
    }

    public function testRestoreAsksTheFreeHistoryForTheRecordedVersionAndBuildsTheInverse(): void
    {
        $item = PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] );
        $r    = $this->kind()->restore( $item );
        $this->assertSame( [ [ 5, 3 ] ], $this->restored );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'OLD', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( Canon::hash( 'OLD' ), $r['inverse']['after_hash'] );
        $this->assertSame( [ 'available' => true, 'reason' => null, 'version_id' => 9 ], $r['inverse']['undo'], 'the inverse points at the snapshot the restore took' );
    }

    public function testARestoreThatTookNoSnapshotYieldsAnInverseWithoutAnUndoPoint(): void
    {
        $item = PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] );
        $r    = $this->kind( true, [ 'stored' => false, 'version_id' => null, 'reason' => 'duplicate' ] )->restore( $item );
        $this->assertFalse( $r['inverse']['undo']['available'] );
        $this->assertSame( 'duplicate', $r['inverse']['undo']['reason'] );
    }

    public function testARefusedRestoreIsReportedWithItsReason(): void
    {
        $item = PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] );
        $this->assertSame( [ 'ok' => false, 'reason' => 'version_not_found' ], $this->kind( false )->restore( $item ) );
        $this->assertSame( 'NEW', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testAnItemWithoutAVersionCannotBeRestored(): void
    {
        $item                       = PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] );
        $item['undo']['version_id'] = null;
        $this->assertSame( [ 'ok' => false, 'reason' => 'no_version' ], $this->kind()->restore( $item ) );
        $this->assertSame( [], $this->restored );
    }

    public function testThroughTheEngineAContentChangedSinceIsNeverOverwritten(): void
    {
        $repo = new InMemoryBatchRepository();
        $log  = new BatchLog( $repo );
        $undo = new BatchUndo( [ 'post_content' => $this->kind() ], $log );
        $b    = $log->record( 'edit_page_content', 'edit', [ PostContentKind::itemFor( 5, 'Home', 'NEW', [ 'stored' => true, 'version_id' => 3, 'reason' => null ] ) ] );

        $GLOBALS['__wp_posts'][5]->post_content = 'EDITED IN THE BUILDER';
        $this->assertSame( 'changed_since', $undo->undo( $b['id'] )['items'][0]['result'] );
        $this->assertSame( [], $this->restored );

        $GLOBALS['__wp_posts'][5]->post_content = 'NEW';
        $r                                      = $undo->undo( $b['id'] );
        $this->assertTrue( $r['fully_undone'] );
        $this->assertSame( 'OLD', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertTrue( $r['undo_batch']['undoable'], 'the undo is itself undoable through the new snapshot' );
    }

    public function testTheDefaultRestorerIsTheFreePluginsHistoryService(): void
    {
        $this->assertTrue( method_exists( \AiEditorDivi5\WP\HistoryService::class, 'restore' ) );
        $this->assertNotNull( ( new \ReflectionClass( PostContentKind::class ) )->getConstructor() );
    }
}
