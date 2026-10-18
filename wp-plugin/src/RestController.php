<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/ViolationReport.php';
require_once __DIR__ . '/PageOutline.php';

require_once __DIR__ . '/PageAccess.php';
require_once __DIR__ . '/TitleChange.php';

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * REST API endpoints (the same tools as the MCP server, for ChatGPT Actions and scripts).
 *
 * Namespace: /wp-json/ai-editor-divi5/v1/
 *
 * Every endpoint requires authentication: the plugin API key (Bearer; its user must be able
 * to edit pages) or a WordPress Application Password (the user needs at least edit_posts).
 * Page endpoints then check edit_pages / edit_post for the specific page.
 */
final class RestController
{
    private const NS = 'ai-editor-divi5/v1';

    public function register_routes(): void
    {
        // GET /pages — list pages using the Divi 5 builder
        register_rest_route(self::NS, '/pages', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'list_pages'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // GET /global-styles — read-only list of the site's Divi presets, global colors and variables
        register_rest_route(self::NS, '/global-styles', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'global_styles'],
            'permission_callback' => [$this, 'require_edit_posts'],
            'args'                => [
                'module' => ['type' => 'string', 'validate_callback' => fn($v) => is_string($v)],
            ],
        ]);

        // POST /html-to-divi — deterministic HTML to Divi 5 conversion (preview, or save as a draft with create=true)
        register_rest_route(self::NS, '/html-to-divi', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'html_to_divi'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // GET /site-structure — read-only post types, taxonomies, image sizes and ACF field groups
        register_rest_route(self::NS, '/site-structure', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'site_structure'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // GET /posts/{id}/custom-fields — read-only custom field values of one post you can edit (any public post type)
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/custom-fields', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'custom_fields'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // GET /products — read-only list and counts of WooCommerce products
        register_rest_route(self::NS, '/products', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'list_products'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // GET /products/{id} — read-only detail of one WooCommerce product
        register_rest_route(self::NS, '/products/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_product'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // POST /pages — validate + create a new page (always a draft)
        register_rest_route(self::NS, '/pages', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'create_page'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // GET /pages/{id} — get a page's current Divi layout
        register_rest_route(self::NS, '/pages/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_page'],
            'permission_callback' => [$this, 'require_edit_posts'],
            'args'                => ['id' => ['validate_callback' => fn($v) => is_numeric($v)]],
        ]);

        // PUT /pages/{id} — validate + save a new layout
        register_rest_route(self::NS, '/pages/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [$this, 'update_page'],
            'permission_callback' => [$this, 'require_edit_posts'],
            'args'                => ['id' => ['validate_callback' => fn($v) => is_numeric($v)]],
        ]);

        // POST /pages/{id}/edit — surgical exact find/replace (no full-layout resend)
        register_rest_route(self::NS, '/pages/(?P<id>\d+)/edit', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'edit_page'],
            'permission_callback' => [$this, 'require_edit_posts'],
            'args'                => ['id' => ['validate_callback' => fn($v) => is_numeric($v)]],
        ]);

