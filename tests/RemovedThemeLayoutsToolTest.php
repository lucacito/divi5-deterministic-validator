<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

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
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/**
 * Header/footer editing moved to the Pro add-on: a client that still calls the old tool gets the normal
 * unknown-tool error, and a layout id is just another post id the page tools do not know.
 */
class RemovedThemeLayoutsToolTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_options']  = [ HistoryTable::DB_VERSION_OPTION => HistoryTable::STAGE_MIGRATED ];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__wp_can']      = true;
        $GLOBALS['__wp_posts']    = [];
        foreach ( [ 5 => 'page', 6 => 'post', 201 => 'et_header_layout', 202 => 'et_footer_layout' ] as $id => $type ) {
            $p               = new \WP_Post();
            $p->ID           = $id;
            $p->post_type    = $type;
            $p->post_status  = 'publish';
            $p->post_title   = "Post {$id}";
            $p->post_content = 'CONTENT';
            $GLOBALS['__wp_posts'][ $id ] = $p;
        }
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']         = null;
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_can']     = true;
    }

    /** @return array<string,mixed> */
    private function mcp( string $method, array $params = [] ): array
    {
        $req = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params ] );

        return (array) ( new McpHandler() )->handle( $req )->get_data();
    }

    public function testToolsListDoesNotAdvertiseTheRemovedTool(): void
    {
        $names = array_column( $this->mcp( 'tools/list' )['result']['tools'], 'name' );
        $this->assertNotContains( 'list_theme_layouts', $names );
        $this->assertContains( 'get_page_layout', $names, 'the page tools are still there' );
    }

    public function testCallingTheRemovedToolIsAnUnknownToolError(): void
    {
        $data = $this->mcp( 'tools/call', [ 'name' => 'list_theme_layouts', 'arguments' => [] ] );
        $this->assertSame( -32602, $data['error']['code'] );
        $this->assertStringContainsString( 'Unknown tool: list_theme_layouts', $data['error']['message'] );
    }

    public function testALayoutIdIsNotFoundForTheMcpPageTools(): void
    {
        $calls = [
            'get_page_layout'   => [ 'page_id' => 201 ],
            'edit_page_content' => [ 'page_id' => 201, 'find' => 'a', 'replace' => 'b' ],
            'list_page_history' => [ 'page_id' => 202 ],
        ];
        foreach ( $calls as $tool => $args ) {
            $data = $this->mcp( 'tools/call', [ 'name' => $tool, 'arguments' => $args ] );
            $this->assertSame( -32602, $data['error']['code'], $tool );
            $this->assertSame( 'Page ' . $args['page_id'] . ' not found.', $data['error']['message'], $tool );
        }
    }

    public function testALayoutIdIsNotFoundForTheRestPageTools(): void
    {
        $r = ( new RestController() )->get_page( new \WP_REST_Request( [], [ 'id' => 201 ] ) );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 'not_found', $r->code );
        $this->assertSame( 404, $r->data['status'] );
    }

    public function testAPageStillReadsThroughRestAndMcp(): void
    {
        $r = ( new RestController() )->get_page( new \WP_REST_Request( [], [ 'id' => 5 ] ) );
        $this->assertSame( 200, $r->get_status() );
        $this->assertSame( 5, $r->get_data()['post_id'] );
    }
}
