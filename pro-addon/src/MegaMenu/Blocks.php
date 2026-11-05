<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The ONLY place in the mega menu feature that writes Divi block markup. Every attribute path used here was rendered on
 * the real Divi (docs/superpowers/specs/2026-11-03-pro-megamenu-design.md, fixtures/megamenu-proven-paths.txt); nothing
 * else in the feature builds a block by hand.
 *
 * Two traps this class closes: a Dropdown whose settings are not wrapped in `desktop.value` is a PHP fatal on the real
 * Divi (the whole page answers 500) and the validator does not see it, so dropdown() is the only way to make one; and
 * attribute JSON must not contain a raw `--`, `<`, `>` or `&` because they can end a block comment.
 */
final class Blocks
{
    public const ADMIN_LABEL = 'JHMG Mega Menu';

    /** Admin label of a card that shows a post (a snapshot taken at apply time, not part of the menu). */
    public const POST_LABEL = 'JHMG post';

    /** Admin label of the pill that shows how many posts an item has (a snapshot taken at apply time, not part of the menu). */
    public const COUNT_LABEL = 'JHMG count';

    /**
     * The admin label of one panel: it says which menu item and which layout it was built from (HeaderEdit::panels reads it back) and,
     * as its last word, a short fingerprint of what the panel was when it was written (PanelFingerprint). The fingerprint travels with the
     * panel, so restoring an earlier header restores the matching fingerprint too.
     */
    public static function panelLabel( int $itemId, string $layoutId, string $stamp = '' ): string
    {
        return 'JHMG panel ' . $itemId . ' ' . $layoutId . ( '' === $stamp ? '' : ' ' . $stamp );
    }

    public function __construct( private string $builderVersion = '5.8.0' )
    {
    }

    /** `['desktop' => ['value' => $value]]`, Divi's per-breakpoint wrapper for a desktop value. */
    public static function resp( mixed $value ): array
    {
        return [ 'desktop' => [ 'value' => $value ] ];
    }

    /** Attribute JSON that is safe inside an HTML comment (the same escaping the free plugin's DiviMarkup uses). */
    public static function encode( array|object $attrs ): string
    {
        $json = (string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR );
        $bs   = '\\';

        return str_replace( [ '--', '<', '>', '&' ], [ $bs . 'u002d' . $bs . 'u002d', $bs . 'u003c', $bs . 'u003e', $bs . 'u0026' ], $json );
    }

    /** One block. `$inner` null or empty writes a self-closing block (the form WordPress itself saves an empty block in). */
    public function block( string $name, array $attrs, ?string $inner = null ): string
    {
        $attrs['builderVersion'] = $this->builderVersion;
        $json                    = self::encode( $attrs );

        return null === $inner || '' === $inner
            ? "<!-- wp:divi/{$name} {$json} /-->"
            : "<!-- wp:divi/{$name} {$json} -->{$inner}<!-- /wp:divi/{$name} -->";
    }

    // ---- decoration fragments (merge into a module's `decoration`) -------------------------------------------------

    public static function background( string $color ): array
    {
        return [ 'background' => self::resp( [ 'color' => $color ] ) ];
    }

    /** The module's background colour while the pointer is on it (nothing at rest). */
    public static function hoverBackground( string $color ): array
    {
        return [ 'background' => [ 'desktop' => [ 'hover' => [ 'color' => $color ] ] ] ];
    }

    /**
     * A two-stop linear gradient. The solid `color` is written as well: it is what the page shows if the gradient is not drawn,
     * and what a contrast check reads, so it is always the stop the text is most at risk on.
     */
    public static function gradient( string $from, string $to, string $angle = '135deg' ): array
    {
        return [ 'background' => self::resp( [
            'color'    => $from,
            'gradient' => [
                'enabled'   => 'on',
                'type'      => 'linear',
                'direction' => $angle,
                'stops'     => [ [ 'position' => '0', 'color' => $from ], [ 'position' => '100', 'color' => $to ] ],
            ],
        ] ) ];
    }

