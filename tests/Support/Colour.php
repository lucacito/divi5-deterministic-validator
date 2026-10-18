<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

use AiEditorDivi5\WP\DesignLanguages;

require_once __DIR__ . '/../../wp-plugin/src/DesignLanguages.php';

/**
 * Colour helpers shared by the contrast tests (dev/test-side only, not shipped): read a hex or rgba() colour,
 * composite a translucent colour over an opaque one, and measure WCAG contrast with the plugin's own formula.
 */
final class Colour
{
    /** "r,g,b" or "r,g,b,a" of a hex or rgba() colour as [r,g,b,a], or null when unreadable. */
    public static function rgba( string $c ): ?array
    {
        $c = trim( $c );
        if ( 1 === preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $c, $m ) ) {
            $h = $m[1];
            if ( 3 === strlen( $h ) ) {
                $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            }
            return [ hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ), 1.0 ];
        }
        if ( 1 === preg_match( '/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([0-9.]+)\s*)?\)$/i', $c, $m ) ) {
            return [ (int) $m[1], (int) $m[2], (int) $m[3], isset( $m[4] ) ? (float) $m[4] : 1.0 ];
        }
        return null;
    }

    /** $colour as an opaque hex: an rgba() colour is composited over $under (whose own alpha is ignored). */
    public static function opaque( string $colour, string $under ): ?string
    {
        $c = self::rgba( $colour );
        $b = self::rgba( $under );
        if ( null === $c || null === $b ) {
            return null;
        }
        $mix = static fn ( int $f, int $g ): int => (int) round( $f * $c[3] + $g * ( 1 - $c[3] ) );
        return sprintf( '#%02x%02x%02x', $mix( $c[0], $b[0] ), $mix( $c[1], $b[1] ), $mix( $c[2], $b[2] ) );
    }

    /** WCAG contrast ratio of two opaque hex colours (the plugin's own DesignLanguages::contrast). */
    public static function contrast( string $fg, string $bg ): float
    {
        return DesignLanguages::contrast( $fg, $bg );
    }
}
