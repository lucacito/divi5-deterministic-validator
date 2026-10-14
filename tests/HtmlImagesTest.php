<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\HtmlImages;
use AiEditorDivi5\WP\ImagePack;

final class HtmlImagesTest extends HtmlTestCase
{
    private function images( ?callable $site = null ): HtmlImages
    {
        return new HtmlImages( ImagePack::manifest(), $site ?? static fn( string $u ): bool => false );
    }

    /** @return array<string,mixed> */
    private function img( array $over = [] ): array
    {
        return array_merge( [ 'type' => 'image', 'src' => 'https://cdn.example.com/photo.jpg', 'alt' => 'A photo', 'width' => null, 'height' => null, 'path' => 'p', 'hint' => '', 'href' => null ], $over );
    }

    public function testAThirdPartyImageNeverReachesTheLayout(): void
    {
        foreach ( [ 'https://cdn.example.com/a.jpg', '/images/a.png', 'a.png', 'https://example.com/x.jpg?token=1', '' ] as $src ) {
            $r = $this->images()->resolve( $this->img( [ 'src' => $src ] ), HtmlImages::MODE_KEEP, 1, 0 );
            $this->assertMatchesRegularExpression( '/^\{\{aied:image:[a-z0-9-]+\}\}$/', $r['src'], 'source: ' . $src );
            $this->assertNotNull( $r['token'] );
        }
    }

    public function testAKnownTokenIsKept(): void
    {
        $r = $this->images()->resolve( $this->img( [ 'src' => '{{aied:image:hero-blue-1}}' ] ), HtmlImages::MODE_PLACEHOLDER, 0, 0 );
        $this->assertSame( '{{aied:image:hero-blue-1}}', $r['src'] );
    }

    public function testASiteImageIsKeptOnlyInKeepMode(): void
    {
        $site = static fn( string $u ): bool => 'https://mysite.test/wp-content/uploads/a.jpg' === $u;
        $img  = $this->img( [ 'src' => 'https://mysite.test/wp-content/uploads/a.jpg' ] );

        $keep = $this->images( $site )->resolve( $img, HtmlImages::MODE_KEEP, 0, 0 );
        $this->assertSame( 'https://mysite.test/wp-content/uploads/a.jpg', $keep['src'] );
        $this->assertNull( $keep['token'] );

        $place = $this->images( $site )->resolve( $img, HtmlImages::MODE_PLACEHOLDER, 0, 0 );
        $this->assertStringStartsWith( '{{aied:image:', $place['src'] );
    }

    public function testTheChoiceIsDeterministicAndSpreadsOut(): void
    {
        $a = $this->img( [ 'src' => '/a.png', 'width' => 400, 'height' => 300 ] );
        $b = $this->img( [ 'src' => '/b.png', 'width' => 400, 'height' => 300 ] );
        $one = $this->images()->resolve( $a, HtmlImages::MODE_PLACEHOLDER, 1, 0 )['src'];
        $this->assertSame( $one, $this->images()->resolve( $a, HtmlImages::MODE_PLACEHOLDER, 1, 0 )['src'] );

        $seen = [];
        for ( $i = 0; $i < 20; $i++ ) {
            $seen[ $this->images()->resolve( $this->img( [ 'src' => '/img' . $i . '.png', 'width' => 400, 'height' => 300 ] ), HtmlImages::MODE_PLACEHOLDER, 1, $i )['src'] ] = true;
        }
        $this->assertGreaterThan( 3, count( $seen ), 'different sources get different placeholders' );
        $this->assertNotSame( '', $this->images()->resolve( $b, HtmlImages::MODE_PLACEHOLDER, 1, 0 )['src'] );
    }

    /** @return array<string,array{0:array<string,mixed>,1:int,2:int,3:string}> */
    public static function roles(): array
    {
        return [
            'wide first image'      => [ [ 'width' => 1600, 'height' => 800 ], 0, 0, 'hero' ],
            'wide later image'      => [ [ 'width' => 1600, 'height' => 800 ], 2, 1, 'section-bg' ],
            'landscape'             => [ [ 'width' => 400, 'height' => 300 ], 1, 0, 'card' ],
            'square'                => [ [ 'width' => 200, 'height' => 200 ], 1, 0, 'square' ],
            'very wide'             => [ [ 'width' => 900, 'height' => 200 ], 1, 0, 'logo' ],
            'avatar by name'        => [ [ 'hint' => 'team-member.jpg' ], 1, 0, 'avatar' ],
            'avatar by alt'         => [ [ 'alt' => 'Headshot of Jane' ], 1, 0, 'avatar' ],
            'logo by name'          => [ [ 'hint' => 'client-logo.png' ], 1, 0, 'logo' ],
            'hero by name'          => [ [ 'hint' => 'hero-banner.jpg' ], 3, 0, 'hero' ],
            'first image unknown'   => [ [], 0, 0, 'hero' ],
            'later image unknown'   => [ [], 2, 1, 'card' ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roles')]
    public function testRoleChoice( array $img, int $section, int $index, string $expected ): void
    {
        $this->assertSame( $expected, $this->images()->roleFor( $this->img( $img ), $section, $index ) );
    }

    public function testEveryRolePicksARealPackImage(): void
    {
        $tokens = array_column( ImagePack::manifest()['images'], 'token' );
        foreach ( [ 'hero', 'card', 'avatar', 'square', 'logo', 'section-bg' ] as $role ) {
            $hint = [ 'avatar' => 'team', 'logo' => 'logo' ][ $role ] ?? '';
            $dims = [ 'hero' => [ 1600, 900 ], 'section-bg' => [ 1600, 900 ], 'card' => [ 400, 300 ], 'square' => [ 200, 200 ] ][ $role ] ?? [ null, null ];
            $r = $this->images()->resolve( $this->img( [ 'hint' => $hint, 'width' => $dims[0], 'height' => $dims[1] ] ), HtmlImages::MODE_PLACEHOLDER, 1, 1 );
            $this->assertContains( $r['token'], $tokens, $role );
        }
    }

    public function testAnEmptyPackFallsBackSafely(): void
    {
        $img = new HtmlImages( [ 'fallback' => 'square-slate-1', 'images' => [] ], static fn(): bool => false );
        $this->assertSame( '{{aied:image:square-slate-1}}', $img->resolve( $this->img(), HtmlImages::MODE_PLACEHOLDER, 0, 0 )['src'] );
    }
}
