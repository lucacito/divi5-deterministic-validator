<?php
/**
 * Plugin Name:       JHMG AI Editor for Divi 5 Pro
 * Description:       Pro add-on for JHMG AI Editor for Divi 5: site-level control, Theme Builder templates and site-wide changes with one-step undo. Requires the free plugin.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            JHMG
 * Author URI:        https://divi5lab.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jhmg-ai-editor-for-divi-5-pro
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AIED_PRO_VERSION', '0.1.0' );
define( 'AIED_PRO_FILE', __FILE__ );
define( 'AIED_PRO_PRODUCT', 'ai-editor-divi5-pro' );
define( 'AIED_PRO_MIN_PHP', '8.1' );

if ( version_compare( PHP_VERSION, AIED_PRO_MIN_PHP, '<' ) ) {
    add_action( 'admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p><strong>JHMG AI Editor for Divi 5 Pro</strong> requires PHP %s or higher and has been disabled. Your server runs PHP %s.</p></div>',
            esc_html( AIED_PRO_MIN_PHP ),
            esc_html( PHP_VERSION )
        );
    } );

    return;
}

require_once __DIR__ . '/src/autoload.php';

add_action( 'plugins_loaded', [ AiEditorDivi5\Pro\Plugin::class, 'boot' ], 20 );
