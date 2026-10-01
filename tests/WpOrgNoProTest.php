<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

class WpOrgNoProTest extends TestCase
{
    private const FORBIDDEN = [
        'Licensing', 'LicenseClient', 'isPremium', 'MenuBuilder', 'CustomCss', 'PhpProposals',
        'set_custom_css', 'propose_php_snippet', 'set_front_page', 'set_primary_menu',
        'setFrontPage', 'setPrimaryMenu', 'pre_set_site_transient', 'update_plugins',
    ];

    public function testNoLicenceOrProCodeRemainsInThePlugin(): void
    {
        $root = dirname(__DIR__) . '/wp-plugin';
        $hits = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            // readme.txt is rewritten in a later task of the 4.0.0 plan; it is not part of this guard yet.
            if (!$f->isFile() || $f->getFilename() === 'readme.txt' || !in_array($f->getExtension(), ['php', 'js', 'json', 'txt', 'css'], true)) {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            foreach (self::FORBIDDEN as $needle) {
                if (stripos($src, $needle) !== false) {
                    $hits[] = substr($f->getPathname(), strlen($root) + 1) . " mentions {$needle}";
                }
            }
        }
        $this->assertSame([], $hits);
    }

    public function testCreatePageIsFree(): void
    {
        $mcp  = (string) file_get_contents(dirname(__DIR__) . '/wp-plugin/src/McpHandler.php');
        $rest = (string) file_get_contents(dirname(__DIR__) . '/wp-plugin/src/RestController.php');
        $this->assertStringNotContainsString('upgrade_url', $mcp);
        $this->assertStringNotContainsString('upgrade_url', $rest);
        $this->assertStringNotContainsString('PREMIUM', (string) file_get_contents(dirname(__DIR__) . '/wp-plugin/src/OpenApiSpec.php'));
    }

    public function testMcpExposesExactlyTheFreeTools(): void
    {
        $mcp = (string) file_get_contents(dirname(__DIR__) . '/wp-plugin/src/McpHandler.php');
        preg_match_all("/^\s+'name'\s+=> '([a-z_]+)',$/m", $mcp, $m);
        $tools = $m[1];
        sort($tools);
        $expected = [
            'create_page', 'edit_page_content', 'get_image_guide', 'get_landing_guide', 'get_page_history_entry',
            'get_page_layout', 'get_section_recipes', 'get_site_guide', 'get_style_guide', 'list_divi_pages',
            'list_page_history', 'restore_page_version', 'update_page_layout', 'validate_layout',
        ];
        $this->assertSame($expected, $tools);
        $this->assertCount(14, $tools);
    }
}
