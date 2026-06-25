<?php
// Runs when the plugin is deleted from the WordPress admin.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';
AiEditorDivi5\WP\UsageTracker::dropTable();
AiEditorDivi5\WP\ApiKey::delete();
delete_option('ai_editor_divi5_db_version');
