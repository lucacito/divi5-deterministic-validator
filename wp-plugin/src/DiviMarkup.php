<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ONLY place html_to_divi writes Divi block markup. Every attribute shape here is the one a real Divi 5 export
 * (fixtures/valid) and the style guide already show; tests/HtmlToDiviGroundingTest.php pins that, so nothing is
 * invented from memory. The attributes are serialised the way WordPress serialises block attributes, so the result
 * is what the builder itself would have saved.
 */
final class DiviMarkup
{
    /** The builderVersion the plugin's guides teach for every block. */
    public const VERSION = '5.8.0';

    /**
     * Columns in a row => the exact shapes Divi 5's own builder writes for a new row of that many equal columns
     * (fixtures/valid/page-17-newpage.json): the row's column structure, its flex structure and the breakpoints at
     * which it wraps, and each column's type and flex width per breakpoint. That is what makes the columns stack on
     * a phone (four columns wrap to two by two on a tablet first). Without it a classic row stays side by side.
     *
     * @var array<int,array{structure:string,flex:string,wrap:list<string>,type:string,widths:array<string,string>}>
     */
    private const ROWS = [
        1 => [ 'structure' => '4_4', 'flex' => 'equal-columns_1', 'wrap' => [], 'type' => '4_4', 'widths' => [ 'desktop' => '24_24' ] ],
        2 => [ 'structure' => '1_2,1_2', 'flex' => 'equal-columns_2', 'wrap' => [ 'phone', 'phoneWide' ], 'type' => '1_2', 'widths' => [ 'desktop' => '12_24', 'phone' => '24_24', 'phoneWide' => '24_24' ] ],
        3 => [ 'structure' => '1_3,1_3,1_3', 'flex' => 'equal-columns_3', 'wrap' => [ 'phone', 'phoneWide' ], 'type' => '1_3', 'widths' => [ 'desktop' => '8_24', 'phone' => '24_24', 'phoneWide' => '24_24' ] ],
        4 => [ 'structure' => '1_4,1_4,1_4,1_4', 'flex' => 'equal-columns_4', 'wrap' => [ 'tablet', 'tabletWide' ], 'type' => '1_4', 'widths' => [ 'desktop' => '6_24', 'tablet' => '12_24', 'phone' => '24_24', 'tabletWide' => '12_24', 'phoneWide' => '24_24' ] ],
    ];

    /**
     * Style decisions the effect probe proved ON their own block (fixtures/shapes/html-styles-vocabulary.md, "Adapter decisions",
     * same keys, same order; HtmlStylesVocabularyTest pins this constant to the table). A style is written only when its
     * decision is true, at the path the probe measured; a false decision is silently not written. Generated from the verdicts,
     * never decided by hand.
     *
     * @var array<string,bool>
     */
    public const STYLE_PROVEN = [
        'text-align-orientation' => true,
        'text-align-bodyfont'    => true,
        'text-justify'           => true,
        'heading-justify'        => true,
        'websafe-family'         => true,
        'row-max-width'          => true,
        'list-ink-ul'            => true,
        'list-ink-ol'            => true,
        'quote-ink'              => true,
        'row-background'         => true,
        'column-background'      => true,
        'column-padding'         => true,
        'section-padding'        => true,
    ];

    /** Families that are not in Divi's Google font list but render everywhere; written only when STYLE_PROVEN['websafe-family']. */
    public const WEB_SAFE_FAMILIES = [ 'Arial', 'Helvetica', 'Georgia', 'Times New Roman', 'Verdana', 'Tahoma', 'Trebuchet MS', 'Courier New' ];

    /** The fill of a ghost button: KitAdapters::TRANSPARENT, repeated here so this writer needs no other class. */
    public const TRANSPARENT = 'rgba(255,255,255,0)';

    private const SIZE_MIN   = 8;
    private const SIZE_MAX   = 200;
    private const PAD_MAX    = 200;
    private const WIDTH_MIN  = 200;
    private const WIDTH_MAX  = 2400;
    private const RADIUS_MAX = 999;
    private const WEIGHTS    = [ '100', '200', '300', '400', '500', '600', '700', '800', '900' ];
    private const SIDES      = [ 'top', 'right', 'bottom', 'left' ];

    /** @var ?array<string,bool> a test-only replacement for STYLE_PROVEN (runWithProven) */
    private static ?array $provenOverride = null;

