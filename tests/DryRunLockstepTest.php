<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OpenApiSpec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';

/** dry_run must exist, with the same meaning, in MCP, REST and OpenAPI, and must never reach a write. */
class DryRunLockstepTest extends TestCase
{
    private function src( string $file ): string
    {
        return (string) file_get_contents( __DIR__ . '/../wp-plugin/src/' . $file );
    }

    /** The source of one method: from its declaration to the next method declaration. */
    private function body( string $src, string $method ): string
    {
        $start = strpos( $src, "function {$method}(" );
        $this->assertNotFalse( $start, "{$method} not found" );
        $rest = substr( $src, $start + 10 );
        $end  = preg_match( '/\n    (?:public |private |protected )?(?:static )?function /', $rest, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] : strlen( $rest );

        return substr( $rest, 0, $end );
    }

    /** @return list<array{string,string,string}> file, method, argument variable */
    public static function handlers(): array
    {
        return [
            [ 'McpHandler.php', 'toolUpdate', '$args' ],
            [ 'McpHandler.php', 'toolEditContent', '$args' ],
            [ 'RestController.php', 'update_page', '$body' ],
            [ 'RestController.php', 'edit_page', '$body' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider( 'handlers' )]
    public function testEveryWriteHandlerParsesDryRunStrictlyAndReturnsBeforeAnyWrite( string $file, string $method, string $var ): void
    {
        $b = $this->body( $this->src( $file ), $method );
        $this->assertStringContainsString( "PageAccess::boolArg({$var}, 'dry_run')", $b );
        $this->assertStringContainsString( 'DryRun::preview(', $b );
        $this->assertStringContainsString( 'UsageTracker::log(DryRun::ENDPOINT', $b );
        $this->assertStringContainsString( 'HistoryService::write(', $b );
        $this->assertLessThan(
            strpos( $b, 'HistoryService::write(' ),
            strpos( $b, 'DryRun::preview(' ),
            "{$method}: the dry-run return must come before HistoryService::write()"
        );
        $this->assertStringContainsString( 'if ($dryRun === null)', $b, "{$method}: a mistyped dry_run must be rejected" );
    }

    public function testMcpDeclaresDryRunAsABooleanOnBothWriteTools(): void
    {
        $mcp = $this->src( 'McpHandler.php' );
        $this->assertSame( 2, substr_count( $mcp, "'dry_run' => ['type' => 'boolean'" ) );
        $this->assertStringContainsString( 'dry_run=true', $mcp, 'the tool descriptions explain dry_run' );
    }

    public function testRestReadsDryRunFromTheJsonBodyAndRegistersNoRouteArgForIt(): void
    {
        $rest = $this->src( 'RestController.php' );

        foreach ( [ 'update_page', 'edit_page' ] as $method ) {
            $b = $this->body( $rest, $method );
            $this->assertStringContainsString( '$body    = (array) $request->get_json_params();', $b, "{$method} reads the JSON body" );
            $this->assertStringContainsString( "PageAccess::boolArg(\$body, 'dry_run')", $b );
        }

        // A route-level 'dry_run' arg would let WordPress coerce "true" into true before the strict parser sees it.
        $routes = $this->body( $rest, 'register_routes' );
        $this->assertStringContainsString( "'/pages/(?P<id>\\d+)'", $routes );
        $this->assertStringContainsString( "'/pages/(?P<id>\\d+)/edit'", $routes );
        $this->assertStringNotContainsString( 'dry_run', $routes, 'no route may declare a dry_run arg' );
    }

    public function testOpenApiDeclaresDryRunOnBothWriteOperationsAndTheResponseShape(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );

        $putBody  = $spec['paths']['/pages/{id}']['put']['requestBody']['content']['application/json']['schema']['properties'] ?? [];
        $editBody = $spec['paths']['/pages/{id}/edit']['post']['requestBody']['content']['application/json']['schema']['properties'] ?? [];
        $this->assertSame( 'boolean', $putBody['dry_run']['type'] ?? null );
        $this->assertSame( 'boolean', $editBody['dry_run']['type'] ?? null );
        $this->assertNotContains( 'dry_run', $spec['paths']['/pages/{id}']['put']['requestBody']['content']['application/json']['schema']['required'] ?? [] );

        $validateBody = $spec['paths']['/validate']['post']['requestBody']['content']['application/json']['schema']['properties'] ?? [];
        $this->assertArrayNotHasKey( 'dry_run', $validateBody, 'validate_layout never saves, it has no dry_run' );

        foreach ( [ $spec['paths']['/pages/{id}']['put'], $spec['paths']['/pages/{id}/edit']['post'] ] as $op ) {
            $props = $op['responses']['200']['content']['application/json']['schema']['properties'] ?? [];
            $this->assertSame( 'boolean', $props['dry_run']['type'] ?? null );
            $this->assertSame( '#/components/schemas/DryRunDiff', $props['diff']['$ref'] ?? null );
            $this->assertSame( 'string', $props['diff_error']['type'] ?? null );
            $this->assertSame( 'string', $props['message']['type'] ?? null );
        }

        $schemas = $spec['components']['schemas'];
        foreach ( [ 'DryRunDiff', 'DryRunChange', 'DryRunAttr' ] as $name ) {
            $this->assertArrayHasKey( $name, $schemas );
        }
        $this->assertSame( [ 'added', 'removed', 'changed' ], $schemas['DryRunChange']['properties']['kind']['enum'] ?? null );
        $this->assertSame( [ 'string', 'null' ], $schemas['DryRunAttr']['properties']['before']['type'] ?? null );
    }

    public function testTheOperationCountIsUnchanged(): void
    {
        $spec = OpenApiSpec::spec( 'https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9' );
        $ops  = [];
        foreach ( $spec['paths'] as $methods ) {
            foreach ( $methods as $op ) {
                $ops[] = $op['operationId'] ?? '';
            }
        }
        $this->assertCount( 17, $ops, 'dry_run is a flag, not a new operation (the 16th and 17th operations are the read-only list_theme_layouts and list_global_styles)' );
    }
}
