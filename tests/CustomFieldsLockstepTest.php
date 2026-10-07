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
require_once __DIR__ . '/CustomFieldsReadShims.php';
require_once __DIR__ . '/../wp-plugin/src/UsageTracker.php';
require_once __DIR__ . '/../wp-plugin/src/PageHistory.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryTable.php';
require_once __DIR__ . '/../wp-plugin/src/HistoryStore.php';
require_once __DIR__ . '/../wp-plugin/src/EditTarget.php';
require_once __DIR__ . '/../wp-plugin/src/FieldTarget.php';
require_once __DIR__ . '/../wp-plugin/src/CustomFieldReader.php';
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
require_once __DIR__ . '/../wp-plugin/src/SiteStructure.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** get_custom_fields: same meaning in MCP and REST, read-only, and deliberately NOT in the ChatGPT OpenAPI spec. */
class CustomFieldsLockstepTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__cf_protected']   = [];
        $GLOBALS['__cf_types']       = [ 'page' => true, 'project' => true, 'et_header_layout' => false ];
        $GLOBALS['__ss_acf_groups']  = [ [ 'key' => 'group_1', 'title' => 'Book' ] ];
        $GLOBALS['__ss_acf_fields']  = [ 'group_1' => [ [ 'name' => 'headline', 'label' => 'Headline', 'type' => 'text' ] ] ];
        $GLOBALS['__wp_caps']        = [ 'edit_pages' => true, 'edit_post' => true ];
        $GLOBALS['__wp_posts']       = [];
        $GLOBALS['__wp_postmeta']    = [];
        $this->post( 5, 'page', [ 'headline' => 'Hello', 'subtitle' => 'Sub', '_edit_lock' => '1:2', 'api_key' => 'SECRET-VALUE' ] );
        $this->post( 6, 'project', [ 'project_url' => 'https://example.test/p' ] );
        $this->post( 7, 'et_header_layout', [ 'anything' => 'x' ] );
        $this->post( 8, 'page', [] );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__cf_types'], $GLOBALS['__cf_protected'], $GLOBALS['__ss_acf_groups'], $GLOBALS['__ss_acf_fields'] );
        $GLOBALS['__wp_can'] = true;
    }

    private function post( int $id, string $type, array $meta ): void
    {
        $p            = new \WP_Post();
        $p->ID        = $id;
        $p->post_type = $type;
        $GLOBALS['__wp_posts'][ $id ]    = $p;
        $GLOBALS['__wp_postmeta'][ $id ] = $meta;
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    /** @return array<string,mixed> the JSON-RPC response */
    private function mcpCall( array $args ): array
    {
        $req = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'get_custom_fields', 'arguments' => $args ?: new \stdClass() ] ] );

        return (array) ( new McpHandler() )->handle( $req )->get_data();
    }

    private function rest( array $params )
    {
        return ( new RestController() )->custom_fields( new \WP_REST_Request( [], $params ) );
    }

    public function testMcpDeclaresAndDispatchesTheTool(): void
    {
        $mcp = $this->src( 'McpHandler.php' );
        $this->assertStringContainsString( "'name'        => 'get_custom_fields'", $mcp );
        $this->assertMatchesRegularExpression( "/'get_custom_fields'\s*=>\s*\\\$this->toolGetCustomFields/", $mcp );
    }

    public function testRestRegistersTheRouteAsReadOnly(): void
    {
        $this->assertMatchesRegularExpression( '#/posts/\(\?P<id>\\\\d\+\)/custom-fields\',\s*\[\s*\'methods\'\s*=>\s*WP_REST_Server::READABLE#', $this->src( 'RestController.php' ) );
    }

    public function testItIsNotInTheChatGptSpecOnPurpose(): void
    {
        // The importer allows 30 operations and the free (16) plus Pro (14) tools already use all 30 (owner decision 2026-10-04).
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $this->assertStringNotContainsString( 'custom-fields', json_encode( $spec ) );
        $this->assertStringNotContainsString( 'get_custom_fields', json_encode( $spec ) );
    }

    public function testRestAndMcpReturnTheSameAnswerForAPageAndAProject(): void
    {
        foreach ( [ 5, 6 ] as $id ) {
            $rest = $this->rest( [ 'id' => (string) $id ] );
            $this->assertSame( 200, $rest->get_status() );
            $mcp = $this->mcpCall( [ 'id' => $id ] );
            $this->assertArrayNotHasKey( 'error', $mcp );
            $this->assertSame( (array) $rest->get_data(), json_decode( $mcp['result']['content'][0]['text'], true ) );
        }
        $page = (array) $this->rest( [ 'id' => '5' ] )->get_data();
        $this->assertSame( 'page', $page['post_type'] );
        $this->assertSame( [ 'headline', 'subtitle' ], array_column( $page['fields'], 'key' ) );
        $this->assertSame( [ 'label' => 'Headline', 'type' => 'text' ], $page['fields'][0]['acf'] );
        $this->assertSame( 1, $page['withheld'] );
        $this->assertStringNotContainsString( 'SECRET-VALUE', json_encode( $page ) );
        $this->assertSame( 'project', ( (array) $this->rest( [ 'id' => '6' ] )->get_data() )['post_type'] );
    }

    public function testAPostWithNoFieldsIsANormalAnswer(): void
    {
        $d = (array) $this->rest( [ 'id' => '8' ] )->get_data();
        $this->assertSame( [], $d['fields'] );
        $this->assertSame( 0, $d['withheld'] );
    }

    public function testTheKeysFilterWorksOnBothSurfaces(): void
    {
        $rest = (array) $this->rest( [ 'id' => '5', 'keys' => 'subtitle, api_key, _edit_lock' ] )->get_data();
        $this->assertSame( [ 'subtitle' ], array_column( $rest['fields'], 'key' ) );
        $mcp = json_decode( $this->mcpCall( [ 'id' => 5, 'keys' => [ 'subtitle', 'api_key' ] ] )['result']['content'][0]['text'], true );
        $this->assertSame( [ 'subtitle' ], array_column( $mcp['fields'], 'key' ) );
        $this->assertSame( 1, $mcp['withheld'] );
    }

    public function testBadInputIsRejectedNeverCast(): void
    {
        foreach ( [ [ 'id' => '5', 'keys' => 7 ], [ 'id' => '5', 'keys' => [ 1 ] ], [ 'id' => '5', 'keys' => true ] ] as $bad ) {
            $r = $this->rest( $bad );
            $this->assertInstanceOf( \WP_Error::class, $r );
            $this->assertSame( 400, $r->data['status'] );
            $e = $this->mcpCall( [ 'id' => 5, 'keys' => $bad['keys'] ] );
            $this->assertSame( -32602, $e['error']['code'] );
        }
        foreach ( [ '5', 5.5, true, [ 5 ], 0, -1, null ] as $badId ) {
            $e = $this->mcpCall( [ 'id' => $badId ] );
            $this->assertSame( -32602, $e['error']['code'], 'id ' . json_encode( $badId ) );
        }
    }

    public function testMissingAndNonPublicPostsAreNotFoundAndNoEditRightIsForbidden(): void
    {
        foreach ( [ '999', '7' ] as $id ) {
            $r = $this->rest( [ 'id' => $id ] );
            $this->assertInstanceOf( \WP_Error::class, $r );
            $this->assertSame( 404, $r->data['status'] );
            $this->assertSame( -32602, $this->mcpCall( [ 'id' => (int) $id ] )['error']['code'] );
        }
        $GLOBALS['__wp_caps'] = [ 'edit_pages' => true, 'edit_post' => true, 'edit_post:6' => false ];
        $r = $this->rest( [ 'id' => '6' ] );
        $this->assertSame( 403, $r->data['status'] );
        $e = $this->mcpCall( [ 'id' => 6 ] );
        $this->assertStringContainsString( 'permission', $e['error']['message'] );
    }

    public function testBothSurfacesRefuseAUserWhoCannotEditPages(): void
    {
        $GLOBALS['__wp_caps'] = [ 'edit_post' => true ];
        $denied = $this->mcpCall( [ 'id' => 5 ] );
        $this->assertSame( -32602, $denied['error']['code'] );
        $this->assertStringContainsString( 'permission', $denied['error']['message'] );
        $r = $this->rest( [ 'id' => '5' ] );
        $this->assertSame( 403, $r->data['status'] );
    }

    public function testTheToolWritesNothing(): void
    {
        $before = [ $GLOBALS['__wp_options'], $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        $this->rest( [ 'id' => '5' ] );
        $this->mcpCall( [ 'id' => 5 ] );
        $this->assertSame( $before, [ $GLOBALS['__wp_options'], $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ] );
    }

    public function testToolsListDeclaresAGeminiSafeSchema(): void
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ] );
        $data = (array) ( new McpHandler() )->handle( $req )->get_data();
        $tool = null;
        foreach ( $data['result']['tools'] as $t ) {
            if ( 'get_custom_fields' === $t['name'] ) {
                $tool = $t;
            }
        }
        $this->assertNotNull( $tool, 'get_custom_fields is listed' );
        $this->assertSame( 'integer', $tool['inputSchema']['properties']['id']['type'] );
        $this->assertSame( [ 'id' ], $tool['inputSchema']['required'] );
        $this->assertSame( 'array', $tool['inputSchema']['properties']['keys']['type'] );
        $this->assertSame( 'string', $tool['inputSchema']['properties']['keys']['items']['type'] );
        $json = (string) json_encode( $tool['inputSchema'] );
        foreach ( [ 'additionalProperties', '$ref', 'oneOf' ] as $banned ) {
            $this->assertStringNotContainsString( $banned, $json );
        }
    }
}
