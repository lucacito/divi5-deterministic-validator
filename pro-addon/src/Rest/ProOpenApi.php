<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Rest;

use AiEditorDivi5\Pro\ToolRegistry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ChatGPT/OpenAPI face of every Pro tool, generated from each tool's own definition so it cannot drift from the MCP
 * and REST faces. One operation per tool, except the tools listed in MCP_REST_ONLY (the importer caps a spec at 30 operations in
 * total; the free plugin has 16 and the first 14 Pro tools use the rest), every description at most 300 characters, and no
 * object schema without properties.
 */
final class ProOpenApi
{
    private const SUMMARY_MAX = 120;

    /** Tools that exist over MCP and REST only: the 16 free + 14 Pro operations already use all 30 the ChatGPT importer accepts. */
    public const MCP_REST_ONLY = [ 'manage_global_colors', 'manage_global_variables', 'manage_global_presets', 'set_custom_fields', 'create_product', 'update_products', 'bulk_adjust_prices' ];

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
            if ( in_array( $def['name'], self::MCP_REST_ONLY, true ) ) {
                continue;
            }
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

    /** At most 120 bytes: the first sentence, cut at a word boundary, never ending inside brackets or quotes, always valid UTF-8. */
    public static function summary( string $description ): string
    {
        $text  = trim( (string) preg_replace( '/\s+/', ' ', $description ) );
        $first = trim( self::firstSentence( $text ) );
        if ( '' === $first ) {
            $first = $text;
        }
        $ellipsis = false;
        // Every pass only makes the text shorter, so this ends: cut to fit, drop an unclosed fragment, check again.
        for ( ;; ) {
            if ( strlen( $first ) + ( $ellipsis ? 3 : 0 ) > self::SUMMARY_MAX ) {
                $first    = self::wordCut( $first, self::SUMMARY_MAX - 3 );
                $ellipsis = true;
            }
            $clean = self::closeOff( $first );
            if ( $clean === $first ) {
                break;
            }
            $first    = $clean;
            $ellipsis = true;
        }

        return '' === $first ? '' : $first . ( $ellipsis ? '...' : '' );
    }

    /** At most $max bytes, valid UTF-8, ending at a word boundary where there is one. */
    private static function wordCut( string $text, int $max ): string
    {
        $head = self::utf8Prefix( substr( $text, 0, $max ) );
        if ( ' ' !== substr( $text, strlen( $head ), 1 ) ) {
            $space = strrpos( $head, ' ' );
            if ( false !== $space && $space > 0 ) {
                $head = substr( $head, 0, $space );
            }
        }

        return rtrim( $head, " ,;:-" );
    }

    /** Drops every fragment that opens a bracket or a quote and never closes it (at the very start: just that character). */
    private static function closeOff( string $text ): string
    {
        for ( $open = self::unclosedAt( $text ); null !== $open; $open = self::unclosedAt( $text ) ) {
            $text = rtrim( trim( 0 === $open ? substr( $text, 1 ) : substr( $text, 0, $open ) ), " ,;:-" );
        }

        return $text;
    }

    /** The longest prefix that is valid UTF-8 (a multibyte character is never split). */
    private static function utf8Prefix( string $text ): string
    {
        while ( '' !== $text && 1 !== preg_match( '//u', $text ) ) {
            $text = substr( $text, 0, -1 );
        }

        return $text;
    }

    /** The text before the first full stop, question mark or exclamation mark outside brackets and quotes (an ellipsis is not one). */
    private static function firstSentence( string $text ): string
    {
        $depth = 0;
        $quote = false;
        $len   = strlen( $text );
        for ( $i = 0; $i < $len; $i++ ) {
            $c = $text[ $i ];
            if ( '"' === $c ) {
                $quote = ! $quote;
            } elseif ( '(' === $c && ! $quote ) {
                ++$depth;
            } elseif ( ')' === $c && ! $quote && $depth > 0 ) {
                --$depth;
            } elseif ( 0 === $depth && ! $quote && $i > 0 && ( '.' === $c || '!' === $c || '?' === $c ) && '.' !== $text[ $i - 1 ] && ( $i + 1 === $len || ' ' === $text[ $i + 1 ] ) ) {
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