    /** @param array{0:string,1:string,2:string,3:string} $trbl top, right, bottom, left (CSS lengths) */
    public static function padding( array $trbl ): array
    {
        return [ 'spacing' => self::resp( [ 'padding' => [ 'top' => $trbl[0], 'right' => $trbl[1], 'bottom' => $trbl[2], 'left' => $trbl[3], 'syncVertical' => 'off', 'syncHorizontal' => 'off' ] ] ) ];
    }

    public static function margin( array $trbl ): array
    {
        return [ 'spacing' => self::resp( [ 'margin' => [ 'top' => $trbl[0], 'right' => $trbl[1], 'bottom' => $trbl[2], 'left' => $trbl[3], 'syncVertical' => 'off', 'syncHorizontal' => 'off' ] ] ) ];
    }

    /** Merge several spacing fragments (padding and margin) into one. */
    public static function spacing( array ...$fragments ): array
    {
        $value = [];
        foreach ( $fragments as $f ) {
            $value = array_merge( $value, $f['spacing']['desktop']['value'] ?? [] );
        }

        return [ 'spacing' => self::resp( $value ) ];
    }

    /**
     * Rounded corners, optionally a frame (`$frame` = [width, colour]), a thicker top edge in another colour (`$top` = [width, colour])
     * and the frame colour on hover (`$hoverFrame`).
     */
    public static function border( string $radius, ?array $frame = null, ?array $top = null, ?string $hoverFrame = null ): array
    {
        $value = [ 'radius' => [ 'topLeft' => $radius, 'topRight' => $radius, 'bottomRight' => $radius, 'bottomLeft' => $radius ] ];
        if ( null !== $frame ) {
            $value['styles'] = [ 'all' => [ 'width' => $frame[0], 'color' => $frame[1], 'style' => 'solid' ] ];
        }
        if ( null !== $top ) {
            $value['styles']['top'] = [ 'width' => $top[0], 'color' => $top[1], 'style' => 'solid' ];
        }
        $border = [ 'desktop' => [ 'value' => $value ] ];
        if ( null !== $hoverFrame ) {
            $border['desktop']['hover'] = [ 'styles' => [ 'all' => [ 'color' => $hoverFrame ] ] ];
        }

        return [ 'border' => $border ];
    }

    /**
     * An entrance animation: the module slides up a short way while it fades in. Divi plays it again every time the Dropdown
     * opens (measured on 5.15.1: three opens in a row, opacity 0 to 1, the animation class removed at the end).
     */
    public static function enter( string $delay ): array
    {
        return [ 'animation' => self::resp( [ 'style' => 'slide', 'direction' => 'bottom', 'duration' => '450ms', 'delay' => $delay, 'startingOpacity' => '0%', 'speedCurve' => 'ease-out', 'repeat' => 'once', 'intensity' => [ 'slide' => '8%' ] ] ) ];
    }

    /** The module rises 4px on hover (the shape the style kits write for a lifted card). */
    public static function lift(): array
    {
        return [ 'transform' => [ 'desktop' => [ 'hover' => [ 'translate' => [ 'y' => '-4px' ] ] ] ] ];
    }

    /** A 1px rule along the top edge only (the shape the kit rules use: `all.width` 0px plus a `top` edge). */
    public static function topRule( string $color ): array
    {
        return [ 'border' => self::resp( [ 'styles' => [ 'all' => [ 'width' => '0px' ], 'top' => [ 'width' => '1px', 'color' => $color, 'style' => 'solid' ] ] ] ) ];
    }

    /** An outer shadow; `$hover` = [vertical, blur, colour] is the shadow while the pointer is on the module. */
    public static function shadow( string $vertical, string $blur, string $color, ?array $hover = null ): array
    {
        $shadow = [ 'desktop' => [ 'value' => [ 'horizontal' => '0px', 'vertical' => $vertical, 'blur' => $blur, 'position' => 'outer', 'color' => $color, 'style' => 'preset3' ] ] ];
        if ( null !== $hover ) {
            $shadow['desktop']['hover'] = [ 'style' => 'preset3', 'vertical' => $hover[0], 'blur' => $hover[1], 'color' => $hover[2] ];
        }

        return [ 'boxShadow' => $shadow ];
    }

