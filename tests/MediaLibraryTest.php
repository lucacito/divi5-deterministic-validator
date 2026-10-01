<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\MediaLibrary;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/MediaLibrary.php';

class MediaLibraryTest extends TestCase
{
    public function testPerPageIsClampedAndDefaulted(): void
    {
        $this->assertSame(20, MediaLibrary::perPage(null));
        $this->assertSame(20, MediaLibrary::perPage('abc'));
        $this->assertSame(1, MediaLibrary::perPage(0));
        $this->assertSame(1, MediaLibrary::perPage(-5));
        $this->assertSame(50, MediaLibrary::perPage(500));
        $this->assertSame(7, MediaLibrary::perPage('7'));
    }

    public function testPageIsAtLeastOne(): void
    {
        $this->assertSame(1, MediaLibrary::page(null));
        $this->assertSame(1, MediaLibrary::page(0));
        $this->assertSame(3, MediaLibrary::page('3'));
    }

    public function testOrientationRules(): void
    {
        $this->assertSame('landscape', MediaLibrary::orientation(1600, 900));
        $this->assertSame('portrait', MediaLibrary::orientation(900, 1600));
        $this->assertSame('square', MediaLibrary::orientation(800, 800));
        $this->assertSame('square', MediaLibrary::orientation(800, 820));   // within 5%
        $this->assertSame('square', MediaLibrary::orientation(0, 0));
        $this->assertSame('landscape', MediaLibrary::orientation(1000, 900));
    }

    public function testNormalizeAndMatchOrientation(): void
    {
        $this->assertSame('landscape', MediaLibrary::normalizeOrientation('LANDSCAPE'));
        $this->assertNull(MediaLibrary::normalizeOrientation('wide'));
        $this->assertNull(MediaLibrary::normalizeOrientation(null));
        $this->assertTrue(MediaLibrary::matchesOrientation('portrait', null));
        $this->assertTrue(MediaLibrary::matchesOrientation('portrait', 'portrait'));
        $this->assertFalse(MediaLibrary::matchesOrientation('portrait', 'landscape'));
    }

    public function testOnlyImageMimesAreAccepted(): void
    {
        $this->assertTrue(MediaLibrary::isImageMime('image/jpeg'));
        $this->assertTrue(MediaLibrary::isImageMime('image/svg+xml'));
        $this->assertFalse(MediaLibrary::isImageMime('application/pdf'));
        $this->assertFalse(MediaLibrary::isImageMime('video/mp4'));
        $this->assertFalse(MediaLibrary::isImageMime(''));
    }

    public function testFormatItemShapeAndSanitising(): void
    {
        $i = MediaLibrary::formatItem([
            'id' => '12', 'title' => ' Team <b>photo</b> ', 'alt' => "Our team\nat work", 'caption' => '<p>Hi</p>',
            'url' => 'https://s.example/wp-content/uploads/a.jpg', 'thumbnail_url' => 'https://s.example/wp-content/uploads/a-300x200.jpg',
            'width' => '1600', 'height' => '900', 'mime' => 'image/jpeg', 'filename' => 'a.jpg',
        ]);
        $this->assertSame(
            ['id', 'title', 'alt', 'caption', 'url', 'thumbnail_url', 'width', 'height', 'orientation', 'mime', 'filename'],
            array_keys($i)
        );
        $this->assertSame(12, $i['id']);
        $this->assertSame('Team photo', $i['title']);
        $this->assertSame('Our team at work', $i['alt']);
        $this->assertSame('Hi', $i['caption']);
        $this->assertSame('landscape', $i['orientation']);
        $this->assertSame(1600, $i['width']);
    }

    public function testFormatItemToleratesMissingKeys(): void
    {
        $i = MediaLibrary::formatItem(['id' => 3]);
        $this->assertSame(3, $i['id']);
        $this->assertSame('', $i['alt']);
        $this->assertSame(0, $i['width']);
        $this->assertSame('square', $i['orientation']);
    }
}
