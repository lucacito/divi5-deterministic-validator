<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/ViolationReport.php';
require_once __DIR__ . '/PageOutline.php';

// The extension guard is also loaded by autoload.php; requiring it here keeps this file safe on its own.
require_once __DIR__ . '/ExtensionGuard.php';
require_once __DIR__ . '/PageAccess.php';
require_once __DIR__ . '/TitleChange.php';
require_once __DIR__ . '/ToolCatalog.php';

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
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            // MCP clients open a GET stream (and may send DELETE to end a session). This server offers neither,
            // and the Streamable HTTP spec says to answer 405, not 404: a route registered for POST only makes
            // core answer rest_no_route (404), which "too many 404s" firewall rules (ModSecurity) count against
            // the client's IP. Public on purpose: the answer reveals nothing.
            [
                'methods'             => 'GET,DELETE',
                'callback'            => [$this, 'methodNotAllowed'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    public function methodNotAllowed(): WP_REST_Response
    {
        $response = new WP_REST_Response(['code' => 'method_not_allowed', 'message' => 'This MCP endpoint accepts POST only.'], 405);
        $response->header('Allow', 'POST');
        return $response;
    }

    public function authenticate(): bool|WP_Error
    {
        if (ApiKey::authenticateRequest()) {
            // The key is valid, but its user must still be able to edit pages (a demoted or
            // deleted key owner cannot keep using the plugin).
            if (!PageAccess::keyUserAllowed('current_user_can')) {
                return new WP_Error('forbidden', ApiKey::presentsOAuthToken() ? 'The signed-in user cannot edit pages.' : 'The API key belongs to a user who cannot edit pages.', ['status' => 403]);
            }
            return true;
        }

        // An access token that is wrong, expired or revoked is a 401: that is what makes a client such as Claude
        // refresh its token or sign in again. (A wrong API key keeps its published 403 below.)
        if (ApiKey::presentsOAuthToken()) {
            return new WP_Error('invalid_token', 'The access token is invalid or has expired.', ['status' => 401]);
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

        $id = $body['id'] ?? null;
        if (!is_string($body['method'] ?? '')) {
            return $this->rpcError($id, -32600, 'Invalid Request — "method" must be a string');
        }
        $method = (string) ($body['method'] ?? '');
        $params = (array)  ($body['params'] ?? []);

        return match ($method) {
            'initialize'               => $this->onInitialize($id),
            'ping'                     => $this->rpcResult($id, new \stdClass()),
            'tools/list'               => $this->onToolsList($id),
            'tools/call'               => $this->onToolsCall($id, $params),
            default                    => str_starts_with($method, 'notifications/')
                ? $this->accepted()
                : $this->rpcError($id, -32601, "Method not found: " . SectionRecipes::plain( (string) $method, '/[^A-Za-z0-9 _.:\/-]/' )),
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
        $tools = ToolCatalog::definitions();

        // Extension point: add-ons may append tool definitions. With no listener this is a no-op.
        $tools = ExtensionGuard::tools( $tools, apply_filters( 'jhmg_aied_mcp_tools', $tools ) );

        return $this->rpcResult($id, ['tools' => $tools]);
    }

    /**
     * Runs one of the free tools in-process and returns its answer as a plain array, for callers that are not JSON-RPC (the
     * WordPress abilities). It runs the very same tool method `tools/call` runs, so approval mode, dry run, history, the
     * validator gate and every capability check behave identically. Only the tools of ToolCatalog are reachable: an
     * add-on's tool is never run through here.
     *
     * @param array<string,mixed> $arguments
     * @return array{ok:bool, text:string, data:?array<mixed>, code:?int, message:string}
     *         ok=false for a protocol error (code, message) or a tool that answered isError (text and data hold its answer).
     */
    public function callTool(string $name, array $arguments): array
    {
        if (null === ToolCatalog::get($name)) {
            return ['ok' => false, 'text' => '', 'data' => null, 'code' => -32602, 'message' => 'Unknown tool.'];
        }
        $body = (array) $this->onToolsCall(null, ['name' => $name, 'arguments' => $arguments])->get_data();
        if (isset($body['error'])) {
            $error = (array) $body['error'];
            return ['ok' => false, 'text' => '', 'data' => null, 'code' => (int) ($error['code'] ?? -32603), 'message' => (string) ($error['message'] ?? 'Error')];
        }
        $result = (array) ($body['result'] ?? []);
        $first  = (array) (((array) ($result['content'] ?? []))[0] ?? []);
        $text   = (string) ($first['text'] ?? '');
        $data   = json_decode($text, true);

        return [
            'ok'      => empty($result['isError']),
            'text'    => $text,
            'data'    => is_array($data) ? $data : null,
            'code'    => null,
            'message' => '',
        ];
    }

    private function onToolsCall(mixed $id, array $params): WP_REST_Response
    {
        $name = PageAccess::stringArg($params, 'name');
        if ($name === null) {
            return $this->rpcError($id, -32602, 'Invalid params — tool "name" must be a string');
        }
        $arguments = (array) ($params['arguments'] ?? []);

        return match ($name) {
            'list_divi_pages'    => $this->toolListPages($id, $arguments),
            'get_style_guide'    => $this->toolStyleGuide($id, $arguments),
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
            'list_media_images'  => $this->toolListMedia($id, $arguments),
            'list_global_styles' => $this->toolListGlobalStyles($id, $arguments),
            'get_site_structure' => $this->toolSiteStructure($id, $arguments),
            'get_custom_fields'  => $this->toolGetCustomFields($id, $arguments),
            'list_products'      => $this->toolListProducts($id, $arguments),
            'get_product'        => $this->toolGetProduct($id, $arguments),
            'create_page'        => $this->toolCreatePage($id, $arguments),
            'html_to_divi'       => $this->toolHtmlToDivi($id, $arguments),
            'lab_build'          => $this->toolLabBuild($id, $arguments),
            default              => $this->extensionCall($id, $name, $arguments),
        };
    }

    /**
     * Add-ons claim tool calls via the jhmg_aied_mcp_call filter. An add-on handler is
     * responsible for its own capability checks and returns a WP_REST_Response
     * (use the same JSON-RPC shapes as the built-in tools); anything else = unknown tool.
     */
    private function extensionCall(mixed $id, string $name, array $arguments): WP_REST_Response
    {
        $response = apply_filters( 'jhmg_aied_mcp_call', null, $name, $arguments, $id );
        if ( $response instanceof WP_REST_Response ) {
            return $response;
        }

        return $this->rpcError($id, -32602, "Unknown tool: " . SectionRecipes::plain( (string) $name ));
    }

    // ---------------------------------------------------------------
    // Tool implementations
    // ---------------------------------------------------------------

    private function toolStyleGuide(mixed $id, array $args): WP_REST_Response
    {
        $topic = PageAccess::stringArg($args, 'topic');
        if ($topic === null) {
            return $this->rpcError($id, -32602, 'Invalid params — guide "topic" must be a string');
        }
        $language = PageAccess::stringArg($args, 'design_language');
        if ($language === null) {
            return $this->rpcError($id, -32602, 'Invalid params — guide "design_language" must be a string');
        }
        return $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => StyleGuide::markdown($topic === '' ? null : $topic, $language === '' ? null : $language)]]]);
    }

    private function toolSectionRecipes(mixed $id, array $args): WP_REST_Response
    {
        $name = PageAccess::stringArg($args, 'name');
        if ($name === null) {
            return $this->rpcError($id, -32602, 'Invalid params — recipe "name" must be a string');
        }
        $filters = [];
        foreach (['role', 'layout', 'mood'] as $key) {
            $value = PageAccess::stringArg($args, $key);
            if ($value === null) {
                return $this->rpcError($id, -32602, "Invalid params — recipe \"{$key}\" must be a string");
            }
            $filters[$key] = $value === '' ? null : $value;
        }
        $language = PageAccess::stringArg($args, 'design_language');
        if ($language === null) {
            return $this->rpcError($id, -32602, 'Invalid params — recipe "design_language" must be a string');
        }
        $kit = PageAccess::stringArg($args, 'style_kit');
        if ($kit === null) {
            return $this->rpcError($id, -32602, 'Invalid params — recipe "style_kit" must be a string');
        }
        if ($name !== '') {
            if ($language !== '' && DesignLanguages::get($language) === null) {
                $text = DesignLanguages::markdown($language);
            } elseif ($kit !== '' && StyleKits::get($kit) === null) {
                $text = StyleKits::markdown($kit);
            } else {
                $markup = SectionRecipes::recipe($name, $language === '' ? null : $language, $kit === '' ? null : $kit);
                $text = $markup ?? ("Unknown recipe '" . SectionRecipes::plain($name) . "'.\n\n" . SectionRecipes::catalog());
            }
        } else {
            $text = SectionRecipes::catalog($filters['role'], $filters['layout'], $filters['mood']);
        }
        return $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => $text]]]);
    }

    private function toolListPages(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('list_pages', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to list pages.');
        }

        $postType = PageAccess::postTypeArg($args, 'post_type', EditTarget::ALLOWED_TYPES);
        if ($postType === null) {
            return $this->rpcError($id, -32602, 'post_type must be one of: ' . implode(', ', EditTarget::ALLOWED_TYPES) . '.');
        }

        $posts = get_posts([
            'post_type'      => $postType,
            'post_status'    => 'any',
            'posts_per_page' => 100,
            'meta_query'     => [['key' => '_et_pb_use_divi_5', 'value' => 'on']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        ]);
        // Only pages or Projects this user can edit (other users' drafts/private ones stay hidden).
        $posts = PageAccess::filterEditable($posts, static fn(int $pid): bool => current_user_can('edit_post', $pid));

        $pages = array_map(fn(\WP_Post $p) => [
            'id'        => $p->ID,
            'title'     => get_the_title($p),
            'status'    => $p->post_status,
            'post_type' => $p->post_type,
            'link'      => get_permalink($p),
        ], $posts);

        UsageTracker::log('list_pages', null, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(['pages' => $pages, 'count' => count($pages)])]],
        ]);
    }

    private function toolGetLayout(mixed $id, array $args): WP_REST_Response
    {
        $pageId = (int) ($args['page_id'] ?? 0);
        $target = EditTarget::resolve($pageId);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            UsageTracker::log('get_layout', $pageId ?: null, 'error');
            return $this->rpcError($id, -32602, "Page or Project {$pageId} not found.");
        }

        if ($target['error'] === EditTarget::FORBIDDEN) {
            UsageTracker::log('get_layout', $pageId, 'error');
            return $this->rpcError($id, -32602, "You do not have permission to read page or Project {$pageId}.");
        }
        $post = $target['post'];

        $view = PageOutline::parseView($args['view'] ?? null, $args['path'] ?? null);
        if ($view['error'] !== null) {
            UsageTracker::log('get_layout', $pageId, 'error');
            return $this->rpcError($id, -32602, (string) $view['error']);
        }

        UsageTracker::log('get_layout', $pageId, 'read');

        $meta = [
            'post_id'     => $post->ID,
            'post_type'   => $post->post_type,
            'post_title'  => get_the_title($post),
            'post_status' => $post->post_status,
        ];
        if ($view['view'] !== 'full') {
            $r = PageOutline::respond($meta, (string) $post->post_content, $view);

            return $r['status'] === 200
                ? $this->rpcResult($id, ['content' => [['type' => 'text', 'text' => json_encode($r['body'])]]])
                : $this->rpcError($id, -32602, (string) $r['body']['error']);
        }

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode($meta + ['post_content' => $post->post_content])]],
        ]);
    }

    private function toolValidate(mixed $id, array $args): WP_REST_Response
    {
        $content = PageAccess::stringArg($args, 'post_content');
        if ($content === null) {
            return $this->rpcError($id, -32602, 'post_content must be a string.');
        }
        if ($content === '') {
            return $this->rpcError($id, -32602, 'post_content is required.');
        }

        $result = LayoutGate::check($content);
        UsageTracker::log('validate', null, $result->isValid() ? 'read' : 'invalid', count($result->violations()));

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach(['valid' => $result->isValid(), 'violations' => ViolationReport::compact($result->violations())], $content))]],
        ]);
    }

    private function toolUpdate(mixed $id, array $args): WP_REST_Response
    {
        $pageId     = (int) ($args['page_id'] ?? 0);
        $hasContent = array_key_exists('post_content', $args) && $args['post_content'] !== null;
        $content    = PageAccess::stringArg($args, 'post_content');
        $dryRun     = PageAccess::boolArg($args, 'dry_run');

        if ($content === null) {
            return $this->rpcError($id, -32602, 'post_content must be a string.');
        }

        if ($dryRun === null) {
            return $this->rpcError($id, -32602, 'dry_run must be a boolean.');
        }

        $target = EditTarget::resolve($pageId);
        if ($target['error'] === EditTarget::NOT_FOUND) {
            return $this->rpcError($id, -32602, "Page or Project {$pageId} not found.");
        }

        if ($target['error'] === EditTarget::FORBIDDEN) {
            return $this->rpcError($id, -32602, "You do not have permission to edit page or Project {$pageId}.");
        }
        $post = $target['post'];

        // As in REST, the page is looked up first: a bad title on a missing or forbidden page answers "not found" / "no permission".
        $titleArg = PageTitle::parse($args);
        if (!$titleArg['ok']) {
            return $this->rpcError($id, -32602, (string) $titleArg['error']);
        }

        if (!$hasContent && !$titleArg['present']) {
            return $this->rpcError($id, -32602, 'Give post_content, title, or both.');
        }

        if ($hasContent && $content === '') {
            return $this->rpcError($id, -32602, 'post_content is required.');
        }

        // The title is only written when it differs from the stored one (a repeat is reported, never re-saved).
        $plan = TitleChange::plan((string) $post->post_title, (string) $post->post_content, $hasContent ? $content : null, $titleArg);

        if (!$hasContent) {
            return $this->toolUpdateTitleOnly($id, $pageId, $plan, $dryRun);
        }

        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $pageId, UsageTracker::RESULT_READ);

            $preview = DryRun::preview((string) $post->post_content, $content, $plan['report']);
            ApprovalMode::recordPreview($pageId, $plan['subject'], (bool) $preview['valid'], $plan['base']);

            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach($preview, $content, (string) $post->post_content))]],
            ]);
        }

        $result = LayoutGate::check($content, (string) $post->post_content);

        if (!$result->isValid()) {
            UsageTracker::log('update_layout', $pageId, 'invalid', count($result->violations()));
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'saved'      => false,
                    'valid'      => false,
                    'violations' => ViolationReport::compact($result->violations()),
                ])]],
                'isError' => true,
            ]);
        }

        if (!ApprovalMode::consume($pageId, $plan['subject'], $plan['base'])) {
            UsageTracker::log('update_layout', $pageId, 'error');
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(ApprovalMode::refusal())]],
                'isError' => true,
            ]);
        }

        // Snapshots the previous content, then saves (wp_slash is applied inside HistoryService).
        $previousContent = (string) $post->post_content;
        $write = HistoryService::write($pageId, $content, 'update_page_layout', $plan['writeTitle']);

        if (!$write['ok']) {
            UsageTracker::log('update_layout', $pageId, 'error');
            return $this->rpcError($id, -32603, (string) $write['message']);
        }

        UsageTracker::log('update_layout', $pageId, 'valid');

        $out = [
            'saved'   => true,
            'valid'   => true,
            'history' => $write['snapshot'],
            'page'    => ['id' => $pageId, 'title' => get_the_title($pageId)],
        ];
        if ($plan['report'] !== null) {
            $out['title'] = $plan['changed']
                ? PageTitle::report((string) $plan['report']['before'], (string) ($write['title_after'] ?? $plan['report']['after']), true)
                : $plan['report'];
            $out['message'] = $plan['message'];
        }

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach($out, $content, $previousContent))]],
        ]);
    }

    /**
     * update_page_layout with a title and no post_content: only the page title is touched. The layout is neither
     * checked nor snapshotted (nothing about it changes), and the call needs the same approval-mode preview as any save.
     *
     * @param array{present:bool, changed:bool, writeTitle:?string, report:?array<string,mixed>, subject:string, base:string, message:?string} $plan TitleChange::plan()
     */
    private function toolUpdateTitleOnly(mixed $id, int $pageId, array $plan, bool $dryRun): WP_REST_Response
    {
        $report      = (array) $plan['report'];
        $titleBefore = (string) $report['before'];

        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $pageId, UsageTracker::RESULT_READ);

            $preview = DryRun::previewTitle($report);
            if ($plan['changed']) {
                ApprovalMode::recordPreview($pageId, $plan['subject'], true, $plan['base']);
            } else {
                $preview['message'] = $plan['message'];
            }

            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode($preview)]],
            ]);
        }

        if (!$plan['changed']) {
            UsageTracker::log('update_layout', $pageId, UsageTracker::RESULT_READ); // nothing was saved

            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'saved'          => false,
                    'valid'          => true,
                    'layout_changed' => false,
                    'title'          => $report,
                    'page'           => ['id' => $pageId, 'title' => get_the_title($pageId)],
                    'message'        => $plan['message'],
                ])]],
            ]);
        }

        if (!ApprovalMode::consume($pageId, $plan['subject'], $plan['base'])) {
            UsageTracker::log('update_layout', $pageId, 'error');
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(ApprovalMode::refusal())]],
                'isError' => true,
            ]);
        }

        $write = HistoryService::writeTitle($pageId, (string) $plan['writeTitle'], 'update_page_layout');
        if (!$write['ok']) {
            UsageTracker::log('update_layout', $pageId, 'error');
            return $this->rpcError($id, -32603, (string) $write['message']);
        }

        UsageTracker::log('update_layout', $pageId, 'valid');

        $layoutChanged = (bool) ($write['layout_changed'] ?? false);

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode([
                'saved'          => true,
                'valid'          => true,
                'layout_changed' => $layoutChanged,
                'history'        => $write['snapshot'],
                'title'          => PageTitle::report($titleBefore, (string) ($write['title_after'] ?? $plan['writeTitle']), true),
                'page'           => ['id' => $pageId, 'title' => get_the_title($pageId)],
                'message'        => $plan['message'] . ($layoutChanged ? ' ' . PageTitle::contentTouchedNote() : ''),
            ])]],
        ]);
    }

    private function toolEditContent(mixed $id, array $args): WP_REST_Response
    {
        $pageId  = (int) ($args['page_id'] ?? 0);
        $find    = PageAccess::stringArg($args, 'find');
        $replace = PageAccess::stringArg($args, 'replace');
        if ($find === null || $replace === null) {
            return $this->rpcError($id, -32602, 'find and replace must be strings.');
        }
        $expect  = (array_key_exists('expect_count', $args) && $args['expect_count'] !== null)
            ? (int) $args['expect_count']
            : null;
        $dryRun  = PageAccess::boolArg($args, 'dry_run');
        if ($dryRun === null) {
            return $this->rpcError($id, -32602, 'dry_run must be a boolean.');
        }

        $target = EditTarget::resolve($pageId);
        if ($target['error'] === EditTarget::NOT_FOUND) {
            return $this->rpcError($id, -32602, "Page or Project {$pageId} not found.");
        }
        if ($target['error'] === EditTarget::FORBIDDEN) {
            return $this->rpcError($id, -32602, "You do not have permission to edit page or Project {$pageId}.");
        }
        $post = $target['post'];

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

        if ($dryRun) {
            UsageTracker::log(DryRun::ENDPOINT, $pageId, UsageTracker::RESULT_READ);

            $preview = DryRun::preview((string) $post->post_content, $edit['content']);
            ApprovalMode::recordPreview($pageId, $edit['content'], (bool) $preview['valid'], (string) $post->post_content);

            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach($preview + ['replaced' => $edit['count']], $edit['content'], (string) $post->post_content))]],
            ]);
        }

        // A find/replace still has to clear the deterministic validator — the
        // same gate as update_page_layout — before anything is written.
        $result = LayoutGate::check($edit['content'], (string) $post->post_content);
        if (!$result->isValid()) {
            UsageTracker::log('edit_content', $pageId, 'invalid', count($result->violations()));
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'saved'      => false,
                    'valid'      => false,
                    'violations' => ViolationReport::compact($result->violations()),
                ])]],
                'isError' => true,
            ]);
        }

        if (!ApprovalMode::consume($pageId, $edit['content'], (string) $post->post_content)) {
            UsageTracker::log('edit_content', $pageId, 'error');
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode(ApprovalMode::refusal())]],
                'isError' => true,
            ]);
        }

        $previousContent = (string) $post->post_content;
        $write = HistoryService::write($pageId, $edit['content'], 'edit_page_content');
        if (!$write['ok']) {
            UsageTracker::log('edit_content', $pageId, 'error');
            return $this->rpcError($id, -32603, (string) $write['message']);
        }

        UsageTracker::log('edit_content', $pageId, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach([
                'saved'    => true,
                'valid'    => true,
                'replaced' => $edit['count'],
                'history'  => $write['snapshot'],
                'page'     => ['id' => $pageId, 'title' => get_the_title($pageId)],
            ], $edit['content'], $previousContent))]],
        ]);
    }

    /** Resolves a page the caller may edit, or returns an rpcError response. */
    private function historyPage(mixed $id, array $args): \WP_Post|WP_REST_Response
    {
        $pageId = (int) ($args['page_id'] ?? 0);
        $target = EditTarget::resolve($pageId);

        if ($target['error'] === EditTarget::NOT_FOUND) {
            return $this->rpcError($id, -32602, "Page or Project {$pageId} not found.");
        }
        if ($target['error'] === EditTarget::FORBIDDEN) {
            return $this->rpcError($id, -32602, "You do not have permission to access page or Project {$pageId}.");
        }

        return $target['post'];
    }

    private function toolListHistory(mixed $id, array $args): WP_REST_Response
    {
        $post = $this->historyPage($id, $args);
        if ($post instanceof WP_REST_Response) {
            return $post;
        }
        UsageTracker::log('list_history', $post->ID, 'read');
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
            return $this->rpcError($id, -32602, "Version {$versionId} not found for page or Project {$post->ID}.");
        }
        UsageTracker::log('get_history', $post->ID, 'read');

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
                'title_restored'   => $result['title_restored'],
                'title_note'       => $result['title_note'],
                'page'             => ['id' => $post->ID, 'title' => get_the_title($post->ID)],
            ])]],
        ]);
    }

    private function toolListGlobalStyles(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('list_global_styles', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read the site styles.');
        }
        $module = PageAccess::stringArg($args, 'module');
        if ($module === null) {
            return $this->rpcError($id, -32602, 'module must be a string.');
        }
        $result = GlobalStyles::catalog($module);
        UsageTracker::log('list_global_styles', null, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($result)]],
        ]);
    }

    private function toolSiteStructure(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('site_structure', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read the site structure.');
        }
        $include = SiteStructure::parseInclude($args['include'] ?? null);
        if ($include === null) {
            return $this->rpcError($id, -32602, 'include must be a list of: ' . implode(', ', SiteStructure::SECTIONS) . '.');
        }
        $result = SiteStructure::catalog($include);
        UsageTracker::log('site_structure', null, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($result)]],
        ]);
    }

    private function toolGetCustomFields(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('custom_fields', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read custom fields.');
        }
        $postId = $args['id'] ?? null;
        if (!is_int($postId) || $postId < 1) {
            return $this->rpcError($id, -32602, 'id must be a positive integer.');
        }
        $only = is_string($args['keys'] ?? null) ? null : CustomFieldReader::parseKeys($args['keys'] ?? null); // MCP takes a list, never the REST comma form
        if ($only === null) {
            return $this->rpcError($id, -32602, 'keys must be a list of at most ' . CustomFieldReader::MAX_KEYS_ARG . ' strings.');
        }
        $target = FieldTarget::resolve($postId);
        if ($target['error'] !== null) {
            // A missing post, a non-public type and a post the user cannot edit all answer the same.
            UsageTracker::log('custom_fields', $postId, 'error');
            return $this->rpcError($id, -32602, "Post {$postId} not found.");
        }
        try {
            $result = CustomFieldReader::read($target['post'], $only);
        } catch (\Throwable) {
            UsageTracker::log('custom_fields', $postId, 'error');
            return $this->rpcError($id, -32603, 'Could not read the custom fields.');
        }
        UsageTracker::log('custom_fields', $postId, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($result)]],
        ]);
    }

    private function toolListProducts(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('list_products', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read products.');
        }

        return $this->productResult($id, 'list_products', null, ProductService::list($args, false, new WooProductGateway(), 'current_user_can'));
    }

    private function toolGetProduct(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can(PageAccess::CAP)) {
            UsageTracker::log('get_product', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read products.');
        }
        $productId = $args['id'] ?? null;
        if (!is_int($productId) || $productId < 1) {
            UsageTracker::log('get_product', null, 'error');
            return $this->rpcError($id, -32602, 'id must be a positive integer.');
        }

        return $this->productResult($id, 'get_product', $productId, ProductService::get($productId, new WooProductGateway(), 'current_user_can'));
    }

    /** @param array<string,mixed> $r a ProductService result */
    private function productResult(mixed $id, string $endpoint, ?int $postId, array $r): WP_REST_Response
    {
        UsageTracker::log($endpoint, $postId, $r['ok'] ? UsageTracker::RESULT_READ : UsageTracker::RESULT_ERROR);
        if (!$r['ok']) {
            return $this->rpcError($id, 'server' === $r['kind'] ? -32603 : -32602, (string) $r['message']);
        }

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($r['data'])]],
        ]);
    }

    private function toolListMedia(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can('upload_files')) {
            UsageTracker::log('list_media', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to read the Media Library.');
        }
        $result = MediaService::list($args);
        UsageTracker::log('list_media', null, 'read');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($result)]],
        ]);
    }

    private function toolHtmlToDivi(mixed $id, array $args): WP_REST_Response
    {
        $out = HtmlToDiviService::run($args);
        if ($out['status'] === 400 && !isset($out['body']['report'])) {
            // A bad argument: the same JSON-RPC error every other tool uses.
            return $this->rpcError($id, -32602, (string) ($out['body']['error'] ?? 'Invalid arguments.'));
        }
        if ($out['status'] === 403) {
            return $this->rpcError($id, -32602, (string) ($out['body']['error'] ?? 'Not allowed.'));
        }

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($out['body'])]],
            'isError' => $out['status'] >= 400,
        ]);
    }

    private function toolLabBuild(mixed $id, array $args): WP_REST_Response
    {
        $out = StudioAssistant::run($args);
        if ($out['status'] === 400 || $out['status'] === 403) {
            // A bad argument or a missing right: the same JSON-RPC error every other tool uses.
            return $this->rpcError($id, -32602, (string) ($out['body']['error'] ?? 'Invalid arguments.'));
        }

        // 404, 409, 422 and 500 are normal results the assistant can act on (isError true).
        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => wp_json_encode($out['body'])]],
            'isError' => $out['status'] >= 400,
        ]);
    }

    private function toolCreatePage(mixed $id, array $args): WP_REST_Response
    {
        if (!current_user_can('publish_pages')) {
            UsageTracker::log('create_page', null, 'error');
            return $this->rpcError($id, -32602, 'You do not have permission to create pages.');
        }

        $title   = PageAccess::stringArg($args, 'title');
        $content = PageAccess::stringArg($args, 'post_content');
        $slug    = PageAccess::stringArg($args, 'slug');
        if ($title === null || $content === null || $slug === null) {
            return $this->rpcError($id, -32602, 'title, post_content and slug must be strings.');
        }
        // A title is plain text: strip markup before it is stored (many themes print it unescaped).
        $title = trim(sanitize_text_field($title));
        $slug  = sanitize_title($slug);

        if ($title === '') {
            return $this->rpcError($id, -32602, 'title is required.');
        }
        if ($content === '') {
            return $this->rpcError($id, -32602, 'post_content is required.');
        }

        $result = LayoutGate::check($content);

        if (!$result->isValid()) {
            UsageTracker::log('create_page', null, 'invalid', count($result->violations()));
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => json_encode([
                    'created'    => false,
                    'valid'      => false,
                    'violations' => ViolationReport::compact($result->violations()),
                ])]],
                'isError' => true,
            ]);
        }

        $pageId = DraftPage::insert($title, $content, $slug);

        if (is_wp_error($pageId)) {
            UsageTracker::log('create_page', null, 'error');
            return $this->rpcError($id, -32603, $pageId->get_error_message());
        }

        UsageTracker::log('create_page', (int) $pageId, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode(StyleReferences::attach([
                'created' => true,
                'valid'   => true,
                'page'    => [
                    'id'     => (int) $pageId,
                    'title'  => $title,
                    'status' => 'draft',
                    'link'   => get_permalink((int) $pageId),
                ],
            ], $content))]],
        ]);
    }

    // ---------------------------------------------------------------
    // JSON-RPC helpers
    // ---------------------------------------------------------------

    private function rpcResult(mixed $id, array|\stdClass $result): WP_REST_Response
    {
        return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200);
    }

    private function rpcError(mixed $id, int $code, string $message): WP_REST_Response
    {
        return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], 200);
    }

    /** The MCP HTTP transport answers a notification with 202 Accepted and no body. */
    private function accepted(): WP_REST_Response
    {
        return new WP_REST_Response(null, 202);
    }
}
