<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Admin;

use AiEditorDivi5\Pro\ProPage;
use AiEditorDivi5\Pro\Settings\PhpProposals;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The "Code proposals" card on the Pro tab: PHP the AI proposed, shown escaped, for a human to review and copy. */
final class ProposalsPanel
{
    use AdminActions;

    public const ACTION_DELETE = 'aied_pro_delete_proposal';

    public function __construct( private \Closure $unlocked, ?\Closure $terminate = null )
    {
        $this->initTerminate( $terminate );
    }

    public function register(): void
    {
        add_action( 'jhmg_aied_render_admin_tab', [ $this, 'render' ] );
        add_action( 'admin_post_' . self::ACTION_DELETE, [ $this, 'handleDelete' ] );
    }

    public function render( string $tab ): void
    {
        if ( ProPage::TAB !== $tab || ! current_user_can( 'manage_options' ) || ! ( $this->unlocked )() ) {
            return;
        }
        $proposals = PhpProposals::all();
        $notice    = isset( $_GET['aied_pro_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['aied_pro_notice'] ) ) : '';
        ?>
        <div class="aied-card aied-pro" id="proposals">
            <h2><?php esc_html_e( 'Code proposals', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h2>
            <?php if ( 'proposal_deleted' === $notice ) : ?>
                <div class="notice notice-success inline"><p><?php esc_html_e( 'Proposal deleted.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p></div>
            <?php endif; ?>
            <p><?php esc_html_e( 'PHP your AI assistant proposed. It is shown here only: it is never run, saved as a file or loaded by this plugin. Read it, and if you want it, copy it into a snippets plugin yourself.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            <?php if ( [] === $proposals ) : ?>
                <p><?php esc_html_e( 'No code proposals yet.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            <?php endif; ?>
            <?php foreach ( $proposals as $p ) : ?>
                <div class="aied-proposal">
                    <h3><?php echo esc_html( $p['title'] ); ?></h3>
                    <p><small><?php echo esc_html( sprintf( /* translators: 1: date, 2: user id */ __( 'Proposed %1$s by user #%2$d', 'jhmg-ai-editor-for-divi-5-pro' ), gmdate( 'Y-m-d H:i', (int) $p['created'] ) . ' UTC', (int) ( $p['user_id'] ?? 0 ) ) ); ?></small></p>
                    <?php if ( '' !== $p['description'] ) : ?>
                        <p style="white-space:pre-wrap"><?php echo esc_html( $p['description'] ); ?></p>
                    <?php endif; ?>
                    <pre><code><?php echo esc_html( $p['code'] ); ?></code></pre>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE ); ?>">
                        <input type="hidden" name="proposal_id" value="<?php echo esc_attr( $p['id'] ); ?>">
                        <?php wp_nonce_field( self::ACTION_DELETE ); ?>
                        <button type="submit" class="button"><?php esc_html_e( 'Delete proposal', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function handleDelete(): void
    {
        $this->guardPost( self::ACTION_DELETE, 'manage_options' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guardPost().
        $id = isset( $_POST['proposal_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['proposal_id'] ) ) : '';
        if ( '' !== $id ) {
            PhpProposals::delete( $id );
        }
        $this->redirectToProTab( 'proposal_deleted' );
    }
}
