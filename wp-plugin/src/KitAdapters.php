<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/StyleKits.php';

/**
 * Adapters: turn a style kit's decisions into the attributes of one module, for KitResolver to merge where a recipe
 * carries a marker. v1 knows one module, `divi/button`, in two roles (`control`, `control-secondary`) on three
 * surfaces (`light`: the section's bg or surface, `accent`: an accent band, `dark`: a photo or overlay).
 *
 * The button patch writes only `button.decoration.{background,border,font}` and `module.decoration.spacing` at
 * `desktop` (never innerContent, nothing else under `module`: a recipe's `module.decoration.layout` and the like survive
 * the merge), and every path it controls is written explicitly, rest and hover: an absent key would fall back to the
 * site's module preset. Every path is in the shape registry (fixtures/shapes/verified-shapes.json) and none is in
 * fixtures/shapes/dead-on-module.txt. Colours are `{{aied:color:<role>}}` tokens (the language supplies the value), the
 * radius and padding whole-value kit tokens; the only literal is TRANSPARENT.
 *
 * Module rule (controller Ruling 10, effect probe 2026-10-18, fixtures/shapes/kit-button-vocabulary.md): on
 * `divi/button` Divi 5.15.1 drops every `button.decoration` group whose `<name>Group` is render:false (ButtonModule.php
 * :664-712, :760), so the button ELEMENT's spacing, boxShadow and transform are DEAD (kb-padding-element,
 * kb-shadow-element-hard, kb-skew-x-element). The control padding is therefore written at
 * `module.decoration.spacing.desktop.value.padding` (kb-padding-module PASS: Divi prints it on the button element).
 * The adapter MAY write `module.decoration.spacing`, and later `module.decoration.boxShadow` / `module.decoration.transform`
 * (proven, not written yet).
 *
 * Role table (final, controller Ruling 6; proven by KitAdaptersTest's contrast proof over every shipped kit x language
 * x role x surface x state, with no exception):
 * - Colour c: the kit's `color` role, except that `control-secondary` on a `light` surface always uses `text` (the
 *   neutral secondary today's recipes ship: text ink and border on bg/surface), whatever the kit's colour.
 * - Ink of a transparent button (its label and border): light -> c (`text` when c is `inverse`), accent band -> `accent-text` (the language's
 *   guaranteed pair on accent), dark (photo or overlay) -> `inverse`.
 * - Filled treatment: light and dark -> fill c, label on(c) (on(accent)=accent-text, on(text)=bg); accent band ->
 *   fill `surface`, label `text`. `inverse` is an ink, never a fill: a filled `inverse` button (a kit whose colour is
 *   inverse) is `surface` with a `text` label, because no role is readable on inverse in every language (text and
 *   inverse are both light in the dark languages).
 * - solid: the filled treatment, border the kit's (`same` = the fill colour) at the kit width.
 * - outline: transparent, label and border in the ink at the kit width.
 * - ghost: as outline with a zero border.
 * - The secondary role uses the outline treatment for solid and ghost kits, and the solid one for outline kits.
 * Hover:
 * - `none` repeats the rest values.
 * - `fill-swap` swaps the filled and transparent treatments: a transparent button fills (light: fill = its ink, label
 *   on(ink); accent band and dark: fill `surface`, label `text`, the core pair readable in every language); a filled
 *   button goes transparent in the surface's ink (light: c, as above; accent band: `accent-text`; dark: `inverse`).
 * - `invert` swaps label and fill of a filled button (a `same` border follows the new fill). A transparent button has
 *   no fill colour to swap, so `invert` fills it exactly as `fill-swap` does.
 * Not written on purpose: `background.hover.enableColor` (every recipe button sets its hover colour without it and
 * renders; the registry knows the path but nothing proves it is needed), `border.value.radius.sync` (the radius token
 * is the four corners, as every recipe button writes it), shadows, gradients and transforms (not probe-proven yet).
 *
 * Pure and deterministic: no site access.
 */
final class KitAdapters
{
    /**
     * The transparent background value (already shipped in a rendered recipe, hero-image-scrim). One constant so a
     * probe-proven value can replace it in one place.
     */
    public const TRANSPARENT = 'rgba(255,255,255,0)';

    private const ROLES    = [ 'control', 'control-secondary' ];
    private const SURFACES = [ 'light', 'accent', 'dark' ];

    /** The label colour role on a fill of the given colour role (`inverse` is never a fill, see filled()). */
    private const ON = [ 'accent' => 'accent-text', 'text' => 'bg' ];

    /** The secondary treatment of each primary fill. */
    private const SECONDARY = [ 'solid' => 'outline', 'outline' => 'solid', 'ghost' => 'outline' ];

    /**
     * The attribute subtree to merge into $blockName for a marker of $role on surface $on, or null when this
     * adapter does not know the combination (any other block, role or surface, or an invalid kit).
     *
     * @param array<string,mixed> $kit
     * @return array<string,mixed>|null
     */
    public static function patch( string $blockName, string $role, string $on, array $kit ): ?array
    {
        if ( 'divi/button' !== $blockName || ! in_array( $role, self::ROLES, true ) || ! in_array( $on, self::SURFACES, true ) ) {
            return null;
        }
        if ( [] !== StyleKits::problems( $kit ) ) {
            return null;
        }
        return self::button( $role, $on, $kit );
    }

