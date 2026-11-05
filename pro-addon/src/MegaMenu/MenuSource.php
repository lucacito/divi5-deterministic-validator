<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reads the WordPress menu the mega menu is built from (the only WordPress-bound reader besides PostSource).
 *
 * The menu is the one the header's Menu module points at; when that id is empty or no longer exists, the menu on the
 * theme's first assigned location, then the first menu of the site (the same order a Menu module falls back in).
 */
class MenuSource
{
    /**
     * @return array{name:string,menuId:int,tree:list<array<string,mixed>>,how:string}|null
     */
    public function read( ?string $preferredId ): ?array
    {
        [ $menu, $how ] = $this->pick( $preferredId );
        if ( null === $menu ) {
            return null;
        }
        $items = wp_get_nav_menu_items( (int) $menu->term_id );
        if ( ! is_array( $items ) || [] === $items ) {
            return [ 'name' => (string) $menu->name, 'menuId' => (int) $menu->term_id, 'tree' => [], 'how' => $how ];
        }
        $flat = [];
        foreach ( $items as $it ) {
            $flat[] = [
                'id'          => (int) $it->ID,
                'parent'      => (int) $it->menu_item_parent,
                'order'       => (int) $it->menu_order,
                'title'       => wp_strip_all_tags( (string) $it->title ),
                'url'         => (string) $it->url,
                'newTab'      => '_blank' === (string) $it->target,
                'description' => $this->description( $it ),
                'type'        => (string) $it->type,
                'object'      => (string) $it->object,
                'objectId'    => (int) $it->object_id,
                'count'       => $this->count( $it ),
            ];
        }

        return [ 'name' => (string) $menu->name, 'menuId' => (int) $menu->term_id, 'tree' => MenuTree::build( $flat ), 'how' => $how ];
    }

    /**
     * Every menu of the site, for the menu choice on the tab.
     *
     * @return list<array{id:int,name:string,items:int}>
     */
    public function all(): array
    {
        $out = [];
        foreach ( (array) wp_get_nav_menus() as $m ) {
            $out[] = [ 'id' => (int) $m->term_id, 'name' => (string) $m->name, 'items' => (int) $m->count ];
        }

        return $out;
    }

    /** Whether a menu with this id exists. */
    public function exists( int $menuId ): bool
    {
        return $menuId > 0 && (bool) wp_get_nav_menu_object( $menuId );
    }

    /** @return array{0:?object,1:string} */
    private function pick( ?string $preferredId ): array
    {
        if ( null !== $preferredId && ctype_digit( $preferredId ) ) {
            $menu = wp_get_nav_menu_object( (int) $preferredId );
            if ( $menu ) {
                return [ $menu, 'the menu chosen in the header\'s Menu module' ];
            }
        }
        foreach ( get_nav_menu_locations() as $menuId ) {
            $menu = (int) $menuId > 0 ? wp_get_nav_menu_object( (int) $menuId ) : false;
            if ( $menu ) {
                return [ $menu, 'the menu on the theme\'s first menu location' ];
            }
        }
        $menus = wp_get_nav_menus();
        if ( is_array( $menus ) && [] !== $menus ) {
            return [ $menus[0], 'the first menu of the site' ];
        }

        return [ null, '' ];
    }

    /** How many published posts the item leads to: a category, tag or other term's count, or a post type archive's total. Null for anything else. */
    private function count( object $item ): ?int
    {
        if ( 'taxonomy' === (string) $item->type && (int) $item->object_id > 0 ) {
            $term = get_term( (int) $item->object_id, (string) $item->object );

            return $term instanceof \WP_Term ? (int) $term->count : null;
        }
        if ( 'post_type_archive' === (string) $item->type && '' !== (string) $item->object ) {
            $counts = wp_count_posts( (string) $item->object );

            return is_object( $counts ) && isset( $counts->publish ) ? (int) $counts->publish : null;
        }

        return null;
    }

    /** The menu item's own description, else a short excerpt of the page it points at. */
    private function description( object $item ): string
    {
        $own = trim( wp_strip_all_tags( (string) ( $item->description ?? '' ) ) );
        if ( '' !== $own ) {
            return self::cut( $own );
        }
        if ( 'post_type' === (string) $item->type && (int) $item->object_id > 0 ) {
            $post = get_post( (int) $item->object_id );
            if ( $post && 'publish' === $post->post_status && '' === (string) $post->post_password ) {
                return self::cut( trim( wp_strip_all_tags( (string) $post->post_excerpt ) ) );
            }
        }

        return '';
    }

    private static function cut( string $s ): string
    {
        $s = (string) preg_replace( '/\s+/u', ' ', $s );

        return mb_strlen( $s ) > 90 ? rtrim( mb_substr( $s, 0, 88 ) ) . '…' : $s;
    }
}
