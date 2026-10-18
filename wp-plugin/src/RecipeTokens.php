<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/DesignLanguages.php';

/**
 * Colour and font tokens in section recipes: {{aied:color:<role>}} and {{aied:font:<role>}}.
 *
 * A recipe stores tokens where its look depends on the design language; they are resolved when the
 * recipe is SERVED, so the AI always receives hard values and a saved page never contains a token.
 * Pure: no site access. A token with an unknown role is left as it is (found() reports it), never
 * guessed.
 */
final class RecipeTokens
{
    /** Token role => key in the design language's `colors`. */
    private const COLOR_ROLES = [
        'bg'            => 'bg',
        'surface'       => 'surface',
        'surface-glass' => 'surface_glass',
        'text'          => 'text',
        'muted'         => 'muted',
        'accent'        => 'accent',
        'accent-text'   => 'accent_text',
        'border'        => 'border',
    ];

    /** Token role => key in the design language's `fonts`. */
    private const FONT_ROLES = [ 'display' => 'display', 'body' => 'body', 'eyebrow' => 'eyebrow' ];

    /** Every colour or font token, whatever its role. */
    private const ANY = '/\{\{aied:(?:color|font):[^{}]*\}\}/';

    /** Anything that looks like a token, however malformed (closed braces, no nested braces). Only used to DETECT, never echoed. */
    private const LOOSE_AIED = '/\{\{aied:[^{}]*\}\}/';

    /** A well-formed token of any kind: lower-case kind, then lower-case role or name (color, font, image, or one this code does not know). */
    private const STRICT_AIED = '/\{\{aied:[a-z]+:[a-z0-9-]+\}\}/';

    /** A listed token longer than this many characters is not echoed (it counts as malformed). */
    public const MAX_TOKEN_LENGTH = 64;

    /** Listed instead of any malformed token: a fixed text, never the content's own. */
    public const MALFORMED_ENTRY = '{{aied:...}}';

    /** At most this many distinct tokens are listed in advise(). */
    public const MAX_LISTED = 20;

    public const UNRESOLVED_MESSAGE = "This layout still contains the placeholder tokens listed in tokens. A token is not a real value: Divi will not understand it, so a color or font would be wrong and an image would not load. It is valid and saved as is; this is only a hint. Fetch the recipe again through get_section_recipes (the recipe it returns has every token already replaced with a real value, and you can pass a design language), or replace each listed token with a real value yourself.";

    /** A well-formed colour or font token (role characters only). */
    private const TOKEN = '/\{\{aied:(color|font):([a-z-]+)\}\}/';

    /**
     * The language used when none is requested: the fields of the `editorial` language, so a recipe
     * served without a language never carries a raw token. Falls back to a fixed copy if the data
     * file is unreadable.
     *
     * @return array<string,mixed>
     */
    public static function default(): array
    {
        $editorial = DesignLanguages::get( 'editorial' );
        if ( null !== $editorial ) {
            return $editorial;
        }

        return [
            'id'     => 'editorial',
            'colors' => [
                'bg' => '#faf7f2', 'surface' => '#ffffff', 'text' => '#1c1a17', 'muted' => '#6b645a',
                'accent' => '#9a3b1f', 'accent_text' => '#ffffff', 'border' => '#e3dccf',
            ],
            'fonts'  => [ 'display' => 'Playfair Display', 'body' => 'Source Sans 3', 'eyebrow' => 'Source Sans 3' ],
        ];
    }

    /**
     * Replace every colour and font token whose role is known with the language's value.
     * `surface-glass` uses the language's `surface_glass` and falls back to its `surface`.
     *
     * @param array<string,mixed> $language a design language as DesignLanguages::get() returns it
     */
    public static function resolve( string $markup, array $language ): string
    {
        $colors = isset( $language['colors'] ) && is_array( $language['colors'] ) ? $language['colors'] : [];
        $fonts  = isset( $language['fonts'] ) && is_array( $language['fonts'] ) ? $language['fonts'] : [];

        return (string) preg_replace_callback(
            self::TOKEN,
            static function ( array $m ) use ( $colors, $fonts ): string {
                if ( 'color' === $m[1] ) {
                    $key = self::COLOR_ROLES[ $m[2] ] ?? null;
                    if ( null === $key ) {
                        return $m[0];
                    }
                    $value = $colors[ $key ] ?? null;
                    if ( ( ! is_string( $value ) || '' === $value ) && 'surface_glass' === $key ) {
                        $value = $colors['surface'] ?? null;
                    }
                } else {
                    $key = self::FONT_ROLES[ $m[2] ] ?? null;
                    if ( null === $key ) {
                        return $m[0];
                    }
                    $value = $fonts[ $key ] ?? null;
                }

                return is_string( $value ) && '' !== $value ? $value : $m[0];
            },
            $markup
        );
    }

    /**
     * Every colour or font token left in $text, in order, repeats included, unknown roles too.
     *
     * @return list<string>
     */
    public static function found( string $text ): array
    {
        return self::matches( self::ANY, $text );
    }

    /**
     * Every well-formed {{aied:kind:name}} token of any kind in $text (lower-case kind and name: color, font, image, unknown),
     * in order, repeats included. Malformed ones are not returned (see advise()).
     *
     * @return list<string>
     */
    public static function foundAll( string $text ): array
    {
        return self::matches( self::STRICT_AIED, $text );
    }

    /**
     * The advisory for content that still carries a token: each distinct well-formed token once, in order of first use, at most
     * MAX_LISTED, each at most MAX_TOKEN_LENGTH characters, plus what to do. Anything that merely LOOKS like a token but is not
     * well-formed (spaces, newlines, upper case, over-long, unknown shape) still triggers the advisory, but only as the fixed
     * entry MALFORMED_ENTRY, listed once after the valid ones: nothing the content wrote is echoed unless it matches the strict
     * pattern, so the list is bounded (at most MAX_LISTED + 1 entries of MAX_TOKEN_LENGTH characters). Null when there is no
     * token at all. An unterminated "{{aied:" is not a token. Advisory only; it never changes a verdict or the content.
     *
     * @return ?array{tokens: list<string>, message: string}
     */
    public static function advise( string $text ): ?array
    {
        $loose = self::matches( self::LOOSE_AIED, $text );
        if ( [] === $loose ) {
            return null;
        }
        $tokens    = [];
        $malformed = false;
        foreach ( $loose as $candidate ) {
            $strict = preg_match( self::STRICT_AIED, $candidate );
            if ( false === $strict ) {
                return null; // the regex engine failed: say nothing rather than guess
            }
            if ( 1 === $strict && strlen( $candidate ) <= self::MAX_TOKEN_LENGTH ) {
                $tokens[ $candidate ] = true;
            } else {
                $malformed = true;
            }
        }
        $list = array_slice( array_keys( $tokens ), 0, self::MAX_LISTED );
        if ( $malformed ) {
            $list[] = self::MALFORMED_ENTRY;
        }

        return [ 'tokens' => $list, 'message' => self::UNRESOLVED_MESSAGE ];
    }

    /**
     * @return list<string> every match of $pattern; empty when the regex engine fails (never a TypeError in a save)
     */
    private static function matches( string $pattern, string $text ): array
    {
        $m = [];
        if ( false === preg_match_all( $pattern, $text, $m ) ) {
            return [];
        }

        return array_values( $m[0] ?? [] );
    }
}
