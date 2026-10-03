<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Plugin;
use AiEditorDivi5\Pro\Rest\ProOpenApi;
use AiEditorDivi5\Pro\Rest\ProRest;
use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\Tools;
use AiEditorDivi5\WP\OpenApiSpec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/WpRestRouteShims.php';

/**
 * MCP, REST and the ChatGPT OpenAPI spec must expose exactly the same Pro tools with the same arguments, and the
 * spec must stay inside the ChatGPT importer's limits. All three are generated from the tool list, so this proves it.
 */
class ProParityTest extends TestCase
{
    private ToolRegistry $registry;
    /** @var array<string,mixed> */
    private array $spec;

    protected function setUp(): void
    {
        $GLOBALS['__wp_filters']     = [];
        $GLOBALS['__wp_rest_routes'] = [];
        $this->registry              = new ToolRegistry( Tools::all(), static fn (): bool => true );
        $this->registry->register();
        ( new ProRest( $this->registry ) )->register();
        ( new ProOpenApi( $this->registry ) )->register();
        do_action( 'jhmg_aied_register_rest_routes', 'ai-editor-divi5/v1' );
        $this->spec = OpenApiSpec::spec( 'https://example.com/wp-json/ai-editor-divi5/v1', '4.4.0' );
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_filters'] = [];
    }

    /** @return list<array{string,string,array<string,mixed>}> every operation: method, path, operation */
    private function operations(): array
    {
        $ops = [];
        foreach ( $this->spec['paths'] as $path => $methods ) {
            foreach ( $methods as $method => $op ) {
                $ops[] = [ (string) $method, (string) $path, $op ];
            }
        }

        return $ops;
    }

    /** @return list<array{string,string,array<string,mixed>}> */
    private function proOperations(): array
    {
        return array_values( array_filter( $this->operations(), static fn ( array $o ): bool => str_starts_with( $o[1], '/pro/' ) ) );
    }

    public function testMcpRestAndOpenApiExposeExactlyTheSameTools(): void
    {
        $mcp = array_column( $this->registry->definitions( [] ), 'name' );
        $rest = array_map( static fn ( array $r ): string => substr( $r[1], strlen( '/pro/' ) ), $GLOBALS['__wp_rest_routes'] );
        $api  = array_map( static fn ( array $o ): string => substr( $o[1], strlen( '/pro/' ) ), $this->proOperations() );
        $this->assertSame( $mcp, $rest );
        $this->assertSame( $mcp, $api );
        $this->assertCount( count( Tools::all() ), $mcp );
    }

    public function testEveryOperationCarriesTheToolsOwnArguments(): void
    {
        foreach ( $this->registry->definitions( [] ) as $def ) {
            $op     = $this->spec['paths']['/pro/' . $def['name']]['post'];
            $schema = $op['requestBody']['content']['application/json']['schema'];
            $this->assertSame( array_keys( $def['inputSchema']['properties'] ), array_keys( $schema['properties'] ), $def['name'] );
            $this->assertSame( $def['inputSchema']['required'] ?? [], $schema['required'] ?? [], $def['name'] );
            $this->assertSame( $def['description'], $op['description'], $def['name'] );
            $this->assertSame( [] !== ( $def['inputSchema']['required'] ?? [] ), $op['requestBody']['required'], $def['name'] );
        }
    }

    public function testTheSpecStaysInsideTheChatGptImporterLimits(): void
    {
        $ops = $this->operations();
        $this->assertLessThanOrEqual( 30, count( $ops ), 'the importer caps a spec at 30 operations' );
        $ids = [];
        foreach ( $ops as [ $method, $path, $op ] ) {
            $this->assertLessThanOrEqual( 300, strlen( (string) $op['description'] ), "{$method} {$path} description" );
            $this->assertLessThanOrEqual( 300, strlen( (string) ( $op['summary'] ?? '' ) ), "{$method} {$path} summary" );
            $ids[] = $op['operationId'];
        }
        $this->assertSame( $ids, array_values( array_unique( $ids ) ), 'operationIds are unique' );
        foreach ( $this->proOperations() as [ , $path, $op ] ) {
            $this->assertMatchesRegularExpression( '/^[a-z][A-Za-z0-9]*$/', $op['operationId'], $path );
        }
    }

    public function testNoProObjectSchemaIsBare(): void
    {
        $check = function ( mixed $node, string $where ) use ( &$check ): void {
            if ( ! is_array( $node ) ) {
                return;
            }
            if ( ( $node['type'] ?? null ) === 'object' ) {
                $this->assertTrue( isset( $node['properties'] ) && [] !== $node['properties'] || isset( $node['additionalProperties'] ), "bare object schema at {$where}" );
            }
            foreach ( $node as $k => $v ) {
                $check( $v, $where . '/' . $k );
            }
        };
        foreach ( $this->proOperations() as [ , $path, $op ] ) {
            $check( $op, $path );
        }
        $check( $this->spec['components']['schemas']['ProResult'], 'ProResult' );
        $check( $this->spec['components']['schemas']['ProError'], 'ProError' );
    }

    public function testTheFreePluginsOwnOperationsAreUntouched(): void
    {
        $free = array_filter( $this->operations(), static fn ( array $o ): bool => ! str_starts_with( $o[1], '/pro/' ) );
        $this->assertCount( 16, $free );
        $this->assertSame( [ '/pro/' ], array_values( array_unique( array_map( static fn ( array $o ): string => substr( $o[1], 0, 5 ), $this->proOperations() ) ) ) );
    }

    public function testWhileLockedNothingIsAdded(): void
    {
        $GLOBALS['__wp_filters']     = [];
        $GLOBALS['__wp_rest_routes'] = [];
        $locked                      = new ToolRegistry( Tools::all(), static fn (): bool => false );
        ( new ProRest( $locked ) )->register();
        ( new ProOpenApi( $locked ) )->register();
        do_action( 'jhmg_aied_register_rest_routes', 'ai-editor-divi5/v1' );
        $spec = OpenApiSpec::spec( 'https://example.com/wp-json/ai-editor-divi5/v1', '4.4.0' );
        $this->assertSame( [], $GLOBALS['__wp_rest_routes'] );
        $this->assertSame( [], array_filter( array_keys( $spec['paths'] ), static fn ( string $p ): bool => str_starts_with( $p, '/pro/' ) ) );
        $this->assertArrayNotHasKey( 'ProResult', $spec['components']['schemas'] );
    }

    public function testTheAddOnWiresRestAndOpenApiWhenItBoots(): void
    {
        $GLOBALS['__wp_filters'] = [];
        $GLOBALS['__wp_options'] = [ 'aied_pro_premium_unlocked' => 1 ];
        \AiEditorDivi5\Pro\Licensing::resetForTests();
        $this->assertTrue( Plugin::bootWith( '4.4.0', static fn (): bool => true ) );
        $this->assertArrayHasKey( 'jhmg_aied_register_rest_routes', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'jhmg_aied_openapi_paths', $GLOBALS['__wp_filters'] );
        $this->assertArrayHasKey( 'jhmg_aied_openapi_schemas', $GLOBALS['__wp_filters'] );
        $GLOBALS['__wp_options'] = [];
        \AiEditorDivi5\Pro\Licensing::resetForTests();
    }
}
