<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Admin;

use AiEditorDivi5\Pro\Batch\BatchLog;
use AiEditorDivi5\Pro\Batch\BatchUndo;
use AiEditorDivi5\Pro\ProPage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The "Recent Pro changes" card on the Pro tab, with an Undo button that follows the same changed-since rule as `undo_batch`. */
final class BatchesPanel
{
    use AdminActions;

    public const ACTION_UNDO = 'aied_pro_undo_batch';

    public function __construct( private \Closure $unlocked, private BatchLog $log, private BatchUndo $undo, ?\Closure $terminate = null )
    {
        $this->initTerminate( $terminate );
    }

    public function register(): void
    {
        add_action( 'jhmg_aied_render_admin_tab', [ $this, 'render' ] );
        add_action( 'admin_post_' . self::ACTION_UNDO, [ $this, 'handleUndo' ] );
    }

    public function render( string $tab ): void
    {
        if ( ProPage::TAB !== $tab || ! current_user_can( 'edit_pages' ) || ! ( $this->unlocked )() ) {
            return;
        }
        $all     = current_user_can( 'manage_options' );
        $me      = (int) get_current_user_id();
        $batches = [];
        foreach ( $this->log->recent( 10 ) as $batch ) {
            if ( $all || (int) $batch['user_id'] === $me ) {
                $batches[] = $batch;
            }
        }
        $notices = [
            'batch_undone'           => [ 'success', __( 'The batch was undone.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'batch_partly_undone'    => [ 'warning', __( 'Only part of that batch could be undone: a page or setting was changed afterwards, or its earlier version is no longer stored. Nothing that changed since was overwritten.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'batch_nothing_restored' => [ 'error', __( 'Nothing could be restored: what that batch changed has been changed since, or its earlier version is no longer stored. Nothing was overwritten.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'batch_not_found'        => [ 'error', __( 'That batch was not found.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
        ];
        $notice = isset( $_GET['aied_pro_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['aied_pro_notice'] ) ) : '';
        ?>
        <div class="aied-card aied-pro" id="batches">
            <h2><?php esc_html_e( 'Recent Pro changes', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h2>
            <?php if ( isset( $notices[ $notice ] ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> inline"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
            <?php endif; ?>
            <?php if ( [] === $batches ) : ?>
                <p><?php esc_html_e( 'No Pro changes yet.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr>
                        <th>#</th>
                        <th><?php esc_html_e( 'When (UTC)', 'jhmg-ai-editor-for-divi-5-pro' ); ?></th>
                        <th><?php esc_html_e( 'Change', 'jhmg-ai-editor-for-divi-5-pro' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'jhmg-ai-editor-for-divi-5-pro' ); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $batches as $batch ) : ?>
                        <?php $d = BatchLog::describe( $batch ); ?>
                        <tr>
                            <td><?php echo esc_html( (string) $d['id'] ); ?></td>
                            <td><?php echo esc_html( $d['created_at'] ); ?></td>
                            <td><code><?php echo esc_html( $d['tool'] ); ?></code> <?php echo esc_html( $d['summary'] ); ?></td>
                            <td><?php echo esc_html( self::statusLabel( $d['status'] ) ); ?></td>
                            <td>
                                <?php if ( $d['undoable'] ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_UNDO ); ?>">
                                        <input type="hidden" name="batch_id" value="<?php echo esc_attr( (string) $d['id'] ); ?>">
                                        <?php wp_nonce_field( self::ACTION_UNDO ); ?>
                                        <button type="submit" class="button"><?php esc_html_e( 'Undo', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handleUndo(): void
    {
        $this->guardPost( self::ACTION_UNDO, 'edit_pages' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guardPost().
        $raw = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['batch_id'] ) ) : '';
        $id  = ctype_digit( $raw ) ? (int) $raw : 0;

        $batch = $id > 0 ? $this->log->find( $id ) : null;
        if ( null === $batch || ( ! current_user_can( 'manage_options' ) && (int) $batch['user_id'] !== (int) get_current_user_id() ) ) {
            $this->redirectToProTab( 'batch_not_found' );

            return;
        }
        $result = $this->undo->undo( $id );
        $this->redirectToProTab( ! empty( $result['fully_undone'] ) ? 'batch_undone' : ( (int) ( $result['restored'] ?? 0 ) > 0 ? 'batch_partly_undone' : 'batch_nothing_restored' ) );
    }

    private static function statusLabel( string $status ): string
    {
        return match ( $status ) {
            'undone'        => __( 'undone', 'jhmg-ai-editor-for-divi-5-pro' ),
            'partly_undone' => __( 'partly undone', 'jhmg-ai-editor-for-divi-5-pro' ),
            default         => __( 'applied', 'jhmg-ai-editor-for-divi-5-pro' ),
        };
    }
}
