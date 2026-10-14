<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The tests load plugin classes through Composer, but the installed plugin loads them only through
 * wp-plugin/src/autoload.php. A class that is missing from that file passes every unit test and then
 * fatals on a real WordPress site, so each source file must be required there.
 */
final class PluginAutoloadCoversEverySourceFileTest extends TestCase
{
    private const SRC = __DIR__ . '/../wp-plugin/src';

    public function testEveryPluginSourceFileIsRequiredByTheAutoloader(): void
    {
        $autoload = (string) file_get_contents(self::SRC . '/autoload.php');
        $missing  = [];
        foreach ((array) glob(self::SRC . '/*.php') as $file) {
            $name = basename((string) $file);
            if ('autoload.php' === $name || 'index.php' === $name) {
                continue;
            }
            if (!str_contains($autoload, "'/" . $name . "'")) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, 'Add these files to wp-plugin/src/autoload.php: ' . implode(', ', $missing));
    }

    public function testNoRequiredFileIsMissingOnDisk(): void
    {
        $autoload = (string) file_get_contents(self::SRC . '/autoload.php');
        preg_match_all("~require_once __DIR__ \\. '/([A-Za-z0-9_]+\\.php)';~", $autoload, $m);
        $gone = [];
        foreach ($m[1] as $name) {
            if (!is_file(self::SRC . '/' . $name)) {
                $gone[] = $name;
            }
        }

        $this->assertSame([], $gone);
    }
}
