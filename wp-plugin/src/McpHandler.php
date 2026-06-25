<?php

declare(strict_types=1);

namespace Divi5Validator\WP;

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
 * Endpoint: POST /wp-json/divi5-validator/v1/mcp
 * Auth:     Authorization: Bearer {api_key}
 */
final class McpHandler
{
    private const PROTOCOL_VERSION = '2024-11-05';

    public function register_routes(): void
    {
        register_rest_route('divi5-validator/v1', '/mcp', [
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

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
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
            'notifications/initialized'=> $this->noContent(),
            'ping'                     => $this->rpcResult($id, new \stdClass()),
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
            'capabilities'    => ['tools' => new \stdClass()],
            'serverInfo'      => [
                'name'    => 'divi5-validator',
                'version' => DIVI5_VALIDATOR_VERSION,
            ],
        ]);
    }

    private function onToolsList(mixed $id): WP_REST_Response
    {
        return $this->rpcResult($id, ['tools' => $this->toolDefinitions()]);
    }

    private function onToolsCall(mixed $id, array $params): WP_REST_Response
    {
        $name = (string) ($params['name']      ?? '');
        $args = (array)  ($params['arguments'] ?? []);

        try {
            $text = match ($name) {
                'list_divi_pages'    => $this->toolListPages(),
                'get_page_layout'    => $this->toolGetLayout((int) ($args['page_id'] ?? 0)),
                'validate_layout'    => $this->toolValidate((string) ($args['post_content'] ?? '')),
                'update_page_layout' => $this->toolUpdate(
                    (int)    ($args['page_id']      ?? 0),
                    (string) ($args['post_content'] ?? '')
                ),
                default => throw new \InvalidArgumentException("Unknown tool: {$name}"),
            };
        } catch (\Throwable $e) {
            UsageTracker::log($name ?: 'unknown', null, 'error');
            return $this->rpcResult($id, [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ]);
        }

        return $this->rpcResult($id, [
            'content' => [['type' => 'text', 'text' => $text]],
        ]);
    }

    // ---------------------------------------------------------------
    // Tool implementations
    // ---------------------------------------------------------------

    private function toolListPages(): string
    {
        $posts = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'any',
            'posts_per_page' => 100,
            'meta_query'     => [['key' => '_et_pb_use_divi_5', 'value' => 'on']],
        ]);

        $pages = array_map(fn(\WP_Post $p) => [
            'id'        => $p->ID,
            'title'     => get_the_title($p),
            'status'    => $p->post_status,
            'edit_link' => get_edit_post_link($p->ID, 'raw'),
        ], $posts);

        UsageTracker::log('list_pages', null, 'valid');

        return json_encode(['pages' => $pages, 'count' => count($pages)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function toolGetLayout(int $pageId): string
    {
        if ($pageId <= 0) {
            throw new \InvalidArgumentException('page_id is required.');
        }

        $post = get_post($pageId);
        if (!$post || $post->post_type !== 'page') {
            throw new \RuntimeException("Page {$pageId} not found.");
        }

        UsageTracker::log('get_page', $pageId, 'valid');

        return json_encode([
            'page_id'      => $post->ID,
            'post_title'   => get_the_title($post),
            'post_status'  => $post->post_status,
            'post_content' => $post->post_content,
            'divi_version' => defined('ET_CORE_VERSION') ? ET_CORE_VERSION : 'unknown',
            'exported_at'  => date('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function toolValidate(string $postContent): string
    {
        if ($postContent === '') {
            throw new \InvalidArgumentException('post_content is required.');
        }

        $envelope = json_encode(['post_content' => $postContent]);
        $result   = (new Validator())->validate($envelope);
        $count    = count($result->violations());

        UsageTracker::log('validate', null, $result->isValid() ? 'valid' : 'invalid', $count);

        if ($result->isValid()) {
            return "VALID — No violations found.";
        }

        $lines = ["INVALID — {$count} violation(s) found:\n"];
        foreach ($result->violations() as $v) {
            $lines[] = "  [{$v->code()}] {$v->message()} (at {$v->path()})";
        }
        return implode("\n", $lines);
    }

    private function toolUpdate(int $pageId, string $postContent): string
    {
        if ($pageId <= 0)     throw new \InvalidArgumentException('page_id is required.');
        if ($postContent === '') throw new \InvalidArgumentException('post_content is required.');

        $post = get_post($pageId);
        if (!$post || $post->post_type !== 'page') {
            throw new \RuntimeException("Page {$pageId} not found.");
        }

        $envelope = json_encode(['post_content' => $postContent]);
        $result   = (new Validator())->validate($envelope);
        $count    = count($result->violations());

        if (!$result->isValid()) {
            UsageTracker::log('update_page', $pageId, 'invalid', $count);
            $lines = ["BLOCKED — Layout failed validation. Page was NOT updated.\n"];
            foreach ($result->violations() as $v) {
                $lines[] = "  [{$v->code()}] {$v->message()} (at {$v->path()})";
            }
            return implode("\n", $lines);
        }

        $saved = wp_update_post(['ID' => $pageId, 'post_content' => $postContent], true);
        if (is_wp_error($saved)) {
            throw new \RuntimeException($saved->get_error_message());
        }

        UsageTracker::log('update_page', $pageId, 'valid');
        return "SAVED — Page {$pageId} updated successfully. Layout passed all validation checks.";
    }

    // ---------------------------------------------------------------
    // Tool schema definitions
    // ---------------------------------------------------------------

    private function toolDefinitions(): array
    {
        return [
            [
                'name'        => 'list_divi_pages',
                'description' => 'List all WordPress pages that use the Divi 5 builder. Returns page IDs, titles, and statuses. Use this first to find a page ID.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
            ],
            [
                'name'        => 'get_page_layout',
                'description' => 'Get the current Divi 5 layout for a WordPress page. Returns the full post_content (Gutenberg block HTML) and metadata. Always call this before editing.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id' => ['type' => 'integer', 'description' => 'WordPress page ID (from list_divi_pages)'],
                    ],
                    'required'   => ['page_id'],
                ],
            ],
            [
                'name'        => 'validate_layout',
                'description' => 'Validate a Divi 5 layout without saving. Returns VALID or INVALID with specific violation codes and paths. Always validate before calling update_page_layout.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_content' => ['type' => 'string', 'description' => 'Divi 5 post_content (Gutenberg block HTML)'],
                    ],
                    'required'   => ['post_content'],
                ],
            ],
            [
                'name'        => 'update_page_layout',
                'description' => 'Validate a Divi 5 layout and, if valid, save it to a WordPress page. Refuses to save broken layouts — violations are returned instead. This is the only safe way to write Divi 5 content.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_id'      => ['type' => 'integer', 'description' => 'WordPress page ID to update'],
                        'post_content' => ['type' => 'string',  'description' => 'New Divi 5 post_content (Gutenberg block HTML)'],
                    ],
                    'required'   => ['page_id', 'post_content'],
                ],
            ],
        ];
    }

    // ---------------------------------------------------------------
    // JSON-RPC helpers
    // ---------------------------------------------------------------

    private function rpcResult(mixed $id, mixed $result): WP_REST_Response
    {
        return new WP_REST_Response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], 200);
    }

    private function rpcError(mixed $id, int $code, string $message): WP_REST_Response
    {
        return new WP_REST_Response(
            ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]],
            400
        );
    }

    private function noContent(): WP_REST_Response
    {
        return new WP_REST_Response(null, 204);
    }
}