    /** @param list<string> $sections */
    public static function page( array $sections ): string
    {
        return self::block( 'placeholder', [], implode( "\n", $sections ) );
    }

    /**
     * @param array{background?:string,padding?:array<string,string>} $style background '#rrggbb'; padding top/right/bottom/left => '<n>px'
     *        (only when STYLE_PROVEN['section-padding'])
     */
    public static function section( string $label, string $rows, array $style = [] ): string
    {
        $decoration = self::boxDecoration( self::checkStyle( $style, [ 'background', 'padding' ], 'section' ), true, self::proven( 'section-padding' ) );
        $module     = [];
        if ( '' !== $label ) {
            $module['meta'] = [ 'adminLabel' => [ 'desktop' => [ 'value' => $label ] ] ];
        }
        if ( [] !== $decoration ) {
            $module['decoration'] = $decoration;
        }
        $attrs = [] === $module ? [ 'builderVersion' => self::VERSION ] : [ 'module' => $module, 'builderVersion' => self::VERSION ];

        return self::block( 'section', $attrs, $rows );
    }

    /**
     * One row of 1 to 4 equal columns; each entry of $columns is the markup of that column's modules.
     *
     * @param list<string> $columns
     * @param array{background?:string,padding?:array<string,string>,max_width?:string} $style the row: background (row-background), padding,
     *        max_width '<n>px' (row-max-width: the row is centred)
     * @param list<array{background?:string,padding?:array<string,string>}> $columnStyles one record per column, in order
     *        (background: column-background, padding: column-padding)
     */
    public static function row( array $columns, array $style = [], array $columnStyles = [] ): string
    {
        $rowStyle = self::checkStyle( $style, [ 'background', 'padding', 'max_width' ], 'row' );
        foreach ( $columnStyles as $columnStyle ) {
            self::checkStyle( (array) $columnStyle, [ 'background', 'padding' ], 'column' );
        }

        $columns = array() === $columns ? [ '' ] : $columns; // a row always has at least one column
        $n       = max( 1, min( 4, count( $columns ) ) );
        $shape   = self::ROWS[ $n ];

        $sizing = [];
        foreach ( $shape['widths'] as $breakpoint => $flexType ) {
            $sizing[ $breakpoint ] = [ 'value' => [ 'flexType' => $flexType ] ];
        }
        $cols = '';
        foreach ( array_slice( array_values( $columns ), 0, 4 ) as $i => $modules ) {
            $colStyle   = isset( $columnStyles[ $i ] ) ? self::checkStyle( (array) $columnStyles[ $i ], [ 'background', 'padding' ], 'column' ) : [];
            $decoration = [ 'sizing' => $sizing ] + self::boxDecoration( $colStyle, self::proven( 'column-background' ), self::proven( 'column-padding' ) );
            $cols .= ( '' === $cols ? '' : "\n" ) . self::block( 'column', [
                'module'         => [ 'advanced' => [ 'type' => [ 'desktop' => [ 'value' => $shape['type'] ] ] ], 'decoration' => $decoration ],
                'builderVersion' => self::VERSION,
            ], $modules );
        }

        $layout = [ 'desktop' => [ 'value' => [ 'flexWrap' => 'nowrap' ] ] ];
        foreach ( $shape['wrap'] as $breakpoint ) {
            $layout[ $breakpoint ] = [ 'value' => [ 'flexWrap' => 'wrap' ] ];
        }
        $decoration = [ 'layout' => $layout ] + self::boxDecoration( $rowStyle, self::proven( 'row-background' ), true );
        if ( isset( $rowStyle['max_width'] ) && self::proven( 'row-max-width' ) ) {
            $decoration['sizing'] = [ 'desktop' => [ 'value' => [ 'maxWidth' => $rowStyle['max_width'] ] ] ];
        }

        return self::block( 'row', [
            'module'         => [
                'advanced'   => [ 'columnStructure' => [ 'desktop' => [ 'value' => $shape['structure'] ] ], 'flexColumnStructure' => [ 'desktop' => [ 'value' => $shape['flex'] ] ] ],
                'decoration' => $decoration,
            ],
            'builderVersion' => self::VERSION,
        ], $cols );
    }

