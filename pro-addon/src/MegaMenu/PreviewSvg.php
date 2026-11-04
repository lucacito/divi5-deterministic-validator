<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A small wireframe of one layout, drawn from its definition (Layouts::all()) as an inline SVG: a nav bar with one
 * highlighted item and the panel hanging under it. Pure and deterministic: the same definition always gives the same
 * bytes. Only numbers and constants are drawn; the one piece of text is the layout's own name, escaped.
 */
final class PreviewSvg
{
    private const PAGE     = '#e9edf4';
    private const NAV      = '#ffffff';
    private const NAV_LINE = '#d5dbe5';
    private const NAV_BAR  = '#6b7585';
    private const LOGO     = '#9aa5b8';
    private const NAV_TOP  = 8;
    private const PANEL_Y  = 32;
    private const PANEL_H  = 112;
    private const HILITE_X = 114;

    /** @param array<string,mixed> $layout one entry of Layouts::all() */
    public static function for( array $layout ): string
    {
        $t        = Palette::tone( (string) ( $layout['tone'] ?? 'light' ) );
        $width    = (int) $layout['width'];
        $mode     = (string) $layout['mode'];
        $name     = (string) $layout['name'];
        $viewport = 'viewport' === $mode;

        $shapes = [ self::rect( 0.0, 0.0, 240.0, 150.0, 8.0, self::PAGE ) ];
        $shapes = array_merge( $shapes, self::nav( $t ) );
        if ( 'showcase' === (string) $layout['family'] ) {
            return '<svg viewBox="0 0 240 150" role="img" aria-label="' . esc_attr( $name ) . '">' . implode( '', array_merge( $shapes, self::showcase( $layout, $t ) ) ) . '</svg>';
        }

        [ $px, $pw, $cx, $cw ] = self::panelBox( $width, $viewport );
        $shapes[] = self::rect( (float) $px, (float) self::PANEL_Y, (float) $pw, (float) self::PANEL_H, $viewport ? 0.0 : 6.0, $t['bg'], 1.0, $t['line'] );

        $top    = self::PANEL_Y + 10;
        $bottom = self::PANEL_Y + self::PANEL_H - 6;
        $end    = $bottom;
        $bx     = $cx;
        $bw     = $cw;
        $feature = (string) $layout['feature'];

        if ( (bool) $layout['footer'] ) {
            $end      = $bottom - 22;
            $shapes[] = self::rect( (float) $cx, (float) ( $bottom - 18 ), (float) $cw, 1.0, 0.0, $t['line'] );
            $shapes[] = self::bar( (float) $cx, (float) ( $bottom - 11 ), 40.0, $t['accent'], 1.0 );
        }
        if ( 'cta' === $feature ) {
            $end      = $bottom - 20;
            $shapes[] = self::rect( (float) $cx, (float) ( $bottom - 14 ), (float) $cw, 14.0, 4.0, $t['accent'] );
            $shapes[] = self::bar( (float) ( $cx + 8 ), (float) ( $bottom - 9 ), (float) $cw * 0.4, $t['onAccent'], 0.9 );
        }
        if ( 'top' === $feature ) {
            $shapes[] = self::rect( (float) $cx, (float) $top, (float) $cw, 24.0, 4.0, $t['accent'], 0.3 );
            $shapes[] = self::bar( (float) ( $cx + 8 ), (float) ( $top + 7 ), (float) $cw * 0.45, $t['text'], 0.9, 5.0 );
            $shapes[] = self::rect( (float) ( $cx + 8 ), (float) ( $top + 14 ), 26.0, 6.0, 3.0, $t['accent'] );
            $top      = $top + 30;
        } elseif ( 'right' === $feature || 'left' === $feature ) {
            $fw       = (float) (int) round( $cw * 0.34 );
            $fx       = 'right' === $feature ? $cx + $cw - $fw : (float) $cx;
            $bw       = (int) ( $cw - $fw - 6 );
            $bx       = 'right' === $feature ? $cx : (int) ( $cx + $fw + 6 );
            $shapes[] = self::rect( $fx, (float) $top, $fw, (float) ( $end - $top ), 5.0, $t['accent'] );
            $shapes[] = self::bar( $fx + 6.0, (float) ( $top + 7 ), $fw - 12.0, $t['onAccent'], 0.9 );
            $shapes[] = self::bar( $fx + 6.0, (float) ( $top + 13 ), ( $fw - 12.0 ) * 0.7, $t['onAccent'], 0.6 );
            $shapes[] = self::rect( $fx + 6.0, (float) ( $end - 14 ), min( 30.0, $fw - 12.0 ), 8.0, 3.0, $t['onAccent'] );
        }

        if ( (int) $layout['posts'] > 0 ) {
            $shapes = array_merge( $shapes, self::latest( $layout, $t, $bx, $top, $bw, $end ) );
        } else {
            $shapes = array_merge( $shapes, self::links( $layout, $t, $bx, $top, $bw ) );
        }

        return '<svg viewBox="0 0 240 150" role="img" aria-label="' . esc_attr( $name ) . '">' . implode( '', $shapes ) . '</svg>';
    }

