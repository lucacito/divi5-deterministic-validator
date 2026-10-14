<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use AiEditorDivi5\WP\OAuthConfig;
use AiEditorDivi5\WP\OAuthStoreInterface;
use AiEditorDivi5\WP\OAuthTokens;

/** The security core: codes, PKCE, single use, rotation, replay revocation, audience, expiry. */
final class OAuthTokensTest extends OAuthTestCase
{
    private function exchange( string $client, string $code, string $verifier, ?string $resource = null ): array
    {
        return $this->tokens()->exchangeCode( $code, $verifier, $client, self::CALLBACK, $resource );
    }

    public function testACodeBecomesATokenPair(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $r = $this->exchange( $client, $code, $verifier );

        $this->assertTrue( $r['ok'] );
        $this->assertStringStartsWith( OAuthConfig::ACCESS_PREFIX, $r['access_token'] );
        $this->assertStringStartsWith( OAuthConfig::REFRESH_PREFIX, $r['refresh_token'] );
        $this->assertSame( 'Bearer', $r['token_type'] );
        $this->assertSame( 3600, $r['expires_in'] );
        $this->assertSame( OAuthConfig::SCOPE, $r['scope'] );
        $this->assertSame( 7, $this->tokens()->authenticate( $r['access_token'] ) );
    }

    public function testSecretsAreNeverStored(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $r = $this->exchange( $client, $code, $verifier );
        $dump = json_encode( ( new \ReflectionProperty( $this->store, 'rows' ) )->getValue( $this->store ) );

        foreach ( [ $code, $r['access_token'], $r['refresh_token'] ] as $secret ) {
            $this->assertStringNotContainsString( substr( $secret, 8 ), (string) $dump, 'only hashes may be stored' );
        }
    }

    public function testACodeWorksOnlyOnce(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $first = $this->exchange( $client, $code, $verifier );
        $this->assertTrue( $first['ok'] );

        $second = $this->exchange( $client, $code, $verifier );
        $this->assertFalse( $second['ok'] );
        $this->assertSame( 'invalid_grant', $second['error'] );
    }

    public function testReplayingACodeEndsTheWholeGrant(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $first = $this->exchange( $client, $code, $verifier );
        $this->assertSame( 7, $this->tokens()->authenticate( $first['access_token'] ) );

        $this->exchange( $client, $code, $verifier ); // the replay

        $this->assertNull( $this->tokens()->authenticate( $first['access_token'] ), 'the access token from that code dies' );
        $this->assertFalse( $this->tokens()->refresh( $first['refresh_token'], $client, null )['ok'], 'and so does its refresh token' );
    }

    public function testAWrongVerifierBurnsTheCode(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();

        $bad = $this->exchange( $client, $code, str_repeat( 'a', 43 ) );
        $this->assertSame( 'invalid_grant', $bad['error'] );

        $this->assertFalse( $this->exchange( $client, $code, $verifier )['ok'], 'the right verifier no longer helps: no guessing' );
    }

