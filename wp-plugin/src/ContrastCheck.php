<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Finds text that DEFINITELY cannot be read, in a Divi 5 layout, and says plainly what it could not judge.
 *
 * The rule is no false positives: a pair is reported only when both colours are plain values written in the layout
 * (#rgb, #rrggbb, rgb() or rgba() with full opacity) and the background is a solid colour of the text's own module or of the
 * nearest ancestor that sets one, with nothing in between that could paint over it (an image, a gradient or a video on any of
 * those blocks stops the search). Anything else is counted under a reason and never reported as a problem:
 *  - a colour that is a global colour id, a variable or a name (the site's own settings decide it),
 *  - text with no colour of its own (the theme decides it),
 *  - no background anywhere above the text (the page background is the theme's),
 *  - a semi-transparent colour, and any image, gradient or video background.
 *
 * Only the desktop value is read. It is a failure when the ratio is below 3:1 (unreadable at any size), or below 4.5:1 for text
 * whose size is written in px and is under 18px (the ordinary-size rule of WCAG 2.x AA). Between those the answer depends on
 * a size we do not know, so it is not reported. Hover states are not read.
 *
 * Deterministic: the same markup always gives the same answer. Nothing is changed and nothing is blocked.
 */
final class ContrastCheck
{
    public const MAX_BYTES = 524288;

    /** Below this ratio no text size is readable. */
    public const HARD_FLOOR = 3.0;

    /** Ordinary-size text must reach this (WCAG 2.x AA). */
    public const NORMAL = 4.5;

    /** Smallest px size that WCAG 2.x calls large only when bold; we treat nothing below this as large. */
    private const LARGE_PX = 18.0;

    private const BG = 'module.decoration.background.desktop.value';

    /**
     * Each text element: module => list of [label, text colour path, font size path, own-background path or null].
     * An own-background path is a fill painted behind just that element (a button); null means the text sits on the module's
     * own background or an ancestor's.
     */
    private const TEXTS = [
        'divi/heading' => [
            [ 'heading', 'title.decoration.font.font.desktop.value.color', 'title.decoration.font.font.desktop.value.size', null ],
        ],
        'divi/text' => [
            [ 'text', 'content.decoration.bodyFont.body.font.desktop.value.color', 'content.decoration.bodyFont.body.font.desktop.value.size', null ],
            [ 'text heading 2', 'content.decoration.headingFont.h2.font.desktop.value.color', 'content.decoration.headingFont.h2.font.desktop.value.size', null ],
            [ 'text heading 3', 'content.decoration.headingFont.h3.font.desktop.value.color', 'content.decoration.headingFont.h3.font.desktop.value.size', null ],
            [ 'text heading 4', 'content.decoration.headingFont.h4.font.desktop.value.color', 'content.decoration.headingFont.h4.font.desktop.value.size', null ],
        ],
        'divi/blurb' => [
            [ 'title', 'title.decoration.font.font.desktop.value.color', 'title.decoration.font.font.desktop.value.size', null ],
            [ 'text', 'content.decoration.bodyFont.body.font.desktop.value.color', 'content.decoration.bodyFont.body.font.desktop.value.size', null ],
        ],
        'divi/button' => [
            [ 'button label', 'button.decoration.font.font.desktop.value.color', 'button.decoration.font.font.desktop.value.size', 'button.decoration.background.desktop.value.color' ],
        ],
    ];

    /**
     * @return array{status:string, checked:int, problems:list<array<string,mixed>>, not_checked:array<string,int>, message:string}
     *         status: "ok" (everything it could judge is readable), "problems" or "not_checked" (it could judge nothing).
     */
    public static function check( string $content ): array
    {
        $empty = static fn ( string $why ): array => [ 'status' => 'not_checked', 'checked' => 0, 'problems' => [], 'not_checked' => [ $why => 1 ], 'message' => self::message( 'not_checked', 0, 0, [ $why => 1 ] ) ];
        if ( '' === trim( $content ) || strlen( $content ) > self::MAX_BYTES ) {
            return $empty( '' === trim( $content ) ? 'no_content' : 'too_large' );
        }
        $parsed = ( new BlockParser() )->parse( $content );
        if ( ! $parsed->isOk() || null === $parsed->root() ) {
            return $empty( 'not_parseable' );
        }

        $problems   = [];
        $checked    = 0;
        $notChecked = [];
        $note       = static function ( string $reason ) use ( &$notChecked ): void {
            $notChecked[ $reason ] = ( $notChecked[ $reason ] ?? 0 ) + 1;
        };

        $walk = static function ( Block $block, array $ancestors, string $path ) use ( &$walk, &$problems, &$checked, $note ): void {
            $name = $block->name();
            foreach ( self::TEXTS[ $name ] ?? [] as [ $label, $colorPath, $sizePath, $ownFill ] ) {
                $textRaw = $block->attr( $colorPath );
                if ( null === $textRaw || '' === $textRaw ) {
                    // No colour of its own: only worth counting where the module has a text at all.
                    continue;
                }
                $text = self::parse( $textRaw );
                if ( null === $text ) {
                    $note( self::isTransparent( $textRaw ) ? 'transparent_color' : 'color_is_not_a_plain_value' );
                    continue;
                }
                $bg = self::background( $block, $ancestors, $ownFill );
                if ( is_string( $bg ) ) {
                    $note( $bg );
                    continue;
                }
                ++$checked;
                $ratio = self::ratio( $text, $bg['rgb'] );
                $size  = self::px( $block->attr( $sizePath ) );
                $fails = $ratio < self::HARD_FLOOR || ( $ratio < self::NORMAL && null !== $size && $size < self::LARGE_PX );
                if ( $fails ) {
                    $problems[] = [
                        'code'       => 'LOW_CONTRAST',
                        'block'      => $path,
                        'module'     => $name,
                        'element'    => $label,
                        'text_color' => self::hex( $text ),
                        'background' => self::hex( $bg['rgb'] ),
                        'on'         => $bg['from'],
                        'ratio'      => round( $ratio, 2 ),
                        'needs'      => $ratio < self::HARD_FLOOR ? self::HARD_FLOOR : self::NORMAL,
                        'font_size'  => null === $size ? null : $size . 'px',
                    ];
                }
            }
            $next = $ancestors;
            $next[] = $block;
            foreach ( $block->children() as $i => $child ) {
                $walk( $child, $next, $path . '/' . $child->name() . '[' . $i . ']' );
            }
        };
        $walk( $parsed->root(), [], $parsed->root()->name() );

        $status = [] !== $problems ? 'problems' : ( $checked > 0 ? 'ok' : 'not_checked' );
        ksort( $notChecked );

        return [ 'status' => $status, 'checked' => $checked, 'problems' => $problems, 'not_checked' => $notChecked, 'message' => self::message( $status, $checked, count( $problems ), $notChecked ) ];
    }

    /**
     * The solid background behind a block's text: its own button fill, else the block's own background, else the nearest
     * ancestor's. Returns the colour, or a reason string when it cannot be known for sure.
     *
     * @param list<Block> $ancestors outermost first
     * @return array{rgb:array{0:int,1:int,2:int}, from:string}|string
     */
    private static function background( Block $block, array $ancestors, ?string $ownFill ): array|string
    {
        if ( null !== $ownFill ) {
            $fill = $block->attr( $ownFill );
            if ( null !== $fill && '' !== $fill ) {
                $rgb = self::parse( $fill );

                return null === $rgb ? ( self::isTransparent( $fill ) ? 'transparent_color' : 'color_is_not_a_plain_value' ) : [ 'rgb' => $rgb, 'from' => 'the button fill' ];
            }
        }
        $chain = array_merge( [ $block ], array_reverse( $ancestors ) );
        foreach ( $chain as $i => $candidate ) {
            if ( self::mayOverlap( $candidate ) ) {
                return 'positioned_or_overlapping';
            }
            $bg = $candidate->attr( self::BG );
            if ( ! is_array( $bg ) || [] === $bg ) {
                continue;
            }
            if ( self::paintsOver( $bg ) ) {
                return 'background_is_an_image_gradient_or_video';
            }
            $color = $bg['color'] ?? null;
            if ( null === $color || '' === $color ) {
                continue;
            }
            $rgb = self::parse( $color );
            if ( null === $rgb ) {
                return self::isTransparent( $color ) ? 'transparent_color' : 'color_is_not_a_plain_value';
            }

            return [ 'rgb' => $rgb, 'from' => 0 === $i ? 'the module\'s own background' : 'the background of ' . $candidate->name() ];
        }

        return 'no_background_above_the_text';
    }

    /** A block that is positioned, or pulled over its neighbour by a negative margin, may sit on something other than its parent's colour. */
    private static function mayOverlap( Block $block ): bool
    {
        $position = $block->attr( 'module.decoration.position' );
        if ( is_array( $position ) && [] !== $position ) {
            return true;
        }
        $spacing = $block->attr( 'module.decoration.spacing' );

        return is_array( $spacing ) && str_contains( (string) json_encode( $spacing ), ':"-' );
    }

    /** Anything that can be drawn over or instead of a flat colour. @param array<mixed> $bg */
    private static function paintsOver( array $bg ): bool
    {
        $image = $bg['image'] ?? null;
        if ( is_array( $image ) && [] !== array_filter( $image, static fn ( $v ): bool => is_string( $v ) ? '' !== trim( $v ) : ! empty( $v ) ) ) {
            return true;
        }
        $gradient = $bg['gradient'] ?? null;
        if ( is_array( $gradient ) && 'on' === ( $gradient['enabled'] ?? null ) ) {
            return true;
        }
        $video = $bg['video'] ?? null;

        return is_array( $video ) && [] !== array_filter( $video, static fn ( $v ): bool => is_string( $v ) ? '' !== trim( $v ) : ! empty( $v ) );
    }

    /** A plain, fully opaque colour as [r, g, b], or null. */
    public static function parse( mixed $value ): ?array
    {
        if ( ! is_string( $value ) ) {
            return null;
        }
        $v = strtolower( trim( $value ) );
        if ( 1 === preg_match( '/^#([0-9a-f]{3})$/', $v, $m ) ) {
            return [ hexdec( $m[1][0] . $m[1][0] ), hexdec( $m[1][1] . $m[1][1] ), hexdec( $m[1][2] . $m[1][2] ) ];
        }
        if ( 1 === preg_match( '/^#([0-9a-f]{6})$/', $v, $m ) ) {
            return [ hexdec( substr( $m[1], 0, 2 ) ), hexdec( substr( $m[1], 2, 2 ) ), hexdec( substr( $m[1], 4, 2 ) ) ];
        }
        if ( 1 === preg_match( '/^#([0-9a-f]{6})ff$/', $v, $m ) ) {
            return [ hexdec( substr( $m[1], 0, 2 ) ), hexdec( substr( $m[1], 2, 2 ) ), hexdec( substr( $m[1], 4, 2 ) ) ];
        }
        if ( 1 === preg_match( '/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(1|1\.0+|100%)\s*)?\)$/', $v, $m ) && (int) $m[1] <= 255 && (int) $m[2] <= 255 && (int) $m[3] <= 255 ) {
            return [ (int) $m[1], (int) $m[2], (int) $m[3] ];
        }

        return null;
    }

    /** A colour written as a plain value that is not fully opaque. */
    private static function isTransparent( mixed $value ): bool
    {
        if ( ! is_string( $value ) ) {
            return false;
        }
        $v = strtolower( trim( $value ) );

        return 1 === preg_match( '/^#[0-9a-f]{8}$/', $v ) || 1 === preg_match( '/^rgba\(/', $v ) || 'transparent' === $v;
    }

    /** WCAG 2.x contrast ratio of two [r, g, b] colours. @param array{0:int,1:int,2:int} $a @param array{0:int,1:int,2:int} $b */
    public static function ratio( array $a, array $b ): float
    {
        $la = self::luminance( $a );
        $lb = self::luminance( $b );

        return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function luminance( array $rgb ): float
    {
        $lin = static function ( int $c ): float {
            $v = $c / 255;

            return $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
        };

        return 0.2126 * $lin( $rgb[0] ) + 0.7152 * $lin( $rgb[1] ) + 0.0722 * $lin( $rgb[2] );
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private static function hex( array $rgb ): string
    {
        return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
    }

    /** A size written in px as a number, else null (em, rem, vw, clamp and variables are not guessed). */
    private static function px( mixed $value ): ?float
    {
        if ( is_string( $value ) && 1 === preg_match( '/^\s*(\d+(?:\.\d+)?)px\s*$/i', $value, $m ) ) {
            return (float) $m[1];
        }

        return null;
    }

    /** @param array<string,int> $notChecked */
    private static function message( string $status, int $checked, int $problems, array $notChecked ): string
    {
        $skipped = array_sum( $notChecked );
        if ( 'problems' === $status ) {
            return sprintf( '%d text pair(s) cannot be read; each is listed with the colours and the ratio it needs. %d pair(s) were judged and %d could not be (colours the site\'s settings decide, images, gradients).', $problems, $checked, $skipped );
        }
        if ( 'ok' === $status ) {
            return sprintf( 'Every text pair that could be judged is readable (%d judged, %d could not be: colours the site\'s settings decide, images, gradients, no background found).', $checked, $skipped );
        }

        return 'No text pair could be judged: the colours are set by the site\'s settings or the theme, or sit on images or gradients. This is not a pass.';
    }
}
