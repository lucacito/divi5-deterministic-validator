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

/**
 * A Divi Project (post 5) is read, edited, previewed, snapshotted and restored through the REAL REST and MCP handlers exactly like
 * a page, and a blog post (post 6) is refused everywhere. Post 7 is a page, to prove pages still work.
 */
class ProjectLayoutsHandlersTest extends TestCase
{
    private const ORIGINAL = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private const NEW_VALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private const NEW_INVALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/not-a-module {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private function addPost( int $id, string $type ): void
    {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = $type;
        $post->post_title   = "Post {$id}";
        $post->post_content = self::ORIGINAL;
        $GLOBALS['__wp_posts'][ $id ]    = $post;
        $GLOBALS['__wp_postmeta'][ $id ] = [];
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']         = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_transient_ttl']   = [];
        $GLOBALS['__wp_current_user_id'] = 7;
        $GLOBALS['__wp_posts']           = [];
        $GLOBALS['__wp_postmeta']        = [];
        $GLOBALS['__wp_update_fail']     = false;
        $GLOBALS['__wp_update_calls']    = 0;
        $GLOBALS['__wp_can']             = true;
        unset( $GLOBALS['__wp_caps'] );
        $this->addPost( 5, 'project' );
        $this->addPost( 6, 'post' );
        $this->addPost( 7, 'page' );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']            = null;
        $GLOBALS['__wp_options']    = [];
        $GLOBALS['__wp_transients'] = [];
        unset( $GLOBALS['__wp_current_user_id'], $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can'] = true;
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function unwrap( mixed $r ): array
    {
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code, 'message' => $r->message ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( string $method, int $id, array $body = [], array $urlParams = [] ): array
    {
        return $this->unwrap( ( new RestController() )->$method( new \WP_REST_Request( $body, [ 'id' => $id ] + $urlParams ) ) );
    }

    /** @return array{0:?int,1:array<string,mixed>,2:bool,3:string} JSON-RPC error code, decoded tool result, isError, error message */
    private function mcp( string $tool, array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], [], true, (string) $data['error']['message'] ];
        }

        return [ null, (array) json_decode( $data['result']['content'][0]['text'], true ), (bool) ( $data['result']['isError'] ?? false ), '' ];
    }

