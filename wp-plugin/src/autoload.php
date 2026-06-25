<?php

declare(strict_types=1);

// Load the standalone validator library (mounted at /validator-src in the container)
$validatorSrc = '/validator-src';
foreach (['Violation', 'ValidationResult', 'SchemaRules', 'Block', 'ParseResult', 'BlockParser', 'Validator'] as $class) {
    require_once $validatorSrc . '/' . $class . '.php';
}

// Load plugin classes
require_once __DIR__ . '/RestController.php';
