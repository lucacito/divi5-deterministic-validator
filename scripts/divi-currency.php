<?php
/**
 * Writes docs/divi-currency-<version>.md: what changed between two unpacked Divi themes. Used by scripts/divi-currency.sh.
 * Usage: php scripts/divi-currency.php <old-theme-dir|-> <new-theme-dir> <out.md>
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Divi5Validator\Tools\DiviCurrency;

[$self, $old, $new, $out] = $argv + [null, null, null, null];
if (!$new || !$out || !is_dir($new)) {
    fwrite(STDERR, "Usage: php scripts/divi-currency.php <old-theme-dir|-> <new-theme-dir> <out.md>\n");
    exit(2);
}
$hasOld     = $old && '-' !== $old && is_dir($old);
$newVersion = DiviCurrency::version($new) ?? 'unknown';
$oldVersion = $hasOld ? DiviCurrency::version($old) : null;
$changelog  = DiviCurrency::changelogSince((string) @file_get_contents($new . '/changelog.txt'), $oldVersion);
$diff       = $hasOld ? DiviCurrency::diff($old, $new) : ['added' => [], 'removed' => [], 'changed' => []];
$newMods    = $hasOld ? array_values(array_diff(DiviCurrency::moduleNames($new), DiviCurrency::moduleNames($old))) : [];
$goneMods   = $hasOld ? array_values(array_diff(DiviCurrency::moduleNames($old), DiviCurrency::moduleNames($new))) : [];
file_put_contents($out, DiviCurrency::report($oldVersion, $newVersion, $diff, $changelog, $newMods, $goneMods));
echo "[divi-currency] wrote {$out} (Divi " . ($oldVersion ?? '?') . " -> {$newVersion}; new modules: " . (implode(', ', $newMods) ?: 'none') . ")\n";
