<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WordPress admin settings page.
 * Menu: Settings → AI Editor for Divi 5
 */
final class AdminPage
{
    public function register(): void
    {
        add_action('admin_menu',            [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_ai_editor_divi5_regenerate_key', [$this, 'handleRegenerate']);
        add_action('admin_post_ai_editor_divi5_clear_usage',    [$this, 'handleClearUsage']);
        add_action('admin_post_ai_editor_divi5_activate_license',   [$this, 'handleActivateLicense']);
        add_action('admin_post_ai_editor_divi5_deactivate_license', [$this, 'handleDeactivateLicense']);
        add_action('admin_post_ai_editor_divi5_delete_proposal',    [$this, 'handleDeleteProposal']);
    }

    public function handleDeleteProposal(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die( esc_html__( 'Unauthorized.', 'ai-editor-divi5' ) );
        }
        check_admin_referer('ai_editor_divi5_delete_proposal');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        PhpProposals::delete( sanitize_text_field( wp_unslash( $_POST['proposal_id'] ?? '' ) ) );
        wp_safe_redirect(add_query_arg(['page' => 'ai-editor-divi5', 'tab' => 'code', 'notice' => 'proposal_deleted'], admin_url('options-general.php')));
        exit;
    }

    public function addMenu(): void
    {
        add_options_page(
            __( 'AI Editor for Divi 5', 'ai-editor-divi5' ),
            __( 'AI Editor for Divi 5', 'ai-editor-divi5' ),
            'manage_options',
            'ai-editor-divi5',
            [$this, 'render']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_ai-editor-divi5') {
            return;
        }
        wp_enqueue_style(
            'ai-editor-divi5-admin',
            plugin_dir_url(AI_EDITOR_DIVI5_FILE) . 'assets/admin.css',
            [],
            AI_EDITOR_DIVI5_VERSION
        );
        wp_enqueue_script(
            'ai-editor-divi5-admin',
            plugin_dir_url(AI_EDITOR_DIVI5_FILE) . 'assets/admin.js',
            [],
            AI_EDITOR_DIVI5_VERSION,
            true
        );
    }

    public function handleRegenerate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die( esc_html__( 'Unauthorized.', 'ai-editor-divi5' ) );
        }
        check_admin_referer('ai_editor_divi5_regenerate_key');
        ApiKey::generate();
        wp_safe_redirect(add_query_arg(['page' => 'ai-editor-divi5', 'notice' => 'key_regenerated'], admin_url('options-general.php')));
        exit;
    }

    public function handleClearUsage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die( esc_html__( 'Unauthorized.', 'ai-editor-divi5' ) );
        }
        check_admin_referer('ai_editor_divi5_clear_usage');
        UsageTracker::clear();
        wp_safe_redirect(add_query_arg(['page' => 'ai-editor-divi5', 'tab' => 'usage', 'notice' => 'usage_cleared'], admin_url('options-general.php')));
        exit;
    }

    public function handleActivateLicense(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die( esc_html__( 'Unauthorized.', 'ai-editor-divi5' ) );
        }
        check_admin_referer('ai_editor_divi5_activate_license');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- license keys are base64url (no slashes added by WP), trimmed below.
        $key = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
        Licensing::setKey($key);

        $notice = Licensing::isPremium() ? 'license_activated' : 'license_invalid';
        wp_safe_redirect(add_query_arg(['page' => 'ai-editor-divi5', 'notice' => $notice], admin_url('options-general.php')));
        exit;
    }

    public function handleDeactivateLicense(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die( esc_html__( 'Unauthorized.', 'ai-editor-divi5' ) );
        }
        check_admin_referer('ai_editor_divi5_deactivate_license');
        Licensing::clear();
        wp_safe_redirect(add_query_arg(['page' => 'ai-editor-divi5', 'notice' => 'license_deactivated'], admin_url('options-general.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $license = Licensing::status();

        $apiKey    = ApiKey::get();
        $siteUrl   = rtrim(get_site_url(), '/');
        $mcpUrl    = $siteUrl . '/wp-json/ai-editor-divi5/v1/mcp';
        $specUrl   = $siteUrl . '/wp-json/ai-editor-divi5/v1/openapi.json';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only nav params, not form data.
        $activeTab = sanitize_key( $_GET['tab'] ?? 'connect' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- set by our own redirects after nonce-verified POST.
        $notice    = sanitize_key( $_GET['notice'] ?? '' );
        $summary   = UsageTracker::getSummary();
        $recent    = UsageTracker::getRecent(50);

        $mcpConfig    = json_encode([
            'mcpServers' => [
                'ai-editor-divi5' => [
                    'url'     => $mcpUrl,
                    'headers' => ['Authorization' => "Bearer {$apiKey}"],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $cursorConfig = $mcpConfig;

        ?>
        <div class="wrap aied-admin">

            <div class="aied-header">
                <div class="aied-header__brand">
                    <span class="aied-header__logo">&#10086;</span>
                    <div>
                        <h1><?php esc_html_e( 'AI Editor for Divi 5', 'ai-editor-divi5' ); ?></h1>
                        <p><?php esc_html_e( 'Edit your Divi site with natural language — changes are validated before they land.', 'ai-editor-divi5' ); ?></p>
                    </div>
                </div>
                <span class="aied-header__version">v<?php echo esc_html( AI_EDITOR_DIVI5_VERSION ); ?></span>
            </div>

            <?php if ( $notice === 'key_regenerated' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'API key regenerated. Update your AI assistant configuration.', 'ai-editor-divi5' ); ?></p></div>
            <?php elseif ( $notice === 'usage_cleared' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Usage log cleared.', 'ai-editor-divi5' ); ?></p></div>
            <?php elseif ( $notice === 'license_activated' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License activated. Premium features are now enabled.', 'ai-editor-divi5' ); ?></p></div>
            <?php elseif ( $notice === 'license_invalid' ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'That license key is not valid for this site. Check the key and your domain, then try again.', 'ai-editor-divi5' ); ?></p></div>
            <?php elseif ( $notice === 'license_deactivated' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'License removed. Premium features are now disabled.', 'ai-editor-divi5' ); ?></p></div>
            <?php elseif ( $notice === 'proposal_deleted' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Code proposal deleted.', 'ai-editor-divi5' ); ?></p></div>
            <?php endif; ?>

            <div class="aied-layout">
            <div class="aied-main">

            <!-- API Key -->
            <div class="aied-card">
                <h2><?php esc_html_e( 'API Key', 'ai-editor-divi5' ); ?></h2>
                <p class="aied-card__desc"><?php esc_html_e( 'Paste this into your AI assistant\'s configuration. It authorizes all read and write operations.', 'ai-editor-divi5' ); ?></p>
                <div class="aied-key-row">
                    <code class="aied-key" id="aied-api-key" data-key="<?php echo esc_attr( $apiKey ); ?>">
                        &#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;
                    </code>
                    <button type="button" class="button" id="aied-toggle-key"><?php esc_html_e( 'Show', 'ai-editor-divi5' ); ?></button>
                    <button type="button" class="button button-primary" data-copy="<?php echo esc_attr( $apiKey ); ?>"><?php esc_html_e( 'Copy', 'ai-editor-divi5' ); ?></button>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                        <input type="hidden" name="action" value="ai_editor_divi5_regenerate_key">
                        <?php wp_nonce_field( 'ai_editor_divi5_regenerate_key' ); ?>
                        <button type="submit" class="button aied-btn-danger"
                            onclick="return confirm( '<?php echo esc_js( __( 'Regenerate the API key? You will need to update your AI assistant config.', 'ai-editor-divi5' ) ); ?>' )">
                            <?php esc_html_e( 'Regenerate', 'ai-editor-divi5' ); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- License -->
            <div class="aied-card">
                <h2>
                    <?php esc_html_e( 'License', 'ai-editor-divi5' ); ?>
                    <?php if ( $license['valid'] ) : ?>
                        <span class="aied-result aied-result--valid"><?php esc_html_e( 'PREMIUM', 'ai-editor-divi5' ); ?></span>
                    <?php else : ?>
                        <span class="aied-result aied-result--invalid"><?php esc_html_e( 'FREE', 'ai-editor-divi5' ); ?></span>
                    <?php endif; ?>
                </h2>

                <?php if ( $license['valid'] ) : ?>
                    <p class="aied-card__desc">
                        <?php
                        echo esc_html( sprintf(
                            /* translators: %s: licensee email address */
                            __( 'Premium features are unlocked for %s.', 'ai-editor-divi5' ),
                            $license['email'] ?: __( 'this site', 'ai-editor-divi5' )
                        ) );
                        if ( $license['expires'] ) {
                            echo ' ' . esc_html( sprintf(
                                /* translators: %s: expiry date */
                                __( 'Expires %s.', 'ai-editor-divi5' ),
                                date_i18n( get_option( 'date_format' ), (int) $license['expires'] )
                            ) );
                        }
                        ?>
                    </p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="ai_editor_divi5_deactivate_license">
                        <?php wp_nonce_field( 'ai_editor_divi5_deactivate_license' ); ?>
                        <button type="submit" class="button aied-btn-danger"
                            onclick="return confirm( '<?php echo esc_js( __( 'Remove this license and disable premium features?', 'ai-editor-divi5' ) ); ?>' )">
                            <?php esc_html_e( 'Deactivate license', 'ai-editor-divi5' ); ?>
                        </button>
                    </form>
                <?php else : ?>
                    <p class="aied-card__desc">
                        <?php esc_html_e( 'Activate a license key to unlock premium features such as creating new pages from your AI assistant.', 'ai-editor-divi5' ); ?>
                    </p>
                    <?php if ( $license['reason'] === 'expired' ) : ?>
                        <p class="aied-note"><?php esc_html_e( 'The stored key has expired. Enter a renewed key below.', 'ai-editor-divi5' ); ?></p>
                    <?php elseif ( $license['reason'] === 'domain_mismatch' ) : ?>
                        <p class="aied-note"><?php esc_html_e( 'The stored key is issued for a different domain than this site.', 'ai-editor-divi5' ); ?></p>
                    <?php elseif ( $license['reason'] === 'invalid_signature' ) : ?>
                        <p class="aied-note"><?php esc_html_e( 'The stored key could not be verified. Re-paste it below.', 'ai-editor-divi5' ); ?></p>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aied-key-row">
                        <input type="hidden" name="action" value="ai_editor_divi5_activate_license">
                        <?php wp_nonce_field( 'ai_editor_divi5_activate_license' ); ?>
                        <input type="text" name="license_key" class="regular-text" style="flex:1"
                            placeholder="<?php esc_attr_e( 'Paste your license key', 'ai-editor-divi5' ); ?>"
                            autocomplete="off" spellcheck="false">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Activate', 'ai-editor-divi5' ); ?></button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Tabs -->
            <nav class="aied-tabs" role="tablist">
                <?php
                $tabs = [
                    'connect' => __( 'Connect AI', 'ai-editor-divi5' ),
                    'usage'   => __( 'Usage', 'ai-editor-divi5' ),
                    'code'    => __( 'Code Proposals', 'ai-editor-divi5' ),
                ];
                foreach ( $tabs as $slug => $label ) :
                ?>
                    <a href="?page=ai-editor-divi5&amp;tab=<?php echo esc_attr( $slug ); ?>"
                       class="aied-tab <?php echo $activeTab === $slug ? 'aied-tab--active' : ''; ?>"
                       role="tab">
                        <?php echo esc_html( $label ); ?>
                        <?php if ( $slug === 'usage' && $summary['today'] > 0 ) : ?>
                            <span class="aied-badge"><?php echo esc_html( $summary['today'] ); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Connect Tab -->
            <?php if ( $activeTab === 'connect' ) : ?>
            <div class="aied-card">
                <div class="aied-llm-tabs" id="aied-llm-tabs">
                    <button class="aied-llm-tab aied-llm-tab--active" data-target="claude"><?php esc_html_e( 'Claude Desktop', 'ai-editor-divi5' ); ?></button>
                    <button class="aied-llm-tab" data-target="cursor"><?php esc_html_e( 'Cursor / Windsurf', 'ai-editor-divi5' ); ?></button>
                    <button class="aied-llm-tab" data-target="vscode"><?php esc_html_e( 'VS Code Copilot', 'ai-editor-divi5' ); ?></button>
                    <button class="aied-llm-tab" data-target="chatgpt"><?php esc_html_e( 'ChatGPT', 'ai-editor-divi5' ); ?></button>
                    <button class="aied-llm-tab" data-target="api"><?php esc_html_e( 'REST API', 'ai-editor-divi5' ); ?></button>
                </div>

                <!-- Claude Desktop -->
                <div class="aied-llm-panel" id="aied-panel-claude">
                    <ol class="aied-steps">
                        <li><?php esc_html_e( 'Open Claude Desktop and quit it completely.', 'ai-editor-divi5' ); ?></li>
                        <li><?php esc_html_e( 'Open this file in a text editor:', 'ai-editor-divi5' ); ?><br>
                            <code>~/Library/Application Support/Claude/claude_desktop_config.json</code>
                        </li>
                        <li><?php /* translators: mcpServers is a JSON key name, do not translate */ esc_html_e( 'Add or merge the mcpServers key:', 'ai-editor-divi5' ); ?></li>
                    </ol>
                    <div class="aied-snippet-wrap">
                        <pre class="aied-snippet" id="snippet-claude"><?php echo esc_html( $mcpConfig ); ?></pre>
                        <button class="button button-primary aied-copy-btn" data-target="snippet-claude"><?php esc_html_e( 'Copy', 'ai-editor-divi5' ); ?></button>
                    </div>
                    <ol class="aied-steps" start="4">
                        <li><?php esc_html_e( 'Save the file and reopen Claude Desktop.', 'ai-editor-divi5' ); ?></li>
                        <li><?php esc_html_e( 'Click + in a new chat → Connectors → ai-editor-divi5 should appear.', 'ai-editor-divi5' ); ?></li>
                    </ol>
                </div>

                <!-- Cursor / Windsurf -->
                <div class="aied-llm-panel" id="aied-panel-cursor" hidden>
                    <ol class="aied-steps">
                        <li><?php esc_html_e( 'Open Cursor → Settings → MCP.', 'ai-editor-divi5' ); ?></li>
                        <li><?php esc_html_e( 'Click Add new global MCP server and paste:', 'ai-editor-divi5' ); ?></li>
                    </ol>
                    <div class="aied-snippet-wrap">
                        <pre class="aied-snippet" id="snippet-cursor"><?php echo esc_html( $cursorConfig ); ?></pre>
                        <button class="button button-primary aied-copy-btn" data-target="snippet-cursor"><?php esc_html_e( 'Copy', 'ai-editor-divi5' ); ?></button>
                    </div>
                    <p class="aied-note"><?php esc_html_e( 'Windsurf: Settings → Cascade → MCP Servers → Add Server — use the same JSON.', 'ai-editor-divi5' ); ?></p>
                </div>

                <!-- VS Code Copilot -->
                <div class="aied-llm-panel" id="aied-panel-vscode" hidden>
                    <ol class="aied-steps">
                        <li><?php esc_html_e( 'Open VS Code → Command Palette → Open User Settings (JSON).', 'ai-editor-divi5' ); ?></li>
                        <li><?php esc_html_e( 'Add inside the root object:', 'ai-editor-divi5' ); ?></li>
                    </ol>
                    <div class="aied-snippet-wrap">
                        <pre class="aied-snippet" id="snippet-vscode"><?php echo esc_html( json_encode( [
                            'github.copilot.chat.mcp.enabled' => true,
                            'mcp' => [
                                'servers' => [
                                    'ai-editor-divi5' => [
                                        'url'     => $mcpUrl,
                                        'headers' => [ 'Authorization' => "Bearer {$apiKey}" ],
                                    ],
                                ],
                            ],
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
                        <button class="button button-primary aied-copy-btn" data-target="snippet-vscode"><?php esc_html_e( 'Copy', 'ai-editor-divi5' ); ?></button>
                    </div>
                    <ol class="aied-steps" start="3">
                        <li><?php esc_html_e( 'Reload VS Code — the tools appear in Copilot Chat.', 'ai-editor-divi5' ); ?></li>
                    </ol>
                </div>

                <!-- ChatGPT -->
                <div class="aied-llm-panel" id="aied-panel-chatgpt" hidden>
                    <ol class="aied-steps">
                        <li><?php esc_html_e( 'Go to chat.openai.com → Explore GPTs → Create a GPT.', 'ai-editor-divi5' ); ?></li>
                        <li><?php esc_html_e( 'Go to the Configure tab → Actions → Create new action.', 'ai-editor-divi5' ); ?></li>
                        <li><?php esc_html_e( 'Click Import from URL and paste the OpenAPI spec URL:', 'ai-editor-divi5' ); ?></li>
                    </ol>
                    <div class="aied-snippet-wrap">
                        <pre class="aied-snippet" id="snippet-spec-url"><?php echo esc_html( $specUrl ); ?></pre>
                        <button class="button button-primary aied-copy-btn" data-target="snippet-spec-url"><?php esc_html_e( 'Copy', 'ai-editor-divi5' ); ?></button>
                    </div>
                    <ol class="aied-steps" start="4">
                        <li><?php esc_html_e( 'Under Authentication, choose API Key → Bearer and paste your API key.', 'ai-editor-divi5' ); ?></li>
                        <li><?php esc_html_e( 'Save the GPT and test it.', 'ai-editor-divi5' ); ?></li>
                    </ol>
                    <p class="aied-note">
                        <?php esc_html_e( 'OpenAPI spec:', 'ai-editor-divi5' ); ?>
                        <a href="<?php echo esc_url( $specUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $specUrl ); ?></a>
                    </p>
                </div>

                <!-- REST API -->
                <div class="aied-llm-panel" id="aied-panel-api" hidden>
                    <p><?php esc_html_e( 'Use the REST API directly from any tool, script, or custom integration.', 'ai-editor-divi5' ); ?></p>
                    <div class="aied-snippet-wrap">
                        <pre class="aied-snippet" id="snippet-api"><?php echo esc_html( implode( "\n\n", [
                            "# List Divi 5 pages\ncurl -H \"Authorization: Bearer {$apiKey}\" \\\n  {$siteUrl}/wp-json/ai-editor-divi5/v1/pages/",
                            "# Get a page layout\ncurl -H \"Authorization: Bearer {$apiKey}\" \\\n  {$siteUrl}/wp-json/ai-editor-divi5/v1/pages/7/",
                            "# Validate a layout\ncurl -X POST -H \"Authorization: Bearer {$apiKey}\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"post_content\":\"...\"}' \\\n  {$siteUrl}/wp-json/ai-editor-divi5/v1/validate/",
                        ] ) ); ?></pre>
                        <button class="button button-primary aied-copy-btn" data-target="snippet-api"><?php esc_html_e( 'Copy', 'ai-editor-divi5' ); ?></button>
                    </div>
                    <p class="aied-note">
                        <?php esc_html_e( 'Full spec:', 'ai-editor-divi5' ); ?>
                        <a href="<?php echo esc_url( $specUrl ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'OpenAPI JSON', 'ai-editor-divi5' ); ?></a>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Usage Tab -->
            <?php if ( $activeTab === 'usage' ) : ?>
            <div class="aied-card">
                <div class="aied-stats">
                    <div class="aied-stat">
                        <span class="aied-stat__num"><?php echo esc_html( $summary['total'] ); ?></span>
                        <span class="aied-stat__label"><?php esc_html_e( 'Total edits', 'ai-editor-divi5' ); ?></span>
                    </div>
                    <div class="aied-stat">
                        <span class="aied-stat__num"><?php echo esc_html( $summary['today'] ); ?></span>
                        <span class="aied-stat__label"><?php esc_html_e( 'Today', 'ai-editor-divi5' ); ?></span>
                    </div>
                    <div class="aied-stat aied-stat--valid">
                        <span class="aied-stat__num"><?php echo esc_html( $summary['valid'] ); ?></span>
                        <span class="aied-stat__label"><?php esc_html_e( 'Saved', 'ai-editor-divi5' ); ?></span>
                    </div>
                    <div class="aied-stat aied-stat--invalid">
                        <span class="aied-stat__num"><?php echo esc_html( $summary['invalid'] ); ?></span>
                        <span class="aied-stat__label"><?php esc_html_e( 'Blocked', 'ai-editor-divi5' ); ?></span>
                    </div>
                </div>

                <?php if ( ! empty( $summary['byClient'] ) ) : ?>
                <div class="aied-by-client">
                    <h3><?php esc_html_e( 'By AI assistant', 'ai-editor-divi5' ); ?></h3>
                    <div class="aied-client-bars">
                        <?php
                        $max = max( array_column( $summary['byClient'], 'cnt' ) );
                        foreach ( $summary['byClient'] as $row ) :
                            $pct = $max > 0 ? round( ( $row['cnt'] / $max ) * 100 ) : 0;
                        ?>
                        <div class="aied-client-bar">
                            <span class="aied-client-bar__label"><?php echo esc_html( $row['client'] ?: __( 'Unknown', 'ai-editor-divi5' ) ); ?></span>
                            <div class="aied-client-bar__track">
                                <div class="aied-client-bar__fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
                            </div>
                            <span class="aied-client-bar__count"><?php echo esc_html( $row['cnt'] ); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="aied-table-header">
                    <h3><?php esc_html_e( 'Recent activity', 'ai-editor-divi5' ); ?></h3>
                    <?php if ( ! empty( $recent ) ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="ai_editor_divi5_clear_usage">
                        <?php wp_nonce_field( 'ai_editor_divi5_clear_usage' ); ?>
                        <button type="submit" class="button aied-btn-danger"
                            onclick="return confirm( '<?php echo esc_js( __( 'Clear all activity logs?', 'ai-editor-divi5' ) ); ?>' )">
                            <?php esc_html_e( 'Clear log', 'ai-editor-divi5' ); ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if ( empty( $recent ) ) : ?>
                    <p class="aied-empty"><?php esc_html_e( 'No activity yet. Connect an AI assistant and start editing your Divi pages!', 'ai-editor-divi5' ); ?></p>
                <?php else : ?>
                <table class="wp-list-table widefat fixed striped aied-usage-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'ai-editor-divi5' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'ai-editor-divi5' ); ?></th>
                            <th><?php esc_html_e( 'Page', 'ai-editor-divi5' ); ?></th>
                            <th><?php esc_html_e( 'Result', 'ai-editor-divi5' ); ?></th>
                            <th><?php esc_html_e( 'Violations', 'ai-editor-divi5' ); ?></th>
                            <th><?php esc_html_e( 'AI assistant', 'ai-editor-divi5' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $recent as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( date_i18n( 'M j, H:i:s', strtotime( $row['created_at'] ) ) ); ?></td>
                            <td><code><?php echo esc_html( $row['endpoint'] ); ?></code></td>
                            <td>
                                <?php if ( $row['page_id'] ) : ?>
                                    <a href="<?php echo esc_url( get_edit_post_link( (int) $row['page_id'] ) ); ?>">#<?php echo esc_html( $row['page_id'] ); ?></a>
                                <?php else : ?>&#8212;<?php endif; ?>
                            </td>
                            <td>
                                <span class="aied-result aied-result--<?php echo esc_attr( $row['result'] ); ?>">
                                    <?php echo esc_html( strtoupper( $row['result'] ) ); ?>
                                </span>
                            </td>
                            <td><?php echo $row['violations'] > 0 ? esc_html( $row['violations'] ) : '&#8212;'; ?></td>
                            <td><?php echo $row['client'] ? esc_html( $row['client'] ) : '&#8212;'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Code Proposals Tab -->
            <?php if ( $activeTab === 'code' ) : ?>
            <div class="aied-card">
                <h2><?php esc_html_e( 'Code Proposals', 'ai-editor-divi5' ); ?></h2>
                <p class="aied-card__desc">
                    <?php esc_html_e( 'PHP snippets your AI assistant proposed. These are NOT executed or saved to your site — review each one, then apply it yourself (paste into a code-snippets plugin or your theme functions). Only apply code you understand and trust.', 'ai-editor-divi5' ); ?>
                </p>
                <?php $proposals = PhpProposals::all(); ?>
                <?php if ( empty( $proposals ) ) : ?>
                    <p class="aied-empty"><?php esc_html_e( 'No code proposals yet. Ask your AI assistant to build a PHP feature and it will appear here for review.', 'ai-editor-divi5' ); ?></p>
                <?php else : foreach ( $proposals as $p ) : $pid = esc_attr( $p['id'] ); ?>
                    <div class="aied-card" style="margin-top:16px">
                        <h3 style="margin-top:0"><?php echo esc_html( $p['title'] ); ?></h3>
                        <p class="aied-card__desc"><?php echo esc_html( $p['description'] ); ?></p>
                        <p class="aied-note"><?php echo esc_html( date_i18n( 'M j, Y H:i', (int) $p['created'] ) ); ?></p>
                        <div class="aied-snippet-wrap">
                            <pre class="aied-snippet" id="proposal-<?php echo $pid; ?>"><?php echo esc_html( $p['code'] ); ?></pre>
                            <button class="button button-primary aied-copy-btn" data-target="proposal-<?php echo $pid; ?>"><?php esc_html_e( 'Copy', 'ai-editor-divi5' ); ?></button>
                        </div>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px">
                            <input type="hidden" name="action" value="ai_editor_divi5_delete_proposal">
                            <input type="hidden" name="proposal_id" value="<?php echo $pid; ?>">
                            <?php wp_nonce_field( 'ai_editor_divi5_delete_proposal' ); ?>
                            <button type="submit" class="button aied-btn-danger"
                                onclick="return confirm( '<?php echo esc_js( __( 'Delete this proposal?', 'ai-editor-divi5' ) ); ?>' )">
                                <?php esc_html_e( 'Delete', 'ai-editor-divi5' ); ?>
                            </button>
                        </form>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <?php endif; ?>

            </div><!-- /.aied-main -->

            <!-- Sidebar -->
            <aside class="aied-sidebar">

                <div class="aied-sidebar-card">
                    <h3><?php esc_html_e( 'What you can do', 'ai-editor-divi5' ); ?></h3>
                    <p><?php esc_html_e( 'Talk to your AI assistant in plain English and it edits your Divi 5 pages directly — no Divi builder, no copy-paste, no manual clicking.', 'ai-editor-divi5' ); ?></p>
                    <p><?php esc_html_e( 'Free: change copy, swap buttons, update headings, and restructure sections on existing pages.', 'ai-editor-divi5' ); ?></p>
                    <p><?php esc_html_e( 'Premium: create new pages, build entire multi-page sites, apply custom CSS, and get reviewed PHP snippets.', 'ai-editor-divi5' ); ?></p>
                </div>

                <div class="aied-sidebar-card">
                    <h3><?php esc_html_e( 'How saves stay safe', 'ai-editor-divi5' ); ?></h3>
                    <ol class="aied-sidebar-steps">
                        <li>
                            <span class="aied-sidebar-step__num">1</span>
                            <div>
                                <strong><?php esc_html_e( 'You give a plain-English instruction', 'ai-editor-divi5' ); ?></strong>
                                <span><?php esc_html_e( 'e.g. "Change the hero heading to Welcome back"', 'ai-editor-divi5' ); ?></span>
                            </div>
                        </li>
                        <li>
                            <span class="aied-sidebar-step__num">2</span>
                            <div>
                                <strong><?php esc_html_e( 'AI reads the live page', 'ai-editor-divi5' ); ?></strong>
                                <span><?php esc_html_e( 'Fetches the exact Gutenberg block HTML currently on your site', 'ai-editor-divi5' ); ?></span>
                            </div>
                        </li>
                        <li>
                            <span class="aied-sidebar-step__num">3</span>
                            <div>
                                <strong><?php esc_html_e( 'Validator checks the edit', 'ai-editor-divi5' ); ?></strong>
                                <span><?php esc_html_e( '56+ Divi 5 module types, required attributes, nesting, and a single H1 — all checked before anything touches the database', 'ai-editor-divi5' ); ?></span>
                            </div>
                        </li>
                        <li>
                            <span class="aied-sidebar-step__num">4</span>
                            <div>
                                <strong><?php esc_html_e( 'Save or self-correct', 'ai-editor-divi5' ); ?></strong>
                                <span><?php esc_html_e( 'Valid layout saves instantly. Invalid layout returns exact violations so the AI fixes and retries.', 'ai-editor-divi5' ); ?></span>
                            </div>
                        </li>
                    </ol>
                </div>

                <div class="aied-sidebar-card">
                    <h3><?php esc_html_e( 'Free capabilities', 'ai-editor-divi5' ); ?></h3>
                    <p class="aied-card__desc"><?php esc_html_e( 'Always available — no license required. Read and safely edit your existing Divi 5 pages.', 'ai-editor-divi5' ); ?></p>
                    <ul class="aied-tool-list">
                        <li><code>list_divi_pages</code><span><?php esc_html_e( 'List all Divi 5 pages', 'ai-editor-divi5' ); ?></span></li>
                        <li><code>get_page_layout</code><span><?php esc_html_e( 'Read any page\'s layout', 'ai-editor-divi5' ); ?></span></li>
                        <li><code>validate_layout</code><span><?php esc_html_e( 'Dry-run a change, no save', 'ai-editor-divi5' ); ?></span></li>
                        <li><code>update_page_layout</code><span><?php esc_html_e( 'Validate then save an edit', 'ai-editor-divi5' ); ?></span></li>
                        <li><code>get_style_guide</code><span><?php esc_html_e( 'Real Divi 5 styling vocabulary', 'ai-editor-divi5' ); ?></span></li>
                        <li><code>get_section_recipes</code><span><?php esc_html_e( 'Proven section patterns', 'ai-editor-divi5' ); ?></span></li>
                        <li><code>get_site_guide</code><span><?php esc_html_e( 'Multi-page site blueprint', 'ai-editor-divi5' ); ?></span></li>
                    </ul>
                </div>

                <div class="aied-sidebar-card">
                    <h3>
                        <?php esc_html_e( 'Premium capabilities', 'ai-editor-divi5' ); ?>
                        <?php if ( $license['valid'] ) : ?>
                            <span class="aied-result aied-result--valid"><?php esc_html_e( 'ACTIVE', 'ai-editor-divi5' ); ?></span>
                        <?php else : ?>
                            <span class="aied-result aied-result--invalid"><?php esc_html_e( 'LOCKED', 'ai-editor-divi5' ); ?></span>
                        <?php endif; ?>
                    </h3>
                    <p class="aied-card__desc">
                        <?php echo $license['valid']
                            ? esc_html__( 'Unlocked on this site — create pages, build whole sites, and style with custom CSS.', 'ai-editor-divi5' )
                            : esc_html__( 'Activate a license (License section above) to unlock these.', 'ai-editor-divi5' ); ?>
                    </p>
                    <ul class="aied-tool-list">
                        <li><code>create_page</code><span><?php esc_html_e( 'Build a brand-new page', 'ai-editor-divi5' ); ?></span></li>
                        <li><code>set_front_page</code><span><?php esc_html_e( 'Set the site homepage', 'ai-editor-divi5' ); ?></span></li>
                        <li><code>set_primary_menu</code><span><?php esc_html_e( 'Build the navigation menu', 'ai-editor-divi5' ); ?></span></li>
                        <li><code>set_custom_css</code><span><?php esc_html_e( 'Apply site-wide custom CSS', 'ai-editor-divi5' ); ?></span></li>
                        <li><code>propose_php_snippet</code><span><?php esc_html_e( 'Draft PHP for your review', 'ai-editor-divi5' ); ?></span></li>
                    </ul>
                </div>

                <div class="aied-sidebar-card">
                    <h3><?php esc_html_e( 'Prompts to try', 'ai-editor-divi5' ); ?></h3>
                    <ul class="aied-prompt-list">
                        <li><?php esc_html_e( 'Show me all my Divi 5 pages', 'ai-editor-divi5' ); ?></li>
                        <li><?php esc_html_e( 'Change the hero heading on Home to Built for you', 'ai-editor-divi5' ); ?></li>
                        <li><?php esc_html_e( 'Update the CTA button on page 7 to say Get started free', 'ai-editor-divi5' ); ?></li>
                        <li><?php esc_html_e( 'What sections are on my Pricing page?', 'ai-editor-divi5' ); ?></li>
                        <li><?php esc_html_e( 'Add a bold sentence under the hero text on Home', 'ai-editor-divi5' ); ?></li>
                        <li><?php esc_html_e( 'Make the same button text change on every page', 'ai-editor-divi5' ); ?></li>
                    </ul>
                </div>

                <div class="aied-sidebar-card aied-sidebar-card--tip">
                    <h3><?php esc_html_e( 'Pro tip', 'ai-editor-divi5' ); ?></h3>
                    <p><?php esc_html_e( 'Name the page explicitly and your AI makes the edit in a single round-trip — no clarifying questions needed.', 'ai-editor-divi5' ); ?></p>
                    <p class="aied-note"><?php
                        $count = ( new \WP_Query( [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                            'post_type'      => 'page',
                            'post_status'    => 'any',
                            'posts_per_page' => 1,
                            'fields'         => 'ids',
                            'meta_query'     => [ [ 'key' => '_et_pb_use_divi_5', 'value' => 'on' ] ],
                        ] ) )->found_posts;
                        echo esc_html( sprintf(
                            /* translators: %d: number of Divi 5 pages */
                            _n( '%d Divi 5 page on this site is editable by AI.', '%d Divi 5 pages on this site are editable by AI.', $count, 'ai-editor-divi5' ),
                            $count
                        ) );
                    ?></p>
                </div>

            </aside><!-- /.aied-sidebar -->

            </div><!-- /.aied-layout -->

        </div>
        <?php
    }
}
