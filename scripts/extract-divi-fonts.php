<?php
// Usage: php scripts/extract-divi-fonts.php <path-to-Divi-zip> [output-file]
// Reads Divi/core/json-data/google-fonts.json from the theme zip (in memory, nothing is extracted to disk)
// and writes the family names, sorted, one per line, to fixtures/shapes/google-fonts-names.txt (or the
// given output file). Dev tooling for tests only: the plugin never reads that file.
// Exit 0 = written; exit 2 = usage / read / decode error.

declare(strict_types=1);

const ENTRY = 'Divi/core/json-data/google-fonts.json';

if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "Usage: php scripts/extract-divi-fonts.php <path-to-Divi-zip> [output-file]\n");
    exit(2);
}
$zipPath = $argv[1];
$out     = $argv[2] ?? dirname(__DIR__) . '/fixtures/shapes/google-fonts-names.txt';

$zip = new ZipArchive();
if (!is_file($zipPath) || $zip->open($zipPath) !== true) {
    fwrite(STDERR, "cannot open zip: $zipPath\n");
    exit(2);
}
$raw = $zip->getFromName(ENTRY);
$zip->close();
if (!is_string($raw)) {
    fwrite(STDERR, "entry not found in zip: " . ENTRY . "\n");
    exit(2);
}
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
    fwrite(STDERR, "unexpected google-fonts.json shape (no items list)\n");
    exit(2);
}
$names = [];
foreach ($data['items'] as $item) {
    if (is_array($item) && isset($item['family']) && is_string($item['family']) && $item['family'] !== '') {
        $names[$item['family']] = true;
    }
}
$names = array_keys($names);
if ($names === []) {
    fwrite(STDERR, "no font families found\n");
    exit(2);
}
sort($names, SORT_STRING);
file_put_contents($out, implode("\n", $names) . "\n");
fwrite(STDOUT, count($names) . " font families written to $out\n");
