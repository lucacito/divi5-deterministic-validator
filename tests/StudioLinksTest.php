<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\StudioLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/StudioLinks.php';

/** Studio compiler, plan 1 task 2: the only link forms a brief may carry. */
class StudioLinksTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function allowed(): array
    {
        return [
            'https'    => [ 'https://example.com/a?b=1' ],
            'https up' => [ 'HTTPS://Example.com' ],
            'mailto'   => [ 'mailto:a@b.co' ],
            'tel'      => [ 'tel:+1 (555) 010-1234' ],
            'anchor'   => [ '#pricing' ],
            'path'     => [ '/contact/' ],
            'path q'   => [ '/shop/?cat=3' ],
        ];
    }

    #[DataProvider( 'allowed' )]
    public function testAllowed( string $url ): void
    {
        $this->assertNull( StudioLinks::problem( $url ), $url );
    }

    /** @return array<string,array{string,string}> */
    public static function refused(): array
    {
        return [
            'javascript'     => [ 'javascript:alert(1)', 'LINK_SCHEME_NOT_ALLOWED' ],
            'data'           => [ 'data:text/html,x', 'LINK_SCHEME_NOT_ALLOWED' ],
            'http'           => [ 'http://example.com', 'LINK_SCHEME_NOT_ALLOWED' ],
            'no scheme'      => [ 'example.com/x', 'LINK_SCHEME_NOT_ALLOWED' ],
            'protocol rel'   => [ '//evil.com', 'LINK_MALFORMED' ],
            'backslash path' => [ '/\\evil.com', 'LINK_MALFORMED' ],
            'userinfo'       => [ 'https://user@example.com', 'LINK_MALFORMED' ],
            'no host'        => [ 'https://', 'LINK_MALFORMED' ],
            'space in path'  => [ '/a b', 'LINK_MALFORMED' ],
            'newline'        => [ "https://example.com/a\nb", 'LINK_MALFORMED' ],
            'token'          => [ '{{aied:color:bg}}', 'LINK_MALFORMED' ],
            'loop token'     => [ '$variable({})$', 'LINK_MALFORMED' ],
            'bad anchor'     => [ '#1 two', 'LINK_MALFORMED' ],
            'bad mailto'     => [ 'mailto:not-an-address', 'LINK_MALFORMED' ],
            'bad tel'        => [ 'tel:call-me', 'LINK_MALFORMED' ],
            'empty'          => [ '', 'LINK_MALFORMED' ],
            'too long'       => [ '/' . str_repeat( 'a', 2001 ), 'LINK_TOO_LONG' ],
        ];
    }

    #[DataProvider( 'refused' )]
    public function testRefused( string $url, string $code ): void
    {
        $this->assertSame( $code, StudioLinks::problem( $url ), $url );
    }

    public function testExactlyTheLimitIsAllowed(): void
    {
        $this->assertNull( StudioLinks::problem( '/' . str_repeat( 'a', 1999 ) ) );
    }

    public function testSitePath(): void
    {
        $home = 'https://example.com';
        $this->assertSame( '/x/', StudioLinks::sitePath( 'https://example.com/x/?y=1', $home ) );
        $this->assertSame( '/x/', StudioLinks::sitePath( '/x/?y=1#top', $home ) );
        $this->assertSame( '/', StudioLinks::sitePath( 'https://EXAMPLE.com', $home ) );
        $this->assertNull( StudioLinks::sitePath( 'https://other.com/x', $home ) );
        $this->assertNull( StudioLinks::sitePath( '#pricing', $home ) );
        $this->assertNull( StudioLinks::sitePath( 'mailto:a@b.co', $home ) );
        $this->assertNull( StudioLinks::sitePath( '//example.com/x', $home ) );
    }
}
