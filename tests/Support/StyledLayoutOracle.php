<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\DiviMarkup;
use AiEditorDivi5\WP\HtmlFonts;
use Divi5Validator\Block;
use Divi5Validator\BlockParser;

require_once __DIR__ . '/../../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../../wp-plugin/src/HtmlFonts.php';
require_once __DIR__ . '/../../wp-plugin/src/DiviMarkup.php';

/**
 * The layout oracle of html_to_divi's styles (dev/test-side only, not shipped). It judges a converted layout from the OUTPUT only
 * (BlockParser over the markup), never from the converter's own decisions: it does not call HtmlStyles, HtmlStyleBlocks or
 * HtmlReadability. Contrast is the project's one WCAG formula, DesignLanguages::contrast(). The attribute paths it reads are the
 * ones DiviMarkup writes (pinned by HtmlToDiviGroundingTest), named here again so a writer change that moves a colour is seen.
 *
 * Rules (each problem string starts with "rule N:"):
 *  1 ink only on paint: every heading/text colour has a painted ancestor block (column, row or section);
 *  2 paint implies ink: every heading/text under a painted block has a colour;
 *  3 every such colour meets 4.5:1 against the innermost paint (column, else row, else section), or 3:1 when the text is large:
 *    its written size is >= 24 px, or >= 19 px with a written weight >= 700. The size judged is the SMALLEST size the layout writes
 *    for that module on ANY device (desktop, tablet, phone step), so a 40 px heading that the writer steps down to 22 px on phones
 *    is judged at 22 px, normal text. This is at least as strict as the converter, which decides large text at the writer's
 *    smallest device step too (Task 8 ruling 5). A module without a written size is never large. The weight is the smallest
 *    written on any device;
 *  4 a button has a label colour iff it has a fill; a solid fill pair meets 4.5:1; a transparent fill (a ghost) only under paint,
 *    its label meeting 4.5:1 against that paint; every button under paint has a pair or a ghost;
 *  5 value forms: every colour '#rrggbb' (a button fill may also be DiviMarkup::TRANSPARENT), every size, padding, max width and
 *    radius '<n>px' within the writer's clamps (size 8-120, padding 0-200, max width 200-2400, radius 0-999), a weight
 *    '100'..'900', an alignment center|right|justify (orientation center|right), a family one of HtmlFonts::google() spelled
 *    canonically, or one of HtmlFonts::WEB_SAFE when DiviMarkup::STYLE_PROVEN['websafe-family'] is true.
 */
final class StyledLayoutOracle
{
    public const FLOOR       = 4.5;
    public const LARGE_FLOOR = 3.0;

    private const PAINT = 'module.decoration.background.desktop.value.color';

    /** module => the font object (per device) that holds its ink, size and weight */
    private const FONT = [
        'divi/heading' => 'title.decoration.font.font',
        'divi/text'    => 'content.decoration.bodyFont.body.font',
    ];

    private const BOXES = [ 'divi/section', 'divi/row', 'divi/column' ];

    private const BUTTON_FILL  = 'button.decoration.background.desktop.value.color';
    private const BUTTON_LABEL = 'button.decoration.font.font.desktop.value.color';

    private const WEIGHTS = [ '100', '200', '300', '400', '500', '600', '700', '800', '900' ];

    /**
     * @return list<string>
     */
    public static function problems( string $layout ): array
    {
        $root = ( new BlockParser() )->parse( $layout )->root();
        if ( null === $root ) {
            return [ 'the layout does not parse' ];
        }
        $out  = [];
        $seen = [];
        self::walk( $root, null, $out, $seen );

        return $out;
    }

