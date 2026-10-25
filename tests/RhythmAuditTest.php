<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Tests\Support\RhythmAudit;
use PHPUnit\Framework\TestCase;

/**
 * The audit of the section padding of the shipped recipes (rhythm adapter, plan 2026-10-24, Task 4, Steps 1-3): which
 * `divi/section` blocks are a section, an open or a tight band, found by their own padding (unmigrated) or by their
 * `"aied":{"rhythm":...}` marker (migrated), so the table below is the same before and after the migration (Task 5).
 *
 * The pinned table is the plan's audit at 2b40d59 (desktop/tablet/phone, equal top and bottom in px).
 */
class RhythmAuditTest extends TestCase
{
    /**
     * recipe => [ role, desktop, tablet, phone ].
     *
     * @return array<string,array{0:string,1:int,2:int,3:int}>
     */
    private static function pinned(): array
    {
        $t   = [];
        $add = static function ( string $role, array $triple, array $names ) use ( &$t ): void {
            foreach ( $names as $n ) {
                $t[ $n ] = [ $role, ...$triple ];
            }
        };
        $add( 'open', [ 160, 112, 72 ], [ 'hero-pattern-centered' ] );
        $add( 'open', [ 140, 104, 72 ], [ 'quote-single-large' ] );
        $add( 'open', [ 160, 120, 88 ], [ 'quote-over-image' ] );
        $add( 'open', [ 128, 96, 72 ], [ 'founder-note', 'story-timeline' ] );
        $add( 'open', [ 160, 120, 72 ], [ 'cta-card-over-image' ] );
        $add( 'open', [ 152, 120, 80 ], [ 'transition-wave-band' ] );
        $add( 'tight', [ 80, 64, 48 ], [ 'proof-logo-wall' ] );
        $add( 'section', [ 120, 88, 56 ], [ 'hero-split-right', 'hero-split-left-overlap', 'hero-gradient-stats', 'hero-spec-card' ] );
        $add( 'section', [ 96, 72, 48 ], [ 'hero-bordered-cells' ] );
        $add( 'section', [ 120, 88, 64 ], [ 'stats-big-numbers' ] );
        $add( 'section', [ 96, 80, 64 ], [ 'cta-band' ] );
        $add( 'section', [ 112, 88, 64 ], [
            'features-bento', 'features-zigzag', 'list-icon-dividers', 'checklist-two-column', 'steps-numbered', 'steps-horizontal', 'timeline-vertical',
            'features-accordion-image', 'compare-two-panels', 'features-carousel-strip', 'proof-press-quotes', 'stats-counter-band', 'stats-circle-counters',
            'stats-big-captions', 'testimonials-slider-quote', 'testimonials-avatar-grid', 'pricing-three-tier', 'pricing-two-plan-highlight',
            'pricing-comparison-table', 'team-grid', 'values-list', 'faq-accordion', 'faq-two-column', 'faq-toggle-list', 'cta-split-form', 'cta-countdown',
            'gallery-masonry-style', 'portfolio-featured-grid', 'before-after-compare', 'contact-location-form', 'contact-details-form',
        ] );

        return $t;
    }

    /** @return list<array{name:string,markup:string}> */
    private static function library(): array
    {
        $recipes = json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        self::assertIsArray( $recipes );

        return $recipes;
    }

    /**
     * A section block with the given module attributes (and an optional `aied` marker).
     *
     * @param array<string,mixed>|null $spacing the module.decoration.spacing object, null for none
     * @param array<string,mixed>|null $aied    the marker object
     */
    private function section( ?array $spacing, ?array $aied = null ): string
    {
        $attrs = [ 'module' => [ 'decoration' => [ 'background' => [ 'desktop' => [ 'value' => [ 'color' => '#ffffff' ] ] ] ] ], 'builderVersion' => '5.8.0' ];
        if ( null !== $spacing ) {
            $attrs['module']['decoration']['spacing'] = $spacing;
        }
        if ( null !== $aied ) {
            $attrs['aied'] = $aied;
        }

        return '<!-- wp:divi/section ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' --><!-- wp:divi/row --><!-- /wp:divi/row --><!-- /wp:divi/section -->';
    }

