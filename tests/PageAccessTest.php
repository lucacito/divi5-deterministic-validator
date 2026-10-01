<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\PageAccess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/PageAccess.php';

/**
 * Authorisation of the API key user and of list_divi_pages (WordPress.org review, fix round 1).
 */
class PageAccessTest extends TestCase
{
    private function src(string $file): string
    {
        return (string) file_get_contents(__DIR__ . '/../wp-plugin/src/' . $file);
    }

    /** Body of one method, from its signature to the next method. */
    private function method(string $file, string $signature): string
    {
        $src   = $this->src($file);
        $start = strpos($src, $signature);
        $this->assertNotFalse($start, "{$file}: {$signature} not found");
        $end = preg_match('/\n    (?:public|private|protected) function /', $src, $m, PREG_OFFSET_CAPTURE, (int) $start + strlen($signature))
            ? $m[0][1] : strlen($src);
        return substr($src, (int) $start, $end - (int) $start);
    }

    public function testKeyUserMustBeAbleToEditPages(): void
    {
        $asked = [];
        $this->assertTrue(PageAccess::keyUserAllowed(function (string $cap) use (&$asked) { $asked[] = $cap; return true; }));
        $this->assertFalse(PageAccess::keyUserAllowed(fn(string $cap) => false));
        $this->assertSame(['edit_pages'], $asked);
    }

    public function testKeyOwnerHintCondition(): void
    {
        $asked = [];
        $can = function (int $uid, string $cap) use (&$asked) { $asked[] = [$uid, $cap]; return $uid === 5; };
        $this->assertTrue(PageAccess::keyOwnerCanUse(5, $can));
        $this->assertFalse(PageAccess::keyOwnerCanUse(6, $can), 'a user without edit_pages');
        $this->assertFalse(PageAccess::keyOwnerCanUse(0, $can), 'owner 0 (WP-CLI activation) is unusable');
        $this->assertSame([[5, 'edit_pages'], [6, 'edit_pages']], $asked, 'user 0 is rejected without asking');
    }

    public function testSettingsShowsTheHintOnlyOnTheSettingsView(): void
    {
        $src = $this->src('AdminPage.php');
        $this->assertSame(1, substr_count($src, 'PageAccess::keyOwnerCanUse('), 'one check, in viewSettings');
        $body = $this->method('AdminPage.php', 'private function viewSettings(');
        $this->assertStringContainsString('PageAccess::keyOwnerCanUse(', $body);
        $this->assertStringContainsString('Press “Regenerate” to create a key owned by you.', $body);
        $this->assertStringContainsString('esc_html_e', $body);
    }

    public function testFilterEditableKeepsOnlyEditablePages(): void
    {
        $posts = [(object) ['ID' => 1], (object) ['ID' => 2], (object) ['ID' => 3], 'not-a-post', (object) ['no' => 'id']];
        $kept  = PageAccess::filterEditable($posts, fn(int $id) => $id !== 2);
        $this->assertSame([1, 3], array_map(fn($p) => $p->ID, $kept));
        $this->assertSame([], PageAccess::filterEditable($posts, fn(int $id) => false));
    }

    public function testStringArgRejectsNonStrings(): void
    {
        $this->assertSame('', PageAccess::stringArg([], 'post_content'));
        $this->assertSame('', PageAccess::stringArg(['post_content' => null], 'post_content'));
        $this->assertSame('<p>x</p>', PageAccess::stringArg(['post_content' => '<p>x</p>'], 'post_content'));
        foreach ([['a'], 12, 1.5, true, (object) []] as $bad) {
            $this->assertNull(PageAccess::stringArg(['post_content' => $bad], 'post_content'));
        }
    }

    public function testApiKeyPathsCheckTheKeyUsersCapability(): void
    {
        foreach ([['McpHandler.php', 'public function authenticate()'], ['RestController.php', 'public function require_edit_posts()']] as [$file, $sig]) {
            $body = $this->method($file, $sig);
            $this->assertStringContainsString('ApiKey::authenticateRequest()', $body);
            $this->assertStringContainsString("PageAccess::keyUserAllowed('current_user_can')", $body, "{$file}: an accepted key must still be checked for edit_pages");
            $this->assertDoesNotMatchRegularExpression('/authenticateRequest\(\)\)\s*\{\s*return true;/', $body, "{$file}: never return true straight after accepting a key");
        }
    }

    public function testListDiviPagesChecksCapabilityAndFiltersPerPage(): void
    {
        foreach ([['McpHandler.php', 'private function toolListPages('], ['RestController.php', 'public function list_pages(']] as [$file, $sig]) {
            $body = $this->method($file, $sig);
            $this->assertStringContainsString('current_user_can(PageAccess::CAP)', $body, "{$file}: list requires edit_pages up front");
            $this->assertStringContainsString('PageAccess::filterEditable(', $body, "{$file}: list filters per page");
            $this->assertStringContainsString("current_user_can('edit_post', \$pid)", $body);
            $this->assertLessThan(strpos($body, 'get_posts('), strpos($body, 'current_user_can(PageAccess::CAP)'), "{$file}: capability check comes before the query");
        }
        $rest = $this->method('RestController.php', 'public function list_pages(');
        $this->assertStringContainsString("(string) get_permalink(\$p)", $rest, 'link stays string-coerced');
        $this->assertStringContainsString("(string) get_edit_post_link(\$p->ID, 'raw')", $rest, 'edit_link stays string-coerced');
    }

    public function testPostContentMustBeAStringEverywhere(): void
    {
        foreach (['private function toolValidate(', 'private function toolUpdate(', 'private function toolCreatePage(', 'private function toolEditContent('] as $sig) {
            $body = $this->method('McpHandler.php', $sig);
            $this->assertStringContainsString('PageAccess::stringArg(', $body, "McpHandler {$sig} must not cast arguments to string");
            $this->assertDoesNotMatchRegularExpression("/\(string\)\s*\(\\\$args\['(post_content|title|find|replace|slug)'\]/", $body);
        }
        foreach (['public function validate(', 'public function update_page(', 'public function create_page(', 'public function edit_page('] as $sig) {
            $body = $this->method('RestController.php', $sig);
            $this->assertStringContainsString('PageAccess::stringArg(', $body, "RestController {$sig} must type-check its strings");
            $this->assertDoesNotMatchRegularExpression("/\(string\)\s*\\\$body\['(post_content|title|find|replace|slug)'\]/", $body);
        }
    }

    public function testNameAndMethodArgumentsAreTypeChecked(): void
    {
        $handle = $this->method('McpHandler.php', 'public function handle(');
        $this->assertStringContainsString("is_string(\$body['method'] ?? '')", $handle, 'JSON-RPC method must be a string');
        $this->assertDoesNotMatchRegularExpression("/\(string\)\s*\(\$body\['method'\]/", $handle);

        $call = $this->method('McpHandler.php', 'private function onToolsCall(');
        $this->assertStringContainsString("PageAccess::stringArg(\$params, 'name')", $call);
        $this->assertStringContainsString('-32602', $call);

        $recipes = $this->method('McpHandler.php', 'private function toolSectionRecipes(');
        $this->assertStringContainsString("PageAccess::stringArg(\$args, 'name')", $recipes);
        $this->assertStringContainsString('-32602', $recipes);

        $rest = $this->method('RestController.php', 'public function section_recipes(');
        $this->assertStringContainsString('PageAccess::stringArg(', $rest);
        $this->assertStringContainsString(', 400)', $rest);
        $this->assertDoesNotMatchRegularExpression("/\(string\)\s*\$request->get_param\('name'\)/", $rest);
    }
}
