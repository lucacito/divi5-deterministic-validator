<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\PostStatusKind;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\PublishPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class PublishPageTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private PublishPage $tool;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->tool = new PublishPage( $this->log );
        $page       = static fn ( int $id, string $status, string $type = 'page' ) => (object) [ 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => "Page {$id}", 'post_content' => 'C' ];
        $GLOBALS['__wp_posts'] = [
            5  => $page( 5, 'draft' ),
            6  => $page( 6, 'pending' ),
            7  => $page( 7, 'publish' ),
            8  => $page( 8, 'draft', 'post' ),
            9  => $page( 9, 'private' ),
            10 => $page( 10, 'trash' ),
        ];
        $GLOBALS['__wp_update_calls'] = 0;
        $GLOBALS['__wp_update_fail']  = false;
        $GLOBALS['__wp_can']          = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function undo(): BatchUndo
    {
        return new BatchUndo( [ 'post_status' => new PostStatusKind() ], $this->log );
    }

    private function fails( array $args, string $message ): void
    {
        $status = $GLOBALS['__wp_posts'][5]->post_status;
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], 'nothing was saved' );
        $this->assertSame( $status, $GLOBALS['__wp_posts'][5]->post_status );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testItPublishesADraftAndRecordsAnUndoableBatch(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5 ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 'publish', $r['page']['status'] );
        $this->assertSame( 'publish', $GLOBALS['__wp_posts'][5]->post_status );
        $this->assertSame( 'Page 5', $r['page']['title'] );
        $this->assertTrue( $r['batch']['undoable'] );
    }

    public function testItPublishesAPendingPageToo(): void
    {
        $this->assertSame( 'publish', $this->tool->run( [ 'page_id' => 6 ] )['page']['status'] );
    }

    public function testItSchedulesWhenPublishAtIsGivenInAnyTimezone(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5, 'publish_at' => '2099-01-01T12:00:00+02:00' ] );
        $this->assertSame( 'future', $r['page']['status'] );
        $this->assertSame( '2099-01-01 10:00:00', $GLOBALS['__wp_posts'][5]->post_date_gmt );
        $this->assertTrue( $GLOBALS['__wp_posts'][5]->edit_date );

        $r2 = $this->tool->run( [ 'page_id' => 6, 'publish_at' => '2099-01-01T12:00Z' ] );
        $this->assertSame( '2099-01-01 12:00:00', $GLOBALS['__wp_posts'][6]->post_date_gmt, 'seconds are optional, Z means UTC' );
        $this->assertSame( 'future', $r2['page']['status'] );
    }

    public function testTheEdgesOfTheValidOffsetRangeAreAccepted(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5, 'publish_at' => '2099-01-01T12:00:00+14:00' ] );
        $this->assertSame( '2098-12-31 22:00:00', $GLOBALS['__wp_posts'][5]->post_date_gmt );
        $this->assertSame( 'future', $r['page']['status'] );
        $r2 = $this->tool->run( [ 'page_id' => 6, 'publish_at' => '2099-01-01T12:00:00-12:00' ] );
        $this->assertSame( '2099-01-02 00:00:00', $GLOBALS['__wp_posts'][6]->post_date_gmt );
        $this->assertSame( 'future', $r2['page']['status'] );
    }

    public function testBadArgumentsAreRejectedBeforeAnythingIsSaved(): void
    {
        $this->fails( [], '"page_id" is required.' );
        $this->fails( [ 'page_id' => '5' ], '"page_id" must be an integer.' );
        $this->fails( [ 'page_id' => 5, 'dry_run' => 'no' ], '"dry_run" must be true or false.' );
        $this->fails( [ 'page_id' => 5, 'publish_at' => 20990101 ], '"publish_at" must be a string.' );
        foreach ( [ '', 'tomorrow', '2099-01-01', '2099-01-01 12:00:00', '2099-01-01T12:00:00', '2099-02-31T10:00:00Z', '2099-13-01T10:00:00Z', '2099-01-01T12:00:00+15:00', '2099-01-01T12:00:00+99:99', '2099-01-01T12:00:00+14:75', '2099-01-01T12:00:00-15:00' ] as $bad ) {
            $this->fails( [ 'page_id' => 5, 'publish_at' => $bad ], 'publish_at' );
        }
        $this->fails( [ 'page_id' => 5, 'publish_at' => '2020-01-01T00:00:00Z' ], 'in the future' );
        $this->fails( [ 'page_id' => 5, 'publish_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 10 ) ], 'in the future' );
    }

    public function testThePostStatusKindWritesOnlyARealPublishingStatus(): void
    {
        $kind = new PostStatusKind();
        foreach ( [ 'trash', '', 'private', 'auto-draft', 'inherit', 'nonsense' ] as $bad ) {
            $r = $kind->restore( [ 'ref' => '5', 'label' => 'x', 'before' => $bad ] );
            $this->assertFalse( $r['ok'], "'{$bad}' is refused" );
        }
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], 'wp_update_post was never called' );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][5]->post_status );
        foreach ( [ 'draft', 'pending', 'future', 'publish' ] as $good ) {
            $this->assertTrue( $kind->restore( [ 'ref' => '5', 'label' => 'x', 'before' => $good ] )['ok'] );
        }
    }

    public function testOnlyAnEditableDraftOrPendingPageIsAccepted(): void
    {
        $this->fails( [ 'page_id' => 99 ], 'Page 99 not found.' );
        $this->fails( [ 'page_id' => 8 ], 'Page 8 not found.' );
        foreach ( [ 7 => 'publish', 9 => 'private', 10 => 'trash' ] as $id => $status ) {
            $this->fails( [ 'page_id' => $id ], "Page {$id} is {$status}" );
        }
    }

    public function testAUserWhoCannotEditThatPageIsRefused(): void
    {
        $GLOBALS['__wp_caps'] = [ 'publish_pages' => true, 'edit_post:5' => false ];
        $this->fails( [ 'page_id' => 5 ], 'You do not have permission to edit page 5.' );
    }

    public function testADryRunSavesAndRecordsNothing(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5, 'dry_run' => true ] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertFalse( $r['changed'] );
        $this->assertSame( [ 'from' => 'draft', 'to' => 'publish' ], $r['would_change']['status'] );
        $this->assertNull( $r['would_change']['publish_at'] );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $this->repo->rows );

        $s = $this->tool->run( [ 'page_id' => 5, 'publish_at' => '2099-01-01T12:00:00Z', 'dry_run' => true ] );
        $this->assertSame( 'future', $s['would_change']['status']['to'] );
        $this->assertSame( '2099-01-01T12:00:00Z', $s['would_change']['publish_at'] );
    }

    public function testAFailedSaveIsAnErrorAndRecordsNothing(): void
    {
        $GLOBALS['__wp_update_fail'] = true;
        try {
            $this->tool->run( [ 'page_id' => 5 ] );
            $this->fail( 'expected RuntimeException' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( [], $this->repo->rows );
        }
    }

    public function testUndoReturnsThePageToItsPreviousStatus(): void
    {
        $r    = $this->tool->run( [ 'page_id' => 6 ] );
        $undo = $this->undo()->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( 'pending', $GLOBALS['__wp_posts'][6]->post_status );
    }

    public function testUndoOfAScheduledPageAndUndoingTheUndo(): void
    {
        $r     = $this->tool->run( [ 'page_id' => 5, 'publish_at' => '2099-01-01T12:00:00Z' ] );
        $first = $this->undo()->undo( $r['batch']['id'] );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][5]->post_status );
        $this->undo()->undo( $first['undo_batch']['id'] );
        $this->assertSame( 'future', $GLOBALS['__wp_posts'][5]->post_status );
    }

    public function testUndoSkipsAPageThatWasChangedSince(): void
    {
        $r                                      = $this->tool->run( [ 'page_id' => 5 ] );
        $GLOBALS['__wp_posts'][5]->post_status = 'trash';
        $undo                                   = $this->undo()->undo( $r['batch']['id'] );
        $this->assertSame( 'changed_since', $undo['items'][0]['result'] );
        $this->assertSame( 'trash', $GLOBALS['__wp_posts'][5]->post_status );
    }

    public function testUndoIsRefusedForAUserWhoCannotEditThePageAnyMore(): void
    {
        $r                    = $this->tool->run( [ 'page_id' => 5 ] );
        $GLOBALS['__wp_caps'] = [ 'publish_pages' => true, 'edit_post:5' => false ];
        $undo                 = $this->undo()->undo( $r['batch']['id'] );
        $this->assertSame( 'forbidden', $undo['items'][0]['result'] );
        $this->assertSame( 'publish', $GLOBALS['__wp_posts'][5]->post_status );
    }

    public function testItIsRegisteredAndNeedsPublishPages(): void
    {
        $this->assertContains( 'publish_page', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
        $this->assertSame( 'publish_pages', $this->tool->capability() );
        $this->assertLessThanOrEqual( 300, strlen( $this->tool->definition()['description'] ) );
        $this->assertSame( [ 'page_id' ], $this->tool->definition()['inputSchema']['required'] );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $registry             = new ToolRegistry( [ $this->tool ], static fn (): bool => true );
        $this->assertSame( -32602, ( (array) $registry->claim( null, 'publish_page', [ 'page_id' => 5 ], 1 )->get_data() )['error']['code'] );
        $this->assertSame( 'draft', $GLOBALS['__wp_posts'][5]->post_status );
    }
}
