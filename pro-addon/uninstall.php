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
delete_metadata( 'user', 0, 'aied_pro_whats_new_dismissed', '', true );
// Options the companion features keep (explicit batch sessions, strict validation switch and its flagged list).
// The mega menu's own content (headlines, texts, buttons, pictures, hand-edit choices) and its out-of-date check.
foreach ( [ 'aied_pro_agent_sessions', 'aied_pro_strict_validation', 'aied_pro_strict_flags', 'aied_pro_megamenu_content' ] as $aied_pro_option ) {
    delete_option( $aied_pro_option );
}
AiEditorDivi5\Pro\Settings\PhpProposals::clear();
AiEditorDivi5\Pro\Batch\BatchTable::drop();

delete_transient( 'aied_pro_megamenu_drift' );

// Build locks are rows of the options table; a crashed request can leave one behind.
global $wpdb;
if ( isset( $wpdb ) && is_object( $wpdb ) ) {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'aied_pro_buildlock_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    // The mega menu's per-user messages and pending choices (short-lived transients, stored as options on most sites).
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_aied_pro_megamenu_' ) . '%', $wpdb->esc_like( '_transient_timeout_aied_pro_megamenu_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
