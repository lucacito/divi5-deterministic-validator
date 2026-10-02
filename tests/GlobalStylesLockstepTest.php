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
require_once __DIR__ . '/DiviGlobalDataShims.php';
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
require_once __DIR__ . '/../wp-plugin/src/GlobalStyles.php';
require_once __DIR__ . '/../wp-plugin/src/StyleReferences.php';
require_once __DIR__ . '/../wp-plugin/src/StyleGuide.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';
require_once __DIR__ . '/../wp-plugin/src/RestController.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';

/** list_global_styles must exist, with the same meaning, in MCP, REST and OpenAPI, and be read-only. */
class GlobalStylesLockstepTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->createHistoryTable();
        $GLOBALS['__wp_posts']      = [];
        $GLOBALS['__wp_postmeta']   = [];
        $GLOBALS['__wp_caps']       = [ 'edit_pages' => true ];
        $GLOBALS['__divi_presets']  = [ 'module' => [ 'divi/button' => [ 'default' => 'b-1', 'items' => [ 'b-1' => [ 'id' => 'b-1', 'name' => 'Primary', 'attrs' => [ 'button' => [ 'decoration' => [ 'font' => [] ] ] ] ], 'b-2' => [ 'id' => 'b-2', 'name' => 'Ghost' ] ] ], 'divi/text' => [ 'default' => '', 'items' => [ 't-1' => [ 'id' => 't-1', 'name' => 'Body' ] ] ] ] ];
        $GLOBALS['__divi_colors']   = [ 'gcid-primary-color' => [ 'color' => '#2ea3f2', 'label' => 'Primary Color', 'status' => 'active', 'folder' => 'customizer' ] ];
        $GLOBALS['__divi_variables'] = [ 'numbers' => [ 'gvid-1' => [ 'label' => 'Radius', 'value' => '12px', 'status' => 'active' ] ] ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = null;
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__divi_presets'], $GLOBALS['__divi_colors'], $GLOBALS['__divi_variables'] );
        $GLOBALS['__wp_can'] = true;
    }

    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    public function testMcpDeclaresAndDispatchesTheTool(): void
    {
        $mcp = $this->src( 'McpHandler.php' );
        $this->assertStringContainsString( "'name'        => 'list_global_styles'", $mcp );
        $this->assertMatchesRegularExpression( "/'list_global_styles'\s*=>\s*\\\$this->toolListGlobalStyles/", $mcp );
    }

    public function testRestRegistersTheRouteAsReadOnly(): void
    {
        $rest = $this->src( 'RestController.php' );
        $this->assertMatchesRegularExpression( "/'\/global-styles',\s*\[\s*'methods'\s*=>\s*WP_REST_Server::READABLE/", $rest );
    }

    public function testOpenApiDescribesTheOperationAndItsSchemas(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $op   = $spec['paths']['/global-styles']['get'] ?? null;
        $this->assertIsArray( $op );
        $this->assertSame( 'listGlobalStyles', $op['operationId'] );
        $this->assertLessThanOrEqual( 300, strlen( (string) $op['description'] ) );
        $this->assertArrayNotHasKey( 'post', $spec['paths']['/global-styles'] );
        $this->assertArrayNotHasKey( 'put', $spec['paths']['/global-styles'] );
        $schema = $op['responses']['200']['content']['application/json']['schema'];
        $this->assertSame( '#/components/schemas/GlobalPreset', $schema['properties']['presets']['items']['$ref'] );
        $this->assertSame( '#/components/schemas/GlobalColor', $schema['properties']['colors']['items']['$ref'] );
        $this->assertSame( 'module', $op['parameters'][0]['name'] );
        foreach ( [ 'GlobalPreset', 'GlobalColor', 'GlobalVariable' ] as $name ) {
            $this->assertArrayHasKey( $name, $spec['components']['schemas'] );
        }
    }

    public function testTheAdvisoryIsDocumentedOnEveryResponseThatCanCarryIt(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $ref  = '#/components/schemas/Advisories';
        foreach ( [ 'StyleAdvisory', 'StyleRefCount', 'Advisories' ] as $name ) {
            $this->assertArrayHasKey( $name, $spec['components']['schemas'] );
        }
        $this->assertSame( $ref, $spec['components']['schemas']['ValidationResult']['properties']['advisories']['$ref'] );
        $this->assertSame( $ref, $spec['paths']['/pages/{id}']['put']['responses']['200']['content']['application/json']['schema']['properties']['advisories']['$ref'] );
        $this->assertSame( $ref, $spec['paths']['/pages/{id}/edit']['post']['responses']['200']['content']['application/json']['schema']['properties']['advisories']['$ref'] );
        $this->assertSame( $ref, $spec['paths']['/pages']['post']['responses']['201']['content']['application/json']['schema']['properties']['advisories']['$ref'] );
    }

    private function mcpCall( array $args = [] ): array
    {
        $req = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [ 'name' => 'list_global_styles', 'arguments' => $args ?: new \stdClass() ] ] );

        return (array) ( new McpHandler() )->handle( $req )->get_data();
    }

    public function testRestAndMcpReturnTheSameCatalog(): void
    {
        $rest = ( new RestController() )->global_styles( new \WP_REST_Request() );
        $this->assertSame( 200, $rest->get_status() );
        $d = (array) $rest->get_data();
        $this->assertSame( [ 'Ghost', 'Primary', 'Body' ], array_column( $d['presets'], 'name' ) );
        $this->assertSame( 'theme', $d['colors'][0]['source'] );
        $this->assertSame( 1, $d['counts']['variables'] );

        $ok = $this->mcpCall();
        $this->assertArrayNotHasKey( 'error', $ok );
        $this->assertSame( $d, json_decode( $ok['result']['content'][0]['text'], true ) );
    }

    public function testModuleFilterWorksOnBothSurfaces(): void
    {
        $rest = ( new RestController() )->global_styles( new \WP_REST_Request( [], [ 'module' => 'divi/text' ] ) );
        $this->assertSame( [ 'Body' ], array_column( ( (array) $rest->get_data() )['presets'], 'name' ) );
        $mcp = json_decode( $this->mcpCall( [ 'module' => 'divi/text' ] )['result']['content'][0]['text'], true );
        $this->assertSame( [ 'Body' ], array_column( $mcp['presets'], 'name' ) );
    }

    public function testANonStringModuleIsRejectedNeverCast(): void
    {
        $r = ( new RestController() )->global_styles( new \WP_REST_Request( [], [ 'module' => [ 'divi/text' ] ] ) );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 400, $r->data['status'] );
        foreach ( [ [ 'divi/text' ], 7, true ] as $bad ) {
            $e = $this->mcpCall( [ 'module' => $bad ] );
            $this->assertSame( -32602, $e['error']['code'] );
        }
    }

    public function testBothSurfacesRefuseAUserWhoCannotEditPages(): void
    {
        $GLOBALS['__wp_caps'] = [];
        $denied = $this->mcpCall();
        $this->assertSame( -32602, $denied['error']['code'] );
        $this->assertStringContainsString( 'permission', $denied['error']['message'] );
        $r = ( new RestController() )->global_styles( new \WP_REST_Request() );
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 403, $r->data['status'] );
    }

    public function testTheToolWritesNothing(): void
    {
        $before = [ $GLOBALS['__divi_presets'], $GLOBALS['__divi_colors'], $GLOBALS['__divi_variables'], $GLOBALS['__wp_options'], $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        ( new RestController() )->global_styles( new \WP_REST_Request() );
        $this->mcpCall();
        $after = [ $GLOBALS['__divi_presets'], $GLOBALS['__divi_colors'], $GLOBALS['__divi_variables'], $GLOBALS['__wp_options'], $GLOBALS['__wp_posts'], $GLOBALS['__wp_postmeta'] ];
        $this->assertSame( $before, $after );
    }

    public function testWithoutDiviTheToolAnswersWithNotesNotAnError(): void
    {
        $GLOBALS['__divi_presets'] = null;
        $GLOBALS['__divi_colors']  = null;
        $d = (array) ( new RestController() )->global_styles( new \WP_REST_Request() )->get_data();
        $this->assertSame( [], $d['presets'] );
        $this->assertSame( [], $d['colors'] );
        $this->assertNotEmpty( $d['notes'] );
    }
}
