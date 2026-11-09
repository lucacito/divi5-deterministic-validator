<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The Studio brief: a small JSON document that describes a page (one design language, optionally one style kit, an
 * ordered list of recipe sections with their words, links and pictures). check() validates it strictly and returns a
 * normalised copy, or a list of problems each with a path, a stable code and a message that tells the sender what to do.
 * Never throws and never warns, whatever it is given. Pure.
 *
 * Shape: {"version":1,"title":str,"goal":goal,"language":id,"kit":id?,"sections":[{"recipe":name,"slots":{id:str}?,"pictures":{id:{"pack":token}|{"url":str}}?}]}
 */
final class StudioBrief
{
    public const VERSION      = 1;
    public const MAX_SECTIONS = 20;
    public const MAX_TEXT     = 2000;
    public const MAX_PROBLEMS = 50;
    public const GOALS        = [ 'sell', 'book', 'signup', 'explain', 'showcase' ];

    /**
     * Retired recipe names => the recipe that replaces them, so old briefs keep working when a recipe is renamed.
     *
     * @var array<string,string>
     */
    public const RECIPE_ALIASES = [];

    /** The current name of a recipe (an alias is followed), or null when no such recipe exists. */
    public static function resolveRecipe( string $name ): ?string
    {
        $name = self::RECIPE_ALIASES[ $name ] ?? $name;

        return in_array( $name, SectionRecipes::names(), true ) ? $name : null;
    }

    /**
     * @return array{ok:bool,brief:?array<string,mixed>,problems:list<array{path:string,code:string,message:string}>}
     */
    public static function check( mixed $brief ): array
    {
        $problems = [];
        $out      = self::run( $brief, $problems );
        $ok       = [] === $problems;

        return [ 'ok' => $ok, 'brief' => $ok ? $out : null, 'problems' => $problems ];
    }

    /**
     * @param list<array{path:string,code:string,message:string}> $problems
     * @return array<string,mixed>
     */
    private static function run( mixed $brief, array &$problems ): array
    {
        if ( ! is_array( $brief ) || ( [] !== $brief && self::isList( $brief ) ) ) {
            self::add( $problems, '', 'NOT_AN_OBJECT', 'A brief is a JSON object with title, goal, language and sections.' );

            return [];
        }
        foreach ( array_keys( $brief ) as $key ) {
            if ( ! in_array( (string) $key, [ 'version', 'title', 'goal', 'language', 'kit', 'sections' ], true ) ) {
                self::add( $problems, (string) $key, 'UNKNOWN_KEY', 'Unknown key "' . self::cut( (string) $key ) . '". Allowed: version, title, goal, language, kit, sections.' );
            }
        }
        if ( array_key_exists( 'version', $brief ) && self::VERSION !== $brief['version'] ) {
            self::add( $problems, 'version', 'UNSUPPORTED_VERSION', 'The only supported version is ' . self::VERSION . '.' );
        }
        $out = [ 'version' => self::VERSION ];

        $title = self::title( $brief, $problems );
        if ( null !== $title ) {
            $out['title'] = $title;
        }
        $goal = self::choice( $brief, 'goal', self::GOALS, 'UNKNOWN_GOAL', $problems );
        if ( null !== $goal ) {
            $out['goal'] = $goal;
        }
        $language = self::choice( $brief, 'language', DesignLanguages::ids(), 'UNKNOWN_LANGUAGE', $problems );
        if ( null !== $language ) {
            $out['language'] = $language;
        }
        if ( array_key_exists( 'kit', $brief ) ) {
            $kit = self::choice( $brief, 'kit', StyleKits::ids(), 'UNKNOWN_KIT', $problems );
            if ( null !== $kit ) {
                $out['kit'] = $kit;
            }
        }
        $out['sections'] = self::sections( $brief, $problems );

        return $out;
    }