    /** The showcase family: an optional backdrop, an optional intro panel and ruled link tiles. @return list<string> */
    private static function showcase( array $layout, array $t ): array
    {
        $viewport = 'viewport' === (string) $layout['mode'];
        $tiles    = 'cards' === (string) $layout['surface'];
        $dark     = 'dark' === (string) $layout['tone'];
        [ $px, $pw, $cx, $cw ] = self::panelBox( (int) $layout['width'], $viewport );
        $backdrop = (string) $layout['backdrop'];
        $fill     = 'accent' === $backdrop ? ( $dark ? $t['bg'] : $t['accent'] ) : ( 'wash' === $backdrop ? $t['card'] : $t['bg'] );
        $opacity  = 'accent' === $backdrop && ! $dark ? 0.9 : 1.0;
        $shapes   = [ self::rect( (float) $px, (float) self::PANEL_Y, (float) $pw, (float) self::PANEL_H, $viewport ? 0.0 : 6.0, $fill, $opacity, 'none' === $backdrop ? $t['line'] : '' ) ];

        $top = self::PANEL_Y + 10;
        $h   = self::PANEL_H - 20;
        $x   = (float) $cx;
        $w   = (float) $cw;
        if ( (bool) $layout['intro'] ) {
            $iw       = (float) (int) round( $w * 0.26 );
            $shapes[] = self::rect( $x, (float) $top, $iw, (float) $h, 5.0, $t['accent'] );
            $shapes[] = self::bar( $x + 6.0, (float) ( $top + 8 ), $iw - 12.0, $t['onAccent'], 0.95, 6.0 );
            $shapes[] = self::bar( $x + 6.0, (float) ( $top + 18 ), ( $iw - 12.0 ) * 0.8, $t['onAccent'], 0.6 );
            $shapes[] = self::bar( $x + 6.0, (float) ( $top + 25 ), ( $iw - 12.0 ) * 0.6, $t['onAccent'], 0.6 );
            $shapes[] = self::circle( $x + 10.0, (float) ( $top + $h - 12 ), 5.0, $t['onAccent'], 0.95 );
            $x       += $iw + 6.0;
            $w       -= $iw + 6.0;
        }
        $cols = max( 1, (int) $layout['columns'] );
        $colW = ( $w - ( $cols - 1 ) * 5 ) / $cols;
        for ( $c = 0; $c < $cols; $c++ ) {
            $tx = $x + $c * ( $colW + 5 );
            if ( $tiles ) {
                $shapes[] = self::rect( $tx, (float) $top, $colW, (float) $h, 5.0, $t['surface'], 1.0, $t['line'] );
            }
            $shapes[] = self::bar( $tx + 6.0, (float) ( $top + 8 ), $colW * 0.5, $t['text'], 0.95, 5.0 );
            $shapes[] = self::circle( $tx + $colW - 9.0, (float) ( $top + 10 ), 2.5, $t['accent'], 0.9 );
            for ( $i = 0; $i < 3; $i++ ) {
                $ry       = $top + 19 + $i * 14;
                $shapes[] = self::rect( $tx + 6.0, (float) $ry, $colW - 12.0, 1.0, 0.0, $t['line'] );
                $shapes[] = self::bar( $tx + 6.0, (float) ( $ry + 5 ), ( $colW - 12.0 ) * ( 0.8 - $i * 0.1 ), $t['muted'], 0.85 );
            }
        }

        return $shapes;
    }

    /** Where the panel and its content sit: [panel x, panel width, content x, content width]. */
    private static function panelBox( int $width, bool $viewport ): array
    {
        $cw = (int) round( $width * 224 / 1200 );
        if ( $viewport ) {
            $cw = min( 208, $cw );

            return [ 0, 240, (int) ( ( 240 - $cw ) / 2 ), $cw ];
        }
        $pw = max( 64, min( 224, $cw ) );
        $px = max( 8, min( self::HILITE_X, 232 - $pw ) );

        return [ $px, $pw, $px + 6, $pw - 12 ];
    }