    private function assertUnchanged( int $id, string $why ): void
    {
        $this->assertSame( self::ORIGINAL, $GLOBALS['__wp_posts'][ $id ]->post_content, "{$why}: content changed" );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], "{$why}: wp_update_post was called" );
    }

    // ---- reading ------------------------------------------------------------------------------

    public function testAProjectCanBeReadOverRestAndMcp(): void
    {
        [ $status, $data ] = $this->rest( 'get_page', 5 );
        $this->assertSame( 200, $status );
        $this->assertSame( 'project', $data['post_type'] );
        $this->assertSame( self::ORIGINAL, $data['post_content'] );

        [ $code, $result, $isError ] = $this->mcp( 'get_page_layout', [ 'page_id' => 5 ] );
        $this->assertNull( $code );
        $this->assertFalse( $isError );
        $this->assertSame( 'project', $result['post_type'] );
        $this->assertSame( self::ORIGINAL, $result['post_content'] );
    }

    public function testAPageStillWorks(): void
    {
        [ $status, $data ] = $this->rest( 'get_page', 7 );
        $this->assertSame( 200, $status );
        $this->assertSame( 'page', $data['post_type'] );
    }

    // ---- writing: save, refuse, dry run, approval mode ---------------------------------------------

    public function testAValidLayoutIsSavedToAProjectAfterASnapshot(): void
    {
        [ $status, $data ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertTrue( $data['history']['stored'], 'the previous content was snapshotted first' );
        $this->assertSame( self::NEW_VALID, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertSame( 1, $GLOBALS['__wp_update_calls'] );
    }

    public function testAnInvalidLayoutIsRefusedForAProject(): void
    {
        [ $status, $data ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_INVALID ] );
        $this->assertSame( 422, $status );
        $this->assertFalse( $data['saved'] );
        $this->assertNotEmpty( $data['violations'] );
        $this->assertUnchanged( 5, 'invalid layout' );
        $this->assertSame( [], \AiEditorDivi5\WP\HistoryService::listFor( 5 ), 'nothing was snapshotted' );
    }

    public function testDryRunLeavesAProjectUntouched(): void
    {
        [ $status, $data ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['valid'] );
        $this->assertUnchanged( 5, 'dry run' );
        $this->assertSame( [], \AiEditorDivi5\WP\HistoryService::listFor( 5 ), 'a dry run snapshots nothing' );
    }

    public function testApprovalModeAppliesToProjects(): void
    {
        ApprovalMode::set( true );
        [ $status, $data ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 428, $status );
        $this->assertSame( 'PREVIEW_REQUIRED', $data['code'] );
        $this->assertUnchanged( 5, 'no preview' );

        $this->assertSame( 200, $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID, 'dry_run' => true ] )[0] );
        [ $status ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 200, $status, 'saved after a valid preview' );
        $this->assertSame( self::NEW_VALID, $GLOBALS['__wp_posts'][5]->post_content );
    }

    public function testMcpUpdateAndSurgicalEditWorkOnAProject(): void
    {
        [ $code, , $isError ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID ] );
        $this->assertNull( $code );
        $this->assertFalse( $isError );
        $this->assertSame( self::NEW_VALID, $GLOBALS['__wp_posts'][5]->post_content );

        [ $code, , $isError ] = $this->mcp( 'edit_page_content', [ 'page_id' => 5, 'find' => '4_4', 'replace' => '1_2', 'expect_count' => 1 ] );
        $this->assertNull( $code );
        $this->assertFalse( $isError );
        $this->assertStringContainsString( '"value":"1_2"', $GLOBALS['__wp_posts'][5]->post_content );
    }

    // ---- undo ------------------------------------------------------------------------------------

    public function testRestoreBringsBackAProjectsOldContent(): void
    {
        [ , $saved ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID ] );
        $versionId   = (int) $saved['history']['version_id'];
        $this->assertGreaterThan( 0, $versionId );

        [ $status, $list ] = $this->rest( 'history_list', 5 );
        $this->assertSame( 200, $status );
        $this->assertSame( 1, $list['count'] );

        [ $status, $entry ] = $this->rest( 'history_entry', 5, [], [ 'version_id' => $versionId ] );
        $this->assertSame( 200, $status );
        $this->assertSame( self::ORIGINAL, $entry['post_content'] );

        [ $status, $data ] = $this->rest( 'history_restore', 5, [ 'version_id' => $versionId ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['restored'] );
        $this->assertSame( self::ORIGINAL, $GLOBALS['__wp_posts'][5]->post_content );
        $this->assertTrue( $data['history']['stored'], 'the restore is itself snapshotted' );

        [ $code, $mcpList ] = $this->mcp( 'list_page_history', [ 'page_id' => 5 ] );
        $this->assertNull( $code );
        $this->assertSame( 2, $mcpList['count'] );
    }

    // ---- refusals ---------------------------------------------------------------------------------

    public function testABlogPostIsNotFoundOnEveryIdTakingToolAndRoute(): void
    {
        // Seed a REAL snapshot for the blog post straight through the service (not the handlers under test), so a
        // missing version can never be the reason for a refusal: only the post-type check can explain it.
        $seed = \AiEditorDivi5\WP\HistoryService::write( 6, self::NEW_VALID, 'seed' );
        $this->assertTrue( $seed['snapshot']['stored'], 'the seed snapshot was stored' );
        $vid = (int) $seed['snapshot']['version_id'];
        $this->assertGreaterThan( 0, $vid );
        $GLOBALS['__wp_posts'][6]->post_content = self::ORIGINAL;
        $GLOBALS['__wp_update_calls']           = 0;
        $seeded                                 = \AiEditorDivi5\WP\HistoryService::listFor( 6 );
        $this->assertCount( 1, $seeded );
        $this->assertNotNull( \AiEditorDivi5\WP\HistoryService::entry( 6, $vid ), 'the seeded version really exists' );

        // [ method, JSON body, URL params ]: history_entry reads version_id from the URL, history_restore from the body.
        $restCalls = [
            [ 'get_page', [], [] ],
            [ 'update_page', [ 'post_content' => self::NEW_VALID ], [] ],
            [ 'edit_page', [ 'find' => '4_4', 'replace' => '1_2' ], [] ],
            [ 'history_list', [], [] ],
            [ 'history_entry', [], [ 'version_id' => $vid ] ],
            [ 'history_restore', [ 'version_id' => $vid ], [] ],
        ];
        foreach ( $restCalls as [ $method, $body, $urlParams ] ) {
            [ $status, $data ] = $this->rest( $method, 6, $body, $urlParams );
            $this->assertSame( 404, $status, "REST {$method} on a blog post" );
            $this->assertSame( 'not_found', $data['error'] );
        }
        $mcpCalls = [
            [ 'get_page_layout', [ 'page_id' => 6 ] ],
            [ 'update_page_layout', [ 'page_id' => 6, 'post_content' => self::NEW_VALID ] ],
            [ 'edit_page_content', [ 'page_id' => 6, 'find' => '4_4', 'replace' => '1_2' ] ],
            [ 'list_page_history', [ 'page_id' => 6 ] ],
            [ 'get_page_history_entry', [ 'page_id' => 6, 'version_id' => $vid ] ],
            [ 'restore_page_version', [ 'page_id' => 6, 'version_id' => $vid ] ],
        ];
        foreach ( $mcpCalls as [ $tool, $args ] ) {
            [ $code ] = $this->mcp( $tool, $args );
            $this->assertSame( -32602, $code, "MCP {$tool} on a blog post" );
        }
        $this->assertUnchanged( 6, 'a blog post' );
        $this->assertSame( $seeded, \AiEditorDivi5\WP\HistoryService::listFor( 6 ), 'nothing new was snapshotted, nothing restored' );
    }

    public function testAProjectTheUserCannotEditIsForbidden(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'edit_post' => true, 'edit_post:5' => false ];
        [ $status, $data ] = $this->rest( 'update_page', 5, [ 'post_content' => self::NEW_VALID ] );
        $this->assertSame( 403, $status );
        $this->assertSame( 'forbidden', $data['error'] );
        [ $code, , , $message ] = $this->mcp( 'update_page_layout', [ 'page_id' => 5, 'post_content' => self::NEW_VALID ] );
        $this->assertSame( -32602, $code );
        $this->assertStringContainsString( 'permission', $message );
        $this->assertUnchanged( 5, 'no edit rights' );
    }
}
