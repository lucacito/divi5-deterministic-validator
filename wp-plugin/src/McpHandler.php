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
                'description' => 'Validate and save a new Divi 5 layout to a page. The page is only updated if the layout passes all schema checks — invalid layouts are rejected with a list of violations.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id'      => ['type' => 'integer', 'description' => 'WordPress page ID'],
                        'post_content' => ['type' => 'string',  'description' => 'Divi 5 Gutenberg block HTML'],
                    ],
                    'required' => ['page_id', 'post_content'],
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
            'get_page_layout'    => $this->toolGetLayout($id, $arguments),
            'validate_layout'    => $this->toolValidate($id, $arguments),
            'update_page_layout' => $this->toolUpdate($id, $arguments),
            default              => $this->rpcError($id, -32602, "Unknown tool: {$name}"),
        };
    }

    // ---------------------------------------------------------------
    // Tool implementations
    // ---------------------------------------------------------------

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

        $updated = wp_update_post(['ID' => $pageId, 'post_content' => $content], true);

        if (is_wp_error($updated)) {
            UsageTracker::log('update_layout', $pageId, 'error');
            return $this->rpcError($id, -32603, $updated->get_error_message());
        }

        UsageTracker::log('update_layout', $pageId, 'valid');

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => json_encode([
                'saved' => true,
                'valid' => true,
                'page'  => ['id' => $pageId, 'title' => get_the_title($pageId)],
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