    /** @param list<string> $out @param array<string,int> $seen */
    private static function walk( Block $b, ?string $paint, array &$out, array &$seen ): void
    {
        $name  = $b->name();
        $where = $name . ' #' . ( $seen[ $name ] = ( $seen[ $name ] ?? 0 ) + 1 );
        self::forms( $b->attrs(), '', $name, $where, $out );

        if ( in_array( $name, self::BOXES, true ) ) {
            $own = $b->attr( self::PAINT );
            if ( null !== $own ) {
                $paint = is_string( $own ) ? $own : '?';
            }
        }
        if ( isset( self::FONT[ $name ] ) ) {
            self::judgeText( $b, $paint, $where, $out );
        } elseif ( 'divi/button' === $name ) {
            self::judgeButton( $b, $paint, $where, $out );
        }
        foreach ( $b->children() as $c ) {
            self::walk( $c, $paint, $out, $seen );
        }
    }

    /** @param list<string> $out */
    private static function judgeText( Block $b, ?string $paint, string $where, array &$out ): void
    {
        $font = $b->attr( self::FONT[ $b->name() ] );
        $font = is_array( $font ) ? $font : [];
        $ink  = $font['desktop']['value']['color'] ?? null;
        if ( null !== $ink && null === $paint ) {
            $out[] = 'rule 1: ' . $where . ': ink ' . self::show( $ink ) . ' without paint';

            return;
        }
        if ( null === $ink && null !== $paint ) {
            $out[] = 'rule 2: ' . $where . ': on paint ' . $paint . ' without ink';

            return;
        }
        if ( null === $ink || ! self::isHex( $ink ) || ! self::isHex( $paint ) ) {
            return; // nothing to judge, or a value form rule 5 already reports
        }
        $sizes   = [];
        $weights = [];
        foreach ( $font as $device ) {
            $v = is_array( $device ) ? ( $device['value'] ?? null ) : null;
            if ( ! is_array( $v ) ) {
                continue;
            }
            if ( is_string( $v['size'] ?? null ) && 1 === preg_match( '/^(\d+)px\z/', $v['size'], $m ) ) {
                $sizes[] = (int) $m[1];
            }
            if ( is_string( $v['weight'] ?? null ) && in_array( $v['weight'], self::WEIGHTS, true ) ) {
                $weights[] = (int) $v['weight'];
            }
        }
        $small  = [] === $sizes ? null : min( $sizes );
        $weight = [] === $weights ? 0 : min( $weights );
        $large  = null !== $small && ( $small >= 24 || ( $small >= 19 && $weight >= 700 ) );
        $floor  = $large ? self::LARGE_FLOOR : self::FLOOR;
        $ratio  = DesignLanguages::contrast( $ink, $paint );
        if ( $ratio < $floor ) {
            $out[] = sprintf( 'rule 3: %s: %s on %s is %.2F, below %.1F:1 (smallest size %s, weight %d)', $where, $ink, $paint, $ratio, $floor, null === $small ? 'none' : $small . 'px', $weight );
        }
    }

    /** @param list<string> $out */
    private static function judgeButton( Block $b, ?string $paint, string $where, array &$out ): void
    {
        $fill  = $b->attr( self::BUTTON_FILL );
        $label = $b->attr( self::BUTTON_LABEL );
        if ( ( null === $fill ) !== ( null === $label ) ) {
            $out[] = 'rule 4: ' . $where . ': half a button pair (fill ' . self::show( $fill ) . ', label ' . self::show( $label ) . ')';

            return;
        }
        if ( null === $fill ) {
            if ( null !== $paint ) {
                $out[] = 'rule 4: ' . $where . ': a button on paint ' . $paint . ' with neither a pair nor a ghost';
            }

            return;
        }
        if ( DiviMarkup::TRANSPARENT === $fill ) {
            if ( null === $paint ) {
                $out[] = 'rule 4: ' . $where . ': a ghost button without paint';

                return;
            }
            $under = $paint;
        } else {
            $under = $fill;
        }
        if ( self::isHex( $label ) && self::isHex( $under ) ) {
            $ratio = DesignLanguages::contrast( $label, $under );
            if ( $ratio < self::FLOOR ) {
                $out[] = sprintf( 'rule 4: %s: button label %s on %s is %.2F, below %.1F:1', $where, $label, $under, $ratio, self::FLOOR );
            }
        }
    }

