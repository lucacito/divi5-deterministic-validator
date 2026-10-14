<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Dynamic Client Registration (RFC 7591) for the plugin's own sign-in, plus lookup of registered clients.
 *
 * Every client is a public client (no secret; PKCE protects the code). Registering grants nothing by itself:
 * a person still has to sign in to WordPress and press Approve on a screen that names the client and shows
 * where it will be sent. That is why registration can be open, and why the redirect addresses are limited
 * (OAuthRedirectPolicy), the count is capped and the rate is throttled.
 */
final class OAuthClients
{
    /** @var \Closure():int */
    private \Closure $clock;

    /**
     * @param ?\Closure():int      $clock    unix time
     * @param ?\Closure():bool     $throttle returns false when registrations are coming too fast
     * @param list<string>         $extraRedirectHosts https hosts the site owner added (filter jhmg_aied_oauth_redirect_hosts)
     */
    public function __construct(
        private readonly OAuthStoreInterface $store,
        ?\Closure $clock = null,
        private readonly ?\Closure $throttle = null,
        private readonly array $extraRedirectHosts = []
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * @param mixed $body the decoded JSON body of the registration request
     * @return array<string,mixed> The RFC 7591 client information response, or { error, error_description }
     */
    public function register( mixed $body ): array
    {
        if ( ! is_array( $body ) ) {
            return $this->error( 'invalid_client_metadata', 'The request body must be a JSON object.' );
        }
        if ( null !== $this->throttle && false === ( $this->throttle )() ) {
            return $this->error( 'temporarily_unavailable', 'Too many registrations. Try again later.' );
        }

        $uris = $body['redirect_uris'] ?? null;
        if ( ! is_array( $uris ) || array() === $uris || count( $uris ) > OAuthConfig::MAX_REDIRECT_URIS || ! array_is_list( $uris ) ) {
            return $this->error( 'invalid_redirect_uri', 'redirect_uris must be a list of one to five addresses.' );
        }
        $clean = [];
        foreach ( $uris as $uri ) {
            if ( ! is_string( $uri ) || ! OAuthRedirectPolicy::isAllowedForRegistration( $uri, $this->extraRedirectHosts ) ) {
                return $this->error( 'invalid_redirect_uri', 'A redirect address is not allowed. Only the Claude apps and apps on this computer can sign in.' );
            }
            $clean[] = $uri;
        }
        $clean = array_values( array_unique( $clean ) );

        $method = $body['token_endpoint_auth_method'] ?? 'none';
        if ( 'none' !== $method ) {
            return $this->error( 'invalid_client_metadata', 'Only token_endpoint_auth_method "none" is supported (PKCE is required instead).' );
        }
        foreach ( [ 'grant_types' => [ 'authorization_code', 'refresh_token' ], 'response_types' => [ 'code' ] ] as $field => $allowed ) {
            if ( isset( $body[ $field ] ) ) {
                if ( ! is_array( $body[ $field ] ) || array_diff( $body[ $field ], $allowed ) !== array() ) {
                    return $this->error( 'invalid_client_metadata', $field . ' contains a value that is not supported.' );
                }
            }
        }

        $name = self::cleanName( $body['client_name'] ?? null );
        $now  = ( $this->clock )();

        if ( $this->store->countLive( OAuthStoreInterface::CLIENT, $now ) >= OAuthConfig::MAX_CLIENTS && ! $this->makeRoom( $now ) ) {
            return $this->error( 'temporarily_unavailable', 'Too many apps are connected. Remove one on the Settings screen and try again.' );
        }

        $clientId = 'aied_cl_' . bin2hex( random_bytes( 12 ) );
        $stored   = $this->store->insert(
            OAuthStoreInterface::CLIENT,
            hash( 'sha256', $clientId ),
            [ 'client_id' => $clientId, 'data' => [ 'name' => $name, 'redirect_uris' => $clean ] ],
            $now + OAuthConfig::CLIENT_TTL,
            $now
        );
        if ( ! $stored ) {
            return $this->error( 'server_error', 'The registration could not be stored.' );
        }

        return [
            'client_id'                  => $clientId,
            'client_id_issued_at'        => $now,
            'client_name'                => $name,
            'redirect_uris'              => $clean,
            'token_endpoint_auth_method' => 'none',
            'grant_types'                => [ 'authorization_code', 'refresh_token' ],
            'response_types'             => [ 'code' ],
            'scope'                      => OAuthConfig::SCOPE,
        ];
    }

    /**
     * @return ?array{id:string,name:string,redirect_uris:list<string>}
     */
    public function get( string $clientId ): ?array
    {
        if ( 1 !== preg_match( '/^aied_cl_[0-9a-f]{24}$/', $clientId ) ) {
            return null;
        }
        $row = $this->store->find( OAuthStoreInterface::CLIENT, hash( 'sha256', $clientId ) );
        if ( null === $row || $row['expires_at'] <= ( $this->clock )() ) {
            return null;
        }
        $uris = $row['data']['redirect_uris'] ?? [];

        return [
            'id'            => $clientId,
            'name'          => (string) ( $row['data']['name'] ?? '' ),
            'redirect_uris' => is_array( $uris ) ? array_values( array_filter( $uris, 'is_string' ) ) : [],
        ];
    }

    /** Plain text only: no markup, no control characters, at most 80 characters. */
    public static function cleanName( mixed $name ): string
    {
        $name = is_string( $name ) ? $name : '';
        $name = (string) preg_replace( '/[\x00-\x1f\x7f<>"\'&`\\\\]+/u', ' ', $name );
        $name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
        if ( function_exists( 'mb_substr' ) ) {
            $name = mb_substr( $name, 0, 80 );
        } else {
            $name = substr( $name, 0, 80 );
        }

        return '' === $name ? 'Unnamed app' : $name;
    }

    /** Deletes the oldest client with no live grant. False when every client is in use. */
    private function makeRoom( int $now ): bool
    {
        $busy = array_flip( $this->store->clientIdsWithLiveGrants( $now ) );
        foreach ( $this->store->clientsOldestFirst( OAuthConfig::MAX_CLIENTS ) as $row ) {
            if ( ! isset( $busy[ $row['client_id'] ] ) ) {
                $this->store->revokeClient( (string) $row['client_id'] );

                return true;
            }
        }

        return false;
    }

    /** @return array{error:string,error_description:string} */
    private function error( string $code, string $description ): array
    {
        return [ 'error' => $code, 'error_description' => $description ];
    }
}