        // GET /pages/{id}/history — saved previous versions (no content)
        register_rest_route(self::NS, '/pages/(?P<id>\d+)/history', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'history_list'],
            'permission_callback' => [$this, 'require_edit_posts'],
            'args'                => ['id' => ['validate_callback' => fn($v) => is_numeric($v)]],
        ]);

        // GET /pages/{id}/history/{version_id} — one version's full content
        register_rest_route(self::NS, '/pages/(?P<id>\d+)/history/(?P<version_id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'history_entry'],
            'permission_callback' => [$this, 'require_edit_posts'],
            'args'                => [
                'id'         => ['validate_callback' => fn($v) => is_numeric($v)],
                'version_id' => ['validate_callback' => fn($v) => is_numeric($v)],
            ],
        ]);

        // POST /pages/{id}/restore — undo: restore a saved version
        register_rest_route(self::NS, '/pages/(?P<id>\d+)/restore', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'history_restore'],
            'permission_callback' => [$this, 'require_edit_posts'],
            'args'                => ['id' => ['validate_callback' => fn($v) => is_numeric($v)]],
        ]);

        // POST /validate — validate a layout without saving
        register_rest_route(self::NS, '/validate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'validate'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // GET /style-guide — Divi 5 authoring + styling vocabulary
        register_rest_route(self::NS, '/style-guide', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'style_guide'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // GET /section-recipes — library of proven section patterns (?name=<recipe> for one; ?role=, ?layout=, ?mood= filter the list; ?design_language= with ?name= serves a recipe in that language's colours and fonts)
        register_rest_route(self::NS, '/section-recipes', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'section_recipes'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // GET /site-guide — multi-page site blueprint
        register_rest_route(self::NS, '/site-guide', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => fn() => new WP_REST_Response(['guide' => SiteGuide::markdown()], 200),
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // GET /landing-guide — conversion-focused single landing page blueprint
        register_rest_route(self::NS, '/landing-guide', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => fn() => new WP_REST_Response(['guide' => LandingGuide::markdown()], 200),
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // GET /image-guide — role-based image assignment (Media Library first, then the built-in image pack)
        register_rest_route(self::NS, '/image-guide', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => fn() => new WP_REST_Response(['guide' => ImageGuide::markdown()], 200),
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);

        // GET /media — read-only list of Media Library images
        register_rest_route(self::NS, '/media', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'list_media'],
            'permission_callback' => [$this, 'require_edit_posts'],
            'args'                => [
                'search'      => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => fn($v) => is_string($v)],
                'orientation' => ['type' => 'string', 'enum' => ['landscape', 'portrait', 'square'], 'validate_callback' => fn($v) => is_string($v) && in_array($v, ['landscape', 'portrait', 'square'], true)],
                'per_page'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'validate_callback' => fn($v) => is_numeric($v) && (int) $v >= 1 && (int) $v <= 50],
                'page'        => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'validate_callback' => fn($v) => is_numeric($v) && (int) $v >= 1 && (int) $v <= 200],
            ],
        ]);

        // Extension point: add-ons register their own routes under the same namespace.
        do_action( 'jhmg_aied_register_rest_routes', self::NS );
    }

    public function style_guide(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(['guide' => StyleGuide::markdown()], 200);
    }

    public function section_recipes(WP_REST_Request $request): WP_REST_Response
    {
        $name = PageAccess::stringArg(['name' => $request->get_param('name')], 'name');
        if ($name === null) {
            return new WP_REST_Response(['error' => 'Invalid parameter: "name" must be a string'], 400);
        }
        $filters = [];
        foreach (['role', 'layout', 'mood'] as $key) {
            $value = PageAccess::stringArg([$key => $request->get_param($key)], $key);
            if ($value === null) {
                return new WP_REST_Response(['error' => "Invalid parameter: \"{$key}\" must be a string"], 400);
            }
            $filters[$key] = $value === '' ? null : $value;
        }
        $language = PageAccess::stringArg(['design_language' => $request->get_param('design_language')], 'design_language');
        if ($language === null) {
            return new WP_REST_Response(['error' => 'Invalid parameter: "design_language" must be a string'], 400);
        }
        if ($name !== '') {
            if ($language !== '' && DesignLanguages::get($language) === null) {
                return new WP_REST_Response(['error' => "Unknown design language '{$language}'", 'languages' => DesignLanguages::ids()], 404);
            }
            $markup = SectionRecipes::recipe($name, $language === '' ? null : $language);
            if ($markup === null) {
                return new WP_REST_Response(['error' => "Unknown recipe '{$name}'", 'catalog' => SectionRecipes::catalog()], 404);
            }
            return new WP_REST_Response(['name' => $name, 'markup' => $markup], 200);
        }
        if ($filters['role'] !== null || $filters['layout'] !== null || $filters['mood'] !== null) {
            return new WP_REST_Response([
                'catalog' => SectionRecipes::catalog($filters['role'], $filters['layout'], $filters['mood']),
                'names'   => SectionRecipes::matchingNames($filters['role'], $filters['layout'], $filters['mood']),
            ], 200);
        }
        return new WP_REST_Response(['catalog' => SectionRecipes::catalog(), 'names' => SectionRecipes::names()], 200);
    }

    // ---------------------------------------------------------------
    // Endpoint handlers
    // ---------------------------------------------------------------

    public function list_pages(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can(PageAccess::CAP)) {
            return new WP_Error('forbidden', 'You do not have permission to list pages.', ['status' => 403]);
        }

        $postType = PageAccess::postTypeArg(['post_type' => $request->get_param('post_type')], 'post_type', EditTarget::ALLOWED_TYPES);
        if ($postType === null) {
            return new WP_Error('invalid_field', '"post_type" must be one of: ' . implode(', ', EditTarget::ALLOWED_TYPES) . '.', ['status' => 400]);
        }

        $posts = get_posts([
            'post_type'      => $postType,
            'post_status'    => 'any',
            'posts_per_page' => 100,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'meta_query'     => [
                [
                    'key'   => '_et_pb_use_divi_5',
                    'value' => 'on',
                ],
            ],
        ]);
        // Only pages or Projects this user can edit (other users' drafts/private ones stay hidden).
        $posts = PageAccess::filterEditable($posts, static fn(int $pid): bool => current_user_can('edit_post', $pid));

        // Coerce link/edit_link to strings: get_permalink() can return false and
        // get_edit_post_link() returns null when the API-key owner lacks edit
        // caps. The OpenAPI schema (and ChatGPT's response validator) require a
        // string here — a null/false emits invalid JSON and ChatGPT then shows a
        // generic "something went wrong" for an otherwise-successful call.
        $pages = array_map(fn(\WP_Post $p) => [
            'id'         => $p->ID,
            'title'      => get_the_title($p),
            'status'     => $p->post_status,
            'post_type'  => $p->post_type,
            'link'       => (string) get_permalink($p),
            'edit_link'  => (string) get_edit_post_link($p->ID, 'raw'),
            'divi_meta'  => [
                '_et_pb_use_divi_5'  => get_post_meta($p->ID, '_et_pb_use_divi_5', true),
                '_et_pb_use_builder' => get_post_meta($p->ID, '_et_pb_use_builder', true),
            ],
        ], $posts);

        return new WP_REST_Response(['pages' => $pages, 'count' => count($pages)], 200);
    }

    public function get_page(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id     = (int) $request->get_param('id');
        $target = EditTarget::resolve($id);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return new WP_Error('not_found', "Page or Project $id not found.", ['status' => 404]);
        }

        if ($target['error'] === EditTarget::FORBIDDEN) {
            return new WP_Error('forbidden', "You do not have permission to read page or Project $id.", ['status' => 403]);
        }
        $post = $target['post'];

        $view = PageOutline::parseView($request->get_param('view'), $request->get_param('path'));
        if ($view['error'] !== null) {
            return new WP_Error('invalid_field', (string) $view['error'], ['status' => 400]);
        }

        UsageTracker::log('get_page', $id, 'read');

        if ($view['view'] !== 'full') {
            $r = PageOutline::respond([
                'post_id'     => $post->ID,
                'post_type'   => $post->post_type,
                'post_title'  => get_the_title($post),
                'post_status' => $post->post_status,
            ], (string) $post->post_content, $view);

            return $r['status'] === 200
                ? new WP_REST_Response($r['body'], 200)
                : new WP_Error($r['status'] === 404 ? 'not_found' : 'layout_unreadable', (string) $r['body']['error'], ['status' => $r['status']]);
        }

        $layout = $this->build_layout_envelope($post);

        return new WP_REST_Response($layout, 200);
    }

    public function update_page(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id     = (int) $request->get_param('id');
        $target = EditTarget::resolve($id);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return new WP_Error('not_found', "Page or Project $id not found.", ['status' => 404]);
        }

        if ($target['error'] === EditTarget::FORBIDDEN) {
            return new WP_Error('forbidden', "You do not have permission to edit page or Project $id.", ['status' => 403]);
        }
        $post = $target['post'];

        $body    = (array) $request->get_json_params();
        $hasContent = array_key_exists('post_content', $body) && $body['post_content'] !== null;
        $content = '';
        if ($hasContent) {
            $content = PageAccess::stringArg($body, 'post_content');
            if ($content === null || trim($content) === '') {
                return new WP_Error('missing_field', 'Request body must include a non-empty string "post_content".', ['status' => 400]);
            }
        }

        $dryRun = PageAccess::boolArg($body, 'dry_run');
        if ($dryRun === null) {
            return new WP_Error('invalid_field', '"dry_run" must be a boolean.', ['status' => 400]);
        }

        $titleArg = PageTitle::parse($body);
        if (!$titleArg['ok']) {
            return new WP_Error('invalid_field', (string) $titleArg['error'], ['status' => 400]);
        }

        if (!$hasContent && !$titleArg['present']) {
            return new WP_Error('missing_field', 'Give post_content, title, or both.', ['status' => 400]);
        }

        // The title is only written when it differs from the stored one (a repeat is reported, never re-saved).
        $plan = TitleChange::plan((string) $post->post_title, (string) $post->post_content, $hasContent ? $content : null, $titleArg);

        if (!$hasContent) {
            return $this->update_page_title_only($id, $post, $plan, $dryRun);
        }

        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $id, UsageTracker::RESULT_READ);

            $preview = DryRun::preview((string) $post->post_content, $content, $plan['report']);
            ApprovalMode::recordPreview($id, $plan['subject'], (bool) $preview['valid'], $plan['base']);

            return new WP_REST_Response(StyleReferences::attach($preview, $content, (string) $post->post_content), 200);
        }

        // Validate before saving — this is the safety gate
        $result = LayoutGate::check($content, (string) $post->post_content);

        if (!$result->isValid()) {
            UsageTracker::log('update_page', $id, 'invalid', count($result->violations()));
            return new WP_REST_Response([
                'saved'      => false,
                'valid'      => false,
                'violations' => ViolationReport::compact($result->violations()),
                'message'    => 'Layout failed validation. Page was NOT updated.',
            ], 422);
        }

        if (!ApprovalMode::consume($id, $plan['subject'], $plan['base'])) {
            UsageTracker::log('update_page', $id, 'error');
            return new WP_REST_Response(ApprovalMode::refusal(), 428);
        }

        // Validation passed — snapshot the previous content, then save (wp_slash inside HistoryService).
        $write = HistoryService::write($id, $content, 'update_page', $plan['writeTitle']);

        if (!$write['ok']) {
            return new WP_Error('update_failed', (string) $write['message'], ['status' => 500]);
        }

        UsageTracker::log('update_page', $id, 'valid');

        $previousContent    = (string) $post->post_content;
        $post->post_content = $content;
        if ($plan['changed'] && isset($write['title_after'])) {
            $post->post_title = (string) $write['title_after'];
        }

        $out = [
            'saved'      => true,
            'valid'      => true,
            'violations' => [],
            'history'    => $write['snapshot'],
            'page'       => $this->build_layout_envelope($post),
        ];
        if ($plan['report'] !== null) {
            $out['title'] = $plan['changed']
                ? PageTitle::report((string) $plan['report']['before'], (string) ($write['title_after'] ?? $plan['report']['after']), true)
                : $plan['report'];
            $out['message'] = $plan['message'];
        }

        return new WP_REST_Response(StyleReferences::attach($out, $content, $previousContent), 200);
    }

    /**
     * PUT /pages/{id} with a title and no post_content: only the page title is touched. The layout is neither checked nor
     * snapshotted (nothing about it changes), and the call needs the same approval-mode preview as any save.
     *
     * @param array{present:bool, changed:bool, writeTitle:?string, report:?array<string,mixed>, subject:string, base:string, message:?string} $plan TitleChange::plan()
     */
    private function update_page_title_only(int $id, \WP_Post $post, array $plan, bool $dryRun): WP_REST_Response|WP_Error
    {
        $report = (array) $plan['report'];

        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $id, UsageTracker::RESULT_READ);

            $preview = DryRun::previewTitle($report);
            if ($plan['changed']) {
                ApprovalMode::recordPreview($id, $plan['subject'], true, $plan['base']);
            } else {
                $preview['message'] = $plan['message'];
            }

            return new WP_REST_Response($preview, 200);
        }

        if (!$plan['changed']) {
            UsageTracker::log('update_page', $id, UsageTracker::RESULT_READ); // nothing was saved

            return new WP_REST_Response([
                'saved'          => false,
                'valid'          => true,
                'layout_changed' => false,
                'title'          => $report,
                'page'           => $this->build_layout_envelope($post),
                'message'        => $plan['message'],
            ], 200);
        }

        if (!ApprovalMode::consume($id, $plan['subject'], $plan['base'])) {
            UsageTracker::log('update_page', $id, 'error');
            return new WP_REST_Response(ApprovalMode::refusal(), 428);
        }

        $write = HistoryService::writeTitle($id, (string) $plan['writeTitle'], 'update_page');
        if (!$write['ok']) {
            UsageTracker::log('update_page', $id, 'error');
            return new WP_Error('update_failed', (string) $write['message'], ['status' => 500]);
        }

        UsageTracker::log('update_page', $id, 'valid');

        $stored           = (string) ($write['title_after'] ?? $plan['writeTitle']);
        $post->post_title = $stored;
        $layoutChanged    = (bool) ($write['layout_changed'] ?? false);
        if ($layoutChanged) {
            $post = get_post($id) ?: $post; // the content changed too: describe the page as it is stored now
            $post->post_title = $stored;
        }

        return new WP_REST_Response([
            'saved'          => true,
            'valid'          => true,
            'violations'     => [],
            'layout_changed' => $layoutChanged,
            'history'        => $write['snapshot'],
            'title'          => PageTitle::report((string) $report['before'], $stored, true),
            'page'           => $this->build_layout_envelope($post),
            'message'        => $plan['message'] . ($layoutChanged ? ' ' . PageTitle::contentTouchedNote() : ''),
        ], 200);
    }

    /** @return \WP_Post|WP_Error */
    private function history_page(WP_REST_Request $request): \WP_Post|WP_Error
    {
        $id     = (int) $request->get_param('id');
        $target = EditTarget::resolve($id);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return new WP_Error('not_found', "Page or Project $id not found.", ['status' => 404]);
        }
        if ($target['error'] === EditTarget::FORBIDDEN) {
            return new WP_Error('forbidden', "You do not have permission to access page or Project $id.", ['status' => 403]);
        }

        return $target['post'];
    }

    public function history_list(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = $this->history_page($request);
        if (is_wp_error($post)) {
            return $post;
        }
        UsageTracker::log('list_history', $post->ID, 'read');
        $versions = HistoryService::listFor($post->ID);

        return new WP_REST_Response(['page_id' => $post->ID, 'versions' => $versions, 'count' => count($versions)], 200);
    }

    public function history_entry(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = $this->history_page($request);
        if (is_wp_error($post)) {
            return $post;
        }
        $versionId = (int) $request->get_param('version_id');
        $entry     = HistoryService::entry($post->ID, $versionId);
        if ($entry === null) {
            UsageTracker::log('get_history', $post->ID, 'error');
            return new WP_Error('not_found', "Version $versionId not found for page or Project {$post->ID}.", ['status' => 404]);
        }
        UsageTracker::log('get_history', $post->ID, 'read');

        return new WP_REST_Response(['page_id' => $post->ID, 'version' => array_diff_key($entry, ['content' => 1]), 'post_content' => $entry['content']], 200);
    }

    public function history_restore(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = $this->history_page($request);
        if (is_wp_error($post)) {
            return $post;
        }
        $body = $request->get_json_params();
        if (!isset($body['version_id']) || !is_numeric($body['version_id'])) {
            return new WP_Error('missing_field', 'Request body must include an integer "version_id".', ['status' => 400]);
        }
        $result = HistoryService::restore($post->ID, (int) $body['version_id']);
        if (!$result['ok']) {
            UsageTracker::log('restore_version', $post->ID, 'error');
            return new WP_Error($result['error'], (string) $result['message'], ['status' => $result['error'] === 'version_not_found' ? 404 : 500]);
        }
        UsageTracker::log('restore_version', $post->ID, 'valid');

        return new WP_REST_Response([
            'restored'         => true,
            'restored_version' => $result['restored_version'],
            'history'          => $result['snapshot'],
            'title_restored'   => $result['title_restored'],
            'title_note'       => $result['title_note'],
            'validator'        => $result['validator'],
        ], 200);
    }

    public function edit_page(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id     = (int) $request->get_param('id');
        $target = EditTarget::resolve($id);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return new WP_Error('not_found', "Page or Project $id not found.", ['status' => 404]);
        }
        if ($target['error'] === EditTarget::FORBIDDEN) {
            return new WP_Error('forbidden', "You do not have permission to edit page or Project $id.", ['status' => 403]);
        }
        $post = $target['post'];

        $body    = (array) $request->get_json_params();
        $find    = PageAccess::stringArg($body, 'find');
        $replace = PageAccess::stringArg($body, 'replace');
        if (!isset($body['find']) || !isset($body['replace']) || $find === null || $replace === null) {
            return new WP_Error('missing_field', 'Request body must include "find" and "replace" strings.', ['status' => 400]);
        }
        $expect = (isset($body['expect_count']) && $body['expect_count'] !== null && $body['expect_count'] !== '')
            ? (int) $body['expect_count']
            : null;

        $dryRun = PageAccess::boolArg($body, 'dry_run');
        if ($dryRun === null) {
            return new WP_Error('invalid_field', '"dry_run" must be a boolean.', ['status' => 400]);
        }

        $edit = PageEditor::apply($post->post_content, $find, $replace, $expect);

        if (!$edit['ok']) {
            UsageTracker::log('edit_page', $id, 'error');
            return new WP_REST_Response([
                'saved'   => false,
                'matches' => $edit['count'],
                'message' => $edit['error'],
            ], 422);
        }

        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $id, UsageTracker::RESULT_READ);

            $preview = DryRun::preview((string) $post->post_content, $edit['content']);
            ApprovalMode::recordPreview($id, $edit['content'], (bool) $preview['valid'], (string) $post->post_content);

            return new WP_REST_Response(StyleReferences::attach($preview + ['replaced' => $edit['count']], $edit['content'], (string) $post->post_content), 200);
        }

        // Same safety gate as update_page — the edited content must still validate.
        $result = LayoutGate::check($edit['content'], (string) $post->post_content);
        if (!$result->isValid()) {
            UsageTracker::log('edit_page', $id, 'invalid', count($result->violations()));
            return new WP_REST_Response([
                'saved'      => false,
                'valid'      => false,
                'violations' => ViolationReport::compact($result->violations()),
                'message'    => 'Edit would make the layout invalid. Page was NOT updated.',
            ], 422);
        }

        if (!ApprovalMode::consume($id, $edit['content'], (string) $post->post_content)) {
            UsageTracker::log('edit_page', $id, 'error');
            return new WP_REST_Response(ApprovalMode::refusal(), 428);
        }

        $write = HistoryService::write($id, $edit['content'], 'edit_page');
        if (!$write['ok']) {
            return new WP_Error('update_failed', (string) $write['message'], ['status' => 500]);
        }

        UsageTracker::log('edit_page', $id, 'valid');

        $previousContent    = (string) $post->post_content;
        $post->post_content = $edit['content'];

        return new WP_REST_Response(StyleReferences::attach([
            'saved'      => true,
            'valid'      => true,
            'replaced'   => $edit['count'],
            'violations' => [],
            'history'    => $write['snapshot'],
            'page'       => $this->build_layout_envelope($post),
        ], $edit['content'], $previousContent), 200);
    }

    public function create_page(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can('publish_pages')) {
            return new WP_Error('forbidden', 'Your account does not have permission to create pages.', ['status' => 403]);
        }

        $body    = (array) $request->get_json_params();
        $title   = PageAccess::stringArg($body, 'title');
        $content = PageAccess::stringArg($body, 'post_content');
        $slug    = PageAccess::stringArg($body, 'slug');
        if ($title === null || $content === null || $slug === null) {
            return new WP_Error('invalid_field', '"title", "post_content" and "slug" must be strings.', ['status' => 400]);
        }
        // A title is plain text: strip markup before it is stored (many themes print it unescaped).
        $title = trim(sanitize_text_field($title));
        $slug  = sanitize_title($slug);

        if ($title === '') {
            return new WP_Error('missing_field', 'Request body must include a non-empty "title".', ['status' => 400]);
        }
        if (trim($content) === '') {
            return new WP_Error('missing_field', 'Request body must include a non-empty string "post_content".', ['status' => 400]);
        }

        $result = LayoutGate::check($content);

        if (!$result->isValid()) {
            UsageTracker::log('create_page', null, 'invalid', count($result->violations()));
            return new WP_REST_Response([
                'created'    => false,
                'valid'      => false,
                'violations' => ViolationReport::compact($result->violations()),
                'message'    => 'Layout failed validation. Page was NOT created.',
            ], 422);
        }

        $pageId = DraftPage::insert($title, $content, $slug);

        if (is_wp_error($pageId)) {
            return new WP_Error('create_failed', $pageId->get_error_message(), ['status' => 500]);
        }

        UsageTracker::log('create_page', (int) $pageId, 'valid');

        return new WP_REST_Response(StyleReferences::attach([
            'created' => true,
            'valid'   => true,
            'page'    => $this->build_layout_envelope(get_post((int) $pageId)),
        ], $content), 201);
    }

    public function validate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = (array) $request->get_json_params();

        if (!isset($body['post_content']) && !isset($body['layout'])) {
            return new WP_Error('missing_field', 'Request body must include "post_content".', ['status' => 400]);
        }

        // Accept either {post_content:...} or a full layout envelope
        $content = null;
        if (isset($body['post_content'])) {
            $content = PageAccess::stringArg($body, 'post_content');
            if ($content === null) {
                return new WP_Error('invalid_field', '"post_content" must be a string.', ['status' => 400]);
            }
            $result = LayoutGate::check($content);
        } else {
            $result = LayoutGate::checkJson((string) json_encode($body));
        }

        UsageTracker::log('validate', null, $result->isValid() ? 'read' : 'invalid', count($result->violations()));

        $payload = ['valid' => $result->isValid(), 'violations' => ViolationReport::compact($result->violations())];
        if ($content !== null) {
            $payload = StyleReferences::attach($payload, $content);
        }

        return new WP_REST_Response($payload, $result->isValid() ? 200 : 422);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function build_layout_envelope(\WP_Post $post): array
    {
        return [
            'source'       => 'wp-rest-export',
            'format'       => 'gutenberg-blocks',
            'divi_version' => defined('ET_CORE_VERSION') ? ET_CORE_VERSION : 'unknown',
            'post_id'      => $post->ID,
            'post_type'    => $post->post_type,
            'post_title'   => get_the_title($post),
            'post_status'  => $post->post_status,
            'post_content' => $post->post_content,
            'divi_meta'    => [
                '_et_pb_use_divi_5'  => get_post_meta($post->ID, '_et_pb_use_divi_5', true),
                '_et_pb_use_builder' => get_post_meta($post->ID, '_et_pb_use_builder', true),
            ],
            'exported_at'  => gmdate('c'),
        ];
    }

    public function global_styles(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('list_global_styles', null, 'error');
            return new WP_Error('forbidden', 'You do not have permission to read the site styles.', ['status' => 403]);
        }
        $module = PageAccess::stringArg(['module' => $request->get_param('module')], 'module');
        if ($module === null) {
            return new WP_Error('invalid_field', '"module" must be a string.', ['status' => 400]);
        }
        $result = GlobalStyles::catalog($module);
        UsageTracker::log('list_global_styles', null, 'read');

        return new WP_REST_Response($result, 200);
    }

    public function html_to_divi(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new WP_Error('invalid_body', 'The request body must be a JSON object.', ['status' => 400]);
        }
        $out = HtmlToDiviService::run($body);
        if ($out['status'] === 403) {
            return new WP_Error('forbidden', (string) ($out['body']['error'] ?? 'Not allowed.'), ['status' => 403]);
        }

        return new WP_REST_Response($out['body'], $out['status']);
    }

    public function site_structure(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('site_structure', null, 'error');
            return new WP_Error('forbidden', 'You do not have permission to read the site structure.', ['status' => 403]);
        }
        $include = SiteStructure::parseInclude($request->get_param('include'));
        if ($include === null) {
            return new WP_Error('invalid_field', '"include" must be a list of: ' . implode(', ', SiteStructure::SECTIONS) . '.', ['status' => 400]);
        }
        $result = SiteStructure::catalog($include);
        UsageTracker::log('site_structure', null, 'read');

        return new WP_REST_Response($result, 200);
    }

    public function custom_fields(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('custom_fields', null, 'error');
            return new WP_Error('forbidden', 'You do not have permission to read custom fields.', ['status' => 403]);
        }
        $postId = (int) $request->get_param('id');
        $only   = CustomFieldReader::parseKeys($request->get_param('keys'));
        if ($only === null) {
            return new WP_Error('invalid_field', '"keys" must be a list of at most ' . CustomFieldReader::MAX_KEYS_ARG . ' strings.', ['status' => 400]);
        }
        $target = FieldTarget::resolve($postId);
        if ($target['error'] !== null) {
            // A missing post, a non-public type and a post the user cannot edit all answer the same.
            UsageTracker::log('custom_fields', $postId ?: null, 'error');
            return new WP_Error('not_found', "Post $postId not found.", ['status' => 404]);
        }
        try {
            $result = CustomFieldReader::read($target['post'], $only);
        } catch (\Throwable) {
            UsageTracker::log('custom_fields', $postId, 'error');
            return new WP_Error('server_error', 'Could not read the custom fields.', ['status' => 500]);
        }
        UsageTracker::log('custom_fields', $postId, 'read');

        return new WP_REST_Response($result, 200);
    }

    public function list_products(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('list_products', null, 'error');
            return new WP_Error('forbidden', 'You do not have permission to read products.', ['status' => 403]);
        }
        $input = [];
        foreach (['status', 'type', 'category', 'stock_status', 'on_sale', 'search', 'orderby', 'order', 'page', 'per_page'] as $key) {
            $value = $request->get_param($key);
            if ($value !== null) {
                $input[$key] = $value;
            }
        }

        return $this->productResponse('list_products', null, ProductService::list($input, true, new WooProductGateway(), 'current_user_can'));
    }

    public function get_product(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('get_product', null, 'error');
            return new WP_Error('forbidden', 'You do not have permission to read products.', ['status' => 403]);
        }
        $productId = (int) $request->get_param('id');

        return $this->productResponse('get_product', $productId ?: null, ProductService::get($productId, new WooProductGateway(), 'current_user_can'));
    }

    /** @param array<string,mixed> $r a ProductService result */
    private function productResponse(string $endpoint, ?int $postId, array $r): WP_REST_Response|WP_Error
    {
        UsageTracker::log($endpoint, $postId, $r['ok'] ? UsageTracker::RESULT_READ : UsageTracker::RESULT_ERROR);
        if ($r['ok']) {
            return new WP_REST_Response($r['data'], 200);
        }
        $map = ['forbidden' => [403, 'forbidden'], 'invalid' => [400, 'invalid_field'], 'not_found' => [404, 'not_found'], 'server' => [500, 'server_error']];
        [$status, $code] = $map[$r['kind']] ?? [500, 'server_error'];

        return new WP_Error($code, (string) $r['message'], ['status' => $status]);
    }

    public function list_media(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!current_user_can('upload_files')) {
            UsageTracker::log('list_media', null, 'error');
            return new WP_Error('forbidden', 'You do not have permission to read the Media Library.', ['status' => 403]);
        }
        $result = MediaService::list([
            'search'      => $request->get_param('search'),
            'per_page'    => $request->get_param('per_page'),
            'page'        => $request->get_param('page'),
            'orientation' => $request->get_param('orientation'),
        ]);
        UsageTracker::log('list_media', null, 'read');

        return new WP_REST_Response($result, 200);
    }

    public function require_edit_posts(): bool|WP_Error
    {
        // Accept plugin API key (Bearer token) — simpler than Application Passwords
        if (ApiKey::authenticateRequest()) {
            // The key is valid, but its user must still be able to edit pages (a demoted or
            // deleted key owner cannot keep using the plugin).
            if (!PageAccess::keyUserAllowed('current_user_can')) {
                return new WP_Error('forbidden', ApiKey::presentsOAuthToken() ? 'The signed-in user cannot edit pages.' : 'The API key belongs to a user who cannot edit pages.', ['status' => 403]);
            }
            return true;
        }

        // Also accept WordPress Application Passwords (Basic auth, handled by WP core)
        if (!is_user_logged_in()) {
            return new WP_Error('unauthorized', 'Authentication required. Use an Application Password or the plugin API key.', ['status' => 401]);
        }

        if (!current_user_can('edit_posts')) {
            return new WP_Error('forbidden', 'Your account does not have permission to use this endpoint.', ['status' => 403]);
        }

        return true;
    }
}
