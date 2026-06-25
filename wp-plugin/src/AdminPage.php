<?php

declare(strict_types=1);

namespace Divi5Validator\WP;

/**
 * WordPress admin settings page.
 * Menu: Settings → Divi 5 Validator
 */
final class AdminPage
{
    public function register(): void
    {
        add_action('admin_menu',    [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_divi5_regenerate_key',  [$this, 'handleRegenerate']);
        add_action('admin_post_divi5_clear_usage',     [$this, 'handleClearUsage']);
    }

    public function addMenu(): void
    {
        add_options_page(
            'Divi 5 Validator',
            'Divi 5 Validator',
            'manage_options',
            'divi5-validator',
            [$this, 'render']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'settings_page_divi5-validator') {
            return;
        }
        wp_enqueue_style(
            'divi5-validator-admin',
            plugin_dir_url(DIVI5_VALIDATOR_FILE) . 'assets/admin.css',
            [],
            DIVI5_VALIDATOR_VERSION
        );
        wp_enqueue_script(
            'divi5-validator-admin',
            plugin_dir_url(DIVI5_VALIDATOR_FILE) . 'assets/admin.js',
            [],
            DIVI5_VALIDATOR_VERSION,
            true
        );
    }

    public function handleRegenerate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }
        check_admin_referer('divi5_regenerate_key');
        ApiKey::generate();
        wp_redirect(add_query_arg(['page' => 'divi5-validator', 'notice' => 'key_regenerated'], admin_url('options-general.php')));
        exit;
    }

