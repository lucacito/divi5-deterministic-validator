<?php

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/src/Licensing.php';
require_once __DIR__ . '/src/Batch/BatchTable.php';
require_once __DIR__ . '/src/Settings/PhpProposals.php';

// Local state only: uninstalling never calls the licence server. It removes the add-on's own data and leaves
// the site's front page, menu, CSS and page statuses exactly as they are (undo those first if you want them back).
AiEditorDivi5\Pro\Licensing::clear();
AiEditorDivi5\Pro\Settings\PhpProposals::clear();
AiEditorDivi5\Pro\Batch\BatchTable::drop();
