<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitAdapters;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/ImagePack.php';
require_once __DIR__ . '/../wp-plugin/src/ImageTokens.php';
require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/SectionRecipes.php';
require_once __DIR__ . '/../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/KitResolver.php';

/**
 * Image radius pass, Task 1: a style kit also rounds images. There is no new marker or adapter: the whole-value token
 * `{{aied:kit:radius-media}}` (StyleKits::VALUE_TOKENS) replaces the four literal corners of a `divi/image`'s
 * `image.decoration.border.{bp}.value.radius`. Every image radius in the recipe library is one of
 *
 * - a TOKEN: a corner decision (a photo or an avatar with a card-like radius), so it follows the kit;
 * - FLUSH: all four corners 0px, a tiled grid that must stay square in every kit;
 * - SHAPED: a corner of 500px or more (a circle, a pill or an arch): a shape decision, the same in every kit.
 *
 * Anything else (a 12px literal that is neither) fails closed, and BESPOKE_IMAGES, the list of images allowed to stay
 * literal, is empty with cap 0.
 */
class ImageRadiusMigrationTest extends TestCase
{
    private const TOKEN = '{{aied:kit:radius-media}}';

    /** Where a `divi/image` keeps its radius, under any breakpoint. */
    private const RADIUS_PATH = '/^image\.decoration\.border\.[a-z]+\.value\.radius$/';

    /** @var array<string,string> "<recipe>|<index among divi/image>" => why a literal radius may stay. SHRINK-ONLY, EMPTY (cap 0). */
    private const BESPOKE_IMAGES = [];

    private const BESPOKE_IMAGES_MAX = 0;

    /** The file size before the image radius pass (671,687 B): the file must end below it, never above. */
    private const SIZE_BEFORE = 671687;

    /** The 11 kit-like images (a corner decision), pinned as literals: recipe => image indexes (among that recipe's divi/image blocks). */
    private const TOKEN_IMAGES = [
        'portfolio-featured-grid'  => [ 0, 1, 2, 3, 4 ],
        'hero-product-glow'        => [ 0 ],
        'testimonials-avatar-grid' => [ 0, 1, 2 ],
        'hero-split-left-overlap'  => [ 0 ],
        'card-grid-3'              => [ 1 ],
    ];

    /** Shaped images (a corner of 500px or more): recipe => [index => the four corners the recipe keeps]. */
    private const SHAPED_IMAGES = [
        'quote-single-large'     => [ 0 => [ '999px', '999px', '999px', '999px' ] ],
        'split-image-text'       => [ 0 => [ '500px', '500px', '500px', '500px' ] ],
        'founder-note'           => [ 0 => [ '999px', '999px', '32px', '32px' ] ],
        'contact-location-form'  => [ 0 => [ '999px', '999px', '32px', '32px' ] ],
    ];

    /** Flush images (0px, a tiled grid): recipe => indexes. */
    private const FLUSH_IMAGES = [
        'team-grid'             => [ 0, 1, 2, 3 ],
        'gallery-masonry-style' => [ 0, 1, 2, 3, 4, 5 ],
    ];

    private const BLOCK = '/<!--\s+wp:(divi\/[a-z0-9-]+)(?:\s+(\{(?:[^-}]++|-(?!->)|\}(?!\s+\/?-->))*+\}))?\s+\/?-->/s';

    /** Dotted path => value of every leaf of $node (an empty array is a leaf). @return array<string,mixed> */
    private static function leaves( array $node, string $prefix = '' ): array
    {
        $out = [];
        foreach ( $node as $k => $v ) {
            $path = '' === $prefix ? (string) $k : $prefix . '.' . $k;
            if ( is_array( $v ) && [] !== $v ) {
                $out += self::leaves( $v, $path );
            } else {
                $out[ $path ] = $v;
            }
        }
        return $out;
    }

    /** Every `divi/image` of $markup in order: list of its attributes. @return list<array<string,mixed>> */
    private static function images( string $markup ): array
    {
        preg_match_all( self::BLOCK, $markup, $all, PREG_SET_ORDER );
        $out = [];
        foreach ( $all as $m ) {
            if ( 'divi/image' === $m[1] ) {
                $attrs = isset( $m[2] ) && '' !== $m[2] ? json_decode( $m[2], true ) : [];
                $out[] = is_array( $attrs ) ? $attrs : [];
            }
        }
        return $out;
    }

