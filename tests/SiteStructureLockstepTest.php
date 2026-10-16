<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
use AiEditorDivi5\WP\RestController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/SiteStructureShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryService.php';
require_once __DIR__ . '/../wp-plugin/src/PageEditor.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutDiff.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/../wp-plugin/src/DryRun.php';
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFields.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFieldReader.php';
require_once __DIR__ . '/../wp-plugin/src/SiteStructure.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** get_site_structure: same meaning in MCP and REST, read-only, and deliberately NOT in the ChatGPT OpenAPI spec. */
class SiteStructureLockstepTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_posts']       = [];
        $GLOBALS['__wp_postmeta']    = [];
        $GLOBALS['__wp_caps']        = [ 'edit_pages' => true ];
        $GLOBALS['__ss_post_types']  = [ 'book' => (object) [ 'label' => 'Books', 'has_archive' => true, 'hierarchical' => false ] ];
        $GLOBALS['__ss_image_sizes'] = [ 'card' => [ 'width' => 400, 'height' => 300, 'crop' => false ] ];
        $GLOBALS['__ss_acf_groups']  = [ [ 'key' => 'group_1', 'title' => 'Book' ] ];
        $GLOBALS['__ss_acf_fields']  = [ 'group_1' => [ [ 'name' => 'isbn', 'label' => 'ISBN', 'type' => 'text' ] ] ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__ss_post_types'], $GLOBALS['__ss_image_sizes'], $GLOBALS['__ss_acf_groups'], $GLOBALS['__ss_acf_fields'] );
        $GLOBALS['__wp_can'] = true;
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    /** @return array<string,mixed> the JSON-RPC response */
    private function mcpCall( array $args = [] ): array
    {
        $req = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'get_site_structure', 'arguments' => $args ?: new \stdClass() ] ] );

        return (array) ( new McpHandler() )->handle( $req )->get_data();
    }

    public function testMcpDeclaresAndDispatchesTheTool(): void
    {
        $mcp = $this->src( 'McpHandler.php' );
        $this->assertStringContainsString( "'name'        => 'get_site_structure'", $mcp );
        $this->assertMatchesRegularExpression( "/'get_site_structure'\s*=>\s*\\\$this->toolSiteStructure/", $mcp );
    }

    public function testRestRegistersTheRouteAsReadOnly(): void
    {
        $rest = $this->src( 'RestController.php' );
        $this->assertMatchesRegularExpression( "/'\/site-structure',\s*\[\s*'methods'\s*=>\s*WP_REST_Server::READABLE/", $rest );
    }

    public function testItIsNotInTheChatGptSpecOnPurpose(): void
    {
        // The importer allows 30 operations and the free (16) plus Pro (14) tools already use all 30 (owner decision 2026-10-04).
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $this->assertArrayNotHasKey( '/site-structure', $spec['paths'] );
        $this->assertStringNotContainsString( 'site-structure', json_encode( $spec ) );
    }

    public function testRestAndMcpReturnTheSameStructure(): void
    {
        $rest = ( new RestController() )->site_structure( new \WP_REST_Request() );
        $this->assertSame( 200, $rest->get_status() );
        $d = (array) $rest->get_data();
        $this->assertSame( [ 'book' ], array_column( $d['post_types'], 'name' ) );
        $this->assertSame( 'isbn', $d['acf']['groups'][0]['fields'][0]['name'] );

        $ok = $this->mcpCall();
        $this->assertArrayNotHasKey( 'error', $ok );
        $this->assertSame( $d, json_decode( $ok['result']['content'][0]['text'], true ) );
    }

    public function testIncludeWorksOnBothSurfaces(): void
    {
        $rest = ( new RestController() )->site_structure( new \WP_REST_Request( [], [ 'include' => 'image_sizes, acf' ] ) );
        $this->assertSame( [ 'image_sizes', 'acf', 'truncated', 'notes' ], array_keys( (array) $rest->get_data() ) );

        $mcp = json_decode( $this->mcpCall( [ 'include' => [ 'image_sizes' ] ] )['result']['content'][0]['text'], true );
        $this->assertSame( [ 'image_sizes', 'truncated', 'notes' ], array_keys( $mcp ) );
    }

    public function testABadIncludeIsRejectedNeverCast(): void
    {
        foreach ( [ 'nope', [ 'nope' ], 7, true ] as $bad ) {
            $r = ( new RestController() )->site_structure( new \WP_REST_Request( [], [ 'include' => $bad ] ) );
            $this->assertInstanceOf( \WP_Error::class, $r );
            $this->assertSame( 400, $r->data['status'] );
            $e = $this->mcpCall( [ 'include' => $bad ] );
            $this->assertSame( -32602, $e['error']['code'] );
        }
    }

    public function testBothSurfacesRefuseAUserWhoCannotEditPages(): void
    {
        $GLOBALS['__wp_caps'] = [];
        $denied = $this->mcpCall();
        $this->assertSame( -32602, $denied['error']['code'] );
        $this->assertStringContainsString( 'permission', $denied['error']['message'] );
        $r = ( new RestController() )->site_structure( new \WP_REST_Request() );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 403, $r->data['status'] );
    }

    public function testTheToolWritesNothing(): void
    {
        $before = [ $GLOBALS['__wp_options'], $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        ( new RestController() )->site_structure( new \WP_REST_Request() );
        $this->mcpCall();
        $this->assertSame( $before, [ $GLOBALS['__wp_options'], $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ] );
    }

    public function testToolsListDeclaresAGeminiSafeSchema(): void
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();
        $tool = null;
        foreach ( $data['result']['tools'] as $t ) {
            if ( 'get_site_structure' === $t['name'] ) {
                $tool = $t;
            }
        }
        $this->assertNotNull( $tool, 'get_site_structure is listed' );
        $include = $tool['inputSchema']['properties']['include'];
        $this->assertSame( 'array', $include['type'] );
        $this->assertSame( 'string', $include['items']['type'] );
        $this->assertSame( [ 'post_types', 'taxonomies', 'image_sizes', 'acf', 'custom_fields', 'components' ], $include['items']['enum'] );
        $json = (string) json_encode( $tool['inputSchema'] );
        foreach ( [ 'additionalProperties', '$ref', 'oneOf' ] as $banned ) {
            $this->assertStringNotContainsString( $banned, $json );
        }
    }

    public function testTheStyleGuideTellsTheAiToCallIt(): void
    {
        $this->assertStringContainsString( '`get_site_structure`', $this->src( 'StyleGuide.php' ) );
    }
}
