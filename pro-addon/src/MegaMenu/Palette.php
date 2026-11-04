<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The colours of the two panel tones. Fixed hex values (no site colour tokens: a token the site does not have leaves a
 * colour undefined), chosen so every text/background pair below is at least 4.5:1 (PaletteTest checks all of them).
 * `surface` is the colour of a tile that sits on a translucent `backdrop` or `wash` (the two are never a text background:
 * text only ever sits on an opaque tile, the intro panel or the panel itself). `gradFrom`/`gradTo` are the two stops of the accent
 * gradient (intro panel, promo card, icon chips); `panelFrom`/`panelTo` the faint top-to-bottom gradient of the panel itself;
 * `glow` and `soft` the tinted shadow of a lifted tile and the resting shadow.
 */
final class Palette
{
    private const TONES = [
        'light' => [ 'bg' => '#ffffff', 'text' => '#1b2130', 'muted' => '#4a5468', 'line' => '#e3e7ee', 'card' => '#f3f5f9', 'accent' => '#1d4ed8', 'tint' => 'rgba(29, 78, 216, 0.10)', 'onAccent' => '#ffffff', 'shadow' => 'rgba(16, 24, 40, 0.18)', 'onAccentHover' => '#dbe6ff', 'surface' => '#ffffff', 'backdrop' => 'rgba(18, 40, 110, 0.90)', 'wash' => 'rgba(238, 241, 247, 0.97)', 'panelFrom' => '#ffffff', 'panelTo' => '#f2f5fb', 'gradFrom' => '#1d4ed8', 'gradTo' => '#6d28d9', 'glow' => 'rgba(29, 78, 216, 0.26)', 'soft' => 'rgba(16, 24, 40, 0.10)', 'backdropTo' => 'rgba(76, 29, 148, 0.92)' ],
        'dark'  => [ 'bg' => '#12161f', 'text' => '#f2f4f8', 'muted' => '#b4bccb', 'line' => '#2a3142', 'card' => '#1c2230', 'accent' => '#8fb3ff', 'tint' => 'rgba(143, 179, 255, 0.16)', 'onAccent' => '#0b1020', 'shadow' => 'rgba(0, 0, 0, 0.45)', 'onAccentHover' => '#1d2a52', 'surface' => '#1c2230', 'backdrop' => 'rgba(6, 9, 16, 0.92)', 'wash' => 'rgba(18, 22, 31, 0.97)', 'panelFrom' => '#12161f', 'panelTo' => '#1a2134', 'gradFrom' => '#8fb3ff', 'gradTo' => '#c4b5fd', 'glow' => 'rgba(143, 179, 255, 0.32)', 'soft' => 'rgba(0, 0, 0, 0.40)', 'backdropTo' => 'rgba(26, 20, 62, 0.94)' ],
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
        [ 'onAccent', 'accent' ], [ 'onAccentHover', 'accent' ],
        [ 'text', 'surface' ], [ 'muted', 'surface' ], [ 'accent', 'surface' ],
        [ 'onAccent', 'gradFrom' ], [ 'onAccent', 'gradTo' ], [ 'gradFrom', 'onAccent' ], [ 'gradFrom', 'onAccentHover' ], [ 'onAccentHover', 'gradFrom' ], [ 'onAccentHover', 'gradTo' ],
        [ 'text', 'panelFrom' ], [ 'text', 'panelTo' ], [ 'muted', 'panelFrom' ], [ 'muted', 'panelTo' ], [ 'accent', 'panelFrom' ], [ 'accent', 'panelTo' ],
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
