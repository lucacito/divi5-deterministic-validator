<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Top-level admin experience — a guided, outcome-focused "SaaS" app:
 * Dashboard · Features · Settings (one menu item, internal views).
 */
final class AdminPage
{
    private const SLUG = 'ai-editor-divi5';
    private const HOOK = 'toplevel_page_ai-editor-divi5';

    public function register(): void
    {
        add_action('admin_menu',            [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_ai_editor_divi5_regenerate_key',     [$this, 'handleRegenerate']);
        add_action('admin_post_ai_editor_divi5_clear_usage',        [$this, 'handleClearUsage']);
        add_action('admin_post_ai_editor_divi5_restore_page',       [$this, 'handleRestorePage']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            __( 'AI Editor for Divi 5', 'jhmg-ai-editor-for-divi-5' ),
            __( 'AI Editor', 'jhmg-ai-editor-for-divi-5' ),
            'manage_options',
            self::SLUG,
            [$this, 'render'],
            'dashicons-edit-large',
            58
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== self::HOOK) {
            return;
        }
        wp_enqueue_style('ai-editor-divi5-admin', plugin_dir_url(AI_EDITOR_DIVI5_FILE) . 'assets/admin.css', [], AI_EDITOR_DIVI5_VERSION);
        wp_enqueue_script('ai-editor-divi5-admin', plugin_dir_url(AI_EDITOR_DIVI5_FILE) . 'assets/admin.js', [], AI_EDITOR_DIVI5_VERSION, true);
    }

    // ---------------------------------------------------------------
    // Form handlers (nonce + capability protected)
    // ---------------------------------------------------------------

    private function redirect(string $tab, string $notice = ''): void
    {
        $args = ['page' => self::SLUG, 'tab' => $tab];
        if ($notice !== '') {
            $args['notice'] = $notice;
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private function guard(string $nonce): void
    {
        if (!current_user_can('manage_options')) {
            wp_die( esc_html__( 'Unauthorized.', 'jhmg-ai-editor-for-divi-5' ) );
        }
        check_admin_referer($nonce);
    }

    public function handleRegenerate(): void
    {
        $this->guard('ai_editor_divi5_regenerate_key');
        ApiKey::generate();
        $this->redirect('settings', 'key_regenerated');
    }

    public function handleClearUsage(): void
    {
        $this->guard('ai_editor_divi5_clear_usage');
        UsageTracker::clear();
        $this->redirect('dashboard', 'usage_cleared');
    }

    public function handleRestorePage(): void
    {
        $this->guard('ai_editor_divi5_restore_page'); // guard() verifies the nonce via check_admin_referer().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
        $pageId    = isset( $_POST['page_id'] ) ? absint( wp_unslash( $_POST['page_id'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in guard().
        $versionId = isset( $_POST['version_id'] ) ? absint( wp_unslash( $_POST['version_id'] ) ) : 0;

        $post = $pageId ? get_post( $pageId ) : null;
        if ( ! $post || 'page' !== $post->post_type || ! current_user_can( 'edit_post', $pageId ) ) {
            $this->redirect( 'dashboard', 'restore_failed' );
        }

        $result = HistoryService::restore( $pageId, $versionId );
        UsageTracker::log( 'restore_version', $pageId, $result['ok'] ? 'valid' : 'error' );

        if ( ! $result['ok'] ) {
            $this->redirect( 'dashboard', 'restore_failed' );
        }
        // Only claim the replaced version was kept when it really was (stored, or identical to the newest snapshot).
        $kept = ! empty( $result['snapshot']['stored'] ) || 'duplicate' === ( $result['snapshot']['reason'] ?? null );
        $this->redirect( 'dashboard', $kept ? 'version_restored' : 'version_restored_no_undo' );
    }

    // ---------------------------------------------------------------
    // Derived data (no new storage — computed from existing classes)
    // ---------------------------------------------------------------

    /** @return array{steps: list<array{label:string, done:bool}>, done:int, total:int, pct:int} */
    private function setupProgress(): array
    {
        $summary = UsageTracker::getSummary();
        $steps = [
            ['label' => __( 'Plugin activated', 'jhmg-ai-editor-for-divi-5' ),            'done' => true],
            ['label' => __( 'AI assistant connected', 'jhmg-ai-editor-for-divi-5' ),      'done' => (int) $summary['total'] > 0],
            ['label' => __( 'First page edit saved', 'jhmg-ai-editor-for-divi-5' ),       'done' => (int) $summary['valid'] > 0],
        ];
        $done  = count(array_filter($steps, static fn($s) => $s['done']));
        $total = count($steps);
        return ['steps' => $steps, 'done' => $done, 'total' => $total, 'pct' => (int) round($done / $total * 100)];
    }

    /**
     * Pure per-assistant connection data. No WordPress calls beyond wp_json_encode
     * (shimmed in tests), so it is unit-testable directly with synthetic inputs.
     *
     * Snippet formats intentionally differ per client:
     *  - Claude / Cursor / Other MCP clients: {"mcpServers":{...}} with a bare url.
     *  - VS Code (mcp.json): {"servers":{...}} with "type":"http".
     *  - ChatGPT: no MCP snippet — it connects via OpenAPI Actions (specUrl).
     *
     * @return array<string, array{transport:string, snippet:?string, guide:?string, specUrl:?string}>
     */
    public static function connectClients(string $siteUrl, string $apiKey): array
    {
        $siteUrl = rtrim($siteUrl, '/');
        $mcpUrl  = $siteUrl . '/wp-json/ai-editor-divi5/v1/mcp';
        $specUrl = $siteUrl . '/wp-json/ai-editor-divi5/v1/openapi.json';
        $bearer  = "Bearer {$apiKey}";
        $flags   = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

        $mcpSnippet = (string) wp_json_encode([
            'mcpServers' => ['ai-editor-divi5' => [
                'url'     => $mcpUrl,
                'headers' => ['Authorization' => $bearer],
            ]],
        ], $flags);

        $vscodeSnippet = (string) wp_json_encode([
            'servers' => ['ai-editor-divi5' => [
                'type'    => 'http',
                'url'     => $mcpUrl,
                'headers' => ['Authorization' => $bearer],
            ]],
        ], $flags);

        $guides = 'https://divi5lab.com/guides/';

        return [
            'claude'  => ['transport' => 'mcp',     'snippet' => $mcpSnippet,    'guide' => $guides . 'connect-claude-to-divi-5', 'specUrl' => null],
            'cursor'  => ['transport' => 'mcp',     'snippet' => $mcpSnippet,    'guide' => $guides . 'connect-cursor-to-divi-5', 'specUrl' => null],
            'vscode'  => ['transport' => 'mcp',     'snippet' => $vscodeSnippet, 'guide' => $guides . 'connect-cursor-to-divi-5', 'specUrl' => null],
            'chatgpt' => ['transport' => 'actions', 'snippet' => null,           'guide' => $guides . 'connect-chatgpt-to-divi-5', 'specUrl' => $specUrl],
            'other'   => ['transport' => 'mcp',     'snippet' => $mcpSnippet,    'guide' => null, 'specUrl' => null],
        ];
    }

    /**
     * Renders the tabbed per-assistant connection UI. Panels are NOT server-hidden;
     * admin.js hides inactive ones on load (progressive enhancement / no-JS fallback).
     *
     * @param array<string, array{transport:string, snippet:?string, guide:?string, specUrl:?string}> $clients
     */
    public function connectCard( array $clients ): void
    {
        $tabs = [
            'claude'  => __( 'Claude', 'jhmg-ai-editor-for-divi-5' ),
            'cursor'  => __( 'Cursor', 'jhmg-ai-editor-for-divi-5' ),
            'vscode'  => __( 'VS Code', 'jhmg-ai-editor-for-divi-5' ),
            'chatgpt' => __( 'ChatGPT', 'jhmg-ai-editor-for-divi-5' ),
            'other'   => __( 'Other MCP client', 'jhmg-ai-editor-for-divi-5' ),
        ];
        ?>
        <p class="aied-connect-reassure">
            <strong><?php esc_html_e( 'MCP is an open standard', 'jhmg-ai-editor-for-divi-5' ); ?></strong> —
            <?php esc_html_e( 'it works with any of these assistants. You don’t need a Claude account or subscription to use it.', 'jhmg-ai-editor-for-divi-5' ); ?>
        </p>

        <div class="aied-llm-tabs" role="tablist">
            <?php $first = true; foreach ( $tabs as $id => $label ) : ?>
                <button type="button"
                        class="aied-llm-tab<?php echo $first ? ' aied-llm-tab--active' : ''; ?>"
                        role="tab"
                        id="aied-tab-<?php echo esc_attr( $id ); ?>"
                        aria-controls="aied-panel-<?php echo esc_attr( $id ); ?>"
                        aria-selected="<?php echo $first ? 'true' : 'false'; ?>"
                        data-target="<?php echo esc_attr( $id ); ?>">
                    <?php echo esc_html( $label ); ?>
                </button>
            <?php $first = false; endforeach; ?>
        </div>

        <?php foreach ( $tabs as $id => $label ) :
            $client = $clients[ $id ] ?? [];
            $guide  = $client['guide'] ?? null; ?>
            <div class="aied-llm-panel" id="aied-panel-<?php echo esc_attr( $id ); ?>" role="tabpanel" aria-labelledby="aied-tab-<?php echo esc_attr( $id ); ?>" tabindex="0">
                <?php $this->connectPanelBody( $id, $client ); ?>
                <?php if ( $guide ) : ?>
                    <a class="aied-guide-link" href="<?php echo esc_url( $guide ); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e( 'Full step-by-step guide →', 'jhmg-ai-editor-for-divi-5' ); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endforeach;
    }

    /** Renders the per-client steps + snippet (MCP) or Actions steps (ChatGPT). */
    private function connectPanelBody( string $id, array $client ): void
    {
        // ChatGPT: OpenAPI Actions, not MCP.
        if ( ( $client['transport'] ?? '' ) === 'actions' ) {
            ?>
            <p class="aied-note"><strong><?php esc_html_e( 'ChatGPT uses Actions, not MCP.', 'jhmg-ai-editor-for-divi-5' ); ?></strong></p>
            <ol class="aied-steps">
                <li><?php esc_html_e( 'In ChatGPT, go to Explore GPTs → Create (or My GPTs → Create a GPT).', 'jhmg-ai-editor-for-divi-5' ); ?></li>
                <li><?php esc_html_e( 'Under Actions, click “Create new action”, then “Import from URL”.', 'jhmg-ai-editor-for-divi-5' ); ?></li>
                <li>
                    <?php esc_html_e( 'Import from this OpenAPI spec URL (copy the whole thing):', 'jhmg-ai-editor-for-divi-5' ); ?>
                    <span class="aied-spec-url-row">
                        <code class="aied-spec-url"><?php echo esc_html( (string) ( $client['specUrl'] ?? '' ) ); ?></code>
                        <button type="button" class="button aied-copy-inline" data-copy="<?php echo esc_attr( (string) ( $client['specUrl'] ?? '' ) ); ?>"><?php esc_html_e( 'Copy', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                    </span>
                </li>
                <li><?php esc_html_e( 'Set Authentication to API Key, type Bearer, and paste the API key shown above.', 'jhmg-ai-editor-for-divi-5' ); ?></li>
            </ol>
            <p class="aied-merge-warn"><?php esc_html_e( 'Requires your site to be reachable over public HTTPS — localhost or an unreachable staging box will not work, because ChatGPT calls your site from OpenAI’s servers.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
            <?php
            return;
        }

        // MCP clients: per-client destination steps, then the snippet + merge warning.
        $steps = $this->mcpSteps( $id );
        ?>
        <ol class="aied-steps"><?php foreach ( $steps as $step ) {
            // Each step may contain a single inline <code> span, pre-escaped in mcpSteps().
            echo '<li>' . $step . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html in mcpSteps.
        } ?></ol>
        <?php if ( ! empty( $client['snippet'] ) ) : ?>
            <div class="aied-snippet-wrap">
                <pre class="aied-snippet" id="snippet-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( (string) $client['snippet'] ); ?></pre>
                <button class="button button-primary aied-copy-btn" data-target="snippet-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Copy', 'jhmg-ai-editor-for-divi-5' ); ?></button>
            </div>
            <p class="aied-merge-warn"><?php esc_html_e( 'Already have MCP servers configured? Paste only the inner "ai-editor-divi5": { … } entry into your existing list — not the whole snippet — or it will not load.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
        <?php endif;
    }

    /**
     * Per-client "where does it go" steps. Returns HTML-safe list items; any inline
     * code is escaped here so the caller can echo them directly.
     *
     * @return list<string>
     */
    private function mcpSteps( string $id ): array
    {
        $code = static fn( string $s ): string => '<code>' . esc_html( $s ) . '</code>';
        switch ( $id ) {
            case 'claude':
                return [
                    esc_html__( 'Claude Desktop: open your config file —', 'jhmg-ai-editor-for-divi-5' ) . ' '
                        . $code( '~/Library/Application Support/Claude/claude_desktop_config.json' ) . ' '
                        . esc_html__( '(macOS) or', 'jhmg-ai-editor-for-divi-5' ) . ' '
                        . $code( '%APPDATA%\\Claude\\claude_desktop_config.json' ) . ' ' . esc_html__( '(Windows).', 'jhmg-ai-editor-for-divi-5' ),
                    esc_html__( 'Paste the snippet below, then fully quit and relaunch Claude Desktop (not just close the window).', 'jhmg-ai-editor-for-divi-5' ),
                    esc_html__( 'Prefer Claude Code (CLI)? Run:', 'jhmg-ai-editor-for-divi-5' ) . ' '
                        . $code( 'claude mcp add --transport http ai-editor-divi5 <MCP-URL> --header "Authorization: Bearer <KEY>"' ),
                ];
            case 'cursor':
                return [
                    esc_html__( 'Open Cursor Settings → MCP (or edit', 'jhmg-ai-editor-for-divi-5' ) . ' ' . $code( '.cursor/mcp.json' ) . ' ' . esc_html__( 'in your project).', 'jhmg-ai-editor-for-divi-5' ),
                    esc_html__( 'Add the snippet below, then use Cursor’s agent mode — plain chat mode will not call the tools.', 'jhmg-ai-editor-for-divi-5' ),
                ];
            case 'vscode':
                return [
                    esc_html__( 'Open Settings → Copilot → MCP Servers (search “MCP” if the path has moved).', 'jhmg-ai-editor-for-divi-5' ),
                    esc_html__( 'Add the snippet below, then use Copilot’s agent mode — standard chat mode will not reach the plugin.', 'jhmg-ai-editor-for-divi-5' ),
                ];
            case 'other':
            default:
                return [
                    esc_html__( 'Add the snippet below to your assistant’s MCP configuration.', 'jhmg-ai-editor-for-divi-5' ),
                    esc_html__( 'Works with Windsurf and any client that supports MCP Streamable HTTP.', 'jhmg-ai-editor-for-divi-5' ),
                ];
        }
    }

    // ---------------------------------------------------------------
    // Render shell
    // ---------------------------------------------------------------

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only nav.
        $tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );
        // Extension point: add-ons may contribute extra tabs (slug => label).
        $extra = (array) apply_filters( 'jhmg_aied_admin_tabs', [] );
        $extra = array_diff_key( $extra, array_flip( [ 'dashboard', 'features', 'settings' ] ) );
        if (!in_array($tab, array_merge(['dashboard', 'features', 'settings'], array_map('strval', array_keys($extra))), true)) {
            $tab = 'dashboard';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- set by our own nonce-verified redirects.
        $notice  = sanitize_key( $_GET['notice'] ?? '' );

        $tabs = [
            'dashboard' => __( 'Dashboard', 'jhmg-ai-editor-for-divi-5' ),
            'features'  => __( 'Features', 'jhmg-ai-editor-for-divi-5' ),
            'settings'  => __( 'Settings', 'jhmg-ai-editor-for-divi-5' ),
        ];
        foreach ( $extra as $xslug => $xlabel ) {
            $tabs[ (string) $xslug ] = (string) $xlabel;
        }
        ?>
        <div class="wrap aied">
            <div class="aied-topbar">
                <div class="aied-topbar__brand">
                    <span class="aied-logo">&#10086;</span>
                    <div>
                        <strong><?php esc_html_e( 'AI Editor for Divi 5', 'jhmg-ai-editor-for-divi-5' ); ?></strong>
                        <span class="aied-topbar__ver">v<?php echo esc_html( AI_EDITOR_DIVI5_VERSION ); ?></span>
                    </div>
                </div>
            </div>

            <nav class="aied-nav">
                <?php foreach ( $tabs as $slug => $tlabel ) :
                    $url = add_query_arg(['page' => self::SLUG, 'tab' => $slug], admin_url('admin.php')); ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="aied-nav__item <?php echo $tab === $slug ? 'is-active' : ''; ?>">
                        <?php echo esc_html( $tlabel ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php $this->notice( $notice ); ?>
            <?php $this->diviCompatNotice(); ?>

            <div class="aied-view">
                <?php
                switch ( $tab ) {
                    case 'features': $this->viewFeatures(); break;
                    case 'settings': $this->viewSettings(); break;
                    default:
                        if ( isset( $extra[ $tab ] ) ) {
                            do_action( 'jhmg_aied_render_admin_tab', $tab );
                        } else {
                            $this->viewDashboard();
                        }
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function notice( string $notice ): void
    {
        $map = [
            'key_regenerated'    => __( 'API key regenerated. Update your AI assistant configuration.', 'jhmg-ai-editor-for-divi-5' ),
            'usage_cleared'      => __( 'Activity log cleared.', 'jhmg-ai-editor-for-divi-5' ),
            'version_restored'   => __( 'Previous version restored. The version it replaced was saved too, so you can undo this.', 'jhmg-ai-editor-for-divi-5' ),
        ];
        if ( isset( $map[ $notice ] ) ) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $map[ $notice ] ));
        } elseif ( $notice === 'version_restored_no_undo' ) {
            printf('<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html__( 'Previous version restored, but the version it replaced could not be kept, so this restore cannot be undone from here.', 'jhmg-ai-editor-for-divi-5' ));
        } elseif ( $notice === 'restore_failed' ) {
            printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html__( 'Could not restore that version.', 'jhmg-ai-editor-for-divi-5' ));
        }
    }

    private function diviCompatNotice(): void
    {
        $status = DiviCompat::status( DiviCompat::installedVersion() );
        if ( 'newer' !== $status['level'] ) {
            return;
        }
        echo '<div class="notice notice-info inline"><p>' . esc_html( $status['message'] ) . '</p></div>';
    }

    // ---------------------------------------------------------------
    // View: Dashboard
    // ---------------------------------------------------------------

    private function historySection(): void
    {
        $rows = HistoryService::recent( 5 );
        if ( [] === $rows ) {
            return;
        }
        ?>
        <h3 class="aied-section-title"><?php esc_html_e( 'Recent AI edits', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
        <div class="aied-card">
            <p class="aied-muted"><?php esc_html_e( 'Every AI save keeps the previous version when it can. Restore it here if an edit was not what you wanted.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
            <table class="widefat striped">
                <tbody>
                <?php foreach ( $rows as $row ) :
                    $title = '' === $row['title'] ? __( '(no title)', 'jhmg-ai-editor-for-divi-5' ) : $row['title']; ?>
                    <tr>
                        <td><strong><?php echo esc_html( $title ); ?></strong></td>
                        <?php
                        $savedAt = (string) $row['saved_at'];
                        $ts      = strtotime( $savedAt );
                        $when    = false === $ts
                            ? $savedAt
                            /* translators: %s: how long ago, e.g. "5 minutes" */
                            : sprintf( __( '%s ago', 'jhmg-ai-editor-for-divi-5' ), human_time_diff( $ts ) );
                        ?>
                        <td title="<?php echo esc_attr( $savedAt ); ?>"><?php echo esc_html( $when ); ?></td>
                        <td><code><?php echo esc_html( (string) $row['tool'] ); ?></code></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                  onsubmit="return confirm('<?php echo esc_js( __( 'Restore the version saved before this AI edit?', 'jhmg-ai-editor-for-divi-5' ) ); ?>')">
                                <input type="hidden" name="action" value="ai_editor_divi5_restore_page">
                                <input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $row['page_id'] ); ?>">
                                <input type="hidden" name="version_id" value="<?php echo esc_attr( (string) $row['version_id'] ); ?>">
                                <?php wp_nonce_field( 'ai_editor_divi5_restore_page' ); ?>
                                <button type="submit" class="button"><?php esc_html_e( 'Restore previous version', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function viewDashboard(): void
    {
        $summary  = UsageTracker::getSummary();
        $progress = $this->setupProgress();
        $connected = (int) $summary['total'] > 0;
        ?>
        <div class="aied-hello">
            <h1><?php esc_html_e( 'Welcome 👋', 'jhmg-ai-editor-for-divi-5' ); ?></h1>
            <p><?php esc_html_e( 'Edit your Divi 5 site by chatting with your AI assistant. Here’s what to do next.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
        </div>

        <div class="aied-grid aied-grid--2">
            <!-- Primary action -->
            <div class="aied-card aied-card--primary">
                <?php if ( ! $connected ) : ?>
                    <span class="aied-eyebrow"><?php esc_html_e( 'Start here', 'jhmg-ai-editor-for-divi-5' ); ?></span>
                    <h2><?php esc_html_e( 'Connect your AI assistant', 'jhmg-ai-editor-for-divi-5' ); ?></h2>
                    <p><?php esc_html_e( 'Paste one config into Claude, Cursor, VS Code, or ChatGPT — then edit your site in plain English.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
                    <a class="button button-primary button-hero" href="<?php echo esc_url( add_query_arg(['page' => self::SLUG, 'tab' => 'settings'], admin_url('admin.php')) ); ?>">
                        <?php esc_html_e( 'Connect now', 'jhmg-ai-editor-for-divi-5' ); ?>
                    </a>
                <?php else : ?>
                    <span class="aied-eyebrow aied-eyebrow--ok">&#10003; <?php esc_html_e( 'Connected', 'jhmg-ai-editor-for-divi-5' ); ?></span>
                    <h2><?php esc_html_e( 'Try this in your AI assistant', 'jhmg-ai-editor-for-divi-5' ); ?></h2>
                    <p class="aied-try">“<?php esc_html_e( 'Change the hero heading on my Home page to “Built for you”.', 'jhmg-ai-editor-for-divi-5' ); ?>”</p>
                    <a class="button button-secondary" href="<?php echo esc_url( admin_url('edit.php?post_type=page') ); ?>"><?php esc_html_e( 'View your pages', 'jhmg-ai-editor-for-divi-5' ); ?></a>
                <?php endif; ?>
            </div>

            <!-- Setup progress -->
            <div class="aied-card">
                <div class="aied-card__head">
                    <h3><?php esc_html_e( 'Setup progress', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
                    <span class="aied-muted"><?php echo esc_html( $progress['done'] . '/' . $progress['total'] ); ?></span>
                </div>
                <div class="aied-progress"><span style="width:<?php echo esc_attr( $progress['pct'] ); ?>%"></span></div>
                <ul class="aied-checklist">
                    <?php foreach ( $progress['steps'] as $s ) : ?>
                        <li class="<?php echo $s['done'] ? 'is-done' : ''; ?>">
                            <span class="aied-check"><?php echo $s['done'] ? '&#10003;' : '&#9675;'; ?></span>
                            <?php echo esc_html( $s['label'] ); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- Achievements -->
        <h3 class="aied-section-title"><?php esc_html_e( 'Your results', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
        <?php if ( $connected ) : ?>
            <div class="aied-stats">
                <div class="aied-stat"><span class="aied-stat__n"><?php echo esc_html( $summary['total'] ); ?></span><span class="aied-stat__l"><?php esc_html_e( 'AI edits processed', 'jhmg-ai-editor-for-divi-5' ); ?></span></div>
                <div class="aied-stat"><span class="aied-stat__n aied-pos"><?php echo esc_html( $summary['valid'] ); ?></span><span class="aied-stat__l"><?php esc_html_e( 'Changes saved', 'jhmg-ai-editor-for-divi-5' ); ?></span></div>
                <div class="aied-stat"><span class="aied-stat__n aied-warn"><?php echo esc_html( $summary['invalid'] ); ?></span><span class="aied-stat__l"><?php esc_html_e( 'Invalid layouts blocked', 'jhmg-ai-editor-for-divi-5' ); ?></span></div>
                <div class="aied-stat"><span class="aied-stat__n"><?php echo esc_html( $summary['today'] ); ?></span><span class="aied-stat__l"><?php esc_html_e( 'Today', 'jhmg-ai-editor-for-divi-5' ); ?></span></div>
            </div>
        <?php else : ?>
            <div class="aied-card aied-empty">
                <p><strong><?php esc_html_e( 'No activity yet', 'jhmg-ai-editor-for-divi-5' ); ?></strong></p>
                <p class="aied-muted"><?php esc_html_e( 'Connect your AI assistant and make your first edit — your results will show up here.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
            </div>
        <?php endif; ?>

        <?php $this->historySection(); ?>
        <?php
    }

    // ---------------------------------------------------------------
    // View: Features
    // ---------------------------------------------------------------

    private function viewFeatures(): void
    {
        // [title, description, tools it covers] — the 14 tools your AI assistant can call.
        $features = [
            [ __( 'Edit pages in plain English', 'jhmg-ai-editor-for-divi-5' ), __( 'Tell your AI what to change and it updates the live Divi 5 layout — no builder, no copy-paste.', 'jhmg-ai-editor-for-divi-5' ), 'update_page_layout, edit_page_content' ],
            [ __( 'Validated, safe saves', 'jhmg-ai-editor-for-divi-5' ), __( 'Every change is checked against 56+ Divi 5 module types before saving, so broken layouts never reach your site.', 'jhmg-ai-editor-for-divi-5' ), 'validate_layout' ],
            [ __( 'Read & understand any page', 'jhmg-ai-editor-for-divi-5' ), __( 'Your AI can list and read existing pages to make precise, context-aware edits.', 'jhmg-ai-editor-for-divi-5' ), 'list_divi_pages, get_page_layout' ],
            [ __( 'Undo AI edits', 'jhmg-ai-editor-for-divi-5' ), __( 'Every AI save keeps the previous version, so you or your AI can browse the history and restore an earlier one.', 'jhmg-ai-editor-for-divi-5' ), 'list_page_history, get_page_history_entry, restore_page_version' ],
            [ __( 'Create new pages (as drafts)', 'jhmg-ai-editor-for-divi-5' ), __( 'Generate brand-new pages from a prompt — always saved as a draft, validated, ready for you to review and publish.', 'jhmg-ai-editor-for-divi-5' ), 'create_page' ],
            [ __( 'Build entire websites', 'jhmg-ai-editor-for-divi-5' ), __( 'A built-in blueprint helps your AI plan a cohesive multi-page site (home, about, services, contact) with shared styling, then tells you which page to set as the front page and which to add to the menu.', 'jhmg-ai-editor-for-divi-5' ), 'get_site_guide' ],
            [ __( 'Conversion-focused page generation', 'jhmg-ai-editor-for-divi-5' ), __( 'A built-in landing-page blueprint, design vocabulary and proven section patterns guide the AI to produce polished, on-brand pages with a strategic structure built to convert.', 'jhmg-ai-editor-for-divi-5' ), 'get_landing_guide, get_style_guide, get_section_recipes' ],
            [ __( 'Relevant images', 'jhmg-ai-editor-for-divi-5' ), __( 'An image guide teaches your AI to pick the right visual for each section, so pages do not ship with empty image slots.', 'jhmg-ai-editor-for-divi-5' ), 'get_image_guide' ],
        ];
        ?>
        <div class="aied-hello"><h1><?php esc_html_e( 'Features', 'jhmg-ai-editor-for-divi-5' ); ?></h1>
            <p><?php esc_html_e( 'Everything your AI assistant can do with your Divi 5 site.', 'jhmg-ai-editor-for-divi-5' ); ?></p></div>

        <h3 class="aied-section-title"><?php esc_html_e( 'Included', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
        <div class="aied-grid aied-grid--2">
            <?php foreach ( $features as [$t, $d, $tools] ) : ?>
                <div class="aied-card aied-feature">
                    <span class="aied-feature__badge aied-feature__badge--free">&#10003;</span>
                    <div><h4><?php echo esc_html( $t ); ?></h4><p class="aied-muted"><?php echo esc_html( $d ); ?></p><p class="aied-muted"><code><?php echo esc_html( $tools ); ?></code></p></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    // ---------------------------------------------------------------
    // View: Settings
    // ---------------------------------------------------------------

    private function viewSettings(): void
    {
        $key = ApiKey::get();
        ?>
        <div class="aied-hello"><h1><?php esc_html_e( 'Settings', 'jhmg-ai-editor-for-divi-5' ); ?></h1>
            <p><?php esc_html_e( 'Connect your AI assistant and manage your API key.', 'jhmg-ai-editor-for-divi-5' ); ?></p></div>

        <!-- Connection -->
        <div class="aied-card">
            <h3><?php esc_html_e( 'Connect your AI assistant', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
            <p class="aied-muted"><?php esc_html_e( 'Your API key authorizes your AI assistant to read and edit this site. Keep it private.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
            <div class="aied-key-row">
                <code class="aied-key" id="aied-api-key" data-key="<?php echo esc_attr( $key ); ?>">••••••••••••••••••••••••</code>
                <button type="button" class="button" id="aied-toggle-key"><?php esc_html_e( 'Show', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                <button type="button" class="button button-primary" data-copy="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Copy', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                    <input type="hidden" name="action" value="ai_editor_divi5_regenerate_key">
                    <?php wp_nonce_field( 'ai_editor_divi5_regenerate_key' ); ?>
                    <button type="submit" class="button aied-btn-danger" onclick="return confirm('<?php echo esc_js( __( 'Regenerate the key? You will need to update your AI assistant.', 'jhmg-ai-editor-for-divi-5' ) ); ?>')"><?php esc_html_e( 'Regenerate', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                </form>
            </div>
            <?php $this->connectCard( self::connectClients( rtrim( get_site_url(), '/' ), $key ) ); ?>
        </div>

        <!-- Activity log -->
        <?php $recent = UsageTracker::getRecent(10); if ( ! empty( $recent ) ) : ?>
        <div class="aied-card">
            <div class="aied-card__head">
                <h3><?php esc_html_e( 'Recent activity', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="ai_editor_divi5_clear_usage">
                    <?php wp_nonce_field( 'ai_editor_divi5_clear_usage' ); ?>
                    <button type="submit" class="button"><?php esc_html_e( 'Clear log', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                </form>
            </div>
            <table class="widefat striped aied-usage-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Time', 'jhmg-ai-editor-for-divi-5' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'jhmg-ai-editor-for-divi-5' ); ?></th>
                    <th><?php esc_html_e( 'Result', 'jhmg-ai-editor-for-divi-5' ); ?></th>
                    <th><?php esc_html_e( 'Assistant', 'jhmg-ai-editor-for-divi-5' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ( $recent as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( date_i18n( 'M j, H:i', strtotime( $row['created_at'] ) ) ); ?></td>
                            <td><code><?php echo esc_html( $row['endpoint'] ); ?></code></td>
                            <td><span class="aied-result aied-result--<?php echo esc_attr( $row['result'] ); ?>"><?php echo esc_html( strtoupper( $row['result'] ) ); ?></span></td>
                            <td><?php echo $row['client'] ? esc_html( $row['client'] ) : '&#8212;'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php
    }
}
