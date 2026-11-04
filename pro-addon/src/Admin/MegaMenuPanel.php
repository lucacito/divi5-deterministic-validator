<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Admin;

use AiEditorDivi5\Pro\MegaMenu\Layouts;
use AiEditorDivi5\Pro\MegaMenu\MegaMenuService;
use AiEditorDivi5\Pro\MegaMenu\PreviewSvg;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The "Mega menu" tab: shows whether the site can take a mega menu, and lists the 13 layouts. "Use this layout" puts it on
 * the global header through MegaMenuService (the one write path, undoable from Recent Pro changes). No JavaScript.
 */
final class MegaMenuPanel
{
    use AdminActions;

    public const TAB           = 'megamenu';
    public const ACTION_APPLY  = 'aied_pro_megamenu_apply';
    public const ACTION_REMOVE = 'aied_pro_megamenu_remove';

    /** Short-lived, user-bound transients carrying the words of the last result (the layout name or an error message). */
    private const LAST_KEY  = 'aied_pro_megamenu_last_';
    private const ERROR_KEY = 'aied_pro_megamenu_error_';
    private const TTL       = 120;

    public function __construct( private \Closure $unlocked, private MegaMenuService $service, ?\Closure $terminate = null )
    {
        $this->initTerminate( $terminate );
    }

    public function register(): void
    {
        add_filter( 'jhmg_aied_admin_tabs', [ $this, 'addTab' ] );
        add_action( 'jhmg_aied_render_admin_tab', [ $this, 'render' ] );
        add_action( 'admin_post_' . self::ACTION_APPLY, [ $this, 'handleApply' ] );
        add_action( 'admin_post_' . self::ACTION_REMOVE, [ $this, 'handleRemove' ] );
    }

    /** `jhmg_aied_admin_tabs`: the tab exists only while the add-on is unlocked. */
    public function addTab( mixed $tabs ): mixed
    {
        if ( ! is_array( $tabs ) || ! ( $this->unlocked )() ) {
            return $tabs;
        }
        $tabs[ self::TAB ] = __( 'Mega menu', 'jhmg-ai-editor-for-divi-5-pro' );

        return $tabs;
    }

