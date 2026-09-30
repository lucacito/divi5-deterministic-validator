#!/usr/bin/env php
<?php
// Usage: php scripts/promote-modules.php <proposals.json> <module-verification-X.Y.json>
// Writes src/VerifiedModules.php, mirrors it (and SchemaRules.php) into wp-plugin/validator/,
// and writes one fixture per promoted module/placement into fixtures/valid/.

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Divi5Validator\Tools\MarkupBuilder;
use Divi5Validator\Tools\Promoter;

[$proposalsFile, $evidenceFile] = [$argv[1] ?? '', $argv[2] ?? ''];
if (!is_file($proposalsFile) || !is_file($evidenceFile)) {
    fwrite(STDERR, "Usage: php scripts/promote-modules.php <proposals.json> <module-verification.json>\n");
    exit(2);
}

$proposals = json_decode((string) file_get_contents($proposalsFile), true, 512, JSON_THROW_ON_ERROR);
$evidence  = json_decode((string) file_get_contents($evidenceFile), true, 512, JSON_THROW_ON_ERROR);
$version   = $evidence['divi_version'] ?? null;

// Refuse uncalibrated or unversioned evidence before writing anything.
if (($evidence['calibrated'] ?? false) !== true || !is_string($version) || $version === '') {
    fwrite(STDERR, "Refusing to promote: evidence must have \"calibrated\": true and a non-empty string \"divi_version\".\n");
    exit(2);
}

$sets = Promoter::promote($proposals, $evidence['results']);
$root = dirname(__DIR__);

if (file_put_contents($root . '/src/VerifiedModules.php', Promoter::render($sets, $version)) === false) {
    fwrite(STDERR, "Failed to write src/VerifiedModules.php\n");
    exit(1);
}
foreach (['VerifiedModules.php', 'SchemaRules.php'] as $f) {
    if (!copy($root . '/src/' . $f, $root . '/wp-plugin/validator/' . $f)) {
        fwrite(STDERR, "Failed to mirror {$f} into wp-plugin/validator/\n");
        exit(1);
    }
}

// Clear previously generated fixtures so demoted modules do not linger.
foreach (glob($root . '/fixtures/valid/verified-*.json') ?: [] as $old) {
    unlink($old);
}
$written = 0;
foreach (['column' => $sets['columnChildren'], 'section' => $sets['sectionChildren']] as $placement => $modules) {
    foreach ($modules as $m) {
        $child   = $sets['children'][$m][0] ?? null;
        $slug    = str_replace('divi/', '', $m);
        $fixture = [
            'source'       => 'render-verified',
            'format'       => 'gutenberg-blocks',
            'divi_version' => $version,
            'post_title'   => "Verified {$slug} ({$placement})",
            'post_status'  => 'draft',
            'post_content' => MarkupBuilder::page($m, $placement, $child, $version),
        ];
        if (file_put_contents("{$root}/fixtures/valid/verified-{$slug}-{$placement}.json", json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
            fwrite(STDERR, "Failed to write fixture for {$m}\n");
            exit(1);
        }
        $written++;
    }
}

printf("Promoted: %d leaf, %d structural; column=%d section=%d; %d fixtures written.\n",
    count($sets['leaf']), count($sets['structural']), count($sets['columnChildren']), count($sets['sectionChildren']), $written);
