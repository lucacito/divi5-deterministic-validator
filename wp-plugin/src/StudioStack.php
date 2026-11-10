<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The default section stack of a Divi 5 Lab page, per goal, and the same-role variants of a recipe. Pure.
 *
 * The stacks are a data table of recipe ROLES (the `role` tag), never recipe ids: for each role the recipe whose
 * `density` tag is closest to the requested density is picked, ties by catalog order, so the result is deterministic
 * and a new recipe is picked up without editing the table. No copy, claims or facts live here; the words are the
 * owner's (or the recipes' placeholders).
 */
final class StudioStack
{
    /** Density tags in order, and the words a caller may use for them. */
    private const DENSITY_ORDER = [ 'airy' => 0, 'balanced' => 1, 'dense' => 2 ];
    private const DENSITY_WORDS = [ 'airy' => 'airy', 'balanced' => 'balanced', 'compact' => 'dense', 'dense' => 'dense' ];

    /**
     * Steps per goal, in order. A step is a role (the recipe's `role` tag) and, optionally, the only recipes of that
     * role that suit the goal. The first step is always a hero; sell, book and signup end on a closing call to action
     * (a real CTA band, never a signup strip). A role may appear twice; a recipe never does.
     *
     * @var array<string,list<array{role:string,only?:list<string>}>>
     */
    public const STACKS = [
        'sell'     => [
            [ 'role' => 'hero', 'only' => self::HERO_WITH_BUTTON ],
            [ 'role' => 'features' ],
            [ 'role' => 'features' ],
            [ 'role' => 'testimonials', 'only' => self::TESTIMONIALS ],
            [ 'role' => 'pricing' ],
            [ 'role' => 'faq' ],
            [ 'role' => 'cta', 'only' => self::CLOSING_CTA ],
        ],
        'book'     => [
            [ 'role' => 'hero', 'only' => self::HERO_WITH_BUTTON ],
            [ 'role' => 'features' ],
            [ 'role' => 'process' ],
            [ 'role' => 'testimonials', 'only' => self::TESTIMONIALS ],
            [ 'role' => 'faq' ],
            [ 'role' => 'cta', 'only' => self::CLOSING_CTA ],
        ],
        'signup'   => [
            [ 'role' => 'hero', 'only' => self::HERO_WITH_BUTTON ],
            [ 'role' => 'features' ],
            [ 'role' => 'testimonials', 'only' => self::TESTIMONIALS ],
            [ 'role' => 'faq' ],
            [ 'role' => 'cta', 'only' => self::CLOSING_CTA ],
        ],
        'explain'  => [
            [ 'role' => 'hero' ],
            [ 'role' => 'content', 'only' => [ 'section-intro', 'values-list' ] ],
            [ 'role' => 'features' ],
            [ 'role' => 'process' ],
            [ 'role' => 'faq' ],
        ],
        'showcase' => [
            [ 'role' => 'hero' ],
            [ 'role' => 'gallery', 'only' => [ 'portfolio-featured-grid', 'gallery-masonry-style', 'image-gallery' ] ],
            [ 'role' => 'testimonials', 'only' => self::TESTIMONIALS ],
            [ 'role' => 'team', 'only' => [ 'team-grid' ] ],
            [ 'role' => 'contact', 'only' => [ 'contact-form', 'contact-details-form' ] ],
        ],
    ];

    /** Heroes that carry a button, for the goals that ask for an action (hero-editorial-minimal has none). */
    private const HERO_WITH_BUTTON = [ 'hero-cta', 'hero-split-right', 'hero-split-left-overlap', 'hero-image-scrim', 'hero-pattern-centered', 'hero-bordered-cells' ];

    /** Testimonial recipes; the generic `slider` recipe has the same role tag but is a hero/showcase carousel. */
    private const TESTIMONIALS = [ 'testimonial', 'testimonials-avatar-grid', 'quote-single-large', 'testimonials-slider-quote', 'quote-over-image' ];

    /** The closing call to action: a full-width band with one button, never a footer or newsletter strip. */
    private const CLOSING_CTA = [ 'cta-band', 'cta-card-over-image' ];

