<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\ProTool;

require_once __DIR__ . '/../src/ProTool.php';

/** A configurable stand-in for a Pro tool. */
final class FakeTool implements ProTool
{
    public int $runs = 0;

    /** @param array<string,mixed> $payload */
    public function __construct(
        private string $name = 'fake_pro_tool',
        private string $capability = 'manage_options',
        private ?\Throwable $throws = null,
        private array $payload = [ 'ok' => true ]
    ) {
    }

    public function name(): string { return $this->name; }

    public function capability(): string { return $this->capability; }

    public function definition(): array
    {
        return [ 'name' => $this->name, 'description' => 'A fake Pro tool.', 'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ] ];
    }

    public function run( array $arguments ): array
    {
        $this->runs++;
        if ( null !== $this->throws ) {
            throw $this->throws;
        }

        return $this->payload + [ 'args' => $arguments ];
    }
}
