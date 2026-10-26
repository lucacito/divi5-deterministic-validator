<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use AiEditorDivi5\Pro\Design\MarkerSpace;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LanguageAdapters;
use AiEditorDivi5\WP\LanguageResolver;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../wp-plugin/src/autoload.php';
require_once __DIR__ . '/../src/autoload.php';

/**
 * MarkerSpace copies five private KitResolver constants. These tests pin the copies to the free values and prove,
 * against the free resolver itself, that the candidates it lists are exactly the markers the resolver accepts.
 */
class MarkerSpaceTest extends TestCase
{
    private const NAMES = [ 'ROLE_BLOCKS', 'ROLE_SURFACES', 'ROLE_GROUNDS', 'GROUND_SURFACES', 'GROUND_BLOCKS' ];

    /** @var array<string,mixed> */
    private array $language;
    /** @var array<string,mixed> */
    private array $kit;

    protected function setUp(): void
    {
        $this->language = (array) DesignLanguages::get( 'editorial' );
        $this->kit      = (array) StyleKits::get( 'quiet-solid' );
        $this->assertNotSame( [], $this->language );
        $this->assertNotSame( [], $this->kit );
    }

    /** @return list<array{string}> */
    public static function constantNames(): array
    {
        return array_map( static fn ( string $n ): array => [ $n ], self::NAMES );
    }

    #[DataProvider( 'constantNames' )]
    public function testTheCopiedConstantsEqualTheFreeOnes( string $name ): void
    {
        $free = ( new \ReflectionClassConstant( KitResolver::class, $name ) )->getValue();
        $this->assertSame( $free, ( new \ReflectionClassConstant( MarkerSpace::class, $name ) )->getValue(), $name . ' drifted from KitResolver' );
    }

    private function oneBlock( string $block, array $marker ): string
    {
        return '<!-- wp:' . $block . ' ' . json_encode( [ 'aied' => $marker ], JSON_UNESCAPED_SLASHES ) . ' /-->';
    }

    public function testEveryKitCandidateResolves(): void
    {
        $checked = 0;
        foreach ( $this->blocks() as $block ) {
            $candidates = MarkerSpace::kitCandidates( $block );
            $this->assertNotSame( [], $candidates, $block );
            foreach ( $candidates as $marker ) {
                $markup = $this->oneBlock( $block, $marker );
                $out    = SectionRecipes::resolveMarkup( $markup, $this->language, $this->kit );
                $this->assertNotNull( $out, $markup );
                $this->assertSame( [], KitResolver::leftovers( $out ), $markup );
                $this->assertStringNotContainsString( 'aied', $out, $markup );
                ++$checked;
            }
        }
        $this->assertGreaterThan( 100, $checked );
    }

    public function testNoAcceptedMarkerIsMissing(): void
    {
        $roles  = array_keys( MarkerSpace::ROLE_SURFACES );
        $bands  = array_values( array_unique( array_merge( ...array_values( MarkerSpace::ROLE_SURFACES ), ...[ [ 'bogus' ] ] ) ) );
        $blocks = array_merge( $this->blocks(), [ 'divi/text', 'divi/section', 'divi/image' ] );
        $seen   = 0;
        foreach ( $blocks as $block ) {
            $listed = array_map( 'json_encode', MarkerSpace::kitCandidates( $block ) );
            foreach ( array_merge( $roles, [ 'bogus' ] ) as $role ) {
                foreach ( $bands as $band ) {
                    foreach ( [ null, 'inverted', 'accent', 'bogus' ] as $ground ) {
                        $marker = [ 'kit' => $role, 'on' => $band ];
                        if ( null !== $ground ) {
                            $marker['ground'] = $ground;
                        }
                        $resolves = null !== SectionRecipes::resolveMarkup( $this->oneBlock( $block, $marker ), $this->language, $this->kit );
                        $this->assertSame( $resolves, in_array( json_encode( $marker ), $listed, true ), "$block " . json_encode( $marker ) );
                        ++$seen;
                    }
                }
            }
        }
        $this->assertGreaterThan( 1000, $seen );
    }

    public function testCandidateOrderFollowsTheConstants(): void
    {
        $c = MarkerSpace::kitCandidates( 'divi/group' );
        $this->assertSame( [ 'kit' => 'card', 'on' => 'light' ], $c[0] );
        $this->assertSame( [ 'kit' => 'card', 'on' => 'light', 'ground' => 'inverted' ], $c[1] );
        $this->assertSame( [ 'kit' => 'card', 'on' => 'light', 'ground' => 'accent' ], $c[2] );
        $this->assertSame( [ 'kit' => 'card', 'on' => 'surface' ], $c[3] );
        $this->assertSame( [], MarkerSpace::kitCandidates( 'divi/text' ) );
        $this->assertSame( [ 'kit' => 'form', 'on' => 'light' ], MarkerSpace::kitCandidates( 'divi/contact-form' )[0] );
        // No ground on a testimonial or an accent band.
        foreach ( MarkerSpace::kitCandidates( 'divi/testimonial' ) as $m ) {
            $this->assertArrayNotHasKey( 'ground', $m );
        }
        foreach ( MarkerSpace::kitCandidates( 'divi/group' ) as $m ) {
            if ( isset( $m['ground'] ) ) {
                $this->assertContains( $m['on'], MarkerSpace::GROUND_SURFACES );
            }
        }
    }

    public function testLanguageCandidatesFollowApplied(): void
    {
        $this->assertSame(
            [ [ 'type' => 'display' ], [ 'type' => 'title' ], [ 'type' => 'eyebrow' ] ],
            MarkerSpace::languageCandidates( 'divi/heading' )
        );
        $this->assertSame( [ [ 'type' => 'eyebrow' ] ], MarkerSpace::languageCandidates( 'divi/text' ) );
        $this->assertSame( [ [ 'rhythm' => 'section' ], [ 'rhythm' => 'open' ], [ 'rhythm' => 'tight' ] ], MarkerSpace::languageCandidates( 'divi/section' ) );
        $this->assertSame( [], MarkerSpace::languageCandidates( 'divi/button' ) );

        $expected = 0;
        foreach ( LanguageAdapters::APPLIED as $blocks ) {
            foreach ( $blocks as $roles ) {
                $expected += count( $roles );
            }
        }
        $total = 0;
        foreach ( [ 'divi/heading', 'divi/text', 'divi/section', 'divi/button' ] as $block ) {
            foreach ( MarkerSpace::languageCandidates( $block ) as $marker ) {
                $markup = $this->oneBlock( $block, $marker );
                $out    = LanguageResolver::resolve( $markup, $this->language );
                $this->assertSame( [], LanguageResolver::leftovers( $out ), $markup );
                $this->assertStringNotContainsString( 'aied', $out, $markup );
                ++$total;
            }
        }
        $this->assertSame( $expected, $total );
    }

    /** @return list<string> */
    private function blocks(): array
    {
        return array_values( array_unique( array_merge( ...array_values( MarkerSpace::ROLE_BLOCKS ) ) ) );
    }
}
