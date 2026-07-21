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

    public function testHasAllFiveClients(): void
    {
        $this->assertSame(
            ['claude', 'cursor', 'vscode', 'chatgpt', 'other'],
            array_keys($this->clients())
        );
    }

    public function testMcpClientsUseMcpServersShapeWithBearerKey(): void
    {
        foreach (['claude', 'cursor', 'other'] as $id) {
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

    public function testChatgptUsesActionsWithSpecUrlAndNoSnippet(): void
    {
        $c = $this->clients()['chatgpt'];
        $this->assertSame('actions', $c['transport']);
        $this->assertNull($c['snippet'], 'ChatGPT has no MCP snippet');
        $this->assertSame(self::SITE . '/wp-json/ai-editor-divi5/v1/openapi.json', $c['specUrl']);
    }

    public function testEachClientLinksToItsGuide(): void
    {
        $c = $this->clients();
        $this->assertStringEndsWith('/guides/connect-claude-to-divi-5', (string) $c['claude']['guide']);
        $this->assertStringEndsWith('/guides/connect-cursor-to-divi-5', (string) $c['cursor']['guide']);
        $this->assertStringEndsWith('/guides/connect-cursor-to-divi-5', (string) $c['vscode']['guide']);
        $this->assertStringEndsWith('/guides/connect-chatgpt-to-divi-5', (string) $c['chatgpt']['guide']);
        $this->assertNull($c['other']['guide'], 'Other MCP client has no dedicated guide');
    }

    public function testTrailingSlashOnSiteUrlIsNormalized(): void
    {
        $c = AdminPage::connectClients(self::SITE . '/', self::KEY);
        $data = json_decode((string) $c['claude']['snippet'], true);
        $this->assertSame(
            self::SITE . '/wp-json/ai-editor-divi5/v1/mcp',
            $data['mcpServers']['ai-editor-divi5']['url']
        );
    }
}
