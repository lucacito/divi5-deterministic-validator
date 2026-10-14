<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The token endpoint's rules (RFC 6749 section 3.2 and 5): form parameters in, { status, body } out.
 * Public clients only; the proof of possession is the PKCE verifier. The REST glue adds the no-store headers.
 */
final class OAuthTokenEndpoint
{
    public function __construct( private readonly OAuthTokens $tokens, private readonly OAuthClients $clients )
    {
    }

    /**
     * @param array<string,mixed> $params
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handle( array $params ): array
    {
        foreach ( [ 'grant_type', 'client_id', 'code', 'code_verifier', 'redirect_uri', 'refresh_token', 'resource' ] as $field ) {
            if ( isset( $params[ $field ] ) && ( ! is_string( $params[ $field ] ) || strlen( $params[ $field ] ) > 2000 ) ) {
                return $this->error( 400, 'invalid_request', 'Parameter ' . $field . ' is malformed.' );
            }
        }
        $grant    = (string) ( $params['grant_type'] ?? '' );
        $clientId = (string) ( $params['client_id'] ?? '' );
        $resource = isset( $params['resource'] ) && '' !== $params['resource'] ? (string) $params['resource'] : null;

        if ( 'authorization_code' !== $grant && 'refresh_token' !== $grant ) {
            return $this->error( 400, 'unsupported_grant_type', 'Only authorization_code and refresh_token are supported.' );
        }
        if ( null === $this->clients->get( $clientId ) ) {
            return $this->error( 401, 'invalid_client', 'The client is not registered.' );
        }

        if ( 'authorization_code' === $grant ) {
            foreach ( [ 'code', 'code_verifier', 'redirect_uri' ] as $required ) {
                if ( '' === (string) ( $params[ $required ] ?? '' ) ) {
                    return $this->error( 400, 'invalid_request', 'Parameter ' . $required . ' is required.' );
                }
            }
            $result = $this->tokens->exchangeCode( (string) $params['code'], (string) $params['code_verifier'], $clientId, (string) $params['redirect_uri'], $resource );
        } else {
            if ( '' === (string) ( $params['refresh_token'] ?? '' ) ) {
                return $this->error( 400, 'invalid_request', 'Parameter refresh_token is required.' );
            }
            $result = $this->tokens->refresh( (string) $params['refresh_token'], $clientId, $resource );
        }

        if ( true !== ( $result['ok'] ?? false ) ) {
            $status = 'server_error' === $result['error'] ? 500 : 400;

            return $this->error( $status, (string) $result['error'], (string) $result['description'] );
        }
        unset( $result['ok'] );

        return [ 'status' => 200, 'body' => $result ];
    }

    /** @return array{status:int,body:array<string,string>} */
    private function error( int $status, string $code, string $description ): array
    {
        return [ 'status' => $status, 'body' => [ 'error' => $code, 'error_description' => $description ] ];
    }
}
