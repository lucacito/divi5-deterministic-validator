<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Block;
use Divi5Validator\BlockParser;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Divi's own stock look showing where a design was meant: the grey image placeholder, the stock button and the pale stock tab
 * bar. Advisory only.
 *
 * Every default below was measured, not remembered. The placeholder is the data URI the builder itself writes into a new image
 * (fixtures/valid/page-17-newpage.json; the SVG decodes to a #EBEBEB rectangle). The button and tab bar were rendered unstyled on
 * the real Divi 5.15.1 in Chromium (scripts/probe-divi-defaults.cjs, evidence fixtures/shapes/divi-defaults-5.15.1.json):
 *  - button: white label, transparent fill, 2px solid white border (invisible on a light section),
 *  - tab bar: #f4f4f4 behind white tabs with #666666 text.
 *
 * Confidence: the placeholder is "certain" (the bytes are Divi's own). The button and the tab bar are "possible": a site can
 * restyle them with a preset or the theme options, so they are reported only when the site has no preset for that module (or
 * Divi's presets cannot be read), and the finding says so. A module that sets any of the properties below is never reported.
 */
final class DefaultFallbacks
{
    public const MAX_BYTES = 524288;

    /** What the unstyled modules look like on Divi 5.15.1, from the committed probe evidence. */
    public const MEASURED = [
        'divi'   => '5.15.1',
        'button' => 'white label, transparent fill, 2px solid white border',
        'tabs'   => 'pale #f4f4f4 bar behind white tabs with #666666 text',
        'image'  => 'flat #EBEBEB grey rectangle with a picture icon',
    ];

    /**
     * @param ?callable(string):?bool $moduleHasPreset whether the site has any preset for a module (null result = Divi's presets cannot be read)
     * @return array<string,mixed> status ok|problems|not_checked, findings, and what was measured
     */
    public static function check( string $content, ?callable $moduleHasPreset = null ): array
    {
        if ( '' === trim( $content ) || strlen( $content ) > self::MAX_BYTES ) {
            return [ 'status' => 'not_checked', 'findings' => [], 'reason' => '' === trim( $content ) ? 'No content to check.' : 'The layout is too large to check.' ];
        }
        $parsed = ( new BlockParser() )->parse( $content );
        if ( ! $parsed->isOk() || null === $parsed->root() ) {
            return [ 'status' => 'not_checked', 'findings' => [], 'reason' => 'The layout could not be parsed.' ];
        }

        if ( [] === $parsed->root()->children() ) {
            return [ 'status' => 'not_checked', 'findings' => [], 'reason' => 'No Divi blocks found.' ];
        }

        $findings = [];
        $walk     = static function ( Block $block, string $path ) use ( &$walk, &$findings, $moduleHasPreset ): void {
            $name = $block->name();
            $attrs = $block->attrs();
            foreach ( self::placeholders( $attrs ) as $where ) {
                $findings[] = [
                    'code'       => 'GREY_PLACEHOLDER_IMAGE',
                    'confidence' => 'certain',
                    'block'      => $path,
                    'module'     => $name,
                    'at'         => $where,
                    'message'    => 'This image is still Divi\'s grey placeholder. Replace it with a Media Library image or one from the built-in pack (see get-image-guide).',
                ];
            }
            if ( 'divi/button' === $name && self::buttonIsStock( $block ) && self::unpreset( 'divi/button', $moduleHasPreset ) ) {
                $findings[] = [
                    'code'       => 'STOCK_BUTTON_LOOK',
                    'confidence' => 'possible',
                    'block'      => $path,
                    'module'     => $name,
                    'message'    => 'This button sets no color, fill or border and has no preset, so it shows Divi\'s stock look (' . self::MEASURED['button'] . '), which disappears on a light section. Style it (see get-style-guide) unless this site\'s theme options style buttons.',
                ];
            }
            if ( 'divi/tabs' === $name && self::tabsAreStock( $block ) && self::unpreset( 'divi/tabs', $moduleHasPreset ) ) {
                $findings[] = [
                    'code'       => 'STOCK_TAB_BAR',
                    'confidence' => 'possible',
                    'block'      => $path,
                    'module'     => $name,
                    'message'    => 'These tabs set no tab or active-tab fill and have no preset, so they show Divi\'s stock tab bar (' . self::MEASURED['tabs'] . '). Style them (see get-style-guide) unless this site\'s theme options style tabs.',
                ];
            }
            foreach ( $block->children() as $i => $child ) {
                $walk( $child, $path . '/' . $child->name() . '[' . $i . ']' );
            }
        };
        $walk( $parsed->root(), $parsed->root()->name() );

        return [
            'status'   => [] === $findings ? 'ok' : 'problems',
            'findings' => $findings,
            'measured' => self::MEASURED,
            'note'     => 'Checks only Divi\'s grey image placeholder, the stock button and the stock tab bar. A "possible" finding can be a deliberate look set in the theme options.',
        ];
    }

    /** True when the site has no preset for the module, or when that cannot be known (so a stock look may show). */
    private static function unpreset( string $module, ?callable $moduleHasPreset ): bool
    {
        if ( null === $moduleHasPreset ) {
            return true;
        }

        return true !== $moduleHasPreset( $module );
    }

    private static function buttonIsStock( Block $b ): bool
    {
        if ( isset( $b->attrs()['modulePreset'] ) ) {
            return false;
        }
        foreach ( [ 'button.decoration.background', 'button.decoration.font.font.desktop.value.color', 'button.decoration.border', 'module.decoration.background' ] as $path ) {
            $v = $b->attr( $path );
            if ( ! self::empty( $v ) ) {
                return false;
            }
        }

        return true;
    }

    private static function tabsAreStock( Block $b ): bool
    {
        if ( isset( $b->attrs()['modulePreset'] ) ) {
            return false;
        }
        foreach ( [ 'tab.decoration.background', 'activeTab.decoration.background', 'module.decoration.background' ] as $path ) {
            if ( ! self::empty( $b->attr( $path ) ) ) {
                return false;
            }
        }

        return true;
    }

    private static function empty( mixed $v ): bool
    {
        if ( is_array( $v ) ) {
            foreach ( $v as $item ) {
                if ( ! self::empty( $item ) ) {
                    return false;
                }
            }

            return true;
        }

        return null === $v || '' === $v;
    }

    /**
     * Every string in the attributes that is Divi's own placeholder picture: an SVG data URI that decodes to the #EBEBEB
     * rectangle of the builder's placeholder (1080 x 540).
     *
     * @param array<string,mixed> $attrs
     * @return list<string> dotted paths
     */
    private static function placeholders( array $attrs, string $prefix = '' ): array
    {
        $out = [];
        foreach ( $attrs as $key => $value ) {
            $at = '' === $prefix ? (string) $key : $prefix . '.' . $key;
            if ( is_array( $value ) ) {
                $out = array_merge( $out, self::placeholders( $value, $at ) );
            } elseif ( is_string( $value ) && str_starts_with( $value, 'data:image/svg+xml;base64,' ) && self::isPlaceholder( $value ) ) {
                $out[] = $at;
            }
        }

        return $out;
    }

    public static function isPlaceholder( string $dataUri ): bool
    {
        $svg = base64_decode( substr( $dataUri, strlen( 'data:image/svg+xml;base64,' ) ), true );

        return false !== $svg && strlen( $svg ) < 4096
            && str_contains( $svg, 'viewBox="0 0 1080 540"' ) && str_contains( $svg, 'fill="#EBEBEB"' ) && str_contains( $svg, 'd="M0 0h1080v540H0z"' );
    }
}