    /** All three breakpoints with the same padding object. */
    private function every( array $padding ): array
    {
        $bp = [ 'value' => [ 'padding' => $padding ] ];

        return [ 'desktop' => $bp, 'tablet' => $bp, 'phone' => $bp ];
    }

    /** @return list<array<string,mixed>> */
    private function audit( string ...$blocks ): array
    {
        return RhythmAudit::sections( [ [ 'name' => 'planted', 'markup' => implode( '', $blocks ) ] ] );
    }

    public function testTheAuditFindsTheLibrarysSections(): void
    {
        $rows = RhythmAudit::sections( self::library() );
        $this->assertCount( 71, $rows, 'the library has 71 sections' );
        $this->assertCount( 71, array_unique( array_column( $rows, 'recipe' ) ), 'one in each of 71 recipes' );

        $roles  = [ 'section' => 0, 'open' => 0, 'tight' => 0 ];
        $got    = [];
        $exempt = [];
        $unclassified = [];
        foreach ( $rows as $r ) {
            $this->assertSame( 0, $r['index'], $r['recipe'] . ': every recipe has exactly one section' );
            $key = RhythmAudit::key( $r );
            if ( null !== $r['exempt'] ) {
                $exempt[] = $key;
                $this->assertNull( $r['role'], "$key is exempt, so it has no role" );
                continue;
            }
            if ( null === $r['role'] ) {
                $unclassified[] = $key;
                continue;
            }
            ++$roles[ $r['role'] ];
            $got[ $r['recipe'] ] = $r;
        }

        $this->assertSame( [], $unclassified, 'every section is classified or a named exemption' );
        $this->assertSame( [ 'section' => 38, 'open' => 7, 'tight' => 1 ], $roles );
        $this->assertCount( 46, $got );
        $this->assertCount( 25, $exempt );
        $this->assertEqualsCanonicalizing(
            array_map( static fn ( string $recipe ): string => $recipe . '|divi/section|0', array_keys( RhythmAudit::EXEMPTIONS ) ),
            $exempt,
            'exactly the named exemptions'
        );
        $this->assertSame( 25, count( RhythmAudit::EXEMPTIONS ) );
        $this->assertLessThanOrEqual( RhythmAudit::EXEMPTIONS_MAX, count( RhythmAudit::EXEMPTIONS ), 'the exemptions only shrink' );
        $this->assertSame( 25, RhythmAudit::EXEMPTIONS_MAX );

        $pinned = self::pinned();
        $this->assertCount( 46, $pinned );
        $this->assertEqualsCanonicalizing( array_keys( $pinned ), array_keys( $got ), 'the classified recipes are exactly the pinned ones' );
        foreach ( $pinned as $recipe => [ $role, $d, $t, $p ] ) {
            // Since Task 5 (scripts/migrate-rhythm-markers.php) a classified section is found by its marker and its own padding
            // is gone (the language writes it), so the pinned D/T/P are the measured values before the migration.
            $this->assertTrue( $got[ $recipe ]['marked'], "$recipe carries its rhythm marker" );
            $this->assertSame( [ $role, null, null, null ], [ $got[ $recipe ]['role'], $got[ $recipe ]['desktop'], $got[ $recipe ]['tablet'], $got[ $recipe ]['phone'] ], $recipe );
        }
    }

