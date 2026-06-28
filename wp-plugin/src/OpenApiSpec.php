<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Serves an OpenAPI 3.1 spec for the plugin REST endpoints.
 *
 * Used by ChatGPT Actions and any OpenAPI-compatible client.
 * Endpoint: GET /wp-json/ai-editor-divi5/v1/openapi.json
 */
final class OpenApiSpec
{
    public function register_routes(): void
    {
        register_rest_route('ai-editor-divi5/v1', '/openapi.json', [
            'methods'             => 'GET',
            'callback'            => [$this, 'serve'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function serve(WP_REST_Request $request): WP_REST_Response
    {
        $base = rtrim(get_site_url(), '/') . '/wp-json/ai-editor-divi5/v1';

        $spec = [
            'openapi' => '3.1.0',
            'info'    => [
                'title'       => 'AI Editor for Divi 5',
                'description' => 'Let your AI assistant read and edit Divi 5 pages with natural language. Every change is validated before saving — broken pages become impossible.',
                'version'     => AI_EDITOR_DIVI5_VERSION,
            ],
            'servers'    => [['url' => $base]],
            'security'   => [['ApiKey' => []]],
            'components' => [
                'securitySchemes' => [
                    'ApiKey' => [
                        'type'        => 'http',
                        'scheme'      => 'bearer',
                        'description' => 'Plugin API key from Settings → AI Editor for Divi 5',
                    ],
                ],
                'schemas' => [
                    'Violation' => [
                        'type'       => 'object',
                        'properties' => [
                            'code'    => ['type' => 'string', 'description' => 'Machine-readable violation code'],
                            'message' => ['type' => 'string', 'description' => 'Human-readable description'],
                            'path'    => ['type' => 'string', 'description' => 'Path to the offending block'],
                        ],
                    ],
                    'PageSummary' => [
                        'type'       => 'object',
                        'properties' => [
                            'id'        => ['type' => 'integer'],
                            'title'     => ['type' => 'string'],
                            'status'    => ['type' => 'string'],
                            'link'      => ['type' => 'string'],
                            'edit_link' => ['type' => 'string'],
                        ],
                    ],
                    'ValidationResult' => [
                        'type'       => 'object',
                        'properties' => [
                            'valid'      => ['type' => 'boolean'],
                            'violations' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Violation']],
                        ],
                    ],
                ],
            ],
            'paths' => [
                '/pages' => [
                    'get' => [
                        'operationId' => 'listDiviPages',
                        'summary'     => 'List all Divi 5 pages',
                        'description' => 'Returns all WordPress pages that use the Divi 5 builder, including their IDs and edit links.',
                        'responses'   => [
                            '200' => [
                                'description' => 'Success',
                                'content'     => ['application/json' => ['schema' => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'pages' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PageSummary']],
                                        'count' => ['type' => 'integer'],
                                    ],
                                ]]],
                            ],
                        ],
                    ],
                    'post' => [
                        'operationId' => 'createPage',
                        'summary'     => 'Create a new page (premium)',
                        'description' => 'PREMIUM: Validates the submitted layout and, if valid, creates a new page as a draft for the site owner to review and publish. Returns 402 if no active license, 422 with violations if the layout is invalid — no page is created in either case. For any image, unless the caller supplies a specific image URL, use https://picsum.photos/seed/{keyword}/{width}/{height} as the src (a stable placeholder per keyword).',
                        'requestBody' => [
                            'required' => true,
                            'content'  => ['application/json' => ['schema' => [
                                'type'       => 'object',
                                'required'   => ['title', 'post_content'],
                                'properties' => [
                                    'title'        => ['type' => 'string', 'description' => 'Page title'],
                                    'post_content' => ['type' => 'string', 'description' => 'Divi 5 Gutenberg block HTML'],
                                    'slug'         => ['type' => 'string', 'description' => 'Optional URL slug for cross-linking'],
                                ],
                            ]]],
                        ],
                        'responses'   => [
                            '201' => ['description' => 'Page created'],
                            '400' => ['description' => 'Missing title or post_content'],
                            '402' => ['description' => 'Premium license required'],
                            '422' => ['description' => 'Validation failed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationResult']]]],
                        ],
                    ],
                ],
                '/pages/{id}' => [
                    'get' => [
                        'operationId' => 'getPageLayout',
                        'summary'     => 'Get a page layout',
                        'description' => 'Returns the full Divi 5 post_content (Gutenberg block HTML) and metadata for a page.',
                        'parameters'  => [self::idParam()],
                        'responses'   => [
                            '200' => ['description' => 'Page layout', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                            '404' => ['description' => 'Page not found'],
                        ],
                    ],
                    'put' => [
                        'operationId' => 'updatePageLayout',
                        'summary'     => 'Validate and save a page layout',
                        'description' => 'Validates the submitted layout and saves it only if all checks pass. Returns 422 with violations if the layout is invalid — the page is NOT updated. For any image, unless the caller supplies a specific image URL, use https://picsum.photos/seed/{keyword}/{width}/{height} as the src (a stable placeholder per keyword).',
                        'parameters'  => [self::idParam()],
                        'requestBody' => self::postContentBody(),
                        'responses'   => [
                            '200' => ['description' => 'Layout saved'],
                            '400' => ['description' => 'Missing post_content'],
                            '404' => ['description' => 'Page not found'],
                            '422' => ['description' => 'Validation failed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationResult']]]],
                        ],
                    ],
                ],
                '/style-guide' => [
                    'get' => [
                        'operationId' => 'getStyleGuide',
                        'summary'     => 'Get the Divi 5 authoring + styling guide',
                        'description' => 'Returns real block structure rules, required content keys, and styling attribute shapes (backgrounds, gradients, spacing, typography, borders, shadows, transforms, hover, animation). Call before building or restyling a layout so the result is styled, not plain.',
                        'responses'   => [
                            '200' => ['description' => 'The guide (Markdown)', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['guide' => ['type' => 'string']]]]]],
                        ],
                    ],
                ],
                '/site-guide' => [
                    'get' => [
                        'operationId' => 'getSiteGuide',
                        'summary'     => 'Get the multi-page site blueprint',
                        'description' => 'Blueprint for building an entire multi-page website from one brief: plan pages, lock one design system, build each page with a slug, cross-link, then set the front page and nav menu.',
                        'responses'   => ['200' => ['description' => 'The blueprint (Markdown)', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['guide' => ['type' => 'string']]]]]]],
                    ],
                ],
                '/front-page' => [
                    'post' => [
                        'operationId' => 'setFrontPage',
                        'summary'     => 'Set the static front page (premium)',
                        'description' => 'PREMIUM: Make a page the site front page.',
                        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['page_id'], 'properties' => ['page_id' => ['type' => 'integer']]]]]],
                        'responses'   => ['200' => ['description' => 'Front page set'], '402' => ['description' => 'Premium required'], '404' => ['description' => 'Page not found']],
                    ],
                ],
                '/primary-menu' => [
                    'post' => [
                        'operationId' => 'setPrimaryMenu',
                        'summary'     => 'Build + assign the primary nav menu (premium)',
                        'description' => 'PREMIUM: Build the Main Menu from items and assign it to the theme primary location.',
                        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['items'], 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'page_id' => ['type' => 'integer'], 'url' => ['type' => 'string']]]]]]]]],
                        'responses'   => ['200' => ['description' => 'Menu built'], '402' => ['description' => 'Premium required']],
                    ],
                ],
                '/section-recipes' => [
                    'get' => [
                        'operationId' => 'getSectionRecipes',
                        'summary'     => 'Get proven section recipes',
                        'description' => 'Library of complete, validated Divi 5 section patterns (hero, feature grids, split, slider, CTA, footer). With no query, returns the catalog of recipe names; pass ?name=<recipe> to get that section\'s full block markup to copy and fill.',
                        'parameters'  => [['name' => 'name', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'description' => 'Recipe name (omit to list all)']]],
                        'responses'   => [
                            '200' => ['description' => 'Catalog or a single recipe', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
                            '404' => ['description' => 'Unknown recipe name'],
                        ],
                    ],
                ],
                '/validate' => [
                    'post' => [
                        'operationId' => 'validateLayout',
                        'summary'     => 'Validate a layout without saving',
                        'description' => 'Runs all validation passes on a Divi 5 post_content string without writing to the database. Safe to call repeatedly.',
                        'requestBody' => self::postContentBody(),
                        'responses'   => [
                            '200' => ['description' => 'Valid layout',   'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationResult']]]],
                            '422' => ['description' => 'Invalid layout', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationResult']]]],
                        ],
                    ],
                ],
            ],
        ];

        return new WP_REST_Response($spec, 200);
    }

    private static function idParam(): array
    {
        return ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'description' => 'WordPress page ID']];
    }

    private static function postContentBody(): array
    {
        return [
            'required' => true,
            'content'  => ['application/json' => ['schema' => [
                'type'       => 'object',
                'required'   => ['post_content'],
                'properties' => ['post_content' => ['type' => 'string', 'description' => 'Divi 5 Gutenberg block HTML']],
            ]]],
        ];
    }
}
