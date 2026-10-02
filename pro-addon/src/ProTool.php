<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One Pro MCP tool. The registry owns advertising, the licence gate, the capability check and the
 * JSON-RPC shapes; a tool only declares itself and does its work.
 */
interface ProTool
{
    /** The MCP tool name. It must not equal a built-in tool name (the free plugin drops such a collision). */
    public function name(): string;

    /** The WordPress capability the current user needs (the free plugin only authenticates; the add-on authorises). */
    public function capability(): string;

    /** @return array{name:string, description:string, inputSchema:array<string,mixed>} */
    public function definition(): array;

    /**
     * Do the work. Type-check every argument: throw \InvalidArgumentException with a message that is safe to
     * show the client for a bad argument. Any other throwable is reported as a generic internal error.
     *
     * @param array<string,mixed> $arguments
     * @return array<string,mixed> the result payload (JSON-encoded by the registry)
     */
    public function run( array $arguments ): array;
}
