<?php
/**
 * Plugin Name:       AI Editor for Divi 5
 * Plugin URI:        https://divi5lab.com/plugins/divi-5-ai-editor
 * Description:       Let your AI assistant (Claude, ChatGPT, Cursor) read and edit Divi 5 pages with natural language — every change is validated before saving, so broken pages become impossible.
 * Version:           3.1.1
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      8.1
 * Author:            JHMG
 * Author URI:        https://divi5lab.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-editor-divi5
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('AI_EDITOR_DIVI5_VERSION', '3.0.0');
define('AI_EDITOR_DIVI5_MIN_PHP', '8.1');
define('AI_EDITOR_DIVI5_MIN_WP',  '6.0');
define('AI_EDITOR_DIVI5_FILE',    __FILE__);
define('AI_EDITOR_DIVI5_PRODUCT', 'ai-editor-divi5-pro');
// License/update server. Override in wp-config.php for dev:
//   define('AIED_API_BASE', 'http://host.docker.internal:3100');

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
// Licensing: WP-native update checks + periodic validation + notices
// ---------------------------------------------------------------

add_filter('pre_set_site_transient_update_plugins', static function ($transient) {
    return AiEditorDivi5\WP\Licensing::client()->inject_update($transient);
});

if (is_admin()) {
    (new AiEditorDivi5\WP\AdminPage())->register();
    add_action('admin_init', static function (): void {
        AiEditorDivi5\WP\Licensing::refresh(); // daily-cached validate (24h + 72h offline grace)
    });
    add_action('admin_notices', static function (): void {
        $has_key = AiEditorDivi5\WP\Licensing::client()->get_key() !== null;
        $screen  = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_own  = $screen && $screen->id === 'toplevel_page_ai-editor-divi5';
        if ($has_key || $on_own) {
            AiEditorDivi5\WP\Licensing::client()->status_notice();
        }
    });
}
