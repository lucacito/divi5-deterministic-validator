<?php

declare(strict_types=1);

// Load the standalone validator library (mounted at /validator-src in the container)
$validatorSrc = '/validator-src';
foreach (['Violation', 'ValidationResult', 'SchemaRules', 'Block', 'ParseResult', 'BlockParser', 'Validator'] as $class) {
    require_once $validatorSrc . '/' . $class . '.php';
}

// Load plugin classes
require_once __DIR__ . '/ApiKey.php';
require_once __DIR__ . '/UsageTracker.php';
require_once __DIR__ . '/RestController.php';
require_once __DIR__ . '/McpHandler.php';
require_once __DIR__ . '/OpenApiSpec.php';
require_once __DIR__ . '/AdminPage.php';