    /** Every radius of an image's attrs: dotted path of the radius => its value (a string token or the corner array). @return array<string,mixed> */
    private static function radii( array $attrs ): array
    {
        $out = [];
        $walk = static function ( $node, string $path ) use ( &$walk, &$out ): void {
            if ( ! is_array( $node ) ) {
                return;
            }
            foreach ( $node as $k => $v ) {
                $p = '' === $path ? (string) $k : $path . '.' . $k;
                if ( 'radius' === $k ) {
                    $out[ $p ] = $v;
                } else {
                    $walk( $v, $p );
                }
            }
        };
        $walk( $attrs, '' );
        return $out;
    }

    /** token | flush | shaped | other. */
    private static function classify( $radius ): string
    {
        if ( self::TOKEN === $radius ) {
            return 'token';
        }
        if ( ! is_array( $radius ) ) {
            return 'other';
        }
        $corners = [];
        foreach ( [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ] as $c ) {
            $corners[] = (string) ( $radius[ $c ] ?? '' );
        }
        if ( [ '0px', '0px', '0px', '0px' ] === $corners ) {
            return 'flush';
        }
        foreach ( $corners as $v ) {
            if ( 1 === preg_match( '/^(\d+(?:\.\d+)?)px$/', $v, $m ) && (float) $m[1] >= 500.0 ) {
                return 'shaped';
            }
        }
        return 'other';
    }

