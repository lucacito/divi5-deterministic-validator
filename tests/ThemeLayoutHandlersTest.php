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
require_once __DIR__ . '/DiviThemeBuilderShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/ThemeLayouts.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/**
 * Header/footer layouts through the REAL REST and MCP handlers: a live layout works exactly like a
 * page (read, preview, edit, history, restore) and every other post is "not found".
 */
class ThemeLayoutHandlersTest extends TestCase
{
    private const HEADER = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/shop {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    private const SHOP   = '<!-- wp:divi/shop {"builderVersion":"5.14.0"} /-->';
    private const INVALID = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.14.0"} --><!-- wp:divi/row {"builderVersion":"5.14.0"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.14.0"} --><!-- wp:divi/not-a-module {"builderVersion":"5.14.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    private function post( int $id, string $type, string $status = 'publish', array $meta = [], string $content = '' ): void
    {
        $p               = new \WP_Post();
        $p->ID           = $id;
        $p->post_type    = $type;
        $p->post_status  = $status;
        $p->post_title   = "Post {$id}";
        $p->post_content = $content;
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']        = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_posts']          = [];
        $GLOBALS['__wp_postmeta']       = [];
        $GLOBALS['__wp_update_fail']    = false;
        $GLOBALS['__wp_update_calls']   = 0;
        $GLOBALS['__divi_cache_clears'] = [];
        $GLOBALS['__divi_tb']           = [ 'tb_id' => 100, 'draft_id' => 300, 'template_ids' => [ 200 ], 'draft_template_ids' => [ 301 ] ];
        $GLOBALS['__wp_caps']           = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true ];

