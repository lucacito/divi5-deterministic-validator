<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Kinds;

use AiEditorDivi5\Pro\Batch\ValueKind;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** A page's status (draft, pending, future, publish). `ref` is the page id. */
final class PostStatusKind extends ValueKind
{
    public function name(): string
    {
        return 'post_status';
    }

    public function current( array $item ): mixed
    {
        $post = get_post( (int) $item['ref'] );

        return $post ? (string) $post->post_status : null;
    }

    public function allowed( array $item ): bool
    {
        return current_user_can( 'publish_pages' ) && current_user_can( 'edit_post', (int) $item['ref'] );
    }

    /**
     * An item a site build's launch recorded carries `meta.guard_in_use`: undoing it must not put a page back to draft while the live
     * front page or a menu still points at it (visitors would get a dead link). That happens when the owner changed the menu or the
     * front page after the launch, so their restore was skipped. The item then reports `in_use` and the page stays as it is. The
     * `publish_page` tool records no such mark, so its undo is unchanged.
     */
    public function restore( array $item ): array
    {
        if ( ! empty( $item['meta']['guard_in_use'] ) && self::inUse( (int) $item['ref'] ) ) {
            return [ 'ok' => false, 'reason' => 'in_use' ];
        }

        return parent::restore( $item );
    }

    /** Whether the live front page or any navigation menu item points at this page. */
    private static function inUse( int $pageId ): bool
    {
        if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $pageId ) {
            return true;
        }
        foreach ( (array) wp_get_nav_menus() as $menu ) {
            foreach ( (array) wp_get_nav_menu_items( (int) $menu->term_id ) as $menuItem ) {
                if ( 'post_type' === (string) $menuItem->type && 'page' === (string) $menuItem->object && (int) $menuItem->object_id === $pageId ) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function write( array $item, mixed $value ): bool
    {
        if ( ! is_string( $value ) || ! in_array( $value, [ 'draft', 'pending', 'future', 'publish' ], true ) ) {
            return false;
        }

        return ! is_wp_error( wp_update_post( [ 'ID' => (int) $item['ref'], 'post_status' => $value ], true ) );
    }
}
