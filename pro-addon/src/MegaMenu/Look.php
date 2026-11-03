<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * What the new nav borrows from the header's own Menu module so it looks like part of the same header: the menu text
 * font (size, weight, colour, hover colour) and the logo. Pure: it reads the decoded attributes of the Menu block.
 */
final class Look
{
    public const DEFAULT_FONT = [ 'size' => '16px', 'weight' => '600', 'color' => '#1b2130' ];

    /**
     * @param array<string,mixed> $menuAttrs the decoded attributes of the `divi/menu` block
     * @return array{font:array<string,string>,hoverColor:?string,logo:?array<string,string>,logoWidth:?string,menuId:?string}
     */
    public static function fromMenuAttrs( array $menuAttrs ): array
    {
        $fontNode = $menuAttrs['menu']['decoration']['font']['font']['desktop'] ?? [];
        $value    = is_array( $fontNode['value'] ?? null ) ? $fontNode['value'] : [];
        $font     = [];
        foreach ( [ 'size', 'weight', 'color', 'family' ] as $key ) {
            if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) && '' !== $value[ $key ] ) {
                $font[ $key ] = $value[ $key ];
            }
        }
        $font  = array_merge( self::DEFAULT_FONT, $font );
        $hover = $fontNode['hover']['color'] ?? null;

        $logoValue = $menuAttrs['logo']['innerContent']['desktop']['value'] ?? null;
        $logo      = null;
        if ( is_array( $logoValue ) && is_string( $logoValue['src'] ?? null ) && '' !== $logoValue['src'] ) {
            $logo = [];
            foreach ( [ 'src', 'id', 'alt', 'titleText', 'width', 'height', 'linkUrl' ] as $key ) {
                if ( isset( $logoValue[ $key ] ) && ( is_string( $logoValue[ $key ] ) || is_int( $logoValue[ $key ] ) ) ) {
                    $logo[ $key ] = (string) $logoValue[ $key ];
                }
            }
        }
        $width  = $menuAttrs['logo']['decoration']['sizing']['desktop']['value']['width'] ?? null;
        $menuId = $menuAttrs['menu']['advanced']['menuId']['desktop']['value'] ?? null;

        return [
            'font'       => $font,
            'hoverColor' => is_string( $hover ) && '' !== $hover ? $hover : null,
            'logo'       => $logo,
            'logoWidth'  => is_string( $width ) && '' !== $width ? $width : null,
            'menuId'     => is_string( $menuId ) || is_int( $menuId ) ? (string) $menuId : null,
        ];
    }
}
