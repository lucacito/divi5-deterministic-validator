<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\TestCase;

class WpOrgNamingTest extends TestCase
{
    private const SLUG = 'jhmg-ai-editor-for-divi-5';
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__) . '/wp-plugin';
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    public function testMainFileIsNamedAfterTheSlugAndOldOneIsGone(): void
    {
        $this->assertFileExists($this->root . '/' . self::SLUG . '.php');
        $this->assertFileDoesNotExist($this->root . '/ai-editor-divi5.php');
    }

    public function testHeaderIdentity(): void
    {
        $h = (string) file_get_contents($this->root . '/' . self::SLUG . '.php');
        $this->assertMatchesRegularExpression('/^\s*\*\s*Plugin Name:\s+JHMG AI Editor for Divi 5\s*$/m', $h);
        $this->assertMatchesRegularExpression('/^\s*\*\s*Text Domain:\s+' . preg_quote(self::SLUG, '/') . '\s*$/m', $h);
        $this->assertMatchesRegularExpression('/^\s*\*\s*Version:\s+4\.0\.0\s*$/m', $h);
        $this->assertStringContainsString("define('AI_EDITOR_DIVI5_VERSION', '4.0.0');", $h);
        $this->assertDoesNotMatchRegularExpression('/Tested up to/i', $h, 'Tested up to belongs only in readme.txt');
    }

    public function testEveryTranslationCallUsesTheSlugAsTextDomain(): void
    {
        $bad = [];
        foreach ($this->phpFiles() as $file) {
            if (str_contains($file, '/validator/')) {
                continue;
            }
            $src = (string) file_get_contents($file);
            // define(...) lines are constants, not i18n calls; skip them so their string arguments are not mistaken for a text domain.
            $src = (string) preg_replace('/^\s*define\(.*$/m', '', $src);
            // The text domain is the LAST string argument of an i18n call: ..., 'domain' )
            if (preg_match_all("/,\s*'([a-z0-9-]*editor[a-z0-9-]*)'\s*\)/", $src, $m)) {
                foreach ($m[1] as $domain) {
                    if ($domain !== self::SLUG) {
                        $bad[] = basename($file) . ": '{$domain}'";
                    }
                }
            }
        }
        $this->assertSame([], $bad, 'text domain must equal the slug everywhere');
    }

    public function testReadmeIdentity(): void
    {
        $r = (string) file_get_contents($this->root . '/readme.txt');
        $this->assertStringStartsWith('=== JHMG AI Editor for Divi 5 ===', $r);
        $this->assertMatchesRegularExpression('/^Contributors:\s+lucaslopvet\s*$/m', $r);
        $this->assertMatchesRegularExpression('/^Stable tag:\s+4\.0\.0\s*$/m', $r);
    }
}
