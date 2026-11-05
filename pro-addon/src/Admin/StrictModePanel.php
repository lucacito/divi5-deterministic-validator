<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Admin;

use AiEditorDivi5\Pro\Companion\StrictMode;
use AiEditorDivi5\Pro\ProPage;
use AiEditorDivi5\WP\HistorySources;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The Pro tab card for strict validation, and a notice anywhere in the admin while a page is flagged. */
final class StrictModePanel
{
    use AdminActions;

    public const ACTION_SAVE  = 'aied_pro_strict_save';
    public const ACTION_CLEAR = 'aied_pro_strict_clear';

    public function __construct( private \Closure $unlocked, ?\Closure $terminate = null )
    {
        $this->initTerminate( $terminate );
    }

    public function register(): void
    {
        add_action( 'jhmg_aied_render_admin_tab', [ $this, 'render' ] );
        add_action( 'admin_post_' . self::ACTION_SAVE, [ $this, 'handleSave' ] );
        add_action( 'admin_post_' . self::ACTION_CLEAR, [ $this, 'handleClear' ] );
        add_action( 'admin_notices', [ $this, 'notice' ] );
    }

    public function notice(): void
    {
        if ( ! ( $this->unlocked )() || ! current_user_can( 'edit_pages' ) || ! StrictMode::enabled() ) {
            return;
        }
        $n = count( StrictMode::flags() );
        if ( 0 === $n ) {
            return;
        }
        $url = add_query_arg( [ 'page' => ProPage::MENU_SLUG, 'tab' => ProPage::TAB ], admin_url( 'admin.php' ) ) . '#strict';
        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html( sprintf( /* translators: %d: number of pages */ _n( '%d Divi page saved by an AI tool did not pass the layout check.', '%d Divi pages saved by AI tools did not pass the layout check.', $n, 'jhmg-ai-editor-for-divi-5-pro' ), $n ) ),
            esc_url( $url ),
            esc_html__( 'See which', 'jhmg-ai-editor-for-divi-5-pro' )
        );
    }

    public function render( string $tab ): void
    {
        if ( ProPage::TAB !== $tab || ! current_user_can( 'edit_pages' ) || ! ( $this->unlocked )() ) {
            return;
        }
        $on        = StrictMode::enabled();
        $flags     = StrictMode::flags();
        $canManage = current_user_can( 'manage_options' );
        $notice    = isset( $_GET['aied_pro_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['aied_pro_notice'] ) ) : '';
        ?>
        <div class="aied-card aied-pro" id="strict">
            <h2><?php esc_html_e( 'Strict validation for AI writes', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h2>
            <?php if ( 'strict_saved' === $notice ) : ?>
                <div class="notice notice-success inline"><p><?php esc_html_e( 'Saved.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p></div>
            <?php elseif ( 'strict_cleared' === $notice ) : ?>
                <div class="notice notice-success inline"><p><?php esc_html_e( 'The list was cleared.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p></div>
            <?php endif; ?>
            <p><?php esc_html_e( 'When this is on, a Divi page saved by an AI tool (another plugin\'s ability such as Divi\'s upcoming MCP, an application password or the REST API) is checked by the layout checker right after it is saved. A page that fails is listed here. Nothing is blocked, changed or rolled back, and no page content is stored: you see the page, where the change came from and the violation codes. A later valid save clears the entry. Off by default.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            <?php if ( $canManage ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
                    <?php wp_nonce_field( self::ACTION_SAVE ); ?>
                    <label><input type="checkbox" name="strict" value="1"<?php checked( $on ); ?>> <?php esc_html_e( 'Check pages saved by AI tools and flag the ones that fail', 'jhmg-ai-editor-for-divi-5-pro' ); ?></label>
                    <button type="submit" class="button"><?php esc_html_e( 'Save', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                </form>
            <?php endif; ?>
            <?php if ( $on && [] === $flags ) : ?>
                <p><?php esc_html_e( 'No flagged pages.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            <?php elseif ( [] !== $flags ) : ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'Page', 'jhmg-ai-editor-for-divi-5-pro' ); ?></th>
                        <th><?php esc_html_e( 'Saved by', 'jhmg-ai-editor-for-divi-5-pro' ); ?></th>
                        <th><?php esc_html_e( 'Problems', 'jhmg-ai-editor-for-divi-5-pro' ); ?></th>
                        <th><?php esc_html_e( 'When (UTC)', 'jhmg-ai-editor-for-divi-5-pro' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $flags as $f ) : ?>
                        <tr>
                            <td><?php if ( current_user_can( 'edit_post', $f['post_id'] ) ) : ?><a href="<?php echo esc_url( (string) get_edit_post_link( $f['post_id'], 'raw' ) ); ?>"><?php echo esc_html( '' === $f['title'] ? '#' . $f['post_id'] : $f['title'] ); ?></a><?php else : ?><?php echo esc_html( '#' . $f['post_id'] ); ?><?php endif; ?></td>
                            <td><?php echo esc_html( HistorySources::describe( $f['source'], $f['label'] ) ); ?></td>
                            <td><code><?php echo esc_html( implode( ', ', $f['codes'] ) ); ?></code></td>
                            <td><?php echo esc_html( $f['at'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ( $canManage ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CLEAR ); ?>">
                        <?php wp_nonce_field( self::ACTION_CLEAR ); ?>
                        <button type="submit" class="button"><?php esc_html_e( 'Clear the list', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handleSave(): void
    {
        $this->guardPost( self::ACTION_SAVE, 'manage_options' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guardPost().
        StrictMode::set( isset( $_POST['strict'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['strict'] ) ) );
        $this->redirectToProTab( 'strict_saved' );
    }

    public function handleClear(): void
    {
        $this->guardPost( self::ACTION_CLEAR, 'manage_options' );
        StrictMode::clear();
        $this->redirectToProTab( 'strict_cleared' );
    }
}
