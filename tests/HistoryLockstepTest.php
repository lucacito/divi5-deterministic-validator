<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OpenApiSpec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';

/** The history tools must exist, under matching names, in all three transports. */
class HistoryLockstepTest extends TestCase
{
    private function src(string $file): string
    {
        return (string) file_get_contents(__DIR__ . '/../wp-plugin/src/' . $file);
    }

    public function testMcpExposesTheThreeToolsInListAndDispatch(): void
    {
        $mcp = $this->src('McpHandler.php');
        foreach (['list_page_history', 'get_page_history_entry', 'restore_page_version'] as $tool) {
            $this->assertStringContainsString("'name'        => '{$tool}'", $mcp, "$tool missing from tools/list");
            $this->assertStringContainsString("'{$tool}'", $mcp);
            $this->assertMatchesRegularExpression("/'{$tool}'\s*=>\s*\\\$this->tool/", $mcp, "$tool missing from the dispatch match");
        }
    }

    public function testRestExposesTheThreeRoutes(): void
    {
        $rest = $this->src('RestController.php');
        $this->assertStringContainsString("'/pages/(?P<id>\\d+)/history'", $rest);
        $this->assertStringContainsString("'/pages/(?P<id>\\d+)/history/(?P<version_id>\\d+)'", $rest);
        $this->assertStringContainsString("'/pages/(?P<id>\\d+)/restore'", $rest);
    }

    public function testOpenApiDeclaresTheThreeOperations(): void
    {
        $spec = OpenApiSpec::spec('https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9');
        $ops  = [];
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $method => $op) {
                $ops[$op['operationId'] ?? ''] = [$method, $path];
            }
        }
        $this->assertSame(['get', '/pages/{id}/history'], $ops['listPageHistory'] ?? null);
        $this->assertSame(['get', '/pages/{id}/history/{versionId}'], $ops['getPageHistoryEntry'] ?? null);
        $this->assertSame(['post', '/pages/{id}/restore'], $ops['restorePageVersion'] ?? null);
        $this->assertArrayHasKey('HistoryEntry', $spec['components']['schemas']);
        $this->assertArrayHasKey('SnapshotInfo', $spec['components']['schemas']);
    }

    public function testOpenApiDocumentsTheHistoryFieldOnWriteResponses(): void
    {
        $spec = OpenApiSpec::spec('https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9');
        $put  = $spec['paths']['/pages/{id}']['put']['responses']['200']['content']['application/json']['schema'] ?? null;
        $edit = $spec['paths']['/pages/{id}/edit']['post']['responses']['200']['content']['application/json']['schema'] ?? null;
        $rest = $spec['paths']['/pages/{id}/restore']['post']['responses']['200']['content']['application/json']['schema'] ?? null;
        foreach ([$put, $edit, $rest] as $schema) {
            $this->assertIsArray($schema);
            $this->assertSame('#/components/schemas/SnapshotInfo', $schema['properties']['history']['$ref'] ?? null);
        }
    }

    public function testMediaToolExistsInAllThreeTransports(): void
    {
        $mcp = $this->src('McpHandler.php');
        $this->assertStringContainsString("'name'        => 'list_media_images'", $mcp);
        $this->assertMatchesRegularExpression("/'list_media_images'\s*=>\s*\\\$this->toolListMedia/", $mcp);
        $this->assertStringContainsString("'/media'", $this->src('RestController.php'));

        $spec = OpenApiSpec::spec('https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9');
        $this->assertSame('listMediaImages', $spec['paths']['/media']['get']['operationId'] ?? null);
        $this->assertArrayHasKey('MediaItem', $spec['components']['schemas']);
    }
}