    /**
     * @param array<string,mixed> $kit a valid kit
     * @return array<string,mixed>
     */
    private static function button( string $role, string $on, array $kit ): array
    {
        $fill   = (string) $kit['fill'];
        $fill   = 'control' === $role ? $fill : self::SECONDARY[ $fill ];
        $c      = 'control-secondary' === $role && 'light' === $on ? 'text' : (string) $kit['color'];
        $rest   = self::treatment( $fill, $c, $on, $kit );
        $hover  = self::hover( (string) $kit['hover'], $rest, (string) $kit['border']['color'], $c, $on );
        $text   = (array) $kit['text'];

        return [
            'button' => [
                'decoration' => [
                    'background' => [
                        'desktop' => [
                            'value' => [ 'color' => self::colour( $rest['fill'] ) ],
                            'hover' => [ 'color' => self::colour( $hover['fill'] ) ],
                        ],
                    ],
                    'border'     => [
                        'desktop' => [
                            'value' => [
                                'radius' => '{{aied:kit:radius-control}}',
                                'styles' => [
                                    'all' => [
                                        'width' => $rest['width'],
                                        'style' => (string) $kit['border']['style'],
                                        'color' => self::colour( $rest['border'] ),
                                    ],
                                ],
                            ],
                            'hover' => [ 'styles' => [ 'all' => [ 'color' => self::colour( $hover['border'] ) ] ] ],
                        ],
                    ],
                    'font'       => [
                        'font' => [
                            'desktop' => [
                                'value' => [
                                    'color'         => self::colour( $rest['label'] ),
                                    'capitalization' => (string) $text['capitalization'],
                                    'letterSpacing' => (string) $text['letterSpacing'],
                                    'weight'        => (string) $text['weight'],
                                ],
                                'hover' => [ 'color' => self::colour( $hover['label'] ) ],
                            ],
                        ],
                    ],
                ],
            ],
            'module' => [
                'decoration' => [
                    'spacing' => [
                        'desktop' => [ 'value' => [ 'padding' => '{{aied:kit:space-control}}' ] ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The rest state of a treatment as colour roles (null = transparent) and the border width.
     *
     * @param array<string,mixed> $kit
     * @return array{fill:?string,label:string,border:string,width:string}
     */
    private static function treatment( string $fill, string $c, string $on, array $kit ): array
    {
        $width       = (string) $kit['border']['width'];
        $borderColor = (string) $kit['border']['color'];

        if ( 'solid' === $fill ) {
            [ $bg, $label ] = self::filled( $c, $on );
            return [ 'fill' => $bg, 'label' => $label, 'border' => 'same' === $borderColor ? $bg : $borderColor, 'width' => $width ];
        }

        $ink = self::ink( $c, $on );
        return [ 'fill' => null, 'label' => $ink, 'border' => $ink, 'width' => 'ghost' === $fill ? '0px' : $width ];
    }

    /** The ink (label and border) of a transparent button of colour $c on surface $on (`inverse` is for dark surfaces: on light it is `text`). */
    private static function ink( string $c, string $on ): string
    {
        if ( 'accent' === $on ) {
            return 'accent-text';
        }
        if ( 'dark' === $on ) {
            return 'inverse';
        }
        return 'inverse' === $c ? 'text' : $c;
    }

    /**
     * [fill, label] of a filled button of colour $c on surface $on.
     *
     * @return array{0:string,1:string}
     */
    private static function filled( string $c, string $on ): array
    {
        if ( 'accent' === $on || ! isset( self::ON[ $c ] ) ) {
            return [ 'surface', 'text' ];
        }
        return [ $c, self::ON[ $c ] ];
    }

    /**
     * The hover state of a rest state.
     *
     * @param array{fill:?string,label:string,border:string,width:string} $rest
     * @return array{fill:?string,label:string,border:string}
     */
    private static function hover( string $hover, array $rest, string $kitBorderColor, string $c, string $on ): array
    {
        if ( 'none' === $hover ) {
            return [ 'fill' => $rest['fill'], 'label' => $rest['label'], 'border' => $rest['border'] ];
        }
        if ( null === $rest['fill'] ) {
            // Transparent at rest: fill-swap and invert both fill it.
            [ $bg, $label ] = 'light' === $on ? self::filled( $rest['label'], $on ) : [ 'surface', 'text' ];
            return [ 'fill' => $bg, 'label' => $label, 'border' => $bg ];
        }
        if ( 'fill-swap' === $hover ) {
            // Filled at rest: transparent in the surface's ink.
            $ink = self::ink( $c, $on );
            return [ 'fill' => null, 'label' => $ink, 'border' => $ink ];
        }
        // invert: label and fill swap; a `same` border follows the fill.
        return [ 'fill' => $rest['label'], 'label' => $rest['fill'], 'border' => 'same' === $kitBorderColor ? $rest['label'] : $rest['border'] ];
    }

    private static function colour( ?string $role ): string
    {
        return null === $role ? self::TRANSPARENT : '{{aied:color:' . $role . '}}';
    }
}
