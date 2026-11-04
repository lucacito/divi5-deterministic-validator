<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Admin;

use AiEditorDivi5\Pro\MegaMenu\Layouts;
use AiEditorDivi5\Pro\MegaMenu\MegaMenuService;
use AiEditorDivi5\Pro\MegaMenu\MegaMenuUndo;
use AiEditorDivi5\Pro\MegaMenu\PreviewSvg;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The "Mega menu" tab: shows whether the site can take a mega menu, and lists the 13 layouts. "Use this layout" puts it on
 * the global header through MegaMenuService (the one write path); once a mega menu is on, "Use this layout" can be limited to one
 * top-level item, and "Undo last change" steps back one change (MegaMenuUndo, the same undo as Recent Pro changes). No JavaScript.
 */
final class MegaMenuPanel
{
    use AdminActions;

    public const TAB           = 'megamenu';
    public const ACTION_APPLY  = 'aied_pro_megamenu_apply';
    public const ACTION_REMOVE = 'aied_pro_megamenu_remove';
    public const ACTION_UNDO   = 'aied_pro_megamenu_undo';
    public const ACTION_UPDATE = 'aied_pro_megamenu_update';

    /** Short-lived, user-bound transients carrying the words of the last result (the layout name or an error message). */
    private const LAST_KEY  = 'aied_pro_megamenu_last_';
    private const ERROR_KEY = 'aied_pro_megamenu_error_';
    private const ITEM_KEY  = 'aied_pro_megamenu_item_';
    private const TTL       = 120;

    public function __construct( private \Closure $unlocked, private MegaMenuService $service, ?\Closure $terminate = null, private ?MegaMenuUndo $undo = null )
    {
        $this->initTerminate( $terminate );
    }

