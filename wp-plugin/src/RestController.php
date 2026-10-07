<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/PageAccess.php';

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

        // GET /section-recipes — library of proven section patterns (?name=<recipe> for one)
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
        if ($name !== '') {
            $markup = SectionRecipes::recipe($name);
            if ($markup === null) {
                return new WP_REST_Response(['error' => "Unknown recipe '{$name}'", 'catalog' => SectionRecipes::catalog()], 404);
            }
            return new WP_REST_Response(['name' => $name, 'markup' => $markup], 200);
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

        $posts = get_posts([
            'post_type'      => 'page',
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
        // Only pages this user can edit (other users' drafts/private pages stay hidden).
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
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }

        if ($target['error'] === EditTarget::FORBIDDEN) {
            return new WP_Error('forbidden', "You do not have permission to read page $id.", ['status' => 403]);
        }
        $post = $target['post'];

        UsageTracker::log('get_page', $id, 'read');

        $layout = $this->build_layout_envelope($post);

        return new WP_REST_Response($layout, 200);
    }

    public function update_page(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id     = (int) $request->get_param('id');
        $target = EditTarget::resolve($id);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }

        if ($target['error'] === EditTarget::FORBIDDEN) {
            return new WP_Error('forbidden', "You do not have permission to edit page $id.", ['status' => 403]);
        }
        $post = $target['post'];

        $body    = (array) $request->get_json_params();
        $content = PageAccess::stringArg($body, 'post_content');
        if ($content === null || trim($content) === '') {
            return new WP_Error('missing_field', 'Request body must include a non-empty string "post_content".', ['status' => 400]);
        }

        $dryRun = PageAccess::boolArg($body, 'dry_run');
        if ($dryRun === null) {
            return new WP_Error('invalid_field', '"dry_run" must be a boolean.', ['status' => 400]);
        }

        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $id, UsageTracker::RESULT_READ);

            $preview = DryRun::preview((string) $post->post_content, $content);
            ApprovalMode::recordPreview($id, $content, (bool) $preview['valid'], (string) $post->post_content);

            return new WP_REST_Response(StyleReferences::attach($preview, $content), 200);
        }

        // Validate before saving — this is the safety gate
        $result = LayoutGate::check($content, (string) $post->post_content);

        if (!$result->isValid()) {
            UsageTracker::log('update_page', $id, 'invalid', count($result->violations()));
            return new WP_REST_Response([
                'saved'      => false,
                'valid'      => false,
                'violations' => array_map(fn($v) => $v->toArray(), $result->violations()),
                'message'    => 'Layout failed validation. Page was NOT updated.',
            ], 422);
        }

        if (!ApprovalMode::consume($id, $content, (string) $post->post_content)) {
            UsageTracker::log('update_page', $id, 'error');
            return new WP_REST_Response(ApprovalMode::refusal(), 428);
        }

        // Validation passed — snapshot the previous content, then save (wp_slash inside HistoryService).
        $write = HistoryService::write($id, $content, 'update_page');

        if (!$write['ok']) {
            return new WP_Error('update_failed', (string) $write['message'], ['status' => 500]);
        }

        UsageTracker::log('update_page', $id, 'valid');

        $post->post_content = $content;

        return new WP_REST_Response(StyleReferences::attach([
            'saved'      => true,
            'valid'      => true,
            'violations' => [],
            'history'    => $write['snapshot'],
            'page'       => $this->build_layout_envelope($post),
        ], $content), 200);
    }

    /** @return \WP_Post|WP_Error */
    private function history_page(WP_REST_Request $request): \WP_Post|WP_Error
    {
        $id     = (int) $request->get_param('id');
        $target = EditTarget::resolve($id);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }
        if ($target['error'] === EditTarget::FORBIDDEN) {
            return new WP_Error('forbidden', "You do not have permission to access page $id.", ['status' => 403]);
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
            return new WP_Error('not_found', "Version $versionId not found for page {$post->ID}.", ['status' => 404]);
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
            'validator'        => $result['validator'],
        ], 200);
    }

    public function edit_page(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id     = (int) $request->get_param('id');
        $target = EditTarget::resolve($id);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }
        if ($target['error'] === EditTarget::FORBIDDEN) {
            return new WP_Error('forbidden', "You do not have permission to edit page $id.", ['status' => 403]);
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

            return new WP_REST_Response(StyleReferences::attach($preview + ['replaced' => $edit['count']], $edit['content']), 200);
        }

        // Same safety gate as update_page — the edited content must still validate.
        $result = LayoutGate::check($edit['content'], (string) $post->post_content);
        if (!$result->isValid()) {
            UsageTracker::log('edit_page', $id, 'invalid', count($result->violations()));
            return new WP_REST_Response([
                'saved'      => false,
                'valid'      => false,
                'violations' => array_map(fn($v) => $v->toArray(), $result->violations()),
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

        $post->post_content = $edit['content'];

        return new WP_REST_Response(StyleReferences::attach([
            'saved'      => true,
            'valid'      => true,
            'replaced'   => $edit['count'],
            'violations' => [],
            'history'    => $write['snapshot'],
            'page'       => $this->build_layout_envelope($post),
        ], $edit['content']), 200);
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
                'violations' => array_map(fn($v) => $v->toArray(), $result->violations()),
                'message'    => 'Layout failed validation. Page was NOT created.',
            ], 422);
        }

        // Always a draft — the site owner reviews and publishes. The Divi 5
        // builder meta flags make the page open in the Divi 5 editor and appear
        // in list_divi_pages (which filters on _et_pb_use_divi_5).
        // wp_slash because wp_insert_post runs wp_unslash internally and would
        // otherwise strip backslashes from escaped HTML and corrupt content.
        $postArr = [
            'post_type'    => 'page',
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'draft',
            'meta_input'   => [
                '_et_pb_use_divi_5'  => 'on',
                '_et_pb_use_builder' => 'on',
            ],
        ];
        if ($slug !== '') {
            $postArr['post_name'] = $slug;
        }
        $pageId = wp_insert_post(wp_slash($postArr), true);

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

        $payload = $result->toArray();
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
        if ($target['error'] === FieldTarget::NOT_FOUND) {
            UsageTracker::log('custom_fields', $postId ?: null, 'error');
            return new WP_Error('not_found', "Post $postId not found.", ['status' => 404]);
        }
        if ($target['error'] === FieldTarget::FORBIDDEN) {
            UsageTracker::log('custom_fields', $postId, 'error');
            return new WP_Error('forbidden', "You do not have permission to read post $postId.", ['status' => 403]);
        }
        $result = CustomFieldReader::read($target['post'], $only);
        UsageTracker::log('custom_fields', $postId, 'read');

        return new WP_REST_Response($result, 200);
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
                return new WP_Error('forbidden', 'The API key belongs to a user who cannot edit pages.', ['status' => 403]);
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
