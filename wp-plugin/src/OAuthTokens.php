<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The life of an OAuth grant: authorization codes, access tokens and rotating refresh tokens.
 *
 * Every secret is 32 random bytes, handed out once, and stored only as its SHA-256. A grant is a "family"
 * (code, access token and refresh token share one family id): replaying a used code or a used refresh token
 * deletes the whole family, so a stolen copy cannot outlive the first reuse (OAuth 2.1, section 4.3.1).
 *
 * Failures return an RFC 6749 error code, never an exception, and never say which check failed beyond that.
 *
 * @phpstan-type Tokens array{ok:true,access_token:string,refresh_token:string,token_type:string,expires_in:int,scope:string}
 * @phpstan-type Failure array{ok:false,error:string,description:string}
 */
final class OAuthTokens
{
    /** @var \Closure():int */
    private \Closure $clock;

    /** @var \Closure(int):bool */
    private \Closure $userExists;

    /**
     * @param ?\Closure():int    $clock      unix time (tests inject a fake)
     * @param ?\Closure(int):bool $userExists whether the user id is a real user
     */
    public function __construct(
        private readonly OAuthStoreInterface $store,
        private readonly OAuthConfig $config,
        ?\Closure $clock = null,
        ?\Closure $userExists = null
    ) {
        $this->clock      = $clock ?? static fn(): int => time();
        $this->userExists = $userExists ?? static fn( int $id ): bool => $id > 0;
    }

    /**
     * Issues a one-time code after the signed-in user approved. Returns the code to put in the redirect.
     * The grant family starts here.
     */
    public function issueCode( string $clientId, int $userId, string $redirectUri, string $codeChallenge, ?string $resource ): string
    {
        $now    = ( $this->clock )();
        $secret = bin2hex( random_bytes( 32 ) );
        $family = bin2hex( random_bytes( 16 ) );

        $this->store->insert(
            OAuthStoreInterface::CODE,
            hash( 'sha256', $secret ),
            [
                'client_id' => $clientId,
                'user_id'   => $userId,
                'family'    => $family,
                'data'      => [
                    'redirect_uri'   => $redirectUri,
                    'code_challenge' => $codeChallenge,
                    'resource'       => $resource ?? $this->config->resource,
                ],
            ],
            $now + OAuthConfig::CODE_TTL,
            $now
        );

        return OAuthConfig::CODE_PREFIX . $secret;
    }

    /**
     * Trades a code for tokens. Needs the PKCE verifier, the same client and the same redirect URI.
     *
     * @return array<string,mixed> Tokens or Failure
     */
    public function exchangeCode( string $code, string $verifier, string $clientId, string $redirectUri, ?string $resource ): array
    {
        $hash = $this->hashOf( $code, OAuthConfig::CODE_PREFIX );
        $row  = null === $hash ? null : $this->store->find( OAuthStoreInterface::CODE, $hash );
        if ( null === $row ) {
            return $this->fail( 'invalid_grant', 'The code is not valid.' );
        }
        $now = ( $this->clock )();

        if ( null !== $row['used_at'] ) {
            // A code is single use. Seeing it again means it leaked: end the whole grant.
            $this->store->revokeFamily( (string) $row['family'] );

            return $this->fail( 'invalid_grant', 'The code was already used.' );
        }
        if ( $row['expires_at'] <= $now ) {
            return $this->fail( 'invalid_grant', 'The code has expired.' );
        }
        if ( ! hash_equals( (string) $row['client_id'], $clientId ) || ! hash_equals( (string) ( $row['data']['redirect_uri'] ?? '' ), $redirectUri ) ) {
            return $this->fail( 'invalid_grant', 'The code was issued to a different client or redirect address.' );
        }
        if ( null !== $resource && ! $this->config->isOurResource( $resource ) ) {
            return $this->fail( 'invalid_target', 'The resource is not this site.' );
        }

        // A wrong verifier burns the code, so it cannot be guessed at.
        $pkceOk = self::verifierMatches( $verifier, (string) ( $row['data']['code_challenge'] ?? '' ) );
        if ( ! $this->store->consume( OAuthStoreInterface::CODE, $hash, $now ) ) {
            $this->store->revokeFamily( (string) $row['family'] );

            return $this->fail( 'invalid_grant', 'The code was already used.' );
        }
        if ( ! $pkceOk ) {
            return $this->fail( 'invalid_grant', 'The code verifier does not match.' );
        }

        return $this->issuePair( $clientId, (int) $row['user_id'], (string) $row['family'], (string) ( $row['data']['resource'] ?? $this->config->resource ), $now );
    }