    public function testTheRolesByRecipeRoleAreThePlansTable(): void
    {
        // The recipe's own role tag is reported by the plan, never used to place a section (spec issue 2).
        $byRecipeRole = [];
        $roleOf       = [];
        foreach ( self::library() as $recipe ) {
            $roleOf[ $recipe['name'] ] = $recipe['tags']['role'] ?? '?';
        }
        foreach ( RhythmAudit::sections( self::library() ) as $r ) {
            if ( null !== $r['role'] ) {
                $byRecipeRole[ $roleOf[ $r['recipe'] ] ][ $r['role'] ] = ( $byRecipeRole[ $roleOf[ $r['recipe'] ] ][ $r['role'] ] ?? 0 ) + 1;
            }
        }
        ksort( $byRecipeRole );
        $this->assertSame(
            [
                'contact'      => [ 'section' => 2 ],
                'content'      => [ 'open' => 1, 'section' => 1 ],
                'cta'          => [ 'open' => 1, 'section' => 3 ],
                'faq'          => [ 'section' => 3 ],
                'features'     => [ 'section' => 7 ],
                'gallery'      => [ 'section' => 3 ],
                'hero'         => [ 'open' => 1, 'section' => 5 ],
                'pricing'      => [ 'section' => 3 ],
                'process'      => [ 'section' => 3 ],
                'proof'        => [ 'section' => 1, 'tight' => 1 ],
                'stats'        => [ 'section' => 4 ],
                'team'         => [ 'open' => 1, 'section' => 1 ],
                'testimonials' => [ 'open' => 2, 'section' => 2 ],
                'transition'   => [ 'open' => 1 ],
            ],
            array_map( static function ( array $c ): array { ksort( $c ); return $c; }, $byRecipeRole )
        );
    }

    public function testTheRoleRuleIsTheNearestFactor(): void
    {
        $this->assertSame( 'open', RhythmAudit::role( 126 ) );
        $this->assertSame( 'section', RhythmAudit::role( 125 ) );
        $this->assertSame( 'section', RhythmAudit::role( 112 ) );
        $this->assertSame( 'section', RhythmAudit::role( 87 ) );
        $this->assertSame( 'tight', RhythmAudit::role( 86 ) );
        $this->assertNull( RhythmAudit::role( 50 ), 'below 0.45 x 112' );
        $this->assertSame( 'tight', RhythmAudit::role( 51 ) );
        $this->assertSame( 'open', RhythmAudit::role( 179 ) );
        $this->assertNull( RhythmAudit::role( 180 ), 'above 1.6 x 112' );
        $this->assertNull( RhythmAudit::role( 0 ) );
        $this->assertNull( RhythmAudit::role( -8 ) );
    }

