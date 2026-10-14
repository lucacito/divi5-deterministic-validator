<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\OAuthWellKnown as W;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/OAuthWellKnown.php';

final class OAuthWellKnownTest extends TestCase
{
    /** @return array<string,array{0:string,1:string,2:?string}> */
    public static function cases(): array
    {
        $as = W::AUTH_SERVER;
        $pr = W::PROTECTED_RESOURCE;

        return [
            'root: AS'                         => [ '/.well-known/oauth-authorization-server', '', $as ],
            'root: AS trailing slash'          => [ '/.well-known/oauth-authorization-server/', '', $as ],
            'root: openid-configuration'       => [ '/.well-known/openid-configuration', '', $as ],
            'root: PRM'                        => [ '/.well-known/oauth-protected-resource', '', $pr ],
            'root: PRM path-aware'             => [ '/.well-known/oauth-protected-resource/wp-json/ai-editor-divi5/v1/mcp', '', $pr ],
            'root: unrelated suffix'           => [ '/.well-known/oauth-protected-resource/anything', '', null ],
            'root: prefix trick'               => [ '/.well-known/oauth-protected-resourceXYZ', '', null ],
            'root: AS with junk'               => [ '/.well-known/oauth-authorization-server/x', '', null ],
            'root: other well-known'           => [ '/.well-known/acme-challenge/token', '', null ],
            'root: security.txt'               => [ '/.well-known/security.txt', '', null ],
            'root: homepage'                   => [ '/', '', null ],
            'root: empty'                      => [ '', '', null ],
            'root: wp-json'                    => [ '/wp-json/ai-editor-divi5/v1/mcp', '', null ],
            'root: case matters'               => [ '/.well-known/OAuth-Authorization-Server', '', null ],
            'sub: openid under the site path'  => [ '/blog/.well-known/openid-configuration', '/blog', $as ],
            'sub: AS under the site path'      => [ '/blog/.well-known/oauth-authorization-server', '/blog', $as ],
            'sub: root form reaching WP'       => [ '/.well-known/oauth-authorization-server/blog', '/blog', $as ],
            'sub: openid root form'            => [ '/.well-known/openid-configuration/blog', '/blog', $as ],
            'sub: PRM path-aware'              => [ '/.well-known/oauth-protected-resource/blog/wp-json/ai-editor-divi5/v1/mcp', '/blog', $pr ],
            'sub: PRM under the site path'     => [ '/blog/.well-known/oauth-protected-resource', '/blog', $pr ],
            'sub: another site path'           => [ '/shop/.well-known/openid-configuration', '/blog', null ],
            'sub: AS for another path'         => [ '/.well-known/oauth-authorization-server/shop', '/blog', null ],
        ];
    }

    #[DataProvider('cases')]
    public function testMatch( string $path, string $home, ?string $expected ): void
    {
        $this->assertSame( $expected, W::match( $path, $home ) );
    }
}
