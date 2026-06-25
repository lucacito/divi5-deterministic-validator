<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

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
    }

    public function addMenu(): void
    {
        add_options_page(
            'AI Editor for Divi 5',
            'AI Editor for Divi 5',
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
            wp_die('Unauthorized.');
        }
        check_admin_referer('ai_editor_divi5_regenerate_key');
        ApiKey::generate();
        wp_redirect(add_query_arg(['page' => 'ai-editor-divi5', 'notice' => 'key_regenerated'], admin_url('options-general.php')));
        exit;
    }

    public function handleClearUsage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }
        check_admin_referer('ai_editor_divi5_clear_usage');
        UsageTracker::clear();
        wp_redirect(add_query_arg(['page' => 'ai-editor-divi5', 'tab' => 'usage', 'notice' => 'usage_cleared'], admin_url('options-general.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $apiKey    = ApiKey::get();
        $siteUrl   = rtrim(get_site_url(), '/');
        $mcpUrl    = $siteUrl . '/wp-json/ai-editor-divi5/v1/mcp';
        $specUrl   = $siteUrl . '/wp-json/ai-editor-divi5/v1/openapi.json';
        $activeTab = sanitize_key($_GET['tab'] ?? 'connect');
        $notice    = sanitize_key($_GET['notice'] ?? '');
        $summary   = UsageTracker::getSummary();
        $recent    = UsageTracker::getRecent(50);

        $mcpConfig = json_encode([
            'mcpServers' => [
                'ai-editor-divi5' => [
                    'url'     => $mcpUrl,
                    'headers' => ['Authorization' => "Bearer {$apiKey}"],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $cursorConfig = json_encode([
            'mcpServers' => [
                'ai-editor-divi5' => [
                    'url'     => $mcpUrl,
                    'headers' => ['Authorization' => "Bearer {$apiKey}"],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        ?>
        <div class="wrap aied-admin">

            <div class="aied-header">
                <div class="aied-header__brand">
                    <span class="aied-header__logo">✦</span>
                    <div>
                        <h1>AI Editor for Divi 5</h1>
                        <p>Edit your Divi site with natural language — changes are validated before they land.</p>
                    </div>
                </div>
                <span class="aied-header__version">v<?php echo esc_html(AI_EDITOR_DIVI5_VERSION); ?></span>
            </div>

            <?php if ($notice === 'key_regenerated'): ?>
                <div class="notice notice-success is-dismissible"><p>API key regenerated. Update your AI assistant configuration.</p></div>
            <?php elseif ($notice === 'usage_cleared'): ?>
                <div class="notice notice-success is-dismissible"><p>Usage log cleared.</p></div>
            <?php endif; ?>

            <div class="aied-layout">
            <div class="aied-main">

            <!-- API Key -->
            <div class="aied-card">
                <h2>API Key</h2>
                <p class="aied-card__desc">Paste this into your AI assistant's configuration. It authorizes all read and write operations.</p>
                <div class="aied-key-row">
                    <code class="aied-key" id="aied-api-key" data-key="<?php echo esc_attr($apiKey); ?>">
                        ••••••••••••••••••••••••••••••••••••••••
                    </code>
                    <button type="button" class="button" id="aied-toggle-key">Show</button>
                    <button type="button" class="button button-primary" data-copy="<?php echo esc_attr($apiKey); ?>">Copy</button>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <input type="hidden" name="action" value="ai_editor_divi5_regenerate_key">
                        <?php wp_nonce_field('ai_editor_divi5_regenerate_key'); ?>
                        <button type="submit" class="button aied-btn-danger"
                            onclick="return confirm('Regenerate the API key? You will need to update your AI assistant config.')">
                            Regenerate
                        </button>
                    </form>
                </div>
            </div>

            <!-- Tabs -->
            <nav class="aied-tabs" role="tablist">
                <?php foreach (['connect' => 'Connect AI', 'usage' => 'Usage'] as $slug => $label): ?>
                    <a href="?page=ai-editor-divi5&tab=<?php echo esc_attr($slug); ?>"
                       class="aied-tab <?php echo $activeTab === $slug ? 'aied-tab--active' : ''; ?>"
                       role="tab">
                        <?php echo esc_html($label); ?>
                        <?php if ($slug === 'usage' && $summary['today'] > 0): ?>
                            <span class="aied-badge"><?php echo esc_html($summary['today']); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- Connect Tab -->
            <?php if ($activeTab === 'connect'): ?>
            <div class="aied-card">
                <div class="aied-llm-tabs" id="aied-llm-tabs">
                    <button class="aied-llm-tab aied-llm-tab--active" data-target="claude">Claude Desktop</button>
                    <button class="aied-llm-tab" data-target="cursor">Cursor / Windsurf</button>
                    <button class="aied-llm-tab" data-target="vscode">VS Code Copilot</button>
                    <button class="aied-llm-tab" data-target="chatgpt">ChatGPT</button>
                    <button class="aied-llm-tab" data-target="api">REST API</button>
                </div>

                <!-- Claude Desktop -->
                <div class="aied-llm-panel" id="aied-panel-claude">
                    <ol class="aied-steps">
                        <li>Open <strong>Claude Desktop</strong> → quit with <kbd>Cmd+Q</kbd></li>
                        <li>Open this file in a text editor:<br>
                            <code>~/Library/Application Support/Claude/claude_desktop_config.json</code>
                        </li>
                        <li>Add or merge the <code>mcpServers</code> key:</li>
                    </ol>
                    <div class="aied-snippet-wrap">
                        <pre class="aied-snippet" id="snippet-claude"><?php echo esc_html($mcpConfig); ?></pre>
                        <button class="button button-primary aied-copy-btn" data-target="snippet-claude">Copy</button>
                    </div>
                    <ol class="aied-steps" start="4">
                        <li>Save the file and reopen Claude Desktop</li>
                        <li>Click <strong>+</strong> in a new chat → Connectors → <strong>ai-editor-divi5</strong> should appear ✓</li>
                    </ol>
                </div>

                <!-- Cursor / Windsurf -->
                <div class="aied-llm-panel" id="aied-panel-cursor" hidden>
                    <ol class="aied-steps">
                        <li>Open <strong>Cursor</strong> → Settings → <strong>MCP</strong></li>
                        <li>Click <strong>Add new global MCP server</strong> and paste:</li>
                    </ol>
                    <div class="aied-snippet-wrap">
                        <pre class="aied-snippet" id="snippet-cursor"><?php echo esc_html($cursorConfig); ?></pre>
                        <button class="button button-primary aied-copy-btn" data-target="snippet-cursor">Copy</button>
                    </div>
                    <p class="aied-note">Windsurf: <strong>Settings → Cascade → MCP Servers → Add Server</strong> — use the same JSON.</p>
                </div>

                <!-- VS Code Copilot -->
                <div class="aied-llm-panel" id="aied-panel-vscode" hidden>
                    <ol class="aied-steps">
                        <li>Open VS Code → <kbd>Cmd+Shift+P</kbd> → <strong>Open User Settings (JSON)</strong></li>
                        <li>Add inside the root object:</li>
                    </ol>
                    <div class="aied-snippet-wrap">
                        <pre class="aied-snippet" id="snippet-vscode"><?php echo esc_html(json_encode([
                            'github.copilot.chat.mcp.enabled' => true,
                            'mcp' => [
                                'servers' => [
                                    'ai-editor-divi5' => [
                                        'url'     => $mcpUrl,
                                        'headers' => ['Authorization' => "Bearer {$apiKey}"],
                                    ],
                                ],
                            ],
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        <button class="button button-primary aied-copy-btn" data-target="snippet-vscode">Copy</button>
                    </div>
                    <ol class="aied-steps" start="3">
                        <li>Reload VS Code — the tools appear in Copilot Chat with the <strong>#</strong> symbol</li>
                    </ol>
                </div>

                <!-- ChatGPT -->
                <div class="aied-llm-panel" id="aied-panel-chatgpt" hidden>
                    <ol class="aied-steps">
                        <li>Go to <strong>chat.openai.com</strong> → Explore GPTs → <strong>Create a GPT</strong></li>
                        <li>Go to the <strong>Configure</strong> tab → scroll to <strong>Actions</strong> → <strong>Create new action</strong></li>
                        <li>Click <strong>Import from URL</strong> and paste the OpenAPI spec URL:</li>
                    </ol>
                    <div class="aied-snippet-wrap">
                        <pre class="aied-snippet" id="snippet-spec-url"><?php echo esc_html($specUrl); ?></pre>
                        <button class="button button-primary aied-copy-btn" data-target="snippet-spec-url">Copy</button>
                    </div>
                    <ol class="aied-steps" start="4">
                        <li>Under <strong>Authentication</strong>, choose <strong>API Key</strong> → <strong>Bearer</strong> and paste your API key</li>
                        <li>Save the GPT and test it with: <em>"List my Divi pages"</em></li>
                    </ol>
                    <p class="aied-note">The OpenAPI spec is always up to date at: <a href="<?php echo esc_url($specUrl); ?>" target="_blank"><?php echo esc_html($specUrl); ?></a></p>
                </div>

                <!-- REST API -->
                <div class="aied-llm-panel" id="aied-panel-api" hidden>
                    <p>Use the REST API directly from any tool, script, or custom integration.</p>
                    <div class="aied-snippet-wrap">
                        <pre class="aied-snippet" id="snippet-api"><?php echo esc_html(implode("\n\n", [
                            "# List Divi 5 pages\ncurl -H \"Authorization: Bearer {$apiKey}\" \\\n  {$siteUrl}/wp-json/ai-editor-divi5/v1/pages/",
                            "# Get a page layout\ncurl -H \"Authorization: Bearer {$apiKey}\" \\\n  {$siteUrl}/wp-json/ai-editor-divi5/v1/pages/7/",
                            "# Validate a layout\ncurl -X POST -H \"Authorization: Bearer {$apiKey}\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"post_content\":\"...\"}' \\\n  {$siteUrl}/wp-json/ai-editor-divi5/v1/validate/",
                        ])); ?></pre>
                        <button class="button button-primary aied-copy-btn" data-target="snippet-api">Copy</button>
                    </div>
                    <p class="aied-note">Full spec: <a href="<?php echo esc_url($specUrl); ?>" target="_blank">OpenAPI JSON</a></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Usage Tab -->
            <?php if ($activeTab === 'usage'): ?>
            <div class="aied-card">
                <div class="aied-stats">
                    <div class="aied-stat">
                        <span class="aied-stat__num"><?php echo esc_html($summary['total']); ?></span>
                        <span class="aied-stat__label">Total edits</span>
                    </div>
                    <div class="aied-stat">
                        <span class="aied-stat__num"><?php echo esc_html($summary['today']); ?></span>
                        <span class="aied-stat__label">Today</span>
                    </div>
                    <div class="aied-stat aied-stat--valid">
                        <span class="aied-stat__num"><?php echo esc_html($summary['valid']); ?></span>
                        <span class="aied-stat__label">Saved</span>
                    </div>
                    <div class="aied-stat aied-stat--invalid">
                        <span class="aied-stat__num"><?php echo esc_html($summary['invalid']); ?></span>
                        <span class="aied-stat__label">Blocked</span>
                    </div>
                </div>

                <?php if (!empty($summary['byClient'])): ?>
                <div class="aied-by-client">
                    <h3>By AI assistant</h3>
                    <div class="aied-client-bars">
                        <?php
                        $max = max(array_column($summary['byClient'], 'cnt'));
                        foreach ($summary['byClient'] as $row):
                            $pct = $max > 0 ? round(($row['cnt'] / $max) * 100) : 0;
                        ?>
                        <div class="aied-client-bar">
                            <span class="aied-client-bar__label"><?php echo esc_html($row['client'] ?: 'Unknown'); ?></span>
                            <div class="aied-client-bar__track">
                                <div class="aied-client-bar__fill" style="width:<?php echo esc_attr($pct); ?>%"></div>
                            </div>
                            <span class="aied-client-bar__count"><?php echo esc_html($row['cnt']); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="aied-table-header">
                    <h3>Recent activity</h3>
                    <?php if (!empty($recent)): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="ai_editor_divi5_clear_usage">
                        <?php wp_nonce_field('ai_editor_divi5_clear_usage'); ?>
                        <button type="submit" class="button aied-btn-danger"
                            onclick="return confirm('Clear all activity logs?')">Clear log</button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if (empty($recent)): ?>
                    <p class="aied-empty">No activity yet. Connect an AI assistant and start editing your Divi pages!</p>
                <?php else: ?>
                <table class="wp-list-table widefat fixed striped aied-usage-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Action</th>
                            <th>Page</th>
                            <th>Result</th>
                            <th>Violations</th>
                            <th>AI assistant</th>
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
                                <span class="aied-result aied-result--<?php echo esc_attr($row['result']); ?>">
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

            </div><!-- /.aied-main -->

            <!-- Sidebar -->
            <aside class="aied-sidebar">

                <div class="aied-sidebar-card">
                    <h3>What you can do</h3>
                    <p>Talk to your AI assistant in plain English and it will edit your Divi 5 pages directly — no Divi builder required, no copy-paste, no manual clicking.</p>
                    <p>Ask it to change copy, swap buttons, update headings, restructure sections, or make the same change across multiple pages at once. The AI reads the current layout, makes your change, and saves it back.</p>
                </div>

                <div class="aied-sidebar-card">
                    <h3>How saves stay safe</h3>
                    <ol class="aied-sidebar-steps">
                        <li>
                            <span class="aied-sidebar-step__num">1</span>
                            <div>
                                <strong>You give a plain-English instruction</strong>
                                <span>"Change the hero heading to 'Welcome back'"</span>
                            </div>
                        </li>
                        <li>
                            <span class="aied-sidebar-step__num">2</span>
                            <div>
                                <strong>AI reads the live page</strong>
                                <span>Fetches the exact Gutenberg block HTML currently on your site</span>
                            </div>
                        </li>
                        <li>
                            <span class="aied-sidebar-step__num">3</span>
                            <div>
                                <strong>Validator checks the edit</strong>
                                <span>37 Divi 5 block types, required attributes, hierarchy — all checked deterministically before anything touches the DB</span>
                            </div>
                        </li>
                        <li>
                            <span class="aied-sidebar-step__num">4</span>
                            <div>
                                <strong>Save or self-correct</strong>
                                <span>Valid → page updated instantly. Invalid → AI receives the exact violations and fixes them automatically</span>
                            </div>
                        </li>
                    </ol>
                </div>

                <div class="aied-sidebar-card">
                    <h3>4 tools your AI gets</h3>
                    <ul class="aied-tool-list">
                        <li>
                            <code>list_divi_pages</code>
                            <span>See all pages built with Divi 5</span>
                        </li>
                        <li>
                            <code>get_page_layout</code>
                            <span>Read the current layout of any page</span>
                        </li>
                        <li>
                            <code>validate_layout</code>
                            <span>Dry-run a change without saving</span>
                        </li>
                        <li>
                            <code>update_page_layout</code>
                            <span>Validate then save — the live edit tool</span>
                        </li>
                    </ul>
                </div>

                <div class="aied-sidebar-card">
                    <h3>Prompts to try right now</h3>
                    <ul class="aied-prompt-list">
                        <li>Show me all my Divi 5 pages</li>
                        <li>Change the hero heading on Home to 'Built for you'</li>
                        <li>Update the CTA button on page 7 to say 'Get started free'</li>
                        <li>What sections are on my Pricing page?</li>
                        <li>Add a bold sentence under the hero text on Home</li>
                        <li>Make the same button text change on every page</li>
                    </ul>
                </div>

                <div class="aied-sidebar-card aied-sidebar-card--tip">
                    <h3>Pro tip</h3>
                    <p>Name the page explicitly ("on the Home page", "on page 7") and your AI makes the edit in a single round-trip — no clarifying questions needed.</p>
                    <p class="aied-note"><?php
                        $count = count(get_posts(['post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_query' => [['key' => '_et_pb_use_divi_5', 'value' => 'on']]]));
                        echo esc_html($count . ' Divi 5 ' . ($count === 1 ? 'page' : 'pages') . ' on this site are editable by AI.');
                    ?></p>
                </div>

            </aside><!-- /.aied-sidebar -->

            </div><!-- /.aied-layout -->

        </div>
        <?php
    }
}
