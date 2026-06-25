<?php
/**
 * Plugin Name:       Divi 5 Deterministic Validator
 * Plugin URI:        https://github.com/lucacito/divi5-deterministic-validator
 * Description:       REST API endpoints for validating and safely updating Divi 5 layouts. Prevents AI agents and automations from saving broken Divi layouts.
 * Version:           1.1.0
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

define('DIVI5_VALIDATOR_VERSION', '1.1.0');
define('DIVI5_VALIDATOR_MIN_PHP', '8.1');
define('DIVI5_VALIDATOR_MIN_WP', '6.0');
define('DIVI5_VALIDATOR_FILE', __FILE__);

// ---------------------------------------------------------------
// Activation — check requirements before anything loads
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
});

// ---------------------------------------------------------------
// Runtime requirements check (graceful degradation)
// ---------------------------------------------------------------

if (version_compare(PHP_VERSION, DIVI5_VALIDATOR_MIN_PHP, '<')) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p><strong>Divi 5 Validator</strong> requires PHP '
            . DIVI5_VALIDATOR_MIN_PHP . ' or higher and has been disabled.</p></div>';
    });
    return;
}

// ---------------------------------------------------------------
// Load validator + register REST routes
// ---------------------------------------------------------------

require_once __DIR__ . '/src/autoload.php';

add_action('rest_api_init', function (): void {
    $ctrl = new Divi5Validator\WP\RestController();
    $ctrl->register_routes();
});