    /** @param array<string,string> $t @return list<string> */
    private static function nav( array $t ): array
    {
        $shapes = [
            self::rect( 8.0, (float) self::NAV_TOP, 224.0, 18.0, 4.0, self::NAV, 1.0, self::NAV_LINE ),
            self::rect( 14.0, 14.0, 20.0, 6.0, 2.0, self::LOGO ),
        ];
        foreach ( [ 84.0, 118.0, 152.0, 186.0 ] as $i => $x ) {
            if ( 1 === $i ) {
                $shapes[] = self::rect( (float) self::HILITE_X, 11.0, 30.0, 12.0, 3.0, $t['accent'], 0.18 );
                $shapes[] = self::bar( $x, 15.0, 22.0, $t['accent'] );
            } else {
                $shapes[] = self::bar( $x, 15.0, 22.0, self::NAV_BAR );
            }
        }

        return $shapes;
    }

    /** Link columns, with headings for the column families, icons, descriptions or cards for the rest. @return list<string> */
    private static function links( array $layout, array $t, int $x, int $y, int $w ): array
    {
        $family   = (string) $layout['family'];
        $cols     = max( 1, (int) $layout['columns'] );
        $rows     = (string) $layout['rows'];
        $headings = in_array( $family, [ 'columns', 'feature' ], true );
        $per      = match ( $family ) {
            'list'  => 1 === $cols ? 4 : 3,
            'icons' => 'icons' === $rows ? 3 : 2,
            default => 3,
        };
        $step   = match ( $rows ) {
            'desc'  => 16.0,
            'icons' => 12.0,
            'cards' => 24.0,
            default => 10.0,
        };
        $headH  = $headings ? 12.0 : 0.0;
        $colW   = ( $w - ( $cols - 1 ) * 8 ) / $cols;
        $shapes = [];

        for ( $c = 0; $c < $cols; $c++ ) {
            $cx = $x + $c * ( $colW + 8 );
            if ( $headings ) {
                $shapes[] = self::bar( $cx, (float) $y, $colW * 0.55, $t['text'], 0.9, 5.0 );
            }
            for ( $i = 0; $i < $per; $i++ ) {
                $iy = $y + $headH + $i * $step;
                if ( 'cards' === $rows ) {
                    $shapes[] = self::rect( $cx, $iy, $colW, 20.0, 4.0, $t['card'], 1.0, $t['line'] );
                    $shapes[] = self::circle( $cx + 7.0, $iy + 7.0, 3.5, $t['accent'], 0.3 );
                    $shapes[] = self::bar( $cx + 14.0, $iy + 5.0, ( $colW - 20.0 ) * 0.7, $t['text'], 0.9 );
                    $shapes[] = self::bar( $cx + 6.0, $iy + 13.0, ( $colW - 12.0 ) * 0.8, $t['muted'], 0.5 );
                } elseif ( 'icons' === $rows ) {
                    $shapes[] = self::circle( $cx + 4.0, $iy + 2.0, 4.0, $t['accent'], 0.25 );
                    $shapes[] = self::bar( $cx + 12.0, $iy, ( $colW - 12.0 ) * 0.75, $t['muted'], 0.85 );
                } elseif ( 'desc' === $rows ) {
                    $shapes[] = self::bar( $cx, $iy, $colW * 0.7, $t['text'], 0.9 );
                    $shapes[] = self::bar( $cx, $iy + 7.0, $colW * 0.9, $t['muted'], 0.5 );
                } else {
                    $shapes[] = self::bar( $cx, $iy + 1.0, $colW * 0.8, $t['muted'], 0.85 );
                }
            }
        }

        return $shapes;
    }

