<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The two discovery documents Claude reads before it signs a person in.
 *
 * Not advertised on purpose: Client ID Metadata Documents (the server would have to fetch the client's URL,
 * which this plugin never does), client secrets, and any grant other than code + refresh.
 */
final class OAuthMetadata
{
    public function __construct( private readonly OAuthConfig $config )
    {
    }

    /** RFC 8414 authorization server metadata (also served as the OpenID Connect discovery path). @return array<string,mixed> */
    public function authorizationServer(): array
    {
        return [
            'issuer'                                => $this->config->issuer,
            'authorization_endpoint'                => $this->config->authorizeUrl,
            'token_endpoint'                        => $this->config->tokenUrl,
            'registration_endpoint'                 => $this->config->registerUrl,
            'response_types_supported'              => [ 'code' ],
            'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],
            'code_challenge_methods_supported'      => [ 'S256' ],
            'token_endpoint_auth_methods_supported' => [ 'none' ],
            'scopes_supported'                      => [ OAuthConfig::SCOPE, 'offline_access' ],
        ];
    }

    /** RFC 9728 protected resource metadata. `resource` must equal the connector URL exactly. @return array<string,mixed> */
    public function protectedResource(): array
    {
        return [
            'resource'                 => $this->config->resource,
            'authorization_servers'    => [ $this->config->issuer ],
            'scopes_supported'         => [ OAuthConfig::SCOPE ],
            'bearer_methods_supported' => [ 'header' ],
            'resource_name'            => 'JHMG AI Editor for Divi 5',
        ];
    }

    /** The value of the WWW-Authenticate header on a 401 from the plugin's endpoints. */
    public function challenge(): string
    {
        return 'Bearer resource_metadata="' . $this->config->resourceMetadataUrl . '", scope="' . OAuthConfig::SCOPE . '"';
    }
}
