#!/usr/bin/env php
<?php
// Usage: php scripts/derive-schema.php <packages-dir> [--json=f] [--candidates=f] [--report]
// <packages-dir> is Divi/includes/builder-5/visual-builder/packages extracted from Divi.zip.

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Divi5Validator\SchemaRules;
use Divi5Validator\Tools\ModuleDeriver;
use Divi5Validator\Tools\SchemaGap;

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Usage: php scripts/derive-schema.php <packages-dir> [--json=f] [--candidates=f] [--report]\n");
    exit(2);
}
$opts = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

$defs = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getFilename() === 'module.json') {
        $decoded = json_decode((string) file_get_contents($file->getPathname()), true);
        $defs[substr($file->getPathname(), strlen($dir) + 1)] = $decoded;
    }
}
ksort($defs);

$proposals = ModuleDeriver::derive($defs);
$rules     = new SchemaRules();
$missing   = SchemaGap::missing($proposals, $rules);

// Candidates the harness should try: missing, placeable on their own (not child items),
// and of a category that lives in a column/section.
$candidates = array_values(array_filter(
    $missing,
    fn (array $p): bool => !$p['isChild'] && in_array($p['category'], ['module', 'fullwidth-module'], true)
));
// Children referenced by a candidate travel with it; keep their own proposals for the promoter.
$all = $proposals;

if (isset($opts['json']) && is_string($opts['json'])) {
    file_put_contents($opts['json'], json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
if (isset($opts['candidates']) && is_string($opts['candidates'])) {
    file_put_contents($opts['candidates'], json_encode($candidates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

if (isset($opts['report'])) {
    printf("Divi declares %d modules; validator is missing %d (%d placeable candidates).\n", count($proposals), count($missing), count($candidates));
    foreach ($missing as $p) {
        printf("  MISSING  %-46s %s%s\n", $p['name'], $p['category'] ?? '?', $p['isChild'] ? ' (child item)' : '');
    }
    $unmatched = SchemaGap::unmatched($proposals, $rules);
    printf("Known to the validator but with no module.json (wrappers / references): %s\n", $unmatched === [] ? 'none' : implode(', ', $unmatched));
}
