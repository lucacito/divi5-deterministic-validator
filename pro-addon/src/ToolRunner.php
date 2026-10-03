<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one place a Pro tool is actually called: capability check, the call, and the mapping of what it throws to a
 * transport-neutral failure. The MCP registry and the REST routes both go through it, so a tool cannot be reachable
 * over one transport with weaker rules than the other.
 */
final class ToolRunner
{
    /**
     * @param array<string,mixed> $arguments
     * @return array{ok:true, payload:array<string,mixed>}|array{ok:false, status:int, code:string, message:string}
     */
    public static function run( ProTool $tool, array $arguments ): array
    {
        $name = $tool->name();
        if ( ! current_user_can( $tool->capability() ) ) {
            return self::fail( 403, 'forbidden', "You do not have permission to use {$name}." );
        }
        try {
            return [ 'ok' => true, 'payload' => $tool->run( $arguments ) ];
        } catch ( \InvalidArgumentException $e ) {
            // The message is written to be shown to the client.
            return self::fail( 400, 'invalid_argument', $e->getMessage() );
        } catch ( \Throwable $e ) {
            return self::fail( 500, 'tool_failed', 'The tool failed unexpectedly.' );
        }
    }

    /** @return array{ok:false, status:int, code:string, message:string} */
    private static function fail( int $status, string $code, string $message ): array
    {
        return [ 'ok' => false, 'status' => $status, 'code' => $code, 'message' => $message ];
    }
}
