<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OAuthTokenEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;

/** The token endpoint end to end over the pure classes: register, approve, exchange, refresh. */
final class OAuthTokenEndpointTest extends OAuthTestCase
{
    private function endpoint(): OAuthTokenEndpoint
    {
        return new OAuthTokenEndpoint( $this->tokens(), $this->clients() );
    }

    public function testTheWholeFlow(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();

        $r = $this->endpoint()->handle( [ 'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'client_id' => $client, 'redirect_uri' => self::CALLBACK, 'resource' => $this->config()->resource ] );
        $this->assertSame( 200, $r['status'] );
        $this->assertSame( 'Bearer', $r['body']['token_type'] );
        $this->assertArrayNotHasKey( 'ok', $r['body'] );
        $this->assertSame( 7, $this->tokens()->authenticate( $r['body']['access_token'] ) );

        $again = $this->endpoint()->handle( [ 'grant_type' => 'refresh_token', 'refresh_token' => $r['body']['refresh_token'], 'client_id' => $client ] );
        $this->assertSame( 200, $again['status'] );
        $this->assertNotSame( $r['body']['refresh_token'], $again['body']['refresh_token'] );
    }

    public function testUnknownGrantTypesAreRefused(): void
    {
        [ $client ] = $this->approvedCode();
        foreach ( [ 'password', 'client_credentials', 'implicit', '', 'AUTHORIZATION_CODE' ] as $grant ) {
            $r = $this->endpoint()->handle( [ 'grant_type' => $grant, 'client_id' => $client ] );
            $this->assertSame( 400, $r['status'], $grant );
            $this->assertSame( 'unsupported_grant_type', $r['body']['error'], $grant );
        }
    }

    public function testAnUnknownClientIsInvalidClient(): void
    {
        $r = $this->endpoint()->handle( [ 'grant_type' => 'authorization_code', 'client_id' => 'aied_cl_' . str_repeat( 'a', 24 ), 'code' => 'x', 'code_verifier' => 'y', 'redirect_uri' => self::CALLBACK ] );
        $this->assertSame( 401, $r['status'] );
        $this->assertSame( 'invalid_client', $r['body']['error'] );
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function malformed(): array
    {
        return [
            'array code'    => [ [ 'grant_type' => 'authorization_code', 'code' => [ 'a' ] ] ],
            'array client'  => [ [ 'grant_type' => 'refresh_token', 'client_id' => [ 'a' ] ] ],
            'int verifier'  => [ [ 'grant_type' => 'authorization_code', 'code_verifier' => 5 ] ],
            'huge redirect' => [ [ 'grant_type' => 'authorization_code', 'redirect_uri' => str_repeat( 'a', 5000 ) ] ],
        ];
    }

    #[DataProvider('malformed')]
    public function testWrongTypesAreRefusedNeverCast( array $params ): void
    {
        $r = $this->endpoint()->handle( $params );
        $this->assertSame( 400, $r['status'] );
        $this->assertSame( 'invalid_request', $r['body']['error'] );
    }

    public function testMissingParametersAreNamed(): void
    {
        [ $client ] = $this->approvedCode();
        $r = $this->endpoint()->handle( [ 'grant_type' => 'authorization_code', 'client_id' => $client ] );
        $this->assertSame( 'invalid_request', $r['body']['error'] );
        $this->assertStringContainsString( 'code', $r['body']['error_description'] );

        $r = $this->endpoint()->handle( [ 'grant_type' => 'refresh_token', 'client_id' => $client ] );
        $this->assertSame( 'invalid_request', $r['body']['error'] );
    }

    public function testTokenErrorsUseTheRfcCodes(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $good = [ 'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'client_id' => $client, 'redirect_uri' => self::CALLBACK ];

        $this->assertSame( 200, $this->endpoint()->handle( $good )['status'] );
        $replay = $this->endpoint()->handle( $good );
        $this->assertSame( 400, $replay['status'] );
        $this->assertSame( 'invalid_grant', $replay['body']['error'] );

        [ $c2, $code2, $v2 ] = $this->approvedCode();
        $bad = $this->endpoint()->handle( [ 'grant_type' => 'authorization_code', 'code' => $code2, 'code_verifier' => $v2, 'client_id' => $c2, 'redirect_uri' => self::CALLBACK, 'resource' => 'https://evil.example/' ] );
        $this->assertSame( 'invalid_target', $bad['body']['error'] );
    }

    public function testErrorBodiesNeverLeakSecrets(): void
    {
        [ $client, $code, $verifier ] = $this->approvedCode();
        $r = $this->endpoint()->handle( [ 'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => 'wrong' . $verifier, 'client_id' => $client, 'redirect_uri' => self::CALLBACK ] );
        $this->assertStringNotContainsString( $code, (string) json_encode( $r ) );
        $this->assertStringNotContainsString( $verifier, (string) json_encode( $r ) );
    }
}
