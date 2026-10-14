<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Everything the OAuth sign-in adds to a running site, registered only while the owner has it switched on:
 * the three REST routes (register, token, resource metadata), the well-known documents at the site root, the
 * WWW-Authenticate header on the plugin's 401 answers and the sign-in page.
 */
final class OAuthRoutes
{
    private const NAMESPACE = 'ai-editor-divi5/v1';

    public static function register(): void
    {
        if ( ! OAuthSettings::enabled() ) {
            return;
        }
        add_action( 'plugins_loaded', static function (): void {
            OAuthTable::ensureReady();
        } );
        add_action( 'rest_api_init', [ self::class, 'registerRoutes' ] );
        add_action( 'init', [ self::class, 'serveWellKnown' ], 0 );
        add_filter( 'rest_post_dispatch', [ self::class, 'addChallenge' ], 10, 3 );
        add_action( 'admin_post_nopriv_aied_oauth_authorize', [ OAuthAuthorizeHandler::class, 'handle' ] );
        add_action( 'admin_post_aied_oauth_authorize', [ OAuthAuthorizeHandler::class, 'handle' ] );
    }

    public static function registerRoutes(): void
    {
        // Public by design: these are the doors a client uses before it has any credential. What they accept is
        // checked inside (registered clients, PKCE, single-use codes), and a person must still approve in WordPress.
        register_rest_route( self::NAMESPACE, '/oauth/register', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'register_client' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( self::NAMESPACE, '/oauth/token', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'token' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( self::NAMESPACE, '/oauth/resource-metadata', [
            'methods'             => 'GET',
            'callback'            => static fn(): WP_REST_Response => self::json( OAuthRuntime::metadata()->protectedResource(), 200, false ),
            'permission_callback' => '__return_true',
        ] );
    }

    public static function register_client( WP_REST_Request $request ): WP_REST_Response
    {
        $result = OAuthRuntime::clients()->register( $request->get_json_params() );
        if ( isset( $result['error'] ) ) {
            return self::json( $result, 'temporarily_unavailable' === $result['error'] ? 503 : ( 'server_error' === $result['error'] ? 500 : 400 ) );
        }

        return self::json( $result, 201 );
    }

    public static function token( WP_REST_Request $request ): WP_REST_Response
    {
        // The form body (RFC 6749 requires it) and, for clients that send JSON, the JSON body. Never the query string.
        $params = array_merge( (array) $request->get_body_params(), (array) $request->get_json_params() );
        $out    = ( new OAuthTokenEndpoint( OAuthRuntime::tokens(), OAuthRuntime::clients() ) )->handle( $params );

        return self::json( $out['body'], $out['status'] );
    }

    /** Answers GET and HEAD for the well-known documents, before WordPress routes the request anywhere. */
    public static function serveWellKnown(): void
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
        if ( 'GET' !== $method && 'HEAD' !== $method ) {
            return;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        $path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
        $type = OAuthWellKnown::match( rawurldecode( $path ), OAuthRuntime::config()->homePath );
        if ( null === $type ) {
            return;
        }
        $meta = OAuthRuntime::metadata();
        $doc  = OAuthWellKnown::AUTH_SERVER === $type ? $meta->authorizationServer() : $meta->protectedResource();

        status_header( 200 );
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Cache-Control: no-store' );
        header( 'Access-Control-Allow-Origin: *' );
        header( 'X-Content-Type-Options: nosniff' );
        if ( 'HEAD' !== $method ) {
            echo wp_json_encode( $doc, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
        }
        exit;
    }

    /**
     * Adds the WWW-Authenticate challenge to 401 answers of the plugin's own endpoints (not the OAuth routes), so
     * a client that has no token yet learns where to sign in.
     *
     * @param mixed $response
     * @return mixed
     */
    public static function addChallenge( $response, $server, $request )
    {
        if ( $response instanceof WP_REST_Response && $request instanceof WP_REST_Request && 401 === $response->get_status() ) {
            $route = $request->get_route();
            if ( str_starts_with( $route, '/' . self::NAMESPACE . '/' ) && ! str_starts_with( $route, '/' . self::NAMESPACE . '/oauth/' ) ) {
                $response->header( 'WWW-Authenticate', OAuthRuntime::metadata()->challenge() );
            }
        }

        return $response;
    }

    /** @param array<string,mixed> $body */
    private static function json( array $body, int $status, bool $noStore = true ): WP_REST_Response
    {
        $response = new WP_REST_Response( $body, $status );
        if ( $noStore ) {
            $response->header( 'Cache-Control', 'no-store' );
            $response->header( 'Pragma', 'no-cache' );
        }

        return $response;
    }
}