    /**
     * @param array{color?:string,size?:int,weight?:string,align?:string,family?:string} $style color '#rrggbb', size px (8-200; above 32 also a
     *        tablet and a phone step), weight '100'..'900', align center|right|justify (justify: heading-justify), family (a web-safe one: websafe-family)
     */
    public static function heading( string $text, int $level, array $style = [] ): string
    {
        $level = max( 1, min( 6, $level ) );
        $style = self::checkStyle( $style, self::FONT_KEYS, 'heading' );

        $font = [ 'desktop' => [ 'value' => [ 'headingLevel' => 'h' . $level ] ] ];
        $v    = &$font['desktop']['value'];
        foreach ( [ 'color', 'size', 'weight' ] as $key ) {
            if ( isset( $style[ $key ] ) ) {
                $v[ $key ] = 'size' === $key ? $style[ $key ] . 'px' : $style[ $key ];
            }
        }
        $align = self::headingAlign( $style['align'] ?? null );
        if ( null !== $align ) {
            $v['textAlign'] = $align;
        }
        if ( isset( $style['family'] ) && self::familyAllowed( $style['family'] ) ) {
            $v['family'] = $style['family'];
        }
        unset( $v );
        $font += self::sizeSteps( $style['size'] ?? null );

        return self::block( 'heading', [
            'title'          => [
                'innerContent' => [ 'desktop' => [ 'value' => $text ] ],
                'decoration'   => [ 'font' => [ 'font' => $font ] ],
            ],
            'builderVersion' => self::VERSION,
        ], null );
    }

    /** @param array{color?:string,size?:int,weight?:string,align?:string,family?:string} $style as heading() */
    public static function text( string $html, array $style = [] ): string
    {
        $style = self::checkStyle( $style, self::FONT_KEYS, 'text' );

        $font = [ 'desktop' => [ 'value' => [] ] ];
        $v    = &$font['desktop']['value'];
        if ( isset( $style['color'] ) && self::inkAllowed( $html ) ) {
            $v['color'] = $style['color'];
        }
        if ( isset( $style['size'] ) ) {
            $v['size'] = $style['size'] . 'px';
        }
        if ( isset( $style['weight'] ) ) {
            $v['weight'] = $style['weight'];
        }
        if ( isset( $style['family'] ) && self::familyAllowed( $style['family'] ) ) {
            $v['family'] = $style['family'];
        }
        // Alignment: the module's orientation first (centre, right); the body font's textAlign when that is the proven one, and for justify
        // (orientation 'justified' is DEAD on divi/text, measured).
        $orientation = null;
        $align       = $style['align'] ?? null;
        if ( 'justify' === $align ) {
            if ( self::proven( 'text-justify' ) ) {
                $v['textAlign'] = 'justify';
            }
        } elseif ( null !== $align ) {
            if ( self::proven( 'text-align-orientation' ) ) {
                $orientation = $align;
            } elseif ( self::proven( 'text-align-bodyfont' ) ) {
                $v['textAlign'] = $align;
            }
        }
        unset( $v );
        if ( [] === $font['desktop']['value'] ) {
            $font = [];
        } else {
            $font += self::sizeSteps( $style['size'] ?? null );
        }

        $content = [ 'innerContent' => [ 'desktop' => [ 'value' => $html ] ] ];
        if ( [] !== $font ) {
            $content['decoration'] = [ 'bodyFont' => [ 'body' => [ 'font' => $font ] ] ];
        }
        $attrs = [ 'content' => $content ];
        if ( null !== $orientation ) {
            $attrs['module'] = [ 'advanced' => [ 'text' => [ 'text' => [ 'desktop' => [ 'value' => [ 'orientation' => $orientation ] ] ] ] ] ];
        }
        $attrs['builderVersion'] = self::VERSION;

        return self::block( 'text', $attrs, null );
    }

    public static function image( string $src, string $alt, ?string $linkUrl ): string
    {
        $value = [ 'src' => $src ];
        if ( '' !== $alt ) {
            $value['alt'] = $alt;
        }
        if ( null !== $linkUrl && '' !== $linkUrl ) {
            $value['linkUrl'] = $linkUrl;
        }

        return self::block( 'image', [ 'image' => [ 'innerContent' => [ 'desktop' => [ 'value' => $value ] ] ], 'builderVersion' => self::VERSION ], null );
    }

