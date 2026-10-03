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
require_once __DIR__ . '/Plugin.php';