    /** @param list<array{path:string,code:string,message:string}> $problems */
    private static function title( array $brief, array &$problems ): ?string
    {
        if ( ! array_key_exists( 'title', $brief ) || null === $brief['title'] ) {
            self::add( $problems, 'title', 'MISSING', 'A brief needs a title (the page title, one line of plain text).' );

            return null;
        }
        if ( ! is_string( $brief['title'] ) ) {
            self::add( $problems, 'title', 'WRONG_TYPE', 'title must be a string.' );

            return null;
        }
        $parsed = PageTitle::parse( [ 'title' => $brief['title'] ] );
        if ( ! $parsed['ok'] || null === $parsed['title'] ) {
            self::add( $problems, 'title', 'INVALID_TITLE', (string) ( $parsed['error'] ?? 'title is not valid.' ) );

            return null;
        }

        return $parsed['title'];
    }

    /**
     * @param list<string> $allowed
     * @param list<array{path:string,code:string,message:string}> $problems
     */
    private static function choice( array $brief, string $key, array $allowed, string $code, array &$problems ): ?string
    {
        if ( ! array_key_exists( $key, $brief ) || null === $brief[ $key ] ) {
            self::add( $problems, $key, 'MISSING', "A brief needs a $key." );

            return null;
        }
        if ( ! is_string( $brief[ $key ] ) ) {
            self::add( $problems, $key, 'WRONG_TYPE', "$key must be a string." );

            return null;
        }
        if ( ! in_array( $brief[ $key ], $allowed, true ) ) {
            self::add( $problems, $key, $code, "Unknown $key \"" . self::cut( $brief[ $key ] ) . '". Valid: ' . implode( ', ', $allowed ) . '.' );

            return null;
        }

        return $brief[ $key ];
    }

    /**
     * @param list<array{path:string,code:string,message:string}> $problems
     * @return list<array<string,mixed>>
     */
    private static function sections( array $brief, array &$problems ): array
    {
        if ( ! array_key_exists( 'sections', $brief ) || null === $brief['sections'] ) {
            self::add( $problems, 'sections', 'MISSING', 'A brief needs sections: a list of {"recipe": name, "slots": {...}, "pictures": {...}}.' );

            return [];
        }
        $sections = $brief['sections'];
        if ( ! is_array( $sections ) || ( [] !== $sections && ! self::isList( $sections ) ) ) {
            self::add( $problems, 'sections', 'WRONG_TYPE', 'sections must be a list.' );

            return [];
        }
        if ( [] === $sections ) {
            self::add( $problems, 'sections', 'NO_SECTIONS', 'A brief needs at least one section.' );

            return [];
        }
        if ( count( $sections ) > self::MAX_SECTIONS ) {
            self::add( $problems, 'sections', 'TOO_MANY_SECTIONS', 'At most ' . self::MAX_SECTIONS . ' sections (got ' . count( $sections ) . ').' );

            return [];
        }
        $out = [];
        foreach ( $sections as $i => $section ) {
            $clean = self::section( $section, 'sections.' . $i, $problems );
            if ( null !== $clean ) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * @param list<array{path:string,code:string,message:string}> $problems
     * @return ?array<string,mixed>
     */
    private static function section( mixed $section, string $path, array &$problems ): ?array
    {
        if ( ! is_array( $section ) || ( [] !== $section && self::isList( $section ) ) ) {
            self::add( $problems, $path, 'WRONG_TYPE', 'A section is an object with a recipe.' );

            return null;
        }
        foreach ( array_keys( $section ) as $key ) {
            if ( ! in_array( (string) $key, [ 'recipe', 'slots', 'pictures' ], true ) ) {
                self::add( $problems, $path . '.' . $key, 'UNKNOWN_KEY', 'Unknown key "' . self::cut( (string) $key ) . '". Allowed: recipe, slots, pictures.' );
            }
        }
        if ( ! array_key_exists( 'recipe', $section ) || null === $section['recipe'] ) {
            self::add( $problems, $path . '.recipe', 'MISSING', 'A section needs a recipe name (see get_section_recipes).' );

            return null;
        }
        if ( ! is_string( $section['recipe'] ) ) {
            self::add( $problems, $path . '.recipe', 'WRONG_TYPE', 'recipe must be a string.' );

            return null;
        }
        $recipe = self::resolveRecipe( $section['recipe'] );
        if ( null === $recipe ) {
            self::add( $problems, $path . '.recipe', 'UNKNOWN_RECIPE', 'Unknown recipe "' . self::cut( $section['recipe'] ) . '". Use a name from get_section_recipes.' );

            return null;
        }
        $kinds = [];
        $ids   = [];
        foreach ( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $recipe ) ) as $slot ) {
            $kinds[ $slot['id'] ] = $slot['kind'];
            $ids[]                = $slot['id'];
        }
        $out = [ 'recipe' => $recipe ];
        $slots = self::slots( $section, $path, $recipe, $kinds, $ids, $problems );
        if ( [] !== $slots ) {
            $out['slots'] = $slots;
        }
        $pictures = self::pictures( $section, $path, $recipe, $kinds, $ids, $problems );
        if ( [] !== $pictures ) {
            $out['pictures'] = $pictures;
        }

        return $out;
    }

