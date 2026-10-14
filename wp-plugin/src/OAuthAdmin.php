<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The "Claude on the web" card on the Settings screen: the owner's switch for the OAuth sign-in, the address to
 * paste into claude.ai, and the list of connected apps with a Revoke button. Handlers need manage_options and a nonce.
 */
final class OAuthAdmin
{
    private const PAGE = 'ai-editor-divi5';

    public static function registerHooks(): void
    {
        add_action( 'admin_post_ai_editor_divi5_oauth_toggle', [ self::class, 'handleToggle' ] );
        add_action( 'admin_post_ai_editor_divi5_oauth_revoke', [ self::class, 'handleRevoke' ] );
    }

    public static function handleToggle(): void
    {
        self::guard( 'ai_editor_divi5_oauth_toggle' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
        $turnOn = '1' === sanitize_text_field( wp_unslash( $_POST['turn_on'] ?? '' ) );
        if ( $turnOn ) {
            self::back( OAuthSettings::enable() ? 'oauth_on' : 'oauth_failed' );
        }
        OAuthSettings::disable();
        self::back( 'oauth_off' );
    }

    public static function handleRevoke(): void
    {
        self::guard( 'ai_editor_divi5_oauth_revoke' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
        $family = sanitize_text_field( wp_unslash( $_POST['family'] ?? '' ) );
        if ( 1 === preg_match( '/^[0-9a-f]{32}$/', $family ) && OAuthSettings::enabled() ) {
            OAuthRuntime::tokens()->revokeFamily( $family );
        }
        self::back( 'oauth_revoked' );
    }

    /**
     * The rows of the connected-apps table. Pure: the lookups are passed in.
     *
     * @param list<array{family:string,client_id:string,user_id:int,created_at:int}> $grants
     * @param callable(string):string $clientName
     * @param callable(int):string    $userName
     * @return list<array{family:string,app:string,user:string,since:int}>
     */
    public static function rows( array $grants, callable $clientName, callable $userName ): array
    {
        $rows = [];
        foreach ( $grants as $g ) {
            $app    = $clientName( $g['client_id'] );
            $rows[] = [
                'family' => $g['family'],
                'app'    => '' !== $app ? $app : 'Unknown app',
                'user'   => $userName( $g['user_id'] ),
                'since'  => $g['created_at'],
            ];
        }

        return $rows;
    }

    public static function card(): void
    {
        $on  = OAuthSettings::enabled();
        $cfg = OAuthRuntime::config();
        ?>
        <div class="aied-card" id="aied-oauth-card">
            <h3><?php esc_html_e( 'Claude on the web, and other apps that sign in', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
            <p class="aied-muted"><?php esc_html_e( 'Connect claude.ai without installing anything on your computer. You paste one address into Claude, sign in to this site and press Allow. Claude then uses the plugin as you, with the same permissions your account has. Off by default; nothing changes for the other ways of connecting.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
            <?php if ( ! $on ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="ai_editor_divi5_oauth_toggle">
                    <input type="hidden" name="turn_on" value="1">
                    <?php wp_nonce_field( 'ai_editor_divi5_oauth_toggle' ); ?>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Turn on sign-in for Claude', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                </form>
            <?php else : ?>
                <?php self::connectedView( $cfg ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function connectedView( OAuthConfig $cfg ): void
    {
        $warnings = [];
        if ( 0 !== strpos( $cfg->issuer, 'https://' ) ) {
            $warnings[] = __( 'Your site address does not start with https. Claude’s servers connect from the internet and only reach a public https address, so this will not work on a local or http-only site. Assistants on your own computer (Claude Code, Cursor, Gemini CLI) keep working with the API key.', 'jhmg-ai-editor-for-divi-5' );
        }
        if ( '' !== $cfg->homePath ) {
            $warnings[] = __( 'Your site lives in a subfolder. Sign-in has only been verified on sites at the root of their domain, so it may not work here.', 'jhmg-ai-editor-for-divi-5' );
        }
        ?>
        <?php foreach ( $warnings as $w ) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html( $w ); ?></p></div>
        <?php endforeach; ?>
        <p><strong><?php esc_html_e( 'The address to paste into Claude:', 'jhmg-ai-editor-for-divi-5' ); ?></strong></p>
        <div class="aied-key-row">
            <code class="aied-key" id="aied-oauth-url"><?php echo esc_html( $cfg->resource ); ?></code>
            <button type="button" class="button button-primary" data-copy="<?php echo esc_attr( $cfg->resource ); ?>"><?php esc_html_e( 'Copy', 'jhmg-ai-editor-for-divi-5' ); ?></button>
        </div>
        <ol class="aied-steps">
            <li><?php esc_html_e( 'In claude.ai open Customize → Connectors and choose “Add custom connector”. (On a Team or Enterprise plan an Owner adds it under Organization settings → Connectors.)', 'jhmg-ai-editor-for-divi-5' ); ?></li>
            <li><?php esc_html_e( 'Paste the address above and leave the advanced settings alone.', 'jhmg-ai-editor-for-divi-5' ); ?></li>
            <li><?php esc_html_e( 'Click Connect. This site opens: sign in if asked, then press Allow.', 'jhmg-ai-editor-for-divi-5' ); ?></li>
            <li><?php esc_html_e( 'Ask Claude to list your Divi pages. If something fails, press “Test connection” above.', 'jhmg-ai-editor-for-divi-5' ); ?></li>
        </ol>
        <?php
        $now  = time();
        $rows = self::rows(
            OAuthRuntime::store()->liveGrants( $now, 50 ),
            static function ( string $id ): string {
                $client = OAuthRuntime::clients()->get( $id );

                return null === $client ? '' : $client['name'];
            },
            static function ( int $id ): string {
                $u = get_userdata( $id );

                return false === $u ? __( '(deleted user)', 'jhmg-ai-editor-for-divi-5' ) : (string) $u->display_name;
            }
        );
        ?>
        <h4><?php esc_html_e( 'Connected apps', 'jhmg-ai-editor-for-divi-5' ); ?></h4>
        <?php if ( array() === $rows ) : ?>
            <p class="aied-muted"><?php esc_html_e( 'No app is connected yet.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
        <?php else : ?>
            <table class="widefat striped aied-oauth-grants">
                <thead><tr>
                    <th><?php esc_html_e( 'App', 'jhmg-ai-editor-for-divi-5' ); ?></th>
                    <th><?php esc_html_e( 'Signed in as', 'jhmg-ai-editor-for-divi-5' ); ?></th>
                    <th><?php esc_html_e( 'Connected', 'jhmg-ai-editor-for-divi-5' ); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $rows as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['app'] ); ?></td>
                        <td><?php echo esc_html( $row['user'] ); ?></td>
                        <td><?php
                            /* translators: %s: how long ago, such as "3 hours" */
                            echo esc_html( sprintf( __( '%s ago', 'jhmg-ai-editor-for-divi-5' ), human_time_diff( $row['since'], $now ) ) );
                        ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aied-inline-form">
                                <input type="hidden" name="action" value="ai_editor_divi5_oauth_revoke">
                                <input type="hidden" name="family" value="<?php echo esc_attr( $row['family'] ); ?>">
                                <?php wp_nonce_field( 'ai_editor_divi5_oauth_revoke' ); ?>
                                <button type="submit" class="button aied-btn-danger"><?php esc_html_e( 'Disconnect', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aied-inline-form" style="margin-top:12px"
              data-confirm="<?php echo esc_attr__( 'Turn sign-in off? Every connected app is disconnected and must be added again.', 'jhmg-ai-editor-for-divi-5' ); ?>">
            <input type="hidden" name="action" value="ai_editor_divi5_oauth_toggle">
            <input type="hidden" name="turn_on" value="0">
            <?php wp_nonce_field( 'ai_editor_divi5_oauth_toggle' ); ?>
            <button type="submit" class="button"><?php esc_html_e( 'Turn off and disconnect every app', 'jhmg-ai-editor-for-divi-5' ); ?></button>
        </form>
        <?php
    }

    private static function guard( string $nonce ): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'jhmg-ai-editor-for-divi-5' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( $nonce );
    }

    private static function back( string $notice ): never
    {
        wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'settings', 'notice' => $notice ], admin_url( 'admin.php' ) ) );
        exit;
    }
}
