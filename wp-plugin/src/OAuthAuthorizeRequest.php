<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validation of an authorization request (the address Claude opens in the person's browser).
 *
 * Two kinds of failure, as RFC 6749 section 4.1.2.1 requires:
 *  - the client or redirect address is unknown or not registered: NEVER redirect (that would be an open
 *    redirector); show the person an error page instead;
 *  - anything else wrong: send the person back to the registered redirect address with an `error`.
 */
final class OAuthAuthorizeRequest
{
    private const FIELDS = [ 'response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'resource', 'scope' ];

    /**
     * @param array<string,mixed> $query the raw request parameters
     * @return array<string,mixed> { ok:true, ... } | { ok:false, redirectable:false, message } | { ok:false, redirectable:true, redirect_uri, state, error, description }
     */
    public static function parse( array $query, OAuthClients $clients, OAuthConfig $config ): array
    {
        $p = [];
        foreach ( self::FIELDS as $field ) {
            $v = $query[ $field ] ?? '';
            if ( ! is_string( $v ) || strlen( $v ) > 2000 ) {
                return self::page( 'The sign-in request is malformed.' );
            }
            $p[ $field ] = $v;
        }

        $client = $clients->get( $p['client_id'] );
        if ( null === $client ) {
            return self::page( 'This app is not registered with this site. Remove the connector and add it again.' );
        }
        if ( '' === $p['redirect_uri'] || ! OAuthRedirectPolicy::matches( $client['redirect_uris'], $p['redirect_uri'] ) ) {
            return self::page( 'The address this app wants to return to is not the one it registered, so the sign-in was stopped.' );
        }

        $state = $p['state'];
        if ( strlen( $state ) > 512 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $state ) ) {
            return self::page( 'The sign-in request is malformed.' );
        }
        $back = static fn( string $error, string $description ): array => [
            'ok'           => false,
            'redirectable' => true,
            'redirect_uri' => $p['redirect_uri'],
            'state'        => $state,
            'error'        => $error,
            'description'  => $description,
        ];

        if ( 'code' !== $p['response_type'] ) {
            return $back( 'unsupported_response_type', 'Only response_type=code is supported.' );
        }
        if ( 'S256' !== $p['code_challenge_method'] || 1 !== preg_match( '/^[A-Za-z0-9\-_]{43}$/', $p['code_challenge'] ) ) {
            return $back( 'invalid_request', 'PKCE with code_challenge_method=S256 is required.' );
        }
        if ( '' !== $p['resource'] && ! $config->isOurResource( $p['resource'] ) ) {
            return $back( 'invalid_target', 'The resource is not this site.' );
        }

        return [
            'ok'             => true,
            'client'         => $client,
            'redirect_uri'   => $p['redirect_uri'],
            'state'          => $state,
            'code_challenge' => $p['code_challenge'],
            'resource'       => '' === $p['resource'] ? $config->resource : $p['resource'],
        ];
    }

    /** The address to send the person to after approving or denying. @param array<string,string> $params */
    public static function redirectUrl( string $redirectUri, array $params ): string
    {
        $params = array_filter( $params, static fn( string $v ): bool => '' !== $v );

        return $redirectUri . ( str_contains( $redirectUri, '?' ) ? '&' : '?' ) . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
    }

    /** A stable fingerprint of the request the person is shown, so an approval cannot be reused for another request. */
    public static function fingerprint( string $clientId, string $redirectUri, string $codeChallenge, string $state ): string
    {
        return hash( 'sha256', implode( "\n", [ $clientId, $redirectUri, $codeChallenge, $state ] ) );
    }

    /** @return array{ok:false,redirectable:false,message:string} */
    private static function page( string $message ): array
    {
        return [ 'ok' => false, 'redirectable' => false, 'message' => $message ];
    }
}
