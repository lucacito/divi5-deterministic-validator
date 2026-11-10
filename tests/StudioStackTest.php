<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StudioBrief;
use AiEditorDivi5\WP\StudioCompiler;
use AiEditorDivi5\WP\StudioSlots;
use AiEditorDivi5\WP\StudioStack;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/autoload.php';

/** Studio screen task 2: the default section stack per goal, and same-role variants. */
class StudioStackTest extends TestCase
{
    /** @return list<string> */
    private static function roles( array $stack ): array
    {
        return array_map( static fn ( string $r ): string => (string) ( SectionRecipes::tags( $r )['role'] ?? '' ), $stack );
    }

    public function testEveryGoalStackIsValid(): void
    {
        foreach ( StudioBrief::GOALS as $goal ) {
            $stack = StudioStack::forGoal( $goal );
            $this->assertNotSame( [], $stack, $goal );
            $this->assertLessThanOrEqual( StudioBrief::MAX_SECTIONS, count( $stack ), $goal );
            foreach ( $stack as $id ) {
                $this->assertContains( $id, SectionRecipes::names(), "$goal: $id exists" );
            }
            $roles = self::roles( $stack );
            $this->assertSame( 'hero', $roles[0], "$goal starts with a hero" );
            if ( in_array( $goal, [ 'sell', 'book', 'signup' ], true ) ) {
                $this->assertSame( 'cta', end( $roles ), "$goal ends with a closing call to action" );
            }
            foreach ( DesignLanguages::ids() as $language ) {
                $brief = [
                    'title'    => 'Stack ' . $goal,
                    'goal'     => $goal,
                    'language' => $language,
                    'sections' => array_map( static fn ( string $r ): array => [ 'recipe' => $r ], $stack ),
                ];
                $r = StudioCompiler::compile( $brief );
                $this->assertTrue( $r['ok'], "$goal under $language: " . json_encode( [ $r['problems'], $r['violations'] ] ) );
            }
        }
    }

