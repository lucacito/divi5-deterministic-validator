<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

class ProCardTest extends TestCase
{
    private function admin(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/wp-plugin/src/AdminPage.php');
    }

    public function testCardIsRenderedOnlyFromTheDashboardView(): void
    {
        $src = $this->admin();
        $this->assertSame(1, substr_count($src, '$this->proCard()'), 'proCard() must be called exactly once');
        $start = strpos($src, 'private function viewDashboard()');
        $this->assertNotFalse($start);
        $end   = strpos($src, 'private function ', (int) $start + 10);
        $body  = substr($src, (int) $start, ($end === false ? strlen($src) : $end) - (int) $start);
        $this->assertStringContainsString('$this->proCard()', $body, 'the card belongs to the Dashboard tab only');
    }

    public function testDismissIsPerUserNonceProtectedAndPermanent(): void
    {
        $src = $this->admin();
        $this->assertStringContainsString("admin_post_ai_editor_divi5_dismiss_pro_card", $src);
        $this->assertStringContainsString("guard('ai_editor_divi5_dismiss_pro_card')", $src);
        $this->assertStringContainsString("update_user_meta", $src);
        $this->assertStringContainsString("aied_pro_card_dismissed", $src);
        $this->assertStringContainsString("wp_nonce_field( 'ai_editor_divi5_dismiss_pro_card' )", $src);
    }

    public function testUninstallRemovesTheDismissalMeta(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/wp-plugin/uninstall.php');
        $this->assertStringContainsString("delete_metadata( 'user', 0, 'aied_pro_card_dismissed', '', true )", $src);
    }

    public function testCopyDoesNotImplyTheFreePluginIsLimited(): void
    {
        $src = $this->admin();
        $this->assertStringContainsString('Want live stock photos?', $src);
        $this->assertStringContainsString('JHMG AI Editor for Divi 5 includes a built-in image pack and reads your Media Library. The separate Pro add-on adds live photo sourcing for each section.', $src);
        // The card must not advertise remote-admin-style tools (WordPress.org review, fix round 1).
        foreach (['front page', 'menu setup', 'site tools', 'site-level'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $src, "AdminPage must not advertise “{$bad}”");
        }
        foreach (['unlock', 'locked', 'trial', 'limited', 'upgrade now', 'only in pro', 'included with pro', 'free version'] as $bad) {
            $this->assertStringNotContainsStringIgnoringCase($bad, $src, "AdminPage must not contain “{$bad}”");
        }
    }

    public function testNoOtherScreenOrToolMentionsThePro(): void
    {
        $root = dirname(__DIR__) . '/wp-plugin';
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (!$f->isFile() || str_contains($p, '/validator/') || str_ends_with($p, 'readme.txt') || str_ends_with($p, 'AdminPage.php')) {
                continue;
            }
            if (preg_match('/divi5lab\.com|\bPro add-on\b|\bPro version\b/i', (string) file_get_contents($p), $m)) {
                // the plugin header may carry the Plugin URI
                if (!str_ends_with($p, 'jhmg-ai-editor-for-divi-5.php')) {
                    $hits[] = substr($p, strlen($root) + 1) . " ({$m[0]})";
                }
            }
        }
        $this->assertSame([], $hits, 'the Pro add-on may be mentioned only in AdminPage.php (the one card), the readme and the header');
    }
}
