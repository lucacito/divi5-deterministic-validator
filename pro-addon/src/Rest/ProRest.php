<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Rest;

use AiEditorDivi5\Pro\ToolRegistry;
use AiEditorDivi5\Pro\ToolRunner;
use AiEditorDivi5\WP\RestController;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The REST face of every Pro tool, generated from the same tool list as the MCP face: `POST /pro/<tool_name>` with the
 * tool's arguments as the JSON body. Authentication is the free plugin's own (`RestController::require_edit_posts`:
 * plugin API key, Application Password or a logged-in editor); the tool's own capability, argument checks and error
 * mapping are `ToolRunner`, shared with MCP. While the licence is locked no route is registered, and a licence
 * revoked after registration stops a call at request time.
 */
final class ProRest
{
    public const PREFIX = '/pro/';

    public function __construct( private ToolRegistry $registry )
    {
    }

    public function register(): void
    {
        add_action( 'jhmg_aied_register_rest_routes', [ $this, 'routes' ] );
    }

    /** `jhmg_aied_register_rest_routes`: one POST route per Pro tool, only while unlocked. */
    public function routes( mixed $namespace ): void
    {
        if ( ! is_string( $namespace ) || ! $this->registry->isUnlocked() ) {
            return;
        }
        foreach ( $this->registry->tools() as $tool ) {
            $name = $tool->name();
            register_rest_route( $namespace, self::PREFIX . $name, [
                'methods'             => 'POST',
                'callback'            => fn ( $request ) => $this->handle( $name, $request ),
                'permission_callback' => [ $this, 'authorize' ],
            ] );
        }
    }

    /** @return bool|\WP_Error */
    public function authorize(): mixed
    {
        if ( ! $this->registry->isUnlocked() ) {
            return new \WP_Error( 'pro_locked', 'The Pro tools are not unlocked on this site.', [ 'status' => 403 ] );
        }

        return ( new RestController() )->require_edit_posts();
    }

    /** @return \WP_REST_Response|\WP_Error */
    public function handle( string $name, \WP_REST_Request $request ): mixed
    {
        $tool = $this->registry->get( $name );
        if ( null === $tool || ! $this->registry->isUnlocked() ) {
            return new \WP_Error( 'pro_locked', 'The Pro tools are not unlocked on this site.', [ 'status' => 403 ] );
        }
        $body = $request->get_json_params();
        // A body WordPress did not parse as JSON (sent as a form, or as text/plain) must not run the tool with its defaults.
        if ( null === $body && '' !== trim( (string) $request->get_body() ) ) {
            return new \WP_Error( 'invalid_argument', 'The request body must be a JSON object of arguments, sent with Content-Type: application/json.', [ 'status' => 400 ] );
        }
        if ( null !== $body && ( ! is_array( $body ) || ( [] !== $body && array_is_list( $body ) ) ) ) {
            return new \WP_Error( 'invalid_argument', 'The request body must be a JSON object of arguments.', [ 'status' => 400 ] );
        }
        $run = ToolRunner::run( $tool, $body ?? [] );
        if ( ! $run['ok'] ) {
            return new \WP_Error( $run['code'], $run['message'], [ 'status' => $run['status'] ] );
        }

        return new \WP_REST_Response( $run['payload'], 200 );
    }
}