    /**
     * Trades a refresh token for a new pair. The old refresh token stops working at once (rotation).
     *
     * @return array<string,mixed> Tokens or Failure
     */
    public function refresh( string $refreshToken, string $clientId, ?string $resource ): array
    {
        $hash = $this->hashOf( $refreshToken, OAuthConfig::REFRESH_PREFIX );
        $row  = null === $hash ? null : $this->store->find( OAuthStoreInterface::REFRESH, $hash );
        if ( null === $row ) {
            return $this->fail( 'invalid_grant', 'The refresh token is not valid.' );
        }
        $now = ( $this->clock )();

        if ( null !== $row['used_at'] ) {
            // A rotated refresh token came back: someone kept a copy. End the whole grant.
            $this->store->revokeFamily( (string) $row['family'] );

            return $this->fail( 'invalid_grant', 'The refresh token was already used.' );
        }
        if ( $row['expires_at'] <= $now ) {
            return $this->fail( 'invalid_grant', 'The refresh token has expired.' );
        }
        if ( ! hash_equals( (string) $row['client_id'], $clientId ) ) {
            return $this->fail( 'invalid_grant', 'The refresh token was issued to a different client.' );
        }
        if ( null !== $resource && ! $this->config->isOurResource( $resource ) ) {
            return $this->fail( 'invalid_target', 'The resource is not this site.' );
        }
        if ( ! $this->userExists->__invoke( (int) $row['user_id'] ) ) {
            $this->store->revokeFamily( (string) $row['family'] );

            return $this->fail( 'invalid_grant', 'The refresh token is not valid.' );
        }
        if ( ! $this->store->consume( OAuthStoreInterface::REFRESH, $hash, $now ) ) {
            $this->store->revokeFamily( (string) $row['family'] );

            return $this->fail( 'invalid_grant', 'The refresh token was already used.' );
        }

        return $this->issuePair( $clientId, (int) $row['user_id'], (string) $row['family'], (string) ( $row['data']['resource'] ?? $this->config->resource ), $now );
    }

    /**
     * The user id behind an access token, or null. The token must exist, be unexpired, have been issued for
     * this site's MCP address, and its user must still exist. The caller still checks what that user may do.
     */
    public function authenticate( string $accessToken ): ?int
    {
        $hash = $this->hashOf( $accessToken, OAuthConfig::ACCESS_PREFIX );
        $row  = null === $hash ? null : $this->store->find( OAuthStoreInterface::ACCESS, $hash );
        if ( null === $row || $row['expires_at'] <= ( $this->clock )() ) {
            return null;
        }
        $resource = $row['data']['resource'] ?? null;
        if ( ! is_string( $resource ) || ! $this->config->isOurResource( $resource ) ) {
            return null;
        }
        $userId = (int) $row['user_id'];

        return $userId > 0 && $this->userExists->__invoke( $userId ) ? $userId : null;
    }

    /** Ends one grant (the "Revoke" button). */
    public function revokeFamily( string $family ): void
    {
        $this->store->revokeFamily( $family );
    }

    public function revokeUser( int $userId ): void
    {
        $this->store->revokeUser( $userId );
    }

    public function revokeAll(): void
    {
        $this->store->revokeAll();
    }

    /** True when $verifier is a well-formed PKCE verifier whose S256 hash is $challenge. */
    public static function verifierMatches( string $verifier, string $challenge ): bool
    {
        if ( 1 !== preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier ) || 1 !== preg_match( '/^[A-Za-z0-9\-_]{43}$/', $challenge ) ) {
            return false;
        }
        $computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

        return hash_equals( $challenge, $computed );
    }

    /** @return array<string,mixed> */
    private function issuePair( string $clientId, int $userId, string $family, string $resource, int $now ): array
    {
        $access  = bin2hex( random_bytes( 32 ) );
        $refresh = bin2hex( random_bytes( 32 ) );
        $data    = [ 'resource' => $resource, 'scope' => OAuthConfig::SCOPE ];

        $ok = $this->store->insert(
            OAuthStoreInterface::ACCESS,
            hash( 'sha256', $access ),
            [ 'client_id' => $clientId, 'user_id' => $userId, 'family' => $family, 'data' => $data ],
            $now + OAuthConfig::ACCESS_TTL,
            $now
        ) && $this->store->insert(
            OAuthStoreInterface::REFRESH,
            hash( 'sha256', $refresh ),
            [ 'client_id' => $clientId, 'user_id' => $userId, 'family' => $family, 'data' => $data ],
            $now + OAuthConfig::REFRESH_TTL,
            $now
        );
        if ( ! $ok ) {
            $this->store->revokeFamily( $family );

            return $this->fail( 'server_error', 'The tokens could not be stored.' );
        }

        return [
            'ok'            => true,
            'access_token'  => OAuthConfig::ACCESS_PREFIX . $access,
            'refresh_token' => OAuthConfig::REFRESH_PREFIX . $refresh,
            'token_type'    => 'Bearer',
            'expires_in'    => OAuthConfig::ACCESS_TTL,
            'scope'         => OAuthConfig::SCOPE,
        ];
    }

    /** SHA-256 of the secret part of a prefixed token, or null when it is not a well-formed token of that kind. */
    private function hashOf( string $token, string $prefix ): ?string
    {
        if ( ! str_starts_with( $token, $prefix ) ) {
            return null;
        }
        $secret = substr( $token, strlen( $prefix ) );

        return 1 === preg_match( '/^[0-9a-f]{64}$/', $secret ) ? hash( 'sha256', $secret ) : null;
    }

    /** @return array{ok:false,error:string,description:string} */
    private function fail( string $error, string $description ): array
    {
        return [ 'ok' => false, 'error' => $error, 'description' => $description ];
    }
}
