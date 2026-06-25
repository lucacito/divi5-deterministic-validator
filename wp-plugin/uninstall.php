<?php
// Runs when the plugin is deleted from the WordPress admin.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Drop the usage log table
require_once __DIR__ . '/src/autoload.php';
Divi5Validator\WP\UsageTracker::dropTable();

// Remove stored options
Divi5Validator\WP\ApiKey::delete();
delete_option('divi5_validator_db_version');
