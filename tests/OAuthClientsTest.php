<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OAuthClients;
use AiEditorDivi5\WP\OAuthConfig;
use AiEditorDivi5\WP\OAuthStoreInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/** Dynamic Client Registration: what is accepted, what is refused, caps and pruning. */
final class OAuthClientsTest extends OAuthTestCase
{
    public function testRegistrationReturnsAPublicClient(): void
    {
        $r = $this->clients()->register( [ 'redirect_uris' => [ self::CALLBACK ], 'client_name' => 'Claude' ] );

        $this->assertMatchesRegularExpression( '/^aied_cl_[0-9a-f]{24}$/', $r['client_id'] );
        $this->assertSame( 'none', $r['token_endpoint_auth_method'] );
        $this->assertArrayNotHasKey( 'client_secret', $r );
        $this->assertSame( [ self::CALLBACK ], $r['redirect_uris'] );
        $this->assertSame( [ 'authorization_code', 'refresh_token' ], $r['grant_types'] );
        $this->assertSame( $this->now, $r['client_id_issued_at'] );
        $this->assertSame( $r['client_id'], $this->clients()->get( $r['client_id'] )['id'] );
    }

    /** @return array<string,array{0:mixed}> */
    public static function badBodies(): array
    {
        return [
            'not an object'             => [ 'x' ],
            'null'                      => [ null ],
            'no redirect_uris'          => [ [ 'client_name' => 'x' ] ],
            'empty redirect_uris'       => [ [ 'redirect_uris' => [] ] ],
            'redirect_uris not a list'  => [ [ 'redirect_uris' => 'https://claude.ai/api/mcp/auth_callback' ] ],
            'object instead of list'    => [ [ 'redirect_uris' => [ 'a' => 'https://claude.ai/api/mcp/auth_callback' ] ] ],
            'too many'                  => [ [ 'redirect_uris' => array_fill( 0, 6, 'https://claude.ai/api/mcp/auth_callback' ) ] ],
            'non-string uri'            => [ [ 'redirect_uris' => [ 123 ] ] ],
            'foreign host'              => [ [ 'redirect_uris' => [ 'https://evil.example/cb' ] ] ],
            'one bad among good'        => [ [ 'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback', 'https://evil.example/cb' ] ] ],
            'claude.ai wrong path'      => [ [ 'redirect_uris' => [ 'https://claude.ai/other' ] ] ],
            'look-alike host'           => [ [ 'redirect_uris' => [ 'https://claude.ai.evil.example/api/mcp/auth_callback' ] ] ],
            'userinfo trick'            => [ [ 'redirect_uris' => [ 'https://claude.ai@evil.example/api/mcp/auth_callback' ] ] ],
            'http on a real host'       => [ [ 'redirect_uris' => [ 'http://claude.ai/api/mcp/auth_callback' ] ] ],
            'javascript scheme'         => [ [ 'redirect_uris' => [ 'javascript:alert(1)' ] ] ],
            'data scheme'               => [ [ 'redirect_uris' => [ 'data:text/html,x' ] ] ],
            'fragment'                  => [ [ 'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback#x' ] ] ],
            'query'                     => [ [ 'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback?x=1' ] ] ],
            'traversal'                 => [ [ 'redirect_uris' => [ 'https://claude.ai/api/mcp/../auth_callback' ] ] ],
            'secret auth method'        => [ [ 'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ], 'token_endpoint_auth_method' => 'client_secret_basic' ] ],
            'implicit grant'            => [ [ 'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ], 'grant_types' => [ 'implicit' ] ] ],
            'token response'            => [ [ 'redirect_uris' => [ 'https://claude.ai/api/mcp/auth_callback' ], 'response_types' => [ 'token' ] ] ],
        ];
    }

    #[DataProvider('badBodies')]
    public function testBadRegistrationsAreRefusedAndNothingIsStored( mixed $body ): void
    {
        $r = $this->clients()->register( $body );
        $this->assertArrayHasKey( 'error', $r );
        $this->assertArrayNotHasKey( 'client_id', $r );
        $this->assertSame( 0, $this->store->countLive( OAuthStoreInterface::CLIENT, $this->now ) );
    }

    /** @return array<string,array{0:string}> */
    public static function goodRedirects(): array
    {
        return [
            'claude.ai'     => [ 'https://claude.ai/api/mcp/auth_callback' ],
            'claude.com'    => [ 'https://claude.com/api/mcp/auth_callback' ],
            'loopback name' => [ 'http://localhost:3118/callback' ],
            'loopback v4'   => [ 'http://127.0.0.1:8080/callback' ],
            'loopback v6'   => [ 'http://[::1]:5000/callback' ],
            'loopback bare' => [ 'http://localhost/callback' ],
        ];
    }

    #[DataProvider('goodRedirects')]
    public function testAllowedRedirectsRegister( string $uri ): void
    {
        $this->assertArrayHasKey( 'client_id', $this->clients()->register( [ 'redirect_uris' => [ $uri ] ] ) );
    }

    public function testASiteOwnerCanAddAnHttpsHostThroughTheFilter(): void
    {
        $c = new OAuthClients( $this->store, fn(): int => $this->now, null, [ 'Chat.Example.com' ] );
        $this->assertArrayHasKey( 'client_id', $c->register( [ 'redirect_uris' => [ 'https://chat.example.com/oauth/callback' ] ] ) );
        $this->assertArrayHasKey( 'error', $c->register( [ 'redirect_uris' => [ 'http://chat.example.com/oauth/callback' ] ] ), 'http is still refused' );
    }

    public function testDuplicateRedirectsAreCollapsed(): void
    {
        $r = $this->clients()->register( [ 'redirect_uris' => [ self::CALLBACK, self::CALLBACK ] ] );
        $this->assertSame( [ self::CALLBACK ], $r['redirect_uris'] );
    }

    public function testTheNameIsPlainTextAndShort(): void
    {
        $this->assertSame( 'Unnamed app', OAuthClients::cleanName( null ) );
        $this->assertSame( 'Unnamed app', OAuthClients::cleanName( '   ' ) );
        $this->assertSame( 'Unnamed app', OAuthClients::cleanName( [ 'x' ] ) );
        $this->assertDoesNotMatchRegularExpression( '/[<>]/', OAuthClients::cleanName( '<script>alert(1)</script>' ) );
        $this->assertStringNotContainsString( '<', OAuthClients::cleanName( '<img src=x onerror=alert(1)>' ) );
        $this->assertStringNotContainsString( '"', OAuthClients::cleanName( 'a"b' ) );
        $this->assertSame( 80, mb_strlen( OAuthClients::cleanName( str_repeat( 'é', 300 ) ) ) );
        $this->assertSame( 'a b', OAuthClients::cleanName( "a\x00\n\tb" ) );
    }

    public function testRegistrationsAreThrottled(): void
    {
        $r = $this->clients( fn(): bool => false )->register( [ 'redirect_uris' => [ self::CALLBACK ] ] );
        $this->assertSame( 'temporarily_unavailable', $r['error'] );
    }

    public function testAtTheCapTheOldestUnusedClientIsPruned(): void
    {
        $first = null;
        for ( $i = 0; $i < OAuthConfig::MAX_CLIENTS; $i++ ) {
            ++$this->now;
            $r = $this->registerClient();
            $first ??= $r['client_id'];
        }
        $this->assertSame( OAuthConfig::MAX_CLIENTS, $this->store->countLive( OAuthStoreInterface::CLIENT, $this->now ) );

        ++$this->now;
        $new = $this->registerClient();
        $this->assertSame( OAuthConfig::MAX_CLIENTS, $this->store->countLive( OAuthStoreInterface::CLIENT, $this->now ) );
        $this->assertNull( $this->clients()->get( $first ), 'the oldest went' );
        $this->assertNotNull( $this->clients()->get( $new['client_id'] ) );
    }

    public function testAClientWithALiveGrantIsNeverPruned(): void
    {
        $busy = $this->registerClient();
        [ $verifier, $challenge ] = $this->pkce();
        $this->tokens()->issueCode( $busy['client_id'], 7, self::CALLBACK, $challenge, null ); // a live code = a live grant

        for ( $i = 1; $i < OAuthConfig::MAX_CLIENTS; $i++ ) {
            ++$this->now;
            $this->registerClient();
        }
        ++$this->now;
        $this->registerClient(); // over the cap: someone else is pruned

        $this->assertNotNull( $this->clients()->get( $busy['client_id'] ), 'the oldest client survives because it is in use' );
    }

    public function testWhenEveryClientIsInUseRegistrationIsRefused(): void
    {
        [ , $challenge ] = $this->pkce();
        for ( $i = 0; $i < OAuthConfig::MAX_CLIENTS; $i++ ) {
            ++$this->now;
            $c = $this->registerClient();
            $this->tokens()->issueCode( $c['client_id'], 7, self::CALLBACK, $challenge, null );
        }
        $r = $this->clients()->register( [ 'redirect_uris' => [ self::CALLBACK ] ] );
        $this->assertSame( 'temporarily_unavailable', $r['error'] );
    }

    public function testUnknownMalformedAndExpiredClientIdsAreNotFound(): void
    {
        $c = $this->registerClient();
        $this->assertNull( $this->clients()->get( '' ) );
        $this->assertNull( $this->clients()->get( 'aied_cl_' . str_repeat( '0', 24 ) ) );
        $this->assertNull( $this->clients()->get( "' OR 1=1 --" ) );
        $this->now += OAuthConfig::CLIENT_TTL + 1;
        $this->assertNull( $this->clients()->get( $c['client_id'] ) );
    }
}
