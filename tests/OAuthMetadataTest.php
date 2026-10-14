<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OAuthMetadata;

final class OAuthMetadataTest extends OAuthTestCase
{
    public function testTheAuthorizationServerDocumentCarriesWhatClaudeNeeds(): void
    {
        $d = ( new OAuthMetadata( $this->config() ) )->authorizationServer();

        $this->assertSame( 'https://example.com', $d['issuer'] );
        $this->assertSame( $this->config()->authorizeUrl, $d['authorization_endpoint'] );
        $this->assertSame( $this->config()->tokenUrl, $d['token_endpoint'] );
        $this->assertSame( $this->config()->registerUrl, $d['registration_endpoint'] );
        $this->assertSame( [ 'S256' ], $d['code_challenge_methods_supported'], 'Claude refuses to continue without S256 advertised' );
        $this->assertSame( [ 'code' ], $d['response_types_supported'] );
        $this->assertSame( [ 'authorization_code', 'refresh_token' ], $d['grant_types_supported'] );
        $this->assertSame( [ 'none' ], $d['token_endpoint_auth_methods_supported'] );
        $this->assertContains( 'offline_access', $d['scopes_supported'], 'so Claude asks for a refresh token' );
    }

    public function testClientIdMetadataDocumentsAreNotAdvertised(): void
    {
        $d = ( new OAuthMetadata( $this->config() ) )->authorizationServer();
        $this->assertArrayNotHasKey( 'client_id_metadata_document_supported', $d, 'it would need the server to fetch a URL' );
    }

    public function testEveryEndpointIsHttps(): void
    {
        $d = ( new OAuthMetadata( $this->config() ) )->authorizationServer();
        foreach ( [ 'issuer', 'authorization_endpoint', 'token_endpoint', 'registration_endpoint' ] as $k ) {
            $this->assertStringStartsWith( 'https://', $d[ $k ], $k );
        }
    }

    public function testTheProtectedResourceNamesTheConnectorUrlExactly(): void
    {
        $d = ( new OAuthMetadata( $this->config() ) )->protectedResource();
        $this->assertSame( 'https://example.com/wp-json/ai-editor-divi5/v1/mcp', $d['resource'] );
        $this->assertSame( [ 'https://example.com' ], $d['authorization_servers'], 'Claude uses only the first entry' );
        $this->assertSame( [ 'header' ], $d['bearer_methods_supported'] );
    }

    public function testTheChallengeHeader(): void
    {
        $h = ( new OAuthMetadata( $this->config() ) )->challenge();
        $this->assertSame( 'Bearer resource_metadata="https://example.com/wp-json/ai-editor-divi5/v1/oauth/resource-metadata", scope="aied:edit"', $h );
        $this->assertStringNotContainsString( "\n", $h );
    }

    public function testBothDocumentsEncodeAsJson(): void
    {
        $m = new OAuthMetadata( $this->config() );
        $this->assertIsString( json_encode( $m->authorizationServer(), JSON_THROW_ON_ERROR ) );
        $this->assertIsString( json_encode( $m->protectedResource(), JSON_THROW_ON_ERROR ) );
    }
}