    public static function sizing( array $value ): array
    {
        return [ 'sizing' => self::resp( $value ) ];
    }

    public static function zIndex( int $z ): array
    {
        return [ 'zIndex' => self::resp( $z ) ];
    }

    /** @param array<string,string> $byBreakpoint e.g. ['tablet' => 'on', 'phone' => 'on'] */
    public static function disabledOn( array $byBreakpoint ): array
    {
        $out = [];
        foreach ( $byBreakpoint as $bp => $state ) {
            $out[ $bp ] = [ 'value' => $state ];
        }

        return [ 'disabledOn' => $out ];
    }

    // ---- modules ----------------------------------------------------------------------------------------------------

    /**
     * A flex or grid group. `$layout` is the layout value ({display, flexDirection, columnGap, ...}), `$deco` merges into
     * `module.decoration`, `$label` is the builder's admin label.
     */
    public function group( array $layout, string $inner, array $deco = [], ?string $label = null ): string
    {
        $module = [ 'decoration' => array_merge( [ 'layout' => self::resp( $layout ) ], $deco ) ];
        if ( null !== $label ) {
            $module['meta'] = [ 'adminLabel' => self::resp( $label ) ];
        }

        return $this->block( 'group', [ 'module' => $module ], $inner );
    }

    /** A paragraph of text; `$font` is {color, size, weight, lineHeight}. */
    public function text( string $html, array $font = [], array $deco = [] ): string
    {
        $attrs = [ 'content' => [ 'innerContent' => self::resp( $html ) ] ];
        if ( [] !== $font ) {
            $attrs['content']['decoration'] = [ 'bodyFont' => [ 'body' => [ 'font' => self::resp( $font ) ] ] ];
        }
        if ( [] !== $deco ) {
            $attrs['module'] = [ 'decoration' => $deco ];
        }

        return $this->block( 'text', $attrs );
    }

    /** A heading; `$font` is {color, size, weight, lineHeight}. */
    public function heading( string $text, string $level, array $font = [], array $deco = [] ): string
    {
        $attrs = [ 'title' => [ 'innerContent' => self::resp( $text ), 'decoration' => [ 'font' => [ 'font' => self::resp( array_merge( [ 'headingLevel' => $level ], $font ) ) ] ] ] ];
        if ( [] !== $deco ) {
            $attrs['module'] = [ 'decoration' => $deco ];
        }

        return $this->block( 'heading', $attrs );
    }

    /**
     * A Link. `$inner` (a Dropdown) makes it the trigger of that dropdown. `$font` is {size, weight, color, ...};
     * `$hoverColor` the text colour on hover.
     */
    public function link( string $text, string $url, array $font = [], ?string $hoverColor = null, string $inner = '', array $deco = [], bool $newTab = false ): string
    {
        $content = [ 'text' => $text, 'linkUrl' => $url ];
        if ( $newTab ) {
            $content['linkTarget'] = 'on';
        }
        $attrs = [ 'content' => [ 'innerContent' => self::resp( $content ) ] ];
        if ( [] !== $font || null !== $hoverColor ) {
            $fontAttr = [ 'desktop' => [ 'value' => $font ] ];
            if ( null !== $hoverColor ) {
                $fontAttr['desktop']['hover'] = [ 'color' => $hoverColor ];
            }
            $attrs['content']['decoration'] = [ 'font' => [ 'font' => $fontAttr ] ];
        }
        if ( [] !== $deco ) {
            $attrs['module'] = [ 'decoration' => $deco ];
        }

        return $this->block( 'link', $attrs, '' === $inner ? null : $inner );
    }

