<?php
// Runs when the plugin is deleted from the WordPress admin.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';
AiEditorDivi5\WP\UsageTracker::dropTable();
AiEditorDivi5\WP\ApiKey::delete();
AiEditorDivi5\WP\HistoryStore::deleteAll();
delete_option('ai_editor_divi5_db_version');
delete_metadata( 'user', 0, 'aied_pro_card_dismissed', '', true );