    public function testVariantsShareTheRoleAndCountItems(): void
    {
        foreach ( SectionRecipes::names() as $name ) {
            $role     = SectionRecipes::tags( $name )['role'] ?? null;
            $variants = StudioStack::variants( $name );
            $this->assertContains( $name, array_column( $variants, 'recipe' ), "$name lists itself" );
            foreach ( $variants as $v ) {
                $this->assertSame( [ 'recipe', 'layout', 'items', 'needs_images' ], array_keys( $v ) );
                $this->assertSame( $role, SectionRecipes::tags( $v['recipe'] )['role'] ?? null );
                $items = 0;
                foreach ( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $v['recipe'] ) ) as $s ) {
                    $items += 'item_title' === $s['role'] ? 1 : 0;
                }
                $this->assertSame( $items, $v['items'], $v['recipe'] );
                $this->assertSame( $items, StudioStack::itemCount( $v['recipe'] ) );
                $this->assertIsBool( $v['needs_images'] );
                $this->assertSame( SectionRecipes::tags( $v['recipe'] )['layout'] ?? '', $v['layout'] );
            }
        }
        $this->assertSame( [], StudioStack::variants( 'no-such-recipe' ) );
        $this->assertSame( 0, StudioStack::itemCount( 'no-such-recipe' ) );
    }

    public function testStacksDiffer(): void
    {
        $seen = [];
        foreach ( StudioBrief::GOALS as $goal ) {
            $key = implode( ',', StudioStack::forGoal( $goal ) );
            $this->assertArrayNotHasKey( $key, $seen, "$goal repeats another goal's stack" );
            $seen[ $key ] = $goal;
        }
    }

    public function testDensityOnlyReordersWithinTheSameRoles(): void
    {
        $changed = false;
        foreach ( StudioBrief::GOALS as $goal ) {
            $base = StudioStack::forGoal( $goal, 'balanced' );
            $this->assertSame( $base, StudioStack::forGoal( $goal ), 'balanced is the default' );
            foreach ( [ 'airy', 'compact' ] as $density ) {
                $stack = StudioStack::forGoal( $goal, $density );
                $this->assertSame( self::roles( $base ), self::roles( $stack ), "$goal $density keeps the roles" );
                $changed = $changed || $stack !== $base;
                $this->assertSame( $stack, StudioStack::forGoal( $goal, $density ), 'deterministic' );
            }
            $this->assertNotSame( StudioStack::forGoal( $goal, 'airy' ), StudioStack::forGoal( $goal, 'compact' ), "$goal airy differs from compact" );
        }
        $this->assertTrue( $changed );
        $this->assertSame( StudioStack::forGoal( 'sell' ), StudioStack::forGoal( 'sell', 'nonsense' ), 'an unknown density is balanced' );
        $this->assertSame( StudioStack::forGoal( 'sell', 'compact' ), StudioStack::forGoal( 'sell', 'dense' ) );
        $this->assertSame( [], StudioStack::forGoal( 'no-such-goal' ) );
    }

    public function testNoRecipeRepeatsInAStack(): void
    {
        foreach ( StudioBrief::GOALS as $goal ) {
            foreach ( [ 'airy', 'balanced', 'compact' ] as $density ) {
                $stack = StudioStack::forGoal( $goal, $density );
                $this->assertSame( $stack, array_values( array_unique( $stack ) ), "$goal $density" );
            }
        }
    }

    /** The recipe picked per goal and density, pinned: a change here is a deliberate edit of the role table. */
    private const PINNED = [
        'sell'     => [
            'airy'     => [ 'hero-cta', 'features-zigzag', 'blurb-grid', 'testimonial', 'pricing-three-tier', 'faq-accordion', 'cta-band' ],
            'balanced' => [ 'hero-split-right', 'blurb-grid', 'split-image-text', 'testimonials-slider-quote', 'pricing-three-tier', 'faq-accordion', 'cta-band' ],
            'compact'  => [ 'hero-bordered-cells', 'features-bento', 'list-icon-dividers', 'testimonials-slider-quote', 'pricing-comparison-table', 'faq-two-column', 'cta-band' ],
        ],
        'book'     => [
            'airy'     => [ 'hero-cta', 'features-zigzag', 'steps-numbered', 'testimonial', 'faq-toggle-list', 'cta-band' ],
            'balanced' => [ 'hero-split-right', 'blurb-grid', 'steps-numbered', 'testimonials-slider-quote', 'faq-accordion', 'cta-band' ],
            'compact'  => [ 'hero-bordered-cells', 'features-bento', 'steps-numbered', 'testimonials-slider-quote', 'faq-two-column', 'cta-band' ],
        ],
        'signup'   => [
            'airy'     => [ 'hero-cta', 'features-zigzag', 'testimonial', 'faq-toggle-list', 'cta-band' ],
            'balanced' => [ 'hero-split-right', 'blurb-grid', 'testimonials-slider-quote', 'faq-accordion', 'cta-band' ],
            'compact'  => [ 'hero-bordered-cells', 'features-bento', 'testimonials-slider-quote', 'faq-two-column', 'cta-band' ],
        ],
        'explain'  => [
            'airy'     => [ 'hero-cta', 'values-list', 'features-zigzag', 'steps-numbered', 'faq-accordion' ],
            'balanced' => [ 'hero-split-right', 'section-intro', 'blurb-grid', 'steps-numbered', 'faq-accordion' ],
            'compact'  => [ 'hero-bordered-cells', 'section-intro', 'features-bento', 'steps-numbered', 'faq-two-column' ],
        ],
        'showcase' => [
            'airy'     => [ 'hero-cta', 'portfolio-featured-grid', 'testimonial', 'team-grid', 'contact-form' ],
            'balanced' => [ 'hero-split-right', 'portfolio-featured-grid', 'testimonials-slider-quote', 'team-grid', 'contact-form' ],
            'compact'  => [ 'hero-bordered-cells', 'image-gallery', 'testimonials-slider-quote', 'team-grid', 'contact-details-form' ],
        ],
    ];

    public function testThePickPerGoalAndDensityIsPinned(): void
    {
        $this->assertSame( StudioBrief::GOALS, array_keys( self::PINNED ) );
        foreach ( self::PINNED as $goal => $byDensity ) {
            foreach ( $byDensity as $density => $expected ) {
                $this->assertSame( $expected, StudioStack::forGoal( $goal, $density ), "$goal $density" );
            }
        }
    }

    public function testNoRoleIsSilentlySkipped(): void
    {
        foreach ( StudioBrief::GOALS as $goal ) {
            foreach ( [ 'airy', 'balanced', 'compact' ] as $density ) {
                $stack = StudioStack::forGoal( $goal, $density );
                $this->assertCount( count( StudioStack::roles( $goal ) ), $stack, "$goal $density" );
                $this->assertSame( StudioStack::roles( $goal ), self::roles( $stack ), "$goal $density" );
            }
        }
    }

    public function testExcludedRecipesAreNeverUsedAndEachHasAReason(): void
    {
        $this->assertNotSame( [], StudioStack::EXCLUDED );
        foreach ( StudioStack::EXCLUDED as $recipe => $reason ) {
            $this->assertContains( $recipe, SectionRecipes::names(), "$recipe is a real recipe" );
            $this->assertNotSame( '', trim( $reason ), "$recipe has a reason" );
        }
        foreach ( StudioBrief::GOALS as $goal ) {
            foreach ( [ 'airy', 'balanced', 'compact' ] as $density ) {
                foreach ( StudioStack::forGoal( $goal, $density ) as $id ) {
                    $this->assertArrayNotHasKey( $id, StudioStack::EXCLUDED, "$goal $density uses $id" );
                }
            }
        }
        // The data that made the entries: the closing call to action is never a newsletter strip.
        $this->assertArrayHasKey( 'newsletter-social', StudioStack::EXCLUDED );
    }

    public function testRestrictedStepsListOnlyRealRecipesOfTheStepsRole(): void
    {
        foreach ( StudioStack::STACKS as $goal => $steps ) {
            foreach ( $steps as $step ) {
                foreach ( $step['only'] ?? [] as $id ) {
                    $this->assertSame( $step['role'], SectionRecipes::tags( $id )['role'] ?? null, "$goal: $id" );
                    $this->assertArrayNotHasKey( $id, StudioStack::EXCLUDED, "$goal: $id" );
                }
            }
        }
    }

    public function testAdjacentSectionsNeverShareALayout(): void
    {
        foreach ( StudioBrief::GOALS as $goal ) {
            foreach ( [ 'airy', 'balanced', 'compact' ] as $density ) {
                $layouts = array_map( static fn ( string $r ): string => (string) ( SectionRecipes::tags( $r )['layout'] ?? '' ), StudioStack::forGoal( $goal, $density ) );
                for ( $i = 1; $i < count( $layouts ); $i++ ) {
                    $this->assertNotSame( $layouts[ $i - 1 ], $layouts[ $i ], "$goal $density: sections " . ( $i - 1 ) . ' and ' . $i . ' are both ' . $layouts[ $i ] );
                }
            }
        }
    }

    public function testTheClosingCallToActionIsABandWithAButton(): void
    {
        foreach ( [ 'sell', 'book', 'signup' ] as $goal ) {
            foreach ( [ 'airy', 'balanced', 'compact' ] as $density ) {
                $stack = StudioStack::forGoal( $goal, $density );
                $last  = $stack[ count( $stack ) - 1 ];
                $this->assertContains( $last, [ 'cta-band', 'cta-card-over-image' ], "$goal $density" );
            }
        }
    }
}
