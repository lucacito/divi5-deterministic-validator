<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Admin\BatchesPanel;
use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\Batch\Item;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/InMemoryBatchRepository.php';
require_once __DIR__ . '/FakeKind.php';

class BatchesPanelTest extends TestCase
{
    private InMemoryBatchRepository $repo;
    private BatchLog $log;
    private bool $unlocked = true;

    protected function setUp(): void
    {
        $this->repo = new InMemoryBatchRepository();
        $this->log  = new BatchLog( $this->repo );
        $GLOBALS['__fake_store']   = [];
        $GLOBALS['__fake_allowed'] = true;
        $GLOBALS['__fake_fail']    = false;
        $GLOBALS['__fake_writes']  = [];
        $GLOBALS['__wp_redirects'] = [];
        $GLOBALS['__wp_filters']   = [];
        $GLOBALS['__wp_can']       = true;
        unset( $GLOBALS['__wp_caps'] );
        $_GET = $_POST = $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->unlocked            = true;
    }

    protected function tearDown(): void
    {
        $_GET = $_POST = $_REQUEST = [];
        $GLOBALS['__wp_can'] = true;
        unset( $GLOBALS['__wp_caps'] );
    }

    private function panel(): BatchesPanel
    {
        return new BatchesPanel( fn (): bool => $this->unlocked, $this->log, new BatchUndo( [ 'fake' => new FakeKind() ], $this->log ), static function (): void {} );
    }

    private function render( string $tab = 'pro' ): string
    {
        ob_start();
        $this->panel()->render( $tab );

        return (string) ob_get_clean();
    }

    private function change( string $ref, mixed $new, string $summary = 'a change', int $uid = 7 ): int
    {
        $old                             = $GLOBALS['__fake_store'][ $ref ] ?? null;
        $GLOBALS['__fake_store'][ $ref ] = $new;
        $b                               = $this->log->record( 'fake_tool', $summary, [ Item::make( 'fake', $ref, "Thing {$ref}", $old, $new ) ] );
        $this->repo->rows[ $b['id'] ]['user_id'] = $uid;

        return $b['id'];
    }

    private function post( int|string $id ): void
    {
        $_POST    = [ 'batch_id' => (string) $id ];
        $_REQUEST = $_POST + [ '_wpnonce' => 'nonce-for-aied_pro_undo_batch' ];
    }

    public function testRegisterAttachesTheTabRenderAndTheUndoAction(): void
    {
        $this->panel()->register();
        $this->assertArrayHasKey( 'jhmg_aied_render_admin_tab', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'admin_post_aied_pro_undo_batch', $GLOBALS['__wp_filters'] );
    }

    public function testItRendersOnlyOnTheProTabForEditorsWhileUnlocked(): void
    {
        $this->change( 'a', 1 );
        $this->assertSame( '', $this->render( 'features' ) );
        $this->unlocked = false;
        $this->assertSame( '', $this->render() );
        $this->unlocked       = true;
        $GLOBALS['__wp_caps'] = [ 'read' => true ];
        $this->assertSame( '', $this->render() );
    }

    public function testItShowsAnEmptyState(): void
    {
        $html = $this->render();
        $this->assertStringContainsString( 'Recent Pro changes', $html );
        $this->assertStringContainsString( 'No Pro changes yet', $html );
    }

    public function testItListsBatchesEscapedWithAnUndoFormOnlyWhereUndoIsPossible(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $undoable                     = $this->change( 'a', 'two', '<script>alert(1)</script> set a' );
        $none                         = $this->log->record( 'fake_tool', 'no undo point', [ Item::make( 'fake', 'z', 'Z', 1, 2, [ 'available' => false, 'reason' => 'missing' ] ) ] )['id'];
        $html                         = $this->render();
        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; set a', $html );
        $this->assertStringContainsString( 'name="batch_id" value="' . $undoable . '"', $html );
        $this->assertStringNotContainsString( 'name="batch_id" value="' . $none . '"', $html );
        $this->assertStringContainsString( 'nonce-for-aied_pro_undo_batch', $html );
    }

    public function testAnUndoneBatchHasNoUndoButton(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $this->post( $id );
        $this->panel()->handleUndo();
        $html = $this->render();
        $this->assertStringContainsString( 'undone', $html );
        $this->assertStringNotContainsString( 'name="batch_id" value="' . $id . '"', $html );
    }

