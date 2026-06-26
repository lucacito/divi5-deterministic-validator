<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

use Divi5Validator\Validator;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

/**
 * REST API endpoints for the Divi 5 validator.
 *
 * Namespace: /wp-json/divi5-validator/v1/
 *
 * All write endpoints require authentication (Application Password recommended).
 * Read + validate endpoints require at minimum 'edit_posts' capability.
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

        // POST /validate — validate a layout without saving
        register_rest_route(self::NS, '/validate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'validate'],
            'permission_callback' => [$this, 'require_edit_posts'],
        ]);
    }

    // ---------------------------------------------------------------
    // Endpoint handlers
    // ---------------------------------------------------------------

    public function list_pages(WP_REST_Request $request): WP_REST_Response
    {
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

        $pages = array_map(fn(\WP_Post $p) => [
            'id'         => $p->ID,
            'title'      => get_the_title($p),
            'status'     => $p->post_status,
            'link'       => get_permalink($p),
            'edit_link'  => get_edit_post_link($p->ID, 'raw'),
            'divi_meta'  => [
                '_et_pb_use_divi_5'  => get_post_meta($p->ID, '_et_pb_use_divi_5', true),
                '_et_pb_use_builder' => get_post_meta($p->ID, '_et_pb_use_builder', true),
            ],
        ], $posts);

        return new WP_REST_Response(['pages' => $pages, 'count' => count($pages)], 200);
    }

    public function get_page(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id   = (int) $request->get_param('id');
        $post = get_post($id);

        if (!$post || $post->post_type !== 'page') {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }

        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('forbidden', "You do not have permission to read page $id.", ['status' => 403]);
        }

        UsageTracker::log('get_page', $id, 'valid');

        $layout = $this->build_layout_envelope($post);

        return new WP_REST_Response($layout, 200);
    }

    public function update_page(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id   = (int) $request->get_param('id');
        $post = get_post($id);

        if (!$post || $post->post_type !== 'page') {
            return new WP_Error('not_found', "Page $id not found.", ['status' => 404]);
        }

        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('forbidden', "You do not have permission to edit page $id.", ['status' => 403]);
        }

        $body = $request->get_json_params();
        if (!isset($body['post_content']) || !is_string($body['post_content']) || trim($body['post_content']) === '') {
            return new WP_Error('missing_field', 'Request body must include a non-empty string "post_content".', ['status' => 400]);
        }

        // Validate before saving — this is the safety gate
        $result = (new Validator())->validateContent($body['post_content']);

        if (!$result->isValid()) {
            UsageTracker::log('update_page', $id, 'invalid', count($result->violations()));
            return new WP_REST_Response([
                'saved'      => false,
                'valid'      => false,
                'violations' => array_map(fn($v) => $v->toArray(), $result->violations()),
                'message'    => 'Layout failed validation. Page was NOT updated.',
            ], 422);
        }

        // Validation passed — safe to save
        $updated = wp_update_post([
            'ID'           => $id,
            'post_content' => $body['post_content'],
        ], true);

        if (is_wp_error($updated)) {
            return new WP_Error('update_failed', $updated->get_error_message(), ['status' => 500]);
        }

        UsageTracker::log('update_page', $id, 'valid');

        $post->post_content = $body['post_content'];

        return new WP_REST_Response([
            'saved'      => true,
            'valid'      => true,
            'violations' => [],
            'page'       => $this->build_layout_envelope($post),
        ], 200);
    }

    public function validate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = $request->get_json_params();

        if (!isset($body['post_content']) && !isset($body['layout'])) {
            return new WP_Error('missing_field', 'Request body must include "post_content".', ['status' => 400]);
        }

        // Accept either {post_content:...} or a full layout envelope
        if (isset($body['post_content'])) {
            $result = (new Validator())->validateContent($body['post_content']);
        } else {
            $result = (new Validator())->validate((string) json_encode($body));
        }

        UsageTracker::log('validate', null, $result->isValid() ? 'valid' : 'invalid', count($result->violations()));

        return new WP_REST_Response($result->toArray(), $result->isValid() ? 200 : 422);
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

    public function require_edit_posts(): bool|WP_Error
    {
        // Accept plugin API key (Bearer token) — simpler than Application Passwords
        if (ApiKey::authenticateRequest()) {
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
