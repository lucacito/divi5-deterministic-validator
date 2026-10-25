#!/usr/bin/env php
<?php
// Usage: php scripts/promote-render-fatals.php [<render-fatals.json>]
// Reads the render-fatal sweep evidence (default fixtures/shapes/render-fatals.json, docs/render-fatal-sweep.md),
// writes src/VerifiedContentTypes.php and mirrors it into wp-plugin/validator/. Deterministic: the same evidence
// always gives the same bytes (tests/VerifiedContentTypesTest.php checks the committed file is fresh).

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Divi5Validator\Tools\RenderFatalPromoter;

$root     = dirname(__DIR__);
$evidence = $argv[1] ?? $root . '/' . RenderFatalPromoter::EVIDENCE;
if (!is_file($evidence)) {
    fwrite(STDERR, "Usage: php scripts/promote-render-fatals.php [<render-fatals.json>]\n");
    exit(2);
}

try {
    $sets = RenderFatalPromoter::promote(json_decode((string) file_get_contents($evidence), true, 512, JSON_THROW_ON_ERROR));
} catch (\Throwable $e) {
    fwrite(STDERR, 'Refusing to promote: ' . $e->getMessage() . "\n");
    exit(2);
}

if (file_put_contents($root . '/src/VerifiedContentTypes.php', RenderFatalPromoter::render($sets)) === false) {
    fwrite(STDERR, "Failed to write src/VerifiedContentTypes.php\n");
    exit(1);
}
if (!copy($root . '/src/VerifiedContentTypes.php', $root . '/wp-plugin/validator/VerifiedContentTypes.php')) {
    fwrite(STDERR, "Failed to mirror VerifiedContentTypes.php into wp-plugin/validator/\n");
    exit(1);
}

$count = fn (array $m): int => array_sum(array_map('count', $m));
printf("Promoted: %d object keys in %d modules, %d scalar keys in %d modules (Divi %s, %s).\n",
    $count($sets['object']), count($sets['object']), $count($sets['scalar']), count($sets['scalar']), $sets['version'], $sets['date']);
