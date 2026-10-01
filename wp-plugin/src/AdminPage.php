<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Top-level admin experience — a guided, outcome-focused "SaaS" app:
 * Dashboard · Features · Settings · Upgrade (one menu item, internal views).
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
        add_action('admin_post_ai_editor_divi5_activate_license',   [$this, 'handleActivateLicense']);
        add_action('admin_post_ai_editor_divi5_deactivate_license', [$this, 'handleDeactivateLicense']);
        add_action('admin_post_ai_editor_divi5_delete_proposal',    [$this, 'handleDeleteProposal']);
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

    public function handleActivateLicense(): void
    {
        $this->guard('ai_editor_divi5_activate_license'); // guard() verifies the nonce via check_admin_referer().
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- opaque key, trimmed in Licensing; nonce verified in guard().
        $key    = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
        $result = $key !== '' ? Licensing::activate( $key ) : [ 'ok' => false, 'error' => 'invalid_request' ];
        $this->redirect('upgrade', $result['ok'] ? 'license_activated' : 'license_invalid');
    }

    public function handleDeactivateLicense(): void
    {
        $this->guard('ai_editor_divi5_deactivate_license');
        Licensing::deactivate();
        $this->redirect('upgrade', 'license_deactivated');
    }

    public function handleDeleteProposal(): void
    {
        $this->guard('ai_editor_divi5_delete_proposal'); // guard() verifies the nonce via check_admin_referer().
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- opaque id, sanitized; nonce verified in guard().
        PhpProposals::delete( sanitize_text_field( wp_unslash( $_POST['proposal_id'] ?? '' ) ) );
        $this->redirect('settings', 'proposal_deleted');
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

    private function isPremium(): bool
    {
        return Licensing::isPremium();
    }

    /** @return array{steps: list<array{label:string, done:bool}>, done:int, total:int, pct:int} */
    private function setupProgress(): array
    {
        $summary = UsageTracker::getSummary();
        $steps = [
            ['label' => __( 'Plugin activated', 'jhmg-ai-editor-for-divi-5' ),            'done' => true],
            ['label' => __( 'AI assistant connected', 'jhmg-ai-editor-for-divi-5' ),      'done' => (int) $summary['total'] > 0],
            ['label' => __( 'First page edit saved', 'jhmg-ai-editor-for-divi-5' ),       'done' => (int) $summary['valid'] > 0],
            ['label' => __( 'Premium unlocked', 'jhmg-ai-editor-for-divi-5' ),            'done' => $this->isPremium()],
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
        if (!in_array($tab, ['dashboard', 'features', 'settings', 'upgrade'], true)) {
            $tab = 'dashboard';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- set by our own nonce-verified redirects.
        $notice  = sanitize_key( $_GET['notice'] ?? '' );
        $premium = $this->isPremium();

        $tabs = [
            'dashboard' => __( 'Dashboard', 'jhmg-ai-editor-for-divi-5' ),
            'features'  => __( 'Features', 'jhmg-ai-editor-for-divi-5' ),
            'settings'  => __( 'Settings', 'jhmg-ai-editor-for-divi-5' ),
            'upgrade'   => $premium ? __( 'Account', 'jhmg-ai-editor-for-divi-5' ) : __( 'Upgrade', 'jhmg-ai-editor-for-divi-5' ),
        ];
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
                <span class="aied-plan aied-plan--<?php echo $premium ? 'pro' : 'free'; ?>">
                    <?php echo $premium ? esc_html__( 'Premium', 'jhmg-ai-editor-for-divi-5' ) : esc_html__( 'Free plan', 'jhmg-ai-editor-for-divi-5' ); ?>
                </span>
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
                    case 'upgrade':  $this->viewUpgrade();  break;
                    default:         $this->viewDashboard(); break;
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
            'license_activated'  => __( 'License activated — premium features are now unlocked.', 'jhmg-ai-editor-for-divi-5' ),
            'license_deactivated'=> __( 'License removed.', 'jhmg-ai-editor-for-divi-5' ),
            'proposal_deleted'   => __( 'Code proposal deleted.', 'jhmg-ai-editor-for-divi-5' ),
            'version_restored'   => __( 'Previous version restored. The version it replaced was saved too, so you can undo this.', 'jhmg-ai-editor-for-divi-5' ),
        ];
        if ( isset( $map[ $notice ] ) ) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $map[ $notice ] ));
        } elseif ( $notice === 'license_invalid' ) {
            printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html__( 'That license key is not valid for this site.', 'jhmg-ai-editor-for-divi-5' ));
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
        $premium  = $this->isPremium();
        $proposals = PhpProposals::count();
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

        <!-- Recommendations -->
        <h3 class="aied-section-title"><?php esc_html_e( 'Recommended for you', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
        <div class="aied-grid aied-grid--3">
            <?php if ( $proposals > 0 ) : ?>
                <div class="aied-card aied-rec">
                    <h4><?php echo esc_html( sprintf( /* translators: %d count */ _n( '%d code proposal to review', '%d code proposals to review', $proposals, 'jhmg-ai-editor-for-divi-5' ), $proposals ) ); ?></h4>
                    <p class="aied-muted"><?php esc_html_e( 'Your AI drafted PHP for you to review and apply safely.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
                    <a class="button" href="<?php echo esc_url( add_query_arg(['page' => self::SLUG, 'tab' => 'settings'], admin_url('admin.php')) ); ?>#proposals"><?php esc_html_e( 'Review', 'jhmg-ai-editor-for-divi-5' ); ?></a>
                </div>
            <?php endif; ?>

            <?php if ( ! $premium ) : ?>
                <div class="aied-card aied-rec aied-locked">
                    <h4>&#128274; <?php esc_html_e( 'Create whole pages & sites', 'jhmg-ai-editor-for-divi-5' ); ?></h4>
                    <p class="aied-muted"><?php esc_html_e( 'Premium lets the AI build brand-new pages and entire multi-page sites — not just edit existing ones.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
                    <a class="button" href="<?php echo esc_url( add_query_arg(['page' => self::SLUG, 'tab' => 'upgrade'], admin_url('admin.php')) ); ?>"><?php esc_html_e( 'See what’s included', 'jhmg-ai-editor-for-divi-5' ); ?></a>
                </div>
                <div class="aied-card aied-rec aied-locked">
                    <h4>&#128274; <?php esc_html_e( 'Custom CSS & effects', 'jhmg-ai-editor-for-divi-5' ); ?></h4>
                    <p class="aied-muted"><?php esc_html_e( 'Add site-wide CSS for glassmorphism and animations the page builder can’t do.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
                    <a class="button" href="<?php echo esc_url( add_query_arg(['page' => self::SLUG, 'tab' => 'upgrade'], admin_url('admin.php')) ); ?>"><?php esc_html_e( 'Learn more', 'jhmg-ai-editor-for-divi-5' ); ?></a>
                </div>
            <?php else : ?>
                <div class="aied-card aied-rec">
                    <h4><?php esc_html_e( 'Build a full site from one prompt', 'jhmg-ai-editor-for-divi-5' ); ?></h4>
                    <p class="aied-muted"><?php esc_html_e( 'Ask your AI to build a homepage, about, services and contact pages in one go.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
                    <a class="button" href="<?php echo esc_url( add_query_arg(['page' => self::SLUG, 'tab' => 'features'], admin_url('admin.php')) ); ?>"><?php esc_html_e( 'See features', 'jhmg-ai-editor-for-divi-5' ); ?></a>
                </div>
            <?php endif; ?>
        </div>

        <?php $this->converterPromoSection( true ); ?>
        <?php
    }

    // ---------------------------------------------------------------
    // Cross-promo: JHMG's other Divi plugins (the converters)
    // ---------------------------------------------------------------

    /**
     * The two converter plugins we cross-promote. Static, pure data — unit-testable.
     * Upstream source of truth for names / prices / URLs:
     * layoutlab/lib/nav/menu-data.ts (PLUGIN_MENU). Keep in sync by hand.
     * Note: Divi→Elementor is not purchasable yet — it's a waitlist ("Coming soon").
     *
     * @return list<array{name:string, blurb:string, chip:string, cta:string, url:string}>
     */
    public static function converterPromos(): array
    {
        return [
            [
                'name'  => 'Elementor → Divi 5 Converter',
                'blurb' => __( 'Migrate Elementor pages and kits into validated Divi 5.', 'jhmg-ai-editor-for-divi-5' ),
                'chip'  => __( 'Free · Pro $25/yr', 'jhmg-ai-editor-for-divi-5' ),
                'cta'   => __( 'Get it', 'jhmg-ai-editor-for-divi-5' ),
                'url'   => 'https://divi5lab.com/plugins/elementor-to-divi-5',
            ],
            [
                'name'  => 'Divi → Elementor Converter',
                'blurb' => __( 'Batch-convert Divi sites the other way — 35+ modules mapped.', 'jhmg-ai-editor-for-divi-5' ),
                'chip'  => __( 'Coming soon', 'jhmg-ai-editor-for-divi-5' ),
                'cta'   => __( 'Join the waitlist', 'jhmg-ai-editor-for-divi-5' ),
                'url'   => 'https://divi5lab.com/plugins/divi-to-elementor',
            ],
        ];
    }

    /**
     * Renders the converter cross-promo. $compact = a slim Dashboard strip;
     * otherwise a full two-card section (Upgrade/Account tab).
     */
    public function converterPromoSection( bool $compact ): void
    {
        $promos = self::converterPromos();
        if ( $compact ) : ?>
            <div class="aied-card aied-promo">
                <div class="aied-card__head">
                    <h3><?php esc_html_e( 'More Divi tools from JHMG', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
                </div>
                <ul class="aied-promo-list">
                    <?php foreach ( $promos as $p ) : ?>
                        <li>
                            <a href="<?php echo esc_url( $p['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $p['name'] ); ?></a>
                            <span class="aied-chip"><?php echo esc_html( $p['chip'] ); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else : ?>
            <h3 class="aied-section-title"><?php esc_html_e( 'More Divi tools from JHMG', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
            <div class="aied-grid aied-grid--2">
                <?php foreach ( $promos as $p ) : ?>
                    <div class="aied-card">
                        <div class="aied-card__head">
                            <h4><?php echo esc_html( $p['name'] ); ?></h4>
                            <span class="aied-chip"><?php echo esc_html( $p['chip'] ); ?></span>
                        </div>
                        <p class="aied-muted"><?php echo esc_html( $p['blurb'] ); ?></p>
                        <a class="button button-primary" href="<?php echo esc_url( $p['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $p['cta'] ); ?> &rarr;</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif;
    }

    // ---------------------------------------------------------------
    // View: Features
    // ---------------------------------------------------------------

    private function viewFeatures(): void
    {
        $premium = $this->isPremium();
        $free = [
            [ __( 'Edit pages in plain English', 'jhmg-ai-editor-for-divi-5' ), __( 'Tell your AI what to change and it updates the live Divi 5 layout — no builder, no copy-paste.', 'jhmg-ai-editor-for-divi-5' ) ],
            [ __( 'Validated, safe saves', 'jhmg-ai-editor-for-divi-5' ), __( 'Every change is checked against 56+ Divi 5 module types before saving, so broken layouts never reach your site.', 'jhmg-ai-editor-for-divi-5' ) ],
            [ __( 'Read & understand any page', 'jhmg-ai-editor-for-divi-5' ), __( 'Your AI can list and read existing pages to make precise, context-aware edits.', 'jhmg-ai-editor-for-divi-5' ) ],
            [ __( 'Conversion-focused page generation', 'jhmg-ai-editor-for-divi-5' ), __( 'A built-in landing-page blueprint, design vocabulary and proven section patterns guide the AI to produce polished, on-brand pages with a strategic structure built to convert.', 'jhmg-ai-editor-for-divi-5' ) ],
        ];
        $pro = [
            [ __( 'Create new pages', 'jhmg-ai-editor-for-divi-5' ), __( 'Generate brand-new pages from a prompt — drafted, validated, ready to review.', 'jhmg-ai-editor-for-divi-5' ) ],
            [ __( 'Build entire websites', 'jhmg-ai-editor-for-divi-5' ), __( 'Produce a cohesive multi-page site (home, about, services, contact) with shared styling and navigation in one go.', 'jhmg-ai-editor-for-divi-5' ) ],
            [ __( 'Set homepage & menus', 'jhmg-ai-editor-for-divi-5' ), __( 'Wire up the front page and primary navigation automatically.', 'jhmg-ai-editor-for-divi-5' ) ],
            [ __( 'Site-wide custom CSS', 'jhmg-ai-editor-for-divi-5' ), __( 'Add real glassmorphism, animations and fine-tuning the builder can’t express — safely, never executing code.', 'jhmg-ai-editor-for-divi-5' ) ],
            [ __( 'Reviewed PHP snippets', 'jhmg-ai-editor-for-divi-5' ), __( 'The AI drafts custom post types, hooks and integrations for you to review and apply — nothing runs automatically.', 'jhmg-ai-editor-for-divi-5' ) ],
        ];
        ?>
        <div class="aied-hello"><h1><?php esc_html_e( 'Features', 'jhmg-ai-editor-for-divi-5' ); ?></h1>
            <p><?php esc_html_e( 'Everything your AI assistant can do with your Divi 5 site.', 'jhmg-ai-editor-for-divi-5' ); ?></p></div>

        <h3 class="aied-section-title"><?php esc_html_e( 'Included free', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
        <div class="aied-grid aied-grid--2">
            <?php foreach ( $free as [$t, $d] ) : ?>
                <div class="aied-card aied-feature">
                    <span class="aied-feature__badge aied-feature__badge--free">&#10003;</span>
                    <div><h4><?php echo esc_html( $t ); ?></h4><p class="aied-muted"><?php echo esc_html( $d ); ?></p></div>
                </div>
            <?php endforeach; ?>
        </div>

        <h3 class="aied-section-title">
            <?php esc_html_e( 'Premium', 'jhmg-ai-editor-for-divi-5' ); ?>
            <span class="aied-result <?php echo $premium ? 'aied-result--valid' : 'aied-result--invalid'; ?>"><?php echo $premium ? esc_html__( 'ACTIVE', 'jhmg-ai-editor-for-divi-5' ) : esc_html__( 'LOCKED', 'jhmg-ai-editor-for-divi-5' ); ?></span>
        </h3>
        <div class="aied-grid aied-grid--2">
            <?php foreach ( $pro as [$t, $d] ) : ?>
                <div class="aied-card aied-feature <?php echo $premium ? '' : 'aied-locked'; ?>">
                    <span class="aied-feature__badge"><?php echo $premium ? '&#10003;' : '&#128274;'; ?></span>
                    <div>
                        <h4><?php echo esc_html( $t ); ?></h4>
                        <p class="aied-muted"><?php echo esc_html( $d ); ?></p>
                        <?php if ( ! $premium ) : ?>
                            <a class="aied-link" href="<?php echo esc_url( add_query_arg(['page' => self::SLUG, 'tab' => 'upgrade'], admin_url('admin.php')) ); ?>"><?php esc_html_e( 'Unlock feature →', 'jhmg-ai-editor-for-divi-5' ); ?></a>
                        <?php endif; ?>
                    </div>
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
            <p><?php esc_html_e( 'Connect your AI assistant and manage your account.', 'jhmg-ai-editor-for-divi-5' ); ?></p></div>

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

        <!-- Account / License -->
        <div class="aied-card">
            <?php $this->licensePanel(); ?>
        </div>

        <!-- Code proposals -->
        <div class="aied-card" id="proposals">
            <h3><?php esc_html_e( 'Code Proposals', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
            <p class="aied-muted"><?php esc_html_e( 'PHP your AI drafted for you. Nothing is executed or saved to your site — review each one and apply it yourself.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
            <?php $proposals = PhpProposals::all(); ?>
            <?php if ( empty( $proposals ) ) : ?>
                <div class="aied-empty">
                    <p><strong><?php esc_html_e( 'No proposals yet', 'jhmg-ai-editor-for-divi-5' ); ?></strong></p>
                    <p class="aied-muted"><?php esc_html_e( 'Ask your AI to build a PHP feature (e.g. a custom post type) and it appears here for review.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
                </div>
            <?php else : foreach ( $proposals as $p ) : $pid = $p['id']; ?>
                <div class="aied-proposal">
                    <h4><?php echo esc_html( $p['title'] ); ?></h4>
                    <p class="aied-muted"><?php echo esc_html( $p['description'] ); ?></p>
                    <div class="aied-snippet-wrap">
                        <pre class="aied-snippet" id="proposal-<?php echo esc_attr( $pid ); ?>"><?php echo esc_html( $p['code'] ); ?></pre>
                        <button class="button button-primary aied-copy-btn" data-target="proposal-<?php echo esc_attr( $pid ); ?>"><?php esc_html_e( 'Copy', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                    </div>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="ai_editor_divi5_delete_proposal">
                        <input type="hidden" name="proposal_id" value="<?php echo esc_attr( $pid ); ?>">
                        <?php wp_nonce_field( 'ai_editor_divi5_delete_proposal' ); ?>
                        <button type="submit" class="button aied-btn-danger" onclick="return confirm('<?php echo esc_js( __( 'Delete this proposal?', 'jhmg-ai-editor-for-divi-5' ) ); ?>')"><?php esc_html_e( 'Delete', 'jhmg-ai-editor-for-divi-5' ); ?></button>
                    </form>
                </div>
            <?php endforeach; endif; ?>
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

    private function licensePanel(): void
    {
        $license = Licensing::status();
        ?>
        <h3>
            <?php esc_html_e( 'License', 'jhmg-ai-editor-for-divi-5' ); ?>
            <span class="aied-result <?php echo $license['valid'] ? 'aied-result--valid' : 'aied-result--invalid'; ?>"><?php echo $license['valid'] ? esc_html__( 'PREMIUM', 'jhmg-ai-editor-for-divi-5' ) : esc_html__( 'FREE', 'jhmg-ai-editor-for-divi-5' ); ?></span>
        </h3>
        <?php if ( $license['valid'] ) : ?>
            <p class="aied-muted">
                <?php esc_html_e( 'Premium is active on this site.', 'jhmg-ai-editor-for-divi-5' ); ?>
                <?php if ( $license['expires'] ) {
                    $aied_is_lapsed = in_array( $license['status'], [ 'expired', 'canceled' ], true );
                    echo ' ' . esc_html( sprintf(
                        /* translators: %s date */
                        $aied_is_lapsed ? __( 'Expired %s.', 'jhmg-ai-editor-for-divi-5' ) : __( 'Renews %s.', 'jhmg-ai-editor-for-divi-5' ),
                        date_i18n( get_option( 'date_format' ), (int) $license['expires'] )
                    ) );
                } ?>
            </p>
            <?php if ( in_array( $license['status'], [ 'expired', 'canceled' ], true ) ) : ?>
                <p class="aied-muted">
                    <?php esc_html_e( 'Your license has lapsed: premium features stay unlocked here, but updates and support are paused.', 'jhmg-ai-editor-for-divi-5' ); ?>
                    <a href="<?php echo esc_url( Licensing::UPGRADE_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Renew', 'jhmg-ai-editor-for-divi-5' ); ?></a>
                </p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ai_editor_divi5_deactivate_license">
                <?php wp_nonce_field( 'ai_editor_divi5_deactivate_license' ); ?>
                <button type="submit" class="button aied-btn-danger"><?php esc_html_e( 'Deactivate license', 'jhmg-ai-editor-for-divi-5' ); ?></button>
            </form>
        <?php else : ?>
            <p class="aied-muted"><?php esc_html_e( 'Enter a license key to unlock premium features.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aied-key-row">
                <input type="hidden" name="action" value="ai_editor_divi5_activate_license">
                <?php wp_nonce_field( 'ai_editor_divi5_activate_license' ); ?>
                <input type="text" name="license_key" class="regular-text" style="flex:1" placeholder="<?php esc_attr_e( 'JHMG-XXXX-XXXX-XXXX-XXXX', 'jhmg-ai-editor-for-divi-5' ); ?>" autocomplete="off" spellcheck="false">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Activate', 'jhmg-ai-editor-for-divi-5' ); ?></button>
            </form>
        <?php endif; ?>
        <?php
    }

    // ---------------------------------------------------------------
    // View: Upgrade / Account
    // ---------------------------------------------------------------

    private function viewUpgrade(): void
    {
        $premium = $this->isPremium();
        $benefits = [
            __( 'Generate brand-new pages from a prompt instead of building them by hand.', 'jhmg-ai-editor-for-divi-5' ),
            __( 'Spin up a whole multi-page website — consistent design and navigation — in minutes, not days.', 'jhmg-ai-editor-for-divi-5' ),
            __( 'Add polished effects (glassmorphism, animations) with safe, site-wide custom CSS.', 'jhmg-ai-editor-for-divi-5' ),
            __( 'Get reviewed PHP for custom features without writing it yourself.', 'jhmg-ai-editor-for-divi-5' ),
        ];
        ?>
        <div class="aied-hello">
            <h1><?php echo $premium ? esc_html__( 'Your account', 'jhmg-ai-editor-for-divi-5' ) : esc_html__( 'Unlock more with Premium', 'jhmg-ai-editor-for-divi-5' ); ?></h1>
            <p><?php echo $premium
                ? esc_html__( 'Premium is active. Thanks for your support!', 'jhmg-ai-editor-for-divi-5' )
                : esc_html__( 'You already get safe, AI-powered page editing for free. Premium adds creation and automation.', 'jhmg-ai-editor-for-divi-5' ); ?></p>
        </div>

        <div class="aied-grid aied-grid--2">
            <div class="aied-card">
                <h3><?php esc_html_e( 'You already have', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
                <ul class="aied-ticklist">
                    <li>&#10003; <?php esc_html_e( 'AI editing of existing Divi 5 pages', 'jhmg-ai-editor-for-divi-5' ); ?></li>
                    <li>&#10003; <?php esc_html_e( 'Validated, never-break-the-site saves', 'jhmg-ai-editor-for-divi-5' ); ?></li>
                    <li>&#10003; <?php esc_html_e( 'Conversion-focused page generation (landing blueprint, style guide & section recipes)', 'jhmg-ai-editor-for-divi-5' ); ?></li>
                    <li>&#10003; <?php esc_html_e( 'Works with Claude, Cursor, VS Code & ChatGPT', 'jhmg-ai-editor-for-divi-5' ); ?></li>
                </ul>
            </div>
            <div class="aied-card aied-card--accent">
                <h3>&#128640; <?php esc_html_e( 'Premium unlocks', 'jhmg-ai-editor-for-divi-5' ); ?></h3>
                <ul class="aied-ticklist">
                    <?php foreach ( $benefits as $b ) : ?>
                        <li>&#128640; <?php echo esc_html( $b ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ( ! $premium ) : ?>
                    <a class="button button-primary button-hero" href="<?php echo esc_url( Licensing::UPGRADE_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get Premium', 'jhmg-ai-editor-for-divi-5' ); ?></a>
                <?php else : ?>
                    <p class="aied-eyebrow aied-eyebrow--ok">&#10003; <?php esc_html_e( 'Active on this site', 'jhmg-ai-editor-for-divi-5' ); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="aied-card">
            <?php $this->licensePanel(); ?>
        </div>

        <?php $this->converterPromoSection( false ); ?>
        <?php
    }
}
