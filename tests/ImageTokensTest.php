<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\ImageTokens;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';

class ImageTokensTest extends TestCase
{
    private function manifest(): array
    {
        return [
            'fallback' => 'square-slate-1',
            'images'   => [
                ['token' => 'hero-blue-1', 'file' => 'hero-blue-1.svg', 'ratio' => '16:9', 'width' => 1600, 'height' => 900, 'palette' => 'blue', 'role' => 'hero', 'alt' => 'a'],
                ['token' => 'square-slate-1', 'file' => 'square-slate-1.svg', 'ratio' => '1:1', 'width' => 800, 'height' => 800, 'palette' => 'slate', 'role' => 'square', 'alt' => 'b'],
            ],
        ];
    }

    public function testTokensResolveToFilesUnderTheBaseUrl(): void
    {
        $out = ImageTokens::resolve('<img src="{{aied:image:hero-blue-1}}">', 'https://s.example/wp-content/plugins/p/assets/images/', $this->manifest());
        $this->assertSame('<img src="https://s.example/wp-content/plugins/p/assets/images/hero-blue-1.svg">', $out);
    }

    public function testUnknownTokenFallsBackAndNeverLeaksBraces(): void
    {
        $out = ImageTokens::resolve('{{aied:image:does-not-exist}}', 'https://s.example/i', $this->manifest());
        $this->assertSame('https://s.example/i/square-slate-1.svg', $out);
        $this->assertStringNotContainsString('{{aied:', $out);
    }

    public function testOverrideWinsWhenItReturnsAUrl(): void
    {
        $out = ImageTokens::resolve('{{aied:image:hero-blue-1}} {{aied:image:square-slate-1}}', 'https://s.example/i', $this->manifest(),
            fn (?string $url, string $token): ?string => $token === 'hero-blue-1' ? 'https://cdn.addon.example/x.jpg' : $url);
        $this->assertSame('https://cdn.addon.example/x.jpg https://s.example/i/square-slate-1.svg', $out);
    }

    public function testTextWithoutTokensIsUntouched(): void
    {
        $this->assertSame('plain {text} {{other}}', ImageTokens::resolve('plain {text} {{other}}', 'https://s.example/i', $this->manifest()));
    }
}
