<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ApprovalMode;
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
require_once __DIR__ . '/../wp-plugin/src/PageTitle.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/ApprovalMode.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/**
 * update_page_layout with a `title` (MCP half), driven through the REAL MCP handler against the fake database.
 */
class PageTitleHandlersTest extends TestCase
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
        $GLOBALS['__wp_options']         = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_transient_ttl']   = [];
        $GLOBALS['__wp_current_user_id'] = 7;
        $post                            = new \WP_Post();
        $post->ID                        = 5;
        $post->post_content              = self::ORIGINAL;
        $post->post_title                = 'Old title';
        $post->post_type                 = 'page';
        $post->post_status               = 'publish';
        $post->post_name                 = 'old-slug';
        $GLOBALS['__wp_posts']           = [ 5 => $post ];
        $GLOBALS['__wp_postmeta']        = [];
        $GLOBALS['__wp_update_fail']     = false;
        $GLOBALS['__wp_update_calls']    = 0;
        $GLOBALS['__wp_can']             = true;
        unset( $GLOBALS['__wp_update_hook'] );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']            = null;
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        unset( $GLOBALS['__wp_current_user_id'], $GLOBALS['__wp_update_hook'] );
        $GLOBALS['__wp_can'] = true;
    }

    // ---- helpers --------------------------------------------------------------------------

    /** @return array{0:?int,1:array<string,mixed>,2:bool} JSON-RPC error code (or null), decoded tool result, isError */
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
            return [ $data['error']['code'], [ 'message' => $data['error']['message'] ], true ];
        }

        return [ null, (array) json_decode( $data['result']['content'][0]['text'], true ), (bool) ( $data['result']['isError'] ?? false ) ];
    }

    private function post(): \WP_Post
    {
        return $GLOBALS['__wp_posts'][5];
    }

    private function historyRows(): int
    {
        return (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM wp_ai_editor_divi5_history' );
    }

    private function assertNothingWritten( string $why ): void
    {
        $this->assertSame( self::ORIGINAL, $this->post()->post_content, "{$why}: content changed" );
        $this->assertSame( 'Old title', $this->post()->post_title, "{$why}: title changed" );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], "{$why}: wp_update_post was called" );
        $this->assertSame( 0, $this->historyRows(), "{$why}: the history table changed" );
    }

    // ---- saves ----------------------------------------------------------------------------

    public function testTitleOnlySaveChangesOnlyTheTitle(): void
    {
        [ $code, $data, $isError ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'New title' ] );
        $this->assertNull( $code );
        $this->assertFalse( $isError );
        $this->assertTrue( $data['saved'] );
        $this->assertTrue( $data['valid'] );
        $this->assertFalse( $data['layout_changed'] );
        $this->assertSame( 'title_only', $data['history']['reason'] );
        $this->assertFalse( $data['history']['stored'] );
        $this->assertTrue( $data['title']['changed'] );
        $this->assertSame( 'Old title', $data['title']['before'] );
        $this->assertSame( 'New title', $data['title']['after'] );
        $this->assertFalse( $data['title']['restorable'] );
        $this->assertStringContainsString( 'Old title', $data['message'] );
        $this->assertSame( 5, $data['page']['id'] );
        $this->assertSame( 'New title', $this->post()->post_title );
        $this->assertSame( self::ORIGINAL, $this->post()->post_content );
        $this->assertSame( 'old-slug', $this->post()->post_name );
        $this->assertSame( 'publish', $this->post()->post_status );
        $this->assertSame( 1, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( 0, $this->historyRows() );
    }

    public function testCombinedSaveChangesBothAndSnapshotsTheContent(): void
    {
        [ $code, $data, $isError ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID, 'title' => 'New title' ] );
        $this->assertNull( $code );
        $this->assertFalse( $isError );
        $this->assertTrue( $data['saved'] );
        $this->assertTrue( $data['history']['stored'] );
        $this->assertTrue( $data['title']['changed'] );
        $this->assertSame( 'New title', $data['title']['after'] );
        $this->assertStringContainsString( '"Old title"', $data['message'] );
        $this->assertSame( 'New title', $this->post()->post_title );
        $this->assertSame( self::NEW_VALID, $this->post()->post_content );
        $this->assertSame( 1, $this->historyRows() );
        $entry = HistoryStore::entry( 5, (int) $data['history']['version_id'] );
        $this->assertSame( self::ORIGINAL, $entry['content'] );
    }

    public function testInvalidLayoutWithTitleRefusesTheWholeCall(): void
    {
        [ $code, $data, $isError ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_INVALID, 'title' => 'New title' ] );
        $this->assertNull( $code );
        $this->assertTrue( $isError );
        $this->assertFalse( $data['saved'] );
        $this->assertFalse( $data['valid'] );
        $this->assertNothingWritten( 'invalid layout + title' );
    }

    public function testTitleOnlySavesOnAPageWhoseContentIsNotDivi(): void
    {
        $this->post()->post_content = 'plain text';
        [ $code, $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'New title' ] );
        $this->assertNull( $code );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( 'New title', $this->post()->post_title );
        $this->assertSame( 'plain text', $this->post()->post_content );
    }

    public function testDiviProjectTitleOnlyWorks(): void
    {
        $this->post()->post_type = 'project';
        [ $code, $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'New title' ] );
        $this->assertNull( $code );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( 'New title', $this->post()->post_title );
    }

    public function testRoundTripKeepsEveryCharacter(): void
    {
        $title = 'Say "hi" \\ there & 🎉 é';
        [ , $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => $title ] );
        $this->assertSame( $title, $this->post()->post_title );
        $this->assertSame( $title, $data['title']['after'] );
    }

    public function testReportsTheTitleCoreActuallyStored(): void
    {
        $GLOBALS['__wp_update_hook'] = static function ( int $id, array $arr ): void {
            $GLOBALS['__wp_posts'][ $id ]->post_title = str_replace( '&', '&amp;', (string) $arr['post_title'] );
        };
        [ , $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'Tom & Jerry' ] );
        $this->assertSame( 'Tom &amp; Jerry', $this->post()->post_title );
        $this->assertSame( 'Tom &amp; Jerry', $data['title']['after'] );
    }

    // ---- input rules ----------------------------------------------------------------------

    /** @return array<string,array{mixed}> */
    public static function badTitles(): array
    {
        return [
            'int'        => [ 5 ],
            'bool'       => [ true ],
            'array'      => [ [] ],
            'empty'      => [ '' ],
            'blank'      => [ '   ' ],
            'html'       => [ '<b>x</b>' ],
            'too long'   => [ str_repeat( 'a', 201 ) ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'badTitles' )]
    public function testABadTitleIsAnInputErrorAndWritesNothing( mixed $bad ): void
    {
        [ $code ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => $bad ] );
        $this->assertSame( -32602, $code );
        [ $code2 ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID, 'title' => $bad ] );
        $this->assertSame( -32602, $code2 );
        $this->assertNothingWritten( 'bad title' );
    }

    public function testNeitherFieldIsAnInputError(): void
    {
        [ $code, $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5 ] );
        $this->assertSame( -32602, $code );
        $this->assertSame( 'Give post_content, title, or both.', $data['message'] );
        $this->assertNothingWritten( 'neither' );
    }

    public function testEmptyContentWithATitleIsStillRefused(): void
    {
        [ $code ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => '', 'title' => 'New title' ] );
        $this->assertSame( -32602, $code );
        $this->assertNothingWritten( 'empty content + title' );
    }

    public function testPermissionIsRequiredForTheTitleToo(): void
    {
        $GLOBALS['__wp_can'] = false;
        [ $code, $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'New title' ] );
        $this->assertSame( -32602, $code );
        $this->assertStringContainsString( 'permission', $data['message'] );
        $this->assertNothingWritten( 'no permission' );
    }

    // ---- dry run --------------------------------------------------------------------------

    public function testDryRunTitleOnlyWritesNothing(): void
    {
        [ $code, $data, $isError ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'New title', 'dry_run' => true ] );
        $this->assertNull( $code );
        $this->assertFalse( $isError );
        $this->assertTrue( $data['dry_run'] );
        $this->assertFalse( $data['saved'] );
        $this->assertTrue( $data['title']['changed'] );
        $this->assertSame( 'Old title', $data['title']['before'] );
        $this->assertSame( 'New title', $data['title']['after'] );
        $this->assertNothingWritten( 'dry run title only' );
    }

    public function testDryRunCombinedShowsDiffAndTitleAndWritesNothing(): void
    {
        [ , $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID, 'title' => 'New title', 'dry_run' => true ] );
        $this->assertTrue( $data['dry_run'] );
        $this->assertTrue( $data['valid'] );
        $this->assertNotEmpty( $data['diff'] );
        $this->assertSame( 'New title', $data['title']['after'] );
        $this->assertNothingWritten( 'dry run combined' );
    }

    public function testDryRunUnchangedTitleSaysSo(): void
    {
        [ , $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'Old title', 'dry_run' => true ] );
        $this->assertFalse( $data['title']['changed'] );
        $this->assertStringContainsString( 'already "Old title"', $data['message'] );
        $this->assertNothingWritten( 'dry run unchanged' );
    }

    // ---- unchanged title ------------------------------------------------------------------

    public function testUnchangedTitleOnlyIsANoOpAndNeedsNoApproval(): void
    {
        ApprovalMode::set( true );
        [ $code, $data, $isError ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'Old title' ] );
        $this->assertNull( $code );
        $this->assertFalse( $isError );
        $this->assertFalse( $data['saved'] );
        $this->assertTrue( $data['valid'] );
        $this->assertFalse( $data['layout_changed'] );
        $this->assertFalse( $data['title']['changed'] );
        $this->assertStringContainsString( 'already "Old title"', $data['message'] );
        $this->assertNothingWritten( 'unchanged title' );
    }

    public function testUnchangedTitleInACombinedCallStillSavesTheLayout(): void
    {
        [ $code, $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID, 'title' => 'Old title' ] );
        $this->assertNull( $code );
        $this->assertTrue( $data['saved'] );
        $this->assertFalse( $data['title']['changed'] );
        $this->assertSame( self::NEW_VALID, $this->post()->post_content );
        $this->assertSame( 'Old title', $this->post()->post_title );
        $this->assertSame( 1, $this->historyRows() );
        $this->assertSame( 'The page title was already "Old title" and was left as it is.', $data['message'] );
        $this->assertStringNotContainsString( 'Nothing was changed', $data['message'] );
    }

    public function testDryRunWithAnUnchangedTitleInACombinedCallDoesNotClaimNothingChanges(): void
    {
        [ , $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID, 'title' => 'Old title', 'dry_run' => true ] );
        $this->assertFalse( $data['title']['changed'] );
        $this->assertNotEmpty( $data['diff'] );
        $this->assertSame( 'Preview only. Nothing was saved.', $data['message'] );
        $this->assertNothingWritten( 'dry run combined, title unchanged' );
    }

    public function testExplicitNullContentWithATitleIsTitleOnly(): void
    {
        [ $code, $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => null, 'title' => 'New title' ] );
        $this->assertNull( $code );
        $this->assertTrue( $data['saved'] );
        $this->assertFalse( $data['layout_changed'] );
        $this->assertSame( 'New title', $this->post()->post_title );
        $this->assertSame( self::ORIGINAL, $this->post()->post_content );
    }

    public function testATitleLessSaveKeepsTodaysResponseShape(): void
    {
        [ , $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID ] );
        $this->assertSame( [ 'saved', 'valid', 'history', 'page' ], array_keys( $data ) );
    }

    // ---- approval mode --------------------------------------------------------------------

    private function assertRefused( array $r, string $why ): void
    {
        [ $code, $data, $isError ] = $r;
        $this->assertNull( $code, $why );
        $this->assertTrue( $isError, $why );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] ?? null, $why );
    }

    public function testApprovalModeRequiresAPreviewForATitleOnlySave(): void
    {
        ApprovalMode::set( true );
        $this->assertRefused( $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'A' ] ), 'no preview' );
        $this->assertNothingWritten( 'refused' );
    }

    public function testApprovalModePreviewIsBoundToTheExactTitleAndUsedOnce(): void
    {
        ApprovalMode::set( true );
        $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'A', 'dry_run' => true ] );
        $this->assertRefused( $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'B' ] ), 'other title' );
        $this->assertNothingWritten( 'other title' );

        [ , $data ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'A' ] );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( 'A', $this->post()->post_title );

        $this->post()->post_title = 'Old title';
        $this->assertRefused( $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'A' ] ), 'second use' );
    }

    public function testApprovalModeCombinedLayoutAndTitleNeedsItsOwnPreviewAndIsUsedOnce(): void
    {
        ApprovalMode::set( true );
        $args = [ 'page_id' => 5, 'post_content' => self::NEW_VALID, 'title' => 'A' ];
        $this->mcp( 'update_page_layout', $args + [ 'dry_run' => true ] );
        [ , $data, $isError ] = $this->mcp( 'update_page_layout', $args );
        $this->assertFalse( $isError );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( 'A', $this->post()->post_title );
        $this->assertSame( self::NEW_VALID, $this->post()->post_content );

        // Put the page back exactly as it was at preview time: the same save must still be refused (single use).
        $this->post()->post_title   = 'Old title';
        $this->post()->post_content = self::ORIGINAL;
        $this->assertRefused( $this->mcp( 'update_page_layout', $args ), 'second use' );
    }

    public function testALayoutOnlyPreviewDoesNotAuthoriseLayoutPlusTitle(): void
    {
        ApprovalMode::set( true );
        $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID, 'dry_run' => true ] );
        $this->assertRefused( $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID, 'title' => 'A' ] ), 'layout preview, layout+title save' );
        $this->assertNothingWritten( 'layout preview only' );
    }

    public function testATitlePreviewDoesNotAuthoriseALayoutOnlySave(): void
    {
        ApprovalMode::set( true );
        $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID, 'title' => 'A', 'dry_run' => true ] );
        $this->assertRefused( $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID ] ), 'title preview, layout-only save' );
        $this->assertNothingWritten( 'title preview only' );
    }

    public function testApprovalPreviewIsVoidIfTheStoredTitleChangedSincePreview(): void
    {
        ApprovalMode::set( true );
        $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'A', 'dry_run' => true ] );
        $this->post()->post_title = 'Someone else changed it';
        $this->assertRefused( $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'title' => 'A' ] ), 'stored title changed' );
        $this->assertSame( 'Someone else changed it', $this->post()->post_title );
    }

    // ---- schema and restore ---------------------------------------------------------------

    public function testToolSchemas(): void
    {
        $req   = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ] );
        $data  = (array) ( new McpHandler() )->handle( $req )->get_data();
        $tools = [];
        foreach ( $data['result']['tools'] as $t ) {
            $tools[ $t['name'] ] = $t;
        }
        $this->assertArrayNotHasKey( 'title', $tools['edit_page_content']['inputSchema']['properties'] );
        $update = $tools['update_page_layout']['inputSchema'];
        $this->assertSame( 'string', $update['properties']['title']['type'] );
        $this->assertSame( [ 'page_id' ], $update['required'] );
        $this->assertStringContainsString( 'Optional when title is given', $update['properties']['post_content']['description'] );
    }

    public function testRestoreSaysTheTitleWasLeftAlone(): void
    {
        [ , $saved ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID, 'title' => 'New title' ] );
        [ $code, $data ] = $this->mcp( 'restore_page_version', [ 'page_id' => 5, 'version_id' => $saved['history']['version_id'] ] );
        $this->assertNull( $code );
        $this->assertTrue( $data['restored'] );
        $this->assertFalse( $data['title_restored'] );
        $this->assertStringContainsString( 'New title', $data['title_note'] );
        $this->assertSame( 'New title', $this->post()->post_title );
        $this->assertSame( self::ORIGINAL, $this->post()->post_content );
    }

    // ==== REST: PUT /pages/{id} with a title ==================================================

    /** @return array{0:int,1:array<string,mixed>} HTTP status, decoded body (a WP_Error becomes ['error' => code, 'message' => text]) */
    private function restUpdate( array $body ): array
    {
        $r = ( new RestController() )->update_page( new \WP_REST_Request( $body, [ 'id' => 5 ] ) );
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code, 'message' => $r->get_error_message() ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    public function testRestTitleOnlySaveChangesOnlyTheTitle(): void
    {
        [ $status, $data ] = $this->restUpdate( [ 'title' => 'New title' ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertTrue( $data['valid'] );
        $this->assertSame( [], $data['violations'] );
        $this->assertFalse( $data['layout_changed'] );
        $this->assertSame( [ 'stored' => false, 'version_id' => null, 'reason' => 'title_only' ], $data['history'] );
        $this->assertTrue( $data['title']['changed'] );
        $this->assertSame( 'Old title', $data['title']['before'] );
        $this->assertSame( 'New title', $data['title']['after'] );
        $this->assertFalse( $data['title']['restorable'] );
        $this->assertStringContainsString( 'Old title', $data['message'] );
        $this->assertSame( 'New title', $data['page']['post_title'] );
        $this->assertSame( self::ORIGINAL, $data['page']['post_content'] );
        $this->assertSame( 'New title', $this->post()->post_title );
        $this->assertSame( self::ORIGINAL, $this->post()->post_content );
        $this->assertSame( 'old-slug', $this->post()->post_name );
        $this->assertSame( 'publish', $this->post()->post_status );
        $this->assertSame( 1, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( 0, $this->historyRows() );
    }

    public function testRestCombinedSaveChangesBothAndSnapshotsTheContent(): void
    {
        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'title' => 'New title' ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertTrue( $data['history']['stored'] );
        $this->assertTrue( $data['title']['changed'] );
        $this->assertSame( 'New title', $data['title']['after'] );
        $this->assertStringContainsString( '"Old title"', $data['message'] );
        $this->assertSame( 'New title', $data['page']['post_title'] );
        $this->assertSame( self::NEW_VALID, $data['page']['post_content'] );
        $this->assertSame( 'New title', $this->post()->post_title );
        $this->assertSame( self::NEW_VALID, $this->post()->post_content );
        $this->assertSame( 1, $this->historyRows() );
        $entry = HistoryStore::entry( 5, (int) $data['history']['version_id'] );
        $this->assertSame( self::ORIGINAL, $entry['content'] );
    }

    public function testRestInvalidLayoutWithTitleRefusesTheWholeCall(): void
    {
        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_INVALID, 'title' => 'New title' ] );
        $this->assertSame( 422, $status );
        $this->assertFalse( $data['saved'] );
        $this->assertFalse( $data['valid'] );
        $this->assertNotEmpty( $data['violations'] );
        $this->assertNothingWritten( 'rest invalid layout + title' );
    }

    public function testRestTitleOnlySavesOnAPageWhoseContentIsNotDivi(): void
    {
        $this->post()->post_content = 'plain text';
        [ $status, $data ] = $this->restUpdate( [ 'title' => 'New title' ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( 'New title', $this->post()->post_title );
        $this->assertSame( 'plain text', $this->post()->post_content );
    }

    public function testRestDiviProjectTitleOnlyWorks(): void
    {
        $this->post()->post_type = 'project';
        [ $status, $data ] = $this->restUpdate( [ 'title' => 'New title' ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( 'New title', $this->post()->post_title );
    }

    public function testRestRoundTripKeepsEveryCharacter(): void
    {
        $title = 'Say "hi" \\ there & 🎉 é';
        [ , $data ] = $this->restUpdate( [ 'title' => $title ] );
        $this->assertSame( $title, $this->post()->post_title );
        $this->assertSame( $title, $data['title']['after'] );
        $this->assertSame( $title, $data['page']['post_title'] );
    }

    public function testRestReportsTheTitleCoreActuallyStored(): void
    {
        $GLOBALS['__wp_update_hook'] = static function ( int $id, array $arr ): void {
            $GLOBALS['__wp_posts'][ $id ]->post_title = str_replace( '&', '&amp;', (string) $arr['post_title'] );
        };
        [ , $data ] = $this->restUpdate( [ 'title' => 'Tom & Jerry' ] );
        $this->assertSame( 'Tom &amp; Jerry', $this->post()->post_title );
        $this->assertSame( 'Tom &amp; Jerry', $data['title']['after'] );
        $this->assertSame( 'Tom &amp; Jerry', $data['page']['post_title'] );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'badTitles' )]
    public function testRestABadTitleIs400AndWritesNothing( mixed $bad ): void
    {
        [ $status, $data ] = $this->restUpdate( [ 'title' => $bad ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'invalid_field', $data['error'] );
        [ $status2, $data2 ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'title' => $bad ] );
        $this->assertSame( 400, $status2 );
        $this->assertSame( 'invalid_field', $data2['error'] );
        $this->assertNothingWritten( 'rest bad title' );
    }

    public function testRestNeitherFieldIs400(): void
    {
        [ $status, $data ] = $this->restUpdate( [] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'missing_field', $data['error'] );
        $this->assertSame( 'Give post_content, title, or both.', $data['message'] );
        $this->assertNothingWritten( 'rest neither' );
    }

    public function testRestBadContentKeepsItsOldMessageEvenWithATitle(): void
    {
        foreach ( [ '', '   ', 5, [] ] as $bad ) {
            [ $status, $data ] = $this->restUpdate( [ 'post_content' => $bad, 'title' => 'New title' ] );
            $this->assertSame( 400, $status );
            $this->assertSame( 'missing_field', $data['error'] );
            $this->assertSame( 'Request body must include a non-empty string "post_content".', $data['message'] );
        }
        $this->assertNothingWritten( 'rest bad content + title' );
    }

    public function testRestNonBooleanDryRunIs400(): void
    {
        [ $status, $data ] = $this->restUpdate( [ 'title' => 'New title', 'dry_run' => 'yes' ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'invalid_field', $data['error'] );
        $this->assertNothingWritten( 'rest bad dry_run' );
    }

    public function testRestPermissionIsRequiredForTheTitleToo(): void
    {
        $GLOBALS['__wp_can'] = false;
        [ $status ] = $this->restUpdate( [ 'title' => 'New title' ] );
        $this->assertSame( 403, $status );
        $this->assertNothingWritten( 'rest no permission' );
    }

    public function testRestUnknownPageIs404(): void
    {
        $r = ( new RestController() )->update_page( new \WP_REST_Request( [ 'title' => 'x' ], [ 'id' => 999 ] ) );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 404, $r->data['status'] );
    }

    public function testRestDryRunTitleOnlyWritesNothing(): void
    {
        [ $status, $data ] = $this->restUpdate( [ 'title' => 'New title', 'dry_run' => true ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['dry_run'] );
        $this->assertFalse( $data['saved'] );
        $this->assertFalse( $data['layout_changed'] );
        $this->assertTrue( $data['title']['changed'] );
        $this->assertSame( 'Old title', $data['title']['before'] );
        $this->assertSame( 'New title', $data['title']['after'] );
        $this->assertNothingWritten( 'rest dry run title only' );
    }

    public function testRestDryRunCombinedShowsDiffAndTitleAndWritesNothing(): void
    {
        [ , $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'title' => 'New title', 'dry_run' => true ] );
        $this->assertTrue( $data['dry_run'] );
        $this->assertTrue( $data['valid'] );
        $this->assertNotEmpty( $data['diff'] );
        $this->assertSame( 'New title', $data['title']['after'] );
        $this->assertNothingWritten( 'rest dry run combined' );
    }

    public function testRestDryRunUnchangedTitleSaysSo(): void
    {
        [ , $data ] = $this->restUpdate( [ 'title' => 'Old title', 'dry_run' => true ] );
        $this->assertFalse( $data['title']['changed'] );
        $this->assertStringContainsString( 'already "Old title"', $data['message'] );
        $this->assertNothingWritten( 'rest dry run unchanged' );
    }

    public function testRestUnchangedTitleOnlyIsANoOpAndNeedsNoApproval(): void
    {
        ApprovalMode::set( true );
        [ $status, $data ] = $this->restUpdate( [ 'title' => 'Old title' ] );
        $this->assertSame( 200, $status );
        $this->assertFalse( $data['saved'] );
        $this->assertTrue( $data['valid'] );
        $this->assertFalse( $data['layout_changed'] );
        $this->assertFalse( $data['title']['changed'] );
        $this->assertStringContainsString( 'already "Old title"', $data['message'] );
        $this->assertSame( 'Old title', $data['page']['post_title'] );
        $this->assertNothingWritten( 'rest unchanged title' );
    }

    public function testRestUnchangedTitleInACombinedCallStillSavesTheLayout(): void
    {
        [ $status, $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'title' => 'Old title' ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertFalse( $data['title']['changed'] );
        $this->assertSame( self::NEW_VALID, $this->post()->post_content );
        $this->assertSame( 'Old title', $this->post()->post_title );
        $this->assertSame( 1, $this->historyRows() );
        $this->assertSame( 'The page title was already "Old title" and was left as it is.', $data['message'] );
    }

    public function testRestDryRunWithAnUnchangedTitleInACombinedCallDoesNotClaimNothingChanges(): void
    {
        [ , $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'title' => 'Old title', 'dry_run' => true ] );
        $this->assertFalse( $data['title']['changed'] );
        $this->assertNotEmpty( $data['diff'] );
        $this->assertSame( 'Preview only. Nothing was saved.', $data['message'] );
        $this->assertNothingWritten( 'rest dry run combined, title unchanged' );
    }

    public function testRestExplicitNullContentWithATitleIsTitleOnly(): void
    {
        [ $status, $data ] = $this->restUpdate( [ 'post_content' => null, 'title' => 'New title' ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertFalse( $data['layout_changed'] );
        $this->assertSame( 'New title', $this->post()->post_title );
        $this->assertSame( self::ORIGINAL, $this->post()->post_content );
    }

    public function testRestATitleLessSaveKeepsTodaysResponseShape(): void
    {
        [ , $data ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( [ 'saved', 'valid', 'violations', 'history', 'page' ], array_keys( $data ) );
    }

    private function assertRestRefused( array $r, string $why ): void
    {
        [ $status, $data ] = $r;
        $this->assertSame( 428, $status, $why );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] ?? null, $why );
    }

    public function testRestApprovalModeRequiresAPreviewForATitleOnlySave(): void
    {
        ApprovalMode::set( true );
        $this->assertRestRefused( $this->restUpdate( [ 'title' => 'A' ] ), 'no preview' );
        $this->assertNothingWritten( 'rest refused' );
    }

    public function testRestApprovalModePreviewIsBoundToTheExactTitleAndUsedOnce(): void
    {
        ApprovalMode::set( true );
        $this->restUpdate( [ 'title' => 'A', 'dry_run' => true ] );
        $this->assertRestRefused( $this->restUpdate( [ 'title' => 'B' ] ), 'other title' );
        $this->assertNothingWritten( 'rest other title' );

        [ $status, $data ] = $this->restUpdate( [ 'title' => 'A' ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( 'A', $this->post()->post_title );

        $this->post()->post_title = 'Old title';
        $this->assertRestRefused( $this->restUpdate( [ 'title' => 'A' ] ), 'second use' );
    }

    public function testRestApprovalModeCombinedLayoutAndTitleNeedsItsOwnPreviewAndIsUsedOnce(): void
    {
        ApprovalMode::set( true );
        $body = [ 'post_content' => self::NEW_VALID, 'title' => 'A' ];
        $this->restUpdate( $body + [ 'dry_run' => true ] );
        [ $status, $data ] = $this->restUpdate( $body );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( 'A', $this->post()->post_title );
        $this->assertSame( self::NEW_VALID, $this->post()->post_content );

        $this->post()->post_title   = 'Old title';
        $this->post()->post_content = self::ORIGINAL;
        $this->assertRestRefused( $this->restUpdate( $body ), 'second use' );
    }

    public function testRestALayoutOnlyPreviewDoesNotAuthoriseLayoutPlusTitle(): void
    {
        ApprovalMode::set( true );
        $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );
        $this->assertRestRefused( $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'title' => 'A' ] ), 'layout preview, layout+title save' );
        $this->assertNothingWritten( 'rest layout preview only' );
    }

    public function testRestATitlePreviewDoesNotAuthoriseALayoutOnlySave(): void
    {
        ApprovalMode::set( true );
        $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'title' => 'A', 'dry_run' => true ] );
        $this->assertRestRefused( $this->restUpdate( [ 'post_content' => self::NEW_VALID ] ), 'title preview, layout-only save' );
        $this->assertNothingWritten( 'rest title preview only' );
    }

    public function testRestApprovalPreviewIsVoidIfTheStoredTitleChangedSincePreview(): void
    {
        ApprovalMode::set( true );
        $this->restUpdate( [ 'title' => 'A', 'dry_run' => true ] );
        $this->post()->post_title = 'Someone else changed it';
        $this->assertRestRefused( $this->restUpdate( [ 'title' => 'A' ] ), 'stored title changed' );
        $this->assertSame( 'Someone else changed it', $this->post()->post_title );
    }

    public function testRestRestoreSaysTheTitleWasLeftAlone(): void
    {
        [ , $saved ] = $this->restUpdate( [ 'post_content' => self::NEW_VALID, 'title' => 'New title' ] );
        $r = ( new RestController() )->history_restore( new \WP_REST_Request( [ 'version_id' => $saved['history']['version_id'] ], [ 'id' => 5 ] ) );
        $this->assertSame( 200, $r->get_status() );
        $data = (array) $r->get_data();
        $this->assertTrue( $data['restored'] );
        $this->assertFalse( $data['title_restored'] );
        $this->assertStringContainsString( 'New title', $data['title_note'] );
        $this->assertSame( 'New title', $this->post()->post_title );
        $this->assertSame( self::ORIGINAL, $this->post()->post_content );
    }
}
