<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/**
 * WordPress.org forbids hotlinked third-party images. No source, data file or
 * readme in the plugin may mention an image host; images come from the Media
 * Library or the bundled pack.
 */
class WpOrgNoRemoteImagesTest extends TestCase
{
    private const HOSTS = ['picsum', 'pravatar', 'randomuser', 'placehold.co', 'loremflickr', 'unsplash', 'pexels', 'pixabay', 'keyless'];

    public function testNoImageHostIsMentionedAnywhereInThePlugin(): void
    {
        $root = dirname(__DIR__) . '/wp-plugin';
        $hits = [];
        $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || !in_array($f->getExtension(), ['php', 'js', 'json', 'txt', 'css', 'svg'], true)) {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            foreach (self::HOSTS as $needle) {
                if (stripos($src, $needle) !== false) {
                    $hits[] = substr($f->getPathname(), strlen($root) + 1) . " mentions {$needle}";
                }
            }
        }
        $this->assertSame([], $hits);
    }

    public function testWriteToolDescriptionsPointAtTheMediaLibraryAndThePack(): void
    {
        $mcp = (string) file_get_contents(dirname(__DIR__) . '/wp-plugin/src/McpHandler.php');
        $this->assertStringContainsString('list_media_images', $mcp);
        $this->assertStringContainsString('get_image_guide', $mcp);
        $this->assertSame(2, substr_count($mcp, '(or an image URL the owner supplied)'), 'create_page and update_page_layout must match the guide rule about owner-supplied images');
    }

    public function testSiteGuideDoesNotPromiseWiredNavigation(): void
    {
        $md = (string) file_get_contents(dirname(__DIR__) . '/wp-plugin/src/SiteGuide.php');
        $this->assertStringNotContainsString('plus wired navigation', $md);
        $this->assertStringContainsString('cross-links between pages (the owner adds the menu)', $md);
    }
}
