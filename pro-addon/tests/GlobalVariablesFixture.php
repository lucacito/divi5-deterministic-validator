<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Tests;

use ET\Builder\Packages\GlobalData\GlobalData;

require_once __DIR__ . '/DiviGlobalDataShims.php';

/** Stored variables as Divi stores them (every value a string), plus the usual WordPress shim state. */
final class GlobalVariablesFixture
{
    public const N1  = 'gvid-nnnnnnnn-0000-4000-8000-000000000001';
    public const N2  = 'gvid-nnnnnnnn-0000-4000-8000-000000000002';
    public const S1  = 'gvid-ssssssss-0000-4000-8000-000000000001';
    public const IMG = 'gvid-iiiiiiii-0000-4000-8000-000000000001';

    /** @return array<string,array<string,array<string,string>>> */
    public static function stored(): array
    {
        return [
            'numbers' => [
                self::N1 => [ 'label' => 'Radius', 'value' => '12px', 'order' => '1', 'status' => 'active' ],
                self::N2 => [ 'label' => 'Gap', 'value' => '1.5rem', 'order' => '2', 'status' => 'archived' ],
            ],
            'strings' => [
                self::S1 => [ 'label' => 'Phone', 'value' => '555 0100', 'order' => '1', 'status' => 'active' ],
            ],
            'images'  => [
                self::IMG => [ 'label' => 'Logo', 'value' => 'https://example.com/logo.png', 'order' => '1', 'status' => 'active' ],
            ],
        ];
    }

    /** @param array<string,mixed> $extraStored merged over the stored groups (a whole group replaces it) */
    public static function reset( array $extraStored = [] ): void
    {
        GlobalData::resetVariablesForTests( $extraStored + self::stored() );
        $GLOBALS['__wp_transients']      = [];
        $GLOBALS['__wp_options']         = [];
        $GLOBALS['__wp_caps']            = [ 'edit_theme_options' => true ];
        $GLOBALS['__wp_current_user_id'] = 7;
        $GLOBALS['__divi_role_allowed']  = true;
    }

    public static function clear(): void
    {
        unset( $GLOBALS['__wp_caps'], $GLOBALS['__wp_current_user_id'], $GLOBALS['__divi_gv'], $GLOBALS['__divi_role_allowed'] );
    }
}
