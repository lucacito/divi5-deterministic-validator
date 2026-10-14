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

    /** @param list<string> $sections */
    public static function page( array $sections ): string
    {
        return self::block( 'placeholder', [], implode( "\n", $sections ) );
    }

    public static function section( string $label, string $rows ): string
    {
        $attrs = [ 'builderVersion' => self::VERSION ];
        if ( '' !== $label ) {
            $attrs = [ 'module' => [ 'meta' => [ 'adminLabel' => [ 'desktop' => [ 'value' => $label ] ] ] ] ] + $attrs;
        }

        return self::block( 'section', $attrs, $rows );
    }

    /** One row of 1 to 4 equal columns; each entry of $columns is the markup of that column's modules. @param list<string> $columns */
    public static function row( array $columns ): string
    {
        $columns = array() === $columns ? [ '' ] : $columns; // a row always has at least one column
        $n       = max( 1, min( 4, count( $columns ) ) );
        $shape   = self::ROWS[ $n ];

        $sizing = [];
        foreach ( $shape['widths'] as $breakpoint => $flexType ) {
            $sizing[ $breakpoint ] = [ 'value' => [ 'flexType' => $flexType ] ];
        }
        $cols = '';
        foreach ( array_slice( $columns, 0, 4 ) as $modules ) {
            $cols .= ( '' === $cols ? '' : "\n" ) . self::block( 'column', [
                'module'         => [ 'advanced' => [ 'type' => [ 'desktop' => [ 'value' => $shape['type'] ] ] ], 'decoration' => [ 'sizing' => $sizing ] ],
                'builderVersion' => self::VERSION,
            ], $modules );
        }

        $layout = [ 'desktop' => [ 'value' => [ 'flexWrap' => 'nowrap' ] ] ];
        foreach ( $shape['wrap'] as $breakpoint ) {
            $layout[ $breakpoint ] = [ 'value' => [ 'flexWrap' => 'wrap' ] ];
        }

        return self::block( 'row', [
            'module'         => [
                'advanced'   => [ 'columnStructure' => [ 'desktop' => [ 'value' => $shape['structure'] ] ], 'flexColumnStructure' => [ 'desktop' => [ 'value' => $shape['flex'] ] ] ],
                'decoration' => [ 'layout' => $layout ],
            ],
            'builderVersion' => self::VERSION,
        ], $cols );
    }

    public static function heading( string $text, int $level ): string
    {
        $level = max( 1, min( 6, $level ) );

        return self::block( 'heading', [
            'title'          => [
                'innerContent' => [ 'desktop' => [ 'value' => $text ] ],
                'decoration'   => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'headingLevel' => 'h' . $level ] ] ] ] ],
            ],
            'builderVersion' => self::VERSION,
        ], null );
    }

    public static function text( string $html ): string
    {
        return self::block( 'text', [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => $html ] ] ], 'builderVersion' => self::VERSION ], null );
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

    public static function button( string $text, ?string $linkUrl, bool $newTab ): string
    {
        $value = [ 'text' => $text ];
        if ( null !== $linkUrl && '' !== $linkUrl ) {
            $value['linkUrl'] = $linkUrl;
            if ( $newTab ) {
                $value['linkTarget'] = 'on';
            }
        }

        return self::block( 'button', [ 'button' => [ 'innerContent' => [ 'desktop' => [ 'value' => $value ] ] ], 'builderVersion' => self::VERSION ], null );
    }

    public static function divider(): string
    {
        return self::block( 'divider', [ 'builderVersion' => self::VERSION ], null );
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