    public function testTheAuditClassifiesPlantedSections(): void
    {
        $px  = static fn ( int $v ): string => $v . 'px';
        $sym = fn ( int $d, int $t, int $p ): array => [
            'desktop' => [ 'value' => [ 'padding' => [ 'top' => $px( $d ), 'bottom' => $px( $d ) ] ] ],
            'tablet'  => [ 'value' => [ 'padding' => [ 'top' => $px( $t ), 'bottom' => $px( $t ) ] ] ],
            'phone'   => [ 'value' => [ 'padding' => [ 'top' => $px( $p ), 'bottom' => $px( $p ) ] ] ],
        ];
        $rows = $this->audit(
            $this->section( $sym( 112, 88, 64 ) ),                                                                       // 0 section
            $this->section( $sym( 160, 112, 72 ) ),                                                                      // 1 open
            $this->section( $sym( 80, 64, 48 ) ),                                                                        // 2 tight
            $this->section( $this->every( [ 'top' => '120px', 'bottom' => '96px' ] ) ),                                  // 3 asymmetric
            $this->section( $this->every( [ 'top' => '8%', 'bottom' => '8%' ] ) ),                                       // 4 percent
            $this->section( $this->every( [ 'top' => '8vw', 'bottom' => '8vw' ] ) ),                                     // 5 vw
            $this->section( [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => '100px', 'bottom' => '100px' ] ] ] ] ), // 6 desktop only
            $this->section( null ),                                                                                      // 7 no padding
            $this->section( [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => '112px', 'bottom' => '112px' ] ], 'hover' => [ 'padding' => [ 'top' => '8px', 'bottom' => '8px' ] ] ], 'tablet' => [ 'value' => [ 'padding' => [ 'top' => '88px', 'bottom' => '88px' ] ] ], 'phone' => [ 'value' => [ 'padding' => [ 'top' => '64px', 'bottom' => '64px' ] ] ] ] ), // 8 hover state
            $this->section( [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => '112px', 'bottom' => '112px' ], 'margin' => [ 'top' => '8px' ] ] ], 'tablet' => [ 'value' => [ 'padding' => [ 'top' => '88px', 'bottom' => '88px' ] ] ], 'phone' => [ 'value' => [ 'padding' => [ 'top' => '64px', 'bottom' => '64px' ] ] ] ] ), // 9 margin
            $this->section( $this->every( [ 'top' => '0px', 'bottom' => '0px' ] ) ),                                     // 10 zero
            $this->section( $this->every( [ 'top' => '112px', 'bottom' => '112px', 'left' => '16px' ] ) ),               // 11 horizontal padding
            $this->section( $sym( 30, 28, 28 ) ),                                                                        // 12 out of band (below)
            $this->section( $sym( 200, 150, 100 ) ),                                                                     // 13 out of band (above)
            $this->section( $this->every( [ 'top' => '112', 'bottom' => '112' ] ) ),                                     // 14 no unit
            $this->section( [ 'desktop' => [ 'value' => [ 'padding' => [ 'top' => '112px', 'bottom' => '112px' ] ] ], 'tablet' => [ 'value' => [ 'padding' => [ 'top' => '88px', 'bottom' => '88px' ] ] ] ] ), // 15 phone missing
            $this->section( $this->every( [ 'top' => '112px', 'bottom' => '112px', 'syncVertical' => 'on' ] ) )          // 16 sync is allowed
        );
        $this->assertCount( 17, $rows );
        foreach ( $rows as $i => $r ) {
            $this->assertSame( $i, $r['index'] );
            $this->assertFalse( $r['marked'] );
            $this->assertNull( $r['exempt'], 'a planted recipe has no exemption' );
        }
        $this->assertSame( [ 'section', 'open', 'tight' ], [ $rows[0]['role'], $rows[1]['role'], $rows[2]['role'] ] );
        $this->assertSame( [ 112, 88, 64 ], [ $rows[0]['desktop'], $rows[0]['tablet'], $rows[0]['phone'] ] );
        foreach ( [ 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15 ] as $i ) {
            $this->assertNull( $rows[ $i ]['role'], "section#$i is not a candidate" );
        }
        $this->assertNull( $rows[3]['desktop'], 'an asymmetric section has no single value' );
        $this->assertNull( $rows[7]['desktop'] );
        $this->assertSame( 'section', $rows[16]['role'], 'syncVertical beside equal top and bottom still classifies' );
    }

    public function testAMarkedSectionSaysItsRoleItself(): void
    {
        $rows = $this->audit(
            $this->section( null, [ 'rhythm' => 'open' ] ),
            $this->section( null, [ 'rhythm' => 'tight' ] ),
            $this->section( null, [ 'rhythm' => 'section' ] )
        );
        $this->assertSame( [ 'open', 'tight', 'section' ], array_column( $rows, 'role' ) );
        $this->assertSame( [ true, true, true ], array_column( $rows, 'marked' ) );
        $this->assertSame( [ null, null, null ], array_column( $rows, 'desktop' ) );
        $this->assertSame( [ null, null, null ], array_column( $rows, 'exempt' ) );
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function badMarkers(): array
    {
        return [
            'unknown role'    => [ [ 'rhythm' => 'wide' ] ],
            'not a string'    => [ [ 'rhythm' => 3 ] ],
            'extra key'       => [ [ 'rhythm' => 'section', 'on' => 'light' ] ],
            'with a type'     => [ [ 'rhythm' => 'section', 'type' => 'display' ] ],
            'with a kit'      => [ [ 'rhythm' => 'section', 'kit' => 'control' ] ],
        ];
    }

    /** @param array<string,mixed> $aied */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'badMarkers' )]
    public function testAMalformedMarkerThrows( array $aied ): void
    {
        $this->expectException( \RuntimeException::class );
        $this->audit( $this->section( null, $aied ) );
    }

    public function testAMarkerOfAnotherKindIsNotARhythmMarker(): void
    {
        $rows = $this->audit( $this->section( null, [ 'type' => 'display' ] ) );
        $this->assertFalse( $rows[0]['marked'], 'a section with only some other marker is unmarked' );
        $this->assertNull( $rows[0]['role'] );
    }

    public function testTheExemptionsAreRealSectionsAndUnmarked(): void
    {
        $byKey = [];
        foreach ( RhythmAudit::sections( self::library() ) as $r ) {
            $byKey[ RhythmAudit::key( $r ) ] = $r;
        }
        $classes = [];
        foreach ( RhythmAudit::EXEMPTIONS as $recipe => $reason ) {
            $key = $recipe . '|divi/section|0';
            $this->assertArrayHasKey( $key, $byKey, "$recipe is a real section" );
            $this->assertFalse( $byKey[ $key ]['marked'], "$key stays literal, so no marker" );
            $this->assertNull( $byKey[ $key ]['role'], "$key is not a candidate" );
            $this->assertSame( $reason, $byKey[ $key ]['exempt'] );
            $this->assertNotSame( '', trim( $reason ) );
            $classes[ RhythmAudit::reasonClass( $reason ) ] = ( $classes[ RhythmAudit::reasonClass( $reason ) ] ?? 0 ) + 1;
        }
        ksort( $classes );
        $this->assertSame(
            [ '0px edge' => 3, 'asymmetric' => 6, 'desktop only' => 3, 'no padding' => 7, 'old alpha shape' => 3, 'out of band' => 1, 'percent or vw' => 2 ],
            $classes
        );
    }

    public function testEverySectionIsMeasuredAtThreeBreakpointsOrHasAReason(): void
    {
        // The rule of the plan header: a candidate has px top = bottom > 0 at ALL THREE breakpoints; nothing else does.
        foreach ( RhythmAudit::sections( self::library() ) as $r ) {
            $key = RhythmAudit::key( $r );
            if ( null !== $r['role'] && $r['marked'] ) {
                // Since Task 5 a classified section is marked and carries no padding of its own: the language decides it.
                $this->assertSame( [ null, null, null ], [ $r['desktop'], $r['tablet'], $r['phone'] ], $key );
                $this->assertSame( [ 'desktop' => '-', 'tablet' => '-', 'phone' => '-' ], $r['raw'], $key );
            } elseif ( null !== $r['role'] ) {
                $this->assertIsInt( $r['desktop'], $key );
                $this->assertIsInt( $r['tablet'], $key );
                $this->assertIsInt( $r['phone'], $key );
                $this->assertGreaterThanOrEqual( $r['tablet'], $r['desktop'], $key );
                $this->assertGreaterThanOrEqual( $r['phone'], $r['tablet'], $key );
            } else {
                $this->assertNotNull( $r['exempt'], "$key has no role, so it must be a named exemption" );
            }
        }
    }

    public function testEveryClassifiedSectionIsMarked(): void
    {
        // Flipped by Task 5 (scripts/migrate-rhythm-markers.php): every classified section carries its marker, no exempt one does.
        $marked = 0;
        foreach ( RhythmAudit::sections( self::library() ) as $r ) {
            $this->assertSame( null !== $r['role'], $r['marked'], RhythmAudit::key( $r ) );
            $marked += $r['marked'] ? 1 : 0;
        }
        $this->assertSame( 46, $marked );
    }
}
