<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Rest;

use AiEditorDivi5\Pro\ToolRegistry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ChatGPT/OpenAPI face of every Pro tool, generated from each tool's own definition so it cannot drift from the MCP
 * and REST faces. One operation per tool (the importer caps a spec at 30 operations in total; the free plugin has 16),
 * every description at most 300 characters, and no object schema without properties.
 */
final class ProOpenApi
{
    public function __construct( private ToolRegistry $registry )
    {
    }

    public function register(): void
    {
        add_filter( 'jhmg_aied_openapi_paths', [ $this, 'paths' ], 10, 2 );
        add_filter( 'jhmg_aied_openapi_schemas', [ $this, 'schemas' ] );
    }

    /** @param mixed $paths @return mixed */
    public function paths( mixed $paths, mixed $base = '' ): mixed
    {
        if ( ! is_array( $paths ) || ! $this->registry->isUnlocked() ) {
            return $paths;
        }
        foreach ( $this->registry->tools() as $tool ) {
            $def    = $tool->definition();
            $schema = self::requestSchema( $def['inputSchema'] );
            $op     = [
                'operationId' => self::operationId( $def['name'] ),
                'summary'     => self::summary( $def['description'] ),
                'description' => $def['description'],
                'requestBody' => [
                    'required' => [] !== ( $def['inputSchema']['required'] ?? [] ),
                    'content'  => [ 'application/json' => [ 'schema' => $schema ] ],
                ],
                'responses'   => [
                    '200' => [ 'description' => 'Success', 'content' => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/ProResult' ] ] ] ],
                    '400' => [ 'description' => 'A missing or invalid argument (the message says which)', 'content' => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/ProError' ] ] ] ],
                    '403' => [ 'description' => 'The user lacks the permission this tool needs, or the Pro tools are not unlocked' ],
                ],
            ];
            $paths[ ProRest::PREFIX . $def['name'] ] = [ 'post' => $op ];
        }

        return $paths;
    }

    /** @param mixed $schemas @return mixed */
    public function schemas( mixed $schemas ): mixed
    {
        if ( ! is_array( $schemas ) || ! $this->registry->isUnlocked() ) {
            return $schemas;
        }
        $schemas['ProResult'] = [
            'type'                 => 'object',
            'description'          => 'What a Pro tool returns. Write tools add a batch (id, undoable) that undo_batch accepts.',
            'properties'           => [
                'changed' => [ 'type' => 'boolean', 'description' => 'True if something was changed' ],
                'dry_run' => [ 'type' => 'boolean', 'description' => 'True for a preview' ],
                'token'   => [ 'type' => 'string', 'description' => 'On a preview of a site-wide change: pass it back to apply that change' ],
                'batch'   => [ 'type' => 'object', 'description' => 'The recorded change (id, undoable)', 'additionalProperties' => true ],
            ],
            'additionalProperties' => true,
        ];
        $schemas['ProError'] = [
            'type'       => 'object',
            'properties' => [
                'code'    => [ 'type' => 'string' ],
                'message' => [ 'type' => 'string', 'description' => 'Safe to show to the user' ],
            ],
        ];

        return $schemas;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private static function requestSchema( array $input ): array
    {
        $props  = is_array( $input['properties'] ?? null ) ? $input['properties'] : [];
        $schema = [ 'type' => 'object', 'properties' => $props ];
        if ( [] === $props ) {
            $schema['additionalProperties'] = true;
        }
        if ( [] !== ( $input['required'] ?? [] ) ) {
            $schema['required'] = array_values( $input['required'] );
        }

        return $schema;
    }

    private static function operationId( string $toolName ): string
    {
        return lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $toolName ) ) ) );
    }

    private static function summary( string $description ): string
    {
        $first = trim( (string) preg_replace( '/\s+/', ' ', strtok( $description, '.' ) ?: $description ) );

        return strlen( $first ) > 120 ? substr( $first, 0, 117 ) . '...' : $first;
    }
}
