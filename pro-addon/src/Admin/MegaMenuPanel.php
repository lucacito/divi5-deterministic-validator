<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Admin;

use AiEditorDivi5\Pro\MegaMenu\EditedPanelsException;
use AiEditorDivi5\Pro\MegaMenu\Layouts;
use AiEditorDivi5\Pro\MegaMenu\MegaMenuContent;
use AiEditorDivi5\Pro\MegaMenu\MegaMenuService;
use AiEditorDivi5\Pro\MegaMenu\PreviewSvg;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The "Mega menu" tab. Before a mega menu is on it is a gallery of the 13 layouts ("Use for the whole menu"). Once it is on, the tab is
 * organised by menu item: the items with sub-items on the left, and one editor for the chosen item on the right with three tabs:
 *   Content  the owner's own headline, text, button and picture (MegaMenuContent), for the layouts that have a slot for them
 *   Layout   the layout of this item only, or of all items
 *   Builder  open the header in the Divi builder, and what an update does with a panel changed there
 * Every change goes through MegaMenuService (the one write path: LayoutGate, history snapshot, undoable batch, cache clear). A site-wide
 * WordPress notice says when the menu changed since the panels were built, with the button that brings them up to date.
 */
final class MegaMenuPanel
{
    use AdminActions;

    public const TAB                  = 'megamenu';
    public const ACTION_APPLY         = 'aied_pro_megamenu_apply';
    public const ACTION_REMOVE        = 'aied_pro_megamenu_remove';
    public const ACTION_UPDATE        = 'aied_pro_megamenu_update';
    public const ACTION_CONTENT       = 'aied_pro_megamenu_content';
    public const ACTION_CONTENT_RESET = 'aied_pro_megamenu_content_reset';
    public const ACTION_EDITS         = 'aied_pro_megamenu_edits';
    public const ACTION_RESOLVE       = 'aied_pro_megamenu_resolve';
    public const ACTION_MENU          = 'aied_pro_megamenu_menu';

    /** Short-lived, user-bound transients carrying the words of the last result (the layout name or an error message). */
    private const LAST_KEY    = 'aied_pro_megamenu_last_';
    private const ERROR_KEY   = 'aied_pro_megamenu_error_';
    private const ITEM_KEY    = 'aied_pro_megamenu_item_';
    private const PENDING_KEY = 'aied_pro_megamenu_pending_';
    private const TTL         = 120;
    private const PENDING_TTL = 900;

    /** The cached "does the header still match the menu" answer, site wide (the header is one for the whole site). */
    private const DRIFT_KEY = 'aied_pro_megamenu_drift';

    private const SCREEN = 'ai-editor-divi5';

    public function __construct( private \Closure $unlocked, private MegaMenuService $service, ?\Closure $terminate = null )
    {
        $this->initTerminate( $terminate );
    }

