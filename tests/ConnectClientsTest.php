<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AdminPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/AdminPage.php';

/**
 * The Connect panel offers per-assistant setup. This locks the machine-readable
 * shape of each client's config (transport, snippet format, spec/guide URLs) so
 * a bad edit to one client's snippet can't silently ship. Copy/markup is covered
 * by ConnectCardRenderTest; this file is data only.
 */
class ConnectClientsTest extends TestCase
{
    private const SITE = 'https://acme.example';
    private const KEY  = 'sk-test-abc123';

    /** @return array<string,mixed> */
    private function clients(): array
    {
        return AdminPage::connectClients(self::SITE, self::KEY);
    }

    public function testHasAllSixClients(): void
    {
        $this->assertSame(
            ['claude', 'cursor', 'vscode', 'gemini', 'chatgpt', 'other'],
            array_keys($this->clients())
        );
    }

    public function testMcpClientsUseMcpServersShapeWithBearerKey(): void
    {
        foreach (['cursor', 'other'] as $id) {
            $c = $this->clients()[$id];
            $this->assertSame('mcp', $c['transport'], "$id transport");
            $data = json_decode((string) $c['snippet'], true);
            $this->assertIsArray($data, "$id snippet is valid JSON");
            $entry = $data['mcpServers']['ai-editor-divi5'] ?? null;
            $this->assertIsArray($entry, "$id has mcpServers.ai-editor-divi5");
            $this->assertSame(self::SITE . '/wp-json/ai-editor-divi5/v1/mcp', $entry['url']);
            $this->assertSame('Bearer ' . self::KEY, $entry['headers']['Authorization']);
        }
    }

    public function testClaudeSnippetIsTheDesktopBridgeThroughNpx(): void
    {
        // Claude Desktop's config runs local commands only, so a remote endpoint
        // goes through the mcp-remote helper, which needs Node.js (npx).
        $c = $this->clients()['claude'];
        $this->assertSame('mcp', $c['transport']);
        $data = json_decode((string) $c['snippet'], true);
        $this->assertIsArray($data, 'claude snippet is valid JSON');
        $entry = $data['mcpServers']['ai-editor-divi5'] ?? null;
        $this->assertIsArray($entry, 'claude has mcpServers.ai-editor-divi5');
        $this->assertSame('npx', $entry['command']);
        $this->assertContains('mcp-remote', $entry['args']);
        $this->assertContains(self::SITE . '/wp-json/ai-editor-divi5/v1/mcp', $entry['args']);
        $this->assertArrayNotHasKey('url', $entry, 'Desktop does not take a bare url');
        $this->assertSame('Bearer ' . self::KEY, $entry['env']['AIED_AUTH']);
        $this->assertContains('Authorization:${AIED_AUTH}', $entry['args']);
        $this->assertSame('json', $c['format']);
    }

    public function testGeminiUsesTheCliAddCommandWithBearerKey(): void
    {
        $c = $this->clients()['gemini'];
        $this->assertSame('mcp', $c['transport']);
        $this->assertSame('command', $c['format']);
        $this->assertSame(
            'gemini mcp add --transport http --header "Authorization: Bearer ' . self::KEY . '" ai-editor-divi5 '
                . self::SITE . '/wp-json/ai-editor-divi5/v1/mcp',
            $c['snippet']
        );
        $this->assertNull($c['specUrl']);
    }

    public function testOnlyJsonSnippetsAreMarkedJson(): void
    {
        foreach (['claude', 'cursor', 'vscode', 'other'] as $id) {
            $this->assertSame('json', $this->clients()[$id]['format'], $id);
        }
        $this->assertNull($this->clients()['chatgpt']['format']);
    }

    public function testVsCodeUsesServersTypeHttpShape(): void
    {
        $c = $this->clients()['vscode'];
        $this->assertSame('mcp', $c['transport']);
        $data = json_decode((string) $c['snippet'], true);
        $this->assertIsArray($data, 'vscode snippet is valid JSON');
        $entry = $data['servers']['ai-editor-divi5'] ?? null;
        $this->assertIsArray($entry, 'vscode uses top-level "servers"');
        $this->assertArrayNotHasKey('mcpServers', $data, 'vscode must NOT use mcpServers');
        $this->assertSame('http', $entry['type']);
        $this->assertSame(self::SITE . '/wp-json/ai-editor-divi5/v1/mcp', $entry['url']);
        $this->assertSame('Bearer ' . self::KEY, $entry['headers']['Authorization']);
    }

    public function testChatgptIsAConnectorWithTheMcpAddressAndKeepsTheLegacySpecUrl(): void
    {
        $c = $this->clients()['chatgpt'];
        $this->assertSame('connector', $c['transport']);
        $this->assertNull($c['snippet'], 'ChatGPT has no config snippet: it signs in with OAuth');
        $this->assertSame(self::SITE . '/wp-json/ai-editor-divi5/v1/mcp', $c['mcpUrl']);
        $this->assertSame(self::SITE . '/wp-json/ai-editor-divi5/v1/openapi.json', $c['specUrl'], 'the legacy Action spec stays available');
        $this->assertStringNotContainsString(self::KEY, json_encode($c), 'the API key is never part of the ChatGPT panel');
    }

    public function testEachClientLinksToItsGuide(): void
    {
        $c = $this->clients();
        $this->assertStringEndsWith('/guides/connect-claude-to-divi-5', (string) $c['claude']['guide']);
        $this->assertStringEndsWith('/guides/connect-cursor-to-divi-5', (string) $c['cursor']['guide']);
        $this->assertStringEndsWith('/guides/connect-cursor-to-divi-5', (string) $c['vscode']['guide']);
        $this->assertStringEndsWith('/guides/connect-chatgpt-to-divi-5', (string) $c['chatgpt']['guide']);
        $this->assertNull($c['other']['guide'], 'Other MCP client has no dedicated guide');
        $this->assertNull($c['gemini']['guide'], 'Gemini CLI has no dedicated guide yet');
    }

    public function testTrailingSlashOnSiteUrlIsNormalized(): void
    {
        $c = AdminPage::connectClients(self::SITE . '/', self::KEY);
        $data = json_decode((string) $c['cursor']['snippet'], true);
        $this->assertSame(
            self::SITE . '/wp-json/ai-editor-divi5/v1/mcp',
            $data['mcpServers']['ai-editor-divi5']['url']
        );
        $claude = json_decode((string) $c['claude']['snippet'], true);
        $this->assertContains(
            self::SITE . '/wp-json/ai-editor-divi5/v1/mcp',
            $claude['mcpServers']['ai-editor-divi5']['args']
        );
        $this->assertStringContainsString(' ' . self::SITE . '/wp-json/ai-editor-divi5/v1/mcp', (string) $c['gemini']['snippet']);
    }
}
