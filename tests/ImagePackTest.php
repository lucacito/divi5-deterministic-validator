<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ImagePack;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';

class ImagePackTest extends TestCase
{
    private const DIR = __DIR__ . '/../wp-plugin/assets/images';

    public function testManifestListsExactlyTheFilesOnDisk(): void
    {
        $m     = ImagePack::manifest();
        $files = array_map('basename', glob(self::DIR . '/*.{svg,png,jpg,webp}', GLOB_BRACE) ?: []);
        $listed = array_column($m['images'], 'file');
        sort($files);
        sort($listed);
        $this->assertSame($files, $listed, 'every image file is in the manifest and vice versa');
        $this->assertGreaterThanOrEqual(40, count($listed));
    }

    public function testEveryEntryIsWellFormedAndTokensAreUnique(): void
    {
        $m = ImagePack::manifest();
        $roles = ['hero', 'section-bg', 'card', 'square', 'avatar', 'logo'];
        $tokens = [];
        foreach ($m['images'] as $i) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $i['token']);
            $this->assertContains($i['role'], $roles);
            $this->assertMatchesRegularExpression('/^\d+:\d+$/', $i['ratio']);
            $this->assertGreaterThan(0, $i['width']);
            $this->assertNotSame('', $i['alt']);
            $tokens[] = $i['token'];
        }
        $this->assertSame($tokens, array_values(array_unique($tokens)));
        $this->assertContains($m['fallback'], $tokens);
        foreach ($roles as $r) {
            $this->assertNotEmpty(array_filter($m['images'], fn ($i) => $i['role'] === $r), "role {$r} has images");
        }
    }

    public function testSizeBudgetsAndNoExternalReferencesInsideImages(): void
    {
        $total = 0;
        foreach (glob(self::DIR . '/*') ?: [] as $f) {
            if (basename($f) === 'manifest.json') {
                continue;
            }
            $size = filesize($f);
            $total += $size;
            $this->assertLessThanOrEqual(61440, $size, basename($f) . ' exceeds 60 KB');
            if (str_ends_with($f, '.svg')) {
                $svg = (string) file_get_contents($f);
                $this->assertStringNotContainsString('<script', $svg);
                $this->assertDoesNotMatchRegularExpression('#(href|src)="https?://#i', $svg, basename($f) . ' must not reference remote files');
                $this->assertDoesNotMatchRegularExpression('/on[a-z]+\s*=/i', $svg, basename($f) . ' must not contain event handlers');
            }
        }
        $this->assertLessThanOrEqual(1572864, $total, 'image pack must stay under 1.5 MB');
    }

    public function testCatalogMarkdownListsEveryToken(): void
    {
        $md = ImagePack::catalogMarkdown();
        foreach (ImagePack::manifest()['images'] as $i) {
            $this->assertStringContainsString('{{aied:image:' . $i['token'] . '}}', $md);
        }
    }

    public function testGeneratorIsDeterministic(): void
    {
        $tmp = sys_get_temp_dir() . '/aied-pack-' . bin2hex(random_bytes(4));
        mkdir($tmp);
        exec('php ' . escapeshellarg(dirname(__DIR__) . '/scripts/generate-image-pack.php') . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        foreach (glob(self::DIR . '/*') ?: [] as $f) {
            $this->assertFileEquals($f, $tmp . '/' . basename($f), basename($f) . ' differs from a fresh generator run');
        }
        array_map('unlink', glob($tmp . '/*') ?: []);
        rmdir($tmp);
    }
}
