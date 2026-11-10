<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Edits of one section of a Lab brief: merge slot values in, or swap the section's recipe.
 *
 * No production code calls this yet: it is the server-side twin of the screen's merge and swap (studio-core.js, pinned
 * against it by StudioScreenTest), kept for plan 3's assistant tool, which edits stored briefs on the server.
 *
 * Pure: a brief goes
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
     * Change a section's recipe. A value is kept only when the new recipe has a slot with the same id AND the same role
     * and kind as the slot the value was written for (slot ids are positional: `heading.0` is an eyebrow in one recipe
     * and the headline in another); every other value is dropped and listed. An unknown recipe or section changes
     * nothing.
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
        $new      = self::roleAndKind( $raw );
        $oldName  = is_string( $brief['sections'][ $section ]['recipe'] ?? null ) ? StudioBrief::resolveRecipe( $brief['sections'][ $section ]['recipe'] ) : null;
        $oldRaw   = null === $oldName ? null : SectionRecipes::rawMarkup( $oldName );
        $oldSlots = null === $oldRaw ? [] : self::roleAndKind( $oldRaw );
        $old      = $brief['sections'][ $section ];
        $slots    = [];
        $pictures = [];
        $kept     = [];
        $dropped  = [];
        foreach ( [ 'slots' => &$slots, 'pictures' => &$pictures ] as $key => &$target ) {
            foreach ( (array) ( $old[ $key ] ?? [] ) as $id => $value ) {
                $id = (string) $id;
                if ( isset( $new[ $id ], $oldSlots[ $id ] ) && $new[ $id ] === $oldSlots[ $id ] ) {
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
     * Slot id => "role/kind" of every slot of a raw recipe.
     *
     * @return array<string,string>
     */
    private static function roleAndKind( string $raw ): array
    {
        $out = [];
        foreach ( StudioSlots::extract( $raw ) as $slot ) {
            $out[ $slot['id'] ] = $slot['role'] . '/' . $slot['kind'];
        }

        return $out;
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