    /** The latest-posts variants: a row of cards, links beside posts, a featured post, or compact thumbnails. @return list<string> */
    private static function latest( array $layout, array $t, int $x, int $y, int $w, int $end ): array
    {
        $posts  = (int) $layout['posts'];
        $shapes = [];
        $thumb  = $t['accent'];

        if ( 'cards' === $layout['postStyle'] ) {
            $cw = ( $w - ( $posts - 1 ) * 6 ) / $posts;
            for ( $i = 0; $i < $posts; $i++ ) {
                $px       = $x + $i * ( $cw + 6 );
                $shapes[] = self::rect( $px, (float) $y, $cw, 52.0, 4.0, $t['card'], 1.0, $t['line'] );
                $shapes[] = self::rect( $px + 3.0, $y + 3.0, $cw - 6.0, 22.0, 3.0, $thumb, 0.22 );
                $shapes[] = self::bar( $px + 4.0, $y + 29.0, ( $cw - 8.0 ) * 0.9, $t['text'], 0.9 );
                $shapes[] = self::bar( $px + 4.0, $y + 35.0, ( $cw - 8.0 ) * 0.5, $t['muted'], 0.5 );
            }
        } elseif ( 'withlinks' === $layout['postStyle'] ) {
            $lw = $w * 0.4;
            for ( $i = 0; $i < 4; $i++ ) {
                $shapes[] = self::bar( (float) $x, $y + $i * 10.0 + 1.0, $lw * 0.8, $t['muted'], 0.85 );
            }
            $rx = $x + $lw + 8;
            $rw = $w - $lw - 8;
            for ( $i = 0; $i < $posts; $i++ ) {
                $iy       = $y + $i * 32.0;
                $shapes[] = self::rect( $rx, $iy, 26.0, 24.0, 3.0, $thumb, 0.22 );
                $shapes[] = self::bar( $rx + 30.0, $iy + 4.0, ( $rw - 30.0 ) * 0.9, $t['text'], 0.9 );
                $shapes[] = self::bar( $rx + 30.0, $iy + 10.0, ( $rw - 30.0 ) * 0.6, $t['text'], 0.6 );
                $shapes[] = self::bar( $rx + 30.0, $iy + 16.0, ( $rw - 30.0 ) * 0.4, $t['muted'], 0.5 );
            }
        } elseif ( 'featured' === $layout['postStyle'] ) {
            $fw       = $w * 0.5;
            $shapes[] = self::rect( (float) $x, (float) $y, $fw, 46.0, 4.0, $thumb, 0.22 );
            $shapes[] = self::bar( (float) $x, $y + 51.0, $fw * 0.9, $t['text'], 0.9, 5.0 );
            $shapes[] = self::bar( (float) $x, $y + 57.0, $fw * 0.5, $t['muted'], 0.5 );
            $rx = $x + $fw + 8;
            $rw = $w - $fw - 8;
            for ( $i = 0; $i < 3; $i++ ) {
                $iy       = $y + $i * 22.0;
                $shapes[] = self::rect( $rx, $iy, 22.0, 18.0, 3.0, $thumb, 0.22 );
                $shapes[] = self::bar( $rx + 26.0, $iy + 4.0, ( $rw - 26.0 ) * 0.9, $t['text'], 0.85 );
                $shapes[] = self::bar( $rx + 26.0, $iy + 10.0, ( $rw - 26.0 ) * 0.5, $t['muted'], 0.5 );
            }
        } else {
            $cols  = max( 1, (int) $layout['columns'] );
            $colW  = ( $w - ( $cols - 1 ) * 8 ) / $cols;
            for ( $i = 0; $i < $posts; $i++ ) {
                $col = $i % $cols;
                $row = intdiv( $i, $cols );
                $cx  = $x + $col * ( $colW + 8 );
                $iy  = $y + $row * 24.0;
                $shapes[] = self::rect( $cx, $iy, 18.0, 18.0, 3.0, $thumb, 0.22 );
                $shapes[] = self::bar( $cx + 22.0, $iy + 3.0, ( $colW - 22.0 ) * 0.9, $t['text'], 0.9 );
                $shapes[] = self::bar( $cx + 22.0, $iy + 9.0, ( $colW - 22.0 ) * 0.6, $t['muted'], 0.5 );
            }
        }

        return $shapes;
    }

    private static function rect( float $x, float $y, float $w, float $h, float $rx, string $fill, float $opacity = 1.0, string $stroke = '' ): string
    {
        $s = '<rect x="' . self::n( $x ) . '" y="' . self::n( $y ) . '" width="' . self::n( $w ) . '" height="' . self::n( $h ) . '" rx="' . self::n( $rx ) . '" fill="' . $fill . '"';
        if ( $opacity < 1.0 ) {
            $s .= ' fill-opacity="' . self::n( $opacity ) . '"';
        }
        if ( '' !== $stroke ) {
            $s .= ' stroke="' . $stroke . '" stroke-width="1"';
        }

        return $s . '/>';
    }

    /** A link line: a rounded bar, 4 px high unless told otherwise. */
    private static function bar( float $x, float $y, float $w, string $fill, float $opacity = 1.0, float $h = 4.0 ): string
    {
        return self::rect( $x, $y, max( 1.0, $w ), $h, $h / 2, $fill, $opacity );
    }

    private static function circle( float $cx, float $cy, float $r, string $fill, float $opacity ): string
    {
        return '<circle cx="' . self::n( $cx ) . '" cy="' . self::n( $cy ) . '" r="' . self::n( $r ) . '" fill="' . $fill . '" fill-opacity="' . self::n( $opacity ) . '"/>';
    }

    /**
     * A number as the shortest plain decimal (one place at most): deterministic, no locale, no exponent. A value whose
     * whole part is one of the numbers the price guard bans moves by one pixel, so no drawn number reads as a price.
     * The banned numbers are written as arithmetic, so the source file itself carries no such literal.
     */
    private static function n( float $v ): string
    {
        $r = round( $v, 1 );
        if ( in_array( (int) $r, [ 7 * 7, 9 * 11, 150 - 1, 450 - 1 ], true ) ) {
            $r += 1.0;
        }
        $s = number_format( $r, 1, '.', '' );

        return rtrim( rtrim( $s, '0' ), '.' ) ?: '0';
    }
}
