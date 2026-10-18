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

    /** Every {{aied:...}} token of any kind (color, font, image or something else). */
    private const ANY_AIED = '/\{\{aied:[^{}]*\}\}/';

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
        preg_match_all( self::ANY, $text, $m );

        return array_values( $m[0] );
    }

    /**
     * Every {{aied:...}} token of any kind in $text (color, font, image, unknown), in order, repeats included.
     *
     * @return list<string>
     */
    public static function foundAll( string $text ): array
    {
        preg_match_all( self::ANY_AIED, $text, $m );

        return array_values( $m[0] );
    }

    /**
     * The advisory for content that still carries a token: each distinct token once, in order of first use (at most
     * MAX_LISTED), plus what to do. Null when there is none. Advisory only; it never changes a verdict or the content.
     *
     * @return ?array{tokens: list<string>, message: string}
     */
    public static function advise( string $text ): ?array
    {
        $tokens = array_values( array_unique( self::foundAll( $text ) ) );
        if ( [] === $tokens ) {
            return null;
        }

        return [ 'tokens' => array_slice( $tokens, 0, self::MAX_LISTED ), 'message' => self::UNRESOLVED_MESSAGE ];
    }
}
