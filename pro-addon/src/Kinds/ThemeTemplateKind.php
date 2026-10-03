<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\Item;
use AiEditorDivi5\Pro\Batch\ItemKind;
use AiEditorDivi5\Pro\ThemeBuilder\TemplateStore;
use AiEditorDivi5\Pro\ThemeBuilder\ThemeLayouts;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One live Theme Builder template's state (enabled flag and where it applies). The item stores the previous full state;
 * undo writes it back through Divi's own function, and only while the template still looks the way the batch left it.
 */
final class ThemeTemplateKind implements ItemKind
{
    public const NAME = 'theme_template';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @param array<string,mixed> $before the state before the change
     * @param array<string,mixed> $after the state read back after the change
     * @return array<string,mixed>
     */
    public static function itemFor( array $before, array $after ): array
    {
        return Item::make( self::NAME, (string) $after['id'], 'Theme Builder template "' . (string) $after['title'] . '" (#' . (string) $after['id'] . ')', $before, $after );
    }

    public function current( array $item ): mixed
    {
        return TemplateStore::read( (int) $item['ref'] );
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'edit_theme_options' ) && current_user_can( 'edit_post', (int) $item['ref'] ) && ThemeLayouts::userMay() && null !== TemplateStore::read( (int) $item['ref'] );
    }

    public function restore( array $item ): array
    {
        $before = $item['before'] ?? null;
        $now    = TemplateStore::read( (int) $item['ref'] );
        if ( ! is_array( $before ) || null === $now ) {
            return [ 'ok' => false, 'reason' => 'no_previous_state' ];
        }
        $after = TemplateStore::write( $before );
        if ( null === $after ) {
            return [ 'ok' => false, 'reason' => 'divi_refused' ];
        }

        return [ 'ok' => true, 'inverse' => self::itemFor( $now, $after ) ];
    }
}