    /**
     * @param array{color?:string,background?:string,radius?:int} $style color '#rrggbb' (the label), background '#rrggbb' or the transparent
     *        literal (a ghost), radius px 0-999 (all four corners)
     */
    public static function button( string $text, ?string $linkUrl, bool $newTab, array $style = [] ): string
    {
        $style = self::checkStyle( $style, [ 'color', 'background', 'radius' ], 'button' );

        $value = [ 'text' => $text ];
        if ( null !== $linkUrl && '' !== $linkUrl ) {
            $value['linkUrl'] = $linkUrl;
            if ( $newTab ) {
                $value['linkTarget'] = 'on';
            }
        }
        $button = [ 'innerContent' => [ 'desktop' => [ 'value' => $value ] ] ];

        $decoration = [];
        if ( isset( $style['background'] ) ) {
            $decoration['background'] = [ 'desktop' => [ 'value' => [ 'color' => $style['background'] ] ] ];
        }
        if ( isset( $style['radius'] ) ) {
            $px                   = $style['radius'] . 'px';
            $decoration['border'] = [ 'desktop' => [ 'value' => [ 'radius' => [ 'topLeft' => $px, 'topRight' => $px, 'bottomRight' => $px, 'bottomLeft' => $px ] ] ] ];
        }
        if ( isset( $style['color'] ) ) {
            $decoration['font'] = [ 'font' => [ 'desktop' => [ 'value' => [ 'color' => $style['color'] ] ] ] ];
        }
        if ( [] !== $decoration ) {
            $button['decoration'] = $decoration;
        }

        return self::block( 'button', [ 'button' => $button, 'builderVersion' => self::VERSION ], null );
    }

    public static function divider(): string
    {
        return self::block( 'divider', [ 'builderVersion' => self::VERSION ], null );
    }

    private const FONT_KEYS = [ 'color', 'size', 'weight', 'align', 'family' ];

    /**
     * Runs $fn with the given decisions in place of STYLE_PROVEN, then puts them back. Test-only (the grounding tests plant a false
     * decision to prove it is never written); nothing in the plugin calls it.
     *
     * @internal
     * @param array<string,bool> $proven exactly the STYLE_PROVEN keys
     */
    public static function runWithProven( array $proven, callable $fn ): mixed
    {
        if ( array_keys( $proven ) !== array_keys( self::STYLE_PROVEN ) ) {
            throw new \InvalidArgumentException( 'runWithProven takes exactly the STYLE_PROVEN keys, in order' );
        }
        $before = self::$provenOverride;
        self::$provenOverride = $proven;
        try {
            return $fn();
        } finally {
            self::$provenOverride = $before;
        }
    }

    private static function proven( string $decision ): bool
    {
        return ( self::$provenOverride ?? self::STYLE_PROVEN )[ $decision ] ?? false;
    }

    /**
     * Refuses an unknown key and a value outside its form (a converter bug, never user input: the values are the normalised tokens
     * HtmlCss and HtmlStyleBlocks produce), and returns the record unchanged.
     *
     * @param array<string,mixed> $style
     * @param list<string>        $allowed
     * @return array<string,mixed>
     */
    private static function checkStyle( array $style, array $allowed, string $kind ): array
    {
        foreach ( $style as $key => $value ) {
            if ( ! in_array( $key, $allowed, true ) ) {
                throw new \InvalidArgumentException( 'DiviMarkup: unknown ' . $kind . ' style key ' . var_export( $key, true ) );
            }
            switch ( $key ) {
                case 'color':
                case 'background':
                    $ok = self::isColour( $value ) || ( 'button' === $kind && 'background' === $key && self::TRANSPARENT === $value );
                    break;
                case 'size':
                    $ok = is_int( $value ) && $value >= self::SIZE_MIN && $value <= self::SIZE_MAX;
                    break;
                case 'radius':
                    $ok = is_int( $value ) && $value >= 0 && $value <= self::RADIUS_MAX;
                    break;
                case 'weight':
                    $ok = is_string( $value ) && in_array( $value, self::WEIGHTS, true );
                    break;
                case 'family':
                    $ok = is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9 ]{1,60}\z/', $value );
                    break;
                case 'align':
                    $ok = is_string( $value ) && in_array( $value, [ 'center', 'right', 'justify' ], true );
                    break;
                case 'max_width':
                    $ok = self::isPx( $value, self::WIDTH_MIN, self::WIDTH_MAX );
                    break;
                case 'padding':
                    $ok = is_array( $value ) && [] !== $value;
                    if ( $ok ) {
                        foreach ( $value as $side => $px ) {
                            $ok = $ok && in_array( $side, self::SIDES, true ) && self::isPx( $px, 0, self::PAD_MAX );
                        }
                    }
                    break;
                default:
                    $ok = false;
            }
            if ( ! $ok ) {
                throw new \InvalidArgumentException( 'DiviMarkup: the ' . $kind . ' style ' . $key . ' has a value outside its form: ' . substr( var_export( $value, true ), 0, 80 ) );
            }
        }

