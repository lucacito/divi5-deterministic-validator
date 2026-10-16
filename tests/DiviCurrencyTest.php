<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Tools\DiviCurrency;
use PHPUnit\Framework\TestCase;

/** `make divi-currency` reports what changed between two Divi themes; it decides nothing. */
class DiviCurrencyTest extends TestCase
{
    private const MODS = 'includes/builder-5/visual-builder/packages/module-library/src/components';
    private const SRV  = 'includes/builder-5/server';

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            exec('rm -rf ' . escapeshellarg($d));
        }
    }

    /** @param array<string,string> $files relative path => content */
    private function theme(string $version, array $files): string
    {
        $d = sys_get_temp_dir() . '/divi-currency-' . bin2hex(random_bytes(4));
        $this->dirs[] = $d;
        $files['style.css'] = "/*\nTheme Name: Divi\nVersion: {$version}\n*/";
        foreach ($files as $rel => $content) {
            @mkdir(dirname("{$d}/{$rel}"), 0777, true);
            file_put_contents("{$d}/{$rel}", $content);
        }

        return $d;
    }

    public function testItReadsTheVersion(): void
    {
        $this->assertSame('5.15.1', DiviCurrency::version($this->theme('5.15.1', [])));
        $this->assertNull(DiviCurrency::version('/nonexistent'));
    }

    public function testChangelogSinceListsOnlyTheNewerEntries(): void
    {
        $log = "version 5.15.1 ( updated 10-10-2026 )\n- Fixed a\n- Fixed b\n\nversion 5.15.0 ( updated 10-09-2026 )\n- Added c\n\nversion 5.14.0 ( updated 09-28-2026 )\n- Old\n";
        $e   = DiviCurrency::changelogSince($log, '5.14.0');
        $this->assertSame(['5.15.1', '5.15.0'], array_column($e, 'version'));
        $this->assertSame(['Fixed a', 'Fixed b'], $e[0]['lines']);
        $this->assertSame('10-10-2026', $e[0]['date']);
        $this->assertCount(3, DiviCurrency::changelogSince($log, null), 'no old version: everything');
        $this->assertCount(3, DiviCurrency::changelogSince($log, '9.9.9'), 'an old version that is not in the log: everything, not nothing');
        $this->assertSame([], DiviCurrency::changelogSince($log, '5.15.1'));
    }

    public function testNewRemovedAndChangedModulesAndServerFilesAreFound(): void
    {
        $old = $this->theme('5.14.0', [
            self::MODS . '/blurb/module.json'   => '{"a":1}',
            self::MODS . '/gone/module.json'    => '{}',
            self::MODS . '/same/module.json'    => '{"s":1}',
            self::SRV . '/Packages/GlobalData/GlobalData.php' => '<?php // old',
            self::SRV . '/Packages/Other/Other.php' => '<?php',
            'languages/de.mo' => 'old',
        ]);
        $new = $this->theme('5.15.1', [
            self::MODS . '/blurb/module.json'   => '{"a":2}',
            self::MODS . '/global-slot/module.json' => '{}',
            self::MODS . '/same/module.json'    => '{"s":1}',
            self::SRV . '/Packages/GlobalData/GlobalData.php' => '<?php // new',
            self::SRV . '/Packages/Other/Other.php' => '<?php',
            self::SRV . '/Packages/ModuleLibrary/GlobalSlot/GlobalSlotModule.php' => '<?php',
            'languages/de.mo' => 'new',
        ]);
        $this->assertSame(['global-slot'], array_values(array_diff(DiviCurrency::moduleNames($new), DiviCurrency::moduleNames($old))));
        $this->assertSame(['gone'], array_values(array_diff(DiviCurrency::moduleNames($old), DiviCurrency::moduleNames($new))));
        $d = DiviCurrency::diff($old, $new);
        $this->assertSame([self::SRV . '/Packages/GlobalData/GlobalData.php', self::MODS . '/blurb/module.json'], $d['changed'], 'translations and unchanged files are not reported');
        $this->assertContains(self::SRV . '/Packages/ModuleLibrary/GlobalSlot/GlobalSlotModule.php', $d['added']);
        $this->assertContains(self::MODS . '/gone/module.json', $d['removed']);
        $this->assertContains(self::MODS . '/global-slot/module.json', $d['added']);
    }

    public function testTheReportNamesTheNewModuleTheWatchedFilesAndTheChangelogAndPromisesNothing(): void
    {
        $diff = ['added' => [self::SRV . '/X/GlobalSlotModule.php'], 'removed' => [], 'changed' => [self::MODS . '/blurb/module.json', self::SRV . '/Packages/GlobalData/GlobalData.php']];
        $md   = DiviCurrency::report('5.14.0', '5.15.1', $diff, [['version' => '5.15.1', 'date' => '10-10-2026', 'lines' => ['Fixed a']]], ['global-slot'], []);
        $this->assertStringContainsString('# Divi 5.15.1: what changed since 5.14.0', $md);
        $this->assertStringContainsString('`divi/global-slot`', $md);
        $this->assertStringContainsString('`blurb`', $md);
        $this->assertStringContainsString('Packages/GlobalData/GlobalData.php', $md);
        $this->assertStringContainsString('- Fixed a', $md);
        $this->assertStringContainsString('never promotes a module', $md);
    }
}
