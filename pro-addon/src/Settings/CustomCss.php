<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The AI's CSS lives inside one clearly marked block of WordPress "Additional CSS"; the owner's own CSS outside it
 * is never touched. Pure string functions: the block is spliced with substr, never with a regex replacement
 * string, so `$1` and `\0` in the CSS stay literal.
 */
final class CustomCss
{
    // The same markers the 3.x premium tool wrote, so an old managed block is replaced in place.
    public const BEGIN     = '/* BEGIN AI Editor for Divi 5 — managed block (safe to edit/remove) */';
    public const END       = '/* END AI Editor for Divi 5 */';
    public const MAX_BYTES = 100000;

    /** @return string|null why this CSS is refused, or null when it is acceptable */
    public static function problem( string $css ): ?string
    {
        // Trim only whitespace: trim()'s default list also strips NUL bytes, which would hide a control character at the edge.
        $css = trim( $css, " \t\n\r" );
        if ( '' === $css ) {
            return 'The CSS is empty.';
        }
        if ( strlen( $css ) > self::MAX_BYTES ) {
            return 'The CSS is larger than ' . self::MAX_BYTES . ' bytes.';
        }
        if ( str_contains( $css, self::BEGIN ) || str_contains( $css, self::END ) ) {
            return 'The CSS contains the managed-block markers; send only the CSS itself (the markers are added for you).';
        }
        if ( 1 === preg_match( '/<\s*\/?\s*(?:style|script)\b|<!--/i', $css ) ) {
            return 'The CSS contains HTML (a style or script tag, or an HTML comment). Send plain CSS only.';
        }
        if ( 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $css ) ) {
            return 'The CSS contains control characters.';
        }

        return null;
    }

    /** The CSS inside the managed block, or null when there is no complete block. */
    public static function extract( string $all ): ?string
    {
        $b = strpos( $all, self::BEGIN );
        if ( false === $b ) {
            return null;
        }
        $start = $b + strlen( self::BEGIN );
        $e     = strpos( $all, self::END, $start );

        return false === $e ? null : trim( substr( $all, $start, $e - $start ) );
    }

    /** Put $css in the managed block (created or replaced in place); null removes the block. Everything else is kept as it was. */
    public static function apply( string $all, ?string $css ): string
    {
        $b = strpos( $all, self::BEGIN );
        $e = false === $b ? false : strpos( $all, self::END, $b + strlen( self::BEGIN ) );

        if ( null === $css ) {
            if ( false === $e ) {
                return $all;
            }
            $head = rtrim( substr( $all, 0, $b ) );
            $tail = ltrim( substr( $all, $e + strlen( self::END ) ) );

            return $head . ( '' !== $head && '' !== $tail ? "\n\n" : '' ) . $tail;
        }

        $block = self::BEGIN . "\n" . trim( $css ) . "\n" . self::END;
        if ( false !== $e ) {
            return substr( $all, 0, $b ) . $block . substr( $all, $e + strlen( self::END ) );
        }

        return ( '' !== trim( $all ) ? rtrim( $all ) . "\n\n" : '' ) . $block;
    }
}