    /**
     * A Dropdown. This is the only way to write one: the settings are always wrapped in `desktop.value` (a raw object is
     * a PHP fatal on the real Divi). `$tabletPhoneShowOn` adds `showOn` for tablet and phone.
     *
     * @param array{showOn?:string,direction?:string,alignment?:string,offset?:string,position?:string,horizontalMode?:string} $settings
     */
    public function dropdown( array $settings, string $inner, array $deco = [], ?string $tabletPhoneShowOn = null, ?string $label = null ): string
    {
        $dropdown = self::resp( $settings );
        if ( null !== $tabletPhoneShowOn ) {
            $dropdown['tablet'] = [ 'value' => [ 'showOn' => $tabletPhoneShowOn ] ];
            $dropdown['phone']  = [ 'value' => [ 'showOn' => $tabletPhoneShowOn ] ];
        }
        $module = [ 'advanced' => [ 'dropdown' => $dropdown ] ];
        if ( [] !== $deco ) {
            $module['decoration'] = $deco;
        }
        if ( null !== $label ) {
            $module['meta'] = [ 'adminLabel' => self::resp( $label ) ];
        }

        return $this->block( 'dropdown', [ 'module' => $module ], $inner );
    }

    /** An image. `$src` must be an absolute or site URL; `$alt` is always written (empty means decorative). */
    public function image( string $src, string $alt, ?string $linkUrl = null, array $deco = [], ?string $titleText = null, ?string $id = null ): string
    {
        $value = [ 'src' => $src, 'alt' => $alt ];
        if ( null !== $titleText ) {
            $value['titleText'] = $titleText;
        }
        if ( null !== $id && '' !== $id ) {
            $value['id'] = $id;
        }
        if ( null !== $linkUrl ) {
            $value['linkUrl'] = $linkUrl;
        }
        $attrs = [ 'image' => [ 'innerContent' => self::resp( $value ) ] ];
        if ( [] !== $deco ) {
            $attrs['module'] = [ 'decoration' => $deco ];
        }

        return $this->block( 'image', $attrs );
    }

    /**
     * A button. `$pill` = {fill, label, hoverFill, hoverLabel, radius} paints it: a solid fill, its label colour and the two
     * hover colours, no border; the padding goes on the module (on a button only `module.decoration.spacing` is live).
     */
    public function button( string $text, string $url, array $font = [], array $deco = [], ?array $pill = null ): string
    {
        $attrs = [ 'button' => [ 'innerContent' => self::resp( [ 'text' => $text, 'linkUrl' => $url ] ) ] ];
        if ( [] !== $font || null !== $pill ) {
            $attrs['button']['decoration'] = [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => $font ] ] ] ];
        }
        if ( null !== $pill ) {
            $r = $pill['radius'];
            $attrs['button']['decoration']['button']     = self::resp( [ 'enable' => 'on' ] );
            $attrs['button']['decoration']['background'] = [ 'desktop' => [ 'value' => [ 'color' => $pill['fill'] ], 'hover' => [ 'color' => $pill['hoverFill'] ] ] ];
            $attrs['button']['decoration']['font']['font']['desktop']['value']['color'] = $pill['label'];
            $attrs['button']['decoration']['font']['font']['desktop']['hover']          = [ 'color' => $pill['hoverLabel'] ];
            $attrs['button']['decoration']['border']     = self::resp( [
                'radius' => [ 'topLeft' => $r, 'topRight' => $r, 'bottomRight' => $r, 'bottomLeft' => $r ],
                'styles' => [ 'all' => [ 'width' => '0px' ] ],
            ] );
        }
        if ( [] !== $deco ) {
            $attrs['module'] = [ 'decoration' => $deco ];
        }

        return $this->block( 'button', $attrs );
    }

    /** A Font Awesome solid glyph (`$unicode` like `&#xf061;`) as an icon module. */
    public function icon( string $unicode, string $color, string $size, array $deco = [] ): string
    {
        $attrs = [ 'icon' => [
            'innerContent' => self::resp( [ 'unicode' => $unicode, 'type' => 'fa', 'weight' => '900' ] ),
            'advanced'     => [ 'color' => self::resp( $color ), 'size' => self::resp( $size ), 'align' => self::resp( 'center' ) ],
        ] ];
        if ( [] !== $deco ) {
            $attrs['module'] = [ 'decoration' => $deco ];
        }

        return $this->block( 'icon', $attrs );
    }
}
