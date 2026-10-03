<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Site\LinkScanner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/autoload.php';

class LinkScannerTest extends TestCase
{
    /** A button module with the given linkUrl, the way real Divi 5 content stores it. */
    private static function button( string $url ): string
    {
        return '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go","linkUrl":"' . $url . '"}}}},"builderVersion":"5.14.0"} /-->';
    }

    /** The JSON escape of a double quote inside block attributes (backslash, u, 0022), built without typing it. */
    private static function q(): string
    {
        return chr( 92 ) . 'u0022';
    }

    /** A text module whose escaped HTML contains the given anchor opening, as WordPress stores block attributes. */
    private static function html( string $anchor ): string
    {
        $lt = chr( 92 ) . 'u003c';
        $gt = chr( 92 ) . 'u003e';

        return '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"' . $lt . 'p' . $gt . $lt . $anchor . $gt . 'x' . $lt . '/a' . $gt . $lt . '/p' . $gt . '"}}}} /-->';
    }

    /** @return array<string,array{string,list<string>}> */
    public static function cases(): array
    {
        return [
            'root-relative page'            => [ self::button( '/about/' ), [ '/about/' ] ],
            'absolute own site'             => [ self::button( 'https://example.com/services/' ), [ '/services/' ] ],
            'own site with www and query'   => [ self::button( 'https://www.example.com/?p=7' ), [ '/?p=7' ] ],
            'other host'                    => [ self::button( 'https://other.com/about/' ), [] ],
            'a subdomain is another host'   => [ self::button( 'https://shop.example.com/' ), [] ],
            'anchor'                        => [ self::button( '#contact' ), [] ],
            'hash only'                     => [ self::button( '#' ), [] ],
            'home page'                     => [ self::button( '/' ), [] ],
            'own home absolute'             => [ self::button( 'https://example.com/' ), [] ],
            'mailto'                        => [ self::button( 'mailto:a@b.co' ), [] ],
            'tel'                           => [ self::button( 'tel:+123' ), [] ],
            'javascript'                    => [ self::button( 'javascript:void(0)' ), [] ],
            'protocol-relative'             => [ self::button( '//example.com/x' ), [] ],
            'relative without slash'        => [ self::button( 'about' ), [] ],
            'dynamic variable'              => [ self::button( '$variable({\"type\":\"content\"})$' ), [] ],
            'uploads'                       => [ self::button( '/wp-content/uploads/2026/a.pdf' ), [] ],
            'admin and rest'                => [ self::button( '/wp-admin/x' ) . self::button( '/wp-json/x' ) . self::button( '/wp-login.php' ), [] ],
            'fragment is dropped'           => [ self::button( '/about/#team' ), [ '/about/' ] ],
            'the same link twice'           => [ self::button( '/about/' ) . self::button( '/about/' ), [ '/about/' ] ],
            'image url on this site'        => [ '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"x","url":"https://example.com/page-with-image/"}}}}} /-->', [ '/page-with-image/' ] ],
            'escaped slashes decode'        => [ self::button( 'https:\/\/example.com\/escaped\/' ), [ '/escaped/' ] ],
            'href in escaped html'          => [ self::html( 'a href=' . self::q() . '/in-text/' . self::q() ), [ '/in-text/' ] ],
            'external href in html'         => [ self::html( 'a href=' . self::q() . 'https://elsewhere.org/' . self::q() ), [] ],
            'subdirectory uploads'          => [ self::button( '/blog/wp-content/uploads/2026/a.png' ), [] ],
            'subdirectory wp-includes'      => [ self::button( '/blog/wp-includes/x' ), [] ],
            'file download'                 => [ self::button( '/files/x.pdf' ), [] ],
            'sitemap file'                  => [ self::button( '/sitemap.xml' ), [] ],
            'robots file'                   => [ self::button( '/robots.txt' ), [] ],
            'search link'                   => [ self::button( '/?s=x' ), [] ],
            'subdirectory feed'             => [ self::button( '/blog/feed/' ), [] ],
            'no extension stays'            => [ self::button( '/about/index' ), [ '/about/index' ] ],
            'no links at all'               => [ '<!-- wp:divi/section /-->', [] ],
            'empty'                         => [ '', [] ],
        ];
    }

    /** @param list<string> $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'cases' )]
    public function testInternalPaths( string $content, array $expected ): void
    {
        $this->assertSame( $expected, LinkScanner::internalPaths( $content, 'example.com' ) );
    }

    public function testItNeverReturnsMoreThanTheCap(): void
    {
        $content = '';
        for ( $i = 0; $i < LinkScanner::MAX_LINKS + 50; $i++ ) {
            $content .= self::button( "/page-{$i}/" );
        }
        $this->assertCount( LinkScanner::MAX_LINKS, LinkScanner::internalPaths( $content, 'example.com' ) );
    }

    public function testAVeryLongValueDoesNotHideTheLinksAfterIt(): void
    {
        $long    = 'data:image/png;base64,' . str_repeat( 'QUJD', 150000 );
        $content = '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"url":"' . $long . '"}}}}} /-->' . self::button( '/about/' );
        $this->assertSame( [ '/about/' ], LinkScanner::internalPaths( $content, 'example.com' ) );
    }

    public function testItMakesNoRemoteRequest(): void
    {
        $source = (string) file_get_contents( __DIR__ . '/../src/Site/LinkScanner.php' );
        $this->assertDoesNotMatchRegularExpression( '/wp_remote_|curl_|file_get_contents\s*\(|fsockopen|stream_socket/i', $source );
    }
}