    public function render( string $tab ): void
    {
        if ( self::TAB !== $tab || ! ( $this->unlocked )() || ! current_user_can( 'edit_theme_options' ) ) {
            return;
        }
        $status  = $this->service->status();
        $header  = $status['header'];
        $problem = $this->problem( $status );
        $usable  = null === $problem;
        $notice  = isset( $_GET['aied_pro_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['aied_pro_notice'] ) ) : '';
        $me      = (int) get_current_user_id();
        $last    = (string) get_transient( self::LAST_KEY . $me );
        $error   = (string) get_transient( self::ERROR_KEY . $me );
        delete_transient( self::LAST_KEY . $me );
        delete_transient( self::ERROR_KEY . $me );

        $notices = [
            'megamenu_applied'    => [ 'success', sprintf( /* translators: %s: layout name */ __( 'The mega menu "%s" is on your header now. Check it on the site.', 'jhmg-ai-editor-for-divi-5-pro' ), $last ) ],
            'megamenu_removed'    => [ 'success', __( 'The mega menu was removed. Your header is back to its normal menu.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_unchanged'  => [ 'info', __( 'Nothing changed: the header already holds exactly that.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_bad_layout' => [ 'error', __( 'That layout does not exist. Choose one of the layouts below.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_error'      => [ 'error', '' !== $error ? $error : __( 'The mega menu could not be applied.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
        ];
        ?>
        <style>
            .aied-mm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
            .aied-mm-card { border: 1px solid #dcdcde; border-radius: 6px; padding: 12px; background: #fff; display: flex; flex-direction: column; gap: 6px; }
            .aied-mm-card h4 { margin: 4px 0 0; font-size: 14px; }
            .aied-mm-card p { margin: 0; color: #50575e; }
            .aied-mm-preview svg { display: block; width: 100%; height: auto; }
            .aied-mm-family { margin: 24px 0 8px; }
            .aied-mm-card form { margin-top: auto; padding-top: 6px; }
        </style>
        <div class="aied-card aied-pro" id="megamenu">
            <h2><?php esc_html_e( 'Mega menu', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h2>
            <?php if ( isset( $notices[ $notice ] ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> inline"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
            <?php endif; ?>

            <?php if ( null === $header ) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php esc_html_e( 'This site needs a global header before a mega menu can be used. Create one in the Divi Theme Builder: a header on the Default Website Template.', 'jhmg-ai-editor-for-divi-5-pro' ); ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=et_theme_builder' ) ); ?>"><?php esc_html_e( 'Open the Divi Theme Builder', 'jhmg-ai-editor-for-divi-5-pro' ); ?></a>
                    </p>
                </div>
            <?php elseif ( null !== $problem ) : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html( $problem ); ?></p></div>
            <?php endif; ?>

            <p>
                <strong><?php esc_html_e( 'Global header:', 'jhmg-ai-editor-for-divi-5-pro' ); ?></strong>
                <?php
                echo null === $header
                    ? esc_html__( 'none', 'jhmg-ai-editor-for-divi-5-pro' )
                    : esc_html( '' !== $header['title'] ? $header['title'] : __( 'Untitled header', 'jhmg-ai-editor-for-divi-5-pro' ) );
                ?>
                &middot;
                <strong><?php esc_html_e( 'Mega menu:', 'jhmg-ai-editor-for-divi-5-pro' ); ?></strong>
                <?php echo $status['applied'] ? esc_html__( 'applied', 'jhmg-ai-editor-for-divi-5-pro' ) : esc_html__( 'not applied', 'jhmg-ai-editor-for-divi-5-pro' ); ?>
            </p>
            <?php if ( null !== $status['menu'] ) : ?>
                <p>
                    <?php
                    echo esc_html( sprintf(
                        /* translators: 1: menu name, 2: number of top-level items, 3: number of items with sub-items, 4: where the menu was found */
                        __( 'Menu "%1$s": %2$d top-level items, %3$d with sub-items (%4$s).', 'jhmg-ai-editor-for-divi-5-pro' ),
                        $status['menu']['name'],
                        $status['menu']['items'],
                        $status['menu']['panels'],
                        $status['menu']['how']
                    ) );
                    ?>
                </p>
            <?php endif; ?>
            <p class="description"><?php esc_html_e( 'On desktop the mega menu replaces the normal menu. Tablet and phone keep the normal menu.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>

            <?php if ( $status['applied'] ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REMOVE ); ?>">
                    <?php wp_nonce_field( self::ACTION_REMOVE ); ?>
                    <button type="submit" class="button"><?php esc_html_e( 'Remove mega menu', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                </form>
            <?php endif; ?>

            <h3><?php esc_html_e( 'Choose a layout', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h3>
            <p class="description">
                <?php esc_html_e( '"Latest posts" layouts show a snapshot of the newest posts, taken when you apply them. Applying a layout replaces any earlier mega menu. You can undo it from Recent Pro changes on the Pro tab.', 'jhmg-ai-editor-for-divi-5-pro' ); ?>
            </p>

            <?php foreach ( Layouts::FAMILIES as $family => $heading ) : ?>
                <h4 class="aied-mm-family"><?php echo esc_html( $heading ); ?></h4>
                <div class="aied-mm-grid">
                    <?php foreach ( array_filter( Layouts::all(), static fn ( array $l ): bool => $l['family'] === $family ) as $layout ) : ?>
                        <div class="aied-mm-card">
                            <div class="aied-mm-preview"><?php echo PreviewSvg::for( $layout ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from constants, the name is escaped inside. ?></div>
                            <h4><?php echo esc_html( (string) $layout['name'] ); ?></h4>
                            <p><?php echo esc_html( (string) $layout['blurb'] ); ?></p>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_APPLY ); ?>">
                                <input type="hidden" name="layout" value="<?php echo esc_attr( (string) $layout['id'] ); ?>">
                                <?php wp_nonce_field( self::ACTION_APPLY ); ?>
                                <button type="submit" class="button button-primary"<?php echo $usable ? '' : ' disabled'; ?>><?php esc_html_e( 'Use this layout', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function handleApply(): void
    {
        $this->guardPost( self::ACTION_APPLY, 'edit_theme_options' );
        if ( ! ( $this->unlocked )() ) {
            $this->failWith( __( 'The mega menu needs an active Pro licence.', 'jhmg-ai-editor-for-divi-5-pro' ) );

            return;
        }
        $id = $this->postedLayout();
        if ( null === $id ) {
            $this->redirectToTab( self::TAB, 'megamenu_bad_layout' );

            return;
        }
        try {
            $result = $this->service->apply( $id, false );
        } catch ( \InvalidArgumentException | \RuntimeException $e ) {
            $this->failWith( $e->getMessage() );

            return;
        }
        if ( empty( $result['changed'] ) ) {
            $this->redirectToTab( self::TAB, 'megamenu_unchanged' );

            return;
        }
        $layout = Layouts::find( $id );
        set_transient( self::LAST_KEY . (int) get_current_user_id(), null === $layout ? $id : (string) $layout['name'], self::TTL );
        $this->redirectToTab( self::TAB, 'megamenu_applied' );
    }

    public function handleRemove(): void
    {
        $this->guardPost( self::ACTION_REMOVE, 'edit_theme_options' );
        if ( ! ( $this->unlocked )() ) {
            $this->failWith( __( 'The mega menu needs an active Pro licence.', 'jhmg-ai-editor-for-divi-5-pro' ) );

            return;
        }
        try {
            $result = $this->service->remove( false );
        } catch ( \InvalidArgumentException | \RuntimeException $e ) {
            $this->failWith( $e->getMessage() );

            return;
        }
        $this->redirectToTab( self::TAB, empty( $result['changed'] ) ? 'megamenu_unchanged' : 'megamenu_removed' );
    }

    /** The first reason the header cannot take a mega menu, or null when it can. @param array<string,mixed> $status */
    private function problem( array $status ): ?string
    {
        if ( null === $status['header'] ) {
            return MegaMenuService::NO_HEADER;
        }
        if ( empty( $status['menuModule'] ) ) {
            return __( 'The global header has no Menu module. Add one to the header in the Divi Theme Builder, then come back.', 'jhmg-ai-editor-for-divi-5-pro' );
        }
        if ( null === $status['menu'] ) {
            return MegaMenuService::NO_MENU;
        }
        if ( 0 === (int) $status['menu']['panels'] ) {
            return MegaMenuService::NO_SUBITEMS;
        }

        return null;
    }

    /** The posted layout id: a string, trimmed to a key, and one of the known layouts; anything else is null. */
    private function postedLayout(): ?string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guardPost().
        $raw = $_POST['layout'] ?? null;
        if ( ! is_string( $raw ) ) {
            return null;
        }
        $id = sanitize_key( wp_unslash( $raw ) );

        return in_array( $id, Layouts::ids(), true ) ? $id : null;
    }

    /** Remember an error message for the next render of this user's tab, then go back to it. */
    private function failWith( string $message ): void
    {
        set_transient( self::ERROR_KEY . (int) get_current_user_id(), $message, self::TTL );
        $this->redirectToTab( self::TAB, 'megamenu_error' );
    }
}
