<?php
// Usage: php scripts/check-evidence.php <old.json> <new.json>
// Refuses (exit 1) when <new.json> drops a module present in <old.json> or demotes one from
// `pass`. Exit 0 = nothing lost; exit 2 = usage / read / decode error.

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Divi5Validator\Tools\EvidenceGuard;

if (count($argv) !== 3) {
    fwrite(STDERR, "Usage: php scripts/check-evidence.php <old.json> <new.json>\n");
    exit(2);
}

$docs = [];
foreach ([1 => 'old', 2 => 'new'] as $i => $label) {
    $path = $argv[$i];
    $raw  = is_file($path) ? @file_get_contents($path) : false;
    if ($raw === false) {
        fwrite(STDERR, "evidence guard: cannot read $label evidence file: $path\n");
        exit(2);
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fwrite(STDERR, "evidence guard: $label evidence is not valid JSON ($path): " . $e->getMessage() . "\n");
        exit(2);
    }
    if (!is_array($decoded)) {
        fwrite(STDERR, "evidence guard: $label evidence is not a JSON object: $path\n");
        exit(2);
    }
    $docs[$label] = $decoded;
}

$lost = EvidenceGuard::lostModules($docs['old'], $docs['new']);
if ($lost['missing'] === [] && $lost['demoted'] === []) {
    echo "evidence guard: no modules lost or demoted\n";
    exit(0);
}

fwrite(STDERR, "evidence guard: REFUSING to overwrite committed evidence with a result set that loses proof.\n");
if ($lost['missing'] !== []) {
    fwrite(STDERR, sprintf("  missing from new evidence (%d): %s\n", count($lost['missing']), implode(', ', $lost['missing'])));
}
if ($lost['demoted'] !== []) {
    fwrite(STDERR, sprintf("  demoted from pass (%d): %s\n", count($lost['demoted']), implode(', ', $lost['demoted'])));
}
exit(1);
