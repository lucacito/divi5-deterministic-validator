<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AdminPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/AdminPage.php';

/**
 * Renders just the connect card and asserts the guidance a confused user needs:
 * per-client panels, "no Claude account" reassurance, merge warning, the
 * ChatGPT public-HTTPS caveat, guide links, and a no-JS-safe panel structure.
 */
class ConnectCardRenderTest extends TestCase
{
    private function render(): string
    {
        $clients = AdminPage::connectClients('https://acme.example', 'sk-test-abc123');
        ob_start();
        ( new AdminPage() )->connectCard($clients);
        return (string) ob_get_clean();
    }

    public function testRendersAllFivePanels(): void
    {
        $html = $this->render();
        foreach (['claude', 'cursor', 'vscode', 'chatgpt', 'other'] as $id) {
            $this->assertStringContainsString('id="aied-panel-' . $id . '"', $html);
            $this->assertStringContainsString('data-target="' . $id . '"', $html);
        }
    }

    public function testReassuresNoClaudeAccountNeeded(): void
    {
        $this->assertStringContainsString('account', strtolower($this->render()));
        $this->assertStringContainsString('open standard', strtolower($this->render()));
    }

    public function testMcpPanelsShowMergeWarning(): void
    {
        // The documented real bug: pasting the whole snippet into an existing config.
        $this->assertStringContainsString('only the inner', strtolower($this->render()));
    }

    public function testChatgptPanelExplainsActionsAndHttps(): void
    {
        $html = strtolower($this->render());
        $this->assertStringContainsString('actions', $html);
        $this->assertStringContainsString('https', $html);
    }

    public function testLinksToEachGuide(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('/guides/connect-claude-to-divi-5', $html);
        $this->assertStringContainsString('/guides/connect-cursor-to-divi-5', $html);
        $this->assertStringContainsString('/guides/connect-chatgpt-to-divi-5', $html);
    }

    public function testPanelsAreNotServerHiddenForNoJsFallback(): void
    {
        // JS hides inactive panels on load; server output must not pre-hide them.
        $html = $this->render();
        $this->assertStringNotContainsString('aied-llm-panel" hidden', $html);
        $this->assertSame(5, substr_count($html, 'aied-llm-panel'));
    }

    public function testTabsHaveAccessibleRolesAndState(): void
    {
        $html = $this->render();
        // Each tab is a real ARIA tab wired to its panel; exactly one is selected.
        foreach (['claude', 'cursor', 'vscode', 'chatgpt', 'other'] as $id) {
            $this->assertStringContainsString('id="aied-tab-' . $id . '"', $html);
            $this->assertStringContainsString('aria-controls="aied-panel-' . $id . '"', $html);
            $this->assertStringContainsString('aria-labelledby="aied-tab-' . $id . '"', $html);
        }
        $this->assertSame(5, substr_count($html, 'role="tab"'));
        $this->assertSame(1, substr_count($html, 'aria-selected="true"'));
        $this->assertSame(4, substr_count($html, 'aria-selected="false"'));
    }
}
