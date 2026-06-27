<?php
/**
 * Plugin Name:       AI Editor for Divi 5
 * Plugin URI:        https://jhmediagroup.com/plugin/ai-editor-divi5
 * Description:       Let your AI assistant (Claude, ChatGPT, Cursor) read and edit Divi 5 pages with natural language — every change is validated before saving, so broken pages become impossible.
 * Version:           2.3.0
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      8.1
 * Author:            JHMG
 * Author URI:        https://jhmediagroup.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-editor-divi5
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('AI_EDITOR_DIVI5_VERSION', '2.3.0');
define('AI_EDITOR_DIVI5_MIN_PHP', '8.1');
define('AI_EDITOR_DIVI5_MIN_WP',  '6.0');
define('AI_EDITOR_DIVI5_FILE',    __FILE__);

// ---------------------------------------------------------------
// Activation — check requirements, create DB table, generate API key
// ---------------------------------------------------------------

register_activation_hook(__FILE__, function (): void {
    if (version_compare(PHP_VERSION, AI_EDITOR_DIVI5_MIN_PHP, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(esc_html(sprintf(
            'AI Editor for Divi 5 requires PHP %s or higher. Your server is running PHP %s.',
            AI_EDITOR_DIVI5_MIN_PHP,
            PHP_VERSION
        )));
    }

    if (version_compare(get_bloginfo('version'), AI_EDITOR_DIVI5_MIN_WP, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(esc_html(sprintf(
            'AI Editor for Divi 5 requires WordPress %s or higher.',
            AI_EDITOR_DIVI5_MIN_WP
        )));
    }

    require_once __DIR__ . '/src/autoload.php';

    AiEditorDivi5\WP\UsageTracker::createTable();

    if (get_option('ai_editor_divi5_api_key', '') === '') {
        AiEditorDivi5\WP\ApiKey::generate();
    }

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

if (version_compare(PHP_VERSION, AI_EDITOR_DIVI5_MIN_PHP, '<')) {
    add_action('admin_notices', function (): void {
        printf(
            '<div class="notice notice-error"><p><strong>AI Editor for Divi 5</strong> requires PHP %s or higher and has been disabled. Your server runs PHP %s.</p></div>',
            esc_html(AI_EDITOR_DIVI5_MIN_PHP),
            esc_html(PHP_VERSION)
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
    (new AiEditorDivi5\WP\RestController())->register_routes();
    (new AiEditorDivi5\WP\McpHandler())->register_routes();
    (new AiEditorDivi5\WP\OpenApiSpec())->register_routes();
});

// ---------------------------------------------------------------
// Admin page
// ---------------------------------------------------------------

if (is_admin()) {
    (new AiEditorDivi5\WP\AdminPage())->register();
}
