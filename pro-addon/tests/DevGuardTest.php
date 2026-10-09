<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../scripts/dev-guard.php';

/** The real-site verify scripts create, change and delete content: they must refuse to run on anything but a local site. */
class DevGuardTest extends TestCase
{
    /** @return array<string,array{string,bool}> */
    public static function hosts(): array
    {
        return [
            'localhost'                 => [ 'localhost', true ],
            'localhost in capitals'     => [ 'LocalHost', true ],
            'the loopback address'      => [ '127.0.0.1', true ],
            'the IPv6 loopback'         => [ '[::1]', true ],
            'a .local site'             => [ 'divi.local', true ],
            'a .test site'              => [ 'shop.divi.test', true ],
            'a .localhost site'         => [ 'dev.localhost', true ],
            'a real site'               => [ 'example.com', false ],
            'a look-alike'              => [ 'localhost.evil.com', false ],
            'local inside a name'       => [ 'mylocal.com', false ],
            'a lan address'             => [ '192.168.1.20', false ],
            'no host at all'            => [ '', false ],
        ];
    }

    #[DataProvider( 'hosts' )]
    public function testWhichHostsCountAsADevelopmentSite( string $host, bool $dev ): void
    {
        $this->assertSame( $dev, aied_is_dev_host( $host ), $host );
    }

    public function testEveryRealSiteVerifyScriptRunsTheGuardFirst(): void
    {
        $scripts = glob( __DIR__ . '/../../scripts/verify-pro-*.php' );
        $this->assertCount( 12, $scripts );
        foreach ( $scripts as $script ) {
            $code = (string) file_get_contents( $script );
            $this->assertStringContainsString( "require_once __DIR__ . '/dev-guard.php';", $code, basename( $script ) );
            $this->assertStringContainsString( 'aied_require_dev_site();', $code, basename( $script ) );
            // Every script's real work starts at its `$failures = [];` line (every verify-pro script declares it first): the guard must come before it.
            $guard  = strpos( $code, 'aied_require_dev_site();' );
            $anchor = strpos( $code, '$failures = [];' );
            $this->assertNotFalse( $anchor, basename( $script ) . ' no longer declares $failures: update this test' );
            $this->assertLessThan( $anchor, $guard, basename( $script ) . ' must run the guard before its work starts' );
        }
    }
}
