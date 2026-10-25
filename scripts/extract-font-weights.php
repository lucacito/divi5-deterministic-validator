<?php
// Usage: php scripts/extract-font-weights.php <path-to-Divi-zip> [output-file]
// Reads Divi/core/json-data/google-fonts.json and Divi/style.css from the theme zip (in memory, nothing is
// extracted to disk) and writes, for every font family the design languages use (wp-plugin/data/design-languages.json),
// its variants and, for a variable family, the wght axis range, to fixtures/shapes/font-weights.json (or the given
// output file). Dev tooling for tests only: the plugin never reads that file.
// Why not fonts-5.15.1.json: a variable family lists only regular and italic as variants in Divi's file, so only
// the axes say which weights it can render.
// Exit 0 = written; exit 2 = usage / read / decode error.

declare(strict_types=1);

const ENTRY_FONTS = 'Divi/core/json-data/google-fonts.json';
const ENTRY_STYLE = 'Divi/style.css';

if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "Usage: php scripts/extract-font-weights.php <path-to-Divi-zip> [output-file]\n");
    exit(2);
}
$zipPath = $argv[1];
$root    = dirname(__DIR__);
$out     = $argv[2] ?? $root . '/fixtures/shapes/font-weights.json';

$languages = json_decode((string) file_get_contents($root . '/wp-plugin/data/design-languages.json'), true);
if (!is_array($languages)) {
    fwrite(STDERR, "cannot read the design languages\n");
    exit(2);
}
$wanted = [];
foreach ($languages as $lang) {
    foreach ((array) ($lang['fonts'] ?? []) as $stack) {
        foreach (explode(',', (string) $stack) as $family) {
            $family = trim($family);
            if ($family !== '') {
                $wanted[$family] = true;
            }
        }
    }
}

$zip = new ZipArchive();
if (!is_file($zipPath) || $zip->open($zipPath) !== true) {
    fwrite(STDERR, "cannot open zip: $zipPath\n");
    exit(2);
}
$rawFonts = $zip->getFromName(ENTRY_FONTS);
$rawStyle = $zip->getFromName(ENTRY_STYLE);
$zip->close();
if (!is_string($rawFonts) || !is_string($rawStyle)) {
    fwrite(STDERR, "entry not found in zip: " . ENTRY_FONTS . " or " . ENTRY_STYLE . "\n");
    exit(2);
}
if (1 !== preg_match('/^Version:\s*(\S+)/m', $rawStyle, $m)) {
    fwrite(STDERR, "no Version header in " . ENTRY_STYLE . "\n");
    exit(2);
}
$version = $m[1];
$data = json_decode($rawFonts, true);
if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
    fwrite(STDERR, "unexpected google-fonts.json shape (no items list)\n");
    exit(2);
}

$families = [];
foreach ($data['items'] as $item) {
    if (!is_array($item) || !isset($item['family']) || !isset($wanted[$item['family']])) {
        continue;
    }
    $entry = ['variants' => array_values(array_map('strval', (array) ($item['variants'] ?? [])))];
    foreach ((array) ($item['axes'] ?? []) as $axis) {
        if (is_array($axis) && ($axis['tag'] ?? null) === 'wght') {
            $entry['axes'] = ['wght' => [(int) $axis['start'], (int) $axis['end']]];
        }
    }
    $families[$item['family']] = $entry;
}
$missing = array_diff(array_keys($wanted), array_keys($families));
if ($missing !== []) {
    fwrite(STDERR, 'families not in Divi\'s font file: ' . implode(', ', $missing) . "\n");
    exit(2);
}
ksort($families, SORT_STRING);

$doc = [
    'divi'         => $version,
    'source'       => ENTRY_FONTS . ' inside ' . basename($zipPath),
    'generated_by' => 'php scripts/extract-font-weights.php divi/Divi.zip',
    'families'     => $families,
];
file_put_contents($out, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
fwrite(STDOUT, count($families) . " font families written to $out\n");
