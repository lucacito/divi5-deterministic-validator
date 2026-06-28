<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Library of real, validated Divi 5 section recipes — whole proven sections the
 * AI copies and fills with the user's content, instead of composing from
 * primitives. Each recipe is a section block fragment extracted verbatim from a
 * real export (local image URLs swapped for picsum placeholders).
 *
 * Served by the get_section_recipes tool: no name => the catalog (small);
 * a name => that recipe's full block markup.
 */
final class SectionRecipes
{
    private const DATA = __DIR__ . '/../data/section-recipes.json';

    /** @return list<array{name:string,title:string,description:string,when:string,stage:string,markup:string}> */
    private static function all(): array
    {
        $raw = is_readable( self::DATA ) ? (string) file_get_contents( self::DATA ) : '';
        $data = json_decode( $raw, true );
        return is_array( $data ) ? $data : [];
    }

    /** Markdown catalog: names + descriptions, plus how to fetch one. */
    public static function catalog(): string
    {
        $lines = [
            '# Divi 5 Section Recipes',
            '',
            'Whole proven, validated sections. To build a page, pick the recipes you need,',
            'fetch each with get_section_recipes {"name":"<name>"}, then replace the example',
            'text and image URLs with the user\'s content. Place sections between the root',
            '<!-- wp:divi/placeholder --> ... <!-- /wp:divi/placeholder --> wrapper.',
            '',
            'Each recipe lists the persuasion _Stage_ it serves. Assemble a landing page by',
            'following the conversion flow in get_landing_guide and picking a recipe per stage.',
            '',
        ];
        foreach ( self::all() as $r ) {
            $stage = isset( $r['stage'] ) ? (string) $r['stage'] : 'Supporting';
            $lines[] = sprintf( '- **%s** — %s _Stage:_ %s. _When:_ %s', $r['name'], $r['description'], $stage, $r['when'] );
        }
        return implode( "\n", $lines );
    }

    /** Full block markup for one recipe, or null if the name is unknown. */
    public static function recipe(string $name): ?string
    {
        foreach ( self::all() as $r ) {
            if ( $r['name'] === $name ) {
                return $r['markup'];
            }
        }
        return null;
    }

    /** @return list<string> recipe names (used by tests) */
    public static function names(): array
    {
        return array_map( static fn( array $r ): string => $r['name'], self::all() );
    }
}
