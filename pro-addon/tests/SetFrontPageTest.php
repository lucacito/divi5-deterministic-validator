<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Kinds\FrontPageKind;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\Pro\Tools\SetFrontPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';

class SetFrontPageTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private SetFrontPage $tool;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $this->tool = new SetFrontPage( $this->log );
        $GLOBALS['__wp_options'] = [ 'show_on_front' => 'posts' ];
        $GLOBALS['__wp_posts']   = [
            5 => (object) [ 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Home', 'post_content' => '' ],
            6 => (object) [ 'ID' => 6, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Soon', 'post_content' => '' ],
            7 => (object) [ 'ID' => 7, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'A post', 'post_content' => '' ],
        ];
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function fails( array $args, string $message ): void
    {
        try {
            $this->tool->run( $args );
            $this->fail( 'expected InvalidArgumentException: ' . $message );
        } catch ( \InvalidArgumentException $e ) {
            $this->assertStringContainsString( $message, $e->getMessage() );
        }
        $this->assertSame( 'posts', $GLOBALS['__wp_options']['show_on_front'], 'nothing was changed' );
    }

    public function testItSetsTheFrontPageAndRecordsAnUndoableBatch(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5 ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( [ 'id' => 5, 'title' => 'Home' ], $r['front_page'] );
        $this->assertSame( 'page', $GLOBALS['__wp_options']['show_on_front'] );
        $this->assertSame( 5, $GLOBALS['__wp_options']['page_on_front'] );
        $this->assertSame( 1, $r['batch']['id'] );
        $this->assertTrue( $r['batch']['undoable'] );
        $this->assertSame( 'set_front_page', $this->repo->rows[1]['tool'] );
    }

    public function testTheArgumentsAreTypeCheckedNeverCast(): void
    {
        $this->fails( [], '"page_id" is required.' );
        $this->fails( [ 'page_id' => '5' ], '"page_id" must be an integer.' );
        $this->fails( [ 'page_id' => 5.0 ], '"page_id" must be an integer.' );
        $this->fails( [ 'page_id' => 5, 'dry_run' => 'yes' ], '"dry_run" must be true or false.' );
        $this->fails( [ 'page_id' => 5, 'dry_run' => 1 ], '"dry_run" must be true or false.' );
    }

    public function testOnlyAnExistingPublishedPageIsAccepted(): void
    {
        $this->fails( [ 'page_id' => 99 ], 'Page 99 not found.' );
        $this->fails( [ 'page_id' => 7 ], 'Page 7 not found.' );
        $this->fails( [ 'page_id' => 6 ], 'not published' );
    }

    public function testADryRunChangesNothingAndRecordsNothing(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5, 'dry_run' => true ] );
        $this->assertFalse( $r['changed'] );
        $this->assertTrue( $r['dry_run'] );
        $this->assertSame( [ 'show_on_front' => 'posts', 'page_on_front' => 0 ], $r['would_change']['from'] );
        $this->assertSame( [ 'show_on_front' => 'page', 'page_on_front' => 5 ], $r['would_change']['to'] );
        $this->assertSame( 'posts', $GLOBALS['__wp_options']['show_on_front'] );
        $this->assertSame( [], $this->repo->rows );
    }

    public function testSettingWhatIsAlreadySetChangesNothingAndRecordsNothing(): void
    {
        $this->tool->run( [ 'page_id' => 5 ] );
        $r = $this->tool->run( [ 'page_id' => 5 ] );
        $this->assertFalse( $r['changed'] );
        $this->assertStringContainsString( 'already', $r['message'] );
        $this->assertArrayNotHasKey( 'batch', $r );
        $this->assertCount( 1, $this->repo->rows );
    }

    public function testAWriteWhoseBatchCannotBeRecordedStillReportsTheWriteHonestly(): void
    {
        $this->repo->failInsert = true;
        $r                      = $this->tool->run( [ 'page_id' => 5 ] );
        $this->assertTrue( $r['changed'] );
        $this->assertSame( 'page', $GLOBALS['__wp_options']['show_on_front'] );
        $this->assertNull( $r['batch']['id'] );
        $this->assertFalse( $r['batch']['undoable'] );
    }

    public function testUndoPutsTheOldFrontPageSettingsBack(): void
    {
        $GLOBALS['__wp_options'] = [ 'show_on_front' => 'page', 'page_on_front' => 3 ];
        $r                       = $this->tool->run( [ 'page_id' => 5 ] );
        $undo                    = ( new BatchUndo( [ 'front_page' => new FrontPageKind() ], $this->log ) )->undo( $r['batch']['id'] );
        $this->assertTrue( $undo['fully_undone'] );
        $this->assertSame( 'page', $GLOBALS['__wp_options']['show_on_front'] );
        $this->assertSame( 3, $GLOBALS['__wp_options']['page_on_front'] );
    }

    public function testUndoRestoresTheBlogPostsHomepage(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5 ] );
        ( new BatchUndo( [ 'front_page' => new FrontPageKind() ], $this->log ) )->undo( $r['batch']['id'] );
        $this->assertSame( 'posts', $GLOBALS['__wp_options']['show_on_front'] );
        $this->assertSame( 0, $GLOBALS['__wp_options']['page_on_front'] );
    }

    public function testUndoSkipsAFrontPageTheOwnerChangedSince(): void
    {
        $r = $this->tool->run( [ 'page_id' => 5 ] );
        update_option( 'page_on_front', 9 );
        $undo = ( new BatchUndo( [ 'front_page' => new FrontPageKind() ], $this->log ) )->undo( $r['batch']['id'] );
        $this->assertSame( 'changed_since', $undo['items'][0]['result'] );
        $this->assertSame( 9, $GLOBALS['__wp_options']['page_on_front'] );
    }

    public function testItIsRegisteredAndNeedsManageOptions(): void
    {
        $this->assertContains( 'set_front_page', array_map( static fn ( $t ) => $t->name(), Tools::all() ) );
        $this->assertSame( 'manage_options', $this->tool->capability() );
        $d = $this->tool->definition();
        $this->assertSame( 'set_front_page', $d['name'] );
        $this->assertLessThanOrEqual( 300, strlen( $d['description'] ) );
        $this->assertSame( [ 'page_id' ], $d['inputSchema']['required'] );
    }

    public function testAnEditorWithoutManageOptionsIsRefusedBeforeAnythingRuns(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $registry             = new ToolRegistry( [ $this->tool ], static fn (): bool => true );
        $d                    = (array) $registry->claim( null, 'set_front_page', [ 'page_id' => 5 ], 1 )->get_data();
        $this->assertSame( -32602, $d['error']['code'] );
        $this->assertSame( 'posts', $GLOBALS['__wp_options']['show_on_front'] );
    }
}
