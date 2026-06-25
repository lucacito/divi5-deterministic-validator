#!/usr/bin/env php
<?php
// CLI entry point: php scripts/validate.php path/to/layout.json
// Exits 0 on valid, 1 on invalid, 2 on usage error.

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use Divi5Validator\Validator;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/validate.php <path-to-layout.json>\n");
    exit(2);
}

$file = $argv[1];

if (!file_exists($file)) {
    fwrite(STDERR, "Error: File not found: $file\n");
    exit(2);
}

$json = file_get_contents($file);
$validator = new Validator();
$result = $validator->validate($json);

if ($result->isValid()) {
    echo "PASS: $file\n";
    echo "  Layout is valid. No violations found.\n";
    exit(0);
} else {
    echo "FAIL: $file\n";
    echo "  " . count($result->violations()) . " violation(s):\n\n";
    foreach ($result->violations() as $v) {
        echo sprintf("  [%s] %s\n", $v->code(), $v->message());
        if ($v->path() !== '') {
            echo "        at: " . $v->path() . "\n";
        }
    }
    echo "\n";
    exit(1);
}
