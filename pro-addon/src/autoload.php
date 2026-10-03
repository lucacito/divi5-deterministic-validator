<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/Compat.php';
require_once __DIR__ . '/Licensing/LicenseClient.php';
require_once __DIR__ . '/Licensing.php';
require_once __DIR__ . '/ProTool.php';
require_once __DIR__ . '/ToolRegistry.php';
require_once __DIR__ . '/Tools.php';
require_once __DIR__ . '/ProPage.php';
require_once __DIR__ . '/Args.php';
require_once __DIR__ . '/Canon.php';
require_once __DIR__ . '/Batch/BatchRepository.php';
require_once __DIR__ . '/Batch/BatchTable.php';
require_once __DIR__ . '/Batch/WpdbBatchRepository.php';
require_once __DIR__ . '/Batch/BatchLog.php';
require_once __DIR__ . '/Batch/Item.php';
require_once __DIR__ . '/Batch/ItemKind.php';
require_once __DIR__ . '/Batch/ValueKind.php';
require_once __DIR__ . '/Batch/BatchUndo.php';
require_once __DIR__ . '/Kinds/FrontPageKind.php';
require_once __DIR__ . '/Settings/CustomCss.php';
require_once __DIR__ . '/Kinds/CssKind.php';
require_once __DIR__ . '/Settings/MenuBuilder.php';
require_once __DIR__ . '/Kinds/MenuKind.php';
require_once __DIR__ . '/Batch/Batches.php';
require_once __DIR__ . '/Tools/SetFrontPage.php';
require_once __DIR__ . '/Tools/SetCustomCss.php';
require_once __DIR__ . '/Tools/SetPrimaryMenu.php';
require_once __DIR__ . '/Plugin.php';
