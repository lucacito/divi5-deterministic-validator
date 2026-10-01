<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

// Wrap in a closure to avoid polluting global scope with loader variables.
(function () {
    $validator_src = __DIR__ . '/../validator';
    foreach ( ['Violation', 'ValidationResult', 'VerifiedModules', 'SchemaRules', 'Block', 'ParseResult', 'BlockParser', 'Validator'] as $cls ) {
        require_once $validator_src . '/' . $cls . '.php';
    }
})();

// Load plugin classes
require_once __DIR__ . '/ApiKey.php';
require_once __DIR__ . '/StyleGuide.php';
require_once __DIR__ . '/ImagePack.php';
require_once __DIR__ . '/ImageTokens.php';
require_once __DIR__ . '/SectionRecipes.php';
require_once __DIR__ . '/SiteGuide.php';
require_once __DIR__ . '/LandingGuide.php';
require_once __DIR__ . '/ImageGuide.php';
require_once __DIR__ . '/UsageTracker.php';
require_once __DIR__ . '/DiviCompat.php';
require_once __DIR__ . '/PageEditor.php';
require_once __DIR__ . '/PageHistory.php';
require_once __DIR__ . '/HistoryStore.php';
require_once __DIR__ . '/HistoryService.php';
require_once __DIR__ . '/ExtensionGuard.php';
require_once __DIR__ . '/PageAccess.php';
require_once __DIR__ . '/MediaLibrary.php';
require_once __DIR__ . '/MediaService.php';
require_once __DIR__ . '/RestController.php';
require_once __DIR__ . '/McpHandler.php';
require_once __DIR__ . '/OpenApiSpec.php';
require_once __DIR__ . '/AdminPage.php';
