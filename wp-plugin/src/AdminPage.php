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
    }

    public function addMenu(): void
    {
        add_menu_page(
            __( 'AI Editor for Divi 5', 'ai-editor-divi5' ),
            __( 'AI Editor', 'ai-editor-divi5' ),
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
            wp_die( esc_html__( 'Unauthorized.', 'ai-editor-divi5' ) );
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
        $this->guard('ai_editor_divi5_activate_license');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- base64url key, trimmed in Licensing.
        Licensing::setKey( sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) ) );
        $this->redirect('upgrade', Licensing::isPremium() ? 'license_activated' : 'license_invalid');
    }

    public function handleDeactivateLicense(): void
    {
        $this->guard('ai_editor_divi5_deactivate_license');
        Licensing::clear();
        $this->redirect('upgrade', 'license_deactivated');
    }

    public function handleDeleteProposal(): void
    {
        $this->guard('ai_editor_divi5_delete_proposal');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- opaque id, sanitized.
        PhpProposals::delete( sanitize_text_field( wp_unslash( $_POST['proposal_id'] ?? '' ) ) );
        $this->redirect('settings', 'proposal_deleted');
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
            ['label' => __( 'Plugin activated', 'ai-editor-divi5' ),            'done' => true],
            ['label' => __( 'AI assistant connected', 'ai-editor-divi5' ),      'done' => (int) $summary['total'] > 0],
            ['label' => __( 'First page edit saved', 'ai-editor-divi5' ),       'done' => (int) $summary['valid'] > 0],
            ['label' => __( 'Premium unlocked', 'ai-editor-divi5' ),            'done' => $this->isPremium()],
        ];
        $done  = count(array_filter($steps, static fn($s) => $s['done']));
        $total = count($steps);
        return ['steps' => $steps, 'done' => $done, 'total' => $total, 'pct' => (int) round($done / $total * 100)];
    }

    /** Connection details + a ready-to-paste config snippet. */
    private function connection(): array
    {
        $apiKey  = ApiKey::get();
        $siteUrl = rtrim(get_site_url(), '/');
        $mcpUrl  = $siteUrl . '/wp-json/ai-editor-divi5/v1/mcp';
        $snippet = wp_json_encode([
            'mcpServers' => ['ai-editor-divi5' => [
                'url'     => $mcpUrl,
                'headers' => ['Authorization' => "Bearer {$apiKey}"],
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return ['key' => $apiKey, 'mcpUrl' => $mcpUrl, 'specUrl' => $siteUrl . '/wp-json/ai-editor-divi5/v1/openapi.json', 'snippet' => (string) $snippet];
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
            'dashboard' => __( 'Dashboard', 'ai-editor-divi5' ),
            'features'  => __( 'Features', 'ai-editor-divi5' ),
            'settings'  => __( 'Settings', 'ai-editor-divi5' ),
            'upgrade'   => $premium ? __( 'Account', 'ai-editor-divi5' ) : __( 'Upgrade', 'ai-editor-divi5' ),
        ];
        ?>
        <div class="wrap aied">
            <div class="aied-topbar">
                <div class="aied-topbar__brand">
                    <span class="aied-logo">&#10086;</span>
                    <div>
                        <strong><?php esc_html_e( 'AI Editor for Divi 5', 'ai-editor-divi5' ); ?></strong>
                        <span class="aied-topbar__ver">v<?php echo esc_html( AI_EDITOR_DIVI5_VERSION ); ?></span>
                    </div>
                </div>
                <span class="aied-plan aied-plan--<?php echo $premium ? 'pro' : 'free'; ?>">
                    <?php echo $premium ? esc_html__( 'Premium', 'ai-editor-divi5' ) : esc_html__( 'Free plan', 'ai-editor-divi5' ); ?>
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
            'key_regenerated'    => __( 'API key regenerated. Update your AI assistant configuration.', 'ai-editor-divi5' ),
            'usage_cleared'      => __( 'Activity log cleared.', 'ai-editor-divi5' ),
            'license_activated'  => __( 'License activated — premium features are now unlocked.', 'ai-editor-divi5' ),
            'license_deactivated'=> __( 'License removed.', 'ai-editor-divi5' ),
            'proposal_deleted'   => __( 'Code proposal deleted.', 'ai-editor-divi5' ),
        ];
        if ( isset( $map[ $notice ] ) ) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $map[ $notice ] ));
        } elseif ( $notice === 'license_invalid' ) {
            printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html__( 'That license key is not valid for this site.', 'ai-editor-divi5' ));
        }
    }

    // ---------------------------------------------------------------
    // View: Dashboard
    // ---------------------------------------------------------------

    private function viewDashboard(): void
    {
        $summary  = UsageTracker::getSummary();
        $progress = $this->setupProgress();
        $connected = (int) $summary['total'] > 0;
        $premium  = $this->isPremium();
        $proposals = PhpProposals::count();
        ?>
        <div class="aied-hello">
            <h1><?php esc_html_e( 'Welcome 👋', 'ai-editor-divi5' ); ?></h1>
            <p><?php esc_html_e( 'Edit your Divi 5 site by chatting with your AI assistant. Here’s what to do next.', 'ai-editor-divi5' ); ?></p>
        </div>

        <div class="aied-grid aied-grid--2">
            <!-- Primary action -->
            <div class="aied-card aied-card--primary">
                <?php if ( ! $connected ) : ?>
                    <span class="aied-eyebrow"><?php esc_html_e( 'Start here', 'ai-editor-divi5' ); ?></span>
                    <h2><?php esc_html_e( 'Connect your AI assistant', 'ai-editor-divi5' ); ?></h2>
                    <p><?php esc_html_e( 'Paste one config into Claude, Cursor, VS Code, or ChatGPT — then edit your site in plain English.', 'ai-editor-divi5' ); ?></p>
                    <a class="button button-primary button-hero" href="<?php echo esc_url( add_query_arg(['page' => self::SLUG, 'tab' => 'settings'], admin_url('admin.php')) ); ?>">
                        <?php esc_html_e( 'Connect now', 'ai-editor-divi5' ); ?>
                    </a>
                <?php else : ?>
                    <span class="aied-eyebrow aied-eyebrow--ok">&#10003; <?php esc_html_e( 'Connected', 'ai-editor-divi5' ); ?></span>
                    <h2><?php esc_html_e( 'Try this in your AI assistant', 'ai-editor-divi5' ); ?></h2>
                    <p class="aied-try">“<?php esc_html_e( 'Change the hero heading on my Home page to “Built for you”.', 'ai-editor-divi5' ); ?>”</p>
                    <a class="button button-secondary" href="<?php echo esc_url( admin_url('edit.php?post_type=page') ); ?>"><?php esc_html_e( 'View your pages', 'ai-editor-divi5' ); ?></a>
                <?php endif; ?>
            </div>

            <!-- Setup progress -->
            <div class="aied-card">
                <div class="aied-card__head">
                    <h3><?php esc_html_e( 'Setup progress', 'ai-editor-divi5' ); ?></h3>
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
        <h3 class="aied-section-title"><?php esc_html_e( 'Your results', 'ai-editor-divi5' ); ?></h3>
        <?php if ( $connected ) : ?>
            <div class="aied-stats">
                <div class="aied-stat"><span class="aied-stat__n"><?php echo esc_html( $summary['total'] ); ?></span><span class="aied-stat__l"><?php esc_html_e( 'AI edits processed', 'ai-editor-divi5' ); ?></span></div>
                <div class="aied-stat"><span class="aied-stat__n aied-pos"><?php echo esc_html( $summary['valid'] ); ?></span><span class="aied-stat__l"><?php esc_html_e( 'Changes saved', 'ai-editor-divi5' ); ?></span></div>
                <div class="aied-stat"><span class="aied-stat__n aied-warn"><?php echo esc_html( $summary['invalid'] ); ?></span><span class="aied-stat__l"><?php esc_html_e( 'Invalid layouts blocked', 'ai-editor-divi5' ); ?></span></div>
                <div class="aied-stat"><span class="aied-stat__n"><?php echo esc_html( $summary['today'] ); ?></span><span class="aied-stat__l"><?php esc_html_e( 'Today', 'ai-editor-divi5' ); ?></span></div>
            </div>
        <?php else : ?>
            <div class="aied-card aied-empty">
                <p><strong><?php esc_html_e( 'No activity yet', 'ai-editor-divi5' ); ?></strong></p>
                <p class="aied-muted"><?php esc_html_e( 'Connect your AI assistant and make your first edit — your results will show up here.', 'ai-editor-divi5' ); ?></p>
            </div>
        <?php endif; ?>

        <!-- Recommendations -->
        <h3 class="aied-section-title"><?php esc_html_e( 'Recommended for you', 'ai-editor-divi5' ); ?></h3>
        <div class="aied-grid aied-grid--3">
            <?php if ( $proposals > 0 ) : ?>
                <div class="aied-card aied-rec">
                    <h4><?php echo esc_html( sprintf( /* translators: %d count */ _n( '%d code proposal to review', '%d code proposals to review', $proposals, 'ai-editor-divi5' ), $proposals ) ); ?></h4>
                    <p class="aied-muted"><?php esc_html_e( 'Your AI drafted PHP for you to review and apply safely.', 'ai-editor-divi5' ); ?></p>
                    <a class="button" href="<?php echo esc_url( add_query_arg(['page' => self::SLUG, 'tab' => 'settings'], admin_url('admin.php')) ); ?>#proposals"><?php esc_html_e( 'Review', 'ai-editor-divi5' ); ?></a>
                </div>
            <?php endif; ?>

            <?php if ( ! $premium ) : ?>
                <div class="aied-card aied-rec aied-locked">
                    <h4>&#128274; <?php esc_html_e( 'Create whole pages & sites', 'ai-editor-divi5' ); ?></h4>
                    <p class="aied-muted"><?php esc_html_e( 'Premium lets the AI build brand-new pages and entire multi-page sites — not just edit existing ones.', 'ai-editor-divi5' ); ?></p>
                    <a class="button" href="<?php echo esc_url( add_query_arg(['page' => self::SLUG, 'tab' => 'upgrade'], admin_url('admin.php')) ); ?>"><?php esc_html_e( 'See what’s included', 'ai-editor-divi5' ); ?></a>
                </div>
                <div class="aied-card aied-rec aied-locked">
                    <h4>&#128274; <?php esc_html_e( 'Custom CSS & effects', 'ai-editor-divi5' ); ?></h4>
                    <p class="aied-muted"><?php esc_html_e( 'Add site-wide CSS for glassmorphism and animations the page builder can’t do.', 'ai-editor-divi5' ); ?></p>
                    <a class="button" href="<?php echo esc_url( add_query_arg(['page' => self::SLUG, 'tab' => 'upgrade'], admin_url('admin.php')) ); ?>"><?php esc_html_e( 'Learn more', 'ai-editor-divi5' ); ?></a>
                </div>
            <?php else : ?>
                <div class="aied-card aied-rec">
                    <h4><?php esc_html_e( 'Build a full site from one prompt', 'ai-editor-divi5' ); ?></h4>
                    <p class="aied-muted"><?php esc_html_e( 'Ask your AI to build a homepage, about, services and contact pages in one go.', 'ai-editor-divi5' ); ?></p>
                    <a class="button" href="<?php echo esc_url( add_query_arg(['page' => self::SLUG, 'tab' => 'features'], admin_url('admin.php')) ); ?>"><?php esc_html_e( 'See features', 'ai-editor-divi5' ); ?></a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ---------------------------------------------------------------
    // View: Features
    // ---------------------------------------------------------------

    private function viewFeatures(): void
    {
        $premium = $this->isPremium();
        $free = [
            [ __( 'Edit pages in plain English', 'ai-editor-divi5' ), __( 'Tell your AI what to change and it updates the live Divi 5 layout — no builder, no copy-paste.', 'ai-editor-divi5' ) ],
            [ __( 'Validated, safe saves', 'ai-editor-divi5' ), __( 'Every change is checked against 56+ Divi 5 module types before saving, so broken layouts never reach your site.', 'ai-editor-divi5' ) ],
            [ __( 'Read & understand any page', 'ai-editor-divi5' ), __( 'Your AI can list and read existing pages to make precise, context-aware edits.', 'ai-editor-divi5' ) ],
            [ __( 'Conversion-focused page generation', 'ai-editor-divi5' ), __( 'A built-in landing-page blueprint, design vocabulary and proven section patterns guide the AI to produce polished, on-brand pages with a strategic structure built to convert.', 'ai-editor-divi5' ) ],
        ];
        $pro = [
            [ __( 'Create new pages', 'ai-editor-divi5' ), __( 'Generate brand-new pages from a prompt — drafted, validated, ready to review.', 'ai-editor-divi5' ) ],
            [ __( 'Build entire websites', 'ai-editor-divi5' ), __( 'Produce a cohesive multi-page site (home, about, services, contact) with shared styling and navigation in one go.', 'ai-editor-divi5' ) ],
            [ __( 'Set homepage & menus', 'ai-editor-divi5' ), __( 'Wire up the front page and primary navigation automatically.', 'ai-editor-divi5' ) ],
            [ __( 'Site-wide custom CSS', 'ai-editor-divi5' ), __( 'Add real glassmorphism, animations and fine-tuning the builder can’t express — safely, never executing code.', 'ai-editor-divi5' ) ],
            [ __( 'Reviewed PHP snippets', 'ai-editor-divi5' ), __( 'The AI drafts custom post types, hooks and integrations for you to review and apply — nothing runs automatically.', 'ai-editor-divi5' ) ],
        ];
        ?>
        <div class="aied-hello"><h1><?php esc_html_e( 'Features', 'ai-editor-divi5' ); ?></h1>
            <p><?php esc_html_e( 'Everything your AI assistant can do with your Divi 5 site.', 'ai-editor-divi5' ); ?></p></div>

        <h3 class="aied-section-title"><?php esc_html_e( 'Included free', 'ai-editor-divi5' ); ?></h3>
        <div class="aied-grid aied-grid--2">
            <?php foreach ( $free as [$t, $d] ) : ?>
                <div class="aied-card aied-feature">
                    <span class="aied-feature__badge aied-feature__badge--free">&#10003;</span>
                    <div><h4><?php echo esc_html( $t ); ?></h4><p class="aied-muted"><?php echo esc_html( $d ); ?></p></div>
                </div>
            <?php endforeach; ?>
        </div>

        <h3 class="aied-section-title">
            <?php esc_html_e( 'Premium', 'ai-editor-divi5' ); ?>
            <span class="aied-result <?php echo $premium ? 'aied-result--valid' : 'aied-result--invalid'; ?>"><?php echo $premium ? esc_html__( 'ACTIVE', 'ai-editor-divi5' ) : esc_html__( 'LOCKED', 'ai-editor-divi5' ); ?></span>
        </h3>
        <div class="aied-grid aied-grid--2">
            <?php foreach ( $pro as [$t, $d] ) : ?>
                <div class="aied-card aied-feature <?php echo $premium ? '' : 'aied-locked'; ?>">
                    <span class="aied-feature__badge"><?php echo $premium ? '&#10003;' : '&#128274;'; ?></span>
                    <div>
                        <h4><?php echo esc_html( $t ); ?></h4>
                        <p class="aied-muted"><?php echo esc_html( $d ); ?></p>
                        <?php if ( ! $premium ) : ?>
                            <a class="aied-link" href="<?php echo esc_url( add_query_arg(['page' => self::SLUG, 'tab' => 'upgrade'], admin_url('admin.php')) ); ?>"><?php esc_html_e( 'Unlock feature →', 'ai-editor-divi5' ); ?></a>
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
        $c = $this->connection();
        ?>
        <div class="aied-hello"><h1><?php esc_html_e( 'Settings', 'ai-editor-divi5' ); ?></h1>
            <p><?php esc_html_e( 'Connect your AI assistant and manage your account.', 'ai-editor-divi5' ); ?></p></div>

        <!-- Connection -->
        <div class="aied-card">
            <h3><?php esc_html_e( 'Connection', 'ai-editor-divi5' ); ?></h3>
            <p class="aied-muted"><?php esc_html_e( 'Your API key authorizes your AI assistant to read and edit this site. Keep it private.', 'ai-editor-divi5' ); ?></p>
            <div class="aied-key-row">
                <code class="aied-key" id="aied-api-key" data-key="<?php echo esc_attr( $c['key'] ); ?>">••••••••••••••••••••••••</code>
                <button type="button" class="button" id="aied-toggle-key"><?php esc_html_e( 'Show', 'ai-editor-divi5' ); ?></button>
                <button type="button" class="button button-primary" data-copy="<?php echo esc_attr( $c['key'] ); ?>"><?php esc_html_e( 'Copy', 'ai-editor-divi5' ); ?></button>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                    <input type="hidden" name="action" value="ai_editor_divi5_regenerate_key">
                    <?php wp_nonce_field( 'ai_editor_divi5_regenerate_key' ); ?>
                    <button type="submit" class="button aied-btn-danger" onclick="return confirm('<?php echo esc_js( __( 'Regenerate the key? You will need to update your AI assistant.', 'ai-editor-divi5' ) ); ?>')"><?php esc_html_e( 'Regenerate', 'ai-editor-divi5' ); ?></button>
                </form>
            </div>
            <p class="aied-field-label"><?php esc_html_e( 'Add this to Claude Desktop, Cursor, or VS Code (MCP):', 'ai-editor-divi5' ); ?></p>
            <div class="aied-snippet-wrap">
                <pre class="aied-snippet" id="snippet-mcp"><?php echo esc_html( $c['snippet'] ); ?></pre>
                <button class="button button-primary aied-copy-btn" data-target="snippet-mcp"><?php esc_html_e( 'Copy', 'ai-editor-divi5' ); ?></button>
            </div>
            <p class="aied-muted aied-note"><?php
                printf(
                    /* translators: %s: OpenAPI spec URL */
                    esc_html__( 'ChatGPT / REST clients: use the OpenAPI spec at %s with Bearer auth.', 'ai-editor-divi5' ),
                    '<a href="' . esc_url( $c['specUrl'] ) . '" target="_blank" rel="noopener noreferrer">openapi.json</a>'
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc applied inline above.
            ?></p>
        </div>

        <!-- Account / License -->
        <div class="aied-card">
            <?php $this->licensePanel(); ?>
        </div>

        <!-- Code proposals -->
        <div class="aied-card" id="proposals">
            <h3><?php esc_html_e( 'Code Proposals', 'ai-editor-divi5' ); ?></h3>
            <p class="aied-muted"><?php esc_html_e( 'PHP your AI drafted for you. Nothing is executed or saved to your site — review each one and apply it yourself.', 'ai-editor-divi5' ); ?></p>
            <?php $proposals = PhpProposals::all(); ?>
            <?php if ( empty( $proposals ) ) : ?>
                <div class="aied-empty">
                    <p><strong><?php esc_html_e( 'No proposals yet', 'ai-editor-divi5' ); ?></strong></p>
                    <p class="aied-muted"><?php esc_html_e( 'Ask your AI to build a PHP feature (e.g. a custom post type) and it appears here for review.', 'ai-editor-divi5' ); ?></p>
                </div>
            <?php else : foreach ( $proposals as $p ) : $pid = esc_attr( $p['id'] ); ?>
                <div class="aied-proposal">
                    <h4><?php echo esc_html( $p['title'] ); ?></h4>
                    <p class="aied-muted"><?php echo esc_html( $p['description'] ); ?></p>
                    <div class="aied-snippet-wrap">
                        <pre class="aied-snippet" id="proposal-<?php echo $pid; ?>"><?php echo esc_html( $p['code'] ); ?></pre>
                        <button class="button button-primary aied-copy-btn" data-target="proposal-<?php echo $pid; ?>"><?php esc_html_e( 'Copy', 'ai-editor-divi5' ); ?></button>
                    </div>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="ai_editor_divi5_delete_proposal">
                        <input type="hidden" name="proposal_id" value="<?php echo $pid; ?>">
                        <?php wp_nonce_field( 'ai_editor_divi5_delete_proposal' ); ?>
                        <button type="submit" class="button aied-btn-danger" onclick="return confirm('<?php echo esc_js( __( 'Delete this proposal?', 'ai-editor-divi5' ) ); ?>')"><?php esc_html_e( 'Delete', 'ai-editor-divi5' ); ?></button>
                    </form>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Activity log -->
        <?php $recent = UsageTracker::getRecent(10); if ( ! empty( $recent ) ) : ?>
        <div class="aied-card">
            <div class="aied-card__head">
                <h3><?php esc_html_e( 'Recent activity', 'ai-editor-divi5' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="ai_editor_divi5_clear_usage">
                    <?php wp_nonce_field( 'ai_editor_divi5_clear_usage' ); ?>
                    <button type="submit" class="button"><?php esc_html_e( 'Clear log', 'ai-editor-divi5' ); ?></button>
                </form>
            </div>
            <table class="widefat striped aied-usage-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Time', 'ai-editor-divi5' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'ai-editor-divi5' ); ?></th>
                    <th><?php esc_html_e( 'Result', 'ai-editor-divi5' ); ?></th>
                    <th><?php esc_html_e( 'Assistant', 'ai-editor-divi5' ); ?></th>
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
            <?php esc_html_e( 'License', 'ai-editor-divi5' ); ?>
            <span class="aied-result <?php echo $license['valid'] ? 'aied-result--valid' : 'aied-result--invalid'; ?>"><?php echo $license['valid'] ? esc_html__( 'PREMIUM', 'ai-editor-divi5' ) : esc_html__( 'FREE', 'ai-editor-divi5' ); ?></span>
        </h3>
        <?php if ( $license['valid'] ) : ?>
            <p class="aied-muted">
                <?php echo esc_html( sprintf( /* translators: %s email */ __( 'Premium is active for %s.', 'ai-editor-divi5' ), $license['email'] ?: __( 'this site', 'ai-editor-divi5' ) ) ); ?>
                <?php if ( $license['expires'] ) { echo ' ' . esc_html( sprintf( /* translators: %s date */ __( 'Expires %s.', 'ai-editor-divi5' ), date_i18n( get_option( 'date_format' ), (int) $license['expires'] ) ) ); } ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ai_editor_divi5_deactivate_license">
                <?php wp_nonce_field( 'ai_editor_divi5_deactivate_license' ); ?>
                <button type="submit" class="button aied-btn-danger"><?php esc_html_e( 'Deactivate license', 'ai-editor-divi5' ); ?></button>
            </form>
        <?php else : ?>
            <p class="aied-muted"><?php esc_html_e( 'Enter a license key to unlock premium features.', 'ai-editor-divi5' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aied-key-row">
                <input type="hidden" name="action" value="ai_editor_divi5_activate_license">
                <?php wp_nonce_field( 'ai_editor_divi5_activate_license' ); ?>
                <input type="text" name="license_key" class="regular-text" style="flex:1" placeholder="<?php esc_attr_e( 'Paste your license key', 'ai-editor-divi5' ); ?>" autocomplete="off" spellcheck="false">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Activate', 'ai-editor-divi5' ); ?></button>
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
            __( 'Generate brand-new pages from a prompt instead of building them by hand.', 'ai-editor-divi5' ),
            __( 'Spin up a whole multi-page website — consistent design and navigation — in minutes, not days.', 'ai-editor-divi5' ),
            __( 'Add polished effects (glassmorphism, animations) with safe, site-wide custom CSS.', 'ai-editor-divi5' ),
            __( 'Get reviewed PHP for custom features without writing it yourself.', 'ai-editor-divi5' ),
        ];
        ?>
        <div class="aied-hello">
            <h1><?php echo $premium ? esc_html__( 'Your account', 'ai-editor-divi5' ) : esc_html__( 'Unlock more with Premium', 'ai-editor-divi5' ); ?></h1>
            <p><?php echo $premium
                ? esc_html__( 'Premium is active. Thanks for your support!', 'ai-editor-divi5' )
                : esc_html__( 'You already get safe, AI-powered page editing for free. Premium adds creation and automation.', 'ai-editor-divi5' ); ?></p>
        </div>

        <div class="aied-grid aied-grid--2">
            <div class="aied-card">
                <h3><?php esc_html_e( 'You already have', 'ai-editor-divi5' ); ?></h3>
                <ul class="aied-ticklist">
                    <li>&#10003; <?php esc_html_e( 'AI editing of existing Divi 5 pages', 'ai-editor-divi5' ); ?></li>
                    <li>&#10003; <?php esc_html_e( 'Validated, never-break-the-site saves', 'ai-editor-divi5' ); ?></li>
                    <li>&#10003; <?php esc_html_e( 'Conversion-focused page generation (landing blueprint, style guide & section recipes)', 'ai-editor-divi5' ); ?></li>
                    <li>&#10003; <?php esc_html_e( 'Works with Claude, Cursor, VS Code & ChatGPT', 'ai-editor-divi5' ); ?></li>
                </ul>
            </div>
            <div class="aied-card aied-card--accent">
                <h3>&#128640; <?php esc_html_e( 'Premium unlocks', 'ai-editor-divi5' ); ?></h3>
                <ul class="aied-ticklist">
                    <?php foreach ( $benefits as $b ) : ?>
                        <li>&#128640; <?php echo esc_html( $b ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ( ! $premium ) : ?>
                    <a class="button button-primary button-hero" href="<?php echo esc_url( Licensing::UPGRADE_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get Premium', 'ai-editor-divi5' ); ?></a>
                <?php else : ?>
                    <p class="aied-eyebrow aied-eyebrow--ok">&#10003; <?php esc_html_e( 'Active on this site', 'ai-editor-divi5' ); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="aied-card">
            <?php $this->licensePanel(); ?>
        </div>
        <?php
    }
}
