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

    public function testLoopbackAndDisplayHost(): void
    {
        $this->assertTrue( P::isLoopback( 'http://127.0.0.1:1/callback' ) );
        $this->assertFalse( P::isLoopback( 'https://claude.ai/api/mcp/auth_callback' ) );
        $this->assertSame( 'claude.ai', P::displayHost( 'https://claude.ai/api/mcp/auth_callback' ) );
        $this->assertSame( '', P::displayHost( 'javascript:alert(1)' ) );
    }
}
