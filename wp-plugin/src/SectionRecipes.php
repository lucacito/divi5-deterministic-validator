<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Library of real, validated Divi 5 section recipes — whole proven sections the
 * AI copies and fills with the user's content, instead of composing from
 * primitives. Each recipe is a section block fragment extracted verbatim from a
 * real export (local image URLs swapped for local image tokens, resolved at serve time to
 * the plugin's bundled image pack).
 *
 * Served by the get_section_recipes tool: no name => the catalog (small);
 * a name => that recipe's full block markup.
 */
final class SectionRecipes
{
    private const DATA = __DIR__ . '/../data/section-recipes.json';

    /** What a recipe is for (controlled vocabulary of the `role` tag). */
    public const ROLES = [ 'hero', 'proof', 'features', 'stats', 'testimonials', 'pricing', 'team', 'faq', 'process', 'cta', 'gallery', 'contact', 'header', 'transition', 'content', 'shop', 'blog' ];

    /** How a recipe is arranged (controlled vocabulary of the `layout` tag). */
    public const LAYOUTS = [ 'centered', 'split', 'grid', 'bento', 'zigzag', 'stacked', 'carousel', 'overlap', 'band', 'list', 'form' ];

    /**
     * Mood words a recipe can carry (the `mood` tag is a list of these). 'any' fits every design.
     *
     * @return list<string>
     */
    public static function moods(): array
    {
        return [ 'any' ];
    }

    /** @return list<array{name:string,title:string,description:string,when:string,stage:string,tags?:array<string,mixed>,markup:string}> */
    private static function all(): array
    {
        $raw = is_readable( self::DATA ) ? (string) file_get_contents( self::DATA ) : '';
        $data = json_decode( $raw, true );
        return is_array( $data ) ? $data : [];
    }

    /**
     * Markdown catalog.
     *
     * No filters: a compact list (recipe names under role headings, a few words each) plus how to
     * fetch one. With a role, layout and/or mood filter: the full one-line description of the
     * matching recipes only. An unknown filter value, or a filter nothing matches, returns a
     * message that lists the valid values.
     */
    public static function catalog( ?string $role = null, ?string $layout = null, ?string $mood = null ): string
    {
        $role   = self::cleanFilter( $role );
        $layout = self::cleanFilter( $layout );
        $mood   = self::cleanFilter( $mood );

        if ( null === $role && null === $layout && null === $mood ) {
            return self::compactCatalog();
        }

        $problems = [];
        if ( null !== $role && ! in_array( $role, self::ROLES, true ) ) {
            $problems[] = sprintf( 'Unknown role "%s". Valid roles: %s.', $role, implode( ', ', self::ROLES ) );
        }
        if ( null !== $layout && ! in_array( $layout, self::LAYOUTS, true ) ) {
            $problems[] = sprintf( 'Unknown layout "%s". Valid layouts: %s.', $layout, implode( ', ', self::LAYOUTS ) );
        }
        if ( null !== $mood && ! in_array( $mood, self::moods(), true ) ) {
            $problems[] = sprintf( 'Unknown mood "%s". Valid moods: %s.', $mood, implode( ', ', self::moods() ) );
        }
        if ( $problems ) {
            return implode( "\n", $problems );
        }

        $lines = [];
        foreach ( self::all() as $r ) {
            $tags = isset( $r['tags'] ) && is_array( $r['tags'] ) ? $r['tags'] : [];
            if ( null !== $role && ( $tags['role'] ?? null ) !== $role ) {
                continue;
            }
            if ( null !== $layout && ( $tags['layout'] ?? null ) !== $layout ) {
                continue;
            }
            if ( null !== $mood ) {
                $moods = isset( $tags['mood'] ) && is_array( $tags['mood'] ) ? $tags['mood'] : [];
                if ( ! in_array( $mood, $moods, true ) && ! in_array( 'any', $moods, true ) ) {
                    continue;
                }
            }
            $stage   = isset( $r['stage'] ) ? (string) $r['stage'] : 'Supporting';
            $lines[] = sprintf( '- **%s** — %s _Stage:_ %s. _When:_ %s', $r['name'], $r['description'], $stage, $r['when'] );
        }

        $applied = [];
        foreach ( [ 'role' => $role, 'layout' => $layout, 'mood' => $mood ] as $k => $v ) {
            if ( null !== $v ) {
                $applied[] = $k . '=' . $v;
            }
        }
        if ( ! $lines ) {
            return sprintf(
                "No recipe matches %s.\nValid roles: %s.\nValid layouts: %s.\nValid moods: %s.\nRoles that have recipes: %s.",
                implode( ', ', $applied ),
                implode( ', ', self::ROLES ),
                implode( ', ', self::LAYOUTS ),
                implode( ', ', self::moods() ),
                implode( ', ', self::rolesInUse() )
            );
        }
        return implode( "\n", array_merge(
            [
                '# Divi 5 Section Recipes (' . implode( ', ', $applied ) . ')',
                '',
                'Fetch one with get_section_recipes {"name":"<name>"}.',
                '',
            ],
            $lines
        ) );
    }

    /** Trim and lower-case a filter value, keeping it short and plain; empty means no filter. */
    private static function cleanFilter( ?string $value ): ?string
    {
        if ( null === $value ) {
            return null;
        }
        $value = strtolower( trim( $value ) );
        $value = substr( (string) preg_replace( '/[^a-z0-9 _-]/', '', $value ), 0, 40 );
        return '' === $value ? null : $value;
    }

    /** @return list<string> roles that at least one recipe carries, in ROLES order */
    private static function rolesInUse(): array
    {
        $used = [];
        foreach ( self::all() as $r ) {
            $used[ (string) ( $r['tags']['role'] ?? '' ) ] = true;
        }
        return array_values( array_filter( self::ROLES, static fn ( string $role ): bool => isset( $used[ $role ] ) ) );
    }

    /** The default catalog: names under role headings, a few words each, and how to fetch one. */
    private static function compactCatalog(): string
    {
        $lines = [
            '# Divi 5 Section Recipes',
            '',
            'Whole proven, validated sections. Pick the recipes you need, fetch each with',
            'get_section_recipes {"name":"<name>"}, replace the example text with the user\'s content and',
            'swap the example images for Media Library images (list_media_images) when suitable ones exist.',
            'Place sections between the root <!-- wp:divi/placeholder --> ... <!-- /wp:divi/placeholder --> wrapper.',
            'Filter with get_section_recipes {"role":"hero"}, {"layout":"split"} or {"mood":"any"}: that lists',
            'only the matches, each with its full description, _Stage:_ and _When:_.',
            '',
            'The preset and color ids inside the recipe markup come from a demo site and may not exist',
            'on this site. Call list_global_styles and swap them for this site\'s own ids, or remove the',
            '"modulePreset" attribute (a module without one gets this site\'s default preset).',
            '',
            'Persuasion stages the recipes serve (flow in get_landing_guide): Hero, Problem, Solution, Benefits,',
            'Social proof, Final CTA, Resources. Pick a recipe per stage.',
        ];

        $byRole = [];
        foreach ( self::all() as $r ) {
            $role              = (string) ( $r['tags']['role'] ?? 'content' );
            $layout            = (string) ( $r['tags']['layout'] ?? '' );
            $byRole[ $role ][] = sprintf( '- %s — %s%s', $r['name'], $r['title'], '' !== $layout ? ' (' . $layout . ')' : '' );
        }
        foreach ( self::ROLES as $role ) {
            if ( isset( $byRole[ $role ] ) ) {
                $lines[] = '';
                $lines[] = '## ' . $role;
                array_push( $lines, ...$byRole[ $role ] );
            }
        }
        return implode( "\n", $lines );
    }

    /**
     * The tags of one recipe (role, layout, mood, density, background, needs_images), or null
     * when the name is unknown or the recipe carries none.
     *
     * @return array<string,mixed>|null
     */
    public static function tags( string $name ): ?array
    {
        foreach ( self::all() as $r ) {
            if ( $r['name'] === $name ) {
                return isset( $r['tags'] ) && is_array( $r['tags'] ) ? $r['tags'] : null;
            }
        }
        return null;
    }

    /**
     * Names of recipes whose layout and role are not in the avoid lists, in catalog order
     * (deterministic), at most $limit.
     *
     * @param list<string> $avoidLayouts
     * @param list<string> $avoidRoles
     * @return list<string>
     */
    public static function suggest( array $avoidLayouts, array $avoidRoles, int $limit = 3 ): array
    {
        $out = [];
        foreach ( self::all() as $r ) {
            if ( count( $out ) >= $limit ) {
                break;
            }
            $tags = isset( $r['tags'] ) && is_array( $r['tags'] ) ? $r['tags'] : null;
            if ( null === $tags || in_array( $tags['layout'] ?? null, $avoidLayouts, true ) || in_array( $tags['role'] ?? null, $avoidRoles, true ) ) {
                continue;
            }
            $out[] = $r['name'];
        }
        return $out;
    }

    /** Full block markup for one recipe, or null if the name is unknown. */
    public static function recipe(string $name): ?string
    {
        foreach ( self::all() as $r ) {
            if ( $r['name'] === $name ) {
                return ImageTokens::resolve(
                    $r['markup'],
                    self::imageBaseUrl(),
                    null,
                    static fn ( ?string $u, string $t ): ?string => apply_filters( 'jhmg_aied_image_token', $u, $t )
                );
            }
        }
        return null;
    }

    /** The stored markup of one recipe with image tokens left unresolved (dev tooling and tests), or null. */
    public static function rawMarkup(string $name): ?string
    {
        foreach ( self::all() as $r ) {
            if ( $r['name'] === $name ) {
                return (string) $r['markup'];
            }
        }
        return null;
    }

    /** Site-local URL of the bundled image pack directory ('' outside WordPress). */
    private static function imageBaseUrl(): string
    {
        return defined( 'AI_EDITOR_DIVI5_FILE' ) ? plugins_url( 'assets/images/', AI_EDITOR_DIVI5_FILE ) : '';
    }

    /** @return list<string> recipe names (used by tests) */
    public static function names(): array
    {
        return array_map( static fn( array $r ): string => $r['name'], self::all() );
    }
}
