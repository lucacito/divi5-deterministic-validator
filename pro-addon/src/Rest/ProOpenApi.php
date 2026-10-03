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
    private const SUMMARY_MAX = 120;

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
                    '401' => [ 'description' => 'Not authenticated: use the plugin API key or an Application Password' ],
                    '403' => [ 'description' => 'The user lacks the permission this tool needs, or the Pro tools are not unlocked' ],
                    '500' => [ 'description' => 'The tool failed unexpectedly; nothing is leaked, so try again or check the site', 'content' => [ 'application/json' => [ 'schema' => [ '$ref' => '#/components/schemas/ProError' ] ] ] ],
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

    /** At most 120 characters: the first sentence, cut at a word boundary, never inside brackets or quotes. */
    public static function summary( string $description ): string
    {
        $first = trim( self::firstSentence( trim( (string) preg_replace( '/\s+/', ' ', $description ) ) ) );
        if ( strlen( $first ) <= self::SUMMARY_MAX ) {
            return $first;
        }
        $cut = substr( $first, 0, self::SUMMARY_MAX - 3 );
        if ( ' ' !== $first[ self::SUMMARY_MAX - 3 ] ) {
            $space = strrpos( $cut, ' ' );
            $cut   = false === $space || 0 === $space ? $cut : substr( $cut, 0, $space );
        }
        // Drop a fragment that opens a bracket or a quote and never closes it.
        $open = self::unclosedAt( $cut );
        while ( null !== $open && $open > 0 ) {
            $cut  = substr( $cut, 0, $open );
            $open = self::unclosedAt( rtrim( $cut ) );
        }
        $cut = rtrim( $cut, " ,;:-" );

        return ( '' === $cut || null !== self::unclosedAt( $cut ) ? substr( $first, 0, self::SUMMARY_MAX - 3 ) : $cut ) . '...';
    }

    /** The text before the first full stop, question mark or exclamation mark outside brackets (an ellipsis is not one). */
    private static function firstSentence( string $text ): string
    {
        $depth = 0;
        $len   = strlen( $text );
        for ( $i = 0; $i < $len; $i++ ) {
            $c = $text[ $i ];
            if ( '(' === $c ) {
                ++$depth;
            } elseif ( ')' === $c && $depth > 0 ) {
                --$depth;
            } elseif ( 0 === $depth && ( '.' === $c || '!' === $c || '?' === $c ) && ( 0 === $i || '.' !== $text[ $i - 1 ] ) && ( $i + 1 === $len || ' ' === $text[ $i + 1 ] ) ) {
                return substr( $text, 0, $i );
            }
        }

        return $text;
    }

    /** Where the outermost unclosed "(" starts, else the last quote of an odd number of them, else null. */
    private static function unclosedAt( string $text ): ?int
    {
        $stack = [];
        $len   = strlen( $text );
        for ( $i = 0; $i < $len; $i++ ) {
            if ( '(' === $text[ $i ] ) {
                $stack[] = $i;
            } elseif ( ')' === $text[ $i ] ) {
                array_pop( $stack );
            }
        }
        if ( [] !== $stack ) {
            return $stack[0];
        }
        if ( 1 === substr_count( $text, '"' ) % 2 ) {
            return (int) strrpos( $text, '"' );
        }

        return null;
    }
}
