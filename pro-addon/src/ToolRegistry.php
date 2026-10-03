<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The only place the add-on talks to the free plugin's tool hooks. Tools are advertised and callable only
 * while the licence is unlocked; each call re-checks the unlock and the tool's capability.
 */
final class ToolRegistry
{
    /** @var array<string,ProTool> */
    private array $tools = [];

    /**
     * @param list<ProTool> $tools
     * @param \Closure():bool $unlocked
     */
    public function __construct( array $tools, private \Closure $unlocked )
    {
        foreach ( $tools as $tool ) {
            $this->tools[ $tool->name() ] = $tool;
        }
    }

    /** The tool with this name, or null (no licence check: callers that serve a request check `isUnlocked()`). */
    public function get( string $name ): ?ProTool
    {
        return $this->tools[ $name ] ?? null;
    }

    /** @return list<ProTool> */
    public function tools(): array
    {
        return array_values( $this->tools );
    }

    public function isUnlocked(): bool
    {
        return ( $this->unlocked )();
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys( $this->tools );
    }

    public function register(): void
    {
        add_filter( 'jhmg_aied_mcp_tools', [ $this, 'definitions' ] );
        add_filter( 'jhmg_aied_mcp_call', [ $this, 'claim' ], 10, 4 );
    }

    /** `jhmg_aied_mcp_tools`: append the Pro tool definitions while unlocked. */
    public function definitions( mixed $tools ): mixed
    {
        if ( ! is_array( $tools ) || ! ( $this->unlocked )() ) {
            return $tools;
        }
        foreach ( $this->tools as $tool ) {
            $tools[] = $tool->definition();
        }

        return $tools;
    }

    /**
     * `jhmg_aied_mcp_call`: claim a call for one of our tools, or return `$response` untouched.
     *
     * @param array<string,mixed> $arguments
     */
    public function claim( mixed $response, string $name, array $arguments, mixed $rpcId ): mixed
    {
        if ( null !== $response || ! isset( $this->tools[ $name ] ) || ! ( $this->unlocked )() ) {
            return $response;
        }
        $run = ToolRunner::run( $this->tools[ $name ], $arguments );
        if ( ! $run['ok'] ) {
            return self::error( $rpcId, 500 === $run['status'] ? -32603 : -32602, $run['message'] );
        }
        $payload = $run['payload'];

        return new \WP_REST_Response(
            [ 'jsonrpc' => '2.0', 'id' => $rpcId, 'result' => [ 'content' => [ [ 'type' => 'text', 'text' => wp_json_encode( $payload ) ] ] ] ],
            200
        );
    }

    private static function error( mixed $rpcId, int $code, string $message ): \WP_REST_Response
    {
        return new \WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $rpcId, 'error' => [ 'code' => $code, 'message' => $message ] ], 200 );
    }
}
