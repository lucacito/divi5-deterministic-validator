<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Single dismissible notice for expired, canceled, or past_due licences.
 * The notice shows once per lapse (identified by status|expires), can be dismissed per-user,
 * and appears again for a different lapse.
 */
final class LapseNotice
{
    private const LAPSED_STATUSES = [ 'expired', 'canceled', 'past_due' ];
    private const USER_META_KEY   = 'aied_pro_lapse_dismissed';
    private const NONCE_ACTION    = 'aied_pro_dismiss_lapse_notice';
    private const ACTION_DISMISS   = 'aied_pro_dismiss_lapse_notice';

    /**
     * @param callable():array{valid:bool,status:?string,expires:?int,reason:string} $statusGetter
     */
    public function __construct( private $statusGetter )
    {
    }

    public function register(): void
    {
        if ( ! function_exists( 'add_action' ) ) {
            return;
        }

        add_action( 'admin_notices', [ $this, 'display' ] );
        add_action( 'admin_post_' . self::ACTION_DISMISS, [ $this, 'handleDismiss' ] );
    }

    /** Display the notice if the licence has lapsed and the user hasn't dismissed this particular lapse. */
    public function display(): void
    {
        if ( ! \is_admin() || ! \current_user_can( 'manage_options' ) ) {
            return;
        }

        $status_info = ( $this->statusGetter )();
        $status      = $status_info['status'] ?? null;

        if ( null === $status || ! in_array( $status, self::LAPSED_STATUSES, true ) ) {
            return;
        }

        $lapseKey = $this->lapseKey( $status, $status_info['expires'] ?? null );
        if ( $this->isDismissed( $lapseKey ) ) {
            return;
        }

        $manage_url = $this->manageUrl();
        $notice_text = __( 'Your licence has ended. Everything keeps working; updates and support stop. ', 'jhmg-ai-editor-for-divi-5-pro' );
        $renew_text  = __( 'Renew', 'jhmg-ai-editor-for-divi-5-pro' );
        $nonce_html  = \wp_nonce_field( self::NONCE_ACTION, '_wpnonce', true, false );
        $html        = sprintf(
            '<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p>%s<input type="hidden" name="action" value="%s"><input type="hidden" name="lapse_key" value="%s"></div>',
            \esc_html( $notice_text ),
            \esc_url( $manage_url ),
            \esc_html( $renew_text ),
            $nonce_html,
            \esc_attr( self::ACTION_DISMISS ),
            \esc_attr( $lapseKey )
        );

        echo $html;
    }

    public function handleDismiss(): void
    {
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_die( 'Insufficient permissions' );
        }

        \check_admin_referer( self::NONCE_ACTION );

        $lapse_key = isset( $_POST['lapse_key'] ) ? \sanitize_text_field( \wp_unslash( (string) $_POST['lapse_key'] ) ) : '';
        if ( empty( $lapse_key ) ) {
            \wp_die( 'Invalid request' );
        }

        $dismissed = (array) \get_user_meta( \get_current_user_id(), self::USER_META_KEY, true );
        $dismissed[ $lapse_key ] = 1;
        \update_user_meta( \get_current_user_id(), self::USER_META_KEY, $dismissed );

        \wp_safe_redirect( \admin_url( 'admin.php?page=ai-editor-divi5&tab=pro' ) );
    }

    /** Generate the key that identifies this lapse (status|expires). */
    private function lapseKey( string $status, ?int $expires ): string
    {
        return $status . '|' . (int) ( $expires ?? 0 );
    }

    /** Check if this particular lapse has been dismissed by the current user. */
    private function isDismissed( string $lapseKey ): bool
    {
        $dismissed = (array) \get_user_meta( \get_current_user_id(), self::USER_META_KEY, true );

        return isset( $dismissed[ $lapseKey ] );
    }

    /** Get the manage/renew URL. */
    private function manageUrl(): string
    {
        // If available, use the stored extras
        $extras = (array) \get_option( 'aied_pro_license_extras', [] );
        if ( ! empty( $extras['manage_url'] ) ) {
            return (string) $extras['manage_url'];
        }

        // Fallback to the default
        if ( \class_exists( 'AiEditorDivi5\Pro\Licensing' ) ) {
            return \AiEditorDivi5\Pro\Licensing::UPGRADE_URL . '/account/licenses';
        }

        return 'https://divi5lab.com/account/licenses';
    }
}
