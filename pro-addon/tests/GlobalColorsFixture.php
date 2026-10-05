<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use ET\Builder\Packages\GlobalData\GlobalData;

require_once __DIR__ . '/DiviGlobalDataShims.php';

/** Two custom colors, an untouched theme option, and the usual WordPress shims state. */
final class GlobalColorsFixture
{
    public const A = 'gcid-aaaaaaaa-0000-4000-8000-000000000001';
    public const B = 'gcid-bbbbbbbb-0000-4000-8000-000000000002';

    /** @return array<string,array<string,mixed>> */
    public static function custom(): array
    {
        return [
            self::A => [ 'color' => '#112233', 'folder' => '', 'label' => 'Brand', 'lastUpdated' => '2026-01-01T00:00:00.000Z', 'status' => 'active', 'usedInPosts' => [ '12', '15' ] ],
            self::B => [ 'color' => '#445566', 'folder' => 'brand', 'label' => 'Accent', 'lastUpdated' => '2026-01-02T00:00:00.000Z', 'status' => 'inactive', 'usedInPosts' => [] ],
        ];
    }

    /** @param array<string,array<string,mixed>> $extraCustom stored in addition to A and B */
    public static function reset( array $extraCustom = [] ): void
    {
        GlobalData::resetForTests( self::custom() + $extraCustom, [ 'accent_color' => '#2ea3f2' ] );
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_caps']            = [ 'edit_theme_options' => true ];
        $GLOBALS['__wp_current_user_id'] = 7;
    }

    public static function clear(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__divi_gc'] );
    }
}