    public function register(): void
    {
        add_filter( 'jhmg_aied_admin_tabs', [ $this, 'addTab' ] );
        add_action( 'jhmg_aied_render_admin_tab', [ $this, 'render' ] );
        add_action( 'admin_post_' . self::ACTION_APPLY, [ $this, 'handleApply' ] );
        add_action( 'admin_post_' . self::ACTION_REMOVE, [ $this, 'handleRemove' ] );
        add_action( 'admin_post_' . self::ACTION_UNDO, [ $this, 'handleUndo' ] );
        add_action( 'admin_post_' . self::ACTION_UPDATE, [ $this, 'handleUpdate' ] );
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
        $itemName = (string) get_transient( self::ITEM_KEY . $me );
        delete_transient( self::ITEM_KEY . $me );
        delete_transient( self::LAST_KEY . $me );
        delete_transient( self::ERROR_KEY . $me );

        $notices = [
            'megamenu_applied'    => [ 'success', sprintf( /* translators: %s: layout name */ __( 'The mega menu "%s" is on your header now. Check it on the site.', 'jhmg-ai-editor-for-divi-5-pro' ), $last ) ],
            'megamenu_applied_item' => [ 'success', sprintf( /* translators: 1: layout name, 2: menu item */ __( 'The layout "%1$s" is on "%2$s" now. The other items kept theirs.', 'jhmg-ai-editor-for-divi-5-pro' ), $last, $itemName ) ],
            'megamenu_undone'     => [ 'success', sprintf( /* translators: %s: what was undone */ __( 'Undone: %s. Your header is back as it was before.', 'jhmg-ai-editor-for-divi-5-pro' ), $last ) ],
            'megamenu_undo_partly' => [ 'warning', __( 'That change could not be fully undone: the header was changed afterwards (for example in the Divi builder), so nothing newer was overwritten.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_undo_none'  => [ 'info', __( 'There is no mega menu change left to undo.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
            'megamenu_updated'    => [ 'success', __( 'The mega menu now matches your menu. Every item kept its layout. "Undo last change" puts the header back as it was.', 'jhmg-ai-editor-for-divi-5-pro' ) ],
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

            <?php if ( $status['applied'] && [] !== $status['items'] ) : ?>
                <ul>
                    <?php foreach ( $status['items'] as $it ) : ?>
                        <li><strong><?php echo esc_html( $it['title'] ); ?></strong>: <?php echo null === $it['layoutName'] ? esc_html__( 'its own layout is not recognised', 'jhmg-ai-editor-for-divi-5-pro' ) : esc_html( $it['layoutName'] ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ( $usable && $status['applied'] && null !== $status['drift'] ) : ?>
                <div class="notice notice-warning inline aied-mm-drift">
                    <p><strong><?php esc_html_e( 'The mega menu in your header no longer matches your menu.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></strong></p>
                    <ul style="list-style:disc;margin-left:20px">
                        <?php foreach ( $this->driftLines( $status['drift'] ) as $line ) : ?>
                            <li><?php echo esc_html( $line ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_UPDATE ); ?>">
                        <?php wp_nonce_field( self::ACTION_UPDATE ); ?>
                        <p>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Update the mega menu', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                            <span class="description"><?php esc_html_e( 'Builds it again from your menu as it is now. Every item keeps its layout, and "Undo last change" steps back.', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span>
                        </p>
                    </form>
                </div>
            <?php endif; ?>

            <?php $lastChange = null === $this->undo ? null : $this->undo->last( $me, current_user_can( 'manage_options' ) ); ?>
            <?php if ( $status['applied'] || null !== $lastChange ) : ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <?php if ( null !== $lastChange ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_UNDO ); ?>">
                            <?php wp_nonce_field( self::ACTION_UNDO ); ?>
                            <button type="submit" class="button button-secondary"><?php esc_html_e( 'Undo last change', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                        </form>
                        <span class="description"><?php echo esc_html( sprintf( /* translators: 1: what the last change was, 2: when (UTC) */ __( 'Last change: %1$s (%2$s UTC)', 'jhmg-ai-editor-for-divi-5-pro' ), (string) $lastChange['summary'], (string) $lastChange['created_at'] ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $status['applied'] ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_REMOVE ); ?>">
                            <?php wp_nonce_field( self::ACTION_REMOVE ); ?>
                            <button type="submit" class="button"><?php esc_html_e( 'Remove mega menu', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h3><?php esc_html_e( 'Choose a layout', 'jhmg-ai-editor-for-divi-5-pro' ); ?></h3>
            <p class="description">
                <?php esc_html_e( '"Latest posts" layouts show a snapshot of the newest posts, taken when you apply them. Applying a layout to all items replaces any earlier mega menu; once one is on, you can limit a layout to a single menu item. "Undo last change" steps back one change (it also appears under Recent Pro changes on the Pro tab).', 'jhmg-ai-editor-for-divi-5-pro' ); ?>
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
                                <?php if ( $usable && $status['applied'] && count( $status['items'] ) > 1 ) : ?>
                                    <select name="item" aria-label="<?php echo esc_attr__( 'Apply to', 'jhmg-ai-editor-for-divi-5-pro' ); ?>">
                                        <option value="0"><?php esc_html_e( 'All menu items', 'jhmg-ai-editor-for-divi-5-pro' ); ?></option>
                                        <?php foreach ( $status['items'] as $it ) : ?>
                                            <option value="<?php echo esc_attr( (string) $it['id'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: menu item */ __( 'Only "%s"', 'jhmg-ai-editor-for-divi-5-pro' ), $it['title'] ) ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
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
        $item = $this->postedItem();
        try {
            $result = $this->service->apply( $id, false, $item );
        } catch ( \InvalidArgumentException | \RuntimeException $e ) {
            $this->failWith( $e->getMessage() );

            return;
        }
        if ( empty( $result['changed'] ) ) {
            $this->redirectToTab( self::TAB, 'megamenu_unchanged' );

            return;
        }
        $layout = Layouts::find( $id );
        $me     = (int) get_current_user_id();
        set_transient( self::LAST_KEY . $me, null === $layout ? $id : (string) $layout['name'], self::TTL );
        if ( null !== $item ) {
            $title = '';
            foreach ( $this->service->status()['items'] as $it ) {
                if ( $it['id'] === $item ) {
                    $title = $it['title'];
                }
            }
            set_transient( self::ITEM_KEY . $me, $title, self::TTL );
        }
        $this->redirectToTab( self::TAB, null === $item ? 'megamenu_applied' : 'megamenu_applied_item' );
    }

    public function handleUpdate(): void
    {
        $this->guardPost( self::ACTION_UPDATE, 'edit_theme_options' );
        if ( ! ( $this->unlocked )() ) {
            $this->failWith( __( 'The mega menu needs an active Pro licence.', 'jhmg-ai-editor-for-divi-5-pro' ) );

            return;
        }
        try {
            $result = $this->service->refresh( false );
        } catch ( \InvalidArgumentException | \RuntimeException $e ) {
            $this->failWith( $e->getMessage() );

            return;
        }
        $this->redirectToTab( self::TAB, empty( $result['changed'] ) ? 'megamenu_unchanged' : 'megamenu_updated' );
    }

    public function handleUndo(): void
    {
        $this->guardPost( self::ACTION_UNDO, 'edit_theme_options' );
        if ( ! ( $this->unlocked )() || null === $this->undo ) {
            $this->failWith( __( 'The mega menu needs an active Pro licence.', 'jhmg-ai-editor-for-divi-5-pro' ) );

            return;
        }
        $me     = (int) get_current_user_id();
        $result = $this->undo->undoLast( $me, current_user_can( 'manage_options' ) );
        if ( 'none' === $result['result'] ) {
            $this->redirectToTab( self::TAB, 'megamenu_undo_none' );

            return;
        }
        set_transient( self::LAST_KEY . $me, $result['summary'], self::TTL );
        $this->redirectToTab( self::TAB, 'undone' === $result['result'] ? 'megamenu_undone' : 'megamenu_undo_partly' );
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

    /** The posted menu item id (a digit string above 0), or null for "all items". A bad value is also null: the service refuses an item that has no panel. */
    private function postedItem(): ?int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guardPost().
        $raw = $_POST['item'] ?? null;
        if ( ! is_string( $raw ) || ! ctype_digit( $raw ) || (int) $raw <= 0 ) {
            return null;
        }

        return (int) $raw;
    }

    /** Remember an error message for the next render of this user's tab, then go back to it. */
    private function failWith( string $message ): void
    {
        set_transient( self::ERROR_KEY . (int) get_current_user_id(), $message, self::TTL );
        $this->redirectToTab( self::TAB, 'megamenu_error' );
    }
}
