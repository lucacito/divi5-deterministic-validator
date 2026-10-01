<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OpenApiSpec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';

class ExtensionHooksTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__wp_filters'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_filters'] = [];
    }

    private function spec(): array
    {
        return OpenApiSpec::spec('https://x.example/wp-json/ai-editor-divi5/v1', '9.9.9');
    }

    public function testWithoutListenersTheSpecIsUnchanged(): void
    {
        $a = $this->spec();
        $b = $this->spec();
        $this->assertSame($a, $b);
        $this->assertArrayNotHasKey('/addon/thing', $a['paths']);
    }

    public function testAnAddonCanAddAPathAndASchema(): void
    {
        add_filter('jhmg_aied_openapi_paths', function (array $paths, string $base): array {
            $paths['/addon/thing'] = ['get' => ['operationId' => 'addonThing', 'summary' => 's', 'description' => 'd',
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]]]]]]]];
            return $paths;
        }, 10, 2);
        add_filter('jhmg_aied_openapi_schemas', function (array $schemas): array {
            $schemas['AddonThing'] = ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]];
            return $schemas;
        });
        $s = $this->spec();
        $this->assertArrayHasKey('/addon/thing', $s['paths']);
        $this->assertArrayHasKey('AddonThing', $s['components']['schemas']);
    }

    public function testMcpAndRestAndAdminCallTheDocumentedHooks(): void
    {
        $mcp   = (string) file_get_contents(__DIR__ . '/../wp-plugin/src/McpHandler.php');
        $rest  = (string) file_get_contents(__DIR__ . '/../wp-plugin/src/RestController.php');
        $admin = (string) file_get_contents(__DIR__ . '/../wp-plugin/src/AdminPage.php');
        $this->assertStringContainsString("apply_filters('jhmg_aied_mcp_tools'", $this->compact($mcp));
        $this->assertStringContainsString("apply_filters('jhmg_aied_mcp_call'", $this->compact($mcp));
        $this->assertStringContainsString("do_action('jhmg_aied_register_rest_routes'", $this->compact($rest));
        $this->assertStringContainsString("apply_filters('jhmg_aied_admin_tabs'", $this->compact($admin));
        $this->assertStringContainsString("do_action('jhmg_aied_render_admin_tab'", $this->compact($admin));
    }

    /** Normalise spacing so the source-guard is not whitespace-brittle. */
    private function compact(string $src): string
    {
        return (string) preg_replace('/\(\s+/', '(', (string) preg_replace('/\s+/', ' ', $src));
    }
}