    public function handleClearUsage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }
        check_admin_referer('divi5_clear_usage');
        UsageTracker::clear();
        wp_redirect(add_query_arg(['page' => 'divi5-validator', 'tab' => 'usage', 'notice' => 'usage_cleared'], admin_url('options-general.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $apiKey   = ApiKey::get();
        $siteUrl  = rtrim(get_site_url(), '/');
        $mcpUrl   = $siteUrl . '/wp-json/divi5-validator/v1/mcp';
        $specUrl  = $siteUrl . '/wp-json/divi5-validator/v1/openapi.json';
        $activeTab = sanitize_key($_GET['tab'] ?? 'connect');
        $notice    = sanitize_key($_GET['notice'] ?? '');
        $summary   = UsageTracker::getSummary();
        $recent    = UsageTracker::getRecent(50);

        $mcpConfig = json_encode([
            'mcpServers' => [
                'divi5-validator' => [
                    'url'     => $mcpUrl,
                    'headers' => ['Authorization' => "Bearer {$apiKey}"],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $cursorConfig = json_encode([
            'mcpServers' => [
                'divi5-validator' => [
                    'url'     => $mcpUrl,
                    'headers' => ['Authorization' => "Bearer {$apiKey}"],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        ?>
        <div class="wrap divi5-admin">

            <div class="divi5-header">
                <div class="divi5-header__brand">
                    <span class="divi5-header__logo">✦</span>
                    <div>
                        <h1>Divi 5 Validator</h1>
                        <p>Connect your AI assistant to WordPress — safely.</p>
                    </div>
                </div>
                <span class="divi5-header__version">v<?php echo esc_html(DIVI5_VALIDATOR_VERSION); ?></span>
            </div>

            <?php if ($notice === 'key_regenerated'): ?>
                <div class="notice notice-success is-dismissible"><p>API key regenerated. Update your AI assistant configuration.</p></div>
            <?php elseif ($notice === 'usage_cleared'): ?>
                <div class="notice notice-success is-dismissible"><p>Usage log cleared.</p></div>
            <?php endif; ?>

            <div class="divi5-layout">
            <div class="divi5-main">

            <!-- API Key -->
            <div class="divi5-card">
                <h2>API Key</h2>
                <p class="divi5-card__desc">This key authenticates all AI assistant connections. Keep it secret.</p>
                <div class="divi5-key-row">
                    <code class="divi5-key" id="divi5-api-key" data-key="<?php echo esc_attr($apiKey); ?>">
                        ••••••••••••••••••••••••••••••••••••••••
                    </code>
                    <button type="button" class="button" id="divi5-toggle-key">Show</button>
                    <button type="button" class="button button-primary" data-copy="<?php echo esc_attr($apiKey); ?>">Copy</button>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <input type="hidden" name="action" value="divi5_regenerate_key">
                        <?php wp_nonce_field('divi5_regenerate_key'); ?>
                        <button type="submit" class="button divi5-btn-danger"
                            onclick="return confirm('Regenerate the API key? You will need to update your AI assistant config.')">
                            Regenerate
                        </button>
                    </form>
                </div>
            </div>

            <!-- Tabs -->
            <nav class="divi5-tabs" role="tablist">
                <?php foreach (['connect' => 'Connect AI', 'usage' => 'Usage'] as $slug => $label): ?>
                    <a href="?page=divi5-validator&tab=<?php echo esc_attr($slug); ?>"
                       class="divi5-tab <?php echo $activeTab === $slug ? 'divi5-tab--active' : ''; ?>"
                       role="tab">
                        <?php echo esc_html($label); ?>
                        <?php if ($slug === 'usage' && $summary['today'] > 0): ?>
                            <span class="divi5-badge"><?php echo esc_html($summary['today']); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Connect Tab -->
            <?php if ($activeTab === 'connect'): ?>
            <div class="divi5-card">
                <div class="divi5-llm-tabs" id="divi5-llm-tabs">
                    <button class="divi5-llm-tab divi5-llm-tab--active" data-target="claude">Claude Desktop</button>
                    <button class="divi5-llm-tab" data-target="cursor">Cursor / Windsurf</button>
                    <button class="divi5-llm-tab" data-target="vscode">VS Code Copilot</button>
                    <button class="divi5-llm-tab" data-target="chatgpt">ChatGPT</button>
                    <button class="divi5-llm-tab" data-target="api">REST API</button>
                </div>

                <!-- Claude Desktop -->
                <div class="divi5-llm-panel" id="divi5-panel-claude">
                    <ol class="divi5-steps">
                        <li>Open <strong>Claude Desktop</strong> → quit with <kbd>Cmd+Q</kbd></li>
                        <li>Open this file in a text editor:<br>
                            <code>~/Library/Application Support/Claude/claude_desktop_config.json</code>
                        </li>
                        <li>Add or merge the <code>mcpServers</code> key:</li>
                    </ol>
                    <div class="divi5-snippet-wrap">
                        <pre class="divi5-snippet" id="snippet-claude"><?php echo esc_html($mcpConfig); ?></pre>
                        <button class="button button-primary divi5-copy-btn" data-target="snippet-claude">Copy</button>
                    </div>
                    <ol class="divi5-steps" start="4">
                        <li>Save the file and reopen Claude Desktop</li>
                        <li>Click <strong>+</strong> in a new chat → Connectors → <strong>divi5-validator</strong> should appear ✓</li>
                    </ol>
                </div>

                <!-- Cursor / Windsurf -->
                <div class="divi5-llm-panel" id="divi5-panel-cursor" hidden>
                    <ol class="divi5-steps">
                        <li>Open <strong>Cursor</strong> → Settings → <strong>MCP</strong></li>
                        <li>Click <strong>Add new global MCP server</strong> and paste:</li>
                    </ol>
                    <div class="divi5-snippet-wrap">
                        <pre class="divi5-snippet" id="snippet-cursor"><?php echo esc_html($cursorConfig); ?></pre>
                        <button class="button button-primary divi5-copy-btn" data-target="snippet-cursor">Copy</button>
                    </div>
                    <p class="divi5-note">Windsurf: <strong>Settings → Cascade → MCP Servers → Add Server</strong> — use the same JSON.</p>
                </div>

                <!-- VS Code Copilot -->
                <div class="divi5-llm-panel" id="divi5-panel-vscode" hidden>
                    <ol class="divi5-steps">
                        <li>Open VS Code → <kbd>Cmd+Shift+P</kbd> → <strong>Open User Settings (JSON)</strong></li>
                        <li>Add inside the root object:</li>
                    </ol>
                    <div class="divi5-snippet-wrap">
                        <pre class="divi5-snippet" id="snippet-vscode"><?php echo esc_html(json_encode([
                            'github.copilot.chat.mcp.enabled' => true,
                            'mcp' => [
                                'servers' => [
                                    'divi5-validator' => [
                                        'url'     => $mcpUrl,
                                        'headers' => ['Authorization' => "Bearer {$apiKey}"],
                                    ],
                                ],
                            ],
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        <button class="button button-primary divi5-copy-btn" data-target="snippet-vscode">Copy</button>
                    </div>
                    <ol class="divi5-steps" start="3">
                        <li>Reload VS Code — the tools appear in Copilot Chat with the <strong>#</strong> symbol</li>
                    </ol>
                </div>

                <!-- ChatGPT -->
                <div class="divi5-llm-panel" id="divi5-panel-chatgpt" hidden>
                    <ol class="divi5-steps">
                        <li>Go to <strong>chat.openai.com</strong> → Explore GPTs → <strong>Create a GPT</strong></li>
                        <li>Go to the <strong>Configure</strong> tab → scroll to <strong>Actions</strong> → <strong>Create new action</strong></li>
                        <li>Click <strong>Import from URL</strong> and paste the OpenAPI spec URL:</li>
                    </ol>
                    <div class="divi5-snippet-wrap">
                        <pre class="divi5-snippet" id="snippet-spec-url"><?php echo esc_html($specUrl); ?></pre>
                        <button class="button button-primary divi5-copy-btn" data-target="snippet-spec-url">Copy</button>
                    </div>
                    <ol class="divi5-steps" start="4">
                        <li>Under <strong>Authentication</strong>, choose <strong>API Key</strong> → <strong>Bearer</strong> and paste your API key</li>
                        <li>Save the GPT and test it with: <em>"List my Divi pages"</em></li>
                    </ol>
                    <p class="divi5-note">The OpenAPI spec is always up to date at: <a href="<?php echo esc_url($specUrl); ?>" target="_blank"><?php echo esc_html($specUrl); ?></a></p>
                </div>

                <!-- REST API -->
                <div class="divi5-llm-panel" id="divi5-panel-api" hidden>
                    <p>Use the REST API directly from any tool, script, or custom integration.</p>
                    <div class="divi5-snippet-wrap">
                        <pre class="divi5-snippet" id="snippet-api"><?php echo esc_html(implode("\n\n", [
                            "# List Divi 5 pages\ncurl -H \"Authorization: Bearer {$apiKey}\" \\\n  {$siteUrl}/wp-json/divi5-validator/v1/pages/",
                            "# Get a page layout\ncurl -H \"Authorization: Bearer {$apiKey}\" \\\n  {$siteUrl}/wp-json/divi5-validator/v1/pages/7/",
                            "# Validate a layout\ncurl -X POST -H \"Authorization: Bearer {$apiKey}\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"post_content\":\"...\"}' \\\n  {$siteUrl}/wp-json/divi5-validator/v1/validate/",
                        ])); ?></pre>
                        <button class="button button-primary divi5-copy-btn" data-target="snippet-api">Copy</button>
                    </div>
                    <p class="divi5-note">Full spec: <a href="<?php echo esc_url($specUrl); ?>" target="_blank">OpenAPI JSON</a></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Usage Tab -->
            <?php if ($activeTab === 'usage'): ?>
            <div class="divi5-card">
                <div class="divi5-stats">
                    <div class="divi5-stat">
                        <span class="divi5-stat__num"><?php echo esc_html($summary['total']); ?></span>
                        <span class="divi5-stat__label">Total calls</span>
                    </div>
                    <div class="divi5-stat">
                        <span class="divi5-stat__num"><?php echo esc_html($summary['today']); ?></span>
                        <span class="divi5-stat__label">Today</span>
                    </div>
                    <div class="divi5-stat divi5-stat--valid">
                        <span class="divi5-stat__num"><?php echo esc_html($summary['valid']); ?></span>
                        <span class="divi5-stat__label">Valid</span>
                    </div>
                    <div class="divi5-stat divi5-stat--invalid">
                        <span class="divi5-stat__num"><?php echo esc_html($summary['invalid']); ?></span>
                        <span class="divi5-stat__label">Invalid / blocked</span>
                    </div>
                </div>

                <?php if (!empty($summary['byClient'])): ?>
                <div class="divi5-by-client">
                    <h3>By AI assistant</h3>
                    <div class="divi5-client-bars">
                        <?php
                        $max = max(array_column($summary['byClient'], 'cnt'));
                        foreach ($summary['byClient'] as $row):
                            $pct = $max > 0 ? round(($row['cnt'] / $max) * 100) : 0;
                        ?>
                        <div class="divi5-client-bar">
                            <span class="divi5-client-bar__label"><?php echo esc_html($row['client'] ?: 'Unknown'); ?></span>
                            <div class="divi5-client-bar__track">
                                <div class="divi5-client-bar__fill" style="width:<?php echo esc_attr($pct); ?>%"></div>
                            </div>
                            <span class="divi5-client-bar__count"><?php echo esc_html($row['cnt']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="divi5-table-header">
                    <h3>Recent calls</h3>
                    <?php if (!empty($recent)): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="divi5_clear_usage">
                        <?php wp_nonce_field('divi5_clear_usage'); ?>
                        <button type="submit" class="button divi5-btn-danger"
                            onclick="return confirm('Clear all usage logs?')">Clear log</button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if (empty($recent)): ?>
                    <p class="divi5-empty">No API calls recorded yet. Connect an AI assistant and start editing!</p>
                <?php else: ?>
                <table class="wp-list-table widefat fixed striped divi5-usage-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Endpoint</th>
                            <th>Page</th>
                            <th>Result</th>
                            <th>Violations</th>
                            <th>Client</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $row): ?>
                        <tr>
                            <td><?php echo esc_html(date('M j, H:i:s', strtotime($row['created_at']))); ?></td>
                            <td><code><?php echo esc_html($row['endpoint']); ?></code></td>
                            <td>
                                <?php if ($row['page_id']): ?>
                                    <a href="<?php echo esc_url(get_edit_post_link((int) $row['page_id'])); ?>">#<?php echo esc_html($row['page_id']); ?></a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <span class="divi5-result divi5-result--<?php echo esc_attr($row['result']); ?>">
                                    <?php echo esc_html(strtoupper($row['result'])); ?>
                                </span>
                            </td>
                            <td><?php echo $row['violations'] > 0 ? esc_html($row['violations']) : '—'; ?></td>
                            <td><?php echo esc_html($row['client'] ?: '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            </div><!-- /.divi5-main -->

            <!-- Sidebar -->
            <aside class="divi5-sidebar">

                <div class="divi5-sidebar-card">
                    <h3>What this plugin does</h3>
                    <p>Divi 5 Validator sits between your AI assistant and WordPress. Every time the AI tries to save a page layout, the validator checks it against the full Divi 5 schema first.</p>
                    <p>If the layout is valid, the save goes through. If it isn't, the page is left untouched and the AI receives a list of exactly what is wrong — so it can fix it and try again.</p>
                    <p><strong>Broken pages become impossible.</strong></p>
                </div>

                <div class="divi5-sidebar-card">
                    <h3>How it works</h3>
                    <ol class="divi5-sidebar-steps">
                        <li>
                            <span class="divi5-sidebar-step__num">1</span>
                            <div>
                                <strong>You prompt your AI</strong>
                                <span>"Change the hero heading to 'Welcome back'"</span>
                            </div>
                        </li>
                        <li>
                            <span class="divi5-sidebar-step__num">2</span>
                            <div>
                                <strong>AI reads the page</strong>
                                <span>Uses <code>get_page_layout</code> to fetch the current Gutenberg block HTML</span>
                            </div>
                        </li>
                        <li>
                            <span class="divi5-sidebar-step__num">3</span>
                            <div>
                                <strong>Validator checks before save</strong>
                                <span>37 Divi 5 block types, required attributes, and hierarchy rules — all deterministic, zero AI guesswork</span>
                            </div>
                        </li>
                        <li>
                            <span class="divi5-sidebar-step__num">4</span>
                            <div>
                                <strong>Save or report</strong>
                                <span>Valid layout → page updated instantly. Invalid → violations returned so the AI self-corrects</span>
                            </div>
                        </li>
                    </ol>
                </div>

                <div class="divi5-sidebar-card">
                    <h3>4 tools available to your AI</h3>
                    <ul class="divi5-tool-list">
                        <li>
                            <code>list_divi_pages</code>
                            <span>See all pages built with Divi 5</span>
                        </li>
                        <li>
                            <code>get_page_layout</code>
                            <span>Read a page's current layout</span>
                        </li>
                        <li>
                            <code>validate_layout</code>
                            <span>Check a layout without saving</span>
                        </li>
                        <li>
                            <code>update_page_layout</code>
                            <span>Validate then save — the safety gate</span>
                        </li>
                    </ul>
                </div>

                <div class="divi5-sidebar-card">
                    <h3>Example prompts to try</h3>
                    <ul class="divi5-prompt-list">
                        <li>"List all my Divi 5 pages"</li>
                        <li>"Change the hero heading on the Home page to 'Welcome'"</li>
                        <li>"Add a CTA button to the pricing section"</li>
                        <li>"Update the button text on page 7 to 'Get Started'"</li>
                        <li>"What's currently on my Home page?"</li>
                        <li>"Validate this layout and tell me if it's broken"</li>
                    </ul>
                </div>

                <div class="divi5-sidebar-card divi5-sidebar-card--tip">
                    <h3>Quick tip</h3>
                    <p>Tell your AI which page to work on by name or ID. The more specific you are, the fewer round-trips it needs.</p>
                    <p class="divi5-note">All <?php echo esc_html(count(get_posts(['post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_query' => [['key' => '_et_pb_use_divi_5', 'value' => 'on']]]))); ?> Divi 5 pages on this site are accessible via the plugin.</p>
                </div>

            </aside><!-- /.divi5-sidebar -->

            </div><!-- /.divi5-layout -->

        </div>
        <?php
    }
}
