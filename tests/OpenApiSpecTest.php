<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OpenApiSpec;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/OpenApiSpec.php';

/**
 * ChatGPT Actions imposes constraints the plugin's OpenAPI spec must respect or
 * the importer shows warnings: every operation description must be <= 300 chars,
 * and a response object schema must declare its shape (a bare `type: object`
 * with no properties is rejected). These lock both so they can't regress.
 */
class OpenApiSpecTest extends TestCase
{
    /** @return array<string,mixed> */
    private function spec(): array
    {
        return OpenApiSpec::spec('https://acme.example/wp-json/ai-editor-divi5/v1', '9.9.9');
    }

    public function testEveryOperationDescriptionWithinChatgpt300CharLimit(): void
    {
        foreach ($this->spec()['paths'] as $path => $methods) {
            foreach ($methods as $method => $op) {
                if (!isset($op['description'])) {
                    continue;
                }
                $len = mb_strlen((string) $op['description']);
                $this->assertLessThanOrEqual(
                    300,
                    $len,
                    "$method $path (operationId " . ($op['operationId'] ?? '?') . ") description is $len chars (>300)"
                );
            }
        }
    }

    public function testNoResponseObjectSchemaIsEmpty(): void
    {
        foreach ($this->spec()['paths'] as $path => $methods) {
            foreach ($methods as $method => $op) {
                foreach (($op['responses'] ?? []) as $code => $resp) {
                    $schema = $resp['content']['application/json']['schema'] ?? null;
                    if ($schema === null || ($schema['type'] ?? null) !== 'object') {
                        continue; // $ref-based or non-object schemas are fine
                    }
                    $hasShape = isset($schema['properties']) || isset($schema['additionalProperties']);
                    $this->assertTrue(
                        $hasShape,
                        "$method $path response $code is a bare object schema (no properties) — ChatGPT rejects it"
                    );
                }
            }
        }
    }

    public function testSpecInjectsBaseAndVersion(): void
    {
        $spec = $this->spec();
        $this->assertSame('9.9.9', $spec['info']['version']);
        $this->assertSame('https://acme.example/wp-json/ai-editor-divi5/v1', $spec['servers'][0]['url']);
    }
}
