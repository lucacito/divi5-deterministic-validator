<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ProTool;
use AiEditorDivi5\Pro\Tools;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/ProTool.php';
require_once __DIR__ . '/../src/Tools.php';

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
            [ 'set_front_page', 'set_custom_css', 'set_primary_menu', 'publish_page', 'propose_php_snippet', 'list_batches', 'undo_batch', 'list_theme_layouts', 'update_theme_layout', 'edit_theme_layout' ],
            array_map( static fn ( ProTool $t ): string => $t->name(), Tools::all() )
        );
    }

    public function testEveryKindARecordedItemUsesIsRegisteredForUndo(): void
    {
        $this->assertEqualsCanonicalizing(
            [ 'front_page', 'custom_css', 'menu', 'post_status', 'post_content', 'theme_layout_content' ],
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
        // The ChatGPT importer caps an OpenAPI spec at 30 operations: free built-ins plus Pro tools must fit.
        $this->assertLessThanOrEqual( 29, count( $this->builtInToolNames() ) + count( Tools::all() ) );
    }
}
