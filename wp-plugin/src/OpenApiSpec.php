<?php

declare(strict_types=1);

namespace Divi5Validator\WP;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Serves an OpenAPI 3.1 spec for the validator REST endpoints.
 *
 * Used by ChatGPT Actions and any OpenAPI-compatible client.
 * Endpoint: GET /wp-json/divi5-validator/v1/openapi.json
 */
final class OpenApiSpec
{
    public function register_routes(): void
    {
        register_rest_route('divi5-validator/v1', '/openapi.json', [
            'methods'             => 'GET',
            'callback'            => [$this, 'serve'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function serve(WP_REST_Request $request): WP_REST_Response
    {
        $base = rtrim(get_site_url(), '/') . '/wp-json/divi5-validator/v1';

        $spec = [
            'openapi' => '3.1.0',
            'info'    => [
                'title'       => 'Divi 5 Deterministic Validator',
                'description' => 'Validates Divi 5 layouts and safely updates WordPress pages. Prevents AI agents from saving broken layouts.',
                'version'     => DIVI5_VALIDATOR_VERSION,
            ],
            'servers'    => [['url' => $base]],
            'security'   => [['ApiKey' => []]],
            'components' => [
                'securitySchemes' => [
                    'ApiKey' => [
                        'type'        => 'http',
                        'scheme'      => 'bearer',
                        'description' => 'Plugin API key from Settings → Divi 5 Validator',
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
                        'description' => 'Validates the submitted layout and saves it only if all checks pass. Returns 422 with violations if the layout is invalid — the page is NOT updated.',
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
