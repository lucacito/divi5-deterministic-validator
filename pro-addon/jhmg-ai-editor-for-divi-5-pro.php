<?php
/**
 * Plugin Name:       JHMG AI Editor for Divi 5 Pro
 * Description:       Pro add-on for JHMG AI Editor for Divi 5: set the front page and menu, publish or schedule pages, add managed custom CSS, edit the live Divi header and footer, find and replace text across your pages in one undoable batch, audit pages for layout problems and broken links, build a whole site page by page and launch it in one undoable step, create and edit your global colors and number and text variables, file PHP snippets for human review, and undo each Pro change in one step. Requires the free plugin.
 * Version:           0.12.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            JHMG
 * Author URI:        https://divi5lab.com
 * Update URI:         https://divi5lab.com/plugins/divi-5-ai-editor
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jhmg-ai-editor-for-divi-5-pro
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'AIED_PRO_VERSION', '0.12.0' );
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

register_activation_hook( __FILE__, static function (): void {
    AiEditorDivi5\Pro\Batch\BatchTable::ensureReady( true );
} );

add_action( 'plugins_loaded', [ AiEditorDivi5\Pro\Plugin::class, 'boot' ], 20 );
