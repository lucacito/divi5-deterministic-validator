<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use Divi5Validator\Tests\Support\TypeAudit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The audit of the headings and labels of the shipped recipes (type adapter, plan 2026-10-24, Task 4): which blocks are a
 * display headline, a title or an eyebrow label, found by their level and font (unmigrated) or by their
 * `"aied":{"type":...}` marker (migrated), so the table below is the same before and after the migration (Task 5).
 *
 * The pinned table is the plan's audit at 77abc24. The index is the block's order among the blocks of the SAME NAME in the
 * recipe (CardReport numbering); a heading's index counts headings only, a text's index counts texts only.
 */
class TypeAuditTest extends TestCase
{
    /**
     * recipe => [ display heading indices, title heading indices, eyebrow heading indices, eyebrow text indices ].
     *
     * @return array<string,array{0:list<int>,1:list<int>,2:list<int>,3:list<int>}>
     */
    private static function pinned(): array
    {
        $t = [];
        $add = static function ( array $names, array $display, array $title, array $eyebrow, array $text ) use ( &$t ): void {
            foreach ( $names as $n ) {
                $t[ $n ] = [ $display, $title, $eyebrow, $text ];
            }
        };
        $add( [ 'hero-cta' ], [ 1 ], [], [ 0 ], [] );
        $add( [ 'section-intro', 'blurb-grid', 'icon-features', 'contact-form', 'testimonial', 'hero-pattern-centered', 'cta-band', 'transition-wave-band' ], [ 0 ], [], [], [] );
        $add( [
            'hero-split-right', 'hero-split-left-overlap', 'hero-gradient-stats', 'hero-editorial-minimal', 'hero-product-glow', 'header-divider-intro',
            'checklist-two-column', 'features-accordion-image', 'stats-big-numbers', 'proof-press-quotes', 'stats-counter-band', 'stats-circle-counters',
            'testimonials-slider-quote', 'testimonials-avatar-grid', 'pricing-comparison-table', 'founder-note', 'faq-accordion', 'faq-toggle-list',
            'cta-card-over-image', 'cta-countdown', 'gallery-masonry-style',
        ], [ 1 ], [], [ 0 ], [] );
        $add( [ 'hero-image-scrim' ], [ 1 ], [], [ 0 ], [ 1, 2, 3 ] );
        $add( [ 'hero-bordered-cells' ], [ 0 ], [], [], [ 0, 2, 4 ] );
        $add( [ 'hero-spec-card' ], [ 0 ], [], [], [ 2 ] );
        $add( [ 'header-title-band', 'header-image-band' ], [ 0 ], [], [], [ 0 ] );
        $add( [ 'features-bento' ], [ 1 ], range( 2, 6 ), [ 0 ], [] );
        $add( [ 'features-zigzag' ], [ 1 ], [ 3, 5, 7 ], [ 0, 2, 4, 6 ], [] );
        $add( [ 'list-icon-dividers' ], [ 1 ], range( 2, 6 ), [ 0 ], [] );
        $add( [ 'steps-numbered' ], [ 1 ], range( 2, 4 ), [ 0 ], [] );
        $add( [ 'steps-horizontal' ], [ 1 ], range( 2, 5 ), [ 0 ], [] );
        $add( [ 'timeline-vertical' ], [ 1 ], [ 3, 5, 7, 9 ], [ 0, 2, 4, 6, 8 ], [] );
        $add( [ 'compare-two-panels' ], [ 1 ], [ 3, 5 ], [ 0, 2, 4 ], [] );
        $add( [ 'features-carousel-strip' ], [ 1 ], range( 2, 7 ), [ 0 ], [] );
        $add( [ 'stats-big-captions' ], [ 1 ], [], [ 0 ], [ 2, 4, 6, 8 ] );
        $add( [ 'pricing-three-tier' ], [ 1 ], range( 2, 4 ), [ 0 ], [ 7 ] );
        $add( [ 'pricing-two-plan-highlight' ], [ 1 ], range( 2, 3 ), [ 0 ], [ 1, 9 ] );
        $add( [ 'team-grid' ], [ 1 ], range( 2, 5 ), [ 0 ], range( 1, 4 ) );
        $add( [ 'values-list', 'story-timeline' ], [ 1 ], range( 2, 5 ), [ 0 ], [] );
        $add( [ 'transition-overlap-panel' ], [ 1 ], range( 2, 4 ), [ 0 ], [] );
        $add( [ 'faq-two-column' ], [ 1 ], range( 2, 7 ), [ 0 ], [] );
        $add( [ 'cta-split-form' ], [ 1 ], [ 2 ], [ 0 ], [] );
        $add( [ 'portfolio-featured-grid' ], [ 1 ], range( 2, 6 ), [ 0 ], range( 0, 4 ) );
        $add( [ 'before-after-compare' ], [ 1 ], [], [ 0 ], [ 1, 3 ] );
        $add( [ 'contact-location-form' ], [ 1 ], [], [ 0 ], [ 0, 2 ] );
        $add( [ 'contact-details-form' ], [ 1 ], [], [ 0 ], [ 0, 2, 4, 6 ] );

        return $t;
    }

