<?php

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/src/Licensing.php';

// Local state only: uninstalling never calls the licence server.
AiEditorDivi5\Pro\Licensing::clear();
