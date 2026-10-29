<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OAuthRedirectPolicy as P;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/OAuthRedirectPolicy.php';

final class OAuthRedirectPolicyTest extends TestCase
{
    public function testExactMatchOnly(): void
    {
        $reg = [ 'https://claude.ai/api/mcp/auth_callback' ];
        $this->assertTrue( P::matches( $reg, 'https://claude.ai/api/mcp/auth_callback' ) );
        foreach ( [ 'https://claude.ai/api/mcp/auth_callback/', 'https://claude.ai/api/mcp/auth_callback?x=1', 'https://CLAUDE.ai/api/mcp/auth_callback', 'http://claude.ai/api/mcp/auth_callback', 'https://claude.ai/api/mcp/auth_callback#x', '', ' https://claude.ai/api/mcp/auth_callback' ] as $bad ) {
            $this->assertFalse( P::matches( $reg, $bad ), $bad );
        }
    }

    public function testLoopbackPortIsIgnoredButNothingElse(): void
    {
        $reg = [ 'http://localhost:3000/callback' ];
        $this->assertTrue( P::matches( $reg, 'http://localhost:51234/callback' ) );
        $this->assertTrue( P::matches( $reg, 'http://localhost/callback' ) );
        $this->assertFalse( P::matches( $reg, 'http://localhost:3000/other' ), 'path must match' );
        $this->assertFalse( P::matches( $reg, 'http://127.0.0.1:3000/callback' ), 'host must match: localhost is not 127.0.0.1' );
        $this->assertFalse( P::matches( $reg, 'https://localhost:3000/callback' ), 'scheme must match' );
        $this->assertFalse( P::matches( $reg, 'http://localhost.evil.example:3000/callback' ) );
        $this->assertFalse( P::matches( $reg, 'http://localhost@evil.example/callback' ) );
    }

    public function testAPortIsNeverIgnoredOnARealHost(): void
    {
        $this->assertFalse( P::matches( [ 'https://claude.ai/api/mcp/auth_callback' ], 'https://claude.ai:8443/api/mcp/auth_callback' ) );
    }

    /** @return array<string,array{0:string}> */
    public static function refused(): array
    {
        return [
            'control character' => [ "https://claude.ai/api/mcp/auth_callback\n" ],
            'space'             => [ 'https://claude.ai/api/mcp/auth_callback ' ],
            'backslash'         => [ 'https://claude.ai\\@evil.example/api/mcp/auth_callback' ],
            'double slash path' => [ 'https://claude.ai//api/mcp/auth_callback' ],
            'dot segment'       => [ 'https://claude.ai/api/./mcp/auth_callback' ],
            'port on hosted'    => [ 'https://claude.ai:444/api/mcp/auth_callback' ],
            'ftp'               => [ 'ftp://claude.ai/api/mcp/auth_callback' ],
            'relative'          => [ '/api/mcp/auth_callback' ],
            'huge'              => [ 'https://claude.ai/' . str_repeat( 'a', 3000 ) ],
            'empty host'        => [ 'https:///api/mcp/auth_callback' ],
            'ipv4 not loopback' => [ 'http://10.0.0.1/callback' ],
            'localhost tld'     => [ 'http://localhost.example/callback' ],
        ];
    }

    #[DataProvider('refused')]
    public function testRefusedForRegistration( string $uri ): void
    {
        $this->assertFalse( P::isAllowedForRegistration( $uri ) );
    }

    /** @return array<string,array{0:string}> */
    public static function chatgptAllowed(): array
    {
        return [
            'stable redirect'      => [ 'https://chatgpt.com/connector_platform_oauth_redirect' ],
            'callback id redirect' => [ 'https://chatgpt.com/connector/oauth/abc123' ],
            'id with dash/under'   => [ 'https://chatgpt.com/connector/oauth/Ab_9-xZ' ],
        ];
    }

    #[DataProvider('chatgptAllowed')]
    public function testChatgptRedirectsAreAcceptedAndMatchExactly( string $uri ): void
    {
        $this->assertTrue( P::isAllowedForRegistration( $uri ), $uri );
        $this->assertTrue( P::matches( [ $uri ], $uri ), $uri );
        $this->assertSame( 'chatgpt.com', P::displayHost( $uri ) );
    }

    /** @return array<string,array{0:string}> */
    public static function chatgptRefused(): array
    {
        return [
            'other path'            => [ 'https://chatgpt.com/api/callback' ],
            'root'                  => [ 'https://chatgpt.com/' ],
            'extra segment stable'  => [ 'https://chatgpt.com/connector_platform_oauth_redirect/x' ],
            'trailing slash stable' => [ 'https://chatgpt.com/connector_platform_oauth_redirect/' ],
            'empty callback id'     => [ 'https://chatgpt.com/connector/oauth/' ],
            'nested callback id'    => [ 'https://chatgpt.com/connector/oauth/a/b' ],
            'dot callback id'       => [ 'https://chatgpt.com/connector/oauth/a.b' ],
            'long callback id'      => [ 'https://chatgpt.com/connector/oauth/' . str_repeat( 'a', 65 ) ],
            'http'                  => [ 'http://chatgpt.com/connector_platform_oauth_redirect' ],
            'port'                  => [ 'https://chatgpt.com:8443/connector_platform_oauth_redirect' ],
            'query'                 => [ 'https://chatgpt.com/connector_platform_oauth_redirect?x=1' ],
            'userinfo'              => [ 'https://evil.example@chatgpt.com/connector_platform_oauth_redirect' ],
            'subdomain'             => [ 'https://evil.chatgpt.com/connector_platform_oauth_redirect' ],
            'lookalike host'        => [ 'https://chatgpt.com.evil.example/connector_platform_oauth_redirect' ],
            'openai.com'            => [ 'https://openai.com/connector_platform_oauth_redirect' ],
        ];
    }

    #[DataProvider('chatgptRefused')]
    public function testOtherChatgptShapedRedirectsAreRefused( string $uri ): void
    {
        $this->assertFalse( P::isAllowedForRegistration( $uri ), $uri );
    }

    public function testASiteOwnerCannotShadowTheBuiltInHostsThroughTheFilter(): void
    {
        $this->assertSame( [], P::cleanHosts( [ 'chatgpt.com', 'claude.ai' ] ) );
    }

    public function testLoopbackAndDisplayHost(): void
    {
        $this->assertTrue( P::isLoopback( 'http://127.0.0.1:1/callback' ) );
        $this->assertFalse( P::isLoopback( 'https://claude.ai/api/mcp/auth_callback' ) );
        $this->assertSame( 'claude.ai', P::displayHost( 'https://claude.ai/api/mcp/auth_callback' ) );
        $this->assertSame( '', P::displayHost( 'javascript:alert(1)' ) );
    }
}