    public function register(): void
    {
        add_filter( 'jhmg_aied_admin_tabs', [ $this, 'addTab' ] );
        add_action( 'jhmg_aied_render_admin_tab', [ $this, 'render' ] );
        foreach ( [
            self::ACTION_APPLY => 'handleApply', self::ACTION_REMOVE => 'handleRemove', self::ACTION_UPDATE => 'handleUpdate', self::ACTION_CONTENT => 'handleContent',
            self::ACTION_CONTENT_RESET => 'handleContentReset', self::ACTION_EDITS => 'handleEdits', self::ACTION_RESOLVE => 'handleResolve', self::ACTION_MENU => 'handleMenu',
        ] as $action => $method ) {
            add_action( 'admin_post_' . $action, [ $this, $method ] );
        }
        add_action( 'admin_notices', [ $this, 'adminNotice' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueueMedia' ] );
        // Anything that can make the header and the menu differ (or agree again) throws the cached answer away.
        foreach ( [ 'wp_update_nav_menu', 'wp_update_nav_menu_item', 'wp_delete_nav_menu', 'save_post', 'deleted_post' ] as $tag ) {
            add_action( $tag, [ $this, 'forgetDrift' ], 10, 0 );
        }
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

    /** The Media Library window, only on this tab. */
    public function enqueueMedia(): void
    {
        if ( $this->onThisTab() && ( $this->unlocked )() && current_user_can( 'edit_theme_options' ) && function_exists( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }
    }

    // ---- the tab -----------------------------------------------------------------------------------------------------

    public function render( string $tab ): void
    {
        if ( self::TAB !== $tab || ! ( $this->unlocked )() || ! current_user_can( 'edit_theme_options' ) ) {
            return;
        }
        $status  = $this->service->status();
        $problem = $this->problem( $status );
        $usable  = null === $problem;
        $me      = (int) get_current_user_id();
        $notice  = isset( $_GET['aied_pro_notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['aied_pro_notice'] ) ) : '';
        $last    = (string) get_transient( self::LAST_KEY . $me );
        $error   = (string) get_transient( self::ERROR_KEY . $me );
        $named   = (string) get_transient( self::ITEM_KEY . $me );
        $pending = get_transient( self::PENDING_KEY . $me );
        foreach ( [ self::ITEM_KEY, self::LAST_KEY, self::ERROR_KEY ] as $key ) {
            delete_transient( $key . $me );
        }
        $notices = [
            'megamenu_applied'        => [ 'success', sprintf( /* translators: %s: layout name */ __( 'The mega menu "%s" is on your header now. Check it on the site.', 'jhmg-ai-editor-for-divi-5-pro' ), $last ) ],
            'megamenu_applied_item'   => [ 'success', sprintf( /* translators: 1: layout name, 2: menu item */ __( 'The layout "%1$s" is on "%2$s" now. The other items kept theirs.', 'jhmg-ai-editor-for-divi-5-pro' ), $last, $named ) ],
            'megamenu_updated'        => [ 'success', __( 'The mega menu now matches your menu. Every item kept its layout and your content.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_content_saved'  => [ 'success', __( 'Saved. The panel in your header is updated.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_content_noslot' => [ 'info', __( 'Saved. This layout has no place for your content, so the header did not change. Choose a layout marked "Picture" to show it.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_content_reset'  => [ 'success', __( 'Back to the defaults: the panel uses the menu item\'s own title and link again.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_edits_saved'    => [ 'success', __( 'Saved. This choice is used the next time you update.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_menu_changed'   => [ 'success', sprintf( /* translators: %s: menu name */ __( 'The header now shows the menu "%s", and the mega menu is built from it.', 'jhmg-ai-editor-for-divi-5-pro' ), $last ) ],
            'megamenu_menu_set'       => [ 'success', sprintf( /* translators: %s: menu name */ __( 'The header now shows the menu "%s".', 'jhmg-ai-editor-for-divi-5-pro' ), $last ) ],
            'megamenu_removed'        => [ 'success', __( 'The mega menu was removed. Your header is back to its normal menu.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_unchanged'      => [ 'info', __( 'Nothing changed: the header already holds exactly that.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_cancelled'      => [ 'info', __( 'Cancelled. Nothing was changed.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_bad_layout'     => [ 'error', __( 'That layout does not exist. Choose one of the layouts below.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_error'          => [ 'error', '' !== $error ? $error : __( 'The mega menu could not be changed.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
        ];
        $items   = $status['items'];
        $applied = $status['applied'] && [] !== $items;
        $sel     = $this->selectedItem( $items );
        $this->styles();
        ?>
        <div class="aied-card aied-pro" id="megamenu">
            <div class="aied-mm-top">
                <div>
                    <h2><?php esc_html_e( 'Mega menu', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h2>
                    <?php $this->statusLine( $status ); ?>
                    <?php $this->menuPicker( $status ); ?>
                </div>
                <?php if ( $status['applied'] ) : ?>
                    <div class="aied-mm-actions">
                        <a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on site', 'jhmg-ai-editor-for-divi-5-pro' ); ?></a>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REMOVE ); ?>">
                            <?php wp_nonce_field( self::ACTION_REMOVE ); ?>
                            <button type="submit" class="button aied-mm-danger"><?php esc_html_e( 'Remove mega menu', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ( isset( $notices[ $notice ] ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notices[ $notice ][0] ); ?> inline"><p><?php echo esc_html( $notices[ $notice ][1] ); ?></p></div>
            <?php endif; ?>

            <?php if ( null === $status['header'] ) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php esc_html_e( 'This site needs a global header before a mega menu can be used. Create one in the Divi Theme Builder: a header on the Default Website Template.', 'jhmg-ai-editor-for-divi-5-pro' ); ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=et_theme_builder' ) ); ?>"><?php esc_html_e( 'Open the Divi Theme Builder', 'jhmg-ai-editor-for-divi-5-pro' ); ?></a>
                    </p>
                </div>
            <?php elseif ( null !== $problem ) : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html( $problem ); ?></p></div>
            <?php endif; ?>

            <?php if ( is_array( $pending ) ) : ?>
                <?php $this->pendingNotice( $pending ); ?>
            <?php endif; ?>

            <?php if ( $usable && $status['applied'] && null !== $status['drift'] ) : ?>
                <div class="notice notice-warning inline aied-mm-drift">
                    <p><strong><?php esc_html_e( 'The mega menu in your header no longer matches your menu.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></strong></p>
                    <ul class="aied-mm-list">
                        <?php foreach ( $this->driftLines( $status['drift'] ) as $line ) : ?>
                            <li><?php echo esc_html( $line ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php $this->updateForm(); ?>
                </div>
            <?php endif; ?>

            <?php if ( $applied ) : ?>
                <?php $this->workspace( $status, $sel, $usable ); ?>
            <?php else : ?>
                <?php $this->gallery( $usable ); ?>
            <?php endif; ?>

            <details class="aied-mm-help">
                <summary><?php esc_html_e( 'How it works', 'jhmg-ai-editor-for-divi-5-pro' ); ?></summary>
                <ul class="aied-mm-list">
                    <li><?php esc_html_e( 'On desktop the mega menu replaces your header\'s menu. Tablet and phone keep the normal menu.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></li>
                    <li><?php esc_html_e( 'It is built from the menu your header\'s Menu module shows. Other menus are not touched.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></li>
                    <li><?php esc_html_e( 'A sub-item that has links under it becomes a heading (not clickable). Latest-post layouts show a snapshot: update to refresh it.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></li>
                    <li><?php esc_html_e( 'The panels are built when you apply or update them. After you edit your menu, press "Update the mega menu".', 'jhmg-ai-editor-for-divi-5-pro' ); ?></li>
                    <li><?php esc_html_e( 'Every change is saved as a step you can undo under "Recent Pro changes" on the Pro tab.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></li>
                </ul>
            </details>
        </div>
        <?php
    }

    /** @param array<string,mixed> $status */
    private function statusLine( array $status ): void
    {
        ?>
        <p class="aied-mm-status">
            <span class="aied-mm-pill <?php echo $status['applied'] ? 'on' : 'off'; ?>"><?php echo $status['applied'] ? esc_html__( 'On', 'jhmg-ai-editor-for-divi-5-pro' ) : esc_html__( 'Off', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span>
            <span><strong><?php esc_html_e( 'Header:', 'jhmg-ai-editor-for-divi-5-pro' ); ?></strong>
                <?php echo null === $status['header'] ? esc_html__( 'none', 'jhmg-ai-editor-for-divi-5-pro' ) : esc_html( '' !== $status['header']['title'] ? $status['header']['title'] : __( 'Untitled header', 'jhmg-ai-editor-for-divi-5-pro' ) ); ?></span>
            <?php if ( null !== $status['menu'] ) : ?>
                <span><strong><?php esc_html_e( 'Menu:', 'jhmg-ai-editor-for-divi-5-pro' ); ?></strong>
                    <?php
                    echo esc_html( sprintf(
                        /* translators: 1: menu name, 2: number of top-level items, 3: number of items with sub-items */
                        __( '%1$s (%2$d items, %3$d with sub-items)', 'jhmg-ai-editor-for-divi-5-pro' ),
                        $status['menu']['name'],
                        $status['menu']['items'],
                        $status['menu']['panels']
                    ) );
                    ?>
                </span>
            <?php endif; ?>
        </p>
        <?php
    }

    /** The menu choice: which of the site's menus the header (and so the mega menu) shows. Only when there is something to choose. @param array<string,mixed> $status */
    private function menuPicker( array $status ): void
    {
        if ( empty( $status['menuModule'] ) || count( $status['menus'] ) < 2 ) {
            return;
        }
        $current = null === $status['menu'] ? 0 : (int) $status['menu']['id'];
        ?>
        <form class="aied-mm-menuform" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_MENU ); ?>">
            <?php wp_nonce_field( self::ACTION_MENU ); ?>
            <label for="aied-mm-menu"><?php esc_html_e( 'Menu shown in the header', 'jhmg-ai-editor-for-divi-5-pro' ); ?></label>
            <select id="aied-mm-menu" name="menu">
                <?php foreach ( $status['menus'] as $m ) : ?>
                    <option value="<?php echo esc_attr( (string) $m['id'] ); ?>"<?php selected( $current, $m['id'] ); ?>><?php echo esc_html( sprintf( /* translators: 1: menu name, 2: number of items */ _n( '%1$s (%2$d item)', '%1$s (%2$d items)', (int) $m['items'], 'jhmg-ai-editor-for-divi-5-pro' ), $m['name'], (int) $m['items'] ) ); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button"><?php esc_html_e( 'Use this menu', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
            <span class="description"><?php esc_html_e( 'Also changes the menu of your phone header. The mega menu is built again from it.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span>
        </form>
        <?php
    }

    // ---- before a mega menu is on ------------------------------------------------------------------------------------

    private function gallery( bool $usable ): void
    {
        $fam = $this->getKey( 'fam' );
        ?>
        <div class="aied-mm-hero">
            <h3><?php esc_html_e( 'Pick a starting look for your menu', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h3>
            <p class="description"><?php esc_html_e( 'It applies to every item that has sub-items. Afterwards you can change one item at a time and add your own texts and pictures.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
        </div>
        <?php $this->chips( $fam, [] ); ?>
        <div class="aied-mm-grid">
            <?php foreach ( Layouts::all() as $layout ) : ?>
                <?php if ( '' === $fam || $fam === $layout['family'] ) : ?>
                    <?php $this->card( $layout, [ 'current' => false, 'label' => '', 'button' => __( 'Use for the whole menu', 'jhmg-ai-editor-for-divi-5-pro' ), 'item' => null, 'enabled' => $usable ] ); ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /** The family filter: plain links, no script. @param array<string,string> $keep query arguments the links keep */
    private function chips( string $current, array $keep ): void
    {
        ?>
        <p class="aied-mm-chips">
            <?php foreach ( [ '' => __( 'All', 'jhmg-ai-editor-for-divi-5-pro' ) ] + Layouts::FAMILIES as $key => $name ) : ?>
                <a class="aied-mm-chip<?php echo (string) $key === $current ? ' is-on' : ''; ?>" href="<?php echo esc_url( $this->tabUrl( $keep + ( '' === $key ? [] : [ 'fam' => (string) $key ] ) ) ); ?>"><?php echo esc_html( $name ); ?></a>
            <?php endforeach; ?>
        </p>
        <?php
    }

    /**
     * One layout card with its own form.
     *
     * @param array<string,mixed> $layout
     * @param array{current:bool,label:string,button:string,item:?int,enabled:bool} $o `current` frames and badges it (`label` is the badge), `item` limits the form to one menu item
     */
    private function card( array $layout, array $o ): void
    {
        ?>
        <div class="aied-mm-card<?php echo $o['current'] ? ' is-current' : ''; ?>">
            <?php if ( '' !== $o['label'] ) : ?>
                <span class="aied-mm-badge"><?php echo esc_html( $o['label'] ); ?></span>
            <?php endif; ?>
            <?php if ( ! empty( $layout['content'] ) ) : ?>
                <span class="aied-mm-pic" title="<?php echo esc_attr__( 'Takes your own headline, text, button and picture', 'jhmg-ai-editor-for-divi-5-pro' ); ?>"><?php esc_html_e( 'Picture', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span>
            <?php endif; ?>
            <div class="aied-mm-preview"><?php echo PreviewSvg::for( $layout ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from constants, the name is escaped inside. ?></div>
            <h4><?php echo esc_html( (string) $layout['name'] ); ?></h4>
            <p><?php echo esc_html( (string) $layout['hint'] ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_APPLY ); ?>">
                <input type="hidden" name="layout" value="<?php echo esc_attr( (string) $layout['id'] ); ?>">
                <?php if ( null !== $o['item'] ) : ?>
                    <input type="hidden" name="item" value="<?php echo esc_attr( (string) $o['item'] ); ?>">
                <?php endif; ?>
                <?php wp_nonce_field( self::ACTION_APPLY ); ?>
                <button type="submit" class="button button-primary"<?php echo $o['enabled'] ? '' : ' disabled'; ?>><?php echo esc_html( $o['button'] ); ?></button>
            </form>
        </div>
        <?php
    }

    // ---- once a mega menu is on --------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $status
     * @param array<string,mixed>|null $sel the chosen item of $status['items']
     */
    private function workspace( array $status, ?array $sel, bool $usable ): void
    {
        $layout = null === $sel || null === $sel['layout'] ? null : Layouts::find( $sel['layout'] );
        $etab   = $this->editorTab( $layout );
        ?>
        <div class="aied-mm-cols">
            <nav class="aied-mm-items" aria-label="<?php echo esc_attr__( 'Menu items', 'jhmg-ai-editor-for-divi-5-pro' ); ?>">
                <h3><?php esc_html_e( 'Menu items', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h3>
                <?php foreach ( $status['items'] as $it ) : ?>
                    <?php $stale = $this->itemIsStale( $status, (int) $it['id'] ); ?>
                    <a class="aied-mm-item<?php echo null !== $sel && $sel['id'] === $it['id'] ? ' is-selected' : ''; ?>" href="<?php echo esc_url( $this->tabUrl( [ 'item' => (string) $it['id'] ] ) ); ?>"<?php echo null !== $sel && $sel['id'] === $it['id'] ? ' aria-current="true"' : ''; ?>>
                        <span class="aied-mm-thumb"><?php echo null === $it['layout'] || null === Layouts::find( $it['layout'] ) ? '' : PreviewSvg::for( Layouts::find( $it['layout'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from constants ?></span>
                        <span class="aied-mm-iname"><?php echo esc_html( $it['title'] ); ?></span>
                        <span class="aied-mm-isub">
                            <?php echo null === $it['layoutName'] ? esc_html__( 'Layout not recognised', 'jhmg-ai-editor-for-divi-5-pro' ) : esc_html( $it['layoutName'] ); ?>
                            <?php if ( $it['custom'] ) : ?><span class="aied-mm-pill accent"><?php esc_html_e( 'Custom content', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span><?php endif; ?>
                            <?php if ( $it['edited'] ) : ?><span class="aied-mm-pill hand"><?php esc_html_e( 'Edited in Divi', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span><?php endif; ?>
                            <?php if ( $stale ) : ?><span class="aied-mm-pill warn"><?php esc_html_e( 'Out of date', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span><?php endif; ?>
                        </span>
                    </a>
                <?php endforeach; ?>
                <p class="description"><?php esc_html_e( 'Items without sub-items stay normal links.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            </nav>

            <div class="aied-mm-editor">
                <?php if ( null === $sel ) : ?>
                    <p class="description"><?php esc_html_e( 'Choose a menu item on the left.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
                <?php else : ?>
                    <div class="aied-mm-ehead">
                        <h3><?php echo esc_html( sprintf( /* translators: %s: menu item */ __( '%s panel', 'jhmg-ai-editor-for-divi-5-pro' ), $sel['title'] ) ); ?></h3>
                        <p class="description"><?php echo null === $layout ? esc_html__( 'Layout not recognised: choose a layout.', 'jhmg-ai-editor-for-divi-5-pro' ) : esc_html( sprintf( /* translators: %s: layout name */ __( 'Layout: %s', 'jhmg-ai-editor-for-divi-5-pro' ), $layout['name'] ) ); ?></p>
                    </div>
                    <h2 class="nav-tab-wrapper aied-mm-tabs">
                        <?php foreach ( [ 'content' => __( 'Content', 'jhmg-ai-editor-for-divi-5-pro' ), 'layout' => __( 'Layout', 'jhmg-ai-editor-for-divi-5-pro' ), 'builder' => __( 'Builder', 'jhmg-ai-editor-for-divi-5-pro' ) ] as $key => $name ) : ?>
                            <a class="nav-tab<?php echo $etab === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $this->tabUrl( [ 'item' => (string) $sel['id'], 'etab' => $key ] ) ); ?>"><?php echo esc_html( $name ); ?></a>
                        <?php endforeach; ?>
                    </h2>
                    <div class="aied-mm-pane">
                        <?php
                        if ( 'layout' === $etab ) {
                            $this->layoutTab( $status, $sel, $layout, $usable );
                        } elseif ( 'builder' === $etab ) {
                            $this->builderTab( $sel, $layout );
                        } else {
                            $this->contentTab( $sel, $layout );
                        }
                        ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /** @param array<string,mixed> $sel @param array<string,mixed>|null $layout */
    private function contentTab( array $sel, ?array $layout ): void
    {
        if ( null === $layout || empty( $layout['content'] ) ) {
            ?>
            <div class="aied-mm-note">
                <strong><?php esc_html_e( 'This layout shows your links only.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></strong>
                <?php esc_html_e( 'Your own headline, text, button and picture can go on the layouts marked "Picture", for example "Columns and a card on the right".', 'jhmg-ai-editor-for-divi-5-pro' ); ?>
            </div>
            <p><a class="button button-primary" href="<?php echo esc_url( $this->tabUrl( [ 'item' => (string) $sel['id'], 'etab' => 'layout' ] ) ); ?>"><?php esc_html_e( 'Choose a layout with a picture', 'jhmg-ai-editor-for-divi-5-pro' ); ?></a></p>
            <?php
            if ( $sel['custom'] ) {
                echo '<p class="description">' . esc_html__( 'Your content for this item is kept and comes back when you choose a layout with a place for it.', 'jhmg-ai-editor-for-divi-5-pro' ) . '</p>';
            }

            return;
        }
        $c       = $this->service->content()->get( (int) $sel['id'] );
        $picture = $c['image'] > 0 ? MegaMenuContent::attachmentFromLibrary( $c['image'] ) : null;
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aied-mm-form">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CONTENT ); ?>">
            <input type="hidden" name="item" value="<?php echo esc_attr( (string) $sel['id'] ); ?>">
            <?php wp_nonce_field( self::ACTION_CONTENT ); ?>
            <div class="aied-mm-fields">
                <div class="aied-mm-field">
                    <span class="aied-mm-label"><?php esc_html_e( 'Picture', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span>
                    <div class="aied-mm-picker">
                        <div class="aied-mm-picked" id="aied-mm-picked"><?php echo null === $picture ? esc_html__( 'No picture', 'jhmg-ai-editor-for-divi-5-pro' ) : '<img src="' . esc_url( $picture['url'] ) . '" alt="">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the URL is escaped ?></div>
                        <input type="hidden" name="image" id="aied-mm-image" value="<?php echo esc_attr( (string) ( null === $picture ? 0 : $c['image'] ) ); ?>">
                        <button type="button" class="button" id="aied-mm-pick"><?php esc_html_e( 'Choose from the Media Library', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                        <button type="button" class="button-link" id="aied-mm-unpick"><?php esc_html_e( 'Remove', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                    </div>
                    <span class="description"><?php esc_html_e( 'Shown at the top of the card or panel. A picture at least 480 px wide looks best. The alt text comes from the Media Library.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span>
                </div>
                <?php $this->textField( 'aied-mm-title', 'title', __( 'Headline', 'jhmg-ai-editor-for-divi-5-pro' ), $c['title'], __( 'Empty: the menu item\'s title.', 'jhmg-ai-editor-for-divi-5-pro' ), MegaMenuContent::MAX_TITLE ); ?>
                <?php $this->textField( 'aied-mm-text', 'text', __( 'Text', 'jhmg-ai-editor-for-divi-5-pro' ), $c['text'], __( 'One or two short lines. Empty: the item\'s description.', 'jhmg-ai-editor-for-divi-5-pro' ), MegaMenuContent::MAX_TEXT, true ); ?>
                <div class="aied-mm-two">
                    <?php $this->textField( 'aied-mm-button', 'button', __( 'Button label', 'jhmg-ai-editor-for-divi-5-pro' ), $c['button'], __( 'Empty: "View all".', 'jhmg-ai-editor-for-divi-5-pro' ), MegaMenuContent::MAX_BUTTON ); ?>
                    <?php $this->textField( 'aied-mm-url', 'url', __( 'Button link', 'jhmg-ai-editor-for-divi-5-pro' ), $c['url'], __( 'Empty: the menu item\'s link.', 'jhmg-ai-editor-for-divi-5-pro' ), MegaMenuContent::MAX_URL ); ?>
                </div>
                <div class="aied-mm-note"><?php esc_html_e( 'Saved with the menu item. It stays when you press Update, and applies to this item only.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></div>
                <p class="aied-mm-row">
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Save and update the header', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                    <?php if ( $sel['custom'] ) : ?>
                        <button type="submit" class="button-link" form="aied-mm-reset"><?php esc_html_e( 'Reset to the defaults', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                    <?php endif; ?>
                </p>
            </div>
            <div class="aied-mm-live" aria-hidden="true">
                <span class="description"><?php esc_html_e( 'Preview of the card', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span>
                <div class="aied-mm-card-pv">
                    <div class="aied-mm-card-pic" id="aied-mm-pv-pic"><?php echo null === $picture ? '' : '<img src="' . esc_url( $picture['url'] ) . '" alt="">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the URL is escaped ?></div>
                    <b id="aied-mm-pv-title"><?php echo esc_html( '' !== $c['title'] ? $c['title'] : (string) $sel['title'] ); ?></b>
                    <span id="aied-mm-pv-text"><?php echo esc_html( '' !== $c['text'] ? $c['text'] : sprintf( /* translators: %s: menu item */ __( 'Everything under %s in one place.', 'jhmg-ai-editor-for-divi-5-pro' ), $sel['title'] ) ); ?></span>
                    <i id="aied-mm-pv-button"><?php echo esc_html( '' !== $c['button'] ? $c['button'] : __( 'View all', 'jhmg-ai-editor-for-divi-5-pro' ) ); ?></i>
                </div>
                <span class="description"><?php esc_html_e( 'A rough preview. The real panel uses your Divi fonts and colours.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span>
            </div>
        </form>
        <?php if ( $sel['custom'] ) : ?>
            <form id="aied-mm-reset" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CONTENT_RESET ); ?>">
                <input type="hidden" name="item" value="<?php echo esc_attr( (string) $sel['id'] ); ?>">
                <?php wp_nonce_field( self::ACTION_CONTENT_RESET ); ?>
            </form>
        <?php endif; ?>
        <?php
        $this->mediaScript();
    }

    private function textField( string $id, string $name, string $label, string $value, string $help, int $max, bool $area = false ): void
    {
        ?>
        <div class="aied-mm-field">
            <label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
            <?php if ( $area ) : ?>
                <textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3" maxlength="<?php echo esc_attr( (string) $max ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
            <?php else : ?>
                <input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" maxlength="<?php echo esc_attr( (string) $max ); ?>">
            <?php endif; ?>
            <span class="description"><?php echo esc_html( $help ); ?></span>
        </div>
        <?php
    }

    /** @param array<string,mixed> $status @param array<string,mixed> $sel @param array<string,mixed>|null $layout */
    private function layoutTab( array $status, array $sel, ?array $layout, bool $usable ): void
    {
        $fam    = $this->getKey( 'fam' );
        $keep   = [ 'item' => (string) $sel['id'], 'etab' => 'layout' ];
        $usedBy = [];
        foreach ( $status['items'] as $it ) {
            if ( null !== $it['layout'] ) {
                $usedBy[ $it['layout'] ][] = (int) $it['id'];
            }
        }
        ?>
        <p class="description"><?php echo esc_html( sprintf( /* translators: %s: menu item */ __( 'Choose the layout for %s. The other items keep theirs.', 'jhmg-ai-editor-for-divi-5-pro' ), $sel['title'] ) ); ?></p>
        <?php $this->chips( $fam, $keep ); ?>
        <div class="aied-mm-grid">
            <?php foreach ( Layouts::all() as $l ) : ?>
                <?php
                if ( '' !== $fam && $fam !== $l['family'] ) {
                    continue;
                }
                $here  = $l['id'] === $sel['layout'];
                $other = ! $here && [] !== array_diff( $usedBy[ $l['id'] ] ?? [], [ (int) $sel['id'] ] );
                $this->card( $l, [
                    'current' => $here || $other,
                    'label'   => $here ? __( 'This item', 'jhmg-ai-editor-for-divi-5-pro' ) : ( $other ? __( 'Another item', 'jhmg-ai-editor-for-divi-5-pro' ) : '' ),
                    'button'  => $here ? __( 'Rebuild this item', 'jhmg-ai-editor-for-divi-5-pro' ) : __( 'Use for this item', 'jhmg-ai-editor-for-divi-5-pro' ),
                    'item'    => (int) $sel['id'],
                    'enabled' => $usable,
                ] );
                ?>
            <?php endforeach; ?>
        </div>
        <?php if ( null !== $layout ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aied-mm-row">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_APPLY ); ?>">
                <input type="hidden" name="layout" value="<?php echo esc_attr( (string) $layout['id'] ); ?>">
                <?php wp_nonce_field( self::ACTION_APPLY ); ?>
                <button type="submit" class="button"<?php echo $usable ? '' : ' disabled'; ?>><?php echo esc_html( sprintf( /* translators: %s: layout name */ __( 'Use "%s" for all items', 'jhmg-ai-editor-for-divi-5-pro' ), $layout['name'] ) ); ?></button>
            </form>
        <?php endif; ?>
        <?php
    }

    /** @param array<string,mixed> $sel @param array<string,mixed>|null $layout */
    private function builderTab( array $sel, ?array $layout ): void
    {
        $open  = $this->builderUrl();
        $edits = $this->service->content()->get( (int) $sel['id'] )['edits'];
        ?>
        <p class="description"><?php esc_html_e( 'Want full control? Edit this panel in the Divi builder.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
        <div class="aied-mm-rows">
            <div class="aied-mm-srow">
                <div class="aied-mm-grow">
                    <h4><?php esc_html_e( 'Edit in the Divi builder', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h4>
                    <p class="description"><?php echo esc_html( sprintf( /* translators: %s: menu item */ __( 'Opens your header in the Theme Builder. Select the group named "JHMG Mega Menu", then the panel of %s. Change colours, fonts, spacing or add modules.', 'jhmg-ai-editor-for-divi-5-pro' ), $sel['title'] ) ); ?></p>
                </div>
                <a class="button button-primary" href="<?php echo esc_url( $open ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open in Divi', 'jhmg-ai-editor-for-divi-5-pro' ); ?></a>
            </div>
            <div class="aied-mm-srow">
                <div class="aied-mm-grow">
                    <h4><?php esc_html_e( 'This panel is', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h4>
                    <p class="description"><?php echo $sel['edited'] ? esc_html__( 'You changed it in the builder. The plugin does not touch it unless you say so below.', 'jhmg-ai-editor-for-divi-5-pro' ) : esc_html__( 'Built by the plugin from your menu and your content, and kept in step when you press Update.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
                </div>
                <?php if ( $sel['edited'] ) : ?>
                    <span class="aied-mm-pill hand"><?php esc_html_e( 'Edited in Divi', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span>
                <?php else : ?>
                    <span class="aied-mm-pill on"><?php esc_html_e( 'Automatic', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span>
                <?php endif; ?>
            </div>
            <form class="aied-mm-srow" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_EDITS ); ?>">
                <input type="hidden" name="item" value="<?php echo esc_attr( (string) $sel['id'] ); ?>">
                <?php wp_nonce_field( self::ACTION_EDITS ); ?>
                <div class="aied-mm-grow">
                    <label for="aied-mm-edits"><h4><?php esc_html_e( 'When you press Update and this panel was edited in Divi', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h4></label>
                    <select id="aied-mm-edits" name="edits">
                        <option value="ask"<?php selected( $edits, 'ask' ); ?>><?php esc_html_e( 'Ask me', 'jhmg-ai-editor-for-divi-5-pro' ); ?></option>
                        <option value="keep"<?php selected( $edits, 'keep' ); ?>><?php esc_html_e( 'Keep my version', 'jhmg-ai-editor-for-divi-5-pro' ); ?></option>
                        <option value="rebuild"<?php selected( $edits, 'rebuild' ); ?>><?php esc_html_e( 'Replace it with the plugin\'s version', 'jhmg-ai-editor-for-divi-5-pro' ); ?></option>
                    </select>
                </div>
                <button type="submit" class="button"><?php esc_html_e( 'Save', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
            </form>
            <?php if ( $sel['edited'] && null !== $layout ) : ?>
                <form class="aied-mm-srow" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_APPLY ); ?>">
                    <input type="hidden" name="layout" value="<?php echo esc_attr( (string) $layout['id'] ); ?>">
                    <input type="hidden" name="item" value="<?php echo esc_attr( (string) $sel['id'] ); ?>">
                    <?php wp_nonce_field( self::ACTION_APPLY ); ?>
                    <div class="aied-mm-grow">
                        <h4><?php esc_html_e( 'Start over', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h4>
                        <p class="description"><?php esc_html_e( 'Replaces your version of this panel with the plugin\'s version now. Your changes in the builder are lost.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
                    </div>
                    <button type="submit" class="button aied-mm-danger"><?php esc_html_e( 'Replace my version', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /** The buttons that settle what an update does with panels the owner edited in the builder. @param array<string,mixed> $pending */
    private function pendingNotice( array $pending ): void
    {
        $panels = is_array( $pending['panels'] ?? null ) ? array_map( 'strval', $pending['panels'] ) : [];
        ?>
        <div class="notice notice-warning inline aied-mm-pending">
            <p><strong><?php echo esc_html( sprintf( /* translators: %s: the panels, for example: the panel of "Services" */ __( 'You changed %s in the Divi builder.', 'jhmg-ai-editor-for-divi-5-pro' ), EditedPanelsException::names( $panels ) ) ); ?></strong>
                <?php esc_html_e( 'Keep your changes, or replace them with the plugin\'s version? Nothing has been changed yet.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></p>
            <div class="aied-mm-row">
                <?php foreach ( [ 'keep' => [ __( 'Keep my changes', 'jhmg-ai-editor-for-divi-5-pro' ), 'button button-primary' ], 'rebuild' => [ __( 'Replace them', 'jhmg-ai-editor-for-divi-5-pro' ), 'button aied-mm-danger' ], 'cancel' => [ __( 'Cancel', 'jhmg-ai-editor-for-divi-5-pro' ), 'button' ] ] as $choice => $b ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_RESOLVE ); ?>">
                        <input type="hidden" name="choice" value="<?php echo esc_attr( $choice ); ?>">
                        <?php wp_nonce_field( self::ACTION_RESOLVE ); ?>
                        <button type="submit" class="<?php echo esc_attr( $b[1] ); ?>"><?php echo esc_html( $b[0] ); ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /** The "Update the mega menu" button (a form of its own: the notice on the tab and the one on every other admin screen). */
    private function updateForm(): void
    {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_UPDATE ); ?>">
            <?php wp_nonce_field( self::ACTION_UPDATE ); ?>
            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Update the mega menu', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                <span class="description"><?php esc_html_e( 'Builds it again from your menu as it is now. Every item keeps its layout and your content.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span>
            </p>
        </form>
        <?php
    }

    private function styles(): void
    {
        ?>
        <style>
            #megamenu .aied-mm-top { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: flex-start; justify-content: space-between; }
            #megamenu .aied-mm-top h2 { margin-bottom: 4px; }
            #megamenu .aied-mm-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
            #megamenu .aied-mm-actions form { margin: 0; }
            #megamenu .aied-mm-status { display: flex; gap: 6px 16px; flex-wrap: wrap; align-items: center; margin: 4px 0 12px; color: #50575e; }
            #megamenu .aied-mm-menuform { display: flex; gap: 6px 10px; flex-wrap: wrap; align-items: center; margin: 0 0 12px; }
            #megamenu .aied-mm-menuform select { max-width: 100%; }
            #megamenu .aied-mm-pill { display: inline-block; border-radius: 999px; padding: 1px 9px; font-size: 12px; font-weight: 600; background: #f0f0f1; color: #50575e; margin-left: 4px; }
            #megamenu .aied-mm-status .aied-mm-pill { margin-left: 0; }
            #megamenu .aied-mm-pill.on { background: #e7f5ec; color: #1e6b3a; }
            #megamenu .aied-mm-pill.accent { background: #eeedfd; color: #4f46e5; }
            #megamenu .aied-mm-pill.hand { background: #fff1d6; color: #8a5a00; }
            #megamenu .aied-mm-pill.warn { background: #fde8e8; color: #a02222; }
            #megamenu .aied-mm-danger { color: #b32d2e; border-color: #b32d2e; }
            #megamenu .aied-mm-list { list-style: disc; margin: 4px 0 8px 20px; }
            #megamenu .aied-mm-help { margin: 20px 0 0; }
            #megamenu .aied-mm-hero h3 { margin-bottom: 2px; }
            #megamenu .aied-mm-chips { display: flex; gap: 6px; flex-wrap: wrap; margin: 10px 0; }
            #megamenu .aied-mm-chip { border: 1px solid #c3c4c7; border-radius: 999px; padding: 2px 12px; text-decoration: none; color: #1d2327; background: #fff; }
            #megamenu .aied-mm-chip.is-on { background: #1d2327; border-color: #1d2327; color: #fff; }
            #megamenu .aied-mm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; margin: 8px 0; }
            #megamenu .aied-mm-card { position: relative; border: 1px solid #dcdcde; border-radius: 6px; padding: 12px; background: #fff; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
            #megamenu .aied-mm-card.is-current { border-color: #4f46e5; box-shadow: 0 0 0 2px #4f46e5; background: #f5f5ff; }
            #megamenu .aied-mm-badge { align-self: flex-start; background: #4f46e5; color: #fff; border-radius: 999px; padding: 2px 10px; font-size: 12px; font-weight: 600; line-height: 1.6; max-width: 100%; box-sizing: border-box; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            #megamenu .aied-mm-pic { position: absolute; top: 14px; right: 14px; background: rgba(20, 22, 31, .78); color: #fff; border-radius: 4px; padding: 1px 6px; font-size: 11px; font-weight: 600; }
            #megamenu .aied-mm-card h4 { margin: 4px 0 0; font-size: 14px; }
            #megamenu .aied-mm-card p { margin: 0; color: #50575e; }
            #megamenu .aied-mm-preview svg { display: block; width: 100%; height: auto; }
            #megamenu .aied-mm-card form { margin-top: auto; padding-top: 6px; }
            #megamenu .aied-mm-cols { display: grid; grid-template-columns: 300px minmax(0, 1fr); border: 1px solid #dcdcde; border-radius: 8px; overflow: hidden; margin-top: 8px; }
            @media (max-width: 960px) { #megamenu .aied-mm-cols { grid-template-columns: 1fr; } }
            #megamenu .aied-mm-items { background: #f6f7f7; border-right: 1px solid #dcdcde; padding: 12px; display: flex; flex-direction: column; gap: 6px; min-width: 0; }
            #megamenu .aied-mm-items h3 { margin: 0; padding: 4px 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #646970; }
            #megamenu .aied-mm-item { display: grid; grid-template-columns: 44px minmax(0, 1fr); column-gap: 10px; align-items: center; border: 1px solid transparent; border-radius: 8px; padding: 8px; text-decoration: none; color: #1d2327; }
            #megamenu .aied-mm-item:hover { background: #fff; }
            #megamenu .aied-mm-item.is-selected { background: #fff; border-color: #4f46e5; box-shadow: 0 0 0 1px #4f46e5; }
            #megamenu .aied-mm-thumb { grid-row: 1 / span 2; width: 44px; border-radius: 4px; overflow: hidden; background: #e6e9f0; }
            #megamenu .aied-mm-thumb svg { display: block; width: 100%; height: auto; }
            #megamenu .aied-mm-iname { font-weight: 600; overflow-wrap: anywhere; }
            #megamenu .aied-mm-isub { color: #646970; font-size: 12px; display: flex; flex-wrap: wrap; gap: 2px 4px; align-items: center; }
            #megamenu .aied-mm-editor { min-width: 0; padding: 14px 18px 18px; }
            #megamenu .aied-mm-ehead h3 { margin: 0; }
            #megamenu .aied-mm-tabs { margin: 10px 0 0; padding: 0; }
            #megamenu .aied-mm-pane { padding-top: 16px; display: flex; flex-direction: column; gap: 14px; }
            #megamenu .aied-mm-note { border: 1px solid #dcdcde; border-left: 4px solid #4f46e5; border-radius: 4px; background: #f6f7f7; padding: 8px 12px; color: #50575e; }
            #megamenu .aied-mm-form { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 300px); gap: 24px; align-items: start; }
            @media (max-width: 1200px) { #megamenu .aied-mm-form { grid-template-columns: 1fr; } }
            #megamenu .aied-mm-fields { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
            #megamenu .aied-mm-field { display: flex; flex-direction: column; gap: 4px; }
            #megamenu .aied-mm-field label, #megamenu .aied-mm-label { font-weight: 600; }
            #megamenu .aied-mm-field input[type=text], #megamenu .aied-mm-field textarea { width: 100%; max-width: 100%; }
            #megamenu .aied-mm-two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
            @media (max-width: 700px) { #megamenu .aied-mm-two { grid-template-columns: 1fr; } }
            #megamenu .aied-mm-picker { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
            #megamenu .aied-mm-picked { width: 120px; height: 80px; border: 1px dashed #c3c4c7; border-radius: 6px; display: grid; place-items: center; color: #646970; font-size: 12px; background: #f6f7f7; overflow: hidden; }
            #megamenu .aied-mm-picked img { width: 100%; height: 100%; object-fit: cover; display: block; }
            #megamenu .aied-mm-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 4px 0 0; }
            #megamenu .aied-mm-row form { margin: 0; }
            #megamenu .aied-mm-live { border: 1px solid #dcdcde; border-radius: 8px; background: #f6f7f7; padding: 12px; display: flex; flex-direction: column; gap: 8px; }
            #megamenu .aied-mm-card-pv { background: linear-gradient(135deg, #1d4ed8, #6d28d9); color: #fff; border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
            #megamenu .aied-mm-card-pic { width: 100%; max-height: 96px; border-radius: 8px; overflow: hidden; }
            #megamenu .aied-mm-card-pic:empty { display: none; }
            #megamenu .aied-mm-card-pic img { width: 100%; height: 96px; object-fit: cover; display: block; }
            #megamenu .aied-mm-card-pv b { font-size: 16px; overflow-wrap: anywhere; }
            #megamenu .aied-mm-card-pv span { font-size: 12px; opacity: .92; overflow-wrap: anywhere; }
            #megamenu .aied-mm-card-pv i { font-style: normal; background: #fff; color: #1d2327; border-radius: 999px; padding: 2px 12px; font-size: 12px; font-weight: 700; }
            #megamenu .aied-mm-rows { display: flex; flex-direction: column; gap: 10px; max-width: 820px; }
            #megamenu .aied-mm-srow { display: flex; gap: 12px 16px; flex-wrap: wrap; align-items: center; justify-content: space-between; border: 1px solid #dcdcde; border-radius: 8px; padding: 10px 14px; margin: 0; }
            #megamenu .aied-mm-grow { flex: 1 1 280px; min-width: 0; }
            #megamenu .aied-mm-srow h4 { margin: 0 0 2px; }
            #megamenu .aied-mm-srow select { max-width: 100%; }
            #megamenu .aied-mm-pending .aied-mm-row, #megamenu .aied-mm-drift form { margin-bottom: 8px; }
        </style>
        <?php
    }

    /** The Media Library window and the small live preview, only on the Content tab. */
    private function mediaScript(): void
    {
        ?>
        <script>
        (function () {
            var $ = function (id) { return document.getElementById(id); };
            var pick = $('aied-mm-pick'), unpick = $('aied-mm-unpick'), input = $('aied-mm-image'), box = $('aied-mm-picked'), pv = $('aied-mm-pv-pic'), frame = null;
            var none = <?php echo wp_json_encode( __( 'No picture', 'jhmg-ai-editor-for-divi-5-pro' ) ); ?>;
            function show(url) {
                box.textContent = ''; pv.textContent = '';
                if (!url) { box.textContent = none; return; }
                [box, pv].forEach(function (host) { var i = document.createElement('img'); i.src = url; i.alt = ''; host.appendChild(i); });
            }
            // WordPress prints the Media Library script in the footer, after this one: wait for the page before looking for it.
            function initMedia() {
                if (pick && window.wp && wp.media) {
                    pick.addEventListener('click', function (e) {
                        e.preventDefault();
                        if (!frame) {
                            frame = wp.media({ title: <?php echo wp_json_encode( __( 'Choose a picture', 'jhmg-ai-editor-for-divi-5-pro' ) ); ?>, button: { text: <?php echo wp_json_encode( __( 'Use this picture', 'jhmg-ai-editor-for-divi-5-pro' ) ); ?> }, library: { type: 'image' }, multiple: false });
                            frame.on('select', function () {
                                var a = frame.state().get('selection').first().toJSON();
                                input.value = a.id;
                                show((a.sizes && a.sizes.medium) ? a.sizes.medium.url : a.url);
                            });
                        }
                        frame.open();
                    });
                } else if (pick) { pick.disabled = true; }
            }
            if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initMedia); } else { initMedia(); }
            if (unpick) { unpick.addEventListener('click', function () { input.value = '0'; show(''); }); }
            [['aied-mm-title', 'aied-mm-pv-title'], ['aied-mm-text', 'aied-mm-pv-text'], ['aied-mm-button', 'aied-mm-pv-button']].forEach(function (p) {
                var src = $(p[0]), dst = $(p[1]);
                if (!src || !dst) { return; }
                var fallback = dst.textContent;
                src.addEventListener('input', function () { dst.textContent = src.value.trim() || fallback; });
            });
        })();
        </script>
        <?php
    }

    // ---- the notice on every admin screen ------------------------------------------------------------------------------

    /**
     * `admin_notices`: while the mega menu in the header no longer matches the menu, say so on every admin screen (not only on this
     * tab) to a user who may change the header, with the button that fixes it. The answer is cached (see cachedDrift()) and forgotten
     * whenever a menu item, a menu or any post is saved, so a page load does not rebuild the menu.
     */
    public function adminNotice(): void
    {
        if ( ! is_admin() || ! ( $this->unlocked )() || ! current_user_can( 'edit_theme_options' ) || $this->onThisTab() ) {
            return; // the tab shows it in full
        }
        $lines = $this->cachedDrift();
        if ( [] === $lines ) {
            return;
        }
        ?>
        <div class="notice notice-warning aied-mm-wpnotice">
            <p>
                <strong><?php esc_html_e( 'Your mega menu is out of date.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></strong>
                <?php echo esc_html( $lines[0] ); ?>
                <?php if ( count( $lines ) > 1 ) : ?>
                    <?php echo esc_html( sprintf( /* translators: %d: number of other changes */ _n( '(and %d more change)', '(and %d more changes)', count( $lines ) - 1, 'jhmg-ai-editor-for-divi-5-pro' ), count( $lines ) - 1 ) ); ?>
                <?php endif; ?>
                <a href="<?php echo esc_url( $this->tabUrl( [] ) ); ?>"><?php esc_html_e( 'See what changed', 'jhmg-ai-editor-for-divi-5-pro' ); ?></a>
            </p>
            <?php $this->updateForm(); ?>
        </div>
        <?php
    }

    /** Forget the cached answer (a menu, a menu item, the header or any post was saved, or the mega menu itself was changed). */
    public function forgetDrift( mixed ...$unused ): void
    {
        delete_transient( self::DRIFT_KEY );
    }

    /**
     * What differs between the header and the menu, one sentence each, or [] when they match (or there is nothing to compare).
     * Cached for an hour; a failure to compare counts as "nothing to report", never as an error on every admin screen.
     *
     * @return list<string>
     */
    private function cachedDrift(): array
    {
        $cached = get_transient( self::DRIFT_KEY );
        if ( is_array( $cached ) && isset( $cached['lines'] ) && is_array( $cached['lines'] ) ) {
            return array_values( array_map( 'strval', $cached['lines'] ) );
        }
        $lines = [];
        try {
            $status = $this->service->status();
            if ( $status['applied'] && null !== $status['drift'] ) {
                $lines = $this->driftLines( $status['drift'] );
            }
        } catch ( \Throwable ) {
            $lines = [];
        }
        set_transient( self::DRIFT_KEY, [ 'lines' => $lines ], 3600 );

        return $lines;
    }

    /**
     * What differs between the header and the menu, in plain sentences (at most a few names each).
     *
     * @param array<string,mixed> $drift MegaMenuService::status()['drift']
     * @return list<string>
     */
    private function driftLines( array $drift ): array
    {
        $names = static function ( array $list ): string {
            $shown = array_slice( array_values( array_unique( $list ) ), 0, 4 );
            $more  = count( array_unique( $list ) ) - count( $shown );

            return '"' . implode( '", "', $shown ) . '"' . ( $more > 0 ? sprintf( /* translators: %d: how many more names */ __( ' and %d more', 'jhmg-ai-editor-for-divi-5-pro' ), $more ) : '' );
        };
        $lines = [];
        $one   = static function ( string $where, array $d ) use ( $names ): array {
            $out = [];
            if ( [] !== $d['missing'] ) {
                $out[] = sprintf( /* translators: 1: where (menu item or the top bar), 2: names */ __( '%1$s: in your menu but not in the header yet: %2$s.', 'jhmg-ai-editor-for-divi-5-pro' ), $where, $names( $d['missing'] ) );
            }
            if ( [] !== $d['extra'] ) {
                $out[] = sprintf( /* translators: 1: where (menu item or the top bar), 2: names */ __( '%1$s: still in the header but no longer in your menu: %2$s.', 'jhmg-ai-editor-for-divi-5-pro' ), $where, $names( $d['extra'] ) );
            }
            if ( [] === $out && ! empty( $d['reordered'] ) ) {
                $out[] = sprintf( /* translators: %s: where (menu item or the top bar) */ __( '%s: the order of the links changed.', 'jhmg-ai-editor-for-divi-5-pro' ), $where );
            }

            return $out;
        };
        if ( is_array( $drift['bar'] ?? null ) ) {
            array_push( $lines, ...$one( __( 'Top-level items', 'jhmg-ai-editor-for-divi-5-pro' ), $drift['bar'] ) );
        }
        foreach ( $drift['items'] ?? [] as $it ) {
            array_push( $lines, ...$one( (string) $it['title'], $it ) );
        }
        if ( [] !== ( $drift['gone'] ?? [] ) ) {
            $lines[] = __( 'The header has a panel for an item that no longer exists in your menu.', 'jhmg-ai-editor-for-divi-5-pro' );
        }

        return $lines;
    }

    // ---- handlers ----------------------------------------------------------------------------------------------------

    public function handleApply(): void
    {
        if ( ! $this->prepare( self::ACTION_APPLY ) ) {
            return;
        }
        $id = $this->postedLayout();
        if ( null === $id ) {
            $this->back( 'megamenu_bad_layout', [] );

            return;
        }
        $item = $this->postedItem();
        $this->back( $this->execute( [ 'op' => 'apply', 'layout' => $id, 'item' => $item ], null ), null === $item ? [] : [ 'item' => (string) $item, 'etab' => 'layout' ] );
    }

    public function handleUpdate(): void
    {
        if ( ! $this->prepare( self::ACTION_UPDATE ) ) {
            return;
        }
        $this->back( $this->execute( [ 'op' => 'update' ], null ), [] );
    }

    /** Makes the header (and the mega menu built from it) use another menu. */
    public function handleMenu(): void
    {
        if ( ! $this->prepare( self::ACTION_MENU ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in prepare().
        $raw = $_POST['menu'] ?? null;
        if ( ! is_string( $raw ) || ! ctype_digit( $raw ) || (int) $raw <= 0 ) {
            $this->fail( MegaMenuService::NO_SUCH_MENU );

            return;
        }
        $this->back( $this->execute( [ 'op' => 'menu', 'menu' => (int) $raw ], null ), [] );
    }

    public function handleRemove(): void
    {
        if ( ! $this->prepare( self::ACTION_REMOVE ) ) {
            return;
        }
        try {
            $result = $this->service->remove( false );
        } catch ( \InvalidArgumentException | \RuntimeException $e ) {
            $this->fail( $e->getMessage() );

            return;
        }
        $this->forgetDrift();
        delete_transient( self::PENDING_KEY . (int) get_current_user_id() );
        $this->back( empty( $result['changed'] ) ? 'megamenu_unchanged' : 'megamenu_removed', [] );
    }

    /** Saves the owner's texts and picture of one item and updates the header with them. */
    public function handleContent(): void
    {
        if ( ! $this->prepare( self::ACTION_CONTENT ) ) {
            return;
        }
        $item = $this->postedItem();
        $at   = $this->itemState( $item );
        if ( null === $item || null === $at ) {
            $this->fail( MegaMenuService::NO_SUCH_ITEM );

            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in prepare().
        $in = [];
        foreach ( [ 'title', 'text', 'button', 'url', 'image' ] as $key ) {
            $in[ $key ] = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
        }
        // phpcs:enable
        $this->service->content()->save( $item, $in );
        $args = [ 'item' => (string) $item, 'etab' => 'content' ];
        if ( empty( $at['content'] ) ) {
            $this->back( 'megamenu_content_noslot', $args );

            return;
        }
        $notice = $this->execute( [ 'op' => 'update' ], null );
        $this->back( 'megamenu_updated' === $notice || 'megamenu_unchanged' === $notice ? 'megamenu_content_saved' : $notice, $args );
    }

    public function handleContentReset(): void
    {
        if ( ! $this->prepare( self::ACTION_CONTENT_RESET ) ) {
            return;
        }
        $item = $this->postedItem();
        if ( null === $item || null === $this->itemState( $item ) ) {
            $this->fail( MegaMenuService::NO_SUCH_ITEM );

            return;
        }
        $this->service->content()->reset( $item );
        $notice = $this->execute( [ 'op' => 'update' ], null );
        $this->back( 'megamenu_updated' === $notice || 'megamenu_unchanged' === $notice ? 'megamenu_content_reset' : $notice, [ 'item' => (string) $item, 'etab' => 'content' ] );
    }

    /** What an update does with this item's panel when it was edited in the builder. */
    public function handleEdits(): void
    {
        if ( ! $this->prepare( self::ACTION_EDITS ) ) {
            return;
        }
        $item = $this->postedItem();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in prepare().
        $policy = isset( $_POST['edits'] ) && is_string( $_POST['edits'] ) ? sanitize_key( wp_unslash( $_POST['edits'] ) ) : '';
        if ( null === $item || ! in_array( $policy, MegaMenuContent::EDITS, true ) ) {
            $this->fail( __( 'That choice is not available.', 'jhmg-ai-editor-for-divi-5-pro' ) );

            return;
        }
        $this->service->content()->setEdits( $item, $policy );
        $this->back( 'megamenu_edits_saved', [ 'item' => (string) $item, 'etab' => 'builder' ] );
    }

    /** The answer to "you changed a panel in the builder": keep the changes, replace them, or cancel. */
    public function handleResolve(): void
    {
        if ( ! $this->prepare( self::ACTION_RESOLVE ) ) {
            return;
        }
        $me      = (int) get_current_user_id();
        $pending = get_transient( self::PENDING_KEY . $me );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in prepare().
        $choice = isset( $_POST['choice'] ) && is_string( $_POST['choice'] ) ? sanitize_key( wp_unslash( $_POST['choice'] ) ) : '';
        if ( ! is_array( $pending ) || ! in_array( $choice, [ 'keep', 'rebuild', 'cancel' ], true ) ) {
            delete_transient( self::PENDING_KEY . $me );
            $this->back( 'megamenu_cancelled', [] );

            return;
        }
        delete_transient( self::PENDING_KEY . $me );
        if ( 'cancel' === $choice ) {
            $this->back( 'megamenu_cancelled', [] );

            return;
        }
        $op = [ 'op' => (string) ( $pending['op'] ?? 'update' ), 'layout' => $pending['layout'] ?? null, 'item' => isset( $pending['item'] ) ? (int) $pending['item'] : null, 'menu' => isset( $pending['menu'] ) ? (int) $pending['menu'] : null ];
        $this->back( $this->execute( $op, $choice ), null === $op['item'] ? [] : [ 'item' => (string) $op['item'], 'etab' => 'layout' ] );
    }

    /**
     * Runs one change on the header and says what happened as a notice code. A panel edited in the builder with no answer yet stops it
     * and is remembered, so the tab can ask.
     *
     * @param array{op:string,layout?:?string,item?:?int,menu?:?int} $op
     */
    private function execute( array $op, ?string $edited ): string
    {
        $me = (int) get_current_user_id();
        try {
            if ( 'menu' === $op['op'] ) {
                $result = $this->service->useMenu( (int) ( $op['menu'] ?? 0 ), false, $edited );
            } elseif ( 'apply' === $op['op'] ) {
                $result = $this->service->apply( (string) $op['layout'], false, $op['item'] ?? null, $edited );
            } else {
                $result = $this->service->refresh( false, $edited );
            }
        } catch ( EditedPanelsException $e ) {
            set_transient( self::PENDING_KEY . $me, [ 'op' => $op['op'], 'layout' => $op['layout'] ?? null, 'item' => $op['item'] ?? null, 'menu' => $op['menu'] ?? null, 'panels' => $e->panels ], self::PENDING_TTL );

            return 'megamenu_edited';
        } catch ( \InvalidArgumentException | \RuntimeException $e ) {
            set_transient( self::ERROR_KEY . $me, $e->getMessage(), self::TTL );

            return 'megamenu_error';
        }
        $this->forgetDrift();
        delete_transient( self::PENDING_KEY . $me );
        if ( empty( $result['changed'] ) ) {
            return 'megamenu_unchanged';
        }
        if ( 'menu' === $op['op'] ) {
            set_transient( self::LAST_KEY . $me, (string) ( $result['mega_menu']['menu'] ?? '' ), self::TTL );

            return ! empty( $result['mega_menu']['mega_menu_on'] ) ? 'megamenu_menu_changed' : 'megamenu_menu_set';
        }
        if ( 'update' === $op['op'] ) {
            return 'megamenu_updated';
        }
        $layout = Layouts::find( (string) $op['layout'] );
        set_transient( self::LAST_KEY . $me, null === $layout ? (string) $op['layout'] : (string) $layout['name'], self::TTL );
        if ( null !== ( $op['item'] ?? null ) ) {
            $at = $this->itemState( (int) $op['item'] );
            set_transient( self::ITEM_KEY . $me, null === $at ? '' : (string) $at['title'], self::TTL );

            return 'megamenu_applied_item';
        }

        return 'megamenu_applied';
    }

    // ---- small helpers -----------------------------------------------------------------------------------------------

    /** Capability, POST, nonce and licence: the same for every handler. False when the licence is missing (already redirected); the handler then stops. */
    private function prepare( string $action ): bool
    {
        $this->guardPost( $action, 'edit_theme_options' );
        if ( ! ( $this->unlocked )() ) {
            $this->fail( __( 'The mega menu needs an active Pro licence.', 'jhmg-ai-editor-for-divi-5-pro' ) );

            return false;
        }

        return true;
    }

    private function fail( string $message ): void
    {
        set_transient( self::ERROR_KEY . (int) get_current_user_id(), $message, self::TTL );
        $this->back( 'megamenu_error', [] );
    }

    /** @param array<string,string> $args */
    private function back( string $notice, array $args ): void
    {
        wp_safe_redirect( add_query_arg( [ 'page' => self::SCREEN, 'tab' => self::TAB, 'aied_pro_notice' => $notice ] + $args, admin_url( 'admin.php' ) ) );
        ( $this->terminate )();
    }

    /** @param array<string,string> $args */
    private function tabUrl( array $args ): string
    {
        return add_query_arg( [ 'page' => self::SCREEN, 'tab' => self::TAB ] + $args, admin_url( 'admin.php' ) );
    }

    private function onThisTab(): bool
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- reads where we are, changes nothing.
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $tab  = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        // phpcs:enable

        return self::SCREEN === $page && self::TAB === $tab;
    }

    /** A query argument of the tab as a plain key ('' when absent or not a string). */
    private function getKey( string $name ): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads a filter, changes nothing.
        return isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_key( wp_unslash( $_GET[ $name ] ) ) : '';
    }

    /**
     * The item the editor shows: the one in the address when it has a panel, else the first that has one.
     *
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>|null
     */
    private function selectedItem( array $items ): ?array
    {
        $want = $this->getKey( 'item' );
        foreach ( $items as $it ) {
            if ( ctype_digit( $want ) && (int) $want === $it['id'] ) {
                return $it;
            }
        }

        return $items[0] ?? null;
    }

    /** The editor tab: the one in the address, else Content for a layout that has a place for it and Layout otherwise. @param array<string,mixed>|null $layout */
    private function editorTab( ?array $layout ): string
    {
        $want = $this->getKey( 'etab' );
        if ( in_array( $want, [ 'content', 'layout', 'builder' ], true ) ) {
            return $want;
        }

        return null !== $layout && ! empty( $layout['content'] ) ? 'content' : 'layout';
    }

    /** @param array<string,mixed> $status */
    private function itemIsStale( array $status, int $id ): bool
    {
        foreach ( $status['drift']['items'] ?? [] as $d ) {
            if ( (int) $d['id'] === $id ) {
                return true;
            }
        }

        return false;
    }

    /** The status entry of a top-level item that has a panel, or null. @return array<string,mixed>|null */
    private function itemState( ?int $id ): ?array
    {
        if ( null === $id ) {
            return null;
        }
        foreach ( $this->service->status()['items'] as $it ) {
            if ( $it['id'] === $id ) {
                return $it;
            }
        }

        return null;
    }

    /** Where "Open in Divi" goes: the header in the Theme Builder, the way Divi's own Theme Builder builds that address. */
    private function builderUrl(): string
    {
        $header = $this->service->globalHeader();
        if ( null !== $header && function_exists( 'et_fb_get_builder_url' ) && function_exists( 'get_permalink' ) ) {
            $link = get_permalink( $header['layout_id'] );
            if ( is_string( $link ) && '' !== $link ) {
                return add_query_arg( [ 'et_tb' => '1', 'app_window' => '1' ], (string) et_fb_get_builder_url( $link ) );
            }
        }

        return admin_url( 'admin.php?page=et_theme_builder' );
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in prepare().
        $raw = $_POST['layout'] ?? null;
        if ( ! is_string( $raw ) ) {
            return null;
        }
        $id = sanitize_key( wp_unslash( $raw ) );

        return in_array( $id, Layouts::ids(), true ) ? $id : null;
    }

    /** The posted menu item id (a digit string above 0), or null. A bad value is also null: the service refuses an item that has no panel. */
    private function postedItem(): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in prepare().
        $raw = $_POST['item'] ?? null;
        if ( ! is_string( $raw ) || ! ctype_digit( $raw ) || (int) $raw <= 0 ) {
            return null;
        }

        return (int) $raw;
    }
}
