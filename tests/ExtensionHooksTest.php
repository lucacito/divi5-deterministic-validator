<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ExtensionGuard;
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

    public function testWithoutListenersTheSpecKeepsItsBuiltInAnchors(): void
    {
        $s = $this->spec();
        $this->assertArrayHasKey('/pages', $s['paths']);
        $this->assertArrayHasKey('/pages/{id}/history', $s['paths']);
        $this->assertArrayHasKey('/validate', $s['paths']);
        $this->assertArrayHasKey('/media', $s['paths']);
        $this->assertArrayNotHasKey('/addon/thing', $s['paths']);
        $this->assertArrayHasKey('Violation', $s['components']['schemas']);
    }

    public function testBadSpecFiltersLeaveTheSpecIdentical(): void
    {
        $baseline = $this->spec();
        foreach ([null, 'oops', 42, false] as $bad) {
            $GLOBALS['__wp_filters'] = [];
            add_filter('jhmg_aied_openapi_paths', fn() => $bad, 10, 2);
            add_filter('jhmg_aied_openapi_schemas', fn() => $bad);
            $this->assertSame($baseline, $this->spec());
        }
    }

    public function testAnAddonCannotOverwriteOrRemoveBuiltInPathsOrSchemas(): void
    {
        $baseline = $this->spec();
        add_filter('jhmg_aied_openapi_paths', function (array $paths): array {
            $paths['/pages'] = ['get' => ['operationId' => 'hijack']];
            unset($paths['/validate']);
            $paths['/addon/ok'] = ['get' => ['operationId' => 'addonOk']];
            return $paths;
        }, 10, 2);
        add_filter('jhmg_aied_openapi_schemas', function (array $schemas): array {
            $schemas['Violation'] = ['type' => 'string'];
            return $schemas;
        });
        $s = $this->spec();
        $this->assertSame($baseline['paths']['/pages'], $s['paths']['/pages']);
        $this->assertSame($baseline['paths']['/validate'], $s['paths']['/validate']);
        $this->assertSame($baseline['components']['schemas']['Violation'], $s['components']['schemas']['Violation']);
        $this->assertArrayHasKey('/addon/ok', $s['paths']);
    }

    // ---- ExtensionGuard ------------------------------------------------------

    private function builtIns(): array
    {
        return [['name' => 'validate_layout'], ['name' => 'create_page']];
    }

    public function testToolsNonArrayLeavesBuiltInsUnchanged(): void
    {
        foreach ([null, 'x', 5, false] as $bad) {
            $this->assertSame($this->builtIns(), ExtensionGuard::tools($this->builtIns(), $bad));
        }
    }

    public function testToolsDropsMalformedAndCollidingEntriesAndAppendsValidOnes(): void
    {
        $filtered = [
            ['name' => 'validate_layout', 'description' => 'hijack'],
            null,
            'string',
            ['description' => 'no name'],
            ['name' => 7],
            ['name' => ''],
            ['name' => 'extra_tool'],
            ['name' => 'extra_tool', 'description' => 'dup'],
        ];
        $out = ExtensionGuard::tools($this->builtIns(), $filtered);
        $this->assertSame(['validate_layout', 'create_page', 'extra_tool'], array_column($out, 'name'));
        $this->assertArrayNotHasKey('description', $out[0]);
        $this->assertSame(array_keys($out), range(0, count($out) - 1));
    }

    public function testToolsKeepsBuiltInsWhenFilterDropsThem(): void
    {
        $out = ExtensionGuard::tools($this->builtIns(), [['name' => 'extra_tool']]);
        $this->assertSame(['validate_layout', 'create_page', 'extra_tool'], array_column($out, 'name'));
    }

    public function testTabsAreNormalisedAndGuarded(): void
    {
        $tabs = ExtensionGuard::tabs([
            'Reports'   => 'Reports',
            'settings'  => 'Hijack',
            123         => 'Numeric',
            'bad slug!' => 'Weird',
            'obj'       => ['not scalar'],
            '!!!'       => 'Empty after sanitising',
        ], ['dashboard', 'features', 'settings']);
        $this->assertSame(['reports' => 'Reports', '123' => 'Numeric', 'badslug' => 'Weird'], $tabs);
        $this->assertSame([], ExtensionGuard::tabs(null, []));
        $this->assertSame([], ExtensionGuard::tabs('x', []));
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
