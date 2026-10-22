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
require_once __DIR__ . '/OAuthConfig.php';
require_once __DIR__ . '/OAuthStoreInterface.php';
require_once __DIR__ . '/OAuthStore.php';
require_once __DIR__ . '/OAuthTable.php';
require_once __DIR__ . '/OAuthRedirectPolicy.php';
require_once __DIR__ . '/OAuthSettings.php';
require_once __DIR__ . '/OAuthTokens.php';
require_once __DIR__ . '/OAuthClients.php';
require_once __DIR__ . '/OAuthMetadata.php';
require_once __DIR__ . '/OAuthWellKnown.php';
require_once __DIR__ . '/OAuthAuthorizeRequest.php';
require_once __DIR__ . '/OAuthConsentPage.php';
require_once __DIR__ . '/OAuthTokenEndpoint.php';
require_once __DIR__ . '/OAuthRuntime.php';
require_once __DIR__ . '/OAuthRoutes.php';
require_once __DIR__ . '/OAuthAuthorizeHandler.php';
require_once __DIR__ . '/OAuthAdmin.php';
require_once __DIR__ . '/StyleGuide.php';
require_once __DIR__ . '/ImagePack.php';
require_once __DIR__ . '/ImageTokens.php';
require_once __DIR__ . '/DesignLanguages.php';
require_once __DIR__ . '/StyleKits.php';
require_once __DIR__ . '/RecipeTokens.php';
require_once __DIR__ . '/KitResolver.php';
require_once __DIR__ . '/KitAdapters.php';
require_once __DIR__ . '/SectionRecipes.php';
require_once __DIR__ . '/SiteGuide.php';
require_once __DIR__ . '/LandingGuide.php';
require_once __DIR__ . '/ImageGuide.php';
require_once __DIR__ . '/UsageTracker.php';
require_once __DIR__ . '/ReviewPrompt.php';
require_once __DIR__ . '/DiviCompat.php';
require_once __DIR__ . '/GlobalStyles.php';
require_once __DIR__ . '/ComponentReader.php';
require_once __DIR__ . '/ComponentAdvisory.php';
require_once __DIR__ . '/ContentLoss.php';
require_once __DIR__ . '/DesignVariety.php';
require_once __DIR__ . '/ViolationReport.php';
require_once __DIR__ . '/PageOutline.php';
require_once __DIR__ . '/SiteStructure.php';
require_once __DIR__ . '/ApprovalMode.php';
require_once __DIR__ . '/CustomFields.php';
require_once __DIR__ . '/CustomFieldReader.php';
require_once __DIR__ . '/FieldTarget.php';
require_once __DIR__ . '/ProductReader.php';
require_once __DIR__ . '/ProductQuery.php';
require_once __DIR__ . '/ProductGateway.php';
require_once __DIR__ . '/WooProductGateway.php';
require_once __DIR__ . '/ProductService.php';
require_once __DIR__ . '/StyleReferences.php';
require_once __DIR__ . '/CodePolicy.php';
require_once __DIR__ . '/LayoutGate.php';
require_once __DIR__ . '/EditTarget.php';
require_once __DIR__ . '/PageEditor.php';
require_once __DIR__ . '/PageTitle.php';
require_once __DIR__ . '/TitleChange.php';
require_once __DIR__ . '/DraftPage.php';
require_once __DIR__ . '/HtmlSource.php';
require_once __DIR__ . '/HtmlProse.php';
require_once __DIR__ . '/HtmlMapper.php';
require_once __DIR__ . '/DiviMarkup.php';
require_once __DIR__ . '/HtmlImages.php';
require_once __DIR__ . '/HtmlFonts.php';
require_once __DIR__ . '/HtmlToDivi.php';
require_once __DIR__ . '/HtmlToDiviService.php';
require_once __DIR__ . '/LayoutDiff.php';
require_once __DIR__ . '/DryRun.php';
require_once __DIR__ . '/PageHistory.php';
require_once __DIR__ . '/HistoryTable.php';
require_once __DIR__ . '/HistoryMigration.php';
require_once __DIR__ . '/HistoryStore.php';
require_once __DIR__ . '/HistoryService.php';
require_once __DIR__ . '/ExtensionGuard.php';
require_once __DIR__ . '/PageAccess.php';
require_once __DIR__ . '/ConnectionDiagnosis.php';
require_once __DIR__ . '/MediaLibrary.php';
require_once __DIR__ . '/MediaService.php';
require_once __DIR__ . '/RestController.php';
require_once __DIR__ . '/McpHandler.php';
require_once __DIR__ . '/OpenApiSpec.php';
require_once __DIR__ . '/AdminPage.php';