    /** Every image of the recipe file: list of [recipe, index, attrs]. @return list<array{0:string,1:int,2:array<string,mixed>}> */
    private static function allImages(): array
    {
        $out = [];
        foreach ( (array) json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true ) as $recipe ) {
            foreach ( self::images( (string) $recipe['markup'] ) as $i => $attrs ) {
                $out[] = [ (string) $recipe['name'], $i, $attrs ];
            }
        }
        return $out;
    }

    public function testEveryImageRadiusIsATokenACircleOrFlush(): void
    {
        $counts = [ 'token' => 0, 'flush' => 0, 'shaped' => 0 ];
        $other  = [];
        $radii  = 0;
        foreach ( self::allImages() as [ $name, $i, $attrs ] ) {
            foreach ( self::radii( $attrs ) as $path => $radius ) {
                ++$radii;
                $class = self::classify( $radius );
                $key   = "$name|$i";
                if ( 1 !== preg_match( self::RADIUS_PATH, $path ) ) {
                    $other[ $key ] = "radius at $path (the proven path is image.decoration.border.{bp}.value.radius)";
                } elseif ( 'other' === $class ) {
                    $other[ $key ] = 'literal ' . json_encode( $radius );
                } else {
                    ++$counts[ $class ];
                }
            }
        }
        foreach ( $other as $key => $why ) {
            $this->assertArrayHasKey( $key, self::BESPOKE_IMAGES, "$key: $why (a corner decision takes the media token; a circle, a pill, an arch or a flush grid keeps its shape; BESPOKE_IMAGES never grows)" );
        }
        foreach ( array_keys( self::BESPOKE_IMAGES ) as $key ) {
            $this->assertArrayHasKey( $key, $other, "$key is listed as bespoke but is gone or classified: remove it" );
        }
        $this->assertLessThanOrEqual( self::BESPOKE_IMAGES_MAX, count( self::BESPOKE_IMAGES ), 'shrink-only' );
        $this->assertSame( 0, self::BESPOKE_IMAGES_MAX, 'cap 0' );
        $this->assertSame( [], self::BESPOKE_IMAGES );
        $this->assertSame( [], $other );
        $this->assertSame( 25, $radii, '25 image radii in the library (the audit of the image radius spec; every breakpoint counted)' );
        $this->assertSame( [ 'token' => 11, 'flush' => 10, 'shaped' => 4 ], $counts );
    }

    public function testTheElevenKitLikeImagesCarryTheMediaToken(): void
    {
        $tokens = [];
        foreach ( self::allImages() as [ $name, $i, $attrs ] ) {
            foreach ( self::radii( $attrs ) as $path => $radius ) {
                if ( 'token' === self::classify( $radius ) ) {
                    $this->assertSame( 'image.decoration.border.desktop.value.radius', $path, "$name#$i: the token sits at the proven path" );
                    $tokens[ $name ][] = $i;
                }
            }
        }
        $pinned = self::TOKEN_IMAGES;
        ksort( $pinned );
        ksort( $tokens );
        $this->assertSame( $pinned, $tokens, 'exactly these images carry the media token' );
        // Shaped and flush images keep exactly their literal corners.
        foreach ( self::allImages() as [ $name, $i, $attrs ] ) {
            $radius = self::radii( $attrs )['image.decoration.border.desktop.value.radius'] ?? null;
            if ( isset( self::SHAPED_IMAGES[ $name ][ $i ] ) ) {
                $this->assertSame(
                    self::SHAPED_IMAGES[ $name ][ $i ],
                    [ $radius['topLeft'] ?? null, $radius['topRight'] ?? null, $radius['bottomRight'] ?? null, $radius['bottomLeft'] ?? null ],
                    "$name#$i keeps its shape"
                );
            }
            if ( in_array( $i, self::FLUSH_IMAGES[ $name ] ?? [], true ) ) {
                $this->assertSame( 'flush', self::classify( $radius ), "$name#$i stays flush" );
            }
        }
    }

    public function testTheTokenResolvesToTheKitsMediaRadiusForEveryKit(): void
    {
        $checked = 0;
        $lang    = (string) DesignLanguages::ids()[0];
        $this->assertCount( 40, StyleKits::ids() );
        foreach ( self::TOKEN_IMAGES as $name => $indexes ) {
            foreach ( StyleKits::ids() as $kitId ) {
                $kit   = (array) StyleKits::get( $kitId );
                $media = (string) $kit['radius']['media'];
                $out   = (string) SectionRecipes::recipe( $name, $lang, $kitId );
                $this->assertStringNotContainsString( 'aied:kit', $out, "$name/$kitId: no kit token is left" );
                $images = self::images( $out );
                foreach ( $indexes as $i ) {
                    $radius = $images[ $i ]['image']['decoration']['border']['desktop']['value']['radius'] ?? null;
                    $this->assertSame(
                        [ 'topLeft' => $media, 'topRight' => $media, 'bottomRight' => $media, 'bottomLeft' => $media ],
                        $radius,
                        "$name/$kitId image#$i: four corners at the kit's media radius, no sync key"
                    );
                    ++$checked;
                }
            }
        }
        $this->assertSame( 11 * 40, $checked );
    }

    public function testTheTokenIsTheWholeRadiusValueWithNoCornerOrSyncKeyBesideIt(): void
    {
        // Review focus 2: the token sits at image.decoration.border.{bp}.value.radius and nowhere else, and no corner or sync
        // key is left next to it (the resolver replaces the whole value; a leftover key would merge into the kit's corners).
        foreach ( self::allImages() as [ $name, $i, $attrs ] ) {
            $leaves = self::leaves( $attrs );
            foreach ( $leaves as $path => $value ) {
                if ( self::TOKEN === $value ) {
                    $this->assertSame( 'image.decoration.border.desktop.value.radius', $path, "$name#$i: the token is at the proven path" );
                }
            }
            if ( in_array( $i, self::TOKEN_IMAGES[ $name ] ?? [], true ) ) {
                $border = (array) ( $attrs['image']['decoration']['border']['desktop']['value'] ?? [] );
                $this->assertSame( self::TOKEN, $border['radius'] ?? null, "$name#$i" );
                foreach ( array_keys( $leaves ) as $path ) {
                    $this->assertDoesNotMatchRegularExpression( '/radius\.(sync|topLeft|topRight|bottomRight|bottomLeft)$/', $path, "$name#$i keeps no corner key beside the token" );
                }
            }
        }
    }

    public function testTheMediaRadiusBlocksConstAndTheRecipeFileSize(): void
    {
        $this->assertSame( [ 'divi/image' ], KitAdapters::MEDIA_RADIUS_BLOCKS );
        $file = __DIR__ . '/../wp-plugin/data/section-recipes.json';
        clearstatcache();
        $this->assertLessThan( self::SIZE_BEFORE, filesize( $file ), 'the token is shorter than the four literal corners: the recipe file shrinks' );
        $this->assertLessThan( 700 * 1024, filesize( $file ) );
    }
}
