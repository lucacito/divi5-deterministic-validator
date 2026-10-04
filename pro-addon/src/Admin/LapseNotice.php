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
    private \Closure $terminate;

    public function __construct( private $statusGetter, ?\Closure $terminate = null )
    {
        $this->terminate = $terminate ?? static function (): void {
            exit;
        };
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

        $dismissUrl = \add_query_arg(
            [ 'action' => self::ACTION_DISMISS, 'lapse_key' => $lapseKey, '_wpnonce' => \wp_create_nonce( self::NONCE_ACTION ) ],
            \admin_url( 'admin-post.php' )
        );
        printf(
            '<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a> &middot; <a href="%4$s">%5$s</a></p></div>',
            \esc_html__( 'Your licence has ended. Everything keeps working; updates and support stop.', 'jhmg-ai-editor-for-divi-5-pro' ),
            \esc_url( $this->manageUrl() ),
            \esc_html__( 'Renew', 'jhmg-ai-editor-for-divi-5-pro' ),
            \esc_url( $dismissUrl ),
            \esc_html__( 'Dismiss', 'jhmg-ai-editor-for-divi-5-pro' )
        );
    }

    /** `admin_post_aied_pro_dismiss_lapse_notice` (a GET link with a nonce): remember that this user dismissed this lapse. */
    public function handleDismiss(): void
    {
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_die( 'Insufficient permissions' );
        }

        \check_admin_referer( self::NONCE_ACTION );

        $lapse_key = isset( $_GET['lapse_key'] ) ? \sanitize_text_field( \wp_unslash( (string) $_GET['lapse_key'] ) ) : '';
        if ( '' === $lapse_key ) {
            \wp_die( 'Invalid request' );
        }

        $dismissed = \get_user_meta( \get_current_user_id(), self::USER_META_KEY, true );
        $dismissed = is_array( $dismissed ) ? $dismissed : [];
        $dismissed[ $lapse_key ] = 1;
        \update_user_meta( \get_current_user_id(), self::USER_META_KEY, $dismissed );

        \wp_safe_redirect( \admin_url( 'admin.php?page=ai-editor-divi5&tab=pro' ) );
        ( $this->terminate )();
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

        return 'https://divi5lab.com/account/licenses';
    }
}