        $this->post( 100, 'et_theme_builder' );
        $this->post( 200, 'et_template', 'publish', [ '_et_header_layout_id' => 201, '_et_footer_layout_id' => 202, '_et_body_layout_id' => 203 ] );
        $this->post( 201, 'et_header_layout', 'publish', [], self::HEADER );
        $this->post( 202, 'et_footer_layout', 'publish', [], self::HEADER );
        $this->post( 203, 'et_body_layout', 'publish', [], self::HEADER );
        $this->post( 204, 'et_header_layout', 'publish', [], self::HEADER );   // orphan
        $this->post( 300, 'et_theme_builder', 'auto-draft' );
        $this->post( 301, 'et_template', 'publish', [ '_et_header_layout_id' => 302 ] );
        $this->post( 302, 'et_header_layout', 'publish', [], self::HEADER );   // draft-Theme-Builder only
        $this->post( 5, 'page', 'publish', [], self::HEADER );
        $this->post( 6, 'post', 'publish', [], self::HEADER );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']      = null;
        $GLOBALS['__wp_options'] = [];
        unset( $GLOBALS['__wp_caps'] );
        $GLOBALS['__wp_can']  = true;
        $GLOBALS['__divi_tb'] = [];
    }

    // ---- helpers ------------------------------------------------------------------------------

    /** @return array{0:int,1:array<string,mixed>} */
    private function rest( string $method, int $id, array $body = [], array $extra = [] ): array
    {
        $req = new \WP_REST_Request( $body, [ 'id' => $id ] + $extra );
        $r   = ( new RestController() )->$method( $req );
        if ( $r instanceof \WP_Error ) {
            return [ (int) ( $r->data['status'] ?? 0 ), [ 'error' => $r->code ] ];
        }

        return [ $r->get_status(), (array) $r->get_data() ];
    }

    /** @return array{0:?int,1:string,2:array<string,mixed>} JSON-RPC error code, error message, decoded result */
    private function mcp( string $tool, array $args ): array
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => $tool, 'arguments' => $args ] ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();
        if ( isset( $data['error'] ) ) {
            return [ $data['error']['code'], (string) $data['error']['message'], [] ];
        }

        return [ null, '', (array) json_decode( $data['result']['content'][0]['text'], true ) ];
    }

    private function content( int $id ): string
    {
        return $GLOBALS['__wp_posts'][ $id ]->post_content;
    }

    private function historyRows( int $id ): int
    {
        return count( HistoryStore::summaries( $id ) );
    }

    // ---- a live header/footer works like a page -----------------------------------------------

    public function testReadingALiveHeaderThroughRestAndMcp(): void
    {
        [ $status, $data ] = $this->rest( 'get_page', 201 );
        $this->assertSame( 200, $status );
        $this->assertSame( 201, $data['post_id'] );
        $this->assertSame( 'et_header_layout', $data['post_type'] );
        $this->assertSame( self::HEADER, $data['post_content'] );

        [ $code, , $result ] = $this->mcp( 'get_page_layout', [ 'page_id' => 202 ] );
        $this->assertNull( $code );
        $this->assertSame( 'et_footer_layout', $result['post_type'] );
        $this->assertSame( self::HEADER, $result['post_content'] );
    }

    public function testAPageEnvelopeKeepsItsShapeAndGainsPostType(): void
    {
        [ , $data ] = $this->rest( 'get_page', 5 );
        $this->assertSame( 'page', $data['post_type'] );
        $this->assertSame( 5, $data['post_id'] );
    }

    public function testEditingALiveHeaderSavesSnapshotsAndClearsCaches(): void
    {
        $new = str_replace( self::SHOP, self::SHOP . self::SHOP, self::HEADER );
        [ $status, $data ] = $this->rest( 'edit_page', 201, [ 'find' => self::SHOP, 'replace' => self::SHOP . self::SHOP ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertTrue( $data['history']['stored'] );
        $this->assertSame( $new, $this->content( 201 ) );
        $this->assertSame( 1, $this->historyRows( 201 ) );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'] );

        [ $code, , $result ] = $this->mcp( 'edit_page_content', [ 'page_id' => 202, 'find' => self::SHOP, 'replace' => self::SHOP . self::SHOP ] );
        $this->assertNull( $code );
        $this->assertTrue( $result['saved'] );
        $this->assertCount( 2, $GLOBALS['__divi_cache_clears'] );
    }

    public function testWholeLayoutUpdateStillGoesThroughTheValidatorGate(): void
    {
        [ $status, $data ] = $this->rest( 'update_page', 201, [ 'post_content' => self::INVALID ] );
        $this->assertSame( 422, $status );
        $this->assertFalse( $data['valid'] );
        $this->assertSame( self::HEADER, $this->content( 201 ), 'an invalid layout never reaches a header' );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );

        [ $code, , $result ] = $this->mcp( 'update_page_layout', [ 'page_id' => 201, 'post_content' => self::INVALID ] );
        $this->assertNull( $code );
        $this->assertFalse( $result['saved'] );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testDryRunOnALayoutWritesNothingAndClearsNoCaches(): void
    {
        [ $status, $data ] = $this->rest( 'edit_page', 201, [ 'find' => self::SHOP, 'replace' => self::SHOP . self::SHOP, 'dry_run' => true ] );
        $this->assertSame( 200, $status );
        $this->assertFalse( $data['saved'] );
        $this->assertTrue( $data['dry_run'] );
        [ , , $result ] = $this->mcp( 'update_page_layout', [ 'page_id' => 202, 'post_content' => self::INVALID, 'dry_run' => true ] );
        $this->assertFalse( $result['saved'] );
        $this->assertSame( self::HEADER, $this->content( 201 ) );
        $this->assertSame( self::HEADER, $this->content( 202 ) );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
        $this->assertSame( 0, $this->historyRows( 201 ) + $this->historyRows( 202 ) );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'] );
    }

    public function testHistoryAndRestoreWorkForALayout(): void
    {
        $this->rest( 'edit_page', 201, [ 'find' => self::SHOP, 'replace' => self::SHOP . self::SHOP ] );

        [ $status, $list ] = $this->rest( 'history_list', 201 );
        $this->assertSame( 200, $status );
        $this->assertSame( 1, $list['count'] );
        $vid = (int) $list['versions'][0]['id'];

        [ $status, $entry ] = $this->rest( 'history_entry', 201, [], [ 'version_id' => $vid ] );
        $this->assertSame( 200, $status );
        $this->assertSame( self::HEADER, $entry['post_content'] );

        $GLOBALS['__divi_cache_clears'] = [];
        [ $status, $restored ] = $this->rest( 'history_restore', 201, [ 'version_id' => $vid ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $restored['restored'] );
        $this->assertSame( self::HEADER, $this->content( 201 ) );
        $this->assertSame( [ 'all' ], $GLOBALS['__divi_cache_clears'] );

        [ $code, , $m ] = $this->mcp( 'list_page_history', [ 'page_id' => 201 ] );
        $this->assertNull( $code );
        $this->assertGreaterThanOrEqual( 1, $m['count'] );
    }

    // ---- everything else is "not found" ---------------------------------------------------------

    /** @return list<array{int,string}> */
    public static function notFoundIds(): array
    {
        return [
            [ 203, 'a body layout' ],
            [ 204, 'an orphaned header' ],
            [ 302, 'a header of the draft Theme Builder only' ],
            [ 200, 'a live template' ],
            [ 100, 'the Theme Builder post' ],
            [ 6, 'an ordinary post' ],
            [ 9999, 'a post that does not exist' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'notFoundIds' )]
    public function testEveryOtherPostIsNotFoundOnEveryRestEntryPoint( int $id, string $why ): void
    {
        $this->assertSame( 404, $this->rest( 'get_page', $id )[0], "get_page: {$why}" );
        $this->assertSame( 404, $this->rest( 'update_page', $id, [ 'post_content' => self::HEADER ] )[0], "update_page: {$why}" );
        $this->assertSame( 404, $this->rest( 'edit_page', $id, [ 'find' => 'a', 'replace' => 'b' ] )[0], "edit_page: {$why}" );
        $this->assertSame( 404, $this->rest( 'history_list', $id )[0], "history_list: {$why}" );
        $this->assertSame( 404, $this->rest( 'history_restore', $id, [ 'version_id' => 1 ] )[0], "history_restore: {$why}" );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], $why );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'notFoundIds' )]
    public function testEveryOtherPostIsNotFoundOnEveryMcpTool( int $id, string $why ): void
    {
        foreach ( [
            [ 'get_page_layout', [ 'page_id' => $id ] ],
            [ 'update_page_layout', [ 'page_id' => $id, 'post_content' => self::HEADER ] ],
            [ 'edit_page_content', [ 'page_id' => $id, 'find' => 'a', 'replace' => 'b' ] ],
            [ 'list_page_history', [ 'page_id' => $id ] ],
            [ 'get_page_history_entry', [ 'page_id' => $id, 'version_id' => 1 ] ],
            [ 'restore_page_version', [ 'page_id' => $id, 'version_id' => 1 ] ],
        ] as [ $tool, $args ] ) {
            [ $code, $message ] = $this->mcp( $tool, $args );
            $this->assertSame( -32602, $code, "{$tool}: {$why}" );
            $this->assertSame( "Page {$id} not found.", $message, "{$tool}: {$why}" );
        }
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'], $why );
    }

    public function testWithoutEditOthersPostsALayoutIsForbiddenButAPageIsStillFine(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_pages' => true ]; // no edit_others_posts
        $this->assertSame( 403, $this->rest( 'get_page', 201 )[0] );
        $this->assertSame( 403, $this->rest( 'edit_page', 201, [ 'find' => 'a', 'replace' => 'b' ] )[0] );
        [ $code, $message ] = $this->mcp( 'edit_page_content', [ 'page_id' => 201, 'find' => 'a', 'replace' => 'b' ] );
        $this->assertSame( -32602, $code );
        $this->assertStringContainsString( 'permission', $message );
        $this->assertSame( 200, $this->rest( 'get_page', 5 )[0], 'pages never needed edit_others_posts' );
        $this->assertSame( 0, $GLOBALS['__wp_update_calls'] );
    }

    public function testPageBehaviourIsUnchanged(): void
    {
        [ $status, $data ] = $this->rest( 'edit_page', 5, [ 'find' => self::SHOP, 'replace' => self::SHOP . self::SHOP ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( $data['saved'] );
        $this->assertSame( [], $GLOBALS['__divi_cache_clears'], 'a page save leaves Divi caches alone' );
        $this->assertSame( 404, $this->rest( 'get_page', 6 )[0], 'an ordinary post is still not a page' );
        [ $code, $message ] = $this->mcp( 'get_page_layout', [ 'page_id' => 9999 ] );
        $this->assertSame( [ -32602, 'Page 9999 not found.' ], [ $code, $message ] );
    }
}
