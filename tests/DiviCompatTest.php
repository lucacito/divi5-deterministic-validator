<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DiviCompat;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/DiviCompat.php';

class DiviCompatTest extends TestCase
{
    public function testNewerMinorVersionWarns(): void
    {
        $s = DiviCompat::status('5.15.0', '5.14');
        $this->assertSame('newer', $s['level']);
        $this->assertStringContainsString('5.15', $s['message']);
        $this->assertStringContainsString('5.14', $s['message']);
    }

    public function testPatchBumpWithinTestedMinorIsFine(): void
    {
        $this->assertSame('ok', DiviCompat::status('5.14.9', '5.14')['level']);
    }

    public function testOlderOrEqualIsFine(): void
    {
        $this->assertSame('ok', DiviCompat::status('5.8.0', '5.14')['level']);
        $this->assertSame('ok', DiviCompat::status('5.14.0', '5.14')['level']);
    }

    public function testNewerMajorWarns(): void
    {
        $this->assertSame('newer', DiviCompat::status('6.0.0', '5.14')['level']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unknownVersions')]
    public function testUnreadableVersionNeverWarns(?string $installed): void
    {
        $s = DiviCompat::status($installed, '5.14');
        $this->assertSame('unknown', $s['level']);
        $this->assertSame('', $s['message']);
    }

    public static function unknownVersions(): array
    {
        return [[null], [''], ['garbage'], ['v?']];
    }
}
