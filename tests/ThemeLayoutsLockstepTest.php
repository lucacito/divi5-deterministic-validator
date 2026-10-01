<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
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
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** list_theme_layouts must exist, with the same meaning, in MCP, REST and OpenAPI, and be read-only. */
class ThemeLayoutsLockstepTest extends TestCase
{
    private function post( int $id, string $type, array $meta = [] ): void
    {
        $p              = new \WP_Post();
        $p->ID          = $id;
        $p->post_type   = $type;
        $p->post_status = 'publish';
        $p->post_title  = "Post {$id}";
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_posts']    = [];
        $GLOBALS['__wp_postmeta'] = [];
        $GLOBALS['__divi_tb']     = [ 'tb_id' => 100, 'draft_id' => 0, 'template_ids' => [ 200 ], 'draft_template_ids' => [] ];
        $GLOBALS['__divi_tb_calls'] = [];
        unset( $GLOBALS['__divi_role_allowed'] );
        $GLOBALS['__wp_caps']     = [ 'edit_post' => true, 'edit_others_posts' => true, 'edit_pages' => true ];
        $this->post( 100, 'et_theme_builder' );
        $this->post( 200, 'et_template', [ '_et_default' => '1', '_et_enabled' => '1', '_et_use_on' => [], '_et_header_layout_id' => 201, '_et_header_layout_enabled' => '1', '_et_body_layout_id' => 203, '_et_body_layout_enabled' => '1' ] );
        $this->post( 201, 'et_header_layout' );
        $this->post( 203, 'et_body_layout' );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_role_allowed'] );
        $GLOBALS['__wp_can']  = true;
        $GLOBALS['__divi_tb'] = [];
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    public function testMcpDeclaresAndDispatchesTheTool(): void
    {
        $mcp = $this->src( 'McpHandler.php' );
        $this->assertStringContainsString( "'name'        => 'list_theme_layouts'", $mcp );
        $this->assertMatchesRegularExpression( "/'list_theme_layouts'\s*=>\s*\\\$this->toolListThemeLayouts/", $mcp );
    }

    public function testRestRegistersTheRouteAsReadOnly(): void
    {
        $rest = $this->src( 'RestController.php' );
        $this->assertStringContainsString( "'/theme-layouts'", $rest );
        $this->assertMatchesRegularExpression( "/'\/theme-layouts',\s*\[\s*'methods'\s*=>\s*WP_REST_Server::READABLE/", $rest );
    }

    public function testOpenApiDescribesTheOperationAndItsSchemas(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $op   = $spec['paths']['/theme-layouts']['get'] ?? null;
        $this->assertIsArray( $op );
        $this->assertSame( 'listThemeLayouts', $op['operationId'] );
        $this->assertLessThanOrEqual( 300, strlen( (string) $op['description'] ) );
        $schema = $op['responses']['200']['content']['application/json']['schema'];
        $this->assertSame( '#/components/schemas/ThemeTemplate', $schema['properties']['templates']['items']['$ref'] );
        $this->assertSame( 'integer', $schema['properties']['count']['type'] );
        $this->assertSame( [ 'string', 'null' ], $schema['properties']['note']['type'] );
        foreach ( [ 'ThemeTemplate', 'ThemeLayoutRef' ] as $name ) {
            $this->assertArrayHasKey( $name, $spec['components']['schemas'] );
        }
        $this->assertSame( '#/components/schemas/ThemeLayoutRef', $spec['components']['schemas']['ThemeTemplate']['properties']['header']['$ref'] );
        $this->assertSame( 'boolean', $spec['components']['schemas']['ThemeLayoutRef']['properties']['editable']['type'] );
    }

    public function testThereAreNowSixteenOperations(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $ops  = [];
        foreach ( $spec['paths'] as $methods ) {
            foreach ( $methods as $op ) {
                $ops[] = $op['operationId'] ?? '';
            }
        }
        $this->assertCount( 16, $ops );
        $this->assertContains( 'listThemeLayouts', $ops );
    }

    public function testRestListingReturnsTheTemplatesAndNeverCreatesAThemeBuilderPost(): void
    {
        $r = ( new RestController() )->theme_layouts( new \WP_REST_Request() );
        $this->assertSame( 200, $r->get_status() );
        $d = (array) $r->get_data();
        $this->assertSame( 1, $d['count'] );
        $this->assertSame( 200, $d['templates'][0]['template_id'] );
        $this->assertSame( [ 'layout_id' => 201, 'title' => 'Post 201', 'enabled' => true, 'editable' => true ], $d['templates'][0]['header'] );
        $this->assertFalse( $d['templates'][0]['body']['editable'] );
        $this->assertArrayNotHasKey( 'footer', $d['templates'][0] );
        foreach ( $GLOBALS['__divi_tb_calls'] as $call ) {
            if ( 'post_id' === $call[0] ) {
                $this->assertFalse( $call[2] );
            }
        }
    }

    private function mcpCall(): array
    {
        $req = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'list_theme_layouts', 'arguments' => new \stdClass() ] ] );

        return (array) ( new McpHandler() )->handle( $req )->get_data();
    }

    public function testMcpListingMatchesRestAndRefusesWithoutEditOthersPosts(): void
    {
        $ok = $this->mcpCall();
        $this->assertArrayNotHasKey( 'error', $ok );
        $decoded = json_decode( $ok['result']['content'][0]['text'], true );
        $rest    = (array) ( new RestController() )->theme_layouts( new \WP_REST_Request() )->get_data();
        $this->assertSame( $rest, $decoded );

        $GLOBALS['__wp_caps'] = [ 'edit_post' => true, 'edit_pages' => true ];
        $denied = $this->mcpCall();
        $this->assertSame( -32602, $denied['error']['code'] );
        $this->assertStringContainsString( 'permission', $denied['error']['message'] );

        $r = ( new RestController() )->theme_layouts( new \WP_REST_Request() );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 403, $r->data['status'] );
    }

    public function testBothSurfacesAlsoRefuseWhenDiviRoleEditorDeniesTheThemeBuilder(): void
    {
        // edit_others_posts is granted (setUp); only Divi's Role Editor permission is withdrawn.
        $GLOBALS['__divi_role_allowed'] = false;

        $denied = $this->mcpCall();
        $this->assertSame( -32602, $denied['error']['code'] );
        $this->assertStringContainsString( 'permission', $denied['error']['message'] );

        $r = ( new RestController() )->theme_layouts( new \WP_REST_Request() );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 403, $r->data['status'] );
    }

    public function testTheListingToolWritesNothing(): void
    {
        $before = [ $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        ( new RestController() )->theme_layouts( new \WP_REST_Request() );
        $this->assertSame( $before[1], $GLOBALS['__wp_postmeta'] );
        $this->assertCount( count( $before[0] ), $GLOBALS['__wp_posts'] );
    }
}
