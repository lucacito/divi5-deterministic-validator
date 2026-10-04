<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HistoryStore;
use AiEditorDivi5\WP\HistoryTable;
use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/ApprovalMode.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/**
 * Behavioural proof that dry_run never writes: it drives the REAL REST and MCP handlers against the
 * fake database and checks that the page content, the history rows and wp_update_post are untouched
 * (and that the same calls without dry_run do save, so a handler that always previewed would fail).
 */
class DryRunHandlersTest extends TestCase
{
    private const ORIGINAL = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    /** A different but valid layout (a second shop module). */
    private const NEW_VALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    /** A layout the checker refuses (an unknown block type). */
    private const NEW_INVALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/not-a-module {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']      = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $post                         = new \WP_Post();
        $post->ID                     = 5;
        $post->post_content           = self::ORIGINAL;
        $GLOBALS['__wp_posts']        = [ 5 => $post ];
        $GLOBALS['__wp_postmeta']     = [];
        $GLOBALS['__wp_update_fail']  = false;
        $GLOBALS['__wp_update_calls'] = 0;
        $GLOBALS['__wp_can']          = true;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']         = null;
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_can']     = true;
    }

    // ---- helpers --------------------------------------------------------------------------

    /** @return array{0:int,1:array<string,mixed>} status, data */
    private function restUpdate( array $body ): array
    {
        $r = ( new RestController() )->update_page( new \WP_REST_Request( $body, [ 'id' => 5 ] ) );

        return $this->unwrap( $r );
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function restEdit( array $body ): array
    {
        $r = ( new RestController() )->edit_page( new \WP_REST_Request( $body, [ 'id' => 5 ] ) );

        return $this->unwrap( $r );
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function unwrap( mixed $r ): array
    {
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array{0:?int,1:array<string,mixed>} JSON-RPC error code (or null), decoded tool result */
    private function mcp( string $tool, array $args ): array
    {
        $req = new \WP_REST_Request( [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [ 'name' => $tool, 'arguments' => $args ],
        ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], [] ];
        }

        return [ null, (array) json_decode( $data['result']['content'][0]['text'], true ) ];
    }

    private function historyRows(): int
    {
        return (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM wp_ai_editor_divi5_history' );
    }

    private function assertNothingWritten( string $why ): void
    {
        $this->assertSame( self::ORIGINAL, $GLOBALS['__wp_posts'][5]->post_content, "{$why}: post content changed" );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], "{$why}: wp_update_post was called" );
        $this->assertSame( [], HistoryStore::summaries( 5 ), "{$why}: a history entry appeared" );
        $this->assertSame( 0, $this->historyRows(), "{$why}: the history table changed" );
    }

    /** @return array<string,array{mixed}> */
    public static function mistyped(): array
    {
        return [ 'string false' => [ 'false' ], 'int 0' => [ 0 ], 'string yes' => [ 'yes' ], 'empty array' => [ [] ], 'int 1' => [ 1 ] ];
    }

    // ---- dry runs never write -------------------------------------------------------------

    public function testRestUpdateDryRunPreviewsValidAndInvalidLayoutsWithoutWriting(): void
    {
        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );
        $this->assertSame( 200, $status );
        $this->assertFalse( $data['saved'] );
        $this->assertTrue( $data['dry_run'] );
        $this->assertTrue( $data['valid'] );
        $this->assertSame( 1, $data['diff']['summary']['added'] ?? null, 'the preview carries a real diff' );
        $this->assertNothingWritten( 'REST update, valid layout' );

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_INVALID, 'dry_run' => true ] );
        $this->assertSame( 200, $status, 'an invalid preview is a normal 200' );
        $this->assertFalse( $data['saved'] );
        $this->assertTrue( $data['dry_run'] );
        $this->assertFalse( $data['valid'] );
        $this->assertNotEmpty( $data['violations'] );
        $this->assertNothingWritten( 'REST update, invalid layout' );
    }

    public function testRestEditDryRunPreviewsWithoutWriting(): void
    {
        [ $status, $data ] = $this->restEdit( [ 'find' => '4_4', 'replace' => '1_2', 'dry_run' => true ] );
        $this->assertSame( 200, $status );
        $this->assertFalse( $data['saved'] );
        $this->assertTrue( $data['dry_run'] );
        $this->assertSame( 1, $data['replaced'] );
        $this->assertNothingWritten( 'REST edit, valid result' );

        [ $status, $data ] = $this->restEdit( [ 'find' => 'divi/shop', 'replace' => 'divi/not-a-module', 'dry_run' => true ] );
        $this->assertSame( 200, $status );
        $this->assertFalse( $data['saved'] );
        $this->assertFalse( $data['valid'] );
        $this->assertNothingWritten( 'REST edit, invalid result' );
    }

    public function testMcpUpdateDryRunPreviewsWithoutWriting(): void
    {
        foreach ( [ self::NEW_VALID => true, self::NEW_INVALID => false ] as $content => $valid ) {
            [ $err, $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => $content, 'dry_run' => true ] );
            $this->assertNull( $err );
            $this->assertFalse( $data['saved'] );
            $this->assertTrue( $data['dry_run'] );
            $this->assertSame( $valid, $data['valid'] );
            $this->assertNothingWritten( 'MCP update, valid=' . var_export( $valid, true ) );
        }
    }

    public function testMcpEditDryRunPreviewsWithoutWriting(): void
    {
        [ $err, $data ] = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => '4_4', 'replace' => '1_2', 'dry_run' => true ] );
        $this->assertNull( $err );
        $this->assertFalse( $data['saved'] );
        $this->assertTrue( $data['dry_run'] );
        $this->assertSame( 1, $data['replaced'] );
        $this->assertNothingWritten( 'MCP edit, valid result' );

        [ $err, $data ] = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => 'divi/shop', 'replace' => 'divi/not-a-module', 'dry_run' => true ] );
        $this->assertNull( $err );
        $this->assertFalse( $data['valid'] );
        $this->assertNothingWritten( 'MCP edit, invalid result' );
    }

    // ---- a mistyped flag is rejected, never treated as false -------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider( 'mistyped' )]
    public function testAMistypedDryRunIsRejectedOnEveryEntryPointAndWritesNothing( mixed $bad ): void
    {
        $this->assertSame( 400, $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'dry_run' => $bad ] )[0] );
        $this->assertSame( 400, $this->restEdit( [ 'find' => '4_4', 'replace' => '1_2', 'dry_run' => $bad ] )[0] );
        $this->assertSame( -32602, $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID, 'dry_run' => $bad ] )[0] );
        $this->assertSame( -32602, $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => '4_4', 'replace' => '1_2', 'dry_run' => $bad ] )[0] );
        $this->assertNothingWritten( 'mistyped dry_run ' . json_encode( $bad ) );
    }

    // ---- the same calls without dry_run DO save (the test would catch an always-preview handler) --

    public function testWithoutDryRunTheSameCallsSaveAndSnapshot(): void
    {
        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( self::NEW_VALID, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 1, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( 1, $this->historyRows() );

        [ $err, $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::ORIGINAL, 'dry_run' => false ] );
        $this->assertNull( $err );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( self::ORIGINAL, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 2, $this->historyRows() );

        [ $status, $data ] = $this->restEdit( [ 'find' => '4_4', 'replace' => '1_2' ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertStringContainsString( '1_2', $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 3, $this->historyRows() );

        [ $err, $data ] = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => '1_2', 'replace' => '4_4' ] );
        $this->assertNull( $err );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( self::ORIGINAL, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 4, $this->historyRows() );
        $this->assertSame( 4, $GLOBALS['__wp_update_calls'] );
    }
}
