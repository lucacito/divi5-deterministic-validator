<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\Settings\MenuBuilder;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** The "Main Menu" and the theme location pointing at it. `ref` is the location; `before` is the full snapshot. */
final class MenuKind implements ItemKind
{
    public function name(): string
    {
        return 'menu';
    }

    public function current( array $item ): mixed
    {
        return MenuBuilder::canonical( MenuBuilder::snapshot( (string) ( $item['ref'] ?? '' ) ) );
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'edit_theme_options' );
    }

    public function restore( array $item ): array
    {
        $location = (string) $item['ref'];
        $now      = MenuBuilder::snapshot( $location );
        try {
            MenuBuilder::restore( (array) $item['before'] );
        } catch ( \RuntimeException $e ) {
            return [ 'ok' => false, 'reason' => 'menu_failed' ];
        }

        return [ 'ok' => true, 'inverse' => Item::make( 'menu', $location, (string) $item['label'], $now, MenuBuilder::canonical( (array) $item['before'] ) ) ];
    }
}