    /**
     * @param array<string,string> $kinds
     * @param list<string> $ids
     * @param list<array{path:string,code:string,message:string}> $problems
     * @return array<string,string>
     */
    private static function slots( array $section, string $path, string $recipe, array $kinds, array $ids, array &$problems ): array
    {
        $map = self::map( $section, 'slots', $path, $problems );
        $out = [];
        foreach ( $map as $id => $value ) {
            $id    = (string) $id;
            $where = $path . '.slots.' . $id;
            if ( ! isset( $kinds[ $id ] ) || 'picture' === $kinds[ $id ] ) {
                self::unknownSlot( $problems, $where, $id, $recipe, $ids, isset( $kinds[ $id ] ) ? ' It is a picture: put it under "pictures".' : '' );
                continue;
            }
            $text = self::text( $value, $where, $problems );
            if ( null === $text || '' === $text ) {
                continue;
            }
            if ( 'link' === $kinds[ $id ] ) {
                $code = StudioLinks::problem( $text );
                if ( null !== $code ) {
                    self::add( $problems, $where, $code, 'Not an allowed link: use https://, mailto:, tel:, #anchor or a path starting with /.' );
                    continue;
                }
            }
            $out[ $id ] = $text;
        }

        return $out;
    }

    /**
     * @param array<string,string> $kinds
     * @param list<string> $ids
     * @param list<array{path:string,code:string,message:string}> $problems
     * @return array<string,array<string,string>>
     */
    private static function pictures( array $section, string $path, string $recipe, array $kinds, array $ids, array &$problems ): array
    {
        $map    = self::map( $section, 'pictures', $path, $problems );
        $tokens = array_column( ImagePack::manifest()['images'], 'token' );
        $out    = [];
        foreach ( $map as $id => $value ) {
            $id    = (string) $id;
            $where = $path . '.pictures.' . $id;
            if ( ! isset( $kinds[ $id ] ) || 'picture' !== $kinds[ $id ] ) {
                self::unknownSlot( $problems, $where, $id, $recipe, $ids, isset( $kinds[ $id ] ) ? ' It is not a picture: put it under "slots".' : '' );
                continue;
            }
            if ( ! is_array( $value ) ) {
                self::add( $problems, $where, 'WRONG_TYPE', 'A picture is {"pack": token} or {"url": address}.' );
                continue;
            }
            if ( [ 'pack' ] === array_keys( $value ) && is_string( $value['pack'] ) ) {
                if ( ! in_array( $value['pack'], $tokens, true ) ) {
                    self::add( $problems, $where, 'UNKNOWN_PACK_IMAGE', 'Unknown pack image "' . self::cut( $value['pack'] ) . '". See get_image_guide for the tokens.' );
                    continue;
                }
                $out[ $id ] = [ 'pack' => $value['pack'] ];
            } elseif ( [ 'url' ] === array_keys( $value ) && is_string( $value['url'] ) ) {
                $url = trim( $value['url'] );
                if ( '' === $url ) {
                    continue;
                }
                if ( 1 !== preg_match( '~^(https://|/)~i', $url ) ) {
                    self::add( $problems, $where, 'PICTURE_INVALID', 'A picture address is an https:// address or a path on this site starting with /.' );
                    continue;
                }
                $code = StudioLinks::problem( $url );
                if ( null !== $code ) {
                    self::add( $problems, $where, $code, 'Not an allowed picture address.' );
                    continue;
                }
                $out[ $id ] = [ 'url' => $url ];
            } else {
                self::add( $problems, $where, 'PICTURE_INVALID', 'A picture is exactly one of {"pack": token} or {"url": address}.' );
            }
        }

        return $out;
    }

