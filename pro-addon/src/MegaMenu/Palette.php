<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The colours of the two panel tones. Fixed hex values (no site colour tokens: a token the site does not have leaves a
 * colour undefined), chosen so every text/background pair below is at least 4.5:1 (PaletteTest checks all of them).
 */
final class Palette
{
    private const TONES = [
        'light' => [ 'bg' => '#ffffff', 'text' => '#1b2130', 'muted' => '#4a5468', 'line' => '#e3e7ee', 'card' => '#f3f5f9', 'accent' => '#1d4ed8', 'tint' => 'rgba(29, 78, 216, 0.10)', 'onAccent' => '#ffffff', 'shadow' => 'rgba(16, 24, 40, 0.18)' ],
        'dark'  => [ 'bg' => '#12161f', 'text' => '#f2f4f8', 'muted' => '#b4bccb', 'line' => '#2a3142', 'card' => '#1c2230', 'accent' => '#8fb3ff', 'tint' => 'rgba(143, 179, 255, 0.16)', 'onAccent' => '#0b1020', 'shadow' => 'rgba(0, 0, 0, 0.45)' ],
    ];

    /** @return array<string,string> */
    public static function tone( string $tone ): array
    {
        return self::TONES[ 'dark' === $tone ? 'dark' : 'light' ];
    }

    /** Pairs [foreground key, background key] that carry text and must stay readable (WCAG AA, 4.5:1). */
    public const TEXT_PAIRS = [
        [ 'text', 'bg' ], [ 'muted', 'bg' ], [ 'accent', 'bg' ],
        [ 'text', 'card' ], [ 'muted', 'card' ], [ 'accent', 'card' ],
        [ 'onAccent', 'accent' ],
    ];

    public static function contrast( string $fg, string $bg ): float
    {
        $l = static function ( string $hex ): float {
            $hex = ltrim( $hex, '#' );
            $c   = [ hexdec( substr( $hex, 0, 2 ) ) / 255, hexdec( substr( $hex, 2, 2 ) ) / 255, hexdec( substr( $hex, 4, 2 ) ) / 255 ];
            $c   = array_map( static fn ( float $v ): float => $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4, $c );

            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        $a = $l( $fg );
        $b = $l( $bg );

        return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
    }
}
