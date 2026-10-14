<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OAuthAuthorizeRequest as A;
use AiEditorDivi5\WP\OAuthConsentPage;
use PHPUnit\Framework\Attributes\DataProvider;

final class OAuthAuthorizeRequestTest extends OAuthTestCase
{
    /** @return array<string,string> a valid request for a freshly registered client */
    private function query( array $override = [] ): array
    {
        $client = $this->registerClient();
        [ , $challenge ] = $this->pkce();

        return array_merge( [
            'response_type'         => 'code',
            'client_id'             => $client['client_id'],
            'redirect_uri'          => self::CALLBACK,
            'state'                 => 'abc123',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'resource'              => 'https://example.com/wp-json/ai-editor-divi5/v1/mcp',
        ], $override );
    }

    private function parse( array $q ): array
    {
        return A::parse( $q, $this->clients(), $this->config() );
    }

    public function testAValidRequestIsAccepted(): void
    {
        $r = $this->parse( $this->query() );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 'Claude', $r['client']['name'] );
        $this->assertSame( 'abc123', $r['state'] );
    }

    public function testTheResourceDefaultsToThisSite(): void
    {
        $r = $this->parse( $this->query( [ 'resource' => '' ] ) );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( $this->config()->resource, $r['resource'] );
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function neverRedirect(): array
    {
        return [
            'unknown client'      => [ [ 'client_id' => 'aied_cl_' . '0000000000000000000000ff' ] ],
            'malformed client id' => [ [ 'client_id' => "x' OR '1'='1" ] ],
            'missing client id'   => [ [ 'client_id' => '' ] ],
            'unregistered uri'    => [ [ 'redirect_uri' => 'https://evil.example/cb' ] ],
            'near-match uri'      => [ [ 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback/' ] ],
            'missing uri'         => [ [ 'redirect_uri' => '' ] ],
            'array param'         => [ [ 'state' => [ 'a' ] ] ],
            'huge param'          => [ [ 'state' => str_repeat( 'a', 3000 ) ] ],
            'state too long'      => [ [ 'state' => str_repeat( 'a', 513 ) ] ],
            'control in state'    => [ [ 'state' => "a\r\nLocation: https://evil.example" ] ],
        ];
    }

    #[DataProvider('neverRedirect')]
    public function testAnUnknownClientOrAddressIsNeverRedirectedTo( array $override ): void
    {
        $r = $this->parse( $this->query( $override ) );
        $this->assertFalse( $r['ok'] );
        $this->assertFalse( $r['redirectable'], 'an open redirect would let anyone bounce people through the site' );
        $this->assertNotSame( '', $r['message'] );
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function redirectable(): array
    {
        return [
            'implicit flow'         => [ [ 'response_type' => 'token' ], 'unsupported_response_type' ],
            'no response type'      => [ [ 'response_type' => '' ], 'unsupported_response_type' ],
            'no challenge'          => [ [ 'code_challenge' => '' ], 'invalid_request' ],
            'plain method'          => [ [ 'code_challenge_method' => 'plain' ], 'invalid_request' ],
            'no method'             => [ [ 'code_challenge_method' => '' ], 'invalid_request' ],
            'short challenge'       => [ [ 'code_challenge' => 'abc' ], 'invalid_request' ],
            'challenge bad chars'   => [ [ 'code_challenge' => str_repeat( '+', 43 ) ], 'invalid_request' ],
            'foreign resource'      => [ [ 'resource' => 'https://evil.example/mcp' ], 'invalid_target' ],
        ];
    }

    #[DataProvider('redirectable')]
    public function testOtherProblemsGoBackToTheRegisteredAddressWithAnError( array $override, string $error ): void
    {
        $r = $this->parse( $this->query( $override ) );
        $this->assertFalse( $r['ok'] );
        $this->assertTrue( $r['redirectable'] );
        $this->assertSame( self::CALLBACK, $r['redirect_uri'] );
        $this->assertSame( 'abc123', $r['state'] );
        $this->assertSame( $error, $r['error'] );
    }

    public function testRedirectUrlBuildsAQueryAndDropsEmptyValues(): void
    {
        $this->assertSame( self::CALLBACK . '?code=c1&state=s%26x', A::redirectUrl( self::CALLBACK, [ 'code' => 'c1', 'state' => 's&x' ] ) );
        $this->assertSame( self::CALLBACK . '?error=access_denied', A::redirectUrl( self::CALLBACK, [ 'error' => 'access_denied', 'state' => '' ] ) );
        $this->assertSame( 'http://localhost:1/callback?code=c', A::redirectUrl( 'http://localhost:1/callback', [ 'code' => 'c' ] ) );
    }

    public function testTheFingerprintChangesWithAnyPart(): void
    {
        $base = A::fingerprint( 'c', 'r', 'ch', 's' );
        $this->assertNotSame( $base, A::fingerprint( 'c2', 'r', 'ch', 's' ) );
        $this->assertNotSame( $base, A::fingerprint( 'c', 'r2', 'ch', 's' ) );
        $this->assertNotSame( $base, A::fingerprint( 'c', 'r', 'ch2', 's' ) );
        $this->assertNotSame( $base, A::fingerprint( 'c', 'r', 'ch', 's2' ) );
        $this->assertNotSame( A::fingerprint( 'ab', 'c', 'd', 'e' ), A::fingerprint( 'a', 'bc', 'd', 'e' ), 'parts cannot be shifted across the boundary' );
    }

    public function testTheConsentPageEscapesEverythingAndShowsWhereItGoes(): void
    {
        $client = $this->registerClient( self::CALLBACK, '<img src=x onerror=alert(1)>"Evil"' );
        $req = $this->parse( $this->query( [ 'client_id' => $client['client_id'], 'state' => '"><script>alert(1)</script>' ] ) );
        $this->assertTrue( $req['ok'] );

        $html = OAuthConsentPage::render( $req, '<b>Admin</b>', 'My <i>Site</i>', 'https://example.com/wp-admin/admin-post.php?action=x&y=1', 'nonce"1' );

        $this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
        $this->assertStringNotContainsString( '<img src=x', $html );
        $this->assertStringNotContainsString( '<b>Admin</b>', $html );
        $this->assertStringContainsString( '&lt;b&gt;Admin&lt;/b&gt;', $html );
        $this->assertStringContainsString( '<strong>claude.ai</strong>', $html, 'the redirect host is shown' );
        $this->assertStringNotContainsString( 'This app is running on your own computer', $html );
        $this->assertStringContainsString( 'name="decision" value="approve"', $html );
        $this->assertStringContainsString( 'name="decision" value="deny"', $html );
        $this->assertStringNotContainsString( '<script', $html, 'no scripts at all' );
        $this->assertStringNotContainsString( 'http://', str_replace( 'http://www.w3.org', '', $html ), 'no external or insecure references' );
    }

    public function testALoopbackRedirectGetsAWarning(): void
    {
        $client = $this->registerClient( 'http://localhost:3118/callback', 'Claude Code' );
        $req = $this->parse( $this->query( [ 'client_id' => $client['client_id'], 'redirect_uri' => 'http://localhost:55555/callback' ] ) );
        $this->assertTrue( $req['ok'] );
        $html = OAuthConsentPage::render( $req, 'Admin', 'Site', 'https://example.com/post', 'n' );
        $this->assertStringContainsString( 'running on your own computer', $html );
        $this->assertStringContainsString( '<strong>localhost</strong>', $html );
    }

    public function testTheErrorPageEscapes(): void
    {
        $html = OAuthConsentPage::error( '<script>alert(1)</script>' );
        $this->assertStringNotContainsString( '<script>alert', $html );
        $this->assertStringContainsString( 'Nothing was shared', $html );
    }
}
