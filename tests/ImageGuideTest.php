<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ImageGuide;
use AiEditorDivi5\WP\ImagePack;
use AiEditorDivi5\WP\ImageTokens;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/ImageGuide.php';

/**
 * The image guide steers the AI to the site's Media Library first and the
 * plugin's bundled image pack second. It must never teach a third-party image
 * host, and must show REAL resolved URLs (the AI cannot use {{aied:}} tokens).
 */
class ImageGuideTest extends TestCase
{
    private const HOSTS = ['picsum', 'pravatar', 'randomuser', 'placehold.co', 'loremflickr', 'unsplash', 'pexels', 'pixabay'];

    public function testCataloguesEveryBundledImageWithItsRealUrl(): void
    {
        $md = ImageGuide::markdown();
        foreach (ImagePack::manifest()['images'] as $i) {
            $this->assertStringContainsString('`' . $i['token'] . '`', $md, "guide should list {$i['token']}");
            $this->assertStringContainsString('https://example.com/wp-content/plugins/jhmg-ai-editor-for-divi-5/assets/images/' . $i['file'], $md);
        }
        foreach (['Token', 'URL', 'Role', 'Ratio', 'Palette', 'What it shows'] as $col) {
            $this->assertStringContainsString($col, $md);
        }
    }

    public function testMediaLibraryComesFirstAndPackIsTheFallback(): void
    {
        $md = ImageGuide::markdown();
        $this->assertStringContainsString('list_media_images', $md);
        $lib  = strpos($md, 'list_media_images');
        $pack = strpos($md, 'built-in');
        $this->assertNotFalse($lib);
        $this->assertNotFalse($pack);
        $this->assertLessThan($pack, $lib, 'Media Library guidance must come before the built-in pack');
        $this->assertStringContainsStringIgnoringCase('Step 1', $md);
        $this->assertStringContainsStringIgnoringCase('Step 2', $md);
        $this->assertMatchesRegularExpression('/only when the (Media )?Library has no suitable/i', $md);
        $this->assertStringContainsString('orientation', $md);
    }

    public function testTeachesRoleBasedAssignmentAndTheRules(): void
    {
        $md = ImageGuide::markdown();
        foreach (['role', 'aspect ratio', 'alt', 'Hero', 'Testimonials', 'Team', 'avatar', 'never hotlink', 'never invent'] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $md, "image guide should cover $needle");
        }
    }

    public function testNoThirdPartyHostsAndNoUnresolvedTokens(): void
    {
        $md = ImageGuide::markdown();
        foreach (self::HOSTS as $host) {
            $this->assertStringNotContainsStringIgnoringCase($host, $md, "ImageGuide must not mention $host");
        }
        $this->assertFalse(ImageTokens::hasUnresolved($md), 'the AI cannot use {{aied:}} tokens, so none may appear');
    }

    public function testDoesNotReferenceRemovedOrPaidTools(): void
    {
        $md = ImageGuide::markdown();
        foreach (['set_front_page', 'set_primary_menu', 'set_custom_css', 'propose_php_snippet', 'find_image', 'premium', 'upgrade', 'license'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $md, "ImageGuide must not mention $needle");
        }
    }

    public function testImageGuideFilterReceivesAndReturnsMarkdown(): void
    {
        add_filter('jhmg_aied_image_guide', static fn (string $md): string => $md . "\n\n## Add-on sourcing\n", 10, 1);
        try {
            $this->assertStringEndsWith("## Add-on sourcing\n", ImageGuide::markdown());
        } finally {
            remove_all_filters('jhmg_aied_image_guide');
        }
        $this->assertStringNotContainsString('Add-on sourcing', ImageGuide::markdown());
    }
}
