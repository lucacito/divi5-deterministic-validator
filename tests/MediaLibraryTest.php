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

    public function testPageIsBoundedAndNeverOverflows(): void
    {
        foreach (['9223372036854775807', '461168601842738800', 1e20, 9.3e18, '1e400', INF, NAN, -INF] as $v) {
            $p = MediaLibrary::page($v);
            $this->assertGreaterThanOrEqual(1, $p);
            $this->assertLessThanOrEqual(MediaLibrary::SCAN_LIMIT, $p);
            $off = MediaLibrary::offset($p, MediaLibrary::perPage(20));
            $this->assertIsInt($off);
            $this->assertGreaterThanOrEqual(0, $off);
            $this->assertLessThanOrEqual(199 * 50, $off);
            $this->assertIsArray(array_slice([1, 2, 3], $off, 20)); // would TypeError on a float offset
        }
        $this->assertSame(200, MediaLibrary::page('9223372036854775807'));
        $this->assertSame(200, MediaLibrary::page(1e20));
        $this->assertSame(1, MediaLibrary::page('1e400'));
        $this->assertSame(1, MediaLibrary::page(INF));
        $this->assertSame(1, MediaLibrary::page(NAN));
        $this->assertSame(1, MediaLibrary::page(-7));
        $this->assertSame(1, MediaLibrary::page('0'));
        $this->assertSame(1, MediaLibrary::page([5]));
        $this->assertSame(1, MediaLibrary::page(null));
    }

    public function testPerPageNonFiniteAndOddInputs(): void
    {
        $this->assertSame(20, MediaLibrary::perPage('1e400'));
        $this->assertSame(20, MediaLibrary::perPage(INF));
        $this->assertSame(20, MediaLibrary::perPage(NAN));
        $this->assertSame(20, MediaLibrary::perPage([3]));
        $this->assertSame(50, MediaLibrary::perPage(1e20));
        $this->assertSame(50, MediaLibrary::perPage('9223372036854775807'));
        $this->assertSame(1, MediaLibrary::perPage(-3));
        $this->assertSame(1, MediaLibrary::perPage('0'));
    }

    public function testOffsetIsPlainArithmeticOnClampedValues(): void
    {
        $this->assertSame(0, MediaLibrary::offset(1, 20));
        $this->assertSame(40, MediaLibrary::offset(3, 20));
        $this->assertSame(199 * 50, MediaLibrary::offset(PHP_INT_MAX, PHP_INT_MAX));
        $this->assertSame(0, MediaLibrary::offset(-5, -5));
    }

    public function testTruncationFlag(): void
    {
        $this->assertFalse(MediaLibrary::isTruncated(199));
        $this->assertTrue(MediaLibrary::isTruncated(200));
        $this->assertTrue(MediaLibrary::isTruncated(250));
        $this->assertFalse(MediaLibrary::isTruncated(0));
        $this->assertTrue(MediaLibrary::isTruncated(5, 5));
    }

    public function testSearchTermIsTrimmedAndCapped(): void
    {
        $this->assertSame('team', MediaLibrary::searchTerm('  team '));
        $this->assertSame('', MediaLibrary::searchTerm(null));
        $this->assertSame('', MediaLibrary::searchTerm(['x']));
        $this->assertSame(200, strlen(MediaLibrary::searchTerm(str_repeat('a', 500))));
        // Multibyte char straddling the cap must not leave invalid UTF-8.
        $t = MediaLibrary::searchTerm(str_repeat('a', 199) . str_repeat("\u{00e9}", 10));
        $this->assertSame(1, preg_match('//u', $t));
        $this->assertLessThanOrEqual(200, strlen($t));
    }

    public function testFormatItemDropsScriptAndStyleContent(): void
    {
        $i = MediaLibrary::formatItem(['id' => 1, 'title' => '<script>alert(1)</script>Hi', 'alt' => '<style>p{}</style>Logo', 'caption' => '<b>x</b>']);
        $this->assertSame('Hi', $i['title']);
        $this->assertSame('Logo', $i['alt']);
        $this->assertSame('x', $i['caption']);
    }
}
