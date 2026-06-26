<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that the JSON snippet shown on the plugin Connect tab is always
 * well-formed and can be safely merged into an existing claude_desktop_config.json.
 *
 * The real AdminPage.php calls get_site_url() / ApiKey::get() which require
 * WordPress, so we replicate the json_encode logic here with synthetic inputs.
 * The logic is trivial enough that this stays honest.
 */
class McpConfigSnippetTest extends TestCase
{
    private function buildSnippet(string $siteUrl, string $apiKey): string
    {
        $mcpUrl = rtrim($siteUrl, '/') . '/wp-json/ai-editor-divi5/v1/mcp';

        $json = json_encode(
            [
                'mcpServers' => [
                    'ai-editor-divi5' => [
                        'url'     => $mcpUrl,
                        'headers' => ['Authorization' => "Bearer {$apiKey}"],
                    ],
                ],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        // json_encode returns false only on unencodable input.
        $this->assertNotFalse($json, 'json_encode returned false');

        return (string) $json;
    }

    public function testSnippetIsValidJson(): void
    {
        $snippet = $this->buildSnippet('https://example.com', 'abc123');
        $decoded = json_decode($snippet, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Snippet is not valid JSON');
        $this->assertIsArray($decoded);
    }

    public function testSnippetStructure(): void
    {
        $snippet = $this->buildSnippet('https://example.com', 'mykey');
        $decoded = json_decode($snippet, true);

        $this->assertArrayHasKey('mcpServers', $decoded);
        $this->assertArrayHasKey('ai-editor-divi5', $decoded['mcpServers']);

        $server = $decoded['mcpServers']['ai-editor-divi5'];
        $this->assertArrayHasKey('url', $server);
        $this->assertStringContainsString('/wp-json/ai-editor-divi5/v1/mcp', $server['url']);
        $this->assertArrayHasKey('headers', $server);
        $this->assertArrayHasKey('Authorization', $server['headers']);
        $this->assertStringStartsWith('Bearer ', $server['headers']['Authorization']);
    }

    public function testSnippetUrlStripsTrailingSlash(): void
    {
        $a = $this->buildSnippet('https://example.com/', 'k');
        $b = $this->buildSnippet('https://example.com', 'k');

        $urlA = json_decode($a, true)['mcpServers']['ai-editor-divi5']['url'];
        $urlB = json_decode($b, true)['mcpServers']['ai-editor-divi5']['url'];

        $this->assertSame($urlA, $urlB, 'Trailing slash in site URL must be normalised');
    }

    /**
     * Simulates merging the snippet into an existing config the correct way:
     * pull out mcpServers and add it to the existing root object.
     * The result must be valid JSON.
     */
    public function testCorrectMergeProducesValidJson(): void
    {
        $snippet = $this->buildSnippet('https://example.com', 'k');
        $snippetData = json_decode($snippet, true);

        $existingConfig = [
            'preferences'          => ['theme' => 'dark'],
            'coworkUserFilesPath'  => '/Users/user/Claude',
        ];

        $merged = array_merge($existingConfig, $snippetData);
        $mergedJson = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->assertNotFalse($mergedJson);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $decoded = json_decode((string) $mergedJson, true);
        $this->assertArrayHasKey('mcpServers', $decoded);
        $this->assertArrayHasKey('preferences', $decoded);
    }

    /**
     * Reproduces the exact bug the user hit: pasting the snippet as a sibling
     * JSON document after the closing brace of the existing config.
     * This MUST be invalid JSON — the test asserts the bad merge is detectable.
     */
    public function testBadMerge_concatenatedDocuments_isInvalidJson(): void
    {
        $existing = "{\n  \"preferences\": {}\n}";
        $snippet  = $this->buildSnippet('https://example.com', 'k');

        // Simulates what happens when a user appends / misplaces the snippet
        // so the file has two root-level JSON objects or a stray brace.
        $corrupted = $existing . "\n," . $snippet;

        json_decode($corrupted);
        $this->assertNotSame(
            JSON_ERROR_NONE,
            json_last_error(),
            'Concatenated JSON documents must not parse as valid — bad merge should be detectable'
        );
    }
}
