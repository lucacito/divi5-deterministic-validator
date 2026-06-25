<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

// Wrap in a closure to avoid polluting global scope with loader variables.
(function () {
    $validator_src = __DIR__ . '/../validator';
    foreach ( ['Violation', 'ValidationResult', 'SchemaRules', 'Block', 'ParseResult', 'BlockParser', 'Validator'] as $cls ) {
        require_once $validator_src . '/' . $cls . '.php';
    }
})();

// Load plugin classes
require_once __DIR__ . '/ApiKey.php';
require_once __DIR__ . '/UsageTracker.php';
require_once __DIR__ . '/RestController.php';
require_once __DIR__ . '/McpHandler.php';
require_once __DIR__ . '/OpenApiSpec.php';
require_once __DIR__ . '/AdminPage.php';
