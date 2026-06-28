<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ImageGuide;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImageGuide.php';

/**
 * The image guide tells the AI which source to use per section role. It must
 * teach the verified keyless sources, the per-section rules, stable pinning, and
 * must NOT resurrect the retired source.unsplash.com endpoint.
 */
class ImageGuideTest extends TestCase
{
    public function testCoversTheVerifiedSourceToolkit(): void
    {
        $md = ImageGuide::markdown();
        $this->assertNotEmpty($md);
        foreach ([
            'loremflickr.com',     // relevant real photos
            'picsum.photos',       // generic / fallback
            'randomuser.me',       // avatars
            'pravatar.cc',         // avatars (alt)
            'placehold.co',        // labeled placeholders
        ] as $source) {
            $this->assertStringContainsString($source, $md, "image guide should document $source");
        }
    }

    public function testTeachesRoleBasedAssignmentAndStability(): void
    {
        $md = ImageGuide::markdown();
        // Decide by role, derive keywords, pin images, size by ratio.
        foreach (['role', 'keyword', '?lock=', 'aspect ratio', 'fallback'] as $needle) {
            $this->assertStringContainsString($needle, $md, "image guide should cover $needle");
        }
        // Per-section coverage.
        foreach (['Hero', 'Testimonials', 'Team', 'avatar'] as $section) {
            $this->assertStringContainsString($section, $md, "image guide should give rules for $section");
        }
    }

    public function testDoesNotTeachTheRetiredUnsplashSourceEndpoint(): void
    {
        $md = ImageGuide::markdown();
        // source.unsplash.com returns 503 (retired) — teaching it would produce
        // broken images. It may only appear as an explicit "never use" warning.
        $this->assertStringNotContainsString('https://source.unsplash.com', $md);
    }
}
