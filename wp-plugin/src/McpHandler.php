<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

use Divi5Validator\Validator;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * MCP Streamable HTTP transport endpoint.
 *
 * Implements the Model Context Protocol (2024-11-05) over a single
 * POST endpoint. No Node.js or stdio required — runs entirely in PHP.
 *
 * Endpoint: POST /wp-json/ai-editor-divi5/v1/mcp
 * Auth:     Authorization: Bearer {api_key}
 */
final class McpHandler
{
    private const PROTOCOL_VERSION = '2024-11-05';

    public function register_routes(): void
    {
        register_rest_route('ai-editor-divi5/v1', '/mcp', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
    }

    public function authenticate(): bool|WP_Error
    {
        if (ApiKey::authenticateRequest()) {
            return true;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
        if (str_starts_with(strtolower($header), 'bearer ')) {
            return new WP_Error('forbidden', 'Invalid API key.', ['status' => 403]);
        }
        return new WP_Error('unauthorized', 'Authorization: Bearer {api_key} required.', ['status' => 401]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        if (!is_array($body) || ($body['jsonrpc'] ?? '') !== '2.0') {
            return $this->rpcError(null, -32600, 'Invalid Request — expected JSON-RPC 2.0');
        }

        $method = (string) ($body['method'] ?? '');
        $id     = $body['id']     ?? null;
        $params = (array)  ($body['params'] ?? []);

        return match ($method) {
            'initialize'               => $this->onInitialize($id),
            'notifications/initialized' => $this->noContent(),
            'ping'                     => $this->rpcResult($id, []),
            'tools/list'               => $this->onToolsList($id),
            'tools/call'               => $this->onToolsCall($id, $params),
            default                    => $this->rpcError($id, -32601, "Method not found: {$method}"),
        };
    }

    // ---------------------------------------------------------------
    // Protocol handlers
    // ---------------------------------------------------------------

    private function onInitialize(mixed $id): WP_REST_Response
    {
        return $this->rpcResult($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => ['name' => 'ai-editor-divi5', 'version' => AI_EDITOR_DIVI5_VERSION],
        ]);
    }

    private function onToolsList(mixed $id): WP_REST_Response
    {
        return $this->rpcResult($id, ['tools' => [
            [
                'name'        => 'list_divi_pages',
                'description' => 'List all WordPress pages built with the Divi 5 editor. Returns page IDs, titles, status, and links.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name'        => 'get_page_layout',
                'description' => 'Get the full Divi 5 layout (Gutenberg block HTML) for a specific page by ID.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['page_id' => ['type' => 'integer', 'description' => 'WordPress page ID']],
                    'required'   => ['page_id'],
                ],
            ],
            [
                'name'        => 'get_style_guide',
                'description' => 'Get the Divi 5 authoring guide: real block structure/nesting rules, required content keys, and the exact styling attribute shapes (backgrounds, gradients, spacing, typography, borders, radius, box-shadow, transform, hover, filters, animation). Call this BEFORE building or restyling a layout so the result is styled and valid, not plain.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name'        => 'get_site_guide',
                'description' => 'Get the blueprint for building an ENTIRE multi-page website from one brief: how to plan the page set, lock one shared design system across all pages, build each page with create_page (passing a slug), cross-link them, and hand the owner a short list of what to set up in WordPress (front page, menu). Call this when the user asks for a whole site, not a single page.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name'        => 'get_landing_guide',
                'description' => 'Get the conversion-focused LANDING PAGE blueprint: how to structure a page that sells, not just looks good. Covers the persuasion flow (hero → problem → solution → benefits → social proof → how-it-works → features → FAQ → final CTA), how to adapt the structure to the business type/audience/goal, copywriting rules (benefit-driven headlines, banned generic phrases), and CTA placement strategy. Call this BEFORE building or restyling any single landing/marketing page so the result is strategically structured to convert — pair it with get_style_guide (styling) and get_section_recipes (section markup).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name'        => 'get_image_guide',
                'description' => 'Get the IMAGE-INTELLIGENCE guide: how to assign the right visual to each section by role (not random images everywhere), using keyless, verified image sources — relevant photos (LoremFlickr), generic/abstract (Picsum), real avatars for testimonials/team (Random User, Pravatar), and descriptive labeled placeholders (Placehold.co). Covers per-section image rules, how to derive search keywords from the business type, stable image pinning, aspect-ratio sizing, and the fallback order. Call this BEFORE choosing any image src so generated pages look like finished demos, not empty templates.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name'        => 'get_section_recipes',
                'description' => 'Get a library of complete, validated Divi 5 section recipes (hero, feature grids, split, slider, CTA, footer). With no arguments, returns the catalog of recipe names. Pass {"name":"<recipe>"} to get that section\'s full block markup to copy and fill with the user\'s content. Use these to assemble well-composed pages instead of building sections from scratch.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['name' => ['type' => 'string', 'description' => 'Recipe name from the catalog (omit to list all)']],
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'validate_layout',
                'description' => 'Validate a Divi 5 post_content string against the schema without saving. Use this to check edits before calling update_page_layout.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['post_content' => ['type' => 'string', 'description' => 'Divi 5 Gutenberg block HTML']],
                    'required'   => ['post_content'],
                ],
            ],
            [
                'name'        => 'update_page_layout',
                'description' => 'Validate and save a new Divi 5 layout to a page. The page is only updated if the layout passes all schema checks — invalid layouts are rejected with a list of violations. For a landing/marketing page, call get_landing_guide first for the conversion structure (persuasion flow, copywriting, CTA placement), get_style_guide for the real styling attribute shapes, get_section_recipes to assemble the page from complete proven section patterns, and get_image_guide to choose a relevant, role-appropriate image for each section — so the result is strategically structured, styled, well-composed, and visually finished, not plain. Never leave an image module without a src (see get_image_guide for the right keyless source per role; picsum /seed/ is the generic fallback).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id'      => ['type' => 'integer', 'description' => 'WordPress page ID'],
                        'post_content' => ['type' => 'string',  'description' => 'Divi 5 Gutenberg block HTML'],
                    ],
                    'required' => ['page_id', 'post_content'],
                ],
            ],
            [
                'name'        => 'edit_page_content',
                'description' => 'Make a small, surgical edit to an existing page WITHOUT re-sending the whole layout: an exact find-and-replace on the page\'s stored content. Use this for changing an email, phone number, link, price, or a line of copy — never rebuild the full page for a tiny change. "find" must match exactly once unless you set expect_count to the number of matches. The result is re-validated before saving; if "find" is empty, not found, or ambiguous, nothing is changed.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id'      => ['type' => 'integer', 'description' => 'WordPress page ID'],
                        'find'         => ['type' => 'string',  'description' => 'Exact text to find (verbatim, including any surrounding markup needed to make it unique)'],
                        'replace'      => ['type' => 'string',  'description' => 'Text to replace it with'],
                        'expect_count' => ['type' => 'integer', 'description' => 'Optional: how many matches to replace (must equal the number found). Omit to require a single unique match.'],
                    ],
                    'required' => ['page_id', 'find', 'replace'],
                ],
            ],
            [
                'name'        => 'list_page_history',
                'description' => 'List the saved previous versions of a page (newest first). Every AI save tries to snapshot the page\'s prior content (up to the last 10 per page, fewer for very large pages), so an AI edit can be undone when a snapshot was kept (the save result\'s history.stored says so). Returns id, saved_at, tool, actor, bytes, label for each version (no content). Use restore_page_version to undo.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id' => ['type' => 'integer', 'description' => 'WordPress page ID'],
                    ],
                    'required' => ['page_id'],
                ],
            ],
            [
                'name'        => 'get_page_history_entry',
                'description' => 'Get the full saved content of one previous version of a page (from list_page_history), e.g. to compare it with the current layout before restoring.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id'    => ['type' => 'integer', 'description' => 'WordPress page ID'],
                        'version_id' => ['type' => 'integer', 'description' => 'Version id from list_page_history'],
                    ],
                    'required' => ['page_id', 'version_id'],
                ],
            ],
            [
                'name'        => 'restore_page_version',
                'description' => 'Undo: restore a page to a previous saved version. The current content is snapshotted first; the restore itself is undoable when its own history.stored is true. Restore does not block on validation (it returns the person\'s own earlier content); the result reports whether that content passes the validator.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id'    => ['type' => 'integer', 'description' => 'WordPress page ID'],
                        'version_id' => ['type' => 'integer', 'description' => 'Version id from list_page_history'],
                    ],
                    'required' => ['page_id', 'version_id'],
                ],
            ],
            [
                'name'        => 'create_page',
                'description' => 'Create a new WordPress page with a validated Divi 5 layout. The page is always created as a DRAFT for the site owner to review and publish. For a landing/marketing page, call get_landing_guide first for the conversion structure (persuasion flow, copywriting, CTA placement), get_style_guide for the real styling attribute shapes, get_section_recipes to assemble the page from complete proven section patterns, and get_image_guide to choose a relevant, role-appropriate image for each section — so the page is strategically structured, styled, well-composed, and visually finished, not plain. Never leave an image module without a src (see get_image_guide for the right keyless source per role; picsum /seed/ is the generic fallback).',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'title'        => ['type' => 'string', 'description' => 'Page title'],
                        'post_content' => ['type' => 'string', 'description' => 'Divi 5 Gutenberg block HTML'],
                        'slug'         => ['type' => 'string', 'description' => 'Optional URL slug (e.g. "about") for predictable cross-linking'],
                    ],
                    'required' => ['title', 'post_content'],
                ],
            ],
        ]]);
    }

    private function onToolsCall(mixed $id, array $params): WP_REST_Response
    {
        $name      = (string) ($params['name']      ?? '');
        $arguments = (array)  ($params['arguments'] ?? []);

        return match ($name) {
            'list_divi_pages'    => $this->toolListPages($id),
            'get_style_guide'    => $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => StyleGuide::markdown()]]]),
            'get_site_guide'     => $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => SiteGuide::markdown()]]]),
            'get_landing_guide'  => $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => LandingGuide::markdown()]]]),
            'get_image_guide'    => $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => ImageGuide::markdown()]]]),
            'get_section_recipes' => $this->toolSectionRecipes($id, $arguments),
            'get_page_layout'    => $this->toolGetLayout($id, $arguments),
            'validate_layout'    => $this->toolValidate($id, $arguments),
            'update_page_layout' => $this->toolUpdate($id, $arguments),
            'edit_page_content'  => $this->toolEditContent($id, $arguments),
            'list_page_history'      => $this->toolListHistory($id, $arguments),
            'get_page_history_entry' => $this->toolGetHistoryEntry($id, $arguments),
            'restore_page_version'   => $this->toolRestoreVersion($id, $arguments),
            'create_page'        => $this->toolCreatePage($id, $arguments),
            default              => $this->rpcError($id, -32602, "Unknown tool: {$name}"),
        };
    }

    // ---------------------------------------------------------------
    // Tool implementations
    // ---------------------------------------------------------------

    private function toolSectionRecipes(mixed $id, array $args): WP_REST_Response
    {
        $name = isset($args['name']) ? (string) $args['name'] : '';
        if ($name !== '') {
            $markup = SectionRecipes::recipe($name);
            $text = $markup ?? ("Unknown recipe '{$name}'.\n\n" . SectionRecipes::catalog());
        } else {
            $text = SectionRecipes::catalog();
        }
        return $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => $text]]]);
    }

    private function toolListPages(mixed $id): WP_REST_Response
    {
        $posts = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'any',
            'posts_per_page' => 100,
            'meta_query'     => [['key' => '_et_pb_use_divi_5', 'value' => 'on']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        ]);

        $pages = array_map(fn(\WP_Post $p) => [
            'id'     => $p->ID,
            'title'  => get_the_title($p),
            'status' => $p->post_status,
            'link'   => get_permalink($p),
        ], $posts);

        UsageTracker::log('list_pages', null, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(['pages' => $pages, 'count' => count($pages)])]],
        ]);
    }

    private function toolGetLayout(mixed $id, array $args): WP_REST_Response
    {
        $pageId = (int) ($args['page_id'] ?? 0);
        $post   = $pageId ? get_post($pageId) : null;

        if (!$post || $post->post_type !== 'page') {
            UsageTracker::log('get_layout', $pageId ?: null, 'error');
            return $this->rpcError($id, -32602, "Page {$pageId} not found.");
        }

        if (!current_user_can('edit_post', $pageId)) {
            UsageTracker::log('get_layout', $pageId, 'error');
            return $this->rpcError($id, -32602, "You do not have permission to read page {$pageId}.");
        }

        UsageTracker::log('get_layout', $pageId, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode([
                'post_id'      => $post->ID,
                'post_title'   => get_the_title($post),
                'post_status'  => $post->post_status,
                'post_content' => $post->post_content,
            ])]],
        ]);
    }

    private function toolValidate(mixed $id, array $args): WP_REST_Response
    {
        $content = (string) ($args['post_content'] ?? '');
        if ($content === '') {
            return $this->rpcError($id, -32602, 'post_content is required.');
        }

        $result = (new Validator())->validateContent($content);
        UsageTracker::log('validate', null, $result->isValid() ? 'valid' : 'invalid', count($result->violations()));

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode($result->toArray())]],
        ]);
    }

    private function toolUpdate(mixed $id, array $args): WP_REST_Response
    {
        $pageId  = (int)    ($args['page_id']      ?? 0);
        $content = (string) ($args['post_content'] ?? '');
        $post    = $pageId ? get_post($pageId) : null;

        if (!$post || $post->post_type !== 'page') {
            return $this->rpcError($id, -32602, "Page {$pageId} not found.");
        }

        if (!current_user_can('edit_post', $pageId)) {
            return $this->rpcError($id, -32602, "You do not have permission to edit page {$pageId}.");
        }

        if ($content === '') {
            return $this->rpcError($id, -32602, 'post_content is required.');
        }

        $result = (new Validator())->validateContent($content);

        if (!$result->isValid()) {
            UsageTracker::log('update_layout', $pageId, 'invalid', count($result->violations()));
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'saved'      => false,
                    'valid'      => false,
                    'violations' => array_map(fn($v) => $v->toArray(), $result->violations()),
                ])]],
                'isError' => true,
            ]);
        }

        // Snapshots the previous content, then saves (wp_slash is applied inside HistoryService).
        $write = HistoryService::write($pageId, $content, 'update_page_layout');

        if (!$write['ok']) {
            UsageTracker::log('update_layout', $pageId, 'error');
            return $this->rpcError($id, -32603, (string) $write['message']);
        }

        UsageTracker::log('update_layout', $pageId, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode([
                'saved'   => true,
                'valid'   => true,
                'history' => $write['snapshot'],
                'page'    => ['id' => $pageId, 'title' => get_the_title($pageId)],
            ])]],
        ]);
    }

    private function toolEditContent(mixed $id, array $args): WP_REST_Response
    {
        $pageId  = (int)    ($args['page_id'] ?? 0);
        $find    = (string) ($args['find']    ?? '');
        $replace = (string) ($args['replace'] ?? '');
        $expect  = (array_key_exists('expect_count', $args) && $args['expect_count'] !== null)
            ? (int) $args['expect_count']
            : null;
        $post = $pageId ? get_post($pageId) : null;

        if (!$post || $post->post_type !== 'page') {
            return $this->rpcError($id, -32602, "Page {$pageId} not found.");
        }
        if (!current_user_can('edit_post', $pageId)) {
            return $this->rpcError($id, -32602, "You do not have permission to edit page {$pageId}.");
        }

        $edit = PageEditor::apply($post->post_content, $find, $replace, $expect);

        if (!$edit['ok']) {
            UsageTracker::log('edit_content', $pageId, 'error');
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'saved'   => false,
                    'matches' => $edit['count'],
                    'message' => $edit['error'],
                ])]],
                'isError' => true,
            ]);
        }

        // A find/replace still has to clear the deterministic validator — the
        // same gate as update_page_layout — before anything is written.
        $result = (new Validator())->validateContent($edit['content']);
        if (!$result->isValid()) {
            UsageTracker::log('edit_content', $pageId, 'invalid', count($result->violations()));
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'saved'      => false,
                    'valid'      => false,
                    'violations' => array_map(fn($v) => $v->toArray(), $result->violations()),
                ])]],
                'isError' => true,
            ]);
        }

        $write = HistoryService::write($pageId, $edit['content'], 'edit_page_content');
        if (!$write['ok']) {
            UsageTracker::log('edit_content', $pageId, 'error');
            return $this->rpcError($id, -32603, (string) $write['message']);
        }

        UsageTracker::log('edit_content', $pageId, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode([
                'saved'    => true,
                'valid'    => true,
                'replaced' => $edit['count'],
                'history'  => $write['snapshot'],
                'page'     => ['id' => $pageId, 'title' => get_the_title($pageId)],
            ])]],
        ]);
    }

    /** Resolves a page the caller may edit, or returns an rpcError response. */
    private function historyPage(mixed $id, array $args): \WP_Post|WP_REST_Response
    {
        $pageId = (int) ($args['page_id'] ?? 0);
        $post   = $pageId ? get_post($pageId) : null;

        if (!$post || $post->post_type !== 'page') {
            return $this->rpcError($id, -32602, "Page {$pageId} not found.");
        }
        if (!current_user_can('edit_post', $pageId)) {
            return $this->rpcError($id, -32602, "You do not have permission to access page {$pageId}.");
        }

        return $post;
    }

    private function toolListHistory(mixed $id, array $args): WP_REST_Response
    {
        $post = $this->historyPage($id, $args);
        if ($post instanceof WP_REST_Response) {
            return $post;
        }
        UsageTracker::log('list_history', $post->ID, 'valid');
        $versions = HistoryService::listFor($post->ID);

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(['page_id' => $post->ID, 'versions' => $versions, 'count' => count($versions)])]],
        ]);
    }

    private function toolGetHistoryEntry(mixed $id, array $args): WP_REST_Response
    {
        $post = $this->historyPage($id, $args);
        if ($post instanceof WP_REST_Response) {
            return $post;
        }
        $versionId = (int) ($args['version_id'] ?? 0);
        $entry     = HistoryService::entry($post->ID, $versionId);
        if ($entry === null) {
            UsageTracker::log('get_history', $post->ID, 'error');
            return $this->rpcError($id, -32602, "Version {$versionId} not found for page {$post->ID}.");
        }
        UsageTracker::log('get_history', $post->ID, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(['page_id' => $post->ID, 'version' => array_diff_key($entry, ['content' => 1]), 'post_content' => $entry['content']])]],
        ]);
    }

    private function toolRestoreVersion(mixed $id, array $args): WP_REST_Response
    {
        $post = $this->historyPage($id, $args);
        if ($post instanceof WP_REST_Response) {
            return $post;
        }
        $versionId = (int) ($args['version_id'] ?? 0);
        $result    = HistoryService::restore($post->ID, $versionId);
        if (!$result['ok']) {
            UsageTracker::log('restore_version', $post->ID, 'error');
            return $this->rpcError($id, $result['error'] === 'version_not_found' ? -32602 : -32603, (string) $result['message']);
        }
        UsageTracker::log('restore_version', $post->ID, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode([
                'restored'         => true,
                'restored_version' => $result['restored_version'],
                'history'          => $result['snapshot'],
                'validator'        => $result['validator'],
                'page'             => ['id' => $post->ID, 'title' => get_the_title($post->ID)],
            ])]],
        ]);
    }

    private function toolCreatePage(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can('publish_pages')) {
            UsageTracker::log('create_page', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to create pages.');
        }

        $title   = trim((string) ($args['title'] ?? ''));
        $content = (string) ($args['post_content'] ?? '');
        $slug    = sanitize_title((string) ($args['slug'] ?? ''));

        if ($title === '') {
            return $this->rpcError($id, -32602, 'title is required.');
        }
        if ($content === '') {
            return $this->rpcError($id, -32602, 'post_content is required.');
        }

        $result = (new Validator())->validateContent($content);

        if (!$result->isValid()) {
            UsageTracker::log('create_page', null, 'invalid', count($result->violations()));
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'created'    => false,
                    'valid'      => false,
                    'violations' => array_map(fn($v) => $v->toArray(), $result->violations()),
                ])]],
                'isError' => true,
            ]);
        }

        // Always a draft — the site owner reviews and publishes. The Divi 5
        // builder meta flags make the page open in the Divi 5 editor and appear
        // in list_divi_pages (which filters on _et_pb_use_divi_5).
        // wp_slash: wp_insert_post runs wp_unslash internally, which would
        // otherwise strip backslashes from escaped HTML (e.g. <) and corrupt content.
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
            UsageTracker::log('create_page', null, 'error');
            return $this->rpcError($id, -32603, $pageId->get_error_message());
        }

        UsageTracker::log('create_page', (int) $pageId, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode([
                'created' => true,
                'valid'   => true,
                'page'    => [
                    'id'     => (int) $pageId,
                    'title'  => $title,
                    'status' => 'draft',
                    'link'   => get_permalink((int) $pageId),
                ],
            ])]],
        ]);
    }

    // ---------------------------------------------------------------
    // JSON-RPC helpers
    // ---------------------------------------------------------------

    private function rpcResult(mixed $id, array $result): WP_REST_Response
    {
        return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200);
    }

    private function rpcError(mixed $id, int $code, string $message): WP_REST_Response
    {
        return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], 200);
    }

    private function noContent(): WP_REST_Response
    {
        return new WP_REST_Response(null, 204);
    }
}
