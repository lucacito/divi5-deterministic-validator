<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\McpHandler;
use AiEditorDivi5\WP\OpenApiSpec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WpRestShims.php';
require_once __DIR__ . '/../wp-plugin/src/McpHandler.php';
require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';

/**
 * Pins the free plugin's public tool surface byte for byte: the MCP `tools/list` answer and the OpenAPI spec that ChatGPT's
 * importer reads. The companion work (shared tool catalog, abilities) must not change a single byte of either.
 * Regenerate deliberately with AIED_UPDATE_GOLDEN=1, and only when a tool is meant to change.
 */
class ToolsListGoldenTest extends TestCase
{
    private const DIR = __DIR__ . '/golden/companion/';

    private function toolsListJson(): string
    {
        $req  = new \WP_REST_Request( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new \stdClass() ] );
        $data = ( new McpHandler() )->handle( $req )->get_data();

        return json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
    }

    private function openApiJson(): string
    {
        return json_encode( OpenApiSpec::spec( 'https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
    }

    private function check( string $file, string $actual ): void
    {
        $path = self::DIR . $file;
        if ( getenv( 'AIED_UPDATE_GOLDEN' ) ) {
            file_put_contents( $path, $actual );
        }
        $this->assertFileExists( $path );
        $this->assertSame( file_get_contents( $path ), $actual, "{$file} changed: an existing tool must keep its name, parameters and description" );
    }

    public function testMcpToolsListIsByteIdentical(): void
    {
        $this->check( 'mcp-tools-list.json', $this->toolsListJson() );
    }

    public function testOpenApiSpecIsByteIdentical(): void
    {
        $this->check( 'openapi-spec.json', $this->openApiJson() );
    }

    public function testTheFreePluginStillOffers21ToolsAnd16Operations(): void
    {
        $data = json_decode( $this->toolsListJson(), true );
        $this->assertCount( 21, $data['result']['tools'] );
        $ops = 0;
        foreach ( json_decode( $this->openApiJson(), true )['paths'] as $item ) {
            $ops += count( $item );
        }
        $this->assertSame( 16, $ops );
    }
}