        return $style;
    }

    private static function isColour( mixed $v ): bool
    {
        return is_string( $v ) && 1 === preg_match( '/^#[0-9a-f]{6}\z/', $v );
    }

    private static function isPx( mixed $v, int $min, int $max ): bool
    {
        return is_string( $v ) && 1 === preg_match( '/^\d{1,4}px\z/', $v ) && (int) $v >= $min && (int) $v <= $max;
    }

    /** Background and padding of a section, row or column: { background?, spacing? } in this order, each only when its decision allows. */
    private static function boxDecoration( array $style, bool $backgroundAllowed, bool $paddingAllowed ): array
    {
        $out = [];
        if ( isset( $style['background'] ) && $backgroundAllowed ) {
            $out['background'] = [ 'desktop' => [ 'value' => [ 'color' => $style['background'] ] ] ];
        }
        if ( isset( $style['padding'] ) && $paddingAllowed ) {
            $padding = [];
            foreach ( self::SIDES as $side ) {
                if ( isset( $style['padding'][ $side ] ) ) {
                    $padding[ $side ] = $style['padding'][ $side ];
                }
            }
            $out['spacing'] = [ 'desktop' => [ 'value' => [ 'padding' => $padding ] ] ];
        }

        return $out;
    }

    /** The tablet and phone steps of a size above 32 px (75% and 55%, at least 20 px), the shape make verify-guide-shapes proved. */
    private static function sizeSteps( ?int $size ): array
    {
        if ( null === $size || $size <= 32 ) {
            return [];
        }

        return [
            'tablet' => [ 'value' => [ 'size' => (int) round( 0.75 * $size ) . 'px' ] ],
            'phone'  => [ 'value' => [ 'size' => max( 20, (int) round( 0.55 * $size ) ) . 'px' ] ],
        ];
    }

    private static function headingAlign( ?string $align ): ?string
    {
        if ( null === $align ) {
            return null;
        }

        return 'justify' !== $align || self::proven( 'heading-justify' ) ? $align : null;
    }

    /** A Google family is always allowed (HtmlFonts chose it); a web-safe one only when the probe proved it. */
    private static function familyAllowed( string $family ): bool
    {
        foreach ( self::WEB_SAFE_FAMILIES as $safe ) {
            if ( 0 === strcasecmp( $safe, $family ) ) {
                return self::proven( 'websafe-family' );
            }
        }

        return true;
    }

    /** The text colour reaches a list or a quote only through a proven decision: a colour on text holding one is written only then. */
    private static function inkAllowed( string $html ): bool
    {
        foreach ( [ 'list-ink-ul' => '<ul', 'list-ink-ol' => '<ol', 'quote-ink' => '<blockquote' ] as $decision => $tag ) {
            if ( false !== stripos( $html, $tag ) && ! self::proven( $decision ) ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $attrs */
    private static function block( string $name, array $attrs, ?string $inner ): string
    {
        $json = [] === $attrs ? '' : ' ' . self::attributes( $attrs );
        if ( null === $inner ) {
            return '<!-- wp:divi/' . $name . $json . ' /-->';
        }

        return '<!-- wp:divi/' . $name . $json . " -->\n" . $inner . "\n<!-- /wp:divi/" . $name . ' -->';
    }

    /**
     * Block attributes as WordPress writes them: JSON with slashes and unicode left alone, and the characters that
     * could end a block comment or break the markup written as \uXXXX. Quotes inside strings use JSON_HEX_QUOT
     * (never a text replace of a backslash-quote pair, which is ambiguous after a trailing backslash).
     *
     * @param array<string,mixed> $attrs
     */
    public static function attributes( array $attrs ): string
    {
        $json = (string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR );

        // < > & and -- never occur in the JSON structure itself, only inside strings, so a plain replace is exact.
        $bs = '\\';

        return str_replace( [ '--', '<', '>', '&' ], [ $bs . 'u002d' . $bs . 'u002d', $bs . 'u003c', $bs . 'u003e', $bs . 'u0026' ], $json );
    }
}
