<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The checks every admin-post handler on the Pro tab makes, and the redirect back to the tab. */
trait AdminActions
{
    private \Closure $terminate;

    private function initTerminate( ?\Closure $terminate ): void
    {
        $this->terminate = $terminate ?? static function (): void {
            exit;
        };
    }

    /** Capability, then POST-only, then the nonce. */
    private function guardPost( string $nonceAction, string $capability ): void
    {
        if ( ! current_user_can( $capability ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'jhmg-ai-editor-for-divi-5-pro' ) );
        }
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            wp_die( esc_html__( 'Invalid request.', 'jhmg-ai-editor-for-divi-5-pro' ) );
        }
        check_admin_referer( $nonceAction );
    }

    private function redirectToProTab( string $notice ): void
    {
        wp_safe_redirect( add_query_arg( [ 'page' => 'ai-editor-divi5', 'tab' => 'pro', 'aied_pro_notice' => $notice ], admin_url( 'admin.php' ) ) );
        ( $this->terminate )();
    }
}
