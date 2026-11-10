<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Edits of one section of a Divi 5 Lab brief: merge slot values in, or swap the section's recipe. Pure: a brief goes
 * in, a new brief comes out, nothing else is touched (other sections, language, kit, title and goal stay as given).
 * The result is not validated here; StudioBrief::check() does that, so a value that is too long or a link that is not
 * allowed is reported there with its own code.
 */
final class StudioMerge
{
    /**
     * Merge slot values into one section. A text or link slot takes a string; a picture slot takes a
     * {"pack":..}/{"url":..} object. An empty string clears a slot (the key is removed, so the recipe's sample copy
     * returns). A slot id the section's recipe does not have, or a value of the wrong kind, is listed in `ignored`
     * and not written.
     *
     * @param array<string,mixed> $brief
     * @param array<string,mixed> $incoming slot id => value
     * @return array{brief:array<string,mixed>,applied:list<string>,ignored:list<string>}
     */
    public static function slots( array $brief, int $section, array $incoming ): array
    {
        $applied = [];
        $ignored = [];
        $kinds   = self::kinds( $brief, $section );
        if ( null === $kinds ) {
            return [ 'brief' => $brief, 'applied' => [], 'ignored' => array_map( 'strval', array_keys( $incoming ) ) ];
        }
        $slots    = $brief['sections'][ $section ]['slots'] ?? [];
        $pictures = $brief['sections'][ $section ]['pictures'] ?? [];
        foreach ( $incoming as $id => $value ) {
            $id   = (string) $id;
            $kind = $kinds[ $id ] ?? null;
            if ( null === $kind ) {
                $ignored[] = $id;
                continue;
            }
            if ( 'picture' === $kind ) {
                if ( '' === $value ) {
                    unset( $pictures[ $id ] );
                } elseif ( is_array( $value ) ) {
                    $pictures[ $id ] = $value;
                } else {
                    $ignored[] = $id;
                    continue;
                }
            } elseif ( ! is_string( $value ) ) {
                $ignored[] = $id;
                continue;
            } elseif ( '' === $value ) {
                unset( $slots[ $id ] );
            } else {
                $slots[ $id ] = $value;
            }
            $applied[] = $id;
        }
        $brief['sections'][ $section ] = self::withMaps( $brief['sections'][ $section ], $slots, $pictures );

        return [ 'brief' => $brief, 'applied' => $applied, 'ignored' => $ignored ];
    }

    /**
     * Change a section's recipe. Values whose slot id also exists in the new recipe are kept; the rest are dropped and
     * listed. An unknown recipe or section changes nothing.
     *
     * @param array<string,mixed> $brief
     * @return array{brief:array<string,mixed>,kept:list<string>,dropped:list<string>}
     */
    public static function swapRecipe( array $brief, int $section, string $newRecipe ): array
    {
        $name = StudioBrief::resolveRecipe( $newRecipe );
        $raw  = null === $name ? null : SectionRecipes::rawMarkup( $name );
        if ( null === $name || null === $raw || ! isset( $brief['sections'][ $section ] ) || ! is_array( $brief['sections'][ $section ] ) ) {
            return [ 'brief' => $brief, 'kept' => [], 'dropped' => [] ];
        }
        $ids      = array_column( StudioSlots::extract( $raw ), 'id' );
        $old      = $brief['sections'][ $section ];
        $slots    = [];
        $pictures = [];
        $kept     = [];
        $dropped  = [];
        foreach ( [ 'slots' => &$slots, 'pictures' => &$pictures ] as $key => &$target ) {
            foreach ( (array) ( $old[ $key ] ?? [] ) as $id => $value ) {
                $id = (string) $id;
                if ( in_array( $id, $ids, true ) ) {
                    $target[ $id ] = $value;
                    $kept[]        = $id;
                } else {
                    $dropped[] = $id;
                }
            }
        }
        unset( $target );
        $old['recipe']                 = $name;
        $brief['sections'][ $section ] = self::withMaps( $old, $slots, $pictures );

        return [ 'brief' => $brief, 'kept' => $kept, 'dropped' => $dropped ];
    }

    /**
     * Slot id => kind for the recipe of a section, or null when the section or its recipe does not exist.
     *
     * @param array<string,mixed> $brief
     * @return ?array<string,string>
     */
    private static function kinds( array $brief, int $section ): ?array
    {
        $recipe = $brief['sections'][ $section ]['recipe'] ?? null;
        $name   = is_string( $recipe ) ? StudioBrief::resolveRecipe( $recipe ) : null;
        $raw    = null === $name ? null : SectionRecipes::rawMarkup( $name );
        if ( null === $raw ) {
            return null;
        }

        return array_column( StudioSlots::extract( $raw ), 'kind', 'id' );
    }

    /**
     * The section with its `slots` and `pictures` maps set, or removed when empty.
     *
     * @param array<string,mixed> $section
     * @param array<string,mixed> $slots
     * @param array<string,mixed> $pictures
     * @return array<string,mixed>
     */
    private static function withMaps( array $section, array $slots, array $pictures ): array
    {
        unset( $section['slots'], $section['pictures'] );
        if ( [] !== $slots ) {
            $section['slots'] = $slots;
        }
        if ( [] !== $pictures ) {
            $section['pictures'] = $pictures;
        }

        return $section;
    }
}
