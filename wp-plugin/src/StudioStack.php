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
     * Role order per goal. The first is always the hero; sell, book and signup end on a closing call to action.
     *
     * @var array<string,list<string>>
     */
    public const STACKS = [
        'sell'     => [ 'hero', 'features', 'stats', 'testimonials', 'pricing', 'faq', 'cta' ],
        'book'     => [ 'hero', 'features', 'process', 'testimonials', 'faq', 'cta' ],
        'signup'   => [ 'hero', 'features', 'proof', 'testimonials', 'cta' ],
        'explain'  => [ 'hero', 'content', 'features', 'process', 'faq' ],
        'showcase' => [ 'hero', 'gallery', 'testimonials', 'team', 'contact' ],
    ];

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
        foreach ( self::STACKS[ $goal ] as $role ) {
            $pick = self::closest( $role, $want, $stack );
            if ( null !== $pick ) {
                $stack[] = $pick;
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
     * The recipe of a role whose density is closest to $want (0 airy, 1 balanced, 2 dense), ties by catalog order,
     * never one already in the stack.
     *
     * @param list<string> $taken
     */
    private static function closest( string $role, int $want, array $taken ): ?string
    {
        $best      = null;
        $bestScore = PHP_INT_MAX;
        foreach ( SectionRecipes::names() as $name ) {
            $tags = SectionRecipes::tags( $name ) ?? [];
            if ( ( $tags['role'] ?? null ) !== $role || in_array( $name, $taken, true ) ) {
                continue;
            }
            $density = self::DENSITY_ORDER[ (string) ( $tags['density'] ?? 'balanced' ) ] ?? 1;
            $score   = abs( $density - $want );
            if ( $score < $bestScore ) {
                $best      = $name;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
