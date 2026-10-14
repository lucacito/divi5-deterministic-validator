<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OAuthClients;
use AiEditorDivi5\WP\OAuthConfig;
use AiEditorDivi5\WP\OAuthStoreMemory;
use AiEditorDivi5\WP\OAuthTokens;
use PHPUnit\Framework\TestCase;

foreach ( [ 'OAuthConfig', 'OAuthStoreInterface', 'OAuthRedirectPolicy', 'OAuthTokens', 'OAuthClients', 'OAuthMetadata', 'OAuthWellKnown', 'OAuthAuthorizeRequest', 'OAuthConsentPage', 'OAuthTokenEndpoint' ] as $oauthClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $oauthClass . '.php';
}
require_once __DIR__ . '/OAuthStoreMemory.php';

/** Shared fixtures for the OAuth unit tests: a fixed config, an in-memory store and a controllable clock. */
abstract class OAuthTestCase extends TestCase
{
    protected const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

    protected int $now = 1_800_000_000;

    protected OAuthStoreMemory $store;

    /** @var list<int> user ids that "exist" */
    protected array $users = [ 7 ];

    protected function setUp(): void
    {
        $this->store = new OAuthStoreMemory();
    }

    protected function config(): OAuthConfig
    {
        return new OAuthConfig(
            'https://example.com',
            'https://example.com/wp-json/ai-editor-divi5/v1/mcp',
            'https://example.com/wp-admin/admin-post.php?action=aied_oauth_authorize',
            'https://example.com/wp-json/ai-editor-divi5/v1/oauth/token',
            'https://example.com/wp-json/ai-editor-divi5/v1/oauth/register',
            'https://example.com/wp-json/ai-editor-divi5/v1/oauth/resource-metadata',
            ''
        );
    }

    protected function tokens(): OAuthTokens
    {
        return new OAuthTokens(
            $this->store,
            $this->config(),
            fn(): int => $this->now,
            fn( int $id ): bool => in_array( $id, $this->users, true )
        );
    }

    protected function clients( ?\Closure $throttle = null ): OAuthClients
    {
        return new OAuthClients( $this->store, fn(): int => $this->now, $throttle );
    }

    /** @return array<string,mixed> a registered client */
    protected function registerClient( string $redirect = self::CALLBACK, string $name = 'Claude' ): array
    {
        $r = $this->clients()->register( [ 'redirect_uris' => [ $redirect ], 'client_name' => $name ] );
        $this->assertArrayHasKey( 'client_id', $r, 'registration should succeed' );

        return $r;
    }

    /** A PKCE pair: [verifier, S256 challenge]. @return array{0:string,1:string} */
    protected function pkce( string $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ): array
    {
        return [ $verifier, rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ) ];
    }

    /** Runs the first leg: registers a client and returns [clientId, code, verifier]. @return array{0:string,1:string,2:string} */
    protected function approvedCode( int $user = 7, ?string $resource = null ): array
    {
        $client = $this->registerClient();
        [ $verifier, $challenge ] = $this->pkce();
        $code = $this->tokens()->issueCode( $client['client_id'], $user, self::CALLBACK, $challenge, $resource );

        return [ $client['client_id'], $code, $verifier ];
    }
}
