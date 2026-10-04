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

    public function testRendersAllSixPanels(): void
    {
        $html = $this->render();
        foreach (['claude', 'cursor', 'vscode', 'gemini', 'chatgpt', 'other'] as $id) {
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
        $this->assertSame(6, substr_count($html, 'aied-llm-panel'));
    }

    public function testTabsHaveAccessibleRolesAndState(): void
    {
        $html = $this->render();
        // Each tab is a real ARIA tab wired to its panel; exactly one is selected.
        foreach (['claude', 'cursor', 'vscode', 'gemini', 'chatgpt', 'other'] as $id) {
            $this->assertStringContainsString('id="aied-tab-' . $id . '"', $html);
            $this->assertStringContainsString('aria-controls="aied-panel-' . $id . '"', $html);
            $this->assertStringContainsString('aria-labelledby="aied-tab-' . $id . '"', $html);
        }
        $this->assertSame(6, substr_count($html, 'role="tab"'));
        $this->assertSame(1, substr_count($html, 'aria-selected="true"'));
        $this->assertSame(5, substr_count($html, 'aria-selected="false"'));
    }

    /** @return string The HTML of one panel (from its opening div to the next panel or the end). */
    private function panel(string $id): string
    {
        $html  = $this->render();
        $start = strpos($html, 'id="aied-panel-' . $id . '"');
        $this->assertNotFalse($start, "panel $id exists");
        $next = strpos($html, 'class="aied-llm-panel"', $start);
        return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
    }

    public function testClaudePanelExplainsNodeJsAndTheMacTroubleshooting(): void
    {
        $panel = $this->panel('claude');
        $this->assertStringContainsString('Node.js', $panel);
        $this->assertStringContainsString('nodejs.org', $panel);
        $this->assertStringContainsString('npx', $panel);
        $this->assertStringContainsString('ENOENT', $panel);
        $this->assertStringContainsString('which npx', $panel);
    }

    public function testNodeNoteIsOnlyOnTheClaudePanel(): void
    {
        foreach (['cursor', 'vscode', 'gemini', 'other'] as $id) {
            $this->assertStringNotContainsString('ENOENT', $this->panel($id), $id);
        }
    }

    public function testGeminiPanelShowsTheCommandWithoutTheJsonMergeWarning(): void
    {
        $panel = $this->panel('gemini');
        $this->assertStringContainsString('gemini mcp add --transport http', $panel);
        $this->assertStringContainsString('sk-test-abc123', $panel);
        $this->assertStringNotContainsString('inner', strtolower($panel), 'a command is not merged into a config file');
    }

    public function testLocalSiteNoteSaysWhichAssistantsCanReachALocalUrl(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('local site', strtolower($html));
        $this->assertStringContainsString('--allow-http', $html);
        $this->assertStringContainsString('Gemini CLI', $html);
        $this->assertSame(1, substr_count($html, 'aied-local-note'), 'one note, not one per panel');
    }
}
