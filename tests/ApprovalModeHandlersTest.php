<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ApprovalMode;
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
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** Approval mode, driven through the REAL REST and MCP handlers against the fake database. */
class ApprovalModeHandlersTest extends TestCase
{
    private const ORIGINAL = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private const NEW_VALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private const NEW_INVALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/not-a-module {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']         = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_transient_ttl']   = [];
        $GLOBALS['__wp_current_user_id'] = 7;
        $post                            = new \WP_Post();
        $post->ID                        = 5;
        $post->post_content              = self::ORIGINAL;
        $GLOBALS['__wp_posts']           = [ 5 => $post ];
        $GLOBALS['__wp_postmeta']        = [];
        $GLOBALS['__wp_update_fail']     = false;
        $GLOBALS['__wp_update_calls']    = 0;
        $GLOBALS['__wp_can']             = true;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']            = null;
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        unset( $GLOBALS['__wp_current_user_id'] );
        $GLOBALS['__wp_can'] = true;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function unwrap( mixed $r ): array
    {
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function restUpdate( array $body ): array
    {
        return $this->unwrap( ( new RestController() )->update_page( new \WP_REST_Request( $body, [ 'id' => 5 ] ) ) );
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function restEdit( array $body ): array
    {
        return $this->unwrap( ( new RestController() )->edit_page( new \WP_REST_Request( $body, [ 'id' => 5 ] ) ) );
    }

    /** @return array{0:?int,1:array<string,mixed>,2:bool} JSON-RPC error code, decoded tool result, isError */
    private function mcp( string $tool, array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], [], true ];
        }

        return [ null, (array) json_decode( $data['result']['content'][0]['text'], true ), (bool) ( $data['result']['isError'] ?? false ) ];
    }

    private function assertUnchanged( string $why ): void
    {
        $this->assertSame( self::ORIGINAL, $GLOBALS['__wp_posts'][5]->post_content, "{$why}: content changed" );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], "{$why}: wp_update_post was called" );
    }

    // ---- off: nothing changes -------------------------------------------------------------------

    public function testWithTheModeOffSavesNeedNoPreviewAndNoStateIsWritten(): void
    {
        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );
        $this->assertSame( [], $GLOBALS['__wp_transients'] );
    }

    // ---- on: the four entry points ---------------------------------------------------------------

    public function testRestUpdateIsRefusedWith428WithoutAPreviewThenAllowedOnceAfterOne(): void
    {
        ApprovalMode::set( true );

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 428, $status );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] );
        $this->assertFalse( $data['saved'] );
        $this->assertUnchanged( 'no preview' );

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['valid'] );
        $this->assertUnchanged( 'the preview itself' );

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( self::NEW_VALID, $GLOBALS['__wp_posts'][5]->post_content );

        [ $status ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 428, $status, 'the preview was used up' );
    }

    public function testRestEditFollowsTheSameRule(): void
    {
        ApprovalMode::set( true );
        $body = [ 'find' => '4_4', 'replace' => '1_2' ];

        [ $status, $data ] = $this->restEdit( $body );
        $this->assertSame( 428, $status );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] );
        $this->assertUnchanged( 'edit without preview' );

        $this->restEdit( $body + [ 'dry_run' => true ] );
        [ $status, $data ] = $this->restEdit( $body );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertStringContainsString( '1_2', $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testMcpUpdateIsAToolErrorWithoutAPreviewThenAllowedAfterOne(): void
    {
        ApprovalMode::set( true );
        $args = [ 'page_id' => 5, 'post_content' => self::NEW_VALID ];

        [ $err, $data, $isError ] = $this->mcp( 'update_page_layout', $args );
        $this->assertNull( $err );
        $this->assertTrue( $isError );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] );
        $this->assertUnchanged( 'mcp update without preview' );

        $this->mcp( 'update_page_layout', $args + [ 'dry_run' => true ] );
        [ $err, $data, $isError ] = $this->mcp( 'update_page_layout', $args );
        $this->assertFalse( $isError );
        $this->assertTrue( $data['saved'] );
    }

    public function testMcpEditFollowsTheSameRule(): void
    {
        ApprovalMode::set( true );
        $args = [ 'page_id' => 5, 'find' => '4_4', 'replace' => '1_2' ];

        [ , $data, $isError ] = $this->mcp( 'edit_page_content', $args );
        $this->assertTrue( $isError );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] );
        $this->assertUnchanged( 'mcp edit without preview' );

        $this->mcp( 'edit_page_content', $args + [ 'dry_run' => true ] );
        [ , $data, $isError ] = $this->mcp( 'edit_page_content', $args );
        $this->assertFalse( $isError );
        $this->assertTrue( $data['saved'] );
    }

    public function testAPreviewIsVoidWhenThePageChangedAfterItOnTheRestUpdate(): void
    {
        ApprovalMode::set( true );
        $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );

        // Someone saved the page in the builder after the owner saw the preview's diff.
        $GLOBALS['__wp_posts'][5]->post_content = self::NEW_INVALID;

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 428, $status );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] );
        $this->assertSame( self::NEW_INVALID, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );

        $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );
        [ $status ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 200, $status, 'a fresh preview against the new base unlocks the save' );
    }

    public function testAPreviewIsVoidWhenThePageChangedAfterItOnTheMcpUpdate(): void
    {
        ApprovalMode::set( true );
        $args = [ 'page_id' => 5, 'post_content' => self::NEW_VALID ];
        $this->mcp( 'update_page_layout', $args + [ 'dry_run' => true ] );

        $GLOBALS['__wp_posts'][5]->post_content = self::NEW_INVALID;

        [ , $data, $isError ] = $this->mcp( 'update_page_layout', $args );
        $this->assertTrue( $isError );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    // ---- what a preview does and does not unlock ---------------------------------------------------

    public function testAPreviewOfOneChangeDoesNotUnlockADifferentChange(): void
    {
        ApprovalMode::set( true );
        $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );

        [ $status ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID . ' ' ] );
        $this->assertSame( 428, $status, 'one extra character is a different change' );
        $this->assertUnchanged( 'different content' );

        $GLOBALS['__wp_current_user_id'] = 8;
        [ $status ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 428, $status, 'another user cannot use this user\'s preview' );
        $this->assertUnchanged( 'another user' );
    }

    public function testAnInvalidPreviewUnlocksNothingAndAnInvalidSaveReturnsViolationsNotTheApprovalError(): void
    {
        ApprovalMode::set( true );

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_INVALID, 'dry_run' => true ] );
        $this->assertSame( 200, $status );
        $this->assertFalse( $data['valid'] );
        $this->assertSame( [], $GLOBALS['__wp_transients'], 'an invalid preview records nothing' );

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_INVALID ] );
        $this->assertSame( 422, $status, 'the AI gets the violations so it can fix the layout' );
        $this->assertArrayHasKey( 'violations', $data );
        $this->assertArrayNotHasKey( 'code', $data );
        $this->assertUnchanged( 'invalid save' );
    }

    public function testTurningTheModeOffBetweenPreviewAndSaveStillSaves(): void
    {
        ApprovalMode::set( true );
        $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );
        ApprovalMode::set( false );

        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
    }
}
