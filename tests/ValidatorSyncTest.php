<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/** CLAUDE.md: src/ is canonical and wp-plugin/validator/ must mirror it. */
class ValidatorSyncTest extends TestCase
{
    public function testBundledValidatorMirrorsSrc(): void
    {
        $root = dirname(__DIR__);
        $files = glob($root . '/src/*.php') ?: [];
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $copy = $root . '/wp-plugin/validator/' . basename($file);
            $this->assertFileExists($copy, basename($file) . ' is missing from wp-plugin/validator/');
            $this->assertSame(file_get_contents($file), file_get_contents($copy), basename($file) . ' differs from wp-plugin/validator/');
        }
    }
}
