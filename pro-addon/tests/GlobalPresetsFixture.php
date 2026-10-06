<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use ET\Builder\Packages\GlobalData\GlobalPreset;

require_once __DIR__ . '/DiviGlobalDataShims.php';

/** Stored presets as Divi stores them, including the legacy oddities seen on a real site. */
final class GlobalPresetsFixture
{
    public const H_DEFAULT = 'c78db57d-4ac7-4849-b5b4-1797b4b1eb8f';
    public const H1        = '1e32dbff-cc61-4cda-b68a-39243c406d98';
    public const T_DEFAULT = '0afc9131-1111-4222-8333-444444444444';
    public const LEGACY    = 's2s-test-1785336674';

    /** @return array<string,array<string,mixed>> */
    public static function data(): array
    {
        $item = static fn ( string $id, string $module, string $name, array $attrs ): array => [
            'id' => $id, 'moduleName' => $module, 'name' => $name, 'attrs' => $attrs, 'styleAttrs' => $attrs, 'version' => '5.8.1', 'type' => 'module', 'created' => 1782593353, 'updated' => 1782593353,
        ];
        $size = static fn ( string $px ): array => [ 'title' => [ 'decoration' => [ 'font' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => $px ] ] ] ] ] ] ];

        return [
            'module' => [
                'divi/heading' => [
                    'default' => self::H_DEFAULT,
                    'items'   => [
                        self::H_DEFAULT => $item( self::H_DEFAULT, 'divi/heading', 'Heading default', $size( '30px' ) ),
                        self::H1        => $item( self::H1, 'divi/heading', 'Heading 1', $size( '72px' ) ),
                    ],
                ],
                'divi/text'    => [
                    'default' => self::T_DEFAULT,
                    'items'   => [ self::T_DEFAULT => $item( self::T_DEFAULT, 'divi/text', 'Text default', [ 'content' => [ 'decoration' => [ 'bodyFont' => [ 'body' => [ 'font' => [ 'desktop' => [ 'value' => [ 'size' => '16px' ] ] ] ] ] ] ] ] ) ],
                ],
                '0'            => [
                    'default' => 'legacy-default',
                    'items'   => [
                        self::LEGACY => [ 'id' => self::LEGACY, 'name' => 'S2S Test Preset', 'attrs' => [ 'x' => '1' ], 'type' => 'module' ],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $replace replaces the stored data entirely when given */
    public static function reset( array $replace = [] ): void
    {
        GlobalPreset::resetForTests( [] === $replace ? self::data() : $replace );
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_options']         = [];
        $GLOBALS['__wp_caps']            = [ 'edit_theme_options' => true ];
        $GLOBALS['__wp_current_user_id'] = 7;
    }

    public static function clear(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__divi_gp'] );
    }
}
