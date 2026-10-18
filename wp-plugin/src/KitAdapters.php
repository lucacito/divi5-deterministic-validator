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
 * The button patch writes only `button.decoration.{background,border,font,spacing}` at `desktop` (never innerContent,
 * never `module`), and every path it controls is written explicitly, rest and hover: an absent key would fall back to
 * the site's module preset. Every path is in the shape registry (fixtures/shapes/verified-shapes.json). Colours are
 * `{{aied:color:<role>}}` tokens (the language supplies the value), the radius and padding whole-value kit tokens;
 * the only literal is TRANSPARENT.
 *
 * Role table (c = the kit's colour role; on(accent)=accent-text, on(text)=bg, on(inverse)=text):
 * - solid:   light and dark: fill c, label on(c), border the kit's; accent band: fill surface, label text.
 * - outline: light: transparent, label and border c at the kit width; accent and dark: label and border inverse.
 * - ghost:   as outline with a zero border.
 * The secondary role uses the kit's outline treatment for solid and ghost kits, and the solid one for outline kits.
 * Hover: `fill-swap` swaps the filled and transparent treatments of the same colours; `invert` swaps label and fill
 * (a transparent button has no fill colour to swap, so it fills with its label colour, as fill-swap does); `none`
 * repeats the rest values.
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

    /** The label colour role on a fill of the given colour role. */
    private const ON = [ 'accent' => 'accent-text', 'text' => 'bg', 'inverse' => 'text' ];

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
        $rest   = self::treatment( $fill, (string) $kit['color'], $on, $kit );
        $hover  = self::hover( (string) $kit['hover'], $rest, (string) $kit['border']['color'] );
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
                    'spacing'    => [
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
            [ $bg, $label ] = 'accent' === $on ? [ 'surface', 'text' ] : [ $c, self::ON[ $c ] ];
            return [ 'fill' => $bg, 'label' => $label, 'border' => 'same' === $borderColor ? $bg : $borderColor, 'width' => $width ];
        }

        $ink = 'light' === $on ? $c : 'inverse';
        return [ 'fill' => null, 'label' => $ink, 'border' => $ink, 'width' => 'ghost' === $fill ? '0px' : $width ];
    }

    /**
     * The hover state of a rest state.
     *
     * @param array{fill:?string,label:string,border:string,width:string} $rest
     * @return array{fill:?string,label:string,border:string}
     */
    private static function hover( string $hover, array $rest, string $kitBorderColor ): array
    {
        if ( 'none' === $hover ) {
            return [ 'fill' => $rest['fill'], 'label' => $rest['label'], 'border' => $rest['border'] ];
        }
        if ( null === $rest['fill'] ) {
            // Transparent at rest: both fill-swap and invert fill the button with its label colour.
            $ink = $rest['label'];
            return [ 'fill' => $ink, 'label' => self::ON[ $ink ], 'border' => $ink ];
        }
        if ( 'fill-swap' === $hover ) {
            // Filled at rest: the transparent treatment of the same colour.
            return [ 'fill' => null, 'label' => $rest['fill'], 'border' => $rest['fill'] ];
        }
        // invert: label and fill swap; a `same` border follows the fill.
        return [ 'fill' => $rest['label'], 'label' => $rest['fill'], 'border' => 'same' === $kitBorderColor ? $rest['label'] : $rest['border'] ];
    }

    private static function colour( ?string $role ): string
    {
        return null === $role ? self::TRANSPARENT : '{{aied:color:' . $role . '}}';
    }
}