    /**
     * Rule 5 over every attribute leaf of one block, by key name (the phase-1 attributes use none of these keys for anything else).
     *
     * @param list<string> $out
     */
    private static function forms( mixed $node, string $path, string $block, string $where, array &$out ): void
    {
        if ( ! is_array( $node ) ) {
            return;
        }
        foreach ( $node as $key => $value ) {
            $p   = '' === $path ? (string) $key : $path . '.' . $key;
            $bad = null;
            switch ( (string) $key ) {
                case 'color':
                    $ok = self::isHex( $value ) || ( 'divi/button' === $block && 'button.decoration.background.desktop.value.color' === $p && DiviMarkup::TRANSPARENT === $value );
                    $bad = $ok ? null : 'not a #rrggbb colour';
                    break;
                case 'size':
                    $bad = self::px( $value, 8, 120 ) ? null : 'not a size of 8-120px';
                    break;
                case 'maxWidth':
                    $bad = self::px( $value, 200, 2400 ) ? null : 'not a max width of 200-2400px';
                    break;
                case 'padding':
                    if ( ! is_array( $value ) || [] === $value ) {
                        $bad = 'not a padding record';
                        break;
                    }
                    foreach ( $value as $side => $px ) {
                        if ( ! in_array( $side, [ 'top', 'right', 'bottom', 'left' ], true ) || ! self::px( $px, 0, 200 ) ) {
                            $bad = 'side ' . $side . ' is not 0-200px';
                        }
                    }
                    break;
                case 'radius':
                    if ( ! is_array( $value ) || [] === $value ) {
                        $bad = 'not a radius record';
                        break;
                    }
                    foreach ( $value as $corner => $px ) {
                        if ( ! in_array( $corner, [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ], true ) || ! self::px( $px, 0, 999 ) ) {
                            $bad = 'corner ' . $corner . ' is not 0-999px';
                        }
                    }
                    break;
                case 'weight':
                    $bad = is_string( $value ) && in_array( $value, self::WEIGHTS, true ) ? null : 'not a weight 100-900';
                    break;
                case 'textAlign':
                    $bad = in_array( $value, [ 'center', 'right', 'justify' ], true ) ? null : 'not an alignment';
                    break;
                case 'orientation':
                    $bad = in_array( $value, [ 'center', 'right' ], true ) ? null : 'not an orientation';
                    break;
                case 'family':
                    $bad = self::isFamily( $value ) ? null : 'not a Google font or a proven web-safe family';
                    break;
                default:
                    self::forms( $value, $p, $block, $where, $out );
                    continue 2;
            }
            if ( null !== $bad ) {
                $out[] = 'rule 5: ' . $where . ': ' . $p . ' = ' . self::show( $value ) . ' is ' . $bad;
            }
        }
    }

    private static function isFamily( mixed $v ): bool
    {
        if ( ! is_string( $v ) ) {
            return false;
        }
        if ( in_array( $v, HtmlFonts::WEB_SAFE, true ) ) {
            return true === ( DiviMarkup::STYLE_PROVEN['websafe-family'] ?? false );
        }

        return in_array( $v, HtmlFonts::google(), true );
    }

    private static function isHex( mixed $v ): bool
    {
        return is_string( $v ) && 1 === preg_match( '/^#[0-9a-f]{6}\z/', $v );
    }

    private static function px( mixed $v, int $min, int $max ): bool
    {
        return is_string( $v ) && 1 === preg_match( '/^(0|[1-9]\d{0,3})px\z/', $v ) && (int) $v >= $min && (int) $v <= $max;
    }

    private static function show( mixed $v ): string
    {
        return substr( (string) json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 0, 80 );
    }
}
