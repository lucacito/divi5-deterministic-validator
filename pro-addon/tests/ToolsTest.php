<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Rest\ProOpenApi;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\WP\OpenApiSpec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/ProTool.php';
require_once __DIR__ . '/../src/Tools.php';
require_once __DIR__ . '/../src/Rest/ProOpenApi.php';
require_once __DIR__ . '/../../wp-plugin/src/OpenApiSpec.php';

/** Guards the tool list as the next plans fill it: no shadowing, consistent names, and the OpenAPI operation budget. */
class ToolsTest extends TestCase
{
    /** @return list<string> */
    private function builtInToolNames(): array
    {
        $mcp = (string) file_get_contents( __DIR__ . '/../../wp-plugin/src/McpHandler.php' );
        preg_match_all( "/^\s+'name'\s+=> '([a-z_]+)',$/m", $mcp, $m );

        return $m[1];
    }

    public function testEveryToolIsAProToolWithAMatchingDefinition(): void
    {
        $this->assertNotSame( [], Tools::all() );
        foreach ( Tools::all() as $tool ) {
            $this->assertInstanceOf( ProTool::class, $tool );
            $d = $tool->definition();
            $this->assertSame( $tool->name(), $d['name'] );
            $this->assertNotSame( '', $tool->capability() );
            $this->assertMatchesRegularExpression( '/^[a-z_]+$/', $tool->name() );
            $this->assertLessThanOrEqual( 300, strlen( $d['description'] ), $tool->name() . ': the ChatGPT importer caps descriptions at 300 characters' );
            $this->assertSame( 'object', $d['inputSchema']['type'] );
            foreach ( $d['inputSchema']['required'] ?? [] as $field ) {
                $this->assertArrayHasKey( $field, $d['inputSchema']['properties'], $tool->name() . ": required field {$field}" );
            }
        }
    }

    public function testThisReleaseShipsExactlyTheseTools(): void
    {
        $this->assertSame(
            [ 'set_front_page', 'set_custom_css', 'set_primary_menu', 'publish_page', 'propose_php_snippet', 'list_batches', 'undo_batch', 'list_theme_layouts', 'update_theme_layout', 'edit_theme_layout', 'manage_theme_template', 'find_replace_site', 'audit_site', 'build_site', 'manage_global_colors', 'manage_global_variables', 'manage_global_presets', 'set_custom_fields', 'create_product', 'update_products', 'bulk_adjust_prices', 'switch_design' ],
            array_map( static fn ( ProTool $t ): string => $t->name(), Tools::all() )
        );
    }

    public function testEveryKindARecordedItemUsesIsRegisteredForUndo(): void
    {
        $this->assertEqualsCanonicalizing(
            [ 'front_page', 'custom_css', 'menu', 'post_status', 'page_created', 'page_trashed', 'post_content', 'theme_layout_content', 'theme_template', 'global_color', 'global_variable', 'global_preset', 'post_meta', 'product_created', 'product_trashed', 'product_fields' ],
            array_keys( \AiEditorDivi5\Pro\Batch\Batches::kinds() )
        );
    }

    public function testNoToolShadowsABuiltInOrAnotherProTool(): void
    {
        $names = array_map( static fn ( ProTool $t ): string => $t->name(), Tools::all() );
        $this->assertSame( [], array_intersect( $names, $this->builtInToolNames() ), 'a Pro tool must not reuse a built-in name' );
        $this->assertSame( $names, array_values( array_unique( $names ) ), 'Pro tool names are unique' );
    }

    public function testTheChatGptOperationBudgetIsRespected(): void
    {
        // The ChatGPT importer caps an OpenAPI spec at 30 operations: the free plugin's REAL spec operations plus the
        // Pro tools must fit. Count OpenApiSpec, not the MCP tool list: get_site_structure (4.6.0) is MCP + REST only
        // and deliberately NOT in the spec (owner decision 2026-10-04: the 16 free + 14 Pro operations use all 30).
        $free = 0;
        foreach ( OpenApiSpec::spec( 'https://example.com/wp-json/ai-editor-divi5/v1', '9.9.9' )['paths'] as $path => $methods ) {
            if ( ! str_starts_with( (string) $path, '/pro/' ) ) { // a Pro-registered filter may already have added its operations
                $free += count( $methods );
            }
        }
        // The 15th to 22nd Pro tools, manage_global_colors, manage_global_variables, manage_global_presets, set_custom_fields, create_product, update_products, bulk_adjust_prices and switch_design (2026-10-25, plan ruling 8: +1), are MCP + REST only by the owner's decision (2026-10-05 and after): they are not an
        // operation of the spec, so the budget counts the Pro tools that ARE in it (16 free + 14 Pro = 30, exactly).
        $inSpec = count( Tools::all() ) - count( ProOpenApi::MCP_REST_ONLY );
        $this->assertLessThanOrEqual( 30, $free + $inSpec );
        $this->assertSame( [ 'manage_global_colors', 'manage_global_variables', 'manage_global_presets', 'set_custom_fields', 'create_product', 'update_products', 'bulk_adjust_prices', 'switch_design' ], ProOpenApi::MCP_REST_ONLY, 'a new MCP-only tool is an owner decision: change this list on purpose' );
    }
}
