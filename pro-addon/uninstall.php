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
// Per-user dismissal of the lapse notice.
delete_metadata( 'user', 0, 'aied_pro_lapse_dismissed', '', true );
AiEditorDivi5\Pro\Settings\PhpProposals::clear();
AiEditorDivi5\Pro\Batch\BatchTable::drop();

// Build locks are rows of the options table; a crashed request can leave one behind.
global $wpdb;
if ( isset( $wpdb ) && is_object( $wpdb ) ) {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'aied_pro_buildlock_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