    /**
     * Recipes no default stack uses, each with the reason. A stack is a starting point the owner fills with their own
     * words, so it must not seed facts the owner has not supplied, nor use a recipe whose data does not match its role.
     *
     * @var array<string,string>
     */
    public const EXCLUDED = [
        'newsletter-social'    => 'A footer or newsletter signup strip, not a closing call to action.',
        'image-carousel'       => 'A client or partner logo strip: would show invented clients.',
        'proof-logo-wall'      => 'Needs logos the owner has permission to show.',
        'proof-press-quotes'   => 'Needs real press coverage to quote.',
        'proof-rating-strip'   => 'Needs a real public rating and review count.',
        'stats-big-captions'   => 'Needs figures the owner can prove.',
        'stats-big-numbers'    => 'Needs figures the owner can prove.',
        'stats-circle-counters' => 'Needs percentages the owner can prove.',
        'stats-counter'        => 'Needs figures the owner can prove.',
        'stats-counter-band'   => 'Needs figures the owner can prove.',
        'hero-gradient-stats'  => 'Its point is figures the owner can back.',
        'hero-product-glow'    => 'Software screenshot and trust lines the owner would have to supply.',
        'hero-spec-card'       => 'Technical label and value lines for software and tools only.',
        'cta-countdown'        => 'Only for a real, dated deadline the owner confirms.',
        'slider'               => 'A generic hero/showcase carousel that carries the testimonials role tag.',
        'card-grid-3'          => 'Its data describes a project showcase, not a feature section.',
        'card-grid-4'          => 'Its data describes a call to action over a photo, not a feature section.',
        'founder-note'         => 'A first-person letter: the stack would write the owner\'s voice for them.',
        'story-timeline'       => 'Dated history the owner has not supplied.',
        'before-after-compare' => 'Needs a real before and after picture pair.',
        'contact-location-form' => 'Needs a real address and opening hours.',
    ];

    /**
     * The roles of a goal's stack, in order.
     *
     * @return list<string>
     */
    public static function roles( string $goal ): array
    {
        return array_map( static fn ( array $step ): string => $step['role'], self::STACKS[ $goal ] ?? [] );
    }

    /**
     * The recipe ids of the default stack for a goal. An unknown goal gives an empty list; an unknown density is balanced.
     *
     * @return list<string>
     */
    public static function forGoal( string $goal, string $density = 'balanced' ): array
    {
        if ( ! isset( self::STACKS[ $goal ] ) ) {
            return [];
        }
        $want  = self::DENSITY_ORDER[ self::DENSITY_WORDS[ $density ] ?? 'balanced' ];
        $stack = [];
        $prev  = null;
        foreach ( self::STACKS[ $goal ] as $step ) {
            $pick = self::closest( $step['role'], $step['only'] ?? null, $want, $stack, $prev );
            if ( null !== $pick ) {
                $stack[] = $pick;
                $prev    = (string) ( SectionRecipes::tags( $pick )['layout'] ?? '' );
            }
        }

        return array_slice( $stack, 0, StudioBrief::MAX_SECTIONS );
    }

    /**
     * Every recipe with the same role as $recipe (itself included), in catalog order.
     *
     * @return list<array{recipe:string,layout:string,items:int,needs_images:bool}>
     */
    public static function variants( string $recipe ): array
    {
        $role = SectionRecipes::tags( $recipe )['role'] ?? null;
        if ( ! is_string( $role ) ) {
            return [];
        }
        $out = [];
        foreach ( SectionRecipes::names() as $name ) {
            $tags = SectionRecipes::tags( $name ) ?? [];
            if ( ( $tags['role'] ?? null ) !== $role ) {
                continue;
            }
            $out[] = [
                'recipe'       => $name,
                'layout'       => (string) ( $tags['layout'] ?? '' ),
                'items'        => self::itemCount( $name ),
                'needs_images' => ! empty( $tags['needs_images'] ),
            ];
        }

        return $out;
    }

    /** How many repeated items (cards, steps, questions ...) a recipe has: its slots with the role item_title. */
    public static function itemCount( string $recipe ): int
    {
        $raw = SectionRecipes::rawMarkup( $recipe );
        if ( null === $raw ) {
            return 0;
        }
        $n = 0;
        foreach ( StudioSlots::extract( $raw ) as $slot ) {
            if ( 'item_title' === $slot['role'] ) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * The recipe of a role whose density is closest to $want (0 airy, 1 balanced, 2 dense), ties by catalog order.
     * Never an excluded recipe, one already in the stack, or (when another candidate exists) one whose layout tag
     * equals the previous section's. $only, when given, limits the candidates.
     *
     * @param ?list<string> $only
     * @param list<string> $taken
     */
    private static function closest( string $role, ?array $only, int $want, array $taken, ?string $prevLayout ): ?string
    {
        $best      = null;
        $bestScore = PHP_INT_MAX;
        foreach ( [ true, false ] as $avoidLayout ) {
            foreach ( SectionRecipes::names() as $name ) {
                $tags = SectionRecipes::tags( $name ) ?? [];
                if ( ( $tags['role'] ?? null ) !== $role || in_array( $name, $taken, true ) || isset( self::EXCLUDED[ $name ] ) ) {
                    continue;
                }
                if ( null !== $only && ! in_array( $name, $only, true ) ) {
                    continue;
                }
                if ( $avoidLayout && null !== $prevLayout && ( $tags['layout'] ?? null ) === $prevLayout ) {
                    continue;
                }
                $density = self::DENSITY_ORDER[ (string) ( $tags['density'] ?? 'balanced' ) ] ?? 1;
                $score   = abs( $density - $want );
                if ( $score < $bestScore ) {
                    $best      = $name;
                    $bestScore = $score;
                }
            }
            if ( null !== $best ) {
                break;
            }
        }

        return $best;
    }
}