    /** @return array<string,array{0:string}> */
    public static function badVerifiers(): array
    {
        return [
            'empty'              => [ '' ],
            'too short'          => [ str_repeat( 'a', 42 ) ],
            'too long'           => [ str_repeat( 'a', 129 ) ],
            'bad characters'     => [ str_repeat( 'a', 42 ) . '+' ],
            'the challenge itself (plain method)' => [ 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' ],
        ];
    }

    #[DataProvider('badVerifiers')]
    public function testMalformedOrPlainVerifiersAreRefused( string $verifier ): void
    {
        [ $client, $code ] = $this->approvedCode();
        $this->assertFalse( $this->exchange( $client, $code, $verifier )['ok'] );
    }

    public function testThePkceVectorFromRfc7636AppendixB(): void
    {
        $this->assertTrue( OAuthTokens::verifierMatches( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' ) );
        $this->assertFalse( OAuthTokens::verifierMatches( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXl', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' ) );
        $this->assertFalse( OAuthTokens::verifierMatches( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', '' ) );
    }

    public function testACodeIsBoundToItsClientAndRedirectUri(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $other = $this->registerClient();

        $this->assertFalse( $this->tokens()->exchangeCode( $code, $verifier, $other['client_id'], self::CALLBACK, null )['ok'], 'another client' );
        $this->assertFalse( $this->tokens()->exchangeCode( $code, $verifier, $client, 'https://claude.com/api/mcp/auth_callback', null )['ok'], 'another redirect' );
        $this->assertTrue( $this->tokens()->exchangeCode( $code, $verifier, $client, self::CALLBACK, null )['ok'], 'the right pair still works' );
    }

    public function testACodeExpiresAfterTenMinutes(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $this->now += 601;
        $this->assertSame( 'invalid_grant', $this->exchange( $client, $code, $verifier )['error'] );
    }

    public function testMalformedAndUnknownCodesAreRefused(): void
    {
        [ $client, , $verifier ] = $this->approvedCode();
        foreach ( [ '', 'x', 'aied_ac_', 'aied_ac_zzzz', 'aied_ac_' . str_repeat( '0', 64 ), 'aied_at_' . str_repeat( 'a', 64 ) ] as $code ) {
            $this->assertSame( 'invalid_grant', $this->exchange( $client, $code, $verifier )['error'], $code );
        }
    }

    public function testResourceMustBeThisSite(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $r = $this->exchange( $client, $code, $verifier, 'https://evil.example/mcp' );
        $this->assertSame( 'invalid_target', $r['error'] );
    }

    public function testResourceWithATrailingSlashIsAccepted(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $this->assertTrue( $this->exchange( $client, $code, $verifier, 'https://example.com/wp-json/ai-editor-divi5/v1/mcp/' )['ok'] );
    }

    public function testRefreshRotatesAndTheOldTokenStopsWorking(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $first = $this->exchange( $client, $code, $verifier );

        $second = $this->tokens()->refresh( $first['refresh_token'], $client, null );
        $this->assertTrue( $second['ok'] );
        $this->assertNotSame( $first['refresh_token'], $second['refresh_token'] );
        $this->assertSame( 7, $this->tokens()->authenticate( $second['access_token'] ) );
    }

    public function testReplayingARotatedRefreshTokenEndsTheWholeGrant(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $first  = $this->exchange( $client, $code, $verifier );
        $second = $this->tokens()->refresh( $first['refresh_token'], $client, null );

        $replay = $this->tokens()->refresh( $first['refresh_token'], $client, null );
        $this->assertSame( 'invalid_grant', $replay['error'] );

        $this->assertNull( $this->tokens()->authenticate( $second['access_token'] ), 'the newest access token dies too' );
        $this->assertFalse( $this->tokens()->refresh( $second['refresh_token'], $client, null )['ok'], 'and the newest refresh token' );
    }

    public function testRefreshIsBoundToItsClient(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $first = $this->exchange( $client, $code, $verifier );
        $other = $this->registerClient();

        $this->assertFalse( $this->tokens()->refresh( $first['refresh_token'], $other['client_id'], null )['ok'] );
        $this->assertTrue( $this->tokens()->refresh( $first['refresh_token'], $client, null )['ok'], 'a wrong client does not burn the token' );
    }

    public function testRefreshTokensExpireAfterThirtyDays(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $first = $this->exchange( $client, $code, $verifier );
        $this->now += 30 * 86400 + 1;
        $this->assertSame( 'invalid_grant', $this->tokens()->refresh( $first['refresh_token'], $client, null )['error'] );
    }

    public function testAnAccessTokenExpiresAfterAnHour(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $r = $this->exchange( $client, $code, $verifier );
        $this->now += 3599;
        $this->assertSame( 7, $this->tokens()->authenticate( $r['access_token'] ) );
        $this->now += 2;
        $this->assertNull( $this->tokens()->authenticate( $r['access_token'] ) );
    }

    public function testARefreshTokenIsNotAnAccessToken(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $r = $this->exchange( $client, $code, $verifier );
        $this->assertNull( $this->tokens()->authenticate( $r['refresh_token'] ) );
        $this->assertNull( $this->tokens()->authenticate( $code ) );
    }

    public function testAnAccessTokenForAnotherResourceIsNeverAccepted(): void
    {
        // A row as another service on the same database might have written it.
        $secret = bin2hex( random_bytes( 32 ) );
        $this->store->insert( OAuthStoreInterface::ACCESS, hash( 'sha256', $secret ), [ 'client_id' => 'c', 'user_id' => 7, 'family' => 'f', 'data' => [ 'resource' => 'https://other.example/mcp' ] ], $this->now + 100, $this->now );
        $this->assertNull( $this->tokens()->authenticate( OAuthConfig::ACCESS_PREFIX . $secret ) );

        $secret2 = bin2hex( random_bytes( 32 ) );
        $this->store->insert( OAuthStoreInterface::ACCESS, hash( 'sha256', $secret2 ), [ 'client_id' => 'c', 'user_id' => 7, 'family' => 'f', 'data' => [] ], $this->now + 100, $this->now );
        $this->assertNull( $this->tokens()->authenticate( OAuthConfig::ACCESS_PREFIX . $secret2 ), 'no resource at all is not good enough' );
    }

    public function testADeletedUserCannotUseOrRefreshTokens(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $r = $this->exchange( $client, $code, $verifier );
        $this->users = [];

        $this->assertNull( $this->tokens()->authenticate( $r['access_token'] ) );
        $this->assertFalse( $this->tokens()->refresh( $r['refresh_token'], $client, null )['ok'] );
    }

    public function testRevokingAFamilyKillsOnlyThatGrant(): void
    {
        [ $c1, $code1, $v ] = $this->approvedCode();
        $a = $this->exchange( $c1, $code1, $v );
        [ $c2, $code2 ] = $this->approvedCode();
        $b = $this->exchange( $c2, $code2, $v );

        $family = $this->store->liveGrants( $this->now, 10 );
        $this->assertCount( 2, $family );
        $this->tokens()->revokeFamily( $family[0]['family'] );

        $alive = array_filter( [ $this->tokens()->authenticate( $a['access_token'] ), $this->tokens()->authenticate( $b['access_token'] ) ] );
        $this->assertCount( 1, $alive );
    }

    public function testRevokeUserAndRevokeAll(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $r = $this->exchange( $client, $code, $verifier );
        $this->tokens()->revokeUser( 7 );
        $this->assertNull( $this->tokens()->authenticate( $r['access_token'] ) );

        [ $client2, $code2 ] = $this->approvedCode();
        $r2 = $this->exchange( $client2, $code2, $verifier );
        $this->tokens()->revokeAll();
        $this->assertNull( $this->tokens()->authenticate( $r2['access_token'] ) );
        $this->assertNull( $this->clients()->get( $client2 ), 'revoking everything also forgets the clients' );
    }

    public function testRandomnessAndPrefixes(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $this->assertMatchesRegularExpression( '/^aied_ac_[0-9a-f]{64}$/', $code );
        $r = $this->exchange( $client, $code, $verifier );
        $this->assertMatchesRegularExpression( '/^aied_at_[0-9a-f]{64}$/', $r['access_token'] );
        $this->assertMatchesRegularExpression( '/^aied_rt_[0-9a-f]{64}$/', $r['refresh_token'] );
    }

    public function testTheSourceMakesNoRemoteCalls(): void
    {
        foreach ( glob( __DIR__ . '/../wp-plugin/src/OAuth*.php' ) as $file ) {
            $this->assertDoesNotMatchRegularExpression( '/wp_remote_|curl_init|file_get_contents\(\s*[\'"]http|fsockopen|stream_socket_client/', (string) file_get_contents( $file ), basename( $file ) );
        }
    }
}
