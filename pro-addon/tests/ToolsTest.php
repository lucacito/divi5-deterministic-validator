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
        foreach ( Tools::all() as $tool ) {
            $this->assertInstanceOf( ProTool::class, $tool );
            $this->assertSame( $tool->name(), $tool->definition()['name'] );
            $this->assertNotSame( '', $tool->capability() );
        }
        $this->assertTrue( true, 'the list may be empty before the tool plans land' );
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
