<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Admin;

use AiEditorDivi5\Pro\Companion\FreeVersionNotice;
use AiEditorDivi5\Pro\ProPage;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The "What's new" panel at the top of the Pro tab, and nowhere else (never an admin notice). The announcement is in
 * data/whats-new.php (version, title, bullets, link). A user dismisses it once and it returns only when that file carries a newer
 * version. Only a user who can edit pages sees it.
 *
 * When the free plugin is older than 4.17.0 the update message is shown here instead, and it cannot be dismissed: it says what is
 * off and what to do about it.
 */
final class WhatsNewPanel
{
    use AdminActions;

    public const META   = 'aied_pro_whats_new_dismissed';
    public const ACTION = 'aied_pro_dismiss_whats_new';
    public const FILE   = __DIR__ . '/../../data/whats-new.php';

    public const MIN_BULLETS = 3;
    public const MAX_BULLETS = 5;

    public function __construct( ?\Closure $terminate = null )
    {
        $this->initTerminate( $terminate );
    }

    public function register(): void
    {
        // Priority 5: before the licence and tool cards (ProPage renders at 10), so it is the first thing on the tab.
        add_action( 'jhmg_aied_render_admin_tab', [ $this, 'render' ], 5 );
        add_action( 'admin_post_' . self::ACTION, [ $this, 'handleDismiss' ] );
    }

    /** @return ?array{version:string, title:string, bullets:list<string>, link:array{label:string, url:string}} */
    public static function content( ?string $file = null ): ?array
    {
        $file = $file ?? self::FILE;
        if ( ! is_readable( $file ) ) {
            return null;
        }
        $data = include $file;

        return self::valid( $data ) ? $data : null;
    }

    public static function valid( mixed $data ): bool
    {
        if ( ! is_array( $data ) || ! is_string( $data['version'] ?? null ) || 1 !== preg_match( '/^\d+(?:\.\d+)*$/', $data['version'] ) ) {
            return false;
        }
        if ( ! is_string( $data['title'] ?? null ) || '' === trim( $data['title'] ) ) {
            return false;
        }
        $bullets = $data['bullets'] ?? null;
        if ( ! is_array( $bullets ) || count( $bullets ) < self::MIN_BULLETS || count( $bullets ) > self::MAX_BULLETS ) {
            return false;
        }
        foreach ( $bullets as $b ) {
            if ( ! is_string( $b ) || '' === trim( $b ) ) {
                return false;
            }
        }
        $link = $data['link'] ?? null;

        return is_array( $link ) && is_string( $link['label'] ?? null ) && '' !== trim( $link['label'] )
            && is_string( $link['url'] ?? null ) && str_starts_with( $link['url'], 'https://' );
    }

    /** @param mixed $dismissedVersion the stored user meta value */
    public static function shouldShow( ?array $content, mixed $dismissedVersion, bool $canEditPages ): bool
    {
        if ( null === $content || ! $canEditPages ) {
            return false;
        }

        return ! ( is_string( $dismissedVersion ) && '' !== $dismissedVersion && version_compare( $dismissedVersion, $content['version'], '>=' ) );
    }

    /** @return 'update'|'whats_new'|'none' what the top of the Pro tab shows */
    public static function mode( ?string $freeVersion, ?array $content, mixed $dismissedVersion, bool $canEditPages ): string
    {
        if ( ! $canEditPages ) {
            return 'none';
        }
        if ( null !== FreeVersionNotice::message( $freeVersion ) ) {
            return 'update';
        }

        return self::shouldShow( $content, $dismissedVersion, $canEditPages ) ? 'whats_new' : 'none';
    }

    public function render( string $tab ): void
    {
        if ( ProPage::TAB !== $tab ) {
            return;
        }
        $free    = defined( 'AI_EDITOR_DIVI5_VERSION' ) ? (string) AI_EDITOR_DIVI5_VERSION : null;
        $content = self::content();
        $mode    = self::mode( $free, $content, get_user_meta( get_current_user_id(), self::META, true ), current_user_can( 'edit_pages' ) );
        if ( 'update' === $mode ) {
            ?>
            <div class="aied-card aied-pro">
                <div class="notice notice-warning inline"><p><?php echo esc_html( (string) FreeVersionNotice::message( $free ) ); ?>
                    <a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Go to Plugins', 'jhmg-ai-editor-for-divi-5-pro' ); ?></a></p></div>
            </div>
            <?php
            return;
        }
        if ( 'whats_new' !== $mode || null === $content ) {
            return;
        }
        ?>
        <div class="aied-card aied-pro aied-whats-new">
            <h2><?php echo esc_html( $content['title'] ); ?></h2>
            <ul class="ul-disc">
                <?php foreach ( $content['bullets'] as $bullet ) : ?>
                    <li><?php echo esc_html( $bullet ); ?></li>
                <?php endforeach; ?>
            </ul>
            <p>
                <a href="<?php echo esc_url( $content['link']['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $content['link']['label'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'jhmg-ai-editor-for-divi-5-pro' ); ?></span></a>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
                <?php wp_nonce_field( self::ACTION ); ?>
                <button type="submit" class="button"><?php esc_html_e( 'Got it', 'jhmg-ai-editor-for-divi-5-pro' ); ?></button>
            </form>
        </div>
        <?php
    }

    public function handleDismiss(): void
    {
        $this->guardPost( self::ACTION, 'edit_pages' );
        $content = self::content();
        if ( null !== $content ) {
            update_user_meta( get_current_user_id(), self::META, $content['version'] );
        }
        $this->redirectToProTab( 'whats_new_dismissed' );
    }
}