    /**
     * The optional map under $section[$key] (slots or pictures); a non-object value is a problem and gives an empty map.
     *
     * @param list<array{path:string,code:string,message:string}> $problems
     * @return array<string|int,mixed>
     */
    private static function map( array $section, string $key, string $path, array &$problems ): array
    {
        if ( ! array_key_exists( $key, $section ) || null === $section[ $key ] ) {
            return [];
        }
        if ( ! is_array( $section[ $key ] ) || ( [] !== $section[ $key ] && self::isList( $section[ $key ] ) ) ) {
            self::add( $problems, $path . '.' . $key, 'WRONG_TYPE', "$key must be an object (slot id => value)." );

            return [];
        }

        return $section[ $key ];
    }

    /** @param list<array{path:string,code:string,message:string}> $problems */
    private static function text( mixed $value, string $where, array &$problems ): ?string
    {
        if ( ! is_string( $value ) ) {
            self::add( $problems, $where, 'WRONG_TYPE', 'A slot value is a string.' );

            return null;
        }
        if ( strlen( $value ) > self::MAX_TEXT * 4 ) {
            self::add( $problems, $where, 'TEXT_TOO_LONG', 'At most ' . self::MAX_TEXT . ' characters.' );

            return null;
        }
        if ( 1 !== preg_match( '//u', $value ) ) {
            self::add( $problems, $where, 'INVALID_UTF8', 'The text must be valid UTF-8.' );

            return null;
        }
        $text = preg_replace( '/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value ) ?? '';
        if ( preg_match_all( '/./us', $text ) > self::MAX_TEXT ) {
            self::add( $problems, $where, 'TEXT_TOO_LONG', 'At most ' . self::MAX_TEXT . ' characters.' );

            return null;
        }

        return $text;
    }

    /**
     * @param list<string> $ids the recipe's own slot ids
     * @param list<array{path:string,code:string,message:string}> $problems
     */
    private static function unknownSlot( array &$problems, string $where, string $id, string $recipe, array $ids, string $hint ): void
    {
        $others = array_slice( array_values( array_diff( StudioSlots::recipesWithSlot( $id ), [ $recipe ] ) ), 0, 5 );
        $msg    = 'Recipe ' . $recipe . ' has no slot "' . self::cut( $id ) . '".' . $hint . ' Its slots: ' . implode( ', ', array_slice( $ids, 0, 40 ) ) . ( count( $ids ) > 40 ? ', ...' : '' ) . '.'
            . ( [] === $others ? '' : ' Recipes that do have it: ' . implode( ', ', $others ) . '.' );
        self::add( $problems, $where, 'UNKNOWN_SLOT', $msg );
    }

    /** @param list<array{path:string,code:string,message:string}> $problems */
    private static function add( array &$problems, string $path, string $code, string $message ): void
    {
        if ( count( $problems ) < self::MAX_PROBLEMS ) {
            $problems[] = [ 'path' => $path, 'code' => $code, 'message' => $message ];
        }
    }

    /** @param array<mixed> $a */
    private static function isList( array $a ): bool
    {
        return array_keys( $a ) === range( 0, count( $a ) - 1 );
    }

    /** A user-supplied string shortened for a message. */
    private static function cut( string $s ): string
    {
        $s = preg_replace( '/[^\x20-\x7E]/', '?', $s ) ?? '';

        return strlen( $s ) > 60 ? substr( $s, 0, 57 ) . '...' : $s;
    }
}
