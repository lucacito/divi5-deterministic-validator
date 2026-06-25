<?php
/**
 * Plugin Name:       Divi 5 Validator
 * Plugin URI:        https://github.com/lucacito/divi5-deterministic-validator
 * Description:       Connect AI assistants (Claude, ChatGPT, Cursor) to your Divi 5 WordPress site safely. Validates every layout change before saving — broken pages become impossible.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            JHMG
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       divi5-validator
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('DIVI5_VALIDATOR_VERSION', '2.0.0');
define('DIVI5_VALIDATOR_MIN_PHP', '8.1');
define('DIVI5_VALIDATOR_MIN_WP',  '6.0');
define('DIVI5_VALIDATOR_FILE',    __FILE__);

// ---------------------------------------------------------------
// Activation — check requirements, create DB table, generate API key
// ---------------------------------------------------------------

register_activation_hook(__FILE__, function (): void {
    if (version_compare(PHP_VERSION, DIVI5_VALIDATOR_MIN_PHP, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(sprintf(
            'Divi 5 Validator requires PHP %s or higher. Your server is running PHP %s.',
            DIVI5_VALIDATOR_MIN_PHP,
            PHP_VERSION
        ));
    }

    if (version_compare(get_bloginfo('version'), DIVI5_VALIDATOR_MIN_WP, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(sprintf(
            'Divi 5 Validator requires WordPress %s or higher.',
            DIVI5_VALIDATOR_MIN_WP
        ));
    }

    require_once __DIR__ . '/src/autoload.php';

    Divi5Validator\WP\UsageTracker::createTable();

    // Generate API key on first activation (lazy otherwise)
    if (get_option('divi5_validator_api_key', '') === '') {
        Divi5Validator\WP\ApiKey::generate();
    }

    // Flush rewrite rules so new REST routes resolve
    flush_rewrite_rules();
});

// ---------------------------------------------------------------
// Deactivation
// ---------------------------------------------------------------

register_deactivation_hook(__FILE__, function (): void {
    flush_rewrite_rules();
});

// ---------------------------------------------------------------
// Runtime requirements check (graceful degradation)
// ---------------------------------------------------------------

if (version_compare(PHP_VERSION, DIVI5_VALIDATOR_MIN_PHP, '<')) {
    add_action('admin_notices', function (): void {
        printf(
            '<div class="notice notice-error"><p><strong>Divi 5 Validator</strong> requires PHP %s or higher and has been disabled. Your server runs PHP %s.</p></div>',
            DIVI5_VALIDATOR_MIN_PHP,
            PHP_VERSION
        );
    });
    return;
}

// ---------------------------------------------------------------
// Load all plugin classes
// ---------------------------------------------------------------

require_once __DIR__ . '/src/autoload.php';

// ---------------------------------------------------------------
// Register REST routes
// ---------------------------------------------------------------

add_action('rest_api_init', function (): void {
    (new Divi5Validator\WP\RestController())->register_routes();
    (new Divi5Validator\WP\McpHandler())->register_routes();
    (new Divi5Validator\WP\OpenApiSpec())->register_routes();
});

// ---------------------------------------------------------------
// Admin page
// ---------------------------------------------------------------

if (is_admin()) {
    (new Divi5Validator\WP\AdminPage())->register();
}