    /** @return list<array{name:string,markup:string}> */
    private static function library(): array
    {
        $recipes = json_decode( (string) file_get_contents( __DIR__ . '/../wp-plugin/data/section-recipes.json' ), true );
        self::assertIsArray( $recipes );

        return $recipes;
    }

    /** @param array<string,mixed> $value the desktop font value */
    private function heading( array $value, ?string $type = null ): string
    {
        $attrs = [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'A headline' ] ], 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => $value ] ] ] ] ], 'builderVersion' => '5.8.0' ];
        if ( null !== $type ) {
            $attrs['aied'] = [ 'type' => $type ];
        }

        return '<!-- wp:divi/heading ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
    }

    /** @param array<string,mixed> $value the desktop body font value */
    private function text( array $value, ?string $type = null ): string
    {
        $attrs = [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<p>Label</p>' ] ], 'decoration' => [ 'bodyFont' => [ 'body' => [ 'font' => [ 'desktop' => [ 'value' => $value ] ] ] ] ] ], 'builderVersion' => '5.8.0' ];
        if ( null !== $type ) {
            $attrs['aied'] = [ 'type' => $type ];
        }

        return '<!-- wp:divi/text ' . json_encode( $attrs, JSON_UNESCAPED_SLASHES ) . ' /-->';
    }

    /** @return list<array<string,mixed>> */
    private function audit( string ...$blocks ): array
    {
        return TypeAudit::blocks( [ [ 'name' => 'planted', 'markup' => implode( '', $blocks ) ] ] );
    }

    public function testTheAuditFindsTheLibrarysHeadingsAndLabels(): void
    {
        $recipes = self::library();
        $rows    = TypeAudit::blocks( $recipes );

        $headings = array_filter( $rows, static fn ( array $r ): bool => 'divi/heading' === $r['block'] );
        $this->assertCount( 174, $headings, 'the library has 174 headings' );
        $this->assertCount( 57, array_unique( array_column( $headings, 'recipe' ) ), 'in 57 recipes' );

        $roles = [ 'display' => 0, 'title' => 0, 'eyebrow-heading' => 0, 'eyebrow-text' => 0 ];
        $got   = [];
        $exempt = [];
        $unclassified = [];
        foreach ( $rows as $r ) {
            $key = TypeAudit::key( $r );
            if ( null !== $r['exempt'] ) {
                $exempt[] = $key;
                $this->assertNull( $r['role'], "$key is exempt, so it has no role" );
                continue;
            }
            if ( null === $r['role'] ) {
                $unclassified[] = $key;
                continue;
            }
            $short = 'divi/heading' === $r['block'] ? $r['role'] : 'eyebrow-text';
            $short = 'eyebrow' === $short ? 'eyebrow-heading' : $short;
            ++$roles[ $short ];
            $got[ $r['recipe'] ][ $short ][] = $r['index'];
        }

        $this->assertSame( [], $unclassified, 'every heading and uppercase label is classified or a named exemption' );
        $this->assertSame( [ 'display' => 56, 'title' => 64, 'eyebrow-heading' => 53, 'eyebrow-text' => 33 ], $roles );
        $this->assertSame( 206, array_sum( $roles ) );
        $this->assertCount( 56, $got, '206 blocks in 56 recipes' );

        $this->assertEqualsCanonicalizing(
            [ 'shop-grid|divi/heading|0', 'proof-press-quotes|divi/text|0', 'proof-press-quotes|divi/text|3', 'proof-press-quotes|divi/text|6' ],
            $exempt,
            'exactly the four named exemptions'
        );

        $pinned = self::pinned();
        $this->assertSame( array_keys( $pinned ), array_values( array_intersect( array_keys( $pinned ), array_keys( $got ) ) ), 'every pinned recipe has blocks' );
        $this->assertEqualsCanonicalizing( array_keys( $pinned ), array_keys( $got ), 'no recipe outside the pinned table has a classified block' );
        foreach ( $pinned as $recipe => [ $display, $title, $eyebrow, $text ] ) {
            $this->assertSame(
                [ 'display' => $display, 'title' => $title, 'eyebrow-heading' => $eyebrow, 'eyebrow-text' => $text ],
                [
                    'display'         => $got[ $recipe ]['display'] ?? [],
                    'title'           => $got[ $recipe ]['title'] ?? [],
                    'eyebrow-heading' => $got[ $recipe ]['eyebrow-heading'] ?? [],
                    'eyebrow-text'    => $got[ $recipe ]['eyebrow-text'] ?? [],
                ],
                $recipe
            );
        }
    }

    public function testEveryClassifiedBlockIsMarked(): void
    {
        // Flipped by Task 5 (scripts/migrate-type-markers.php): every classified block carries its marker, no exempt one does.
        $marked = 0;
        foreach ( TypeAudit::blocks( self::library() ) as $r ) {
            $this->assertSame( null !== $r['role'], $r['marked'], TypeAudit::key( $r ) );
            $marked += $r['marked'] ? 1 : 0;
        }
        $this->assertSame( 206, $marked );
    }

    public function testTheAuditClassifiesPlantedBlocks(): void
    {
        $rows = $this->audit(
            $this->heading( [ 'headingLevel' => 'h1', 'family' => '{{aied:font:display}}' ] ),            // heading#0 display
            $this->heading( [ 'headingLevel' => 'h2', 'family' => '{{aied:font:display}}' ] ),            // heading#1 display
            $this->heading( [ 'headingLevel' => 'h3', 'family' => '{{aied:font:display}}' ] ),            // heading#2 title
            $this->heading( [ 'headingLevel' => 'h4', 'family' => '{{aied:font:eyebrow}}' ] ),            // heading#3 eyebrow
            $this->heading( [ 'headingLevel' => 'h5', 'family' => '{{aied:font:display}}' ] ),            // heading#4 unclassified
            $this->heading( [ 'headingLevel' => 'h2', 'family' => '{{aied:font:eyebrow}}' ] ),            // heading#5 unclassified
            $this->heading( [ 'headingLevel' => 'h3', 'family' => '{{aied:font:eyebrow}}' ] ),            // heading#6 unclassified
            $this->heading( [ 'headingLevel' => 'h4', 'family' => '{{aied:font:display}}' ] ),            // heading#7 unclassified
            $this->heading( [ 'family' => '{{aied:font:display}}' ] ),                                     // heading#8 no level
            $this->text( [ 'family' => '{{aied:font:eyebrow}}', 'capitalization' => 'uppercase' ] ),       // text#0 eyebrow
            $this->text( [ 'family' => '{{aied:font:body}}', 'capitalization' => 'uppercase' ] ),          // text#1 unclassified
            $this->text( [ 'family' => '{{aied:font:eyebrow}}' ] ),                                         // text#2 not a label (lowercase)
            $this->text( [ 'family' => '{{aied:font:display}}', 'capitalization' => 'uppercase' ] )        // text#3 unclassified
        );
        $by = [];
        foreach ( $rows as $r ) {
            $by[ $r['block'] . '#' . $r['index'] ] = $r;
        }

        $this->assertSame( [ 'display', 'display', 'title', 'eyebrow' ], [ $by['divi/heading#0']['role'], $by['divi/heading#1']['role'], $by['divi/heading#2']['role'], $by['divi/heading#3']['role'] ] );
        foreach ( [ 4, 5, 6, 7, 8 ] as $i ) {
            $this->assertNull( $by[ "divi/heading#$i" ]['role'], "heading#$i is not placed by the rule" );
            $this->assertNull( $by[ "divi/heading#$i" ]['exempt'], "heading#$i is unclassified, not exempt" );
        }
        $this->assertSame( 'h1', $by['divi/heading#0']['level'] );
        $this->assertSame( 'display', $by['divi/heading#0']['font'] );
        $this->assertSame( 'eyebrow', $by['divi/heading#3']['font'] );
        $this->assertNull( $by['divi/heading#8']['level'] );

        $this->assertSame( 'eyebrow', $by['divi/text#0']['role'] );
        $this->assertNull( $by['divi/text#1']['role'], 'an uppercase text in the body font is no eyebrow' );
        $this->assertArrayNotHasKey( 'divi/text#2', $by, 'a lowercase eyebrow-font text is not a label (values-list 01)' );
        $this->assertNull( $by['divi/text#3']['role'], 'an uppercase text in the display font is no eyebrow' );
        $this->assertCount( 12, $rows );

        $all = TypeAudit::blocks( [ [ 'name' => 'planted', 'markup' => $this->text( [ 'family' => '{{aied:font:eyebrow}}' ] ) . $this->text( [ 'family' => '{{aied:font:body}}', 'capitalization' => 'uppercase' ] ) ] ], true );
        $this->assertSame( [ 'divi/text#0', 'divi/text#1' ], array_map( static fn ( array $r ): string => $r['block'] . '#' . $r['index'], $all ), '$allTexts lists every text, classified or not' );
        $this->assertSame( [ null, null ], array_column( $all, 'role' ) );
    }

    public function testAMarkerPlacesABlockTheSameWayTheRuleDoes(): void
    {
        // Migrated shape: the literals are gone, the marker says the role. Level and font may still be there.
        $unmarked = $this->audit(
            $this->heading( [ 'headingLevel' => 'h2', 'family' => '{{aied:font:display}}', 'weight' => '700' ] ),
            $this->heading( [ 'headingLevel' => 'h3', 'family' => '{{aied:font:display}}' ] ),
            $this->heading( [ 'headingLevel' => 'h4', 'family' => '{{aied:font:eyebrow}}', 'capitalization' => 'uppercase', 'letterSpacing' => '2px' ] ),
            $this->text( [ 'family' => '{{aied:font:eyebrow}}', 'capitalization' => 'uppercase' ] )
        );
        $marked = $this->audit(
            $this->heading( [ 'headingLevel' => 'h2', 'family' => '{{aied:font:display}}' ], 'display' ),
            $this->heading( [ 'headingLevel' => 'h3', 'family' => '{{aied:font:display}}' ], 'title' ),
            $this->heading( [ 'headingLevel' => 'h4', 'family' => '{{aied:font:eyebrow}}' ], 'eyebrow' ),
            $this->text( [ 'family' => '{{aied:font:eyebrow}}' ], 'eyebrow' )
        );
        $strip = static fn ( array $rows ): array => array_map( static fn ( array $r ): array => [ $r['block'], $r['index'], $r['role'], $r['exempt'] ], $rows );
        $this->assertSame( $strip( $unmarked ), $strip( $marked ), 'marked and unmarked blocks give the same roles' );
        $this->assertSame( [ false, false, false, false ], array_column( $unmarked, 'marked' ) );
        $this->assertSame( [ true, true, true, true ], array_column( $marked, 'marked' ) );

        // A marked heading with no level is placed by its marker alone.
        $rows = $this->audit( $this->heading( [ 'family' => '{{aied:font:display}}' ], 'display' ) );
        $this->assertSame( 'display', $rows[0]['role'] );
        $this->assertNull( $rows[0]['level'] );
        $this->assertTrue( $rows[0]['marked'] );
    }

    /** @return array<string,array{0:string}> */
    public static function malformedMarkers(): array
    {
        return [
            'unknown role'     => [ '{"type":"body"}' ],
            'extra key'        => [ '{"type":"display","on":"light"}' ],
            'non-string role'  => [ '{"type":7}' ],
            'empty'            => [ '{"type":""}' ],
        ];
    }

    #[DataProvider( 'malformedMarkers' )]
    public function testAMalformedTypeMarkerFailsClosed( string $marker ): void
    {
        $markup = '<!-- wp:divi/heading {"title":{"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"h2","family":"{{aied:font:display}}"}}}}}},"aied":' . $marker . '} /-->';
        $this->expectException( \RuntimeException::class );
        TypeAudit::blocks( [ [ 'name' => 'planted', 'markup' => $markup ] ] );
    }

    public function testAKitMarkerIsNotATypeMarker(): void
    {
        $markup = '<!-- wp:divi/heading {"title":{"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"h2","family":"{{aied:font:display}}"}}}}}},"aied":{"kit":"control"}} /-->';
        $rows   = TypeAudit::blocks( [ [ 'name' => 'planted', 'markup' => $markup ] ] );
        $this->assertFalse( $rows[0]['marked'] );
        $this->assertSame( 'display', $rows[0]['role'] );
    }

    public function testATypeMarkerOnATextMustBeAnEyebrow(): void
    {
        $this->expectException( \RuntimeException::class );
        $this->audit( $this->text( [ 'family' => '{{aied:font:eyebrow}}' ], 'display' ) );
    }

    public function testTheExemptionsAreRealBlocksAndUnmarked(): void
    {
        $this->assertSame( 4, count( TypeAudit::EXEMPTIONS ), 'the exemptions only shrink' );
        $this->assertSame( 4, TypeAudit::EXEMPTIONS_MAX );
        $rows = [];
        foreach ( TypeAudit::blocks( self::library() ) as $r ) {
            $rows[ TypeAudit::key( $r ) ] = $r;
        }
        foreach ( TypeAudit::EXEMPTIONS as $key => $reason ) {
            $this->assertArrayHasKey( $key, $rows, "$key is a real heading or uppercase text of the library" );
            $this->assertFalse( $rows[ $key ]['marked'], "$key stays unmarked" );
            $this->assertNull( $rows[ $key ]['role'], "$key has no role" );
            $this->assertSame( $reason, $rows[ $key ]['exempt'] );
            $this->assertGreaterThan( 20, strlen( $reason ), "$key carries a real reason" );
        }
    }

    public function testTheIndexIsTheOrderAmongBlocksOfTheSameName(): void
    {
        // The numbering the other migration tests use (RecipeLibraryTest::markerBands): the audit must agree with it.
        foreach ( self::library() as $recipe ) {
            $expected = [];
            foreach ( RecipeLibraryTest::markerBands( (string) $recipe['markup'], static fn ( array $attrs ): ?array => [] === $attrs ? null : [ $attrs ] ) as $key => [ $found ] ) {
                if ( str_starts_with( $key, 'divi/heading#' ) || str_starts_with( $key, 'divi/text#' ) ) {
                    $expected[ $key ] = $found[0];
                }
            }
            foreach ( TypeAudit::blocks( [ $recipe ], true ) as $r ) {
                $key = $r['block'] . '#' . $r['index'];
                $this->assertArrayHasKey( $key, $expected, $recipe['name'] . ' ' . $key );
                $level = $expected[ $key ]['title']['decoration']['font']['font']['desktop']['value']['headingLevel'] ?? null;
                if ( 'divi/heading' === $r['block'] ) {
                    $this->assertSame( $level, $r['level'], $recipe['name'] . ' ' . $key );
                }
            }
        }
    }
}
