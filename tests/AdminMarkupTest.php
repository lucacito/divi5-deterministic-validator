<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\AdminPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/AdminPage.php';

/** Admin markup hygiene a reviewer reads first: no inline handlers, no silenced escaping, no hosted-service wording. */
class AdminMarkupTest extends TestCase
{
    private function src(string $rel): string
    {
        return (string) file_get_contents(__DIR__ . '/../wp-plugin/' . $rel);
    }

    public function testNoInlineEventHandlersInAdminMarkup(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\son(click|submit|change|load)\s*=/i', $this->src('src/AdminPage.php'));
    }

    public function testConfirmsUseADataAttributeHandledByAdminJs(): void
    {
        $php = $this->src('src/AdminPage.php');
        $this->assertSame(2, substr_count($php, 'data-confirm="<?php echo esc_attr__('), 'Regenerate and Restore both ask first');
        $js = $this->src('assets/admin.js');
        $this->assertStringContainsString("addEventListener('submit'", $js);
        $this->assertStringContainsString("getAttribute('data-confirm')", $js);
        $this->assertStringContainsString('window.confirm(', $js);
        $this->assertStringContainsString('preventDefault()', $js);
    }

    public function testConnectStepsAreEscapedNotSilenced(): void
    {
        $php = $this->src('src/AdminPage.php');
        $this->assertStringContainsString("wp_kses( \$step, [ 'code' => [] ] )", $php);
        $this->assertStringNotContainsString('OutputNotEscaped', $php, 'no escaping check is silenced in the admin page');
    }

    public function testStepTextIsUnchangedAndOnlyCodeSpansSurvive(): void
    {
        $html = (function (): string {
            ob_start();
            ( new AdminPage() )->connectCard( AdminPage::connectClients('https://acme.example', 'sk-test-abc123') );
            return (string) ob_get_clean();
        })();
        $this->assertStringContainsString('<code>.cursor/mcp.json</code>', $html);
        $this->assertStringContainsString('<code>claude mcp add --transport http ai-editor-divi5 &lt;MCP-URL&gt; --header &quot;Authorization: Bearer &lt;KEY&gt;&quot;</code>', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function testNoHostedServiceWordingInTheAdminUi(): void
    {
        foreach (['src/AdminPage.php', 'assets/admin.css', 'assets/admin.js'] as $f) {
            $this->assertStringNotContainsStringIgnoringCase('saas', $this->src($f), $f);
        }
    }
}