    public function testANonAdministratorSeesOnlyTheirOwnBatches(): void
    {
        $this->change( 'a', 1, 'mine', 7 );
        $this->change( 'b', 2, 'theirs', 99 );
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true ];
        $html                 = $this->render();
        $this->assertStringContainsString( 'mine', $html );
        $this->assertStringNotContainsString( 'theirs', $html );
        unset( $GLOBALS['__wp_caps'] );
        $this->assertStringContainsString( 'theirs', $this->render() );
    }

    public function testUndoRestoresAndRedirectsWithTheRightNotice(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $this->post( $id );
        $this->panel()->handleUndo();
        $this->assertSame( 'one', $GLOBALS['__fake_store']['a'] );
        $this->assertStringContainsString( 'aied_pro_notice=batch_undone', $GLOBALS['__wp_redirects'][0] );
        $this->assertStringContainsString( 'tab=pro', $GLOBALS['__wp_redirects'][0] );
    }

    public function testAChangedSinceBatchIsNotOverwrittenAndTheNoticeSaysNothingWasRestored(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $GLOBALS['__fake_store']['a'] = 'edited since';
        $this->post( $id );
        $this->panel()->handleUndo();
        $this->assertSame( 'edited since', $GLOBALS['__fake_store']['a'], 'the panel never forces' );
        $this->assertStringContainsString( 'aied_pro_notice=batch_nothing_restored', $GLOBALS['__wp_redirects'][0] );
    }

    public function testAPartialUndoSaysSo(): void
    {
        $GLOBALS['__fake_store'] = [ 'a' => 'a1', 'b' => 'b1' ];
        $b                       = $this->log->record( 'multi', 'two things', [
            Item::make( 'fake', 'a', 'A', 'a1', 'a2' ),
            Item::make( 'fake', 'b', 'B', 'b1', 'b2', [ 'available' => false, 'reason' => 'missing' ] ),
        ] );
        $GLOBALS['__fake_store'] = [ 'a' => 'a2', 'b' => 'b2' ];
        $this->post( $b['id'] );
        $this->panel()->handleUndo();
        $this->assertStringContainsString( 'aied_pro_notice=batch_partly_undone', $GLOBALS['__wp_redirects'][0] );
        $this->assertSame( 'a1', $GLOBALS['__fake_store']['a'] );
        $this->assertSame( 'b2', $GLOBALS['__fake_store']['b'] );
    }

    public function testAnUnknownOrNonNumericBatchIsNotFound(): void
    {
        foreach ( [ 99, 'abc', '1; DROP TABLE', '-1', '' ] as $bad ) {
            $GLOBALS['__wp_redirects'] = [];
            $this->post( $bad );
            $this->panel()->handleUndo();
            $this->assertStringContainsString( 'aied_pro_notice=batch_not_found', $GLOBALS['__wp_redirects'][0], (string) $bad );
        }
    }

    public function testAnotherUsersBatchIsNotFoundToANonAdministratorAndIsNotUndone(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two', 'theirs', 99 );
        $GLOBALS['__wp_caps']         = [ 'edit_pages' => true ];
        $this->post( $id );
        $this->panel()->handleUndo();
        $this->assertStringContainsString( 'aied_pro_notice=batch_not_found', $GLOBALS['__wp_redirects'][0] );
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'] );
    }

    public function testTheHandlerRefusesABadNonceAMissingCapabilityAndANonPost(): void
    {
        $GLOBALS['__fake_store']['a'] = 'one';
        $id                           = $this->change( 'a', 'two' );
        $_POST                        = [ 'batch_id' => (string) $id ];

        $_REQUEST = $_POST + [ '_wpnonce' => 'forged' ];
        try {
            $this->panel()->handleUndo();
            $this->fail( 'a forged nonce must be refused' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'check_admin_referer failed', $e->getMessage() );
        }

        $this->post( $id );
        $GLOBALS['__wp_caps'] = [ 'read' => true ];
        try {
            $this->panel()->handleUndo();
            $this->fail( 'edit_pages is required' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'wp_die', $e->getMessage() );
        }

        unset( $GLOBALS['__wp_caps'] );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            $this->panel()->handleUndo();
            $this->fail( 'POST only' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'wp_die', $e->getMessage() );
        }
        $this->assertSame( 'two', $GLOBALS['__fake_store']['a'], 'nothing was undone' );
    }

    public function testTheResultNoticesAreShown(): void
    {
        foreach ( [ 'batch_undone' => 'notice-success', 'batch_partly_undone' => 'notice-warning', 'batch_nothing_restored' => 'notice-error', 'batch_not_found' => 'notice-error' ] as $notice => $class ) {
            $_GET['aied_pro_notice'] = $notice;
            $this->assertStringContainsString( $class, $this->render(), $notice );
        }
        $_GET['aied_pro_notice'] = '<script>x</script>';
        $this->assertStringNotContainsString( '<script>', $this->render() );
    }
}
