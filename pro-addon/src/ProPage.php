<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The "Pro" tab inside the free plugin's admin page: licence entry and the list of Pro tools. Both
 * handlers are POST-only admin-post actions that need `manage_options` and a valid nonce.
 */
final class ProPage
{
    public const TAB               = 'pro';
    public const MENU_SLUG         = 'ai-editor-divi5';
    public const ACTION_ACTIVATE   = 'aied_pro_activate';
    public const ACTION_DEACTIVATE = 'aied_pro_deactivate';

    private \Closure $terminate;

    public function __construct( private ToolRegistry $registry, ?\Closure $terminate = null )
    {
        $this->terminate = $terminate ?? static function (): void {
            exit;
        };
    }

    public function register(): void
    {
        add_filter( 'jhmg_aied_admin_tabs', [ $this, 'tabs' ] );
        add_action( 'jhmg_aied_render_admin_tab', [ $this, 'render' ] );
        add_action( 'admin_post_' . self::ACTION_ACTIVATE, [ $this, 'handleActivate' ] );
        add_action( 'admin_post_' . self::ACTION_DEACTIVATE, [ $this, 'handleDeactivate' ] );
    }

    /** `jhmg_aied_admin_tabs`: add the Pro tab (always, so a locked add-on can still take a licence key). */
    public function tabs( mixed $tabs ): mixed
    {
        if ( ! is_array( $tabs ) ) {
            return $tabs;
        }
        $tabs[ self::TAB ] = __( 'Pro', 'jhmg-ai-editor-for-divi-5-pro' );

        return $tabs;
    }

    /** Never more than the last four characters of the key. */
    public static function maskKey( string $key ): string
    {
        $len = strlen( $key );
        if ( $len <= 4 ) {
            return str_repeat( '*', $len );
        }

        return str_repeat( '*', $len - 4 ) . substr( $key, -4 );
    }

    /** `jhmg_aied_render_admin_tab` */
    public function render( string $tab ): void
    {
        if ( self::TAB !== $tab || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $status  = Licensing::status();
        $key     = Licensing::client()->get_key();
        $premium = Licensing::isPremium();
        $names   = $this->registry->names();
        $notices = [
            'activated'       => [ 'success', __( 'Licence activated. The Pro tools are unlocked.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'activate_failed' => [ 'error', __( 'The licence could not be activated. Check the key and try again.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'empty_key'       => [ 'error', __( 'Enter your licence key.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'deactivated'     => [ 'success', __( 'Licence deactivated on this site.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
        ];
        $notice = isset( $_GET['aied_pro_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['aied_pro_notice'] ) ) : '';
        ?>
        <div class="aied-card aied-pro">
            <h2><?php esc_html_e( 'Pro licence', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h2>
            <?php if ( isset( $notices[ $notice ] ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> inline"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
            <?php endif; ?>
            <p>
                <strong><?php echo esc_html( self::statusLabel( $status['reason'] ) ); ?></strong>
                <?php if ( null !== $status['expires'] ) : ?>
                    &middot; <?php echo esc_html( sprintf( /* translators: %s: date */ __( 'renews or expires %s', 'jhmg-ai-editor-for-divi-5-pro' ), gmdate( 'Y-m-d', $status['expires'] ) ) ); ?>
                <?php endif; ?>
            </p>
            <?php if ( null !== $key ) : ?>
                <p><?php esc_html_e( 'Licence key:', 'jhmg-ai-editor-for-divi-5-pro' ); ?> <code><?php echo esc_html( self::maskKey( $key ) ); ?></code></p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DEACTIVATE ); ?>">
                    <?php wp_nonce_field( self::ACTION_DEACTIVATE ); ?>
                    <button type="submit" class="button"><?php esc_html_e( 'Deactivate on this site', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                </form>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_ACTIVATE ); ?>">
                    <?php wp_nonce_field( self::ACTION_ACTIVATE ); ?>
                    <p><input type="text" name="license_key" class="regular-text" autocomplete="off" placeholder="JHMG-XXXX-XXXX-XXXX-XXXX"></p>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Activate licence', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <div class="aied-card aied-pro">
            <h2><?php esc_html_e( 'Pro tools', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h2>
            <?php if ( ! $premium ) : ?>
                <p><?php esc_html_e( 'Activate a licence to unlock the Pro tools.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            <?php elseif ( [] === $names ) : ?>
                <p><?php esc_html_e( 'No Pro tools are included in this version yet.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            <?php else : ?>
                <ul>
                    <?php foreach ( $names as $name ) : ?>
                        <li><code><?php echo esc_html( $name ); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handleActivate(): void
    {
        $this->guard( self::ACTION_ACTIVATE );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
        $key = isset( $_POST['license_key'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['license_key'] ) ) ) : '';
        if ( '' === $key ) {
            $this->redirect( 'empty_key' );

            return;
        }
        $res = Licensing::activate( $key );
        $this->redirect( $res['ok'] ? 'activated' : 'activate_failed' );
    }

    public function handleDeactivate(): void
    {
        $this->guard( self::ACTION_DEACTIVATE );
        Licensing::deactivate();
        $this->redirect( 'deactivated' );
    }

    private function guard( string $nonceAction ): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'jhmg-ai-editor-for-divi-5-pro' ) );
        }
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'jhmg-ai-editor-for-divi-5-pro' ) );
        }
        check_admin_referer( $nonceAction );
    }

    private function redirect( string $notice ): void
    {
        wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'tab' => self::TAB, 'aied_pro_notice' => $notice ], admin_url( 'admin.php' ) ) );
        ( $this->terminate )();
    }

    private static function statusLabel( string $reason ): string
    {
        return match ( $reason ) {
            'no_key'   => __( 'No licence activated', 'jhmg-ai-editor-for-divi-5-pro' ),
            'active'   => __( 'Active', 'jhmg-ai-editor-for-divi-5-pro' ),
            'expired'  => __( 'Expired (your Pro tools keep working; updates and support stop)', 'jhmg-ai-editor-for-divi-5-pro' ),
            'canceled' => __( 'Cancelled (your Pro tools keep working; updates and support stop)', 'jhmg-ai-editor-for-divi-5-pro' ),
            'revoked'  => __( 'Revoked (the Pro tools are locked)', 'jhmg-ai-editor-for-divi-5-pro' ),
            'invalid'  => __( 'Invalid (the Pro tools are locked)', 'jhmg-ai-editor-for-divi-5-pro' ),
            default    => __( 'Status unknown', 'jhmg-ai-editor-for-divi-5-pro' ),
        };
    }
}
