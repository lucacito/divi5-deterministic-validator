<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Writes site-wide custom CSS (WordPress "Additional CSS"). Safe — CSS cannot
 * execute code; the worst case is visual. The AI's CSS lives inside a clearly
 * marked managed block so the site owner's own custom CSS outside it is never
 * touched, and re-runs are idempotent (the block is replaced, not duplicated).
 */
final class CustomCss
{
    private const BEGIN = '/* BEGIN AI Editor for Divi 5 — managed block (safe to edit/remove) */';
    private const END   = '/* END AI Editor for Divi 5 */';

    /** @return array{updated:bool, bytes?:int, error?:string} */
    public static function set(string $css): array
    {
        $css      = trim( $css );
        $existing = (string) wp_get_custom_css();
        $block    = self::BEGIN . "\n" . $css . "\n" . self::END;

        if ( strpos( $existing, self::BEGIN ) !== false && strpos( $existing, self::END ) !== false ) {
            $pattern  = '/' . preg_quote( self::BEGIN, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '/s';
            $existing = (string) preg_replace( $pattern, $block, $existing, 1 );
        } else {
            $existing = ( $existing !== '' ? rtrim( $existing ) . "\n\n" : '' ) . $block;
        }

        $result = wp_update_custom_css_post( $existing );
        if ( is_wp_error( $result ) ) {
            return ['updated' => false, 'error' => $result->get_error_message()];
        }
        return ['updated' => true, 'bytes' => strlen( $css )];
    }
}
